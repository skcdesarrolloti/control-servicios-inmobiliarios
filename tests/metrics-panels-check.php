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
  $check(substr_count($html, 'data-scm-dropdown-sub>') === 2 && str_contains($html, '<span>Mantenimiento</span>') && str_contains($html, '<span>Contratos</span>') && !str_contains($html, 'scm-dropdown-group-label'), 'Mantenimiento y Contratos abren su submenú; Casos es un enlace directo.');
}

require $root . '/vendor/autoload.php';

use SCM\Modules\Metrics\CaseMetricsReport;
use SCM\Modules\Metrics\MetricsPanelsView;
use SCM\Modules\Metrics\QuoteMetricsReport;

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
foreach (['year', 'month', 'categoria'] as $filter) {
  $check(str_contains($quotes, 'data-scm-quote-filter="' . $filter . '"'), 'Cotizaciones debe filtrar por ' . $filter . '.');
}
$check(str_contains($quotes, '<option value="0">Todo el año</option>'), 'Cotizaciones permite ver el año completo.');

// Cotizaciones: administración sin IVA y fecha de respuesta con respaldo del caso.
$check(QuoteMetricsReport::adminAmount(['total_admon' => '18000', 'porcentaje_admon' => '10']) === 18000, 'La administración usa total_admon.');
$check(QuoteMetricsReport::adminAmount(['total_admon' => '', 'porcentaje_admon' => '10', 'total_mano_obra' => '180000', 'total_materiales' => '20000']) === 20000, 'Sin total_admon se calcula subtotal × porcentaje.');
$quoteReport = (new ReflectionClass(QuoteMetricsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(QuoteMetricsReport::class, 'timezone'))->setValue($quoteReport, new DateTimeZone('America/Bogota'));
$cols = array_flip(QuoteMetricsReport::COLUMNS);
$quoteBase = ['_ID' => 579, 'id_ticket' => '10963', 'estado' => 'Aprobada', 'se_envio' => 'Si', 'fecha' => '1792076064', 'cct_created' => '2026-10-15 09:54:25', 'total' => '201420', 'total_admon' => '18000', 'categoria_cotizacion' => 'Inicial'];
$row = $quoteReport->mapRow($quoteBase + ['fecha_respuesta' => '1792167812', 'ticket_respuesta' => '']);
$check($row[$cols['fecha']] === '2026-10-15' && $row[$cols['respuesta']] === '2026-10-16' && $row[$cols['respuesta_fuente']] === 'cotizacion', 'Creada por fecha de cotización y aprobada por fecha de respuesta.');
$check($row[$cols['total']] === 201420 && $row[$cols['admin']] === 18000 && $row[$cols['enviada']] === 1, 'Valor total, administración y envío.');
$row = $quoteReport->mapRow($quoteBase + ['fecha_respuesta' => '0', 'ticket_respuesta' => '1792167812']);
$check($row[$cols['respuesta']] === '2026-10-16' && $row[$cols['respuesta_fuente']] === 'caso', 'Sin fecha de respuesta se usa la del caso.');
$row = $quoteReport->mapRow(['se_envio' => 'No', 'fecha' => '0', 'cct_created' => '2026-09-02 10:00:00', 'fecha_respuesta' => '0', 'ticket_respuesta' => null] + $quoteBase);
$check($row[$cols['enviada']] === 0 && $row[$cols['fecha']] === '2026-09-02' && $row[$cols['respuesta']] === null, 'Sin enviar, fecha desde cct_created y sin fecha de respuesta.');

$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
foreach (['id="scm-panel-metricas"', 'data-scm-contractual-reports', 'Gestión contractual', '+4.2%', 'Meta: 85%', 'REPORTES_GERENCIALES_URL'] as $removed) {
  $check(!str_contains($dashboard, $removed), 'El panel viejo de métricas sigue presente: ' . $removed);
}
$check(str_contains($dashboard, "'dashboard' => 'scm-panel-metricas-casos'") && str_contains($dashboard, "'metricas_cotizaciones' => 'scm-panel-metricas-cotizaciones'"), 'Las rutas apuntan a los paneles nuevos.');

$casesJs = (string) file_get_contents($root . '/public/assets/js/admin-case-metrics.js');
$quotesJs = (string) file_get_contents($root . '/public/assets/js/admin-quote-metrics.js');
$check(substr_count($casesJs, 'fetch(') === 1 && str_contains($casesJs, 'sessionStorage'), 'Casos consulta una sola vez y usa caché de sesión.');
// Una consulta para cargar y otra solo al guardar una fecha de respuesta.
$check(substr_count($quotesJs, 'fetch(') === 2 && str_contains($quotesJs, 'state.data && !force') && str_contains($quotesJs, 'quote_response_date'), 'Cotizaciones consulta una sola vez y solo vuelve al servidor para guardar fechas.');
$check(str_contains($quotesJs, 'data-scm-maintenance-quote-open-case') && str_contains($quotesJs, 'scm-maintenance-quote-row'), 'Cotizaciones conserva "Ver caso" con el popup del panel.');

// Completar fecha de respuesta: solo aprobadas sin fecha, sin fechas futuras ni previas a la creación.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY, estado TEXT, fecha TEXT, cct_created TEXT, fecha_respuesta TEXT, cct_modified TEXT, cct_author_id TEXT)');
$sqlite = new \SCM\Core\Database($pdo, 'wp_');
$sqlite->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 1, 'estado' => 'Aprobada', 'fecha' => '1788800000', 'fecha_respuesta' => '0', 'cct_author_id' => '1']);
$sqlite->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 2, 'estado' => 'Aprobada', 'fecha' => '1788800000', 'fecha_respuesta' => '1789000000', 'cct_author_id' => '1']);
$sqlite->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 3, 'estado' => 'Desaprobada', 'fecha' => '1788800000', 'fecha_respuesta' => '0', 'cct_author_id' => '1']);
$writer = new QuoteMetricsReport($sqlite);
$fails = static function (callable $fn, string $expected, string $label) use ($check): void {
  try { $fn(); } catch (Throwable $e) { $check(str_contains($e->getMessage(), $expected), $label . ' (' . $e->getMessage() . ')'); return; }
  throw new RuntimeException($label . ' debía fallar.');
};
$fails(fn() => $writer->setMissingResponseDate(1, '2999-01-01', '2871'), 'futura', 'No se aceptan fechas futuras');
$fails(fn() => $writer->setMissingResponseDate(1, '2026-01-01', '2871'), 'anterior a la creación', 'No se acepta una fecha previa a la creación');
$fails(fn() => $writer->setMissingResponseDate(1, '2026-02-30', '2871'), 'fecha válida', 'Fecha inexistente');
$fails(fn() => $writer->setMissingResponseDate(2, '2026-09-20', '2871'), 'ya tiene fecha', 'No se reemplaza una fecha existente');
$fails(fn() => $writer->setMissingResponseDate(3, '2026-09-20', '2871'), 'aprobadas', 'Solo cotizaciones aprobadas');
$fails(fn() => $writer->setMissingResponseDate(1, '2026-09-20', ''), 'id_empleado', 'Exige el id_empleado real de quien edita');
$saved = $writer->setMissingResponseDate(1, '2026-09-20', '2871');
$row = $sqlite->getRow('SELECT fecha_respuesta, cct_author_id FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID = 1');
$check($saved === ['id' => 1, 'respuesta' => '2026-09-20'] && $row['cct_author_id'] === '2871', 'Guarda la fecha y el id_empleado real como autor.');
$check((new DateTimeImmutable('@' . $row['fecha_respuesta']))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i') === '2026-09-20 12:00', 'La fecha se guarda a mediodía de Colombia.');
$fails(fn() => $writer->setMissingResponseDate(1, '2026-09-21', '2871'), 'ya tiene fecha', 'Una segunda edición no sobrescribe');

echo "Metrics panels checks passed.\n";
