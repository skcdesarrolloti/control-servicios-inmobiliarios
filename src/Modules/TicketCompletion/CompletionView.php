<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

final class CompletionView
{
  private string $damageReview = 'correctiva';

  public function __construct(private ?\Closure $correctiveItem = null) {}

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  public function panel(array $context, CompletionService $service, int $editActId = 0, bool $showHistory = true, bool $canDeleteAny = false): string
  {
    $ticket = $context['ticket'];
    // Review (corrective or preventive) where acta damages are synced; empty when the case has none.
    $this->damageReview = (string) ($context['damage_review'] ?? 'correctiva');
    $active = false;
    $activeAct = null;
    $editAct = null;
    $editPayload = null;
    foreach ($context['acts'] as $act) {
      $active = $active || in_array($act['status'], ['pending', 'signed'], true);
      if ($activeAct === null && in_array($act['status'], ['pending', 'signed'], true)) {
        $activeAct = $act;
      }
      if ($editActId > 0 && (int) $act['id'] === $editActId && in_array($act['status'], ['pending', 'signed'], true)) {
        $editAct = $act;
        $editPayload = $service->payload($act);
      }
    }
    $executor = str_replace('En ejecucion por ', '', (string) ($ticket['estado_administrativo'] ?? ''));
    if (!isset(CompletionPolicy::EXECUTORS[$executor])) {
      // Sin estado "En ejecucion por …": se propone quién ejecuta según el caso.
      $caseExecutor = mb_strtolower(trim((string) ($ticket['ejecutado'] ?? '')), 'UTF-8');
      $executor = '';
      foreach (CompletionPolicy::EXECUTORS as $role => $_label) {
        if ($caseExecutor !== '' && str_starts_with($caseExecutor, substr($role, 0, 6))) {
          $executor = $role;
          break;
        }
      }
    }
    $formPayload = is_array($editPayload) ? $editPayload : [];
    $selectedExecutor = (string) ($formPayload['executor'] ?? $executor);
    $selectedSigner = (string) ($formPayload['signer']['role'] ?? '');
    $signerName = (string) ($formPayload['signer']['name'] ?? '');
    // Un contacto personalizado se conserva al editar; si no, se muestra el registrado vigente.
    $registeredSigner = (array) ($context['contacts'][$selectedSigner] ?? []);
    $signerEmail = (string) (!empty($formPayload['signer']['custom_email']) ? $formPayload['signer']['email'] : ($registeredSigner['email'] ?? ''));
    $signerPhone = (string) (!empty($formPayload['signer']['custom_phone']) ? $formPayload['signer']['phone'] : ($registeredSigner['phone'] ?? ''));
    $selectedChannels = (array) ($formPayload['channels'] ?? []);
    // Por defecto la invitación y la copia firmada salen por correo y WhatsApp.
    $selectedChannel = count($selectedChannels) !== 1 ? 'both' : (string) $selectedChannels[0];
    $suggestedItems = is_array($context['suggested_items'] ?? null) ? $context['suggested_items'] : [];
    $formItems = is_array($formPayload['items'] ?? null) && $formPayload['items'] ? $formPayload['items'] : ($suggestedItems ?: [[]]);
    $normalizeDamage = static fn(string $text): string => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace(["\r\n", "\r"], "\n", html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    // Enrich older acts whose payload stored only the damage summary.
    foreach ($formItems as &$formItem) {
      if (!empty($formItem['corrective'])) continue;
      $matches = array_values(array_filter($suggestedItems, static fn(array $suggestion): bool => $normalizeDamage((string) ($suggestion['damage'] ?? '')) === $normalizeDamage((string) ($formItem['damage'] ?? '')) && !empty($suggestion['corrective'])));
      if (count($matches) === 1) {
        $formItem['corrective'] = $matches[0]['corrective'];
        $formItem['corrective_sync_id'] = $matches[0]['corrective_sync_id'];
        if (empty($formItem['damage_photos'])) $formItem['damage_photos'] = $matches[0]['damage_photos'] ?? [];
      }
    }
    unset($formItem);
    $draftRevision = $editAct['payload_hash'] ?? hash('sha256', json_encode([$suggestedItems, $context['contacts']], JSON_THROW_ON_ERROR));
    if ($editAct && $formItems !== ($formPayload['items'] ?? [])) {
      // An older draft must not hide newly recovered corrective fields or photos.
      $draftRevision = hash('sha256', json_encode([$draftRevision, $formItems], JSON_THROW_ON_ERROR));
    }
    $formObservations = (string) ($formPayload['observations'] ?? '');
    $sourceFlow = is_array($formPayload['source'] ?? null) ? $formPayload['source'] : (is_array($context['source_flow'] ?? null) ? $context['source_flow'] : []);
    $sourceName = (string) ($sourceFlow['flow'] ?? 'ticket_solution');
    $sourceQuoteId = (string) ($sourceFlow['quote_id'] ?? '');
    // Con cotización aprobada ejecuta la inmobiliaria; sin cotización la solución la hizo otro responsable.
    // El servicio valida lo mismo al guardar.
    $executorOptions = $sourceName === 'approved_quote' ? ['inmobiliaria' => CompletionPolicy::EXECUTORS['inmobiliaria']] : CompletionPolicy::ROLES;
    if ($sourceName === 'approved_quote') {
      $selectedExecutor = 'inmobiliaria';
    } elseif (!isset($executorOptions[$selectedExecutor])) {
      $selectedExecutor = '';
    }
    $sourceNotice = $sourceName === 'approved_quote' && trim($sourceQuoteId) !== ''
      ? 'Esta acta quedará asociada a la cotización aprobada #' . trim($sourceQuoteId) . '. Al firmarse cerrará el caso y registrará el trabajo finalizado sin cambiar el estado de la cotización ni generar reporte administrativo nuevo.'
      : '';
    ob_start(); ?>
    <section class="scm-acta">
      <p class="scm-acta-notice">Documenta la solución y elige el firmante. El caso quedará en <strong>En ejecución</strong> mientras el acta está pendiente, y quién realizó la solución se guarda en <strong>Ejecutado por</strong>. Puedes hacer seguimiento desde <strong>Actividades administrativas → Actas de satisfacción</strong>. Solo la firma registrará el cierre; no se creará reporte administrativo nuevo desde esta acta.</p>
      <?php if ($sourceNotice !== ''): ?><p class="scm-acta-notice"><?= self::e($sourceNotice) ?></p><?php endif; ?>
      <?php if ($editAct): ?><p class="scm-acta-notice">Estás editando el acta #<?= self::e($editAct['id']) ?>. Al guardar <?= $editAct['status'] === 'signed' ? 'quedará sin firmar y el caso volverá a estar en proceso. La versión firmada anterior se conservará en el historial. Se invalidan la firma, enlaces y códigos anteriores y se solicita una nueva firma.' : 'se invalidan los códigos anteriores y se envía una nueva invitación.' ?></p><?php endif; ?>
      <div class="scm-acta-meta"><span>Caso <strong>#<?= self::e($ticket['id_ticket'] ?: $ticket['_ID']) ?></strong></span><span>Contrato <strong><?= self::e($ticket['contrato'] ?? '—') ?></strong> · Inmueble SIMI <strong><?= self::e($ticket['inmueble'] ?? '—') ?></strong></span></div>
      <?php if ($showHistory): foreach ($context['acts'] as $act): $payload = $service->payload($act); $canDeleteAct = in_array($act['status'], ['archived', 'cancelled'], true) || $canDeleteAny; ?>
        <article class="scm-acta-record">
          <div class="scm-acta-meta"><h3><?= $act['status'] === 'superseded' ? 'Versión firmada anterior · Registro #' : 'Acta de satisfacción #' ?><?= self::e($act['id']) ?></h3><strong class="scm-acta-status" data-acta-status="<?= self::e($act['status']) ?>"><?= self::e(['pending' => 'Acta sin firmar', 'signed' => 'Firmada', 'archived' => 'Archivada', 'cancelled' => 'Anulada', 'superseded' => 'Versión firmada anterior'][$act['status']] ?? $act['status']) ?></strong></div>
          <?php if ($act['status'] === 'pending'): ?><p class="scm-acta-help">También puedes consultarla y administrarla desde <strong>Actividades administrativas → Actas de satisfacción</strong>.</p><?php endif; ?>
          <p>Firmante: <strong><?= self::e($payload['signer']['name']) ?></strong> · <?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?><br><?= self::e($payload['signer']['email']) ?> · <?= self::e($payload['signer']['phone']) ?><?php if (!empty($payload['signer']['custom_email']) || !empty($payload['signer']['custom_phone'])): ?><br><small>Contacto usado solo en esta acta; los datos registrados de la persona no cambiaron.</small><?php endif; ?></p>
          <?php
            $recordSource = is_array($payload['source'] ?? null) ? $payload['source'] : [];
          ?>
          <?php if (trim((string) ($recordSource['quote_id'] ?? '')) !== ''): ?>
            <p>Cotización asociada: <strong>#<?= self::e($recordSource['quote_id']) ?></strong></p>
          <?php endif; ?>
          <?php if (in_array($act['status'], ['archived', 'cancelled', 'superseded'], true)): ?><p>Motivo: <?= self::e($act['cancellation_reason']) ?></p><?php endif; ?>
          <div class="scm-acta-actions"><?php if ($act['status'] !== 'superseded'): ?><a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id'])) ?>" data-acta-preview>Ver acta</a><?php endif; ?>
          <?php if (in_array($act['status'], ['signed', 'superseded'], true)): ?>
          <a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id']) . '&format=pdf' . ($act['status'] === 'superseded' ? '&audience=staff' : '')) ?>" download>Descargar PDF firmado</a>
          <?php endif; ?>
          <?php if (in_array($act['status'], ['pending', 'signed'], true) && (int) ($act['active_slot'] ?? 1) === 1): ?><a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->editUrl($act)) ?>" data-acta-edit="<?= self::e($act['id']) ?>" data-acta-signed="<?= $act['status'] === 'signed' ? '1' : '0' ?>">Editar acta</a><?php endif; ?>
          <?php if (in_array($act['status'], ['pending', 'signed'], true)): ?>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-resend="<?= self::e($act['id']) ?>"><?= $act['status'] === 'signed' ? 'Reenviar copia firmada' : 'Reenviar invitación' ?></button>
          <?php endif; ?>
          <?php if ($act['status'] === 'pending'): ?><button type="button" class="scm-acta-button scm-acta-danger" data-acta-archive="<?= self::e($act['id']) ?>">Archivar</button><?php endif; ?>
          <?php if ($canDeleteAct): ?><button type="button" class="scm-acta-button scm-acta-danger" data-acta-delete="<?= self::e($act['id']) ?>">Eliminar</button><?php endif; ?></div>
          <?php $delivery = json_decode((string) ($act['delivery_json'] ?? ''), true) ?: []; $event = $act['status'] === 'signed' ? 'signed_receipt' : 'signature_invitation'; ?>
          <?php if (!in_array($act['status'], ['archived', 'cancelled', 'superseded'], true)): ?><ul class="scm-acta-help"><?php foreach ($payload['channels'] ?? ['email'] as $channel): ?><li><?= $channel === 'email' ? 'Correo' : 'WhatsApp' ?>: <?= !empty($delivery[$event][$channel]['sent']) ? 'enviado al proveedor' : (!empty($delivery[$event][$channel]['queued']) ? 'pendiente de reintento o confirmación; el acta permanece guardada' : 'no se pudo registrar el envío; reintenta') ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($act['status'] === 'pending'): ?>
            <p class="scm-acta-help">El enlace vence el <?= self::e(date('d/m/Y', (int) $act['expires_at'])) ?>. Reenviar no cierra el caso.</p>
            <details><summary>¿Necesitas corregir el acta o cambiar el firmante?</summary>
              <p>Anula esta versión y genera otra. El enlace anterior dejará de funcionar. No se elimina el registro.</p>
              <form data-acta-cancel="<?= self::e($act['id']) ?>"><label>Motivo de anulación <textarea name="reason" required maxlength="1000" rows="2"></textarea></label><button type="submit" class="scm-acta-button scm-acta-danger">Anular acta pendiente</button></form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; endif; ?>
      <?php if ((!$active || $editAct) && ($editAct || !in_array(mb_strtolower((string) $ticket['estado']), ['cerrado', 'finalizado', 'resuelto'], true))): ?>
        <form data-acta-create data-acta-draft-user="<?= self::e(\SCM\Core\Auth::userId()) ?>" data-acta-draft-ticket="<?= self::e($ticket['_ID']) ?>" data-acta-draft-revision="<?= self::e($draftRevision) ?>" data-acta-operation="<?= $editAct ? 'update' : 'create' ?>"<?= $editAct ? ' data-acta-id="' . self::e($editAct['id']) . '"' : '' ?>>
          <?php if ($editAct && $editAct['status'] === 'signed'): ?><label class="scm-acta-check"><input type="checkbox" name="confirm_reopen" value="1" required><span>Confirmo que al guardar esta acta quedará sin firmar, el caso se reabrirá y se enviará una nueva solicitud de firma.</span></label><?php endif; ?>
          <input type="hidden" name="source_flow" value="<?= self::e($sourceName) ?>">
          <input type="hidden" name="source_cotizacion_id" value="<?= self::e($sourceQuoteId) ?>">
          <h3>1. <?= $editAct ? 'Editar solución y firmante' : 'Solución y firmante' ?></h3>
          <div class="scm-acta-grid">
            <label>¿Quién realizó la solución? *<select name="executor" required><option value="">Seleccionar responsable</option><?php foreach ($executorOptions as $role => $label): ?><option value="<?= self::e($role) ?>" <?= $selectedExecutor === $role ? 'selected' : '' ?>><?= self::e($label) ?></option><?php endforeach; ?></select></label>
            <label>¿A quién se solicita la firma? *<select name="signer_role" required data-acta-signer><option value="">Seleccionar firmante</option><?php foreach ($context['contacts'] as $role => $contact): ?><option value="<?= self::e($role) ?>" data-name="<?= self::e($contact['name']) ?>" data-email="<?= self::e($contact['email']) ?>" data-phone="<?= self::e($contact['phone']) ?>" <?= $selectedSigner === $role ? 'selected' : '' ?> <?= !$contact['available'] ? 'disabled' : '' ?>><?= self::e($contact['label']) ?><?= !$contact['available'] ? ' — falta contacto para verificación' : ' · ' . self::e($contact['name']) ?></option><?php endforeach; ?></select></label>
            <label>Nombre completo de la persona que firma *<input name="signer_name" maxlength="160" required autocomplete="off" data-acta-signer-name value="<?= self::e($signerName) ?>"></label>
            <label>Correo para recibir la firma<input type="email" name="signer_email" maxlength="160" autocomplete="off" data-acta-signer-email value="<?= self::e($signerEmail) ?>"></label>
            <label>WhatsApp para recibir la firma<input type="tel" name="signer_phone" maxlength="30" autocomplete="off" data-acta-signer-phone value="<?= self::e($signerPhone) ?>"></label>
            <label>Enviar invitación y copia firmada por *<select name="channels[]" required><option value="both" <?= $selectedChannel === 'both' ? 'selected' : '' ?>>Correo y WhatsApp</option><option value="email" <?= $selectedChannel === 'email' ? 'selected' : '' ?>>Solo correo</option><option value="whatsapp" <?= $selectedChannel === 'whatsapp' ? 'selected' : '' ?>>Solo WhatsApp</option></select></label>
          </div>
          <p class="scm-acta-help">El correo y el WhatsApp se cargan del registro. Si piden que llegue a otro contacto, cámbialos aquí: aplica solo a esta acta y no modifica los datos registrados de la persona. Si firmará otra persona, cambia también el nombre. Para empresas o copropiedades, indica el nombre de quien firma en su representación. El destinatario confirma su nombre y verifica un código antes del cierre.</p>
          <?php if (CompletionDelivery::otpTemplate() === ''): ?><p class="scm-acta-notice">El enlace puede enviarse por WhatsApp, pero el código se enviará por correo hasta configurar la plantilla de autenticación de WhatsApp. El firmante necesita correo válido.</p><?php endif; ?>
          <h3>2. Daños encontrados y soluciones realizadas</h3>
          <div data-acta-items><?php foreach ($formItems as $index => $item): ?><?= $this->item((int) $index, is_array($item) ? $item : []) ?><?php endforeach; ?></div>
          <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-add-item>Agregar otro daño y solución</button>
          <label>Observaciones finales *<textarea name="observations" required maxlength="6000" rows="3" placeholder="Describe verificaciones, alcance de la solución y observaciones para el firmante."><?= self::e($formObservations) ?></textarea></label>
          <input type="hidden" name="service_fee" value="0" data-acta-fee>
          <input type="hidden" name="transport" value="0" data-acta-transport>
          <label class="scm-acta-check"><input type="checkbox" name="confirm" value="1" required><span>Revisé los daños, las soluciones y el firmante. Entiendo que al firmar se cerrará el caso sin crear un reporte administrativo nuevo.</span></label>
          <button type="submit" class="scm-acta-button"><?= $editAct ? 'Guardar cambios y reenviar firma' : 'Generar acta y solicitar firma' ?></button>
        </form>
      <?php elseif (!$showHistory && $activeAct): ?>
        <p class="scm-acta-notice">Este caso ya tiene un acta <?= self::e($activeAct['status'] === 'pending' ? 'sin firmar' : 'firmada') ?>. Adminístrala desde <strong>Actividades administrativas → Actas de satisfacción</strong>.</p>
        <p><a class="scm-acta-button" href="<?= self::e($service->dashboardUrlForTicket($ticket, $activeAct['status'] === 'signed' ? 'signed' : 'pending')) ?>">Ir a Actas de satisfacción</a></p>
      <?php elseif (!$active): ?><p>El caso está cerrado. No se pueden generar nuevas actas.</p><?php endif; ?>
      <div data-acta-message role="status" aria-live="polite"></div>
    </section>
    <?php return (string) ob_get_clean();
  }

