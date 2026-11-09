<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

/** Estructura del panel; el contenido lo dibuja admin-legal-cases.js. */
final class LegalCaseView
{
  public function renderShell(bool $canManage): string
  {
    ob_start();
?>
    <section class="scm-lc" data-scm-legal-cases data-can-manage="<?php echo $canManage ? '1' : '0'; ?>" aria-labelledby="scm-lc-title">
      <header class="scm-lc-head">
        <div>
          <p class="scm-lc-kicker">Actividades contractuales</p>
          <h1 id="scm-lc-title">Casos jurídicos</h1>
          <p class="scm-lc-subtitle">Siniestros, reclamaciones, PQR y demás asuntos especiales, con su línea de tiempo y sus comunicaciones.</p>
        </div>
        <div class="scm-lc-actions">
          <button type="button" class="scm-lc-btn" data-scm-lc-refresh><span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span></button>
          <?php if ($canManage): ?>
            <button type="button" class="scm-lc-btn scm-lc-btn-primary" data-scm-lc-new><span class="material-symbols-outlined" aria-hidden="true">add</span><span>Nuevo caso</span></button>
          <?php endif; ?>
        </div>
      </header>

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
      <div data-scm-lc-modals></div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
