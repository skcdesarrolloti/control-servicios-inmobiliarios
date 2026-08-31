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
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content = '';
$id = (int) ($_GET['id'] ?? 0);
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$staff = $token === '';
$repo = new CompletionRepository(App::db());
$service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
$view = new CompletionView();

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
    if ($staff || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384 || !App::csrf()->verify($csrfAction, (string) ($_POST['_csrf_token'] ?? ''), false)) {
      http_response_code(403);
      throw new DomainException('No se pudo validar la firma. Abre nuevamente el enlace de tu correo e inténtalo otra vez.');
    }
    try {
      $act = $service->sign($id, $token, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } catch (DomainException $error) {
      $formError = $error->getMessage();
    }
  }
  $payload = $service->payload($act);
  $form = '';
  if (!$staff && $act['status'] === 'pending') {
    $csrf = (string) ($_SESSION['scm_csrf'][$csrfAction] ?? '');
    if ($csrf === '') {
      $csrf = App::csrf()->token($csrfAction);
    }
    $form = $view->signingForm($act, $payload, $csrf, $formError, $_POST);
  } elseif ($staff && $act['status'] === 'pending') {
    $form = '<p class="scm-acta-notice">Vista interna de consulta. La firma debe realizarla el destinatario desde el enlace personal enviado a su correo.</p>';
  }
  $content = $view->document($act, $payload, $staff, $form);
} catch (DomainException $error) {
  if (http_response_code() < 400) {
    http_response_code(400);
  }
  $content = '<main class="scm-acta scm-acta-document"><h1>Acta no disponible</h1><p role="alert">' . $escape($error->getMessage()) . '</p></main>';
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[ticket-acta] Error al procesar acta #' . $id . ': ' . $error->getMessage());
  $content = '<main class="scm-acta scm-acta-document"><h1>No se pudo completar la operación</h1><p>Recarga para consultar el estado del acta antes de intentar nuevamente. No es necesario crear otro documento.</p></main>';
}
session_write_close();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acta de satisfacción · SuCasa</title><link rel="stylesheet" href="assets/css/ticket-completion.css?v=<?= $escape(SCM_VERSION) ?>"><script defer src="assets/js/ticket-completion-public.js?v=<?= $escape(SCM_VERSION) ?>"></script></head><body class="scm-acta-page"><div class="scm-acta scm-acta-print"><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-print>Imprimir acta</button></div><?= $content ?></body></html>
