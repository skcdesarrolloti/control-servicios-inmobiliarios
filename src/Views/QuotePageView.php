<?php

declare(strict_types=1);

namespace SCM\Views;

/**
 * Página propia para crear, editar o enviar una cotización y crear su orden (public/cotizacion.php).
 *
 * Los formularios de cotización viven en el runtime del panel, así que el panel se carga oculto como
 * motor y el formulario se abre solo. La persona solo ve qué va a hacer, el formulario y, al cerrarlo,
 * qué puede hacer después.
 */
final class QuotePageView
{
  /** acción => [título, ícono, frase, pasos, parámetro del registro] */
  public const ACTIONS = [
    'crear' => ['Crear cotización', 'request_quote', 'Nueva cotización de mantenimiento', [
      ['edit_note', 'Describe el trabajo', 'Qué se va a reparar y por qué.'],
      ['payments', 'Agrega los valores', 'Mano de obra, materiales y otros costos.'],
      ['save', 'Guarda la cotización', 'Pulsa "Crear cotización" al final.'],
    ], 'ticket_pk', 'crear_cotizacion'],
    'editar' => ['Editar cotización', 'edit_document', 'Corregir una cotización existente', [
      ['search', 'Revisa los datos', 'Verifica cada valor y descripción.'],
      ['edit', 'Haz los cambios', 'Ajusta lo que necesites.'],
      ['save', 'Guarda los cambios', 'Pulsa "Guardar cambios" al final.'],
    ], 'id_cotizacion', 'editar_cotizacion'],
    'enviar' => ['Enviar cotización', 'send', 'Enviar la cotización al cliente', [
      ['group', 'Elige a quién', 'Propietario, arrendatario u otro destinatario.'],
      ['mail', 'Revisa el mensaje', 'Correo y WhatsApp con la cotización.'],
      ['send', 'Envía', 'Pulsa el botón de enviar.'],
    ], 'id_cotizacion', 'enviar_cotizacion'],
    'orden' => ['Crear orden de mantenimiento', 'engineering', 'Orden de trabajo de una cotización aprobada', [
      ['person', 'Elige el responsable', 'Quién va a hacer el trabajo.'],
      ['payments', 'Confirma los valores', 'Lo que se paga en esta orden.'],
      ['save', 'Crea la orden', 'Pulsa el botón de guardar al final.'],
    ], 'id_cotizacion', 'crear_orden'],
  ];

  /**
   * @param array{action:string,record_id:int,case:string,quote:string,address:string,user:string,base_url:string,logo:string,favicon:string,panel_html:string,css:list<string>,js:list<string>} $data
   */
  public function render(array $data): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$label, $icon, $eyebrow, $steps, $param, $bridgeAction] = self::ACTIONS[$data['action']];
    $firstName = trim((string) strtok(trim($data['user']), ' ')) ?: $data['user'];
    $details = array_filter([
      'Caso' => $data['case'] !== '' ? '#' . $data['case'] : '',
      'Cotización' => $data['quote'] !== '' ? '#' . $data['quote'] : '',
      'Dirección' => $data['address'],
    ], static fn($value): bool => trim((string) $value) !== '');

    ob_start();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($label) ?> · SKC SuCasa Inmobiliaria</title>
  <?php if ($data['favicon'] !== ''): ?><link rel="icon" href="<?= $h($data['favicon']) ?>" sizes="32x32"><?php endif; ?>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
  <?php foreach ($data['css'] as $href): ?><link rel="stylesheet" href="<?= $h($href) ?>">
  <?php endforeach; ?>
  <?php foreach ($data['js'] as $src): ?><script src="<?= $h($src) ?>" defer></script>
  <?php endforeach; ?>
</head>
<body class="scm-review-page scm-quote-page">
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
      <span class="rp-hero-icon material-symbols-outlined" aria-hidden="true"><?= $h($icon) ?></span>
      <div class="rp-hero-text">
        <p class="rp-eyebrow"><?= $h($eyebrow) ?></p>
        <h1><?= $h($label) ?></h1>
        <dl class="rp-facts">
          <?php foreach ($details as $term => $value): ?>
            <div><dt><?= $h($term) ?></dt><dd><?= $h($value) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
    </section>

    <ol class="rp-steps" aria-label="Pasos">
      <?php foreach ($steps as $index => [$stepIcon, $title, $text]): ?>
        <li><span class="rp-step-number"><?= $index + 1 ?></span><span class="material-symbols-outlined" aria-hidden="true"><?= $h($stepIcon) ?></span><div><strong><?= $h($title) ?></strong><small><?= $h($text) ?></small></div></li>
      <?php endforeach; ?>
    </ol>

    <section class="rp-card qp-status" data-qp-status data-bridge-action="<?= $h($bridgeAction) ?>" data-record-param="<?= $h($param) ?>" data-record-id="<?= (int) $data['record_id'] ?>" aria-live="polite">
      <div class="qp-state" data-qp-state="loading"><span class="rp-spinner" aria-hidden="true"></span><p>Abriendo el formulario…</p></div>
      <div class="qp-state" data-qp-state="closed" hidden>
        <span class="material-symbols-outlined qp-state-icon" aria-hidden="true">task_alt</span>
        <h2>Se cerró el formulario</h2>
        <p>Si lo guardaste o enviaste, ya quedó registrado. Si lo cerraste sin querer, ábrelo otra vez: tu avance queda como borrador en este equipo.</p>
        <div class="qp-actions"><button type="button" data-qp-reopen>Abrir de nuevo</button><a href="<?= $h($data['base_url'] . '/index.php') ?>">Ir al panel</a></div>
      </div>
      <div class="qp-state is-error" data-qp-state="error" hidden>
        <span class="material-symbols-outlined qp-state-icon" aria-hidden="true">error</span>
        <h2>No se pudo abrir</h2>
        <p data-qp-error></p>
        <div class="qp-actions"><button type="button" data-qp-reopen>Intentar de nuevo</button><a href="<?= $h($data['base_url'] . '/index.php') ?>">Ir al panel</a></div>
      </div>
    </section>
  </main>

  <!-- Motor del panel: necesario para los formularios de cotización, no se muestra. -->
  <div class="qp-engine" aria-hidden="true"><?= $data['panel_html'] ?></div>
</body>
</html>
<?php
    return (string) ob_get_clean();
  }
}
