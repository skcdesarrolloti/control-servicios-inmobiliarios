<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

final class CompletionView
{
  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  public function panel(array $context, CompletionService $service): string
  {
    $ticket = $context['ticket'];
    $active = false;
    foreach ($context['acts'] as $act) {
      $active = $active || $act['status'] !== 'cancelled';
    }
    $executor = str_replace('En ejecucion por ', '', (string) ($ticket['estado_administrativo'] ?? ''));
    ob_start(); ?>
    <section class="scm-acta">
      <p class="scm-acta-notice">Documenta la solución, elige el firmante y revisa el valor administrativo. El ticket permanecerá <strong>en espera de firma</strong>. Solo la firma registrará el cierre y el reporte de cobro.</p>
      <div class="scm-acta-meta"><span>Ticket <strong>#<?= self::e($ticket['id_ticket'] ?: $ticket['_ID']) ?></strong></span><span>Inmueble <strong><?= self::e($ticket['inmueble'] ?? '—') ?></strong></span></div>
      <?php foreach ($context['acts'] as $act): $payload = $service->payload($act); ?>
        <article class="scm-acta-record">
          <div class="scm-acta-meta"><h3>Acta #<?= self::e($act['id']) ?></h3><strong class="scm-acta-status"><?= self::e(['pending' => 'Pendiente de firma', 'signed' => 'Firmada', 'cancelled' => 'Anulada'][$act['status']] ?? $act['status']) ?></strong></div>
          <p>Firmante: <strong><?= self::e($payload['signer']['name']) ?></strong> · <?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?><br><?= self::e($payload['signer']['email']) ?> · <?= self::e($payload['signer']['phone']) ?></p>
          <p>Reporte administrativo: <strong><?= self::money((int) $payload['report']['total']) ?></strong> <?= !empty($act['report_id']) ? '· Registrado #' . self::e($act['report_id']) : '· Se registrará al firmar' ?></p>
          <?php if ($act['status'] === 'cancelled'): ?><p>Motivo: <?= self::e($act['cancellation_reason']) ?></p><?php endif; ?>
          <div class="scm-acta-actions"><a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id'])) ?>" target="_blank" rel="noopener" data-acta-preview>Ver acta</a>
          <a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id']) . '&format=pdf') ?>" target="_blank" rel="noopener">PDF destinatario</a>
          <a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id']) . '&format=pdf&audience=staff') ?>" target="_blank" rel="noopener">PDF interno</a>
          <?php if (in_array($act['status'], ['pending', 'signed'], true)): ?>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-resend="<?= self::e($act['id']) ?>"><?= $act['status'] === 'signed' ? 'Reenviar copia firmada' : 'Reenviar invitación' ?></button>
          <?php endif; ?></div>
          <?php $delivery = json_decode((string) ($act['delivery_json'] ?? ''), true) ?: []; $event = $act['status'] === 'signed' ? 'signed_receipt' : 'signature_invitation'; ?>
          <?php if ($act['status'] !== 'cancelled'): ?><ul class="scm-acta-help"><?php foreach ($payload['channels'] ?? ['email'] as $channel): ?><li><?= $channel === 'email' ? 'Correo' : 'WhatsApp' ?>: <?= !empty($delivery[$event][$channel]['queued']) ? 'en cola; no confirma entrega' : 'sin confirmación de encolado; reintenta el envío' ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($act['status'] === 'pending'): ?>
            <p class="scm-acta-help">El enlace vence el <?= self::e(date('d/m/Y', (int) $act['expires_at'])) ?>. Reenviar no cierra el ticket.</p>
            <details><summary>¿Necesitas corregir el acta o cambiar el firmante?</summary>
              <p>Anula esta versión y genera otra. El enlace anterior dejará de funcionar. No se elimina el registro.</p>
              <form data-acta-cancel="<?= self::e($act['id']) ?>"><label>Motivo de anulación <textarea name="reason" required maxlength="1000" rows="2"></textarea></label><button type="submit" class="scm-acta-button scm-acta-danger">Anular acta pendiente</button></form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$active && !in_array(mb_strtolower((string) $ticket['estado']), ['cerrado', 'finalizado', 'resuelto'], true)): ?>
        <form data-acta-create>
          <h3>1. Solución y firmante</h3>
          <div class="scm-acta-grid">
            <label>¿Quién realizó la solución? *<select name="executor" required><option value="">Seleccionar responsable</option><?php foreach (CompletionPolicy::EXECUTORS as $role => $label): ?><option value="<?= self::e($role) ?>" <?= $executor === $role ? 'selected' : '' ?>><?= self::e($label) ?></option><?php endforeach; ?></select></label>
            <label>¿A quién se solicita la firma? *<select name="signer_role" required data-acta-signer><option value="">Seleccionar firmante</option><?php foreach ($context['contacts'] as $role => $contact): ?><option value="<?= self::e($role) ?>" data-name="<?= self::e($contact['name']) ?>" data-email="<?= self::e($contact['email']) ?>" data-phone="<?= self::e($contact['phone']) ?>" <?= !$contact['available'] ? 'disabled' : '' ?>><?= self::e($contact['label']) ?><?= !$contact['available'] ? ' — falta contacto para verificación' : ' · ' . self::e($contact['name']) ?></option><?php endforeach; ?></select></label>
            <label>Nombre completo de la persona que firma *<input name="signer_name" maxlength="160" required autocomplete="off" data-acta-signer-name></label>
            <label>Correo registrado para recibir la firma<input type="email" readonly data-acta-signer-email></label>
            <label>WhatsApp registrado<input type="tel" readonly data-acta-signer-phone></label>
            <label>Enviar invitación y copia firmada por *<select name="channels[]" required><option value="email">Correo</option><option value="whatsapp">WhatsApp</option><option value="both">Correo y WhatsApp</option></select></label>
          </div>
          <p class="scm-acta-help">Solo se usan los contactos registrados. Para empresas o copropiedades, indica el nombre de quien firma en su representación. El destinatario dibuja su firma y verifica un código antes del cierre.</p>
          <?php if (CompletionDelivery::otpTemplate() === ''): ?><p class="scm-acta-notice">El enlace puede enviarse por WhatsApp, pero el código se enviará por correo hasta configurar la plantilla de autenticación de WhatsApp. El firmante necesita correo válido.</p><?php endif; ?>
          <h3>2. Daños encontrados y soluciones realizadas</h3>
          <div data-acta-items><?= $this->item(0) ?></div>
          <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-add-item>Agregar otro daño y solución</button>
          <label>Observaciones finales *<textarea name="observations" required maxlength="6000" rows="3" placeholder="Describe verificaciones, alcance de la solución y observaciones para el firmante."></textarea></label>
          <h3>3. Reporte administrativo de cobro</h3>
          <p class="scm-acta-help">Tarifa administrativa según configuración de mantenimiento: salario ÷ días de trabajo × porcentaje. No es el presupuesto de reparación. Revisa este valor antes de continuar.</p>
          <div class="scm-acta-grid">
            <label>Servicio administrativo (COP) *<input type="number" name="service_fee" min="1" max="999999999" step="1" required value="<?= self::e($context['fee'] ?? '') ?>" <?= $context['fee'] !== null ? 'readonly' : '' ?> data-acta-fee></label>
            <label>Transporte (COP) *<input type="number" name="transport" min="0" max="999999999" step="1" required value="0" data-acta-transport></label>
          </div>
          <?php if ($context['fee'] === null): ?><p class="scm-acta-notice">Falta una configuración válida. Ingresa expresamente el valor administrativo; no se generarán cobros con una tarifa vacía.</p><?php endif; ?>
          <p class="scm-acta-total">Total del reporte: <output data-acta-total><?= self::money((int) ($context['fee'] ?? 0)) ?></output></p>
          <label class="scm-acta-check"><input type="checkbox" name="confirm" value="1" required><span>Revisé los daños, las soluciones, el firmante y el valor. Entiendo que al firmar se cerrará el ticket y se registrará un único reporte como no pagado y no exportado.</span></label>
          <button type="submit" class="scm-acta-button">Generar acta y solicitar firma</button>
        </form>
      <?php elseif (!$active): ?><p>El ticket está cerrado. No se pueden generar nuevas actas.</p><?php endif; ?>
      <div data-acta-message role="status" aria-live="polite"></div>
    </section>
    <?php return (string) ob_get_clean();
  }

  public function item(int $index): string
  {
    return '<fieldset class="scm-acta-item" data-acta-item><legend>Daño y solución</legend><div class="scm-acta-grid"><label>Daño encontrado *<textarea name="items[' . $index . '][damage]" required maxlength="3000" rows="3"></textarea></label><label>Solución realizada *<textarea name="items[' . $index . '][solution]" required maxlength="3000" rows="3"></textarea></label></div><label class="scm-acta-photo-field">Fotos de evidencia (opcional)<input type="file" name="acta_item_photos_' . $index . '[]" accept="image/jpeg,image/png,image/webp" multiple data-acta-photos><small>Hasta 4 fotos por daño. Se comprimen automáticamente antes de guardarlas.</small></label><div class="scm-acta-photo-preview" data-acta-photo-preview aria-live="polite"></div><button type="button" class="scm-acta-remove" data-acta-remove-item>Quitar este detalle</button></fieldset>';
  }

  public function document(array $act, array $payload, bool $staff, string $form = ''): string
  {
    $signed = $act['status'] === 'signed';
    $signature = $signed ? json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR) : [];
    ob_start(); ?>
    <article class="scm-acta scm-acta-document">
      <header><p class="scm-acta-eyebrow">SKC SuCasa Inmobiliaria · NIT 900623242-4</p><h1>Acta de satisfacción #<?= self::e($act['id']) ?></h1><p>Solución de daños · Ticket #<?= self::e($payload['ticket_number']) ?></p><strong class="scm-acta-status"><?= $signed ? 'Firmada · Ticket cerrado' : ($act['status'] === 'cancelled' ? 'Anulada · No válida para firma' : 'Pendiente de firma · Ticket abierto') ?></strong></header>
      <section><h2>Datos del servicio</h2><dl class="scm-acta-grid"><div><dt>Inmueble / contrato</dt><dd><?= self::e($payload['property']) ?> / <?= self::e($payload['contract']) ?></dd></div><div><dt>Dirección</dt><dd><?= self::e($payload['address']) ?></dd></div><div><dt>Solución realizada por</dt><dd><?= self::e(CompletionPolicy::EXECUTORS[$payload['executor']]) ?></dd></div><div><dt>Fecha del acta</dt><dd><?= self::e(date('d/m/Y H:i', (int) $payload['created_at'])) ?></dd></div></dl></section>
      <section><h2>Daños encontrados y soluciones realizadas</h2><div class="scm-acta-table-wrap"><table><thead><tr><th scope="col">#</th><th scope="col">Daño encontrado</th><th scope="col">Solución realizada</th></tr></thead><tbody><?php foreach ($payload['items'] as $index => $item): ?><tr><td><?= $index + 1 ?></td><td><?= nl2br(self::e($item['damage'])) ?></td><td><?= nl2br(self::e($item['solution'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php foreach ($payload['items'] as $index => $item): if (empty($item['photos'])) { continue; } ?><div class="scm-acta-evidence"><h3>Evidencias del daño #<?= $index + 1 ?></h3><div class="scm-acta-photo-grid"><?php foreach ($item['photos'] as $photoIndex => $photo): ?><figure><img src="<?= self::e(\SCM\Support\StoredFileService::fromRuntime()->urlFor((string) $photo['name'])) ?>" alt="Evidencia <?= $photoIndex + 1 ?> del daño <?= $index + 1 ?>" width="<?= (int) $photo['width'] ?>" height="<?= (int) $photo['height'] ?>" loading="lazy"><figcaption>Foto <?= $photoIndex + 1 ?></figcaption></figure><?php endforeach; ?></div></div><?php endforeach; ?></section>
      <section><h2>Observaciones</h2><p class="scm-acta-long-text"><?= nl2br(self::e($payload['observations'])) ?></p></section>
      <section><h2>Firmante seleccionado</h2><p><strong><?= self::e($payload['signer']['name']) ?></strong><br><?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?> · <?= self::e($payload['signer']['email']) ?></p></section>
      <?php if ($staff): ?><section><h2>Reporte administrativo · Uso interno</h2><table><tbody><tr><th scope="row">Servicio</th><td><?= self::money((int) $payload['report']['service_fee']) ?></td></tr><tr><th scope="row">Transporte</th><td><?= self::money((int) $payload['report']['transport']) ?></td></tr><tr><th scope="row">Total</th><td><?= self::money((int) $payload['report']['total']) ?></td></tr><tr><th scope="row">Registro de cobro</th><td><?= $signed ? '#' . self::e($act['report_id']) . ' · Registrado al firmar' : 'No generado' ?></td></tr></tbody></table></section><?php endif; ?>
      <?php if ($signed): ?><section class="scm-acta-signature"><h2>Firma electrónica registrada</h2><?= !empty($signature['strokes']) ? self::signatureSvg($signature['strokes']) : '' ?><p class="scm-acta-signature-name"><?= self::e($signature['name']) ?></p><p>Documento: <?= self::e($signature['document']) ?><br>Fecha: <?= self::e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?> (Colombia)</p><p><?= !empty($signature['verification']) ? 'Firma dibujada y contacto verificado por código vía ' . self::e($signature['verification']['channel']) . '. No certifica la identidad documental.' : 'Firma histórica mediante nombre escrito y aceptación expresa.' ?></p><p><?= self::e($signature['consent_text']) ?></p></section><?php endif; ?>
      <?= $form ?>
      <footer><p>Preparada por <?= self::e($payload['actor']['name']) ?> · SKC SuCasa Inmobiliaria</p><p class="scm-acta-fingerprint">Identificador de contenido (SHA-256): <?= self::e($act['payload_hash']) ?></p></footer>
    </article>
    <?php return (string) ob_get_clean();
  }

  public function signingForm(array $act, array $payload, string $csrf, string $error = '', array $submitted = [], array $channels = []): string
  {
    $options = '';
    foreach ($channels as $channel => $label) { $options .= '<option value="' . self::e($channel) . '">' . self::e($label) . '</option>'; }
    $strokes = '';
    try { $strokes = json_encode(CompletionPolicy::strokes($submitted['signature_strokes'] ?? ''), JSON_THROW_ON_ERROR); } catch (\DomainException) {}
    return '<section><h2>Revisar y firmar</h2><p class="scm-acta-notice">Firma únicamente si estás conforme con los daños, las soluciones y las observaciones descritas. Si algo no corresponde, no firmes y contacta a la inmobiliaria. Esta firma cerrará el ticket.</p>'
      . ($error !== '' ? '<p role="alert" class="scm-acta-notice">' . self::e($error) . '</p>' : '')
      . '<noscript><p class="scm-acta-notice">Activa JavaScript para dibujar la firma y solicitar el código.</p></noscript>'
      . '<div class="scm-acta-record"><h3>1. Solicita tu código de verificación</h3><label>Recibir código por<select data-acta-otp-channel>' . $options . '</select></label><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-request-code' . (!$channels ? ' disabled' : '') . '>Solicitar código</button><p role="status" aria-live="polite" data-acta-otp-status></p><p class="scm-acta-help">Vence en 10 minutos. Máximo 5 solicitudes y 5 intentos incorrectos por hora. El código verifica acceso a tu contacto, no tu documento de identidad.</p></div>'
      . '<form method="post" data-acta-sign-form>'
      . '<h3>2. Identifícate y dibuja tu firma</h3>'
      . '<input type="hidden" name="_csrf_token" value="' . self::e($csrf) . '">'
      . '<input type="hidden" name="document_hash" value="' . self::e($act['payload_hash']) . '">'
      . '<input type="hidden" name="consent_version" value="2">'
      . '<input type="hidden" name="signature_strokes" value="' . self::e($strokes) . '">'
      . '<label>Documento de identidad *<input name="document" required minlength="4" maxlength="40" autocomplete="off" value="' . self::e(is_string($submitted['document'] ?? null) ? $submitted['document'] : '') . '"></label>'
      . '<label>Nombre completo *<input name="signature_name" required maxlength="160" autocomplete="name" placeholder="' . self::e($payload['signer']['name']) . '" value="' . self::e(is_string($submitted['signature_name'] ?? null) ? $submitted['signature_name'] : '') . '"></label>'
      . '<p class="scm-acta-help">Escribe exactamente: <strong>' . self::e($payload['signer']['name']) . '</strong>. Se registrarán tu nombre, documento, fecha y evidencia técnica de esta aceptación. No se trata de una firma digital certificada.</p>'
      . '<p id="acta-draw-help">Dibuja tu firma con el dedo o el mouse. Con teclado: enfoca el recuadro, pulsa Espacio para iniciar/terminar un trazo y usa las flechas.</p><canvas class="scm-acta-canvas" width="1000" height="350" tabindex="0" aria-label="Recuadro para dibujar tu firma" aria-describedby="acta-draw-help" data-acta-canvas></canvas><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-clear>Borrar firma</button><p role="status" data-acta-draw-status></p>'
      . '<h3>3. Confirma el código y firma</h3><label>Código de 6 dígitos *<input name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></label>'
      . '<label class="scm-acta-check"><input type="checkbox" name="accepted" value="1" required><span>' . self::e(CompletionPolicy::DRAWN_CONSENT) . '</span></label>'
      . '<button type="submit" class="scm-acta-button">Firmar acta y cerrar ticket</button><p role="status" data-acta-sign-status></p></form></section>';
  }

  public static function signatureSvg(array $strokes): string
  {
    $html = '<svg class="scm-acta-signed-drawing" viewBox="0 0 1000 350" role="img" aria-label="Trazo de la firma registrada" xmlns="http://www.w3.org/2000/svg">';
    foreach ($strokes as $stroke) {
      $points = array_map(static fn(array $point): string => (float) $point[0] . ',' . (float) $point[1], $stroke);
      $html .= '<polyline points="' . self::e(implode(' ', $points)) . '" fill="none" stroke="#10264a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
    }
    return $html . '</svg>';
  }
}
