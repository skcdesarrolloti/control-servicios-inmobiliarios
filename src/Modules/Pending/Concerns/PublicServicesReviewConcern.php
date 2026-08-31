<?php

declare(strict_types=1);

namespace SCM\Modules\Pending\Concerns;

use SCM\Core\Auth;
use SCM\Modules\Pending\PublicServicesReviewPdfGenerator;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait PublicServicesReviewConcern
{
  /** @return array<string,mixed> */
  public function buildServiciosPublicosReviewContext(int $contractId): array
  {
    if ($contractId <= 0) {
      return ['ok' => false, 'message' => 'ID de contrato inválido.'];
    }

    $contract = $this->repo->getPublicServicesContract($contractId);
    if (!is_array($contract)) {
      return ['ok' => false, 'message' => 'Contrato no encontrado.'];
    }

    $employee = $this->repo->getFuncionarioByUserId(Auth::userId());
    if (!$employee || in_array(trim((string) ($employee['id_empleado'] ?? '')), ['', '0'], true) || trim((string) ($employee['nombre'] ?? '')) === '') {
      return ['ok' => false, 'message' => 'No se pudo identificar el id_empleado del funcionario autenticado. Revisa su ficha antes de guardar.'];
    }
    $available = $this->availablePublicServices($contract);
    $formServices = [];
    foreach ($this->publicServiceDefinitions() as $key => $definition) {
      $formServices[$key] = $definition + [
        'configured' => isset($available[$key]),
        'account' => (string) ($contract[$definition['contract_account_field']] ?? ''),
        'meter' => (string) ($contract[$definition['contract_meter_field']] ?? ''),
      ];
    }
    $branchId = trim((string) ($contract['id_sucursal'] ?? $contract['sucursal'] ?? ''));
    $branch = $this->repo->getSucursalById($branchId) ?? [];

    return [
      'ok' => true,
      'contract' => $contract,
      'services' => $formServices,
      'has_services' => !empty($available),
      'employee' => $employee,
      'branch' => $branch,
      'review_date' => date('Y-m-d'),
    ];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public function createServiciosPublicosReview(int $contractId, array $input): array
  {
    $context = $this->buildServiciosPublicosReviewContext($contractId);
    if (empty($context['ok'])) {
      return $context;
    }

    $contract = (array) ($context['contract'] ?? []);
    $contractPk = (int) ($contract['_ID'] ?? $contractId);
    $configuration = $this->validatePublicServicesConfiguration($input);
    if (empty($configuration['ok'])) {
      return $configuration;
    }
    $selectedKeys = array_values(array_unique(array_filter(array_map(
      fn($value): string => $this->normalizePublicServiceKey((string) $value),
      is_array($input['servicios'] ?? null) ? $input['servicios'] : []
    ))));
    if (empty($selectedKeys)) {
      return ['ok' => false, 'message' => 'Selecciona por lo menos un servicio para registrar la revisión.'];
    }

    $services = [];
    foreach ($selectedKeys as $serviceKey) {
      if (!in_array($serviceKey, $configuration['keys'], true)) {
        return ['ok' => false, 'message' => 'Activa el servicio en el contrato antes de incluirlo en la revisión.'];
      }
      $definition = $this->publicServiceDefinitions()[$serviceKey];
      $account = $this->cleanReviewText((string) ($input[$definition['account_field']] ?? ''));
      $meter = $this->cleanReviewText((string) ($input[$definition['meter_field']] ?? ''));
      $status = $this->canonicalReviewStatus((string) ($input[$definition['status_field']] ?? ''));
      $amount = $this->reviewAmount((string) ($input[$definition['amount_field']] ?? ''));
      if ($account === '' || $meter === '' || $status === '') {
        return ['ok' => false, 'message' => 'Completa identificador, medidor y resultado para ' . $definition['label'] . '.'];
      }
      if ($status === 'Al dia' && $amount !== 0) {
        return ['ok' => false, 'message' => 'El valor de ' . $definition['label'] . ' debe ser 0 cuando el servicio esta al dia.'];
      }
      if ($status !== 'Al dia' && $amount <= 0) {
        return ['ok' => false, 'message' => 'Indica un valor adeudado mayor que 0 para ' . $definition['label'] . '.'];
      }

      $services[$serviceKey] = $definition + [
        'account' => $account,
        'meter' => $meter,
        'status' => $status,
        'amount' => $amount,
      ];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $reviewCount = max(0, (int) ($contract['revisiones_servicios'] ?? 0)) + 1;
    $baseMonth = (int) ($contract['mes_revision_servicios'] ?? 0);
    if ($baseMonth < 1 || $baseMonth > 12) {
      $baseMonth = (int) date('n', $nowTs);
    }
    // Equivalente nativo del hook crear-mes-revision-servicios.
    $nextReviewMonth = (($baseMonth + 3 - 1) % 12) + 1;
    $employee = (array) ($context['employee'] ?? []);
    $branch = (array) ($context['branch'] ?? []);
    $employeeId = (string) $employee['id_empleado'];
    $employeeName = (string) $employee['nombre'];

    $pdfContext = [
      'fecha' => $nowTs,
      'ciudad' => 'Cartagena de Indias',
      'contrato' => (string) ($contract['contrato'] ?? $contractId),
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
      'inmueble' => (string) ($contract['inmueble'] ?? ''),
      'direccion' => (string) ($contract['direccion'] ?? ''),
      'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
      'propietario' => (string) ($contract['propietario'] ?? ''),
      'realizado_por' => $employeeName,
      'representante_legal' => (string) ($branch['representante_legal'] ?? ''),
      'celular_legal' => (string) ($branch['celular_legal'] ?? ''),
    ];

    $documents = [];
    if ($this->repo->getDb()->pdo()->inTransaction()) {
      return ['ok' => false, 'message' => 'Ya existe una operación en curso. Intenta nuevamente.'];
    }
    try {
      $documents = (new PublicServicesReviewPdfGenerator())->generate($pdfContext, $services);
      if (count($documents) !== count($services)) {
        throw new \RuntimeException('No fue posible generar todas las actas de la revisión.');
      }
    } catch (\Throwable $exception) {
      $this->removeGeneratedReviewDocuments($documents);
      return ['ok' => false, 'message' => 'No se pudieron generar las actas: ' . $exception->getMessage()];
    }

    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $pdo = $db->pdo();
    $startedTransaction = false;
    $reviewId = 0;

    try {
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
      }

      $reviewPayload = [
        'cct_status' => 'publish',
        'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
        'id_contrato' => (string) ($contract['_ID'] ?? $contractId),
        'fecha' => $nowTs,
        'contrato' => (string) ($contract['contrato'] ?? ''),
        'inmueble' => (string) ($contract['inmueble'] ?? ''),
        'direccion' => (string) ($contract['direccion'] ?? ''),
        'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
        'propietario' => (string) ($contract['propietario'] ?? ''),
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'sucursal' => (string) ($contract['id_sucursal'] ?? $contract['sucursal'] ?? ''),
        'id_empleado' => $employeeId,
        'realizado_por' => $employeeName,
        'inicio_contrato' => (int) ($contract['inicio_contrato'] ?? 0),
        'fin_contrato' => (int) ($contract['fin_contrato'] ?? 0),
        'revisiones_servicios' => (string) $reviewCount,
        'fecha_formateada' => date('d-m-Y', $nowTs),
        'tipo_inmueble' => (string) ($contract['tipo_inmueble'] ?? ''),
        'tipo_negocio' => (string) ($contract['gestion_inmueble'] ?? ''),
        'destinacion' => (string) ($contract['destinacion_inmueble'] ?? ''),
        'tipo' => 'Durante la ocupacion',
      ];
      $contractPayload = $configuration['payload'] + [
        'id_empleado' => $employeeId,
        'realizado_por' => $employeeName,
        'revisiones_servicios' => (string) $reviewCount,
        'tuvo_revision' => 'Si',
        'ultima_revision_servicios' => $nowTs,
        'mes_revision_servicios' => (string) $nextReviewMonth,
        'cct_modified' => $nowMysql,
      ];

      foreach ($this->publicServiceDefinitions() as $serviceKey => $definition) {
        $reviewPayload[$definition['review_date_field']] = isset($services[$serviceKey]) ? $nowTs : 0;
        $reviewPayload[$definition['review_account_field']] = '';
        $reviewPayload[$definition['review_meter_field']] = '';
        $reviewPayload[$definition['review_status_field']] = '';
        $reviewPayload[$definition['review_amount_field']] = '';
        $reviewPayload[$definition['good_document_field']] = '';
        $reviewPayload[$definition['debt_document_field']] = '';
        if (!isset($services[$serviceKey])) {
          continue;
        }

        $service = $services[$serviceKey];
        $reviewPayload[$definition['review_account_field']] = $service['account'];
        $reviewPayload[$definition['review_meter_field']] = $service['meter'];
        $reviewPayload[$definition['review_status_field']] = $service['status'];
        $reviewPayload[$definition['review_amount_field']] = (string) $service['amount'];
        $contractPayload[$definition['contract_account_field']] = $service['account'];
        $contractPayload[$definition['contract_meter_field']] = $service['meter'];
        $contractPayload[$definition['contract_date_field']] = $nowTs;
        $contractPayload[$definition['contract_status_field']] = $service['status'];
        $contractPayload[$definition['contract_amount_field']] = (string) $service['amount'];

        $documentField = $service['status'] === 'Al dia'
          ? $definition['good_document_field']
          : $definition['debt_document_field'];
        $otherDocumentField = $service['status'] === 'Al dia'
          ? $definition['debt_document_field']
          : $definition['good_document_field'];
        $documentUrl = trim((string) ($documents[$documentField]['url'] ?? ''));
        $reviewPayload[$documentField] = $documentUrl;
        $contractPayload[$documentField] = $documentUrl;
        $contractPayload[$otherDocumentField] = '';
      }

      $reviewTable = $db->table('jet_cct_revisiones_servicios');
      $reviewPayload = $schema->filterTableData($reviewTable, $reviewPayload);
      if (empty($reviewPayload) || !$db->insert($reviewTable, $reviewPayload)) {
        throw new \RuntimeException('No fue posible guardar la revisión.');
      }
      $reviewId = (int) $db->lastInsertId();
      if ($reviewId <= 0) {
        throw new \RuntimeException('No fue posible obtener el ID de la revisión.');
      }

      $contractPayload['id_revision_servicios_publicos'] = (string) $reviewId;
      if ($this->repo->updateContratoArrendamiento($contractPk, $contractPayload) <= 0) {
        throw new \RuntimeException('No fue posible actualizar el contrato con la revisión.');
      }
      $historyInserted = $this->repo->insertHistorialInmueble([
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_empleado' => $employeeId,
        'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
        'fecha' => $nowTs,
        'tipo_reporte' => 'Contractual',
        'observacion' => 'Se ha realizado revisión de servicios públicos en su inmueble. Servicios configurados: ' . $configuration['summary'] . '.',
        'funcionario' => $employeeName,
      ]);
      if (!$historyInserted) {
        throw new \RuntimeException('No fue posible guardar el historial del inmueble.');
      }
      $this->repo->updatePropertyRevisionCount((int) ($contract['id_inmueble'] ?? 0), $reviewCount);

      if ($startedTransaction) {
        $pdo->commit();
      }
    } catch (\Throwable $exception) {
      if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->removeGeneratedReviewDocuments($documents);
      return ['ok' => false, 'message' => 'No se pudo registrar la revisión: ' . $exception->getMessage()];
    }

    $queued = $this->queuePublicServicesReviewEmails($reviewId, $contract, $employee, $documents, $services);
    return [
      'ok' => true,
      'message' => 'Revisión agregada con éxito. Se generaron ' . count($documents) . ' actas y se encolaron ' . $queued . ' correos.',
      'review_id' => $reviewId,
      'documents' => array_values(array_map(static fn(array $document): array => [
        'title' => $document['title'],
        'url' => $document['url'],
      ], $documents)),
      'notifications_queued' => $queued,
      'ultima_revision_servicios' => $nowTs,
      'mes_revision_servicios' => $nextReviewMonth,
    ];
  }

  /**
   * Save only contract configuration: no review, PDFs, scheduling change or notification.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function saveServiciosPublicosConfiguration(int $contractId, array $input): array
  {
    $context = $this->buildServiciosPublicosReviewContext($contractId);
    if (empty($context['ok'])) {
      return $context;
    }
    $configuration = $this->validatePublicServicesConfiguration($input);
    if (empty($configuration['ok'])) {
      return $configuration;
    }
    $contract = $context['contract'];
    $employee = $context['employee'];
    $changes = array_filter($configuration['payload'], static fn($value, $key): bool => (string) ($contract[$key] ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH);
    if (empty($changes)) {
      return ['ok' => true, 'message' => 'No hay cambios en la configuración de servicios.'];
    }
    $pdo = $this->repo->getDb()->pdo();
    if ($pdo->inTransaction()) {
      return ['ok' => false, 'message' => 'Ya existe una operación en curso. Intenta nuevamente.'];
    }
    try {
      $pdo->beginTransaction();
      $now = date('Y-m-d H:i:s');
      $changedFields = array_keys($changes);
      $changes['cct_modified'] = $now;
      if ($this->repo->updateContratoArrendamiento($contractId, $changes) <= 0) {
        throw new \RuntimeException('No fue posible actualizar los servicios del contrato.');
      }
      if (!$this->repo->insertHistorialInmueble([
        'cct_status' => 'publish', 'cct_author_id' => (string) $employee['id_empleado'],
        'cct_created' => $now, 'cct_modified' => $now,
        'id_empleado' => (string) $employee['id_empleado'], 'funcionario' => (string) $employee['nombre'],
        'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'fecha' => time(), 'tipo_reporte' => 'Contractual',
        'observacion' => 'Configuración de servicios públicos del contrato #' . ($contract['contrato'] ?? $contractId)
          . ': ' . $configuration['summary'] . '. Campos corregidos: ' . implode(', ', $changedFields)
          . '. No se registró revisión ni se generaron actas.',
      ])) {
        throw new \RuntimeException('No fue posible guardar el historial de la configuración.');
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      return ['ok' => false, 'message' => 'No se pudo guardar la configuración: ' . $exception->getMessage()];
    }
    return ['ok' => true, 'message' => 'Servicios del contrato actualizados. No se generaron actas ni se enviaron correos.'];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function validatePublicServicesConfiguration(array $input): array
  {
    if (($input['configuration_present'] ?? '') !== '1') {
      return ['ok' => false, 'message' => 'Recarga el formulario para confirmar la configuración de servicios.'];
    }
    $keys = [];
    $labels = [];
    $payload = [];
    $legacyLabels = ['energia' => 'Energia', 'agua' => 'Agua', 'gas' => 'Gas'];
    foreach ((array) ($input['servicios_configurados'] ?? []) as $value) {
      $key = is_scalar($value) ? $this->normalizePublicServiceKey((string) $value) : '';
      if ($key === '') {
        return ['ok' => false, 'message' => 'La configuración contiene un servicio no válido.'];
      }
      $keys[$key] = $key;
      $definition = $this->publicServiceDefinitions()[$key];
      $labels[$key] = $legacyLabels[$key];
      $payload[$definition['contract_account_field']] = $this->cleanReviewText((string) ($input[$definition['account_field']] ?? ''));
      $payload[$definition['contract_meter_field']] = $this->cleanReviewText((string) ($input[$definition['meter_field']] ?? ''));
    }
    // An explicit empty list means no services, even if historical account fields remain.
    $payload['servicios_publicos'] = serialize(array_values($labels));
    return ['ok' => true, 'keys' => array_values($keys), 'payload' => $payload, 'summary' => $labels ? implode(', ', $labels) : 'ninguno'];
  }

  /** @param array<string,mixed> $contract @return array<string,array<string,string>> */
  private function availablePublicServices(array $contract): array
  {
    $raw = $contract['servicios_publicos'] ?? '';
    $values = [];
    if (is_array($raw)) {
      $values = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
      $unserialized = @unserialize($raw, ['allowed_classes' => false]);
      if (is_array($unserialized)) {
        $values = $unserialized;
      } else {
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : preg_split('/[,;|]+/', $raw);
      }
    }

    $keys = [];
    foreach ($values as $value) {
      $key = $this->normalizePublicServiceKey((string) $value);
      if ($key !== '') {
        $keys[$key] = true;
      }
    }
    if (empty($keys) && (!is_array($raw) && trim((string) $raw) === '')) {
      foreach ($this->publicServiceDefinitions() as $key => $definition) {
        if (trim((string) ($contract[$definition['contract_account_field']] ?? '')) !== '') {
          $keys[$key] = true;
        }
      }
    }

    $available = [];
    foreach ($this->publicServiceDefinitions() as $key => $definition) {
      if (!isset($keys[$key])) {
        continue;
      }
      $available[$key] = $definition + [
        'account' => (string) ($contract[$definition['contract_account_field']] ?? ''),
        'meter' => (string) ($contract[$definition['contract_meter_field']] ?? ''),
        'last_status' => (string) ($contract[$definition['contract_status_field']] ?? ''),
        'last_amount' => (string) ($contract[$definition['contract_amount_field']] ?? ''),
      ];
    }
    return $available;
  }

  /** @return array<string,array<string,string>> */
  private function publicServiceDefinitions(): array
  {
    return [
      'energia' => [
        'label' => 'energía', 'display_label' => 'Energía eléctrica', 'account_label' => 'NIC',
        'account_field' => 'nic', 'meter_field' => 'medidor_luz', 'status_field' => 'resultado_tiempo_luz', 'amount_field' => 'resultado_valores_luz',
        'review_account_field' => 'nic', 'review_meter_field' => 'medidor_luz', 'review_date_field' => 'fecha_revision_luz', 'review_status_field' => 'resultado_tiempo_luz', 'review_amount_field' => 'resultado_valores_luz',
        'contract_account_field' => 'luz', 'contract_meter_field' => 'medidor_luz', 'contract_date_field' => 'fecha_revision_luz', 'contract_status_field' => 'resultado_tiempo_luz', 'contract_amount_field' => 'resultado_valores_luz',
        'good_document_field' => 'acta_felicitaciones_luz', 'debt_document_field' => 'acta_mora_luz',
      ],
      'agua' => [
        'label' => 'agua', 'display_label' => 'Acueducto y alcantarillado', 'account_label' => 'Poliza',
        'account_field' => 'poliza', 'meter_field' => 'medidor_agua', 'status_field' => 'resultado_tiempo_agua', 'amount_field' => 'resultado_valores_agua',
        'review_account_field' => 'poliza', 'review_meter_field' => 'medidor_agua', 'review_date_field' => 'fecha_revision_agua', 'review_status_field' => 'resultado_tiempo_agua', 'review_amount_field' => 'resultado_valores_agua',
        'contract_account_field' => 'agua', 'contract_meter_field' => 'medidor_agua', 'contract_date_field' => 'fecha_revision_agua', 'contract_status_field' => 'resultado_tiempo_agua', 'contract_amount_field' => 'resultado_valores_agua',
        'good_document_field' => 'acta_felicitaciones_agua', 'debt_document_field' => 'acta_mora_agua',
      ],
      'gas' => [
        'label' => 'gas', 'display_label' => 'Gas natural', 'account_label' => 'Contrato',
        'account_field' => 'numero_contrato', 'meter_field' => 'medidor_gas', 'status_field' => 'resultado_tiempo_gas', 'amount_field' => 'resultado_valores_gas',
        'review_account_field' => 'numero_contrato', 'review_meter_field' => 'medidor_gas', 'review_date_field' => 'fecha_revision_gas', 'review_status_field' => 'resultado_tiempo_gas', 'review_amount_field' => 'resultado_valores_gas',
        'contract_account_field' => 'gas', 'contract_meter_field' => 'medidor_gas', 'contract_date_field' => 'fecha_revision_gas', 'contract_status_field' => 'resultado_tiempo_gas', 'contract_amount_field' => 'resultado_valores_gas',
        'good_document_field' => 'acta_felicitaciones_gas', 'debt_document_field' => 'acta_mora_gas',
      ],
    ];
  }

  private function normalizePublicServiceKey(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = preg_replace('/[^a-z]/', '', $ascii !== false ? $ascii : $value) ?? '';
    return match ($value) {
      'energia', 'luz', 'electricidad' => 'energia',
      'agua', 'acueducto', 'alcantarillado' => 'agua',
      'gas', 'gasnatural' => 'gas',
      default => '',
    };
  }

  private function canonicalReviewStatus(string $value): string
  {
    $normalized = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $ascii !== false ? $ascii : $normalized) ?? '';
    return match ($normalized) {
      'al dia' => 'Al dia',
      '30 dias', '30 dia' => '30 dias',
      '60 dias', '60 dia' => '60 dias',
      'estado critico', 'critico' => 'Estado critico',
      default => '',
    };
  }

  private function reviewAmount(string $value): int
  {
    $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
    return $digits === '' ? 0 : min(PHP_INT_MAX, (int) $digits);
  }

  private function cleanReviewText(string $value): string
  {
    $value = trim(strip_tags($value));
    return mb_substr(preg_replace('/\s+/', ' ', $value) ?? '', 0, 180, 'UTF-8');
  }

  /** @param array<string,array<string,mixed>> $documents */
  private function removeGeneratedReviewDocuments(array $documents): void
  {
    foreach ($documents as $document) {
      $path = trim((string) ($document['path'] ?? ''));
      if ($path !== '' && is_file($path)) {
        @unlink($path);
      }
    }
  }

  /**
   * @param array<string,mixed> $contract
   * @param array<string,mixed> $employee
   * @param array<string,array<string,mixed>> $documents
   * @param array<string,array<string,mixed>> $services
   */
  private function queuePublicServicesReviewEmails(int $reviewId, array $contract, array $employee, array $documents, array $services): int
  {
    $buttons = [];
    $attachments = [];
    foreach ($documents as $document) {
      $url = trim((string) ($document['url'] ?? ''));
      $path = trim((string) ($document['path'] ?? ''));
      $name = trim((string) ($document['attachment_name'] ?? 'acta.pdf'));
      if ($url !== '') {
        $buttons[] = ['url' => $url, 'label' => 'Ver ' . (string) ($document['title'] ?? 'acta')];
      }
      if ($path !== '') {
        $attachments[] = ['path' => $path, 'name' => $name];
      }
    }

    $serviceNames = array_map(static fn(array $service): string => (string) ($service['display_label'] ?? $service['label'] ?? ''), $services);
    $contractCode = trim((string) ($contract['contrato'] ?? '')) ?: (string) ($contract['_ID'] ?? '');
    $property = trim((string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''));
    $subject = 'Revisión de servicios públicos del contrato #' . $contractCode;
    $reviewUrl = 'https://sucasainmobiliaria.com.co/revision-de-servicios-publicos/?numero=' . rawurlencode((string) $reviewId);
    array_unshift($buttons, ['url' => $reviewUrl, 'label' => 'Ver revisión completa']);
    $replyTo = trim((string) ($employee['correo'] ?? ''));
    $queue = new EmailQueue($this->repo->getDb());
    $queued = 0;

    $recipients = [
      ['email' => (string) ($contract['correo_propietario'] ?? ''), 'name' => (string) ($contract['propietario'] ?? ''), 'role' => 'propietario'],
      ['email' => (string) ($contract['correo_arrendatario'] ?? ''), 'name' => (string) ($contract['arrendatario'] ?? ''), 'role' => 'arrendatario'],
      ['email' => 'sucasa.inmobiliaria@hotmail.com', 'name' => 'Administracion SuCasa', 'role' => 'administracion'],
      ['email' => 'nabuita1@gmail.com', 'name' => 'Administracion SuCasa', 'role' => 'administracion'],
      ['email' => 'gcorrearivera@gmail.com', 'name' => 'Administracion SuCasa', 'role' => 'administracion'],
      ['email' => 'sucasacorreos@gmail.com', 'name' => 'Administracion SuCasa', 'role' => 'administracion'],
    ];

    foreach ($recipients as $recipient) {
      $email = trim($recipient['email']);
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $name = trim($recipient['name']) ?: 'Usuario';
      $content = '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . EmailTemplate::e($name) . ':</p>';
      $content .= '<p style="margin:0 0 14px;line-height:1.65;">Se registró la revisión de servicios públicos del contrato <b>#' . EmailTemplate::e($contractCode) . '</b>, inmueble <b>#' . EmailTemplate::e($property) . '</b>, ubicado en ' . EmailTemplate::e((string) ($contract['direccion'] ?? '')) . '.</p>';
      $content .= '<p style="margin:0 0 14px;line-height:1.65;"><b>Servicios revisados:</b> ' . EmailTemplate::e(implode(', ', array_filter($serviceNames))) . '.</p>';
      $content .= '<p style="margin:0;line-height:1.65;">Las actas correspondientes se adjuntan al correo y también están disponibles en los botones de consulta.</p>';
      $html = EmailTemplate::render($subject, $content, ['buttons' => $buttons]);
      $queued += $queue->enqueue($email, $subject, $html, [
        'source_module' => 'revision-servicios-publicos-nativa',
        'destination_name' => $name,
        'dedupe_key' => 'revision_servicios_publicos_' . $reviewId,
        'payload' => [
          'attachments' => $attachments,
          'reply_to' => $replyTo,
        ],
        'meta' => [
          'review_id' => $reviewId,
          'contract_id' => (int) ($contract['_ID'] ?? 0),
          'recipient_role' => $recipient['role'],
          'documents' => array_values(array_map(static fn(array $document): array => [
            'title' => (string) ($document['title'] ?? ''),
            'url' => (string) ($document['url'] ?? ''),
          ], $documents)),
        ],
      ]);
    }

    return $queued;
  }
}
