<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

use SCM\Modules\Metrics\EmployeeBudgetReport;
use SCM\Modules\Metrics\EmployeeBudgetView;

$check = static function (string $name, mixed $expected, mixed $actual): void {
  if ($expected !== $actual) {
    fwrite(STDERR, "FAIL {$name}: expected " . json_encode($expected) . ', got ' . json_encode($actual) . "\n");
    exit(1);
  }
};

$budget = [
  ['tipo_presupuesto' => 'Captaciones', 'tipo_valor' => 'cantidad', 'month' => 1, 'value' => 10],
  ['tipo_presupuesto' => 'Captaciones', 'tipo_valor' => 'cantidad', 'month' => 2, 'value' => 10],
  ['tipo_presupuesto' => 'Numero de negocios', 'tipo_valor' => 'valor', 'month' => 2, 'value' => 1000000],
  ['tipo_presupuesto' => 'Oferta Inmobiliaria', 'tipo_valor' => 'cantidad', 'month' => 1, 'value' => 100],
  ['tipo_presupuesto' => 'Oferta Inmobiliaria', 'tipo_valor' => 'cantidad', 'month' => 2, 'value' => 120],
  ['tipo_presupuesto' => 'Desocupaciones del Mes', 'tipo_valor' => 'valor', 'month' => 2, 'value' => 5],
  ['tipo_presupuesto' => 'Sin meta', 'tipo_valor' => 'cantidad', 'month' => 2, 'value' => 0],
];

$sent = [];
$report = new EmployeeBudgetReport(static function (array $requests) use ($budget, &$sent): array {
  return array_map(static function (array $request) use ($budget, &$sent): ?array {
    $sent[] = $request;
    if ($request['route'] === 'presupuestos/list') return ['success' => true, 'presupuestos' => $budget];
    if ($request['route'] === 'ejecucion/snapshot-mes') return ['success' => true, 'ejecutado' => $request['mes'] === 1 ? 90 : 60];
    if ($request['tipo'] === 'Captaciones') return ['success' => true, 'items' => [['month' => 1, 'ejecutado' => 8], ['month' => 2, 'ejecutado' => 12], ['month' => 3, 'ejecutado' => 50]]];
    if ($request['tipo'] === 'Numero de negocios') return null;
    return ['success' => true, 'items' => []];
  }, $requests);
});

$data = $report->build('2871', 2026, 2);
$byName = array_column($data['activities'], null, 'name');

$check('sin meta no aparece', false, isset($byName['Sin meta']));
$check('meta del mes', 10.0, $byName['Captaciones']['month']['target']);
$check('cumplimiento del mes', 120.0, $byName['Captaciones']['month']['pct']);
$check('acumula solo hasta el mes elegido', ['target' => 20.0, 'done' => 20.0, 'pct' => 100.0], $byName['Captaciones']['accumulated']);
$check('la oferta no se suma', ['target' => 120.0, 'done' => 60.0, 'pct' => 50.0], $byName['Oferta Inmobiliaria']['accumulated']);
$check('un fallo no se muestra como cero', false, $byName['Numero de negocios']['available']);
$check('marca el valor', true, $byName['Numero de negocios']['is_money']);
$check('resuelve el tipo de desocupaciones', true, in_array('Desocupaciones del Mes en Valores', array_column($sent, 'tipo'), true));
$check('siempre consulta al funcionario de la sesión', ['2871'], array_values(array_unique(array_column($sent, 'id_empleado'))));

$html = (new EmployeeBudgetView())->render($data);
$check('muestra la actividad', true, str_contains($html, 'Captaciones'));
$check('indica el dato faltante', true, str_contains($html, 'Sin dato'));
$check('muestra el porcentaje', true, str_contains($html, '120,0 %'));
$check('el mes a mes llega hasta el mes elegido', false, str_contains($html, '>Mar<'));

$empty = (new EmployeeBudgetView())->render(['year' => 2026, 'month' => 2, 'activities' => []]);
$check('sin presupuestos', true, str_contains($empty, 'No tienes presupuestos'));

$untouched = new EmployeeBudgetReport(static function (array $requests): array {
  throw new \LogicException('No debe consultar Reportes sin funcionario.');
});
$check('sin funcionario no consulta a todos', [], $untouched->build('', 2026, 2)['activities']);

