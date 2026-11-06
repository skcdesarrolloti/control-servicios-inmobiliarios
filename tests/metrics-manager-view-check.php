<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
date_default_timezone_set('America/Bogota');

use SCM\Modules\Metrics\CaseMetricsReport;
use SCM\Modules\Metrics\EmployeeBudgetReport;
use SCM\Modules\Metrics\EmployeeBudgetView;
use SCM\Modules\Metrics\MaintenanceMetricsReport;
use SCM\Modules\Metrics\MaintenanceMetricsView;
use SCM\Modules\Metrics\MetricsBrief;

$check = static function (string $name, mixed $expected, mixed $actual): void {
  if ($expected !== $actual) {
    fwrite(STDERR, "FAIL {$name}: expected " . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
  }
};
$has = static fn(string $name, string $needle, string $html) => $check($name, true, str_contains($html, $needle));
$lacks = static fn(string $name, string $needle, string $html) => $check($name, false, str_contains($html, $needle));

// Reportes: sin corte no hay pendientes y el cumplimiento saldría 100 %.
$serie = [];
for ($m = 1; $m <= 12; $m++) $serie[] = ['mes' => $m, 'realizadas' => 10, 'tarde' => 1, 'pendientes' => 2, 'total' => 13, 'porcentaje' => 76.9];
$figures = ['serie' => $serie, 'mes' => ['realizadas' => 5, 'tarde' => 0, 'pendientes' => 73, 'total' => 78, 'porcentaje' => 6.4], 'acumulado' => ['realizadas' => 451, 'pendientes' => 102, 'total' => 553, 'porcentaje' => 81.6]];
$stats = ['success' => true, 'fecha_corte' => '2026-11-05', 'preventiva' => $figures, 'servicios' => $figures];
$remote = static fn(array $response): EmployeeBudgetReport => new EmployeeBudgetReport(static fn(array $requests): array => [$response]);
$check('entrega las cifras de Reportes', $stats, $remote($stats)->reviewStats(2026, 11));
$check('sin corte no se muestra 100 %', null, $remote(['success' => true, 'fecha_corte' => null])->reviewStats(2025, 12));
$check('si Reportes falla no hay cumplimiento', null, (new EmployeeBudgetReport(static fn(array $requests): array => [null]))->reviewStats(2026, 11));

$sent = [];
$cases = (new EmployeeBudgetReport(static function (array $requests) use (&$sent): array {
  $sent = $requests;
  return [['fecha_snapshot_ref' => '2026-11-05', 'data' => [['nombre_empleado' => 'Ana', 'total' => 100, 'cumple' => 34, 'temas_casos' => [['categoria' => 'Reparaciones necesarias', 'total' => 100, 'cumple' => 34]]]]]];
}))->caseSummary('2026-11-06');
$check('casos: mismos parámetros que Mis presupuestos', ['historial', 'tickets', 1], [$sent[0]['route'], $sent[0]['tipo'], $sent[0]['casos_al_dia']]);
$check('casos: conserva el corte', '2026-11-05', $cases['cut']);

// Tarjetas compartidas con Mis presupuestos.
$year = (int) date('Y');
$month = (int) date('n');
$compliance = EmployeeBudgetView::reviewCompliance('servicios', $stats, $year, $month);
$has('muestra acumulado', 'Acumulado ' . $year, $compliance);
$has('un mes abierto no incumple todavía', 'Mes en curso', $compliance);
$has('el acumulado sí incumple', 'No cumple', $compliance);
$has('dice cuánto falta', 'Faltan 75 revisiones para llegar al 95%', $compliance);
$has('trae el mes a mes', 'Cumplimiento mes a mes', EmployeeBudgetView::reviewComplianceHistory('servicios', $stats, $month));
$check('sin Reportes no hay mes a mes', '', EmployeeBudgetView::reviewComplianceHistory('servicios', null, $month));
$closed = EmployeeBudgetView::reviewCompliance('servicios', $stats, $year - 1, 12);
$lacks('un mes cerrado no queda en curso', 'Mes en curso', $closed);
$has('sin datos de Reportes lo dice', 'Reportes no entregó', EmployeeBudgetView::reviewCompliance('preventiva', null, $year, $month));
$card = EmployeeBudgetView::casesCard($cases);
$has('casos: cuánto falta para 85', 'Faltan 51 casos por poner al día para llegar al 85%', $card);
$has('casos: corte', 'Corte 2026-11-05', $card);
$lacks('Mis presupuestos ve el detalle abierto', '<details>', $card);
$has('Casos lo pliega para no alejar los filtros', '<details>', EmployeeBudgetView::casesCard($cases, true));

// Pendientes de servicios: la cartera completa no son pendientes y cuadra con Reportes.
$report = (new ReflectionClass(MaintenanceMetricsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(MaintenanceMetricsReport::class, 'timezone'))->setValue($report, new DateTimeZone('America/Bogota'));
$monthStart = strtotime(date('Y-m-01'));
$item = static fn(int $due, string $contract = 'C'): array => ['due' => $due, 'row' => ['contrato' => $contract, 'direccion' => 'Calle 1', 'arrendatario' => 'Ana']];
$items = [
  $item($monthStart + 3600, 'mes'),
  $item(strtotime('+3 months', $monthStart), 'futuro'),
  $item(strtotime('2020-05-10'), 'viejo'),
  $item(strtotime('-40 days', $monthStart), 'reciente'),
];
[$pending, $aging, $late] = (new ReflectionMethod(MaintenanceMetricsReport::class, 'servicesPending'))->invoke($report, $items);
$check('la base es la cartera', 4, $pending['base']);
$check('vencidas: solo meses anteriores', 2, $pending['overdue']);
$check('vencen este mes', 1, $pending['month']);
$check('arrastre de años anteriores aparte', 1, $pending['overdue_old']);
$check('los futuros no cuentan', 'viejo,reciente', implode(',', array_column($late, 'contract')));
$check('antigüedad', 2, array_sum($aging));

// `$late` también es una variable del cálculo de críticos: la lista no debe compartir ese nombre.
$has('la lista de atrasados no se pisa', "'late' => \$overdueRows", (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Metrics/MaintenanceMetricsReport.php'));

// Lectura rápida.
$services = array_map(static fn(string $label, int $debt): array => ['label' => $label, 'debt' => $debt, 'reviewed' => 1, 'al_dia' => 0, 'm30' => 1, 'm60' => 0, 'critico' => 0], ['Energía eléctrica', 'Gas natural'], [500000, 100000]);
$data = [
  'pending' => ['base' => 385, 'overdue' => 62, 'overdue_year' => 29, 'overdue_old' => 33, 'month' => 73, 'unconfigured' => 9],
  'reviews' => ['total' => 12, 'during' => 10, 'none' => 0, 'debt' => 600000, 'al_dia' => 8, 'm30' => 2, 'm60' => 1, 'critico' => 1],
  'critical' => ['created' => 1, 'open' => 1, 'overdue' => 0, 'closed' => 0, 'open_now' => 10, 'overdue_now' => 3],
  'services' => $services, 'stats' => $stats, 'statsYear' => 2026, 'statsMonth' => 11,
];
$brief = implode(' | ', array_column(MetricsBrief::services($data), 'text'));
$has('brief: cumplimiento', 'Cumplimiento acumulado 2026: 81.6% frente a un mínimo de 95%. Faltan 75 revisiones.', $brief);
$has('brief: vencidas', '62 contratos con revisión vencida de meses anteriores; 33 de ellos vienen de años anteriores', $brief);
$has('brief: críticos', '10 casos críticos abiertos, 3 fuera de las 72 horas.', $brief);
$has('brief: mayor deuda', 'la mayor en energía eléctrica', $brief);
$check('brief sin Reportes', 'warn', MetricsBrief::services(['stats' => null] + $data)[0]['tone']);

$prevData = [
  'pending' => ['total' => 45, 'overdue' => 10, 'month' => 24, 'later' => 11, 'without_case' => 11],
  'queues' => ['sin_cita' => ['total' => 3, 'overdue' => 1], 'cita_sin_realizar' => ['total' => 1, 'overdue' => 0], 'sin_enviar' => ['total' => 35, 'overdue' => 4]],
  'reviews' => ['total' => 10, 'sent' => 8, 'unsent' => 2, 'damages' => 4, 'no_damages' => 6, 'with_quote' => 3, 'without_quote' => 1, 'send_days' => 5],
  'quotes' => ['total' => 3, 'aprobada' => 1, 'esperando' => 1, 'sin_enviar' => 0, 'desaprobada' => 1, 'quoted' => 900000, 'approved_value' => 300000, 'admin' => 30000, 'admin_pending' => 40000],
  'staff' => [['name' => 'Ana', 'total' => 10, 'damages' => 4, 'unsent' => 2]], 'trend' => ['2026-11' => ['total' => 10, 'damages' => 4, 'sent' => 8]],
  'stats' => $stats, 'statsYear' => 2026, 'statsMonth' => 11,
];
$brief = implode(' | ', array_column(MetricsBrief::preventive($prevData), 'text'));
$has('preventiva: pendientes como Reportes', '34 revisiones pendientes a la fecha', $brief);
$has('preventiva: cuello de botella', 'Donde más se frena: 35 revisiones sin enviar', $brief);

// Las plantillas pintan con las claves que entrega el informe.
$view = new MaintenanceMetricsView();
$html = $view->render('preventiva', $prevData, '2026-11-01', '2026-11-06');
foreach (['Lectura rápida', 'Pendientes a la fecha', 'De la revisión a la administración ganada', 'se envían en 5 días'] as $text) $has('plantilla preventiva: ' . $text, $text, $html);
$html = $view->render('servicios', $data + [
  'aging' => ['d30' => 5, 'd60' => 2, 'd90' => 22, 'older' => 33],
  'late' => [['contract' => '1167', 'address' => 'Calle <1>', 'tenant' => 'Ana', 'due' => strtotime('2025-01-10'), 'days' => 600]],
  'trend' => [], 'staff' => [],
], '2026-11-01', '2026-11-06');
$html = str_replace('Calle &lt;1&gt;', 'ESCAPADO', $html);
foreach (['Lectura rápida', 'Revisiones vencidas', 'Antigüedad de las vencidas', 'Los 10 contratos más atrasados', 'ESCAPADO'] as $text) $has('plantilla servicios: ' . $text, $text, $html);
$lacks('ya no llama pendientes a la cartera', 'Pendientes de revisión', $html);

// Casos: la columna de cierre va al final y la caché cambia con ella.
$check('columna de cierre', 'cerrado', CaseMetricsReport::COLUMNS[6]);
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-case-metrics.js');
$has('la caché vieja no sirve', 'scm-case-metrics-v2', $js);
$has('usa el cumplimiento de Reportes', 'compliance_html', $js);
$lacks('la carga de hoy no depende del periodo', 'filteredRows', $js);

echo "Metrics manager view checks passed\n";
