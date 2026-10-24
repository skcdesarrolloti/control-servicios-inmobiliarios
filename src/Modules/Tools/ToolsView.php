<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Herramientas del encabezado: Generar PSE y Calculadora de mora (public/assets/js/admin-tools.js). */
final class ToolsView
{
  private const HEADER_ICON = '!sp-inline-flex !sp-items-center !sp-justify-center !sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-service-navy';
  private const SECTION_TITLE = '!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide !sp-text-service-navy';
  private const SECTION_HEAD = '!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
  private const READONLY = '!sp-block !sp-box-border !sp-w-full !sp-h-10 !sp-px-3 !sp-border !sp-border-solid !sp-border-slate-200 !sp-rounded-lg !sp-bg-slate-100 !sp-text-service-navy !sp-text-xs !sp-font-semibold !sp-font-sans';

  public function pse(): string
  {
    $periods = [
      1 => ['title' => 'Primer periodo del mes', 'payments' => ['Primer pago del mes', 'Segundo pago del mes'], 'pct' => ['0', '1']],
      2 => ['title' => 'Segundo periodo del mes', 'payments' => ['Tercer pago del mes', 'Cuarto pago del mes'], 'pct' => ['2', '3']],
    ];
    $cards = '';
    foreach ($periods as $number => $period) {
      $rows = '';
      foreach ($period['payments'] as $index => $label) {
        $slot = $index + 1;
        $rows .= '<div class="!sp-grid !sp-grid-cols-[minmax(0,1fr)_96px] !sp-gap-3">'
          . $this->field($label, '<input class="' . UI::INPUT . '" type="date" name="p' . $number . '_pay' . $slot . '" required>')
          . $this->field('% recargo', '<input class="' . UI::INPUT . ' !sp-text-right" type="text" inputmode="decimal" name="p' . $number . '_pct' . $slot . '" value="' . $period['pct'][$index] . '" required>')
          . '</div>';
      }
      $rows .= $this->field('Fecha de publicación', '<input class="' . UI::INPUT . '" type="date" name="p' . $number . '_published" required>');
      $cards .= '<fieldset class="!sp-m-0 !sp-min-w-0 !sp-p-4 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-bg-slate-50/60 !sp-space-y-3">'
        . '<legend class="!sp-px-1 !sp-text-xs !sp-font-bold !sp-text-service-navy"><span class="!sp-inline-flex !sp-items-center !sp-justify-center !sp-w-5 !sp-h-5 !sp-mr-1 !sp-rounded-full !sp-bg-service-navy !sp-text-service-yellow !sp-text-[10px]">' . $number . '</span>' . $period['title'] . '</legend>'
        . $rows . '<p class="!sp-m-0 !sp-text-[10px] !sp-text-service-muted">Archivo: <strong>&lt;fecha de publicación&gt;_' . $number . 'Periodo.txt</strong></p></fieldset>';
    }

    return '<section class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-scm-tool="pse" data-scm-loaded="0">'
      . $this->header('payments', 'Generar PSE', 'Archivos planos de recaudo PSE · Primer y segundo periodo del mes · Convenio 48686')
      . '<div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-12 !sp-gap-5 !sp-items-start">'
      . '<form class="' . UI::CARD . ' !sp-p-5 xl:!sp-col-span-7 !sp-space-y-5" data-pse-form autocomplete="off" novalidate>'
      . '<div class="' . self::SECTION_HEAD . ' !sp-mb-0"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">event</span>Fechas y recargos</h3><span class="!sp-text-[10px] !sp-text-service-muted">El % se suma al valor de cada factura en ese pago</span></div>'
      . '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4">' . $cards . '</div>'
      . '<div class="!sp-space-y-2">' . $this->field('Excel de facturación (.xls o .xlsx)', '<input class="' . UI::INPUT . ' !sp-h-auto !sp-py-2 file:!sp-mr-3 file:!sp-px-3 file:!sp-py-1.5 file:!sp-rounded-md file:!sp-border-0 file:!sp-bg-service-navy file:!sp-text-white file:!sp-text-[11px] file:!sp-font-semibold" type="file" name="archivo" accept=".xls,.xlsx" required>')
      . '<p class="!sp-m-0 !sp-text-[10px] !sp-text-service-muted !sp-leading-relaxed">Primera hoja, encabezados en la fila 1. Columnas: <strong>A</strong> Anulada (solo se toman las facturas en "False"), <strong>B</strong> No. factura, <strong>E</strong> Valor, <strong>F</strong> IVA, <strong>G</strong> Retención, <strong>H</strong> ReteIVA, <strong>I</strong> ReteICA y <strong>J</strong> Admin PH.</p></div>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3"><button class="' . UI::PRIMARY . '" type="submit" data-pse-submit><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">upload_file</span>Generar archivos</button>'
      . '<span class="!sp-text-[11px] !sp-text-service-muted" role="status" data-pse-status></span></div>'
      . '<div data-pse-result></div>'
      . '</form>'
      . '<section class="' . UI::CARD . ' !sp-p-5 xl:!sp-col-span-5">'
      . '<div class="' . self::SECTION_HEAD . '"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">inventory_2</span>Archivador</h3>'
      . '<button type="button" class="' . UI::SECONDARY . ' !sp-text-[11px]" data-pse-archive-refresh>' . UI::icon('refresh', '!sp-w-3.5 !sp-h-3.5') . 'Actualizar</button></div>'
      . '<div data-pse-archive><p class="!sp-m-0 !sp-p-6 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando archivos…</p></div>'
      . '</section></div></section>';
  }

