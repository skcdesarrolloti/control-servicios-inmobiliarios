<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

final class ContractMovementsView
{
  public const VIEWS = [
    'ocupaciones' => ['label' => 'Ocupaciones', 'icon' => 'login', 'tab' => 'ocupaciones'],
    'desocupaciones' => ['label' => 'Desocupaciones', 'icon' => 'logout', 'tab' => 'desocupaciones'],
    'comparativo' => ['label' => 'Ocupaciones vs Desocupaciones', 'icon' => 'compare_arrows', 'tab' => 'ocupaciones_vs_desocupaciones'],
  ];

  /** Traduce el parámetro `tab` de la URL a la vista interna del panel. */
  public static function viewFromTab(string $tab): string
  {
    foreach (self::VIEWS as $view => $def) {
      if ($tab === $def['tab'] || $tab === $view) {
        return $view;
      }
    }
    return 'ocupaciones';
  }

  public function renderShell(string $initialView): string
  {
    $initialView = isset(self::VIEWS[$initialView]) ? $initialView : 'ocupaciones';
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    ob_start();
?>
    <section class="scm-cm" data-scm-contract-movements data-initial-view="<?php echo $h($initialView); ?>" aria-labelledby="scm-cm-title">
      <header class="scm-cm-head">
        <div class="scm-cm-head-copy">
          <p class="scm-cm-kicker">Métricas · Contratos de arrendamiento</p>
          <h1 id="scm-cm-title" data-scm-cm-title><?php echo $h(self::VIEWS[$initialView]['label']); ?></h1>
          <p class="scm-cm-subtitle" data-scm-cm-subtitle></p>
        </div>
        <div class="scm-cm-head-actions">
          <span class="scm-cm-updated" data-scm-cm-updated></span>
          <button type="button" class="scm-cm-btn" data-scm-cm-export disabled>
            <span class="material-symbols-outlined" aria-hidden="true">download</span>
            <span>Exportar CSV</span>
          </button>
          <button type="button" class="scm-cm-btn" data-scm-cm-refresh title="Volver a consultar los contratos">
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
            <span>Actualizar</span>
          </button>
        </div>
      </header>

      <div class="scm-cm-views" role="tablist" aria-label="Vistas de ocupación" hidden>
        <?php foreach (self::VIEWS as $view => $def): ?>
          <button
            type="button"
            role="tab"
            class="scm-cm-view<?php echo $view === $initialView ? ' is-active' : ''; ?>"
            aria-selected="<?php echo $view === $initialView ? 'true' : 'false'; ?>"
            data-scm-subview-target="<?php echo $h($view); ?>"
            data-tab-key="<?php echo $h($def['tab']); ?>"
          >
            <span class="material-symbols-outlined" aria-hidden="true"><?php echo $h($def['icon']); ?></span>
            <span><?php echo $h($def['label']); ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <form class="scm-cm-filters" data-scm-cm-filters onsubmit="return false;">
        <label class="scm-cm-field">
          <span>Año</span>
          <select name="year" data-scm-cm-filter="year"><option value="">Todos los años</option></select>
        </label>
        <label class="scm-cm-field">
          <span>Mes</span>
          <select name="month" data-scm-cm-filter="month">
            <option value="">Todos los meses</option>
            <?php foreach (['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'] as $index => $monthName): ?>
              <option value="<?php echo $index + 1; ?>"><?php echo $h($monthName); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="scm-cm-field">
          <span>Funcionario</span>
          <select name="funcionario" data-scm-cm-filter="funcionario"><option value="">Todos</option></select>
        </label>
        <label class="scm-cm-field">
          <span>Tipo de inmueble</span>
          <select name="tipo" data-scm-cm-filter="tipo"><option value="">Todos</option></select>
        </label>
        <label class="scm-cm-field">
          <span>Destinación</span>
          <select name="destinacion" data-scm-cm-filter="destinacion"><option value="">Todas</option></select>
        </label>
        <button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cm-clear disabled>Limpiar filtros</button>
      </form>

      <div class="scm-cm-status" data-scm-cm-status role="status" aria-live="polite">Cargando contratos&hellip;</div>
      <div class="scm-cm-content" data-scm-cm-content></div>
    </section>
<?php
    return (string) ob_get_clean();
  }
}
