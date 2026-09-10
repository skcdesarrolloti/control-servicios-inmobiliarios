<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Support\FileRateLimiter;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static function (mixed $value): string {
  $number = preg_replace('/[^\d,.\-]/', '', (string) $value) ?? '';
  if ($number === '') {
    return '$0';
  }
  if (str_contains($number, ',') && str_contains($number, '.')) {
    $number = str_replace('.', '', $number);
    $number = str_replace(',', '.', $number);
  } elseif (str_contains($number, ',') && !str_contains($number, '.')) {
    $number = str_replace(',', '.', $number);
  }
  return '$' . number_format((float) $number, 0, ',', '.');
};
$dateLabel = static function (mixed $value): string {
  $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
  return $timestamp > 0 ? date('d/m/Y h:i a', $timestamp) : '-';
};

$orderId = (int) ($_GET['numero'] ?? $_GET['id_orden'] ?? $_GET['id'] ?? 0);
$expires = (int) ($_GET['expires'] ?? 0);
$signature = is_string($_GET['sig'] ?? null) ? trim($_GET['sig']) : '';
$app = new SuCasaControlServiciosInmobiliarios(App::db());
$content = '';
$title = 'Orden no disponible';
$notice = '';
$statusClass = '';

try {
  if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    throw new DomainException('Método no permitido.');
  }
  if (!$app->public_cotizacion_order_signature_valid($orderId, $expires, $signature)) {
    http_response_code(403);
    throw new DomainException('El enlace de esta orden no es válido o ya venció. Solicita un nuevo enlace desde la inmobiliaria.');
  }

  $limiter = new FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
  $rateKey = 'orden-publica:' . $orderId . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? '');
  if (!$limiter->consume($rateKey, 40, 3600)) {
    http_response_code(429);
    header('Retry-After: ' . (string) max(1, $limiter->retryAfter($rateKey, 40, 3600)));
    throw new DomainException('Hay demasiadas solicitudes. Espera unos minutos y vuelve a abrir el enlace.');
  }

  $csrfAction = 'orden-publica-' . $orderId . '-' . $expires;
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
      throw new DomainException('La solicitud es demasiado grande.');
    }
    $csrf = is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : '';
    if (!App::csrf()->verify($csrfAction, $csrf, true)) {
      http_response_code(403);
      throw new DomainException('No se pudo validar la respuesta. Recarga el enlace e inténtalo nuevamente.');
    }
    $result = $app->public_respond_cotizacion_order(
      $orderId,
      is_string($_POST['estado'] ?? null) ? $_POST['estado'] : '',
      is_string($_POST['observacion'] ?? null) ? $_POST['observacion'] : '',
      is_string($_POST['responde'] ?? null) ? $_POST['responde'] : ''
    );
    $redirect = 'orden-publica.php?' . http_build_query([
      'numero' => $orderId,
      'expires' => $expires,
      'sig' => $signature,
      'ok' => $result['estado'],
    ], '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $redirect, true, 303);
    exit;
  }

  $order = $app->public_cotizacion_order($orderId);
  if (!is_array($order)) {
    http_response_code(404);
    throw new DomainException('Orden no encontrada.');
  }

  $state = trim((string) ($order['estado'] ?? ''));
  $stateLower = strtolower($state);
  $pending = $stateLower === '' || $stateLower === 'esperando respuesta';
  $respondedOk = trim((string) ($_GET['ok'] ?? '')) !== '';
  $title = 'Orden de mantenimiento #' . $orderId;
  $statusClass = $pending ? 'is-pending' : ($stateLower === 'aprobada' ? 'is-approved' : 'is-rejected');
  $csrf = (string) ($_SESSION['scm_csrf'][$csrfAction] ?? '');
  if ($csrf === '') {
    $csrf = App::csrf()->token($csrfAction);
  }

  if ($respondedOk) {
    $notice = '<div class="scm-order-public-alert is-success"><strong>Respuesta guardada.</strong><span>La orden quedó actualizada y la notificación interna fue encolada si hay destinatarios configurados.</span></div>';
  }

  $details = [
    'Estado' => $state !== '' ? $state : 'Esperando respuesta',
    'Cotización' => '#' . trim((string) ($order['id_cotizacion'] ?? '-')),
    'Caso' => '#' . trim((string) ($order['id_ticket'] ?? '-')),
    'Inmueble' => trim((string) ($order['inmueble'] ?? $order['id_inmueble'] ?? '-')),
    'Dirección' => trim((string) ($order['direccion'] ?? '-')),
    'Categoría' => trim((string) ($order['categoria'] ?? '-')),
    'Valor' => $money($order['valor'] ?? 0),
    'Fecha' => $dateLabel($order['fecha'] ?? $order['cct_created'] ?? ''),
    'Proveedor' => trim((string) ($order['proveedor'] ?? '-')),
    'Correo proveedor' => trim((string) ($order['correo_proveedor'] ?? '-')),
    'Celular proveedor' => trim((string) ($order['celular_proveedor'] ?? '-')),
    'Creador' => trim((string) ($order['creador'] ?? '-')),
  ];

  $detailHtml = '';
  foreach ($details as $label => $value) {
    $detailHtml .= '<div><span>' . $escape($label) . '</span><strong>' . $escape($value !== '' ? $value : '-') . '</strong></div>';
  }
  $concept = trim((string) ($order['concepto'] ?? $order['actividad'] ?? ''));
  $activity = trim((string) ($order['actividad'] ?? ''));
  $form = '';
  if ($pending) {
    $form = '<section class="scm-order-public-card scm-order-public-form-card"><h2>Responder orden</h2><p>Si estás conforme con esta orden, apruébala. Si no corresponde, desapruébala y deja una observación para el equipo.</p>'
      . '<form method="post">'
      . '<input type="hidden" name="_csrf_token" value="' . $escape($csrf) . '">'
      . '<label>Nombre de quien responde *<input name="responde" maxlength="160" required autocomplete="name" placeholder="Nombre completo"></label>'
      . '<label>Respuesta *<select name="estado" required><option value="">Selecciona una respuesta</option><option value="Aprobada">Aprobar orden</option><option value="Desaprobada">Desaprobar orden</option></select></label>'
      . '<label>Observación interna<textarea name="observacion" rows="4" maxlength="1200" placeholder="Opcional, pero recomendado si desapruebas"></textarea></label>'
      . '<div class="scm-order-public-actions"><button type="submit" class="scm-order-public-button">Guardar respuesta</button></div>'
      . '</form></section>';
  } else {
    $form = '<section class="scm-order-public-card"><h2>Orden respondida</h2><p>Esta orden ya fue marcada como <strong>' . $escape($state !== '' ? $state : 'respondida') . '</strong>. Para cambios adicionales, entra al panel administrativo.</p></section>';
  }

  $content = $notice
    . '<article class="scm-order-public-card">'
    . '<div class="scm-order-public-status ' . $escape($statusClass) . '">' . $escape($state !== '' ? $state : 'Esperando respuesta') . '</div>'
    . '<h1>' . $escape($title) . '</h1>'
    . '<p class="scm-order-public-subtitle">Consulta y respuesta pública segura desde enlace firmado.</p>'
    . '<div class="scm-order-public-grid">' . $detailHtml . '</div>'
    . ($activity !== '' ? '<section class="scm-order-public-section"><h2>Actividad</h2><p>' . $escape($activity) . '</p></section>' : '')
    . ($concept !== '' ? '<section class="scm-order-public-section"><h2>Concepto</h2><p>' . $escape($concept) . '</p></section>' : '')
    . '</article>' . $form;
} catch (DomainException $error) {
  if (http_response_code() < 400) {
    http_response_code(400);
  }
  $content = '<article class="scm-order-public-card scm-order-public-error"><h1>Orden no disponible</h1><p role="alert">' . $escape($error->getMessage()) . '</p></article>';
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[orden-publica] Error orden #' . $orderId . ': ' . $error->getMessage());
  $content = '<article class="scm-order-public-card scm-order-public-error"><h1>No se pudo completar la operación</h1><p>Recarga para consultar el estado antes de intentar nuevamente.</p></article>';
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape($title) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/public-order-response.css?v=<?= $escape(SCM_VERSION) ?>">
</head>
<body class="scm-order-public-page">
  <main class="scm-order-public-shell">
    <header class="scm-order-public-head">
      <span class="scm-order-public-logo"><img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SuCasa Inmobiliaria"></span>
      <div>
        <p>Control Servicios Inmobiliarios</p>
        <strong>Respuesta de orden de mantenimiento</strong>
      </div>
    </header>
    <?= $content ?>
  </main>
</body>
</html>