  /** @param list<array{name:string,size:int,modified:int,records:int}> $files */
  public function pseArchive(array $files): string
  {
    if ($files === []) {
      return '<p class="!sp-m-0 !sp-p-6 !sp-text-center !sp-text-xs !sp-text-service-muted">Aún no hay archivos generados desde el panel.</p>';
    }
    $html = '<ul class="!sp-m-0 !sp-p-0 !sp-list-none !sp-divide-y !sp-divide-slate-100 !sp-max-h-[520px] !sp-overflow-y-auto">';
    foreach ($files as $file) {
      $html .= '<li class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-3 !sp-py-2.5">'
        . '<div class="!sp-min-w-0"><strong class="!sp-block !sp-truncate !sp-text-xs">' . self::e($file['name']) . '</strong>'
        . '<span class="!sp-text-[10px] !sp-text-service-muted">' . self::e(date('d/m/Y H:i', $file['modified'])) . ' · ' . (int) $file['records'] . ' factura(s) · ' . self::e(number_format($file['size'] / 1024, 1, ',', '.')) . ' KB</span></div>'
        . '<button type="button" class="' . UI::SECONDARY . ' !sp-text-[11px] !sp-shrink-0" data-pse-download="' . self::e($file['name']) . '">' . UI::icon('download', '!sp-w-3.5 !sp-h-3.5') . 'Descargar</button></li>';
    }
    return $html . '</ul>';
  }

