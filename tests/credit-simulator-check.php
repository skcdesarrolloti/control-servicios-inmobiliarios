<?php

declare(strict_types=1);

// Simulador de crédito (Herramientas) y simulación de la financiación en la respuesta pública de la cotización.
require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Modules\OwnerCredits\OwnerCreditCoverage;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditSimulator;
use SCM\Modules\Tools\CreditSimulationPdf;
use SCM\Modules\Tools\CreditSimulationService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

// Plan: mismo cálculo del liquidador, saldo en cero y abonos que suman el capital.
$sim = OwnerCreditSimulator::simulate(893723, 6, 0.0242, (int) strtotime('2026-11-10 12:00:00'));
$check(count($sim['rows']) === 6 && $sim['payment'] === OwnerCreditCoverage::payment(893723, 0.0242, 6), 'the simulation uses the liquidator payment for the term');
$check(abs(array_sum(array_column($sim['rows'], 'abono')) - 893723) < 0.01 && (float) $sim['rows'][5]['saldo'] === 0.0, 'the principal payments add up to the amount and the last balance is zero');
$check(abs($sim['total'] - (893723 + $sim['total_interest'])) < 0.01, 'total to pay is amount plus interest');
$check(date('Y-m-d', $sim['first_due_ts']) === '2026-11-30' && date('Y-m-d', $sim['last_due_ts']) === '2027-04-30', 'a disbursement on the 10th pays the first installment that same month, due on the 30th');
$late = OwnerCreditSimulator::simulate(500000, 4, 0.02, (int) strtotime('2027-01-20 12:00:00'));
$check(date('Y-m-d', $late['rows'][0]['fecha']) === '2027-02-28' && date('Y-m-d', $late['rows'][1]['fecha']) === '2027-03-30', 'from the 16th the first installment moves to the next month, and February uses its last day');
$check(abs($sim['effective_annual'] - ((1.0242 ** 12) - 1)) < 1e-12, 'the effective annual rate is the monthly rate compounded');

// Tasa: la de referencia, sin pasar la usura.
$settings = ['usury_annual' => 0.35] + OwnerCreditSettings::defaults();
$check(OwnerCreditSimulator::rate($settings) === 0.0242, 'the simulation rate is the reference rate');
$check(abs(OwnerCreditSimulator::rate(['reference_rate' => 0.05] + $settings) - ((1 + 0.35) ** (1 / 12) - 1)) < 1e-12, 'the simulation rate never exceeds usury');

$compare = OwnerCreditSimulator::compare(1000000, 0.02, [12, 3, 61, 0, 5, 3]);
$check(array_column($compare, 'term') === [3, 5, 12], 'the term comparison is sorted, unique and within 1 to 60 months');
$check($compare[0]['payment'] > $compare[2]['payment'] && $compare[0]['total_interest'] < $compare[2]['total_interest'], 'longer terms lower the payment and raise the interest');

// Herramienta: validación de lo que escribe el funcionario.
$settings['usury_updated_at'] = date('Y-m-01');
$settings['usury_valid_until'] = date('Y-m-t');
$service = new CreditSimulationService($settings);
$input = $service->input(['valor' => '2.500.000', 'plazo' => '12', 'tasa' => '2,42', 'desembolso' => '2026-11-20', 'destinatario' => 'arrendatario', 'nombre' => '  Ana   Pérez ', 'documento' => '1.047.000', 'destinacion' => 'Libre inversión']);
$check($input['amount'] === 2500000.0 && $input['term'] === 12 && abs($input['rate'] - 0.0242) < 1e-12 && $input['name'] === 'Ana Pérez', 'amounts with thousands dots and rates with a decimal comma are read');
foreach ([
  [['valor' => '', 'plazo' => '12', 'tasa' => '2'], 'Escribe el valor'],
  [['valor' => '1000000', 'plazo' => '61', 'tasa' => '2'], 'entre 1 y 60 meses'],
  [['valor' => '1000000', 'plazo' => '12', 'tasa' => ''], 'Escribe la tasa'],
  [['valor' => '1000000', 'plazo' => '12', 'tasa' => '2,6'], 'supera la usura'],
] as [$raw, $expected]) {
  try {
    $service->input($raw);
    $check(false, 'invalid input is rejected: ' . $expected);
  } catch (InvalidArgumentException $error) {
    $check(str_contains($error->getMessage(), $expected), 'invalid input is rejected: ' . $error->getMessage());
  }
}
$result = $service->simulate($input);
$check(in_array(12, array_column($result['compare'], 'term'), true) && in_array(3, array_column($result['compare'], 'term'), true), 'the comparison includes the simulated term and the usual terms');
$warnings = implode(' ', $result['warnings']);
$check(str_contains($warnings, 'tope de la política de créditos (10 meses)') && str_contains($warnings, 'solo registra créditos a propietarios'), 'the tool warns when the term passes the policy cap and when the client is not an owner');
$check(!str_contains($warnings, 'usura guardada no está vigente'), 'no stale-usury warning while the saved usury is current');
$owner = $service->simulate($service->input(['valor' => '1000000', 'plazo' => '6', 'tasa' => '2,42']));
$check($owner['warnings'] === [], 'an owner credit within policy has no warnings');
$low = $service->simulate($service->input(['valor' => '1000000', 'plazo' => '6', 'tasa' => '2']));
$check(str_contains(implode(' ', $low['warnings']), 'fuera de política'), 'a rate below the policy minimum is flagged');

