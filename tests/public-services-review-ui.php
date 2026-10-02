<?php
// Local visual harness only. No DB/bootstrap and no real save or notification.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL','https://example.invalid');
define('SCM_APP_SECRET','synthetic-ui-test-secret');
$templateTypes = \SCM\Modules\Pending\PublicServicesActTemplates::defaults();
$templateHtml = static function (string $type, ?array $override = null) use ($templateTypes): string {
  $template = $override ?? $templateTypes[$type];
  $template['version'] = \SCM\Modules\Pending\PublicServicesActTemplates::version($template);
  return \SCM\Modules\Pending\PublicServicesWorkspace::templateEditor($type,$template);
};
$reflection = new ReflectionClass(\SCM\Modules\Pending\PendingService::class);
$definitions = $reflection->getMethod('publicServiceDefinitions')->invoke($reflection->newInstanceWithoutConstructor());
$services = [];
foreach ($definitions as $key => $definition) {
  $services[$key] = $definition + ['configured' => $key === 'energia', 'account' => $key === 'energia' ? 'NIC-QA' : '', 'meter' => $key === 'energia' ? 'METER-QA' : ''];
}
$context = ['contract' => ['_ID' => 90001, 'contrato' => 2000, 'inmueble' => 204578, 'direccion' => 'Dirección sintética de prueba', 'arrendatario' => 'Arrendatario QA', 'mes_revision_servicios' => 11], 'services' => $services, 'employee' => ['nombre' => 'Funcionario autenticado QA', 'id_empleado' => '94001'], 'has_services' => true, 'review_date' => date('Y-m-d')];
$view = new \SCM\Modules\Pending\PendingView();
$items = [];
for ($i=0;$i<24;$i++) $items[]=['row'=>['_ID'=>90001+$i,'contrato'=>2000+$i,'inmueble'=>10156+$i,'estado'=>'Entregado','direccion'=>'Crespo 2da Avenida No. 67–190 Local 102, Edificio Crespo 270','propietario'=>'MYRIAM ABEITA NASSAR','arrendatario'=>'COMERCIALIZADORA DE SERVICIOS DE BOLÍVAR S.A.S.','inicio_contrato'=>strtotime('2024-07-29'),'fin_contrato'=>strtotime('2027-07-28'),'fecha_entrega'=>strtotime('2024-07-29')],'ultima'=>strtotime('2026-07-01'),'due'=>strtotime('2026-10-01')+$i*86400];
$configurationItems=[array_replace($items[0],['needs_service_configuration'=>true,'due'=>0])];
$historyRows=[['_ID'=>123,'fecha'=>time(),'contrato'=>149,'inmueble'=>10156,'direccion'=>'Altos de Plan Parejo 2 Mz 42 Lt 02','arrendatario'=>'JORGE IVAN ZABALETA RINCON','realizado_por'=>'Funcionario autenticado QA','acta_felicitaciones_luz'=>'https://example.invalid/acta.pdf']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $operation = (string) ($_POST['operation'] ?? '');
  $type = (string) ($_POST['type'] ?? 'al_dia');
  if (in_array(($_POST['action']??''),['qa-pending','scm_servicios_publicos_pendientes'],true)) { echo json_encode(['success'=>true,'data'=>['table_html'=>$view->renderServiciosPublicosTable($items,$configurationItems),'kpis_html'=>$view->renderServiciosPublicosKpis(count($items),'2026',$items,$configurationItems),'count'=>(string)count($items)]]);exit; }
  if (in_array($operation,['templates','save_template','preview_template','history'],true)) {
    if ($operation==='history') $html=\SCM\Modules\Pending\PublicServicesWorkspace::historyList($historyRows,$_POST,89,max(1,(int)($_POST['page']??1)),3);
    elseif ($operation==='preview_template') $html=\SCM\Modules\Pending\PublicServicesWorkspace::preview(['title'=>$_POST['title'],'body'=>$_POST['body']],$type);
    else $html=$templateHtml($type,$operation==='save_template'?['title'=>$_POST['title'],'body'=>$_POST['body']]:null);
    echo json_encode(['success'=>true,'data'=>['html'=>$html,'message'=>'QA sin escritura: plantilla guardada']]);exit;
  }
  $data = $operation === 'load'
    ? ['form_html' => (new \SCM\Modules\Pending\PendingView())->renderServiciosPublicosReviewForm($context)]
    : ['message' => 'QA sin escritura: ' . $operation . '; configurados=' . implode(',', (array) ($_POST['servicios_configurados'] ?? [])) . '; revisados=' . implode(',', (array) ($_POST['servicios'] ?? [])), 'documents' => []];
  echo json_encode(['success' => true, 'data' => $data]);
  exit;
}
$runtime = ['ajaxUrl' => '/tests/public-services-review-ui.php', 'nonce' => 'qa-only', 'actions' => ['revision_servicios_publicos' => 'qa-review','servicios_publicos_pendientes'=>'qa-pending']];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QA servicios públicos</title><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/public/assets/css/scm-admin.css"><link rel="stylesheet" href="/public/assets/css/tailwind-services.css"></head><body>
<main id="scm-app" class="scm-wrap" data-scm-runtime="<?php echo esc_attr(json_encode($runtime)); ?>">
<div id="scm-panel-servicios-publicos-pendientes"><?= $view->renderServiciosPublicosPanel([],$items,count($items),'2026',$configurationItems) ?></div>
</main>
<output id="qa-result" aria-live="polite"></output>
<script src="/public/assets/js/scm-admin.js"></script>
<script>window.SCMAdminCore.scmNotify = function(type, message) { document.getElementById('qa-result').textContent = type + ': ' + message; };</script>
<script src="/public/assets/js/admin-dashboard-runtime.js"></script>
<script src="/public/assets/js/admin-dashboard-inline.js"></script></body></html>
