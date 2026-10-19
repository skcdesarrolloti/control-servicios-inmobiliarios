<?php

declare(strict_types=1);

namespace SCM\Modules\MaintenanceOrders;

use SCM\Modules\Pending\PublicServicesUi as UI;

final class MaintenanceOrdersView
{
  /** @var array<string,array{0:string,1:string,2:string}> clave => [etiqueta, icono, descripción] */
  private const TABS = [
    'pendientes' => ['Órdenes por responder', 'clock', 'Órdenes esperando aprobación. Revísalas y apruébalas o desapruébalas sin salir del panel.'],
    'aprobadas' => ['Órdenes aprobadas', 'check', 'Órdenes autorizadas, listas para generar el soporte de pago.'],
    'desaprobadas' => ['Órdenes desaprobadas', 'close', 'Órdenes que no fueron autorizadas. Puedes revisarlas o eliminarlas para liberar el saldo.'],
  ];

  public function renderPanel(bool $canCreate): string
  {
    $ui = UI::class;
    ob_start();
?>
    <div class="scm-maintenance-orders !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-maintenance-orders-panel>
      <header class="<?php echo $ui::CARD; ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?php echo $ui::icon('document', '!sp-w-6 !sp-h-6'); ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Órdenes de mantenimiento</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Aprobación, soporte de pago y seguimiento de las órdenes generadas desde cotizaciones aprobadas</p></div></div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-4">
          <div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" data-maintenance-orders-value>$ 0</strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Valor de la vista</p></div>
          <?php if ($canCreate): ?>
            <button type="button" class="<?php echo $ui::PRIMARY; ?>" data-maintenance-orders-create><svg class="!sp-w-4 !sp-h-4 !sp-shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"></path></svg>Crear orden</button>
          <?php endif; ?>
        </div>
      </header>
      <nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-w-fit !sp-max-w-full" role="tablist" aria-label="Órdenes de mantenimiento">
        <?php foreach (self::TABS as $key => $tab): $active = $key === 'pendientes'; ?>
          <button type="button" class="sp-group !sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-maintenance-orders-tab="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $active ? 'true' : 'false'; ?>"><?php echo $ui::icon($tab[1], '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow'); ?><span><?php echo esc_html($tab[0]); ?></span><span class="!sp-min-w-[18px] !sp-px-1.5 !sp-py-0.5 !sp-rounded-full !sp-bg-white !sp-text-service-navy !sp-text-[9px] !sp-font-bold !sp-text-center group-aria-pressed:!sp-bg-service-yellow" data-maintenance-orders-count="<?php echo esc_attr($key); ?>">·</span></button>
        <?php endforeach; ?>
      </nav>
      <?php echo $this->renderFilters(); ?>
      <div class="<?php echo $ui::CARD; ?>">
        <div class="!sp-p-4"><h3 class="!sp-m-0 !sp-font-bold !sp-text-sm" data-maintenance-orders-title><?php echo esc_html(self::TABS['pendientes'][0]); ?></h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted" data-maintenance-orders-description><?php echo esc_html(self::TABS['pendientes'][2]); ?></p></div>
        <div data-maintenance-orders-table><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando órdenes...</p></div>
        <div class="!sp-p-4 !sp-bg-slate-50/50 empty:!sp-hidden" data-maintenance-orders-pagination></div>
      </div>
      <script type="application/json" data-maintenance-orders-tabs><?php echo wp_json_encode(array_map(static fn(array $tab): array => ['title' => $tab[0], 'description' => $tab[2]], self::TABS)); ?></script>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function renderFilters(): string
  {
    $ui = UI::class;
    $field = static fn(string $label, string $control): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . esc_html($label) . '</span>' . $control . '</label>';
    $input = static fn(string $name, string $type, string $placeholder = ''): string => '<input class="' . $ui::INPUT . '" name="' . $name . '" type="' . $type . '"' . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '') . '>';
    $categories = '<option value="">Todas</option>';
    foreach (['Mano de obra', 'Materiales', 'Maquinarias', 'Otros costos'] as $category) {
      $categories .= '<option value="' . esc_attr($category) . '">' . esc_html($category) . '</option>';
    }
    ob_start();
?>
    <div class="<?php echo $ui::CARD; ?> !sp-p-5">
      <div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?php echo $ui::icon('filter'); ?>Filtros</h3><span class="!sp-text-[10px] !sp-text-service-muted">Los filtros aplican a las tres pestañas</span></div>
      <form autocomplete="off" data-maintenance-orders-form>
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="scope" value="pendientes">
        <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
          <div class="sm:!sp-col-span-2"><?php echo $field('Buscar', $input('q', 'text', 'Orden, cotización, caso, contrato, inmueble, proveedor o dirección...')); ?></div>
          <?php
          echo $field('Tipo de orden', '<select class="' . $ui::INPUT . '" name="categoria">' . $categories . '</select>');
          echo $field('Creada por', $input('creador', 'text', 'Nombre del funcionario...'));
          echo $field('Creada desde', $input('desde', 'date'));
          echo $field('Creada hasta', $input('hasta', 'date'));
          echo $field('Registros por página', '<select class="' . $ui::INPUT . '" name="per_page"><option value="20">20</option><option value="30">30</option><option value="60">60</option><option value="100">100</option></select>');
          ?>
        </div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5">
          <button class="<?php echo $ui::PRIMARY; ?>" type="submit"><?php echo $ui::icon('filter'); ?>Filtrar</button>
          <button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-maintenance-orders-clear>Limpiar filtros</button>
          <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderTable(array $rows, string $scope, bool $canRespond, bool $canDelete): string
  {
    $ui = UI::class;
    if ($rows === []) {
      return '<div class="!sp-p-10 !sp-text-center"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-slate-100 !sp-text-service-muted">' . $ui::icon('document', '!sp-w-5 !sp-h-5') . '</span><p class="!sp-m-0 !sp-mt-3 !sp-text-xs !sp-font-semibold">No hay órdenes en esta pestaña</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Ajusta los filtros o revisa otra pestaña.</p></div>';
    }
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $td = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4 !sp-align-top';
    $muted = '!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted';
    $html = '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[1100px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['Orden', 'Caso / Cotización', 'Inmueble', 'Tipo y concepto', 'Proveedor', 'Valor', 'Estado', 'Acciones'] as $label) {
      $html .= '<th class="' . $th . '">' . $label . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $v = static fn(string $key, string $fallback = '-'): string => trim((string) ($row[$key] ?? '')) !== '' ? trim((string) $row[$key]) : $fallback;
      $id = $v('_ID', '');
      $state = $v('estado', 'Esperando respuesta');
      $html .= '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . esc_html($id) . '</span><small class="' . $muted . '">' . esc_html((string) ($row['_fecha_label'] ?? '-')) . ' ' . esc_html((string) ($row['_hora_label'] ?? '')) . '</small><small class="' . $muted . '">Por ' . esc_html($v('creador')) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><strong class="!sp-block">Caso #' . esc_html($v('id_ticket')) . '</strong><small class="' . $muted . '">Cotización #' . esc_html($v('id_cotizacion')) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[220px]"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-700 !sp-font-semibold">' . esc_html($v('inmueble', $v('id_inmueble'))) . '</span><small class="' . $muted . ' !sp-leading-relaxed">' . esc_html($v('direccion')) . '</small><small class="' . $muted . '">Contrato #' . esc_html($v('contrato', $v('id_contrato'))) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[240px]"><span class="!sp-inline-flex !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-bg-slate-100 !sp-text-[9px] !sp-font-semibold">' . esc_html($v('categoria')) . '</span><p class="!sp-m-0 !sp-mt-1.5 !sp-leading-relaxed !sp-font-medium">' . esc_html($v('concepto', $v('actividad'))) . '</p></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px]"><strong class="!sp-block !sp-leading-relaxed">' . esc_html($v('proveedor')) . '</strong><small class="' . $muted . '">' . esc_html($v('identificacion_proveedor', '')) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><strong class="!sp-text-xs">' . esc_html((string) ($row['_valor_label'] ?? '-')) . '</strong></td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap">' . $this->stateBadge($scope, $state);
      if ($scope !== 'pendientes') {
        $html .= '<small class="' . $muted . '">Por ' . esc_html($v('autorizador', 'Sin registrar')) . '</small>';
      }
      $html .= '</td><td class="' . $td . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-1.5 !sp-min-w-[170px]">';
      $html .= '<button type="button" class="' . $ui::NAVY . ' !sp-whitespace-nowrap" data-maintenance-order-view data-order-id="' . esc_attr($id) . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Ver</button>';
      $html .= '<button type="button" class="' . $ui::SECONDARY . ' !sp-text-[10px] !sp-whitespace-nowrap" data-maintenance-order-pdf data-order-id="' . esc_attr($id) . '" title="Descargar soporte de pago en PDF">' . $ui::icon('download', '!sp-w-3 !sp-h-3') . 'PDF</button>';
      if ($scope === 'pendientes' && $canRespond) {
        $html .= '<button type="button" class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-emerald-600 !sp-text-white !sp-border !sp-border-solid !sp-border-emerald-600 !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-emerald-700 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow !sp-whitespace-nowrap" data-maintenance-order-respond'
          . ' data-order-id="' . esc_attr($id) . '" data-order-number="' . esc_attr($id) . '"'
          . ' data-order-category="' . esc_attr($v('categoria', '')) . '" data-order-provider="' . esc_attr($v('proveedor', '')) . '"'
          . ' data-order-value="' . esc_attr((string) ($row['_valor_label'] ?? '')) . '" data-order-version="' . esc_attr((string) ($row['cct_modified'] ?? '')) . '">'
          . $ui::icon('check', '!sp-w-3 !sp-h-3') . 'Aprobar</button>';
      }
      if ($canDelete) {
        $html .= '<button type="button" class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-rose-700 !sp-border !sp-border-solid !sp-border-rose-200 !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-rose-50 focus-visible:!sp-ring-2 focus-visible:!sp-ring-rose-300 !sp-whitespace-nowrap" data-maintenance-order-delete'
          . ' data-order-id="' . esc_attr($id) . '" data-order-state="' . esc_attr($state) . '" data-order-value="' . esc_attr((string) ($row['_valor_label'] ?? '')) . '"'
          . ' data-order-category="' . esc_attr($v('categoria', '')) . '" data-order-version="' . esc_attr((string) ($row['cct_modified'] ?? '')) . '">'
          . $ui::icon('close', '!sp-w-3 !sp-h-3') . 'Eliminar</button>';
      }
      $html .= '</div></td></tr>';
    }
    return $html . '</tbody></table></div>';
  }

