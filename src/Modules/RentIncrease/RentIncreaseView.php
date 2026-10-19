<?php

declare(strict_types=1);

namespace SCM\Modules\RentIncrease;

use SCM\Modules\Pending\PublicServicesUi as UI;

final class RentIncreaseView
{
  public function renderPanel(): string
  {
    $ui = UI::class;
    $tabs = ['contracts' => ['Contratos activos', 'document'], 'canon' => ['Aumentos en canon', 'refresh'], 'administracion' => ['Aumentos en administración', 'building']];
    ob_start();
?>
    <div class="scm-rent-increase !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-rent-increase-panel>
      <header class="<?php echo $ui::CARD; ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?php echo $ui::icon('document', '!sp-w-6 !sp-h-6'); ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Cartas de aumento</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Contratos de arrendamiento activos · Generación de cartas · Seguimiento de aumentos guardados</p></div></div>
        <div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" data-rent-increase-count>0</strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Registros filtrados</p></div>
      </header>
      <nav class="scm-rent-increase-tabs !sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-w-fit !sp-max-w-full" role="tablist" aria-label="Cartas de aumento">
        <?php foreach ($tabs as $key => $tab): ?>
          <button type="button" class="sp-group !sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow<?php echo $key === 'contracts' ? ' active' : ''; ?>" data-rent-increase-tab="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $key === 'contracts' ? 'true' : 'false'; ?>"><?php echo $ui::icon($tab[1], '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow'); ?><span><?php echo esc_html($tab[0]); ?></span></button>
        <?php endforeach; ?>
      </nav>
      <?php foreach ($tabs as $key => $tab): ?>
        <section class="scm-rent-increase-section !sp-space-y-5<?php echo $key === 'contracts' ? ' active' : ''; ?>" data-rent-increase-section="<?php echo esc_attr($key); ?>" data-scm-loaded="0">
          <?php echo $this->renderFilters($key, $tab[0]); ?>
          <div class="<?php echo $ui::CARD; ?>">
            <div class="!sp-p-4"><h3 class="!sp-m-0 !sp-font-bold !sp-text-sm"><?php echo esc_html($key === 'contracts' ? 'Contratos activos para generar cartas' : $tab[0] . ' guardados'); ?></h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?php echo esc_html($key === 'contracts' ? 'Genera la carta de aumento de canon o de administración y notifica a los interesados.' : 'Cartas generadas con su valor y fecha de incremento. Ábrelas sin salir del panel.'); ?></p></div>
            <div data-rent-increase-table><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Selecciona esta vista para cargar la información.</p></div>
            <div class="!sp-p-4 !sp-bg-slate-50/50 empty:!sp-hidden" data-rent-increase-pagination></div>
          </div>
        </section>
      <?php endforeach; ?>
      <div class="scm-cia-modal" data-rent-increase-modal hidden>
        <div class="scm-cia-modal-backdrop" data-rent-increase-close></div>
        <section class="scm-cia-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scm-rent-increase-modal-title">
          <header>
            <div><span class="scm-eyebrow">Carta de aumento</span><h3 id="scm-rent-increase-modal-title" data-rent-increase-modal-title>Nueva carta</h3><p data-rent-increase-modal-subtitle>Completa los datos antes de generar y notificar.</p></div>
            <button type="button" class="scm-cia-modal-close" data-rent-increase-close aria-label="Cerrar">&times;</button>
          </header>
          <div data-rent-increase-form-wrap></div>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function renderFilters(string $scope, string $label): string
  {
    $ui = UI::class;
    $field = static fn(string $label, string $control): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . esc_html($label) . '</span>' . $control . '</label>';
    $input = static fn(string $name, string $type, string $placeholder = ''): string => '<input class="' . $ui::INPUT . '" name="' . $name . '" type="' . $type . '"' . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '') . '>';
    $months = '<option value="0">Todos</option>';
    for ($m = 1; $m <= 12; $m++) {
      $months .= '<option value="' . $m . '">' . esc_html($this->monthName($m)) . '</option>';
    }
    ob_start();
?>
    <div class="<?php echo $ui::CARD; ?> !sp-p-5">
      <div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?php echo $ui::icon('filter'); ?>Filtros · <?php echo esc_html($label); ?></h3><span class="!sp-text-[10px] !sp-text-service-muted">Datos del contrato, fechas de incremento y fin de contrato</span></div>
      <form autocomplete="off" data-rent-increase-form="<?php echo esc_attr($scope); ?>">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
        <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
          <?php
          echo $field('Propietario', $input('propietario', 'text', 'Buscar por nombre...'));
          echo $field('Arrendatario', $input('arrendatario', 'text', 'Buscar por nombre...'));
          echo $field('Contrato', $input('contrato', 'text', 'Código contrato...'));
          echo $field('Inmueble', $input('inmueble', 'text', 'Ej: 10156'));
          echo $field('Incremento canon desde', $input('canon_from', 'date'));
          echo $field('Incremento canon hasta', $input('canon_to', 'date'));
          echo $field('Incremento administración desde', $input('admin_from', 'date'));
          echo $field('Incremento administración hasta', $input('admin_to', 'date'));
          echo $field('Mes fin de contrato', '<select class="' . $ui::INPUT . '" name="month">' . $months . '</select>');
          echo $field('Registros por página', '<select class="' . $ui::INPUT . '" name="per_page"><option value="20">20</option><option value="30">30</option><option value="60">60</option><option value="100">100</option></select>');
          ?>
        </div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5">
          <button class="<?php echo $ui::PRIMARY; ?>" type="submit"><?php echo $ui::icon('filter'); ?>Filtrar</button>
          <button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-rent-increase-clear>Limpiar filtros</button>
          <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderTable(array $rows, string $scope): string
  {
    $ui = UI::class;
    if ($rows === []) {
      return '<p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No hay registros con los filtros actuales.</p>';
    }
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $td = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4';
    $html = '<div class="!sp-overflow-x-auto"><table class="scm-rent-increase-table !sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[1150px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['Contrato', 'Inmueble', 'Canon', 'Administración', 'Dirección', 'Propietario', 'Arrendatario', 'Inicio', 'Fin', 'Acciones'] as $label) {
      $html .= '<th class="' . $th . '">' . $label . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $row = (array) $row;
      $html .= '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">';
      $html .= '<td class="' . $td . '"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td class="' . $td . '"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-700 !sp-font-semibold">' . esc_html((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '-')) . '</span></td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><strong class="!sp-block !sp-text-xs">' . esc_html($this->money($row['valor_canon'] ?? '')) . '</strong>' . $this->dateSmall($row['fecha_incremento_canon'] ?? null) . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><strong class="!sp-block !sp-text-xs">' . esc_html($this->money($row['valor_administracion'] ?? '')) . '</strong>' . $this->dateSmall($row['fecha_incremento_admin'] ?? null) . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[220px] !sp-leading-relaxed !sp-font-medium">' . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px] !sp-leading-relaxed">' . esc_html((string) ($row['propietario'] ?? '-')) . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px] !sp-leading-relaxed">' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap !sp-text-service-muted">' . esc_html($this->dateText($row['inicio_contrato'] ?? null)) . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-bg-slate-100 !sp-rounded !sp-px-2 !sp-py-1">' . esc_html($this->dateText($row['fin_contrato'] ?? null)) . '</span></td>';
      $html .= '<td class="' . $td . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-2">';
      if ($scope === 'contracts') {
        $html .= $this->actionButton('canon', $row) . $this->actionButton('administracion', $row);
      } else {
        $field = $scope === 'canon' ? 'carta_aumento_canon' : 'carta_aumento_admin';
        $url = trim((string) ($row[$field] ?? ''));
        $html .= $url !== ''
          ? '<button type="button" class="' . $ui::NAVY . ' !sp-whitespace-nowrap" data-scm-open-iframe data-iframe-url="' . esc_attr($url) . '" data-iframe-title="Carta de aumento">' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Ver carta</button>'
          : '<span class="!sp-text-service-muted">Sin carta</span>';
      }
      $html .= '</div></td></tr>';
    }
    return $html . '</tbody></table></div>';
  }

  /** @param array<string,mixed> $row */
  private function actionButton(string $type, array $row): string
  {
    $ui = UI::class;
    $label = $type === 'canon' ? 'Aumentar canon' : 'Aumentar administración';
    $attrs = [
      'type' => $type,
      'contract-id' => (string) ($row['_ID'] ?? ''),
      'contract-code' => (string) ($row['contrato'] ?? $row['_ID'] ?? ''),
      'tenant' => (string) ($row['arrendatario'] ?? ''),
      'property' => (string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''),
      'address' => (string) ($row['direccion'] ?? ''),
      'canon' => (string) ($row['valor_canon'] ?? ''),
      'administration' => (string) ($row['valor_administracion'] ?? ''),
    ];
    $htmlAttrs = '';
    foreach ($attrs as $key => $value) {
      $htmlAttrs .= ' data-' . $key . '="' . esc_attr($value) . '"';
    }
    $class = $type === 'canon' ? $ui::NAVY : $ui::SECONDARY . ' !sp-text-[10px]';
    return '<button type="button" class="' . $class . ' !sp-whitespace-nowrap" data-rent-increase-open' . $htmlAttrs . '>' . $ui::icon($type === 'canon' ? 'refresh' : 'building', '!sp-w-3 !sp-h-3') . esc_html($label) . '</button>';
  }

  /** @param array<string,mixed> $pagination */
  public function renderPagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    $info = '<span class="!sp-text-[10px] !sp-text-service-muted">';
    if ($totalPages <= 1) {
      return $total > 0 ? $info . 'Mostrando ' . esc_html((string) $total) . ' registro(s)</span>' : '';
    }
    $button = static fn(int $target, bool $disabled, string $label): string => '<button type="button" class="scm-page-btn-rent-increase ' . UI::SECONDARY . '" data-page="' . esc_attr((string) $target) . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>';
    return '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-font-sans">'
      . $info . 'Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' · ' . esc_html((string) $total) . ' registros</span>'
      . '<div class="!sp-flex !sp-items-center !sp-gap-2">' . $button(max(1, $page - 1), $page <= 1, 'Anterior')
      . '<span class="!sp-bg-service-navy !sp-text-white !sp-text-[10px] !sp-px-3 !sp-py-2 !sp-rounded">' . esc_html((string) $page) . '</span>'
      . $button(min($totalPages, $page + 1), $page >= $totalPages, 'Siguiente') . '</div>'
      . '</div>';
  }

  private function money($value): string
  {
    $value = trim((string) $value);
    if ($value === '') {
      return '-';
    }
    if (preg_match('/no\s*aplica/i', $value) === 1) {
      return 'No aplica';
    }
    $normalized = preg_replace('/[^\d,.-]/', '', $value) ?? '';
    if ($normalized === '' || preg_match('/\d/', $normalized) !== 1) {
      return $value;
    }
    $negative = str_starts_with($normalized, '-');
    $normalized = trim($normalized, '-');
    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
      $normalized = str_replace('.', '', $normalized);
      $normalized = preg_replace('/,\d+$/', '', $normalized) ?? $normalized;
    } elseif (str_contains($normalized, '.')) {
      $parts = explode('.', $normalized);
      $last = end($parts);
      $normalized = strlen((string) $last) === 3
        ? str_replace('.', '', $normalized)
        : (preg_replace('/\.\d+$/', '', $normalized) ?? $normalized);
    } elseif (str_contains($normalized, ',')) {
      $parts = explode(',', $normalized);
      $last = end($parts);
      $normalized = strlen((string) $last) === 3
        ? str_replace(',', '', $normalized)
        : (preg_replace('/,\d+$/', '', $normalized) ?? $normalized);
    }
    $digits = preg_replace('/\D/', '', $normalized) ?? '';
    if ($digits === '') {
      return $value;
    }
    $amount = (int) $digits;
    if ($negative) {
      $amount *= -1;
    }
    return '$' . number_format($amount, 0, ',', '.');
  }

  private function dateText($value): string
  {
    if ($value === null || $value === '') {
      return '-';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }

  private function dateSmall($value): string
  {
    $date = $this->dateText($value);
    return $date === '' || $date === '-' ? '' : '<small class="!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted">Incremento ' . esc_html($date) . '</small>';
  }

  private function monthName(int $month): string
  {
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return $months[$month] ?? (string) $month;
  }
}
