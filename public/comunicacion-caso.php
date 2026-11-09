<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\LegalCases\LegalCaseService;
use SCM\Modules\LegalCases\LegalCommunicationService;
use SCM\Modules\LegalCases\LegalDeliveryGateway;
use SCM\Support\StoredFileService;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' blob:; script-src 'self'; connect-src 'self'; frame-src blob:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$id = (int) ($_GET['numero'] ?? 0);
$view = null;
$message = 'Solicita a SKC SuCasa Inmobiliaria un nuevo enlace para consultar esta comunicación.';
$title = 'Enlace no válido o vencido';
if (!LegalCommunicationService::validLink($id, (int) ($_GET['expires'] ?? 0), is_string($_GET['sig'] ?? null) ? $_GET['sig'] : '')) {
  http_response_code(403);
} else {
  try {
    $db = App::db();
    $view = (new LegalCommunicationService($db, new LegalCaseService($db, App::settings()), StoredFileService::fromRuntime(), new LegalDeliveryGateway($db)))->publicView($id);
    if ($view === null) {
      http_response_code(404);
      $title = 'Comunicación no disponible';
    }
  } catch (Throwable $error) {
    http_response_code(500);
    error_log('[legal-communication-public] #' . $id . ': ' . $error->getMessage());
    $title = 'No disponible';
    $message = 'No fue posible cargar la comunicación. Intenta nuevamente.';
  }
}
session_write_close();

$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$size = static fn(int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
$date = '';
if ($view !== null) {
  $sent = (new DateTimeImmutable('@' . $view['created_at']))->setTimezone(new DateTimeZone('America/Bogota'));
  $date = $sent->format('j') . ' de ' . $months[(int) $sent->format('n') - 1] . ' de ' . $sent->format('Y');
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comunicación · SKC SuCasa Inmobiliaria</title>
<link rel="icon" href="<?= $e(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>">
<link rel="stylesheet" href="assets/css/legal-communication-public.css?v=<?= $e(SCM_VERSION) ?>">
<script src="assets/js/legal-communication-public.js?v=<?= $e(SCM_VERSION) ?>" defer></script></head>
<body><main class="lcp-page">
<?php if ($view === null): ?>
  <article class="lcp-card"><h1><?= $e($title) ?></h1><p><?= $e($message) ?></p></article>
<?php else: ?>
  <div class="lcp-actions"><button type="button" class="lcp-button" data-lcp-print>Imprimir</button></div>
  <article class="lcp-card">
    <p class="lcp-brand">SKC SuCasa Inmobiliaria</p>
    <h1><?= $e($view['subject']) ?></h1>
    <dl class="lcp-meta">
      <div><dt>Para</dt><dd><?= $e($view['name']) ?></dd></div>
      <div><dt>Referencia</dt><dd><?= $e($view['code']) ?></dd></div>
      <div><dt>Fecha</dt><dd><?= $e($date) ?></dd></div>
    </dl>
    <div class="lcp-letter"><?= $view['html'] ?></div>
    <?php if ($view['attachments'] !== []): ?>
      <section class="lcp-attachments"><h2>Documentos adjuntos</h2><ul>
        <?php foreach ($view['attachments'] as $file): ?>
          <li><button type="button" class="lcp-file" data-lcp-file="<?= $e($file['url']) ?>" data-lcp-name="<?= $e($file['name']) ?>" data-lcp-mime="<?= $e($file['mime']) ?>"><?= $e($file['name']) ?> <small><?= $e($size($file['bytes'])) ?></small></button></li>
        <?php endforeach; ?>
      </ul></section>
    <?php endif; ?>
  </article>
<?php endif; ?>
</main>
<dialog class="lcp-dialog" data-lcp-dialog><header><strong data-lcp-title>Documento</strong><span><a class="lcp-button" data-lcp-download download>Descargar</a> <button type="button" class="lcp-button" data-lcp-close>Cerrar</button></span></header><div data-lcp-body></div></dialog>
</body></html>
