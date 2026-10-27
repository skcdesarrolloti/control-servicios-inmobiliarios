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
// Sin margen de meses, como el Excel (el margen del preaprobado se prueba aparte).
$settings = OwnerCreditSettings::normalize(['usury_annual' => '0.333', 'reference_rate' => '0.0242', 'policy_discount' => '0.05', 'income_months' => 3, 'term_margin_months' => 0]);
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
// A diferencia del Excel (B35-B38), el descuento de tasa lo aplica el analista: la sugerencia usa la tasa de referencia.
$assert($ev['suggested']['term'] === 12 && $near($ev['suggested']['amount'], 13167756) && $near($ev['suggested']['rate'], 0.0242, 1e-12) && $near($ev['suggested']['payment'], OwnerCreditCalculator::payment(0.0242, 12, 13167756)), 'low vacancy suggests the requested term and amount at the reference rate');

$alta = OwnerCreditCalculator::evaluate(['vacancy' => 'Alta'] + ['gross' => 6232674, 'deductions' => ['comision' => 2110546], 'requested_amount' => 13167756, 'requested_term' => 12, 'request_ts' => strtotime('2024-03-26'), 'contract_end_ts' => strtotime('2024-09-25')], $settings);
$assert($alta['suggested']['term'] === 3 && $near($alta['suggested']['rate'], $alta['risk_rate'], 1e-12) && $near($alta['suggested']['amount'], 12366384), 'high vacancy suggests 3 months at the risk rate');
$media = OwnerCreditCalculator::evaluate(['vacancy' => 'Media'] + ['gross' => 6232674, 'deductions' => ['comision' => 2110546], 'requested_amount' => 13167756, 'requested_term' => 12, 'request_ts' => strtotime('2024-03-26'), 'contract_end_ts' => strtotime('2024-09-25')], $settings);
$assert($media['suggested']['term'] === 6 && $near($media['suggested']['rate'], 0.0242, 1e-12), 'medium vacancy suggests max months at the reference rate');

