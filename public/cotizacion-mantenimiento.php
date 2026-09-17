<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' https: data:; script-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$quoteId = (int) ($_GET['numero'] ?? $_GET['id_cotizacion'] ?? $_GET['id'] ?? 0);
$expires = (int) ($_GET['expires'] ?? 0);
$signature = is_string($_GET['sig'] ?? null) ? trim($_GET['sig']) : '';
$result = [
  'title' => 'Cotización de mantenimiento',
  'content' => '<article class="scm-cotizacion-native-doc"><section class="scm-cotizacion-native-section"><h2>No disponible</h2><p>No fue posible cargar la cotización.</p></section></article>',
  'status' => 500,
];

try {
  $hasPanelSession = Auth::isLoggedIn();
  $hasValidPublicSignature = SuCasaControlServiciosInmobiliarios::maintenanceQuotePublicSignatureValid($quoteId, $expires, $signature);
  if (!$hasPanelSession && !$hasValidPublicSignature) {
    $result = [
      'title' => 'Enlace no válido',
      'content' => '<article class="scm-cotizacion-native-doc"><section class="scm-cotizacion-native-section"><h2>Enlace no válido o vencido</h2><p role="alert">Solicita a la inmobiliaria un nuevo enlace para consultar esta cotización de mantenimiento.</p></section></article>',
      'status' => 403,
    ];
    http_response_code(403);
  } else {
    $app = new SuCasaControlServiciosInmobiliarios(App::db());
    $result = $app->render_public_cotizacion_mantenimiento($quoteId);
    http_response_code((int) ($result['status'] ?? 200));
  }
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[cotizacion-mantenimiento-publica] Error cotización #' . $quoteId . ': ' . $error->getMessage());
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape((string) ($result['title'] ?? 'Cotización de mantenimiento')) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/admin/04-dashboard-pending.css?v=<?= $escape(SCM_VERSION) ?>">
  <style>
    body{margin:0;background:#eef4fb;color:#0b1f3a;font-family:Poppins,Arial,sans-serif}
    #scm-app{max-width:1120px;margin:0 auto;padding:24px 14px 40px}
    .scm-public-quote-head{display:flex;align-items:center;gap:14px;margin:0 0 18px;padding:14px 18px;background:#fff;border:1px solid #d8e6f5;border-radius:18px;box-shadow:0 14px 35px rgba(15,23,42,.08)}
    .scm-public-quote-logo{display:flex;align-items:center;justify-content:center;width:190px;max-width:45vw;min-height:58px;background:#0b254f;border-radius:14px;padding:8px 14px}
    .scm-public-quote-logo img{display:block;max-width:100%;max-height:48px;object-fit:contain}
    .scm-public-quote-head p{margin:0;color:#64748b;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    .scm-public-quote-head strong{display:block;font-size:22px;color:#0b1f3a}
    @media print{body{background:#fff}#scm-app{max-width:none;padding:0}.scm-public-quote-head{box-shadow:none;border:0;margin-bottom:8px}.scm-cotizacion-native-audience{display:none!important}}
  </style>
</head>
<body>
  <main id="scm-app">
    <header class="scm-public-quote-head">
      <span class="scm-public-quote-logo"><img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SKC SuCasa Inmobiliaria"></span>
      <div>
        <p>SKC SuCasa Inmobiliaria</p>
        <strong><?= $escape((string) ($result['title'] ?? 'Cotización de mantenimiento')) ?></strong>
      </div>
    </header>
    <?= (string) ($result['content'] ?? '') ?>
  </main>
</body>
</html>
