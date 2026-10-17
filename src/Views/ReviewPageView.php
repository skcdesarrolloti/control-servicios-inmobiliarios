<?php

declare(strict_types=1);

namespace SCM\Views;

/**
 * Página propia de revisión preventiva o correctiva (public/revision.php).
 * Diseño simple para personas poco familiarizadas con el panel: qué caso es, qué hacer y el formulario.
 */
final class ReviewPageView
{
  /**
   * @param array{kind:string,label:string,case:string,contract:string,property:string,address:string,user:string,base_url:string,logo:string,favicon:string,runtime:array<string,mixed>,ticket_pk:int,draft_user:string,assets:array{css:list<string>,js:list<string>,drafts:string}} $data
   */
  public function render(array $data): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isPreventive = $data['kind'] === 'preventiva';
    $firstName = trim((string) strtok(trim($data['user']), ' ')) ?: $data['user'];
    $steps = $isPreventive
      ? [
        ['checklist', 'Revisa el inmueble', 'Recorre cada espacio y marca cómo está.'],
        ['photo_camera', 'Registra los daños', 'Si encuentras algo, descríbelo y agrega fotos.'],
        ['save', 'Guarda la revisión', 'Al final pulsa el botón de guardar.'],
      ]
      : [
        ['search', 'Diagnostica el daño', 'Describe qué pasó y dónde está.'],
        ['photo_camera', 'Agrega evidencias', 'Toma fotos claras del daño.'],
        ['save', 'Guarda la revisión', 'Al final pulsa el botón de guardar.'],
      ];
    // El formulario ya muestra contrato, inmueble y partes; aquí solo lo necesario para ubicarse.
    $details = array_filter([
      'Caso' => '#' . $data['case'],
      'Dirección' => $data['address'] !== '' ? $data['address'] : ($data['property'] !== '' ? 'Inmueble ' . $data['property'] : ''),
    ], static fn($value): bool => trim((string) $value) !== '' && $value !== '#');

    ob_start();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($data['label']) ?> · Caso #<?= $h($data['case']) ?> · SKC SuCasa Inmobiliaria</title>
  <?php if ($data['favicon'] !== ''): ?><link rel="icon" href="<?= $h($data['favicon']) ?>" sizes="32x32"><?php endif; ?>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <?php foreach ($data['assets']['css'] as $href): ?><link rel="stylesheet" href="<?= $h($href) ?>">
  <?php endforeach; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js" defer></script>
  <script src="<?= $h($data['assets']['drafts']) ?>" data-scm-draft-user="<?= $h($data['draft_user']) ?>" defer></script>
  <?php foreach ($data['assets']['js'] as $src): ?><script src="<?= $h($src) ?>" defer></script>
  <?php endforeach; ?>
</head>
<body class="scm-review-page is-<?= $h($data['kind']) ?>">
  <header class="rp-topbar">
    <a class="rp-brand" href="<?= $h($data['base_url'] . '/index.php') ?>">
      <?php if ($data['logo'] !== ''): ?><img src="<?= $h($data['logo']) ?>" alt="SKC SuCasa Inmobiliaria"><?php endif; ?>
      <span>SKC SuCasa Inmobiliaria</span>
    </a>
    <div class="rp-user">
      <span class="rp-avatar" aria-hidden="true"><?= $h(mb_strtoupper(mb_substr($firstName, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
      <span class="rp-user-name">Hola, <strong><?= $h($firstName) ?></strong></span>
      <a class="rp-link-button" href="<?= $h($data['base_url'] . '/index.php') ?>"><span class="material-symbols-outlined" aria-hidden="true">dashboard</span><span>Ir al panel</span></a>
    </div>
  </header>

  <main class="scm-review-page-shell">
    <section class="rp-hero">
      <span class="rp-hero-icon material-symbols-outlined" aria-hidden="true"><?= $isPreventive ? 'home_repair_service' : 'build' ?></span>
      <div class="rp-hero-text">
        <p class="rp-eyebrow"><?= $isPreventive ? 'Revisión del estado del inmueble' : 'Revisión de un daño reportado' ?></p>
        <h1><?= $h($data['label']) ?></h1>
        <dl class="rp-facts">
          <?php foreach ($details as $term => $value): ?>
            <div><dt><?= $h($term) ?></dt><dd><?= $h($value) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
    </section>

    <ol class="rp-steps" aria-label="Pasos">
      <?php foreach ($steps as $index => [$icon, $title, $text]): ?>
        <li><span class="rp-step-number"><?= $index + 1 ?></span><span class="material-symbols-outlined" aria-hidden="true"><?= $h($icon) ?></span><div><strong><?= $h($title) ?></strong><small><?= $h($text) ?></small></div></li>
      <?php endforeach; ?>
    </ol>

    <section class="rp-card" aria-label="<?= $h($data['label']) ?>">
      <div id="scm-app" class="scm-wrap" data-scm-runtime="<?= $h(json_encode($data['runtime'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
        <div id="scm-case-modal" class="scm-case-modal open scm-review-page-modal" data-id-inmueble-web="<?= $h($data['property']) ?>">
          <div class="scm-case-dialog"></div>
        </div>
        <button type="button" class="scm-btn-case" hidden data-scm-review-case data-review-kind="<?= $h($data['kind']) ?>" data-ticket-pk="<?= (int) $data['ticket_pk'] ?>" data-id-inmueble-web="<?= $h($data['property']) ?>"></button>
        <p class="scm-review-page-status" data-scm-review-status role="status"><span class="rp-spinner" aria-hidden="true"></span>Cargando la <?= $h(mb_strtolower($data['label'], 'UTF-8')) ?>…</p>
      </div>
    </section>

    <p class="rp-footnote"><span class="material-symbols-outlined" aria-hidden="true">info</span>Si cierras esta página antes de guardar, lo que escribiste queda como borrador en este equipo.</p>
  </main>
</body>
</html>
<?php
    return (string) ob_get_clean();
  }
}
