<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Modules\TicketCompletion\CompletionView;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$ticketPk = (int) ($_GET['ticket_pk'] ?? $_GET['_ID'] ?? $_GET['id'] ?? 0);
if ($ticketPk <= 0) {
  http_response_code(400);
  exit('Falta el identificador interno del caso. Usa crear-acta.php?ticket_pk=_ID');
}

$relativeTarget = 'crear-acta.php?ticket_pk=' . $ticketPk;
if (!Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget), true, 302);
  exit;
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

try {
  $db = App::db();
  $repo = new CompletionRepository($db);
  $repo->requireSchema();
  $service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
  $context = $service->context($ticketPk);
  $ticket = $context['ticket'];
  $app = new SuCasaControlServiciosInmobiliarios($db);
  if (!$app->canAccessTicketCompletion($ticketPk)) {
    http_response_code(403);
    exit('No tienes permiso para crear o consultar el acta de este caso.');
  }
  $caseNumber = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketPk;
  $property = trim((string) ($ticket['inmueble'] ?? ''));
  $redirectUrl = $service->dashboardUrlForTicket($ticket, 'pending');
  $csrf = App::csrf()->token(SuCasaControlServiciosInmobiliarios::NONCE_KEY);
  $panel = (new CompletionView())->panel($context, $service);
} catch (DomainException $error) {
  http_response_code(404);
  exit($escape($error->getMessage()));
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Crear acta de satisfacción · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="assets/css/ticket-completion.css?v=<?= $escape(SCM_VERSION) ?>">
  <script defer src="assets/js/ticket-completion-create.js?v=<?= $escape(SCM_VERSION) ?>"></script>
</head>
<body class="scm-acta-page scm-acta-create-body">
  <div class="scm-acta-create-top">
    <a class="scm-acta-create-brand" href="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/index.php') ?>">
      <span class="scm-acta-logo"><img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SuCasa Inmobiliaria"></span>
      <span>Control Servicios Inmobiliarios</span>
    </a>
    <div class="scm-acta-create-session">
      <span>Elaborando como: <strong><?= $escape(Auth::user()) ?></strong></span>
      <a href="<?= $escape($redirectUrl) ?>">Ver actas de satisfacción</a>
    </div>
  </div>

  <main
    class="scm-acta-create-shell"
    data-acta-create-page
    data-ticket-pk="<?= $ticketPk ?>"
    data-action="<?= $escape(SuCasaControlServiciosInmobiliarios::AJAX_TICKET_COMPLETION) ?>"
    data-nonce="<?= $escape($csrf) ?>"
    data-api-url="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/api.php') ?>"
    data-redirect-url="<?= $escape($redirectUrl) ?>"
  >
    <header class="scm-acta scm-acta-create-hero">
      <p class="scm-acta-eyebrow">Creación directa autenticada</p>
      <h1>Crear acta de solución y satisfacción</h1>
      <p>Caso <strong>#<?= $escape($caseNumber) ?></strong><?= $property !== '' ? ' · Inmueble <strong>' . $escape($property) . '</strong>' : '' ?>. Al generar el acta se enviará a la bandeja de <strong>Actas de satisfacción</strong> para seguimiento y firma.</p>
    </header>
    <?= $panel ?>
  </main>
</body>
</html>
