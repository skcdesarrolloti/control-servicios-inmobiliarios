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
$check($row[$cols['revisada']] === 0, 'Una fecha sin revisar no queda marcada como revisada.');
$row = $quoteReport->mapRow($quoteBase + ['fecha_respuesta' => '1792167812', 'ticket_respuesta' => '', 'revisada' => '2026-10-16']);
$check($row[$cols['revisada']] === 1, 'La fecha confirmada por un funcionario queda revisada.');
$row = $quoteReport->mapRow($quoteBase + ['fecha_respuesta' => '1792167812', 'ticket_respuesta' => '', 'revisada' => '2026-09-09']);
$check($row[$cols['revisada']] === 0, 'Si la fecha cambió después de revisarla, se vuelve a evaluar.');
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
// Métricas consulta una sola vez; calidad/conciliación reutiliza esas filas y solo va al servidor para guardar o conciliar.
$qualityJs = (string) file_get_contents($root . '/public/assets/js/admin-quote-quality.js');
$check(substr_count($quotesJs, 'fetch(') === 1 && str_contains($quotesJs, 'state.data && !force') && str_contains($quotesJs, 'scm:quote-data'), 'Cotizaciones consulta una sola vez y comparte sus filas.');
$check(substr_count($qualityJs, 'fetch(') === 1 && str_contains($qualityJs, 'scm:quote-data') && str_contains($qualityJs, 'scm:quote-reload'), 'Calidad de datos reutiliza las filas y pide recargar tras corregir.');
$check(str_contains($qualityJs, 'overwrite: mode === "dubious" ? "1" : "0"') && str_contains($qualityJs, 'data-scm-qq-confirm-dates'), 'Corregir fechas existentes exige confirmación explícita.');
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
$check($saved === ['id' => 1, 'respuesta' => '2026-09-20', 'anterior' => null] && $row['cct_author_id'] === '2871', 'Guarda la fecha y el id_empleado real como autor.');
$check((new DateTimeImmutable('@' . $row['fecha_respuesta']))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i') === '2026-09-20 12:00', 'La fecha se guarda a mediodía de Colombia.');
$fails(fn() => $writer->setMissingResponseDate(1, '2026-09-21', '2871'), 'ya tiene fecha', 'Una segunda edición no sobrescribe');

// Corregir una fecha existente (lote mal fechado) exige sobrescritura explícita.
$fixed = $writer->setResponseDates([['id' => 2, 'date' => '2026-09-10'], ['id' => 3, 'date' => '2026-09-10'], ['id' => 1, 'date' => '2999-01-01']], '2871', true);
$check(count($fixed['updated']) === 1 && $fixed['updated'][0]['id'] === 2 && $fixed['updated'][0]['anterior'] !== null, 'Con sobrescritura se corrige la fecha existente y se informa la anterior.');
$check(array_column($fixed['errors'], 'id') === [3, 1], 'En lote, cada cotización inválida se informa sin frenar las demás.');

// Inmueble "No asignado": se obtiene del caso, del contrato del caso o del código; nunca se pisa uno existente.
$pdo->exec('ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN inmueble TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN id_ticket TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN contrato TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN id_contrato TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN id_inmueble TEXT');
$pdo->exec('CREATE TABLE wp_jet_cct_tickets(_ID INTEGER PRIMARY KEY, inmueble TEXT, id_contrato TEXT, id_inmueble TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, id_inmueble TEXT, id_inmueble_data TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY, codigo TEXT, cct_status TEXT)');
// SQLite no tiene information_schema: se precargan las cachés del inspector con las tablas de la prueba.
$inspector = new \SCM\Support\SchemaInspector($sqlite);
$schemaTables = [
  'wp_jet_cct_cotizacion_mantenimiento' => ['_ID', 'estado', 'inmueble', 'id_ticket', 'contrato', 'id_contrato', 'id_inmueble'],
  'wp_jet_cct_tickets' => ['_ID', 'inmueble', 'id_contrato', 'id_inmueble'],
  'wp_jet_cct_contratos_arrendamiento' => ['_ID', 'contrato', 'id_inmueble', 'id_inmueble_data'],
  'wp_jet_cct_inmuebles' => ['_ID', 'codigo', 'cct_status'],
];
(new ReflectionProperty(\SCM\Support\SchemaInspector::class, 'tableExistsCache'))->setValue($inspector, array_map(static fn(): bool => true, $schemaTables));
(new ReflectionProperty(\SCM\Support\SchemaInspector::class, 'tableColumnsCache'))->setValue($inspector, $schemaTables);
(new ReflectionProperty(QuoteMetricsReport::class, 'schema'))->setValue($writer, $inspector);
$sqlite->insert('wp_jet_cct_tickets', ['_ID' => 8983, 'inmueble' => '10614']);
$sqlite->insert('wp_jet_cct_tickets', ['_ID' => 9860, 'inmueble' => '10385']);
$sqlite->insert('wp_jet_cct_tickets', ['_ID' => 10727, 'inmueble' => 'No asignado', 'id_contrato' => '778']);
$sqlite->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 778, 'contrato' => 'No asignado', 'id_inmueble' => '71802', 'id_inmueble_data' => '12177']);
$sqlite->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 499, 'contrato' => '1167-Diseños', 'id_inmueble' => '79831', 'id_inmueble_data' => '']);
$sqlite->insert('wp_jet_cct_tickets', ['_ID' => 6373, 'inmueble' => '1247-Diseños', 'id_contrato' => '499', 'id_inmueble' => '79831']);
$sqlite->insert('wp_jet_cct_inmuebles', ['_ID' => 12356, 'codigo' => '79831', 'cct_status' => 'publish']);
$sqlite->insert('wp_jet_cct_inmuebles', ['_ID' => 20001, 'codigo' => '555', 'cct_status' => 'publish']);
$sqlite->insert('wp_jet_cct_inmuebles', ['_ID' => 20002, 'codigo' => '555', 'cct_status' => 'publish']);
$sqlite->update('wp_jet_cct_cotizacion_mantenimiento', ['inmueble' => 'No asignado', 'id_ticket' => '8983'], ['_ID' => 1]);
$sqlite->update('wp_jet_cct_cotizacion_mantenimiento', ['inmueble' => '10335', 'id_ticket' => '9860'], ['_ID' => 2]);
$sqlite->update('wp_jet_cct_cotizacion_mantenimiento', ['inmueble' => 'No asignado', 'id_ticket' => '10727', 'contrato' => 'No asignado'], ['_ID' => 3]);
$sqlite->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 4, 'estado' => 'Aprobada', 'inmueble' => '', 'id_ticket' => '6373', 'contrato' => '']);
$sqlite->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 5, 'estado' => 'Aprobada', 'inmueble' => '', 'id_ticket' => '', 'id_inmueble' => '555']);
$assigned = $writer->assignCaseProperty([1, 2, 3, 4, 5], '2871');
$check($assigned === ['updated' => [
  ['id' => 1, 'inmueble' => '10614', 'fuente' => 'caso'],
  ['id' => 3, 'inmueble' => '12177', 'fuente' => 'contrato'],
  ['id' => 4, 'inmueble' => '12356', 'fuente' => 'codigo'],
], 'skipped' => [2, 5]], 'Asigna el inmueble del caso, del contrato del caso o del código, solo a las cotizaciones sin inmueble: ' . json_encode($assigned));
$check($sqlite->getVar('SELECT inmueble FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID = 2') === '10335', 'Un inmueble existente no se modifica.');
$quote4 = $sqlite->getRow('SELECT contrato, id_contrato FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID = 4');
$check($quote4['contrato'] === '1167-Diseños' && $quote4['id_contrato'] === '499', 'Completa el contrato desde el contrato del caso cuando falta.');
$check($sqlite->getVar('SELECT contrato FROM wp_jet_cct_cotizacion_mantenimiento WHERE _ID = 3') === 'No asignado', 'Un contrato "No asignado" no se copia.');

