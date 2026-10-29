<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Modules\LeaseContracts\LeaseAnnexesDocument;
use SCM\Modules\Pending\PublicServicesDocument as Doc;
use SCM\Support\HtmlPdfRenderer;

/**
 * Documentos de la respuesta al propietario de un crédito aprobado: la carta de aprobación (modelo "Notificación de
 * aprobación de crédito") y las condiciones del crédito con su plan de pagos. Van adjuntos al correo y uno por
 * mensaje de WhatsApp (la plantilla con encabezado de documento solo lleva un PDF), junto al pagaré firmado.
 * El sistema no lleva el estado de cuenta: los descuentos de los giros no se registran aquí.
 */
final class OwnerCreditResponseDocuments
{
  public const LETTER = 'carta';
  public const PLAN = 'plan';
  public const TITLES = [self::LETTER => 'Carta de aprobación', self::PLAN => 'Condiciones y plan de pagos'];
  public const FILENAMES = [self::LETTER => 'carta-aprobacion', self::PLAN => 'condiciones-plan-de-pagos'];
  private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  private const NUMBERS = ['cero', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce'];

  /**
   * Plan de pagos del crédito aprobado con la fecha de cada cuota.
   * @param array<string,mixed> $credit fila aprobada de scm_owner_credits
   * @return list<array<string,mixed>>
   */
  public static function schedule(array $credit): array
  {
    $rows = OwnerCreditCoverage::plan((float) $credit['approved_amount'], (float) $credit['approved_rate'], (int) $credit['approved_term'])['rows'];
    $first = (int) ($credit['first_payment_ts'] ?? 0);
    foreach ($rows as $index => $row) $rows[$index]['fecha'] = $first > 0 ? OwnerCreditCoverage::installmentTs($first, $index + 1) : 0;
    return $rows;
  }

  /** Condiciones aprobadas, como las muestran la carta y el plan de pagos. @return array<string,string> */
  private static function conditions(array $credit, array $rows): array
  {
    $last = $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['fecha'];
    return [
      'Número de crédito' => '#' . (int) $credit['id'],
      'Valor aprobado' => self::money((float) $credit['approved_amount']),
      'Plazo' => (int) $credit['approved_term'] . ' meses',
      'Número de cuotas' => (string) (int) $credit['approved_term'],
      'Valor de la cuota mensual' => self::money((float) $credit['monthly_payment']),
      'Tasa de interés' => self::rate((float) $credit['approved_rate']),
      'Fecha de desembolso' => self::shortDate((int) ($credit['disbursed_at'] ?? 0)),
      'Fecha de primera cuota' => self::shortDate((int) ($credit['first_payment_ts'] ?? 0)),
      'Fecha de última cuota' => self::shortDate($last),
      'Inmueble vinculado' => implode(' · ', array_filter([(string) $credit['property'] !== '' ? 'Inmueble ' . $credit['property'] : '', trim((string) $credit['address']), 'Contrato #' . $credit['contract_code']])),
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
    $table = '';
    foreach (self::conditions($credit, self::schedule($credit)) as $label => $value) $table .= '<tr><th scope="row">' . $e($label) . '</th><td>' . $e($value) . '</td></tr>';
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
      // Se pueden tener varios créditos dentro del cupo: cada solicitud nueva la estudia y la decide el analista.
      . '<p class="scm-owner-credit-letter-text">Durante la vigencia del crédito, cualquier solicitud de recursos adicionales quedará sujeta a estudio y aprobación, de acuerdo con su cupo disponible. También podrá solicitar un retanqueo, mediante el cual se cancelará el saldo pendiente del crédito vigente y se establecerán las condiciones de una nueva operación.</p>'
      . '<p class="scm-owner-credit-letter-text">Este beneficio no requiere garantías adicionales, dado que su mecanismo de pago se encuentra respaldado por los recursos derivados del arrendamiento del inmueble que administramos.</p></section>'
      . '<p class="scm-owner-credit-letter-text">Agradecemos la confianza depositada en nuestra organización. Reafirmamos nuestro compromiso de ofrecerle servicios y soluciones que contribuyan a la adecuada administración y protección de su patrimonio.</p>'
      . '<div class="scm-owner-credit-letter-signature scm-owner-credit-keep"><p>Cordialmente,</p>'
      . ($signerName !== '' && $signerName !== 'SKC SuCasa Inmobiliaria' ? '<p><strong>' . $e($signerName) . '</strong>' . (trim((string) ($signer['cargo'] ?? '')) !== '' ? '<br>' . $e($signer['cargo']) : '') . '</p>' : '')
      . '<p>SKC SuCasa Inmobiliaria<br>Soluciones Comerciales y Constructivas S.A.S.</p><p><em>¡Construimos confianza y protegemos su patrimonio!</em></p></div>'
      . $bottom . '</article>';
  }

  /**
   * Condiciones del crédito con la tabla de amortización completa (cuota, fecha, capital, intereses y saldo) y la
   * forma de pago. Cierra con "Realizado por".
   * @param array<string,mixed> $credit @param array{signer:array<string,string>,date:int} $context
   */
  public static function plan(array $credit, array $context, bool $pdf = true): string
  {
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $e = static fn($value): string => Doc::e((string) $value);
    $rows = self::schedule($credit);
    $interest = array_sum(array_map(static fn(array $row): float => (float) $row['interes'], $rows));
    $total = array_sum(array_map(static fn(array $row): float => (float) $row['cuota'], $rows));
    $schedule = '';
    foreach ($rows as $row) {
      $schedule .= '<tr><td>' . (int) $row['mes'] . '</td><td>' . $e(self::shortDate((int) $row['fecha'])) . '</td><td>' . $e(self::money((float) $row['saldo_inicial'])) . '</td><td>' . $e(self::money((float) $row['abono'])) . '</td><td>' . $e(self::money((float) $row['interes'])) . '</td><td>' . $e(self::money((float) $row['cuota'])) . '</td><td>' . $e(self::money((float) $row['saldo'])) . '</td></tr>';
    }
    $schedule .= '<tr class="is-total"><td colspan="3">Totales</td><td>' . $e(self::money((float) $credit['approved_amount'])) . '</td><td>' . $e(self::money($interest)) . '</td><td>' . $e(self::money($total)) . '</td><td></td></tr>';
    $signer = $context['signer'];

    return '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document scm-owner-credit-plan">' . $top
      . '<header class="scm-owner-credit-plan-head"><div><span class="scm-owner-credit-plan-kicker">SKC SuCasa Inmobiliaria</span><h1>Condiciones del crédito y plan de pagos</h1><p>Crédito a propietarios · Pasaporte SuCasa · ' . $e(self::shortDate((int) $context['date'])) . '</p></div>'
      . '<div class="scm-owner-credit-plan-number"><span>Número de crédito</span><strong>#' . (int) $credit['id'] . '</strong></div></header>'
      . '<div class="scm-owner-credit-plan-client"><strong>' . $e($credit['owner_name']) . '</strong>'
      . ((string) ($credit['owner_document'] ?? '') !== '' ? '<span>Identificación: ' . $e($credit['owner_document']) . '</span>' : '') . '</div>'
      . '<section class="scm-acta-receipt-section scm-owner-credit-keep"><h2>Condiciones aprobadas</h2>' . Doc::table(self::conditions($credit, $rows) + [
        'Total intereses' => self::money($interest),
        'Total a pagar' => self::money($total),
        'Forma de pago' => 'Descuento mensual de los giros de arrendamiento del inmueble',
      ]) . '</section>'
      . '<section class="scm-acta-receipt-section"><h2>Plan de pagos</h2><table class="scm-owner-credit-schedule"><thead><tr><th>Cuota</th><th>Fecha</th><th>Saldo inicial</th><th>Abono a capital</th><th>Intereses</th><th>Valor cuota</th><th>Saldo</th></tr></thead><tbody>' . $schedule . '</tbody></table></section>'
      . '<section class="scm-owner-credit-plan-info scm-owner-credit-keep"><h2>Información importante</h2><ol>'
      . '<li>Las cuotas se descuentan de los valores que SKC SuCasa Inmobiliaria recauda por el arrendamiento de su inmueble y que corresponda transferirle. Si en un mes el giro no alcanza para la cuota, le informaremos cómo pagar la diferencia.</li>'
      . '<li>Cada cuota se divide en intereses sobre el saldo y abono a capital; la última cuota se ajusta para dejar el saldo en $0.</li>'
      . '<li>Puede hacer abonos extraordinarios a capital para reducir el plazo o el valor de la cuota, o cancelar el total del crédito. Comuníquese con nosotros para liquidarlo a la fecha.</li>'
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
