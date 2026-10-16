<?php

declare(strict_types=1);

namespace SCM\Modules\ContractOwnership;

final class ContractOwnershipView
{
  public function renderShell(bool $canEdit): string
  {
    ob_start();
?>
    <section class="scm-co" data-scm-contract-owners data-can-edit="<?php echo $canEdit ? '1' : '0'; ?>" aria-labelledby="scm-co-title">
      <header class="scm-co-head">
        <div>
          <p class="scm-co-kicker">Actividades contractuales</p>
          <h1 id="scm-co-title">Funcionario de contratos</h1>
          <p class="scm-co-subtitle">Corrige el consultor responsable y los días de gracia de cada contrato de arrendamiento. La ayuda los sugiere a partir de la hoja de cierre y el mandato.</p>
        </div>
        <div class="scm-co-head-actions">
          <button type="button" class="scm-co-btn" data-scm-co-guide>
            <span class="material-symbols-outlined" aria-hidden="true">help</span><span>¿Cómo se sugiere?</span>
          </button>
          <button type="button" class="scm-co-btn" data-scm-co-refresh>
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span>
          </button>
        </div>
      </header>

      <div class="scm-co-kpis" data-scm-co-kpis></div>

      <form class="scm-co-filters" onsubmit="return false;">
        <label class="scm-co-field scm-co-field-search">
          <span>Buscar</span>
          <input type="search" data-scm-co-search placeholder="Contrato, inmueble, dirección, propietario o arrendatario">
        </label>
        <label class="scm-co-field">
          <span>Situación</span>
          <select data-scm-co-filter="status">
            <option value="">Todas</option>
            <option value="difiere">Difiere de la sugerencia</option>
            <option value="sin_funcionario">Sin funcionario</option>
            <option value="retirado">Consultor retirado</option>
            <option value="dias_gracia">Días de gracia por completar</option>
            <option value="coincide">Coincide con la sugerencia</option>
            <option value="sin_evidencia">Sin hoja de cierre ni mandato</option>
          </select>
        </label>
        <label class="scm-co-field">
          <span>Estado del contrato</span>
          <select data-scm-co-filter="estado"><option value="">Todos</option></select>
        </label>
        <label class="scm-co-field">
          <span>Funcionario actual</span>
          <select data-scm-co-filter="employee"><option value="">Todos</option></select>
        </label>
        <label class="scm-co-field">
          <span>Confianza</span>
          <select data-scm-co-filter="confidence">
            <option value="">Todas</option>
            <option value="alta">Alta</option>
            <option value="media">Media</option>
            <option value="baja">Baja</option>
            <option value="ninguna">Sin sugerencia</option>
          </select>
        </label>
      </form>

      <?php if ($canEdit): ?>
        <div class="scm-co-bulk" data-scm-co-bulk hidden>
          <strong data-scm-co-bulk-count></strong>
          <button type="button" class="scm-co-btn scm-co-btn-primary" data-scm-co-bulk-suggest>Aplicar sugerencia</button>
          <label class="scm-co-inline">
            <span>o asignar a</span>
            <select data-scm-co-bulk-employee><option value="">Elige un consultor</option></select>
          </label>
          <button type="button" class="scm-co-btn" data-scm-co-bulk-assign>Asignar</button>
          <button type="button" class="scm-co-btn scm-co-btn-ghost" data-scm-co-bulk-clear>Quitar selección</button>
        </div>
      <?php else: ?>
        <p class="scm-co-readonly"><span class="material-symbols-outlined" aria-hidden="true">lock</span>Puedes consultar los contratos, pero tu cargo no tiene permiso para cambiar el funcionario.</p>
      <?php endif; ?>

      <div class="scm-co-status" data-scm-co-status role="status" aria-live="polite">Cargando contratos&hellip;</div>
      <div class="scm-co-table" data-scm-co-table></div>

      <div class="scm-co-modal" data-scm-co-modal hidden>
        <div class="scm-co-modal-backdrop" data-scm-co-close></div>
        <div class="scm-co-modal-card" role="dialog" aria-modal="true" aria-labelledby="scm-co-modal-title">
          <header>
            <h2 id="scm-co-modal-title" data-scm-co-modal-title></h2>
            <button type="button" class="scm-co-icon-btn" data-scm-co-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
          </header>
          <div class="scm-co-modal-body" data-scm-co-modal-body></div>
        </div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
