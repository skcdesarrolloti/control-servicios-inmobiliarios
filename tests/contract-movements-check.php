<?php

declare(strict_types=1);

define('SCM_VERSION', 'contract-movements-test');

use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Modules\ContractMovements\ContractMovementsView;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

// El header se evalúa antes del autoload, igual que en contract-activities-navigation-check.
foreach (['ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones', 'metricas'] as $route) {
  $_GET = ['tab' => $route];
  $current_page = '';
  ob_start();
  include dirname(__DIR__) . '/includes/header.php';
  ob_end_clean();
  $menu = $rawNavItems['metricas'];
  $check($menu['type'] === 'dropdown' && $menu['label'] === 'Métricas', 'Métricas debe ser un desplegable.');
  $check(array_keys($menu['children']) === ['dashboard', 'metricas_cotizaciones', 'ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones'], 'El desplegable de métricas no tiene las vistas pedidas.');
  $check(array_column($menu['children'], 'group') === ['Casos', 'Mantenimiento', 'Contratos', 'Contratos', 'Contratos'], 'Las vistas de métricas se agrupan en Casos, Mantenimiento y Contratos.');
  $check($current_page === ($route === 'metricas' ? 'dashboard' : $route), 'Al recargar se marca el ítem equivocado para ' . $route);
  foreach (['ocupaciones', 'desocupaciones', 'ocupaciones_vs_desocupaciones'] as $key) {
    $item = $menu['children'][$key];
    $check($item['panel_id'] === 'scm-panel-movimientos-contratos' && $item['perms'] === ['metricas'], 'Ítem mal conectado: ' . $key);
    $check(str_ends_with($item['url'], '?tab=' . $key), 'La ruta debe usar tab=' . $key);
  }
}


require dirname(__DIR__) . '/vendor/autoload.php';

foreach (['1.250.000' => 1250000, '$ 4.498.821' => 4498821, '1587766' => 1587766, '1,250,000' => 1250000, '1.250.000,50' => 1250001, 'COP 980.000' => 980000, '' => 0, 'sin dato' => 0, '840.5' => 841] as $raw => $expected) {
  $check(ContractMovementsReport::parseMoney($raw) === $expected, 'Canon mal interpretado: ' . $raw);
}

$report = (new ReflectionClass(ContractMovementsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ContractMovementsReport::class, 'timezone'))->setValue($report, new DateTimeZone('America/Bogota'));
$base = ['_ID' => 7, 'contrato' => ' 12 ', 'valor_canon' => '1.000.000', 'tipo_inmueble' => 'apartamento', 'destinacion_inmueble' => 'Vivienda', 'gestion_inmueble' => 'a:0:{}', 'id_empleado' => '10', 'direccion' => 'CL 1', 'arrendatario' => 'Ana', 'id_inmueble_data' => '', 'id_inmueble' => '501'];
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

foreach (['ocupaciones' => 'ocupaciones', 'desocupaciones' => 'desocupaciones', 'ocupaciones_vs_desocupaciones' => 'comparativo', 'metricas' => 'ocupaciones'] as $tab => $view) {
  $check(ContractMovementsView::viewFromTab($tab) === $view, 'Vista inicial incorrecta para ' . $tab);
}
$shell = (new ContractMovementsView())->renderShell('comparativo');
$check(substr_count($shell, 'data-scm-subview-target=') === 3, 'El panel debe ofrecer las tres vistas.');
$check(str_contains($shell, 'data-initial-view="comparativo"'), 'El panel debe abrir en la vista pedida.');
$check(str_contains($shell, 'data-scm-cm-filter="month"') && substr_count($shell, '<option value="') >= 12, 'La barra de filtros incluye el mes.');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-contract-movements.js');
$check(substr_count($js, 'fetch(') === 1, 'El módulo debe consultar el servidor desde un único punto.');
$check(str_contains($js, 'sessionStorage') && str_contains($js, 'state.pending'), 'El módulo debe cachear y no duplicar peticiones en curso.');

echo "Contract movements checks passed.\n";
