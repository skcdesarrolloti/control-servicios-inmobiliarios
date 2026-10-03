<?php
declare(strict_types=1);
// Local HTTP upload harness. Every table written is connection-local TEMPORARY.
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||($_SERVER['HTTP_X_SCM_QA']??'')!=='critical-upload'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap/app.php';
header('Content-Type: application/json');
use SCM\Core\App;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
$db=App::db();$critical=new Critical($db);$paths=[];$shadowReady=false;
try{
  $bridge=new \SCM\Support\SharedNotificationsBridge($db);$bridge->queue();
  $tables=[$bridge->queueTable()];
  foreach(['cases','payments','audit','jobs'] as $suffix)$tables[]=$critical->table($suffix);
  foreach(['jet_cct_confi_sistema','jet_cct_funcionarios','jet_cct_cargos'] as $suffix)$tables[]=$db->table($suffix);
  foreach($tables as $table){$row=$db->getRow('SHOW CREATE TABLE `'.$table.'`');$db->pdo()->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$row['Create Table']));}
  $shadowReady=true;
  $db->insert($db->table('jet_cct_cargos'),['_ID'=>11,'nombre_cargo'=>'Director QA']);
  foreach([70001=>['94001','Creador QA','3001112222'],70002=>['94002','Responsable QA','3003334444'],70003=>['94003','Respuesta QA','3005556666']] as $pk=>$f)$db->insert($db->table('jet_cct_funcionarios'),['_ID'=>$pk,'id_empleado'=>$f[0],'nombre'=>$f[1],'correo'=>$f[0].'@example.invalid','celular'=>$f[2],'id_cargo'=>'11','activo'=>'Si']);
  $actor=['id_empleado'=>'94001','nombre'=>'Creador QA','correo'=>'94001@example.invalid','celular'=>'3001112222','id_cargo'=>'11'];
  $critical->saveConfig(['whatsapp_template'=>'qa_critical_document','response_template'=>'qa_payment_document','language'=>'es_CO','whatsapp_enabled'=>'1',Critical::EVENT=>['70002'],Critical::RESPONSE_EVENT=>['70003'],Critical::CALENDAR_EVENT=>[]],$actor);
  $payload=['contract'=>['_ID'=>99,'contrato'=>'2000','direccion'=>'Dirección QA','arrendatario'=>'Arrendatario QA'],'employee'=>$actor,'services'=>['energia'=>['label'=>'Energía','account'=>'NIC-QA','meter'=>'MED-QA','status'=>'Estado critico','amount'=>350000]],'documents'=>[]];
  $deadline=time()-3600;
  $db->insert($critical->table(),['review_id'=>99,'contract_id'=>99,'created_at'=>time()-73*3600,'deadline_at'=>$deadline,'planned'=>1,'payload_json'=>json_encode($payload)]);
  $id=$critical->report(99,(array)($_FILES['evidence']??[]),(string)($_POST['note']??''),str_repeat('a',64));
  $repeated=isset($_FILES['repeat'])?$critical->report(99,$_FILES['repeat'],(string)($_POST['note']??''),str_repeat('b',64)):$id;
  $payments=$db->getResults('SELECT * FROM `'.$critical->table('payments').'`');$paths=array_column($payments,'path');
  $case=$critical->case(99);
  $jobs=$db->getResults('SELECT * FROM `'.$critical->table('jobs').'`');
  $queue=$db->getResults('SELECT * FROM `'.$bridge->queueTable().'`');
  $history=$db->getRow('SELECT * FROM `'.$critical->table('audit').'` WHERE action=?',['payment_reported']);
  $sha=$payments[0]['sha256'];
  $critical->verify(99,'verified','Comprobante verificado QA',$actor);
  echo json_encode(['ok'=>true,'payment_id'=>$id,'repeated_same'=>$repeated===$id,'payment_count'=>count($payments),'status_after_report'=>$case['status'],'status_after_verification'=>$critical->case(99)['status'],'deadline_unchanged'=>(int)$case['deadline_at']===$deadline,'late'=>json_decode($history['details_json'],true)['late'],'jobs'=>count($jobs),'queue'=>count($queue),'channels'=>array_count_values(array_column($queue,'channel')),'all_queued_responses'=>count(array_filter($queue,static fn($q)=>json_decode($q['meta_json'],true)['response']===true))===count($queue),'private_path'=>str_contains($payments[0]['path'],'storage/public-services-payments'),'sha256'=>$sha,'escaped_note'=>\SCM\Modules\Pending\PublicServicesDocument::e($payments[0]['note'])],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
finally{
  if($shadowReady)$paths=array_merge($paths,$db->getCol('SELECT path FROM `'.$critical->table('payments').'`'));
  foreach(array_unique($paths) as $path)if(is_file($path))unlink($path);
}
