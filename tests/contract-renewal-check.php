<?php
declare(strict_types=1);

// SQLite fixtures plus a transactional queue double: no production DB or transport.
namespace SharedNotifications\Config {
  class QueueConfig { public function __construct(...$args) {} public function queueTable() { return 'test_jobs'; } }
}
namespace SharedNotifications\Storage {
  class PdoStorageAdapter { public function __construct(public \PDO $pdo) {} }
}
namespace SharedNotifications {
  class NotificationQueue {
    public static int $constructions = 0;
    public static bool $failEnqueue = false;
    public function __construct(private $storage, $config) {
      if ($storage->pdo->inTransaction()) throw new \RuntimeException('Queue initialization must not execute DDL inside a business transaction');
      self::$constructions++;
    }
    public function enqueue(array $job): int {
      if (self::$failEnqueue) throw new \RuntimeException('Synthetic queue failure');
      $stmt = $this->storage->pdo->prepare('INSERT INTO test_jobs(dedupe_key,status,payload) VALUES (?, ?, ?)');
      $stmt->execute([$job['dedupe_key'], 'pending', json_encode($job)]);
      return (int) $this->storage->pdo->lastInsertId();
    }
    public function cancelByDedupeKey(string $key, string $reason = ''): int {
      $stmt = $this->storage->pdo->prepare("UPDATE test_jobs SET status='cancelled' WHERE dedupe_key=? AND status='pending'");
      $stmt->execute([$key]); return $stmt->rowCount();
    }
  }
}
namespace {
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';
require dirname(__DIR__) . '/src/Modules/Pending/Concerns/AdministrativeTicketCreationConcern.php';
require dirname(__DIR__) . '/src/Modules/Pending/Concerns/PendingNotificationsAndDatesConcern.php';
date_default_timezone_set('America/Bogota');
define('SCM_APP_SECRET', 'synthetic-review-secret');
$_SESSION = ['scm_employee_id' => 'EMP-900', 'scm_user_id' => 9, 'scm_user' => 'Test creator'];

final class RenewalTestPdo extends PDO {
  public function __construct() {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->sqliteCreateFunction('REGEXP', fn($pattern, $value) => preg_match('/' . $pattern . '/', (string) $value));
    $this->sqliteCreateFunction('UNIX_TIMESTAMP', fn($value) => strtotime((string) $value));
    $this->sqliteCreateFunction('FLOOR', fn($value) => floor((float) $value));
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    if (str_contains($query, 'f.id_empleado = e.actor')) throw new PDOException('Illegal mix of collations between actor and CCT employee');
    // MySQL numeric dates acquire a coercible collation when converted to text.
    // Reject the old expression so SQLite cannot conceal this production failure.
    if (str_contains($query, "TRIM(COALESCE(`fecha_terminacion_contrato`, '')) = ?")) throw new PDOException('Illegal mix of collations on numeric termination date');
    $query = str_replace(' FOR UPDATE', '', $query);
    $query = str_replace(' BETWEEN ? AND ?', ' BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER)', $query);
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    $query = str_replace("TRIM(LEADING '0' FROM TRIM(`contrato`))", "LTRIM(TRIM(`contrato`),'0')", $query);
    return parent::prepare($query, $options);
  }
  public function exec(string $statement): int|false {
    if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS')) {
      $statement = preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', ')', $statement);
      $statement = str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $statement);
      $statement = preg_replace('/,\s*KEY \w+\([^)]*\)/', '', $statement);
    }
    return parent::exec($statement);
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}
final class RenewalResult extends RuntimeException { public function __construct(public bool $ok, public array $data) { parent::__construct((string) ($data['message'] ?? '')); } }
final class RenewalProbe {
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public bool $writable = true;
  public bool $failResponse = false;
  public array $spreadsheetRows = [];
  public function __construct(public \SCM\Core\Database $db) {}
  public function parse($value) { return $this->contractsEndingImportDateTimestamp($value); }
  public function pk($id) { return $this->contractEndingContractByPk($id); }
  public function matches($rows) { return $this->contractsEndingImportContractMatches($rows); }
  public function token($rows, $expires) { return $this->contractsEndingImportToken($rows, $expires); }
  public function retention($row) { return $this->contractEndingRetentionTicketId($row); }
  public function month($year, $month) { return $this->contractsEndingMonthItems($year, $month); }
  public function defaultEmployee($row,$ids) { return $this->contractRetentionDefaultEmployeeId($row,$ids); }
  public function createRetention($row) { return $this->createContractRetentionTicketFromContractRequest($row,'','EMP-13','test','','','contrato por terminar',(string)$row['_ID']); }
  public function requestContract($row) { return $this->contractTerminationContractByContext($row); }
  public function createRequestRetention($row, $source) { return $this->createContractRetentionTicketFromContractRequest($row,'dentro','EMP-13','test','','',$source); }
  public function respondRequest($row, $term, $source, $retention = false) { return $this->saveContractRequestResponse((int)$row['_ID'],$row,$term,'Respuesta de prueba',[],$retention,'EMP-13','','',$source); }
  public function preview($file) { return $this->contractsEndingImportPreview($file); }
  private function contractsEndingImportSpreadsheetRows(string $path, string $name): array { return $this->spreadsheetRows; }
  private function verifyCsrf() {}
  private function canAccessDashboardTab($tab) { return $tab === 'contractual' || $this->writable; }
  private function canUseDashboardAction($action) { return $this->writable; }
  private function jsonFail($message) { throw new RenewalResult(false, ['message' => $message]); }
  private function jsonOk($data) { throw new RenewalResult(true, $data); }
  private function table_exists($table) { return (new \SCM\Support\SchemaInspector($this->db))->tableExists($table); }
  private function column_exists($table,$column) { return (new \SCM\Support\SchemaInspector($this->db))->columnExists($table,$column); }
  private function calendarCitaFuncionarioRow($value,$mode) { return []; }
  private function get_seguimiento_service() {
    return new class($this->db, $this->failResponse) {
      public function __construct(private $db, private bool $fail) {}
      public function saveTicketResponse($pk, $text, $admin, $close, $notify, ...$args): array {
        if (!$this->db->pdo()->inTransaction() || $notify !== ['none']) throw new RuntimeException('Response and probability must share transaction without duplicate notifications');
        $this->db->update('wp_jet_cct_tickets', ['estado'=>'Cerrado','estado_administrativo'=>$admin], ['_ID'=>$pk]);
        $this->db->insert('wp_jet_cct_historial_del_ticket', ['id_ticket'=>$pk,'nombre'=>$text,'id_empleado'=>'EMP-900','cct_author_id'=>'EMP-900']);
        return $this->fail ? ['ok'=>'0','message'=>'Synthetic response failure'] : ['ok'=>'1'];
      }
    };
  }
  private function get_pending_controller() {
    return new class($this->db) {
      public function __construct(private $db) {}
      public function createAdministrativeTicket($input, ...$args) {
        $contract = $this->db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID=?', [$input['contract_pk']]);
        $id = (int) $this->db->getVar('SELECT COALESCE(MAX(_ID),0)+1 FROM wp_jet_cct_tickets');
        $this->db->insert('wp_jet_cct_tickets', ['_ID'=>$id,'id_ticket'=>(string)$id,'id_contrato'=>$input['contract_pk'],'tema_ayuda'=>$input['tema_ayuda'],'fecha_terminacion_contrato'=>$contract['fin_contrato']]);
        return ['ok'=>'1','ticket_id'=>(string)$id,'input'=>$input];
      }
    };
  }
}
final class RealTicketCreationProbe {
  use \SCM\Modules\Pending\Concerns\AdministrativeTicketCreationConcern;
  use \SCM\Modules\Pending\Concerns\PendingNotificationsAndDatesConcern;
  public object $repo;
  public bool $letterAvailable = false;
  public function __construct(\SCM\Core\Database $db) {
    $this->repo = new class($db) {
      public function __construct(private $db) {}
      public function getDb() { return $this->db; }
      public function getFuncionarioById($id) { return $this->db->getRow('SELECT * FROM wp_jet_cct_funcionarios WHERE id_empleado=?',[$id]); }
      public function getSucursalById($id) { return []; }
    };
  }
  private function ticketCreatorProfile($schema): array { return ['name'=>'Creator test','cargo'=>'Test','phone'=>'','email'=>'']; }
  private function generateTicketPdfs($mode,$tema,$id,$payload): array { return ['pdfs'=> $this->letterAvailable ? ['acta_desocupacion'=>['url'=>'https://example.invalid/signed-letter.pdf','title'=>'Carta previa de entrega']] : [], 'warnings'=>[]]; }
}
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function callEndpoint(callable $fn): RenewalResult { try { $fn(); } catch (RenewalResult $result) { return $result; } throw new RuntimeException('Missing endpoint response'); }

