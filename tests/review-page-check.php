<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Views\ReviewPageView;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$data = [
  'kind' => 'preventiva', 'label' => 'Revisión preventiva', 'case' => '10915', 'contract' => '777', 'property' => '10532',
  'address' => 'DG 31 # 71 113', 'user' => 'Royner Guardo', 'base_url' => 'https://x.test/public', 'logo' => '', 'favicon' => '',
  'runtime' => ['ajaxUrl' => 'https://x.test/public/api.php', 'nonce' => 'abc', 'actions' => ['revision_preventiva' => 'scm_revision_preventiva', 'revision_correctiva' => 'scm_revision_correctiva']],
  'ticket_pk' => 10915, 'draft_user' => '7',
  'assets' => ['drafts' => 'ticket-completion-form-state.js', 'css' => ['review-page.css'], 'js' => ['scm-admin.js', 'review-page.js']],
];
$html = (new ReviewPageView())->render($data);
$check(str_contains($html, 'data-review-kind="preventiva"') && str_contains($html, 'data-ticket-pk="10915"'), 'La página identifica el caso y el tipo de revisión.');
$check(str_contains($html, 'id="scm-case-modal"') && str_contains($html, 'class="scm-case-dialog"') && str_contains($html, 'class="scm-wrap"'), 'El editor del panel encuentra su contenedor.');
$check(str_contains($html, 'data-scm-draft-user="7"') && strpos($html, 'ticket-completion-form-state.js') < strpos($html, 'scm-admin.js'), 'Los borradores se cargan antes del editor.');
$check(str_contains($html, '&quot;nonce&quot;:&quot;abc&quot;') && str_contains($html, 'scm_revision_preventiva'), 'El runtime trae nonce y acciones de revisión.');
$check(str_contains($html, 'Hola, <strong>Royner</strong>') && str_contains($html, 'Revisa el inmueble') && str_contains($html, 'Ir al panel'), 'La página saluda y explica los pasos.');
$check(!str_contains($html, '<script>alert') && str_contains((new ReviewPageView())->render(['user' => '<b>x</b>'] + $data), '&lt;b&gt;x&lt;/b&gt;'), 'Los datos se escapan.');
$corrective = (new ReviewPageView())->render(['kind' => 'correctiva', 'label' => 'Revisión correctiva'] + $data);
$check(str_contains($corrective, 'is-correctiva') && str_contains($corrective, 'Diagnostica el daño'), 'La correctiva tiene sus propios pasos.');

$root = dirname(__DIR__);
$bridge = (string) file_get_contents($root . '/public/puente-funcionario.php');
$check(str_contains($bridge, "'revision.php?'") && str_contains($bridge, "'revision_preventiva', 'revision_correctiva'"), 'El puente lleva las revisiones a su vista propia.');
$login = (string) file_get_contents($root . '/public/login.php');
$check(str_contains($login, "\$path === 'revision.php'"), 'Después del login se vuelve a la vista de revisión.');
$page = (string) file_get_contents($root . '/public/revision.php');
$check(str_contains($page, 'ActaAutologin::valid($secret, $employeeId, $token)') && str_contains($page, "\$app->{\$config['can']}(\$ticketPk)"), 'La vista exige sesión o autologin firmado y permiso sobre el caso.');
$admin = (string) file_get_contents($root . '/public/assets/js/scm-admin.js');
$check(str_contains($admin, 'openDamageReviewEditor: openDamageReviewEditor,'), 'El editor de revisiones está disponible para la vista propia.');

// Página propia de cotizaciones y órdenes.
$quoteHtml = (new \SCM\Views\QuotePageView())->render([
  'action' => 'orden', 'record_id' => 577, 'case' => '10203', 'quote' => '577', 'address' => 'CR 49', 'user' => 'Royner Guardo',
  'base_url' => '', 'logo' => '', 'favicon' => '', 'panel_html' => '<div id="scm-app"></div>', 'css' => [], 'js' => ['quote-page.js'],
]);
$check(str_contains($quoteHtml, 'data-bridge-action="crear_orden"') && str_contains($quoteHtml, 'data-record-param="id_cotizacion"') && str_contains($quoteHtml, 'data-record-id="577"'), 'La página de orden sabe qué abrir.');
$check(str_contains($quoteHtml, 'class="qp-engine"') && str_contains($quoteHtml, 'Se cerró el formulario') && str_contains($quoteHtml, 'Abrir de nuevo'), 'El panel queda oculto y hay tarjeta al cerrar.');
foreach (['crear' => 'crear_cotizacion', 'editar' => 'editar_cotizacion', 'enviar' => 'enviar_cotizacion', 'orden' => 'crear_orden'] as $pageAction => $bridgeAction) {
  $check(\SCM\Views\QuotePageView::ACTIONS[$pageAction][5] === $bridgeAction, 'Acción mal conectada: ' . $pageAction);
}
$check(str_contains($bridge, "'cotizacion.php?'") && str_contains($bridge, "'crear_orden' => 'orden'"), 'El puente lleva las cotizaciones a su página propia.');
$check(str_contains($login, "\$path === 'cotizacion.php'"), 'Después del login se vuelve a la página de cotización.');
$quotePage = (string) file_get_contents($root . '/public/cotizacion.php');
$check(str_contains($quotePage, 'ActaAutologin::valid($secret, $employeeId, $token)') && str_contains($quotePage, 'canOpenQuoteFunction($action)'), 'La página de cotización exige sesión y permiso del cargo.');
$runtime = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$check(str_contains($runtime, 'window.SCMFuncionarioBridge = {') && str_contains($runtime, 'bridgeFailureHandler(message'), 'El runtime abre la acción y entrega el error a la página.');

echo "Review page checks passed.\n";
