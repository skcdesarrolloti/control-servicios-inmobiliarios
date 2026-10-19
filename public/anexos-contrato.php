<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\LeaseContracts\LeaseAnnexesDocument;
use SCM\Modules\LeaseContracts\LeaseAnnexesService;
use SCM\Modules\Pending\PublicServicesDocument as Doc;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' https: data:; script-src 'self'; frame-src 'self' https:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

$id = (int) ($_GET['numero'] ?? 0);
$role = is_string($_GET['tipo'] ?? null) ? strtolower(trim($_GET['tipo'])) : '';
$index = $role === 'codeudor' ? max(0, min(4, (int) ($_GET['codeudor'] ?? 0))) : 0;
$validRole = isset(LeaseAnnexesDocument::ROLES[$role]) && ($role !== 'codeudor' || $index > 0);
$valid = $validRole && (Auth::isLoggedIn() || LeaseAnnexesDocument::valid($id, $role, $index, (int) ($_GET['expires'] ?? 0), is_string($_GET['sig'] ?? null) ? $_GET['sig'] : ''));
$content = '';
$available = false;
if (!$valid) {
  http_response_code(403);
  $content = '<article class="scm-acta-receipt"><h1>Enlace no válido o vencido</h1><p>Solicita a SKC SuCasa Inmobiliaria un nuevo enlace para consultar los anexos de tu contrato.</p></article>';
} else {
  try {
    $service = new LeaseAnnexesService(App::db());
    $contract = $service->contract($id);
    $content = LeaseAnnexesDocument::publicPage($contract, $role, $index, ['name' => 'SKC SuCasa Inmobiliaria', 'cargo' => 'Área contractual', 'phone' => '']);
    $available = true;
  } catch (DomainException $error) {
    http_response_code(404);
    $content = '<article class="scm-acta-receipt"><h1>Anexos no disponibles</h1><p>No se encontró el contrato solicitado.</p></article>';
  } catch (Throwable $error) {
    http_response_code(500);
    error_log('[lease-annexes-public] #' . $id . ': ' . $error->getMessage());
    $content = '<article class="scm-acta-receipt"><h1>No disponible</h1><p>No fue posible cargar los anexos. Intenta nuevamente.</p></article>';
  }
}
session_write_close();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anexos del contrato · SKC SuCasa Inmobiliaria</title>
<link rel="icon" href="<?= Doc::e(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>">
<link rel="stylesheet" href="assets/css/ticket-completion-document.css?v=<?= Doc::e(SCM_VERSION) ?>">
<link rel="stylesheet" href="assets/css/public-services-document.css?v=<?= Doc::e(SCM_VERSION) ?>">
<link rel="stylesheet" href="assets/css/lease-annexes.css?v=<?= Doc::e(SCM_VERSION) ?>">
<script src="assets/js/public-services-public.js?v=<?= Doc::e(SCM_VERSION) ?>" defer></script></head>
<body class="scm-services-page"><main class="scm-services-public-root">
<?php if ($available): ?><div class="scm-services-actions"><button type="button" class="scm-services-button" data-services-print>Imprimir</button></div><?php endif; ?>
<?= $content ?>
</main><dialog class="scm-services-preview-dialog" data-services-dialog><header><strong>Documento</strong><button type="button" class="scm-services-button" data-services-preview-close>Cerrar</button></header><iframe title="Vista previa del documento" referrerpolicy="no-referrer"></iframe></dialog></body></html>
