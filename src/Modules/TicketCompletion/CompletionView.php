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
    $transportMax = (int) ($context['transport_max'] ?? 0);
    $active = false;
    foreach ($context['acts'] as $act) {
      $active = $active || in_array($act['status'], ['pending', 'signed'], true);
    }
    $executor = str_replace('En ejecucion por ', '', (string) ($ticket['estado_administrativo'] ?? ''));
    ob_start(); ?>
    <section class="scm-acta">
      <p class="scm-acta-notice">Documenta la solución, elige el firmante y revisa el valor administrativo. El ticket conservará el <strong>estado de ejecución seleccionado</strong> mientras el acta queda pendiente. Puedes hacer seguimiento desde <strong>Actividades administrativas → Actas de satisfacción</strong>. Solo la firma registrará el cierre y el reporte de cobro.</p>
      <div class="scm-acta-meta"><span>Ticket <strong>#<?= self::e($ticket['id_ticket'] ?: $ticket['_ID']) ?></strong></span><span>Inmueble <strong><?= self::e($ticket['inmueble'] ?? '—') ?></strong></span></div>
      <?php foreach ($context['acts'] as $act): $payload = $service->payload($act); ?>
        <article class="scm-acta-record">
          <div class="scm-acta-meta"><h3><?= $act['status'] === 'pending' ? 'Acta enviada a bandeja' : 'Registro interno #' . self::e($act['id']) ?></h3><strong class="scm-acta-status"><?= self::e(['pending' => 'Acta sin firmar', 'signed' => 'Firmada', 'archived' => 'Archivada', 'cancelled' => 'Anulada'][$act['status']] ?? $act['status']) ?></strong></div>
          <?php if ($act['status'] === 'pending'): ?><p class="scm-acta-help">También puedes consultarla y administrarla desde <strong>Actividades administrativas → Actas de satisfacción</strong>.</p><?php endif; ?>
          <p>Firmante: <strong><?= self::e($payload['signer']['name']) ?></strong> · <?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?><br><?= self::e($payload['signer']['email']) ?> · <?= self::e($payload['signer']['phone']) ?></p>
          <p>Reporte administrativo: <strong><?= self::money((int) $payload['report']['total']) ?></strong> <?= !empty($act['report_id']) ? '· Registrado #' . self::e($act['report_id']) : '· Se registrará al firmar' ?></p>
          <?php if (in_array($act['status'], ['archived', 'cancelled'], true)): ?><p>Motivo: <?= self::e($act['cancellation_reason']) ?></p><?php endif; ?>
          <div class="scm-acta-actions"><a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id'])) ?>" target="_blank" rel="noopener" data-acta-preview>Ver acta</a>
          <a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id']) . '&format=pdf') ?>" target="_blank" rel="noopener">PDF destinatario</a>
          <a class="scm-acta-button scm-acta-secondary" href="<?= self::e($service->viewUrl((int) $act['id']) . '&format=pdf&audience=staff') ?>" target="_blank" rel="noopener">PDF interno</a>
          <?php if (in_array($act['status'], ['pending', 'signed'], true)): ?>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-resend="<?= self::e($act['id']) ?>"><?= $act['status'] === 'signed' ? 'Reenviar copia firmada' : 'Reenviar invitación' ?></button>
          <?php endif; ?></div>
          <?php $delivery = json_decode((string) ($act['delivery_json'] ?? ''), true) ?: []; $event = $act['status'] === 'signed' ? 'signed_receipt' : 'signature_invitation'; ?>
          <?php if (!in_array($act['status'], ['archived', 'cancelled'], true)): ?><ul class="scm-acta-help"><?php foreach ($payload['channels'] ?? ['email'] as $channel): ?><li><?= $channel === 'email' ? 'Correo' : 'WhatsApp' ?>: <?= !empty($delivery[$event][$channel]['queued']) ? 'en cola; no confirma entrega' : 'sin confirmación de encolado; reintenta el envío' ?></li><?php endforeach; ?></ul><?php endif; ?>
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
          <p class="scm-acta-help">Solo se usan los contactos registrados. Para empresas o copropiedades, indica el nombre de quien firma en su representación. El destinatario confirma su nombre y verifica un código antes del cierre.</p>
          <?php if (CompletionDelivery::otpTemplate() === ''): ?><p class="scm-acta-notice">El enlace puede enviarse por WhatsApp, pero el código se enviará por correo hasta configurar la plantilla de autenticación de WhatsApp. El firmante necesita correo válido.</p><?php endif; ?>
          <h3>2. Daños encontrados y soluciones realizadas</h3>
          <div data-acta-items><?= $this->item(0) ?></div>
          <button type="button" class="scm-acta-button scm-acta-secondary" data-acta-add-item>Agregar otro daño y solución</button>
          <label>Observaciones finales *<textarea name="observations" required maxlength="6000" rows="3" placeholder="Describe verificaciones, alcance de la solución y observaciones para el firmante."></textarea></label>
          <h3>3. Reporte administrativo de cobro</h3>
          <p class="scm-acta-help">Valores fijos tomados de la configuración de mantenimiento.</p>
          <div class="scm-acta-grid">
            <label>Servicio administrativo (COP) *<input type="number" name="service_fee" min="1" max="999999999" step="1" required value="<?= self::e($context['fee'] ?? '') ?>" <?= $context['fee'] !== null ? 'readonly' : '' ?> data-acta-fee></label>
            <label>Transporte (COP) *<input type="hidden" name="transport" value="<?= self::e($transportMax) ?>" data-acta-transport><span class="scm-acta-readonly-value" aria-live="polite"><?= self::money($transportMax) ?></span></label>
          </div>
          <?php if ($context['fee'] === null): ?><p class="scm-acta-notice">Falta una configuración válida. Ingresa expresamente el valor administrativo; no se generarán cobros con una tarifa vacía.</p><?php endif; ?>
          <p class="scm-acta-total">Total del reporte: <output data-acta-total><?= self::money((int) ($context['fee'] ?? 0) + $transportMax) ?></output></p>
          <label class="scm-acta-check"><input type="checkbox" name="confirm" value="1" required><span>Revisé los daños, las soluciones, el firmante y el valor. Entiendo que al firmar se cerrará el caso y se registrará un único reporte como no pagado y no exportado.</span></label>
          <button type="submit" class="scm-acta-button">Generar acta y solicitar firma</button>
        </form>
      <?php elseif (!$active): ?><p>El ticket está cerrado. No se pueden generar nuevas actas.</p><?php endif; ?>
      <div data-acta-message role="status" aria-live="polite"></div>
    </section>
    <?php return (string) ob_get_clean();
  }

  public function item(int $index): string
  {
    $helpId = 'acta-photo-help-' . $index;
    return '<fieldset class="scm-acta-item" data-acta-item><legend>Daño y solución</legend><div class="scm-acta-grid"><label>Daño encontrado *<textarea name="items[' . $index . '][damage]" required maxlength="3000" rows="3"></textarea></label><label>Solución realizada *<textarea name="items[' . $index . '][solution]" required maxlength="3000" rows="3"></textarea></label></div><div class="scm-acta-photo-field"><label>Fotos de evidencia (opcional)<input type="file" name="acta_item_photos_' . $index . '[]" accept="image/jpeg,image/png,image/webp" multiple data-acta-photos aria-describedby="' . $helpId . '"></label><button type="button" class="scm-acta-photo-paste" data-acta-photo-paste><strong>Pegar captura</strong><span>Haz clic aquí y presiona Ctrl+V</span></button><small id="' . $helpId . '" data-acta-photo-help>Máximo 4 fotos por daño y 12 en toda el acta. JPG, PNG o WebP; hasta 25 MB por archivo. Se comprimen automáticamente.</small></div><div class="scm-acta-photo-preview" data-acta-photo-preview aria-live="polite" aria-label="Fotos seleccionadas"></div><button type="button" class="scm-acta-remove" data-acta-remove-item>Quitar este detalle</button></fieldset>';
  }

  /** @param array<string,string|int> $filters @param array<int,array<string,mixed>> $items @param array<string,int> $stats @param array<string,int> $pagination */
  public function dashboardPanel(array $filters, array $items, array $stats, int $count, array $pagination, CompletionService $service, bool $canDeleteActive = false): string
  {
    $status = (string) ($filters['estado'] ?? 'pending');
    ob_start(); ?>
    <div class="scm-pending-wrap scm-actas-dashboard" id="sacta_panel">
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Actas de satisfacción</h2>
          <p>Consulta actas sin firmar, firmadas, archivadas y anuladas. Las pendientes quedan aquí hasta que el destinatario firme.</p>
        </div>
        <div>
          <div class="scm-pending-count" id="sacta-kpi-count"><?= self::e((string) $count) ?></div>
          <div class="scm-pending-count-label"><?= $status === 'pending' ? 'actas sin firmar' : 'actas filtradas' ?></div>
        </div>
      </div>
      <div id="sacta_kpis"><?= $this->dashboardKpis($stats) ?></div>
      <div class="scm-filter-card">
        <h3>Filtros</h3>
        <form method="post" autocomplete="off" id="sacta_form">
          <div class="scm-grid scm-actas-filter-grid">
            <div class="scm-field"><label for="sacta_estado">Estado</label><select id="sacta_estado" name="sacta_estado"><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Actas sin firmar</option><option value="signed" <?= $status === 'signed' ? 'selected' : '' ?>>Firmadas</option><option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archivadas</option><option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Anuladas</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todas</option></select></div>
            <div class="scm-field"><label for="sacta_caso"># caso</label><input id="sacta_caso" name="sacta_caso" type="text" value="<?= self::e($filters['caso'] ?? '') ?>" placeholder="Ticket"></div>
            <div class="scm-field"><label for="sacta_inmueble">Inmueble</label><input id="sacta_inmueble" name="sacta_inmueble" type="text" value="<?= self::e($filters['inmueble'] ?? '') ?>" placeholder="# inmueble"></div>
            <div class="scm-field"><label for="sacta_contrato">Contrato</label><input id="sacta_contrato" name="sacta_contrato" type="text" value="<?= self::e($filters['contrato'] ?? '') ?>" placeholder="# contrato"></div>
            <div class="scm-field"><label for="sacta_firmante">Firmante</label><input id="sacta_firmante" name="sacta_firmante" type="text" value="<?= self::e($filters['firmante'] ?? '') ?>" placeholder="Nombre, correo o celular"></div>
            <div class="scm-field"><label for="sacta_per_page">Por página</label><select id="sacta_per_page" name="sacta_per_page"><?php foreach ([30, 50, 100] as $size): ?><option value="<?= $size ?>" <?= (int) ($filters['per_page'] ?? 30) === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
          </div>
          <input type="hidden" name="sacta_page" value="<?= (int) ($pagination['page'] ?? 1) ?>">
          <div class="scm-actions">
            <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
            <button class="scm-btn-secondary" type="button" data-pending-clear="sacta_">Limpiar</button>
            <span class="scm-spinner" id="sacta_spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
          </div>
        </form>
      </div>
      <div id="sacta_table"><?= $this->dashboardTable($items, $service, $pagination, $canDeleteActive) ?></div>
    </div>
    <?php return (string) ob_get_clean();
  }

  /** @param array<string,int> $stats */
  public function dashboardKpis(array $stats): string
  {
    return '<div class="scm-actas-kpis"><div><span>Sin firmar</span><strong>' . self::e((string) ($stats['pending'] ?? 0)) . '</strong></div><div><span>Firmadas</span><strong>' . self::e((string) ($stats['signed'] ?? 0)) . '</strong></div><div><span>Archivadas</span><strong>' . self::e((string) ($stats['archived'] ?? 0)) . '</strong></div><div><span>Anuladas</span><strong>' . self::e((string) ($stats['cancelled'] ?? 0)) . '</strong></div><div><span>Total</span><strong>' . self::e((string) ($stats['all'] ?? 0)) . '</strong></div></div>';
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,int> $pagination */
  public function dashboardTable(array $items, CompletionService $service, array $pagination = [], bool $canDeleteActive = false): string
  {
    if (!$items) {
      return '<div class="scm-table-wrap"><p class="scm-actas-empty">No hay actas para los filtros actuales.</p></div>';
    }
    $labels = ['pending' => 'Acta sin firmar', 'signed' => 'Firmada', 'archived' => 'Archivada', 'cancelled' => 'Anulada'];
    $html = '<div class="scm-table-wrap"><table class="scm-table scm-table-prev scm-actas-table"><thead><tr><th>Acta</th><th>Estado</th><th>Caso</th><th>Inmueble</th><th>Firmante</th><th>Reporte</th><th>Fechas</th><th>Acciones</th></tr></thead><tbody>';
    foreach ($items as $act) {
      $payload = (array) ($act['_payload'] ?? []);
      $ticket = (string) ($payload['ticket_number'] ?? $act['ticket_display'] ?? $act['ticket_pk'] ?? '-');
      $property = (string) ($payload['property'] ?? $act['ticket_inmueble'] ?? '-');
      $contract = (string) ($payload['contract'] ?? $act['ticket_contrato'] ?? '-');
      $signer = (array) ($payload['signer'] ?? []);
      $report = (array) ($payload['report'] ?? []);
      $status = (string) ($act['status'] ?? '');
      $url = $service->viewUrl((int) $act['id']);
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">#' . self::e($act['id']) . '</span></td>';
      $html .= '<td><span class="scm-acta-dashboard-status scm-acta-dashboard-status--' . self::e($status) . '">' . self::e($labels[$status] ?? $status) . '</span></td>';
      $html .= '<td><strong>#' . self::e($ticket) . '</strong><br><small>' . self::e((string) ($act['ticket_estado_admin'] ?? '')) . '</small></td>';
      $html .= '<td><span class="scm-inmueble-badge">' . self::e($property ?: '-') . '</span><br><small>Contrato ' . self::e($contract ?: '-') . '</small></td>';
      $html .= '<td><strong>' . self::e($signer['name'] ?? '-') . '</strong><br><small>' . self::e($signer['email'] ?? '') . ($signer['phone'] ?? '' ? ' · ' . self::e($signer['phone']) : '') . '</small></td>';
      $html .= '<td>' . self::money((int) ($report['total'] ?? 0)) . '<br><small>' . (!empty($act['report_id']) ? 'Cobro #' . self::e($act['report_id']) : 'Se genera al firmar') . '</small></td>';
      $html .= '<td class="scm-date-cell">Creada ' . self::e(date('d/m/Y H:i', (int) ($act['created_at'] ?? 0))) . (!empty($act['signed_at']) ? '<br>Firmada ' . self::e(date('d/m/Y H:i', (int) $act['signed_at'])) : '') . '</td>';
      $canDelete = in_array($status, ['archived', 'cancelled'], true) || ($status === 'pending' && $canDeleteActive);
      $html .= '<td class="scm-pending-action-cell"><button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" data-scm-open-iframe data-iframe-url="' . self::e($url) . '" data-iframe-title="Acta de satisfacción #' . self::e($act['id']) . '" data-scm-compact-iframe>Ver acta</button><a class="scm-pending-action-btn" href="' . self::e($url . '&format=pdf') . '" target="_blank" rel="noopener">PDF destinatario</a><a class="scm-pending-action-btn" href="' . self::e($url . '&format=pdf&audience=staff') . '" target="_blank" rel="noopener">PDF interno</a>' . ($status === 'pending' ? '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--danger" data-acta-archive="' . self::e($act['id']) . '" data-ticket-pk="' . self::e($act['ticket_pk']) . '">Archivar</button>' : '') . ($canDelete ? '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--danger" data-acta-delete="' . self::e($act['id']) . '">Eliminar</button>' : '') . '</td>';
      $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    if (($pagination['total_pages'] ?? 1) > 1) {
      $page = (int) ($pagination['page'] ?? 1);
      $totalPages = (int) ($pagination['total_pages'] ?? 1);
      $html .= '<div class="scm-actas-pagination"><span>Página ' . self::e((string) $page) . ' de ' . self::e((string) $totalPages) . ' · ' . self::e((string) $pagination['total']) . ' registros</span><div>';
      if ($page > 1) {
        $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" data-pending-page="sacta_" data-page="' . ($page - 1) . '">Anterior</button>';
      }
      if ($page < $totalPages) {
        $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" data-pending-page="sacta_" data-page="' . ($page + 1) . '">Siguiente</button>';
      }
      $html .= '</div></div>';
    }
    return $html;
  }

  public function document(array $act, array $payload, bool $staff, string $form = ''): string
  {
    $signed = $act['status'] === 'signed';
    $signature = $signed ? json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR) : [];
    $actor = self::actor($payload);
    $logoUrl = self::logoUrl();
    ob_start(); ?>
    <article class="scm-acta scm-acta-document">
      <header class="scm-acta-document-head"><div class="scm-acta-brand"><?php if ($logoUrl !== ''): ?><span class="scm-acta-logo"><img src="<?= self::e($logoUrl) ?>" alt="SKC SuCasa Inmobiliaria" loading="eager" decoding="async"></span><?php endif; ?><div><p class="scm-acta-eyebrow">SKC SuCasa Inmobiliaria · NIT 900623242-4</p><h1>Acta de satisfacción del caso #<?= self::e($payload['ticket_number']) ?></h1></div></div><p>Registro interno #<?= self::e($act['id']) ?> · Solución de daños</p><strong class="scm-acta-status"><?= $signed ? 'Firmada · Caso cerrado' : ($act['status'] === 'archived' ? 'Archivada · No válida para firma' : ($act['status'] === 'cancelled' ? 'Anulada · No válida para firma' : 'Acta sin firmar · Caso abierto')) ?></strong></header>
      <section><h2>Datos del servicio</h2><dl class="scm-acta-grid"><div><dt>Inmueble / contrato</dt><dd><?= self::e($payload['property']) ?> / <?= self::e($payload['contract']) ?></dd></div><div><dt>Dirección</dt><dd><?= self::e($payload['address']) ?></dd></div><div><dt>Solución realizada por</dt><dd><?= self::e(CompletionPolicy::EXECUTORS[$payload['executor']]) ?></dd></div><div><dt>Fecha del acta</dt><dd><?= self::e(date('d/m/Y H:i', (int) $payload['created_at'])) ?></dd></div></dl></section>
      <section><h2>Daños encontrados y soluciones realizadas</h2><div class="scm-acta-service-list"><?php foreach ($payload['items'] as $index => $item): ?><article class="scm-acta-service-item"><h3>Daño y solución #<?= $index + 1 ?></h3><dl class="scm-acta-service-detail"><div><dt>Daño encontrado</dt><dd><?= nl2br(self::e($item['damage'])) ?></dd></div><div><dt>Solución realizada</dt><dd><?= nl2br(self::e($item['solution'])) ?></dd></div></dl><?php if (!empty($item['photos'])): ?><div class="scm-acta-evidence"><h4>Evidencias del daño #<?= $index + 1 ?></h4><div class="scm-acta-photo-grid"><?php foreach ($item['photos'] as $photoIndex => $photo): $photoUrl = \SCM\Support\StoredFileService::fromRuntime()->urlFor((string) $photo['name']); $caption = 'Foto ' . ($photoIndex + 1) . ' del daño #' . ($index + 1); ?><figure><button type="button" class="scm-acta-photo-thumb" data-acta-gallery-item data-full-src="<?= self::e($photoUrl) ?>" data-caption="<?= self::e($caption) ?>"><img src="<?= self::e($photoUrl) ?>" alt="Evidencia <?= $photoIndex + 1 ?> del daño <?= $index + 1 ?>" width="<?= (int) $photo['width'] ?>" height="<?= (int) $photo['height'] ?>" loading="lazy"></button><figcaption><?= self::e($caption) ?> · clic para ampliar</figcaption></figure><?php endforeach; ?></div></div><?php endif; ?></article><?php endforeach; ?></div></section>
      <section><h2>Observaciones</h2><p class="scm-acta-long-text"><?= nl2br(self::e($payload['observations'])) ?></p></section>
      <section><h2>Firmante seleccionado</h2><p><strong><?= self::e($payload['signer']['name']) ?></strong><br><?= self::e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?> · <?= self::e($payload['signer']['email']) ?></p></section>
      <?php if ($signed): ?><section class="scm-acta-signature"><h2>Firma electrónica registrada</h2><?= !empty($signature['strokes']) ? self::signatureSvg($signature['strokes']) : '' ?><p class="scm-acta-signature-name"><?= self::e($signature['name']) ?></p><p><?= !empty($signature['document']) ? 'Documento: ' . self::e($signature['document']) . '<br>' : '' ?>Fecha: <?= self::e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?> (Colombia)</p><p><?= self::e($signature['consent_text']) ?></p></section><?php endif; ?>
      <?= $form ?>
      <footer class="scm-acta-prepared"><p class="scm-acta-eyebrow">Elaborada por</p><p class="scm-acta-prepared-signature"><?= self::e($actor['name']) ?></p><p class="scm-acta-prepared-details"><?= self::e(self::actorDetails($actor)) ?></p><p class="scm-acta-fingerprint">Identificador de contenido (SHA-256): <?= self::e($act['payload_hash']) ?></p></footer>
    </article>
    <?php return (string) ob_get_clean();
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
      . '<label>Nombre completo *<input name="signature_name" required maxlength="160" autocomplete="name" placeholder="' . self::e($payload['signer']['name']) . '" value="' . self::e(is_string($submitted['signature_name'] ?? null) ? $submitted['signature_name'] : '') . '"></label>'
      . '<p class="scm-acta-help">Escribe exactamente: <strong>' . self::e($payload['signer']['name']) . '</strong>. Ese nombre será tu firma electrónica.</p>'
      . '<label>Código de 6 dígitos *<input name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></label>'
      . '<label class="scm-acta-check"><input type="checkbox" name="accepted" value="1" required><span>' . self::e(CompletionPolicy::TYPED_OTP_CONSENT) . '</span></label>'
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
