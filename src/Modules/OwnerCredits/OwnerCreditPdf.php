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
      default => 'Solicitud de crédito',
    } . ' #' . (int) $credit['id'];
  }

  /** @param array<string,mixed> $work */
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

    $policies = '';
    foreach ($work['policies'] as $policy) {
      $policies .= '<tr><td class="' . ($policy['ok'] ? 'is-ok' : 'is-bad') . '">' . ($policy['ok'] ? 'Cumple' : 'No cumple') . '</td><th scope="row">' . Doc::e($policy['label']) . '</th><td>' . Doc::e($policy['detail']) . '</td></tr>';
    }
    $schedule = '';
    foreach ($work['schedule'] as $line) {
      $schedule .= '<tr><td>' . (int) $line['mes'] . '</td><td>' . Doc::e($money($line['saldo_inicial'])) . '</td><td>' . Doc::e($money($line['cuota'])) . '</td><td>' . Doc::e($money($line['interes'])) . '</td><td>' . Doc::e($money($line['abono'])) . '</td><td>' . Doc::e($money($line['saldo'])) . '</td></tr>';
    }

    $decision = $decided
      ? ['Estado' => OwnerCreditService::STATUSES[$credit['status']] ?? (string) $credit['status'], 'Decidido por' => (string) $credit['decided_by_name'], 'Fecha de decisión' => $date((int) $credit['decided_at']),
        'Fuera de política' => (int) $credit['policy_override'] === 1 ? 'Sí' : 'No', 'Observación / justificación' => trim((string) $credit['decision_note'])]
      : ['Estado' => 'Pendiente de evaluación', 'Observación' => 'Documento de trabajo: las condiciones son las propuestas en el liquidador al momento de generarlo.'];

    $html = '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document">' . $letterhead . Doc::header(self::title($work), false)
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . date('d/m/Y') . ' · Documento interno para funcionarios</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Propietario y contrato</h2>' . Doc::table([
        OwnerCreditService::beneficiaryLabel($credit) . ' (beneficiario)' => (string) $credit['owner_name'] . ((string) ($credit['owner_document'] ?? '') !== '' ? ' · Documento ' . $credit['owner_document'] : ''),
        'Contrato / Inmueble SIMI' => '#' . $contract['code'] . ' · ' . $credit['property'],
        'Dirección' => (string) $credit['address'],
        'Origen' => (int) $credit['quote_id'] > 0 ? 'Cotización #' . (int) $credit['quote_id'] . ((string) $credit['ticket_id'] !== '' ? ' · Caso #' . $credit['ticket_id'] : '') : 'Solicitud registrada en el panel',
        'Inicio / fin del contrato' => $date((int) $contract['inicio_ts']) . ' – ' . $date((int) $contract['fin_ts']),
        'Estado del contrato' => (string) $contract['estado'],
        'Aseguradora' => (string) $contract['aseguradora'],
        'Siniestro' => $contract['siniestrado'] ? 'Siniestrado' : 'Por verificar',
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>2. Ingreso del propietario</h2>' . Doc::table(['Ingreso bruto (canon)' => $money($ev['gross'])] + $deductions + ['Ingreso neto (INO)' => $money($ev['net_income'])]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>3. Solicitud</h2>' . Doc::table([
        'Fecha de solicitud' => $date((int) $credit['created_at']),
        'Valor solicitado' => $money($credit['requested_amount']),
        'Plazo solicitado' => (int) $credit['requested_term'] > 0 ? (int) $credit['requested_term'] . ' meses' : '',
        'Destinación' => (string) $credit['purpose'],
        'Probabilidad de desocupación' => (string) $ev['vacancy'],
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>4. Política de créditos</h2>' . Doc::table([
        'Valor máximo a prestar' => $money($ev['max_amount']) . ' (' . (int) $work['settings']['income_months'] . ' × ingreso neto)',
        'Meses máximos (hasta el fin del contrato)' => (string) $ev['max_months'],
        'Tasa ajustada de colocación' => $percent($ev['policy_rate']) . ' N.M.V',
        'Tasa para el riesgo' => $percent($ev['risk_rate']) . ' N.M.V',
        'Tasa de usura vigente' => $percent((float) $work['settings']['usury_annual']) . ' E.A. (' . $percent((float) $ev['usury_monthly_legal']) . ' mensual)',
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>5. ' . ($approved ? 'Condiciones aprobadas' : 'Condiciones propuestas') . '</h2>' . Doc::table([
        'Valor del crédito' => $money($c['amount']),
        'Plazo' => (int) $c['term'] . ' meses',
        'Tasa' => $percent($c['rate']) . ' N.M.V (' . $percent((1 + $c['rate']) ** 12 - 1) . ' E.A.)',
        'Cuota mensual' => $money($c['payment']),
        'Total intereses' => $money($c['total_interest']),
        'Total a pagar' => $money($c['amount'] + $c['total_interest']),
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>6. Políticas evaluadas</h2><table class="scm-acta-receipt-data scm-owner-credit-policies"><tbody>' . $policies . '</tbody></table></section>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>7. Decisión</h2>' . Doc::table($decision) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>8. Tabla de amortización</h2>'
      . ($schedule === '' ? '<p>Sin valor o plazo para calcular la tabla.</p>' : '<table class="scm-owner-credit-schedule"><thead><tr><th>Mes</th><th>Saldo de capital</th><th>Cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' . $schedule . '</tbody></table>')
      . '</section>'
      . Doc::footer(['realizado_por' => $decided && (string) $credit['decided_by_name'] !== '' ? (string) $credit['decided_by_name'] : $generatedBy, 'realizado_por_cargo' => $decided ? 'Decisión del liquidador de créditos' : 'Generado desde el liquidador de créditos'])
      . $footerImage . '</article>';
    return $html;
  }
}
