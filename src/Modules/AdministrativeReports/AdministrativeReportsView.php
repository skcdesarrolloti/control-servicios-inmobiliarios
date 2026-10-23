<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeReports;

use SCM\Modules\Pending\PublicServicesUi as UI;

/**
 * Actividades administrativas › Reportes administrativos. Pestañas según permisos:
 * Por aprobar (pre reportes), Mis reportes (cuenta de cobro), Consolidado de pagos y Cuentas de cobro.
 * Las listas llegan por AJAX como fragmentos HTML; admin-administrative-reports.js maneja la interacción.
 */
final class AdministrativeReportsView
{
  private const TH = '!sp-text-left !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-px-3 !sp-py-3 !sp-text-service-muted !sp-whitespace-nowrap !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
  private const TD = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-3 !sp-align-top';
  private const TR = '!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
  private const BADGE = '!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-font-semibold !sp-text-[11px] !sp-whitespace-nowrap ';

  public const TABS = [
    'pendientes' => 'Por aprobar',
    'mis_reportes' => 'Mis reportes',
    'consolidado' => 'Consolidado de pagos',
    'cuentas' => 'Cuentas de cobro',
  ];

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  /**
   * @param array<int,string> $tabs Pestañas permitidas, en orden.
   * @param array<int,array<string,string>> $funcionarios
   * @param array<string,bool> $can manage (crear/editar reportes), payments, accounts
   */
  public function panel(array $tabs, string $pendingHtml, array $funcionarios, string $employeeId, array $can): string
  {
    if (!$tabs) {
      return '<div class="' . UI::CARD . ' !sp-font-sans"><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Tu cargo no tiene pestañas habilitadas en Reportes administrativos. Pide a un administrador que las active en Permisos.</p></div>';
    }
    $first = $tabs[0];
    $html = '<div class="!sp-flex !sp-flex-col !sp-gap-4 !sp-font-sans !sp-text-service-navy" data-scm-admin-reports data-employee-id="' . self::e($employeeId) . '">'
      . '<div class="!sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-end !sp-justify-between !sp-gap-3 !sp-pb-3 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">'
      . '<div><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Actividades administrativas</p>'
      . '<h2 class="!sp-m-0 !sp-text-xl !sp-font-bold">Reportes Administrativos</h2>'
      . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Aprobación de reportes, cuentas de cobro de los funcionarios y control de pagos.</p></div>'
      . (!empty($can['manage']) ? $this->newReportActions($funcionarios, $employeeId) : '') . '</div>'
      . '<div class="!sp-flex !sp-items-center !sp-gap-2 !sp-overflow-x-auto !sp-pb-1" role="tablist" aria-label="Reportes administrativos">';
    foreach ($tabs as $tab) {
      $active = $tab === $first;
      $html .= '<button type="button" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" data-ar-tab="' . self::e($tab) . '" class="' . self::tabClass($active) . '">' . self::e(self::TABS[$tab]) . '</button>';
    }
    $html .= '</div>';
    foreach ($tabs as $tab) {
      $html .= '<section data-ar-panel="' . self::e($tab) . '"' . ($tab === $first ? '' : ' hidden') . '>' . match ($tab) {
        'pendientes' => $pendingHtml,
        'mis_reportes' => $this->ownShell($funcionarios, $employeeId, $can),
        'consolidado' => $this->consolidatedShell($funcionarios),
        'cuentas' => $this->accountsShell(),
        default => '',
      } . '</section>';
    }
    return $html . '</div>';
  }

  public static function tabClass(bool $active): string
  {
    return '!sp-inline-flex !sp-items-center !sp-gap-2 !sp-px-4 !sp-py-2 !sp-rounded-full !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer !sp-whitespace-nowrap !sp-border !sp-border-solid '
      . ($active ? '!sp-bg-service-navy !sp-text-white !sp-border-service-navy' : '!sp-bg-white !sp-text-service-navy !sp-border-slate-200 hover:!sp-bg-slate-50');
  }