  private function stateBadge(string $scope, string $state): string
  {
    $classes = match ($scope) {
      'aprobadas' => '!sp-bg-emerald-50 !sp-border-emerald-200 !sp-text-emerald-700',
      'desaprobadas' => '!sp-bg-rose-50 !sp-border-rose-200 !sp-text-rose-700',
      default => '!sp-bg-amber-50 !sp-border-amber-200 !sp-text-amber-800',
    };
    return '<span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-px-2 !sp-py-1 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[9px] !sp-font-bold ' . $classes . '"><span class="!sp-w-1.5 !sp-h-1.5 !sp-rounded-full !sp-bg-current"></span>' . esc_html($state) . '</span>';
  }

  /** @param array<string,mixed> $pagination */
  public function renderPagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    $info = '<span class="!sp-text-[10px] !sp-text-service-muted">';
    if ($totalPages <= 1) {
      return $total > 0 ? $info . 'Mostrando ' . esc_html((string) $total) . ' orden(es)</span>' : '';
    }
    $button = static fn(int $target, bool $disabled, string $label): string => '<button type="button" class="' . UI::SECONDARY . '" data-maintenance-orders-page="' . esc_attr((string) $target) . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>';
    return '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-font-sans">'
      . $info . 'Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' · ' . esc_html((string) $total) . ' órdenes</span>'
      . '<div class="!sp-flex !sp-items-center !sp-gap-2">' . $button(max(1, $page - 1), $page <= 1, 'Anterior')
      . '<span class="!sp-bg-service-navy !sp-text-white !sp-text-[10px] !sp-px-3 !sp-py-2 !sp-rounded">' . esc_html((string) $page) . '</span>'
      . $button(min($totalPages, $page + 1), $page >= $totalPages, 'Siguiente') . '</div>'
      . '</div>';
  }
}
