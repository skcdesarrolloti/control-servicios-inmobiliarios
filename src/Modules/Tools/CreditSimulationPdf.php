<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Support\HtmlPdfRenderer;

/**
 * PDF de Herramientas › Simulador de crédito para entregarle al cliente: valor, plazo, tasa, cuota y plan de pagos con
 * fechas. Mismo membrete y estilos que la simulación de ingreso neto (OwnerPreapprovalPdf).
 */
final class CreditSimulationPdf
{
  public const TITLE = 'Simulación de crédito';

  /** @param array<string,mixed> $result CreditSimulationService::simulate() @param array{name?:string,cargo?:string,phone?:string,email?:string} $signer */
  public static function bytes(array $result, array $signer): string
  {
    $css = dirname(__DIR__, 3) . '/public/assets/css/';
    $bytes = HtmlPdfRenderer::render(self::html($result, $signer, true), self::TITLE, [
      'stylesheets' => [$css . 'ticket-completion-document.css', $css . 'public-services-document.css', $css . 'owner-preapproval-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root',
    ]);
    if ($bytes === null) throw new \RuntimeException('No se pudo generar el PDF con Chromium. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  public static function filename(array $result): string
  {
    $input = $result['input'];
    $base = (string) ($input['document'] !== '' ? $input['document'] : $input['name']);
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base)), '-'));
    return 'simulacion-credito-' . ($slug !== '' ? $slug . '-' : '') . date('Y-m-d') . '.pdf';
  }

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /** @param array<string,mixed> $result @param array{name?:string,cargo?:string,phone?:string,email?:string} $signer */
  public static function html(array $result, array $signer, bool $pdf = false): string
  {
    $input = $result['input'];
    $sim = $result['simulation'];
    $money = static fn($value): string => CreditSimulationService::money((float) $value);
    $months = static fn(int $count): string => $count . ($count === 1 ? ' mes' : ' meses');

    $letterhead = $footerImage = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
      $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style><div class="scm-preapproval-topline"></div><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>';
      $footerImage = '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div>';
    }

    $client = array_filter([
      'Nombre' => (string) $input['name'],
      'Cédula o NIT' => (string) $input['document'],
      'Tipo de cliente' => CreditSimulationService::RECIPIENTS[$input['recipient']] ?? '',
    ], static fn(string $value): bool => $value !== '');
    $clientTable = '';
    // Solo con nombre o documento: el tipo de cliente por sí solo no identifica a nadie.
    if ((string) $input['name'] === '' && (string) $input['document'] === '') $client = [];
    foreach ($client as $label => $value) $clientTable .= '<tr><th scope="row">' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>';

    $conditions = [
      ['Valor del crédito', '', $money($sim['amount'])],
      ['Destinación', '', (string) $input['purpose'] !== '' ? (string) $input['purpose'] : '—'],
      ['Plazo', 'Cuotas mensuales', $months((int) $sim['term'])],
      ['Tasa de interés', 'Nominal mes vencido', CreditSimulationService::percent((float) $sim['rate'], 2) . ' (' . CreditSimulationService::percent((float) $sim['effective_annual'], 2) . ' E.A.)'],
      ['Fecha de desembolso estimada', '', date('d/m/Y', (int) $input['disbursed_ts'])],
      ['Primera y última cuota', 'Vencen el día 30 (en febrero, su último día)', (int) $sim['first_due_ts'] > 0 ? date('d/m/Y', (int) $sim['first_due_ts']) . ' a ' . date('d/m/Y', (int) $sim['last_due_ts']) : '—'],
      ['Total intereses', '', $money($sim['total_interest'])],
      ['Total a pagar', '', $money($sim['total'])],
    ];
    $conditionRows = '';
    foreach ($conditions as [$label, $note, $value]) {
      $conditionRows .= '<tr><th scope="row">' . self::e($label) . ($note !== '' ? '<small>' . self::e($note) . '</small>' : '') . '</th><td class="is-money">' . self::e($value) . '</td></tr>';
    }

    $planRows = '';
    foreach ($sim['rows'] as $row) {
      $planRows .= '<tr><th scope="row">' . (int) $row['mes'] . '</th><td class="is-money">' . ((int) $row['fecha'] > 0 ? self::e(date('d/m/Y', (int) $row['fecha'])) : '') . '</td><td class="is-money">' . self::e($money($row['cuota'])) . '</td><td class="is-money">' . self::e($money($row['interes'])) . '</td><td class="is-money">' . self::e($money($row['abono'])) . '</td><td class="is-money">' . self::e($money($row['saldo'])) . '</td></tr>';
    }
    $planRows .= '<tr class="is-total"><th scope="row">Total</th><td></td><td class="is-money">' . self::e($money($sim['total'])) . '</td><td class="is-money">' . self::e($money($sim['total_interest'])) . '</td><td class="is-money">' . self::e($money($sim['amount'])) . '</td><td></td></tr>';

    $compareRows = '';
    foreach ($result['compare'] as $option) {
      $chosen = (int) $option['term'] === (int) $sim['term'];
      $compareRows .= '<tr' . ($chosen ? ' class="is-total"' : '') . '><th scope="row">' . self::e($months((int) $option['term'])) . ($chosen ? '<small>Plazo simulado</small>' : '') . '</th><td class="is-money">' . self::e($money($option['payment'])) . '</td><td class="is-money">' . self::e($money($option['total_interest'])) . '</td><td class="is-money">' . self::e($money($option['total'])) . '</td></tr>';
    }

    $section = 1;
    $html = '<article class="scm-acta-receipt scm-services-document scm-preapproval-document scm-credit-simulation-document">' . $letterhead
      . '<header class="scm-acta-receipt-head"><h1>' . self::TITLE . '</h1></header>'
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . date('d/m/Y') . ' · Documento informativo</p>'
      . '<section class="scm-preapproval-highlight"><div><span>Valor del crédito</span><strong>' . self::e($money($sim['amount'])) . '</strong><em>A ' . self::e($months((int) $sim['term'])) . ' · ' . self::e(CreditSimulationService::percent((float) $sim['rate'], 2)) . ' mensual</em></div>'
      . '<div class="is-credit"><span>Cuota mensual estimada</span><strong>' . self::e($money($sim['payment'])) . '</strong><em>Total a pagar ' . self::e($money($sim['total'])) . '</em></div></section>';
    if ($clientTable !== '') $html .= '<section class="scm-acta-receipt-section"><h2>' . $section++ . '. Cliente</h2><table class="scm-acta-receipt-data"><tbody>' . $clientTable . '</tbody></table></section>';
    $html .= '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section++ . '. Condiciones simuladas</h2><table class="scm-preapproval-income"><tbody>' . $conditionRows . '</tbody></table></section>'
      . '<section class="scm-acta-receipt-section"><h2>' . $section++ . '. Plan de pagos</h2><p class="scm-preapproval-lead">Cuota fija mensual: cada mes paga intereses sobre el saldo y el resto abona a capital; la última cuota se ajusta para dejar el saldo en cero.</p>'
      . '<table class="scm-preapproval-income scm-preapproval-months"><thead><tr><th>Cuota</th><th>Vence</th><th>Valor cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' . $planRows . '</tbody></table></section>'
      . '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section++ . '. Cuota según el plazo</h2><table class="scm-preapproval-income scm-preapproval-months"><thead><tr><th>Plazo</th><th>Cuota mensual</th><th>Total intereses</th><th>Total a pagar</th></tr></thead><tbody>' . $compareRows . '</tbody></table></section>';
    if (trim((string) $input['notes']) !== '') $html .= '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section . '. Observaciones</h2><p class="scm-preapproval-notes">' . nl2br(self::e($input['notes'])) . '</p></section>';

    $signerName = trim((string) ($signer['name'] ?? ''));
    $cargo = trim((string) ($signer['cargo'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($signer['phone'] ?? '')) ?: '';
    if (strlen($phone) === 12 && str_starts_with($phone, '57')) $phone = substr($phone, 2);
    $contact = array_values(array_filter([$phone !== '' ? 'Cel. ' . $phone : '', trim((string) ($signer['email'] ?? ''))]));
    return $html
      . '<p class="scm-preapproval-disclaimer"><strong>Importante:</strong> ' . self::e(OwnerCreditService::DISCLAIMER) . '</p>'
      . '<footer class="scm-acta-receipt-footer scm-services-signatures"><div class="scm-preapproval-signer"><span>Realizado por</span><strong>' . self::e($signerName !== '' ? $signerName : 'SKC SuCasa Inmobiliaria') . '</strong>'
      . '<p>' . self::e($cargo !== '' ? $cargo : 'SKC SuCasa Inmobiliaria') . '</p>' . ($contact !== [] ? '<p>' . self::e(implode(' · ', $contact)) . '</p>' : '') . '</div>'
      . '<p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer>'
      . $footerImage . '</article>';
  }
}
