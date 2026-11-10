<?php

declare(strict_types=1);

define('SCM_VERSION', 'contract-movements-test');

use SCM\Modules\ContractMovements\ContractIncreases;
use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Modules\ContractMovements\ContractReceiptCases;
use SCM\Modules\ContractMovements\ContractMovementsView;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

// El header se evalúa antes del autoload, igual que en contract-activities-navigation-check.
foreach (['ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones', 'metricas_por_terminar', 'metricas_aumentos', 'cartera_ocupaciones', 'cartera_desocupaciones', 'metricas'] as $route) {
  $_GET = ['tab' => $route];
  $current_page = '';
  ob_start();
  include dirname(__DIR__) . '/includes/header.php';
  ob_end_clean();
  $menu = $rawNavItems['metricas'];
  $check($menu['type'] === 'dropdown' && $menu['label'] === 'Métricas', 'Métricas debe ser un desplegable.');
  $check(array_keys($menu['children']) === ['dashboard', 'metricas_cotizaciones', 'metricas_preventivas', 'metricas_costo_beneficio', 'metricas_servicios_publicos', 'ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones', 'metricas_crecimiento', 'mis_presupuestos'], 'El desplegable de métricas no tiene las vistas pedidas.');
  $check(array_column($menu['children'], 'group') === ['Casos', 'Mantenimiento', 'Mantenimiento', 'Mantenimiento', 'Servicios públicos', 'Contratos', 'Contratos', 'Contratos', 'Contratos', 'Mis presupuestos y ejecuciones'], 'Las vistas de métricas se agrupan por tema.');
  $cartera = $rawNavItems['cartera']['children'];
  $check(array_keys($cartera) === ['gestiones_cobro', 'auditoria_canon_aseguradoras', 'cartera_ocupaciones', 'cartera_desocupaciones', 'metricas_por_terminar', 'metricas_aumentos'], 'Actividades de cartera no tiene las vistas pedidas.');
  foreach (['cartera_ocupaciones' => 'ocupaciones', 'cartera_desocupaciones' => 'desocupaciones', 'metricas_por_terminar' => 'por_terminar', 'metricas_aumentos' => 'aumentos'] as $key => $subtab) {
    $check($cartera[$key]['panel_id'] === 'scm-panel-movimientos-contratos' && $cartera[$key]['subtab'] === $subtab && $cartera[$key]['perms'] === [$key] && str_ends_with($cartera[$key]['url'], '?tab=' . $key), 'Ítem de cartera mal conectado: ' . $key);
  }
  $check($current_page === ($route === 'metricas' ? 'dashboard' : $route), 'Al recargar se marca el ítem equivocado para ' . $route);
  foreach (['ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones'] as $key) {
    $item = $menu['children'][$key];
    $check($item['panel_id'] === 'scm-panel-movimientos-contratos' && $item['perms'] === [['ocupaciones' => 'metricas_ocupaciones', 'desocupaciones' => 'metricas_desocupaciones', 'ocupaciones_vs_desocupaciones' => 'metricas_ocupaciones_vs'][$key]], 'Ítem mal conectado: ' . $key);
    $check(str_ends_with($item['url'], '?tab=' . $key), 'La ruta debe usar tab=' . $key);
  }
}


require dirname(__DIR__) . '/vendor/autoload.php';

foreach (['1.250.000' => 1250000, '$ 4.498.821' => 4498821, '1587766' => 1587766, '1,250,000' => 1250000, '1.250.000,50' => 1250001, 'COP 980.000' => 980000, '' => 0, 'sin dato' => 0, '840.5' => 841] as $raw => $expected) {
  $check(ContractMovementsReport::parseMoney($raw) === $expected, 'Canon mal interpretado: ' . $raw);
}

$report = (new ReflectionClass(ContractMovementsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ContractMovementsReport::class, 'timezone'))->setValue($report, new DateTimeZone('America/Bogota'));
$base = ['_ID' => 7, 'contrato' => ' 12 ', 'valor_canon' => '1.000.000', 'tipo_inmueble' => 'apartamento', 'destinacion_inmueble' => 'Vivienda', 'gestion_inmueble' => 'a:0:{}', 'id_empleado' => '10', 'direccion' => 'CL 1', 'arrendatario' => 'Ana', 'inmueble' => '501', 'id_inmueble_data' => '12466', 'id_inmueble' => '84016'];
$cols = array_flip(ContractMovementsReport::COLUMNS);