// Conciliación con el auxiliar contable.
use SCM\Modules\Metrics\QuoteLedgerReconciliation;
$check(QuoteLedgerReconciliation::parseDate('3/10/2026') === '2026-10-03' && QuoteLedgerReconciliation::parseDate('2026-07-11 00:00:00') === '2026-07-11' && QuoteLedgerReconciliation::parseDate('46000') === '2025-12-09', 'Fechas del auxiliar en d/m/Y, ISO y número de Excel.');
$quote = static fn(int $id, string $inm, int $admin, string $estado = 'Aprobada', ?string $resp = '2026-07-01', string $caseInm = ''): array => [
  'id' => $id, 'ticket' => (string) ($id + 9000), 'inmueble' => $inm, 'inmueble_caso' => $caseInm, 'admin' => $admin, 'estado' => $estado,
  'respuesta' => $resp, 'sugerida' => null, 'fecha' => '2026-06-01', 'destinatario' => 'QA', 'categoria' => 'Inicial',
];
$quotes = [
  $quote(1, '10152', 142500),                                   // coincide exacto
  $quote(2, 'No asignado', 224410, 'Aprobada', '2026-05-14', '10614'), // inmueble del caso
  $quote(3, '10385', 108900, 'Aprobada', '2026-07-09'),           // mismo valor, otro inmueble
  $quote(4, '10329', 55986),                                      // dos cuotas de 27.993
  $quote(5, '10148', 180000),                                     // valor distinto
  $quote(6, '10500', 99000, 'Aprobada', '2026-07-20'),            // aprobada sin movimiento
];
$entries = [
  ['fila' => 6, 'transaccion' => 'A', 'fecha' => '2026-07-14', 'inmueble' => '10152', 'valor' => 142500],
  ['fila' => 7, 'transaccion' => 'B', 'fecha' => '2026-05-14', 'inmueble' => '10614', 'valor' => 224410],
  ['fila' => 8, 'transaccion' => 'C', 'fecha' => '2026-07-09', 'inmueble' => '10335', 'valor' => 108900],
  ['fila' => 9, 'transaccion' => 'D', 'fecha' => '2026-07-31', 'inmueble' => '10329', 'valor' => 27993],
  ['fila' => 10, 'transaccion' => 'E', 'fecha' => '2026-08-31', 'inmueble' => '10329', 'valor' => 27993],
  ['fila' => 11, 'transaccion' => 'F', 'fecha' => '2026-07-16', 'inmueble' => '10148', 'valor' => 100240],
  ['fila' => 12, 'transaccion' => 'G', 'fecha' => '2026-07-17', 'inmueble' => '10625', 'valor' => 57500],
];
$ledger = (new QuoteLedgerReconciliation())->reconcile($entries, $quotes);
$states = array_column($ledger['filas'], 'estado', 'transaccion');
$check($states === ['A' => 'coincide', 'B' => 'coincide_inmueble_caso', 'C' => 'coincide_otro_inmueble', 'D' => 'coincide_suma', 'E' => 'coincide_suma', 'F' => 'diferencia', 'G' => 'sin_cotizacion'], 'Clasifica cada movimiento del auxiliar: ' . json_encode($states));
$check($ledger['resumen']['conciliados'] === 5 && $ledger['resumen']['total_auxiliar'] === 689536, 'Resume movimientos conciliados y total del auxiliar.');
$check(array_column($ledger['aprobadas_sin_movimiento'], 'cotizacion') === [6], 'Lista las aprobadas del periodo sin movimiento contable.');

// "Marcar como aprobada" guarda la fecha real de aprobación (por defecto hoy), no siempre "hoy".
$maintenance = (string) file_get_contents($root . '/src/App/Concerns/HandlesMaintenanceActions.php');
$runtimeJs = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$check(str_contains($maintenance, "'fecha_respuesta' => \$responseTs") && str_contains($maintenance, "'fecha_respuesta_cotizacion_mantenimiento' => \$responseTs") && str_contains($maintenance, 'no puede ser anterior a la creación de la cotización'), 'La aprobación desde el panel usa la fecha indicada.');
$check(str_contains($runtimeJs, 'id="swal-approve-date"') && str_contains($runtimeJs, 'fd.append("fecha_aprobacion"'), 'El diálogo de aprobación pide la fecha.');

echo "Metrics panels checks passed.\n";
