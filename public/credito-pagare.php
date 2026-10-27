<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\OwnerCredits\OwnerCreditPromissoryDocument;
use SCM\Modules\OwnerCredits\OwnerCreditPromissoryService;

// Pagaré en blanco + carta de instrucciones: el deudor lo firma desde su enlace personal (id + token HMAC con
// vencimiento); un funcionario con sesión lo consulta sin token. Mismas protecciones que la firma del acta.
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; font-src 'self'; img-src 'self' https: data:; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$id = (int) ($_GET['id'] ?? 0);
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$staff = $token === '';
$service = new OwnerCreditPromissoryService(App::db(), SCM_APP_SECRET, SCM_BASE_URL);
$jsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$jsonResult = null;
$content = '';
$row = null;

try {
  if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    throw new DomainException('Método no permitido.');
  }
  if ($staff) {
    \SCM\Core\Auth::requireLogin('login.php');
    if (!(new \SCM\App\SuCasaControlServiciosInmobiliarios(App::db()))->canViewOwnerCreditDocuments()) {
      http_response_code(403);
      throw new DomainException('No tienes acceso a los pagarés de créditos.');
    }
    $row = $service->find($id);
  } else {
    $limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
    if (!$limiter->consume('credito-pagare:' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60)) {
      http_response_code(429);
      header('Retry-After: 60');
      throw new DomainException('Hay demasiadas solicitudes. Espera un minuto y vuelve a abrir el enlace.');
    }
    $row = $service->publicRow($id, $token);
  }
  $csrfAction = 'credito-pagare-sign-' . $id;
  $formError = '';
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($staff || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536 || !is_string($_POST['_csrf_token'] ?? null) || !App::csrf()->verify($csrfAction, $_POST['_csrf_token'], false)) {
      http_response_code(403);
      throw new DomainException('No se pudo validar la firma. Abre nuevamente el enlace que te enviamos e inténtalo otra vez.');
    }
    try {
      if (($_POST['operation'] ?? '') === 'request_code') {
        $jsonResult = $service->requestCode($id, $token, is_string($_POST['channel'] ?? null) ? $_POST['channel'] : '');
        $jsonResult['ok'] = $jsonResult['queued'];
      } else {
        $row = $service->sign($id, $token, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $thanksUrl = 'credito-pagare.php?id=' . $id . '&token=' . rawurlencode($token) . '&gracias=1';
        $jsonResult = ['ok' => true, 'signed' => true, 'redirect_url' => $thanksUrl, 'message' => 'Pagaré firmado.'];
        if (!$jsonRequest) {
          header('Location: ' . $thanksUrl, true, 303);
          exit;
        }
      }
    } catch (DomainException $error) {
      $formError = $error->getMessage();
      $lower = mb_strtolower($formError);
      $code = str_contains($lower, 'vigente antes de firmar') ? 'OTP_REQUIRED' : (str_contains($lower, 'código incorrecto') ? 'OTP_INVALID' : (str_contains($lower, 'límite') ? 'OTP_LIMIT' : 'SIGN_ERROR'));
      $jsonResult = ['ok' => false, 'message' => $formError, 'code' => $code];
    }
  }
  $payload = $service->payloadOf($row);
  if (($_GET['format'] ?? '') === 'pdf' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $pdf = $service->pdf($row);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="pagare-' . $payload['number'] . '-firmado.pdf"');
    header('Content-Length: ' . strlen($pdf));
    session_write_close();
    echo $pdf;
    exit;
  }
  $base = 'credito-pagare.php?id=' . $id . ($staff ? '' : '&token=' . rawurlencode($token));
  if (!$staff && $row['status'] === 'firmado' && ($_GET['gracias'] ?? '') === '1') {
    $content = '<main class="scm-acta scm-acta-document scm-acta-thanks"><p class="scm-acta-thanks-kicker">Pagaré firmado</p><h1>Gracias por tu firma</h1>'
      . '<p>Quedó registrada la firma del pagaré No. ' . $escape($payload['number']) . ' y su carta de instrucciones. Respalda los créditos que tomes con el Pasaporte SuCasa: no tendrás que firmarlo de nuevo.</p>'
      . '<div class="scm-acta-thanks-actions"><a class="scm-acta-button" href="' . $escape($base . '&format=pdf') . '">Descargar copia firmada</a></div></main>';
  } else {
    $form = '';
    if (!$staff && $row['status'] === 'pendiente') {
      $csrf = (string) ($_SESSION['scm_csrf'][$csrfAction] ?? '') ?: App::csrf()->token($csrfAction);
      $options = '';
      foreach ($service->verificationChannels($row) as $channel => $label) $options .= '<option value="' . $escape($channel) . '">' . $escape($label) . '</option>';
      $debtor = $payload['debtor'];
      $form = '<section class="scm-acta scm-promissory-sign"><h2>Revisar y firmar</h2>'
        . '<p class="scm-acta-notice">Firma únicamente si leíste el pagaré y la carta de instrucciones y estás de acuerdo. El pagaré queda en blanco y respalda los créditos que tomes con el Pasaporte SuCasa: lo firmas una sola vez.</p>'
        . ($formError !== '' ? '<p role="alert" class="scm-acta-notice">' . $escape($formError) . '</p>' : '')
        . '<noscript><p class="scm-acta-notice">Activa JavaScript para solicitar el código y firmar.</p></noscript>'
        . '<div class="scm-acta-record"><h3>1. Solicita tu código de verificación</h3><label>Recibir código por<select data-acta-otp-channel>' . $options . '</select></label><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-request-code' . ($options === '' ? ' disabled' : '') . '>Solicitar código</button><p role="status" aria-live="polite" data-acta-otp-status></p><p class="scm-acta-help">Vence en 10 minutos. Máximo 5 solicitudes y 5 intentos incorrectos por hora.</p></div>'
        . '<form method="post" data-acta-sign-form data-acta-busy-title="Guardando tu firma" data-acta-busy-text="Estamos generando el PDF firmado del pagaré. No cierres esta página.">'
        . '<h3>2. Confirma tus datos y firma</h3>'
        . '<input type="hidden" name="_csrf_token" value="' . $escape($csrf) . '"><input type="hidden" name="document_hash" value="' . $escape($row['payload_hash']) . '">'
        . '<label>Nombre completo *<input name="signature_name" required maxlength="190" autocomplete="name" value="' . $escape(is_string($_POST['signature_name'] ?? null) ? $_POST['signature_name'] : $debtor['name']) . '"></label>'
        . '<p class="scm-acta-help">Escribe exactamente: <strong>' . $escape($debtor['name']) . '</strong>. Ese nombre será tu firma electrónica.</p>'
        . '<label>Número de documento *<input name="document" required maxlength="40" inputmode="numeric" autocomplete="off" value="' . $escape(is_string($_POST['document'] ?? null) ? $_POST['document'] : '') . '"></label>'
        . '<label>Código de 6 dígitos *<input name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></label>'
        . '<label class="scm-acta-check"><input type="checkbox" name="accepted" value="1" required><span>' . $escape(OwnerCreditPromissoryDocument::CONSENT) . '</span></label>'
        . '<button type="submit" class="scm-acta-button">Firmar pagaré y carta de instrucciones</button><p role="alert" aria-live="assertive" data-acta-sign-status></p></form></section>';
    } elseif ($staff && $row['status'] === 'pendiente') {
      $form = '<p class="scm-acta-notice">Vista interna de consulta. La firma la hace el deudor desde su enlace personal, con su nombre, su documento y el código enviado a su contacto.</p>';
    }
    $content = ($row['status'] === 'firmado' ? '<div class="scm-acta scm-acta-print"><a class="scm-acta-button" href="' . $escape($base . '&format=pdf') . '">Descargar PDF firmado</a> <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-print>Imprimir</button></div>' : '')
      . ($row['status'] === 'anulado' ? '<div class="scm-acta"><p class="scm-acta-notice">Pagaré anulado el ' . $escape(date('d/m/Y', (int) $row['voided_at'])) . ' por ' . $escape($row['voided_by_name']) . ': ' . $escape($row['void_note']) . '</p></div>' : '')
      . '<main class="scm-services-public-root">' . OwnerCreditPromissoryDocument::render($row, $payload, $form) . '</main>';
  }
} catch (DomainException $error) {
  if (http_response_code() < 400) http_response_code(400);
  $content = '<main class="scm-acta scm-acta-document"><h1>Pagaré no disponible</h1><p role="alert">' . $escape($error->getMessage()) . '</p></main>';
  $jsonResult = ['ok' => false, 'message' => $error->getMessage()];
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[credito-pagare] Error con el pagaré #' . $id . ': ' . $error->getMessage());
  $content = '<main class="scm-acta scm-acta-document"><h1>No se pudo completar la operación</h1><p>Recarga la página para ver el estado del pagaré antes de intentarlo de nuevo.</p></main>';
  $jsonResult = ['ok' => false, 'message' => 'No se pudo confirmar la operación. Recarga para ver el estado antes de reintentar.'];
}
session_write_close();
if ($jsonRequest) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($jsonResult, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  exit;
}
$version = $escape(SCM_VERSION);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pagaré · SKC SuCasa Inmobiliaria</title><link rel="stylesheet" href="assets/css/ticket-completion.css?v=<?= $version ?>"><link rel="stylesheet" href="assets/css/ticket-completion-document.css?v=<?= $version ?>"><link rel="stylesheet" href="assets/css/public-services-document.css?v=<?= $version ?>"><link rel="stylesheet" href="assets/css/owner-credit-document.css?v=<?= $version ?>"><script defer src="assets/js/ticket-completion-public.js?v=<?= $version ?>"></script></head><body class="scm-acta-page scm-services-page"><?= $content ?></body></html>