$received = $report->mapRow($base + ['estado' => 'Recibido', 'fecha_entrega' => '1622955600', 'inicio_contrato' => '', 'fecha_recibo' => '', 'fin_contrato' => '1749186000']);
$check($received[$cols['estado']] === 'recibido', 'Recibido debe mapearse.');
$check($received[$cols['ingreso']] === '2021-06-06', 'La ocupación de un recibido debe conservar su fecha de entrega en hora Colombia.');
$check($received[$cols['egreso']] === '2025-06-06', 'Sin fecha de recibo la desocupación usa el fin del contrato.');
$check($received[$cols['canon']] === 1000000 && $received[$cols['tipo']] === 'Apartamento' && $received[$cols['gestion']] === '', 'Canon y categorías deben normalizarse.');
$check($received[$cols['contrato']] === '12' && $received[$cols['inmueble']] === '501', 'Contrato e inmueble deben limpiarse.');

$delivered = $report->mapRow($base + ['estado' => 'Entregado', 'fecha_entrega' => '', 'inicio_contrato' => '1622955600', 'fecha_recibo' => '18000', 'fin_contrato' => '1749186000']);
$check($delivered[$cols['ingreso']] === '2021-06-06', 'Sin fecha de entrega la ocupación usa el inicio del contrato.');
$check($delivered[$cols['egreso']] === null, 'Un contrato entregado no es desocupación aunque tenga fecha de recibo.');
$check($delivered[$cols['fin']] === '2025-06-06', 'Un contrato activo expone su fin previsto.');
$check($delivered[$cols['entrega']] === null && $delivered[$cols['inicio']] === '2021-06-06' && $delivered[$cols['recibo']] === null, 'Fecha de entrega, inicio y recibo se exponen por separado (1970 es basura).');

foreach (['15' => 15, '7 dias' => 7, '10 días' => 10, '1 mes' => 30, '1 MES' => 30, '0' => 0, 'NO' => 0, 'N/a' => 0, '' => null, 'pendiente' => null] as $raw => $expected) {
  $check(ContractMovementsReport::parseGraceDays((string) $raw) === $expected, 'Días de gracia mal interpretados: ' . $raw);
}
$extra = $report->mapRow($base + ['estado' => 'Entregado', 'fecha_entrega' => '1622955600', 'inicio_contrato' => '', 'fecha_recibo' => '', 'fin_contrato' => '',
  'valor_administracion' => 'No aplica', 'meses_contrato' => '12', 'dias_gracia' => '', 'dias_gracia_cierre' => '', 'dias_gracia_mandato' => '15']);
$check($extra[$cols['admin']] === 0 && $extra[$cols['meses']] === 12, 'Administración "No aplica" es 0 y la duración se lee en meses.');
$check($extra[$cols['dias_gracia']] === 15 && $extra[$cols['dias_gracia_fuente']] === 'mandato', 'Sin días en contrato ni cierre, se toman del mandato.');
$fromClosing = $report->mapRow($base + ['estado' => 'Entregado', 'fecha_entrega' => '1622955600', 'valor_administracion' => '250.000', 'meses_contrato' => '1761436800', 'dias_gracia_cierre' => '0', 'dias_gracia_mandato' => '15']);
$check($fromClosing[$cols['dias_gracia']] === 0 && $fromClosing[$cols['dias_gracia_fuente']] === 'cierre', 'La hoja de cierre prevalece sobre el mandato.');
$check($fromClosing[$cols['admin']] === 250000 && $fromClosing[$cols['meses']] === null, 'Duraciones absurdas se descartan.');

$check($report->mapRow($base + ['estado' => 'Desistido', 'fecha_entrega' => '1622955600', 'inicio_contrato' => '', 'fecha_recibo' => '', 'fin_contrato' => '']) === null, 'Los desistidos nunca ocuparon el inmueble.');
$check($report->mapRow($base + ['estado' => 'Por entregar', 'fecha_entrega' => '', 'inicio_contrato' => '', 'fecha_recibo' => '', 'fin_contrato' => '']) === null, 'Los contratos por entregar aún no ocupan.');

