<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Modules\Pending\PublicServicesDocument as Doc;
use SCM\Support\HtmlPdfRenderer;

/**
 * PDF interno del crédito (solo para funcionarios): solicitud, evaluación y decisión con su tabla de
 * amortización, con el membrete y los estilos de las actas de servicios públicos. No se envía al propietario.
 */
final class OwnerCreditPdf
{
  /** @param array<string,mixed> $work OwnerCreditService::workspace() */
  public static function bytes(array $work, string $generatedBy): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render(self::html($work, $generatedBy, true), self::title($work), [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css', $root . '/public/assets/css/owner-credit-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF del crédito con Chromium. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  /** @param array<string,mixed> $work */
  public static function title(array $work): string
  {
    $credit = $work['credit'];
    return match ((string) $credit['status']) {
      'aprobado' => 'Crédito aprobado',
      'rechazado' => 'Crédito no aprobado',
      'pagado' => 'Crédito pagado',
      'anulado' => 'Crédito anulado',
      'simulacion' => 'Simulación de crédito',
      default => 'Solicitud de crédito',
    } . ((int) $credit['id'] > 0 ? ' #' . (int) $credit['id'] : ' · Contrato ' . $credit['contract_code']);
  }

  /** @param array<string,mixed> $work */
  /**
   * 5.2 Cobertura de pago de las condiciones (regla, parámetros y resultado), para dejar el rastro del ajuste.
   * @param array<string,mixed>|null $c OwnerCreditService::coverageSummary()
   */
  private static function coverage(?array $c, callable $money, callable $date, bool $simulation = false): string
  {
    if (!is_array($c) || (int) ($c['term'] ?? 0) <= 0) return '';
    $gap = $c['shortfall'] ?? null;
    $rows = [
      'Resultado' => !empty($c['ok']) ? 'Todas las cuotas tienen ingreso disponible' : 'Hay cuotas sin ingreso disponible suficiente',
      'Primera fecha con faltante' => is_array($gap) ? $date((int) $gap['fecha']) . ': disponible ' . $money($gap['disponible']) . ' frente a una cuota de ' . $money($gap['cuota']) . ' (faltan ' . $money((float) $gap['faltante_pago'] > 0 ? $gap['faltante_pago'] : $gap['faltante_cobertura']) . ')' : 'Ninguna',
      'Mayor plazo con cobertura' => (int) $c['best_term'] > 0 ? (int) $c['best_term'] . ' meses, cuota ' . $money($c['best_payment']) : 'Ninguno con este valor',
      'Valor máximo para ' . (int) $c['max_capital_term'] . ' meses' => $money($c['max_capital']),
      'Parámetros' => 'Ingreso utilizable ' . number_format((float) $c['factor'] * 100, 0, ',', '.') . ' % · cobertura mínima ' . number_format((float) $c['min_coverage'], 2, ',', '.')
        . ((float) $c['other_obligations'] > 0 ? ' · otras obligaciones ' . $money($c['other_obligations']) : '') . ((array) ($c['credits'] ?? []) !== [] ? ' · cuotas de los créditos ' . implode(', ', array_map(static fn($id): string => '#' . (int) $id, (array) $c['credits'])) : '')
        . ' · regla ' . (string) $c['rule'],
    ];
    // Los parámetros de la política son internos: no van en la simulación que puede ver el propietario.
    if ($simulation) unset($rows['Parámetros']);
    return '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>5.2 Cobertura de pago</h2>' . Doc::table($rows) . '</section>';
  }

  public static function html(array $work, string $generatedBy, bool $pdf = false): string
  {
    $credit = $work['credit'];
    $contract = $work['contract'];
    $ev = $work['evaluation'];
    $c = $work['conditions'];
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.') . ' COP';
    $percent = static fn(float $rate): string => number_format($rate * 100, 2, ',', '.') . ' %';
    $date = static fn(int $ts): string => $ts > 0 ? date('d/m/Y', $ts) : '';
    $decided = in_array($credit['status'], ['aprobado', 'pagado', 'rechazado'], true);
    $approved = in_array($credit['status'], ['aprobado', 'pagado'], true);
    $simulation = $credit['status'] === 'simulacion';

    $letterhead = $footerImage = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
      $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>';
      $footerImage = '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div>';
    }

    $deductions = [];
    foreach (OwnerCreditService::DEDUCTIONS as $key => $label) $deductions['(−) ' . $label] = $money($ev['deductions'][$key] ?? 0);

    // Ingreso de todos los contratos del propietario (suman al tope); con uno solo, el detalle de sus descuentos.
    $others = $ev['other_contracts'] ?? [];
    $income = ['Ingreso bruto (canon)' => $money($ev['gross'])] + $deductions + ['Ingreso neto (INO)' => $money($ev['net_income'])];
    if ($others !== []) {
      $line = static fn(float $canon, float $less, float $net, int $months, string $end): string => $money($canon) . ' − ' . $money($less) . ' = ' . $money($net) . ' · ' . $months . ' meses de vigencia' . ($end !== '' ? ' (fin ' . $end . ')' : '');
      $income = ['Contrato #' . $contract['code'] . ' · SIMI ' . $credit['property'] . ' (principal)' => $line((float) $ev['gross'], (float) $ev['deductions_total'], (float) $ev['net_income'], (int) $ev['contract_months'], $contract['fin_ts'] > 0 ? date('d/m/Y', (int) $contract['fin_ts']) : '')];
      foreach ($others as $row) $income['Contrato #' . $row['contrato'] . ' · SIMI ' . $row['inmueble']] = $line((float) ($row['canon'] ?? 0), (float) ($row['deductions'] ?? 0), (float) $row['net_income'], (int) $row['max_months'], (string) ($row['fin'] ?? ''));
      $income['Ingreso neto total (' . (count($others) + 1) . ' contratos)'] = $money($ev['total_net_income']);
    }

    // De qué contrato se descuenta cada cuota.
    $plan = [];
    foreach ($work['discount_plan'] ?? [] as $segment) {
      $parts = array_map(static fn(array $part): string => 'Contrato #' . $part['contrato'] . ' ' . $money($part['amount']), $segment['parts']);
      if ($segment['direct'] >= 0.5) $parts[] = 'Pago directo del propietario ' . $money($segment['direct']);
      $plan[$segment['from'] === $segment['to'] ? 'Cuota ' . $segment['from'] : 'Cuotas ' . $segment['from'] . '–' . $segment['to']] = implode(' + ', $parts);
    }

    $policies = '';
    foreach ($work['policies'] as $policy) {
      $policies .= '<tr><td class="' . ($policy['ok'] ? 'is-ok' : 'is-bad') . '">' . ($policy['ok'] ? 'Cumple' : 'No cumple') . '</td><th scope="row">' . Doc::e($policy['label']) . '</th><td>' . Doc::e($policy['detail']) . '</td></tr>';
    }
    $schedule = '';
    foreach ($work['schedule'] as $line) {
      $schedule .= '<tr><td>' . (int) $line['mes'] . '</td><td>' . Doc::e($date((int) ($line['fecha'] ?? 0))) . '</td><td>' . Doc::e($money($line['saldo_inicial'])) . '</td><td>' . Doc::e($money($line['cuota'])) . '</td><td>' . Doc::e($money($line['interes'])) . '</td><td>' . Doc::e($money($line['abono'])) . '</td><td>' . Doc::e($money($line['saldo'])) . '</td></tr>';
    }

    $decision = $simulation
      ? ['Estado' => 'Simulación', 'Observación' => 'Simulación generada desde la solicitud de crédito: no es una aprobación. Las condiciones son las de la política al momento de generarla y quedan sujetas a la evaluación del analista.', 'Términos' => OwnerCreditService::DISCLAIMER]
      : ($decided
      ? ['Estado' => OwnerCreditService::STATUSES[$credit['status']] ?? (string) $credit['status'], 'Decidido por' => (string) $credit['decided_by_name'], 'Fecha de decisión' => $date((int) $credit['decided_at']),
        'Fuera de política' => (int) $credit['policy_override'] === 1 ? 'Sí' : 'No',
        'Cambios frente al sistema' => ($work['adjustments'] ?? []) === [] ? 'Ninguno' : implode(' · ', array_map(static fn(array $item): string => $item['label'] . ': ' . $item['detail'], $work['adjustments'])),
        'Observación / justificación' => trim((string) $credit['decision_note'])]
      : ['Estado' => 'Pendiente de evaluación', 'Observación' => 'Documento de trabajo: las condiciones son las propuestas en el liquidador al momento de generarlo.']);

    $html = '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document">' . $letterhead . Doc::header(self::title($work), false)
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . date('d/m/Y') . ' · Documento interno para funcionarios</p>'
      // La simulación se puede mostrar al propietario: el aviso va arriba, antes de cualquier valor.
      . ($simulation || !$decided ? '<p class="scm-owner-credit-disclaimer"><strong>Importante:</strong> ' . Doc::e(OwnerCreditService::DISCLAIMER) . '</p>' : '')
      . '<section class="scm-acta-receipt-section"><h2>1. Propietario y contrato</h2>' . Doc::table([
        OwnerCreditService::beneficiaryLabel($credit) . ' (beneficiario)' => (string) $credit['owner_name'] . ((string) ($credit['owner_document'] ?? '') !== '' ? ' · Documento ' . $credit['owner_document'] : ''),
        'Contrato / Inmueble SIMI' => '#' . $contract['code'] . ' · ' . $credit['property'],
        'Dirección' => (string) $credit['address'],
        'Origen' => $simulation ? 'Simulación desde la solicitud de crédito (sin registrar)' : ((int) $credit['quote_id'] > 0 ? 'Cotización #' . (int) $credit['quote_id'] . ((string) $credit['ticket_id'] !== '' ? ' · Caso #' . $credit['ticket_id'] : '') : 'Solicitud registrada en el panel'),
        'Inicio / fin del contrato' => $date((int) $contract['inicio_ts']) . ' – ' . $date((int) $contract['fin_ts']),
        'Estado del contrato' => (string) $contract['estado'],
        'Aseguradora' => (string) $contract['aseguradora'],
        'Siniestro' => isset($ev['analyst']) ? ($ev['analyst']['siniestro'] ? 'Siniestrado' . ($ev['analyst']['siniestrados'] !== [] ? ' (' . implode(', ', $ev['analyst']['siniestrados']) . ')' : '') : 'No') : ($contract['siniestrado'] ? 'Siniestrado' : 'Por verificar'),
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>2. Ingreso del propietario</h2>' . ($others !== [] ? '<p>Canon − descuentos (comisión, garantía y póliza) = ingreso neto de cada contrato.</p>' : '') . Doc::table($income) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>3. Solicitud</h2>' . Doc::table([
        ($simulation ? 'Fecha de simulación' : 'Fecha de solicitud') => $date((int) $credit['created_at']),
        'Valor solicitado' => $money($credit['requested_amount']),
        'Plazo solicitado' => (int) $credit['requested_term'] > 0 ? (int) $credit['requested_term'] . ' meses' : '',
        'Destinación' => (string) $credit['purpose'],
        'Probabilidad de desocupación' => (string) $ev['vacancy'] . (isset($ev['vacancy_signal']) ? ($ev['vacancy_signal']['level'] === $ev['vacancy'] ? ' · ' . $ev['vacancy_signal']['reason'] : ' (elegida por el analista; el sistema sugería ' . $ev['vacancy_signal']['reason'] . ')') : ''),
      ] + (($ev['vacancy_signal']['alerts'] ?? []) !== [] ? ['Avisos de desocupación' => implode(' ', $ev['vacancy_signal']['alerts'])] : [])) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>4. Política de créditos</h2>' . Doc::table([
        'Valor máximo a prestar' => $money($ev['max_amount']) . ' (' . (int) $work['settings']['income_months'] . ' × ingreso neto' . ($others !== [] ? ' total' : '') . ')',
        'Plazo máximo por vigencia' => (int) ($ev['term_limit'] ?? $ev['max_months']) . ' meses (' . OwnerCreditService::termBasis($ev) . ')',
        'Mayor plazo con cobertura' => (int) $ev['max_months'] > 0 ? (int) $ev['max_months'] . ' meses para el valor solicitado' : 'Ninguno para el valor solicitado',
      ] + ($simulation ? [] : [
        // Tasas de trabajo del analista: no van en la simulación que puede ver el propietario.
        'Tasa de referencia' => $percent((float) $work['settings']['reference_rate']) . ' N.M.V',
        'Tasa mínima (descuento máximo)' => $percent($ev['policy_rate']) . ' N.M.V',
        'Tasa para el riesgo' => ($ev['risk_applies'] ?? true) ? $percent($ev['risk_rate']) . ' N.M.V' : 'No aplica (lo pedido no supera el valor máximo)',
      ]) + [
        'Tasa de usura vigente' => $percent((float) $work['settings']['usury_annual']) . ' E.A. (' . $percent((float) $ev['usury_monthly_legal']) . ' mensual)',
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>5. ' . ($approved ? 'Condiciones aprobadas' : ($simulation ? 'Condiciones simuladas' : 'Condiciones propuestas')) . '</h2>' . Doc::table([
        'Valor del crédito' => $money($c['amount']),
        'Plazo' => (int) $c['term'] . ' meses',
        'Tasa' => $percent($c['rate']) . ' N.M.V (' . $percent((1 + $c['rate']) ** 12 - 1) . ' E.A.)',
        'Cuota mensual' => $money($c['payment']),
        'Total intereses' => $money($c['total_interest']),
        'Total a pagar' => $money($c['amount'] + $c['total_interest']),
        'Fecha de desembolso' => $date((int) ($work['disbursed_ts'] ?? 0)),
        'Primera / última cuota' => $date((int) ($work['first_payment_ts'] ?? 0)) . ' – ' . $date((int) ($work['last_payment_ts'] ?? 0)),
      ]) . '</section>'
      . ($plan !== [] ? '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>5.1 Plan de descuento por contrato</h2>' . Doc::table($plan) . '</section>' : '')
      . self::coverage($ev['coverage'] ?? null, $money, $date, $simulation)
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>6. Políticas evaluadas</h2><table class="scm-acta-receipt-data scm-owner-credit-policies"><tbody>' . $policies . '</tbody></table></section>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>7. ' . ($simulation ? 'Alcance' : 'Decisión') . '</h2>' . Doc::table($decision) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>8. Tabla de amortización</h2>'
      . ($schedule === '' ? '<p>Sin valor o plazo para calcular la tabla.</p>' : '<table class="scm-owner-credit-schedule"><thead><tr><th>Mes</th><th>Fecha</th><th>Saldo de capital</th><th>Cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' . $schedule . '</tbody></table>')
      . '</section>'
      . Doc::footer(['realizado_por' => $decided && (string) $credit['decided_by_name'] !== '' ? (string) $credit['decided_by_name'] : $generatedBy, 'realizado_por_cargo' => $decided ? 'Decisión del liquidador de créditos' : ($simulation ? 'Simulación desde la solicitud de crédito' : 'Generado desde el liquidador de créditos')])
      . $footerImage . '</article>';
    return $html;
  }
}
