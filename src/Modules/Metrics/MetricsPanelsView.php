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
    $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
    $year = $now->format('Y');
    $currentMonth = $now->format('Y-m');
    $modes = ['actual' => 'Mes actual', 'mes' => 'Por mes', 'anual' => 'Anual', 'acumulado' => 'Acumulado'];
    ob_start();
?>
    <section class="scm-cm scm-quote-metrics" data-scm-quote-metrics data-default-year="<?php echo $year; ?>" data-current-month="<?php echo $currentMonth; ?>" aria-labelledby="scm-quote-metrics-title">
      <header class="scm-cm-head">
        <div class="scm-cm-head-copy">
          <p class="scm-cm-kicker">Métricas · Mantenimiento</p>
          <h1 id="scm-quote-metrics-title">Cotizaciones</h1>
          <p class="scm-cm-subtitle">Cotizaciones creadas en el periodo (valor y administración), cuántas faltan por enviar y la administración ganada por las aprobadas según su fecha de respuesta.</p>
        </div>
        <div class="scm-cm-head-actions">
          <span class="scm-cm-updated" data-scm-quote-updated></span>
          <button type="button" class="scm-cm-btn" data-scm-quote-export disabled hidden>
            <span class="material-symbols-outlined" aria-hidden="true">download</span><span>Exportar mes a mes</span>
          </button>
          <button type="button" class="scm-cm-btn" data-scm-quote-refresh>
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span><span>Actualizar</span>
          </button>
        </div>
      </header>

      <div class="scm-cm-views" role="tablist" aria-label="Vistas de cotizaciones">
        <button type="button" role="tab" class="scm-cm-view is-active" aria-selected="true" data-scm-quote-tab="cotizaciones">
          <span class="material-symbols-outlined" aria-hidden="true">request_quote</span><span>Cotizaciones</span>
        </button>
        <button type="button" role="tab" class="scm-cm-view" aria-selected="false" data-scm-quote-tab="conciliacion">
          <span class="material-symbols-outlined" aria-hidden="true">fact_check</span><span>Conciliar con el auxiliar contable</span>
        </button>
      </div>

      <div class="scm-qm-tab" data-scm-quote-tab-panel="cotizaciones">
        <form class="scm-cm-filters scm-qm-filters" onsubmit="return false;">
          <div class="scm-cm-field scm-qm-period">
            <span>Periodo</span>
            <div class="scm-qm-views" role="radiogroup" aria-label="Periodo">
              <?php foreach ($modes as $mode => $label): ?>
                <button type="button" role="radio" class="scm-qm-view<?php echo $mode === 'actual' ? ' is-active' : ''; ?>" aria-checked="<?php echo $mode === 'actual' ? 'true' : 'false'; ?>" data-scm-quote-mode="<?php echo $mode; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></button>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="scm-cm-field" data-scm-quote-mode-field="mes" hidden>
            <span>Mes</span>
            <input type="month" data-scm-quote-filter="month" value="<?php echo $currentMonth; ?>" max="<?php echo $currentMonth; ?>">
          </label>
          <label class="scm-cm-field" data-scm-quote-mode-field="anual" hidden>
            <span>Año</span>
            <select data-scm-quote-filter="year"><option value="<?php echo $year; ?>"><?php echo $year; ?></option></select>
          </label>
          <label class="scm-cm-field" data-scm-quote-mode-field="acumulado" hidden>
            <span>Desde</span>
            <input type="month" data-scm-quote-filter="from" value="<?php echo $year; ?>-01" max="<?php echo $currentMonth; ?>">
          </label>
          <label class="scm-cm-field" data-scm-quote-mode-field="acumulado" hidden>
            <span>Hasta</span>
            <input type="month" data-scm-quote-filter="to" value="<?php echo $currentMonth; ?>" max="<?php echo $currentMonth; ?>">
          </label>
          <label class="scm-cm-field">
            <span>Categoría</span>
            <select data-scm-quote-filter="categoria"><option value="">Todas</option></select>
          </label>
        </form>

        <div class="scm-cm-status" data-scm-quote-status role="status" aria-live="polite">Cargando cotizaciones&hellip;</div>
        <div class="scm-cm-content" data-scm-quote-content></div>
      </div>

      <div class="scm-qm-tab" data-scm-quote-tab-panel="conciliacion" hidden>
        <div data-scm-quote-ledger></div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
