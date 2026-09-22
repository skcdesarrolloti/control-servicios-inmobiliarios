<?php

declare(strict_types=1);

namespace SCM\Modules\PublicServicesLiquidator;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

final class PublicServicesLiquidatorService
{
  private Database $db;
  private SchemaInspector $schema;
  private PublicServicesLiquidationCalculator $calculator;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
    $this->calculator = new PublicServicesLiquidationCalculator();
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  public function searchContracts(array $filters): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $where = ["LOWER(TRIM(COALESCE(`estado`, ''))) = 'entregado'"];
    $args = [];
    $query = trim((string) ($filters['query'] ?? ''));
    if ($query !== '') {
      $parts = [];
      foreach (['contrato', 'inmueble', 'id_inmueble', 'direccion', 'propietario', 'arrendatario'] as $column) {
        if ($this->schema->columnExists($table, $column)) {
          $parts[] = "COALESCE(`{$column}`, '') LIKE ?";
          $args[] = '%' . $this->db->escapeLike($query) . '%';
        }
      }
      if ($parts !== []) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    $columns = [
      '_ID', 'contrato', 'inmueble', 'id_inmueble', 'direccion', 'propietario', 'arrendatario',
      'correo_propietario', 'correo_arrendatario', 'celular_propietario', 'celular_arrendatario',
      'servicios_publicos', 'agua', 'luz', 'gas', 'medidor_agua', 'medidor_luz', 'medidor_gas',
      'id_sucursal', 'sucursal',
    ];
    $select = [];
    foreach ($columns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    if ($select === []) {
      $select[] = '`_ID`';
    }

    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY `_ID` DESC LIMIT 30',
      $args
    );
    foreach ($rows as &$row) {
      $row['configured_services'] = $this->availableServices($row);
    }
    unset($row);
    return $rows;
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public function calculate(array $input): array
  {
    $contract = $this->contract((int) ($input['contract_id'] ?? 0));
    $services = $this->selectedServices($input, $contract);
    if ($services === []) {
      throw new \InvalidArgumentException('Selecciona al menos un servicio para liquidar.');
    }

    $results = [];
    foreach ($services as $key => $service) {
      $serviceInput = is_array($input['services'][$key] ?? null) ? $input['services'][$key] : [];
      $results[$key] = $service + [
        'result' => $this->calculator->calculate($serviceInput),
      ];
    }

    return [
      'contract' => $this->contractSummary($contract),
      'services' => $results,
      'total_reembolso' => array_sum(array_map(static fn(array $service): float => (float) ($service['result']['valor_reembolsar'] ?? 0), $results)),
    ];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public function generate(array $input): array
  {
    $calculation = $this->calculate($input);
    $contract = $this->contract((int) ($input['contract_id'] ?? 0));
    $employee = $this->currentEmployee();
    $context = $this->documentContext($contract, $input, $employee);
    $generator = new PublicServicesLiquidatorPdfGenerator();
    $documents = [];

    try {
      foreach ((array) ($calculation['services'] ?? []) as $key => $service) {
        $documents[$key] = $generator->generate($context, $service, (array) ($service['result'] ?? []));
      }
    } catch (\Throwable $exception) {
      foreach ($documents as $document) {
        $path = trim((string) ($document['path'] ?? ''));
        if ($path !== '' && is_file($path)) {
          @unlink($path);
        }
      }
      throw $exception;
    }

    $this->insertHistory($contract, $employee, $calculation, $documents);
    $queued = $this->queueNotifications($contract, $context, $calculation, $documents, $input);

    return $calculation + [
      'documents' => array_values(array_map(static fn(array $document): array => [
        'key' => $document['key'],
        'title' => $document['title'],
        'url' => $document['url'],
      ], $documents)),
      'queued' => $queued,
    ];
  }

  /** @return array<string,mixed> */
  private function contract(int $id): array
  {
    if ($id <= 0) {
      throw new \InvalidArgumentException('Selecciona un contrato valido.');
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      throw new \RuntimeException('No existe la tabla de contratos de arrendamiento.');
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]);
    if (!is_array($row)) {
      throw new \RuntimeException('Contrato no encontrado.');
    }
    return $row;
  }

  /** @param array<string,mixed> $input @param array<string,mixed> $contract @return array<string,array<string,mixed>> */
  private function selectedServices(array $input, array $contract): array
  {
    $posted = is_array($input['services'] ?? null) ? $input['services'] : [];
    $out = [];
    foreach ($this->serviceDefinitions() as $key => $definition) {
      $serviceInput = is_array($posted[$key] ?? null) ? $posted[$key] : [];
      $enabled = trim((string) ($serviceInput['enabled'] ?? '')) === '1';
      if (!$enabled) {
        continue;
      }
      $out[$key] = $definition + [
        'key' => $key,
        'account' => trim((string) ($serviceInput['account'] ?? $contract[$definition['account_field']] ?? '')),
        'meter' => trim((string) ($serviceInput['meter'] ?? $contract[$definition['meter_field']] ?? '')),
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $contract @return array<string,array<string,string>> */
  private function availableServices(array $contract): array
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
    foreach ((array) $values as $value) {
      $normalized = $this->normalizeServiceKey((string) $value);
      if ($normalized !== '') {
        $keys[$normalized] = true;
      }
    }
    if ($keys === []) {
      foreach ($this->serviceDefinitions() as $key => $definition) {
        if (trim((string) ($contract[$definition['account_field']] ?? '')) !== '') {
          $keys[$key] = true;
        }
      }
    }
    $out = [];
    foreach ($this->serviceDefinitions() as $key => $definition) {
      if (!isset($keys[$key])) {
        continue;
      }
      $out[$key] = [
        'label' => $definition['label'],
        'account' => trim((string) ($contract[$definition['account_field']] ?? '')),
        'meter' => trim((string) ($contract[$definition['meter_field']] ?? '')),
      ];
    }
    return $out;
  }

  /** @return array<string,array<string,string>> */
  private function serviceDefinitions(): array
  {
    return [
      'agua' => ['label' => 'Agua', 'unit' => 'M3', 'account_field' => 'agua', 'meter_field' => 'medidor_agua'],
      'energia' => ['label' => 'Luz', 'unit' => 'kWh', 'account_field' => 'luz', 'meter_field' => 'medidor_luz'],
      'gas' => ['label' => 'Gas', 'unit' => 'M3', 'account_field' => 'gas', 'meter_field' => 'medidor_gas'],
    ];
  }

  private function normalizeServiceKey(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = preg_replace('/[^a-z]/', '', $ascii !== false ? $ascii : $value) ?? '';
    return match ($value) {
      'agua', 'acueducto', 'alcantarillado' => 'agua',
      'energia', 'luz', 'electricidad' => 'energia',
      'gas', 'gasnatural' => 'gas',
      default => '',
    };
  }

  /** @param array<string,mixed> $contract @return array<string,mixed> */
  private function contractSummary(array $contract): array
  {
    return [
      '_ID' => (int) ($contract['_ID'] ?? 0),
      'contrato' => (string) ($contract['contrato'] ?? ''),
      'inmueble' => (string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''),
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
      'direccion' => (string) ($contract['direccion'] ?? ''),
      'propietario' => (string) ($contract['propietario'] ?? ''),
      'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  private function currentEmployee(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $userId = (int) Auth::userId();
    if ($userId <= 0 || !$this->schema->tableExists($table)) {
      return ['id_empleado' => Auth::employeeId() ?: (string) $userId, 'nombre' => Auth::user()];
    }
    $cargoTable = $this->db->table('jet_cct_cargos');
    $hasCargo = $this->schema->columnExists($table, 'id_cargo')
      && $this->schema->tableExists($cargoTable)
      && $this->schema->columnExists($cargoTable, '_ID')
      && $this->schema->columnExists($cargoTable, 'nombre_cargo');
    $join = $hasCargo ? " LEFT JOIN `{$cargoTable}` c ON TRIM(COALESCE(f.`id_cargo`, '')) = CAST(c.`_ID` AS CHAR)" : '';
    $cargoSelect = $hasCargo ? "TRIM(COALESCE(c.`nombre_cargo`, '')) AS nombre_cargo" : "'' AS nombre_cargo";
    return $this->db->getRow(
      "SELECT f.*, {$cargoSelect} FROM `{$table}` f{$join} WHERE f.`_ID` = ? LIMIT 1",
      [$userId]
    ) ?: ['id_empleado' => Auth::employeeId() ?: (string) $userId, 'nombre' => Auth::user()];
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $input @param array<string,mixed> $employee @return array<string,mixed> */
  private function documentContext(array $contract, array $input, array $employee): array
  {
    return $contract + [
      'fecha' => time(),
      'ciudad' => trim((string) ($input['ciudad'] ?? 'Cartagena de Indias')),
      'periodo' => trim((string) ($input['periodo'] ?? '')),
      'tipo_cuenta' => trim((string) ($input['tipo_cuenta'] ?? '')),
      'numero_cuenta' => trim((string) ($input['numero_cuenta'] ?? '')),
      'titular_cuenta' => trim((string) ($input['titular_cuenta'] ?? '')),
      'cedula_titular' => trim((string) ($input['cedula_titular'] ?? '')),
      'realizado_por' => trim((string) ($employee['nombre'] ?? Auth::user())),
      'realizado_por_cargo' => trim((string) (($employee['nombre_cargo'] ?? '') ?: ($employee['rol'] ?? ''))),
      'realizado_por_telefono' => trim((string) (($employee['celular'] ?? '') ?: ($employee['telefono'] ?? ''))),
      'realizado_por_correo' => trim((string) ($employee['correo'] ?? '')),
    ];
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $employee @param array<string,mixed> $calculation @param array<string,array<string,mixed>> $documents */
  private function insertHistory(array $contract, array $employee, array $calculation, array $documents): void
  {
    $table = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $services = implode(', ', array_map(static fn(array $service): string => (string) ($service['label'] ?? ''), (array) ($calculation['services'] ?? [])));
    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => (string) ($employee['id_empleado'] ?? Auth::employeeId() ?: Auth::userId()),
      'cct_created' => date('Y-m-d H:i:s'),
      'cct_modified' => date('Y-m-d H:i:s'),
      'id_empleado' => (string) ($employee['id_empleado'] ?? Auth::employeeId() ?: Auth::userId()),
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
      'fecha' => time(),
      'tipo_reporte' => 'Contractual',
      'observacion' => 'Se genero liquidador de servicios publicos para contrato #' . (string) ($contract['contrato'] ?? '')
        . '. Servicios: ' . $services . '. Ordenes PDF: ' . count($documents) . '.',
      'funcionario' => (string) ($employee['nombre'] ?? Auth::user()),
    ];
    $payload = $this->schema->filterTableData($table, $payload);
    if ($payload !== []) {
      $this->db->insert($table, $payload);
    }
  }

  /**
   * @param array<string,mixed> $contract
   * @param array<string,mixed> $context
   * @param array<string,mixed> $calculation
   * @param array<string,array<string,mixed>> $documents
   * @param array<string,mixed> $input
   * @return array{email:int,whatsapp:int}
   */
  private function queueNotifications(array $contract, array $context, array $calculation, array $documents, array $input): array
  {
    $channels = array_values(array_filter(array_map('strval', is_array($input['notify_channels'] ?? null) ? $input['notify_channels'] : [])));
    $roles = array_values(array_filter(array_map('strval', is_array($input['notify_roles'] ?? null) ? $input['notify_roles'] : [])));
    if ($channels === [] || $roles === []) {
      return ['email' => 0, 'whatsapp' => 0];
    }

    $recipients = [];
    if (in_array('propietario', $roles, true)) {
      $recipients[] = [
        'role' => 'propietario',
        'name' => (string) ($contract['propietario'] ?? ''),
        'email' => (string) ($contract['correo_propietario'] ?? ''),
        'phone' => (string) ($contract['celular_propietario'] ?? ''),
      ];
    }
    if (in_array('arrendatario', $roles, true)) {
      $recipients[] = [
        'role' => 'arrendatario',
        'name' => (string) ($contract['arrendatario'] ?? ''),
        'email' => (string) ($contract['correo_arrendatario'] ?? ''),
        'phone' => (string) ($contract['celular_arrendatario'] ?? ''),
      ];
    }

    $subject = 'Orden de reembolso de servicios publicos contrato #' . (string) ($contract['contrato'] ?? '');
    $buttons = [];
    $attachments = [];
    foreach ($documents as $document) {
      $buttons[] = ['url' => (string) ($document['url'] ?? ''), 'label' => (string) ($document['title'] ?? 'Orden')];
      $attachments[] = ['path' => (string) ($document['path'] ?? ''), 'name' => (string) ($document['attachment_name'] ?? 'orden-reembolso.pdf')];
    }

    $emailQueued = 0;
    if (in_array('email', $channels, true)) {
      $queue = new EmailQueue($this->db);
      foreach ($this->uniqueRecipients($recipients, 'email') as $recipient) {
        $html = EmailTemplate::render($subject, $this->emailBody($context, $calculation, $recipient), ['buttons' => $buttons]);
        $emailQueued += $queue->enqueue((string) $recipient['email'], $subject, $html, [
          'source_module' => 'liquidador_servicios_publicos',
          'destination_name' => (string) $recipient['name'],
          'dedupe_key' => 'liquidador_servicios_' . (int) ($contract['_ID'] ?? 0) . '_' . date('YmdHis'),
          'payload' => ['attachments' => $attachments, 'reply_to' => (string) ($context['realizado_por_correo'] ?? '')],
          'meta' => ['contract_id' => (int) ($contract['_ID'] ?? 0), 'recipient_role' => (string) $recipient['role'], 'documents' => $buttons],
        ]);
      }
    }

    $whatsappQueued = 0;
    if (in_array('whatsapp', $channels, true)) {
      $sms = new SmsQueue($this->db);
      foreach ($this->uniqueRecipients($recipients, 'phone') as $recipient) {
        foreach ($documents as $document) {
          $message = $this->whatsappMessage($context, $document);
          $ok = $sms->enqueue((string) $recipient['phone'], (string) $recipient['name'], $message, [
            'source_module' => 'liquidador_servicios_publicos',
            'dedupe_key' => 'liquidador_servicios_wa_' . (int) ($contract['_ID'] ?? 0) . '_' . (string) ($document['key'] ?? ''),
            'template_name' => 'scm_liquidador_servicios_reembolso_v1',
            'template_language' => 'es_CO',
            'template_components' => [
              [
                'type' => 'header',
                'parameters' => [[
                  'type' => 'document',
                  'document' => [
                    'link' => (string) ($document['url'] ?? ''),
                    'filename' => (string) ($document['attachment_name'] ?? 'orden-reembolso.pdf'),
                  ],
                ]],
              ],
              [
                'type' => 'body',
                'parameters' => [
                  ['type' => 'text', 'text' => $this->waText((string) $recipient['name'])],
                  ['type' => 'text', 'text' => $this->waText((string) ($document['title'] ?? 'Orden de reembolso'))],
                  ['type' => 'text', 'text' => $this->waText((string) ($context['contrato'] ?? '-'))],
                  ['type' => 'text', 'text' => $this->waText((string) ($context['inmueble'] ?? $context['id_inmueble'] ?? '-'))],
                  ['type' => 'text', 'text' => $this->waText((string) ($context['periodo'] ?? '-'))],
                  ['type' => 'text', 'text' => $this->waText((string) ($document['url'] ?? ''))],
                ],
              ],
            ],
            'contract_id' => (int) ($contract['_ID'] ?? 0),
            'document_url' => (string) ($document['url'] ?? ''),
            'document_filename' => (string) ($document['attachment_name'] ?? 'orden-reembolso.pdf'),
          ]);
          $whatsappQueued += $ok ? 1 : 0;
        }
      }
    }

    return ['email' => $emailQueued, 'whatsapp' => $whatsappQueued];
  }

  /** @param array<string,mixed> $context @param array<string,mixed> $calculation @param array<string,string> $recipient */
  private function emailBody(array $context, array $calculation, array $recipient): string
  {
    $name = trim((string) ($recipient['name'] ?? '')) ?: 'Usuario';
    $services = implode(', ', array_map(static fn(array $service): string => (string) ($service['label'] ?? ''), (array) ($calculation['services'] ?? [])));
    return '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . EmailTemplate::e($name) . ':</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">Compartimos la orden de reembolso de servicios publicos del contrato <b>#' . EmailTemplate::e((string) ($context['contrato'] ?? '')) . '</b>, inmueble <b>#' . EmailTemplate::e((string) ($context['inmueble'] ?? $context['id_inmueble'] ?? '')) . '</b>.</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;"><b>Servicios liquidados:</b> ' . EmailTemplate::e($services) . '. <b>Periodo:</b> ' . EmailTemplate::e((string) ($context['periodo'] ?? '')) . '.</p>'
      . '<p style="margin:0;line-height:1.65;">Las ordenes PDF se adjuntan al correo y quedan disponibles en los botones de consulta.</p>';
  }

  /** @param array<string,mixed> $context @param array<string,mixed> $document */
  private function whatsappMessage(array $context, array $document): string
  {
    return 'Orden de reembolso de servicios publicos del contrato #' . (string) ($context['contrato'] ?? '')
      . ', inmueble #' . (string) ($context['inmueble'] ?? $context['id_inmueble'] ?? '')
      . ', periodo ' . (string) ($context['periodo'] ?? '')
      . '. Documento: ' . (string) ($document['url'] ?? '');
  }

  /** @param array<int,array<string,string>> $recipients @return array<int,array<string,string>> */
  private function uniqueRecipients(array $recipients, string $field): array
  {
    $out = [];
    $seen = [];
    foreach ($recipients as $recipient) {
      $value = trim((string) ($recipient[$field] ?? ''));
      if ($value === '') {
        continue;
      }
      $key = strtolower($value);
      if (isset($seen[$key])) {
        continue;
      }
      if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $recipient;
    }
    return $out;
  }

  private function waText(string $text): string
  {
    $text = preg_replace('/[\r\n\t]+/u', ' | ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')) ?? $text;
    $text = preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    return mb_substr(trim($text, " \t\n\r\0\x0B|"), 0, 1024, 'UTF-8');
  }
}