  /** @param array{records:int,annulled:int,without_value:int,files:list<array{name:string,period:int,published:string,total_first:int,total_second:int}>} $result */
  public function pseResult(array $result): string
  {
    $money = static fn(int $value): string => '$ ' . number_format($value, 0, ',', '.');
    $files = '';
    foreach ($result['files'] as $file) {
      $files .= '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-p-3 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-emerald-200">'
        . '<div class="!sp-min-w-0"><strong class="!sp-block !sp-text-xs">' . self::e($file['name']) . '</strong>'
        . '<span class="!sp-text-[10px] !sp-text-service-muted">Periodo ' . (int) $file['period'] . ' · 1.er pago ' . self::e($money($file['total_first'])) . ' · 2.º pago ' . self::e($money($file['total_second'])) . '</span></div>'
        . '<button type="button" class="' . UI::NAVY . '" data-pse-download="' . self::e($file['name']) . '">' . UI::icon('download', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Descargar</button></div>';
    }
    $skipped = [];
    if ($result['annulled'] > 0) $skipped[] = $result['annulled'] . ' anulada(s)';
    if ($result['without_value'] > 0) $skipped[] = $result['without_value'] . ' sin valor a pagar';
    return '<div class="!sp-p-4 !sp-rounded-xl !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-space-y-3">'
      . '<p class="!sp-m-0 !sp-flex !sp-items-center !sp-gap-2 !sp-text-xs !sp-font-semibold !sp-text-emerald-800"><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">check_circle</span>'
      . (int) $result['records'] . ' factura(s) incluidas en cada archivo' . ($skipped !== [] ? ' · Omitidas: ' . self::e(implode(', ', $skipped)) : '') . '</p>'
      . '<div class="!sp-grid !sp-grid-cols-1 !sp-gap-2">' . $files . '</div></div>';
  }

  public function mora(): string
  {
    $input = UI::INPUT;
    $button = static fn(string $class, string $data, string $icon, string $label): string => '<button type="button" class="' . $class . '" data-mora-action="' . $data . '"><span class="material-symbols-outlined !sp-text-[17px]" aria-hidden="true">' . $icon . '</span>' . $label . '</button>';
    $breakdownRow = static fn(string $label, string $key, bool $strong = false): string => '<tr class="!sp-border-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0' . ($strong ? ' !sp-font-bold' : '') . '"><th class="!sp-py-2 !sp-pr-3 !sp-text-left !sp-text-[11px] ' . ($strong ? '!sp-font-bold' : '!sp-font-medium') . ' !sp-text-service-navy">' . $label . '</th><td class="!sp-py-2 !sp-text-right !sp-text-xs !sp-font-semibold !sp-whitespace-nowrap" data-mora="' . $key . '">$0</td></tr>';
    $note = static fn(string $title, string $body): string => '<div class="!sp-p-4 !sp-rounded-xl !sp-bg-slate-50 !sp-border !sp-border-solid !sp-border-slate-200 !sp-h-full"><h4 class="!sp-m-0 !sp-mb-2 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-navy">' . $title . '</h4>' . $body . '</div>';
    $pre = static fn(string $key, string $text): string => '<pre class="!sp-m-0 !sp-whitespace-pre-wrap !sp-break-words !sp-font-sans !sp-text-[11px] !sp-leading-relaxed !sp-text-service-navy !sp-bg-transparent !sp-border-0 !sp-p-0" data-mora="' . $key . '">' . $text . '</pre>';
    $kpi = static fn(string $label, string $key, bool $accent = false): string => '<div class="' . ($accent ? '!sp-bg-service-navy !sp-border-service-navy' : '!sp-bg-white !sp-border-slate-200') . ' !sp-rounded-xl !sp-border !sp-border-solid !sp-p-4"><p class="!sp-m-0 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide ' . ($accent ? '!sp-text-service-yellow' : '!sp-text-service-muted') . '">' . $label . '</p><p class="!sp-m-0 !sp-mt-1 !sp-text-lg !sp-font-extrabold ' . ($accent ? '!sp-text-white' : '!sp-text-service-navy') . '" data-mora="' . $key . '">$0</p></div>';
    $heads = '';
    foreach (['#' => '', 'Concepto' => '', 'Tipo' => '', 'Valor canon' => 'r', 'Valor pagado' => 'r', 'Vencimiento' => '', 'Pago' => '', '% penal' => 'r', 'Días' => 'r', 'Meses' => 'r', 'Interés mora' => 'r', 'Cláusula penal' => 'r', 'Mayor' => 'r', 'Método' => '', '' => ''] as $label => $align) {
      $heads .= '<th class="!sp-px-2 !sp-py-3 !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-whitespace-nowrap ' . ($align === 'r' ? '!sp-text-right' : '!sp-text-left') . '">' . $label . '</th>';
    }

    return '<section class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-scm-tool="mora" data-scm-loaded="0">'
      . $this->header('gavel', 'Calculadora de mora', 'Calcula intereses por canon, los compara con la cláusula penal y cobra automáticamente el mayor valor', '<span class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-1.5 !sp-rounded-full !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-800 !sp-text-[11px] !sp-font-semibold"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">verified_user</span>Fuente oficial: Superfinanciera</span>')
      . '<div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-12 !sp-gap-5 !sp-items-start">'
      . '<div class="xl:!sp-col-span-4 !sp-space-y-5">'
      . '<section class="' . UI::CARD . ' !sp-p-5"><div class="' . self::SECTION_HEAD . '"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">tune</span>Parámetros</h3></div>'
      . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-4">'
      . '<div class="!sp-col-span-2">' . $this->field('Tasa efectiva anual consumo y ordinario (%)', '<input class="' . $input . '" data-mora="annualRate" type="number" min="0" step="0.0001" placeholder="17.01">') . '</div>'
      . '<div class="!sp-col-span-2">' . $this->field('Cláusula penal global por cuota (%)', '<input class="' . $input . '" data-mora="globalPenaltyPct" type="number" min="0" step="0.01" value="4">') . '</div>'
      . '<div class="!sp-col-span-2"><span class="' . UI::LABEL . '">Fecha de pago global (opcional)</span><div class="!sp-flex !sp-gap-2"><input class="' . $input . '" data-mora="globalPaymentDate" type="date" aria-label="Fecha de pago global">' . $button(UI::SECONDARY . ' !sp-shrink-0', 'applyGlobalDate', 'event_available', 'Aplicar') . '</div></div>'
      . '<div class="!sp-col-span-2">' . $this->field('Nombre del cliente', '<input class="' . $input . '" data-mora="clientName" type="text" placeholder="Nombre completo">') . '</div>'
      . '<div class="!sp-col-span-2">' . $this->field('Cédula', '<input class="' . $input . '" data-mora="clientDocument" type="text" placeholder="Número de cédula">') . '</div>'
      . '<div class="!sp-col-span-2">' . $this->field('Descripción', '<textarea class="' . $input . ' !sp-h-auto !sp-py-2" data-mora="clientDescription" rows="2" placeholder="Detalle del caso para el cliente"></textarea>') . '</div>'
      . $this->field('Mora anual (%)', '<input class="' . self::READONLY . '" data-mora="moraAnnualRate" type="text" readonly>')
      . $this->field('Mora mensual (%)', '<input class="' . self::READONLY . '" data-mora="moraMonthlyRate" type="text" readonly>')
      . '<div class="!sp-col-span-2">' . $button(UI::NAVY . ' !sp-w-full !sp-py-2.5 !sp-text-xs', 'syncSuperfin', 'sync', 'Actualizar resolución SFC') . '</div>'
      . '</div></section>'
      . '<section class="' . UI::CARD . ' !sp-p-5"><div class="' . self::SECTION_HEAD . '"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">account_balance</span>Resolución vigente</h3></div>'
      . '<dl class="!sp-m-0 !sp-space-y-2 !sp-text-xs">'
      . '<div class="!sp-flex !sp-justify-between !sp-gap-3"><dt class="!sp-text-service-muted">Resolución</dt><dd class="!sp-m-0 !sp-font-semibold !sp-text-right" data-mora="resolutionLabel">Sin cargar</dd></div>'
      . '<div class="!sp-flex !sp-justify-between !sp-gap-3"><dt class="!sp-text-service-muted">Vigencia</dt><dd class="!sp-m-0 !sp-font-semibold !sp-text-right" data-mora="vigenciaText">Sin cargar</dd></div>'
      . '<div class="!sp-flex !sp-justify-between !sp-gap-3"><dt class="!sp-text-service-muted">Última actualización</dt><dd class="!sp-m-0 !sp-font-semibold !sp-text-right" data-mora="updatedAtText">Sin cargar</dd></div></dl>'
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 xl:!sp-grid-cols-1 !sp-gap-2 !sp-mt-4">'
      . '<a class="' . UI::SECONDARY . ' !sp-no-underline aria-disabled:!sp-opacity-50 aria-disabled:!sp-pointer-events-none" data-mora="resolutionLink" href="#" target="_blank" rel="noopener" aria-disabled="true">Ver acto de resolución</a>'
      . '<a class="' . UI::SECONDARY . ' !sp-no-underline aria-disabled:!sp-opacity-50 aria-disabled:!sp-pointer-events-none" data-mora="bulletinLink" href="#" target="_blank" rel="noopener" aria-disabled="true">Ver comunicado mensual</a></div>'
      . '<div class="!sp-flex !sp-items-start !sp-gap-2 !sp-mt-4 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-text-[11px] !sp-font-medium" data-mora="statusBox" role="status"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true" data-mora="statusIcon">info</span><span data-mora="statusText">Cargando tasa oficial…</span></div>'
      . '</section></div>'
      . '<div class="xl:!sp-col-span-8 !sp-space-y-5 !sp-min-w-0">'
      . '<section class="' . UI::CARD . ' !sp-p-5"><div class="' . self::SECTION_HEAD . '"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">receipt_long</span>Cánones</h3>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-gap-2"><input class="!sp-hidden" type="file" accept=".xlsx,.xls" data-mora="excelInput">'
      . $button(UI::SECONDARY, 'importExcel', 'table_view', 'Importar Excel')
      . $button(UI::SECONDARY, 'addRow', 'add', 'Agregar ítem')
      . $button(UI::PRIMARY, 'calculate', 'calculate', 'Calcular cobro')
      . $button(UI::NAVY . ' !sp-text-xs', 'pdfClient', 'picture_as_pdf', 'PDF cliente')
      . $button(UI::SECONDARY, 'pdfAdmin', 'picture_as_pdf', 'PDF admin')
      . '</div></div>'
      . '<div class="!sp-overflow-x-auto !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200"><table class="!sp-w-full !sp-min-w-[1180px] !sp-border-collapse !sp-text-[11px]"><thead class="!sp-bg-slate-50"><tr>' . $heads . '</tr></thead><tbody data-mora="installmentsBody"></tbody></table></div>'
      . '</section>'
      . '<section class="' . UI::CARD . ' !sp-p-5 !sp-space-y-4"><div class="' . self::SECTION_HEAD . ' !sp-mb-0"><h3 class="' . self::SECTION_TITLE . '"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">summarize</span>Resumen</h3></div>'
      . '<div class="!sp-grid !sp-grid-cols-2 md:!sp-grid-cols-4 !sp-gap-3">' . $kpi('Capital', 'kpiCapital') . $kpi('Interés mora', 'kpiInterest') . $kpi('Cláusula penal', 'kpiPenalty') . $kpi('Total recomendado', 'kpiRecommended', true) . '</div>'
      . '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4">'
      . $note('Resumen visual valor a pagar', '<table class="!sp-w-full !sp-border-collapse"><tbody>' . $breakdownRow('Valor canon', 'payBreakdownCanon') . $breakdownRow('Servicios públicos', 'payBreakdownServices') . $breakdownRow('Intereses de mora', 'payBreakdownInterest') . $breakdownRow('Recargo aplicado (informativo)', 'payBreakdownRecargo') . $breakdownRow('Total discriminado (canon + servicios + intereses)', 'payBreakdownTotal', true) . $breakdownRow('Total pagado', 'payBreakdownPaid') . $breakdownRow('Saldo pendiente', 'payBreakdownSaldo') . '</tbody></table>')
      . $note('Discriminación de pago', '<table class="!sp-w-full !sp-border-collapse"><tbody>' . $breakdownRow('Pago total', 'applyPayTotal') . $breakdownRow('Pago total de servicio público', 'applyPayServicesTotal') . $breakdownRow('Pago total de intereses', 'applyPayInterestTotal') . $breakdownRow('Pago total del canon', 'applyPayCanonTotal') . $breakdownRow('Saldo pendiente', 'applyPayPending', true) . '</tbody></table>')
      . $note('Descripción cuenta de cobro', $pre('chargeDescriptionText', 'Calcula para generar la descripción automática.'))
      . $note('Registro contable sugerido', $pre('accountingEntryText', 'Calcula para generar el registro contable.'))
      . '<div class="lg:!sp-col-span-2">' . $note('Discriminación detallada por canon', $pre('paymentDetailText', 'Calcula para discriminar el valor por cada detalle.')) . '</div>'
      . '</div></section>'
      . '</div></div></section>';
  }

  private function header(string $icon, string $title, string $subtitle, string $aside = ''): string
  {
    return '<header class="' . UI::CARD . ' !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">'
      . '<div class="!sp-flex !sp-items-center !sp-gap-3"><span class="' . self::HEADER_ICON . '"><span class="material-symbols-outlined !sp-text-[24px]" aria-hidden="true">' . $icon . '</span></span>'
      . '<div><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Herramientas</p><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">' . $title . '</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">' . $subtitle . '</p></div></div>'
      . $aside . '</header>';
  }

  private function field(string $label, string $control): string
  {
    return '<label class="!sp-block !sp-min-w-0"><span class="' . UI::LABEL . '">' . $label . '</span>' . $control . '</label>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