  /** @param array<string,string> $fields name => [label, placeholder] */
  private function filterForm(string $key, string $title, array $fields, string $extra = '', string $hidden = ''): string
  {
    $html = '<form class="' . UI::CARD . ' !sp-p-4" data-ar-filters="' . self::e($key) . '" autocomplete="off">' . $hidden
      . '<h3 class="!sp-m-0 !sp-mb-3 !sp-text-sm !sp-font-bold">' . self::e($title) . '</h3>'
      . '<div class="!sp-grid !sp-grid-cols-2 md:!sp-grid-cols-4 xl:!sp-grid-cols-7 !sp-gap-3 !sp-items-end">' . $extra;
    foreach ($fields as $name => [$label, $placeholder, $type]) {
      $html .= '<label class="!sp-block"><span class="' . UI::LABEL . '">' . self::e($label) . '</span><input class="' . UI::INPUT . '" type="' . self::e($type) . '" name="' . self::e($name) . '" placeholder="' . self::e($placeholder) . '"></label>';
    }
    return $html . '<div><button type="reset" class="' . UI::SECONDARY . ' !sp-h-10">Limpiar</button></div></div></form>';
  }

  /** @param array<int,array<string,string>> $funcionarios */
  private function staffSelect(array $funcionarios, string $name, string $label, string $selected, string $emptyLabel): string
  {
    $html = '<label class="!sp-block"><span class="' . UI::LABEL . '">' . self::e($label) . '</span><select class="' . UI::INPUT . '" name="' . self::e($name) . '">'
      . ($emptyLabel !== '' ? '<option value="">' . self::e($emptyLabel) . '</option>' : '');
    foreach ($funcionarios as $funcionario) {
      $id = (string) ($funcionario['id'] ?? '');
      if ($id === '') continue;
      $html .= '<option value="' . self::e($id) . '"' . ($id === $selected ? ' selected' : '') . '>' . self::e($funcionario['label'] ?? $id) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param array<int,array<string,string>> $funcionarios @param array<string,bool> $can */
  private function ownShell(array $funcionarios, string $employeeId, array $can): string
  {
    $extra = !empty($can['manage']) ? $this->staffSelect($funcionarios, 'employee', 'Funcionario', $employeeId, '') : '';
    $html = $this->filterForm('own', 'Mis reportes', [
      'consecutivo' => ['Consecutivo', 'Ej: 1240', 'text'],
      'tipo' => ['Tipo', 'Ej: Entrega', 'text'],
      'contrato' => ['Contrato', 'Ej: 791', 'text'],
      'inmueble' => ['Inmueble', 'Ej: 10635', 'text'],
      'arrendatario' => ['Arrendatario', 'Nombre', 'text'],
      'ticket' => ['Caso', 'Ej: 9665', 'text'],
    ], $extra, '<input type="hidden" name="state" value="no_exportados">');
    return $html . '<div class="!sp-mt-4" data-ar-results="own">' . $this->loading() . '</div>';
  }

  /**
   * Botones del encabezado para crear reportes. Abren un popup que primero pide el caso (y el funcionario
   * del reporte); la plantilla lleva las opciones de funcionario para ese primer paso.
   * @param array<int,array<string,string>> $funcionarios
   */
  private function newReportActions(array $funcionarios, string $employeeId): string
  {
    $options = '';
    foreach ($funcionarios as $funcionario) {
      $id = (string) ($funcionario['id'] ?? '');
      if ($id !== '') $options .= '<option value="' . self::e($id) . '"' . ($id === $employeeId ? ' selected' : '') . '>' . self::e($funcionario['label'] ?? $id) . '</option>';
    }
    return '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2">'
      . '<button type="button" class="' . UI::SECONDARY . '" data-ar-open-report="transporte">' . UI::icon('building') . 'Añadir transporte por visita</button>'
      . '<button type="button" class="' . UI::NAVY . ' !sp-text-xs !sp-px-4 !sp-py-2.5" data-ar-open-report="proceso">' . UI::icon('document') . 'Crear reporte administrativo</button>'
      . '<template data-ar-ticket-step-template>' . self::ticketStep($options) . '</template></div>';
  }

  /** Primer paso del popup: número del caso y funcionario a cuyo nombre queda el reporte. */
  private static function ticketStep(string $staffOptions): string
  {
    return '<form class="!sp-p-4 sm:!sp-p-6 !sp-flex !sp-flex-col !sp-gap-4" data-ar-ticket-step autocomplete="off">'
      . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Escribe el número del caso para cargar sus datos (contrato, inmueble, solicitud y arrendatario).</p>'
      . '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-3">'
      . '<label class="!sp-block"><span class="' . UI::LABEL . '">Número del caso</span><input class="' . UI::INPUT . '" name="id_ticket" inputmode="numeric" required placeholder="Ej: 9665" data-ar-ticket-input></label>'
      . ($staffOptions !== '' ? '<label class="!sp-block"><span class="' . UI::LABEL . '">Funcionario del reporte</span><select class="' . UI::INPUT . '" name="employee">' . $staffOptions . '</select></label>' : '')
      . '</div><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-3"><p class="!sp-m-0 !sp-text-[11px] !sp-font-semibold !sp-text-red-700" data-ar-form-status></p>'
      . '<div class="!sp-flex !sp-gap-2"><button type="button" class="' . UI::SECONDARY . '" data-ar-modal-close>Cancelar</button><button type="submit" class="' . UI::PRIMARY . '">Continuar</button></div></div></form>';
  }

  /** @param array<int,array<string,string>> $funcionarios */
  private function consolidatedShell(array $funcionarios): string
  {
    return $this->filterForm('consolidated', 'Consolidado de pagos', [
      'consecutivo' => ['Consecutivo', 'Ej: 86315', 'text'],
      'ticket' => ['Caso', 'Ej: 7054', 'text'],
      'contrato' => ['Contrato', 'Ej: 662', 'text'],
      'solicitud' => ['Solicitud', 'Ej: 10234', 'text'],
      'inmueble' => ['Inmueble', 'Ej: 10635', 'text'],
    ], $this->staffSelect($funcionarios, 'funcionario', 'Funcionario', '', 'Todos'), '<input type="hidden" name="state" value="no_pagados"><input type="hidden" name="page" value="1">')
      . '<div class="!sp-mt-4" data-ar-results="consolidated">' . $this->loading() . '</div>';
  }

  private function accountsShell(): string
  {
    $estado = '<label class="!sp-block"><span class="' . UI::LABEL . '">Estado</span><select class="' . UI::INPUT . '" name="estado"><option value="">Todas</option><option value="pendiente">No pagadas</option><option value="pagada">Pagadas</option></select></label>';
    return $this->filterForm('accounts', 'Cuentas de cobro', [
      'numero' => ['N° cuenta', 'Ej: CC-000120', 'text'],
      'funcionario' => ['Funcionario', 'Nombre', 'text'],
      'desde' => ['Desde', '', 'date'],
      'hasta' => ['Hasta', '', 'date'],
    ], $estado)
      . '<div class="!sp-mt-4" data-ar-results="accounts">' . $this->loading() . '</div>';
  }

  private function loading(string $text = 'Abre esta pestaña para cargar la información.'): string
  {
    return '<div class="' . UI::CARD . '"><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">' . self::e($text) . '</p></div>';
  }

  // Fragmentos ----------------------------------------------------------------

  /** @param array<string,int|string> $values */
  private function kpis(array $values): string
  {
    $html = '<div class="!sp-grid !sp-grid-cols-2 md:!sp-grid-cols-5 !sp-gap-3 !sp-mb-4">';
    foreach ($values as $label => $value) {
      [$text, $key] = is_array($value) ? $value : [$value, ''];
      $html .= '<div class="' . UI::CARD . ' !sp-p-3"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</p>'
        . '<p class="!sp-m-0 !sp-mt-1 !sp-text-lg !sp-font-bold"' . ($key !== '' ? ' data-ar-kpi="' . self::e($key) . '"' : '') . '>' . self::e($text) . '</p></div>';
    }
    return $html . '</div>';
  }

  /** @param array<string,array{0:string,1:int}> $states */
  private function states(array $states, string $active): string
  {
    $html = '<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mb-3" role="tablist">';
    foreach ($states as $key => [$label, $count]) {
      $isActive = $key === $active;
      $html .= '<button type="button" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '" data-ar-state="' . self::e($key) . '" class="' . self::tabClass($isActive) . '">'
        . self::e($label) . ' <span class="!sp-px-2 !sp-rounded-full !sp-text-[10px] ' . ($isActive ? '!sp-bg-service-yellow !sp-text-service-navy' : '!sp-bg-slate-100') . '">' . (int) $count . '</span></button>';
    }
    return $html . '</div>';
  }

  private function empty(string $text): string
  {
    return '<tr><td colspan="20" class="' . self::TD . ' !sp-text-center !sp-text-service-muted !sp-py-8">' . self::e($text) . '</td></tr>';
  }

  /** Caso: se abre en el visor del panel, sin salir de la app. */
  private function ticketCell(string $ticket): string
  {
    if ($ticket === '') return '-';
    return '<button type="button" class="' . self::BADGE . '!sp-bg-blue-50 !sp-text-service-blue !sp-border !sp-border-solid !sp-border-blue-200 !sp-cursor-pointer" data-ar-ticket="' . self::e($ticket) . '">#' . self::e($ticket) . '</button>';
  }

  /** Evidencia guardada con HTML (enlaces a reportes públicos): texto plano y enlaces abiertos en el visor del panel. */
  public static function description(string $html): string
  {
    $links = [];
    $text = preg_replace_callback('/<a\s[^>]*href\s*=\s*([\'"])(https?:\/\/[^\'"]+)\1[^>]*>(.*?)<\/a>/is', static function (array $m) use (&$links): string {
      $links[] = '<button type="button" class="!sp-p-0 !sp-bg-transparent !sp-border-0 !sp-text-service-blue !sp-underline !sp-cursor-pointer !sp-text-xs" data-ar-open-url="' . self::e(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) . '">'
        . self::e(trim(strip_tags($m[3])) ?: 'Ver evidencia') . '</button>';
      return ' [[link' . (count($links) - 1) . ']] ';
    }, $html) ?? $html;
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));
    $out = self::e($text);
    foreach ($links as $index => $link) $out = str_replace('[[link' . $index . ']]', $link, $out);
    return $out !== '' ? $out : '-';
  }

