<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SCM\Support\InternalEmailCopies as Copies;
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$plan=Copies::plan(['tenant@example.invalid','owner@example.invalid','staff@example.invalid'],['STAFF@example.invalid','staff@example.invalid','invalid']);
check($plan['to']===['tenant@example.invalid','owner@example.invalid'] && $plan['cc']===['staff@example.invalid'],'Configured staff are removed from standalone primary jobs');
$internal=Copies::plan(['a@example.invalid','b@example.invalid'],['a@example.invalid','b@example.invalid']);
check($internal['internal_only'] && $internal['to']===['a@example.invalid'],'Internal-only event keeps one primary recipient');
check(Copies::plan([],['a@example.invalid'])['to']===['a@example.invalid'],'Configured-only event still has a deliverable To');
check(Copies::plan(['tenant@example.invalid'],[])['cc']===[],'No configuration creates no automatic author copies');
check(Copies::plan(['invalid'],[])['to']===[],'Invalid primary contacts are not used');
$records=Copies::recipients([['email'=>'tenant@example.invalid','role'=>'arrendatario','pdfs'=>['signed.pdf']],['email'=>'staff@example.invalid','role'=>'admin']],['staff@example.invalid']);
check(count($records)===1 && $records[0]['pdfs']===['signed.pdf'] && $records[0]['role']==='arrendatario','Per-party document selection and role are preserved');
check($records[0]['internal_cc']===['staff@example.invalid'],'Recipient records carry their event copies');
echo "OK: isolated routing checks; no messages sent.\n";
