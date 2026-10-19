<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn()=>time());
$pdo->sqliteCreateFunction('FIND_IN_SET', static fn($needle,$list)=>in_array((string)$needle,explode(',',(string)$list),true)?1:0);
$tables = [
 'wp_jet_cct_tickets'=>['_ID','id_ticket','departamento','tema_ayuda','estado','estado_administrativo','fecha','fecha_actualizacion','id_cotizacion_mantenimiento','id_empleado','ejecutado'],
 'wp_jet_cct_cotizacion_mantenimiento'=>['_ID','id_ticket','ejecutado','estado'],
];
foreach($tables as $table=>$columns) $pdo->exec('CREATE TABLE '.$table.' (_ID INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(fn($c)=>$c.' TEXT',array_slice($columns,1))).')');
$db = new \SCM\Core\Database($pdo);
$schema = new \SCM\Support\SchemaInspector($db);
(new ReflectionProperty($schema,'tableExistsCache'))->setValue($schema,array_fill_keys(array_keys($tables),true));
(new ReflectionProperty($schema,'tableColumnsCache'))->setValue($schema,$tables);
$insert=$pdo->prepare("INSERT INTO wp_jet_cct_tickets (_ID,id_ticket,departamento,tema_ayuda,estado,estado_administrativo,fecha,fecha_actualizacion,id_empleado,ejecutado) VALUES (?,?,'Mantenimiento','Reparaciones necesarias','En proceso','Cotizado',1000,1000,'99',?)");
foreach([
 [1,'Propietario'],   // valor del caso
 [2,''],              // sin valor en el caso: usa la última cotización (Arrendatario)
 [3,'Inmobiliaria'],  // el caso manda sobre la cotización (Propietario)
 [4,'Copropiedad'],
 [5,null],            // sin caso ni cotización
 [6,'Propietario'],
] as [$id,$executor]) $insert->execute([$id,(string)(100+$id),$executor]);
$pdo->exec("INSERT INTO wp_jet_cct_cotizacion_mantenimiento (_ID,id_ticket,ejecutado,estado) VALUES (10,'2','Propietario','Desaprobada'),(11,'2','Arrendatario',''),(12,'3','Propietario','Aprobada'),(13,'5','','')");
$maintenance=new class($db,$schema) {
 use \SCM\Repositories\Concerns\MaintenanceQueriesConcern, \SCM\Repositories\Concerns\MaintenanceStatisticsConcern;
 public const MAINTENANCE_TOPICS=['reparaciones necesarias'];
 private int $lastMaintenanceTotal=0,$lastMaintenancePage=1,$lastMaintenancePerPage=10,$lastMaintenanceTotalPages=1;
 public function __construct(private \SCM\Core\Database $db,private \SCM\Support\SchemaInspector $schema){}
 private function convertSql($s){return preg_replace('/%[sd]/','?',$s);}
 private function enrichRowsWithRelatedTables($rows){return $rows;}
 private function buildLikeCondition(&$where,&$args,$column,$value){if($value!==''){$where[]=$column.' LIKE ?';$args[]='%'.$value.'%';}}
 public function executorCounts(array $filters): array { [$where,$args]=$this->buildMaintenanceWhereParts($filters); return $this->aggregateMaintenanceExecutorCounts($where,$args); }
};
$assert=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";};
$filters=(new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosFilters())->parse([]);
$counts=$maintenance->executorCounts($filters);
$assert($counts===['ejecuta_inmobiliaria'=>1,'ejecuta_propietario'=>2,'ejecuta_arrendatario'=>1,'ejecuta_copropiedad'=>1,'ejecuta_externo'=>0,'ejecuta_sin_definir'=>1],'executor KPIs prefer the case value and fall back to the latest quote');
$parsed=(new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosFilters())->parse(['scm_ejecutado'=>'arrendatario']);
$assert($parsed['fEjecutado']==='arrendatario','parser reads the executor filter');
$ids=static fn(array $rows)=>array_map('intval',array_column($rows,'_ID'));
$rows=$maintenance->queryMaintenance($parsed);
$assert($ids($rows)===[2],'executor filter matches cases resolved from the latest quote');
$rows=$maintenance->queryMaintenance(array_replace($filters,['fEjecutado'=>'propietario']));
$propietarioIds=$ids($rows); sort($propietarioIds);
$assert($propietarioIds===[1,6],'case executor overrides an older quote executor');
$rows=$maintenance->queryMaintenance(array_replace($filters,['fEjecutado'=>'sin_definir']));
$assert($ids($rows)===[5],'cases without executor are reachable from Sin definir');
$rows=$maintenance->queryMaintenance(array_replace($filters,['fEjecutado'=>'cualquiera']));
$assert(count($rows)===6,'unknown executor filter values are ignored');
$generic=new class($db,$tables) {
 use \SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
 public function __construct(private \SCM\Core\Database $db,private array $tables){}
 private function table_exists($t){return isset($this->tables[$t]);}
 private function column_exists($t,$c){return in_array($c,$this->tables[$t]??[],true);}
 private function detect_first_existing_column($t,$columns){foreach($columns as $c)if($this->column_exists($t,$c))return $c;return '';}
 private function generic_topic_where_sql($topics,&$args){$args=array_merge($args,$topics);return 'tema_ayuda IN ('.implode(',',array_fill(0,count($topics),'?')).')';}
};
$params=$generic->parse_params_generic([],'scmpv_');
$result=$generic->run_query_generic(['Reparaciones necesarias'],$params,[],false);
$assert(($result['stats']['ejecuta_propietario']??null)===2 && $result['stats']['ejecuta_arrendatario']===1 && $result['stats']['ejecuta_sin_definir']===1,'generic quote tabs count executors with the same rule');
$result=$generic->run_query_generic(['Reparaciones necesarias'],$generic->parse_params_generic(['scmpv_ejecutado'=>'arrendatario'],'scmpv_'),[],false);
$assert($result['stats']['total']===1,'generic executor filter narrows the tab');
$result=$generic->run_query_generic(['Reparaciones necesarias'],$generic->parse_params_generic([],'scmco_'),[],false);
$assert(!isset($result['stats']['ejecuta_propietario']),'tabs without quotes skip executor counts');
$html=\SCM\Support\CaseFilterFields::executor('scmpv_',['fEjecutado'=>'copropiedad']);
$assert(str_contains($html,'name="scmpv_ejecutado"') && str_contains($html,'value="copropiedad" selected'),'executor filter field keeps the selected value');
