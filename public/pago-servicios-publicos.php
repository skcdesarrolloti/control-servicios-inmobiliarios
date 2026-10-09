<?php
declare(strict_types=1);
// Compatibility for previously sent signed links. Payment uploads are retired.
require dirname(__DIR__).'/bootstrap/app.php';
use SCM\Modules\Pending\PublicServicesCritical as Critical;
use SCM\Modules\Pending\PublicServicesDocument as Document;
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
  http_response_code(405);header('Allow: GET');
  exit('El reporte público de pagos fue retirado. Contacta a SKC SuCasa Inmobiliaria para la atención del caso.');
}
$id=(int)($_GET['revision']??0);$expires=(int)($_GET['expires']??0);$sig=is_string($_GET['sig']??null)?$_GET['sig']:'';
if(!Critical::valid($id,$expires,$sig)) {http_response_code(403);exit('Enlace no válido o vencido.');}
header('Location: '.Document::url($id,$expires),true,303);
exit;
