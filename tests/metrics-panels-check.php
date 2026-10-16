<?php

declare(strict_types=1);

define('SCM_VERSION', 'metrics-panels-test');

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};
$root = dirname(__DIR__);

// El header se evalúa antes del autoload, igual que en contract-activities-navigation-check.
foreach (['dashboard' => 'dashboard', 'metricas' => 'dashboard', 'metricas_cotizaciones' => 'metricas_cotizaciones', 'ocupaciones' => 'ocupaciones'] as $route => $expected) {
  $_GET = ['tab' => $route];
  $current_page = '';
  ob_start();
  include $root . '/includes/header.php';
  $html = (string) ob_get_clean();
  $check($current_page === $expected, 'Al recargar ?tab=' . $route . ' se marca el ítem equivocado.');
  $menu = $rawNavItems['metricas'];
  $check($menu['children']['dashboard']['panel_id'] === 'scm-panel-metricas-casos' && str_ends_with($menu['children']['dashboard']['url'], '?tab=dashboard'), 'Casos abre con ?tab=dashboard.');
  $check($menu['children']['metricas_cotizaciones']['panel_id'] === 'scm-panel-metricas-cotizaciones', 'Cotizaciones tiene su propio panel.');
  $check(substr_count($html, 'class="scm-dropdown-group-label"') >= 3 && str_contains($html, '>Mantenimiento</div>') && str_contains($html, '>Contratos</div>'), 'El desplegable muestra las secciones Casos, Mantenimiento y Contratos.');
}

require $root . '/vendor/autoload.php';

use SCM\Modules\Metrics\CaseMetricsReport;
use SCM\Modules\Metrics\MetricsPanelsView;

$report = (new ReflectionClass(CaseMetricsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(CaseMetricsReport::class, 'timezone'))->setValue($report, new DateTimeZone('America/Bogota'));
$check($report->date('1792167812', '2026-10-16 11:23:33') === '2026-10-16', 'La fecha del caso usa `fecha` en hora Colombia.');
$check($report->date('1760331600', '') === '2025-10-13', 'Un caso creado a medianoche de Colombia no cae en el día anterior.');
$check($report->date('', '2025-03-02 09:00:00') === '2025-03-02' && $report->date('0', '2025-03-02 09:00:00') === '2025-03-02', 'Sin `fecha` se usa cct_created.');
$check($report->date('12', '') === null && $report->date(null, null) === null, 'Fechas basura no cuentan.');

$view = new MetricsPanelsView();
$cases = $view->renderCasesShell();
foreach (['month', 'from', 'to', 'tema', 'departamento', 'funcionario'] as $filter) {
  $check(str_contains($cases, 'data-scm-case-filter="' . $filter . '"'), 'Métricas de casos debe filtrar por ' . $filter . '.');
}
$quotes = $view->renderQuotesShell();
$check(str_contains($quotes, 'data-scm-quote-filter="month"') && str_contains($quotes, 'data-scm-quote-step="-1"') && str_contains($quotes, 'data-scm-quote-filter="state"'), 'Cotizaciones filtra por mes (con flechas) y por trabajo.');

$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
foreach (['id="scm-panel-metricas"', 'data-scm-contractual-reports', 'Gestión contractual', '+4.2%', 'Meta: 85%', 'REPORTES_GERENCIALES_URL'] as $removed) {
  $check(!str_contains($dashboard, $removed), 'El panel viejo de métricas sigue presente: ' . $removed);
}
$check(str_contains($dashboard, "'dashboard' => 'scm-panel-metricas-casos'") && str_contains($dashboard, "'metricas_cotizaciones' => 'scm-panel-metricas-cotizaciones'"), 'Las rutas apuntan a los paneles nuevos.');

$casesJs = (string) file_get_contents($root . '/public/assets/js/admin-case-metrics.js');
$quotesJs = (string) file_get_contents($root . '/public/assets/js/admin-quote-metrics.js');
$check(substr_count($casesJs, 'fetch(') === 1 && str_contains($casesJs, 'sessionStorage'), 'Casos consulta una sola vez y usa caché de sesión.');
$check(substr_count($quotesJs, 'fetch(') === 1 && str_contains($quotesJs, 'cache[month]'), 'Cotizaciones consulta una vez por mes.');
$check(str_contains($quotesJs, 'data-scm-maintenance-quote-open-case') && str_contains($quotesJs, 'scm-maintenance-quote-row'), 'Cotizaciones conserva "Ver caso" con el popup del panel.');

echo "Metrics panels checks passed.\n";
