<?php

/**
 * Login — Control de Servicios Inmobiliarios
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

// Ya autenticado → panel
if (\SCM\Core\Auth::isLoggedIn()) {
  header('Location: ' . SCM_BASE_URL . '/index.php');
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
      header('Location: ' . SCM_BASE_URL . '/index.php');
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
