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
 'wp_jet_cct_tickets'=>['_ID','id_ticket','departamento','tema_ayuda','estado','estado_administrativo','fecha','fecha_actualizacion','id_cotizacion_mantenimiento','id_empleado','fue_enviada_cotizacion_mantenimiento','estado_respuesta_cotizacion_mantenimiento','fecha_envio_cotizacion_mantenimiento','fecha_respuesta_cotizacion_mantenimiento','cct_modified'],
 'wp_jet_cct_cotizacion_mantenimiento'=>['_ID','id_ticket','se_envio','estado','fecha_envio','fecha_respuesta','financiacion','cct_modified'],
];
foreach($tables as $table=>$columns) $pdo->exec('CREATE TABLE '.$table.' (_ID INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(fn($c)=>$c.' TEXT',array_slice($columns,1))).')');
$db = new \SCM\Core\Database($pdo);
$schema = new \SCM\Support\SchemaInspector($db);
(new ReflectionProperty($schema,'tableExistsCache'))->setValue($schema,array_fill_keys(array_keys($tables),true));
(new ReflectionProperty($schema,'tableColumnsCache'))->setValue($schema,$tables);

// Caso 1: cotización vieja sin enviar (#10) reemplazada por una enviada (#11); el ticket quedó diciendo "No".
// Caso 2: única cotización enviada y aprobada (#12), ticket sin actualizar.
// Caso 3: única cotización sin enviar (#13).
// Caso 4: sin cotización.
$insert=$pdo->prepare("INSERT INTO wp_jet_cct_tickets (_ID,id_ticket,departamento,tema_ayuda,estado,estado_administrativo,fecha,fecha_actualizacion,id_cotizacion_mantenimiento,id_empleado,fue_enviada_cotizacion_mantenimiento,estado_respuesta_cotizacion_mantenimiento,fecha_envio_cotizacion_mantenimiento,fecha_respuesta_cotizacion_mantenimiento) VALUES (?,?,'Mantenimiento','Reparaciones necesarias','En proceso','Cotizado',1000,1000,?,'99',?,?,?,?)");
foreach([[1,'11','No','',0,0],[2,'12','No','',0,0],[3,'13','No','',0,0],[4,'','','',0,0]] as [$id,$cot,$sent,$resp,$fe,$fr]) $insert->execute([$id,(string)$id,$cot,$sent,$resp,$fe,$fr]);
$pdo->exec("INSERT INTO wp_jet_cct_cotizacion_mantenimiento (_ID,id_ticket,se_envio,estado,fecha_envio,fecha_respuesta) VALUES
 (10,'1','No','',0,0),(11,'1','Si','Esperando respuesta',1790000000,0),(12,'2','Si','Aprobada',1790000000,1790100000),(13,'3','No','',0,0)");

$assert=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";};
$ids=static function(array $rows): array { $out=array_map('intval',array_column($rows,'_ID')); sort($out); return $out; };

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
$assert($ids($maintenance->queryMaintenance(array_replace($filters,['fCotizacion'=>'has','fCotizacionEnviada'=>'si'])))===[1,2],'maintenance "Fue enviada = Sí" uses the current quote');
$assert($ids($maintenance->queryMaintenance(array_replace($filters,['fCotizacion'=>'has','fCotizacionEnviada'=>'no'])))===[3],'maintenance "Fue enviada = No" ignores replaced unsent quotes');
$assert($ids($maintenance->queryMaintenance(array_replace($filters,['fCotizacion'=>'has','fCotizacionEstado'=>'Esperando respuesta'])))===[1],'maintenance quote state uses the current quote');
$assert($ids($maintenance->queryMaintenance(array_replace($filters,['fCotizacionEstado'=>'Aprobada','fCotizacionEnviada'=>'si'])))===[2],'state and sent combine on the same quote');

$generic=new class($db,$tables) {
 use \SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
 public function __construct(private \SCM\Core\Database $db,private array $tables){}
 private function table_exists($t){return isset($this->tables[$t]);}
 private function column_exists($t,$c){return in_array($c,$this->tables[$t]??[],true);}
 private function detect_first_existing_column($t,$columns){foreach($columns as $c)if($this->column_exists($t,$c))return $c;return '';}
 private function generic_topic_where_sql($topics,&$args){$args=array_merge($args,$topics);return 'tema_ayuda IN ('.implode(',',array_fill(0,count($topics),'?')).')';}
};
$params=$generic->parse_params_generic([],'scmpv_');
$total=static fn(array $extra)=>$generic->run_query_generic(['Reparaciones necesarias'],array_replace($params,$extra),[],false)['stats']['total'];
$assert($total(['fCotizacion'=>'has','fCotizacionEnviada'=>'si'])===2,'preventive "Fue enviada = Sí" uses the current quote');
$assert($total(['fCotizacion'=>'has','fCotizacionEnviada'=>'no'])===1,'preventive "Fue enviada = No" ignores replaced unsent quotes');
$assert($total(['fCotizacionEstado'=>'aprobada'])===1,'preventive quote state uses the current quote');

$quotes=$db->getResults('SELECT * FROM wp_jet_cct_cotizacion_mantenimiento WHERE id_ticket = ?',['1']);
$current=\SCM\Support\CurrentQuote::pick($quotes);
$assert((int)$current['_ID']===11,'pick chooses the latest linked quote');
$row=\SCM\Support\CurrentQuote::applyToRow(['fue_enviada_cotizacion_mantenimiento'=>'No','_scm_cot_fecha_envio'=>''],$current);
$assert($row['_scm_cot_se_envio']==='Si'&&$row['fue_enviada_cotizacion_mantenimiento']==='Si'&&$row['_scm_cot_fecha_envio']==='1790000000','card row shows the current quote as sent');
$older=\SCM\Support\CurrentQuote::applyToRow($row,$quotes[0]);
$assert($older['_scm_cot_se_envio']==='Si','an older quote never overrides the current one');
$legacy=\SCM\Support\CurrentQuote::applyToRow(['fecha_envio_cotizacion_mantenimiento'=>1700000000],['_ID'=>5,'se_envio'=>'Si','estado'=>'Aprobada','fecha_envio'=>0]);
$assert($legacy['fecha_envio_cotizacion_mantenimiento']===1700000000,'quotes without a send date keep the ticket date');

$assert(\SCM\Support\CurrentQuote::syncTicket($db,1)&&\SCM\Support\CurrentQuote::syncTicketForQuote($db,12),'ticket mirror sync updates stale tickets');
$t1=$db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=1');
$t2=$db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=2');
$assert($t1['fue_enviada_cotizacion_mantenimiento']==='Si'&&$t1['estado_respuesta_cotizacion_mantenimiento']==='Esperando respuesta'&&(int)$t1['fecha_envio_cotizacion_mantenimiento']===1790000000,'case 1 mirrors its current sent quote');
$assert($t2['estado_respuesta_cotizacion_mantenimiento']==='Aprobada'&&(int)$t2['fecha_respuesta_cotizacion_mantenimiento']===1790100000&&$t2['estado_administrativo']==='Cotizado','case 2 mirrors approval without touching estado_administrativo');
$assert(!\SCM\Support\CurrentQuote::syncTicket($db,1)&&!\SCM\Support\CurrentQuote::syncTicket($db,4),'sync is idempotent and skips cases without quotes');
