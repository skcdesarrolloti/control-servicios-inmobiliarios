<?php
// Local visual harness only. No DB/bootstrap and no real save or notification.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
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
<button type="button" data-scm-open-public-services-review data-contract-id="90001" data-contract-code="2000">Abrir prueba sin escritura</button>
</main>
<output id="qa-result" aria-live="polite"></output>
<script src="/public/assets/js/scm-admin.js"></script>
<script>window.SCMAdminCore.scmNotify = function(type, message) { document.getElementById('qa-result').textContent = type + ': ' + message; };</script>
<script src="/public/assets/js/admin-dashboard-runtime.js"></script>
</body></html>