// Casos de recibo: la fecha que registra contractual decide el mes; un caso repetido cuenta una vez.
$nov = (new DateTimeImmutable('2026-11-20', new DateTimeZone('America/Bogota')))->getTimestamp();
$case = static fn(int $id, string $contrato, string $estado, string $admin, $fecha): array => ['_ID' => $id, 'id_ticket' => (string) (9000 + $id), 'id_contrato' => '5' . $contrato, 'contrato' => $contrato, 'inmueble' => '501', 'direccion' => ' CL  1 ', 'arrendatario' => 'Ana', 'estado' => $estado, 'estado_administrativo' => $admin, 'fecha_terminacion_contrato' => $fecha];
$cases = ContractReceiptCases::normalize([
  $case(3, '12', 'Cerrado', 'Recibido', $nov + 86400),
  $case(1, '10', 'Nuevo', 'Nuevo', $nov),
  $case(2, '10', 'Nuevo', 'Inspeccionado', (string) $nov),
  $case(4, '13', 'Cerrado', 'Nuevo', $nov * 1000),
  $case(5, '14', 'Nuevo', 'Nuevo', 18000),
  $case(6, '15', 'Nuevo', 'Nuevo', ''),
]);
$check(array_column($cases, 'contrato') === ['10', '13', '12'], 'Se ordena por fecha, sin repetidos ni casos sin fecha válida.');
$check($cases[0]['caso'] === '9002' && $cases[0]['abierto'] === true && $cases[0]['estado'] === 'Por recibir' && $cases[0]['fin'] === '2026-11-20' && $cases[0]['direccion'] === 'CL 1', 'Del repetido queda el caso más reciente, con la fecha en hora Colombia.');
$check($cases[2]['estado'] === 'Recibido' && $cases[1]['estado'] === 'Cerrado' && $cases[1]['abierto'] === false, 'Un caso cerrado muestra Recibido o Cerrado.');

$today = new DateTimeImmutable('2026-11-09', new DateTimeZone('America/Bogota'));
$alerts = ContractReceiptCases::alertGroups($cases, $today);
$check(array_column($alerts, 'key') === ['contratos_por_recibir', 'contratos_recibidos'] && array_column($alerts, 'count') === [1, 2], 'El popup separa lo que falta por recibir de lo cerrado.');
$check(array_unique(array_column($alerts, 'popup')) === ['Contratos por desocupar'] && $alerts[0]['popup_note'] === '3 casos de recibo con fecha en noviembre de 2026.' && $alerts[0]['show_all'] === true, 'Es un solo popup, con el mes y todas las filas.');
$check($alerts[0]['rows'][0]['date'] === '20/11/2026' && $alerts[0]['rows'][0]['title'] === 'Contrato #10 · Ana' && $alerts[0]['rows'][0]['badge'] === 'Faltan 11 días' && str_contains($alerts[0]['rows'][0]['meta'], 'Caso #9002'), 'La fila dice qué día termina y cuál es el caso.');
$check($alerts[1]['rows'][0]['badge'] === 'Cerrado' && $alerts[1]['rows'][1]['badge'] === 'Recibido', 'Lo cerrado muestra su estado.');
$check(ContractReceiptCases::alertGroups([], $today) === [], 'Sin casos no hay popup.');
$check($alerts[0]['popup_action'] === null && ContractReceiptCases::alertGroups($cases, $today, true)[0]['popup_action'] === ['label' => 'Ver contratos por terminar', 'tab' => 'metricas_por_terminar'], 'El botón a la vista solo sale con permiso.');

