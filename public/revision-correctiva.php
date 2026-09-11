<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\CorrectiveReview\CorrectiveReviewPublicView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' https: data:; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reviewId = (int) ($_GET['numero'] ?? $_GET['id_revision_correctiva'] ?? $_GET['id'] ?? 0);
$result = [
  'title' => 'Revisión correctiva',
  'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>No disponible</h1><p>No fue posible cargar la revisión.</p></article>',
  'status' => 500,
];

try {
  $view = new CorrectiveReviewPublicView(App::db());
  $result = $view->render($reviewId);
  http_response_code((int) ($result['status'] ?? 200));
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[revision-correctiva-publica] Error revisión #' . $reviewId . ': ' . $error->getMessage());
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape((string) ($result['title'] ?? 'Revisión correctiva')) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/corrective-review-public.css?v=<?= $escape(SCM_VERSION) ?>">
</head>
<body class="scm-corrective-public-page">
  <main class="scm-corrective-public-shell">
    <header class="scm-corrective-public-head">
      <span class="scm-corrective-public-logo"><img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SuCasa Inmobiliaria"></span>
      <div>
        <p>Control Servicios Inmobiliarios</p>
        <strong>Revisión correctiva</strong>
      </div>
    </header>
    <?= (string) ($result['content'] ?? '') ?>
  </main>
</body>
</html>
