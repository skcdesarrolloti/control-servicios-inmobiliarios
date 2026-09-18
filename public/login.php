<?php

/**
 * Login — Control de Servicios Inmobiliarios
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

$nextRaw = trim((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
$safeNext = static function (string $raw): string {
  $raw = trim($raw);
  if ($raw === '' || preg_match('/[\x00-\x1F\x7F]/', $raw) || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $raw)) {
    return '';
  }
  $path = (string) parse_url($raw, PHP_URL_PATH);
  $queryRaw = (string) parse_url($raw, PHP_URL_QUERY);
  parse_str($queryRaw, $query);
  $digits = static fn(mixed $value): bool => preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value) === 1;
  if ($path === 'crear-acta.php') {
    $out = [];
    if (isset($query['ticket_pk']) && $digits($query['ticket_pk'])) { $out['ticket_pk'] = (string) $query['ticket_pk']; }
    if (isset($query['id_cotizacion']) && $digits($query['id_cotizacion'])) { $out['id_cotizacion'] = (string) $query['id_cotizacion']; }
    if (isset($query['act_id']) && $digits($query['act_id'])) { $out['act_id'] = (string) $query['act_id']; }
    if (($query['source_flow'] ?? '') === 'approved_quote') { $out['source_flow'] = 'approved_quote'; }
    return ($out['ticket_pk'] ?? $out['id_cotizacion'] ?? '') !== ''
      ? 'crear-acta.php?' . http_build_query($out, '', '&', PHP_QUERY_RFC3986)
      : '';
  }
  if ($path === 'index.php') {
    $action = trim((string) ($query['scm_bridge_action'] ?? ''));
    $allowedActions = ['revision_correctiva', 'crear_cotizacion', 'editar_cotizacion', 'enviar_cotizacion', 'crear_orden', 'acta_satisfaccion', 'acta_cotizacion'];
    if (!in_array($action, $allowedActions, true)) {
      return '';
    }
    $out = ['scm_bridge_action' => $action, 'scm_bridge' => '1', 'scm_standalone' => '1'];
    if (isset($query['scm_bridge_ticket_pk']) && $digits($query['scm_bridge_ticket_pk'])) { $out['scm_bridge_ticket_pk'] = (string) $query['scm_bridge_ticket_pk']; }
    if (isset($query['scm_bridge_quote_id']) && $digits($query['scm_bridge_quote_id'])) { $out['scm_bridge_quote_id'] = (string) $query['scm_bridge_quote_id']; }
    if (isset($query['scmqt_cotizacion']) && $digits($query['scmqt_cotizacion'])) { $out['scmqt_cotizacion'] = (string) $query['scmqt_cotizacion']; }
    if (isset($query['scm_tab']) && preg_match('/^[a-z0-9_-]{1,80}$/D', (string) $query['scm_tab'])) { $out['scm_tab'] = (string) $query['scm_tab']; }
    if (($query['source_flow'] ?? '') === 'approved_quote') { $out['source_flow'] = 'approved_quote'; }
    return 'index.php?' . http_build_query($out, '', '&', PHP_QUERY_RFC3986);
  }
  return '';
};
$next = $safeNext($nextRaw);
$afterLogin = $next !== '' ? rtrim((string) SCM_BASE_URL, '/') . '/' . $next : SCM_BASE_URL . '/index.php';

// Ya autenticado → panel
if (\SCM\Core\Auth::isLoggedIn()) {
  header('Location: ' . $afterLogin);
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim(sanitize_text_field($_POST['username'] ?? ''));
  $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $rateLimiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
  $ipKey = 'login-v2-ip|' . $clientIp;
  $userKey = 'login-v2-user|' . $clientIp . '|' . strtolower($user);

  $ipRetryAfter = $rateLimiter->retryAfter($ipKey, 30, 900);
  $userRetryAfter = $rateLimiter->retryAfter($userKey, 10, 900);
  $retryAfter = max($ipRetryAfter, $userRetryAfter);
  $withinAttemptLimit = $retryAfter === 0;
  if (!$withinAttemptLimit) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    $minutes = max(1, (int) ceil($retryAfter / 60));
    $error = sprintf(
      'Demasiados intentos fallidos. Intenta nuevamente en %d %s.',
      $minutes,
      $minutes === 1 ? 'minuto' : 'minutos'
    );
  }

  $token  = $_POST['_csrf_token'] ?? '';
  $action = $_POST['_csrf_action'] ?? 'login';

  if ($withinAttemptLimit && !$scmCsrf->verify($action, $token, true)) {
    $error = 'Token de seguridad inválido. Recarga la página.';
  } elseif ($withinAttemptLimit) {
    $pass = $_POST['password'] ?? '';

    if ($scmAuth->attempt($user, $pass)) {
      $rateLimiter->clear($ipKey);
      $rateLimiter->clear($userKey);
      header('Location: ' . $afterLogin);
      exit;
    }

    $rateLimiter->consume($ipKey, 30, 900);
    $rateLimiter->consume($userKey, 10, 900);
    $error = 'Usuario o contraseña incorrectos.';
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso — Control Servicios Inmobiliarios</title>
  <link rel="icon" href="<?php echo esc_url(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/login.css?v=' . SCM_VERSION); ?>">
</head>

<body>
  <div class="login-card">
    <div class="logo">
      <div class="logo-plate">
        <img src="<?php echo esc_url(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
      </div>
      <span class="logo-text">Control Servicios<br><span>Inmobiliarios</span></span>
    </div>
    <h1>Iniciar sesión</h1>
    <p>Ingresa tus credenciales de acceso</p>

    <?php if ($error !== ''): ?>
      <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <?php echo $scmCsrf->field('login'); ?>
      <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <div class="form-group">
        <label for="username">Usuario</label>
        <input type="text" id="username" name="username" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn-primary">Entrar</button>
    </form>
  </div>
</body>

</html>