  public function item(int $index, array $values = []): string
  {
    $hasCorrective = is_array($values['corrective'] ?? null) && $values['corrective'] !== [];
    $syncId = trim((string) ($values['corrective_sync_id'] ?? ''));
    $correctiveHtml = $this->correctiveItem !== null && $this->damageReview !== '' ? ($this->correctiveItem)($index, $hasCorrective ? $values['corrective'] : []) : '';
    $correctiveBlock = $correctiveHtml === '' ? '' : '<div data-acta-corrective-wrap' . ($hasCorrective ? '' : ' hidden') . '><fieldset data-acta-corrective-fields' . ($hasCorrective ? '' : ' disabled') . '><legend>Detalle para la revisión ' . self::e($this->damageReview) . '</legend>' . $correctiveHtml . '</fieldset></div>';
    $syncField = $syncId !== '' ? '<input type="hidden" name="items[' . $index . '][corrective_sync_id]" value="' . self::e($syncId) . '">' : '';
    $helpId = 'acta-photo-help-' . $index;
    $damagePhotosHtml = '';
    foreach ((array) ($values['damage_photos'] ?? []) as $photoIndex => $photo) {
      if (!is_array($photo)) { continue; }
      $photoUrl = \SCM\Support\StoredFileService::fromRuntime()->urlFor((string) ($photo['name'] ?? ''));
      $damagePhotosHtml .= '<figure data-acta-existing-photo data-acta-damage-photo><img src="' . self::e($photoUrl) . '" alt="Evidencia del daño precargada ' . ((int) $photoIndex + 1) . '" loading="lazy"><figcaption>Evidencia del daño ' . ((int) $photoIndex + 1) . '</figcaption><button type="button" class="scm-acta-photo-remove" data-acta-remove-existing-photo aria-label="Quitar evidencia del daño precargada ' . ((int) $photoIndex + 1) . '"><span aria-hidden="true">×</span></button>';
      foreach (['name', 'mime', 'width', 'height', 'bytes', 'sha256'] as $key) {
        $damagePhotosHtml .= '<input type="hidden" name="items[' . $index . '][damage_photos][' . (int) $photoIndex . '][' . $key . ']" value="' . self::e($photo[$key] ?? '') . '">';
      }
      $damagePhotosHtml .= '</figure>';
    }
    $damagePhotosBlock = $damagePhotosHtml !== '' ? '<div class="scm-acta-photo-reference"><strong>Evidencia del daño cargada desde la revisión</strong><div class="scm-acta-photo-preview" aria-live="polite" aria-label="Evidencias del daño precargadas">' . $damagePhotosHtml . '</div></div>' : '';
    $photosHtml = '';
    foreach ((array) ($values['photos'] ?? []) as $photoIndex => $photo) {
      if (!is_array($photo)) { continue; }
      $photoUrl = \SCM\Support\StoredFileService::fromRuntime()->urlFor((string) ($photo['name'] ?? ''));
      $photosHtml .= '<figure data-acta-existing-photo><img src="' . self::e($photoUrl) . '" alt="Evidencia de solución ' . ((int) $photoIndex + 1) . '" loading="lazy"><figcaption>Evidencia de solución ' . ((int) $photoIndex + 1) . '</figcaption><button type="button" class="scm-acta-photo-remove" data-acta-remove-existing-photo aria-label="Quitar evidencia de solución ' . ((int) $photoIndex + 1) . '"><span aria-hidden="true">×</span></button>';
      foreach (['name', 'mime', 'width', 'height', 'bytes', 'sha256'] as $key) {
        $photosHtml .= '<input type="hidden" name="items[' . $index . '][photos][' . (int) $photoIndex . '][' . $key . ']" value="' . self::e($photo[$key] ?? '') . '">';
      }
      $photosHtml .= '</figure>';
    }
    return '<fieldset class="scm-acta-item" data-acta-item><legend>Daño y solución</legend>' . $syncField . $correctiveBlock . '<div class="scm-acta-grid"><label>Daño encontrado *<textarea name="items[' . $index . '][damage]" required maxlength="3000" rows="3">' . self::e($values['damage'] ?? '') . '</textarea></label><label>Solución realizada *<textarea name="items[' . $index . '][solution]" required maxlength="3000" rows="3">' . self::e($values['solution'] ?? '') . '</textarea></label></div>' . $damagePhotosBlock . '<div class="scm-acta-photo-field"><label>Fotos de la solución realizada (opcional)<input type="file" name="acta_item_photos_' . $index . '[]" accept="image/jpeg,image/png,image/webp" multiple data-acta-photos aria-describedby="' . $helpId . '"></label><button type="button" class="scm-acta-photo-paste" data-acta-photo-paste><strong>Pegar foto de solución</strong><span>Haz clic aquí y presiona Ctrl+V</span></button><small id="' . $helpId . '" data-acta-photo-help>Sin límite de cantidad de fotos. Máximo 8 MB en toda el acta después de comprimir. JPG, PNG o WebP; hasta 25 MB por archivo. Se comprimen automáticamente.</small></div><div class="scm-acta-photo-preview" data-acta-photo-preview aria-live="polite" aria-label="Fotos de solución seleccionadas">' . $photosHtml . '</div><button type="button" class="scm-acta-remove" data-acta-remove-item>Quitar este detalle</button></fieldset>';
  }