// Resumen para WhatsApp: firma "Nombre - Cargo - Cel." sin indicativo y sin correo.
$summary = CreditSimulationService::summary($result, ['name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI', 'phone' => '+57 301 523 2054', 'email' => 'no@example.invalid']);
$check(str_contains($summary, 'Atentamente, Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054') && !str_contains($summary, 'no@example.invalid'), 'the summary is signed name - position - Cel. phone, without email or country code');
$check(str_contains($summary, 'Cliente: Ana Pérez (Arrendatario)') && str_contains($summary, 'Cuota mensual estimada: $') && str_contains($summary, 'Primera cuota: 30/12/2026') && str_contains($summary, 'SKC SuCasa Inmobiliaria'), 'the summary carries client, payment, first due date and the company name');
$check(CreditSimulationService::signerLine(['name' => 'Solo Nombre']) === 'Solo Nombre', 'the signature skips missing data');

// PDF: plan con fechas, comparativo, aviso y "Realizado por" al final; sin sucursal.
$html = CreditSimulationPdf::html($result, ['name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI', 'phone' => '573015232054']);
$check(substr_count($html, '<tr><th scope="row">') >= 12 + count($result['compare']) && str_contains($html, '30/12/2026'), 'the PDF lists every installment with its due date');
$check(str_contains($html, 'Realizado por</span><strong>Royner Guardo') && strrpos($html, 'Realizado por') > strrpos($html, 'Plan de pagos'), 'the PDF closes with who made it');
$check(str_contains($html, 'SKC SuCasa Inmobiliaria') && str_contains($html, 'sujeto a estudio, verificación y aprobación') && stripos($html, 'sucursal') === false, 'the PDF names the company, carries the disclaimer and no branch');
$check(str_contains($html, 'Ana Pérez') && str_contains($html, 'Arrendatario'), 'the PDF shows the client when given');
$check(CreditSimulationPdf::filename($result) === 'simulacion-credito-1-047-000-' . date('Y-m-d') . '.pdf', 'the PDF file is named after the client document');
$anonymous = CreditSimulationPdf::html($owner, []);
$check(!str_contains($anonymous, '. Cliente</h2>') && str_contains($anonymous, '1. Condiciones simuladas'), 'without client data the PDF skips that section');

// Simulación de la financiación para el navegador (respuesta pública y formularios del panel): un solo formato.
$plans = [];
for ($term = 1; $term <= 3; $term++) $plans[$term] = OwnerCreditSimulator::simulate(893723, $term, 0.0242);
$preview = ['amount' => 893723.0, 'rate' => 0.0242, 'effective_annual' => OwnerCreditSimulator::effectiveAnnual(0.0242), 'max_term' => 3, 'plans' => $plans];
$browser = OwnerCreditSimulator::browserPreview($preview);
$check(array_keys($browser['plans']) === ['1', '2', '3'] || array_keys($browser['plans']) === [1, 2, 3], 'the browser preview carries one plan per possible term');
$check(count($browser['plans'][3]['rows']) === 3 && $browser['plans'][3]['rows'][2][3] === 0.0 && $browser['amount'] === 893723.0 && $browser['plans'][3]['payment'] === round($plans[3]['payment']) && $browser['max_term'] === 3, 'each plan has its installments as value, interest, principal and balance, in whole pesos');
$check(str_contains($browser['note'], 'sujeto a estudio, verificación y aprobación') && str_contains($browser['note'], 'giros de arrendamiento'), 'the preview explains it is not an approval and how it is paid');
$check(OwnerCreditSimulator::browserPreview(['plans' => []] + $preview) === null, 'without plans there is no preview');