// Aumentos: el valor anterior sale de la carta y, en las antiguas, de la carta previa o del porcentaje.
$ts = static fn(string $date): int => (new DateTimeImmutable($date, new DateTimeZone('America/Bogota')))->getTimestamp();
$letter = static fn(string $date, string $tipo, string $contrato, array $extra): array => $extra + ['_scm_ts' => $ts($date), 'tipo_carta' => $tipo, 'id_contrato' => $contrato, 'contrato' => 'C' . $contrato, 'inmueble' => '501', 'direccion' => 'CL 1', 'arrendatario' => 'Ana'];
$increases = ContractIncreases::fromLetters([
  $letter('2025-09-21', 'Aumento de canon', '1', ['canon' => '6.507.639', 'incremento' => '7,2']),
  $letter('2025-09-21', 'Aumento de canon', '1', ['canon' => '7.028.250', 'incremento' => '8']),
  $letter('2025-10-30', 'Aumento de canon', '2', ['canon' => '1.788.400', 'incremento' => '5.2']),
  $letter('2025-10-30', 'Aumento de canon', '2', ['canon' => '1.788.400', 'incremento' => '5.2']),
  $letter('2026-10-05', 'Aumento de administracion', '3', ['administracion' => '384600', 'administracion_anterior' => '352200', 'incremento' => '', 'vigencia_administracion' => $ts('2026-01-01'), 'texto_retroactivos' => ' Retroactivo por $100.000 ']),
  $letter('2026-11-09', 'Aumento de canon', '4', ['canon' => '4477637', 'canon_anterior' => '4180800', 'incremento' => '7.1', 'vigencia_administracion' => $ts('2026-11-09')]),
  $letter('2026-11-09', 'Aumento de administracion', '5', ['administracion' => '500000', 'incremento' => '']),
  $letter('2026-11-09', 'Otro', '6', ['canon' => '1000']),
  $letter('2026-11-09', 'Aumento de canon', '7', ['canon' => '']),
]);
$check(count($increases) === 6 && array_column($increases, 'contrato') === ['C1', 'C1', 'C2', 'C3', 'C4', 'C5'], 'Se omite la carta generada dos veces, los tipos desconocidos y las que no tienen valor.');
$check($increases[0]['calculado'] === true && $increases[0]['anterior'] === (int) round(6507639 / 1.072), 'Sin valor anterior ni carta previa se calcula con el porcentaje y se marca.');
$check($increases[1]['calculado'] === false && $increases[1]['anterior'] === 6507639 && $increases[1]['diferencia'] === 7028250 - 6507639, 'Dos cartas el mismo día ponen al día años distintos: la anterior es la carta previa.');
$check($increases[3]['tipo_aumento'] === 'admin' && $increases[3]['anterior'] === 352200 && $increases[3]['porcentaje'] === 9.2 && $increases[3]['vigencia'] === '2026-01-01' && $increases[3]['retroactivo'] === 'Retroactivo por $100.000', 'La administración conserva desde cuándo rige, el retroactivo y deduce el porcentaje.');
$check($increases[4]['anterior'] === 4180800 && $increases[4]['nuevo'] === 4477637 && $increases[4]['fecha'] === '2026-11-09' && $increases[4]['vigencia'] === '', 'El valor guardado en la carta manda y la vigencia igual a la carta no se repite.');
$check($increases[5]['anterior'] === 0 && $increases[5]['diferencia'] === null && $increases[5]['porcentaje'] === null, 'Sin datos no se inventa el valor anterior.');

foreach (['ocupaciones' => 'ocupaciones', 'desocupaciones' => 'desocupaciones', 'ocupaciones_vs_desocupaciones' => 'comparativo', 'metricas_por_terminar' => 'por_terminar', 'metricas_aumentos' => 'aumentos', 'cartera_ocupaciones' => 'ocupaciones', 'cartera_desocupaciones' => 'desocupaciones', 'metricas' => 'ocupaciones'] as $tab => $view) {
  $check(ContractMovementsView::viewFromTab($tab) === $view, 'Vista inicial incorrecta para ' . $tab);
}
$shell = (new ContractMovementsView())->renderShell('comparativo');
$check(substr_count($shell, 'data-scm-subview-target=') === 5, 'El panel debe ofrecer las cinco vistas.');
$check(str_contains($shell, 'data-initial-view="comparativo"'), 'El panel debe abrir en la vista pedida.');
$check(str_contains($shell, 'data-scm-cm-filter="month"') && substr_count($shell, '<option value="') >= 12, 'La barra de filtros incluye el mes.');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-contract-movements.js');
$check(substr_count($js, 'fetch(') === 1, 'El módulo debe consultar el servidor desde un único punto.');
$check(str_contains($js, 'sessionStorage') && str_contains($js, 'state.pending'), 'El módulo debe cachear y no duplicar peticiones en curso.');

echo "Contract movements checks passed.\n";
