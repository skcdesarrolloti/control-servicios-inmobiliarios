<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Modules\LeaseContracts\LeaseAnnexesDocument;
use SCM\Modules\Pending\PublicServicesDocument as Doc;

/**
 * Pagaré en blanco y su carta de instrucciones (modelos "MODELO PAGARÉ 1" y "CARTA DE INSTRUCCIONES - MODELO11").
 * El pagaré se firma con la cuantía, los intereses y el vencimiento en blanco: la carta autoriza a llenarlo si el
 * deudor incumple, por el total de lo que deba por el Pasaporte de crédito. Por eso cada persona firma una sola vez
 * y sirve para todos sus créditos. El mismo marcado se muestra en la página de firma y se imprime en el PDF.
 */
final class OwnerCreditPromissoryDocument
{
  public const CREDITOR = 'SOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S.';
  public const VERSION = 'PAGARE_BLANCO_CARTA_INSTRUCCIONES_V1';
  public const CONSENT = 'Confirmo que soy la persona identificada en este documento, que leí el pagaré en blanco y su carta de instrucciones, y que acepto suscribirlos electrónicamente con mi nombre, mi número de documento y el código de verificación enviado a mi contacto. Entiendo que el pagaré respalda las obligaciones que adquiera por los créditos del Pasaporte SuCasa y que podrá llenarse según la carta de instrucciones.';
  /** Cláusula de firma: describe la firma del panel en lugar de la aplicación del modelo original. */
  public const SIGNATURE_CLAUSE = 'será suscrito mediante firma electrónica en la plataforma de SKC SuCasa Inmobiliaria, en la que el firmante se identifica con su nombre completo y su número de documento, recibe un código de verificación de un solo uso en el correo electrónico o el celular que tiene registrado y manifiesta su aceptación expresa. La plataforma registra la fecha y hora, la dirección IP, el navegador y la huella digital (SHA-256) del documento firmado, lo que garantiza la autenticidad (identidad del firmante), la integridad (no alteración del documento luego de su firma) y el no repudio (trazabilidad), conforme a la Ley 527 de 1999 y las normas que la reglamentan.';

