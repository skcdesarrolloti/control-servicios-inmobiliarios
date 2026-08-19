<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeNotifications;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

final class AdministrativeNotificationsService
{
  public const PROJECT_CODE = 'control-servicios-inmobiliarios';
  public const SOURCE_MODULE = 'admin_notifications';
  public const QUEUE_TABLE = 'skc_notification_queue';
  public const SMS_MAX = 160;

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,array<string,mixed>> */
  public function types(): array
  {
    return [
      'propietarios' => [
        'label' => 'Propietarios',
        'role' => 'Propietario',
        'table' => $this->db->table('jet_cct_propietarios'),
        'contract_actor_column' => 'id_propietario',
        'name' => ['nombre', 'nombre_juridico', 'titular'],
        'email' => ['correo', 'correo_cuenta'],
        'phone' => ['celular', 'telefono', 'contacto'],
        'indicator' => ['indicativo'],
        'search' => ['nombre', 'nombre_juridico', 'documento', 'correo', 'celular', 'ciudad'],
      ],
      'arrendatarios' => [
        'label' => 'Arrendatarios',
        'role' => 'Arrendatario',
        'table' => $this->db->table('jet_cct_arrendatarios'),
        'contract_actor_column' => 'id_arrendatario',
        'name' => ['nombre', 'nombre_juridico'],
        'email' => ['correo'],
        'phone' => ['celular', 'telefono', 'contacto'],
        'indicator' => ['indicativo'],
        'search' => ['nombre', 'nombre_juridico', 'documento', 'correo', 'celular', 'ciudad'],
      ],
      'copropiedades' => [
        'label' => 'Copropiedades',
        'role' => 'Copropiedad',
        'table' => $this->db->table('jet_cct_copropiedades'),
        'contract_actor_column' => 'id_copropiedad',
        'contract_match_columns' => [
          'nit' => 'nit_copropiedad',
          'copropiedad' => 'copropiedad',
          'id_inmueble' => 'id_inmueble',
        ],
        'name' => ['copropiedad', 'nombre', 'administrador'],
        'email' => ['correo'],
        'phone' => ['contacto', 'celular', 'telefono'],
        'indicator' => ['indicativo'],
        'search' => ['copropiedad', 'administrador', 'nit', 'correo', 'contacto', 'barrio', 'direccion'],
      ],
      'proveedores' => [
        'label' => 'Proveedores',
        'role' => 'Proveedor',
        'table' => $this->db->table('jet_cct_proveedores'),
        'name' => ['proveedor', 'titular_proveedor', 'nombre'],
        'email' => ['correo_proveedor', 'correo_pago_proveedor', 'correo'],
        'phone' => ['celular_proveedor', 'celular', 'telefono', 'contacto'],
        'indicator' => ['indicativo'],
        'search' => ['proveedor', 'titular_proveedor', 'identificacion_proveedor', 'correo_proveedor', 'celular_proveedor', 'direccion_proveedor'],
      ],
      'funcionarios' => [
        'label' => 'Funcionarios',
        'role' => 'Funcionario',
        'table' => $this->db->table('jet_cct_funcionarios'),
        'name' => ['nombre', 'empleado', 'nombre_funcionario'],
        'email' => ['correo', 'correo_dian'],
        'phone' => ['celular', 'celular_empleado', 'telefono', 'whatsapp'],
        'indicator' => ['indicativo'],
        'search' => ['nombre', 'id_empleado', 'documento', 'correo', 'celular', 'rol', 'gestion'],
        'only_active' => true,
      ],
    ];
  }

  /** @return array<string,array<string,int|string>> */
  public function stats(): array
  {
    $out = [];
    foreach ($this->types() as $type => $config) {
      $table = (string) $config['table'];
      if (!$this->schema->tableExists($table)) {
        $out[$type] = ['total' => 0, 'email' => 0, 'phone' => 0, 'label' => (string) $config['label']];
        continue;
      }
      $emailColumn = $this->detect($table, (array) $config['email']);
      $phoneColumn = $this->detect($table, (array) $config['phone']);
      $where = $this->baseWhere($config);
      $out[$type] = [
        'total' => (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where}"),
        'email' => $emailColumn !== '' ? (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where} AND TRIM(COALESCE(`{$emailColumn}`, '')) <> ''") : 0,
        'phone' => $phoneColumn !== '' ? (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where} AND TRIM(COALESCE(`{$phoneColumn}`, '')) <> ''") : 0,
        'label' => (string) $config['label'],
      ];
    }
    return $out;
  }