// Cupo preaprobado con varios contratos (ejemplo de Cartera): canon 1.000.000 + 500.000 + 2.800.000 − 860.000 de descuentos.
// Plazo del preaprobado: meses de vigencia del contrato más largo menos el margen (aquí 0; por defecto 2).
$disbursed = (int) strtotime('2026-10-26');
$pre = OwnerCreditCalculator::preapproval([
  ['net_income' => 800000, 'max_months' => 3, 'end_ts' => strtotime('2027-01-31')], ['net_income' => 400000, 'max_months' => 7, 'end_ts' => strtotime('2027-05-31')], ['net_income' => 2240000, 'max_months' => 12, 'end_ts' => strtotime('2027-10-31')],
], $settings, $disbursed);
$assert($near($pre['net_income'], 3440000) && $near($pre['amount'], 10320000), 'preapproval = sum of net incomes × 3');
$assert($pre['max_term'] === 12 && $pre['contracts'] === 3 && date('Y-m-d', $pre['first_due_ts']) === '2026-11-30', 'preapproval term = months left of the longest contract');
$assert(OwnerCreditCalculator::preapproval([], $settings)['max_term'] === 0, 'preapproval without contracts is empty');
$short = OwnerCreditCalculator::preapproval([['net_income' => 800000, 'max_months' => 7, 'end_ts' => strtotime('2027-05-31')]], $settings, $disbursed);
$assert($short['max_term'] === 7 && str_contains($short['term_basis'], '7 meses de vigencia del contrato (fin 31/05/2027)'), 'a contract with 7 months left gives 7 installments without margin');
$long = OwnerCreditCalculator::preapproval([['net_income' => 800000, 'max_months' => 25, 'end_ts' => strtotime('2028-12-31')], ['net_income' => 400000, 'max_months' => 6, 'end_ts' => strtotime('2027-04-30')]], OwnerCreditSettings::normalize(['term_margin_months' => 2] + $settings), $disbursed);
$assert($long['max_term'] === 23 && str_contains($long['term_basis'], '25 meses de vigencia del contrato más largo') && str_contains($long['term_basis'], '− 2 de margen'), 'the maximum term is n − 2 with no 12-month cap');
$assert(OwnerCreditSettings::normalize([])['term_margin_months'] === 2 && OwnerCreditSettings::normalize(['term_margin_months' => 3])['term_margin_months'] === 3 && !array_key_exists('max_term_months', OwnerCreditSettings::normalize([])), 'the margin is configurable (2 by default) and there is no term cap');
$assert(OwnerCreditSettings::normalize(['usable_income_factor' => '70', 'min_coverage' => '1,2'])['usable_income_factor'] === 0.7 && OwnerCreditSettings::normalize(['min_coverage' => '1,2'])['min_coverage'] === 1.2, 'usable income (%) and minimum coverage (times the installment) are configurable');
$assert(OwnerCreditSettings::normalize(OwnerCreditSettings::normalize([]))['usable_income_factor'] === 1.0 && OwnerCreditSettings::normalize(['min_coverage' => '0,5'])['min_coverage'] === 1.0, '100 % stays 100 % when read back and the minimum coverage is at least 1');
$consolidated = OwnerCreditCalculator::evaluate([
  'gross' => 1000000, 'deductions' => ['comision' => 200000], 'requested_amount' => 5000000, 'requested_term' => 10,
  'request_ts' => strtotime('2026-01-01'), 'contract_end_ts' => strtotime('2026-04-15'), 'vacancy' => 'Baja',
  'other_net_income' => 2640000, 'longest_months' => 12,
], $settings);
$assert($near($consolidated['total_net_income'], 3440000) && $near($consolidated['max_amount'], 10320000), 'liquidator cap uses the net income of all the owner contracts');
$assert($consolidated['contract_months'] === 3 && $consolidated['max_months'] === 12, 'without coverage data the term is the longest contract (Excel), capped');

// Con cobertura: el plazo máximo es el mayor con ingreso en todas las cuotas y la sugerencia nunca pasa de lo pedido.
$first = \SCM\Modules\OwnerCredits\OwnerCreditCoverage::firstDueTs((int) strtotime('2026-10-26'));
$slots = \SCM\Modules\OwnerCredits\OwnerCreditCoverage::availability(\SCM\Modules\OwnerCredits\OwnerCreditCoverage::dueDates($first, 12), [
  ['id' => 1, 'contrato' => 'C1', 'inmueble' => 'I1', 'net_income' => 1500000.0, 'start_ts' => 0, 'end_ts' => strtotime('2027-01-31'), 'active' => true],
  ['id' => 2, 'contrato' => 'C2', 'inmueble' => 'I2', 'net_income' => 400000.0, 'start_ts' => 0, 'end_ts' => strtotime('2027-09-30'), 'active' => true],
  ['id' => 3, 'contrato' => 'cr', 'inmueble' => 'I3', 'net_income' => 600000.0, 'start_ts' => 0, 'end_ts' => strtotime('2027-04-30'), 'active' => true],
], 1.0, array_fill(0, 12, 0.0));
$covered = OwnerCreditCalculator::evaluate([
  'gross' => 1500000, 'deductions' => [], 'requested_amount' => 4000000, 'requested_term' => 9, 'request_ts' => strtotime('2026-10-26'), 'contract_end_ts' => strtotime('2027-01-31'), 'vacancy' => 'Baja',
  'other_net_income' => 1000000, 'longest_months' => 11, 'coverage' => ['availability' => $slots, 'term_limit' => 11, 'min_coverage' => 1.0],
], OwnerCreditSettings::normalize(['reference_rate' => '0.022', 'usury_annual' => '0.33'] + $settings));
$assert($covered['term_limit'] === 11 && $covered['max_months'] === 6, 'with coverage the max term is the longest one covered in every installment (11 by dates → 6)');
$assert($covered['suggested']['term'] === 6 && abs($covered['suggested']['payment'] - 718930.64) < 0.001 && !$covered['suggested']['uncovered'], 'the suggestion lowers the requested 9 months to the covered 6, at the cents installment');
$none = OwnerCreditCalculator::evaluate(['requested_amount' => 40000000, 'coverage' => ['availability' => $slots, 'term_limit' => 11, 'min_coverage' => 1.0]] + $covered + ['gross' => 1500000, 'deductions' => [], 'requested_term' => 9, 'request_ts' => strtotime('2026-10-26'), 'contract_end_ts' => strtotime('2027-01-31'), 'vacancy' => 'Baja'], OwnerCreditSettings::normalize(['reference_rate' => '0.022', 'usury_annual' => '0.33'] + $settings));
$assert($none['max_months'] === 0 && $none['suggested']['uncovered'], 'an amount no term can cover is flagged');