  /**
   * @param array<string,mixed> $row fila de scm_owner_credit_promissory
   * @param array<string,mixed> $payload datos inmutables del documento
   */
  public static function render(array $row, array $payload, string $form = '', bool $pdf = false): string
  {
    $e = static fn($value): string => Doc::e((string) $value);
    $debtor = $payload['debtor'];
    $signed = $row['status'] === 'firmado';
    $signature = $signed ? (json_decode((string) $row['signed_json'], true) ?: []) : [];
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $creditor = self::CREDITOR;
    $blank = '<span class="scm-promissory-blank" aria-label="Espacio en blanco">&nbsp;</span>';
    $place = 'Cartagena de Indias D. T. y C., ' . ($signed ? date('d/m/Y', (int) $row['signed_at']) : 'en la fecha de la firma electrónica');
    $status = match ((string) $row['status']) {
      'firmado' => 'Firmado electrónicamente',
      'anulado' => 'Anulado · No válido para firma',
      default => 'Pendiente de firma',
    };
    $signatureBlock = static function (string $role) use ($e, $debtor, $signed, $signature): string {
      return '<div class="scm-promissory-signature">'
        . ($signed ? '<p class="scm-acta-typed-signature">' . $e($signature['name'] ?? '') . '</p>' : '<p class="scm-promissory-signature-line">&nbsp;</p>')
        . '<p><strong>' . $e($debtor['name']) . '</strong><br>' . $e($role) . '<br>C.C. No. ' . $e($debtor['document']) . '</p>'
        . ($signed ? '<p class="scm-promissory-signed-note">Firmado electrónicamente el ' . $e(date('d/m/Y H:i:s', (int) ($signature['signed_at'] ?? 0))) . ' (Colombia)</p>' : '')
        . '</div>';
    };

    $html = '<article class="scm-acta-receipt scm-services-document scm-owner-credit-document scm-promissory-document">' . $top
      . Doc::header('Pagaré en blanco y carta de instrucciones', true)
      . '<p class="scm-acta-receipt-state">' . $e($status) . ' · ' . $e($payload['number']) . '</p>'

      // Pagaré (texto del modelo; cuantía, interés y vencimiento quedan en blanco).
      . '<section class="scm-acta-receipt-section scm-promissory-section"><h2>Pagaré No. ' . $e($payload['number']) . '</h2>'
      . '<table class="scm-acta-receipt-data"><tbody>'
      . '<tr><th scope="row">Lugar y fecha de celebración del contrato</th><td>' . $e($place) . '</td></tr>'
      . '<tr><th scope="row">Fecha de vencimiento</th><td>' . $blank . '</td></tr>'
      . '</tbody></table>'
      . '<p class="scm-promissory-text">Yo, <strong>' . $e($debtor['name']) . '</strong>, mayor de edad, domiciliado y residente en Cartagena de Indias D. T. y C., identificado(a) como aparece al pie de mi correspondiente firma, obrando en mi propio nombre, declaro expresamente que pagaré incondicionalmente a la orden de ' . $creditor . ' Sociedad comercial, con domicilio principal en Cartagena de Indias D. T. y C., o a quien represente sus derechos, en Cartagena D. T. y C., la suma de ' . $blank . ' pesos moneda legal ($' . $blank . '), que ha recibido de ' . $creditor . ' a entera satisfacción, en calidad de mutuo con intereses. Sobre la suma mutuada reconoceré intereses a la tasa del ' . $blank . ' por ciento (' . $blank . ' %) mensual, sobre el total de la misma o su saldo insoluto. En caso de que por cualquier circunstancia llegare a presentar mora en el pago de la suma mutuada y/o los intereses pactados reconoceré intereses moratorios a la tasa más alta permitida para dicho evento. En todo caso si incurro en mora ' . $creditor . ' podrá declarar insubsistentes los plazos de esta obligación y exigir su pago inmediato judicial o extrajudicialmente. La prórroga en el plazo para el pago de la suma mutuada o los intereses, o el recibo de abonos parciales no implica novación de la obligación, y en general, la omisión de ' . $creditor . ' en el ejercicio de cualesquiera de sus derechos no significa renuncia del o de los mismos. Acepto que la solidaridad subsiste en caso de extensión del plazo. Me obligo a pagar los honorarios profesionales del Abogado gestor; que se harán exigibles por la sola gestión extrajudicial. Acepto cualquier cesión, endoso o cualquiera transacción que de este título valor haga ' . $creditor . ' a cualquier persona. Acepto que ' . $creditor . ' declare el plazo vencido y exigible la obligación a que se refiere este título valor en su totalidad, siempre que en forma conjunta o separada fuese perseguido judicialmente. Los gastos e impuestos que ocasione el otorgamiento de este título valor serán a mi cargo.</p>'
      . '<p class="scm-promissory-text"><strong>AUTORIZACIÓN FIRMA ELECTRÓNICA / DIGITAL.</strong> Las Partes declaran que el presente pagaré ' . $e(self::SIGNATURE_CLAUSE) . '</p>'
      . $signatureBlock('Deudor') . '</section>'

      // Carta de instrucciones (texto del modelo).
      . '<section class="scm-acta-receipt-section scm-promissory-section scm-promissory-letter"><h2>Carta de instrucciones · Pagaré No. ' . $e($payload['number']) . '</h2>'
      . '<p class="scm-promissory-text">Cartagena de Indias D. T. H. y C.<br>Señor(es)<br><strong>' . $creditor . '</strong><br>Ciudad</p>'
      . '<p class="scm-promissory-text"><strong>Ref.: Carta de instrucciones. Pagaré No. ' . $e($payload['number']) . '</strong></p>'
      . '<p class="scm-promissory-text">El MUTUARIO ha firmado y entregado a ' . $creditor . ', un (1) pagaré a la orden, con el ánimo de hacerlo negociable, en el cual ha dejado en blanco los espacios relativos a la cuantía, intereses y fecha de vencimiento, el cual está destinado a instrumentar para el cobro, las obligaciones a favor de ' . $creditor . ' debido a las operaciones que se celebren en desarrollo del presente contrato. ' . $creditor . ' llenará el pagaré destinado a instrumentar las obligaciones derivadas del contrato del PASAPORTE DE CRÉDITO siguiendo las siguientes instrucciones:</p>'
      . '<ol class="scm-promissory-list">'
      . '<li>' . $creditor . ' para diligenciar el Pagaré no requiere avisar a los firmantes de este.</li>'
      . '<li>' . $creditor . ' podrá llenar el Pagaré en el evento en que el MUTUARIO incumpla en el pago de cualquiera de las obligaciones derivada de este contrato.</li>'
      . '<li>La cuantía del Pagaré será el total de las obligaciones que adeudemos debido a las utilizaciones del PASAPORTE SUCASA.</li>'
      . '<li>La fecha del vencimiento del pagaré será aquella en que se presente el incumplimiento de alguna de las obligaciones que adeudemos, sea por capital o intereses, pues el no pago de alguna hace exigible el total de obligaciones.</li>'
      . '<li>La tasa de interés será el interés bancario corriente para los créditos ordinarios de libre asignación o la más alta permitida para las obligaciones en mora por las autoridades colombianas.</li>'
      . '<li>' . $creditor . ' además de los eventos de aceleración de los plazos previstos en la Ley o en los documentos, contratos o títulos de deuda respectivos, podrá llenar el Pagaré en cualquiera de los siguientes casos:'
      . '<ol type="a"><li>Si los bienes del girador o uno cualquiera de los giradores es embargado o perseguido por cualquier persona en ejercicio de cualquier acción;</li><li>Muerte del Girador o uno cualquiera de los giradores.</li></ol></li>'
      . '</ol>'
      . '<p class="scm-promissory-text">Las anteriores instrucciones se dan de conformidad con el artículo 622 del Código de Comercio.</p>'
      . '<p class="scm-promissory-text"><strong>AUTORIZACIÓN FIRMA ELECTRÓNICA / DIGITAL.</strong> Se declara que el presente documento ' . $e(self::SIGNATURE_CLAUSE) . '</p>'
      . '<p class="scm-promissory-text">En señal de asentimiento las partes suscriben el presente documento en dos (2) ejemplares del mismo tenor y contenido, en ciudad con domicilio principal Cartagena de Indias D. T. H. y C. a la fecha de registrada la firma electrónica.</p>'
      . $signatureBlock('Deudor') . '</section>'
      . $form;

    if ($signed) {
      $verification = $signature['verification'] ?? [];
      $html .= '<section class="scm-acta-signature-certificate scm-owner-credit-keep">'
        . '<div class="scm-acta-certificate-head"><h2>Firma<br>Electrónica</h2><div><strong>DOCUMENTO FINALIZADO</strong><p>' . $e(date('d/m/Y H:i:s', (int) $row['signed_at'])) . ' (Colombia)</p><p>ID: ' . $e($payload['number']) . '</p></div></div>'
        . '<div class="scm-acta-certificate-row"><div class="scm-acta-signature-mark"><p class="scm-acta-typed-signature">' . $e($signature['name'] ?? '') . '</p><small>Nombre escrito como firma</small></div>'
        . '<div class="scm-acta-signature-contact"><strong>' . $e($signature['name'] ?? '') . '</strong><p>DEUDOR · MUTUARIO</p><p>C.C. No. ' . $e($signature['document'] ?? '') . '</p></div></div>'
        . '<div class="scm-acta-certificate-consent">' . $e($signature['consent_text'] ?? '') . '</div>'
        . '<h3 class="scm-acta-audit-title">PISTA DE AUDITORÍA</h3><dl class="scm-acta-audit">'
        . '<div><dt>' . $e(date('d/m/Y H:i:s', (int) $payload['created_at'])) . '</dt><dd>Documento enviado para firma por ' . $e($payload['actor']['name'] ?? '') . '.</dd></div>'
        . (!empty($verification['requested_at']) ? '<div><dt>' . $e(date('d/m/Y H:i:s', (int) $verification['requested_at'])) . '</dt><dd>Código solicitado por ' . $e(($verification['channel'] ?? '') === 'email' ? 'correo electrónico' : 'WhatsApp') . '.</dd></div>' : '')
        . (!empty($verification['verified_at']) ? '<div><dt>' . $e(date('d/m/Y H:i:s', (int) $verification['verified_at'])) . '</dt><dd>Código validado y aceptación expresa registrada.</dd></div>' : '')
        . '<div><dt>' . $e(date('d/m/Y H:i:s', (int) $row['signed_at'])) . '</dt><dd>Firmado por ' . $e($signature['name'] ?? '') . (!empty($signature['ip']) ? ' (IP: ' . $e($signature['ip']) . ')' : '') . '. Documento finalizado.</dd></div>'
        . '</dl></section>';
    }
    return $html . '<p class="scm-acta-fingerprint scm-promissory-fingerprint">Huella del documento (SHA-256): ' . $e($row['payload_hash']) . '</p>'
      . '<p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p>' . $bottom . '</article>';
  }
}
