<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Modules\TicketCompletion\CompletionView;
use SCM\Support\SchemaInspector;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$ticketPk = (int) ($_GET['ticket_pk'] ?? $_GET['_ID'] ?? $_GET['id'] ?? 0);
$quotePk = (int) ($_GET['id_cotizacion'] ?? $_GET['cotizacion_id'] ?? 0);
if ($ticketPk <= 0 && $quotePk > 0) {
  try {
    $dbForQuote = App::db();
    $schemaForQuote = new SchemaInspector($dbForQuote);
    $quoteTable = $dbForQuote->table('jet_cct_cotizacion_mantenimiento');
    if ($schemaForQuote->tableExists($quoteTable) && $schemaForQuote->columnExists($quoteTable, 'id_ticket')) {
      $quoteRow = $dbForQuote->getRow("SELECT `id_ticket` FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$quotePk]);
      $quoteTicket = trim((string) ($quoteRow['id_ticket'] ?? ''));
      if ($quoteTicket !== '') {
        $ticketTable = $dbForQuote->table('jet_cct_tickets');
        $ticketRow = $dbForQuote->getRow("SELECT `_ID` FROM `{$ticketTable}` WHERE `_ID` = ? OR `id_ticket` = ? ORDER BY `_ID` = ? DESC LIMIT 1", [(int) $quoteTicket, $quoteTicket, (int) $quoteTicket]);
        $ticketPk = (int) ($ticketRow['_ID'] ?? 0);
      }
    }
  } catch (Throwable) {
    $ticketPk = 0;
  }
}
if ($ticketPk <= 0) {
  http_response_code(400);
  exit('Falta el identificador interno del caso. Usa crear-acta.php?ticket_pk=_ID o crear-acta.php?id_cotizacion=_ID');
}
$editActId = (int) ($_GET['act_id'] ?? 0);

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

$targetQuery = ['ticket_pk' => $ticketPk];
if ($quotePk > 0) {
  $targetQuery['id_cotizacion'] = $quotePk;
  $targetQuery['source_flow'] = 'approved_quote';
}
if ($editActId > 0) {
  $targetQuery['act_id'] = $editActId;
}
$relativeTarget = 'crear-acta.php?' . http_build_query($targetQuery, '', '&', PHP_QUERY_RFC3986);
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

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

try {
  $db = App::db();
  $repo = new CompletionRepository($db);
  $repo->requireSchema();
  $service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
  $sourceFlow = $quotePk > 0 ? ['flow' => 'approved_quote', 'quote_id' => (string) $quotePk] : [];
  $context = $service->context($ticketPk, $sourceFlow);
  $ticket = $context['ticket'];
  $app = new SuCasaControlServiciosInmobiliarios($db);
  if (!$app->canAccessTicketCompletion($ticketPk)) {
    http_response_code(403);
    exit('No tienes permiso para crear o consultar el acta de este caso.');
  }
  if ($editActId > 0) {
    $editAct = $repo->act($editActId);
    if ((int) $editAct['ticket_pk'] !== $ticketPk || !in_array($editAct['status'], ['pending', 'signed'], true)) {
      throw new DomainException('Solo se puede editar un acta pendiente o firmada de este caso.');
    }
  }
  $caseNumber = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketPk;
  $property = trim((string) ($ticket['inmueble'] ?? ''));
  $redirectUrl = $service->dashboardUrlForTicket($ticket, 'pending');
  $csrf = App::csrf()->token(SuCasaControlServiciosInmobiliarios::NONCE_KEY);
  $panel = (new CompletionView(fn(int $index, array $item): string => $app->correctiveReviewActaItem($index, $item)))->panel($context, $service, $editActId, false);
} catch (DomainException $error) {
  http_response_code(404);
  exit($escape($error->getMessage()));
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $editActId > 0 ? 'Editar acta' : 'Crear acta' ?> · Caso #<?= $escape($caseNumber) ?> · SKC SuCasa Inmobiliaria</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="assets/css/ticket-completion.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/review-page.css?v=<?= $escape(SCM_VERSION . '-' . (string) @filemtime(__DIR__ . '/assets/css/review-page.css')) ?>">
  <script defer src="assets/js/scm-photo-compress.js?v=<?= $escape(SCM_VERSION . '-' . (string) @filemtime(__DIR__ . '/assets/js/scm-photo-compress.js')) ?>"></script>
  <script defer src="assets/js/ticket-completion-form-state.js?v=<?= $escape(SCM_VERSION) ?>"></script>
  <script defer src="assets/js/ticket-completion-create.js?v=<?= $escape(SCM_VERSION) ?>"></script>
</head>
<?php
$userName = (string) Auth::user();
$firstName = trim((string) strtok(trim($userName), ' ')) ?: $userName;
$address = trim((string) ($ticket['direccion'] ?? ''));
$actaSteps = [
  ['build', 'Describe la solución', 'Qué se hizo y quién lo hizo.'],
  ['draw', 'Elige quién firma', 'Propietario, arrendatario u otra persona.'],
  ['send', 'Genera el acta', 'Se envía para firma por correo y WhatsApp.'],
];
?>
<body class="scm-acta-page scm-acta-create-body scm-review-page scm-acta-create-modern">
  <header class="rp-topbar">
    <a class="rp-brand" href="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/index.php') ?>">
      <img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SKC SuCasa Inmobiliaria">
      <span>SKC SuCasa Inmobiliaria</span>
    </a>
    <div class="rp-user">
      <span class="rp-avatar" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr($firstName, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
      <span class="rp-user-name">Hola, <strong><?= $escape($firstName) ?></strong></span>
      <a class="rp-link-button" href="<?= $escape($redirectUrl) ?>"><span class="material-symbols-outlined" aria-hidden="true">fact_check</span><span>Ver actas</span></a>
    </div>
  </header>

  <main
    class="scm-acta-create-shell scm-review-page-shell"
    data-acta-create-page
    data-ticket-pk="<?= $ticketPk ?>"
    data-action="<?= $escape(SuCasaControlServiciosInmobiliarios::AJAX_TICKET_COMPLETION) ?>"
    data-nonce="<?= $escape($csrf) ?>"
    data-api-url="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/api.php') ?>"
    data-nonce-url="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/acta-nonce.php') ?>"
    data-login-url="<?= $escape(rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget)) ?>"
    data-redirect-url="<?= $escape($redirectUrl) ?>"
  >
    <section class="rp-hero">
      <span class="rp-hero-icon material-symbols-outlined" aria-hidden="true">assignment_turned_in</span>
      <div class="rp-hero-text">
        <p class="rp-eyebrow"><?= $quotePk > 0 ? 'Acta de satisfacción de una cotización aprobada' : 'Acta de solución del caso' ?></p>
        <h1><?= $editActId > 0 ? 'Editar acta de solución y satisfacción' : 'Crear acta de solución y satisfacción' ?></h1>
        <dl class="rp-facts">
          <div><dt>Caso</dt><dd>#<?= $escape($caseNumber) ?></dd></div>
          <?php if ($property !== ''): ?><div><dt>Inmueble</dt><dd><?= $escape($property) ?></dd></div><?php endif; ?>
          <?php if ($address !== ''): ?><div><dt>Dirección</dt><dd><?= $escape($address) ?></dd></div><?php endif; ?>
          <div><dt>Elaborando como</dt><dd><?= $escape($userName) ?></dd></div>
        </dl>
      </div>
    </section>

    <ol class="rp-steps" aria-label="Pasos">
      <?php foreach ($actaSteps as $index => [$stepIcon, $stepTitle, $stepText]): ?>
        <li><span class="rp-step-number"><?= $index + 1 ?></span><span class="material-symbols-outlined" aria-hidden="true"><?= $escape($stepIcon) ?></span><div><strong><?= $escape($stepTitle) ?></strong><small><?= $escape($stepText) ?></small></div></li>
      <?php endforeach; ?>
    </ol>

    <section class="rp-card rp-acta-card">
      <?= $panel ?>
    </section>

    <p class="rp-footnote"><span class="material-symbols-outlined" aria-hidden="true">info</span><?= $editActId > 0 ? 'Al guardar los cambios se invalidan los códigos anteriores y se envía una nueva invitación de firma.' : 'El caso sigue abierto hasta que la persona firme. Lo que escribas queda como borrador en este equipo.' ?></p>
  </main>
</body>
</html>
