<?php
declare(strict_types=1);
// All writes target temporary shadows; the worker uses only an inert provider.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap/app.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$db=\SCM\Core\App::db();$shared=getenv('SHARED_NOTIFICATIONS_PATH')?:dirname(__DIR__,2).'/shared-notifications';
require_once $shared.'/autoload.php';$config=require $shared.'/config.php';
$queue=$config['queue']['queue_table'];$attempts=$config['queue']['attempts_table'];
foreach([$queue,$attempts,$db->table('jet_cct_funcionarios')] as $table){
  if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');
  $ddl=$db->getRow('SHOW CREATE TABLE `'.$table.'`')['Create Table'];$db->pdo()->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
}
foreach([[901,'97001','Configurado A','a@example.invalid','Si'],[902,'97002','Inactivo','inactive@example.invalid','No'],[903,'97003','Configurado B','b@example.invalid','Si'],[904,'97004','Autor no seleccionado','author@example.invalid','Si']] as [$pk,$id,$name,$email,$active])$db->insert($db->table('jet_cct_funcionarios'),['_ID'=>$pk,'id_empleado'=>$id,'nombre'=>$name,'correo'=>$email,'activo'=>$active,'id_cargo'=>'3']);
(new ReflectionProperty(\SCM\Core\Settings::class,'data'))->setValue(\SCM\Core\App::settings(),['email_actor_cc_enabled'=>true,'internal_admin_notifications'=>['qa_alpha'=>[901,902],'qa_beta'=>[903],'qa_both'=>[901,903],'gestion_cobro'=>[901]],\SCM\Support\FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY=>['3']]);
$_SESSION=['scm_logged_in'=>true,'scm_user_id'=>904,'scm_employee_id'=>'97004','scm_last_activity'=>time()];
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL;};
$email=new \SCM\Support\EmailQueue($db);
$rows=static fn(string $key):array=>$db->getResults('SELECT * FROM `'.$queue.'` WHERE dedupe_key LIKE ? ORDER BY id',[$key.'%']);
$cc=static fn(array $r):array=>json_decode($r['payload_json'],true)['cc']??[];
$check($email->enqueue(['tenant@example.invalid','a@example.invalid'],'QA','<p>Acta</p>',['internal_action'=>'qa_alpha','dedupe_key'=>'copies:alpha','payload'=>['reply_to'=>'reply@example.invalid','attachments'=>[['path'=>'fixture.pdf','name'=>'acta.pdf']]]])===1,'One primary job replaces standalone configured recipient');
$alpha=$rows('copies:alpha');$check(count($alpha)===1&&$alpha[0]['destination']==='tenant@example.invalid'&&$cc($alpha[0])===['a@example.invalid'],'Only selected active event staff receive CC');
$check(!in_array('author@example.invalid',$cc($alpha[0]),true),'Unconfigured actor is not automatically copied even with old setting enabled');
$payload=json_decode($alpha[0]['payload_json'],true);$check($payload['reply_to']==='reply@example.invalid'&&$payload['attachments'][0]['name']==='acta.pdf','CC preserves Reply-To and act attachment');
$check(json_decode($alpha[0]['meta_json'],true)['internal_email_copies']['action']==='qa_alpha','Queue traces the event and its copies');
$email->enqueue('owner@example.invalid','QA','<p>Other event</p>',['internal_action'=>'qa_beta','dedupe_key'=>'copies:beta']);
$check($cc($rows('copies:beta')[0])===['b@example.invalid'],'Events resolve independent copy configurations');
$email->enqueue(['a@example.invalid','b@example.invalid'],'QA','<p>Internal only</p>',['internal_action'=>'qa_both','dedupe_key'=>'copies:internal']);
$internal=$rows('copies:internal');$check(count($internal)===1&&$internal[0]['destination']==='a@example.invalid'&&$cc($internal[0])===['b@example.invalid'],'Internal-only event keeps one To and remaining staff in CC');
$email->enqueue('tenant@example.invalid','QA','<p>No copies selected</p>',['internal_action'=>'qa_alpha','internal_cc'=>[],'dedupe_key'=>'copies:excluded']);
$check($cc($rows('copies:excluded')[0])===[],'Explicit empty channel selection suppresses event email copies');
$email->enqueue('plain@example.invalid','QA','<p>Other action</p>',['dedupe_key'=>'copies:plain']);
$check($cc($rows('copies:plain')[0])===[],'Unconfigured action creates no automatic copies');
\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db,'signer@example.invalid','Código ficticio','<p>123456</p>',['channel'=>'email','dedupe_key'=>'copies:otp','meta'=>['event'=>'signature_otp']]);
$check($cc($rows('copies:otp')[0])===[],'Signature OTP still goes exclusively to signer');
$admin=new \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService($db);
$result=$admin->enqueueConfigured('funcionarios',[901,902,903],['email'],'Aviso interno QA','Aviso de gestión');
$check($result['queued']===1&&$result['email_queued']===1,'Configured administrative notices produce a grouped email');
$adminRows=$db->getResults('SELECT * FROM `'.$queue.'` WHERE source_module=?',[\SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService::SOURCE_MODULE]);
$adminDestinations=array_merge([$adminRows[0]['destination']],$cc($adminRows[0]));sort($adminDestinations);
$check(count($adminRows)===1&&$adminDestinations===['a@example.invalid','b@example.invalid']&&count($cc($adminRows[0]))===1,'Administrative grouping uses selected active staff and avoids duplicate mail');
$insertAdmin = new ReflectionMethod($admin,'insertQueueRow');
$collectionRecipient=['_ID'=>880,'tipo_actor'=>'arrendatarios','nombre'=>'Arrendatario QA','_scm_notification_meta'=>['collection_management'=>['ids'=>[1]]]];
$check($insertAdmin->invoke($admin,'email','tenant@example.invalid',$collectionRecipient,'Cobro QA','Aviso de cobro','copies-collection'),'Collection notice enqueues through administrative adapter');
$collectionRow=$db->getRow('SELECT * FROM `'.$queue.'` WHERE subject=?',['Cobro QA']);
$check($cc($collectionRow)===['a@example.invalid'],'Collection actor mail carries event staff in CC');
$collectionRecipient['_scm_test_mode']=['enabled'=>true];
$check($insertAdmin->invoke($admin,'email','test@example.invalid',$collectionRecipient,'Prueba QA','Aviso de prueba','copies-test'),'Test-mode collection preview enqueues');
$previewRow=$db->getRow('SELECT * FROM `'.$queue.'` WHERE subject=?',['Prueba QA']);
$check($cc($previewRow)===[],'Test mode never adds real configured copies');
$email->enqueue('A@example.invalid','QA','<p>Already primary</p>',['internal_action'=>'qa_alpha','dedupe_key'=>'copies:already']);
$check($cc($rows('copies:already')[0])===[],'Configured primary is not repeated in CC');
$_SESSION=[];
$email->enqueue('background@example.invalid','QA','<p>Automatic event</p>',['internal_action'=>'qa_beta','dedupe_key'=>'copies:background']);
$check($cc($rows('copies:background')[0])===['b@example.invalid'],'Automatic event resolves configured copies without employee session');
final class InternalCopiesInertEmail implements \SharedNotifications\Contracts\ProviderInterface{
  public function code():string{return 'email_smtp';}
  public function send(array $row):array{return ['ok'=>true,'http_code'=>200,'request'=>json_decode($row['payload_json'],true),'response'=>['simulated'=>true]];}
}
$count=(int)$db->getVar('SELECT COUNT(*) FROM `'.$queue.'`');
$db->pdo()->exec('UPDATE `'.$queue.'` SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
$worker=new \SharedNotifications\NotificationWorker(new \SharedNotifications\Storage\PdoStorageAdapter($db->pdo()),(new \SharedNotifications\Providers\ProviderRegistry())->add(new InternalCopiesInertEmail()),new \SharedNotifications\Config\QueueConfig($queue,$attempts));
$check($worker->run(30)['sent']===$count,'Inert worker processes primary emails with captured CC');
$attempt=$db->getRow('SELECT request_payload FROM `'.$attempts.'` WHERE notification_id=?',[$alpha[0]['id']]);
$check(json_decode($attempt['request_payload'],true)['cc']===['a@example.invalid'],'Shared attempt history keeps actual CC envelope');
echo "OK: {$checks} configured CC integration checks, temporary tables only; no messages sent.\n";
