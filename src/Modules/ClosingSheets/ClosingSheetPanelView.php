<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Modules\Pending\PublicServicesUi as UI;

/**
 * Actividades administrativas › Hojas de cierre: listado por estado dentro del panel. Ver, revisar servicios y
 * responder se abren en una ventana del panel (hoja-cierre-ver.php y revision-servicios-cierre.php con embed=1).
 */
final class ClosingSheetPanelView
{
  private const TONES = [
    ClosingSheetService::STATUS_NEW => '!sp-bg-amber-100 !sp-text-amber-800', ClosingSheetWorkflow::STATUS_REVIEWED => '!sp-bg-blue-100 !sp-text-blue-800',
    ClosingSheetWorkflow::REJECTED => '!sp-bg-red-100 !sp-text-red-800', ClosingSheetWorkflow::APPROVED => '!sp-bg-emerald-100 !sp-text-emerald-800',
    'Realizada' => '!sp-bg-slate-200 !sp-text-slate-700',
  ];

  public static function shell(string $action): string
  {
    $ui = UI::class;
    return '<div class="scm-closing-sheets !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-closing-sheets data-action="' . self::e($action) . '">'
      . '<header class="' . $ui::CARD . ' !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">'
      . '<div class="!sp-flex !sp-items-center !sp-gap-3"><span class="material-symbols-outlined !sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200" aria-hidden="true">handshake</span>'
      . '<div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Hojas de cierre</h2>'
      . '<p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Revisión de servicios antes de la ocupación · Aprobar o no aprobar · Consulta e impresión</p></div></div></header>'
      . '<section class="' . $ui::CARD . ' !sp-p-4 !sp-space-y-4">'
      . '<form class="!sp-flex !sp-flex-wrap !sp-gap-2" data-closing-sheets-search role="search">'
      . '<input type="search" name="q" class="' . $ui::INPUT . ' !sp-flex-1 !sp-min-w-[220px]" placeholder="Buscar por número, inmueble, dirección, arrendatario o propietario" aria-label="Buscar hojas de cierre">'
      . '<button type="submit" class="' . $ui::NAVY . '">' . $ui::icon('search', '!sp-w-3.5 !sp-h-3.5') . 'Buscar</button></form>'
      . '<div data-closing-sheets-content><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando hojas de cierre…</p></div>'
      . '</section></div>';
  }

  /**
   * Pestañas por estado con su cantidad y la tabla del estado elegido.
   * @param array<string,int> $counts @param list<array<string,mixed>> $rows @param array{review:bool,respond:bool} $can
   */
  public static function content(array $counts, array $rows, string $state, string $query, array $can): string
  {
    $tabs = '';
    foreach (ClosingSheetWorkflow::LIST_STATES as $option) {
      $active = $option === $state;
      $tabs .= '<button type="button" class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-border-0 !sp-font-sans !sp-text-[11px] !sp-cursor-pointer '
        . ($active ? '!sp-bg-service-navy !sp-text-white !sp-font-semibold' : '!sp-bg-transparent !sp-text-service-muted !sp-font-medium hover:!sp-bg-white')
        . '" data-closing-sheets-state="' . self::e($option) . '" aria-pressed="' . ($active ? 'true' : 'false') . '">' . self::e($option)
        . '<span class="!sp-px-1.5 !sp-rounded-full ' . ($active ? '!sp-bg-white/20' : '!sp-bg-slate-200') . '">' . (int) ($counts[$option] ?? 0) . '</span></button>';
    }
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $state = ClosingSheetWorkflow::state($row);
      $actions = self::open('hoja-cierre-ver.php?numero=' . $id, 'Ver', UI::SECONDARY);
      if ($can['review'] && ClosingSheetWorkflow::canReview($row)) {
        $actions .= self::open('revision-servicios-cierre.php?numero=' . $id, 'Revisar servicios', UI::SECONDARY);
      }
      if ($can['respond'] && ClosingSheetWorkflow::canRespond($row)) {
        $actions .= self::open('hoja-cierre-ver.php?numero=' . $id, 'Responder', UI::PRIMARY);
      }
      $people = array_filter([
        trim((string) ($row['creador'] ?? '')) !== '' ? 'Hizo: ' . trim((string) $row['creador']) : '',
        trim((string) ($row['verificador'] ?? '')) !== '' ? 'Revisó: ' . trim((string) $row['verificador']) : '',
        trim((string) ($row['aprobador'] ?? '')) !== '' ? 'Respondió: ' . trim((string) $row['aprobador']) : '',
      ]);
      $cell = '!sp-p-3 !sp-align-top !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
      $body .= '<tr><td class="' . $cell . '"><strong>#' . $id . '</strong><small class="!sp-block !sp-text-service-muted">' . self::e(ClosingSheetFlowView::date((int) ($row['fecha'] ?? 0))) . '</small></td>'
        . '<td class="' . $cell . '">' . self::e($row['direccion'] ?? '') . '<small class="!sp-block !sp-text-service-muted">Inmueble #' . self::e($row['id_inmueble'] ?? '') . '</small></td>'
        . '<td class="' . $cell . '">' . self::e($row['arrendatario'] ?? '') . '</td>'
        . '<td class="' . $cell . '">' . implode('', array_map(static fn(string $line): string => '<small class="!sp-block !sp-text-service-muted">' . self::e($line) . '</small>', $people)) . '</td>'
        . '<td class="' . $cell . '"><span class="!sp-inline-block !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-text-[10px] !sp-font-bold ' . (self::TONES[$state] ?? '!sp-bg-slate-100 !sp-text-slate-700') . '">' . self::e($state !== '' ? $state : 'Sin estado') . '</span></td>'
        . '<td class="' . $cell . '"><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-1.5">' . $actions . '</div></td></tr>';
    }
    $head = '!sp-p-3 !sp-text-left !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-bg-slate-50';
    return '<nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-w-fit !sp-max-w-full" aria-label="Estados de la hoja de cierre">' . $tabs . '</nav>'
      . ($body !== ''
        ? '<div class="!sp-overflow-x-auto !sp-mt-4"><table class="!sp-w-full !sp-border-collapse !sp-text-xs"><thead><tr><th class="' . $head . '">Hoja</th><th class="' . $head . '">Inmueble</th><th class="' . $head . '">Arrendatario</th><th class="' . $head . '">Funcionarios</th><th class="' . $head . '">Estado</th><th class="' . $head . '"><span class="!sp-sr-only">Acciones</span></th></tr></thead><tbody>' . $body . '</tbody></table></div>'
        : '<p class="!sp-m-0 !sp-mt-4 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No hay hojas de cierre en "' . self::e($state) . '"' . ($query !== '' ? ' con esa búsqueda' : '') . '.</p>')
      . '<p class="!sp-m-0 !sp-mt-3 !sp-text-[10px] !sp-text-service-muted">Se muestran las 100 más recientes del estado.</p>';
  }

  private static function open(string $url, string $label, string $class): string
  {
    return '<button type="button" class="' . $class . '" data-closing-sheets-open="' . self::e($url) . '">' . self::e($label) . '</button>';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
