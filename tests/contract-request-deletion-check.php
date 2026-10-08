<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';

final class RequestDeletionPdo extends PDO
{
  public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    $query = str_replace(' FOR UPDATE', '', $query);
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
  public function exec(string $statement): int|false
  {
    $statement = str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $statement);
    $statement = str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', '', $statement);
    return parent::exec($statement);
  }
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new RequestDeletionPdo();
$db = new \SCM\Core\Database($pdo, 'test_');
$pdo->exec('CREATE TABLE test_jet_cct_tickets (_ID INTEGER PRIMARY KEY, id_ticket TEXT, tema_ayuda TEXT, estado_administrativo TEXT)');
$pdo->exec('CREATE TABLE test_jet_cct_solicitudes_terminacion_contrato (_ID INTEGER PRIMARY KEY, id_ticket TEXT, motivo TEXT)');
$pdo->exec("INSERT INTO test_jet_cct_tickets VALUES (41,'10863','terminacion','Nuevo'),(42,'10864','no prorroga','Nuevo'),(43,'10865','otro','Nuevo')");
$pdo->exec("INSERT INTO test_jet_cct_solicitudes_terminacion_contrato VALUES (1,'10863','Ejemplo'),(2,'41','Segunda solicitud'),(3,'99999','Huérfana')");
$service = new \SCM\Modules\Contracts\ContractRequestDeletionService($db);
$actor = ['employee_id' => 'EMP-900', 'name' => 'Funcionario QA'];
$match = fn(array $row): bool => $row['tema_ayuda'] === 'terminacion';
$reject = function (callable $fn, string $message) { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); };
$reject(fn() => $service->delete('termination', 42, 1, $actor, $match), 'Must reject mismatched ticket and request');
$reject(fn() => $service->delete('non-renewal', 43, 0, $actor, fn($r) => false), 'Must reject unrelated topic');
$reject(fn() => $service->delete('invalid', 41, 1, $actor, $match), 'Must validate kind');
$reject(fn() => $service->delete('termination', 41, 1, ['employee_id' => ''], $match), 'Must require real employee');
// A failed ticket deletion must restore both request rows and its audit insert.
$pdo->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON test_jet_cct_tickets BEGIN SELECT RAISE(ABORT,'synthetic delete failure'); END");
$reject(fn() => $service->delete('termination', 41, 1, $actor, $match), 'Delete failure must propagate');
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_solicitudes_terminacion_contrato') === 3, 'Rollback restores requests');
check((int) $db->getVar('SELECT COUNT(*) FROM test_scm_contract_request_deletions') === 0, 'Rollback restores audit');
$pdo->exec('DROP TRIGGER fail_delete');
$service->delete('termination', 41, 1, $actor, $match);
check(!$db->getRow('SELECT * FROM test_jet_cct_tickets WHERE _ID=41'), 'Ticket deleted');
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_solicitudes_terminacion_contrato') === 1, 'All linked requests deleted, unrelated request preserved');
$audit = $db->getRow('SELECT * FROM test_scm_contract_request_deletions');
$snapshot = json_decode($audit['snapshot_json'], true);
check($audit['actor_employee_id'] === 'EMP-900' && $snapshot['ticket']['estado_administrativo'] === 'Nuevo' && count($snapshot['requests']) === 2, 'Independent audit preserves actor and original records without state changes');
$reject(fn() => $service->delete('termination', 41, 1, $actor, $match), 'Duplicate request must fail');
$service->delete('non-renewal', 42, 42, $actor, fn($r) => $r['tema_ayuda'] === 'no prorroga');
check(!$db->getRow('SELECT * FROM test_jet_cct_tickets WHERE _ID=42'), 'No renewal ticket is its request and is deleted');
$service->delete('termination', 0, 3, $actor, $match);
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_solicitudes_terminacion_contrato') === 0, 'Orphan request can be removed without another case');
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_tickets') === 1, 'Unrelated ticket remains');
// PK and logical ticket number collisions must never select a random case.
$pdo->exec("INSERT INTO test_jet_cct_tickets VALUES (50,'600','terminacion','Nuevo'),(600,'700','terminacion','Nuevo')");
$pdo->exec("INSERT INTO test_jet_cct_solicitudes_terminacion_contrato VALUES (4,'600','Ambigua')");
$reject(fn() => $service->delete('termination', 50, 4, $actor, $match), 'Ambiguous reference must fail');
check((int) $db->getVar('SELECT COUNT(*) FROM test_jet_cct_tickets') === 3, 'Ambiguous reference preserves both cases');

final class DeletionResult extends RuntimeException {}
final class DeletionGuardProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public bool $csrf = false;
  public bool $permission = false;
  private function verifyCsrf(): void { $this->csrf = true; }
  private function canUseDashboardAction(string $action): bool { return $this->permission; }
  private function canAccessDashboardTab(string $tab): bool { return false; }
  private function jsonFail(string $message): never { throw new DeletionResult($message); }
}
$probe = new DeletionGuardProbe();
$_POST = ['confirm_delete' => '1'];
try { $probe->ajax_handler_contract_request_delete(); } catch (DeletionResult $e) { check(str_contains($e->getMessage(), 'permiso') && $probe->csrf, 'CSRF and permission gate precede mutation'); }
$probe->permission = true; $_POST = [];
try { $probe->ajax_handler_contract_request_delete(); } catch (DeletionResult $e) { check(str_contains($e->getMessage(), 'Confirma'), 'Explicit confirmation required server-side'); }
echo "PASS: both request types, linked deletion, mismatch/type/actor guards, orphan and duplicate requests, ambiguous references, atomic rollback, preserved audit, CSRF, permissions and confirmation.\n";
