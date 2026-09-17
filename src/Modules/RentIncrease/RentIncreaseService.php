<?php

declare(strict_types=1);

namespace SCM\Modules\RentIncrease;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;
use SCM\Support\StoredFileService;

final class RentIncreaseService
{
  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  /** @param array<string,mixed> $filters @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int} */
  public function activeContracts(array $filters): array
  {
    return $this->contracts($filters, 'contracts');
  }

  /** @param array<string,mixed> $filters @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int} */
  public function letters(array $filters, string $type): array
  {
    $type = $type === 'administracion' ? 'administracion' : 'canon';
    return $this->contracts($filters, $type);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public function createLetter(array $input): array
  {
    $type = $this->sanitizeType((string) ($input['type'] ?? ''));
    $contractId = (int) ($input['contract_id'] ?? 0);
    if ($contractId <= 0) {
      throw new \InvalidArgumentException('Selecciona un contrato valido.');
    }
    $contract = $this->contractById($contractId);
    if (!is_array($contract)) {
      throw new \RuntimeException('Contrato no encontrado.');
    }
    if (strtolower(trim((string) ($contract['estado'] ?? ''))) !== 'entregado') {
      throw new \RuntimeException('Solo se pueden crear cartas para contratos activos/entregados.');
    }

    $fechaTs = $this->parseDate((string) ($input['fecha'] ?? date('Y-m-d')));
    if ($fechaTs <= 0) {
      throw new \InvalidArgumentException('La fecha de la carta es obligatoria.');
    }

    $amountField = $type === 'canon' ? 'canon' : 'administracion';
    $amount = $this->money((string) ($input[$amountField] ?? ''));
    if ($amount <= 0) {
      throw new \InvalidArgumentException('El nuevo valor debe ser mayor a cero.');
    }

    $context = $this->context($contract, $input, $type, $fechaTs, $amount);
    $document = (new RentIncreasePdfGenerator())->generate($type, $context);
    $letterId = 0;
    $pdo = $this->db->pdo();
    try {
      $pdo->beginTransaction();
      $letterId = $this->insertLetter($type, $context, $document);
      $this->updateContract($contractId, $type, $fechaTs, $amount, $letterId, (string) $document['url'], $context);
      $this->insertPropertyHistory($type, $context);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if (is_file((string) $document['path'])) {
        @unlink((string) $document['path']);
      }
      throw $exception;
    }

    $queued = $this->queueNotifications($type, $context, $letterId, $document);
    return [
      'ok' => true,
      'message' => 'Carta de aumento creada y notificaciones encoladas.',
      'letter_id' => $letterId,
      'letter_url' => (string) $document['url'],
      'queued' => $queued,
    ];
  }

  /** @param array<string,mixed> $filters @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int} */
  private function contracts(array $filters, string $scope): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 30, 'total_pages' => 1];
    }

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 30)));
    $where = ["LOWER(TRIM(COALESCE(`estado`, ''))) = 'entregado'"];
    $args = [];

    foreach ([
      'contrato' => ['contrato'],
      'inmueble' => ['inmueble', 'id_inmueble'],
      'propietario' => ['propietario', 'id_propietario'],
      'arrendatario' => ['arrendatario', 'id_arrendatario'],
    ] as $key => $columns) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value === '') {
        continue;
      }
      $parts = [];
      foreach ($columns as $column) {
        if ($this->schema->columnExists($table, $column)) {
          $parts[] = "COALESCE(`{$column}`, '') LIKE ?";
          $args[] = '%' . $this->db->escapeLike($value) . '%';
        }
      }
      if ($parts !== []) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    foreach ([
      'canon_from' => ['fecha_incremento_canon', '>='],
      'canon_to' => ['fecha_incremento_canon', '<='],
      'admin_from' => ['fecha_incremento_admin', '>='],
      'admin_to' => ['fecha_incremento_admin', '<='],
    ] as $key => [$column, $op]) {
      $ts = $this->parseDate((string) ($filters[$key] ?? ''));
      if ($ts > 0 && $this->schema->columnExists($table, $column)) {
        $where[] = "CAST(COALESCE(`{$column}`, 0) AS UNSIGNED) {$op} ?";
        $args[] = $ts;
      }
    }

    $month = max(0, min(12, (int) ($filters['month'] ?? 0)));
    if ($month > 0 && $this->schema->columnExists($table, 'fin_contrato')) {
      $where[] = "MONTH(FROM_UNIXTIME(CAST(COALESCE(`fin_contrato`, 0) AS UNSIGNED))) = ?";
      $args[] = $month;
    }

    if ($scope === 'canon') {
      $where[] = $this->schema->columnExists($table, 'id_carta_aumento_canon') ? "TRIM(COALESCE(`id_carta_aumento_canon`, '')) <> ''" : '0=1';
    } elseif ($scope === 'administracion') {
      $where[] = $this->schema->columnExists($table, 'id_carta_aumento_admin') ? "TRIM(COALESCE(`id_carta_aumento_admin`, '')) <> ''" : '0=1';
    }

    $whereSql = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$whereSql}", $args);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = max(0, ($page - 1) * $perPage);

    $columns = ['_ID', 'contrato', 'inmueble', 'id_inmueble', 'direccion', 'propietario', 'arrendatario', 'correo_propietario', 'correo_arrendatario', 'celular_propietario', 'celular_arrendatario', 'valor_canon', 'valor_administracion', 'inicio_contrato', 'fin_contrato', 'fecha_incremento_canon', 'porcentaje_incremento_canon', 'id_carta_aumento_canon', 'carta_aumento_canon', 'fecha_incremento_admin', 'porcentaje_incremento_admin', 'id_carta_aumento_admin', 'carta_aumento_admin', 'sucursal', 'id_sucursal'];
    $select = [];
    foreach ($columns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    $select = $select !== [] ? $select : ['`_ID`'];
    $rows = $this->db->getResults(
      "SELECT " . implode(', ', $select) . " FROM `{$table}` WHERE {$whereSql} ORDER BY `_ID` DESC LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages];
  }

  /** @return array<string,mixed>|null */
  private function contractById(int $id): ?array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    return $this->schema->tableExists($table) ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) : null;
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $input @return array<string,mixed> */
  private function context(array $contract, array $input, string $type, int $fechaTs, float $amount): array
  {
    $branch = $this->branch((string) ($contract['id_sucursal'] ?? $contract['sucursal'] ?? ''));
    $employee = $this->currentEmployee();
    $contractualCoordinator = $this->contractualCoordinator();
    $legalRepresentative = $this->generalManagerRepresentative();
    $ctx = array_merge($contract, [
      'fecha_ts' => $fechaTs,
      'fecha' => date('Y-m-d', $fechaTs),
      'tipo_carta' => $type === 'canon' ? 'Aumento de canon' : 'Aumento de administracion',
      'id_empleado' => (string) ($employee['id_empleado'] ?? Auth::employeeId() ?: Auth::userId()),
      'creador' => (string) ($employee['nombre'] ?? Auth::user()),
      'contractual' => $this->firstNonEmpty([$contractualCoordinator['nombre'] ?? '', $branch['nombre_contractual'] ?? '', $branch['nombre'] ?? '', 'Coordinador Contractual, Mantenimiento y Servicios Públicos']),
      'correo_contractual' => $this->firstNonEmpty([$contractualCoordinator['correo'] ?? '', $branch['correo_contractual'] ?? '', $branch['correo'] ?? '']),
      'celular_contractual' => $this->firstNonEmpty([$contractualCoordinator['celular'] ?? '', $branch['celular_contractual'] ?? '', $branch['telefono'] ?? '']),
      'representante_legal' => $this->firstNonEmpty([$legalRepresentative['nombre'] ?? '', $branch['representante_legal'] ?? '', 'Representante legal']),
      'correo_representante_legal' => $this->firstNonEmpty([$legalRepresentative['correo'] ?? '', $branch['correo_legal'] ?? '', $branch['correo_representante_legal'] ?? '']),
      'celular_representante_legal' => $this->firstNonEmpty([$legalRepresentative['celular'] ?? '', $branch['celular_legal'] ?? '', $branch['celular_representante_legal'] ?? '']),
      'cargo_representante_legal' => $this->firstNonEmpty([$legalRepresentative['cargo'] ?? '', 'Gerente General']),
      'firma_representante_legal' => (string) ($legalRepresentative['firma'] ?? ''),
      'firma_representante_legal_path' => (string) ($legalRepresentative['firma_path'] ?? ''),
      'ciudad' => trim((string) ($input['ciudad'] ?? $branch['ciudad'] ?? 'Cartagena de Indias')),
      'incremento' => trim((string) ($input['incremento'] ?? '')),
      'canon' => $type === 'canon' ? $amount : $this->money((string) ($contract['valor_canon'] ?? '0')),
      'canon_letras' => $this->moneyToSpanish($type === 'canon' ? $amount : $this->money((string) ($contract['valor_canon'] ?? '0'))),
      'administracion' => $type === 'administracion' ? $amount : $this->money((string) ($contract['valor_administracion'] ?? '0')),
      'administracion_letras' => $this->moneyToSpanish($type === 'administracion' ? $amount : $this->money((string) ($contract['valor_administracion'] ?? '0'))),
      'vigencia_ts' => $this->parseDate((string) ($input['vigencia_aumento'] ?? $input['fecha'] ?? '')) ?: $fechaTs,
      'texto_retroactivos' => $this->retroactiveText($input),
    ]);
    return $ctx;
  }

  /** @param array<string,mixed> $context @param array<string,string> $document */
  private function insertLetter(string $type, array $context, array $document): int
  {
    $table = $this->db->table('jet_cct_cartas_aumento');
    if (!$this->schema->tableExists($table)) {
      throw new \RuntimeException('No existe la tabla de cartas de aumento.');
    }
    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => (string) ($context['id_empleado'] ?? ''),
      'cct_created' => date('Y-m-d H:i:s'),
      'cct_modified' => date('Y-m-d H:i:s'),
      'fecha' => (int) ($context['fecha_ts'] ?? time()),
      'id_empleado' => (string) ($context['id_empleado'] ?? ''),
      'contractual' => (string) ($context['contractual'] ?? ''),
      'celular_contractual' => (string) ($context['celular_contractual'] ?? ''),
      'correo_contractual' => (string) ($context['correo_contractual'] ?? ''),
      'representante_legal' => (string) ($context['representante_legal'] ?? ''),
      'representante_legal_celular' => (string) ($context['celular_representante_legal'] ?? ''),
      'representante_legal_correo' => (string) ($context['correo_representante_legal'] ?? ''),
      'representante_legal_cargo' => (string) ($context['cargo_representante_legal'] ?? ''),
      'representante_legal_firma' => (string) ($context['firma_representante_legal'] ?? ''),
      'arrendatario' => (string) ($context['arrendatario'] ?? ''),
      'arrendatario_correo' => (string) ($context['correo_arrendatario'] ?? ''),
      'arrendatario_celular' => (string) ($context['celular_arrendatario'] ?? ''),
      'propietario' => (string) ($context['propietario'] ?? ''),
      'propietario_correo' => (string) ($context['correo_propietario'] ?? ''),
      'propietario_celular' => (string) ($context['celular_propietario'] ?? ''),
      'creador' => (string) ($context['creador'] ?? ''),
      'contrato' => (string) ($context['contrato'] ?? ''),
      'inmueble' => (string) ($context['inmueble'] ?? ''),
      'direccion' => (string) ($context['direccion'] ?? ''),
      'incremento' => (string) ($context['incremento'] ?? ''),
      'canon' => (string) ($context['canon'] ?? ''),
      'canon_letras' => (string) ($context['canon_letras'] ?? ''),
      'administracion' => (string) ($context['administracion'] ?? ''),
      'administracion__letras' => (string) ($context['administracion_letras'] ?? ''),
      'vigencia_administracion' => (int) ($context['vigencia_ts'] ?? 0),
      'texto_retroactivos' => (string) ($context['texto_retroactivos'] ?? ''),
      'carta_pdf' => (string) ($document['url'] ?? ''),
      'id_contrato' => (string) ($context['_ID'] ?? ''),
      'id_inmueble' => (string) ($context['id_inmueble'] ?? ''),
      'id_inmueble_data' => (string) ($context['id_inmueble_data'] ?? ''),
      'sucursal' => (string) ($context['id_sucursal'] ?? $context['sucursal'] ?? ''),
      'tipo_carta' => $type === 'canon' ? 'Aumento de canon' : 'Aumento de administracion',
    ];
    $payload = $this->schema->filterTableData($table, $payload);
    if ($payload === []) {
      throw new \RuntimeException('No hay campos compatibles para guardar la carta de aumento.');
    }
    $this->db->insert($table, $payload);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $context */
  private function updateContract(int $contractId, string $type, int $fechaTs, float $amount, int $letterId, string $url, array $context): void
  {
    $data = $type === 'canon'
      ? ['id_carta_aumento_canon' => $letterId, 'carta_aumento_canon' => $url, 'fecha_incremento_canon' => $fechaTs, 'porcentaje_incremento_canon' => (string) ($context['incremento'] ?? ''), 'valor_canon' => $amount]
      : ['id_carta_aumento_admin' => $letterId, 'carta_aumento_admin' => $url, 'fecha_incremento_admin' => $fechaTs, 'porcentaje_incremento_admin' => (string) ($context['incremento'] ?? ''), 'valor_administracion' => $amount];
    $data['cct_modified'] = date('Y-m-d H:i:s');
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $data = $this->schema->filterTableData($table, $data);
    if ($data !== []) {
      $this->db->update($table, $data, ['_ID' => $contractId]);
    }
  }

  /** @param array<string,mixed> $context */
  private function insertPropertyHistory(string $type, array $context): void
  {
    $table = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => (string) ($context['id_empleado'] ?? ''),
      'id_inmueble' => (string) ($context['id_inmueble'] ?? ''),
      'id_inmueble_data' => (string) ($context['id_inmueble_data'] ?? ''),
      'id_empleado' => (string) ($context['id_empleado'] ?? ''),
      'nombre_empleado' => (string) ($context['creador'] ?? ''),
      'funcionario' => (string) ($context['contractual'] ?? ''),
      'fecha' => (int) ($context['fecha_ts'] ?? time()),
      'tipo_reporte' => $type === 'canon' ? 'Aumento de canon' : 'Aumento de administracion',
      'observacion' => $type === 'canon' ? 'Se aumentó el precio del canon de su inmueble.' : 'Se aumentó la cuota de administración de su inmueble.',
    ];
    $payload = $this->schema->filterTableData($table, $payload);
    if ($payload !== []) {
      $this->db->insert($table, $payload);
    }
  }

  /** @param array<string,mixed> $context @param array<string,string> $document @return array{email:int,whatsapp:int,internal:int} */
  private function queueNotifications(string $type, array $context, int $letterId, array $document): array
  {
    $subject = $type === 'canon'
      ? 'Notificación de aumento de canon del contrato #' . (string) ($context['contrato'] ?? '')
      : 'Notificación de aumento de la cuota de administración del contrato #' . (string) ($context['contrato'] ?? '');
    $letterUrl = (string) ($document['url'] ?? '');
    $attachments = [[
      'path' => (string) ($document['path'] ?? ''),
      'name' => (string) ($document['attachment_name'] ?? 'carta-aumento.pdf'),
    ]];
    $emailQueue = new EmailQueue($this->db);
    $emailQueued = 0;
    $emailRecipients = [
      ['email' => (string) ($context['correo_arrendatario'] ?? ''), 'name' => (string) ($context['arrendatario'] ?? ''), 'role' => 'arrendatario'],
      ['email' => (string) ($context['correo_propietario'] ?? ''), 'name' => (string) ($context['propietario'] ?? ''), 'role' => 'propietario'],
    ];
    foreach ($emailRecipients as $recipient) {
      if (!filter_var(trim($recipient['email']), FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $html = EmailTemplate::render($subject, $this->emailBody($type, $context, $recipient['name'], $recipient['role']), [
        'buttons' => [['url' => $letterUrl, 'label' => 'Ver carta de aumento']],
      ]);
      $emailQueued += $emailQueue->enqueue($recipient['email'], $subject, $html, [
        'source_module' => 'cartas_aumento_arrendamiento',
        'destination_name' => $recipient['name'],
        'dedupe_key' => 'carta_aumento_' . $type . '_' . $letterId,
        'payload' => ['attachments' => $attachments, 'reply_to' => (string) ($context['correo_contractual'] ?? '')],
        'meta' => ['letter_id' => $letterId, 'contract_id' => (int) ($context['_ID'] ?? 0), 'recipient_role' => $recipient['role'], 'letter_url' => $letterUrl],
      ]);
    }

    $internalQueued = 0;
    foreach (InternalNotificationRecipients::emailsForAction($this->db, $type === 'canon' ? 'carta_aumento_canon' : 'carta_aumento_administracion') as $email) {
      $html = EmailTemplate::render($subject, $this->emailBody($type, $context, 'equipo administrativo', 'administracion'), [
        'buttons' => [['url' => $letterUrl, 'label' => 'Ver carta de aumento']],
      ]);
      $internalQueued += $emailQueue->enqueue($email, $subject, $html, [
        'source_module' => 'cartas_aumento_arrendamiento_interno',
        'destination_name' => 'Administración',
        'dedupe_key' => 'carta_aumento_internal_' . $type . '_' . $letterId,
        'payload' => ['attachments' => $attachments],
        'meta' => ['letter_id' => $letterId, 'contract_id' => (int) ($context['_ID'] ?? 0), 'recipient_role' => 'interno', 'letter_url' => $letterUrl],
      ]);
    }

    $whatsappQueued = 0;
    $sms = new SmsQueue($this->db);
    foreach ([
      ['phone' => (string) ($context['celular_arrendatario'] ?? ''), 'name' => (string) ($context['arrendatario'] ?? 'Arrendatario')],
      ['phone' => (string) ($context['celular_propietario'] ?? ''), 'name' => (string) ($context['propietario'] ?? 'Propietario')],
    ] as $recipient) {
      $message = $this->whatsappMessage($type, $context, $letterUrl);
      $ok = $sms->enqueue($recipient['phone'], $recipient['name'], $message, [
        'source_module' => 'cartas_aumento_arrendamiento',
        'dedupe_key' => 'carta_aumento_whatsapp_' . $type . '_' . $letterId,
        'template_name' => 'scm_carta_aumento_arrendamiento_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'header',
            'parameters' => [[
              'type' => 'document',
              'document' => [
                'link' => $letterUrl,
                'filename' => (string) ($document['attachment_name'] ?? 'carta-aumento.pdf'),
              ],
            ]],
          ],
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $this->waText($recipient['name'])],
              ['type' => 'text', 'text' => $this->waText($type === 'canon' ? 'canon de arrendamiento' : 'cuota de administración')],
              ['type' => 'text', 'text' => $this->waText((string) ($context['contrato'] ?? '-'))],
              ['type' => 'text', 'text' => $this->waText((string) ($context['id_inmueble'] ?? $context['inmueble'] ?? '-'))],
              ['type' => 'text', 'text' => $this->waText((string) ($context['direccion'] ?? '-'))],
              ['type' => 'text', 'text' => $this->waText($this->signatureLine($context))],
            ],
          ],
        ],
        'letter_id' => $letterId,
        'contract_id' => (int) ($context['_ID'] ?? 0),
        'letter_url' => $letterUrl,
        'document_url' => $letterUrl,
        'document_filename' => (string) ($document['attachment_name'] ?? 'carta-aumento.pdf'),
      ]);
      $whatsappQueued += $ok ? 1 : 0;
    }

    return ['email' => $emailQueued, 'whatsapp' => $whatsappQueued, 'internal' => $internalQueued];
  }

  private function emailBody(string $type, array $context, string $name, string $role): string
  {
    $name = trim($name) !== '' ? $name : 'Usuario';
    $kind = $type === 'canon' ? 'canon de arrendamiento' : 'cuota de administración';
    $contract = (string) ($context['contrato'] ?? '');
    $property = (string) ($context['id_inmueble'] ?? $context['inmueble'] ?? '');
    $address = (string) ($context['direccion'] ?? '');
    $text = $role === 'propietario'
      ? 'Le informamos que el ajuste fue registrado y notificado al arrendatario.'
      : 'Nos permitimos informarle que se registró el aumento correspondiente.';
    return '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . EmailTemplate::e($name) . ':</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">' . EmailTemplate::e($text) . ' Contrato <b>#' . EmailTemplate::e($contract) . '</b>, inmueble <b>#' . EmailTemplate::e($property) . '</b>, ubicado en <b>' . EmailTemplate::e($address) . '</b>.</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">La carta de aumento de ' . EmailTemplate::e($kind) . ' está disponible para consulta y se adjunta al correo cuando el transportador compartido lo soporte.</p>'
      . '<p style="margin:0;line-height:1.65;">Cordialmente: <b>' . EmailTemplate::e($this->signatureLine($context)) . '</b>.</p>';
  }

  /** @param array<string,mixed> $context */
  private function whatsappMessage(string $type, array $context, string $letterUrl): string
  {
    $kind = $type === 'canon' ? 'canon de arrendamiento' : 'cuota de administración';
    return 'Se registró la carta de aumento de ' . $kind . ' del contrato #' . (string) ($context['contrato'] ?? '') . ', inmueble #' . (string) ($context['id_inmueble'] ?? $context['inmueble'] ?? '') . ', dirección ' . (string) ($context['direccion'] ?? '') . '. Consulte la carta aquí: ' . $letterUrl;
  }

  /** @param array<string,mixed> $context */
  private function signatureLine(array $context): string
  {
    return trim((string) ($context['contractual'] ?? 'Coordinador Contractual') . ' - ' . (string) ($context['celular_contractual'] ?? ''), " \t\n\r\0\x0B-");
  }

  private function waText(string $text): string
  {
    $text = preg_replace('/[\r\n\t]+/u', ' | ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')) ?? $text;
    $text = preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    return mb_substr(trim($text, " \t\n\r\0\x0B|"), 0, 1024, 'UTF-8');
  }

  /** @return array<string,mixed> */
  private function branch(string $id): array
  {
    $table = $this->db->table('jet_cct_sucursales');
    if ($id === '' || !$this->schema->tableExists($table)) {
      return [];
    }
    $where = [];
    $args = [];
    foreach (['_ID', 'id_sucursal'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $where[] = "CAST(`{$column}` AS CHAR) = ?";
        $args[] = $id;
      }
    }
    return $where !== [] ? ($this->db->getRow("SELECT * FROM `{$table}` WHERE (" . implode(' OR ', $where) . ") LIMIT 1", $args) ?: []) : [];
  }

  /** @return array<string,mixed> */
  private function currentEmployee(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $userId = (int) Auth::userId();
    $employeeId = Auth::employeeId();
    if ($userId <= 0 || !$this->schema->tableExists($table)) {
      return ['id_empleado' => $employeeId !== '' ? $employeeId : (string) $userId, 'nombre' => Auth::user()];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$userId]) ?: ['id_empleado' => $employeeId !== '' ? $employeeId : (string) $userId, 'nombre' => Auth::user()];
  }

  /** @return array{nombre:string,correo:string,celular:string,cargo:string,firma:string,firma_path:string}|array{} */
  private function contractualCoordinator(): array
  {
    return $this->employeeByCargo(
      ["LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%contractual%'"],
      "CASE WHEN LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%coordinador%' OR LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%cordinador%' THEN 0 ELSE 1 END, f.`_ID` ASC"
    );
  }

  /** @return array{nombre:string,correo:string,celular:string,cargo:string,firma:string,firma_path:string}|array{} */
  private function generalManagerRepresentative(): array
  {
    return $this->employeeByCargo([
      "(LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%gerente%' OR LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%gerencia%')",
      "LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%general%'",
    ], 'f.`_ID` ASC');
  }

  /**
   * @param string[] $cargoWhere
   * @return array{nombre:string,correo:string,celular:string,cargo:string,firma:string,firma_path:string}|array{}
   */
  private function employeeByCargo(array $cargoWhere, string $orderBy): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $cargoTable = $this->db->table('jet_cct_cargos');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $nameColumn = $this->schema->detectFirstExistingColumn($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    $emailColumn = $this->schema->detectFirstExistingColumn($table, ['correo', 'correo_dian', 'email']);
    $phoneColumn = $this->schema->detectFirstExistingColumn($table, ['celular', 'telefono', 'phone']);
    $signatureColumn = $this->schema->detectFirstExistingColumn($table, [
      'firma',
      'firma_imagen',
      'imagen_firma',
      'firma_digital',
      'firma_representante_legal',
      'firma_funcionario',
      'imagen_firma_funcionario',
    ]);
    $cargoColumn = $this->schema->columnExists($table, 'id_cargo') ? 'id_cargo' : '';
    $activeColumn = $this->schema->detectFirstExistingColumn($table, ['activo', 'cct_status']);
    $hasCargoNames = $cargoColumn !== ''
      && $this->schema->tableExists($cargoTable)
      && $this->schema->columnExists($cargoTable, '_ID')
      && $this->schema->columnExists($cargoTable, 'nombre_cargo');
    if (!$hasCargoNames) {
      return [];
    }

    $select = [
      $nameColumn !== '' ? "TRIM(COALESCE(f.`{$nameColumn}`, '')) AS nombre" : "'' AS nombre",
      $emailColumn !== '' ? "TRIM(COALESCE(f.`{$emailColumn}`, '')) AS correo" : "'' AS correo",
      $phoneColumn !== '' ? "TRIM(COALESCE(f.`{$phoneColumn}`, '')) AS celular" : "'' AS celular",
      $signatureColumn !== '' ? "TRIM(COALESCE(f.`{$signatureColumn}`, '')) AS firma" : "'' AS firma",
      "TRIM(COALESCE(c.`nombre_cargo`, '')) AS cargo",
    ];
    $where = array_values(array_filter(array_map('trim', $cargoWhere)));
    if ($where === []) {
      return [];
    }
    if ($activeColumn !== '') {
      if ($activeColumn === 'cct_status') {
        $where[] = "LOWER(TRIM(COALESCE(f.`{$activeColumn}`, 'publish'))) IN ('publish', 'published', 'si', 'sí', '1', 'true', 'activo', 'active')";
      } else {
        $where[] = "LOWER(TRIM(COALESCE(f.`{$activeColumn}`, 'si'))) IN ('si', 'sí', '1', 'true', 'activo', 'active', 'publish', 'published')";
      }
    }
    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select)
        . " FROM `{$table}` f"
        . " INNER JOIN `{$cargoTable}` c ON TRIM(COALESCE(f.`{$cargoColumn}`, '')) = CAST(c.`_ID` AS CHAR)"
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderBy . ' LIMIT 1'
    );
    if (!is_array($row) || trim((string) ($row['nombre'] ?? '')) === '') {
      return [];
    }
    $signature = trim((string) ($row['firma'] ?? ''));
    return [
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'correo' => trim((string) ($row['correo'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
      'cargo' => trim((string) ($row['cargo'] ?? 'Gerente General')),
      'firma' => $signature,
      'firma_path' => $this->localFilePath($signature),
    ];
  }

  private function localFilePath(string $value): string
  {
    $value = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
    if ($value === '') {
      return '';
    }
    if (is_file($value)) {
      return $value;
    }
    $urlPath = trim((string) (parse_url($value, PHP_URL_PATH) ?? ''));
    if ($urlPath !== '') {
      foreach ($this->wordpressUploadPathCandidates($urlPath) as $candidate) {
        if (is_file($candidate)) {
          return $candidate;
        }
      }
    }
    $query = [];
    $urlQuery = (string) (parse_url($value, PHP_URL_QUERY) ?? '');
    if ($urlQuery !== '') {
      parse_str($urlQuery, $query);
      $name = basename((string) ($query['n'] ?? ''));
      if ($name !== '') {
        $path = StoredFileService::fromRuntime()->pathFor($name);
        if (is_string($path) && $path !== '') {
          return $path;
        }
      }
    }
    $basename = basename((string) (parse_url($value, PHP_URL_PATH) ?? $value));
    if ($basename !== '') {
      $path = StoredFileService::fromRuntime()->pathFor($basename);
      if (is_string($path) && $path !== '') {
        return $path;
      }
      $uploadPath = rtrim((string) SCM_UPLOAD_PATH, '/\\') . '/' . $basename;
      if (is_file($uploadPath)) {
        return $uploadPath;
      }
    }
    return '';
  }

  /** @return string[] */
  private function wordpressUploadPathCandidates(string $urlPath): array
  {
    $path = '/' . ltrim(str_replace('\\', '/', $urlPath), '/');
    $pos = stripos($path, '/wp-content/uploads/');
    if ($pos === false) {
      return [];
    }
    $relative = ltrim(substr($path, $pos), '/');
    $uploadRelative = ltrim(substr($path, $pos + strlen('/wp-content/uploads/')), '/');
    $roots = array_values(array_unique(array_filter([
      dirname((string) SCM_ROOT),
      dirname((string) SCM_ROOT, 2),
      (string) SCM_ROOT,
      trim((string) getenv('SCM_WORDPRESS_ROOT')),
      trim((string) getenv('WP_ROOT')),
    ])));
    $candidates = [];
    foreach ($roots as $root) {
      $root = rtrim($root, '/\\');
      $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
      $candidates[] = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadRelative);
    }
    return array_values(array_unique($candidates));
  }

  private function sanitizeType(string $type): string
  {
    $type = strtolower(trim($type));
    if (!in_array($type, ['canon', 'administracion'], true)) {
      throw new \InvalidArgumentException('Tipo de carta no valido.');
    }
    return $type;
  }

  private function parseDate(string $value): int
  {
    $value = trim($value);
    if ($value === '') {
      return 0;
    }
    if (ctype_digit($value)) {
      return (int) $value;
    }
    $ts = strtotime($value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function money(string $value): float
  {
    $value = preg_replace('/[^\d,.\-]/', '', trim($value)) ?? '';
    if ($value === '') {
      return 0.0;
    }
    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
      $value = str_replace('.', '', $value);
      $value = str_replace(',', '.', $value);
    } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value) === 1) {
      $value = str_replace('.', '', $value);
    } elseif (strpos($value, ',') !== false) {
      $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
  }

  private function retroactiveText(array $input): string
  {
    $has = strtolower(trim((string) ($input['tiene_retroactivos'] ?? '')));
    if (!in_array($has, ['si', 'sí', '1', 'yes'], true)) {
      return '';
    }
    $value = $this->money((string) ($input['retroactivo_administracion'] ?? ''));
    $from = $this->parseDate((string) ($input['mes_inicio'] ?? ''));
    $to = $this->parseDate((string) ($input['mes_final'] ?? ''));
    $parts = ['Adicionalmente, se registra retroactivo de administración por $' . number_format($value, 0, ',', '.')];
    if ($from > 0 || $to > 0) {
      $parts[] = 'correspondiente al periodo ' . ($from > 0 ? date('d/m/Y', $from) : '-') . ' a ' . ($to > 0 ? date('d/m/Y', $to) : '-');
    }
    return implode(' ', $parts) . '.';
  }

  private function moneyToSpanish(float $amount): string
  {
    $value = (int) round($amount);
    if ($value <= 0) {
      return 'CERO PESOS';
    }
    $unit = $value === 1 ? 'PESO' : 'PESOS';
    return $this->apocopateOne($this->numberToSpanish($value)) . ' ' . $unit;
  }

  private function numberToSpanish(int $value): string
  {
    if ($value <= 0) {
      return 'CERO';
    }
    if ($value < 1000) {
      return $this->underThousandToSpanish($value);
    }

    $parts = [];
    $billions = intdiv($value, 1000000000);
    $value %= 1000000000;
    if ($billions > 0) {
      $parts[] = $billions === 1 ? 'MIL MILLONES' : $this->apocopateOne($this->numberToSpanish($billions)) . ' MIL MILLONES';
    }

    $millions = intdiv($value, 1000000);
    $value %= 1000000;
    if ($millions > 0) {
      $parts[] = $millions === 1 ? 'UN MILLÓN' : $this->apocopateOne($this->numberToSpanish($millions)) . ' MILLONES';
    }

    $thousands = intdiv($value, 1000);
    $value %= 1000;
    if ($thousands > 0) {
      $parts[] = $thousands === 1 ? 'MIL' : $this->apocopateOne($this->underThousandToSpanish($thousands)) . ' MIL';
    }

    if ($value > 0) {
      $parts[] = $this->underThousandToSpanish($value);
    }

    return implode(' ', $parts);
  }

  private function underThousandToSpanish(int $value): string
  {
    $units = [
      0 => '',
      1 => 'UNO',
      2 => 'DOS',
      3 => 'TRES',
      4 => 'CUATRO',
      5 => 'CINCO',
      6 => 'SEIS',
      7 => 'SIETE',
      8 => 'OCHO',
      9 => 'NUEVE',
      10 => 'DIEZ',
      11 => 'ONCE',
      12 => 'DOCE',
      13 => 'TRECE',
      14 => 'CATORCE',
      15 => 'QUINCE',
      16 => 'DIECISÉIS',
      17 => 'DIECISIETE',
      18 => 'DIECIOCHO',
      19 => 'DIECINUEVE',
      20 => 'VEINTE',
      21 => 'VEINTIUNO',
      22 => 'VEINTIDÓS',
      23 => 'VEINTITRÉS',
      24 => 'VEINTICUATRO',
      25 => 'VEINTICINCO',
      26 => 'VEINTISÉIS',
      27 => 'VEINTISIETE',
      28 => 'VEINTIOCHO',
      29 => 'VEINTINUEVE',
    ];
    if ($value < 30) {
      return $units[$value];
    }

    $hundreds = [
      1 => 'CIENTO',
      2 => 'DOSCIENTOS',
      3 => 'TRESCIENTOS',
      4 => 'CUATROCIENTOS',
      5 => 'QUINIENTOS',
      6 => 'SEISCIENTOS',
      7 => 'SETECIENTOS',
      8 => 'OCHOCIENTOS',
      9 => 'NOVECIENTOS',
    ];
    if ($value === 100) {
      return 'CIEN';
    }
    if ($value >= 100) {
      $hundred = intdiv($value, 100);
      $rest = $value % 100;
      return trim($hundreds[$hundred] . ($rest > 0 ? ' ' . $this->underThousandToSpanish($rest) : ''));
    }

    $tens = [
      3 => 'TREINTA',
      4 => 'CUARENTA',
      5 => 'CINCUENTA',
      6 => 'SESENTA',
      7 => 'SETENTA',
      8 => 'OCHENTA',
      9 => 'NOVENTA',
    ];
    $ten = intdiv($value, 10);
    $unit = $value % 10;
    return $tens[$ten] . ($unit > 0 ? ' Y ' . $units[$unit] : '');
  }

  private function apocopateOne(string $text): string
  {
    $text = trim($text);
    $text = preg_replace('/VEINTIUNO$/u', 'VEINTIÚN', $text) ?? $text;
    $text = preg_replace('/ Y UNO$/u', ' Y UN', $text) ?? $text;
    $text = preg_replace('/ UNO$/u', ' UN', $text) ?? $text;
    return $text === 'UNO' ? 'UN' : $text;
  }

  /** @param array<int,mixed> $values */
  private function firstNonEmpty(array $values): string
  {
    foreach ($values as $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }
}
