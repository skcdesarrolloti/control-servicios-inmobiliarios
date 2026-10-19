<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Actividades contractuales › Contratos de arrendamiento, con el diseño Tailwind de los workspaces. */
final class LeaseContractsView
{
  private const MODAL_BUTTON = '!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-1.5 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50 hover:!sp-border-slate-300 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow !sp-whitespace-nowrap !sp-no-underline';

  /** @param array<string,array<string,string>> $bucketDefs */
  public function renderPanel(array $bucketDefs, bool $canSend): string
  {
    $ui = UI::class;
    $firstKey = (string) (array_key_first($bucketDefs) ?? 'todos');
    ob_start();
?>
    <div class="scm-lease-contracts !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-scm-contracts data-lease-contracts data-can-send="<?php echo $canSend ? '1' : '0'; ?>">
      <header class="<?php echo $ui::CARD; ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?php echo $ui::icon('document', '!sp-w-6 !sp-h-6'); ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Contratos de arrendamiento</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Partes del contrato · Documentación · Historiales · Envío de anexos a propietario, arrendatario y codeudores</p></div></div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-4">
          <div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" id="sca-kpi-count">0</strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Contratos en la vista</p></div>
          <button type="button" class="<?php echo $ui::SECONDARY; ?>" data-scm-open-due-settings><?php echo $ui::icon('calendar'); ?>Configurar vencimientos</button>
        </div>
      </header>
      <nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-w-fit !sp-max-w-full" role="tablist" aria-label="Estados del contrato">
        <?php foreach ($bucketDefs as $key => $def): $active = $key === $firstKey; ?>
          <button type="button" class="scm-contract-tab sp-group !sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow<?php echo $active ? ' active' : ''; ?>" data-contract-bucket="<?php echo esc_attr((string) $key); ?>" aria-pressed="<?php echo $active ? 'true' : 'false'; ?>"><?php echo $ui::icon((string) ($def['icon'] ?? 'document'), '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow'); ?><span><?php echo esc_html((string) ($def['label'] ?? $key)); ?></span></button>
        <?php endforeach; ?>
      </nav>
      <?php foreach ($bucketDefs as $key => $def): $active = $key === $firstKey; ?>
        <section class="scm-contract-panel !sp-space-y-5<?php echo $active ? ' active' : ''; ?>" data-contract-panel="<?php echo esc_attr((string) $key); ?>" data-scm-loaded="0">
          <?php echo $this->renderFilters((string) ($def['label'] ?? $key)); ?>
          <div class="<?php echo $ui::CARD; ?>">
            <div class="!sp-p-4 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2"><div><h3 class="!sp-m-0 !sp-font-bold !sp-text-sm"><?php echo esc_html((string) ($def['title'] ?? $def['label'] ?? $key)); ?></h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?php echo esc_html((string) ($def['description'] ?? 'Consulta el contrato, sus historiales y sus documentos sin salir del panel.')); ?></p></div><span class="!sp-text-[10px] !sp-text-service-muted">Contratos filtrados: <strong class="!sp-text-service-navy" data-contract-count>0</strong></span></div>
            <div data-contract-table><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando contratos...</p></div>
            <div class="!sp-p-4 !sp-bg-slate-50/50 empty:!sp-hidden" data-contract-pagination></div>
          </div>
        </section>
      <?php endforeach; ?>
      <div class="!sp-hidden !sp-fixed !sp-inset-0 !sp-z-[10050] !sp-items-start !sp-justify-center !sp-p-3 sm:!sp-p-6 !sp-bg-slate-900/60 !sp-overflow-y-auto" data-lease-modal role="dialog" aria-modal="true" aria-labelledby="scm-lease-modal-title">
        <section class="!sp-relative !sp-w-full !sp-max-w-5xl !sp-my-4 !sp-bg-service-background !sp-rounded-2xl !sp-shadow-2xl !sp-overflow-hidden">
          <header class="!sp-flex !sp-items-start !sp-justify-between !sp-gap-4 !sp-px-5 !sp-py-4 !sp-bg-white !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">
            <div><span class="!sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted" data-lease-modal-kicker>Contrato</span><h3 class="!sp-m-0 !sp-mt-1 !sp-text-base !sp-font-bold !sp-text-service-navy" id="scm-lease-modal-title" data-lease-modal-title>Contrato</h3></div>
            <button type="button" class="!sp-inline-flex !sp-items-center !sp-justify-center !sp-w-9 !sp-h-9 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-service-navy !sp-cursor-pointer hover:!sp-bg-slate-50 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-lease-modal-close aria-label="Cerrar"><?php echo $ui::icon('close'); ?></button>
          </header>
          <div class="!sp-p-4 sm:!sp-p-5" data-lease-modal-body></div>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function renderFilters(string $label): string
  {
    $ui = UI::class;
    $input = static fn(string $name, string $text, string $placeholder): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . esc_html($text) . '</span><input class="' . $ui::INPUT . '" name="' . $name . '" type="text" placeholder="' . esc_attr($placeholder) . '"></label>';
    ob_start();
?>
    <div class="<?php echo $ui::CARD; ?> !sp-p-5">
      <div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?php echo $ui::icon('filter'); ?>Filtros · <?php echo esc_html($label); ?></h3><span class="!sp-text-[10px] !sp-text-service-muted">Contrato, inmueble y partes</span></div>
      <form method="post" autocomplete="off" class="sca_form">
        <input type="hidden" name="sca_page" value="1">
        <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 xl:!sp-grid-cols-6 !sp-gap-4">
          <?php
          echo $input('sca_contrato', 'Contrato', 'Código contrato...');
          echo $input('sca_inmueble', 'Inmueble', 'SIMI o código web...');
          echo $input('sca_direccion', 'Dirección', 'Dirección...');
          echo $input('sca_propietario', 'Propietario', 'Nombre o documento...');
          echo $input('sca_arrendatario', 'Arrendatario', 'Nombre o documento...');
          ?>
          <label class="!sp-block"><span class="<?php echo $ui::LABEL; ?>">Registros por página</span><select class="<?php echo $ui::INPUT; ?>" name="sca_per_page"><option value="30">30</option><option value="60">60</option><option value="100">100</option></select></label>
        </div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5">
          <button class="<?php echo $ui::PRIMARY; ?>" type="submit"><?php echo $ui::icon('filter'); ?>Filtrar</button>
          <button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-contract-clear>Limpiar filtros</button>
          <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderTable(array $rows, bool $canSend): string
  {
    $ui = UI::class;
    if ($rows === []) {
      return '<div class="!sp-p-10 !sp-text-center"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-slate-100 !sp-text-service-muted">' . $ui::icon('document', '!sp-w-5 !sp-h-5') . '</span><p class="!sp-m-0 !sp-mt-3 !sp-text-xs !sp-font-semibold">No hay contratos con los filtros actuales</p></div>';
    }
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $td = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4 !sp-align-top';
    $muted = '!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted';
    $html = '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[1180px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['Contrato', 'Inmueble y dirección', 'Propietario', 'Arrendatario', 'Vigencia', 'Anexos', 'Acciones'] as $label) {
      $html .= '<th class="' . $th . '">' . $label . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $row = (array) $row;
      $v = static fn(string $key, string $fallback = '-'): string => trim((string) ($row[$key] ?? '')) !== '' ? trim((string) $row[$key]) : $fallback;
      $id = $v('_ID', '');
      $code = $v('contrato', $id);
      $sent = strtolower($v('se_envio', '')) === 'si';
      $codebtors = ctype_digit($v('num_codeudores', '')) ? (int) $row['num_codeudores'] : 0;
      $attrs = ' data-contract-id="' . esc_attr($id) . '" data-contract-code="' . esc_attr($code) . '" data-property-code="' . esc_attr($v('id_inmueble', $v('inmueble', ''))) . '"';
      $html .= '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . esc_html($code) . '</span>' . $this->stateBadge($v('estado', 'Sin estado')) . '</td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[240px]"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-700 !sp-font-semibold">SIMI ' . esc_html($v('inmueble')) . '</span><small class="' . $muted . '">Código web ' . esc_html($v('id_inmueble')) . '</small><p class="!sp-m-0 !sp-mt-1.5 !sp-leading-relaxed !sp-font-medium">' . esc_html($v('direccion')) . '</p></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px]"><strong class="!sp-block !sp-leading-relaxed">' . esc_html($v('propietario')) . '</strong><small class="' . $muted . '">' . esc_html($v('celular_propietario', '')) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-max-w-[200px]"><strong class="!sp-block !sp-leading-relaxed">' . esc_html($v('arrendatario')) . '</strong><small class="' . $muted . '">' . esc_html($v('celular_arrendatario', '')) . '</small>' . ($codebtors > 0 ? '<small class="' . $muted . '">' . $codebtors . ' codeudor(es)</small>' : '') . '</td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap"><span class="!sp-block">' . esc_html($this->date($row['inicio_contrato'] ?? null)) . '</span><small class="' . $muted . '">hasta ' . esc_html($this->date($row['fin_contrato'] ?? null)) . '</small></td>';
      $html .= '<td class="' . $td . ' !sp-whitespace-nowrap">' . ($sent
        ? '<span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-px-2 !sp-py-1 !sp-rounded-full !sp-border !sp-border-solid !sp-border-emerald-200 !sp-bg-emerald-50 !sp-text-emerald-700 !sp-text-[9px] !sp-font-bold">' . $ui::icon('check', '!sp-w-3 !sp-h-3') . 'Enviados</span>'
        : '<span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-px-2 !sp-py-1 !sp-rounded-full !sp-border !sp-border-solid !sp-border-amber-200 !sp-bg-amber-50 !sp-text-amber-800 !sp-text-[9px] !sp-font-bold">' . $ui::icon('clock', '!sp-w-3 !sp-h-3') . 'Pendientes</span>') . '</td>';
      $html .= '<td class="' . $td . '"><div class="!sp-grid !sp-grid-cols-2 !sp-gap-1.5 !sp-min-w-[300px]">';
      $html .= $this->action('documentos', 'Documentación', 'document', $attrs);
      $html .= $this->action('ficha', 'Ficha del inmueble', 'building', $attrs);
      $html .= $this->action('historial-inmueble', 'Historial inmueble', 'clock', $attrs);
      $html .= $this->action('historial-contrato', 'Historial contrato', 'copy', $attrs);
      $html .= '<button type="button" class="' . $ui::SECONDARY . ' !sp-text-[10px] !sp-justify-start" data-lease-action="caso"' . $attrs . '>' . $ui::icon('edit', '!sp-w-3 !sp-h-3') . 'Crear caso</button>';
      if ($canSend && strtolower($v('estado', '')) !== 'desistido') {
        $html .= '<button type="button" class="' . $ui::NAVY . ' !sp-justify-start" data-lease-action="envio"' . $attrs . '>' . $ui::icon('bolt', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . ($sent ? 'Reenviar anexos' : 'Enviar anexos') . '</button>';
      }
      if ($id !== '' && !in_array(strtolower($v('estado', '')), ['recibido', 'desistido'], true)) {
        $html .= '<button type="button" class="' . self::MODAL_BUTTON . ' !sp-justify-start" data-scm-mark-contract-received data-contract-context="contratos" data-contract-id="' . esc_attr($id) . '" data-contract-code="' . esc_attr($code) . '">' . $ui::icon('check', '!sp-w-3 !sp-h-3') . 'Marcar recibido</button>';
      }
      $html .= '</div></td></tr>';
    }
    return $html . '</tbody></table></div>';
  }

  private function action(string $key, string $label, string $icon, string $attrs): string
  {
    return '<button type="button" class="' . self::MODAL_BUTTON . ' !sp-justify-start" data-lease-action="' . $key . '"' . $attrs . '>' . UI::icon($icon, '!sp-w-3 !sp-h-3') . esc_html($label) . '</button>';
  }

  private function stateBadge(string $state): string
  {
    $normalized = strtolower($state);
    $classes = match (true) {
      $normalized === 'entregado' => '!sp-bg-emerald-50 !sp-border-emerald-200 !sp-text-emerald-700',
      in_array($normalized, ['por entregar', 'por recibir'], true) => '!sp-bg-amber-50 !sp-border-amber-200 !sp-text-amber-800',
      $normalized === 'desistido' => '!sp-bg-rose-50 !sp-border-rose-200 !sp-text-rose-700',
      default => '!sp-bg-slate-100 !sp-border-slate-200 !sp-text-slate-700',
    };
    return '<span class="!sp-flex !sp-w-fit !sp-mt-1.5 !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[9px] !sp-font-bold ' . $classes . '">' . esc_html($state) . '</span>';
  }

  /** @param array<string,mixed> $pagination */
  public function renderPagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    $info = '<span class="!sp-text-[10px] !sp-text-service-muted">';
    if ($totalPages <= 1) {
      return $total > 0 ? $info . 'Mostrando ' . esc_html((string) $total) . ' contrato(s)</span>' : '';
    }
    $button = static fn(int $target, bool $disabled, string $label): string => '<button type="button" class="scm-page-btn-contracts ' . UI::SECONDARY . '" data-page="' . esc_attr((string) $target) . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>';
    return '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-font-sans">'
      . $info . 'Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' · ' . esc_html((string) $total) . ' contratos</span>'
      . '<div class="!sp-flex !sp-items-center !sp-gap-2">' . $button(max(1, $page - 1), $page <= 1, 'Anterior')
      . '<span class="!sp-bg-service-navy !sp-text-white !sp-text-[10px] !sp-px-3 !sp-py-2 !sp-rounded">' . esc_html((string) $page) . '</span>'
      . $button(min($totalPages, $page + 1), $page >= $totalPages, 'Siguiente') . '</div></div>';
  }

