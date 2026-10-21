<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Support\HtmlPdfRenderer;

/** Aviso previo de revisión antes de la desocupación del inmueble (recibo de inmuebles) con el diseño de las actas de servicios públicos. */
final class ReceiptNoticeDocument
{
  public const TITLE = 'Aviso previo de revisión antes de la desocupación del inmueble';
  private const INVENTORY_URL = 'https://sucasainmobiliaria.com.co/inventario-inmueble/?numero=';

  /**
   * @param array<string,mixed> $ticket
   * @return string|null Bytes del PDF, o null si el servicio de Chromium no está disponible.
   */
  public static function pdf(array $ticket): ?string
  {
    $root = dirname(__DIR__, 3);
    return HtmlPdfRenderer::render(self::html($ticket), self::TITLE, [
      'stylesheets' => [
        $root . '/public/assets/css/ticket-completion-document.css',
        $root . '/public/assets/css/public-services-document.css',
        $root . '/public/assets/css/receipt-notice-document.css',
      ],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
  }

  /** @param array<string,mixed> $ticket */
  public static function html(array $ticket): string
  {
    $e = static fn(mixed $value): string => PublicServicesDocument::e($value);
    $value = static fn(string $key, string $default = ''): string => trim((string) ($ticket[$key] ?? '')) !== '' ? trim((string) $ticket[$key]) : $default;
    $deliveryDate = self::dateLabel($value('fecha_terminacion_contrato'));

    $assets = dirname(__DIR__, 3) . '/resources/assets/';
    $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
    $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));

    $html = '<article class="scm-acta-receipt scm-services-document scm-services-act scm-receipt-notice">'
      . '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style>'
      . '<div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>'
      . PublicServicesDocument::header(self::TITLE, false)
      . '<p class="scm-acta-receipt-date">' . $e($value('ciudad', 'Cartagena de Indias')) . ', ' . date('d/m/Y') . '</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Datos del contrato</h2>' . PublicServicesDocument::table([
        'Contrato / Inmueble SIMI' => '#' . $value('contrato', '-') . ' · ' . $value('inmueble', '-'),
        'Dirección' => $value('direccion'),
        'Arrendatario' => $value('arrendatario'),
        'Fecha prevista de desocupación' => $deliveryDate,
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section scm-services-letter"><h2>2. Comunicación</h2>'
      . '<p>Señor(a) <strong>' . $e($value('arrendatario', 'Arrendatario')) . '</strong>:</p>'
      . '<p>Teniendo en cuenta que su contrato de arrendamiento se encuentra próximo a finalizar, nos permitimos recordarle que la fecha prevista para la entrega y restitución del inmueble es el día <strong>' . $e($deliveryDate !== '' ? $deliveryDate : 'pactada en el contrato') . '</strong>.</p>'
      . '<p>Con el propósito de efectuar la entrega de manera oportuna y evitar inconvenientes posteriores, agradecemos realizar con suficiente anticipación las reparaciones que sean de su responsabilidad y verificar que se encuentren atendidas las obligaciones correspondientes a canon de arrendamiento, servicios públicos con el pago de su respectivo depósito post arrendamiento, administración —cuando aplique— y demás conceptos asociados a su ocupación.</p>'
      . '<p>El inmueble deberá ser entregado desocupado, junto con sus llaves y demás elementos recibidos, en las condiciones establecidas en el contrato de arrendamiento, teniendo en cuenta el deterioro normal derivado del uso legítimo y del transcurso del tiempo, así como con los certificados de mantenimientos de equipos entregados, cuando corresponda.</p>'
      . '<div class="scm-receipt-notice-box scm-receipt-notice-box--important"><h3>Importante</h3>'
      . '<p>En caso de que, por circunstancias atribuibles al arrendatario, la entrega formal y efectiva del inmueble se realice en una fecha posterior a la pactada, continuarán causándose los valores correspondientes a canon de arrendamiento hasta la fecha en que el inmueble sea efectivamente recibido.</p>'
      . '<p>Cuando dicha entrega se extienda a un nuevo período de facturación, se generará inicialmente el cobro del canon mensual completo vigente; una vez se produzca la entrega formal del inmueble, se realizará la liquidación correspondiente a los días efectivamente causados y se reintegrará o abonará a su favor el valor correspondiente a los días posteriores a la entrega que no hayan sido consumidos.</p></div>'
      . '<p>Por lo anterior, recomendamos atender previamente cualquier reparación, obligación o novedad que pueda retrasar el proceso de entrega. Agradecemos coordinar oportunamente con nuestro equipo la revisión y recepción del inmueble.</p>'
      . '<div class="scm-receipt-notice-box"><p><strong>Nota:</strong> Es de tener en cuenta que para efectos de la devolución del saldo restante del depósito de servicios públicos y del saldo a favor del arrendatario, este valor será reembolsado en los 60 días siguientes a la terminación del contrato de arrendamiento.</p></div>'
      . '<p>Cordialmente, SKC SuCasa Inmobiliaria.</p></section>'
      . self::links($value('registro_fotografico'), $value('id_inventario'))
      . self::signatures($ticket)
      . '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div></article>';
    return $html;
  }

  /** Registro fotográfico e inventario del contrato: ambos son enlaces. */
  private static function links(string $photosUrl, string $inventoryId): string
  {
    $link = static fn(string $url, string $label): string => '<a href="' . PublicServicesDocument::e($url) . '">' . PublicServicesDocument::e($label) . '</a>';
    $isUrl = filter_var($photosUrl, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($photosUrl, PHP_URL_SCHEME)), ['https', 'http'], true);
    $hasInventory = $inventoryId !== '' && $inventoryId !== '0';
    return '<section class="scm-acta-receipt-section scm-receipt-notice-links"><h2>3. Registro fotográfico e inventario</h2>'
      . '<table class="scm-acta-receipt-data"><tbody>'
      . '<tr><th scope="row">Registro fotográfico</th><td>' . ($isUrl ? $link($photosUrl, 'Consultar registro fotográfico del inmueble') : 'No hay registro fotográfico disponible en el contrato.') . '</td></tr>'
      . '<tr><th scope="row">Inventario del inmueble</th><td>' . ($hasInventory ? $link(self::INVENTORY_URL . rawurlencode($inventoryId), 'Consultar inventario #' . $inventoryId) : 'El contrato no tiene inventario vinculado.') . '</td></tr>'
      . '</tbody></table></section>';
  }

  /** @param array<string,mixed> $ticket */
  private static function signatures(array $ticket): string
  {
    $e = static fn(mixed $value): string => PublicServicesDocument::e($value);
    $person = static function (string $name, array $details) use ($e): string {
      return '<strong>' . $e($name) . '</strong><p>' . $e(implode(' · ', array_filter(array_map('trim', $details)))) . '</p>';
    };
    $html = '<footer class="scm-acta-receipt-footer scm-services-signatures"><div><span>Realizado por</span>'
      . $person(trim((string) ($ticket['nombre_empleado'] ?? '')) ?: 'Funcionario asignado', ['Verificador de inmuebles', (string) ($ticket['celular_empleado'] ?? ''), (string) ($ticket['correo_empleado'] ?? '')]) . '</div>';
    if (trim((string) ($ticket['nombre_contractual'] ?? '')) !== '') {
      $html .= '<div><span>Coordinación contractual</span>' . $person((string) $ticket['nombre_contractual'], [(string) ($ticket['celular_contractual'] ?? ''), (string) ($ticket['correo_contractual'] ?? '')]) . '</div>';
    }
    return $html . '<p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer>';
  }

  private static function dateLabel(string $value): string
  {
    if ($value === '') return '';
    if (preg_match('/^\d{13}$/', $value)) return date('d/m/Y', (int) floor((int) $value / 1000));
    if (preg_match('/^\d{9,10}$/', $value)) return date('d/m/Y', (int) $value);
    $ts = strtotime($value);
    return $ts > 0 ? date('d/m/Y', $ts) : $value;
  }
}
