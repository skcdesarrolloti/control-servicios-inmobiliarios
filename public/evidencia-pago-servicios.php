<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: SAMEORIGIN');
$id=(int)($_GET['id']??0);$expires=(int)($_GET['expires']??0);$sig=is_string($_GET['sig']??null)?$_GET['sig']:'';
if(!Auth::isLoggedIn()&&!Critical::validEvidence($id,$expires,$sig)){http_response_code(403);exit('Enlace no válido o vencido.');}
$critical=new Critical(App::db());
$row=$critical->available()?App::db()->getRow('SELECT path FROM `'.$critical->table('payments').'` WHERE id=?',[$id]):null;
$root=realpath(dirname(__DIR__).'/storage/public-services-payments');$path=$row?realpath($row['path']):false;
if(!$root||!$path||!str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/')||!is_file($path)){http_response_code(404);exit('Comprobante no disponible.');}
session_write_close();header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="comprobante-'.$id.'.pdf"');header('Content-Length: '.filesize($path));readfile($path);