  /** @param array<string,mixed> $c */
  public function renderDocumentation(array $c): string
  {
    $ui = UI::class;
    $v = static fn(string $key, string $fallback = ''): string => trim((string) ($c[$key] ?? '')) !== '' ? trim((string) $c[$key]) : $fallback;
    $html = '<div class="!sp-space-y-4">';
    $html .= '<div class="' . $ui::CARD . ' !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3"><div><p class="!sp-m-0 !sp-text-sm !sp-font-bold">Contrato de arrendamiento #' . esc_html($v('contrato', $v('_ID'))) . '</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Código inmueble web: ' . esc_html($v('id_inmueble', '-')) . ' · Inmueble SIMI: ' . esc_html($v('inmueble', '-')) . ' · ' . esc_html($v('direccion', '-')) . '</p></div>' . $this->stateBadge($v('estado', 'Sin estado')) . '</div>';

    $html .= '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4">';
    $html .= $this->party('Propietario', 'Titular', $v('propietario'), $v('documento_propietario'), $v('correo_propietario'), $v('celular_propietario'));
    $html .= $this->party('Arrendatario', 'Activo', $v('arrendatario'), $v('documento_arrendatario'), $v('correo_arrendatario'), $v('celular_arrendatario'));
    for ($i = 1; $i <= 4; $i++) {
      if ($v('codeudor_' . $i) !== '') {
        $html .= $this->party('Codeudor ' . $i, 'Codeudor', $v('codeudor_' . $i), $v('documento_codeudor_' . $i), $v('correo_codeudor_' . $i), $v('celular_codeudor_' . $i));
      }
    }
    $html .= '</div>';

    $metrics = [
      'Canon mensual' => $this->money($v('valor_canon')),
      'Administración' => $this->money($v('valor_administracion')),
      'Tasa admin' => $this->percent($v('tasa_administracion')),
      'Derechos inmob.' => $this->percent($v('derechos_inmobiliarios')),
      'Aseguradora' => $v('aseguradora', '-') . ($v('numero_solicitud') !== '' ? ' · Sol. ' . $v('numero_solicitud') : ''),
      'Vigencia' => $this->date($c['inicio_contrato'] ?? null) . ' – ' . $this->date($c['fin_contrato'] ?? null),
    ];
    $html .= '<div class="' . $ui::CARD . ' !sp-p-5"><h4 class="!sp-m-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">Condiciones económicas y aseguramiento</h4><div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-3">';
    foreach ($metrics as $label => $value) {
      $html .= '<div class="!sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200 !sp-bg-slate-50"><span class="!sp-block !sp-text-[9px] !sp-uppercase !sp-text-service-muted">' . esc_html($label) . '</span><strong class="!sp-block !sp-mt-1 !sp-text-xs">' . esc_html($value !== '' ? $value : '-') . '</strong></div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="' . $ui::CARD . ' !sp-p-5"><h4 class="!sp-m-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">Documentos y expedientes</h4><div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-2">';
    foreach ($this->documents($c) as $doc) {
      $html .= $doc['external']
        ? '<a class="' . self::MODAL_BUTTON . ' !sp-justify-between !sp-py-2.5" href="' . esc_attr($doc['url']) . '" target="_blank" rel="noopener noreferrer"><span>' . esc_html($doc['label']) . '</span>' . $ui::icon('download', '!sp-w-3 !sp-h-3 !sp-text-service-muted') . '</a>'
        : '<button type="button" class="' . self::MODAL_BUTTON . ' !sp-justify-between !sp-py-2.5" data-scm-open-iframe data-iframe-url="' . esc_attr($doc['url']) . '" data-iframe-title="' . esc_attr($doc['label']) . '"><span>' . esc_html($doc['label']) . '</span>' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-muted') . '</button>';
    }
    $html .= '</div></div>';

    if (strtolower($v('se_envio')) === 'si') {
      $id = (int) ($c['_ID'] ?? 0);
      $html .= '<div class="' . $ui::CARD . ' !sp-p-5"><h4 class="!sp-m-0 !sp-mb-1 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">Vista de anexos por actor</h4><p class="!sp-m-0 !sp-mb-3 !sp-text-[10px] !sp-text-service-muted">Lo que ve cada destinatario: el propietario no recibe el contrato de arrendamiento y los demás no reciben el contrato de mandato.</p><div class="!sp-flex !sp-flex-wrap !sp-gap-2">';
      $views = [['propietario', 0, 'Propietario'], ['arrendatario', 0, 'Arrendatario']];
      for ($i = 1; $i <= 4; $i++) {
        if ($v('codeudor_' . $i) !== '') {
          $views[] = ['codeudor', $i, 'Codeudor ' . $i];
        }
      }
      foreach ($views as [$role, $index, $label]) {
        $html .= '<button type="button" class="' . self::MODAL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr(LeaseAnnexesDocument::url($id, $role, $index)) . '" data-iframe-title="Anexos · ' . esc_attr($label) . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . esc_html($label) . '</button>';
      }
      $html .= '</div></div>';
    }
    return $html . '</div>';
  }

  /** @param array<string,mixed> $c @return array<int,array{label:string,url:string,external:bool}> */
  private function documents(array $c): array
  {
    $wp = 'https://sucasainmobiliaria.com.co/';
    $base = defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') . '/' : '';
    $links = [
      ['Acta de bienvenida propietario', 'acta_propietario', '', false],
      ['Acta de bienvenida arrendatario', 'acta_arrendatario', '', false],
      ['Acta de bienvenida codeudor 1', 'acta_codeudor_1', '', false],
      ['Acta de bienvenida codeudor 2', 'acta_codeudor_2', '', false],
      ['Acta de bienvenida codeudor 3', 'acta_codeudor_3', '', false],
      ['Acta de bienvenida codeudor 4', 'acta_codeudor_4', '', false],
      ['Contrato de arrendamiento', 'contrato_arrendamiento', '', false],
      ['Contrato de mandato', 'id_contrato_mandato', $wp . 'contrato-de-mandato/?numero=', false],
      ['Inventario del inmueble', 'id_inventario', $wp . 'inventario-inmueble/?numero=', false],
      ['Acta de entrega', 'id_acta_entrega', $wp . 'acta-de-entrega/?numero=', false],
      ['Revisión de entrega', 'id_revision_entrega', $wp . 'revision-de-entrega/?numero=', false],
      ['Acta de recibo', 'id_acta_recibo', $wp . 'acta-de-recibo/?numero=', false],
      ['Revisión de recibo', 'id_revision_recibo', $wp . 'revision-de-recibo/?numero=', false],
      ['Acta de revisión de desocupación', 'id_acta_revision_desocupacion', $wp . 'acta-de-revision-de-desocupacion/?numero=', false],
      ['Caso cliente', 'id_ticket_cliente', $wp . 'ticket/?id_ticket=', false],
      ['Hoja de cierre', 'id_cierre', $wp . 'hoja-de-cierre/?numero=', false],
      ['Estudio aseguradora', 'id_estudio_aseguradora', $wp . 'estudio-aseguradora/?id_estudio=', false],
      ['Revisión preventiva', 'id_revision_preventiva', $base . 'revision-preventiva.php?numero=', false],
      ['Revisión de servicios públicos', 'id_revision_servicios_publicos', $base . 'revision-servicios-publicos.php?numero=', false],
      ['Carta de aumento de canon', 'carta_aumento_canon', '', false],
      ['Carta de aumento de administración', 'carta_aumento_admin', '', false],
      ['Registro fotográfico', 'registro_fotografico', '', true],
      ['Inmueble en web', 'id_inmueble', $wp . 'inmuebles/inmueble-', true],
    ];
    $out = [];
    foreach ($links as [$label, $field, $prefix, $external]) {
      $value = trim(html_entity_decode((string) ($c[$field] ?? ''), ENT_QUOTES, 'UTF-8'));
      if ($value === '' || $value === '0') {
        continue;
      }
      $url = $prefix !== '' ? $prefix . rawurlencode($value) : $value;
      if (filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = ['label' => $label, 'url' => $url, 'external' => $external];
      }
    }
    return $out;
  }

  /** @param array<string,mixed> $c @param array<string,mixed> $p */
  public function renderProperty(array $c, array $p): string
  {
    $ui = UI::class;
    $pick = static function (array $row, array $keys): string {
      foreach ($keys as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '' && $value !== '0') {
          return $value;
        }
      }
      return '';
    };
    $groups = [
      'Identificación' => [
        'Código web' => $pick($p, ['codigo']) ?: $pick($c, ['id_inmueble']),
        'Inmueble SIMI' => $pick($c, ['inmueble']),
        'Estado' => $pick($p, ['estado']),
        'Tipo' => implode(' · ', array_filter([$pick($p, ['tipo_inmueble']) ?: $pick($c, ['tipo_inmueble']), $pick($p, ['tipo_negocio'])])),
        'Destinación' => $pick($p, ['destinacion']) ?: $pick($c, ['destinacion_inmueble']),
        'Matrícula inmobiliaria' => $pick($p, ['matricula_inmobiliaria']),
        'Referencia catastral' => $pick($p, ['referencia_catastral']),
      ],
      'Ubicación' => [
        'Dirección' => $pick($p, ['direccion', 'direccion_fisica']) ?: $pick($c, ['direccion']),
        'Barrio / ciudad' => implode(' / ', array_filter([$pick($p, ['barrio']) ?: $pick($c, ['barrio']), $pick($p, ['ciudad'])])),
        'Estrato' => $pick($p, ['estrato']) ?: $pick($c, ['estrato']),
        'Punto de referencia' => $pick($p, ['punto_referencia']),
      ],
      'Características' => [
        'Área construida / privada' => implode(' / ', array_filter([$pick($p, ['area_construida']), $pick($p, ['area_privada'])])),
        'Habitaciones / baños' => implode(' / ', array_filter([$pick($p, ['habitaciones']), $pick($p, ['banos'])])),
        'Parqueadero' => implode(' · ', array_filter([$pick($c, ['tipo_parqueadero']) ?: $pick($p, ['tipo_parqueadero']), $pick($c, ['numero_parqueadero']) ?: $pick($p, ['numero_parqueadero'])])),
        'Depósito' => $pick($c, ['depositos', 'tiene_deposito']) ?: $pick($p, ['depositos', 'deposito']),
        'Amoblado' => $pick($p, ['amoblado']),
      ],
      'Copropiedad' => [
        'Copropiedad' => $pick($c, ['copropiedad']) ?: $pick($p, ['copropiedad']),
        'Administrador' => $pick($c, ['administrador']),
        'Contacto' => implode(' · ', array_filter([$pick($c, ['celular_copropiedad']), $pick($c, ['correo_copropiedad'])])),
      ],
      'Servicios públicos' => [
        'Energía · medidor' => $pick($c, ['medidor_luz', 'luz']),
        'Agua · medidor' => $pick($c, ['medidor_agua', 'agua']),
        'Gas · medidor' => $pick($c, ['medidor_gas', 'gas']),
      ],
      'Valores' => [
        'Canon del contrato' => $this->money($pick($c, ['valor_canon'])),
        'Administración del contrato' => $this->money($pick($c, ['valor_administracion'])),
        'Precio de arriendo publicado' => $this->money($pick($p, ['precio_arriendo'])),
      ],
    ];
    $photo = $pick($p, ['foto_portada']);
    $html = '<div class="!sp-space-y-4">';
    if (filter_var($photo, FILTER_VALIDATE_URL)) {
      $html .= '<div class="' . $ui::CARD . '"><img src="' . esc_attr($photo) . '" alt="Foto del inmueble" class="!sp-block !sp-w-full !sp-max-h-72 !sp-object-cover"></div>';
    }
    $html .= '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4">';
    foreach ($groups as $title => $fields) {
      $fields = array_filter($fields, static fn(string $value): bool => $value !== '' && $value !== '-');
      if ($fields === []) {
        continue;
      }
      $html .= '<div class="' . $ui::CARD . ' !sp-p-5"><h4 class="!sp-m-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">' . esc_html($title) . '</h4><dl class="!sp-m-0 !sp-space-y-2">';
      foreach ($fields as $label => $value) {
        $html .= '<div class="!sp-grid !sp-grid-cols-[40%_1fr] !sp-gap-2 !sp-text-[10px]"><dt class="!sp-text-service-muted">' . esc_html($label) . '</dt><dd class="!sp-m-0 !sp-font-semibold">' . esc_html($value) . '</dd></div>';
      }
      $html .= '</dl></div>';
    }
    $html .= '</div>';
    $maps = $pick($p, ['ubicacion_google_maps']);
    $web = $pick($p, ['codigo']) ?: $pick($c, ['id_inmueble']);
    $html .= '<div class="!sp-flex !sp-flex-wrap !sp-gap-2">';
    if (filter_var($maps, FILTER_VALIDATE_URL)) {
      $html .= '<a class="' . self::MODAL_BUTTON . '" href="' . esc_attr($maps) . '" target="_blank" rel="noopener noreferrer">Ver ubicación en Google Maps</a>';
    }
    if ($web !== '') {
      $html .= '<a class="' . self::MODAL_BUTTON . '" href="https://sucasainmobiliaria.com.co/inmuebles/inmueble-' . esc_attr(rawurlencode($web)) . '" target="_blank" rel="noopener noreferrer">Ver inmueble en web</a>';
    }
    $description = $pick($p, ['descripcion']);
    $html .= '</div>' . ($description !== '' ? '<div class="' . $ui::CARD . ' !sp-p-5"><h4 class="!sp-m-0 !sp-mb-2 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">Descripción</h4><p class="!sp-m-0 !sp-text-[11px] !sp-leading-relaxed">' . nl2br(esc_html(strip_tags($description))) . '</p></div>' : '');
    if ($p === []) {
      $html .= '<p class="!sp-m-0 !sp-text-[10px] !sp-text-service-muted">El inmueble no tiene ficha en la base de inmuebles; se muestran los datos registrados en el contrato.</p>';
    }
    return $html . '</div>';
  }