$pdo = new RenewalTestPdo(); $db = new \SCM\Core\Database($pdo); $probe = new RenewalProbe($db);
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY,contrato TEXT,inmueble TEXT,id_inmueble TEXT,id_inmueble_data TEXT,fin_contrato TEXT,estado TEXT,valor_canon TEXT,destinacion_inmueble TEXT,correo_arrendatario TEXT,id_empleado TEXT,cct_author_id TEXT,cct_modified TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_tickets(_ID INTEGER PRIMARY KEY,id_ticket TEXT,id_contrato TEXT,tema_ayuda TEXT,fecha_terminacion_contrato INTEGER)');
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY,codigo TEXT,id_funcionario TEXT,propietario TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY,id_empleado TEXT,nombre TEXT,correo TEXT,celular TEXT,id_cargo TEXT,activo TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY,funcion TEXT,valor TEXT)');
$pdo->exec('CREATE TABLE test_jobs(id INTEGER PRIMARY KEY,dedupe_key TEXT,status TEXT,payload TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_ticket(_ID INTEGER PRIMARY KEY,id_ticket TEXT,nombre TEXT,id_empleado TEXT,cct_author_id TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY,id_ticket TEXT,funcionario TEXT,id_empleado TEXT,cct_author_id TEXT)');
$pdo->exec('ALTER TABLE wp_jet_cct_tickets ADD COLUMN cct_author_id TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_tickets ADD COLUMN id_empleado TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_tickets ADD COLUMN estado TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_tickets ADD COLUMN estado_administrativo TEXT');
$db->insert('wp_jet_cct_funcionarios', ['_ID'=>13,'id_empleado'=>'EMP-13','nombre'=>'Assigned test','correo'=>'test@example.invalid','id_cargo'=>'13','activo'=>'Si']);
$db->insert('wp_jet_cct_confi_sistema', ['_ID'=>1,'funcion'=>'control_servicios_config','valor'=>json_encode(['internal_admin_notifications'=>['contrato_no_salida'=>[13],'retencion_contrato_ticket'=>[13],'contrato_recibo_automatico'=>[13]]])]);
$end = strtotime('today +15 days');
foreach ([193=>'900',800=>'193',801=>'901',802=>'902'] as $id=>$code) $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>$id,'contrato'=>$code,'inmueble'=>(string)$id,'id_inmueble'=>(string)$id,'fin_contrato'=>(string)$end,'estado'=>'Entregado','valor_canon'=>'1.000.000','correo_arrendatario'=>'tenant@example.invalid','id_empleado'=>'EMP-13']);
$db->insert('wp_jet_cct_inmuebles', ['_ID'=>1,'codigo'=>'193','id_funcionario'=>'PROPERTY-EMP','propietario'=>'Owner test']);
$service = new \SCM\Modules\Contracts\ContractRenewalService($db);
$temp = sys_get_temp_dir() . '/scm-renewal-test-' . bin2hex(random_bytes(6)); mkdir($temp);
file_put_contents($temp.'/autoload.php', '<?php'); file_put_contents($temp.'/config.php', '<?php return [];');
putenv('SHARED_NOTIFICATIONS_PATH='.$temp);
$service->ensureSchema();
try {
  check(count($probe->month((int)date('Y',$end),(int)date('n',$end)))===4,'Month list must load with numeric ticket dates and mixed database collations');
  $db->update('wp_jet_cct_contratos_arrendamiento',['destinacion_inmueble'=>'Vivienda contractual'],['_ID'=>193]);
  $monthRows=$probe->month((int)date('Y',$end),(int)date('n',$end));
  $listedContract=array_values(array_filter($monthRows,static fn(array $row):bool=>(string)$row['contract_pk']==='193'))[0];
  check($listedContract['property_details']['destinacion']==='Vivienda contractual','Property modal must receive the contract use from the month query');
  check($probe->pk('193')['contrato']==='900','Exact PK must beat another contract number');
  foreach (['31/02/2026','00/10/2026','2026-02-31','2026-10-01junk'] as $date) check($probe->parse($date)===0,'Invalid date accepted: '.$date);
  check(date('Y-m-d',$probe->parse('29/02/2028'))==='2028-02-29','Leap day rejected');
  check(\SCM\Modules\Contracts\ContractRenewalService::canon('$ 1.000.000,50')===1000000.5,'Colombian canon parse');
  check(\SCM\Modules\Contracts\ContractRenewalService::probability('80')===80.0,'Probability parse');
  try { \SCM\Modules\Contracts\ContractRenewalService::probability('101'); throw new RuntimeException('101 accepted'); } catch (InvalidArgumentException $expected) {}
  check($probe->defaultEmployee($probe->pk('193'),['PROPERTY-EMP','EMP-13'])==='PROPERTY-EMP','Property responsibility must take priority');
  $matches=$probe->matches([['line'=>2,'contract'=>'900','property'=>'193'],['line'=>3,'contract'=>'193','property'=>'800'],['line'=>4,'contract'=>'900','property'=>'']]);
  check(count($matches[2])===1 && $matches[2][0]['_ID']===193,'Business number match');
  check(!isset($matches[4]),'Empty property must never match');
  $probe->spreadsheetRows = [['No. Contrato','No. Inmueble','Fin Contrato'],['900','193','01/12/2030'],['900','193','02/12/2030'],['901','','03/12/2030'],['193','800','31/02/2030']];
  $preview=$probe->preview(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$temp.'/autoload.php','name'=>'synthetic.xlsx','size'=>5]);
  check($preview['changes']===[] && $preview['stats']['invalid']===4,'Preview must reject duplicates, missing property and impossible dates');
  $a=[['contract_id'=>'193','new_fin_contrato'=>'123','old_fin_contrato'=>(string)$end]]; $expires=time()+1800;
  $b=$a;$b[0]['new_fin_contrato']='124';
  check($probe->token($a,$expires)!==$probe->token($b,$expires),'Edited batch token');
  $_SESSION['scm_employee_id']='another';check($probe->token($a,$expires)!==$probe->token($a,$expires+1),'Expiry token');$_SESSION['scm_employee_id']='EMP-900';
  $pdo->beginTransaction(); $service->save($probe->pk('193'),$end,80,true,'7,0','Confirmed no exit','EMP-900'); $pdo->commit();
  check((int)$db->getVar("SELECT COUNT(*) FROM test_jobs WHERE status='pending'")===2,'Two reminders should be queued');
  $job=json_decode($db->getVar('SELECT payload FROM test_jobs LIMIT 1'),true);
  check($job['destination']==='test@example.invalid' && str_contains($job['scheduled_at'],'-05:00'),'Configured contact and timezone');
  check($service->get(193,$end)['probability']==80,'Persisted probability');
  check($service->get(193,$end+86400)===[],'Probability must not leak into another cycle');
  $_POST=['contract_pk'=>'193','end_ts'=>$end,'revision'=>1,'no_exit'=>'0','probability'=>'85','reminder_days'=>'7,0','note'=>'Withdrawn'];
  check(!callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_renewal_save())->ok,'Retiring no exit requires backend confirmation');
  $pdo->beginTransaction(); $unchanged=$service->save($probe->pk('193'),$end,85,true,'7,0','Confirmed no exit','EMP-900'); $pdo->commit();
  check($unchanged['reminders_queued']===0 && (int)$db->getVar('SELECT COUNT(*) FROM test_jobs')===2,'Editing probability must preserve reminders without duplicates');
  $pdo->beginTransaction(); $service->save($probe->pk('193'),$end,80,false,'7,0','Withdrawn','EMP-900'); $pdo->commit();
  check((int)$db->getVar("SELECT COUNT(*) FROM test_jobs WHERE status='pending'")===0,'Withdrawal cancels pending reminders');
  check(count($service->history(193))===3,'Audit saves with the real actor');
  $db->insert('wp_jet_cct_funcionarios', ['_ID'=>900,'id_empleado'=>'EMP-900','nombre'=>'Real author','correo'=>'author@example.invalid','activo'=>'Si']);
  check($service->history(193)[0]['actor_name']==='Real author','History must resolve real author without joining tables with different collations');
  $pdo->beginTransaction(); $service->save($probe->pk('193'),$end,100,false,'7,0','','EMP-900');$pdo->commit();
  check($probe->createRetention($probe->pk('193'))['ok']==='0','100 percent must block retention in backend');
  $pdo->beginTransaction();$service->save($probe->pk('800'),$end,null,true,'7,0','No exit','EMP-900');$pdo->commit();
  check($probe->processAutomaticContractReceipts()['enabled']===false,'New automation must start disabled');
  check(\SCM\Modules\Contracts\ContractReceiptSettings::read($db)['contract_id']===525,'Default scope must be exactly 525');
  try { \SCM\Modules\Contracts\ContractReceiptSettings::validate($db,['enabled'=>true,'contract_id'=>0,'employee_id'=>'EMP-13']); throw new RuntimeException('Unrestricted scope accepted'); } catch (InvalidArgumentException $expected) {}
  $settings=json_decode($db->getVar('SELECT valor FROM wp_jet_cct_confi_sistema WHERE _ID=1'),true);
  $settings['contract_receipt_automation']=['enabled'=>true,'contract_id'=>193,'employee_id'=>'EMP-13','coordinator_id'=>''];
  $db->update('wp_jet_cct_confi_sistema',['valor'=>json_encode($settings)],['_ID'=>1]);
  check($probe->processAutomaticContractReceipts()['created']===0,'100 percent scope must suppress receipt');
  $settings['contract_receipt_automation']['contract_id']=800;
  $db->update('wp_jet_cct_confi_sistema',['valor'=>json_encode($settings)],['_ID'=>1]);
  check($probe->processAutomaticContractReceipts()['created']===0,'No exit scope must suppress receipt');
  $settings['contract_receipt_automation']['contract_id']=801;
  $db->update('wp_jet_cct_confi_sistema',['valor'=>json_encode($settings)],['_ID'=>1]);
  $before=(int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets');
  check(count($probe->processAutomaticContractReceipts(true)['candidates'])===1,'Dry run must inspect only configured contract');
  check((int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets')===$before,'Dry run must never create a ticket');
  $stats=$probe->processAutomaticContractReceipts();
  check($stats['created']===1,'Only nonrenewed exiting contract gets receipt: '.json_encode($stats));
  check($probe->processAutomaticContractReceipts()['created']===0,'Repeated automation must not duplicate receipt');
  check((int)$db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tickets WHERE id_contrato='802'")===0,'Eligible contract outside configured scope must never get a receipt');
  check($db->getVar("SELECT id_contrato FROM wp_jet_cct_tickets WHERE tema_ayuda='Recibo de inmuebles'")==='801','Receipt must belong to correct contract');
  // Entire import rolls back if any previous value changed.
  $changes=[];foreach ([193,800] as $id) $changes[]=['contract_id'=>(string)$id,'new_fin_contrato'=>(string)($end+86400),'old_fin_contrato'=>(string)$end,'contract_code'=>$probe->pk((string)$id)['contrato'],'property_code'=>(string)$id];
  $_POST=['changes'=>json_encode($changes),'expires'=>$expires,'token'=>$probe->token($changes,$expires)];
  $db->update('wp_jet_cct_contratos_arrendamiento',['fin_contrato'=>(string)($end+172800)],['_ID'=>800]);
  $response=callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_import_apply());
  check(!$response->ok && $probe->pk('193')['fin_contrato']===(string)$end,'Import conflict must roll back earlier updates');
  $db->update('wp_jet_cct_contratos_arrendamiento',['fin_contrato'=>(string)$end],['_ID'=>800]);
  $response=callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_import_apply());
  check($response->ok && $response->data['updated']===2,'Valid batch must apply atomically');
  check((int)$db->getVar("SELECT COUNT(*) FROM test_jobs WHERE status='pending'")===0,'Date import cancels old-cycle reminders');
  $_POST['expires']=time()-1; $_POST['token']=$probe->token($changes,$_POST['expires']);
  check(!callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_import_apply())->ok,'Expired batch must be rejected');
  $result=(new RealTicketCreationProbe($db))->createAdministrativeTicket([
    'contract_pk'=>'193','id_empleado'=>'EMP-13','tema_ayuda'=>'Retencion de contrato','departamento'=>'Servicio al cliente',
    'prioridad'=>'Prioridad urgente','asunto'=>'Retention test','descripcion'=>'Synthetic only', 'internal_notification_action'=>'retencion_contrato_ticket'
  ],[],[],['admin']);
  check($result['ok']==='1' && $result['emails_queued']==='1','The specific retention event must route to configured recipients: '.json_encode($result));
  check($db->getVar('SELECT id_contrato FROM wp_jet_cct_tickets WHERE _ID=?',[$result['ticket_id']])==='193','Real ticket creation must select exact PK despite a number collision');
  check($db->getVar('SELECT cct_author_id FROM wp_jet_cct_historial_del_inmueble WHERE id_ticket=?',[$result['ticket_id']])==='EMP-900','Property audit must record creator, not assignee');
  check($db->getVar('SELECT cct_author_id FROM wp_jet_cct_historial_del_ticket WHERE id_ticket=?',[$result['ticket_id']])==='EMP-900','Ticket audit must record creator');
  check($db->getVar('SELECT id_empleado FROM wp_jet_cct_tickets WHERE _ID=?',[$result['ticket_id']])==='EMP-13','Ticket responsibility must remain the selected employee');
  $letterProbe = new RealTicketCreationProbe($db);
  $letterInput = ['contract_pk'=>'801','id_empleado'=>'EMP-13','tema_ayuda'=>'Recibo de inmuebles','departamento'=>'Servicio al arrendatario','prioridad'=>'Prioridad urgente','asunto'=>'Aviso previo','descripcion'=>'Prueba sin envío','solicitante_tipo'=>'arrendatario','internal_notification_action'=>'contrato_recibo_automatico','require_receipt_letter'=>true];
  $before = (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets');
  $pdo->beginTransaction(); $failed = $letterProbe->createAdministrativeTicket($letterInput,[],[],['empleado','solicitante','admin']); $pdo->rollBack();
  check($failed['ok']==='0' && (int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets')===$before,'Missing letter must prevent automatic ticket');
  $letterProbe->letterAvailable = true;
  \SharedNotifications\NotificationQueue::$failEnqueue=true;
  $pdo->beginTransaction();
  try { $letterProbe->createAdministrativeTicket($letterInput,[],[],['empleado','solicitante','admin']); throw new RuntimeException('Failed queue silently accepted'); }
  catch (RuntimeException $exception) { check(str_contains($exception->getMessage(),'encolar'),'Queue failure must propagate to business rollback'); }
  finally { $pdo->rollBack(); \SharedNotifications\NotificationQueue::$failEnqueue=false; }
  check((int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets')===$before,'Failed delivery enqueue must roll back ticket');
  $pdo->beginTransaction(); $letterResult=$letterProbe->createAdministrativeTicket($letterInput,[],[],['empleado','solicitante','admin']); $pdo->commit();
  check($letterResult['ok']==='1' && (int)$letterResult['emails_queued']===2,'Tenant and configured internal employee must receive the letter, deduplicating recipient');
  $letterJob=json_decode($db->getVar("SELECT payload FROM test_jobs WHERE payload LIKE '%signed-letter.pdf%' LIMIT 1"),true);
  check(($letterJob['meta']['documents'][0]['url'] ?? '')==='https://example.invalid/signed-letter.pdf','Queued letter must retain signed PDF link');
  check(\SharedNotifications\NotificationQueue::$constructions===1,'All transactions must reuse the prewarmed queue');
  $probe->writable=false;
  check(!callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_import_apply())->ok,'Read-only users cannot import');
  $service->audit(802,'retention_created','EMP-900',['ticket_id'=>44]);
  $entry=$service->history(802)[0];
  $contractBefore=$probe->pk('802');
  $service->manageHistory(802,(int)$entry['id'],'edit','Nota de prueba corregida','Corregir prueba',$entry['revision'],'EMP-13');
  $edited=$service->history(802)[0];
  check(json_decode($edited['details_json'],true)['manual_text']==='Nota de prueba corregida','History editor must change visible description');
  check($edited['actor']==='EMP-900','History corrections must preserve original author');
  try { $service->manageHistory(802,(int)$entry['id'],'edit','Overwritten','Stale test',$entry['revision'],'EMP-13'); throw new RuntimeException('Stale history accepted'); } catch (RuntimeException $expected) { check(str_contains($expected->getMessage(),'cambió'),'Stale history correction must be rejected'); }
  try { $service->manageHistory(193,(int)$entry['id'],'void','','Wrong contract',$edited['revision'],'EMP-13'); throw new RuntimeException('Cross-contract edit accepted'); } catch (RuntimeException $expected) { check(str_contains($expected->getMessage(),'modificar'),'Cross-contract edit rejected'); }
  $service->manageHistory(802,(int)$edited['id'],'void','','Anular prueba',$edited['revision'],'EMP-13');
  check(count($service->history(802))===0 && count($service->history(802,true))===3,'Voided entries hidden normally; all correction evidence retained');
  check($probe->pk('802')===$contractBefore,'History editing and voiding must not alter contract state');
  $probe->writable=false; $_POST=['contract_pk'=>802,'entry_id'=>$entry['id'],'operation'=>'void','confirm'=>'1'];
  check(!callEndpoint(fn()=>$probe->ajax_handler_contracts_ending_history_manage())->ok,'History management requires server permission');
  $rawSettings=$db->getVar('SELECT valor FROM wp_jet_cct_confi_sistema WHERE _ID=1');
  $appSettings=new \SCM\Core\Settings($db,true);
  (new ReflectionProperty(\SCM\Core\App::class,'settings'))->setValue(null,$appSettings);
  $app=new \SCM\App\SuCasaControlServiciosInmobiliarios($db);
  $permission=new ReflectionMethod($app,'canUseDashboardAction');
  $_SESSION['scm_user_cargo']='11';check($permission->invoke($app,'contract_history_manage'),'Administrative cargo gets history management initially');
  $_SESSION['scm_user_cargo']='13';check(!$permission->invoke($app,'contract_history_manage'),'Other cargos must not inherit history management');
  $configured=json_decode($rawSettings,true);$configured['contract_history_permissions_configured']=true;$configured['dashboard_action_permissions']=['11'=>[],'13'=>['contract_history_manage']];
  $db->update('wp_jet_cct_confi_sistema',['valor'=>json_encode($configured)],['_ID'=>1]);$appSettings->refresh();
  check($permission->invoke($app,'contract_history_manage'),'Explicit configurable history permission respected');
  $_SESSION['scm_user_cargo']='11';check(!$permission->invoke($app,'contract_history_manage'),'Removing history permission must revoke it');
  $db->update('wp_jet_cct_confi_sistema',['valor'=>$rawSettings],['_ID'=>1]);$appSettings->refresh();
  unset($_SESSION['scm_user_cargo']);
  // Changes outside the Excel flow must cancel obsolete notices before delivery.
  $pdo->beginTransaction();$service->save($probe->pk('802'),$end,null,true,'7,0','Permanencia de prueba','EMP-900');$pdo->commit();
  $scheduledJobs=json_decode($service->get(802,$end)['jobs_json'],true);
  $service->reconcileCycles(802);
  check($service->get(802,$end)['no_exit']==1,'Unchanged active contract keeps its no-exit report');
  $db->update('wp_jet_cct_contratos_arrendamiento',['fin_contrato'=>(string)($end+86400)],['_ID'=>802]);
  $service->reconcileCycles(802);
  foreach($scheduledJobs as $key) check($db->getVar('SELECT status FROM test_jobs WHERE dedupe_key=?',[$key])==='cancelled','External date change must cancel old-cycle notices');
  check($service->get(802,$end)['no_exit']==0,'External date change retires the old report');
  $pdo->beginTransaction();$service->save($probe->pk('802'),$end+86400,null,true,'7,0','Nueva permanencia de prueba','EMP-900');$pdo->commit();
  $scheduledJobs=json_decode($service->get(802,$end+86400)['jobs_json'],true);
  $db->update('wp_jet_cct_contratos_arrendamiento',['estado'=>'Recibido'],['_ID'=>802]);
  $service->reconcileCycles(802);
  foreach($scheduledJobs as $key) check($db->getVar('SELECT status FROM test_jobs WHERE dedupe_key=?',[$key])==='cancelled','Received contract must cancel pending no-exit notices');
  // Requests inherit fin_contrato but their _ID still belongs to the case.
  foreach ([803=>'903',804=>'904',805=>'905'] as $id=>$code) $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>$id,'contrato'=>$code,'inmueble'=>(string)$id,'fin_contrato'=>(string)$end,'estado'=>'Entregado']);
  foreach ([['terminación de contrato',803,'903',91001],['no prórroga',804,'904',800]] as [$source,$contractPk,$code,$casePk]) {
    $request = ['_ID'=>$casePk,'id_ticket'=>(string)$casePk,'id_contrato'=>(string)$contractPk,'contrato'=>$code,'inmueble'=>(string)$contractPk,'fin_contrato'=>(string)$end];
    check((int)($probe->requestContract($request)['_ID'] ?? 0)===$contractPk, $source . ': inherited end date must not turn case PK into contract PK');
    $result = $probe->createRequestRetention($request,$source);
    check($result['ok']==='1', $source . ': retention must create successfully for request with end date: ' . json_encode($result));
    check((string)$result['input']['contract_pk']===(string)$contractPk, $source . ': retention must belong to referenced contract despite missing/colliding case PK');
    check(str_contains($result['input']['descripcion'],'Caso #'.$casePk), $source . ': preserve original case in commercial handoff');
    $repeated = $probe->createRequestRetention($request,$source);
    check($repeated['ok']==='0' && str_contains($repeated['message'],'ya tiene caso de retención'), $source . ': still block duplicate retention for actual contract');
  }
  check($probe->requestContract(['_ID'=>805,'fin_contrato'=>(string)$end])===[], 'An unrelated contract sharing the case PK must not be selected without contract/property references');
  $result = $probe->createRetention($probe->pk('805'));
  check($result['ok']==='1' && $result['input']['contract_pk']==='805', 'Contracts ending flow must continue to use its explicitly selected contract PK');
  // Completing an in-term response and changing probability form one business operation.
  foreach ([806,807,808,809,810,811,812] as $id) $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID'=>$id,'contrato'=>(string)($id+100),'inmueble'=>(string)$id,'fin_contrato'=>(string)$end,'estado'=>'Entregado']);
  $requestFor = static function (int $id) use ($db,$end): array {
    $pk=95000+$id*10;
    $db->insert('wp_jet_cct_tickets', ['_ID'=>$pk,'id_ticket'=>(string)$pk,'id_contrato'=>(string)$id,'estado'=>'Nuevo','estado_administrativo'=>'Nuevo']);
    return ['_ID'=>$pk,'id_ticket'=>(string)$pk,'id_contrato'=>(string)$id,'contrato'=>(string)($id+100),'inmueble'=>(string)$id,'fin_contrato'=>(string)$end];
  };
  $pdo->beginTransaction(); $service->save($probe->pk('806'),$end,80,true,'7,0','Conservar reporte de no salida','EMP-13','EMP-13'); $pdo->commit();
  $oldState=$service->get(806,$end); $jobsBefore=(int)$db->getVar('SELECT COUNT(*) FROM test_jobs');
  $request=$requestFor(806);
  $result=$probe->respondRequest($request,'dentro','terminación de contrato');
  $state=$service->get(806,$end);
  check($result['response']['renewal_probability_updated'] && (float)$state['probability']===0.0, 'Within-term termination response sets current-cycle probability to zero without retention');
  foreach (['no_exit','reminder_days','note','jobs_json','receipt_employee_id'] as $field) check($state[$field]===$oldState[$field], 'Automatic probability preserves existing '.$field);
  check((int)$db->getVar('SELECT COUNT(*) FROM test_jobs')===$jobsBefore, 'Automatic probability must not create or requeue no-exit notices');
  check($state['updated_by']==='EMP-900' && (int)$state['revision']===(int)$oldState['revision']+1, 'Automatic probability records actual employee and invalidates stale edits');
  $event=$service->history(806)[0]; $detail=json_decode($event['details_json'],true);
  check($event['action']==='renewal_saved' && $event['actor']==='EMP-900' && $detail['case_number']===$request['id_ticket'] && str_contains($detail['reason'],'terminación de contrato'), 'Contract history links automatic zero to source case and response type');
  check($db->getVar('SELECT estado FROM wp_jet_cct_tickets WHERE _ID=?',[$request['_ID']])==='Cerrado', 'Successful response closes the actual case');
  $pdo->beginTransaction(); $service->save($probe->pk('807'),$end,100,false,'30,7,0','Renovación anterior','EMP-13'); $pdo->commit();
  $request=$requestFor(807);
  $result=$probe->respondRequest($request,'dentro','no prórroga',true);
  check((float)$service->get(807,$end)['probability']===0.0 && $result['retention']['ok']==='1', 'Within-term non-renewal replaces 100 percent and still allows optional retention');
  check($result['retention']['input']['contract_pk']==='807', 'Optional retention targets the same locked contract as probability update');
  $pdo->beginTransaction(); $service->save($probe->pk('808'),$end,75,false,'30,7,0','Outside-term prior state','EMP-13'); $pdo->commit();
  $oldState=$service->get(808,$end); $historyBefore=count($service->history(808));
  $result=$probe->respondRequest($requestFor(808),'fuera','no prórroga');
  check(!$result['response']['renewal_probability_updated'] && $service->get(808,$end)===$oldState && count($service->history(808))===$historyBefore, 'Outside-term response leaves probability and contract history unchanged');
  $pdo->beginTransaction(); $service->save($probe->pk('809'),$end,65,false,'30,7,0','Preserve on failure','EMP-13'); $pdo->commit();
  $request=$requestFor(809); $oldState=$service->get(809,$end); $historyBefore=count($service->history(809));
  $ticketsBefore=(int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets'); $caseHistoryBefore=(int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket');
  $probe->failResponse=true;
  try { $probe->respondRequest($request,'dentro','terminación de contrato',true); throw new RuntimeException('Failed response accepted'); }
  catch (RuntimeException $e) { check($e->getMessage()==='Synthetic response failure','Failed response must propagate original error'); }
  $probe->failResponse=false;
  check($service->get(809,$end)===$oldState && count($service->history(809))===$historyBefore, 'Failed response rolls back automatic probability and contract audit');
  check((int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets')===$ticketsBefore && (int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket')===$caseHistoryBefore, 'Failed response rolls back optional retention and partially written case history');
  check($db->getVar('SELECT estado FROM wp_jet_cct_tickets WHERE _ID=?',[$request['_ID']])==='Nuevo', 'Failed response leaves original case pending');
  $result=$probe->respondRequest($requestFor(810),'dentro','no prórroga');
  check((float)$service->get(810,$end)['probability']===0.0 && $result['retention']===[], 'Within-term response creates missing renewal tracking with zero percent');
  $request=$requestFor(811); $db->update('wp_jet_cct_contratos_arrendamiento',['fin_contrato'=>'invalid'],['_ID'=>811]);
  try { $probe->respondRequest($request,'dentro','terminación de contrato'); throw new RuntimeException('Invalid end date accepted'); }
  catch (RuntimeException $e) { check(str_contains($e->getMessage(),'fecha fin válida'),'Invalid contract end date must prevent silent partial completion'); }
  check($service->get(811,$end)===[] && $db->getVar('SELECT estado FROM wp_jet_cct_tickets WHERE _ID=?',[$request['_ID']])==='Nuevo', 'Invalid contract date never writes probability or closes case');
  $pdo->beginTransaction(); $service->save($probe->pk('812'),$end-86400,50,true,'7,0','Previous cycle only','EMP-13'); $pdo->commit();
  $stale=$service->get(812,$end-86400);
  $probe->respondRequest($requestFor(812),'dentro','no prórroga');
  check((float)$service->get(812,$end)['probability']===0.0 && (int)$service->get(812,$end)['no_exit']===0, 'New date cycle initializes zero without carrying old no-exit report');
  foreach (json_decode($stale['jobs_json'],true) as $key) check($db->getVar('SELECT status FROM test_jobs WHERE dedupe_key=?',[$key])==='cancelled', 'New date cycle retires stale scheduled jobs');
  echo "PASS: exact contract, request-to-contract resolution, retention in termination/no renewal, dates, matching, signatures, probabilities, responsibility, reminder scheduling/cancellation, history, atomic imports and idempotent automatic receipts. No messages sent.\n";
} finally {
  putenv('SHARED_NOTIFICATIONS_PATH'); unlink($temp.'/autoload.php');unlink($temp.'/config.php');rmdir($temp);
}
}
