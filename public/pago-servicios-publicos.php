<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
use SCM\Core\App;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
use SCM\Modules\Pending\PublicServicesDocument as Document;
use SCM\Modules\Pending\PublicServicesWorkspace as Workspace;
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' https: data:; script-src 'self'; frame-src 'self' https: blob:; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
$id=(int)($_GET['revision']??0);$expires=(int)($_GET['expires']??0);$sig=is_string($_GET['sig']??null)?$_GET['sig']:'';
$e=[Document::class,'e'];$content='';$case=null;$error='';$success=false;
if(!Critical::valid($id,$expires,$sig)){
  http_response_code(403);$error='Enlace no válido o vencido. Solicita un enlace nuevo a SKC SuCasa Inmobiliaria.';
}else{
  try{
    $critical=new Critical(App::db());$case=$critical->case($id);if(!$case)throw new DomainException('El seguimiento solicitado no está disponible.');
    $_SESSION['services_payment_csrf']??=bin2hex(random_bytes(32));
    if($_SERVER['REQUEST_METHOD']==='POST'){
      if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['services_payment_csrf'],$_POST['csrf']))throw new DomainException('La sesión expiró. Recarga la página e intenta otra vez.');
      $requestKey=(string)($_POST['request_key']??'');
      if(!hash_equals(hash_hmac('sha256','payment-request|'.$id.'|'.$_SESSION['services_payment_csrf'],(string)SCM_APP_SECRET),$requestKey))throw new DomainException('Solicitud no válida.');
      $critical->report($id,(array)($_FILES['evidence']??[]),is_string($_POST['note']??null)?$_POST['note']:'',$requestKey);
      $_SESSION['services_payment_success'][$id]=true;
      // Rotate for subsequent reports. Retried requests retain their stored request key.
      $_SESSION['services_payment_csrf']=bin2hex(random_bytes(32));
      header('Location: '.Critical::url($id,$expires),true,303);exit;
    }
    $success=!empty($_SESSION['services_payment_success'][$id]);unset($_SESSION['services_payment_success'][$id]);
    $data=(new Workspace(App::db()))->review($id);
    $content=Document::review($data['review'],$data['context'],$data['services'],$data['documents'],'');
  }catch(DomainException $ex){$error=$ex->getMessage();}
  catch(Throwable $ex){http_response_code(500);error_log('[services-payment] '.$ex->getMessage());$error='No fue posible procesar la solicitud. Intenta de nuevo.';}
}
$csrf=(string)($_SESSION['services_payment_csrf']??'');
$requestKey=hash_hmac('sha256','payment-request|'.$id.'|'.$csrf,(string)SCM_APP_SECRET);
session_write_close();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reportar pago · SKC SuCasa Inmobiliaria</title><link rel="stylesheet" href="assets/css/ticket-completion-document.css?v=<?= $e(SCM_VERSION) ?>"><link rel="stylesheet" href="assets/css/public-services-document.css?v=<?= $e(SCM_VERSION) ?>"><script defer src="assets/js/public-services-public.js?v=<?= $e(SCM_VERSION) ?>"></script></head><body class="scm-services-page"><main class="scm-services-public-root">
<?php if($error): ?><article class="scm-acta-receipt" role="alert"><h1><?= $case?'Revisa el comprobante':'Enlace no disponible' ?></h1><p><?= $e($error) ?></p></article><?php endif ?>
<?php if($case): ?><article class="scm-acta-receipt scm-services-payment"><h1>Reportar pago de servicios públicos</h1><p>Plazo máximo: <strong>72 horas desde el registro de la revisión</strong>. Fecha límite: <strong><?= date('d/m/Y H:i',(int)$case['deadline_at']) ?> (Colombia)</strong>.</p>
<?php if($success): ?><p role="status">Recibimos tu comprobante y registramos la fecha de envío. El pago queda pendiente de verificación; avisaremos a los responsables.</p><?php endif ?>
<?php if($case['status']==='verified'): ?><p>El pago ya fue verificado por un funcionario.</p><?php else: ?>
<p><?= $case['status']==='reported'?'Ya hay un pago reportado pendiente de verificación. Puedes adjuntar un soporte adicional.':'Adjunta el comprobante PDF del pago. Puedes consultar la revisión y las actas abajo.' ?> <?= time()>(int)$case['deadline_at']?'El plazo venció; puedes enviar el soporte y quedará registrada la hora de recepción.':'' ?></p>
<form method="post" enctype="multipart/form-data" action="<?= $e(Critical::url($id,$expires)) ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="request_key" value="<?= $e($requestKey) ?>"><label for="evidence">Comprobante PDF (máximo 10 MB)</label><input id="evidence" name="evidence" type="file" accept="application/pdf,.pdf" required data-services-payment-file><iframe title="Vista previa del comprobante" data-services-payment-preview hidden></iframe><label for="note">Servicios pagados y observación (opcional)</label><textarea id="note" name="note" maxlength="2000" rows="3"></textarea><p>Enviar un archivo no acredita automáticamente el pago. Un funcionario comprobará el soporte.</p><button type="submit" class="scm-services-button">Enviar comprobante de pago</button></form>
<?php endif ?></article><div class="scm-services-actions"><button type="button" class="scm-services-button" data-services-print>Imprimir revisión</button></div><?= $content ?><?php endif ?>
</main><dialog class="scm-services-preview-dialog" data-services-dialog><header><strong>Acta emitida</strong><button type="button" class="scm-services-button" data-services-preview-close>Cerrar</button></header><iframe title="Vista previa del acta" referrerpolicy="no-referrer"></iframe></dialog></body></html>