// Plan de descuento: un solo contrato si alcanza; si no, se reparte; si un contrato termina, su parte pasa a otros.
$plan = OwnerCreditCalculator::discountPlan([
  ['id' => 1, 'contrato' => 'C1', 'net_income' => 800000, 'covers' => 3], ['id' => 2, 'contrato' => 'C2', 'net_income' => 400000, 'covers' => 7], ['id' => 3, 'contrato' => 'C3', 'net_income' => 2240000, 'covers' => 12],
], 1170000, 10, 1);
$assert(count($plan) === 1 && $plan[0]['from'] === 1 && $plan[0]['to'] === 10 && $plan[0]['parts'][0]['contrato'] === 'C3' && $plan[0]['direct'] === 0.0, 'the whole instalment comes from the one contract that covers the term');
$plan = OwnerCreditCalculator::discountPlan([['id' => 1, 'contrato' => 'A', 'net_income' => 2000000, 'covers' => 10], ['id' => 2, 'contrato' => 'B', 'net_income' => 1500000, 'covers' => 10]], 3000000, 10, 1);
$assert(count($plan) === 1 && count($plan[0]['parts']) === 2 && $near($plan[0]['parts'][0]['amount'], 3000000 * 2 / 3.5) && $near($plan[0]['parts'][1]['amount'], 3000000 * 1.5 / 3.5), 'an instalment larger than any canon is split by net income');
$plan = OwnerCreditCalculator::discountPlan([['id' => 1, 'contrato' => 'A', 'net_income' => 2000000, 'covers' => 4], ['id' => 2, 'contrato' => 'B', 'net_income' => 500000, 'covers' => 10]], 1000000, 10, 1);
$assert(count($plan) === 2 && $plan[0]['to'] === 4 && $plan[0]['parts'][0]['contrato'] === 'A' && $plan[1]['from'] === 5 && $plan[1]['parts'][0]['contrato'] === 'B' && $near($plan[1]['direct'], 500000), 'when a contract ends its share moves to the others and the rest is paid directly');

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
// Política 2,42 % × 0,95 = 2,299 % supera la usura 28,59 % E.A. (2,1176 % mensual): se sugiere la usura.
$capped = OwnerCreditCalculator::evaluate(['gross' => 2000000, 'deductions' => ['otros' => 300000], 'requested_amount' => 4000000, 'requested_term' => 12, 'request_ts' => time(), 'contract_end_ts' => strtotime('+14 months'), 'vacancy' => 'Baja'], ['usury_annual' => 0.2859] + $settings);
$assert($capped['suggested']['rate_capped'] && $near($capped['suggested']['rate'], $capped['usury_monthly_legal'], 1e-12) && $near($capped['policy_rate'], 0.02299, 1e-12), 'suggested rate never exceeds the legal usury');
$assert($near($capped['suggested']['payment'], OwnerCreditCalculator::payment($capped['usury_monthly_legal'], 12, 4000000)), 'suggested payment uses the capped rate');
$riskCapped = OwnerCreditCalculator::evaluate(['gross' => 1000000, 'deductions' => [], 'requested_amount' => 9000000, 'requested_term' => 6, 'request_ts' => time(), 'contract_end_ts' => strtotime('+14 months'), 'vacancy' => 'Alta'], ['usury_annual' => 0.2859] + $settings);
$assert($riskCapped['risk_rate'] > $riskCapped['usury_monthly_legal'] && $near($riskCapped['suggested']['rate'], $riskCapped['usury_monthly_legal'], 1e-12), 'high vacancy risk rate is capped too');
$assert($ev['risk_applies'] === true && $riskCapped['risk_applies'] === true, 'the risk rate applies when the request exceeds the cap');
$below = OwnerCreditCalculator::evaluate(['gross' => 15000000, 'deductions' => [], 'requested_amount' => 3000000, 'requested_term' => 6, 'request_ts' => time(), 'contract_end_ts' => strtotime('+14 months'), 'vacancy' => 'Alta'], ['usury_annual' => 0.2859] + $settings);
$assert($below['risk_applies'] === false && $below['risk_rate'] === 0.0 && $near($below['suggested']['amount'], 3000000) && $near($below['suggested']['rate'], min(0.0242, $below['usury_monthly_legal']), 1e-12), 'below the cap there is no risk rate and high vacancy keeps the request at the reference rate');
$warning = \SCM\Modules\OwnerCredits\OwnerCreditView::usuryWarning(0.0242, 0.0211757);
$assert(str_contains($warning, 'supera la usura') && str_contains($warning, 'máximo 2,11 %') && \SCM\Modules\OwnerCredits\OwnerCreditView::usuryWarning(0.02, 0.0211757) === '', 'a reference rate above the usury warns how far to lower it');
$assert(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Modules/OwnerCredits/OwnerCreditService.php'), "La tasa supera la usura"), 'approving above the usury is blocked even with justification');
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
// Siniestro: el del contrato principal; los demás contratos marcados por Cartera se nombran aparte.
$otherSiniestro = array_replace_recursive($pdfWork, ['contract' => ['siniestrado' => false], 'evaluation' => ['analyst' => ['siniestro' => true, 'siniestrados' => ['#2000'], 'has_credits' => false]]]);
$otherHtml = \SCM\Modules\OwnerCredits\OwnerCreditPdf::html($otherSiniestro, 'Funcionario');
$assert(str_contains($otherHtml, 'Contrato principal no siniestrado · Otros contratos siniestrados: #2000'), 'a siniestro on another contract is not shown as the main contract siniestro');
$mainSiniestro = \SCM\Modules\OwnerCredits\OwnerCreditPdf::html(array_replace_recursive($otherSiniestro, ['contract' => ['siniestrado' => true], 'evaluation' => ['analyst' => ['siniestrados' => ['#890']]]]), 'Funcionario');
$assert(str_contains($mainSiniestro, '<td>Siniestrado</td>') || str_contains($mainSiniestro, '>Siniestrado<'), 'a siniestro on the main contract is shown as siniestrado');
$newForm = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->newForm();
$assert(str_contains($newForm, 'Documento o nombre del propietario') && str_contains($newForm, 'Cédula, NIT o nombre') && str_contains($newForm, 'name="owner_key"') && !str_contains($newForm, 'name="contract_end"') && !str_contains($newForm, '¿Cuál propietario?'), 'new credit asks for the owner document, without the owner step or the contract end date');
$healthy = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace(array_replace_recursive($work, ['contract' => ['siniestrado' => false]]), true, true);
$assert(str_contains($healthy, 'name="siniestro"') && str_contains($healthy, '<option value="no" selected>No</option>') && str_contains($healthy, 'Cartera no marcó ninguno de sus contratos'), 'a contract without a siniestro mark proposes "No" and the analyst can change it');