// Respuesta pública: el bloque oculto con el JSON que pinta quote-financing-preview.js.
$app = (new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
$render = new ReflectionMethod($app, 'render_public_quote_financing_preview');
$block = $render->invoke($app, $preview);
$check(str_contains($block, 'data-public-quote-financing hidden'), 'the public simulation stays hidden until financing is Sí');
preg_match('/<script type="application\/json" data-public-quote-financing-data>(.*?)<\/script>/s', $block, $match);
$data = json_decode($match[1] ?? '', true);
$check(is_array($data) && count($data['plans']['3']['rows']) === 3 && (int) $data['amount'] === 893723 && $data['note'] === $browser['note'], 'the public page embeds the same preview the panel gets');
$check($render->invoke($app, ['plans' => []] + $preview) === '', 'without a quote total there is no public simulation');
$publicPage = (string) file_get_contents(dirname(__DIR__) . '/public/cotizacion-mantenimiento.php');
$check(str_contains($publicPage, 'assets/js/quote-financing-preview.js') && str_contains($publicPage, 'SCMQuoteFinancingPreview.mount'), 'the public page uses the shared simulation component');
$dashboard = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
$check(strpos($dashboard, 'assets/js/quote-financing-preview.js') < strpos($dashboard, '<script src="<?php echo $jsUrl; ?>"'), 'the panel loads the shared simulation component before its forms');
$termEndpoint = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/HandlesMaintenanceActions.php');
$check(str_contains($termEndpoint, "OwnerCreditSimulator::browserPreview(\$credits->quoteFinancingPreview(\$quoteId))") && str_contains($termEndpoint, "\$preview['evaluation'] = \$credits->quoteFinancingEvaluation(\$quoteId)") && str_contains($termEndpoint, "'max' => \SCM\Modules\OwnerCredits\OwnerCreditService::QUOTE_REQUEST_MAX_TERM"), 'the months endpoint of the panel sends 12 months, the simulation and the staff evaluation');
$check(!str_contains((string) json_encode($data), 'evaluation') && !str_contains($block, 'Ingreso neto'), 'the public page never gets the staff evaluation');

// Tasa del simulador: vigente o no, de dónde sale y cuándo se actualizó.
$current = ['usury_auto' => true, 'usury_synced_at' => (int) strtotime('2026-11-23 08:15:00'), 'usury_resolution' => '1472', 'reference_rate_updated_at' => (int) strtotime('2026-11-02 10:30:00'), 'usury_sync_error' => ''] + $settings;
$status = (new CreditSimulationService($current))->rateStatus();
$check($status['ok'] && $status['label'] === 'Tasa actualizada' && $status['updated'] === '23/11/2026 08:15' && $status['source'] === 'Superfinanciera (datos.gov.co)', 'a current automatic usury shows when it was last updated from datos.gov.co');
$check($status['reference_updated'] === '02/11/2026 10:30' && str_contains($status['period'], 'Res. 1472') && !$status['capped'], 'the status shows when the reference rate changed, the usury period and resolution');
$stale = (new CreditSimulationService(['usury_updated_at' => '2026-09-01', 'usury_valid_until' => '2026-09-30', 'usury_sync_error' => 'No se pudo consultar datos.gov.co.'] + $current))->rateStatus();
$check(!$stale['ok'] && $stale['label'] === 'Tasa no vigente' && $stale['error'] === 'No se pudo consultar datos.gov.co.', 'an expired usury is flagged with the failed update');
$manual = (new CreditSimulationService(['usury_auto' => false, 'usury_updated_at' => date('Y-m-01'), 'reference_rate' => 0.03] + $current))->rateStatus();
$check($manual['ok'] && $manual['updated'] === date('01/m/Y') && str_contains($manual['source'], 'Manual') && $manual['capped'] && $manual['rate'] < 0.03, 'a manual usury shows its start date, and a reference over usury is simulated at usury');
$card = (new \SCM\Modules\Tools\ToolsView())->creditRateCard($stale);
$check(str_contains($card, 'data-credit-sim-rate-state="stale"') && str_contains($card, 'Última actualización') && str_contains($card, '23/11/2026 08:15') && str_contains($card, 'Se usa la última tasa guardada'), 'the rate card shows the state, the last update and why it could not refresh');

// Fecha de cambio de la tasa de referencia: se registra al guardarla distinta y se conserva al guardar otra cosa.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
$store = new \SCM\Core\Settings(new \SCM\Core\Database($pdo, 'wp_'));
$first = OwnerCreditSettings::save($store, ['reference_rate' => '2%'] + OwnerCreditSettings::defaults(), 1);
$check($first['reference_rate_updated_at'] === 0, 'the first saved settings have no reference rate change yet');
$changed = OwnerCreditSettings::save($store, ['reference_rate' => '1,9%', 'reference_rate_updated_at' => 0] + $first, 1);
$check($changed['reference_rate_updated_at'] >= time() - 5, 'changing the reference rate records when it changed');
$other = OwnerCreditSettings::save($store, ['max_term' => 9, 'reference_rate_updated_at' => 0] + $changed, 1);
$check($other['reference_rate_updated_at'] === $changed['reference_rate_updated_at'] && OwnerCreditSettings::read($store)['reference_rate_updated_at'] === $changed['reference_rate_updated_at'], 'saving other settings keeps the reference rate change date');

echo "Credit simulator checks passed.\n";