$stats = ['success' => true, 'fecha_corte' => '2026-11-04', 'preventiva' => [
  'mes' => ['realizadas' => 7, 'tarde' => 0, 'pendientes' => 2, 'total' => 9, 'porcentaje' => 77.8],
  'acumulado' => ['realizadas' => 142, 'pendientes' => 34, 'total' => 176, 'porcentaje' => 80.7],
  'serie' => [['mes' => 1, 'realizadas' => 7, 'tarde' => 0, 'pendientes' => 1, 'total' => 8, 'porcentaje' => 87.5], ['mes' => 10, 'realizadas' => 7, 'tarde' => 0, 'pendientes' => 2, 'total' => 9, 'porcentaje' => 77.8], ['mes' => 11, 'realizadas' => 1, 'tarde' => 0, 'pendientes' => 1, 'total' => 2, 'porcentaje' => 50]],
]];
$caseRows = [
  ['nombre_empleado' => 'Sin casos', 'total' => 0, 'cumple' => 0, 'temas_casos' => []],
  ['nombre_empleado' => 'Elsie', 'total' => 47, 'cumple' => 17, 'temas_casos' => [['categoria' => 'Reparaciones necesarias', 'total' => 36, 'cumple' => 15], ['categoria' => 'Revision preventiva', 'total' => 4, 'cumple' => 0]]],
  ['nombre_empleado' => 'Alvaro', 'total' => 101, 'cumple' => 34, 'temas_casos' => [['categoria' => 'Revision preventiva', 'total' => 85, 'cumple' => 27]]],
];
$asked = [];
$complianceReport = new EmployeeBudgetReport(static function (array $requests) use ($stats, $caseRows, &$asked): array {
  return array_map(static function (array $request) use ($stats, $caseRows, &$asked): array {
    $asked[] = $request['route'];
    return $request['route'] === 'historial' ? ['data' => $caseRows] : $stats;
  }, $requests);
});
$compliance = $complianceReport->compliance('2871', '3', 2026, 10, '2026-10-31');
$check('el cargo 3 consulta revisiones y casos', ['compensacion/revisiones-stats', 'historial'], $asked);
$check('casos globales', 148, $compliance['case_summary']['total']);
$check('primero quien más atrasa', 'Alvaro', $compliance['case_summary']['by_employee'][0]['name']);
$check('suma por tema', ['name' => 'Revision preventiva', 'total' => 89, 'on_time' => 27, 'late' => 62, 'pct' => 30.3], $compliance['case_summary']['by_topic'][0]);

$only = ['year' => 2026, 'month' => 10, 'activities' => [], 'compliance' => $compliance];
$monthly = (new EmployeeBudgetView())->render($only);
$check('sin presupuesto muestra el cumplimiento', false, str_contains($monthly, 'No tienes presupuestos'));
$check('porcentaje del mes', true, str_contains($monthly, '77.8%'));
$check('fórmula del mes', true, str_contains($monthly, '7 a tiempo ÷ 9 a realizar (7 a tiempo + 0 tarde + 2 pendientes) = 77.8%'));
$check('mes cerrado', true, str_contains($monthly, 'Mes cerrado por debajo del mínimo de 80%.'));
$check('no incluye meses posteriores', false, str_contains($monthly, '>Nov<'));
$check('casos por tema', true, str_contains($monthly, 'Por tema'));
$accumulated = (new EmployeeBudgetView())->render($only, 'acumulado');
$check('vista acumulada', true, str_contains($accumulated, '142 realizadas ÷ 176 a realizar (142 realizadas + 34 pendientes) = 80.7%'));
$check('el acumulado no trae el mes a mes', false, str_contains($accumulated, 'Mes a mes'));
$check('reportes sin el dato', true, str_contains((new EmployeeBudgetView())->render(['year' => 2026, 'month' => 10, 'activities' => [], 'compliance' => ['reviews' => true, 'stats' => null, 'cases' => false]]), 'Reportes no entregó'));

$asked = [];
$check('otros cargos sin cumplimientos', ['reviews' => false, 'stats' => null, 'cases' => false, 'case_summary' => null], $complianceReport->compliance('5', '9', 2026, 10, '2026-10-31'));
$check('no consulta de más', [], $asked);
$check('funcionario 9 excluido', false, $complianceReport->compliance('9', '7', 2026, 10, '2026-10-31')['reviews']);

$failed = new EmployeeBudgetReport(static fn(array $requests): array => [null]);
try {
  $failed->build('1', 2026, 1);
  $check('falla si no hay presupuestos', true, false);
} catch (\RuntimeException) {
}

echo "Employee budget report checks passed\n";
