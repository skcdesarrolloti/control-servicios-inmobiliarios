<?php
declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;
use SCM\Core\Settings;

/** Observes persisted Calendar/JetForm events; delivery remains in the shared queue. */
final class InternalAdminExternalEvents
{
  public const STATE_KEY = 'internal_admin_external_event_state';

  public function __construct(private Database $db) {}

  public function run(): array
  {
    $pdo = $this->db->pdo();
    if ($pdo->inTransaction()) throw new \RuntimeException('La sincronización de avisos requiere una operación independiente.');
    $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lock = 'scm-internal-events-' . substr(hash('sha256', $this->db->table('jet_cct_confi_sistema')), 0, 24);
    if ($mysql && (int) $this->db->getVar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) return ['queued' => 0, 'busy' => true];
    try {
      // Queue initialization executes DDL, so it precedes the business transaction.
      (new SharedNotificationsBridge($this->db))->queue();
      $settings = new Settings($this->db);
      $before = $settings->get(self::STATE_KEY, []);
      $state = is_array($before) ? $before : [];
      $queued = 0;
      $pdo->beginTransaction();
      $schema = new SchemaInspector($this->db);
      if ($schema->tableExists('calendario_actividades')) {
        $history = $this->db->table('jet_cct_historial_del_ticket');
        $appointmentIds = [];
        if ($schema->tableExists($history) && $schema->columnExists($history, 'id_evento')) {
          foreach ($this->db->getResults("SELECT DISTINCT id_evento FROM `{$history}` WHERE id_evento IS NOT NULL AND TRIM(CAST(id_evento AS CHAR)) NOT IN ('','0')") as $item) $appointmentIds[(string) $item['id_evento']] = true;
        }
        $rows = $this->db->getResults("SELECT id,id_ticket,titulo,fecha_inicio,fecha_fin,ubicacion,estado,observacion,creado_por,id_empleado FROM calendario_actividades WHERE TRIM(COALESCE(id_ticket,'')) NOT IN ('','0') ORDER BY id");
        $seed = !isset($state['calendar']);
        $previous = $state['calendar'] ?? [];
        $next = [];
        foreach ($rows as $row) {
          $id = (string) $row['id'];
          if (!isset($appointmentIds[$id])) continue;
          $done = in_array(mb_strtolower(trim((string) $row['estado'])), ['si','sí','realizada','realizado','finalizado'], true);
          $old = $previous[$id] ?? null;
          $revision = (int) ($old['revision'] ?? 0) + ($old === null || (bool) $old['done'] !== $done ? 1 : 0);
          if (!$seed && ($old === null || ($done && !$old['done']))) {
            $ticket = $this->ticket((string) $row['id_ticket']);
            $details = (string) $row['titulo'] . "\nInicio: " . $row['fecha_inicio'] . "\nFin: " . $row['fecha_fin'] . "\nLugar: " . $row['ubicacion'];
            if (!$seed && $old === null) $queued += $this->notice('agendar_cita', $ticket, 'Cita agendada', $details, 'calendar:' . $id, ['calendar_event_id' => $id, 'origin_employee_id' => $row['creado_por']]);
            if (!$seed && $done && ($old === null || !$old['done'])) $queued += $this->notice('cita_realizada', $ticket, 'Cita realizada', $details . "\nObservación: " . $row['observacion'], 'calendar:' . $id . ':' . $revision, ['calendar_event_id' => $id, 'assigned_employee_id' => $row['id_empleado']]);
          }
          $next[$id] = ['done' => $done, 'revision' => $revision];
        }
        $state['calendar'] = $next;
      }
      $table = $this->db->table('jet_cct_revision_preventiva');
      if ($schema->tableExists($table)) {
        $rows = $this->db->getResults("SELECT _ID,id_ticket,contrato,inmueble,direccion,se_envio,fecha_envio,id_empleado FROM `{$table}` ORDER BY _ID");
        $seed = !isset($state['preventive']);
        $previous = $state['preventive'] ?? [];
        $next = [];
        foreach ($rows as $row) {
          $id = (string) $row['_ID'];
          $sent = in_array(mb_strtolower(trim((string) $row['se_envio'])), ['si','sí','1','true'], true);
          $fingerprint = $sent ? hash('sha256', (string) $row['fecha_envio']) : '';
          $old = $previous[$id] ?? null;
          $revision = (int) ($old['revision'] ?? 0) + ($old === null || $old['fingerprint'] !== $fingerprint ? 1 : 0);
          if (!$seed && $sent && ($old === null || $old['fingerprint'] !== $fingerprint)) {
            $details = 'Revisión preventiva #' . $id . "\nContrato: " . $row['contrato'] . "\nInmueble SIMI: " . $row['inmueble'] . "\nDirección: " . $row['direccion'] . "\nActa enviada.";
            $queued += $this->notice('acta_preventiva', $this->ticket((string) $row['id_ticket']), 'Acta de revisión preventiva enviada', $details, 'preventive:' . $id . ':' . $revision, ['revision_id' => $id, 'origin_employee_id' => $row['id_empleado']]);
          }
          $next[$id] = ['fingerprint' => $fingerprint, 'revision' => $revision];
        }
        $state['preventive'] = $next;
      }
      if ($state !== $before) $settings->set(self::STATE_KEY, $state);
      $pdo->commit();
      return ['queued' => $queued, 'busy' => false];
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    } finally {
      if ($mysql) $this->db->getVar('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  private function notice(string $action, array $ticket, string $title, string $details, string $reference, array $meta): int
  {
    if (InternalNotificationRecipients::emailsForAction($this->db, $action) === []) return 0;
    $queued = InternalAdminEventNotification::enqueue($this->db, $action, $ticket, $title, $details, $reference, $meta);
    if (!$queued) throw new \RuntimeException('No se pudo encolar el aviso ' . $action . '. Se reintentará sin duplicar eventos anteriores.');
    return $queued;
  }

  private function ticket(string $number): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $pk = ctype_digit($number) ? $this->db->getRow("SELECT * FROM `{$table}` WHERE _ID = ? LIMIT 1", [(int) $number]) : null;
    return $pk ?: $this->db->getRow("SELECT * FROM `{$table}` WHERE id_ticket = ? LIMIT 1", [$number]) ?: [];
  }
}
