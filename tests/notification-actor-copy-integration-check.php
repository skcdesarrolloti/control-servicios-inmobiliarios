<?php
declare(strict_types=1);
// Connection-local shadows and inert providers only. Never call configured transports.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR,$error->getMessage().PHP_EOL); exit(1); });
$db=\SCM\Core\App::db();
$shared=getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__,2).'/shared-notifications';
require_once $shared.'/autoload.php';
$config=require $shared.'/config.php';
$queue=$config['queue']['queue_table'];$attempts=$config['queue']['attempts_table'];
foreach ([$queue,$attempts,$db->table('jet_cct_funcionarios'),$db->table('jet_cct_confi_sistema')] as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new RuntimeException('Invalid table');
  $definition=$db->getRow('SHOW CREATE TABLE `'.$table.'`')['Create Table'];
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition));
}
(new ReflectionProperty(\SCM\Core\Settings::class,'data'))->setValue(\SCM\Core\App::settings(),[]);
$db->insert($db->table('jet_cct_funcionarios'),['_ID'=>701,'id_empleado'=>'97001','nombre'=>'Autor QA','correo'=>'author@example.invalid','activo'=>'Si']);
$db->insert($db->table('jet_cct_funcionarios'),['_ID'=>702,'id_empleado'=>'97002','nombre'=>'Operador posterior','correo'=>'later@example.invalid','activo'=>'Si']);
$_SESSION=['scm_logged_in'=>true,'scm_user_id'=>701,'scm_employee_id'=>'97001','scm_last_activity'=>time()];
$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL; };
$row=static fn(string $key): array => $db->getRow('SELECT * FROM `'.$queue.'` WHERE dedupe_key LIKE ? ORDER BY id DESC LIMIT 1',[$key.'%']);
$cc=static fn(array $r): array => json_decode($r['payload_json'],true)['cc']??[];
$bridge=new \SCM\Support\SharedNotificationsBridge($db);
$emails=new \SCM\Support\EmailQueue($db);
$check($emails->enqueue('tenant@example.invalid','QA','<p>Acta</p>',['dedupe_key'=>'actor-copy:wrapper','payload'=>['cc'=>['manual@example.invalid'],'reply_to'=>'reply@example.invalid','attachments'=>[['path'=>'fixture.pdf','name'=>'acta.pdf']]]])===1,'EmailQueue accepts existing email notification');
$first=$row('actor-copy:wrapper');
$check($cc($first)===['manual@example.invalid','author@example.invalid'],'Common boundary appends real actor to existing CC');
$check(json_decode($first['meta_json'],true)['actor_email_copy']['employee_id']==='97001','Queue records real employee ID');
$bridge->queue()->enqueueEmail(['owner@example.invalid'],'QA','<p>Direct module</p>',['project_code'=>$bridge->projectCode(),'dedupe_key'=>'actor-copy:direct']);
$check($cc($row('actor-copy:direct'))===['author@example.invalid'],'Direct shared queue calls receive actor CC too');
$admin=new \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService($db);
$insert=new ReflectionMethod($admin,'insertQueueRow');
$check($insert->invoke($admin,'email','bulk@example.invalid',['_ID'=>123,'nombre'=>'Destinatario QA','tipo_actor'=>'arrendatarios'],'QA','Mensaje','actor-copy-admin'),'Administrative module inserts temporary notification');
$adminRow=$db->getRow('SELECT * FROM `'.$queue.'` WHERE source_module=? ORDER BY id DESC LIMIT 1',[\SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService::SOURCE_MODULE]);
$check($cc($adminRow)===['author@example.invalid'],'Administrative SQL insertion uses same CC policy');
$emails->enqueue('author@example.invalid','QA','<p>Own notification</p>',['dedupe_key'=>'actor-copy:own']);
$check($cc($row('actor-copy:own'))===[],'No duplicate CC when actor is already primary recipient');
\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db,'signer@example.invalid','Código ficticio','<p>123456</p>',['channel'=>'email','dedupe_key'=>'actor-copy:otp','meta'=>['event'=>'signature_otp']]);
$check($cc($row('actor-copy:otp'))===[],'Actual act delivery keeps signature codes signer-only');
$_SESSION=[];
$bridge->queue()->enqueueEmail(['background@example.invalid'],'QA','<p>Scheduled</p>',['project_code'=>$bridge->projectCode(),'dedupe_key'=>'actor-copy:background','scheduled_at'=>'2027-01-01 10:00:00','meta'=>['notification_actor_employee_id'=>'97001']]);
$check($cc($row('actor-copy:background'))===['author@example.invalid'],'Background email captures persisted originator without session');
$_SESSION=['scm_logged_in'=>true,'scm_user_id'=>702,'scm_employee_id'=>'97002','scm_last_activity'=>time()];
$emails->enqueue('next@example.invalid','QA','<p>New action</p>',['dedupe_key'=>'actor-copy:next']);
$check($cc($row('actor-copy:next'))===['later@example.invalid'] && $cc($row('actor-copy:wrapper'))===['manual@example.invalid','author@example.invalid'],'Later actor cannot change CC of already queued emails');
// Settings endpoint: same permission, CSRF and transaction as existing event settings.
final class ActorCopySettingsResult extends RuntimeException { public function __construct(public bool $ok,public array $data) {} }
final class ActorCopySettingsProbe {
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public bool $permission=true;public bool $csrf=false;
  public function __construct(public $db) {}
  private function verifyCsrf(): void {$this->csrf=true;}
  private function canManageInternalNotificationSettings(): bool {return $this->permission;}
  private function sanitizeInternalNotificationSettings($settings): array {return [];}
  private function jsonOk($data): never {throw new ActorCopySettingsResult(true,$data);}
  private function jsonFail($message): never {throw new ActorCopySettingsResult(false,['message'=>$message]);}
}
$settingsProbe=new ActorCopySettingsProbe($db);
foreach (['0','1'] as $enabled) {
  $_POST=['settings'=>'{}','email_actor_cc_enabled'=>$enabled];
  try {$settingsProbe->ajax_handler_internal_notifications_save();}catch(ActorCopySettingsResult $result){$check($result->ok && $settingsProbe->csrf,'Settings saves CC toggle '.$enabled.' through existing handler');}
  $emails->enqueue('toggle@example.invalid','QA','<p>Toggle</p>',['dedupe_key'=>'actor-copy:toggle:'.$enabled]);
  $check($cc($row('actor-copy:toggle:'.$enabled))===($enabled==='1'?['later@example.invalid']:[]),'Saved setting '.$enabled.' controls CC at insertion');
}
$_POST=['settings'=>'{}','email_actor_cc_enabled'=>['invalid']];
try {$settingsProbe->ajax_handler_internal_notifications_save();}catch(ActorCopySettingsResult $result){$check(!$result->ok,'Invalid CC configuration rejected');}
$settingsProbe->permission=false;
try {$settingsProbe->ajax_handler_internal_notifications_save();}catch(ActorCopySettingsResult $result){$check(!$result->ok,'Existing settings permissions enforced');}
// The worker reads the captured CC without consulting its current session.
final class ActorCopyInertEmail implements \SharedNotifications\Contracts\ProviderInterface {
  public function code(): string {return 'email_smtp';}
  public function send(array $notification): array {return ['ok'=>true,'http_code'=>200,'request'=>json_decode($notification['payload_json'],true),'response'=>['simulated'=>true]];}
}
$_SESSION=[];
$db->pdo()->exec('UPDATE `'.$queue.'` SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
$worker=new \SharedNotifications\NotificationWorker(new \SharedNotifications\Storage\PdoStorageAdapter($db->pdo()),(new \SharedNotifications\Providers\ProviderRegistry())->add(new ActorCopyInertEmail()),new \SharedNotifications\Config\QueueConfig($queue,$attempts));
$result=$worker->run(30,$bridge->projectCode());
$check($result['sent']===9,'Inert worker processes all temporary notifications with captured CC');
$attempt=$db->getRow('SELECT request_payload FROM `'.$attempts.'` WHERE notification_id=?',[$first['id']]);
$check(json_decode($attempt['request_payload'],true)['cc']===['manual@example.invalid','author@example.invalid'],'Attempt history keeps CC sent by the worker');
echo "OK: {$checks} actor CC integration checks; all writes were temporary, no external messages sent.\n";