  /**
   * @param array<string,mixed> $data AdministrativeReportsService::ownReports()
   * @param array<int,array<string,mixed>> $accounts Cuentas propias (estado "cuentas").
   */
  public function ownResults(array $data, string $state, bool $canEdit, array $accounts, bool $isSelf): string
  {
    $s = $data['summary'];
    $counts = $data['counts'];
    $html = $this->kpis([
      'Reportes' => $s['count'],
      'Total' => self::money($s['total']),
      'Servicios (' . $s['servicio_count'] . ')' => self::money($s['servicio']),
      'Transportes (' . $s['transporte_count'] . ')' => self::money($s['transporte']),
      'Seleccionados' => ['0 · $0', 'selected'],
    ]) . $this->states([
      'no_exportados' => ['No exportados', $counts['no_exportados']],
      'en_cobro' => ['En cuenta de cobro', $counts['en_cobro']],
      'pagados' => ['Pagados', $counts['pagados']],
      'cuentas' => [$isSelf ? 'Mis cuentas de cobro' : 'Cuentas de cobro', count($accounts)],
    ], $state);

    if ($state === 'cuentas') return $html . $this->accountsTable($accounts, false);

    $selectable = $state === 'no_exportados';
    $editable = $selectable && $canEdit;
    if ($selectable) {
      $html .= '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mb-3"><button type="button" class="' . UI::PRIMARY . '" data-ar-create-account disabled>' . UI::icon('document') . 'Crear cuenta de cobro</button>'
        . '<span class="!sp-text-[11px] !sp-text-service-muted">Selecciona los reportes: se registra la cuenta, se genera el PDF y se descarga el Excel.</span></div>';
    }
    $html .= '<div class="' . UI::CARD . '"><div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-xs !sp-min-w-[1100px]" data-ar-table><thead class="!sp-bg-slate-50"><tr>'
      . ($selectable ? '<th class="' . self::TH . '"><input type="checkbox" data-ar-check-all aria-label="Seleccionar todos"></th>' : '');
    foreach (['Consecutivo', 'Fecha', 'Tipo', 'Servicio', 'Transporte', 'Total', 'Contrato', 'Inmueble', 'Arrendatario', 'Caso', 'Observación'] as $label) $html .= '<th class="' . self::TH . '">' . $label . '</th>';
    $html .= ($editable ? '<th class="' . self::TH . '">Valores</th>' : '') . '</tr></thead><tbody>';
    if (!$data['items']) $html .= $this->empty('No hay reportes en este estado con los filtros actuales.');
    foreach ($data['items'] as $item) {
      $html .= '<tr class="' . self::TR . '" data-ar-row="' . (int) $item['id'] . '" data-servicio="' . (int) $item['servicio'] . '" data-transporte="' . (int) $item['transporte'] . '" data-total="' . (int) $item['total'] . '">'
        . ($selectable ? '<td class="' . self::TD . '"><input type="checkbox" data-ar-check value="' . (int) $item['id'] . '" aria-label="Seleccionar reporte ' . (int) $item['id'] . '"></td>' : '')
        . '<td class="' . self::TD . ' !sp-font-bold">#' . (int) $item['id'] . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . ($item['fecha'] > 0 ? date('d/m/Y', (int) $item['fecha']) : '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['categoria'] ?: '-') . '</td>';
      if ($editable) {
        $input = '!sp-w-24 !sp-h-8 !sp-px-2 !sp-border !sp-border-solid !sp-border-slate-300 !sp-rounded !sp-text-[11px] !sp-text-right';
        $html .= '<td class="' . self::TD . '"><input class="' . $input . '" type="text" inputmode="numeric" data-ar-money="servicio" value="' . number_format((int) $item['servicio'], 0, ',', '.') . '" aria-label="Servicio del reporte ' . (int) $item['id'] . '"></td>'
          . '<td class="' . self::TD . '"><input class="' . $input . '" type="text" inputmode="numeric" data-ar-money="transporte" value="' . number_format((int) $item['transporte'], 0, ',', '.') . '" aria-label="Transporte del reporte ' . (int) $item['id'] . '"></td>';
      } else {
        $html .= '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $item['servicio']) . '</td><td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $item['transporte']) . '</td>';
      }
      $html .= '<td class="' . self::TD . ' !sp-whitespace-nowrap !sp-font-bold" data-ar-total>' . self::money((int) $item['total']) . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['contrato'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['inmueble'] ?: '-') . '</td>'
        . '<td class="' . self::TD . ' !sp-max-w-[180px]">' . self::e($item['arrendatario'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . $this->ticketCell((string) $item['id_ticket']) . '</td>'
        . '<td class="' . self::TD . ' !sp-max-w-[320px] !sp-leading-relaxed">' . self::description((string) $item['descripcion']) . '</td>'
        . ($editable ? '<td class="' . self::TD . '"><button type="button" class="' . UI::SECONDARY . '" data-ar-save-values disabled>Guardar</button></td>' : '')
        . '</tr>';
    }
    return $html . '</tbody></table></div></div>';
  }

  /** @param array<string,mixed> $data AdministrativeReportsService::consolidated() */
  public function consolidatedResults(array $data, string $state, int $backfillPending): string
  {
    $s = $data['summary'];
    $html = $this->kpis([
      'Reportes' => $s['count'],
      'Total' => self::money($s['total']),
      'Servicios (' . $s['servicio_count'] . ')' => self::money($s['servicio']),
      'Transportes (' . $s['transporte_count'] . ')' => self::money($s['transporte']),
      'Seleccionados' => ['0 · $0', 'selected'],
    ]) . $this->states(['no_pagados' => ['No pagados', $data['counts']['no_pagados']], 'pagados' => ['Pagados', $data['counts']['pagados']]], $state);

    $selectable = $state === 'no_pagados';
    $html .= '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2 !sp-mb-3">'
      . ($selectable ? '<button type="button" class="' . UI::PRIMARY . '" data-ar-pay-reports disabled>' . UI::icon('check') . 'Marcar seleccionados como pagados</button>' : '')
      . '<button type="button" class="' . UI::SECONDARY . '" data-ar-backfill data-pending="' . $backfillPending . '">' . UI::icon('refresh') . 'Completar contrato/solicitud/arrendatario' . ($backfillPending > 0 ? ' (' . $backfillPending . ')' : '') . '</button></div>';

    $html .= '<div class="' . UI::CARD . '"><div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-xs !sp-min-w-[1200px]" data-ar-table><thead class="!sp-bg-slate-50"><tr>'
      . ($selectable ? '<th class="' . self::TH . '"><input type="checkbox" data-ar-check-all aria-label="Seleccionar todos"></th>' : '');
    foreach (['Consecutivo', 'Fecha', 'Categoría', 'Caso', 'Contrato', 'Solicitud', 'Funcionario', 'Inmueble', 'Arrendatario', 'Servicio', 'Transporte', 'Total', 'Acciones'] as $label) $html .= '<th class="' . self::TH . '">' . $label . '</th>';
    $html .= '</tr></thead><tbody>';
    if (!$data['items']) $html .= $this->empty('No hay reportes con los filtros actuales.');
    foreach ($data['items'] as $item) {
      $html .= '<tr class="' . self::TR . '" data-ar-row="' . (int) $item['id'] . '" data-servicio="' . (int) $item['servicio'] . '" data-transporte="' . (int) $item['transporte'] . '" data-total="' . (int) $item['total'] . '">'
        . ($selectable ? '<td class="' . self::TD . '"><input type="checkbox" data-ar-check value="' . (int) $item['id'] . '" aria-label="Seleccionar reporte ' . (int) $item['id'] . '"></td>' : '')
        . '<td class="' . self::TD . ' !sp-font-bold">#' . (int) $item['id'] . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . ($item['fecha'] > 0 ? date('d/m/Y', (int) $item['fecha']) : '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['categoria'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . $this->ticketCell((string) $item['id_ticket']) . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['contrato'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['solicitud'] ?: '-') . '</td>'
        . '<td class="' . self::TD . ' !sp-max-w-[160px]">' . self::e($item['funcionario'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['inmueble'] ?: '-') . '</td>'
        . '<td class="' . self::TD . ' !sp-max-w-[180px]">' . self::e($item['arrendatario'] ?: '-') . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $item['servicio']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $item['transporte']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap !sp-font-bold">' . self::money((int) $item['total']) . '</td>'
        . '<td class="' . self::TD . '">' . ($selectable
          ? '<button type="button" class="' . UI::NAVY . '" data-ar-pay-report="' . (int) $item['id'] . '">Marcar pagado</button>'
          : '<span class="' . self::BADGE . '!sp-bg-emerald-50 !sp-text-emerald-700">Pagado</span>') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    if ((int) $data['pages'] > 1) {
      $page = (int) $data['page'];
      $html .= '<div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 !sp-py-3 !sp-border-x-0 !sp-border-b-0 !sp-border-t !sp-border-solid !sp-border-slate-200">'
        . '<button type="button" class="' . UI::SECONDARY . '" data-ar-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button>'
        . '<span class="!sp-text-[11px] !sp-text-service-muted">Página ' . $page . ' de ' . (int) $data['pages'] . ' · ' . (int) $data['total_items'] . ' reportes</span>'
        . '<button type="button" class="' . UI::SECONDARY . '" data-ar-page="' . ($page + 1) . '"' . ($page >= (int) $data['pages'] ? ' disabled' : '') . '>Siguiente</button></div>';
    }
    return $html . '</div>';
  }

  /** @param array<string,mixed> $data AdministrativeReportsService::accounts() */
  public function accountsResults(array $data, bool $canPay): string
  {
    return $this->kpis([
      'Cuentas' => $data['counts']['total'],
      'Pagadas' => $data['counts']['pagadas'],
      'Pendientes' => $data['counts']['pendientes'],
      'Valor pendiente' => self::money($data['totals']['pendiente']),
      'Seleccionadas' => ['0 · $0', 'selected'],
    ]) . ($canPay ? '<div class="!sp-flex !sp-items-center !sp-gap-3 !sp-mb-3"><button type="button" class="' . UI::PRIMARY . '" data-ar-pay-accounts disabled>' . UI::icon('check') . 'Marcar seleccionadas como pagadas</button>'
      . '<span class="!sp-text-[11px] !sp-text-service-muted">Al pagar una cuenta se marcan como pagados todos sus reportes.</span></div>' : '')
      . $this->accountsTable($data['items'], $canPay);
  }

  /** @param array<int,array<string,mixed>> $accounts */
  private function accountsTable(array $accounts, bool $canPay): string
  {
    $html = '<div class="' . UI::CARD . '"><div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-xs !sp-min-w-[1000px]" data-ar-table><thead class="!sp-bg-slate-50"><tr>'
      . ($canPay ? '<th class="' . self::TH . '"><input type="checkbox" data-ar-check-all aria-label="Seleccionar todas"></th>' : '');
    foreach (['Fecha', 'N° cuenta', 'Funcionario', 'Período', 'Reportes', 'Servicio', 'Transporte', 'Total', 'Estado', 'Acciones'] as $label) $html .= '<th class="' . self::TH . '">' . $label . '</th>';
    $html .= '</tr></thead><tbody>';
    if (!$accounts) $html .= $this->empty('No hay cuentas de cobro para mostrar.');
    foreach ($accounts as $account) {
      $id = (int) $account['id'];
      $html .= '<tr class="' . self::TR . '" data-ar-row="' . $id . '" data-total="' . (int) $account['total'] . '">'
        . ($canPay ? '<td class="' . self::TD . '"><input type="checkbox" data-ar-check value="' . $id . '"' . ($account['pagada'] ? ' disabled' : '') . ' aria-label="Seleccionar cuenta ' . self::e($account['numero']) . '"></td>' : '')
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::e($account['fecha'] ?: '-') . '</td>'
        . '<td class="' . self::TD . ' !sp-font-bold !sp-whitespace-nowrap">' . self::e($account['numero']) . '</td>'
        . '<td class="' . self::TD . ' !sp-max-w-[180px]">' . self::e($account['funcionario'] ?: 'Funcionario #' . $account['id_empleado']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::e(($account['fecha_inicio'] ?: '-') . ' a ' . ($account['fecha_fin'] ?: '-')) . '</td>'
        . '<td class="' . self::TD . '">' . (count($account['report_ids']) ?: (int) $account['cantidad']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $account['servicio']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . self::money((int) $account['transporte']) . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap !sp-font-bold">' . self::money((int) $account['total']) . '</td>'
        . '<td class="' . self::TD . '"><span class="' . self::BADGE . ($account['pagada'] ? '!sp-bg-emerald-50 !sp-text-emerald-700' : '!sp-bg-amber-50 !sp-text-amber-900') . '">' . ($account['pagada'] ? 'Pagada' : 'No pagada') . '</span></td>'
        . '<td class="' . self::TD . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-1.5">'
        . '<button type="button" class="' . UI::SECONDARY . '" data-ar-account="' . $id . '">' . UI::icon('eye', '!sp-w-3.5 !sp-h-3.5') . 'Ver</button>'
        . '<button type="button" class="' . UI::SECONDARY . '" data-ar-account-pdf="' . $id . '" data-number="' . self::e($account['numero']) . '">PDF</button>'
        . '<button type="button" class="' . UI::SECONDARY . '" data-ar-account-excel="' . $id . '" data-number="' . self::e($account['numero']) . '">Excel</button>'
        . ($canPay && !$account['pagada'] ? '<button type="button" class="' . UI::NAVY . '" data-ar-pay-account="' . $id . '">Marcar pagada</button>' : '')
        . '</div></td></tr>';
    }
    return $html . '</tbody></table></div></div>';
  }

  /** @param array<string,mixed> $account */
  public function accountDetail(array $account, bool $canPay): string
  {
    $id = (int) $account['id'];
    $html = '<div class="!sp-p-4 sm:!sp-p-6 !sp-overflow-y-auto !sp-flex !sp-flex-col !sp-gap-4">'
      . '<div class="!sp-grid !sp-grid-cols-2 md:!sp-grid-cols-4 !sp-gap-3">';
    foreach ([
      'Funcionario' => $account['funcionario'] ?: 'Funcionario #' . $account['id_empleado'],
      'Documento' => $account['documento'] ?: '-',
      'Período' => ($account['fecha_inicio'] ?: '-') . ' a ' . ($account['fecha_fin'] ?: '-'),
      'Estado' => $account['pagada'] ? 'Pagada' : 'No pagada',
      'Servicio' => self::money((int) $account['servicio']),
      'Transporte' => self::money((int) $account['transporte']),
      'Total' => self::money((int) $account['total']),
      'Creada' => $account['fecha_creacion'] ?: '-',
    ] as $label => $value) {
      $html .= '<div class="' . UI::CARD . ' !sp-p-3"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</p><p class="!sp-m-0 !sp-mt-1 !sp-text-sm !sp-font-bold">' . self::e($value) . '</p></div>';
    }
    $html .= '</div><div class="' . UI::CARD . '"><div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-xs !sp-min-w-[900px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['Consecutivo', 'Fecha', 'Categoría', 'Caso', 'Contrato', 'Inmueble', 'Arrendatario', 'Servicio', 'Transporte', 'Total', 'Estado'] as $label) $html .= '<th class="' . self::TH . '">' . $label . '</th>';
    $html .= '</tr></thead><tbody>';
    if (!$account['reportes']) $html .= $this->empty('Esta cuenta no tiene reportes asociados.');
    foreach ($account['reportes'] as $item) {
      $html .= '<tr class="' . self::TR . '"><td class="' . self::TD . ' !sp-font-bold">#' . (int) $item['id'] . '</td>'
        . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . ($item['fecha'] > 0 ? date('d/m/Y', (int) $item['fecha']) : '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['categoria'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . $this->ticketCell((string) $item['id_ticket']) . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['contrato'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['inmueble'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::e($item['arrendatario'] ?: '-') . '</td>'
        . '<td class="' . self::TD . '">' . self::money((int) $item['servicio']) . '</td>'
        . '<td class="' . self::TD . '">' . self::money((int) $item['transporte']) . '</td>'
        . '<td class="' . self::TD . ' !sp-font-bold">' . self::money((int) $item['total']) . '</td>'
        . '<td class="' . self::TD . '"><span class="' . self::BADGE . ($item['pagado'] ? '!sp-bg-emerald-50 !sp-text-emerald-700' : '!sp-bg-amber-50 !sp-text-amber-900') . '">' . ($item['pagado'] ? 'Pagado' : 'Pendiente') . '</span></td></tr>';
    }
    $html .= '</tbody></table></div></div><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2">'
      . '<button type="button" class="' . UI::SECONDARY . '" data-ar-account-excel="' . $id . '" data-number="' . self::e($account['numero']) . '">Descargar Excel</button>'
      . '<button type="button" class="' . UI::SECONDARY . '" data-ar-account-pdf="' . $id . '" data-number="' . self::e($account['numero']) . '">Ver PDF</button>'
      . ($canPay && !$account['pagada'] ? '<button type="button" class="' . UI::PRIMARY . '" data-ar-pay-account="' . $id . '">Marcar cuenta pagada</button>' : '')
      . '</div></div>';
    return $html;
  }

  /**
   * Formulario para crear un reporte desde un caso (o un transporte por visita).
   * @param array<string,string> $ticket @param array<int,string> $categories @param array{servicio:int,transporte:int} $suggested
   */
  public function reportForm(string $mode, array $ticket, array $categories, array $suggested, string $employeeId, string $employeeName): string
  {
    $transport = $mode === 'transporte';
    $card = '';
    foreach (['Caso' => '#' . $ticket['id_ticket'], 'Contrato' => $ticket['contrato'], 'Inmueble' => $ticket['inmueble'], 'Solicitud' => $ticket['numero_solicitud'], 'Arrendatario' => $ticket['arrendatario'], 'Funcionario del reporte' => $employeeName ?: $employeeId] as $label => $value) {
      $card .= '<div><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</p><p class="!sp-m-0 !sp-mt-0.5 !sp-text-xs !sp-font-semibold">' . self::e($value !== '' ? $value : '-') . '</p></div>';
    }
    // Solo tipos existentes; el transporte por visita propone el primer tipo que hable de transporte.
    $preset = '';
    if ($transport) {
      foreach ($categories as $category) {
        if (stripos($category, 'transporte') !== false) { $preset = $category; break; }
      }
    }
    $options = '<option value=""' . ($preset === '' ? ' selected' : '') . ' disabled>Selecciona el tipo de reporte</option>';
    foreach ($categories as $category) $options .= '<option value="' . self::e($category) . '"' . ($category === $preset ? ' selected' : '') . '>' . self::e($category) . '</option>';
    $money = static fn(int $value): string => number_format($value, 0, ',', '.');
    return '<form class="!sp-p-4 sm:!sp-p-6 !sp-flex !sp-flex-col !sp-gap-4 !sp-overflow-y-auto" data-ar-report-form>'
      . '<input type="hidden" name="form" value="' . self::e($mode) . '"><input type="hidden" name="id_ticket" value="' . self::e($ticket['id_ticket']) . '"><input type="hidden" name="employee" value="' . self::e($employeeId) . '">'
      . '<div class="' . UI::CARD . ' !sp-p-4 !sp-grid !sp-grid-cols-2 md:!sp-grid-cols-3 !sp-gap-3">' . $card . '</div>'
      . '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-4 !sp-gap-3">'
      . '<label class="!sp-block md:!sp-col-span-2"><span class="' . UI::LABEL . '">Tipo de reporte</span><select class="' . UI::INPUT . '" name="categoria" required>' . $options . '</select></label>'
      . ($transport ? '' : '<label class="!sp-block"><span class="' . UI::LABEL . '">Valor servicio</span><input class="' . UI::INPUT . '" name="servicio" inputmode="numeric" data-ar-form-money value="' . $money($suggested['servicio']) . '"></label>')
      . '<label class="!sp-block"><span class="' . UI::LABEL . '">Transporte</span><input class="' . UI::INPUT . '" name="transporte" inputmode="numeric" data-ar-form-money value="' . ($transport ? $money($suggested['transporte']) : '0') . '"></label>'
      . ($transport ? '' : '<label class="!sp-block"><span class="' . UI::LABEL . '">Cerrar caso</span><select class="' . UI::INPUT . '" name="cerrar_ticket"><option value="No">No</option><option value="Si">Sí, cerrar y finalizar</option></select></label>')
      . '</div>'
      . '<label class="!sp-block"><span class="' . UI::LABEL . '">Evidencia escrita</span><textarea class="' . str_replace('!sp-h-10', '!sp-h-28 !sp-py-2', UI::INPUT) . '" name="descripcion" required placeholder="Describe la gestión realizada"></textarea></label>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3"><p class="!sp-m-0 !sp-text-sm">Total del reporte: <strong data-ar-form-total>$0</strong></p>'
      . '<div class="!sp-flex !sp-gap-2"><button type="button" class="' . UI::SECONDARY . '" data-ar-modal-close>Cancelar</button><button type="submit" class="' . UI::PRIMARY . '">Guardar reporte</button></div></div>'
      . '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted" data-ar-form-status></p></form>';
  }
}
