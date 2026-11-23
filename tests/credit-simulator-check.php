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

// Respuesta pública: cuota por plazo y planes para el JSON del navegador.
$app = (new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
$render = new ReflectionMethod($app, 'render_public_quote_financing_preview');
$plans = [];
for ($term = 1; $term <= 3; $term++) $plans[$term] = OwnerCreditSimulator::simulate(893723, $term, 0.0242);
$block = $render->invoke($app, ['amount' => 893723.0, 'rate' => 0.0242, 'effective_annual' => OwnerCreditSimulator::effectiveAnnual(0.0242), 'max_term' => 3, 'plans' => $plans]);
$check(str_contains($block, 'data-public-quote-financing hidden') && substr_count($block, 'data-public-quote-financing-term=') === 3, 'the public answer offers one option per possible term, hidden until financing is Sí');
$check(str_contains($block, 'Valor a financiar: $893.723') && str_contains($block, '2,42 % mensual') && str_contains($block, number_format(round($plans[3]['payment']), 0, ',', '.') . ' al mes'), 'each option shows its monthly payment at the reference rate');
preg_match('/<script type="application\/json" data-public-quote-financing-data>(.*?)<\/script>/s', $block, $match);
$data = json_decode($match[1] ?? '', true);
$check(is_array($data) && count($data['plans']['3']['rows']) === 3 && (int) $data['plans']['3']['rows'][2][3] === 0 && (int) $data['amount'] === 893723 && (int) $data['plans']['3']['payment'] === (int) round($plans[3]['payment']), 'the browser gets the payment plan of every term');
$check(str_contains($block, 'sujeto a estudio, verificación y aprobación') && str_contains($block, 'giros de arrendamiento'), 'the public simulation explains it is not an approval and how it is paid');
$check($render->invoke($app, ['amount' => 0.0, 'rate' => 0.0242, 'effective_annual' => 0.0, 'max_term' => 3, 'plans' => []]) === '', 'without a quote total there is no simulation');

echo "Credit simulator checks passed.\n";