  /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,type:string,type_label:string,contract_status:string} */
  public function search(string $type, string $query, int $page = 1, int $perPage = 20, string $contractStatus = ''): array
  {
    $config = $this->typeConfig($type);
    $contractStatus = $this->sanitizeContractStatus($contractStatus);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table)) {
      return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage, 'type' => $type, 'type_label' => (string) $config['label'], 'contract_status' => $contractStatus];
    }

    $columns = $this->resolveColumns($config);
    $where = $this->baseWhere($config);
    $args = [];
    if (trim($query) !== '') {
      $searchColumns = $this->searchColumns($table, $config);
      if ($searchColumns !== []) {
        $like = '%' . $this->db->escapeLike(trim($query)) . '%';
        $parts = [];
        foreach ($searchColumns as $column) {
          $parts[] = "`{$column}` LIKE ?";
          $args[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
      }
    }
    $this->applyContractStatusFilter($where, $args, $type, $config, $contractStatus);

    $total = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where}", $args);
    $page = max(1, $page);
    $perPage = min(100, max(10, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $select = [
      '`_ID`',
      $columns['name'] !== '' ? "`{$columns['name']}` AS nombre" : "CAST(`_ID` AS CHAR) AS nombre",
      $columns['email'] !== '' ? "`{$columns['email']}` AS correo" : "'' AS correo",
      $columns['phone'] !== '' ? "`{$columns['phone']}` AS celular" : "'' AS celular",
      $columns['indicator'] !== '' ? "`{$columns['indicator']}` AS indicativo" : "'' AS indicativo",
    ];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if ($contractActorColumn !== '' && $this->schema->columnExists($table, $contractActorColumn)) {
      $select[] = "`{$contractActorColumn}` AS contrato_actor_ref";
    } else {
      $select[] = "'' AS contrato_actor_ref";
    }
    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      if ($actorColumn !== '' && $this->schema->columnExists($table, $actorColumn)) {
        $select[] = "`{$actorColumn}` AS `contrato_ref_{$actorColumn}`";
      }
    }

    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );

    foreach ($rows as &$row) {
      $row['tipo_actor'] = $type;
      $row['tipo_label'] = (string) $config['label'];
      $row['rol_persona'] = (string) $config['role'];
      $row['celular_normalizado'] = $this->normalizePhone((string) ($row['celular'] ?? ''), (string) ($row['indicativo'] ?? ''), true);
      $contractInfo = $this->contractActivityInfo($type, $config, $row, $contractStatus);
      $row['contrato_arrendamiento_estado'] = $contractInfo['label'];
      $row['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
    }
    unset($row);

    return [
      'rows' => $rows,
      'total' => $total,
      'page' => $page,
      'pages' => $pages,
      'per_page' => $perPage,
      'type' => $type,
      'type_label' => (string) $config['label'],
      'contract_status' => $contractStatus,
    ];
  }

  /** @return int[] */
  public function idsForFilter(string $type, string $query, int $limit = 5000, string $contractStatus = ''): array
  {
    $config = $this->typeConfig($type);
    $contractStatus = $this->sanitizeContractStatus($contractStatus);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $where = $this->baseWhere($config);
    $args = [];
    if (trim($query) !== '') {
      $searchColumns = $this->searchColumns($table, $config);
      if ($searchColumns !== []) {
        $like = '%' . $this->db->escapeLike(trim($query)) . '%';
        $parts = [];
        foreach ($searchColumns as $column) {
          $parts[] = "`{$column}` LIKE ?";
          $args[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
      }
    }
    $this->applyContractStatusFilter($where, $args, $type, $config, $contractStatus);
    $rows = $this->db->getResults("SELECT `_ID` FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT ?", array_merge($args, [max(1, $limit)]));
    return array_values(array_map(static fn(array $row): int => (int) ($row['_ID'] ?? 0), $rows));
  }

  /**
   * @param int[] $ids
   * @param string[] $channels
   * @return array{queued:int,failed:int,invalid:int,filtered:int,selected:int,channels:array<int,string>,type:string}
   */
  public function enqueue(string $type, array $ids, array $channels, string $subject, string $message): array
  {
    $config = $this->typeConfig($type);
    $channels = $this->sanitizeChannels($channels);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    $subject = trim($subject);
    $message = trim($message);

    if ($ids === []) {
      throw new \RuntimeException('Selecciona al menos un destinatario.');
    }
    if ($channels === []) {
      throw new \RuntimeException('Selecciona al menos un canal.');
    }
    if ($message === '') {
      throw new \RuntimeException('El mensaje no puede estar vacio.');
    }
    if (in_array('email', $channels, true) && $subject === '') {
      throw new \RuntimeException('El asunto es obligatorio para Email.');
    }
    if (in_array('sms', $channels, true) && mb_strlen($message) > self::SMS_MAX) {
      throw new \RuntimeException('El SMS supera ' . self::SMS_MAX . ' caracteres.');
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $recipients = $this->recipients($type, $ids);
    $batchId = bin2hex(random_bytes(8));
    $queued = 0;
    $failed = 0;
    $invalid = 0;
    $filtered = 0;

    foreach ($recipients as $recipient) {
      foreach ($channels as $channel) {
        if ($this->isBlockedByPreference($recipient, $channel, $type)) {
          $filtered++;
          continue;
        }
        $destination = $this->destination($recipient, $channel);
        if ($destination === '') {
          $invalid++;
          continue;
        }
        $resolvedMessage = $this->resolveVariables($message, $recipient, $config);
        $resolvedSubject = $this->resolveVariables($subject, $recipient, $config);
        if ($this->insertQueueRow($channel, $destination, $recipient, $resolvedSubject, $resolvedMessage, $batchId)) {
          $queued++;
        } else {
          $failed++;
        }
      }
    }

    return [
      'queued' => $queued,
      'failed' => $failed,
      'invalid' => $invalid,
      'filtered' => $filtered,
      'selected' => count($ids),
      'channels' => $channels,
      'type' => $type,
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function recipients(string $type, array $ids): array
  {
    $config = $this->typeConfig($type);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table) || $ids === []) {
      return [];
    }
    $columns = $this->resolveColumns($config);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $select = [
      '`_ID`',
      $columns['name'] !== '' ? "`{$columns['name']}` AS nombre" : "CAST(`_ID` AS CHAR) AS nombre",
      $columns['email'] !== '' ? "`{$columns['email']}` AS correo" : "'' AS correo",
      $columns['phone'] !== '' ? "`{$columns['phone']}` AS celular" : "'' AS celular",
      $columns['indicator'] !== '' ? "`{$columns['indicator']}` AS indicativo" : "'' AS indicativo",
    ];
    foreach (['bloqueo_email', 'bloqueo_sms', 'bloqueo_whatsapp'] as $preferenceColumn) {
      if ($this->schema->columnExists($table, $preferenceColumn)) {
        $select[] = "`{$preferenceColumn}`";
      }
    }
    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE `_ID` IN ({$placeholders})",
      $ids
    );
    foreach ($rows as &$row) {
      $row['tipo_actor'] = $type;
      $row['tipo_label'] = (string) $config['label'];
      $row['rol_persona'] = (string) $config['role'];
    }
    unset($row);
    return $rows;
  }

  private function isBlockedByPreference(array $recipient, string $channel, string $type): bool
  {
    if ($type === 'funcionarios') {
      return false;
    }
    $field = match ($channel) {
      'sms' => 'bloqueo_sms',
      'whatsapp' => 'bloqueo_whatsapp',
      default => 'bloqueo_email',
    };
    return array_key_exists($field, $recipient) && (int) ($recipient[$field] ?? 0) === 1;
  }

  private function sanitizeContractStatus(string $status): string
  {
    $status = strtolower(trim($status));
    return in_array($status, ['activos', 'no_activos'], true) ? $status : '';
  }

  /** @param array<string,mixed> $config @param array<int,mixed> $args */
  private function applyContractStatusFilter(string &$where, array &$args, string $type, array $config, string $contractStatus): void
  {
    if ($contractStatus === '' || !in_array($type, ['propietarios', 'arrendatarios', 'copropiedades'], true)) {
      return;
    }
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $actorTable = (string) $config['table'];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if (
      $contractActorColumn === ''
      || !$this->schema->tableExists($contractTable)
      || !$this->schema->columnExists($contractTable, $contractActorColumn)
      || !$this->schema->columnExists($contractTable, 'estado')
    ) {
      return;
    }

    $where .= " AND EXISTS (
      SELECT 1
        FROM `{$contractTable}` ca
       WHERE {$this->contractActorComparisonSql($actorTable, $contractActorColumn, $config)}
         AND LOWER(TRIM(COALESCE(ca.`estado`, ''))) = ?
       LIMIT 1
    )";
    $args[] = $contractStatus === 'activos' ? 'entregado' : 'recibido';

    if ($contractStatus === 'no_activos') {
      $where .= " AND NOT EXISTS (
        SELECT 1
          FROM `{$contractTable}` ca
         WHERE {$this->contractActorComparisonSql($actorTable, $contractActorColumn, $config)}
           AND LOWER(TRIM(COALESCE(ca.`estado`, ''))) = ?
         LIMIT 1
      )";
      $args[] = 'entregado';
    }
  }

  /**
   * @param array<string,mixed> $config
   * @param array<string,mixed> $recipient
   * @return array{label:string,summary:string}
   */
  private function contractActivityInfo(string $type, array $config, array $recipient, string $contractStatus = ''): array
  {
    if (!in_array($type, ['propietarios', 'arrendatarios', 'copropiedades'], true)) {
      return ['label' => '', 'summary' => ''];
    }
    $contractStatus = $this->sanitizeContractStatus($contractStatus);
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if (
      $contractActorColumn === ''
      || !$this->schema->tableExists($contractTable)
      || !$this->schema->columnExists($contractTable, $contractActorColumn)
      || !$this->schema->columnExists($contractTable, 'estado')
    ) {
      return ['label' => '', 'summary' => ''];
    }

    [$matchWhere, $matchArgs] = $this->contractRecipientMatch($config, $recipient);
    if ($matchWhere === '') {
      return ['label' => 'Sin contrato', 'summary' => ''];
    }

    $rows = $this->db->getResults(
      "SELECT LOWER(TRIM(COALESCE(`estado`, ''))) AS estado,
              COUNT(1) AS total,
              GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(`contrato`, '')), '') ORDER BY `_ID` DESC SEPARATOR ', ') AS contratos
         FROM `{$contractTable}`
        WHERE ({$matchWhere})
          AND LOWER(TRIM(COALESCE(`estado`, ''))) IN ('entregado', 'recibido')
        GROUP BY LOWER(TRIM(COALESCE(`estado`, '')))",
      $matchArgs
    );

    $hasDelivered = false;
    $hasReceived = false;
    $contracts = [];
    foreach ($rows as $row) {
      $state = trim((string) ($row['estado'] ?? ''));
      $hasDelivered = $hasDelivered || $state === 'entregado';
      $hasReceived = $hasReceived || $state === 'recibido';
      foreach (explode(',', (string) ($row['contratos'] ?? '')) as $contract) {
        $contract = trim($contract);
        if ($contract !== '') {
          $contracts[$contract] = $contract;
        }
      }
    }
    $summary = $this->contractSummary(array_values($contracts));
    if ($hasDelivered) {
      return ['label' => 'Activo', 'summary' => $summary];
    }
    if ($hasReceived) {
      return ['label' => 'No activo', 'summary' => $summary];
    }
    return ['label' => 'Sin contrato', 'summary' => ''];
  }

  /**
   * @param array<string,mixed> $config
   * @param array<string,mixed> $recipient
   * @return array{0:string,1:array<int,mixed>}
   */
  private function contractRecipientMatch(array $config, array $recipient): array
  {
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    $parts = [];
    $args = [];
    $ids = array_values(array_unique(array_filter(array_map(
      static fn($value): int => (int) preg_replace('/\D+/', '', (string) $value),
      [$recipient['_ID'] ?? '', $recipient['contrato_actor_ref'] ?? '']
    ), static fn(int $id): bool => $id > 0)));
    if ($contractActorColumn !== '' && $this->schema->columnExists($contractTable, $contractActorColumn) && $ids !== []) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $parts[] = "CAST(`{$contractActorColumn}` AS UNSIGNED) IN ({$placeholders})";
      array_push($args, ...$ids);
    }

    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      $contractColumn = trim((string) $contractColumn);
      $value = trim((string) ($recipient["contrato_ref_{$actorColumn}"] ?? ''));
      if (
        $actorColumn === ''
        || $contractColumn === ''
        || $value === ''
        || !$this->schema->columnExists($contractTable, $contractColumn)
      ) {
        continue;
      }
      if (preg_match('/^id_/', $actorColumn) === 1 || preg_match('/^id_/', $contractColumn) === 1) {
        $numeric = (int) preg_replace('/\D+/', '', $value);
        if ($numeric <= 0) {
          continue;
        }
        $parts[] = "CAST(`{$contractColumn}` AS UNSIGNED) = ?";
        $args[] = $numeric;
      } else {
        $parts[] = "LOWER(TRIM(COALESCE(`{$contractColumn}`, ''))) = LOWER(TRIM(?))";
        $args[] = $value;
      }
    }

    return $parts === [] ? ['', []] : ['(' . implode(' OR ', $parts) . ')', $args];
  }

  /** @param string[] $contracts */
  private function contractSummary(array $contracts): string
  {
    $contracts = array_values(array_unique(array_filter(array_map(
      static fn($contract): string => trim((string) $contract),
      $contracts
    ), static fn(string $contract): bool => $contract !== '')));
    if ($contracts === []) {
      return '';
    }
    $visible = array_slice($contracts, 0, 4);
    $summary = 'Contratos: #' . implode(', #', $visible);
    $remaining = count($contracts) - count($visible);
    if ($remaining > 0) {
      $summary .= ' +' . $remaining . ' mas';
    }
    return $summary;
  }

  /** @param array<string,mixed> $config */
  private function contractActorComparisonSql(string $actorTable, string $contractActorColumn, array $config = []): string
  {
    $parts = [
      "CAST(ca.`{$contractActorColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`_ID` AS UNSIGNED)",
    ];
    if ($this->schema->columnExists($actorTable, $contractActorColumn)) {
      $parts[] = "CAST(ca.`{$contractActorColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`{$contractActorColumn}` AS UNSIGNED)";
    }
    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      $contractColumn = trim((string) $contractColumn);
      if (
        $actorColumn === ''
        || $contractColumn === ''
        || !$this->schema->columnExists($actorTable, $actorColumn)
        || !$this->schema->columnExists($this->db->table('jet_cct_contratos_arrendamiento'), $contractColumn)
      ) {
        continue;
      }
      if (preg_match('/^id_/', $actorColumn) === 1 || preg_match('/^id_/', $contractColumn) === 1) {
        $parts[] = "CAST(ca.`{$contractColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`{$actorColumn}` AS UNSIGNED)";
      } else {
        $parts[] = "TRIM(COALESCE(ca.`{$contractColumn}`, '')) <> '' AND TRIM(COALESCE(`{$actorTable}`.`{$actorColumn}`, '')) <> '' AND LOWER(TRIM(COALESCE(ca.`{$contractColumn}`, ''))) = LOWER(TRIM(COALESCE(`{$actorTable}`.`{$actorColumn}`, '')))";
      }
    }
    return '(' . implode(' OR ', $parts) . ')';
  }

  private function insertQueueRow(string $channel, string $destination, array $recipient, string $subject, string $message, string $batchId): bool
  {
    $now = gmdate('Y-m-d H:i:s');
    $actorId = (int) ($recipient['_ID'] ?? 0);
    $type = (string) ($recipient['tipo_actor'] ?? '');
    $name = trim((string) ($recipient['nombre'] ?? ''));
    $provider = match ($channel) {
      'sms' => 'sms_onurix',
      'whatsapp' => 'whatsapp',
      default => 'email_smtp',
    };
    $meta = [
      'admin_notifications' => [
        'batch_id' => $batchId,
        'id_actor' => $actorId,
        'tipo_actor' => $type,
        'tipo_label' => (string) ($recipient['tipo_label'] ?? ''),
        'rol_persona' => (string) ($recipient['rol_persona'] ?? ''),
        'id_funcionario' => Auth::userId(),
        'nombre_funcionario' => Auth::user(),
        'source_module' => self::SOURCE_MODULE,
      ],
    ];
    $data = [
      'project_code' => self::PROJECT_CODE,
      'source_module' => self::SOURCE_MODULE,
      'channel' => $channel,
      'provider' => $provider,
      'destination' => $destination,
      'destination_name' => $name,
      'subject' => $channel === 'email' ? $subject : '',
      'message_html' => $channel === 'email' ? nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) : '',
      'message_text' => trim(html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8')),
      'template_name' => '',
      'payload_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'status' => 'pending',
      'priority' => 100,
      'max_attempts' => 3,
      'scheduled_at' => $now,
      'created_at' => $now,
      'updated_at' => $now,
      'created_by' => self::PROJECT_CODE,
      'dedupe_key' => implode(':', [self::PROJECT_CODE, self::SOURCE_MODULE, $batchId, $channel, $type, (string) $actorId]),
    ];

    try {
      $filtered = $this->schema->filterTableData(self::QUEUE_TABLE, $data);
      return $filtered !== [] && $this->db->insert(self::QUEUE_TABLE, $filtered);
    } catch (\Throwable) {
      return false;
    }
  }

  /** @return string[] */
  private function sanitizeChannels(array $channels): array
  {
    $out = [];
    foreach ($channels as $channel) {
      $channel = strtolower(trim((string) $channel));
      if (in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
        $out[$channel] = $channel;
      }
    }
    return array_values($out);
  }

  private function destination(array $recipient, string $channel): string
  {
    if ($channel === 'email') {
      $email = trim((string) ($recipient['correo'] ?? ''));
      return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
    $phone = $this->normalizePhone((string) ($recipient['celular'] ?? ''), (string) ($recipient['indicativo'] ?? ''), $channel === 'whatsapp');
    return $phone;
  }

  private function normalizePhone(string $phone, string $indicator = '', bool $withPlus = false): string
  {
    $phone = trim($phone);
    if ($phone === '') {
      return '';
    }
    if ($withPlus && (str_contains($phone, '@g.us') || str_contains($phone, '@s.whatsapp.net'))) {
      return $phone;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits === '') {
      return '';
    }
    $prefix = preg_replace('/\D+/', '', $indicator) ?: '';
    if ($prefix !== '' && !str_starts_with($digits, $prefix) && strlen($digits) <= 10) {
      $digits = $prefix . $digits;
    }
    if ($prefix === '' && strlen($digits) <= 10) {
      $digits = '57' . $digits;
    }
    return $withPlus ? ('+' . ltrim($digits, '+')) : $digits;
  }

  /** @param array<string,mixed> $config */
  private function resolveVariables(string $template, array $recipient, array $config): string
  {
    $map = [
      '{{nombre}}' => trim((string) ($recipient['nombre'] ?? '')),
      '{{correo}}' => trim((string) ($recipient['correo'] ?? '')),
      '{{celular}}' => trim((string) ($recipient['celular'] ?? '')),
      '{{tipo_actor}}' => trim((string) ($recipient['tipo_label'] ?? $config['label'] ?? '')),
      '{{rol_persona}}' => trim((string) ($recipient['rol_persona'] ?? $config['role'] ?? '')),
    ];
    return strtr($template, $map);
  }

  /** @return array<string,mixed> */
  private function typeConfig(string $type): array
  {
    $types = $this->types();
    $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'propietarios';
    if (!isset($types[$type])) {
      throw new \RuntimeException('Tipo de destinatario no valido.');
    }
    return $types[$type] + ['key' => $type];
  }

  /** @param array<string,mixed> $config */
  private function baseWhere(array $config): string
  {
    $table = (string) $config['table'];
    if (!empty($config['only_active']) && $this->schema->columnExists($table, 'activo')) {
      return "(TRIM(COALESCE(`activo`, '')) = '' OR LOWER(TRIM(COALESCE(`activo`, ''))) IN ('si', '1', 'true', 'activo'))";
    }
    return '1=1';
  }

  /** @param array<string,mixed> $config @return array{name:string,email:string,phone:string,indicator:string} */
  private function resolveColumns(array $config): array
  {
    $table = (string) $config['table'];
    return [
      'name' => $this->detect($table, (array) $config['name']),
      'email' => $this->detect($table, (array) $config['email']),
      'phone' => $this->detect($table, (array) $config['phone']),
      'indicator' => $this->detect($table, (array) $config['indicator']),
    ];
  }

  /** @param string[] $candidates */
  private function detect(string $table, array $candidates): string
  {
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '' && $this->schema->columnExists($table, $candidate)) {
        return $candidate;
      }
    }
    return '';
  }

  /** @param array<string,mixed> $config @return string[] */
  private function searchColumns(string $table, array $config): array
  {
    $columns = [];
    foreach ((array) ($config['search'] ?? []) as $column) {
      $column = trim((string) $column);
      if ($column !== '' && $this->schema->columnExists($table, $column)) {
        $columns[$column] = $column;
      }
    }
    foreach (array_merge((array) $config['name'], (array) $config['email'], (array) $config['phone']) as $column) {
      $column = trim((string) $column);
      if ($column !== '' && $this->schema->columnExists($table, $column)) {
        $columns[$column] = $column;
      }
    }
    return array_values($columns);
  }
}
