<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\OwnerCredits\OwnerCreditCalculator;
use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditUsuryRate;
use SCM\Modules\OwnerCredits\OwnerCreditView;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$near = static fn(float $a, float $b, float $tolerance = 0.01): bool => abs($a - $b) <= $tolerance;

// Valores de "Simuladore de Creditos a Propietarios Version 2.xlsx" (contrato 890).
$settings = OwnerCreditSettings::normalize(['usury_annual' => '0.333', 'reference_rate' => '0.0242', 'policy_discount' => '0.05', 'income_months' => 3]);
$assert($near($settings['usury_annual'], 0.333, 1e-9) && $near($settings['reference_rate'], 0.0242, 1e-9), 'settings keep decimal rates');
$assert($near(OwnerCreditSettings::normalize(['usury_annual' => '33,3', 'reference_rate' => '2,42'])['reference_rate'], 0.0242, 1e-9), 'settings accept rates typed as percentages');

$ev = OwnerCreditCalculator::evaluate([
  'gross' => 6232674, 'deductions' => ['comision' => 2110546], 'requested_amount' => 13167756, 'requested_term' => 12,
  'request_ts' => strtotime('2024-03-26'), 'contract_end_ts' => strtotime('2024-09-25'), 'vacancy' => 'Baja',
], $settings);
$assert($near($ev['net_income'], 4122128), 'net income = gross − deductions (B11)');
$assert($ev['max_months'] === 6, 'max months = INT((end − request)/30) (B21)');
$assert($near($ev['policy_rate'], 0.02299, 1e-9), 'policy rate = reference × (1 − discount) (B22)');
$assert($near($ev['max_amount'], 12366384), 'max amount = net income × 3 (B23)');
$assert($near($ev['policy_payment'], 2230047.8271367345), 'policy payment = PMT(B22,B21,B23) (B24)');
$assert($ev['exceed_months'] === -6 && $near($ev['exceed_amount'], -801372), 'exceeding months and amount (B27, B28)');
$assert($near($ev['risk_rate'], 0.023279687226889677, 1e-12), 'risk rate (B29)');
$assert($near($ev['risk_payment'], 1270352.6211058223), 'risk payment (B30)');
$assert($ev['suggested']['term'] === 12 && $near($ev['suggested']['amount'], 13167756) && $near($ev['suggested']['payment'], 1268114.5586934178), 'low vacancy suggests requested term and amount at policy rate (B35-B38)');

$alta = OwnerCreditCalculator::evaluate(['vacancy' => 'Alta'] + ['gross' => 6232674, 'deductions' => ['comision' => 2110546], 'requested_amount' => 13167756, 'requested_term' => 12, 'request_ts' => strtotime('2024-03-26'), 'contract_end_ts' => strtotime('2024-09-25')], $settings);
$assert($alta['suggested']['term'] === 3 && $near($alta['suggested']['rate'], $alta['risk_rate'], 1e-12) && $near($alta['suggested']['amount'], 12366384), 'high vacancy suggests 3 months at the risk rate');
$media = OwnerCreditCalculator::evaluate(['vacancy' => 'Media'] + ['gross' => 6232674, 'deductions' => ['comision' => 2110546], 'requested_amount' => 13167756, 'requested_term' => 12, 'request_ts' => strtotime('2024-03-26'), 'contract_end_ts' => strtotime('2024-09-25')], $settings);
$assert($media['suggested']['term'] === 6 && $near($media['suggested']['rate'], 0.0242, 1e-12), 'medium vacancy suggests max months at the reference rate');

// Analista: 14.000.000 a 12 meses al 2,8 % (B41-B44 y hoja Tabla de Amortización).
$assert($near(OwnerCreditCalculator::payment(0.028, 12, 14000000), 1389730.2109234515), 'analyst payment = PMT(2.8 %, 12, 14.000.000) (B44)');
$schedule = OwnerCreditCalculator::schedule(0.028, 12, 14000000);
$assert(count($schedule) === 12 && $near($schedule[0]['interes'], 392000) && $near($schedule[0]['abono'], 997730.2109234515) && $near($schedule[1]['saldo'], 11976603.13224724), 'amortization rows match the workbook');
$assert($near($schedule[11]['saldo'], 0.0) && $near(array_sum(array_column($schedule, 'abono')), 14000000), 'amortization closes the balance exactly');

// Usura desde datos.gov.co (pare-7x5i): fila vigente de consumo y ordinario × 1,5.
$rows = [
  ['resolucion' => '1472', 'vigencia_desde' => '2026-10-01T00:00:00.000', 'vigencia_hasta' => '2026-10-31T00:00:00.000', 'interes_bancario_corriente' => '19.06%', 'modalidad' => 'CONSUMO Y ORDINARIO'],
  ['resolucion' => '1472', 'vigencia_desde' => '2026-10-01T00:00:00.000', 'vigencia_hasta' => '2026-10-31T00:00:00.000', 'interes_bancario_corriente' => '44.44%', 'modalidad' => 'CONSUMO BAJO MONTO'],
  ['resolucion' => '1260', 'vigencia_desde' => '2026-09-01T00:00:00.000', 'vigencia_hasta' => '2026-09-30T00:00:00.000', 'interes_bancario_corriente' => '19.49%', 'modalidad' => 'CONSUMO Y ORDINARIO'],
  ['resolucion' => '1600', 'vigencia_desde' => '2026-11-01T00:00:00.000', 'vigencia_hasta' => '2026-11-30T00:00:00.000', 'interes_bancario_corriente' => '18.00%', 'modalidad' => 'CONSUMO Y ORDINARIO'],
];
$usury = OwnerCreditUsuryRate::fromRows($rows, '2026-10-22');
$assert($usury !== null && $near($usury['usury_annual'], 0.2859, 1e-9) && $usury['usury_resolution'] === '1472' && $usury['usury_valid_until'] === '2026-10-31', 'usury = 1.5 × current consumer bank rate, ignoring other modalities and future months');
$assert(OwnerCreditUsuryRate::fromRows([], '2026-10-22') === null, 'no rows keeps the stored rate');
$legal = OwnerCreditCalculator::evaluate(['gross' => 1, 'deductions' => [], 'requested_amount' => 1, 'requested_term' => 1, 'request_ts' => time(), 'contract_end_ts' => time(), 'vacancy' => 'Baja'], ['usury_annual' => 0.2859] + $settings);
$assert($near($legal['usury_monthly_legal'], 0.021176, 1e-6), 'legal monthly usury = (1 + E.A.)^(1/12) − 1');
$assert($near(OwnerCreditSettings::normalize(['no_mandate_deduction' => '15'])['no_mandate_deduction'], 0.15, 1e-9), 'no-mandate deduction is configurable as a percentage');

