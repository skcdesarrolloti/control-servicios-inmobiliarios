<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';
date_default_timezone_set('America/Bogota');

final class RequestEndDatePdo extends PDO
{
  public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
  public function prepare(string $query, array $options = []): PDOStatement|false
  {
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
final class RequestEndDateProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public function __construct(public \SCM\Core\Database $db) {}
  private function table_exists($table) { return (new \SCM\Support\SchemaInspector($this->db))->tableExists($table); }
  private function column_exists($table, $column) { return (new \SCM\Support\SchemaInspector($this->db))->columnExists($table, $column); }
  public function parse_unix_ts($value) { return is_numeric($value) ? (int) $value : (int) strtotime((string) $value); }
  public function nonRenewal(bool $answered = false) { return $this->contractNonRenewalRequestItems(150, false, $answered); }
  public function response(int $pk) { return $this->contractTerminationTicketByPk($pk); }
  public function termination(array $request) { return $this->contractTerminationListItem($this->contractTerminationMergeSolicitudTicket($request), false); }
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new RequestEndDatePdo();
$db = new \SCM\Core\Database($pdo);
$probe = new RequestEndDateProbe($db);
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento (_ID INTEGER PRIMARY KEY, contrato TEXT, inmueble TEXT, id_inmueble TEXT, fin_contrato TEXT, inicio_contrato TEXT, correo_propietario TEXT)');
// Production cases have no fin_contrato column; the end date belongs to the contract.
$pdo->exec('CREATE TABLE wp_jet_cct_tickets (_ID INTEGER PRIMARY KEY, id_ticket TEXT, id_contrato TEXT, contrato TEXT, inmueble TEXT, id_inmueble TEXT, fecha_terminacion_contrato INTEGER, fecha INTEGER, estado TEXT, estado_administrativo TEXT, tema_ayuda TEXT)');
foreach ([[272,'437','10385','33649','2026-01-26'],[121,'218','10226','48466','2026-03-30'],[437,'999','99999','99999','2030-01-01']] as [$pk,$code,$property,$web,$end]) {
  $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>$pk,'contrato'=>$code,'inmueble'=>$property,'id_inmueble'=>$web,'fin_contrato'=>(string)strtotime($end),'inicio_contrato'=>'2024-01-27','correo_propietario'=>'owner@example.invalid']);
}
foreach ([[10952,'272','437','10385','33649','2026-10-10'],[10908,'121','218','10226','48466','2026-10-07'],[10960,'404','404','404','404','2026-10-10']] as [$pk,$contract,$code,$property,$web,$date]) {
  $db->insert('wp_jet_cct_tickets', ['_ID'=>$pk,'id_ticket'=>(string)$pk,'id_contrato'=>$contract,'contrato'=>$code,'inmueble'=>$property,'id_inmueble'=>$web,'fecha'=>strtotime($date),'estado'=>'Nuevo','estado_administrativo'=>'Nuevo','tema_ayuda'=>'No prorroga de contrato']);
}
$items = array_column($probe->nonRenewal(), null, 'id_ticket');
foreach (['10952'=>['2026-01-26','26/01/2026','2025-10-26'], '10908'=>['2026-03-30','30/03/2026','2025-12-30']] as $id=>[$iso,$label,$limit]) {
  check($items[$id]['fin_contrato'] === $iso && $items[$id]['fin_contrato_label'] === $label, "Case {$id} reads linked contract end date");
  check($items[$id]['fecha_limite_terminacion'] === $limit && $items[$id]['term_status'] === 'fuera', "Case {$id} calculates term using original request date");
  $response = $probe->response((int)$id);
  check((int)$response['_ID'] === $id && $response['id_ticket'] === (string)$id, 'Contract enrichment preserves case identifiers');
  check(date('Y-m-d',(int)$response['fin_contrato']) === $iso && $response['correo_propietario'] === 'owner@example.invalid', 'Response loads contract date and contacts');
}
check($items['10960']['term_status'] === 'unknown' && $items['10960']['fin_contrato'] === '', 'Unmatched cases remain unknown instead of using an unrelated contract');
check($probe->response(999999) === [], 'Missing cases stay missing');
$db->update('wp_jet_cct_contratos_arrendamiento', ['fin_contrato'=>(string)strtotime('2027-01-26')], ['_ID'=>272]);
$updated = array_column($probe->nonRenewal(), null, 'id_ticket');
check($updated['10952']['fin_contrato'] === '2027-01-26' && $updated['10952']['term_status'] === 'dentro', 'List uses current contract date after a renewal/import');
$db->update('wp_jet_cct_tickets', ['estado'=>'Cerrado','estado_administrativo'=>'Finalizado'], ['_ID'=>10952]);
$answered = array_column($probe->nonRenewal(true), null, 'id_ticket');
check($answered['10952']['fin_contrato'] === '2027-01-26', 'Answered view also resolves contract end date');
// Existing termination requests use the same contract enrichment without replacing request metadata.
$termination = $probe->termination(['_ID'=>500,'id_ticket'=>'10908','id_contrato'=>'121','contrato'=>'218','inmueble'=>'10226','fecha'=>strtotime('2026-10-07'),'estado'=>'Nuevo']);
check($termination['solicitud_id'] === '500' && $termination['ticket_pk'] === '10908' && $termination['fin_contrato'] === '2026-03-30', 'Termination retains request/case identities and contract date');
check($db->getVar('SELECT fecha_terminacion_contrato FROM wp_jet_cct_tickets WHERE _ID=10908') === null, 'Reading contract context never modifies the stored case');
echo "PASS: pending/answered non-renewal dates, response context, contract-number collisions, missing contracts, updated end dates and termination metadata. No business data changed.\n";
