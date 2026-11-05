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

$failed = new EmployeeBudgetReport(static fn(array $requests): array => [null]);
try {
  $failed->build('1', 2026, 1);
  $check('falla si no hay presupuestos', true, false);
} catch (\RuntimeException) {
}

echo "Employee budget report checks passed\n";
