<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Support\PublicCaseAccess;
use SCM\Support\PublicCaseReply;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https:; script-src 'self'; frame-src 'self' https://sucasainmobiliaria.com.co; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
$reference = is_string($_GET['scm_case'] ?? null) ? $_GET['scm_case'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = PublicCaseAccess::route($reference, Auth::isLoggedIn());
if ($route['mode'] === 'login' && $method !== 'GET') {
  $route['mode'] = 'public';
}
if ($route['mode'] === 'login') {
  // Enlace individual de funcionario: inicia su sesión y abre el caso en el panel para responder.
  // Si ya no está activo, solo ve la consulta pública con el botón de acceso.
  $route = (new Auth(App::db()))->loginByEmployeeId((string) $route['employee_id'])
    ? ['mode' => 'panel', 'id' => $route['id'], 'audience' => 'funcionario']
    : ['mode' => 'public', 'id' => $route['id'], 'audience' => 'funcionario'];
}
if ($route['mode'] === 'panel') {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . $route['id'], true, 302);
  exit;
}
$case = null;
$reply = ['enabled' => false, 'closed' => false, 'token' => '', 'error' => '', 'sent' => false, 'text' => '', 'action' => rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . rawurlencode($reference)];
$error = 'Este enlace no es válido o está vencido. Solicita a SKC SuCasa Inmobiliaria un nuevo enlace del caso.';
http_response_code(403);
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
  http_response_code(405);
  header('Allow: GET, HEAD, POST');
  $error = 'Método no permitido.';
} elseif ($route['mode'] === 'public') {
  $limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
  $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
  if (!$limiter->consume('public-case:' . $ip, 90, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    $error = 'Espera un momento y vuelve a abrir el enlace.';
  } else {
    try {
      $db = App::db();
      $ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$route['id']]);
      if (!$ticket) { http_response_code(404); $error = 'El caso no está disponible.'; }
      else {
        $case = \SCM\Support\PublicCaseData::fromTicket($ticket);
        $case['history'] = [];
        try {
          $case['history'] = \SCM\Support\PublicCaseData::history($db, $ticket);
        } catch (Throwable $e) {
          error_log('[public-case] No se pudo cargar el historial del caso #' . $route['id']);
        }
        http_response_code(200);
        $csrfAction = 'public-case-reply-' . (int) $route['id'] . '-' . $route['audience'];
        $reply['enabled'] = PublicCaseReply::canReply($route['audience'], $ticket);
        $reply['closed'] = PublicCaseReply::isClosed($ticket);
        if ($method === 'POST') {
          $reply['text'] = is_string($_POST['respuesta'] ?? null) ? mb_substr($_POST['respuesta'], 0, PublicCaseReply::MAX_TEXT) : '';
          try {
            $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($length > PublicCaseReply::MAX_TOTAL_BYTES + 1048576 || ($length > 0 && $_POST === [] && $_FILES === [])) {
              throw new InvalidArgumentException('Los adjuntos superan el tamaño permitido (máximo 20 MB en total).');
            }
            if (!App::csrf()->verify($csrfAction, is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : '', true)) {
              throw new InvalidArgumentException('No se pudo validar el envío. Recarga el enlace e inténtalo nuevamente.');
            }
            $replyKey = 'public-case-reply:' . (int) $route['id'] . ':' . $ip;
            if (!$limiter->consume($replyKey, 6, 3600)) {
              header('Retry-After: ' . max(1, $limiter->retryAfter($replyKey, 6, 3600)));
              throw new InvalidArgumentException('Enviaste varias respuestas seguidas. Espera unos minutos para enviar otra.');
            }
            (new PublicCaseReply($db, new \SCM\Support\SchemaInspector($db)))->submit($ticket, $route['audience'], $reply['text'], $_FILES);
            session_write_close();
            header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/?' . http_build_query(['scm_case' => $reference, 'enviado' => '1'], '', '&', PHP_QUERY_RFC3986), true, 303);
            exit;
          } catch (InvalidArgumentException | DomainException $e) {
            http_response_code(422);
            $reply['error'] = $e->getMessage();
          } catch (Throwable $e) {
            http_response_code(500);
            $reply['error'] = 'No se pudo enviar tu respuesta. Intenta nuevamente más tarde.';
            error_log('[public-case] Error al guardar respuesta del caso #' . $route['id'] . ': ' . $e->getMessage());
          }
        }
        $reply['sent'] = $method !== 'POST' && ($_GET['enviado'] ?? '') === '1';
        if ($reply['enabled']) $reply['token'] = App::csrf()->token($csrfAction);
      }
    } catch (Throwable $e) {
      http_response_code(500);
      $case = null;
      $error = 'No se pudo cargar el caso. Intenta nuevamente más tarde.';
      error_log('[public-case] Error al consultar caso #' . $route['id']);
    }
  }
}
session_write_close();
(new \SCM\Views\PublicCaseView())->render($case, $error, $route['audience'], $reply);
