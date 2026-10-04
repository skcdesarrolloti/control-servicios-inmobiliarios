<?php
declare(strict_types=1);
// CLI only. Shadow every table written; never invoke a notification or Google transport.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (!isset($db)) { require dirname(__DIR__).'/bootstrap/app.php'; $db=\SCM\Core\App::db(); }
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); });
$tables=array_map([$db,'table'],['jet_cct_funcionarios','jet_cct_cargos','jet_cct_confi_sistema','jet_cct_revisiones_servicios']);
$tables[]='calendario_google_accounts';
$critical=new \SCM\Modules\Pending\PublicServicesCritical($db);
foreach(['cases','payments','audit','jobs'] as $suffix) $tables[]=$critical->table($suffix);
foreach($tables as $table) {
  $definition=$db->getRow('SHOW CREATE TABLE `'.$table.'`');
  $sql=$definition['Create Table']??'';
  if(!str_starts_with($sql,'CREATE TABLE `'.$table.'`')) throw new RuntimeException('Unexpected schema');
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$sql));
}
$assert=static function(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label.PHP_EOL; };
$employeeTable=$db->table('jet_cct_funcionarios');
foreach([1=>'Si',2=>'Si',3=>'No',4=>'Si',5=>'Si'] as $id=>$active) $db->insert($employeeTable,['_ID'=>70000+$id,'id_empleado'=>(string)(94000+$id),'nombre'=>'Funcionario QA '.$id,'activo'=>$active,'id_cargo'=>'99']);
foreach([1,3,4] as $id) $db->insert('calendario_google_accounts',['id_empleado'=>(string)(94000+$id),'google_email'=>'qa'.$id.'@example.invalid','refresh_token_enc'=>'synthetic-refresh']);
$db->insert('calendario_google_accounts',['id_empleado'=>'94005','google_email'=>'expired@example.invalid','access_token_enc'=>'synthetic-expired','expires_at'=>'2000-01-01 00:00:00']);
(new \SCM\Core\Settings($db))->set('internal_admin_notifications',[\SCM\Modules\Pending\PublicServicesCritical::EVENT=>[70001,70002],\SCM\Modules\Pending\PublicServicesCritical::CALENDAR_EVENT=>[70004]]);
$ids=array_column($critical->connectedCalendarContacts(),'employee_id');sort($ids);
$assert($ids===['94001','94004'],'connected active employees from every cargo; inactive and expired unrenewable accounts excluded');
$payload=['contract'=>['contrato'=>'2000','direccion'=>'Dirección QA','arrendatario'=>'QA'],'employee'=>['id_empleado'=>'94001','nombre'=>'Creador QA'],'services'=>['energia'=>['label'=>'Energía','account'=>'NIC-QA','amount'=>350000]],'documents'=>[]];
$now=time();
$db->insert($critical->table(),['review_id'=>123,'contract_id'=>99,'created_at'=>$now,'deadline_at'=>$now+72*3600,'payload_json'=>json_encode($payload)]);
$critical->plan(123);
$jobs=$db->getResults('SELECT payload_json FROM `'.$critical->table('jobs').'` WHERE kind=\'calendar\' ORDER BY id');
$byEmployee=[]; foreach($jobs as $job){$p=json_decode($job['payload_json'],true);$byEmployee[$p['id_empleado']]=$p;}
$assert(count($byEmployee)===2 && !isset($byEmployee['94004']),'only critical notification recipients get calendar jobs; connected employee in legacy calendar list is excluded');
$assert($byEmployee['94001']['sincronizar_google'] && !$byEmployee['94002']['sincronizar_google'],'selected connected employee gets Google; selected unconnected employee gets internal reminder');
$assert($byEmployee['94001']['recordatorio_at']===date('Y-m-d H:i:s',$now+72*3600),'calendar event uses exact 72-hour deadline');
$critical->plan(123);
$assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE kind=\'calendar\'')===2,'replanning does not duplicate reminders or Google events');
$calls=[]; $remote=null;
$transport=new \SCM\Modules\Pending\PublicServicesCritical($db,static function($action,$p)use(&$calls,&$remote){
  $calls[]=$action;
  if($action==='listar_items_calendario')return ['success'=>true,'data'=>$remote?[$remote]:[]];
  if($action==='crear_recordatorio'){$remote=$p+['id'=>444];return ['success'=>true,'data'=>['item'=>$remote]];}
  if($action==='actualizar_recordatorio'){$remote['google_event_id']='qa-google-event';return ['success'=>true,'data'=>['item'=>$remote]];}
  throw new RuntimeException('Unexpected action');
});
$method=(new ReflectionClass($transport))->getMethod('calendar');
$unselected=$byEmployee['94001'];$unselected['id_empleado']='94004';
$result=$method->invoke($transport,'calendar',$unselected);
$assert(!empty($result['cancelled']) && !$calls,'legacy queued job for unselected connected employee is skipped without calling calendar API');
try{$method->invoke($transport,'calendar',$byEmployee['94001']);$assert(false,'Google pending must retry');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'Google Calendar pendiente'),'does not report success before Google event is confirmed');}
$result=$method->invoke($transport,'calendar',$byEmployee['94001']);
$assert($result['google_event_id']==='qa-google-event' && count(array_filter($calls,static fn($a)=>$a==='crear_recordatorio'))===1,'retry reuses external reference and nested API result without duplicate');
$remote=null;
$result=$method->invoke($transport,'calendar',$byEmployee['94002']);
$assert($result['id']===444,'unconnected internal reminder succeeds without Google retry loop');
$db->insert($db->table('jet_cct_revisiones_servicios'),['_ID'=>123,'contrato'=>'2000','inmueble'=>'204578','arrendatario'=>'Arrendatario QA']);
$workspace=new \SCM\Modules\Pending\PublicServicesWorkspace($db);
$assert(str_contains($workspace->critical(['contrato'=>'2000']),'NIC-QA'),'critical list filters real review and renders service debt');
$assert(str_contains($workspace->critical(['contrato'=>'does-not-exist']),'No hay seguimientos'),'critical list honors search filters');
$db->update($critical->table(),['status'=>'verified'],['review_id'=>123]);
$assert(str_contains($workspace->critical([]),'No hay seguimientos') && str_contains($workspace->critical(['status'=>'verified']),'NIC-QA'),'verified cases leave default list and remain searchable');
$db->update($critical->table(),['status'=>'reported'],['review_id'=>123]);
$critical->verify(123,'verified','Pago comprobado en prueba',['id_empleado'=>'94001','id_cargo'=>'11','nombre'=>'Administrador QA']);
$assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE kind=\'calendar_close\'')===2,'verification schedules closure only for configured recipients');
(new \SCM\Core\Settings($db))->set('internal_admin_notifications',[]);
$db->insert($critical->table(),['review_id'=>124,'contract_id'=>99,'created_at'=>$now,'deadline_at'=>$now+72*3600,'payload_json'=>json_encode($payload)]);
$critical->plan(124);
$assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE review_id=124 AND kind=\'calendar\'')===0,'empty critical notification selection creates no reminders despite connected accounts');
echo 'Critical calendar and listing checks passed; no real messages or Google calls.'.PHP_EOL;
