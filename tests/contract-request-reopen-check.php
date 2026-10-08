<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';

final class RequestReopenPdo extends PDO
{
  public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    $query = str_replace(' FOR UPDATE', '', $query);
    if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) $query = 'SELECT 1 WHERE ? IS NOT NULL';
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
  {
    $stmt = $this->prepare($query); $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$args);
    return $stmt;
  }
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject(callable $fn, string $message): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$pdo = new RequestReopenPdo();
$db = new \SCM\Core\Database($pdo, 'test_');
$pdo->exec('CREATE TABLE test_jet_cct_tickets (_ID INTEGER PRIMARY KEY, id_ticket TEXT, tema_ayuda TEXT, estado TEXT, estado_administrativo TEXT, estado_admin_ticket TEXT, estado_admin TEXT, archivos TEXT, cct_modified TEXT, fecha_actualizacion INTEGER)');
$pdo->exec('CREATE TABLE test_jet_cct_solicitudes_terminacion_contrato (_ID INTEGER PRIMARY KEY, id_ticket TEXT, tema_ayuda TEXT, estado TEXT, carta_url TEXT, carta_archivo TEXT, cct_modified TEXT)');
$pdo->exec('CREATE TABLE test_jet_cct_historial_del_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, id_ticket INTEGER, respuesta TEXT, nombre TEXT, id_empleado TEXT, cct_author_id TEXT, fecha INTEGER)');
$pdo->exec("INSERT INTO test_jet_cct_tickets VALUES (41,'10863','Terminacion de contrato','Cerrado','Finalizado','Finalizado','Finalizado','acta-anterior.pdf','',0),(42,'10864','No prorroga de contrato','Cerrado','Finalizado','Finalizado','Finalizado','no-prorroga.pdf','',0),(43,'10865','No prorroga de contrato','En proceso','Nuevo','Nuevo','Nuevo','','',0),(44,'10866','No prorroga de contrato','Anulado','Finalizado','','','','',0),(45,'10867','Otro','Cerrado','Finalizado','','','','',0)");
$pdo->exec("INSERT INTO test_jet_cct_solicitudes_terminacion_contrato VALUES (1,'10863','Terminacion de contrato','Dentro de termino','acta-anterior.pdf','soporte',''),(2,'10865','Terminacion de contrato','Nuevo','','','')");
final class ReopenResult extends RuntimeException {}
final class ReopenProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public bool $permission = false;
  public bool $csrf = false;
  public function __construct(public \SCM\Core\Database $db) {}
  private function column_exists(string $table, string $column): bool { return (new \SCM\Support\SchemaInspector($this->db))->columnExists($table, $column); }
  private function canUseDashboardAction(string $action): bool { return $this->permission; }
  private function verifyCsrf(): void { $this->csrf = true; }
  private function jsonFail(string $message): never { throw new ReopenResult($message); }
  public function termination(bool $answered): array {
    $table = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    [$sql, $args] = $this->contractTerminationWhereSql($table, 't', $answered);
    return array_map('intval', $this->db->getCol("SELECT _ID FROM `{$table}` t WHERE {$sql} ORDER BY _ID", $args));
  }
  public function nonRenewal(bool $answered): array {
    $table = $this->db->table('jet_cct_tickets');
    $sql = $this->contractNonRenewalWhereSql($table, 't', $answered);
    return array_map('intval', $this->db->getCol("SELECT _ID FROM `{$table}` t WHERE {$sql} ORDER BY _ID"));
  }
}
$probe = new ReopenProbe($db);
check($probe->termination(true) === [1] && $probe->termination(false) === [2], 'Termination SQL separates answered and pending');
check($probe->nonRenewal(true) === [42] && $probe->nonRenewal(false) === [43], 'No renewal SQL excludes cancelled and unrelated topics');
$_POST = ['confirm_reopen' => '1'];
try { $probe->ajax_handler_contract_request_reopen(); } catch (ReopenResult $e) { check(str_contains($e->getMessage(), 'permiso') && $probe->csrf, 'CSRF and permission gate before mutation'); }
$probe->permission = true; $_POST = [];
try { $probe->ajax_handler_contract_request_reopen(); } catch (ReopenResult $e) { check(str_contains($e->getMessage(), 'Confirma'), 'Confirmation required server-side'); }
$service = new \SCM\Modules\Contracts\ContractRequestReopenService($db);
$actor = ['employee_id' => 'EMP-900', 'name' => 'Funcionario QA'];
$termMatch = fn($r) => $r['tema_ayuda'] === 'Terminacion de contrato';
$nonMatch = fn($r) => $r['tema_ayuda'] === 'No prorroga de contrato';
reject(fn() => $service->reopen('termination', 42, 1, $actor, $termMatch), 'Reject wrong kind');
reject(fn() => $service->reopen('termination', 41, 2, $actor, $termMatch), 'Reject pending request');
reject(fn() => $service->reopen('non-renewal', 43, 43, $actor, $nonMatch), 'Reject already pending ticket');
reject(fn() => $service->reopen('non-renewal', 44, 44, $actor, $nonMatch), 'Reject cancelled ticket');
reject(fn() => $service->reopen('termination', 41, 1, ['employee_id' => ''], $termMatch), 'Real employee required');
$pdo->exec("CREATE TRIGGER fail_reopen BEFORE UPDATE ON test_jet_cct_tickets BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
reject(fn() => $service->reopen('termination', 41, 1, $actor, $termMatch), 'Failure must propagate');
check($db->getVar('SELECT estado FROM test_jet_cct_solicitudes_terminacion_contrato WHERE _ID=1') === 'Dentro de termino', 'Request rollback');
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_historial_del_ticket') === 0, 'History rollback');
$pdo->exec('DROP TRIGGER fail_reopen');
// Pending signed-workflow documents keep the existing activation guard.
$pdo->exec('CREATE TABLE test_scm_ticket_completion_acts (ticket_pk INTEGER, active_slot INTEGER, status TEXT)');
$pdo->exec("INSERT INTO test_scm_ticket_completion_acts VALUES (41,1,'pending')");
reject(fn() => $service->reopen('termination', 41, 1, $actor, $termMatch), 'Pending signature guard');
$pdo->exec('DELETE FROM test_scm_ticket_completion_acts');
$service->reopen('termination', 41, 1, $actor, $termMatch);
$service->reopen('non-renewal', 42, 42, $actor, $nonMatch);
check($probe->termination(true) === [] && $probe->termination(false) === [1,2], 'Reopened termination returns to pending');
check($probe->nonRenewal(true) === [] && $probe->nonRenewal(false) === [42,43], 'Reopened no renewal returns to pending');
check($db->getVar('SELECT estado FROM test_jet_cct_solicitudes_terminacion_contrato WHERE _ID=1') === 'Nuevo', 'Request Nuevo');
$ticket = $db->getRow('SELECT * FROM test_jet_cct_tickets WHERE _ID=41');
check($ticket['estado'] === 'En proceso' && $ticket['estado_administrativo'] === 'Nuevo' && $ticket['estado_admin_ticket'] === 'Nuevo' && $ticket['estado_admin'] === 'Nuevo', 'Existing activation states and aliases');
check($ticket['archivos'] === 'acta-anterior.pdf' && $db->getVar('SELECT carta_url FROM test_jet_cct_solicitudes_terminacion_contrato WHERE _ID=1') === 'acta-anterior.pdf', 'Old acts retained');
$history = $db->getResults('SELECT * FROM test_jet_cct_historial_del_ticket');
check(count($history) === 2 && $history[0]['cct_author_id'] === 'EMP-900' && $history[0]['id_empleado'] === 'EMP-900' && str_contains($history[0]['respuesta'], 'Dentro de termino'), 'History records actor and previous state');
reject(fn() => $service->reopen('termination', 41, 1, $actor, $termMatch), 'Duplicate reopen rejected');
echo "PASS: answered/pending SQL, permissions, CSRF, confirmation, both reopen types, rollback, signature guard, actor history and retained acts.\n";
