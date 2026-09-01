<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\TicketCompletion\CompletionRepository;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$ticketPk = (int) ($_GET['ticket_pk'] ?? 0);
if ($ticketPk <= 0) {
  http_response_code(400);
  exit('Falta el identificador interno del ticket.');
}

$relativeTarget = 'crear-acta.php?ticket_pk=' . $ticketPk;
if (!Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget), true, 302);
  exit;
}

try {
  $repo = new CompletionRepository(App::db());
  $ticket = $repo->ticket($ticketPk);
  $app = new SuCasaControlServiciosInmobiliarios(App::db());
  $tab = $app->ticketCompletionDashboardTab($ticketPk);
  if ($tab === '') {
    http_response_code(403);
    exit('No tienes permiso para crear o consultar el acta de este ticket.');
  }
  $filter = $tab === 'abiertos' ? 'scm_caso' : 'scm_my_caso';
  $logicalTicket = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketPk;
  $query = http_build_query([
    'tab' => $tab,
    $filter => $logicalTicket,
    'scm_acta_ticket_pk' => $ticketPk,
  ], '', '&', PHP_QUERY_RFC3986);
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/index.php?' . $query, true, 302);
  exit;
} catch (DomainException $error) {
  http_response_code(404);
  exit(htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}