$assert($near(OwnerCreditService::money('52.661,669'), 52661.669) && $near(OwnerCreditService::money('1.075.000'), 1075000) && $near(OwnerCreditService::money('$ 893723.00'), 893723), 'money parser handles mandate and quote formats');
$assert($near(OwnerCreditService::rate('2,8'), 0.028, 1e-12), 'rate inputs are N.M.V percentages');

// Descuentos reales desde el contrato de mandato (solo lectura).
$db = App::db();
$contract = $db->getRow("SELECT c.* FROM `{$db->table('jet_cct_contratos_arrendamiento')}` c JOIN `{$db->table('jet_cct_contrato_mandato')}` m ON CAST(m.`_ID` AS CHAR) = c.`id_contrato_mandato` WHERE LOWER(c.`estado`) = 'entregado' AND m.`total_comision` <> '' ORDER BY c.`_ID` DESC LIMIT 1");
if (is_array($contract)) {
  $income = (new OwnerCreditService($db, null))->defaultIncome($contract);
  $assert($income['gross'] > 0 && $income['deductions']['comision'] > 0 && str_starts_with($income['source'], 'Contrato de mandato'), 'income deductions come from the lease mandate');
}

// Vista: renderiza el liquidador con políticas incumplidas y deja la justificación obligatoria visible.
$work = [
  'credit' => ['id' => 9, 'status' => 'pendiente', 'quote_id' => 574, 'owner_name' => 'Propietario <b>', 'property' => '10420', 'purpose' => 'Libre Destinación', 'requested_amount' => 13167756, 'requested_term' => 12, 'created_at' => strtotime('2024-03-26'), 'decision_note' => '', 'decided_by_name' => '', 'decided_at' => 0],
  'contract' => ['code' => '890', 'estado' => 'Entregado', 'inicio_ts' => strtotime('2013-09-26'), 'fin_ts' => strtotime('2024-09-25'), 'aseguradora' => 'El libertador', 'siniestrado' => true],
  'income_source' => 'Contrato de mandato #1', 'settings' => $settings, 'evaluation' => $ev,
  'conditions' => ['amount' => 14000000, 'term' => 12, 'rate' => 0.028, 'payment' => 1389730.21, 'total_interest' => 2676762.53],
  'schedule' => $schedule,
  'policies' => [['key' => 'siniestro', 'label' => 'Contrato no siniestrado', 'ok' => false, 'detail' => 'Cartera marcó el contrato como siniestrado.']],
  'policies_ok' => false, 'other_credits' => [], 'events' => [],
];
$html = (new OwnerCreditView())->workspace($work, true, true);
$assert(str_contains($html, 'Propietario &lt;b&gt;') && !str_contains($html, 'Propietario <b>'), 'liquidator escapes owner data');
$assert(str_contains($html, 'data-owner-credit-decide="aprobado"') && str_contains($html, 'justificación') && str_contains($html, 'Siniestrado'), 'liquidator shows decision buttons, failed policies and the siniestro flag');
$readOnly = (new OwnerCreditView())->workspace($work, false, false);
$assert(!str_contains($readOnly, 'data-owner-credit-decide') && str_contains($readOnly, ' readonly'), 'without permission the liquidator is read-only');

// PDF interno: mismas secciones y estilos de las actas, con decisión y tabla de amortización.
$pdfWork = $work;
$pdfWork['credit'] += ['ticket_id' => '10821', 'address' => 'CL 31C 70-05', 'policy_override' => 0];
$pdfWork['credit']['status'] = 'aprobado';
$pdfWork['credit']['decided_by_name'] = 'Analista QA';
$pdfWork['credit']['decided_at'] = strtotime('2026-10-22');
$pdfHtml = \SCM\Modules\OwnerCredits\OwnerCreditPdf::html($pdfWork, 'Funcionario');
$assert(\SCM\Modules\OwnerCredits\OwnerCreditPdf::title($pdfWork) === 'Crédito aprobado #9' && str_contains($pdfHtml, 'Documento interno para funcionarios'), 'credit PDF is titled by status and marked as internal');
$assert(str_contains($pdfHtml, 'scm-acta-receipt-data') && str_contains($pdfHtml, 'Condiciones aprobadas') && str_contains($pdfHtml, 'Tabla de amortización') && substr_count($pdfHtml, '<tr><td>') >= 12, 'credit PDF uses the acta tables and lists every amortization row');
$assert(str_contains($pdfHtml, 'Analista QA') && str_contains($pdfHtml, 'Propietario &lt;b&gt;'), 'credit PDF signs with the deciding employee and escapes data');
$assert(is_readable(dirname(__DIR__) . '/public/assets/css/owner-credit-document.css'), 'credit PDF stylesheet exists');
