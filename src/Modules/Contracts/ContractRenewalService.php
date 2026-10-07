<?php

declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Core\Database;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SharedNotificationsBridge;

/** Business state and audit only; delivery belongs to shared-notifications. */
final class ContractRenewalService
{
  public function __construct(private Database $db) {}

  public function table(): string { return $this->db->table('scm_contract_renewal'); }
  public function eventsTable(): string { return $this->db->table('scm_contract_renewal_events'); }

  public function ensureSchema(): void
  {
    // The shared queue constructor executes DDL even when its tables exist.
    // Prewarm it before locking business rows; the bridge reuses this PDO queue.
    if (!$this->db->pdo()->inTransaction()) (new SharedNotificationsBridge($this->db))->queue();
    $schema = new \SCM\Support\SchemaInspector($this->db);
    if ($schema->tableExists($this->table()) && $schema->tableExists($this->eventsTable()) && $schema->tableExists($this->db->table('scm_contract_receipts'))) return;
    if ($this->db->pdo()->inTransaction()) throw new \RuntimeException('Prepara el esquema contractual antes de crear tickets.');
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      contract_id BIGINT UNSIGNED PRIMARY KEY, end_ts BIGINT NOT NULL,
      probability DECIMAL(5,2) NULL, no_exit TINYINT NOT NULL DEFAULT 0,
      reminder_days VARCHAR(100) NOT NULL DEFAULT '30,7,0', note TEXT NOT NULL,
      revision INT NOT NULL DEFAULT 0, jobs_json MEDIUMTEXT NOT NULL, receipt_employee_id VARCHAR(50) NOT NULL DEFAULT '',
      updated_by VARCHAR(50) NOT NULL, updated_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->db->table('scm_contract_receipts')}` (
      contract_id BIGINT UNSIGNED NOT NULL, end_ts BIGINT NOT NULL, ticket_id BIGINT UNSIGNED NOT NULL,
      created_at BIGINT NOT NULL, PRIMARY KEY(contract_id,end_ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->eventsTable()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, contract_id BIGINT UNSIGNED NOT NULL,
      action VARCHAR(40) NOT NULL, actor VARCHAR(50) NOT NULL,
      details_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL,
      KEY contract_history(contract_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  public function get(int $id, int $endTs): array
  {
    $lock = $this->db->pdo()->inTransaction() ? ' FOR UPDATE' : '';
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE contract_id = ?{$lock}", [$id]) ?? [];
    if ((int) ($row['end_ts'] ?? 0) !== $endTs) return [];
    return $row;
  }

  public static function probability(string $value): ?float
  {
    if (trim($value) === '') return null;
    if (!preg_match('/^\d{1,3}(?:[.,]\d{1,2})?$/', trim($value))) throw new \InvalidArgumentException('La probabilidad debe estar entre 0 y 100 %.');
    $number = (float) str_replace(',', '.', trim($value));
    if ($number > 100) throw new \InvalidArgumentException('La probabilidad debe estar entre 0 y 100 %.');
    return $number;
  }

  public static function reminderDays(string $value): array
  {
    $days = [];
    foreach (explode(',', $value) as $part) {
      $part = trim($part);
      if (!preg_match('/^\d{1,3}$/', $part) || (int) $part > 365) throw new \InvalidArgumentException('Indica días antes de la fecha fin entre 0 y 365, separados por coma.');
      $days[(int) $part] = (int) $part;
    }
    if (count($days) > 12) throw new \InvalidArgumentException('Puedes programar hasta 12 recordatorios.');
    rsort($days);
    return $days;
  }

  public static function canon($value): ?float
  {
    $text = trim((string) $value);
    if ($text === '') return null;
    if (str_contains($text, '-')) return null;
    $text = preg_replace('/[^0-9.,]/', '', $text) ?? '';
    if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/', $text)) $text = str_replace(',', '.', str_replace('.', '', $text));
    elseif (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $text)) $text = str_replace(',', '', $text);
    else $text = str_replace(',', '.', $text);
    return is_numeric($text) ? (float) $text : null;
  }

  public function audit(int $id, string $action, string $actor, array $details): void
  {
    $this->db->insert($this->eventsTable(), [
      'contract_id' => $id, 'action' => $action, 'actor' => $actor,
      'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => time(),
    ]);
  }

  /** Caller holds the contract row lock so saves, imports and ticket creation serialize. */
  public function save(array $contract, int $endTs, ?float $probability, bool $noExit, string $days, string $note, string $actor, string $receiptEmployee = ''): array
  {
    $id = (int) $contract['_ID'];
    $old = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE contract_id = ? FOR UPDATE", [$id]) ?? [];
    $parsedDays = self::reminderDays($days);
    $contacts = $noExit ? InternalNotificationRecipients::emailsForAction($this->db, 'contrato_no_salida') : [];
    if ($noExit && !$contacts) throw new \RuntimeException('Configura destinatarios en Notificaciones internas → No salida del inmueble antes de activar recordatorios.');
    $bridge = new SharedNotificationsBridge($this->db);
    $oldJobs = json_decode((string) ($old['jobs_json'] ?? '[]'), true) ?: [];
    $previousContacts = [];
    foreach ($oldJobs as $key) $previousContacts[] = strtolower((string) substr($key, strrpos($key, ':') + 1));
    $previousContacts = array_values(array_unique($previousContacts)); sort($previousContacts);
    $currentContacts = array_map('strtolower', $contacts); sort($currentContacts);
    $keepSchedule = $noExit && !empty($old['no_exit']) && (int) $old['end_ts'] === $endTs
      && (string) $old['reminder_days'] === implode(',', $parsedDays) && (string) $old['note'] === $note
      && $previousContacts === $currentContacts;
    $queue = !$keepSchedule && ($noExit || $oldJobs) ? $bridge->queue() : null;
    if (!$keepSchedule && ($noExit || $oldJobs) && !$queue) throw new \RuntimeException('La cola compartida no está disponible. No se guardaron cambios.');
    if (!$keepSchedule) foreach ($oldJobs as $job) $queue->cancelByDedupeKey((string) $job, 'Actualización de seguimiento contractual.');
    $revision = (int) ($old['revision'] ?? 0) + 1;
    $jobs = $keepSchedule ? $oldJobs : [];
    if ($noExit && !$keepSchedule) {
      $dates = [];
      foreach ($parsedDays as $day) {
        $scheduled = strtotime(date('Y-m-d', $endTs) . ' 09:00:00 -' . $day . ' days');
        if ($scheduled >= time()) $dates[$scheduled] = $scheduled;
      }
      // An already overdue report still receives one immediate reminder.
      if (!$dates) $dates[time()] = time();
      $label = htmlspecialchars((string) ($contract['contrato'] ?? $id), ENT_QUOTES, 'UTF-8');
      $property = htmlspecialchars((string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''), ENT_QUOTES, 'UTF-8');
      $html = '<p>SKC SuCasa Inmobiliaria</p><p>Se reportó que el arrendatario no realizará la salida del inmueble.</p><p>Contrato: ' . $label . '. Inmueble: ' . $property . '. Fecha fin: ' . date('d/m/Y', $endTs) . '.</p><p>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>';
      foreach ($dates as $scheduled) foreach ($contacts as $email) {
        $key = 'contrato_no_salida:' . $id . ':' . $revision . ':' . $scheduled . ':' . strtolower($email);
        $queue->enqueue([
          'project_code' => $bridge->projectCode(), 'source_module' => 'contrato_no_salida',
          'channel' => 'email', 'provider' => 'email_smtp', 'destination' => $email,
          'subject' => 'Recordatorio de no salida · Contrato ' . (string) ($contract['contrato'] ?? $id),
          'message_html' => $html, 'message_text' => strip_tags($html),
          'scheduled_at' => date(DATE_ATOM, $scheduled), 'dedupe_key' => $key,
          'meta' => ['contract_id' => $id, 'end_ts' => $endTs, 'revision' => $revision], 'created_by' => $actor,
        ]);
        $jobs[] = $key;
      }
    }
    $payload = [
      'end_ts' => $endTs, 'probability' => $probability, 'no_exit' => (int) $noExit,
      'reminder_days' => implode(',', $parsedDays), 'note' => $note, 'revision' => $revision,
      'jobs_json' => json_encode($jobs, JSON_THROW_ON_ERROR), 'updated_by' => $actor, 'updated_at' => time(),
      'receipt_employee_id' => $receiptEmployee,
    ];
    if ($old) $this->db->update($this->table(), $payload, ['contract_id' => $id]);
    else $this->db->insert($this->table(), ['contract_id' => $id] + $payload);
    $this->audit($id, 'renewal_saved', $actor, ['before' => $old, 'after' => $payload]);
    return ['reminders_queued' => $keepSchedule ? 0 : count($jobs), 'revision' => $revision];
  }

  public function history(int $id): array
  {
    $rows = $this->db->getResults("SELECT action,actor,details_json,created_at FROM `{$this->eventsTable()}` WHERE contract_id = ? ORDER BY id DESC LIMIT 30", [$id]);
    $actors = array_values(array_unique(array_filter(array_map(static fn(array $row): string => trim((string) $row['actor']), $rows))));
    $names = [];
    // Resolve authors separately: CCT and business tables can have different collations.
    if ($actors) {
      $employees = $this->db->table('jet_cct_funcionarios');
      $people = $this->db->getResults("SELECT id_empleado,nombre FROM `{$employees}` WHERE id_empleado IN (" . implode(',', array_fill(0, count($actors), '?')) . ")", $actors);
      foreach ($people as $person) $names[(string) $person['id_empleado']] = (string) $person['nombre'];
    }
    foreach ($rows as &$row) $row['actor_name'] = $names[(string) $row['actor']] ?? (string) $row['actor'];
    unset($row);
    return $rows;
  }

  /** Cancel old scheduled notices before the shared worker delivers them. */
  public function reconcileCycles(?int $contractId = null): void
  {
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $rows = $this->db->getResults("SELECT contract_id FROM `{$this->table()}` WHERE no_exit = 1" . ($contractId !== null ? " AND contract_id = ?" : ""), $contractId !== null ? [$contractId] : []);
    foreach ($rows as $item) {
      $pdo = $this->db->pdo();
      try {
        $pdo->beginTransaction();
        $contract = $this->db->getRow("SELECT * FROM `{$contracts}` WHERE `_ID` = ? FOR UPDATE", [(int) $item['contract_id']]);
        $state = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE contract_id = ? FOR UPDATE", [(int) $item['contract_id']]);
        if (!$state || !(int) $state['no_exit']) { $pdo->commit(); continue; }
        $value = $contract['fin_contrato'] ?? '';
        $end = is_numeric($value) ? (int) ((float) $value > 9999999999 ? (float) $value / 1000 : $value) : (int) strtotime((string) $value);
        $active = $contract && in_array(mb_strtolower(trim((string) ($contract['estado'] ?? ''))), ['entregado','por recibir'], true);
        if ($active && $end === (int) $state['end_ts']) { $pdo->commit(); continue; }
        $queue = (new SharedNotificationsBridge($this->db))->queue();
        if (!$queue) throw new \RuntimeException('No se pueden cancelar recordatorios obsoletos: cola no disponible.');
        foreach (json_decode((string) $state['jobs_json'], true) ?: [] as $key) $queue->cancelByDedupeKey($key, 'Contrato recibido, inactivo o con nueva fecha fin.');
        $this->db->update($this->table(), ['no_exit' => 0, 'jobs_json' => '[]', 'revision' => (int) $state['revision'] + 1], ['contract_id' => (int) $item['contract_id']]);
        $this->audit((int) $item['contract_id'], 'reminders_cancelled', 'Sistema', ['reason' => 'Cambio de fecha fin o contrato inactivo', 'previous_end_ts' => $state['end_ts']]);
        $pdo->commit();
      } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
      }
    }
  }
}
