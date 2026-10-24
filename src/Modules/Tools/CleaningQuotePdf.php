<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Modules\Pending\PublicServicesDocument as Doc;
use SCM\Support\HtmlPdfRenderer;

/**
 * PDF de la calculadora de aseo (antes ventanas de impresión del plugin): cotización para el cliente,
 * reporte interno de la inmobiliaria y liquidación del funcionario. Siempre se calculan de nuevo en el servidor.
 */
final class CleaningQuotePdf
{
  public const MODES = ['cliente' => 'Cotización servicio de aseo', 'inmobiliaria' => 'Reporte interno · Servicio de aseo', 'funcionario' => 'Liquidación funcionario · Servicio de aseo'];
  public const INTERNAL_MODES = ['inmobiliaria', 'funcionario'];

  /**
   * @param array<string,mixed> $result CleaningCalculator::calculate
   * @param array<string,mixed> $config CleaningSettings::read
   * @param array{cliente?:string,direccion?:string} $client
   */
  public static function bytes(string $mode, array $result, array $config, array $client, string $generatedBy): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render(self::html($mode, $result, $config, $client, $generatedBy, true), self::MODES[$mode], [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root',
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  /**
   * @param array<string,mixed> $result
   * @param array<string,mixed> $config
   * @param array{cliente?:string,direccion?:string} $client
   */
  public static function html(string $mode, array $result, array $config, array $client, string $generatedBy, bool $pdf = false): string
  {
    if (!isset(self::MODES[$mode])) throw new \InvalidArgumentException('Tipo de PDF no válido.');
    $money = static fn($value): string => '$' . number_format(round((float) $value), 0, ',', '.');
    $percent = static fn($value): string => rtrim(rtrim(number_format((float) $value * 100, 2, ',', '.'), '0'), ',') . ' %';
    $input = $result['input'];
    $service = [
      'Área' => rtrim(rtrim(number_format((float) $input['m2'], 2, ',', '.'), '0'), ',') . ' m²',
      'Jornada' => (string) $input['jornada'],
      'Distancia' => $input['distancia'] . ' km',
      'Grado de dificultad' => 'Nivel ' . (int) $input['dificultad'],
    ];
    $clientRows = array_filter(['Cliente' => trim((string) ($client['cliente'] ?? '')), 'Inmueble / dirección' => trim((string) ($client['direccion'] ?? ''))]);

    $letterhead = $footerImage = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      // Compacto para que la cotización y el membrete inferior quepan en una carta.
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}'
        . '.scm-cleaning-document .scm-acta-receipt-data th,.scm-cleaning-document .scm-acta-receipt-data td{padding:3px 8px!important}'
        . '.scm-cleaning-document .scm-acta-receipt-section{margin:8px 0!important}.scm-cleaning-document .scm-acta-receipt-head{padding-bottom:8px!important}'
        . '.scm-cleaning-document .scm-acta-receipt-footer{margin-top:10px!important;padding-top:8px!important}.scm-cleaning-document .scm-services-letterhead-top{margin-bottom:3mm!important}'
        . '.scm-cleaning-document ul{margin:1mm 0;padding-left:5mm}'
        . '.scm-cleaning-columns{display:grid;grid-template-columns:1fr 1fr;gap:6mm}</style><div class="scm-services-letterhead-top"><img src="data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg')) . '" alt="Membrete institucional"></div>';
      // Los reportes internos no llevan el pie del membrete: así caben en una hoja.
      if ($mode === 'cliente') $footerImage = '<div class="scm-services-letterhead-bottom"><img src="data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg')) . '" alt=""></div>';
    }

    $html = '<article class="scm-acta-receipt scm-services-document scm-cleaning-document">' . $letterhead . Doc::header(self::MODES[$mode], false)
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . date('d/m/Y') . ($mode === 'cliente' ? ' · Cotización válida por 30 días' : ' · Documento interno · No compartir con clientes') . '</p>';

    if ($mode === 'cliente') {
      $lines = $result['cliente'];
      $html .= '<section class="scm-acta-receipt-section"><h2>1. Características del servicio</h2>' . Doc::table($clientRows + $service) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>2. Valor del servicio</h2>' . Doc::table([
          'Servicio de limpieza' => $money($lines['servicio']),
          'Transporte' => $money($lines['transporte']),
          'Insumos profesionales' => $money($lines['insumos']),
          'Subtotal' => $money($result['base']),
          'IVA (' . $percent($config['iva_servicios']) . ')' => $money($result['iva']),
          'Total a pagar' => $money($result['total']),
        ]) . '</section>'
        . '<div class="scm-cleaning-columns"><section class="scm-acta-receipt-section"><h2>3. El servicio incluye</h2><ul><li>Personal calificado y uniformado.</li><li>Insumos de limpieza profesionales.</li><li>Implementos y equipos necesarios.</li><li>Supervisión de calidad del servicio.</li><li>Seguro de responsabilidad civil.</li></ul></section>'
        . '<section class="scm-acta-receipt-section"><h2>4. Términos y condiciones</h2><ul><li>Pago contra entrega del servicio.</li><li>Cancelaciones con 24 horas de anticipación.</li><li>Garantía de satisfacción; servicios adicionales sujetos a cotización.</li>'
        . (!empty($result['protocolo']) ? '<li>Por el grado de dificultad, el servicio se confirma después de una visita técnica o registro fotográfico.</li>' : '') . '</ul></section></div>';
    } elseif ($mode === 'inmobiliaria') {
      $html .= '<section class="scm-acta-receipt-section"><h2>1. Datos del servicio</h2>' . Doc::table($clientRows + $service + ['Tipo de trabajador' => CleaningCalculator::WORKERS[$input['trabajador']], 'Horas estimadas' => rtrim(rtrim(number_format((float) $result['horas'], 2, ',', '.'), '0'), ',')]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>2. Desglose de costos</h2>' . Doc::table([
          'Mano de obra (cliente)' => $money($result['mano_obra']),
          'Transporte (cliente)' => $money($result['transporte']),
          'Insumos' => $money($result['insumos']),
          'Costo directo' => $money($result['costo_directo']),
        ]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>3. Estructura de precios y márgenes</h2>' . Doc::table([
          'Precio contratista (+ margen ' . $percent($config['margen_contratista']) . ')' => $money($result['precio_contratista']),
          'Precio base (sin IVA)' => $money($result['base']),
          'Utilidad inmobiliaria (base − contratista)' => $money($result['utilidad_inmobiliaria']),
          'IVA (' . $percent($config['iva_servicios']) . ')' => $money($result['iva']),
          'Total factura cliente' => $money($result['total']),
        ]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>4. Utilidad real</h2>' . Doc::table([
          'Costo mano de obra real' => $money($result['real_mano_obra']),
          'Auxilio de transporte real' => $money($result['real_transporte']),
          'Insumos' => $money($result['insumos']),
          'Utilidad real del servicio' => $money($result['utilidad_real']),
        ]) . '</section>';
    } else {
      $payroll = round((float) $result['real_mano_obra']) + round((float) $result['real_transporte']);
      $html .= '<section class="scm-acta-receipt-section"><h2>1. Servicio</h2>' . Doc::table($clientRows + $service) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>2. Liquidación de mano de obra</h2>' . Doc::table([
          'Valor hora base (SMLMV / 240)' => $money($result['valor_hora_base']),
          'Factor prestacional' => '× ' . rtrim(rtrim(number_format((float) $config['factor_prestacional'], 2, ',', '.'), '0'), ','),
          'Pago nómina' => $money($result['real_mano_obra']),
        ]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>3. Auxilio de transporte</h2>' . Doc::table([
          'Pasaje unitario' => $money($config['valor_pasaje_trans']),
          'Trayectos (ida y regreso)' => '2',
          'Cobertura de la empresa' => '50 %',
          'Auxilio de transporte' => $money($result['real_transporte']),
        ]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>4. Insumos asignados</h2>' . Doc::table(['Costo de insumos del servicio' => $money($result['insumos'])]) . '</section>'
        . '<section class="scm-acta-receipt-section"><h2>5. Total</h2>' . Doc::table(['Total a pagar (nómina + transporte)' => $money($payroll)]) . '</section>';
    }

    return $html . Doc::footer(['realizado_por' => $generatedBy, 'realizado_por_cargo' => 'Generado desde la calculadora de aseo']) . $footerImage . '</article>';
  }
}
