<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

/** Estructura del panel; el contenido lo dibuja admin-legal-cases.js. */
final class LegalCaseView
{
  /** @param array{manage:bool,send:bool,config:bool,export:bool} $can */
  public function renderShell(array $can, string $quillJs, string $quillCss): string
  {
    $flag = static fn(bool $value): string => $value ? '1' : '0';
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ob_start();
?>
    <section class="scm-lc" data-scm-legal-cases data-can-manage="<?php echo $flag($can['manage']); ?>" data-can-send="<?php echo $flag($can['send']); ?>" data-can-config="<?php echo $flag($can['config']); ?>" data-can-export="<?php echo $flag($can['export']); ?>" data-quill-js="<?php echo $e($quillJs); ?>" data-quill-css="<?php echo $e($quillCss); ?>" aria-labelledby="scm-lc-title">
      <header class="scm-lc-head">
        <div>
          <p class="scm-lc-kicker">Actividades contractuales</p>
          <h1 id="scm-lc-title">Casos jurídicos</h1>
          <p class="scm-lc-subtitle">Siniestros, reclamaciones, PQR y demás asuntos especiales, con su línea de tiempo y sus comunicaciones.</p>
        </div>
        <div class="scm-lc-actions">
          <button type="button" class="scm-lc-btn" data-scm-lc-refresh><span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span></button>
          <?php if ($can['manage']): ?>
            <button type="button" class="scm-lc-btn scm-lc-btn-primary" data-scm-lc-new><span class="material-symbols-outlined" aria-hidden="true">add</span><span>Nuevo caso</span></button>
          <?php endif; ?>
        </div>
      </header>

      <div class="scm-lc-chips" role="group" aria-label="Vista">
        <button type="button" class="scm-lc-chip is-active" data-scm-lc-view-btn="cases">Casos</button>
        <button type="button" class="scm-lc-chip" data-scm-lc-view-btn="comms">Comunicaciones enviadas</button>
      </div>

      <div class="scm-lc-view" data-scm-lc-view="cases">
        <div class="scm-lc-kpis" data-scm-lc-kpis></div>
        <form class="scm-lc-filters" data-scm-lc-filters onsubmit="return false;">
          <label class="scm-lc-field scm-lc-field-wide"><span>Buscar</span><input type="search" name="search" placeholder="Código, nombre, contrato o parte"></label>
          <label class="scm-lc-field"><span>Estado</span><select name="status"><option value="">Todos</option></select></label>
          <label class="scm-lc-field"><span>Tipo</span><select name="type_id"><option value="">Todos</option></select></label>
          <label class="scm-lc-field"><span>Responsable</span><select name="responsible"><option value="">Todos</option></select></label>
          <label class="scm-lc-field"><span>Inmueble</span><input type="search" name="property" placeholder="Código o dirección"></label>
        </form>
        <div class="scm-lc-status" data-scm-lc-status role="status" aria-live="polite">Cargando casos&hellip;</div>
        <div data-scm-lc-list></div>
        <nav class="scm-lc-pager" data-scm-lc-pager aria-label="Paginación de casos"></nav>
      </div>

      <div class="scm-lc-view" data-scm-lc-view="comms" hidden>
        <form class="scm-lc-filters" data-scm-lc-comm-filters onsubmit="return false;">
          <label class="scm-lc-field"><span>Destinatario</span><input type="search" name="recipient" placeholder="Nombre, correo o celular"></label>
          <label class="scm-lc-field"><span>Caso</span><input type="search" name="case" placeholder="Código o nombre"></label>
          <label class="scm-lc-field"><span>Canal</span><select name="channel"><option value="">Todos</option><option value="email">Correo</option><option value="whatsapp">WhatsApp</option></select></label>
          <label class="scm-lc-field"><span>Desde</span><input type="date" name="from"></label>
          <label class="scm-lc-field"><span>Hasta</span><input type="date" name="to"></label>
        </form>
        <div class="scm-lc-status" data-scm-lc-comm-status role="status" aria-live="polite" hidden></div>
        <div data-scm-lc-comm-list></div>
        <nav class="scm-lc-pager" data-scm-lc-comm-pager aria-label="Paginación de comunicaciones"></nav>
      </div>

      <div data-scm-lc-modals></div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
