<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn()=>time());
$pdo->sqliteCreateFunction('FIND_IN_SET', static fn($needle,$list)=>in_array((string)$needle,explode(',',(string)$list),true)?1:0);
$tables = [
 'wp_jet_cct_tickets'=>['_ID','id_ticket','departamento','tema_ayuda','estado','estado_administrativo','fecha','fecha_actualizacion','id_cotizacion_mantenimiento','id_empleado'],
 'wp_jet_cct_cotizacion_mantenimiento'=>['_ID','id_ticket','financiacion','estado','cct_modified'],
 'wp_jet_cct_historial_del_ticket'=>['_ID','id_ticket','respuesta','cct_author_id','id_empleado','id_cotizacion_mantenimiento'],
];
foreach($tables as $table=>$columns) $pdo->exec('CREATE TABLE '.$table.' (_ID INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(fn($c)=>$c.' TEXT',array_slice($columns,1))).')');
$db = new \SCM\Core\Database($pdo);
$schema = new \SCM\Support\SchemaInspector($db);
(new ReflectionProperty($schema,'tableExistsCache'))->setValue($schema,array_fill_keys(array_keys($tables),true));
(new ReflectionProperty($schema,'tableColumnsCache'))->setValue($schema,$tables);
$insert=$pdo->prepare('INSERT INTO wp_jet_cct_tickets (_ID,id_ticket,departamento,tema_ayuda,estado,estado_administrativo,fecha,fecha_actualizacion,id_empleado) VALUES (?,?,?,?,?,?,?,?,?)');
foreach([
 [1,'Mantenimiento','Reparaciones necesarias','En proceso','Por inspeccionar'],
 [2,'Mantenimiento','Reparaciones necesarias','En proceso','En ejecucion por inmobiliaria'],
 [3,'Mantenimiento','Reparaciones necesarias','En proceso','Postergado'],
 [4,'Mantenimiento','Reparaciones necesarias','Cerrado','Finalizado'],
 [5,'Contable','Contabilidad','En proceso','Por contabilizar'],
] as [$id,$dept,$topic,$state,$admin]) $insert->execute([$id,(string)(100+$id),$dept,$topic,$state,$admin,1000,1000,'99']);
// More than a page: a state found only on another page must remain available.
for($i=6;$i<=20;$i++) $insert->execute([$i,(string)(100+$i),'Mantenimiento','Reparaciones necesarias','En proceso','Por inspeccionar',1000,1000,'99']);
$pdo->exec("INSERT INTO wp_jet_cct_cotizacion_mantenimiento (_ID,id_ticket,financiacion,estado) VALUES (10,'1','Si','Aprobada'),(11,'1','No','Aprobada'),(12,'2','Si','Aprobada'),(13,'3','','Aprobada')");
$maintenance=new class($db,$schema) {
 use \SCM\Repositories\Concerns\MaintenanceQueriesConcern;
 public const MAINTENANCE_TOPICS=['reparaciones necesarias'];
 private int $lastMaintenanceTotal=0,$lastMaintenancePage=1,$lastMaintenancePerPage=10,$lastMaintenanceTotalPages=1;
 public function __construct(private \SCM\Core\Database $db,private \SCM\Support\SchemaInspector $schema){}
 private function convertSql($s){return preg_replace('/%[sd]/','?',$s);}
 private function enrichRowsWithRelatedTables($rows){return $rows;}
 private function buildLikeCondition(&$where,&$args,$column,$value){if($value!==''){$where[]=$column.' LIKE ?';$args[]='%'.$value.'%';}}
};
$filters=(new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosFilters())->parse([]);
$assert=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";};
$maintenance->queryMaintenance($filters);
$assert($maintenance->getLastMaintenanceAdminStatuses()===['En ejecucion por inmobiliaria','Por inspeccionar'],'maintenance options scoped to matching open cases across pages');
$rows=$maintenance->queryMaintenance(array_replace($filters,['fFinanciacion'=>'Si']));
$assert(array_column($rows,'_ID')===[2],'financing uses latest quotation instead of old affirmative response');
$maintenance->queryMaintenance(array_replace($filters,['_scmStatusBucket'=>'postergados','fFinanciacion'=>'pending']));
$assert($maintenance->getLastMaintenanceAdminStatuses()===['Postergado'],'postponed financing and administrative options are scoped');
$maintenance->queryMaintenance(array_replace($filters,['_scmStatusBucket'=>'cerrados']));
$assert($maintenance->getLastMaintenanceAdminStatuses()===['Finalizado'],'closed options exclude open states');
$generic=new class($db,$tables) {
 use \SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
 public function __construct(private \SCM\Core\Database $db,private array $tables){}
 private function table_exists($t){return isset($this->tables[$t]);}
 private function column_exists($t,$c){return in_array($c,$this->tables[$t]??[],true);}
 private function detect_first_existing_column($t,$columns){foreach($columns as $c)if($this->column_exists($t,$c))return $c;return '';}
 private function generic_topic_where_sql($topics,&$args){$args=array_merge($args,$topics);return 'tema_ayuda IN ('.implode(',',array_fill(0,count($topics),'?')).')';}
};
$params=$generic->parse_params_generic([],'test_');
$result=$generic->run_query_generic(['Contabilidad'],$params,[],false);
$assert($result['admin_status_options']===['Por contabilizar'],'generic tab excludes maintenance states');
$result=$generic->run_query_generic([],array_replace($params,['_scmStatusBucket'=>'postergados','fEstadoAdmin'=>'Postergado','fFinanciacion'=>'pending']),[],false);
$assert($result['stats']['total']===1 && $result['admin_status_options']===['Postergado'],'generic scoped filtering includes postponed cases');
$service=new \SCM\Support\QuotationFinancingService($db,$schema);
$service->update(12,'No',['employee_id'=>'99','name'=>'Test']);
$quote=$db->getRow('SELECT * FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID=12');
$assert($quote['financiacion']==='No'&&$quote['estado']==='Aprobada','financing update preserves quote approval');
$history=$db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket');
$assert($history['cct_author_id']==='99'&&str_contains($history['respuesta'],'Si → No'),'financing history records real employee and old/new values');
$service->update(12,'No',['employee_id'=>'99','name'=>'Test']);
$assert((int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket')===1,'unchanged financing does not duplicate history');
try{$service->update(12,'otro',['employee_id'=>'99']);throw new RuntimeException('Invalid financing accepted');}catch(\DomainException){}
$assert($db->getVar('SELECT financiacion FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID=12')==='No','invalid financing cannot overwrite quote');
$denied = new class {
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  public bool $csrfChecked = false;
  private function verifyCsrf(): void { $this->csrfChecked = true; }
  private function canUseDashboardAction(string $action): bool { return false; }
  private function canAccessDashboardTab(string $tab): bool { return true; }
  private function jsonFail(string $message): never { throw new \RuntimeException($message); }
};
try { $denied->ajax_handler_cotizacion_financing(); throw new \LogicException('Unauthorized request accepted'); }
catch (\RuntimeException $error) { $assert($denied->csrfChecked && str_contains($error->getMessage(),'permiso'), 'endpoint checks CSRF and quote edit permission before updating'); }
