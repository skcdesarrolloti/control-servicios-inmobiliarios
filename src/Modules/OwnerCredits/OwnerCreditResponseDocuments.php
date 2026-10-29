<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Modules\LeaseContracts\LeaseAnnexesDocument;
use SCM\Modules\Pending\PublicServicesDocument as Doc;
use SCM\Support\HtmlPdfRenderer;

/**
 * Documentos de la respuesta al propietario de un crédito aprobado: la carta de aprobación (modelo "Notificación de
 * aprobación de crédito") y el estado de cuenta con su plan de pagos. Van adjuntos al correo y uno por mensaje de
 * WhatsApp (la plantilla con encabezado de documento solo lleva un PDF), junto al pagaré firmado.
 *
 * Mientras no se registren los descuentos reales, el estado de cuenta toma como descontadas las cuotas vencidas
 * según el calendario (igual que el saldo del listado) y no reporta mora.
 */
final class OwnerCreditResponseDocuments
{
  public const LETTER = 'carta';
  public const STATEMENT = 'estado';
  public const TITLES = [self::LETTER => 'Carta de aprobación', self::STATEMENT => 'Estado de cuenta'];
  private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  private const NUMBERS = ['cero', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce'];

  /**
   * Plan de pagos con la fecha de cada cuota y la posición del crédito a $asOf: cuotas vencidas, saldo de capital,
   * próxima cuota y la anterior.
   * @param array<string,mixed> $credit fila aprobada de scm_owner_credits
   * @return array{rows:list<array<string,mixed>>,paid:int,balance:float,next:?array<string,mixed>,previous:?array<string,mixed>}
   */
  public static function position(array $credit, int $asOf): array
  {
    $rows = OwnerCreditCoverage::plan((float) $credit['approved_amount'], (float) $credit['approved_rate'], (int) $credit['approved_term'])['rows'];
    $first = (int) ($credit['first_payment_ts'] ?? 0);
    foreach ($rows as $index => $row) $rows[$index]['fecha'] = $first > 0 ? OwnerCreditCoverage::installmentTs($first, $index + 1) : 0;
    $paid = 0;
    while ($paid < count($rows) && (int) $rows[$paid]['fecha'] > 0 && (int) $rows[$paid]['fecha'] <= $asOf) $paid++;
    return [
      'rows' => $rows, 'paid' => $paid,
      'balance' => $paid === 0 ? (float) $credit['approved_amount'] : (float) $rows[$paid - 1]['saldo'],
      'next' => $rows[$paid] ?? null, 'previous' => $paid > 0 ? $rows[$paid - 1] : null,
    ];
  }

  /**
   * Carta de aprobación firmada por el funcionario que aprobó.
   * @param array<string,mixed> $credit @param array{signer:array<string,string>,margin:int,date:int} $context
   */
  public static function letter(array $credit, array $context, bool $pdf = true): string
  {
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $e = static fn($value): string => Doc::e((string) $value);
    $name = trim((string) $credit['owner_name']) ?: 'Propietario';
    $position = self::position($credit, (int) $context['date']);
    $rows = $position['rows'];
    $last = $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['fecha'];
    $property = implode(' · ', array_filter([(string) $credit['property'] !== '' ? 'Inmueble ' . $credit['property'] : '', trim((string) $credit['address']), 'Contrato #' . $credit['contract_code']]));
    $conditions = [
      'Número de crédito' => '#' . (int) $credit['id'],
      'Valor aprobado' => self::money((float) $credit['approved_amount']),
      'Plazo' => (int) $credit['approved_term'] . ' meses',
      'Número de cuotas' => (string) (int) $credit['approved_term'],
      'Valor de la cuota mensual' => self::money((float) $credit['monthly_payment']),
      'Tasa de interés' => self::rate((float) $credit['approved_rate']),
      'Fecha de desembolso' => self::shortDate((int) ($credit['disbursed_at'] ?? 0)),
      'Fecha de primera cuota' => self::shortDate((int) ($credit['first_payment_ts'] ?? 0)),
      'Fecha de última cuota' => self::shortDate($last),
      'Inmueble vinculado' => $property,
    ];
    $table = '';
    foreach ($conditions as $label => $value) $table .= '<tr><th scope="row">' . $e($label) . '</th><td>' . $e($value) . '</td></tr>';
    $margin = max(0, (int) $context['margin']);
    $before = $margin === 0 ? 'antes de su vencimiento' : 'como mínimo, ' . (self::NUMBERS[$margin] ?? (string) $margin) . ($margin === 1 ? ' mes' : ' meses') . ' antes de su vencimiento';
    $signer = $context['signer'];
    $signerName = trim((string) ($signer['name'] ?? ''));

    return '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document scm-owner-credit-letter">' . $top
      . '<header class="scm-owner-credit-letter-company"><strong>SOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S.</strong><span>SKC SuCasa Inmobiliaria</span><em>¡Construimos confianza y protegemos su patrimonio!</em></header>'
      . '<p class="scm-owner-credit-letter-text">Cartagena de Indias, ' . $e(self::longDate((int) $context['date'])) . '</p>'
      . '<p class="scm-owner-credit-letter-text">Señor(a):<br><strong>' . $e($name) . '</strong><br>Identificación: ' . $e((string) ($credit['owner_document'] ?? '') ?: 'Sin registrar') . '<br>Ciudad</p>'
      . '<p class="scm-owner-credit-letter-text"><strong>Asunto: Notificación de aprobación de crédito</strong></p>'
      . '<p class="scm-owner-credit-letter-text">Estimado(a) ' . $e($name) . ':</p>'
      . '<p class="scm-owner-credit-letter-text">Nos complace informarle que SKC SuCasa Inmobiliaria ha aprobado su solicitud de crédito, como parte de los beneficios que ofrecemos a nuestros propietarios, con el propósito de brindarles soluciones financieras ágiles y oportunas, aprovechando nuestra relación comercial y de confianza.</p>'
      . '<p class="scm-owner-credit-letter-text">A continuación, presentamos las condiciones de su crédito:</p>'
      . '<table class="scm-acta-receipt-data scm-owner-credit-keep"><thead><tr><th scope="col">Concepto</th><th scope="col">Condición aprobada</th></tr></thead><tbody>' . $table . '</tbody></table>'
      . '<section class="scm-owner-credit-keep"><h2 class="scm-owner-credit-letter-title">Forma de pago</h2>'
      . '<p class="scm-owner-credit-letter-text">Las cuotas mensuales serán descontadas de los valores que SKC SuCasa Inmobiliaria recauda por concepto del arrendamiento de su inmueble y que corresponda transferirle, conforme a las condiciones contractuales acordadas.</p>'
      . '<p class="scm-owner-credit-letter-text">El plazo aprobado tiene en cuenta la fecha de terminación de su contrato de administración, de manera que el crédito quede cancelado, ' . $e($before) . '.</p></section>'
      . '<section class="scm-owner-credit-keep"><h2 class="scm-owner-credit-letter-title">Condiciones especiales</h2>'
      . '<p class="scm-owner-credit-letter-text">Durante la vigencia del crédito solamente podrá mantenerse una obligación activa. En caso de requerir recursos adicionales, podrá solicitar un retanqueo, mediante el cual se cancelará el saldo pendiente del crédito vigente y se establecerán las condiciones de una nueva operación.</p>'
      . '<p class="scm-owner-credit-letter-text">Este beneficio no requiere garantías adicionales, dado que su mecanismo de pago se encuentra respaldado por los recursos derivados del arrendamiento del inmueble que administramos.</p></section>'
      . '<p class="scm-owner-credit-letter-text">Agradecemos la confianza depositada en nuestra organización. Reafirmamos nuestro compromiso de ofrecerle servicios y soluciones que contribuyan a la adecuada administración y protección de su patrimonio.</p>'
      . '<div class="scm-owner-credit-letter-signature scm-owner-credit-keep"><p>Cordialmente,</p>'
      . ($signerName !== '' && $signerName !== 'SKC SuCasa Inmobiliaria' ? '<p><strong>' . $e($signerName) . '</strong>' . (trim((string) ($signer['cargo'] ?? '')) !== '' ? '<br>' . $e($signer['cargo']) : '') . '</p>' : '')
      . '<p>SKC SuCasa Inmobiliaria<br>Soluciones Comerciales y Constructivas S.A.S.</p><p><em>¡Construimos confianza y protegemos su patrimonio!</em></p></div>'
      . $bottom . '</article>';
  }

  /**
   * Estado de cuenta con la estructura de un extracto de crédito de consumo: saldo, cuota a pagar y su distribución,
   * pago anterior, estado del crédito, tasas y plan de pagos. Cierra con "Realizado por".
   * @param array<string,mixed> $credit @param array{signer:array<string,string>,date:int,usury_annual:float} $context
   */
  public static function statement(array $credit, array $context, bool $pdf = true): string
  {
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $e = static fn($value): string => Doc::e((string) $value);
    $date = (int) $context['date'];
    $p = self::position($credit, $date);
    $term = (int) $credit['approved_term'];
    $next = $p['next'];
    $previous = $p['previous'];
    $paidOff = $next === null && $term > 0;
    $zero = self::money(0);
    $banner = $paidOff
      ? 'Su crédito está pagado: no tiene cuotas pendientes.'
      : 'Su crédito está al día. Las cuotas se descuentan de los giros de arrendamiento de su inmueble; no tiene que hacer pagos adicionales.';
    $schedule = '';
    foreach ($p['rows'] as $index => $row) {
      $schedule .= '<tr><td>' . (int) $row['mes'] . '</td><td>' . $e(self::shortDate((int) $row['fecha'])) . '</td><td>' . $e(self::money((float) $row['saldo_inicial'])) . '</td><td>' . $e(self::money((float) $row['abono'])) . '</td><td>' . $e(self::money((float) $row['interes'])) . '</td><td>' . $e(self::money((float) $row['cuota'])) . '</td><td>' . $e(self::money((float) $row['saldo'])) . '</td><td>' . ($index < $p['paid'] ? 'Vencida' : 'Por vencer') . '</td></tr>';
    }
    $signer = $context['signer'];
    $block = static fn(string $title, array $rows, string $class = ''): string => '<section class="scm-owner-credit-statement-box scm-owner-credit-keep' . ($class !== '' ? ' ' . $class : '') . '"><h2>' . $e($title) . '</h2>' . Doc::table($rows) . '</section>';

    return '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document scm-owner-credit-statement">' . $top
      . '<header class="scm-owner-credit-statement-head"><div><span class="scm-owner-credit-statement-kicker">SKC SuCasa Inmobiliaria</span><h1>Estado de cuenta</h1><p>Crédito a propietarios · Pasaporte SuCasa</p></div>'
      . '<div class="scm-owner-credit-statement-number"><span>Número de crédito</span><strong>#' . (int) $credit['id'] . '</strong></div></header>'
      . '<div class="scm-owner-credit-statement-client"><strong>' . $e($credit['owner_name']) . '</strong>'
      . ((string) ($credit['owner_document'] ?? '') !== '' ? '<span>Identificación: ' . $e($credit['owner_document']) . '</span>' : '')
      . '<span>' . $e(implode(' · ', array_filter([(string) $credit['property'] !== '' ? 'Inmueble ' . $credit['property'] : '', trim((string) $credit['address'])]))) . '</span>'
      . '<span>Contrato #' . $e($credit['contract_code']) . '</span></div>'
      . '<p class="scm-owner-credit-statement-banner">' . $e($banner) . '</p>'
      . '<div class="scm-owner-credit-statement-grid">'
      . $block('Saldo', ['Saldo a capital en pesos' => self::money($p['balance']), 'Valor desembolsado' => self::money((float) $credit['approved_amount']), 'Fecha de desembolso' => self::shortDate((int) ($credit['disbursed_at'] ?? 0))])
      . $block('Cuota a pagar', ['Fecha de corte' => self::shortDate($date), 'Pague hasta' => $next !== null ? self::shortDate((int) $next['fecha']) : 'Sin cuotas pendientes', 'Valor a pagar' => $next !== null ? self::money((float) $next['cuota']) : $zero], 'is-key')
      . $block('Distribución del pago anterior', $previous !== null
        ? ['Fecha de pago anterior' => self::shortDate((int) $previous['fecha']), 'Abono a capital' => self::money((float) $previous['abono']), 'Intereses corrientes' => self::money((float) $previous['interes']), 'Intereses de mora' => $zero, 'Total' => self::money((float) $previous['cuota'])]
        : ['Fecha de pago anterior' => 'Aún no vence la primera cuota', 'Total' => $zero])
      . $block('Distribución de la cuota a pagar', $next !== null
        ? ['Abono a capital' => self::money((float) $next['abono']), 'Intereses corrientes' => self::money((float) $next['interes']), 'Intereses de mora' => $zero, 'Valor a pagar' => self::money((float) $next['cuota'])]
        : ['Valor a pagar' => $zero])
      . $block('Estado actual de su crédito', ['Plazo total' => $term . ' cuotas', 'Cuotas vencidas' => (string) $p['paid'], 'Cuotas pendientes' => (string) max(0, $term - $p['paid']), 'Cuotas en mora' => '0'])
      . $block('Tasas', ['Tasa de interés pactada' => self::rate((float) $credit['approved_rate']), 'Tasa de mora vigente' => number_format((float) $context['usury_annual'] * 100, 2, ',', '.') . ' % E.A. (máxima legal)'])
      . '</div>'
      . '<section class="scm-acta-receipt-section"><h2>Plan de pagos</h2><table class="scm-owner-credit-schedule"><thead><tr><th>Cuota</th><th>Fecha</th><th>Saldo inicial</th><th>Abono a capital</th><th>Intereses</th><th>Valor cuota</th><th>Saldo</th><th>Estado</th></tr></thead><tbody>' . $schedule . '</tbody></table></section>'
      . '<section class="scm-owner-credit-statement-info scm-owner-credit-keep"><h2>Información importante</h2><ol>'
      . '<li>Las cuotas se descuentan de los valores que SKC SuCasa Inmobiliaria recauda por el arrendamiento de su inmueble. Si en un mes el giro no alcanza para la cuota, le informaremos cómo pagar la diferencia.</li>'
      . '<li>Puede hacer abonos extraordinarios a capital para reducir el plazo o el valor de la cuota, o cancelar el total del crédito. Comuníquese con nosotros para liquidarlo a la fecha.</li>'
      . '<li>Las cuotas vencidas se toman como descontadas según el calendario del crédito.</li>'
      . '</ol></section>'
      . Doc::footer(['realizado_por' => (string) ($signer['name'] ?? '') ?: 'SKC SuCasa Inmobiliaria', 'realizado_por_cargo' => (string) ($signer['cargo'] ?? '')])
      . $bottom . '</article>';
  }

  /** PDF con los estilos de los documentos del crédito; sin Chromium no se envía la respuesta. */
  public static function pdf(string $html, string $title): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render($html, $title, [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css', $root . '/public/assets/css/owner-credit-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF "' . $title . '". Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  public static function money(float $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  /** "2,10 % N.M.V. (28,32 % E.A.)" */
  public static function rate(float $rate): string
  {
    return number_format($rate * 100, 2, ',', '.') . ' % N.M.V. (' . number_format(((1 + $rate) ** 12 - 1) * 100, 2, ',', '.') . ' % E.A.)';
  }

  public static function shortDate(int $ts): string
  {
    return $ts > 0 ? date('d/m/Y', $ts) : 'Sin registrar';
  }

  /** "29 de octubre de 2026" */
  public static function longDate(int $ts): string
  {
    return (int) date('j', $ts) . ' de ' . self::MONTHS[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
  }
}
