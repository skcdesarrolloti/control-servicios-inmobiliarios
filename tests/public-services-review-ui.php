<?php
// Local visual harness only. No DB/bootstrap and no real save or notification.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $operation = (string) ($_POST['operation'] ?? '');
  $type = (string) ($_POST['type'] ?? 'al_dia');
  if (in_array($operation,['templates','save_template','preview_template','history'],true)) {
    if ($operation==='history') $html='<form data-services-history-form><input name="contrato"><button type="submit">Filtrar</button></form><p>Revisión #123 · Contrato 149</p><button type="button" data-services-copy-url="https://example.invalid/revision?numero=123&amp;expires=123&amp;sig=test">Copiar enlace público</button><button type="button" data-services-history-page="2">Siguiente</button>';
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
$runtime = ['ajaxUrl' => '/tests/public-services-review-ui.php', 'nonce' => 'qa-only', 'actions' => ['revision_servicios_publicos' => 'qa-review']];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QA servicios públicos</title><link rel="stylesheet" href="/public/assets/css/scm-admin.css"></head><body>
<main id="scm-app" class="scm-wrap" data-scm-runtime="<?php echo esc_attr(json_encode($runtime)); ?>">
<div id="scm-panel-servicios-publicos-pendientes"><div class="scm-pending-wrap">
<?= \SCM\Modules\Pending\PublicServicesWorkspace::tabs() ?>
<section data-services-section="pending"><p>Pendientes de ejemplo</p>
<button type="button" data-scm-open-public-services-review data-contract-id="90001" data-contract-code="2000">Abrir prueba sin escritura</button>
</section><section data-services-section="templates" hidden><div data-services-workspace-content="templates"></div></section><section data-services-section="history" hidden><div data-services-workspace-content="history"></div></section>
</div></div>
</main>
<output id="qa-result" aria-live="polite"></output>
<script src="/public/assets/js/scm-admin.js"></script>
<script>window.SCMAdminCore.scmNotify = function(type, message) { document.getElementById('qa-result').textContent = type + ': ' + message; };</script>
<script src="/public/assets/js/admin-dashboard-runtime.js"></script>
</body></html>