  /** @param array<int,array<string,string>> $items */
  public function renderContractHistory(array $items): string
  {
    $ui = UI::class;
    if ($items === []) {
      return '<div class="' . $ui::CARD . ' !sp-p-10 !sp-text-center"><p class="!sp-m-0 !sp-text-xs !sp-font-semibold">Este contrato aún no tiene registros en su historial.</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Las notas al contrato y los envíos de anexos aparecerán aquí.</p></div>';
    }
    $html = '<ol class="!sp-m-0 !sp-p-0 !sp-list-none !sp-space-y-3">';
    foreach ($items as $item) {
      $files = '';
      foreach (['imagen' => 'Ver imagen', 'archivo' => 'Ver archivo'] as $field => $label) {
        $url = trim(html_entity_decode($item[$field], ENT_QUOTES, 'UTF-8'));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
          $files .= '<button type="button" class="' . self::MODAL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr($url) . '" data-iframe-title="' . esc_attr($label) . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . $label . '</button>';
        }
      }
      $html .= '<li class="' . $ui::CARD . ' !sp-p-4"><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2"><span class="!sp-inline-flex !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-bg-slate-100 !sp-text-[9px] !sp-font-bold">' . esc_html($item['tipo'] !== '' ? $item['tipo'] : 'Registro') . '</span><span class="!sp-text-[9px] !sp-text-service-muted">' . esc_html($item['fecha']) . ($item['funcionario'] !== '' ? ' · ' . esc_html($item['funcionario']) : '') . '</span></div>'
        . '<p class="!sp-m-0 !sp-mt-2 !sp-text-[11px] !sp-leading-relaxed">' . nl2br(esc_html(strip_tags(html_entity_decode($item['observacion'], ENT_QUOTES, 'UTF-8')))) . '</p>'
        . ($files !== '' ? '<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-3">' . $files . '</div>' : '') . '</li>';
    }
    return $html . '</ol>';
  }

  /** @param array<string,mixed> $c @param array<string,mixed> $branch */
  public function renderSendForm(array $c, array $branch): string
  {
    $ui = UI::class;
    $v = static fn(string $key): string => trim((string) ($c[$key] ?? ''));
    $dateValue = static fn(mixed $value): string => (int) $value > 0 ? date('Y-m-d', (int) $value) : '';
    $field = static fn(string $label, string $control, string $extra = ''): string => '<label class="!sp-block ' . $extra . '"><span class="' . $ui::LABEL . '">' . $label . '</span>' . $control . '</label>';
    $input = static fn(string $name, string $value, string $type = 'text', string $attrs = ''): string => '<input class="' . $ui::INPUT . '" name="' . $name . '" type="' . $type . '" value="' . esc_attr($value) . '"' . $attrs . '>';
    $person = function (string $key, string $title) use ($v, $field, $input, $ui): string {
      return '<fieldset class="' . $ui::CARD . ' !sp-p-5 !sp-m-0 !sp-min-w-0" data-lease-person="' . $key . '"><legend class="!sp-float-left !sp-w-full !sp-p-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">' . esc_html($title) . '</legend><div class="!sp-clear-both !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-3">'
        . $field('Nombre <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input($key, $v($key), 'text', ' required maxlength="160"'))
        . $field('Documento <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('documento_' . $key, $v('documento_' . $key), 'text', ' required maxlength="40"'))
        . $field('Correo <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('correo_' . $key, $v('correo_' . $key), 'email', ' required maxlength="160"'))
        . $field('Celular <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('celular_' . $key, $v('celular_' . $key), 'tel', ' required maxlength="20" inputmode="tel"'))
        . '</div></fieldset>';
    };
    $codebtors = $v('num_codeudores');
    $codebtors = in_array($codebtors, ['1', '2', '3', '4'], true) ? $codebtors : 'Ninguno';
    $options = '';
    foreach (['Ninguno', '1', '2', '3', '4'] as $option) {
      $options .= '<option value="' . $option . '"' . ($option === $codebtors ? ' selected' : '') . '>' . $option . '</option>';
    }
    $leaseUrl = trim(html_entity_decode($v('contrato_arrendamiento'), ENT_QUOTES, 'UTF-8'));
    $sent = strtolower($v('se_envio')) === 'si';

    $html = '<form class="!sp-space-y-4" data-lease-send-form enctype="multipart/form-data" autocomplete="off" novalidate>'
      . '<input type="hidden" name="contract_id" value="' . esc_attr($v('_ID')) . '">'
      . ($sent ? '<p class="!sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-amber-200 !sp-bg-amber-50 !sp-text-[11px] !sp-text-amber-900">Los anexos de este contrato ya se enviaron. Si continúas, se generan actas nuevas y se notifica otra vez a todos los actores.</p>' : '')
      . '<fieldset class="' . $ui::CARD . ' !sp-p-5 !sp-m-0 !sp-min-w-0"><legend class="!sp-float-left !sp-w-full !sp-p-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide">Contrato</legend><div class="!sp-clear-both !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-3">'
      . $field('# Contrato', $input('contrato', $v('contrato'), 'text', ' readonly'))
      . $field('Dirección', $input('direccion', $v('direccion'), 'text', ' readonly'), 'lg:!sp-col-span-2')
      . $field('Fecha de inicio <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('inicio_contrato', $dateValue($c['inicio_contrato'] ?? null) ?: $dateValue($c['fecha_entrega'] ?? null), 'date', ' required'))
      . $field('Fecha final <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('fin_contrato', $dateValue($c['fin_contrato'] ?? null), 'date', ' required'))
      . $field('Registro fotográfico <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('registro_fotografico', $v('registro_fotografico'), 'url', ' required placeholder="https://drive.google.com/..."'))
      . $field('ID acta de entrega <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('id_acta_entrega', $v('id_acta_entrega'), 'text', ' required'))
      . $field('ID del inventario <em class="!sp-text-rose-600 !sp-not-italic">*</em>', $input('id_inventario', $v('id_inventario'), 'text', ' required'))
      . $field('Contrato de arrendamiento (PDF o Word, máx. 10 MB)' . ($leaseUrl === '' ? ' <em class="!sp-text-rose-600 !sp-not-italic">*</em>' : ''), '<input class="' . $ui::INPUT . ' !sp-h-auto !sp-py-2" name="contrato_arrendamiento" type="file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"' . ($leaseUrl === '' ? ' required' : '') . '>'
        . ($leaseUrl !== '' ? '<button type="button" class="' . self::MODAL_BUTTON . ' !sp-mt-2" data-scm-open-iframe data-iframe-url="' . esc_attr($leaseUrl) . '" data-iframe-title="Contrato de arrendamiento">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . 'Ver contrato cargado</button><small class="!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted">Adjunta un archivo solo si quieres reemplazarlo.</small>' : ''), 'sm:!sp-col-span-2 lg:!sp-col-span-3')
      . '</div></fieldset>'
      . '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4">' . $person('propietario', 'Propietario') . $person('arrendatario', 'Arrendatario') . '</div>'
      . '<div class="' . $ui::CARD . ' !sp-p-5">' . $field('Número de codeudores <em class="!sp-text-rose-600 !sp-not-italic">*</em>', '<select class="' . $ui::INPUT . ' sm:!sp-max-w-xs" name="num_codeudores" data-lease-codebtors>' . $options . '</select>') . '</div>'
      . '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4">';
    for ($i = 1; $i <= 4; $i++) {
      $html .= $person('codeudor_' . $i, 'Codeudor ' . $i);
    }
    $contractual = trim((string) ($branch['nombre_contractual'] ?? ''));
    $html .= '</div>'
      . '<div class="' . $ui::CARD . ' !sp-p-5 !sp-text-[10px] !sp-leading-relaxed !sp-text-service-muted"><strong class="!sp-block !sp-mb-1 !sp-text-service-navy">Qué se envía</strong>'
      . 'Se genera un acta de bienvenida por cada actor. El propietario recibe su acta, el registro fotográfico, el inventario, el acta de entrega y el contrato de mandato. El arrendatario y los codeudores reciben su acta y el contrato de arrendamiento, sin el contrato de mandato. Todos reciben correo y WhatsApp con el enlace a sus anexos.'
      . ($contractual !== '' ? ' Funcionario contractual que aparece en las actas: <strong class="!sp-text-service-navy">' . esc_html($contractual) . '</strong>.' : '') . '</div>'
      . '<p class="!sp-hidden !sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-rose-200 !sp-bg-rose-50 !sp-text-[11px] !sp-text-rose-700" data-lease-send-error role="alert"></p>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2"><button type="button" class="' . $ui::SECONDARY . '" data-lease-modal-close>Cancelar</button><button type="submit" class="' . $ui::PRIMARY . '" data-lease-send-submit>' . $ui::icon('bolt') . 'Generar actas y enviar anexos</button></div>'
      . '</form>';
    return $html;
  }

  /** @param array<string,mixed> $result */
  public function renderSendResult(array $result): string
  {
    $ui = UI::class;
    $queued = (array) ($result['queued'] ?? []);
    $html = '<div class="!sp-space-y-4"><div class="' . $ui::CARD . ' !sp-p-6 !sp-text-center"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-emerald-50 !sp-text-emerald-700">' . $ui::icon('check', '!sp-w-6 !sp-h-6') . '</span><p class="!sp-m-0 !sp-mt-3 !sp-text-sm !sp-font-bold">Anexos enviados</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[11px] !sp-text-service-muted">En cola: ' . (int) ($queued['email'] ?? 0) . ' correo(s), ' . (int) ($queued['whatsapp'] ?? 0) . ' WhatsApp y ' . (int) ($queued['internal'] ?? 0) . ' aviso(s) internos.</p></div><div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-3">';
    foreach ((array) ($result['actors'] ?? []) as $actor) {
      $html .= '<div class="' . $ui::CARD . ' !sp-p-4"><span class="!sp-text-[9px] !sp-font-bold !sp-uppercase !sp-text-service-muted">' . esc_html((string) $actor['label']) . '</span><strong class="!sp-block !sp-mt-1 !sp-text-xs">' . esc_html((string) $actor['name']) . '</strong><div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-3">'
        . '<button type="button" class="' . self::MODAL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr((string) $actor['act_url']) . '" data-iframe-title="Acta de bienvenida">' . $ui::icon('document', '!sp-w-3 !sp-h-3') . 'Ver acta</button>'
        . '<button type="button" class="' . self::MODAL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr((string) $actor['annexes_url']) . '" data-iframe-title="Anexos del contrato">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . 'Ver anexos</button></div></div>';
    }
    return $html . '</div><div class="!sp-flex !sp-justify-end"><button type="button" class="' . $ui::NAVY . '" data-lease-modal-close>Cerrar</button></div></div>';
  }

  private function party(string $role, string $badge, string $name, string $document, string $email, string $phone): string
  {
    return '<div class="' . UI::CARD . ' !sp-p-4"><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-pb-2 !sp-mb-2 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><span class="!sp-text-[9px] !sp-font-bold !sp-uppercase !sp-text-service-muted">' . esc_html($role) . '</span><span class="!sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-border-blue-200 !sp-bg-blue-50 !sp-text-blue-700 !sp-text-[9px] !sp-font-bold">' . esc_html($badge) . '</span></div>'
      . '<strong class="!sp-block !sp-text-xs">' . esc_html($name !== '' ? $name : '-') . '</strong>'
      . '<small class="!sp-block !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Documento: ' . esc_html($document !== '' ? $document : '-') . '</small>'
      . '<small class="!sp-block !sp-text-[10px] !sp-text-service-muted">Correo: ' . esc_html($email !== '' ? $email : '-') . '</small>'
      . '<small class="!sp-block !sp-text-[10px] !sp-text-service-muted">Tel: ' . esc_html($phone !== '' ? $phone : '-') . '</small></div>';
  }

  private function date(mixed $value): string
  {
    $ts = is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: 0);
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }

  private function money(string $value): string
  {
    $digits = preg_replace('/\D+/', '', preg_replace('/[.,]\d{1,2}$/', '', trim($value)) ?? '') ?? '';
    return $digits !== '' ? '$' . number_format((int) $digits, 0, ',', '.') : '';
  }

  private function percent(string $value): string
  {
    $value = trim(str_replace('%', '', $value));
    return $value !== '' ? $value . '%' : '';
  }
}
