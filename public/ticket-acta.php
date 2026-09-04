<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Modules\TicketCompletion\CompletionView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' https: data:; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content = '';
$id = (int) ($_GET['id'] ?? 0);
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$staff = $token === '';
$repo = new CompletionRepository(App::db());
$service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
$view = new CompletionView();
$jsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$jsonResult = null;
$showPrint = false;

try {
  if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    throw new DomainException('Método no permitido.');
  }
  if (!$staff) {
    $limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
    if (!$limiter->consume('ticket-acta:' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60)) {
      http_response_code(429);
      header('Retry-After: 60');
      throw new DomainException('Hay demasiadas solicitudes. Espera un minuto y vuelve a abrir el enlace.');
    }
  }
  if ($staff) {
    \SCM\Core\Auth::requireLogin('login.php');
    $repo->requireSchema();
    $act = $repo->act($id);
    if (!(new \SCM\App\SuCasaControlServiciosInmobiliarios(App::db()))->canAccessTicketCompletion((int) $act['ticket_pk'])) {
      http_response_code(403);
      throw new DomainException('No tienes acceso a esta acta.');
    }
  } else {
    $act = $service->publicAct($id, $token);
  }
  $formError = '';
  $csrfAction = 'ticket-acta-sign-' . $id;
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($staff || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 196608 || !is_string($_POST['_csrf_token'] ?? null) || !App::csrf()->verify($csrfAction, $_POST['_csrf_token'], false)) {
      http_response_code(403);
      throw new DomainException('No se pudo validar la firma. Abre nuevamente el enlace de tu correo e inténtalo otra vez.');
    }
    try {
      if (($_POST['operation'] ?? '') === 'request_code') {
        $jsonResult = $service->requestCode($id, $token, is_string($_POST['channel'] ?? null) ? $_POST['channel'] : '');
        $jsonResult['ok'] = $jsonResult['queued'];
      } else {
        $act = $service->sign($id, $token, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $thanksUrl = 'ticket-acta.php?id=' . $id . '&token=' . rawurlencode($token) . '&gracias=1';
        $jsonResult = ['ok' => true, 'signed' => true, 'redirect_url' => $thanksUrl, 'message' => $act['receipt']['message'] ?? 'Acta firmada. Cierre registrado.'];
        if (!$jsonRequest) {
          header('Location: ' . $thanksUrl, true, 303);
          exit;
        }
      }
    } catch (DomainException $error) {
      $formError = $error->getMessage();
      $lowerError = mb_strtolower($formError);
      $errorCode = 'SIGN_ERROR';
      if (str_contains($lowerError, 'código de verificación vigente') || str_contains($lowerError, 'vigente antes de firmar')) {
        $errorCode = 'OTP_REQUIRED';
      } elseif (str_contains($lowerError, 'código incorrecto')) {
        $errorCode = 'OTP_INVALID';
      } elseif (str_contains($lowerError, 'límite')) {
        $errorCode = 'OTP_LIMIT';
      }
      $jsonResult = ['ok' => false, 'message' => $formError, 'code' => $errorCode];
    }
  }
  $payload = $service->payload($act);
  if (($_GET['format'] ?? '') === 'pdf' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (!$staff && ($_GET['audience'] ?? '') === 'staff') { throw new DomainException('La copia interna requiere sesión de funcionario.'); }
    if ($act['status'] !== 'signed') { throw new DomainException('El PDF solo se puede descargar cuando el acta esté firmada.'); }
    $pdf = $service->pdf($act, $staff && ($_GET['audience'] ?? '') === 'staff');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="acta-' . $id . '-firmada.pdf"');
    header('Content-Length: ' . strlen($pdf));
    session_write_close();
    echo $pdf;
    exit;
  }
  $form = '';
  if (!$staff && $act['status'] === 'signed' && ($_GET['gracias'] ?? '') === '1') {
    $showPrint = false;
    $content = $view->thankYou($act, $payload, $token);
  } elseif (!$staff && $act['status'] === 'pending') {
    $csrf = (string) ($_SESSION['scm_csrf'][$csrfAction] ?? '');
    if ($csrf === '') {
      $csrf = App::csrf()->token($csrfAction);
    }
    $form = $view->signingForm($act, $payload, $csrf, $formError, $_POST, $service->verificationChannels($payload['signer']));
  } elseif ($staff && $act['status'] === 'pending') {
    $form = '<p class="scm-acta-notice">Vista interna de consulta. La firma debe realizarla el destinatario desde su enlace personal, confirmando su nombre y el código enviado a su contacto registrado.</p>';
  }
  if ($content === '') {
    $content = $view->document($act, $payload, $staff, $form);
    if ($act['status'] === 'signed') {
      $showPrint = true;
      $pdfUrl = 'ticket-acta.php?id=' . $id . ($staff ? '' : '&token=' . rawurlencode($token)) . '&format=pdf';
      $content = '<div class="scm-acta scm-acta-print"><a class="scm-acta-button" href="' . $escape($pdfUrl) . '">Descargar PDF firmado</a></div>' . $content;
      $delivery = json_decode((string) ($repo->act($id)['delivery_json'] ?? ''), true) ?: [];
      $pendingCopy = false;
      foreach ($payload['channels'] ?? ['email'] as $channel) { $pendingCopy = $pendingCopy || empty($delivery['signed_receipt'][$channel]['queued']); }
      $content = '<div class="scm-acta scm-acta-print"><p class="scm-acta-notice">Firma registrada. El cierre ya se guardó. ' . ($pendingCopy ? 'No se confirmó el encolado de todas las copias; puedes descargar el PDF aquí y solicitar reenvío a la inmobiliaria.' : 'Copia solicitada por los canales elegidos. En cola no significa entregada.') . '</p></div>' . $content;
    }
  }
} catch (DomainException $error) {
  if (http_response_code() < 400) {
    http_response_code(400);
  }
  $content = '<main class="scm-acta scm-acta-document"><h1>Acta no disponible</h1><p role="alert">' . $escape($error->getMessage()) . '</p></main>';
  $jsonResult = ['ok' => false, 'message' => $error->getMessage()];
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[ticket-acta] Error al procesar acta #' . $id . ': ' . $error->getMessage());
  $content = '<main class="scm-acta scm-acta-document"><h1>No se pudo completar la operación</h1><p>Recarga para consultar el estado del acta antes de intentar nuevamente. No es necesario crear otro documento.</p></main>';
  $jsonResult = ['ok' => false, 'message' => 'No se pudo confirmar la operación. Recarga para consultar el estado antes de reintentar; no crees otra acta.'];
}
session_write_close();
if ($jsonRequest) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($jsonResult, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  exit;
}
$actStatus = isset($act) && is_array($act) ? (string) ($act['status'] ?? '') : '';
$printLocked = $actStatus !== '' && $actStatus !== 'signed';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acta de satisfacción · SuCasa</title><link rel="stylesheet" href="assets/css/ticket-completion.css?v=<?= $escape(SCM_VERSION) ?>"><script defer src="assets/js/ticket-completion-public.js?v=<?= $escape(SCM_VERSION) ?>"></script></head><body class="scm-acta-page<?= $printLocked ? ' scm-acta-page--print-locked' : '' ?>"><?php if ($showPrint): ?><div class="scm-acta scm-acta-print"><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-print>Imprimir acta</button></div><?php endif; ?><?php if ($printLocked): ?><div class="scm-acta scm-acta-print-lock"><p>El acta solo se puede imprimir cuando esté firmada.</p></div><?php endif; ?><?= $content ?></body></html>
