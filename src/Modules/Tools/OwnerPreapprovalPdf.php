<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Support\HtmlPdfRenderer;

/**
 * PDF de la simulación de ingreso neto y crédito preaprobado que se le entrega al propietario. Usa el diseño de las actas
 * de revisión de servicios públicos (membrete, hojas de estilo y Chromium vía HtmlPdfRenderer).
 */
final class OwnerPreapprovalPdf
{
  public const TITLE = 'Simulación de ingreso neto y crédito preaprobado';

  /**
   * @param array<string,mixed> $estimate OwnerPreapprovalService::estimate()
   * @param array{name?:string,cargo?:string,phone?:string,email?:string} $signer OwnerPreapprovalService::signer()
   */
  public static function bytes(array $estimate, array $signer): string
  {
    $css = dirname(__DIR__, 3) . '/public/assets/css/';
    $bytes = HtmlPdfRenderer::render(self::html($estimate, $signer, true), self::TITLE, [
      'stylesheets' => [$css . 'ticket-completion-document.css', $css . 'public-services-document.css', $css . 'owner-preapproval-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root',
    ]);
    if ($bytes === null) throw new \RuntimeException('No se pudo generar el PDF con Chromium. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  public static function filename(array $estimate): string
  {
    $owner = $estimate['owner'];
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) ($owner['document'] !== '' ? $owner['document'] : $owner['name']))), '-'));
    return 'simulacion-ingreso-neto-' . ($slug !== '' ? $slug : 'propietario') . '-' . date('Y-m-d') . '.pdf';
  }

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function money(float $value): string
  {
    return '$' . number_format(round($value), 0, ',', '.');
  }

  /** @param array<string,string> $rows */
  private static function table(array $rows): string
  {
    $html = '<table class="scm-acta-receipt-data"><tbody>';
    foreach ($rows as $label => $value) $html .= '<tr><th scope="row">' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>';
    return $html . '</tbody></table>';
  }

  /** Fila concepto | frecuencia | valor; la nota aclara la unidad (seguro de 12 meses, intereses si se difiere). */
  private static function row(string $label, string $unit, float $value, string $note = '', string $class = ''): string
  {
    return '<tr' . ($class !== '' ? ' class="' . $class . '"' : '') . '><th scope="row">' . self::e($label) . ($note !== '' ? '<small>' . self::e($note) . '</small>' : '') . '</th><td class="is-unit">' . self::e($unit) . '</td><td class="is-money">' . self::e(self::money($value)) . '</td></tr>';
  }

  /**
   * @param array<string,mixed> $estimate
   * @param array{name?:string,cargo?:string,phone?:string,email?:string} $signer quien firma: nombre, cargo, celular y correo
   */
  public static function html(array $estimate, array $signer, bool $pdf = false): string
  {
    $owner = $estimate['owner'];
    $term = $estimate['term'];
    $termText = $term === null ? 'Según la vigencia del contrato' : ((int) $term > 0 ? 'Hasta ' . (int) $term . ' cuotas mensuales' : 'Sin plazo disponible por vigencia');
    $amount = (float) $estimate['amount'];

    $letterhead = $footerImage = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
      $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style><div class="scm-preapproval-topline"></div><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>';
      $footerImage = '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div>';
    }

    $signerName = trim($signer['name'] ?? '');
    $cargo = trim($signer['cargo'] ?? '');
    $phone = trim($signer['phone'] ?? '');
    $contact = array_values(array_filter([$phone !== '' ? 'Cel. ' . $phone : '', trim($signer['email'] ?? '')]));
    $signerLines = '<p>' . self::e($cargo !== '' ? $cargo : 'SKC SuCasa Inmobiliaria') . '</p>'
      . ($contact !== [] ? '<p>' . self::e(implode(' · ', $contact)) . '</p>' : '');

    $income = '';
    foreach ($estimate['sources'] as $index => $item) {
      $sim = is_array($item['sim'] ?? null) ? $item['sim'] : null;
      $installmentsNote = $sim !== null && $sim['cuotas'] > 0 ? ($sim['cuotas'] === 1 ? 'Se paga en 1 pago' : 'Se paga en ' . $sim['cuotas'] . ' cuotas de ' . self::money((float) $sim['cuota'])) . '. ' : '';
      // Solo los descuentos que tiene el inmueble: las filas en $0 alargan el documento sin informar nada.
      $rows = self::row('Canon mensual', 'Mensual (mes)', (float) $item['canon']);
      if ($sim !== null && $sim['administracion'] > 0) $rows .= self::row('Administración', 'Mensual (mes)', (float) $sim['administracion'], 'Informativa: la inmobiliaria la paga a la copropiedad; no se descuenta del canon, pero hace parte de la base de la comisión y de la garantía.', 'is-info');
      if ($item['comision'] > 0) $rows .= self::row('(−) Comisión de administración (IVA incluido)', 'Mensual (mes)', (float) $item['comision'], $sim !== null ? (string) ($sim['detail']['Comisión de administración'] ?? '') : '');
      if ($item['garantia'] > 0) $rows .= self::row('(−) Garantía inmobiliaria', 'Mensual (mes)', (float) $item['garantia'], $sim !== null ? (string) ($sim['detail']['Garantía inmobiliaria'] ?? $sim['detail']['Póliza Berkley'] ?? $sim['detail']['Póliza Seguros Mundial'] ?? '') : '');
      // La póliza de servicios públicos siempre sale: en $0 si ya se pagó o si el inmueble no la tiene (con el aviso).
      $policy = (string) ($item['poliza'] ?? 'si');
      if ($item['seguro'] > 0) $rows .= self::row($sim !== null && $sim['modalidad'] !== OwnerNetIncomeSimulator::MODALITIES['defecto'] ? '(−) Póliza ' . $sim['modalidad'] : '(−) Póliza de servicios públicos', '12 meses', (float) $item['seguro'], $installmentsNote . 'Permanece mientras la vigencia del contrato continúa. Se descuentan ' . self::money($item['seguro'] / 12) . ' por mes en promedio.');
      if ($item['seguro'] <= 0 || $policy !== 'si') {
        $rows .= $policy === 'no'
          ? self::row('(−) Póliza de servicios públicos', '12 meses', 0.0, OwnerPreapprovalService::NO_SERVICES_POLICY, 'is-warning')
          : self::row('(−) Póliza de servicios públicos', '12 meses', 0.0, $policy === 'pagada' ? 'Ya se pagó: no se descuenta.' : '');
      }
      if ($item['intereses'] > 0) $rows .= self::row('(−) Intereses', '12 meses', (float) $item['intereses'], 'Aplica si el seguro se difiere en cuotas. Se descuentan ' . self::money($item['intereses'] / 12) . ' por mes.');
      // El ajuste al descuento mínimo no se le muestra al propietario: va dentro de otros descuentos para que cuadre el neto.
      $others = (float) $item['otros'] + (float) ($item['ajuste'] ?? 0);
      if ($others > 0) $rows .= self::row('(−) Otros descuentos', 'Mensual (mes)', $others);
      $rows .= self::row('Ingreso neto mensual', 'Mensual (mes)', (float) $item['net'], $item['duracion'] > 0 ? 'Duración del contrato: ' . $item['duracion'] . ' meses' : '', 'is-total');
      $title = $item['meta'] !== '' ? $item['meta'] : ($sim === null ? '' : ($sim['modalidad'] === OwnerNetIncomeSimulator::MODALITIES['defecto'] ? 'Simulación con ' . $sim['aseguradora'] : 'Simulación con póliza ' . $sim['modalidad']));
      $income .= '<div class="scm-preapproval-property"><h3>Inmueble ' . ($index + 1) . ($title !== '' ? ' <span>' . self::e($title) . '</span>' : '') . '</h3>'
        . '<table class="scm-preapproval-income"><thead><tr><th>Concepto</th><th>Frecuencia</th><th>Valor</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    $calc = self::row('Ingreso neto mensual total (' . count($estimate['sources']) . (count($estimate['sources']) === 1 ? ' inmueble)' : ' inmuebles)'), 'Mensual (mes)', (float) $estimate['net'])
      . ($term !== null ? '<tr><th scope="row">Plazo máximo estimado<small>Duración del contrato más largo (' . (int) $estimate['longest_months'] . ' meses) menos ' . (int) $estimate['term_margin_months'] . ' de margen.</small></th><td class="is-unit">Cuotas</td><td class="is-money">' . self::e($termText) . '</td></tr>' : '')
      . self::row('Crédito preaprobado estimado', 'Hasta', $amount, '', 'is-total');

    // Lo que el propietario puede resolver con el crédito: solo si respondió que tiene algo pendiente. Muestra lo que
    // escribió el consultor o, sin valores, los usos posibles.
    $section = 4;
    $uses = '';
    $need = array_sum($estimate['uses']);
    foreach (OwnerPreapprovalService::USES as $key => [$label, $text]) {
      if ($need > 0 && !isset($estimate['uses'][$key])) continue;
      $uses .= '<li><strong>' . self::e($label) . '</strong>' . ($need > 0 ? '<b>' . self::e(self::money((float) $estimate['uses'][$key])) . '</b>' : '') . '<span>' . self::e($text) . '</span></li>';
    }
    $coverage = $need <= 0 ? ''
      : '<p class="scm-preapproval-coverage' . ($need <= $amount ? '' : ' is-partial') . '">' . ($need <= $amount
        ? 'Su crédito preaprobado cubre los ' . self::e(self::money($need)) . ' que necesita y le quedan ' . self::e(self::money($amount - $need)) . ' disponibles.'
        : 'Necesita ' . self::e(self::money($need)) . ': su crédito preaprobado cubre ' . self::e(self::money($amount)) . '.') . '</p>';
    $usesSection = !empty($estimate['needs']) ? '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section++ . '. Lo que puede resolver con su crédito</h2><p class="scm-preapproval-lead">Sin sacar dinero de su bolsillo, puede ponerse al día y mantener su inmueble en buen estado.</p><ul class="scm-preapproval-uses">' . $uses . '</ul>' . $coverage . '</section>' : '';
    $conditions = '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section++ . '. Condiciones para la aprobación del crédito</h2><ol class="scm-preapproval-conditions">';
    foreach (OwnerPreapprovalService::CONDITIONS as $condition) $conditions .= '<li>' . self::e($condition) . '</li>';
    $conditions .= '</ol></section>';
    $notes = $estimate['notes'] !== '' ? '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>' . $section . '. Observaciones</h2><p class="scm-preapproval-notes">' . nl2br(self::e($estimate['notes'])) . '</p></section>' : '';

    return '<article class="scm-acta-receipt scm-services-document scm-preapproval-document">' . $letterhead
      . '<header class="scm-acta-receipt-head"><h1>' . self::TITLE . '</h1></header>'
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . date('d/m/Y') . ' · Documento informativo para el propietario</p>'
      . '<section class="scm-preapproval-highlight"><div><span>Ingreso neto mensual</span><strong>' . self::e(self::money((float) $estimate['net'])) . '</strong><em>Canon ' . self::e(self::money((float) $estimate['gross'])) . ' menos comisión, garantía y seguros</em></div>'
      . '<div class="is-credit"><span>Crédito preaprobado hasta</span><strong>' . self::e(self::money($amount)) . '</strong><em>' . self::e($termText) . '</em></div></section>'
      . '<section class="scm-acta-receipt-section"><h2>1. Propietario</h2>' . self::table(array_filter([
        'Nombre' => $owner['name'], 'Cédula o NIT' => $owner['document'], 'Celular' => $owner['phone'], 'Correo' => $owner['email'],
        'Vínculo con SuCasa' => $estimate['registered'] ? 'Propietario registrado (contratos vigentes)' : 'Propietario aún no registrado (simulación con las tarifas de SuCasa)',
      ], static fn(string $value): bool => $value !== '')) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>2. Ingreso neto por inmueble</h2><p class="scm-preapproval-lead">Los valores son mensuales, salvo el seguro, que se registra por sus 12 meses y se descuenta mes a mes.</p>' . $income . '</section>'
      . '<section class="scm-acta-receipt-section scm-preapproval-keep"><h2>3. Crédito preaprobado</h2><table class="scm-preapproval-income"><tbody>' . $calc . '</tbody></table></section>'
      . $usesSection . $conditions . $notes
      . '<p class="scm-preapproval-disclaimer"><strong>Importante:</strong> ' . self::e(OwnerPreapprovalView::DISCLAIMER) . '</p>'
      . '<footer class="scm-acta-receipt-footer scm-services-signatures"><div class="scm-preapproval-signer"><span>Atentamente,</span><strong>' . self::e($signerName !== '' ? $signerName : 'SKC SuCasa Inmobiliaria') . '</strong>' . $signerLines . '</div><p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer>'
      . $footerImage . '</article>';
  }
}
