<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('SCM_GOTENBERG_URL=');
require dirname(__DIR__) . '/bootstrap/app.php';
$db = new \SCM\Core\Database(\SCM\Core\App::db()->pdo(), 'scm_pdf_qa_' . bin2hex(random_bytes(5)) . '_');
$repo = new \SCM\Modules\TicketCompletion\CompletionRepository($db);
$secret = str_repeat('pdf-repair-test-', 3);
$service = new \SCM\Modules\TicketCompletion\CompletionService($repo, $secret, 'https://example.invalid');
$repair = new \SCM\Modules\TicketCompletion\CompletionPdfRepair($service, $secret);
foreach ([$repo->schemaSql(), $repair->schemaSql()] as $sql) $db->pdo()->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql));
$payload = ['created_at'=>time(), 'contract'=>'2000', 'property'=>'204578', 'ticket_number'=>'10841',
  'address'=>'Dirección de prueba', 'executor'=>'propietario', 'items'=>[['damage'=>'Daño de prueba','solution'=>'Solución de prueba']],
  'observations'=>'Prueba', 'signer'=>['name'=>'Ana Pérez','role'=>'propietario','email'=>'ana@example.invalid','phone'=>''],
  'actor'=>['name'=>'Funcionario Prueba','cargo'=>'Coordinador','email'=>'staff@example.invalid','phone'=>'']];
$json=json_encode($payload, JSON_THROW_ON_ERROR); $hash=hash('sha256',$json); $at=time();
$sig=['name'=>'Ana Pérez','consent_text'=>'Aceptación registrada.', 'signed_at'=>$at,'document_hash'=>$hash];
$sig['evidence_hmac']=hash_hmac('sha256',json_encode($sig,JSON_THROW_ON_ERROR),$secret);
$original="%PDF-1.4\nOriginal defectuoso de prueba"; $oldHash=hash('sha256',$original);
$db->insert($repo->table(), ['id'=>1,'ticket_pk'=>123,'status'=>'signed','payload_json'=>$json,'payload_hash'=>$hash,
  'token_nonce'=>str_repeat('a',64),'expires_at'=>$at+600,'created_at'=>$at,'signed_at'=>$at,'signed_json'=>json_encode($sig,JSON_THROW_ON_ERROR),
  'signed_pdf'=>$original,'pdf_hash'=>$oldHash,'pdf_hmac'=>hash_hmac('sha256','1|'.$hash.'|'.$oldHash,$secret)]);
$before=$repo->act(1); $checks=0;
$assert=static function(bool $ok) use (&$checks): void { if (!$ok) throw new RuntimeException('PDF repair check failed #'.($checks+1)); $checks++; };
$reject=static function(Closure $fn) use ($assert): void { try {$fn();} catch (Throwable) {$assert(true); return;} $assert(false); };
$reject(fn()=>$repair->prepare(1,str_repeat('b',64)));
$candidate=$repair->prepare(1,$oldHash);
$assert($repo->act(1)===$before); // Preview does not mutate the original.
$reject(fn()=>$repair->apply($candidate,''));
$repair->apply($candidate,'Corrección de fuentes del PDF de prueba.');
$after=$repo->act(1);
$assert($service->pdf($after)===$candidate['pdf']);
$audit=$db->getRow('SELECT * FROM `'.$repair->table().'` WHERE act_id=1');
$assert($audit['original_pdf']===$original && $audit['original_hash']===$oldHash && $audit['original_hmac']===$before['pdf_hmac']);
foreach (['signed_pdf','pdf_hash','pdf_hmac'] as $key) {unset($before[$key],$after[$key]);}
$assert($before===$after); // Payload, signature, date, token, delivery and business fields stay intact.
$reject(fn()=>$repair->apply($candidate,'Reintento con una copia obsoleta.'));
$assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$repair->table().'`')===1);
$current=$repo->act(1); $next=$candidate;
$next['original_hash']=$current['pdf_hash']; $next['pdf'].="\n"; $next['pdf_hash']=hash('sha256',$next['pdf']);
$db->pdo()->exec('DROP TEMPORARY TABLE `'.$repair->table().'`');
$reject(fn()=>$repair->apply($next,'Simular fallo del respaldo.'));
$assert($repo->act(1)===$current && !$db->pdo()->inTransaction());
echo "$checks PDF repair checks passed using temporary tables; no real acts changed.\n";
