<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
  if (str_starts_with($class, 'SCM\\')) {
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require_once $file;
  }
});

use SCM\Modules\AdministrativeReports\AdministrativeReportsService as Service;
use SCM\Modules\AdministrativeReports\AdministrativeReportsView as View;
use SCM\Modules\AdministrativeReports\BillingAccountDocument;

$app = (string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
$handlers = (string) file_get_contents($root . '/src/App/Concerns/HandlesAdministrativeReports.php');
$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$runtime = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$script = (string) file_get_contents($root . '/public/assets/js/admin-administrative-reports.js');
$tailwind = (string) file_get_contents($root . '/tailwind.services.config.js');
$service = (string) file_get_contents($root . '/src/Modules/AdministrativeReports/AdministrativeReportsService.php');

$view = new View();
$item = Service::present([
  '_ID' => '15', 'fecha' => '1735689600', 'categoria' => 'Mantenimiento correctivo', 'valor_mantenimiento' => '0', 'valor_revision' => '30933',
  'valor_otro' => '', 'transporte' => '8000', 'valor' => '38933', 'id_ticket' => '9665', 'contrato' => '791', 'inmueble' => '10635',
  'arrendatario' => 'Ana <b>Pérez</b>', 'descripcion' => "Evidencia <a href='https://sucasainmobiliaria.com.co/x?id=1' target='_blank'>Ver reporte</a>",
  'id_empleado' => '42', 'exportado' => 'No', 'fue_pagado' => 'No',
]);
$own = ['items' => [$item], 'summary' => Service::summarize([$item]), 'counts' => ['no_exportados' => 1, 'en_cobro' => 0, 'pagados' => 0]];
$ownHtml = $view->ownResults($own, 'no_exportados', true, [], true);
$readOnlyHtml = $view->ownResults($own, 'no_exportados', false, [], true);
$account = [
  'id' => 7, 'numero' => 'CC-000007', 'id_empleado' => '42', 'funcionario' => 'Ana Funcionaria', 'documento' => '123', 'fecha_creacion' => '2026-10-20 10:00:00',
  'fecha' => '2026-10-20', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'servicio' => 30933, 'transporte' => 8000, 'total' => 38933,
  'cantidad' => 1, 'pagada' => false, 'report_ids' => [15], 'reportes' => [$item],
];
$excel = BillingAccountDocument::excel($account, ['nombre' => 'Ana Funcionaria', 'documento' => '123', 'correo' => 'ana@example.com']);
$panel = $view->panel(['mis_reportes', 'cuentas'], '<div id="sra_panel"></div>', [], '42', ['manage' => false]);

$checks = [
  'service value falls back to the first column with money' => $item['servicio'] === 30933 && $item['total'] === 38933,
  'service column follows the category' => Service::serviceColumn('Mantenimiento') === 'valor_mantenimiento' && Service::serviceColumn('Otro cobro') === 'valor_otro' && Service::serviceColumn('Revision preventiva') === 'valor_revision',
  'money parsing ignores separators' => Service::money('$1.200.000') === 1200000 && Service::money('') === 0 && Service::money('30933.00') === 30933 && Service::money('1,200,000') === 1200000,
  'report ids parse JSON and legacy text' => Service::parseIds('["12","13",12]') === [12, 13] && Service::parseIds('12, 15 y 18') === [12, 15, 18],
  'summary counts services and transports' => Service::summarize([$item])['servicio_count'] === 1 && Service::summarize([$item])['transporte'] === 8000,
  'own list escapes HTML and keeps evidence links in the panel viewer' => str_contains($ownHtml, 'Ana &lt;b&gt;Pérez&lt;/b&gt;') && str_contains($ownHtml, 'data-ar-open-url="https://sucasainmobiliaria.com.co/x?id=1"') && !str_contains($ownHtml, 'target='),
  'own list offers cuenta de cobro and value editing only when allowed' => str_contains($ownHtml, 'data-ar-create-account') && str_contains($ownHtml, 'data-ar-save-values') && !str_contains($readOnlyHtml, 'data-ar-save-values'),
  'cases open inside the panel' => str_contains($ownHtml, 'data-ar-ticket="9665"'),
  'panel shows only permitted tabs' => str_contains($panel, 'data-ar-tab="mis_reportes"') && str_contains($panel, 'data-ar-tab="cuentas"') && !str_contains($panel, 'data-ar-tab="pendientes"') && !str_contains($panel, 'data-ar-tab="consolidado"'),
  'panel without tabs explains the missing permission' => str_contains($view->panel([], '', [], '42', []), 'Permisos'),
  'excel neutralizes formulas and includes totals' => str_contains($excel, 'TOTALES') && str_contains($excel, '$38.933') && str_contains($excel, 'ana@example.com'),
  'cuenta PDF uses the corporate name and legal entity' => str_contains(BillingAccountDocument::html($account, ['nombre' => 'Ana Funcionaria', 'documento' => '123']), 'SKC SuCasa Inmobiliaria')
    && str_contains(BillingAccountDocument::html($account, []), Service::COMPANY_NIT),
  'cuenta PDF avoids cutting rows and signature when printing' => str_contains(BillingAccountDocument::html($account, []), 'page-break-inside:avoid'),
  'permission catalog lists the new actions' => str_contains($app, "'admin_report_own'") && str_contains($app, "'admin_report_pending_view'") && str_contains($app, "'admin_report_payments'") && str_contains($app, "'admin_report_accounts'") && str_contains($app, "'admin_report_manage'"),
  'payments, accounts and management are sensitive defaults' => (bool) preg_match("/SENSITIVE_DEFAULT_ACTIONS = \[[^\]]*'admin_report_manage', 'admin_report_payments', 'admin_report_accounts'\]/", $app),
  'trait is registered' => str_contains($app, 'use \\SCM\\App\\Concerns\\HandlesAdministrativeReports;'),
  'every endpoint is routed to an existing handler' => (static function () use ($router, $handlers, $script): bool {
    preg_match_all("/'(scm_admin_reports_[a-z_]+)' => '([a-z_]+)'/", $router, $routes, PREG_SET_ORDER);
    preg_match_all('/"(scm_admin_reports_[a-z_]+)"/', $script, $used);
    $routed = array_column($routes, 2, 1);
    foreach ($routed as $handler) if (!str_contains($handlers, 'public function ' . $handler . '()')) return false;
    foreach (array_unique($used[1]) as $action) if (!isset($routed[$action])) return false;
    return count($routed) === 13;
  })(),
  'every handler checks CSRF and its permission' => substr_count($handlers, '$this->adminReportsRequire(') === 10 && str_contains($handlers, "private function adminReportsAccount(): array\n  {\n    \$this->verifyCsrf();"),
  'other employees reports require the manage permission' => str_contains($handlers, "if (!\$this->canUseDashboardAction('admin_report_manage')) \$this->jsonFail('Solo puedes ver tus propios reportes.');"),
  'cuenta totals come from the database, not the browser' => str_contains($service, "AND COALESCE(`exportado`, '') <> 'Si' AND COALESCE(`fue_pagado`, '') <> 'Si' FOR UPDATE") && !str_contains($handlers, "\$_POST['total_general']"),
  'reports are tied to the real employee id' => str_contains($handlers, 'Auth::employeeId()') && str_contains($service, "'cct_author_id' => \$actorEmployee"),
  'dashboard renders the panel and loads the script' => str_contains($dashboard, '$this->renderAdministrativeReportsPanel(') && str_contains($dashboard, 'assets/js/admin-administrative-reports.js'),
  'runtime loads the active tab when the panel opens' => str_contains($runtime, 'scm:load-admin-reports') && str_contains($script, 'scm:load-admin-reports'),
  'tailwind scans the new view and script' => str_contains($tailwind, 'AdministrativeReportsView.php') && str_contains($tailwind, 'admin-administrative-reports.js'),
  'destructive-like actions ask for confirmation' => substr_count($script, 'confirmAction(') >= 5,
];

$failed = [];
foreach ($checks as $label => $ok) {
  if (!$ok) $failed[] = $label;
}

if ($failed) {
  fwrite(STDERR, "Administrative reports checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}

echo 'Administrative reports checks passed: ' . count($checks) . PHP_EOL;
