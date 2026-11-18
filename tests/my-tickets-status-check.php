<?php
declare(strict_types=1);
// In-memory fixtures exercise the real AJAX handler and status query without production writes.
require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/src/Core/Helpers.php';

final class MyTicketsResult extends RuntimeException {
  public function __construct(public bool $ok, public array $data) { parent::__construct(); }
}
final class MyTicketsProbe {
  use SCM\App\Concerns\HandlesMaintenanceActions;
  use SCM\App\Concerns\RendersDashboard;
  use SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
  public const DEFAULT_TICKET_URL='https://example.invalid/case';
  public const DEFAULT_PREVENTIVA_URL='https://example.invalid/preventive';
  public const DEFAULT_COTIZACION_URL='https://example.invalid/quote';
  public const DEFAULT_ACTA_URL='https://example.invalid/act';
  public bool $allowed=true;
  public string $employee='employee-a';
  public function __construct(public SCM\Core\Database $db) {}
  private function verifyCsrf():void {}
  private function canAccessDashboardTab($tab):bool { return $this->allowed; }
  private function current_employee_id():string { return $this->employee; }
  private static function defaultCorrectiveReviewUrl():string { return 'https://example.invalid/review'; }
  private static function defaultPreventiveReviewUrl():string { return 'https://example.invalid/preventive-review'; }
  private function get_generic_tab_definitions():array { return \SCM\App\SuCasaControlServiciosInmobiliarios::defaultGenericTicketTabDefinitions(); }
  private static function sanitizeUrl($value):string { return (string)$value; }
  private function jsonOk($data):never { throw new MyTicketsResult(true,$data); }
  private function jsonFail($message):never { throw new MyTicketsResult(false,['message'=>$message]); }
  public function table_exists($table):bool { return (bool)$this->db->getVar("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",[$table]); }
  public function column_exists($table,$column):bool { return in_array($column,array_column($this->db->getResults('PRAGMA table_info(`'.$table.'`)'),'name'),true); }
  public function detect_first_existing_column($table,$candidates):string { foreach($candidates as $column)if($this->column_exists($table,$column))return $column;return ''; }
  private function enrich_ticket_rows_with_related_data($rows):array { return $rows; }
  private function attach_historial_rows($rows):array { return $rows; }
  private function enrich_generic_case_details($rows):array { return $rows; }
  private function render_generic_cards($rows,$config,$tab,$bucket=''):string { return json_encode(['ids'=>array_column($rows,'_ID'),'bucket'=>$bucket]); }
  private function get_generic_tickets_module():object { return new class { public function render_generic_pagination($tab,$pagination):string { return json_encode($pagination); } }; }
  private function render_generic_filter_form(...$args):string { return (new SCM\Views\GenericTicketsUiView(...array_fill(0,5,static fn()=>'')))->renderGenericFilterForm(...$args); }
  public function html():string { return $this->render_my_tickets_panel(['tbody'=>'<p>Casos abiertos del funcionario</p>'],['total'=>15],['_scmStatusBucket'=>'active'],[]); }
}
// SQLite no entiende CONVERT(... USING utf8mb4) COLLATE de MySQL: se reduce a la expresión interna.
final class MysqlishPdo extends PDO {
  private static function sql(string $sql):string { return (string)preg_replace('/CONVERT\((.+?) USING utf8mb4\)(?: COLLATE utf8mb4_unicode_ci)?/','$1',$sql); }
  public function prepare(string $query, array $options = []):PDOStatement|false { return parent::prepare(self::sql($query),$options); }
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs):PDOStatement|false { return parent::query(self::sql($query)); }
}
$pdo=new MysqlishPdo('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UNIX_TIMESTAMP',static fn()=>time());
$pdo->exec('CREATE TABLE wp_jet_cct_tickets(_ID INTEGER PRIMARY KEY,id_ticket TEXT,id_empleado TEXT,departamento TEXT,estado TEXT,estado_administrativo TEXT,fecha INTEGER,fecha_actualizacion INTEGER,magnitud_caso TEXT,tema_ayuda TEXT)');
// Tabla de cotizaciones vacía: sin ella el filtro de cotización vigente descarta todos los casos.
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY,id_ticket TEXT)');
$db=new SCM\Core\Database($pdo);$probe=new MyTicketsProbe($db);
foreach(range(1,15) as $id)$db->insert('wp_jet_cct_tickets',['_ID'=>$id,'id_ticket'=>(string)$id,'id_empleado'=>'employee-a','departamento'=>'Mantenimiento','estado'=>$id%2?'Nuevo':'En proceso','estado_administrativo'=>'Nuevo','fecha'=>1000+$id,'fecha_actualizacion'=>2000,'magnitud_caso'=>'alto']);
foreach([[21,'En proceso','Postergado'],[22,'Nuevo','En espera de respuesta'],[31,'Cerrado','Nuevo'],[32,'En proceso','Finalizado'],[33,'Resuelto','Nuevo']] as [$id,$state,$admin])$db->insert('wp_jet_cct_tickets',['_ID'=>$id,'id_ticket'=>(string)$id,'id_empleado'=>'employee-a','departamento'=>'Mantenimiento','estado'=>$state,'estado_administrativo'=>$admin,'fecha'=>1000+$id,'fecha_actualizacion'=>2000]);
foreach([[41,'Nuevo','Nuevo'],[42,'En proceso','Postergado'],[43,'Cerrado','Finalizado']] as [$id,$state,$admin])foreach(['employee-b','employee-a'] as $offset=>$employee)$db->insert('wp_jet_cct_tickets',['_ID'=>$id+$offset*10,'id_ticket'=>(string)($id+$offset*10),'id_empleado'=>$employee,'departamento'=>$offset?'Servicio al cliente':'Mantenimiento','estado'=>$state,'estado_administrativo'=>$admin,'fecha'=>1000+$id,'fecha_actualizacion'=>2000]);
// Un caso de Preventiva asignado al mismo funcionario no debe aparecer en la pestaña Mantenimiento.
$db->insert('wp_jet_cct_tickets',['_ID'=>61,'id_ticket'=>'61','id_empleado'=>'employee-a','departamento'=>'Servicio al propietario','tema_ayuda'=>'Revision preventiva','estado'=>'Nuevo','estado_administrativo'=>'Nuevo','fecha'=>1051,'fecha_actualizacion'=>2000]);
if(($argv[1]??'')==='--html'){echo $probe->html();exit;}
$call=static function(array $post)use($probe):MyTicketsResult{$_POST=$post;try{$probe->ajax_handler_my_tickets();throw new RuntimeException('No response');}catch(MyTicketsResult $result){return $result;}};
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
foreach(['active'=>15,'postergados'=>2,'cerrados'=>3] as $bucket=>$count){
  $result=$call(['scm_my_bucket'=>$bucket,'scm_my_empleado'=>'employee-b','scm_my_estado'=>'Cerrado']);
  $cards=json_decode($result->data['cards'],true);$pagination=json_decode($result->data['pagination'],true);
  $check($result->ok&&(int)$result->data['count']===$count&&$cards['bucket']===$bucket&&$pagination['total']===$count,'Status '.$bucket.' filters rows, counts and pagination while enforcing owner and excluded department');
  foreach($cards['ids'] as $id)$check(($bucket==='active'&&$id<=15)||($bucket==='postergados'&&in_array($id,[21,22],true))||($bucket==='cerrados'&&in_array($id,[31,32,33],true)),'Returned case belongs to selected status');
}
foreach([[],['scm_my_bucket'=>'all'],['scm_my_bucket'=>['cerrados']]] as $post){$result=$call($post);$check($result->data['status_bucket']==='active'&&(int)$result->data['count']===15,'Missing or invalid status defaults to open cases');}
$page=$call(['scm_my_bucket'=>'active','scm_my_page'=>2]);$check(count(json_decode($page->data['cards'],true)['ids'])===5&&json_decode($page->data['pagination'],true)['page']===2,'Open case pagination stays in its status');
$probe->employee='';$check((int)$call(['scm_my_bucket'=>'cerrados'])->data['count']===0,'Missing employee never exposes another employee cases');
$probe->allowed=false;$check(!$call([])->ok,'Personal tab permissions remain enforced');
$probe->allowed=true;$probe->employee='employee-a';
$maintenance=$call(['scm_my_categoria'=>'mantenimiento']);$check($maintenance->data['category']==='mantenimiento'&&!in_array(61,json_decode($maintenance->data['cards'],true)['ids'],true),'Maintenance tab excludes cases from other categories');
$check($call(['scm_my_categoria'=>'desconocida'])->data['category']==='mantenimiento','Unknown category falls back to Maintenance');
$check(str_contains($maintenance->data['kpis_html'],'scm-mis_tickets-kpi-magnitud-critico')&&str_contains($maintenance->data['kpis_html'],'Magnitud del caso'),'Maintenance tab returns its KPI rows');
$check(!str_contains($call(['scm_my_categoria'=>'desconocida','scm_my_tema'=>'Revision preventiva'])->data['cards'],'61'),'Old topic filter links are ignored');
$probe->allowed=true;
// Solo se ofrecen las categorías con casos del funcionario (sin Servicio al cliente ni casos ajenos).
$check($call([])->data['available_categories']===['mantenimiento','preventiva'],'Only categories with assigned cases are offered');
$counts=$probe->my_tickets_category_counts(['mantenimiento','preventiva','entrega'],'employee-a','Servicio al cliente');
$check($counts===['mantenimiento'=>20,'preventiva'=>1,'entrega'=>0],'Category counts add open, postponed and closed cases');
$probe->employee='employee-c';
$db->insert('wp_jet_cct_tickets',['_ID'=>71,'id_ticket'=>'71','id_empleado'=>'employee-c','departamento'=>'Servicio al propietario','tema_ayuda'=>'Revision preventiva','estado'=>'Cerrado','estado_administrativo'=>'Finalizado','fecha'=>1071,'fecha_actualizacion'=>2000]);
$db->insert('wp_jet_cct_tickets',['_ID'=>72,'id_ticket'=>'72','id_empleado'=>'employee-c','departamento'=>'Mantenimiento','estado'=>'Nuevo','estado_administrativo'=>'Desistido','fecha'=>1072,'fecha_actualizacion'=>2000]);
$onlyPreventive=$call(['scm_my_categoria'=>'mantenimiento']);
$check($onlyPreventive->data['available_categories']===['preventiva']&&$onlyPreventive->data['category']==='preventiva','Requested category without cases falls back to the first one with cases (withdrawn open cases do not count)');
$probe->employee='';$check($call([])->data['available_categories']===[],'Missing employee offers no categories');
$probe->employee='employee-a';
$html=$probe->html();
$check(str_contains($html,'data-my-tickets-category-note')&&str_contains($html,'Solo aparecen las categorías en las que tienes casos asignados.'),'Panel explains that only categories with cases appear');
$check((int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_tickets')===29,'Filtering never changes case records');
echo "OK: in-memory status, scope and pagination checks. No production access or messages.\n";