  /** @var array<string,array{0:string,1:string,2:string,3:string}> estado => [pestaña, icono, título, descripción] */
  private const DASHBOARD_TABS = [
    'pending' => ['Sin firmar', 'clock', 'Actas sin firmar', 'Actas enviadas que esperan la firma del destinatario. Quedan aquí hasta que firme.'],
    'signed' => ['Firmadas', 'check', 'Actas firmadas', 'Actas firmadas por el destinatario. Puedes verlas o descargar el PDF firmado.'],
    'archived' => ['Archivadas', 'document', 'Actas archivadas', 'Actas retiradas de pendientes sin cerrar el caso ni generar cobro.'],
    'cancelled' => ['Anuladas', 'close', 'Actas anuladas', 'Actas anuladas. No cerraron el caso ni generaron cobro.'],
    'superseded' => ['Versiones anteriores', 'refresh', 'Versiones firmadas anteriores', 'Firmas anteriores conservadas cuando un acta firmada se reabrió para corregirla.'],
    'all' => ['Todas', 'filter', 'Todas las actas', 'Todas las actas, sin importar su estado.'],
  ];

  /** @param array<string,string|int> $filters @param array<int,array<string,mixed>> $items @param array<string,int> $stats @param array<string,int> $pagination */
  public function dashboardPanel(array $filters, array $items, array $stats, int $count, array $pagination, CompletionService $service, bool $canDeleteAny = false, string $portfolioSelect = ''): string
  {
    $ui = \SCM\Modules\Pending\PublicServicesUi::class;
    $status = (string) ($filters['estado'] ?? 'pending');
    $status = isset(self::DASHBOARD_TABS[$status]) ? $status : 'pending';
    $field = static fn(string $id, string $label, string $control): string => '<label class="!sp-block" for="' . $id . '"><span class="' . $ui::LABEL . '">' . self::e($label) . '</span>' . $control . '</label>';
    $input = static fn(string $id, string $value, string $placeholder): string => '<input class="' . $ui::INPUT . '" id="' . $id . '" name="' . $id . '" type="text" value="' . self::e($value) . '" placeholder="' . self::e($placeholder) . '">';
    $perPage = '';
    foreach ([30, 50, 100] as $size) {
      $perPage .= '<option value="' . $size . '"' . ((int) ($filters['per_page'] ?? 30) === $size ? ' selected' : '') . '>' . $size . '</option>';
    }
    $tabsJson = array_map(static fn(array $tab): array => ['title' => $tab[2], 'description' => $tab[3]], self::DASHBOARD_TABS);
    ob_start(); ?>
    <div class="scm-actas-dashboard !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" id="sacta_panel">
      <header class="<?= $ui::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?= $ui::icon('check', '!sp-w-6 !sp-h-6') ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Actas de satisfacción</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Recibido a satisfacción de los trabajos: firma del destinatario, cierre del caso y cobro</p></div></div>
        <div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" id="sacta-kpi-count"><?= self::e((string) $count) ?></strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Actas en la vista</p></div>
      </header>
      <div id="sacta_kpis"><?= $this->dashboardKpis($stats, $status) ?></div>
      <div class="<?= $ui::CARD ?> !sp-p-5">
        <div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?= $ui::icon('filter') ?>Filtros</h3><span class="!sp-text-[10px] !sp-text-service-muted">Los filtros aplican a todas las pestañas</span></div>
        <form method="post" autocomplete="off" id="sacta_form">
          <input type="hidden" name="sacta_estado" value="<?= self::e($status) ?>" data-acta-status-input>
          <input type="hidden" name="sacta_page" value="<?= (int) ($pagination['page'] ?? 1) ?>">
          <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-6 !sp-gap-4">
            <?= $field('sacta_caso', '# Caso', $input('sacta_caso', (string) ($filters['caso'] ?? ''), 'Número del caso')) ?>
            <?= $field('sacta_inmueble', 'Inmueble', $input('sacta_inmueble', (string) ($filters['inmueble'] ?? ''), '# inmueble')) ?>
            <?= $field('sacta_contrato', 'Contrato', $input('sacta_contrato', (string) ($filters['contrato'] ?? ''), '# contrato')) ?>
            <?= $field('sacta_firmante', 'Firmante', $input('sacta_firmante', (string) ($filters['firmante'] ?? ''), 'Nombre, correo o celular')) ?>
            <?php if ($portfolioSelect !== ''): ?><label class="!sp-block"><span class="<?= $ui::LABEL ?>">Ver</span><?= $portfolioSelect ?></label><?php endif ?>
            <?= $field('sacta_per_page', 'Registros por página', '<select class="' . $ui::INPUT . '" id="sacta_per_page" name="sacta_per_page">' . $perPage . '</select>') ?>
          </div>
          <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5">
            <button class="<?= $ui::PRIMARY ?>" type="submit"><?= $ui::icon('filter') ?>Filtrar</button>
            <button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-pending-clear="sacta_">Limpiar filtros</button>
            <span class="scm-spinner" id="sacta_spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
          </div>
        </form>
      </div>
      <div class="<?= $ui::CARD ?>">
        <div class="!sp-p-4"><h3 class="!sp-m-0 !sp-font-bold !sp-text-sm" data-acta-tab-title><?= self::e(self::DASHBOARD_TABS[$status][2]) ?></h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted" data-acta-tab-description><?= self::e(self::DASHBOARD_TABS[$status][3]) ?></p></div>
        <div id="sacta_table"><?= $this->dashboardTable($items, $service, $pagination, $canDeleteAny) ?></div>
      </div>
      <script type="application/json" data-acta-tabs><?= (string) json_encode($tabsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    </div>
    <?php return (string) ob_get_clean();
  }

  /** Pestañas por estado con su contador (se recargan junto con el listado). @param array<string,int> $stats */
  public function dashboardKpis(array $stats, string $status = 'pending'): string
  {
    $ui = \SCM\Modules\Pending\PublicServicesUi::class;
    $status = isset(self::DASHBOARD_TABS[$status]) ? $status : 'pending';
    $html = '<nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-w-fit !sp-max-w-full !sp-font-sans" role="tablist" aria-label="Estados de las actas">';
    foreach (self::DASHBOARD_TABS as $key => $tab) {
      $html .= '<button type="button" class="sp-group !sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-acta-tab="' . self::e($key) . '" aria-pressed="' . ($key === $status ? 'true' : 'false') . '">'
        . $ui::icon($tab[1], '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow') . '<span>' . self::e($tab[0]) . '</span>'
        . '<span class="!sp-min-w-[18px] !sp-px-1.5 !sp-py-0.5 !sp-rounded-full !sp-bg-white !sp-text-service-navy !sp-text-[9px] !sp-font-bold !sp-text-center group-aria-pressed:!sp-bg-service-yellow">' . self::e((string) (int) ($stats[$key] ?? 0)) . '</span></button>';
    }
    return $html . '</nav>';
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,int> $pagination */
  public function dashboardTable(array $items, CompletionService $service, array $pagination = [], bool $canDeleteAny = false): string
  {
    $ui = \SCM\Modules\Pending\PublicServicesUi::class;
    if (!$items) {
      return '<div class="!sp-p-10 !sp-text-center !sp-font-sans !sp-text-service-navy"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-slate-100 !sp-text-service-muted">' . $ui::icon('document', '!sp-w-5 !sp-h-5') . '</span><p class="!sp-m-0 !sp-mt-3 !sp-text-xs !sp-font-semibold">No hay actas en esta pestaña</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Ajusta los filtros o revisa otra pestaña.</p></div>';
    }
    $labels = ['pending' => 'Sin firmar', 'signed' => 'Firmada', 'archived' => 'Archivada', 'cancelled' => 'Anulada', 'superseded' => 'Versión anterior'];
    $badges = [
      'pending' => '!sp-bg-amber-50 !sp-border-amber-200 !sp-text-amber-800',
      'signed' => '!sp-bg-emerald-50 !sp-border-emerald-200 !sp-text-emerald-700',
      'archived' => '!sp-bg-slate-100 !sp-border-slate-200 !sp-text-slate-600',
      'cancelled' => '!sp-bg-rose-50 !sp-border-rose-200 !sp-text-rose-700',
      'superseded' => '!sp-bg-sky-50 !sp-border-sky-200 !sp-text-sky-700',
    ];
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-bg-slate-50 !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $td = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4 !sp-align-top';
    $muted = '!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted';
    $danger = '!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-rose-700 !sp-border !sp-border-solid !sp-border-rose-200 !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-rose-50 focus-visible:!sp-ring-2 focus-visible:!sp-ring-rose-300 !sp-whitespace-nowrap';
    $html = '<div class="!sp-overflow-x-auto !sp-font-sans"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[1050px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['Acta', 'Caso', 'Inmueble', 'Firmante', 'Cobro', 'Estado', 'Acciones'] as $label) {
      $html .= '<th class="' . $th . '">' . $label . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($items as $act) {
      $payload = (array) ($act['_payload'] ?? []);
      $ticket = (string) ($payload['ticket_number'] ?? $act['ticket_display'] ?? $act['ticket_pk'] ?? '-');
      $property = trim((string) ($payload['property'] ?? $act['ticket_inmueble'] ?? ''));
      $contract = trim((string) ($payload['contract'] ?? $act['ticket_contrato'] ?? ''));
      $address = trim((string) ($act['ticket_address'] ?? ''));
      $signer = (array) ($payload['signer'] ?? []);
      $source = (array) ($payload['source'] ?? []);
      $actorName = trim((string) (((array) ($payload['actor'] ?? []))['name'] ?? ''));
      $quoteId = trim((string) ($source['quote_id'] ?? ''));
      $sourceLabel = ((string) ($source['flow'] ?? '') === 'approved_quote' && $quoteId !== '') ? 'Cotización aprobada #' . $quoteId : 'Solución sin cotización aprobada';
      $status = (string) ($act['status'] ?? '');
      $url = $service->viewUrl((int) $act['id']);
      $createdAt = (int) ($act['created_at'] ?? 0);
      $adminState = trim((string) ($act['ticket_estado_admin'] ?? ''));
      $email = trim((string) ($signer['email'] ?? ''));
      $phone = trim((string) ($signer['phone'] ?? ''));
      $html .= '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . self::e($act['id']) . '</span>'
        . '<small class="' . $muted . '">' . ($createdAt > 0 ? self::e(date('d/m/Y g:i a', $createdAt)) : '-') . '</small>'
        . ($actorName !== '' ? '<small class="' . $muted . '">Por ' . self::e($actorName) . '</small>' : '') . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px]"><strong class="!sp-block">Caso #' . self::e($ticket) . '</strong><small class="' . $muted . '">' . self::e($sourceLabel) . '</small>'
        . ($adminState !== '' ? '<small class="' . $muted . '">' . self::e($adminState) . '</small>' : '') . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[220px]"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-700 !sp-font-semibold">' . self::e($property !== '' ? $property : '-') . '</span>'
        . ($address !== '' ? '<small class="' . $muted . ' !sp-leading-relaxed">' . self::e($address) . '</small>' : '')
        . '<small class="' . $muted . '">Contrato #' . self::e($contract !== '' ? $contract : '-') . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[220px]"><strong class="!sp-block !sp-leading-relaxed">' . self::e($signer['name'] ?? '-') . '</strong>'
        . ($email !== '' ? '<small class="' . $muted . ' !sp-break-all">' . self::e($email) . '</small>' : '')
        . ($phone !== '' ? '<small class="' . $muted . '">' . self::e($phone) . '</small>' : '') . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><strong class="!sp-block">' . ($quoteId !== '' ? 'En la cotización' : 'Sin reporte nuevo') . '</strong>'
        . (!empty($act['legacy_act_id']) ? '<small class="' . $muted . '">CCT acta #' . self::e($act['legacy_act_id']) . '</small>' : '') . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-px-2 !sp-py-1 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[9px] !sp-font-bold ' . ($badges[$status] ?? $badges['archived']) . '"><span class="!sp-w-1.5 !sp-h-1.5 !sp-rounded-full !sp-bg-current"></span>' . self::e($labels[$status] ?? $status) . '</span>'
        . (!empty($act['signed_at']) ? '<small class="' . $muted . '">Firmada ' . self::e(date('d/m/Y g:i a', (int) $act['signed_at'])) . '</small>' : '') . '</td>';
      $actions = '<button type="button" class="' . $ui::NAVY . ' !sp-whitespace-nowrap" data-scm-open-iframe data-iframe-url="' . self::e($url) . '" data-iframe-title="Acta de satisfacción #' . self::e($act['id']) . '" data-scm-compact-iframe>' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Ver</button>';
      if (in_array($status, ['pending', 'signed'], true) && (int) ($act['active_slot'] ?? 1) === 1) {
        $actions .= '<a class="' . $ui::SECONDARY . ' !sp-text-[10px] !sp-whitespace-nowrap !sp-no-underline" href="' . self::e($service->editUrl($act)) . '">' . $ui::icon('edit', '!sp-w-3 !sp-h-3') . 'Editar</a>';
      }
      if (in_array($status, ['signed', 'superseded'], true)) {
        $actions .= '<a class="' . $ui::SECONDARY . ' !sp-text-[10px] !sp-whitespace-nowrap !sp-no-underline" href="' . self::e($url . '&format=pdf' . ($status === 'superseded' ? '&audience=staff' : '')) . '" download title="Descargar PDF firmado">' . $ui::icon('download', '!sp-w-3 !sp-h-3') . 'PDF</a>';
      }
      if ($status === 'pending') {
        $actions .= '<button type="button" class="' . $danger . '" data-acta-archive="' . self::e($act['id']) . '" data-ticket-pk="' . self::e($act['ticket_pk']) . '">' . $ui::icon('document', '!sp-w-3 !sp-h-3') . 'Archivar</button>';
      }
      if (in_array($status, ['archived', 'cancelled'], true) || $canDeleteAny) {
        $actions .= '<button type="button" class="' . $danger . '" data-acta-delete="' . self::e($act['id']) . '">' . $ui::icon('close', '!sp-w-3 !sp-h-3') . 'Eliminar</button>';
      }
      $html .= '<td class="' . $td . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-1.5 !sp-min-w-[170px]">' . $actions . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? count($items));
    $html .= '<div class="!sp-p-4 !sp-bg-slate-50/50 !sp-font-sans">';
    if ($totalPages <= 1) {
      $html .= '<span class="!sp-text-[10px] !sp-text-service-muted">Mostrando ' . self::e((string) $total) . ' acta(s)</span>';
    } else {
      $button = static fn(int $target, bool $disabled, string $label): string => '<button type="button" class="' . $ui::SECONDARY . '" data-pending-page="sacta_" data-page="' . $target . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>';
      $html .= '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3"><span class="!sp-text-[10px] !sp-text-service-muted">Página ' . self::e((string) $page) . ' de ' . self::e((string) $totalPages) . ' · ' . self::e((string) $total) . ' actas</span>'
        . '<div class="!sp-flex !sp-items-center !sp-gap-2">' . $button(max(1, $page - 1), $page <= 1, 'Anterior')
        . '<span class="!sp-bg-service-navy !sp-text-white !sp-text-[10px] !sp-px-3 !sp-py-2 !sp-rounded">' . self::e((string) $page) . '</span>'
        . $button(min($totalPages, $page + 1), $page >= $totalPages, 'Siguiente') . '</div></div>';
    }
    return $html . '</div>';
  }

  public function document(array $act, array $payload, bool $staff, string $form = ''): string
  {
    return CompletionDocument::render($act, $payload, $staff, $form);
  }

  /** @return array{name:string,cargo:string,email:string,phone:string} */
  public static function actor(array $payload): array
  {
    $actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
    return [
      'name' => trim((string) ($actor['name'] ?? '')) ?: 'Funcionario',
      'cargo' => trim((string) ($actor['cargo'] ?? $actor['role'] ?? '')) ?: 'Funcionario',
      'email' => trim((string) ($actor['email'] ?? $actor['correo'] ?? '')),
      'phone' => trim((string) ($actor['phone'] ?? $actor['celular'] ?? '')),
    ];
  }

  public static function actorDetails(array $actor): string
  {
    $parts = array_values(array_filter([
      trim((string) ($actor['cargo'] ?? '')),
      trim((string) ($actor['email'] ?? '')),
      trim((string) ($actor['phone'] ?? '')) !== '' ? 'Celular: ' . trim((string) $actor['phone']) : '',
    ], static fn(string $value): bool => $value !== ''));
    return implode(' · ', $parts);
  }

  private static function logoUrl(): string
  {
    if (function_exists('system_image')) {
      return trim((string) \system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? (string) SCM_DEFAULT_PORTAL_LOGO_URL : ''));
    }
    return defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? (string) SCM_DEFAULT_PORTAL_LOGO_URL : '';
  }

  public function thankYou(array $act, array $payload, string $token): string
  {
    $logoUrl = self::logoUrl();
    ob_start(); ?>
    <main class="scm-acta scm-acta-document scm-acta-thanks">
      <?php if ($logoUrl !== ''): ?><span class="scm-acta-logo"><img src="<?= self::e($logoUrl) ?>" alt="SKC SuCasa Inmobiliaria" loading="eager" decoding="async"></span><?php endif; ?>
      <p class="scm-acta-thanks-kicker">Acta firmada</p>
      <h1>Gracias por tu firma</h1>
      <p>Hemos registrado correctamente la firma del acta del caso #<?= self::e($payload['ticket_number']) ?>. El cierre del caso quedó guardado y puedes descargar la copia firmada cuando la necesites.</p>
      <div class="scm-acta-thanks-actions">
        <a class="scm-acta-button" href="ticket-acta.php?id=<?= (int) $act['id'] ?>&token=<?= self::e(rawurlencode($token)) ?>&format=pdf">Descargar acta firmada</a>
        <a class="scm-acta-button scm-acta-secondary" href="https://sucasainmobiliaria.com.co/" target="_blank" rel="noopener noreferrer">Conocer más productos SuCasa</a>
      </div>
    </main>
    <?php return (string) ob_get_clean();
  }

  public function signingForm(array $act, array $payload, string $csrf, string $error = '', array $submitted = [], array $channels = []): string
  {
    $options = '';
    foreach ($channels as $channel => $label) { $options .= '<option value="' . self::e($channel) . '">' . self::e($label) . '</option>'; }
    return '<section><h2>Revisar y firmar</h2><p class="scm-acta-notice">Firma únicamente si estás conforme con los daños, las soluciones y las observaciones descritas. Si algo no corresponde, no firmes y contacta a la inmobiliaria. Esta firma cerrará el caso.</p>'
      . ($error !== '' ? '<p role="alert" class="scm-acta-notice">' . self::e($error) . '</p>' : '')
      . '<noscript><p class="scm-acta-notice">Activa JavaScript para solicitar el código y registrar la firma.</p></noscript>'
      . '<div class="scm-acta-record"><h3>1. Solicita tu código de verificación</h3><label>Recibir código por<select data-acta-otp-channel>' . $options . '</select></label><button type="button" class="scm-acta-button scm-acta-secondary" data-acta-request-code' . (!$channels ? ' disabled' : '') . '>Solicitar código</button><p role="status" aria-live="polite" data-acta-otp-status></p><p class="scm-acta-help">Vence en 10 minutos. Máximo 5 solicitudes y 5 intentos incorrectos por hora.</p></div>'
      . '<form method="post" data-acta-sign-form>'
      . '<h3>2. Confirma tu nombre y firma</h3>'
      . '<input type="hidden" name="_csrf_token" value="' . self::e($csrf) . '">'
      . '<input type="hidden" name="document_hash" value="' . self::e($act['payload_hash']) . '">'
      . '<input type="hidden" name="consent_version" value="3">'
      . '<label>Nombre completo *<input name="signature_name" required maxlength="160" autocomplete="name" placeholder="' . self::e($payload['signer']['name']) . '" value="' . self::e(is_string($submitted['signature_name'] ?? null) ? $submitted['signature_name'] : (string) $payload['signer']['name']) . '"></label>'
      . '<p class="scm-acta-help">Escribe exactamente: <strong>' . self::e($payload['signer']['name']) . '</strong>. Ese nombre será tu firma electrónica.</p>'
      . '<label>Código de 6 dígitos *<input name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></label>'
      . '<label class="scm-acta-check"><input type="checkbox" name="accepted" value="1" required><span>' . self::e(CompletionPolicy::TYPED_OTP_CONSENT) . '</span></label>'
      . '<button type="submit" class="scm-acta-button">Firmar acta y cerrar caso</button><p role="alert" aria-live="assertive" data-acta-sign-status></p></form></section>';
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
