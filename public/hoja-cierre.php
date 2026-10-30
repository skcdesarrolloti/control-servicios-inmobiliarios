<?php

declare(strict_types=1);

// Hoja de cierre de negocio nativa: reemplaza los formularios JetForm "Añadir / Editar hoja de cierre".
// Añadir: hoja-cierre.php?id_contrato=<mandato>&id_arrendatario=…&id_inmueble=…&id_inmueble_data=…&id_propietario=…&id_copropiedad=…&id_estudio_aseguradora=…
//         (los mismos parámetros del enlace de WordPress). Editar: hoja-cierre.php?id_cierre=<hoja>.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetPageView;
use SCM\Modules\ClosingSheets\ClosingSheetService;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$digits = static fn($value): string => is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', trim($value)) === 1 ? trim($value) : '';
$closingId = (int) $digits($_GET['id_cierre'] ?? '');
$query = [];
foreach (ClosingSheetService::CONTEXT_IDS as $key) {
  $query[$key] = $digits($_GET[$key] ?? '');
}
$relativeTarget = 'hoja-cierre.php?' . http_build_query($closingId > 0 ? ['id_cierre' => $closingId] : array_filter($query), '', '&', PHP_QUERY_RFC3986);

// Desde WordPress el botón puede traer id_empleado + token (como cotizacion.php): inicia sesión con ese funcionario.
$tryEmployeeTokenLogin = static function (): bool {
  $employeeId = trim((string) ($_GET['id_empleado'] ?? ''));
  $token = trim((string) ($_GET['token'] ?? ''));
  $secret = defined('SCM_ACTA_AUTOLOGIN_SECRET') ? trim((string) SCM_ACTA_AUTOLOGIN_SECRET) : '';
  if (!\SCM\Support\ActaAutologin::valid($secret, $employeeId, $token)) {
    return false;
  }
  return (new Auth(App::db()))->loginByEmployeeId($employeeId);
};
// El token nunca se queda en la barra de direcciones ni en el historial del navegador.
if (isset($_GET['token']) && (Auth::isLoggedIn() || $tryEmployeeTokenLogin())) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
  exit;
}
if (!Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget), true, 302);
  exit;
}
if ($closingId <= 0 && $query['id_contrato'] === '') {
  http_response_code(400);
  exit('Enlace incompleto: abre la hoja desde el botón "Añadir cierre" del mandato (falta id_contrato) o usa id_cierre para editar.');
}

$db = App::db();
$app = new SuCasaControlServiciosInmobiliarios($db);
if (!$app->canOpenClosingSheet($closingId <= 0)) {
  http_response_code(403);
  exit('Tu cargo no tiene permiso para ' . ($closingId > 0 ? 'editar' : 'añadir') . ' hojas de cierre.');
}

$service = new ClosingSheetService($db);
try {
  $context = $service->context($query, $closingId);
} catch (\DomainException $error) {
  http_response_code(404);
  exit($error->getMessage());
}

$baseUrl = rtrim((string) SCM_BASE_URL, '/');
$asset = static function (string $path) use ($baseUrl): string {
  $file = __DIR__ . '/' . $path;
  return $baseUrl . '/' . $path . '?v=' . rawurlencode(SCM_VERSION . '-' . (is_file($file) ? (string) filemtime($file) : '0'));
};

echo (new ClosingSheetPageView())->render($context, $service->options(), [
  'user' => Auth::user(),
  'base_url' => $baseUrl,
  'logo' => system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL),
  'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
  'nonce' => App::csrf()->token('scm_nonce'),
  'action' => SuCasaControlServiciosInmobiliarios::AJAX_CLOSING_SHEET_SAVE,
  'css' => array_map($asset, ['assets/css/review-page.css', 'assets/css/closing-sheet.css']),
  'js' => array_map($asset, ['assets/js/closing-sheet.js']),
]);
