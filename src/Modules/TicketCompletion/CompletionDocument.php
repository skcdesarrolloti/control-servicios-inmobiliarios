<?php
declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

/** Shared recipient/staff document: this same markup is printed by Chromium. */
final class CompletionDocument
{
  public static function render(array $act, array $payload, bool $staff = false, string $form = ''): string
  {
    $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $signed = $act['status'] === 'signed';
    $signature = $signed ? json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR) : [];
    $actor = CompletionView::actor($payload);
    $logo = function_exists('system_image') ? \system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : '') : (defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : '');
    $status = ['pending'=>'Pendiente de firma', 'signed'=>'Firmada · Caso cerrado', 'archived'=>'Archivada · No válida para firma', 'cancelled'=>'Anulada · No válida para firma'][$act['status']] ?? 'Sin firma';
    $rows = [
      'Fecha' => date('d/m/Y H:i', (int) $payload['created_at']) . ' (Colombia)',
      'Tipo de inmueble' => $payload['property_meta']['tipo_inmueble'] ?? '',
      'Contrato / Inmueble SIMI' => '#' . ($payload['contract'] ?: '-') . ' · Inmueble SIMI: ' . ($payload['property'] ?: '-'),
      'Código inmueble web' => $payload['report']['id_inmueble'] ?? '',
      '# Caso' => $payload['ticket_number'],
      'Dirección' => $payload['address'],
    ];
    $tenant = $payload['contacts']['arrendatario'] ?? (($payload['signer']['role'] ?? '') === 'arrendatario' ? $payload['signer'] : []);
    if (!empty($tenant['name'])) {
      $rows['Arrendatario'] = $tenant['name'];
      $rows['Email del arrendatario'] = $tenant['email'] ?? '';
      $rows['Celular del arrendatario'] = $tenant['phone'] ?? '';
    }
    $rows['Solución realizada por'] = CompletionPolicy::EXECUTORS[$payload['executor']] ?? $payload['executor'];
    ob_start(); ?>
    <article class="scm-acta scm-acta-document scm-acta-receipt">
      <header class="scm-acta-receipt-head">
        <?php if ($logo !== ''): ?><span class="scm-acta-logo"><img src="<?= $e($logo) ?>" alt="SKC SuCasa Inmobiliaria" loading="eager"></span><?php endif; ?>
        <p class="scm-acta-company">SKC SuCasa Inmobiliaria</p>
        <h1>ACTA DE RECIBO A SATISFACCIÓN #<?= $e($act['id']) ?> DEL CONTRATO #<?= $e($payload['contract'] ?: '-') ?></h1>
        <p class="scm-acta-receipt-state"><?= $e($status) ?></p>
      </header>
      <p class="scm-acta-receipt-date">Fecha de elaboración: <strong><?= $e(date('d/m/Y H:i', (int) $payload['created_at'])) ?> (Colombia)</strong></p>
      <section class="scm-acta-receipt-section"><h2>1). DATOS DEL ACTA DE RECIBO A SATISFACCIÓN</h2>
        <table class="scm-acta-receipt-data"><tbody><?php foreach ($rows as $label=>$value): ?><tr><th scope="row"><?= $e($label) ?></th><td><?= $e(trim((string) $value) !== '' ? $value : 'Sin registrar') ?></td></tr><?php endforeach; ?></tbody></table>
      </section>
      <section class="scm-acta-receipt-section"><h2>2). EVIDENCIAS</h2>
        <?php if (!empty($payload['source']['quote_id'])): ?><p class="scm-acta-reference-note"><strong>Cotización de mantenimiento:</strong> #<?= $e($payload['source']['quote_id']) ?></p><?php endif; ?>
        <div class="scm-acta-service-list"><?php foreach ($payload['items'] as $index=>$item): ?>
          <article class="scm-acta-receipt-item"><h3>2.<?= $index + 1 ?>) Daño encontrado y solución realizada</h3>
            <dl><div><dt>Daño encontrado</dt><dd><?= nl2br($e($item['damage'])) ?></dd></div><div><dt>Solución realizada</dt><dd><?= nl2br($e($item['solution'])) ?></dd></div></dl>
            <?php foreach (['damage_photos'=>'Evidencias del daño','photos'=>'Evidencias del trabajo realizado'] as $key=>$label): if (empty($item[$key])) continue; ?>
              <div class="scm-acta-evidence"><h4><?= $e($label) ?></h4><div class="scm-acta-photo-grid">
                <?php foreach ($item[$key] as $photoIndex=>$photo): $url = $photo['pdf_data_uri'] ?? \SCM\Support\StoredFileService::fromRuntime()->urlFor((string) $photo['name']); $caption = $label . ' · Foto ' . ($photoIndex + 1); ?>
                  <figure><button type="button" class="scm-acta-photo-thumb" data-acta-gallery-item data-full-src="<?= $e($url) ?>" data-caption="<?= $e($caption) ?>"><img src="<?= $e($url) ?>" alt="<?= $e($caption) ?>" loading="eager"></button><figcaption><?= $e($caption) ?></figcaption></figure>
                <?php endforeach; ?>
              </div></div>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?></div>
      </section>
      <section class="scm-acta-receipt-section"><h2>3). OBSERVACIONES ADICIONALES</h2><div class="scm-acta-receipt-observations"><?= nl2br($e(trim($payload['observations']) !== '' ? $payload['observations'] : 'Sin observaciones adicionales.')) ?></div></section>
      <?php if (!$signed): ?><p class="scm-acta-receipt-signing-note">Firma solicitada a <strong><?= $e($payload['signer']['name']) ?></strong>, <?= $e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?>. La aceptación y el código de verificación son necesarios para registrar la firma.</p><?php endif; ?>
      <?= $form ?>
      <?php if ($signed): ?>
        <section class="scm-acta-signature-certificate">
          <div class="scm-acta-certificate-head"><h2>Firmas<br>Electrónicas</h2><div><strong>DOCUMENTO FINALIZADO</strong><p><?= $e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?> (Colombia)</p><p>ID: ACTA-<?= $e($act['id']) ?></p></div></div>
          <div class="scm-acta-certificate-row">
            <div class="scm-acta-signature-mark"><?= !empty($signature['strokes']) ? CompletionView::signatureSvg($signature['strokes']) : '<p class="scm-acta-typed-signature">' . $e($signature['name']) . '</p>' ?><small><?= !empty($signature['strokes']) ? 'Trazo de la firma registrada' : (!empty($signature['verification']) ? 'Representación de la firma electrónica por nombre y código' : 'Representación digital del nombre registrado') ?></small></div>
            <div class="scm-acta-signature-contact"><strong><?= $e($signature['name']) ?></strong><p>QUIEN RECIBE A SATISFACCIÓN EL TRABAJO REALIZADO</p><p><?= $e(CompletionPolicy::ROLES[$payload['signer']['role']]) ?></p><?php if (!empty($signature['document'])): ?><p>Documento: <?= $e($signature['document']) ?></p><?php endif; ?><?php if ($payload['signer']['email'] !== ''): ?><p>Email: <?= $e($payload['signer']['email']) ?></p><?php endif; ?><?php if ($payload['signer']['phone'] !== ''): ?><p>Móvil: <?= $e($payload['signer']['phone']) ?></p><?php endif; ?><p><?= $e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?></p></div>
          </div>
          <div class="scm-acta-certificate-consent"><?= $e($signature['consent_text']) ?></div>
          <h3 class="scm-acta-audit-title">PISTA DE AUDITORÍA</h3><dl class="scm-acta-audit">
            <div><dt><?= $e(date('d/m/Y H:i:s', (int) $payload['created_at'])) ?></dt><dd>Acta elaborada por <?= $e($actor['name']) ?>.</dd></div>
            <?php if (!empty($payload['updated_at'])): ?><div><dt><?= $e(date('d/m/Y H:i:s', (int) $payload['updated_at'])) ?></dt><dd>Contenido actualizado antes de la firma.</dd></div><?php endif; ?>
            <?php if (!empty($signature['verification']['requested_at'])): ?><div><dt><?= $e(date('d/m/Y H:i:s', (int) $signature['verification']['requested_at'])) ?></dt><dd>Código solicitado por <?= $e(($signature['verification']['channel'] ?? '') === 'email' ? 'correo' : 'WhatsApp') ?>.</dd></div><?php endif; ?>
            <?php if (!empty($signature['verification']['verified_at'])): ?><div><dt><?= $e(date('d/m/Y H:i:s', (int) $signature['verification']['verified_at'])) ?></dt><dd>Código validado y aceptación expresa registrada.</dd></div><?php endif; ?>
            <div><dt><?= $e(date('d/m/Y H:i:s', (int) $act['signed_at'])) ?></dt><dd>Firmado por <?= $e($signature['name']) ?><?= !empty($signature['ip']) ? ' (IP: ' . $e($signature['ip']) . ')' : '' ?>. Documento finalizado y caso cerrado.</dd></div>
          </dl>
        </section>
      <?php endif; ?>
      <footer class="scm-acta-receipt-footer"><p><strong>Elaborada por: <?= $e($actor['name']) ?></strong><br><?= $e(CompletionView::actorDetails($actor)) ?></p><p>SKC SuCasa Inmobiliaria · NIT 900623242-4</p><p class="scm-acta-fingerprint">Identificador de contenido (SHA-256): <?= $e($act['payload_hash']) ?></p></footer>
    </article>
    <?php return (string) ob_get_clean();
  }
}
