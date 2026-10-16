<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

/**
 * Paneles de Métricas › Casos y Métricas › Mantenimiento › Cotizaciones.
 * Usan el mismo sistema visual (clases scm-cm-*) que Ocupaciones y Desocupaciones.
 */
final class MetricsPanelsView
{
  public function renderCasesShell(): string
  {
    $month = date('Y-m');
    ob_start();
?>
    <section class="scm-cm scm-case-metrics" data-scm-case-metrics aria-labelledby="scm-case-metrics-title">
      <header class="scm-cm-head">
        <div class="scm-cm-head-copy">
          <p class="scm-cm-kicker">Métricas</p>
          <h1 id="scm-case-metrics-title">Casos</h1>
          <p class="scm-cm-subtitle">Casos creados en el periodo y su estado actual, por estado administrativo, tema y funcionario.</p>
        </div>
        <div class="scm-cm-head-actions">
          <span class="scm-cm-updated" data-scm-case-updated></span>
          <button type="button" class="scm-cm-btn" data-scm-case-export disabled>
            <span class="material-symbols-outlined" aria-hidden="true">download</span><span>Exportar CSV</span>
          </button>
          <button type="button" class="scm-cm-btn" data-scm-case-refresh>
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span>
          </button>
        </div>
      </header>

      <form class="scm-cm-filters" onsubmit="return false;">
        <label class="scm-cm-field">
          <span>Mes</span>
          <input type="month" data-scm-case-filter="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="scm-cm-field">
          <span>Desde</span>
          <input type="date" data-scm-case-filter="from">
        </label>
        <label class="scm-cm-field">
          <span>Hasta</span>
          <input type="date" data-scm-case-filter="to">
        </label>
        <label class="scm-cm-field">
          <span>Tema</span>
          <select data-scm-case-filter="tema"><option value="">Todos</option></select>
        </label>
        <label class="scm-cm-field">
          <span>Departamento</span>
          <select data-scm-case-filter="departamento"><option value="">Todos</option></select>
        </label>
        <label class="scm-cm-field">
          <span>Funcionario</span>
          <select data-scm-case-filter="funcionario"><option value="">Todos</option></select>
        </label>
        <button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-case-clear>Limpiar filtros</button>
      </form>

      <div class="scm-cm-status" data-scm-case-status role="status" aria-live="polite">Cargando casos&hellip;</div>
      <div class="scm-cm-content" data-scm-case-content></div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  public function renderQuotesShell(): string
  {
    $year = (int) date('Y');
    $month = (int) date('n');
    $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    ob_start();
?>
    <section class="scm-cm scm-quote-metrics" data-scm-quote-metrics data-default-year="<?php echo $year; ?>" data-default-month="<?php echo $month; ?>" aria-labelledby="scm-quote-metrics-title">
      <header class="scm-cm-head">
        <div class="scm-cm-head-copy">
          <p class="scm-cm-kicker">Métricas · Mantenimiento</p>
          <h1 id="scm-quote-metrics-title">Cotizaciones</h1>
          <p class="scm-cm-subtitle">Cotizaciones creadas en el mes (valor y administración), cuántas faltan por enviar y la administración ganada por las aprobadas según su fecha de respuesta.</p>
        </div>
        <div class="scm-cm-head-actions">
          <span class="scm-cm-updated" data-scm-quote-updated></span>
          <button type="button" class="scm-cm-btn" data-scm-quote-export disabled>
            <span class="material-symbols-outlined" aria-hidden="true">download</span><span>Exportar mes a mes</span>
          </button>
          <button type="button" class="scm-cm-btn" data-scm-quote-refresh>
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span>
          </button>
        </div>
      </header>

      <form class="scm-cm-filters" onsubmit="return false;">
        <label class="scm-cm-field">
          <span>Año</span>
          <select data-scm-quote-filter="year"><option value="<?php echo $year; ?>"><?php echo $year; ?></option></select>
        </label>
        <label class="scm-cm-field">
          <span>Mes</span>
          <select data-scm-quote-filter="month">
            <option value="0">Todo el año</option>
            <?php foreach ($months as $index => $name): ?>
              <option value="<?php echo $index + 1; ?>"<?php echo $index + 1 === $month ? ' selected' : ''; ?>><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="scm-cm-field">
          <span>Categoría</span>
          <select data-scm-quote-filter="categoria"><option value="">Todas</option></select>
        </label>
      </form>

      <div class="scm-cm-status" data-scm-quote-status role="status" aria-live="polite">Cargando cotizaciones&hellip;</div>
      <div class="scm-cm-content" data-scm-quote-content></div>
      <div data-scm-quote-quality></div>
      <div data-scm-quote-ledger></div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
