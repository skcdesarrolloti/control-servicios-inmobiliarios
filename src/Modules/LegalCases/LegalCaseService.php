<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Support\FuncionarioOptions;
use SCM\Support\PanelCaseNotifications;
use SCM\Support\SchemaInspector;

/**
 * Actividades contractuales › Casos jurídicos: expedientes propios del área contractual, separados de los tickets.
 * `scm_legal_case_events` solo recibe inserciones: es a la vez la línea de tiempo y la bitácora del caso.
 */
final class LegalCaseService
{
  public const STATUSES = ['abierto' => 'Abierto', 'en_tramite' => 'En trámite', 'cerrado' => 'Cerrado'];
  public const ROLES = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'tercero' => 'Tercero'];
  public const PER_PAGE = 25;
  public const REMINDER_DAYS = 3;
  private const DEFAULT_TYPES = ['Siniestro', 'Reclamación', 'PQR', 'Jurídico', 'No prórroga', 'Otro'];

  /** Tablas ya verificadas en esta petición. */
  private static array $schemaReady = [];

  private SchemaInspector $schema;

  public function __construct(private Database $db, private ?Settings $settings = null, ?SchemaInspector $schema = null)
  {
    $this->schema = $schema ?? new SchemaInspector($db);
  }

  public function table(string $name = 'cases'): string
  {
    return $this->db->table('scm_legal_' . ($name === 'cases' ? 'cases' : $name));
  }

  public static function code(int $id): string
  {
    return 'CASO-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
  }

  /** Crea lo que falte; va antes de cualquier transacción porque en MySQL un CREATE la cerraría. */
  public function ensureSchema(): void
  {
    $key = $this->table();
    if (isset(self::$schemaReady[$key])) return;
    $tables = [
      'case_types' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, active TINYINT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL, UNIQUE KEY uniq_name (name)",
      'cases' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, type_id BIGINT UNSIGNED NOT NULL, description TEXT NULL,
        contract_id BIGINT UNSIGNED NOT NULL DEFAULT 0, contract_code VARCHAR(60) NOT NULL DEFAULT '', property VARCHAR(60) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '',
        responsible_id VARCHAR(40) NOT NULL DEFAULT '', responsible_name VARCHAR(190) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'abierto',
        opened_date VARCHAR(10) NOT NULL, due_date VARCHAR(10) NOT NULL DEFAULT '', next_action VARCHAR(255) NOT NULL DEFAULT '', closed_at BIGINT NULL,
        created_by_id VARCHAR(40) NOT NULL DEFAULT '', created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        KEY idx_status (status), KEY idx_type (type_id), KEY idx_responsible (responsible_id), KEY idx_property (property), KEY idx_due (due_date)",
      'case_parties' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, role VARCHAR(20) NOT NULL, name VARCHAR(190) NOT NULL,
        company VARCHAR(190) NOT NULL DEFAULT '', document VARCHAR(40) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '',
        source VARCHAR(20) NOT NULL DEFAULT 'manual', removed_at BIGINT NULL, created_at BIGINT NOT NULL, KEY idx_case (case_id)",
      'case_events' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL,
        actor_id VARCHAR(40) NOT NULL DEFAULT '', actor_name VARCHAR(190) NOT NULL DEFAULT '', details_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, KEY idx_case (case_id)",
      'case_documents' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(120) NOT NULL, mime VARCHAR(120) NOT NULL DEFAULT '', bytes BIGINT NOT NULL DEFAULT 0, sha256 VARCHAR(64) NOT NULL DEFAULT '',
        uploaded_by_id VARCHAR(40) NOT NULL DEFAULT '', uploaded_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, KEY idx_case (case_id)",
      'communications' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, channels VARCHAR(30) NOT NULL, subject VARCHAR(255) NOT NULL,
        body_html MEDIUMTEXT NOT NULL, reply_to VARCHAR(190) NOT NULL DEFAULT '', whatsapp_template VARCHAR(120) NOT NULL DEFAULT '', template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        attachments_json MEDIUMTEXT NOT NULL, sender_id VARCHAR(40) NOT NULL DEFAULT '', sender_name VARCHAR(190) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, KEY idx_case (case_id), KEY idx_created (created_at)",
      'communication_recipients' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, communication_id BIGINT UNSIGNED NOT NULL, party_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        kind VARCHAR(5) NOT NULL DEFAULT 'to', channel VARCHAR(10) NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', destination VARCHAR(190) NOT NULL,
        rendered_subject VARCHAR(255) NOT NULL DEFAULT '', rendered_html MEDIUMTEXT NOT NULL, sent_html MEDIUMTEXT NOT NULL, whatsapp_params MEDIUMTEXT NULL, dedupe_key VARCHAR(120) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'en_cola', provider_message_id VARCHAR(190) NOT NULL DEFAULT '', last_error VARCHAR(500) NOT NULL DEFAULT '', accepted_at BIGINT NULL, checked_at BIGINT NULL,
        KEY idx_communication (communication_id), KEY idx_destination (destination), KEY idx_dedupe (dedupe_key)",
      'templates' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, subject VARCHAR(255) NOT NULL DEFAULT '', body_html MEDIUMTEXT NOT NULL,
        active TINYINT NOT NULL DEFAULT 1, updated_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
    ];
    foreach ($tables as $name => $columns) {
      if ($this->schema->tableExists($this->table($name))) continue;
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table($name)}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ((int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->table('case_types')}`") === 0) {
      foreach (self::DEFAULT_TYPES as $type) $this->db->insert($this->table('case_types'), ['name' => $type, 'active' => 1, 'created_at' => time()]);
    }
    self::$schemaReady[$key] = true;
  }

  /** @return list<array{id:int,name:string,active:bool}> */
  public function types(bool $onlyActive = false): array
  {
    $rows = $this->db->getResults("SELECT `id`, `name`, `active` FROM `{$this->table('case_types')}`" . ($onlyActive ? ' WHERE `active` = 1' : '') . ' ORDER BY `name`');
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'active' => (int) $row['active'] === 1], $rows);
  }

  /** Los tipos no se borran: los casos existentes conservan el suyo y solo dejan de ofrecerse. */
  public function saveType(int $id, string $name, bool $active): void
  {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) throw new \DomainException('Escribe el nombre del tipo (máximo 120 caracteres).');
    $duplicate = $this->db->getVar("SELECT `id` FROM `{$this->table('case_types')}` WHERE LOWER(`name`) = LOWER(?) AND `id` <> ? LIMIT 1", [$name, $id]);
    if ($duplicate !== null) throw new \DomainException('Ya existe un tipo con ese nombre.');
    if ($id > 0) {
      if ($this->typeName($id) === '') throw new \DomainException('El tipo no existe.');
      $this->db->update($this->table('case_types'), ['name' => $name, 'active' => $active ? 1 : 0], ['id' => $id]);
      return;
    }
    $this->db->insert($this->table('case_types'), ['name' => $name, 'active' => $active ? 1 : 0, 'created_at' => time()]);
  }

  /** @return list<array{id:string,name:string,email:string,phone:string,cargo:string}> */
  public function responsibles(): array
  {
    $out = [];
    foreach (FuncionarioOptions::activeFuncionarios($this->db, $this->schema, 'employee', true) as $person) {
      $out[] = ['id' => $person['employee_id'], 'name' => $person['name'], 'email' => $person['email'], 'phone' => $person['phone'], 'cargo' => $person['cargo']];
    }
    return $out;
  }

  /**
   * @param array<string,string> $filters
   * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,kpis:array<string,int>}
   */
  public function list(array $filters, int $page = 1): array
  {
    [$where, $args] = $this->listConditions($filters);
    $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $cases = $this->table();
    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$cases}` c{$sqlWhere}", $args);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * self::PER_PAGE;
    $rows = $this->db->getResults(
      "SELECT c.*, t.`name` AS type_name FROM `{$cases}` c LEFT JOIN `{$this->table('case_types')}` t ON t.`id` = c.`type_id`{$sqlWhere}
        ORDER BY CASE WHEN c.`status` = 'cerrado' THEN 1 ELSE 0 END, CASE WHEN c.`due_date` = '' THEN 1 ELSE 0 END, c.`due_date`, c.`id` DESC
        LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
      $args
    );
    return ['rows' => array_map(fn(array $row): array => $this->present($row), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'kpis' => $this->kpis()];
  }

  /** @param array<string,string> $filters @return array{0:list<string>,1:list<mixed>} */
  private function listConditions(array $filters): array
  {
    $where = [];
    $args = [];
    $status = (string) ($filters['status'] ?? '');
    if (isset(self::STATUSES[$status])) {
      $where[] = 'c.`status` = ?';
      $args[] = $status;
    }
    if ((int) ($filters['type_id'] ?? 0) > 0) {
      $where[] = 'c.`type_id` = ?';
      $args[] = (int) $filters['type_id'];
    }
    if ((string) ($filters['responsible'] ?? '') !== '') {
      $where[] = 'c.`responsible_id` = ?';
      $args[] = (string) $filters['responsible'];
    }
    $property = trim((string) ($filters['property'] ?? ''));
    if ($property !== '') {
      $like = '%' . $this->db->escapeLike($property) . '%';
      $where[] = '(c.`property` LIKE ? OR c.`address` LIKE ?)';
      array_push($args, $like, $like);
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $columns = ['c.`name`', 'c.`description`', 'c.`contract_code`', 'c.`property`', 'c.`address`'];
      $clause = implode(' OR ', array_map(static fn(string $column): string => "{$column} LIKE ?", $columns));
      $clause .= " OR EXISTS (SELECT 1 FROM `{$this->table('case_parties')}` p WHERE p.`case_id` = c.`id` AND p.`removed_at` IS NULL AND (p.`name` LIKE ? OR p.`company` LIKE ? OR p.`email` LIKE ?))";
      array_push($args, ...array_fill(0, count($columns) + 3, $like));
      if (preg_match('/^(?:caso-?)?0*([1-9][0-9]*)$/i', $search, $match) === 1) {
        $clause .= ' OR c.`id` = ?';
        $args[] = (int) $match[1];
      }
      $where[] = "({$clause})";
    }
    return [$where, $args];
  }

  /** @return array<string,int> */
  private function kpis(): array
  {
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime('+7 days'));
    $row = $this->db->getRow(
      "SELECT SUM(CASE WHEN `status` = 'abierto' THEN 1 ELSE 0 END) AS abierto, SUM(CASE WHEN `status` = 'en_tramite' THEN 1 ELSE 0 END) AS en_tramite,
        SUM(CASE WHEN `status` <> 'cerrado' AND `due_date` <> '' AND `due_date` < ? THEN 1 ELSE 0 END) AS vencidos,
        SUM(CASE WHEN `status` <> 'cerrado' AND `due_date` <> '' AND `due_date` >= ? AND `due_date` <= ? THEN 1 ELSE 0 END) AS proximos
       FROM `{$this->table()}`",
      [$today, $today, $limit]
    ) ?? [];
    return ['abierto' => (int) ($row['abierto'] ?? 0), 'en_tramite' => (int) ($row['en_tramite'] ?? 0), 'vencidos' => (int) ($row['vencidos'] ?? 0), 'proximos' => (int) ($row['proximos'] ?? 0)];
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function present(array $row): array
  {
    $id = (int) $row['id'];
    $status = (string) $row['status'];
    $due = (string) $row['due_date'];
    return [
      'id' => $id, 'code' => self::code($id), 'name' => (string) $row['name'], 'type_id' => (int) $row['type_id'], 'type_name' => (string) ($row['type_name'] ?? ''),
      'description' => (string) ($row['description'] ?? ''), 'contract_id' => (int) $row['contract_id'], 'contract_code' => (string) $row['contract_code'],
      'property' => (string) $row['property'], 'address' => (string) $row['address'], 'responsible_id' => (string) $row['responsible_id'], 'responsible_name' => (string) $row['responsible_name'],
      'status' => $status, 'status_label' => self::STATUSES[$status] ?? $status, 'opened_date' => (string) $row['opened_date'], 'due_date' => $due, 'next_action' => (string) $row['next_action'],
      'overdue' => $status !== 'cerrado' && $due !== '' && $due < date('Y-m-d'),
    ];
  }

  /** @return array<string,mixed> */
  public function detail(int $id): array
  {
    $row = $this->caseRow($id);
    $case = $this->present($row);
    $parties = $this->db->getResults("SELECT * FROM `{$this->table('case_parties')}` WHERE `case_id` = ? AND `removed_at` IS NULL ORDER BY `id`", [$id]);
    $events = $this->db->getResults("SELECT * FROM `{$this->table('case_events')}` WHERE `case_id` = ? ORDER BY `created_at`, `id`", [$id]);
    $documents = $this->db->getResults("SELECT * FROM `{$this->table('case_documents')}` WHERE `case_id` = ? ORDER BY `id`", [$id]);
    return [
      'case' => $case,
      'parties' => array_map(fn(array $party): array => $this->presentParty($party), $parties),
      'events' => array_map(static function (array $event): array {
        $details = json_decode((string) $event['details_json'], true);
        return ['id' => (int) $event['id'], 'action' => (string) $event['action'], 'actor_name' => (string) $event['actor_name'], 'created_at' => (int) $event['created_at'], 'details' => is_array($details) ? $details : []];
      }, $events),
      'documents' => array_map(static fn(array $document): array => [
        'id' => (int) $document['id'], 'event_id' => (int) $document['event_id'], 'name' => (string) $document['original_name'], 'stored_name' => (string) $document['stored_name'],
        'mime' => (string) $document['mime'], 'bytes' => (int) $document['bytes'], 'sha256' => (string) $document['sha256'], 'uploaded_by' => (string) $document['uploaded_by_name'], 'created_at' => (int) $document['created_at'],
      ], $documents),
    ];
  }

  /** @param array<string,mixed> $party @return array<string,mixed> */
  private function presentParty(array $party): array
  {
    $role = (string) $party['role'];
    return ['id' => (int) $party['id'], 'role' => $role, 'role_label' => self::ROLES[$role] ?? $role, 'name' => (string) $party['name'], 'company' => (string) $party['company'], 'document' => (string) $party['document'], 'email' => (string) $party['email'], 'phone' => (string) $party['phone']];
  }

  /** @return array<string,mixed> */
  private function caseRow(int $id): array
  {
    $row = $id > 0 ? $this->db->getRow("SELECT c.*, t.`name` AS type_name FROM `{$this->table()}` c LEFT JOIN `{$this->table('case_types')}` t ON t.`id` = c.`type_id` WHERE c.`id` = ? LIMIT 1", [$id]) : null;
    if ($row === null) throw new \DomainException('El caso no existe.');
    return $row;
  }

  /**
   * @param array<string,mixed> $input @param list<array<string,mixed>> $parties @param array<string,string> $actor
   */
  public function create(array $input, array $parties, array $actor): int
  {
    $data = $this->validated($input);
    $clean = array_map(fn(array $party): array => $this->validParty($party), $parties);
    $now = time();
    return $this->transaction(function () use ($data, $clean, $actor, $now): int {
      $this->db->insert($this->table(), $data + ['status' => 'abierto', 'created_by_id' => $actor['id'], 'created_by_name' => $actor['name'], 'created_at' => $now, 'updated_at' => $now]);
      $id = (int) $this->db->lastInsertId();
      foreach ($clean as $party) $this->insertParty($id, $party, $now);
      $this->event($id, 'caso_creado', $actor, ['nombre' => $data['name'], 'responsable' => $data['responsible_name']]);
      return $id;
    });
  }

  /** @param array<string,mixed> $input @param array<string,string> $actor */
  public function update(int $id, array $input, array $actor): void
  {
    $before = $this->caseRow($id);
    $data = $this->validated($input);
    $labels = ['name' => 'Nombre', 'type_id' => 'Tipo', 'description' => 'Descripción', 'contract_code' => 'Contrato', 'property' => 'Inmueble', 'address' => 'Dirección', 'responsible_name' => 'Responsable', 'opened_date' => 'Fecha de apertura', 'due_date' => 'Fecha límite', 'next_action' => 'Próxima acción'];
    $changes = [];
    foreach ($labels as $column => $label) {
      if ((string) $before[$column] !== (string) $data[$column]) $changes[] = ['campo' => $label, 'antes' => $column === 'type_id' ? (string) ($before['type_name'] ?? '') : (string) $before[$column], 'despues' => $column === 'type_id' ? $this->typeName((int) $data['type_id']) : (string) $data[$column]];
    }
    if ($changes === []) return;
    // Al cambiar de contrato sus partes se suman a las que ya tiene el caso.
    $newParties = (int) $data['contract_id'] > 0 && (int) $data['contract_id'] !== (int) $before['contract_id'] ? $this->contractParties((int) $data['contract_id']) : [];
    $this->transaction(function () use ($id, $data, $changes, $actor, $newParties): void {
      $this->db->update($this->table(), $data + ['updated_at' => time()], ['id' => $id]);
      $this->event($id, 'caso_editado', $actor, ['cambios' => $changes]);
      foreach ($this->missingParties($id, $newParties) as $party) {
        $this->insertParty($id, $this->validParty($party), time());
        $this->event($id, 'parte_agregada', $actor, ['nombre' => $party['name'], 'rol' => self::ROLES[$party['role']] ?? $party['role'], 'origen' => 'Contrato asociado']);
      }
    });
  }

  /** @param list<array<string,mixed>> $parties @return list<array<string,mixed>> */
  private function missingParties(int $id, array $parties): array
  {
    $existing = [];
    foreach ($this->db->getResults("SELECT `role`, `name` FROM `{$this->table('case_parties')}` WHERE `case_id` = ? AND `removed_at` IS NULL", [$id]) as $row) {
      $existing[$row['role'] . '|' . mb_strtolower(trim((string) $row['name']))] = true;
    }
    return array_values(array_filter($parties, static fn(array $party): bool => !isset($existing[$party['role'] . '|' . mb_strtolower(trim((string) $party['name']))])));
  }

  /** @param array<string,string> $actor */
  public function changeStatus(int $id, string $status, string $note, array $actor): void
  {
    if (!isset(self::STATUSES[$status])) throw new \DomainException('Estado no válido.');
    $before = $this->caseRow($id);
    if ((string) $before['status'] === $status) throw new \DomainException('El caso ya está en ese estado.');
    $note = trim($note);
    if (mb_strlen($note) > 2000) throw new \DomainException('La observación admite máximo 2000 caracteres.');
    $this->transaction(function () use ($id, $status, $note, $actor, $before): void {
      $this->db->update($this->table(), ['status' => $status, 'closed_at' => $status === 'cerrado' ? time() : null, 'updated_at' => time()], ['id' => $id]);
      $this->event($id, 'estado_cambiado', $actor, ['antes' => self::STATUSES[$before['status']] ?? $before['status'], 'despues' => self::STATUSES[$status], 'nota' => $note]);
    });
  }

  /** @param array<string,mixed> $party @param array<string,string> $actor */
  public function addParty(int $id, array $party, array $actor): int
  {
    $this->caseRow($id);
    $clean = $this->validParty($party);
    return $this->transaction(function () use ($id, $clean, $actor): int {
      $partyId = $this->insertParty($id, $clean, time());
      $this->event($id, 'parte_agregada', $actor, ['nombre' => $clean['name'], 'rol' => self::ROLES[$clean['role']]]);
      return $partyId;
    });
  }

  /** Se marca como retirada: las comunicaciones anteriores conservan a quién se enviaron. @param array<string,string> $actor */
  public function removeParty(int $id, int $partyId, array $actor): void
  {
    $party = $this->db->getRow("SELECT * FROM `{$this->table('case_parties')}` WHERE `id` = ? AND `case_id` = ? AND `removed_at` IS NULL LIMIT 1", [$partyId, $id]);
    if ($party === null) throw new \DomainException('La parte ya no está en el caso.');
    $this->transaction(function () use ($id, $partyId, $party, $actor): void {
      $this->db->update($this->table('case_parties'), ['removed_at' => time()], ['id' => $partyId]);
      $this->event($id, 'parte_retirada', $actor, ['nombre' => $party['name'], 'rol' => self::ROLES[$party['role']] ?? $party['role']]);
    });
  }

  /** @param array<string,string> $actor */
  public function addNote(int $id, string $text, array $actor): void
  {
    $this->caseRow($id);
    $text = trim($text);
    if ($text === '') throw new \DomainException('Escribe la nota.');
    if (mb_strlen($text) > 5000) throw new \DomainException('La nota admite máximo 5000 caracteres.');
    $this->transaction(function () use ($id, $text, $actor): void {
      $this->event($id, 'nota', $actor, ['texto' => $text]);
      $this->touch($id);
    });
  }

  /**
   * Respuesta recibida en el buzón del funcionario o por WhatsApp y registrada a mano.
   * @param array<string,string> $input @param list<array<string,mixed>> $files @param array<string,string> $actor
   */
  public function addManualReply(int $id, array $input, array $files, array $actor): void
  {
    $this->caseRow($id);
    $channel = (string) ($input['channel'] ?? '');
    if (!in_array($channel, ['Correo', 'WhatsApp', 'Físico', 'Otro'], true)) throw new \DomainException('Elige por dónde llegó la respuesta.');
    $from = trim((string) ($input['from'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $date = $this->validDate((string) ($input['received_date'] ?? ''), 'recepción');
    if ($from === '' || mb_strlen($from) > 190) throw new \DomainException('Indica quién respondió.');
    if ($date === '' || $date > date('Y-m-d')) throw new \DomainException('La fecha de recepción no puede estar vacía ni ser futura.');
    if ($body === '') throw new \DomainException('Copia el contenido de la respuesta.');
    if (mb_strlen($body) > 20000 || mb_strlen($subject) > 255) throw new \DomainException('La respuesta es demasiado larga.');
    $this->transaction(function () use ($id, $channel, $from, $body, $subject, $date, $files, $actor): void {
      $eventId = $this->event($id, 'respuesta_registrada', $actor, ['canal' => $channel, 'remitente' => $from, 'asunto' => $subject, 'fecha_recepcion' => $date, 'texto' => $body]);
      foreach ($files as $file) $this->insertDocument($id, $eventId, $file, $actor);
      $this->touch($id);
    });
  }

  /** @param list<array<string,mixed>> $files @param array<string,string> $actor */
  public function addDocuments(int $id, array $files, array $actor): void
  {
    $this->caseRow($id);
    if ($files === []) throw new \DomainException('Selecciona al menos un archivo válido.');
    $this->transaction(function () use ($id, $files, $actor): void {
      $eventId = $this->event($id, 'documento', $actor, ['archivos' => array_map(static fn(array $file): string => (string) $file['original_name'], $files)]);
      foreach ($files as $file) $this->insertDocument($id, $eventId, $file, $actor);
      $this->touch($id);
    });
  }

  /** @param array<string,mixed> $file @param array<string,string> $actor */
  public function insertDocument(int $caseId, int $eventId, array $file, array $actor): int
  {
    $this->db->insert($this->table('case_documents'), [
      'case_id' => $caseId, 'event_id' => $eventId, 'original_name' => mb_substr((string) $file['original_name'], 0, 255), 'stored_name' => (string) $file['name'],
      'mime' => (string) ($file['mime'] ?? ''), 'bytes' => (int) ($file['bytes'] ?? 0), 'sha256' => (string) ($file['sha256'] ?? ''),
      'uploaded_by_id' => $actor['id'], 'uploaded_by_name' => $actor['name'], 'created_at' => time(),
    ]);
    return (int) $this->db->lastInsertId();
  }

  private function touch(int $id): void
  {
    $this->db->update($this->table(), ['updated_at' => time()], ['id' => $id]);
  }

  /** @param array<string,string> $actor @param array<string,mixed> $details */
  public function event(int $id, string $action, array $actor, array $details): int
  {
    $this->db->insert($this->table('case_events'), ['case_id' => $id, 'action' => $action, 'actor_id' => $actor['id'], 'actor_name' => $actor['name'], 'details_json' => (string) json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function validated(array $input): array
  {
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 190) throw new \DomainException('Escribe el nombre del caso (máximo 190 caracteres).');
    $typeId = (int) ($input['type_id'] ?? 0);
    if ($this->typeName($typeId) === '') throw new \DomainException('Elige el tipo de caso.');
    $description = trim((string) ($input['description'] ?? ''));
    if (mb_strlen($description) > 10000) throw new \DomainException('La descripción admite máximo 10000 caracteres.');
    $responsibleId = trim((string) ($input['responsible_id'] ?? ''));
    $responsible = null;
    foreach ($this->responsibles() as $person) if ($person['id'] === $responsibleId) $responsible = $person;
    if ($responsible === null) throw new \DomainException('Elige un funcionario responsable activo.');
    $opened = $this->validDate((string) ($input['opened_date'] ?? ''), 'apertura') ?: date('Y-m-d');
    $due = $this->validDate((string) ($input['due_date'] ?? ''), 'límite');
    if ($due !== '' && $due < $opened) throw new \DomainException('La fecha límite no puede ser anterior a la apertura.');
    $next = trim((string) ($input['next_action'] ?? ''));
    if (mb_strlen($next) > 255) throw new \DomainException('La próxima acción admite máximo 255 caracteres.');

    $contractId = (int) ($input['contract_id'] ?? 0);
    $contractCode = '';
    $property = trim((string) ($input['property'] ?? ''));
    $address = trim((string) ($input['address'] ?? ''));
    if ($contractId > 0) {
      $contract = $this->contract($contractId);
      if ($contract === null) throw new \DomainException('El contrato seleccionado no existe.');
      $contractCode = trim((string) ($contract['contrato'] ?? ''));
      $property = trim((string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''));
      $address = trim((string) ($contract['direccion'] ?? ''));
    }
    if (mb_strlen($property) > 60 || mb_strlen($address) > 255) throw new \DomainException('Los datos del inmueble son demasiado largos.');
    return ['name' => $name, 'type_id' => $typeId, 'description' => $description, 'contract_id' => $contractId, 'contract_code' => $contractCode, 'property' => $property, 'address' => $address, 'responsible_id' => $responsibleId, 'responsible_name' => $responsible['name'], 'opened_date' => $opened, 'due_date' => $due, 'next_action' => $next];
  }

  private function typeName(int $id): string
  {
    return $id > 0 ? (string) ($this->db->getVar("SELECT `name` FROM `{$this->table('case_types')}` WHERE `id` = ? LIMIT 1", [$id]) ?? '') : '';
  }

  private function validDate(string $value, string $label): string
  {
    $value = trim($value);
    if ($value === '') return '';
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) throw new \DomainException("La fecha de {$label} no es válida.");
    return $value;
  }

  /** @param array<string,mixed> $party @return array{role:string,name:string,company:string,document:string,email:string,phone:string,source:string} */
  private function validParty(array $party): array
  {
    $role = (string) ($party['role'] ?? '');
    $name = trim((string) ($party['name'] ?? ''));
    if (!isset(self::ROLES[$role])) throw new \DomainException('Rol de la parte no válido.');
    if ($name === '' || mb_strlen($name) > 190) throw new \DomainException('Cada parte necesita un nombre (máximo 190 caracteres).');
    $email = strtolower(trim((string) ($party['email'] ?? '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \DomainException("El correo de {$name} no es válido.");
    $rawPhone = trim((string) ($party['phone'] ?? ''));
    $phone = $rawPhone === '' ? '' : PanelCaseNotifications::phone($rawPhone);
    if ($rawPhone !== '' && $phone === '') throw new \DomainException("El celular de {$name} no es válido.");
    $company = trim((string) ($party['company'] ?? ''));
    $document = trim((string) ($party['document'] ?? ''));
    if (mb_strlen($company) > 190 || mb_strlen($document) > 40 || mb_strlen($email) > 190) throw new \DomainException("Los datos de {$name} son demasiado largos.");
    return ['role' => $role, 'name' => $name, 'company' => $company, 'document' => $document, 'email' => $email, 'phone' => $phone, 'source' => (string) ($party['source'] ?? '') === 'contrato' ? 'contrato' : 'manual'];
  }

  /** @param array<string,string> $party */
  private function insertParty(int $caseId, array $party, int $now): int
  {
    $this->db->insert($this->table('case_parties'), $party + ['case_id' => $caseId, 'created_at' => $now]);
    return (int) $this->db->lastInsertId();
  }

  /** @return array<string,mixed>|null */
  private function contract(int $id): ?array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    return $this->schema->tableExists($table) ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) : null;
  }

  /**
   * Propietarios (incluye copropietarios) y arrendatario del contrato, para precargarlos como partes.
   * @return list<array<string,string>>
   */
  public function contractParties(int $contractId): array
  {
    $contract = $this->contract($contractId);
    if ($contract === null) throw new \DomainException('El contrato seleccionado no existe.');
    $parties = [];
    foreach ((new OwnerCreditService($this->db, $this->settings ?? \SCM\Core\App::settings()))->contractOwners($contractId) as $owner) {
      $name = trim((string) ($owner['name'] ?? ''));
      if ($name !== '') $parties[] = ['role' => 'propietario', 'name' => $name, 'company' => '', 'document' => (string) ($owner['document'] ?? ''), 'email' => (string) ($owner['email'] ?? ''), 'phone' => (string) ($owner['phone'] ?? ''), 'source' => 'contrato'];
    }
    $tenant = (new PanelCaseNotifications($this->db, $this->schema))->contact($contract, 'arrendatario');
    $tenantName = trim((string) ($contract['arrendatario'] ?? ''));
    if ($tenantName !== '') $parties[] = ['role' => 'arrendatario', 'name' => $tenantName, 'company' => '', 'document' => trim((string) ($contract['documento_arrendatario'] ?? '')), 'email' => (string) $tenant['email'], 'phone' => (string) $tenant['phone'], 'source' => 'contrato'];
    return $parties;
  }

  /** Casos sin cerrar con fecha límite, para el calendario de vencimientos. @return list<array<string,mixed>> */
  public function openWithDueDate(): array
  {
    $rows = $this->db->getResults("SELECT c.*, t.`name` AS type_name FROM `{$this->table()}` c LEFT JOIN `{$this->table('case_types')}` t ON t.`id` = c.`type_id` WHERE c.`status` <> 'cerrado' AND c.`due_date` <> '' ORDER BY c.`due_date`");
    return array_map(fn(array $row): array => $this->present($row), $rows);
  }

  /**
   * Recordatorios por enviar: uno tres días antes y otro al cumplirse la fecha. Si la fecha cambia, vuelven a salir.
   * @return list<array{case:array<string,mixed>,stage:string}>
   */
  public function pendingDueReminders(string $today): array
  {
    $limit = (new \DateTimeImmutable($today))->modify('+' . self::REMINDER_DAYS . ' days')->format('Y-m-d');
    $pending = [];
    foreach ($this->openWithDueDate() as $case) {
      if ($case['due_date'] > $limit) continue;
      $stage = $case['due_date'] <= $today ? 'vencimiento' : 'proxima';
      $sent = false;
      foreach ($this->db->getCol("SELECT `details_json` FROM `{$this->table('case_events')}` WHERE `case_id` = ? AND `action` = 'recordatorio_fecha_limite'", [$case['id']]) as $json) {
        $details = json_decode((string) $json, true);
        if (($details['fecha_limite'] ?? '') === $case['due_date'] && ($details['etapa'] ?? '') === $stage) $sent = true;
      }
      if (!$sent) $pending[] = ['case' => $case, 'stage' => $stage];
    }
    return $pending;
  }

  /** @return list<array{code:string,address:string}> */
  public function searchProperties(string $query): array
  {
    $query = trim($query);
    $table = $this->db->table('jet_cct_inmuebles');
    if (mb_strlen($query) < 2 || mb_strlen($query) > 100 || !$this->schema->tableExists($table)) return [];
    if (!$this->schema->columnExists($table, 'codigo') || !$this->schema->columnExists($table, 'direccion')) return [];
    $like = '%' . $this->db->escapeLike($query) . '%';
    $rows = $this->db->getResults("SELECT `codigo`, `direccion` FROM `{$table}` WHERE `codigo` LIKE ? OR `direccion` LIKE ? ORDER BY `_ID` DESC LIMIT 20", [$like, $like]);
    return array_map(static fn(array $row): array => ['code' => trim((string) $row['codigo']), 'address' => trim((string) $row['direccion'])], $rows);
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function transaction(callable $work): mixed
  {
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $result = $work();
      $pdo->commit();
      return $result;
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }
}
