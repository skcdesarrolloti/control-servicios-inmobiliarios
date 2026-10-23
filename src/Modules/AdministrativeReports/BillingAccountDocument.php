<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeReports;

use SCM\Modules\Pending\PublicServicesDocument as Doc;
use SCM\Support\HtmlPdfRenderer;

/**
 * Cuenta de cobro del funcionario: PDF con el membrete institucional y Excel con el detalle de reportes.
 * Se generan siempre desde la cuenta guardada (AdministrativeReportsService::account()).
 */
final class BillingAccountDocument
{
  /** @param array<string,mixed> $account @param array<string,string> $employee */
  public static function pdf(array $account, array $employee): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render(self::html($account, $employee), 'Cuenta de cobro ' . $account['numero'], [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF de la cuenta de cobro. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  private static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.') . ' COP';
  }

  private static function date(string $value): string
  {
    $ts = $value !== '' ? strtotime($value) : false;
    return $ts ? date('d/m/Y', $ts) : $value;
  }

  /** @param array<string,mixed> $account @param array<string,string> $employee */
  public static function html(array $account, array $employee): string
  {
    $assets = dirname(__DIR__, 3) . '/resources/assets/';
    $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
    $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
    $name = trim((string) ($employee['nombre'] ?? '')) ?: (string) $account['funcionario'];
    $document = trim((string) ($employee['documento'] ?? '')) ?: (string) $account['documento'];
    $count = count((array) $account['reportes']) ?: (int) $account['cantidad'];

    $rows = '';
    foreach ((array) $account['reportes'] as $report) {
      $rows .= '<tr><td>#' . (int) $report['id'] . '</td><td>' . ($report['fecha'] > 0 ? date('d/m/Y', (int) $report['fecha']) : '') . '</td><td>' . Doc::e($report['categoria'])
        . '</td><td>' . Doc::e($report['id_ticket'] !== '' ? '#' . $report['id_ticket'] : '') . '</td><td>' . Doc::e($report['contrato']) . '</td><td>' . Doc::e($report['inmueble'])
        . '</td><td>' . Doc::e('$' . number_format((int) $report['servicio'], 0, ',', '.')) . '</td><td>' . Doc::e('$' . number_format((int) $report['transporte'], 0, ',', '.'))
        . '</td><td>' . Doc::e('$' . number_format((int) $report['total'], 0, ',', '.')) . '</td></tr>';
    }

    return '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}'
      . '.scm-billing-detail{width:100%;border-collapse:collapse;font-size:8.5pt}.scm-billing-detail th,.scm-billing-detail td{border:1px solid #d7deea;padding:4px 5px;text-align:left}'
      . '.scm-billing-detail thead th{background:#f1f4f9}.scm-billing-detail tr{break-inside:avoid;page-break-inside:avoid}'
      . '.scm-billing-total{margin:10px 0 0;padding:10px 12px;border:1px solid #061d49;border-radius:6px;text-align:center;font-weight:700;font-size:11pt;break-inside:avoid}'
      . '.scm-billing-sign{margin-top:28px;break-inside:avoid;page-break-inside:avoid}.scm-billing-sign span{display:block;width:62mm;border-top:1px solid #061d49;margin-bottom:4px}</style>'
      . '<article class="scm-acta-receipt scm-services-document"><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>'
      . Doc::header('Cuenta de cobro No. ' . $account['numero'], false)
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . Doc::e(self::date((string) $account['fecha_creacion']) ?: date('d/m/Y')) . '</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Debe a</h2>' . Doc::table([
        'Nombre' => $name,
        'Documento' => $document,
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>2. Contratante</h2>' . Doc::table([
        'SKC SuCasa Inmobiliaria' => AdministrativeReportsService::COMPANY_NAME,
        'NIT' => AdministrativeReportsService::COMPANY_NIT,
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>3. Concepto</h2><p>Servicios administrativos y transporte correspondientes al período del '
      . Doc::e(self::date((string) $account['fecha_inicio'])) . ' al ' . Doc::e(self::date((string) $account['fecha_fin'])) . ', con ' . $count . ' reporte' . ($count === 1 ? '' : 's') . '.</p>'
      . Doc::table([
        'Servicios administrativos' => self::money((int) $account['servicio']),
        'Transportes' => self::money((int) $account['transporte']),
      ])
      . '<p class="scm-billing-total">VALOR TOTAL A COBRAR: ' . Doc::e(self::money((int) $account['total'])) . '</p></section>'
      . ($rows !== '' ? '<section class="scm-acta-receipt-section"><h2>4. Detalle de reportes</h2><table class="scm-billing-detail"><thead><tr><th>Consecutivo</th><th>Fecha</th><th>Tipo</th><th>Caso</th><th>Contrato</th><th>Inmueble</th><th>Servicio</th><th>Transporte</th><th>Total</th></tr></thead><tbody>' . $rows . '</tbody></table></section>' : '')
      . '<div class="scm-billing-sign"><p>Firma:</p><br><br><span></span><strong>' . Doc::e(mb_strtoupper($name, 'UTF-8')) . '</strong><p>Documento: ' . Doc::e($document) . '</p></div>'
      . '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div></article>';
  }

  /** Detalle en Excel (HTML tabular .xls, igual que la exportación de casos). @param array<string,mixed> $account @param array<string,string> $employee */
  public static function excel(array $account, array $employee): string
  {
    $cell = static function (mixed $value): string {
      $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = trim((string) preg_replace('/\s+/u', ' ', $text));
      if ($text !== '' && preg_match('/^[=+\-@]/', $text) === 1) $text = "'" . $text;
      return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    };
    $money = static fn(int $value): string => '$' . number_format($value, 0, ',', '.');
    $out = "\xEF\xBB\xBF" . '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #b7b7b7;padding:6px;mso-number-format:"\@";}</style></head><body>';
    $out .= '<table><tr><th colspan="11">DETALLE DE CUENTA DE COBRO ' . $cell($account['numero']) . '</th></tr><tr>';
    foreach (['Consecutivo', 'Fecha', 'Tipo', 'Servicio', 'Transporte', 'Valor total', 'Contrato', 'Inmueble', 'Arrendatario', 'Caso', 'Observación'] as $heading) $out .= '<th>' . $heading . '</th>';
    $out .= '</tr>';
    foreach ((array) $account['reportes'] as $report) {
      $out .= '<tr>';
      foreach ([$report['id'], $report['fecha'] > 0 ? date('d-m-Y', (int) $report['fecha']) : '', $report['categoria'], $money((int) $report['servicio']), $money((int) $report['transporte']),
        $money((int) $report['total']), $report['contrato'], $report['inmueble'], $report['arrendatario'], $report['id_ticket'], $report['descripcion']] as $value) {
        $out .= '<td>' . $cell($value) . '</td>';
      }
      $out .= '</tr>';
    }
    $out .= '<tr><th>TOTALES</th><td></td><td></td><th>' . $money((int) $account['servicio']) . '</th><th>' . $money((int) $account['transporte']) . '</th><th>' . $money((int) $account['total']) . '</th><td colspan="5"></td></tr></table><br>';
    $out .= '<table><tr><th colspan="2">INFORMACIÓN ADICIONAL</th></tr>';
    foreach ([
      'Funcionario' => trim((string) ($employee['nombre'] ?? '')) ?: $account['funcionario'],
      'Documento' => trim((string) ($employee['documento'] ?? '')) ?: $account['documento'],
      'Correo DIAN' => (string) ($employee['correo'] ?? ''),
      'Período' => self::date((string) $account['fecha_inicio']) . ' al ' . self::date((string) $account['fecha_fin']),
      'Reportes incluidos' => (string) (count((array) $account['reportes']) ?: $account['cantidad']),
      'Fecha de la cuenta' => self::date((string) $account['fecha_creacion']),
    ] as $label => $value) {
      $out .= '<tr><th>' . $cell($label) . '</th><td>' . $cell($value) . '</td></tr>';
    }
    return $out . '</table></body></html>';
  }
}
