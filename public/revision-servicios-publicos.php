<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\Pending\PublicServicesDocument;
use SCM\Modules\Pending\PublicServicesWorkspace;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' https: data:; script-src 'self'; frame-src 'self' https:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
$id = (int) ($_GET['numero'] ?? 0);
$valid = Auth::isLoggedIn() || PublicServicesDocument::valid($id, (int) ($_GET['expires'] ?? 0), is_string($_GET['sig'] ?? null) ? $_GET['sig'] : '');
$content = ''; $available = false;
if (!$valid) {
  http_response_code(403);
  $content = '<article class="scm-acta-receipt"><h1>Enlace no válido o vencido</h1><p>Solicita a SKC SuCasa Inmobiliaria un nuevo enlace para consultar la revisión.</p></article>';
} else {
  try {
    $data = (new PublicServicesWorkspace(App::db()))->review($id);
    $content = PublicServicesDocument::review($data['review'], $data['context'], $data['services'], $data['documents'], '');
    $available = true;
  } catch (DomainException $error) {
    http_response_code(404);
    $content = '<article class="scm-acta-receipt"><h1>Revisión no disponible</h1><p>No se encontró la revisión solicitada.</p></article>';
  } catch (Throwable $error) {
    http_response_code(500);
    error_log('[public-services-review] #' . $id . ': ' . $error->getMessage());
    $content = '<article class="scm-acta-receipt"><h1>No disponible</h1><p>No fue posible cargar la revisión. Intenta nuevamente.</p></article>';
  }
}
session_write_close();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Revisión de servicios públicos · SKC SuCasa Inmobiliaria</title>
<link rel="icon" href="<?= PublicServicesDocument::e(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>">
<link rel="stylesheet" href="assets/css/ticket-completion-document.css?v=<?= PublicServicesDocument::e(SCM_VERSION) ?>">
<link rel="stylesheet" href="assets/css/public-services-document.css?v=<?= PublicServicesDocument::e(SCM_VERSION) ?>">
<script src="assets/js/public-services-public.js?v=<?= PublicServicesDocument::e(SCM_VERSION) ?>" defer></script></head>
<body class="scm-services-page"><main class="scm-services-public-root">
<?php if ($available): ?><div class="scm-services-actions"><button type="button" class="scm-services-button" data-services-print>Imprimir revisión</button></div><?php endif; ?>
<?= $content ?>
</main><dialog class="scm-services-preview-dialog" data-services-dialog><header><strong>Acta emitida</strong><button type="button" class="scm-services-button" data-services-preview-close>Cerrar</button></header><iframe title="Vista previa del acta" referrerpolicy="no-referrer"></iframe></dialog></body></html>
