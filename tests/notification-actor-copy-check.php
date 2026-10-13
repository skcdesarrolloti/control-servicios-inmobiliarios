<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Support\NotificationActorCopy;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Database($pdo);
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios (_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, correo TEXT, activo TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
foreach ([[7,'900','Autor','Author@Example.invalid','Si'],[900,'OTHER','Colisión','wrong@example.invalid','Si'],[8,'901','Sin correo','bad','Si'],[9,'902','Inactivo','inactive@example.invalid','No'],[10,'903','Otro','other@example.invalid','Si']] as [$pk,$id,$name,$email,$active]) {
  $db->insert('wp_jet_cct_funcionarios', ['_ID'=>$pk,'id_empleado'=>$id,'nombre'=>$name,'correo'=>$email,'activo'=>$active]);
}
$db->insert('wp_jet_cct_confi_sistema', ['_ID'=>1,'funcion'=>'control_servicios_config','valor'=>'{}']);
function login(int $pk): void { $_SESSION=['scm_logged_in'=>true,'scm_user_id'=>$pk,'scm_employee_id'=>'untrusted-session-cache','scm_last_activity'=>time()]; }
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo 'PASS: '.$label.PHP_EOL; }
$base=['project_code'=>'control-servicios-inmobiliarios','source_module'=>'qa','channel'=>'email','destination'=>'tenant@example.invalid','payload_json'=>json_encode(['attachments'=>[['path'=>'example.pdf','name'=>'acta.pdf']],'reply_to'=>'reply@example.invalid','cc'=>['manual@example.invalid','AUTHOR@example.invalid','tenant@example.invalid'],'bcc'=>['author@example.invalid','hidden@example.invalid']]),'meta_json'=>json_encode(['event'=>'respuesta','case'=>10]),'status'=>'pending','attempts'=>0,'dedupe_key'=>'unique','scheduled_at'=>'2027-01-01 10:00:00'];
$apply=static fn(array $row): array => (new NotificationActorCopy($db))->enrich($row);
login(7);
$row=$apply($base);$payload=json_decode($row['payload_json'],true);$meta=json_decode($row['meta_json'],true);
check($payload['cc']===['manual@example.invalid','author@example.invalid'] && $payload['bcc']===['hidden@example.invalid'],'CC merges and deduplicates existing To/CC/BCC');
check($payload['attachments']===[['path'=>'example.pdf','name'=>'acta.pdf']] && $payload['reply_to']==='reply@example.invalid','Attachments and Reply-To remain intact');
check($meta['actor_email_copy']['employee_id']==='900' && $meta['actor_email_copy']['status']==='cc','Session PK resolves real employee ID despite colliding numbers');
check($row['scheduled_at']===$base['scheduled_at'] && $row['dedupe_key']===$base['dedupe_key'] && $row['attempts']===0,'CC does not change scheduling, attempts or deduplication');
$own=$apply(array_replace($base,['destination'=>'AUTHOR@example.invalid']));
check(json_decode($own['payload_json'],true)['cc']===['manual@example.invalid','tenant@example.invalid'] && json_decode($own['meta_json'],true)['actor_email_copy']['status']==='already_to','Actor already in To is not repeated in CC');
foreach (['whatsapp','sms'] as $channel) check($apply(array_replace($base,['channel'=>$channel]))===array_replace($base,['channel'=>$channel]),$channel.' transport is untouched');
check($apply(array_replace($base,['project_code'=>'other-project']))===array_replace($base,['project_code'=>'other-project']),'Other projects are untouched');
$clean=array_replace($base,['payload_json'=>'{}']);
$otp=$apply(array_replace($clean,['meta_json'=>'{"event":"signature_otp"}']));
check(empty(json_decode($otp['payload_json'],true)['cc']) && json_decode($otp['meta_json'],true)['actor_email_copy']['status']==='signer_only','Signature OTP is never copied to employee');
$_SESSION=[];
check(empty(json_decode($apply($clean)['payload_json'],true)['cc']),'Public/system action without an employee does not invent a recipient');
$background=$apply(array_replace($clean,['meta_json'=>'{"notification_actor_employee_id":"900"}']));
check(json_decode($background['payload_json'],true)['cc']===['author@example.invalid'],'Background action uses its persisted real employee ID');
$emailFallback=$apply(array_replace($clean,['meta_json'=>'{"notification_actor_email":"AUTHOR@example.invalid"}']));
check(json_decode($emailFallback['payload_json'],true)['cc']===['author@example.invalid'],'Public quote response resolves saved creator email through staff table');
login(10);
$current=$apply(array_replace($clean,['meta_json'=>'{"notification_actor_email":"author@example.invalid"}']));
check(json_decode($current['payload_json'],true)['cc']===['other@example.invalid'],'Current operator takes precedence over quote creator fallback');
login(8);check(json_decode($apply($clean)['meta_json'],true)['actor_email_copy']['status']==='email_missing','Missing employee email does not prevent original message');
login(9);check(empty(json_decode($apply($clean)['payload_json'],true)['cc']),'Inactive employee is not copied');
login(7);$_SESSION['scm_last_activity']=time()-999999;
check(empty(json_decode($apply($clean)['payload_json'],true)['cc']),'Expired session cannot supply an actor');
login(7);
$db->update('wp_jet_cct_confi_sistema',['valor'=>'{"email_actor_cc_enabled":false}'],['_ID'=>1]);
check(json_decode($apply($clean)['meta_json'],true)['actor_email_copy']['status']==='disabled','General setting can disable automatic CC');
$bad=array_replace($base,['payload_json'=>'bad-json']);check($apply($bad)===$bad,'Malformed legacy payload is preserved rather than replaced');
echo "OK: actor CC policy checked with isolated SQLite fixtures; no messages sent.\n";
