<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Modules\PreventiveReview\PreventiveReviewPublicView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' https: data:; script-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reviewId = (int) ($_GET['numero'] ?? $_GET['id_revision_preventiva'] ?? $_GET['id'] ?? 0);
$expires = (int) ($_GET['expires'] ?? 0);
$signature = is_string($_GET['sig'] ?? null) ? trim($_GET['sig']) : '';
$result = [
  'title' => 'Revisión preventiva',
  'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>No disponible</h1><p>No fue posible cargar la revisión.</p></article>',
  'status' => 500,
];

try {
  $hasPanelSession = Auth::isLoggedIn();
  $hasValidPublicSignature = SuCasaControlServiciosInmobiliarios::preventiveReviewPublicSignatureValid($reviewId, $expires, $signature);
  if (!$hasPanelSession && !$hasValidPublicSignature) {
    $result = [
      'title' => 'Enlace no válido',
      'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>Enlace no válido o vencido</h1><p role="alert">Solicita a la inmobiliaria un nuevo enlace para consultar esta revisión preventiva.</p></article>',
      'status' => 403,
    ];
    http_response_code(403);
  } elseif (($_GET['format'] ?? '') === 'pdf') {
    // Mismo HTML e impresión del informe; la sesión se libera porque generar el PDF toma unos segundos.
    session_write_close();
    $bytes = (new SuCasaControlServiciosInmobiliarios(App::db()))->preventiveReviewPdfBytes($reviewId);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="revision-preventiva-' . $reviewId . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    echo $bytes;
    exit;
  } else {
  $pdfQuery = ['numero' => $reviewId, 'format' => 'pdf'] + ($hasValidPublicSignature ? ['expires' => $expires, 'sig' => $signature] : []);
  $view = new PreventiveReviewPublicView(App::db());
  $result = $view->render($reviewId, 'revision-preventiva.php?' . http_build_query($pdfQuery));
  http_response_code((int) ($result['status'] ?? 200));
  }
} catch (DomainException $error) {
  header('Content-Type: text/html; charset=UTF-8');
  http_response_code(500);
  $result['content'] = '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>No disponible</h1><p role="alert">' . $escape($error->getMessage()) . '</p></article>';
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[revision-preventiva-publica] Error revisión #' . $reviewId . ': ' . $error->getMessage());
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape((string) ($result['title'] ?? 'Revisión preventiva')) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/corrective-review-public.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/preventive-review.css?v=<?= $escape(SCM_VERSION) ?>">
  <script src="assets/js/corrective-review-public.js?v=<?= $escape(SCM_VERSION) ?>" defer></script>
</head>
<body class="scm-corrective-public-page">
  <main class="scm-corrective-public-shell">
    <?= (new PreventiveReviewPublicView(App::db()))->headerHtml() ?>
    <?= (string) ($result['content'] ?? '') ?>
  </main>
</body>
</html>
