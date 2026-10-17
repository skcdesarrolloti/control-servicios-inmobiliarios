<?php

declare(strict_types=1);

// Vista propia para crear o gestionar la revisión preventiva o correctiva de un caso, sin cargar el
// panel completo. Reutiliza el mismo editor y las mismas acciones AJAX que el popup del caso.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$kinds = [
  'preventiva' => ['label' => 'Revisión preventiva', 'can' => 'canAccessPreventiveReview', 'action' => 'preventive_review_manage'],
  'correctiva' => ['label' => 'Revisión correctiva', 'can' => 'canAccessCorrectiveReview', 'action' => 'corrective_review_manage'],
];
$kind = is_string($_GET['tipo'] ?? null) ? strtolower(trim($_GET['tipo'])) : '';
$ticketPk = is_string($_GET['ticket_pk'] ?? null) && ctype_digit($_GET['ticket_pk']) ? (int) $_GET['ticket_pk'] : 0;
if (!isset($kinds[$kind]) || $ticketPk <= 0) {
  http_response_code(400);
  exit('Enlace incompleto: usa tipo=preventiva o tipo=correctiva y el ticket_pk interno del caso.');
}

$relativeTarget = 'revision.php?' . http_build_query(['tipo' => $kind, 'ticket_pk' => $ticketPk], '', '&', PHP_QUERY_RFC3986);
$tryEmployeeTokenLogin = static function (): bool {
  if (Auth::isLoggedIn()) {
    return true;
  }
  $employeeId = trim((string) ($_GET['id_empleado'] ?? ''));
  $token = trim((string) ($_GET['token'] ?? ''));
  $secret = defined('SCM_ACTA_AUTOLOGIN_SECRET') ? trim((string) SCM_ACTA_AUTOLOGIN_SECRET) : '';
  if (!\SCM\Support\ActaAutologin::valid($secret, $employeeId, $token)) {
    return false;
  }
  return (new Auth(App::db()))->loginByEmployeeId($employeeId);
};
// El token nunca se queda en la barra de direcciones ni en el historial del navegador.
if (Auth::isLoggedIn() && (isset($_GET['token']) || isset($_GET['id_empleado']))) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
  exit;
}
if (!Auth::isLoggedIn() && $tryEmployeeTokenLogin()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
  exit;
}
if (!Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget), true, 302);
  exit;
}

$db = App::db();
$app = new SuCasaControlServiciosInmobiliarios($db);
$config = $kinds[$kind];
if (!$app->{$config['can']}($ticketPk)) {
  http_response_code(403);
  exit('No tienes permiso para gestionar la ' . mb_strtolower($config['label'], 'UTF-8') . ' de este caso.');
}
$ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$ticketPk]);
if (!$ticket) {
  http_response_code(404);
  exit('El caso no existe.');
}
$caseNumber = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketPk;
$property = trim((string) ($ticket['inmueble'] ?? ''));
$contract = trim((string) ($ticket['contrato'] ?? ''));
$baseUrl = rtrim((string) SCM_BASE_URL, '/');
$runtime = [
  'ajaxUrl' => $baseUrl . '/api.php',
  'nonce' => App::csrf()->token(SuCasaControlServiciosInmobiliarios::NONCE_KEY),
  'actions' => [
    'revision_preventiva' => SuCasaControlServiciosInmobiliarios::AJAX_PREVENTIVE_REVIEW,
    'revision_correctiva' => SuCasaControlServiciosInmobiliarios::AJAX_CORRECTIVE_REVIEW,
  ],
];
$asset = static function (string $path) use ($baseUrl): string {
  $file = __DIR__ . '/' . $path;
  return $baseUrl . '/' . $path . '?v=' . rawurlencode(SCM_VERSION . '-' . (is_file($file) ? (string) filemtime($file) : '0'));
};

echo (new \SCM\Views\ReviewPageView())->render([
  'kind' => $kind,
  'label' => $config['label'],
  'case' => $caseNumber,
  'contract' => $contract,
  'property' => $property,
  'address' => trim((string) ($ticket['direccion'] ?? '')),
  'user' => Auth::user(),
  'base_url' => $baseUrl,
  'logo' => system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL),
  'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
  'runtime' => $runtime,
  'ticket_pk' => $ticketPk,
  'draft_user' => (string) Auth::userId(),
  'assets' => [
    'drafts' => $asset('assets/js/ticket-completion-form-state.js'),
    'css' => array_map($asset, ['assets/css/tailwind-admin.css', 'assets/css/scm-admin.css', 'assets/css/preventive-review.css', 'assets/css/scm-damage-magnitude.css', 'assets/css/review-page.css']),
    'js' => array_map($asset, ['assets/js/scm-admin.js', 'assets/js/scm-damage-magnitude.js', 'assets/js/review-page.js']),
  ],
]);
