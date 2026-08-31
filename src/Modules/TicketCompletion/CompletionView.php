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
          <p>Firmante: <strong><?= self::e($payload['signer']['name']) ?></strong> · <?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?><br><?= self::e($payload['signer']['email']) ?></p>
          <p>Reporte administrativo: <strong><?= self::money((int) $payload['report']['total']) ?></strong> <?= !empty($act['report_id']) ? '· Registrado #' . self::e($act['report_id']) : '· Se registrará al firmar' ?></p>
          <?php if ($act['status'] === 'cancelled'): ?><p>Motivo: <?= self::e($act['cancellation_reason']) ?></p><?php endif; ?>
          <div class="scm-acta-actions"><a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id'])) ?>" target="_blank" rel="noopener" data-acta-preview>Ver acta</a>
          <?php if ($act['status'] === 'pending'): ?>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-resend="<?= self::e($act['id']) ?>">Reenviar invitación</button>
          <?php endif; ?></div>
          <?php if ($act['status'] === 'pending'): ?>
            <p class="scm-acta-help"><?= empty($act['invitation_queued_at']) ? 'Atención: no hay un correo encolado. Reintenta el envío.' : 'Último correo encolado: ' . self::e(date('d/m/Y H:i', (int) $act['invitation_queued_at'])) . '. Encolado no significa entregado.' ?> El enlace vence el <?= self::e(date('d/m/Y', (int) $act['expires_at'])) ?>.</p>
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
            <label>¿Quién realizó la solución? *<select name="executor" required><option value="">Seleccionar responsable</option><?php foreach (CompletionPolicy::ROLES as $role => $label): ?><option value="<?= self::e($role) ?>" <?= $executor === $role ? 'selected' : '' ?>><?= self::e($label) ?></option><?php endforeach; ?></select></label>
            <label>¿A quién se solicita la firma? *<select name="signer_role" required data-acta-signer><option value="">Seleccionar firmante</option><?php foreach ($context['contacts'] as $role => $contact): ?><option value="<?= self::e($role) ?>" data-name="<?= self::e($contact['name']) ?>" data-email="<?= self::e($contact['email']) ?>" <?= !$contact['available'] ? 'disabled' : '' ?>><?= self::e($contact['label']) ?><?= !$contact['available'] ? ' — falta nombre o correo válido' : ' · ' . self::e($contact['name']) ?></option><?php endforeach; ?></select></label>
            <label>Nombre completo de la persona que firma *<input name="signer_name" maxlength="160" required autocomplete="off" data-acta-signer-name></label>
            <label>Correo registrado para recibir la firma<input type="email" readonly data-acta-signer-email></label>
          </div>
          <p class="scm-acta-help">La invitación se enviará al correo registrado, no a uno escrito libremente. Para una empresa o copropiedad, indica el nombre de la persona que firma en su representación. Si falta un contacto, actualízalo en el ticket.</p>
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
    return '<fieldset class="scm-acta-item" data-acta-item><legend>Daño y solución</legend><div class="scm-acta-grid"><label>Daño encontrado *<textarea name="items[' . $index . '][damage]" required maxlength="3000" rows="3"></textarea></label><label>Solución realizada *<textarea name="items[' . $index . '][solution]" required maxlength="3000" rows="3"></textarea></label></div><button type="button" class="scm-acta-remove" data-acta-remove-item>Quitar este detalle</button></fieldset>';
  }

  public function document(array $act, array $payload, bool $staff, string $form = ''): string
  {
    $signed = $act['status'] === 'signed';
    $signature = $signed ? json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR) : [];
    ob_start(); ?>
    <article class="scm-acta scm-acta-document">
      <header><p class="scm-acta-eyebrow">SKC SuCasa Inmobiliaria · NIT 900623242-4</p><h1>Acta de satisfacción #<?= self::e($act['id']) ?></h1><p>Solución de daños · Ticket #<?= self::e($payload['ticket_number']) ?></p><strong class="scm-acta-status"><?= $signed ? 'Firmada · Ticket cerrado' : ($act['status'] === 'cancelled' ? 'Anulada · No válida para firma' : 'Pendiente de firma · Ticket abierto') ?></strong></header>
      <section><h2>Datos del servicio</h2><dl class="scm-acta-grid"><div><dt>Inmueble / contrato</dt><dd><?= self::e($payload['property']) ?> / <?= self::e($payload['contract']) ?></dd></div><div><dt>Dirección</dt><dd><?= self::e($payload['address']) ?></dd></div><div><dt>Solución realizada por</dt><dd><?= self::e(CompletionPolicy::ROLES[$payload['executor']]) ?></dd></div><div><dt>Fecha del acta</dt><dd><?= self::e(date('d/m/Y H:i', (int) $payload['created_at'])) ?></dd></div></dl></section>
      <section><h2>Daños encontrados y soluciones realizadas</h2><div class="scm-acta-table-wrap"><table><thead><tr><th scope="col">#</th><th scope="col">Daño encontrado</th><th scope="col">Solución realizada</th></tr></thead><tbody><?php foreach ($payload['items'] as $index => $item): ?><tr><td><?= $index + 1 ?></td><td><?= nl2br(self::e($item['damage'])) ?></td><td><?= nl2br(self::e($item['solution'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
      <section><h2>Observaciones</h2><p class="scm-acta-long-text"><?= nl2br(self::e($payload['observations'])) ?></p></section>
      <section><h2>Firmante seleccionado</h2><p><strong><?= self::e($payload['signer']['name']) ?></strong><br><?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?> · <?= self::e($payload['signer']['email']) ?></p></section>
      <?php if ($staff): ?><section><h2>Reporte administrativo · Uso interno</h2><table><tbody><tr><th scope="row">Servicio</th><td><?= self::money((int) $payload['report']['service_fee']) ?></td></tr><tr><th scope="row">Transporte</th><td><?= self::money((int) $payload['report']['transport']) ?></td></tr><tr><th scope="row">Total</th><td><?= self::money((int) $payload['report']['total']) ?></td></tr><tr><th scope="row">Registro de cobro</th><td><?= $signed ? '#' . self::e($act['report_id']) . ' · Registrado al firmar' : 'No generado' ?></td></tr></tbody></table></section><?php endif; ?>
      <?php if ($signed): ?><section class="scm-acta-signature"><h2>Firma electrónica registrada</h2><p class="scm-acta-signature-name"><?= self::e($signature['name']) ?></p><p>Documento: <?= self::e($signature['document']) ?><br>Fecha: <?= self::e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?> (Colombia)</p><p>Aceptación expresa mediante nombre escrito y enlace personal enviado al correo del firmante.</p></section><?php endif; ?>
      <?= $form ?>
      <footer><p>Preparada por <?= self::e($payload['actor']['name']) ?> · SKC SuCasa Inmobiliaria</p><p class="scm-acta-fingerprint">Identificador de contenido (SHA-256): <?= self::e($act['payload_hash']) ?></p></footer>
    </article>
    <?php return (string) ob_get_clean();
  }

  public function signingForm(array $act, array $payload, string $csrf, string $error = '', array $submitted = []): string
  {
    return '<section><h2>Revisar y firmar</h2><p class="scm-acta-notice">Firma únicamente si estás conforme con los daños, las soluciones y las observaciones descritas. Si algo no corresponde, no firmes y contacta a la inmobiliaria. Esta firma cerrará el ticket.</p>'
      . ($error !== '' ? '<p role="alert" class="scm-acta-notice">' . self::e($error) . '</p>' : '')
      . '<form method="post" data-acta-sign-form>'
      . '<input type="hidden" name="_csrf_token" value="' . self::e($csrf) . '">'
      . '<input type="hidden" name="document_hash" value="' . self::e($act['payload_hash']) . '">'
      . '<label>Documento de identidad *<input name="document" required minlength="4" maxlength="40" autocomplete="off" value="' . self::e(is_string($submitted['document'] ?? null) ? $submitted['document'] : '') . '"></label>'
      . '<label>Firma: escribe tu nombre completo *<input name="signature_name" required maxlength="160" autocomplete="name" placeholder="' . self::e($payload['signer']['name']) . '" value="' . self::e(is_string($submitted['signature_name'] ?? null) ? $submitted['signature_name'] : '') . '"></label>'
      . '<p class="scm-acta-help">Escribe exactamente: <strong>' . self::e($payload['signer']['name']) . '</strong>. Se registrarán tu nombre, documento, fecha y evidencia técnica de esta aceptación. No se trata de una firma digital certificada.</p>'
      . '<label class="scm-acta-check"><input type="checkbox" name="accepted" value="1" required><span>' . self::e(CompletionPolicy::CONSENT) . '</span></label>'
      . '<button type="submit" class="scm-acta-button">Firmar acta y cerrar ticket</button><p role="status" data-acta-sign-status></p></form></section>';
  }
}
