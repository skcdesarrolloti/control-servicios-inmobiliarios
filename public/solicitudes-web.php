<?php

require_once dirname(__DIR__) . '/bootstrap/app.php';

$accessToken = trim((string) ($_GET['k'] ?? $_GET['access_token'] ?? ''));
$employeeId = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($_GET['id_empleado'] ?? '')));
$accessScope = mb_strtolower(trim((string) ($_GET['scope'] ?? 'assigned')), 'UTF-8');
$accessScope = $accessScope === 'all' ? 'all' : 'assigned';
$expiresAt = trim((string) ($_GET['exp'] ?? ''));
$signature = trim((string) ($_GET['sig'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accessToken = trim((string) ($_POST['access_token'] ?? $accessToken));
  $employeeId = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($_POST['id_empleado'] ?? $employeeId)));
  $accessScope = mb_strtolower(trim((string) ($_POST['scope'] ?? $accessScope)), 'UTF-8');
  $accessScope = $accessScope === 'all' ? 'all' : 'assigned';
  $expiresAt = trim((string) ($_POST['exp'] ?? $expiresAt));
  $signature = trim((string) ($_POST['sig'] ?? $signature));
}

$service = new \SCM\Modules\PublicTickets\PublicTicketsService($scmDb, $scmConfig);
$resolvedAccess = null;
if ($accessToken !== '') {
  $resolvedAccess = $service->resolveResponsiblePublicPqrAccessToken($accessToken);
  if (is_array($resolvedAccess)) {
    $employeeId = (string) ($resolvedAccess['id_empleado'] ?? $employeeId);
    $accessScope = (string) ($resolvedAccess['scope'] ?? $accessScope);
    $expiresAt = (string) ($resolvedAccess['exp'] ?? $expiresAt);
    $signature = (string) ($resolvedAccess['sig'] ?? $signature);
  }
}

$hasValidAccess = is_array($resolvedAccess)
  || (is_string($employeeId) && $service->validateResponsiblePublicPqrAccess($employeeId, $expiresAt, $signature, $accessScope));

if (!$hasValidAccess) {
  http_response_code(403);
?>
  <!DOCTYPE html>
  <html lang="es">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso no valido</title>
    <style>
      body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
        font-family: Arial, sans-serif;
        background: #f3f7fc;
        color: #17304d;
      }

      .card {
        max-width: 520px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 18px 45px rgba(18, 46, 76, .12);
        padding: 24px;
      }

      h1 {
        margin: 0 0 10px;
        font-size: 24px;
      }

      p {
        margin: 0;
        color: #4c6077;
        line-height: 1.5;
      }
    </style>
  </head>

  <body>
    <section class="card">
      <h1>Acceso no valido</h1>
      <p>El enlace de solicitudes web no es valido o ya vencio. Solicita uno nuevo desde la notificacion mas reciente.</p>
    </section>
  </body>

  </html>
<?php
  exit;
}

if (in_array($employeeId, \SCM\Modules\PublicTickets\PublicTicketsService::getNotifResponsables(), true)) {
  $accessScope = 'all';
}

$app = new \SCM\App\SuCasaControlServiciosInmobiliarios($scmDb);
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $publicAction = trim((string) ($_POST['public_action'] ?? ''));
    if ($publicAction === 'asignar_pqr_publico') {
      $result = $app->assign_public_pqr(
        (int) ($_POST['ticket_pk'] ?? 0),
        (string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? ''),
        (string) ($_POST['new_empleado_id'] ?? ''),
        $employeeId,
        $accessScope,
        (string) ($_POST['departamento'] ?? '')
      );
      $flashMessage = trim((string) ($result['message'] ?? 'PQR actualizado correctamente.'));
    }
  } catch (\Throwable $e) {
    $flashMessage = $e->getMessage();
    $flashType = 'error';
  }
}

echo $app->renderPublicPqrSignedAccessPage($employeeId, $expiresAt, $signature, [
  'ticket_url' => (string) ($scmConfig['ticket_url'] ?? \SCM\App\SuCasaControlServiciosInmobiliarios::DEFAULT_TICKET_URL),
], $flashMessage, $flashType, $accessScope, $accessToken);
