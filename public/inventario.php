<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Modules\Inventory\InventoryPublicView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
// El registro fotográfico se muestra dentro de la página con la vista embebida de la carpeta de Drive.
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' https: data:; script-src 'self' 'unsafe-inline'; frame-src https://drive.google.com; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$inventoryId = (int) ($_GET['numero'] ?? $_GET['id'] ?? 0);
$expires = (int) ($_GET['expires'] ?? 0);
$signature = is_string($_GET['sig'] ?? null) ? trim($_GET['sig']) : '';
$result = [
  'title' => 'Inventario del inmueble',
  'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>No disponible</h1><p>No fue posible cargar el inventario.</p></article>',
  'status' => 500,
];

try {
  // Sin sesión del panel solo abre con el enlace firmado y vigente: el número solo no basta.
  if (!Auth::isLoggedIn() && !SuCasaControlServiciosInmobiliarios::inventoryPublicSignatureValid($inventoryId, $expires, $signature)) {
    $result = [
      'title' => 'Enlace no válido',
      'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>Enlace no válido o vencido</h1><p role="alert">Solicita a la inmobiliaria un nuevo enlace para consultar este inventario.</p></article>',
      'status' => 403,
    ];
    http_response_code(403);
  } else {
    $result = (new InventoryPublicView(App::db()))->render($inventoryId);
    http_response_code((int) ($result['status'] ?? 200));
  }
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[inventario-publico] Error inventario #' . $inventoryId . ': ' . $error->getMessage());
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape((string) ($result['title'] ?? 'Inventario del inmueble')) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/corrective-review-public.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/preventive-review.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/delivery-review.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/inventory-public.css?v=<?= $escape(SCM_VERSION) ?>">
  <script src="assets/js/corrective-review-public.js?v=<?= $escape(SCM_VERSION) ?>" defer></script>
  <script src="assets/js/delivery-review-public.js?v=<?= $escape(SCM_VERSION) ?>" defer></script>
</head>
<body class="scm-corrective-public-page scm-delivery-public-page scm-inventory-public-page">
  <main class="scm-corrective-public-shell">
    <?= (new InventoryPublicView(App::db()))->headerHtml() ?>
    <?= (string) ($result['content'] ?? '') ?>
  </main>
</body>
</html>
