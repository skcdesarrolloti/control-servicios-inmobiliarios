<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Support\SchemaInspector;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$clean = static fn(string $key, string $fallback = ''): string => trim((string) ($_GET[$key] ?? $fallback));
$numeric = static function (string $value): int {
  return preg_match('/^\d+$/D', $value) ? (int) $value : 0;
};

$normalizeAction = static function (string $action): string {
  $action = strtolower(trim(strtr($action, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', '-' => '_', ' ' => '_'])));
  return match ($action) {
    'revision_correctiva', 'crear_revision_correctiva', 'gestionar_revision_correctiva', 'correctiva' => 'revision_correctiva',
    'crear_cotizacion', 'cotizacion_crear', 'nueva_cotizacion', 'anadir_cotizacion', 'añadir_cotizacion' => 'crear_cotizacion',
    'editar_cotizacion', 'cotizacion_editar' => 'editar_cotizacion',
    'enviar_cotizacion', 'cotizacion_enviar' => 'enviar_cotizacion',
    'crear_orden', 'anadir_orden', 'añadir_orden', 'orden_crear', 'crear_orden_mantenimiento' => 'crear_orden',
    'acta', 'acta_satisfaccion', 'crear_acta', 'acta_solucion' => 'acta_satisfaccion',
    default => '',
  };
};

$action = $normalizeAction($clean('accion', $clean('action')));
if ($action === '') {
  http_response_code(400);
  exit('Acción no válida. Usa accion=revision_correctiva, crear_cotizacion, editar_cotizacion, enviar_cotizacion, crear_orden o acta_satisfaccion.');
}

$ticketPk = $numeric($clean('ticket_pk', $clean('_ID', $clean('id'))));
$quotePk = $numeric($clean('id_cotizacion', $clean('cotizacion_id')));

try {
  $db = App::db();
  $schema = new SchemaInspector($db);
  if ($quotePk > 0 && $ticketPk <= 0) {
    $quoteTable = $db->table('jet_cct_cotizacion_mantenimiento');
    if ($schema->tableExists($quoteTable) && $schema->columnExists($quoteTable, 'id_ticket')) {
      $quoteRow = $db->getRow("SELECT `id_ticket` FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$quotePk]);
      $quoteTicket = trim((string) ($quoteRow['id_ticket'] ?? ''));
      if ($quoteTicket !== '') {
        $ticketTable = $db->table('jet_cct_tickets');
        $ticketRow = $db->getRow("SELECT `_ID` FROM `{$ticketTable}` WHERE `_ID` = ? OR `id_ticket` = ? ORDER BY `_ID` = ? DESC LIMIT 1", [(int) $quoteTicket, $quoteTicket, (int) $quoteTicket]);
        $ticketPk = (int) ($ticketRow['_ID'] ?? 0);
      }
    }
  }
} catch (Throwable) {
  $ticketPk = $ticketPk > 0 ? $ticketPk : 0;
}

$quoteActions = ['editar_cotizacion', 'enviar_cotizacion', 'crear_orden'];
if (in_array($action, $quoteActions, true) && $quotePk <= 0) {
  http_response_code(400);
  exit('Falta id_cotizacion para esta acción.');
}
if (in_array($action, ['revision_correctiva', 'crear_cotizacion', 'acta_satisfaccion'], true) && $ticketPk <= 0) {
  http_response_code(400);
  exit('Falta ticket_pk interno del caso para esta acción.');
}

$tryEmployeeTokenLogin = static function (): bool {
  if (Auth::isLoggedIn()) {
    return true;
  }

  $employeeId = trim((string) ($_GET['id_empleado'] ?? ''));
  $token = trim((string) ($_GET['token'] ?? ''));
  $secret = defined('SCM_ACTA_AUTOLOGIN_SECRET') ? trim((string) SCM_ACTA_AUTOLOGIN_SECRET) : '';

  if ($secret === '' || $employeeId === '' || $token === '' || !hash_equals($secret, $token)) {
    return false;
  }

  return (new Auth(App::db()))->loginByEmployeeId($employeeId);
};

$targetQuery = [
  'scm_bridge_action' => $action,
];
if ($ticketPk > 0) {
  $targetQuery['scm_bridge_ticket_pk'] = $ticketPk;
}
if ($quotePk > 0) {
  $targetQuery['scm_bridge_quote_id'] = $quotePk;
}

if (in_array($action, $quoteActions, true)) {
  $targetQuery['scm_tab'] = 'cotizaciones_mantenimiento';
  $targetQuery['scmqt_cotizacion'] = $quotePk;
} elseif ($action === 'crear_cotizacion') {
  $targetQuery['scm_tab'] = 'cotizaciones_mantenimiento';
} else {
  $targetQuery['scm_tab'] = 'abiertos';
}

$relativeTarget = 'index.php?' . http_build_query($targetQuery, '', '&', PHP_QUERY_RFC3986);
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

$app = new SuCasaControlServiciosInmobiliarios(App::db());
if ($action === 'revision_correctiva' && !$app->canAccessCorrectiveReview($ticketPk)) {
  http_response_code(403);
  exit('No tienes permiso para gestionar la revisión correctiva de este caso.');
}
if ($action === 'acta_satisfaccion' && !$app->canAccessTicketCompletion($ticketPk)) {
  http_response_code(403);
  exit('No tienes permiso para gestionar el acta de este caso.');
}

header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
exit;
