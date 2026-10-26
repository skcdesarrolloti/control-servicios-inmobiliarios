<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Modules\Pending\PublicServicesUi as UI;

/**
 * HTML del liquidador de créditos, de Métricas › Créditos a propietarios y de su configuración, con las
 * primitivas Tailwind del panel (PublicServicesUi). "Solicitud de crédito" y "Configuración" se abren desde el
 * encabezado del panel.
 */
final class OwnerCreditView
{
  private const VACANCY = [
    'Baja' => 'Baja · plazo y valor solicitados, tasa de política',
    'Media' => 'Media · plazo máximo, tasa de referencia',
    'Alta' => 'Alta · 3 meses, tasa de riesgo',
  ];
  private const STATUS_TONES = [
    'pendiente' => '!sp-bg-amber-50 !sp-text-amber-800 !sp-border-amber-200',
    'aprobado' => '!sp-bg-emerald-50 !sp-text-emerald-700 !sp-border-emerald-200',
    'rechazado' => '!sp-bg-red-50 !sp-text-red-700 !sp-border-red-200',
    'pagado' => '!sp-bg-blue-50 !sp-text-blue-700 !sp-border-blue-200',
    'anulado' => '!sp-bg-slate-100 !sp-text-slate-600 !sp-border-slate-200',
  ];
  private const SECTION = '!sp-bg-white !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-card !sp-p-4 sm:!sp-p-5';
  private const H4 = '!sp-m-0 !sp-mb-3 !sp-flex !sp-items-center !sp-gap-2 !sp-text-xs !sp-font-bold !sp-text-service-navy';
  private const NOTE = '!sp-m-0 !sp-text-[11px] !sp-leading-relaxed !sp-text-service-muted';
  private const READONLY = '!sp-bg-slate-100 !sp-text-service-muted';

  /** Contenedor de Métricas › Créditos a propietarios; el listado llega por AJAX. */
  public function shell(): string
  {
    $ui = UI::class;
    $status = '<option value="">Todos</option>';
    foreach (OwnerCreditService::STATUSES as $key => $label) $status .= '<option value="' . $key . '">' . $label . '</option>';
    $field = static fn(string $label, string $control): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . self::e($label) . '</span>' . $control . '</label>';
    return '<section class="scm-owner-credits !sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-scm-owner-credits data-scm-loaded="0">'
      . '<header class="' . $ui::CARD . ' !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">'
      . '<div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200">' . self::icon('cash', '!sp-w-6 !sp-h-6') . '</span>'
      . '<div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Créditos a propietarios</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Solicitudes de financiación · Decisiones del liquidador · Cartera vigente por contrato</p></div></div>'
      . '<div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" data-owner-credit-count>0</strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Créditos filtrados</p></div>'
      . '</header>'
      . '<div class="' . $ui::CARD . ' !sp-p-5"><div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide">' . $ui::icon('filter') . 'Filtros · Créditos</h3><span class="!sp-text-[10px] !sp-text-service-muted">"Solicitud de crédito" y su configuración están en el encabezado del panel</span></div>'
      . '<form data-owner-credit-filters autocomplete="off"><div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">'
      . $field('Estado', '<select class="' . $ui::INPUT . '" name="status">' . $status . '</select>')
      . $field('Solicitado desde', '<input class="' . $ui::INPUT . '" type="date" name="from">')
      . $field('Solicitado hasta', '<input class="' . $ui::INPUT . '" type="date" name="to">')
      . $field('Buscar', '<input class="' . $ui::INPUT . '" name="search" placeholder="Contrato, propietario, inmueble o cotización">')
      . '</div><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5"><button class="' . $ui::PRIMARY . '" type="submit">' . $ui::icon('filter') . 'Filtrar</button>'
      . '<button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="reset" data-owner-credit-clear>Limpiar filtros</button></div></form></div>'
      . '<div data-owner-credit-report><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando créditos…</p></div></section>';
  }

  /** @param array<string,mixed> $report */
  public function report(array $report, bool $canOpen): string
  {
    $ui = UI::class;
    $t = $report['totals'];
    $metric = static fn(string $label, string $value, string $hint, string $icon): string => '<article class="' . $ui::CARD . ' !sp-p-4 !sp-flex !sp-gap-3 !sp-items-start">'
      . '<span class="!sp-p-2 !sp-rounded-lg !sp-bg-service-lilac !sp-text-service-blue">' . self::icon($icon, '!sp-w-4 !sp-h-4') . '</span>'
      . '<div class="!sp-min-w-0"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</p><strong class="!sp-block !sp-mt-1 !sp-text-lg !sp-text-service-navy">' . $value . '</strong>'
      . ($hint !== '' ? '<p class="!sp-m-0 !sp-mt-0.5 !sp-text-[10px] !sp-text-service-muted">' . self::e($hint) . '</p>' : '') . '</div></article>';
    $html = '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 xl:!sp-grid-cols-5 !sp-gap-4 !sp-mb-5" data-owner-credit-total="' . (int) $t['count'] . '">'
      . $metric('Solicitudes', (string) $t['count'], $t['pendiente'] . ' pendientes de evaluar', 'document')
      . $metric('Aprobados', (string) ($t['aprobado'] + $t['pagado']), $t['rechazado'] . ' rechazados · ' . $t['anulado'] . ' anulados', 'check')
      . $metric('Tasa de aprobación', $t['approval_rate'] === null ? '—' : number_format($t['approval_rate'] * 100, 0) . ' %', 'Sobre créditos decididos', 'chart')
      . $metric('Monto aprobado', self::money($t['approved_amount']), 'Intereses: ' . self::money($t['interest']), 'cash')
      . $metric('Saldo vigente', self::money($t['outstanding']), $t['aprobado'] . ' créditos por pagar', 'clock')
      . '</div>';
    $html .= '<div class="' . $ui::CARD . '"><div class="!sp-p-4"><h3 class="!sp-m-0 !sp-font-bold !sp-text-sm">Créditos registrados</h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Ábrelos en el liquidador para evaluarlos, ver su tabla de amortización o cerrarlos.</p></div>';
    if ($report['rows'] === []) return $html . '<p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No hay créditos con los filtros actuales.</p></div>';
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $td = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4 !sp-align-top';
    $small = static fn(string $text): string => '<small class="!sp-block !sp-mt-0.5 !sp-text-[10px] !sp-text-service-muted">' . $text . '</small>';
    $html .= '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[1050px]"><thead class="!sp-bg-slate-50"><tr>';
    foreach (['#', 'Contrato / propietario', 'Origen', 'Solicitado', 'Aprobado', 'Cuota', 'Saldo', 'Estado', 'Fecha', 'Acciones'] as $label) $html .= '<th class="' . $th . '">' . $label . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($report['rows'] as $row) {
      $approved = in_array($row['status'], ['aprobado', 'pagado'], true);
      $html .= '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">'
        . '<td class="' . $td . ' !sp-font-semibold">' . (int) $row['id'] . '</td>'
        . '<td class="' . $td . '"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . self::e($row['contract_code']) . '</span>' . $small(self::e($row['owner_name']) . ' · Inm. ' . self::e($row['property'])) . '</td>'
        . '<td class="' . $td . '">' . ((int) $row['quote_id'] > 0 ? 'Cotización #' . (int) $row['quote_id'] . ((string) $row['ticket_id'] !== '' ? $small('Caso #' . self::e($row['ticket_id'])) : '') : 'Manual') . '</td>'
        . '<td class="' . $td . ' !sp-whitespace-nowrap"><strong>' . self::money($row['requested_amount']) . '</strong>' . $small((int) $row['requested_term'] . ' meses') . '</td>'
        . '<td class="' . $td . ' !sp-whitespace-nowrap">' . ($approved ? '<strong>' . self::money($row['approved_amount']) . '</strong>' . $small((int) $row['approved_term'] . ' m · ' . self::percent((float) $row['approved_rate'])) : '—') . '</td>'
        . '<td class="' . $td . ' !sp-whitespace-nowrap">' . ($approved ? self::money($row['monthly_payment']) : '—') . '</td>'
        . '<td class="' . $td . ' !sp-whitespace-nowrap">' . ($row['balance'] !== null ? self::money($row['balance']) : '—') . '</td>'
        . '<td class="' . $td . '">' . self::status((string) $row['status']) . ((int) $row['policy_override'] === 1 ? $small('Fuera de política') : '') . '</td>'
        . '<td class="' . $td . ' !sp-whitespace-nowrap">' . date('d/m/Y', (int) $row['created_at']) . ((int) $row['decided_at'] > 0 ? $small('Decidido ' . date('d/m/Y', (int) $row['decided_at'])) : '') . '</td>'
        . '<td class="' . $td . '">' . ($canOpen ? '<button type="button" class="' . $ui::NAVY . ' !sp-whitespace-nowrap" data-owner-credit-open data-credit-id="' . (int) $row['id'] . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Abrir liquidador</button>' : '') . '</td></tr>';
    }
    return $html . '</tbody></table></div></div>';
  }

  /** @param array<string,mixed> $work */
  public function workspace(array $work, bool $canDecide, bool $canManage): string
  {
    $ui = UI::class;
    $credit = $work['credit'];
    $contract = $work['contract'];
    $ev = $work['evaluation'];
    $c = $work['conditions'];
    $editable = $credit['status'] === 'pendiente' && $canDecide;
    $lock = $editable ? '' : ' readonly';
    // Editables en blanco; de solo lectura en gris.
    $inputClass = $editable ? UI::field('!sp-bg-white') : $ui::INPUT . ' ' . self::READONLY;
    $field = static fn(string $label, string $control, string $class = ''): string => '<label class="!sp-block' . ($class !== '' ? ' ' . $class : '') . '"><span class="' . $ui::LABEL . '">' . self::e($label) . '</span>' . $control . '</label>';
    $money = static fn(string $name, float $value): string => '<input class="' . $inputClass . '" name="' . $name . '" inputmode="numeric" data-owner-credit-money value="' . self::e(self::money($value)) . '"' . $lock . '>';
    $select = static fn(string $name, string $options): string => '<select class="' . $inputClass . '" name="' . $name . '"' . ($editable ? '' : ' disabled') . '>' . $options . '</select>';
    $chip = static fn(string $label, string $value): string => '<span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-px-2.5 !sp-py-1 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-[10px] !sp-text-service-muted">' . self::e($label) . ' <b class="!sp-text-service-navy">' . $value . '</b></span>';
    $years = $contract['inicio_ts'] > 0 && $contract['fin_ts'] > 0 ? number_format(($contract['fin_ts'] - $contract['inicio_ts']) / 86400 / 365, 1, ',', '.') : '—';

    $endTile = $editable
      ? '<div class="!sp-rounded-lg !sp-border !sp-border-solid !sp-border-service-yellow !sp-bg-amber-50/40 !sp-p-3"><span class="!sp-block !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-mb-1.5">Fin del contrato</span>'
        . '<input class="' . $ui::INPUT . ' !sp-h-8 !sp-bg-white" type="date" name="contract_end" value="' . ($contract['fin_ts'] > 0 ? date('Y-m-d', $contract['fin_ts']) : '') . '" aria-label="Fin del contrato">'
        . '<span class="!sp-block !sp-mt-1.5 !sp-text-[10px] !sp-text-service-muted">Si la cambias, se guarda en el contrato al aprobar o rechazar.</span></div>'
      : self::tile('Fin del contrato', $contract['fin_ts'] > 0 ? date('d/m/Y', $contract['fin_ts']) : '—');

    $deductions = '';
    foreach (OwnerCreditService::DEDUCTIONS as $key => $label) $deductions .= $field('(−) ' . $label, $money('ded_' . $key, (float) ($ev['deductions'][$key] ?? 0)));
    $purposeOptions = '';
    foreach (OwnerCreditSettings::PURPOSES as $purpose) $purposeOptions .= '<option' . ($purpose === $credit['purpose'] ? ' selected' : '') . '>' . self::e($purpose) . '</option>';
    $vacancyOptions = '';
    foreach (self::VACANCY as $key => $label) $vacancyOptions .= '<option value="' . $key . '"' . ($key === $ev['vacancy'] ? ' selected' : '') . '>' . self::e($label) . '</option>';

    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-5" data-owner-credit-form data-credit-id="' . (int) $credit['id'] . '" autocomplete="off">'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2">' . self::status((string) $credit['status'])
      . $chip('Contrato', '#' . self::e($contract['code'])) . ((int) $credit['quote_id'] > 0 && $editable ? self::beneficiarySelect($credit) : '') . $chip(OwnerCreditService::beneficiaryLabel($credit), self::e($credit['owner_name']) . ((string) ($credit['owner_document'] ?? '') !== '' ? ' · ' . self::e($credit['owner_document']) : '')) . $chip('Inmueble', self::e($credit['property']))
      . ((int) $credit['quote_id'] > 0 ? $chip('Cotización', '#' . (int) $credit['quote_id']) : $chip('Origen', 'Manual'))
      . '<button type="button" class="' . $ui::NAVY . ' !sp-ml-auto" data-owner-credit-pdf title="Documento interno para funcionarios">' . $ui::icon('document', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Ver PDF</button></div>'

      . '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-12 !sp-gap-5 !sp-items-start">'
      // Columna de datos y entradas.
      . '<div class="lg:!sp-col-span-7 !sp-space-y-5">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('building') . 'Contrato de arriendo vigente</h4>' . self::guide('contrato', [
        'Fin del contrato' => 'Define cuántos meses se puede prestar: se cuentan los meses completos (de 30 días) que faltan hasta esa fecha. Si el contrato fue renovado y la fecha está desactualizada, corrígela aquí; se guarda en el contrato al aprobar o rechazar.',
        'Años arrendados' => 'Tiempo entre el inicio y el fin del contrato. Un historial largo da más confianza.',
        'Siniestro' => 'Lo marca Cartera cuando el contrato fue reportado a la aseguradora por mora. Un contrato siniestrado incumple la política.',
      ]) . '<div class="!sp-grid !sp-grid-cols-2 sm:!sp-grid-cols-3 !sp-gap-3">'
      . self::tile('Inicio del contrato', $contract['inicio_ts'] > 0 ? date('d/m/Y', $contract['inicio_ts']) : '—') . $endTile . self::tile('Años arrendados', $years)
      . self::tile('Estado', self::e($contract['estado'] ?: '—')) . self::tile('Aseguradora', self::e($contract['aseguradora'] ?: '—'))
      . self::tile('Siniestro', $contract['siniestrado'] ? 'Siniestrado' : 'No', $contract['siniestrado'] ? 'bad' : '')
      . '</div></section>'

      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('cash') . 'Ingreso del propietario</h4>' . self::guide('ingreso', [
        'Ingreso bruto (canon)' => 'Lo que paga el arrendatario cada mes. Si ya tuvo aumento, ajústalo: solo cambia este crédito, no el contrato.',
        'Descuentos' => 'Lo que se le descuenta al propietario cada mes antes de girarle: comisión de administración con IVA, garantía inmobiliaria (seguro de arrendamiento) y póliza de servicios públicos. Salen del contrato de mandato; sin mandato se estima un porcentaje del canon (Configuración).',
        'Ingreso neto (INO)' => 'Canon menos descuentos: lo que realmente recibe el propietario y con lo que paga la cuota.',
      ]) . '<p class="' . self::NOTE . ' !sp-mb-3">' . self::e($work['income_source']) . '</p>'
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-4">' . $field('Ingreso bruto (canon)', $money('gross', $ev['gross'])) . $deductions . '</div>'
      . ($editable ? '<p class="' . self::NOTE . ' !sp-mt-3">Puedes ajustar el canon (por ejemplo, si ya tuvo aumento) y los descuentos: solo cambian este crédito, no el contrato.</p>' : '') . '</section>'

      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $ui::icon('document') . 'Información de la solicitud</h4>' . self::guide('solicitud', [
        'Valor solicitado' => 'Lo que pide el propietario. Si viene de una cotización, es el total de la cotización.',
        'Plazo solicitado' => 'Meses en que quiere pagar. Escríbelo según lo que pidió; no puede superar los meses que faltan para el fin del contrato, porque las cuotas se descuentan del canon.',
        'Destinación' => 'Para qué usará el dinero.',
        'Probabilidad de que desocupen' => 'Tu criterio sobre el riesgo de que el arrendatario se vaya antes. Baja: se respeta lo pedido con la tasa de política. Media: plazo hasta el fin del contrato con la tasa de referencia. Alta: máximo 3 meses con la tasa de riesgo.',
      ]) . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-4">'
      . $field('Fecha de solicitud', '<input class="' . $ui::INPUT . ' ' . self::READONLY . '" value="' . date('d/m/Y', (int) $credit['created_at']) . '" readonly>')
      . $field('Valor solicitado', $money('requested_amount', (float) ($credit['requested_amount'] ?? 0)))
      . $field('Plazo solicitado (meses)', '<input class="' . $inputClass . '" name="requested_term" type="number" min="1" max="60" placeholder="Escribe los meses" value="' . ((int) $credit['requested_term'] > 0 ? (int) $credit['requested_term'] : '') . '"' . $lock . '>')
      . $field('Destinación del crédito', $select('purpose', $purposeOptions))
      . $field('Probabilidad de que desocupen', $select('vacancy', $vacancyOptions), 'sm:!sp-col-span-2')
      . '</div></section>'

      . '<section class="' . self::SECTION . ' !sp-border-service-yellow"><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-3"><h4 class="' . self::H4 . ' !sp-mb-0">' . $ui::icon('edit') . 'Condiciones según el analista</h4>'
      . ($editable ? '<button type="button" class="' . $ui::SECONDARY . ' !sp-text-[10px]" data-owner-credit-use-suggested>' . $ui::icon('refresh', '!sp-w-3 !sp-h-3') . 'Usar condición sugerida</button>' : '') . '</div>'
      . self::guide('analista', [
        'Qué son' => 'Las condiciones que realmente se aprueban. Arrancan con la condición sugerida y las puedes ajustar.',
        'Valor a colocar' => 'El dinero que se presta.',
        'Plazo' => 'Número de cuotas mensuales.',
        'Tasa N.M.V' => 'Tasa nominal mes vencido: el interés de cada mes sobre el saldo. No se puede aprobar por encima de la usura (Configuración).',
        'Cuota' => 'Fija, calculada como la función PAGO de Excel; se descuenta de los giros del canon.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-3 !sp-gap-4">'
      . $field('Valor a colocar', $money('amount', $c['amount']))
      . $field('Plazo (meses)', '<input class="' . $inputClass . '" name="term" type="number" min="1" max="60" value="' . (int) $c['term'] . '"' . $lock . '>')
      . $field('Tasa N.M.V (%)', '<input class="' . $inputClass . '" name="rate" inputmode="decimal" value="' . self::e(number_format($c['rate'] * 100, 4, ',', '')) . '"' . $lock . '>')
      . '</div></section>'
      . '</div>'
      // Columna de resultados (se refresca al editar).
      . '<div class="lg:!sp-col-span-5 !sp-space-y-5" data-owner-credit-results>' . $this->results($work) . '</div>'
      . '</div>'

      . '<div data-owner-credit-schedule>' . $this->schedule($work) . '</div>'
      . $this->history($work)
      . $this->actions($credit, $canDecide, $canManage)
      . '</form>';
  }

  /** Bloques calculados que se refrescan al editar el formulario. @param array<string,mixed> $work */
  public function results(array $work): string
  {
    $ui = UI::class;
    $ev = $work['evaluation'];
    $s = $ev['suggested'];
    $endChanged = (int) ($work['contract']['fin_saved_ts'] ?? 0) !== (int) ($work['contract']['fin_ts'] ?? 0);
    $termError = (string) ($work['term_error'] ?? '');
    $policies = '';
    foreach ($work['policies'] as $policy) {
      $policies .= '<li class="!sp-flex !sp-gap-2.5 !sp-items-start !sp-py-2.5 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0">'
        . '<span class="!sp-mt-0.5 !sp-flex !sp-items-center !sp-justify-center !sp-w-5 !sp-h-5 !sp-rounded-full ' . ($policy['ok'] ? '!sp-bg-emerald-50 !sp-text-emerald-600' : '!sp-bg-red-50 !sp-text-red-600') . '">' . $ui::icon($policy['ok'] ? 'check' : 'close', '!sp-w-3 !sp-h-3') . '</span>'
        . '<span class="!sp-min-w-0"><b class="!sp-block !sp-text-[11px] ' . ($policy['ok'] ? '!sp-text-service-navy' : '!sp-text-red-700') . '">' . self::e($policy['label']) . '</b><span class="!sp-block !sp-text-[10px] !sp-text-service-muted">' . self::e($policy['detail']) . '</span></span></li>';
    }
    return ($termError !== '' ? self::alert($termError, 'bad') : '')
      . ($endChanged ? self::alert('Estás calculando con la fecha fin ' . date('d/m/Y', (int) $work['contract']['fin_ts']) . '. Se guardará en el contrato al aprobar o rechazar el crédito.', 'info') : '')
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('chart') . 'Política de créditos</h4>' . self::guide('politica', [
        'Valor máximo a prestar' => 'Tope = ingreso neto × los meses de política (Configuración, hoy ' . (int) $work['settings']['income_months'] . ').',
        'Meses máximos' => 'Meses completos que faltan para el fin del contrato.',
        'Tasa ajustada' => 'Tasa de referencia menos el descuento de política.',
        'Cuota de política' => 'La cuota si se prestara el valor máximo al plazo máximo.',
        'Meses / valor que excede' => 'Diferencia entre la política y lo pedido; en rojo cuando lo pedido la supera.',
        'Tasa para el riesgo' => 'Tasa que compensa el exceso: cobra la usura mensual sobre la parte que supera el tope.',
      ]) . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3">'
      . self::tile('Ingreso neto (INO)', self::money($ev['net_income']), 'key')
      . self::tile('Valor máximo a prestar', self::money($ev['max_amount']), 'key')
      . self::tile('Meses máximos', (string) $ev['max_months'])
      . self::tile('Tasa ajustada', self::percent($ev['policy_rate']))
      . self::tile('Cuota de política', self::money($ev['policy_payment']))
      . self::tile('Tasa para el riesgo', self::percent($ev['risk_rate']))
      . self::tile('Meses que excede', (string) $ev['exceed_months'], $ev['exceed_months'] < 0 ? 'bad' : '')
      . self::tile('Valor que excede', self::money($ev['exceed_amount'], true), $ev['exceed_amount'] < 0 ? 'bad' : '')
      . '</div></section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('target') . 'Condición sugerida · probabilidad ' . self::e($ev['vacancy']) . '</h4>'
      . self::guide('sugerida', [
        'Qué es' => 'La propuesta automática del simulador según la probabilidad de desocupación elegida. Úsala con "Usar condición sugerida" o ajusta las condiciones del analista.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3" data-owner-credit-suggested data-amount="' . round((float) $s['amount']) . '" data-term="' . (int) $s['term'] . '" data-rate="' . self::e(number_format($s['rate'] * 100, 4, ',', '')) . '">'
      . self::tile('Plazo', $s['term'] . ' meses') . self::tile('Tasa', self::percent($s['rate']))
      . self::tile('Valor a colocar', self::money($s['amount'])) . self::tile('Cuota mensual', self::money($s['payment']), 'key')
      . '</div>' . (!empty($s['rate_capped']) ? '<p class="' . self::NOTE . ' !sp-mt-2">La tasa de esta condición superaba la usura; se sugiere la usura mes vencido (' . self::percent($ev['usury_monthly_legal']) . ').</p>' : '') . '</section>'
      . '<section class="' . self::SECTION . '"><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-1"><h4 class="' . self::H4 . ' !sp-mb-0">' . self::icon('shield') . 'Políticas</h4>'
      . ($work['policies_ok'] ? '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-emerald-700">Cumple todas</span>' : '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-red-700">Incumple políticas</span>') . '</div>'
      . self::guide('politicas', [
        'Requisitos' => 'Contrato activo y no próximo a vencer, plazo dentro de la vigencia, sin siniestro, sin otros créditos vigentes del propietario, valor dentro del tope, tasa bajo la usura y cuota cubierta por el ingreso neto.',
        'Si alguna está en rojo' => 'Puedes aprobar igual, pero con justificación: el crédito queda marcado "Fuera de política" y la razón queda en el historial.',
      ])
      . '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $policies . '</ul>'
      . ($work['policies_ok'] ? '' : '<p class="' . self::NOTE . ' !sp-mt-2">Para aprobar fuera de política escribe la justificación al final; quedará en el historial.</p>')
      . '</section>';
  }

  /** @param array<string,mixed> $work */
  public function schedule(array $work): string
  {
    $c = $work['conditions'];
    $th = '!sp-text-right !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-3 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200 first:!sp-text-left';
    $td = '!sp-text-right !sp-px-3 !sp-py-2.5 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100 first:!sp-text-left';
    $rows = '';
    foreach ($work['schedule'] as $line) {
      $rows .= '<tr><td class="' . $td . ' !sp-font-semibold">' . $line['mes'] . '</td><td class="' . $td . '">' . self::money($line['saldo_inicial']) . '</td><td class="' . $td . ' !sp-font-semibold">' . self::money($line['cuota']) . '</td><td class="' . $td . '">' . self::money($line['interes']) . '</td><td class="' . $td . '">' . self::money($line['abono']) . '</td><td class="' . $td . '">' . self::money($line['saldo']) . '</td></tr>';
    }
    $head = '';
    foreach (['Mes', 'Saldo de capital', 'Cuota', 'Intereses', 'Abono a capital', 'Saldo'] as $label) $head .= '<th class="' . $th . '">' . $label . '</th>';
    return '<section class="' . self::SECTION . '"><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-mb-3"><h4 class="' . self::H4 . ' !sp-mb-0">' . UI::icon('calendar') . 'Tabla de amortización</h4>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-gap-2">'
      . '<span class="!sp-px-2.5 !sp-py-1 !sp-rounded-full !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-[10px]">Cuota <b>' . self::money($c['payment']) . '</b></span>'
      . '<span class="!sp-px-2.5 !sp-py-1 !sp-rounded-full !sp-bg-slate-100 !sp-text-[10px]">Intereses <b>' . self::money($c['total_interest']) . '</b></span>'
      . '<span class="!sp-px-2.5 !sp-py-1 !sp-rounded-full !sp-bg-slate-100 !sp-text-[10px]">Total a pagar <b>' . self::money($c['amount'] + $c['total_interest']) . '</b></span></div></div>'
      . self::guide('tabla', [
        'Cómo leerla' => 'Cada fila es un mes. La cuota se divide en intereses (saldo × tasa) y abono a capital; el saldo baja hasta $0. La cuota se descuenta del giro del canon al propietario.',
      ])
      . ($rows === '' ? '<p class="' . self::NOTE . '">Indica valor y plazo para ver la tabla.</p>' : '<div class="!sp-overflow-x-auto !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200"><table class="!sp-w-full !sp-border-collapse !sp-text-[11px] !sp-text-service-navy !sp-min-w-[560px]"><thead class="!sp-bg-slate-50"><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>')
      . '</section>';
  }

  /** @param array<string,mixed> $work */
  private function history(array $work): string
  {
    $item = '!sp-py-2 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0 !sp-text-[11px]';
    $others = '';
    foreach ($work['other_credits'] as $row) {
      $others .= '<li class="' . $item . ' !sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2"><b>#' . (int) $row['id'] . '</b> Contrato ' . self::e($row['contract_code']) . ' ' . self::status((string) $row['status']) . ' <span class="!sp-text-service-muted">' . self::money((float) ($row['approved_amount'] ?? 0) ?: (float) $row['requested_amount']) . ' · ' . date('d/m/Y', (int) $row['created_at']) . '</span></li>';
    }
    $labels = ['solicitado' => 'Solicitud registrada', 'reabierto' => 'Solicitud reabierta', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'pagado' => 'Marcado como pagado', 'anulado' => 'Anulado', 'fecha_fin' => 'Fecha fin del contrato corregida'];
    $events = '';
    foreach ($work['events'] as $event) {
      $detail = trim((string) ($event['details']['nota'] ?? $event['details']['motivo'] ?? ''));
      $events .= '<li class="' . $item . '"><b>' . self::e($labels[$event['action']] ?? $event['action']) . '</b> <span class="!sp-text-service-muted">· ' . date('d/m/Y g:i a', (int) $event['created_at']) . ($event['actor_name'] !== '' ? ' · ' . self::e($event['actor_name']) : '') . '</span>' . ($detail !== '' ? '<span class="!sp-block !sp-text-[10px] !sp-text-service-muted">' . self::e($detail) . '</span>' : '') . '</li>';
    }
    return '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-5">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('user') . 'Otros créditos del propietario</h4>' . ($others !== '' ? '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $others . '</ul>' : '<p class="' . self::NOTE . '">Sin otros créditos registrados.</p>') . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('clock') . 'Historial del crédito</h4><ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $events . '</ul></section>'
      . '</div>';
  }

  /** @param array<string,mixed> $credit */
  private function actions(array $credit, bool $canDecide, bool $canManage): string
  {
    $ui = UI::class;
    $status = (string) $credit['status'];
    $danger = '!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-4 !sp-py-2.5 !sp-rounded-lg !sp-bg-white !sp-text-red-700 !sp-border !sp-border-solid !sp-border-red-200 !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-red-50 disabled:!sp-opacity-50';
    $buttons = '';
    if ($status === 'pendiente' && $canDecide) {
      $buttons .= '<button type="button" class="' . $danger . '" data-owner-credit-decide="rechazado">' . $ui::icon('close', '!sp-w-3.5 !sp-h-3.5') . 'Rechazar</button>'
        . '<button type="button" class="' . $ui::PRIMARY . '" data-owner-credit-decide="aprobado">' . $ui::icon('check', '!sp-w-3.5 !sp-h-3.5') . 'Aprobar crédito</button>';
    }
    if ($status === 'aprobado' && $canManage) {
      $buttons .= '<button type="button" class="' . $ui::NAVY . ' !sp-text-xs !sp-px-4 !sp-py-2.5" data-owner-credit-close="pagado">' . $ui::icon('check', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Marcar como pagado</button>';
    }
    if (in_array($status, ['pendiente', 'aprobado'], true) && $canManage) {
      $buttons .= '<button type="button" class="' . $danger . '" data-owner-credit-close="anulado">Anular</button>';
    }
    $note = (string) ($credit['decision_note'] ?? '');
    $decision = $status !== 'pendiente'
      ? '<div class="!sp-rounded-lg !sp-bg-slate-50 !sp-p-3 !sp-mb-3 !sp-text-[11px]"><b>Decisión:</b> ' . self::status($status) . ((string) $credit['decided_by_name'] !== '' ? ' por ' . self::e($credit['decided_by_name']) : '') . ((int) $credit['decided_at'] > 0 ? ' el ' . date('d/m/Y', (int) $credit['decided_at']) : '') . ($note !== '' ? '<p class="' . self::NOTE . ' !sp-mt-1.5">' . nl2br(self::e($note)) . '</p>' : '') . '</div>'
      : '';
    if ($buttons === '') return $decision !== '' ? '<section class="' . self::SECTION . '">' . $decision . '</section>' : '';
    return '<section class="' . self::SECTION . '">' . $decision
      . '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . ($status === 'pendiente' ? 'Observación / justificación' : 'Observación') . '</span>'
      . '<textarea class="' . $ui::INPUT . ' !sp-h-20 !sp-py-2 !sp-resize-y" name="note" rows="3" maxlength="2000" placeholder="Obligatoria para rechazar, para aprobar fuera de política, para marcar pagado o anular."></textarea></label>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-end !sp-gap-3 !sp-mt-4" data-owner-credit-actions>' . $buttons . '</div>'
      . '<p class="!sp-m-0 !sp-mt-2 !sp-text-[11px] !sp-text-right" data-owner-credit-status role="status" aria-live="polite"></p></section>';
  }

  /** @param array<string,mixed> $settings */
  public function settingsForm(array $settings): string
  {
    $ui = UI::class;
    $field = static fn(string $label, string $control, string $hint = ''): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . self::e($label) . '</span>' . $control . ($hint !== '' ? '<span class="!sp-block !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">' . self::e($hint) . '</span>' : '') . '</label>';
    $input = static fn(string $name, string $value, string $extra = ''): string => '<input class="' . $ui::INPUT . '" name="' . $name . '" value="' . self::e($value) . '"' . $extra . '>';
    $yesNo = static fn(string $name, bool $value): string => '<select class="' . $ui::INPUT . '" name="' . $name . '"><option value="1"' . ($value ? ' selected' : '') . '>Sí</option><option value="0"' . ($value ? '' : ' selected') . '>No</option></select>';
    $pct = static fn(float $value, int $decimals = 2): string => number_format($value * 100, $decimals, ',', '');
    $auto = (bool) $settings['usury_auto'];
    $lock = $auto ? ' readonly' : '';
    $source = $auto
      ? 'Automática desde datos.gov.co (Superfinanciera): interés bancario corriente de consumo y ordinario ' . $pct((float) $settings['usury_bank_rate']) . ' % × 1,5'
        . ((string) $settings['usury_resolution'] !== '' ? ', resolución ' . $settings['usury_resolution'] : '')
        . ((string) $settings['usury_valid_until'] !== '' ? ', vigente hasta ' . date('d/m/Y', (int) strtotime((string) $settings['usury_valid_until'])) : '') . '.'
        . ((int) $settings['usury_synced_at'] > 0 ? ' Última consulta: ' . date('d/m/Y g:i a', (int) $settings['usury_synced_at']) . '.' : '')
      : 'Manual: se usa la tasa escrita aquí.';
    $grid = '!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-4';
    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-5" data-owner-credit-settings-form autocomplete="off">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('chart') . 'Tasa de usura</h4><p class="' . self::NOTE . ' !sp-mb-3">' . self::e($source) . '</p>'
      . ((string) $settings['usury_sync_error'] !== '' ? self::alert('No se pudo actualizar: ' . $settings['usury_sync_error'] . ' Se conserva la última tasa.', 'bad') : '')
      . '<div class="' . $grid . '">'
      . $field('Origen de la tasa', '<select class="' . $ui::INPUT . '" name="usury_auto"><option value="1"' . ($auto ? ' selected' : '') . '>Automática (datos.gov.co)</option><option value="0"' . ($auto ? '' : ' selected') . '>Manual</option></select>')
      . $field('Tasa de usura (E.A., %)', $input('usury_annual', $pct($settings['usury_annual']), ' inputmode="decimal"' . $lock), 'La tasa mensual del crédito no puede superar su equivalente mes vencido.')
      . $field('Vigente desde', '<input class="' . $ui::INPUT . '" type="date" name="usury_updated_at" value="' . self::e($settings['usury_updated_at']) . '"' . $lock . '>')
      . '</div>' . ($auto ? '<button type="button" class="' . $ui::SECONDARY . ' !sp-mt-4" data-owner-credit-usury-refresh>' . $ui::icon('refresh', '!sp-w-3.5 !sp-h-3.5') . 'Actualizar ahora desde datos.gov.co</button>' : '') . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('cash') . 'Variables de entrada</h4><div class="' . $grid . '">'
      . $field('Tasa de referencia N.M.V (%)', $input('reference_rate', $pct($settings['reference_rate']), ' inputmode="decimal"'), 'Tasa normal de crédito de consumo de referencia.')
      . $field('Descuento por política (%)', $input('policy_discount', $pct($settings['policy_discount'], 1), ' inputmode="decimal"'))
      . $field('Descuento sin mandato (% del canon)', $input('no_mandate_deduction', $pct($settings['no_mandate_deduction'], 1), ' inputmode="decimal"'), 'Cuando el contrato no tiene mandato con comisión registrada.')
      . $field('Meses de ingreso neto a prestar', '<input class="' . $ui::INPUT . '" type="number" name="income_months" min="1" max="24" value="' . (int) $settings['income_months'] . '">', 'Tope = ingreso neto × estos meses.')
      . $field('Meses mínimos de contrato restantes', '<input class="' . $ui::INPUT . '" type="number" name="min_remaining_months" min="0" max="60" value="' . (int) $settings['min_remaining_months'] . '">', 'Por debajo se alerta "contrato próximo a vencer".')
      . '</div></section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('bolt') . 'Avisos</h4><div class="' . $grid . '">'
      . $field('Correo al propietario al decidir', $yesNo('notify_owner_email', (bool) $settings['notify_owner_email']), 'Condiciones y tabla de amortización.')
      . $field('WhatsApp al propietario al decidir', $yesNo('notify_owner_whatsapp', (bool) $settings['notify_owner_whatsapp']), 'Plantilla scm_credito_propietario_decision_v1.')
      . $field('WhatsApp a funcionarios', $yesNo('notify_staff_whatsapp', (bool) $settings['notify_staff_whatsapp']), 'Al solicitar y al decidir. Plantilla scm_credito_propietario_funcionario_v2.')
      . '</div><p class="' . self::NOTE . ' !sp-mt-3">El correo interno siempre se envía. Los funcionarios se eligen en Notificaciones internas › Créditos a propietarios.</p></section>'
      . '<div class="!sp-flex !sp-items-center !sp-justify-end !sp-gap-3"><p class="!sp-m-0 !sp-text-[11px]" data-owner-credit-status role="status" aria-live="polite"></p><button type="submit" class="' . $ui::PRIMARY . '">' . $ui::icon('check', '!sp-w-3.5 !sp-h-3.5') . 'Guardar configuración</button></div></form>';
  }

  public function newForm(): string
  {
    $ui = UI::class;
    $options = '';
    foreach (OwnerCreditSettings::PURPOSES as $purpose) $options .= '<option>' . self::e($purpose) . '</option>';
    $field = static fn(string $label, string $control): string => '<label class="!sp-block"><span class="' . $ui::LABEL . '">' . $label . '</span>' . $control . '</label>';
    $step = static fn(string $n): string => '<span class="!sp-flex !sp-items-center !sp-justify-center !sp-w-5 !sp-h-5 !sp-rounded-full !sp-bg-service-navy !sp-text-white !sp-text-[10px]">' . $n . '</span>';
    $required = ' <em class="!sp-not-italic !sp-text-red-600">*</em>';
    // Como en "Crear caso": primero se busca y elige el contrato, luego se completan los datos de la solicitud.
    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-5" data-owner-credit-new-form autocomplete="off"><input type="hidden" name="contract_id" value="">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $step('1') . '¿Para quién es el crédito?</h4>'
      . self::guide('nuevo-tipo', [
        'Tipos' => 'Propietario, arrendatario, codeudor, externo o funcionario. Por ahora solo está habilitado el crédito a propietarios; los demás se habilitarán con su propia evaluación.',
      ])
      . self::beneficiaryTypes(array_keys(OwnerCreditService::BENEFICIARY_TYPES), 'propietario') . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $step('2') . 'Busca el contrato</h4>'
      . self::guide('nuevo-contrato', [
        'Por qué un contrato' => 'El crédito siempre queda ligado a un contrato de arrendamiento, porque las cuotas se descuentan de su canon. Busca por documento (cédula o NIT del propietario, arrendatario, codeudor o de los propietarios del mandato), número de contrato, inmueble SIMI, propietario o arrendatario.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-[180px_minmax(0,1fr)_auto] !sp-gap-3 !sp-items-end">'
      . $field('Buscar por', '<select class="' . $ui::INPUT . '" data-owner-credit-search-by><option value="documento" selected>Documento (cédula o NIT)</option><option value="contrato">Contrato</option><option value="inmueble">Inmueble SIMI</option><option value="propietario">Propietario</option><option value="arrendatario">Arrendatario</option></select>')
      . $field('Dato a buscar', '<input class="' . $ui::INPUT . '" data-owner-credit-search-query placeholder="Ej. 1047455289, 817 o nombre">')
      . '<button type="button" class="' . $ui::NAVY . ' !sp-h-10 !sp-text-xs !sp-px-4" data-owner-credit-search>' . $ui::icon('filter', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Buscar</button></div>'
      . '<p class="' . self::NOTE . ' !sp-mt-3" data-owner-credit-search-status></p><div class="!sp-grid !sp-gap-2 !sp-max-h-72 !sp-overflow-y-auto !sp-mt-2" data-owner-credit-search-results></div>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2 !sp-mt-2 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-service-yellow !sp-bg-amber-50/40 !sp-text-xs" data-owner-credit-picked hidden></div></section>'
      . '<section class="' . self::SECTION . '" data-owner-credit-owner-step hidden><h4 class="' . self::H4 . '">' . $step('3') . '¿Cuál propietario?</h4>'
      . self::guide('nuevo-beneficiario', [
        'Quiénes aparecen' => 'Los propietarios registrados en el contrato de mandato del inmueble (pueden ser varios). Si el contrato no tiene mandato, aparece el propietario del contrato de arrendamiento.',
        'Para qué sirve' => 'El crédito, sus avisos y la revisión de otros créditos vigentes quedan a nombre de esa persona.',
      ])
      . '<div class="!sp-grid !sp-gap-2" data-owner-credit-owners role="radiogroup" aria-label="Propietario beneficiario"></div></section>'
      . '<section class="' . self::SECTION . '" data-owner-credit-request hidden><h4 class="' . self::H4 . '">' . $step('4') . 'Datos de la solicitud</h4>'
      . self::guide('nuevo-datos', [
        'Qué pasa al registrar' => 'Solo se registra lo que pide el propietario: todavía no se aprueba nada. Se abre el liquidador para evaluarlo y ahí se aprueba o rechaza.',
        'Plazo solicitado' => 'Escribe los meses que pidió el propietario. No puede superar los meses que faltan para el fin del contrato.',
        'Fin del contrato' => 'Si el contrato fue renovado y la fecha está desactualizada, corrígela: se guarda en el contrato.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-4">'
      . $field('Valor solicitado' . $required, '<input class="' . $ui::INPUT . '" name="amount" inputmode="numeric" data-owner-credit-money required>')
      . $field('Fin del contrato', '<input class="' . $ui::INPUT . '" type="date" name="contract_end">')
      . $field('Plazo solicitado (meses)' . $required, '<input class="' . $ui::INPUT . '" name="term" type="number" min="1" max="60" placeholder="Escribe los meses" required>')
      . $field('Destinación', '<select class="' . $ui::INPUT . '" name="purpose">' . $options . '</select>')
      . '</div><p class="' . self::NOTE . ' !sp-mt-3">El plazo no puede superar los meses que faltan para el fin del contrato. Si la fecha fin está desactualizada, corrígela aquí: se guardará en el contrato.</p>'
      . '<p class="!sp-m-0 !sp-mt-1 !sp-text-[11px] !sp-font-semibold" data-owner-credit-term-hint></p></section>'
      . '<div class="!sp-flex !sp-items-center !sp-justify-end !sp-gap-3"><p class="!sp-m-0 !sp-text-[11px]" data-owner-credit-status role="status" aria-live="polite"></p><button type="submit" class="' . $ui::PRIMARY . '">' . $ui::icon('check', '!sp-w-3.5 !sp-h-3.5') . 'Registrar solicitud y evaluar</button></div></form>';
  }

  /**
   * Pregunta "¿Para quién es el crédito?" de la solicitud nueva. Los tipos sin flujo aparecen deshabilitados.
   * @param list<string> $types
   */
  private static function beneficiaryTypes(array $types, string $selected): string
  {
    $html = '';
    foreach ($types as $type) {
      $available = in_array($type, OwnerCreditService::AVAILABLE_BENEFICIARY_TYPES, true);
      $html .= '<label class="!sp-flex !sp-items-center !sp-gap-2 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-xs ' . ($available ? '!sp-bg-white !sp-cursor-pointer hover:!sp-border-service-yellow' : '!sp-bg-slate-50 !sp-text-service-muted !sp-cursor-not-allowed') . '">'
        . '<input type="radio" name="beneficiary_type" value="' . $type . '"' . ($type === $selected ? ' checked' : '') . ($available ? '' : ' disabled') . '>'
        . '<span><strong class="!sp-block ' . ($available ? '!sp-text-service-navy' : '') . '">' . self::e(OwnerCreditService::BENEFICIARY_TYPES[$type]) . '</strong>'
        . ($available ? '' : '<span class="!sp-block !sp-text-[10px]">Próximamente</span>') . '</span></label>';
    }
    return '<div class="!sp-grid !sp-grid-cols-2 sm:!sp-grid-cols-5 !sp-gap-2" role="radiogroup" aria-label="Para quién es el crédito">' . $html . '</div>';
  }

  /** En el liquidador de una cotización: para quién es el crédito (sin funcionario; solo propietario habilitado por ahora). */
  private static function beneficiarySelect(array $credit): string
  {
    $current = (string) ($credit['beneficiary_type'] ?? 'propietario');
    $options = '';
    foreach (OwnerCreditService::QUOTE_BENEFICIARY_TYPES as $type) {
      $available = in_array($type, OwnerCreditService::AVAILABLE_BENEFICIARY_TYPES, true);
      $options .= '<option value="' . $type . '"' . ($type === $current ? ' selected' : '') . ($available ? '' : ' disabled') . '>' . self::e(OwnerCreditService::BENEFICIARY_TYPES[$type]) . ($available ? '' : ' (próximamente)') . '</option>';
    }
    return '<label class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-0.5 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-[10px] !sp-text-service-muted">¿Para quién es el crédito?'
      . '<select class="!sp-h-6 !sp-py-0 !sp-pl-1 !sp-pr-6 !sp-rounded !sp-border-0 !sp-bg-transparent !sp-text-[10px] !sp-font-bold !sp-text-service-navy" name="beneficiary_type" aria-label="Para quién es el crédito">' . $options . '</select></label>';
  }

  public static function status(string $status): string
  {
    return '<span class="!sp-inline-flex !sp-items-center !sp-px-2.5 !sp-py-1 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[10px] !sp-font-bold ' . (self::STATUS_TONES[$status] ?? self::STATUS_TONES['anulado']) . '">' . self::e(OwnerCreditService::STATUSES[$status] ?? $status) . '</span>';
  }

  /**
   * Guía desplegable "¿Qué es esto?" de una sección. @param array<string,string> $items término => explicación
   */
  private static function guide(string $key, array $items): string
  {
    $rows = '';
    foreach ($items as $term => $text) {
      $rows .= '<li class="!sp-py-1"><b class="!sp-text-service-navy">' . self::e($term) . ':</b> ' . self::e($text) . '</li>';
    }
    return '<details class="!sp-mb-3 !sp-rounded-lg !sp-bg-service-lilac !sp-border !sp-border-solid !sp-border-indigo-100" data-owner-credit-guide="' . self::e($key) . '">'
      . '<summary class="!sp-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-cursor-pointer !sp-text-[10px] !sp-font-semibold !sp-text-service-blue">' . self::icon('info', '!sp-w-3.5 !sp-h-3.5') . 'Guía: ¿qué significa cada dato?</summary>'
      . '<ul class="!sp-m-0 !sp-px-3 !sp-pb-3 !sp-pl-7 !sp-list-disc !sp-text-[11px] !sp-leading-relaxed !sp-text-service-muted">' . $rows . '</ul></details>';
  }

  private static function tile(string $label, string $value, string $tone = ''): string
  {
    $box = ['key' => '!sp-border-service-yellow !sp-bg-amber-50/40', 'bad' => '!sp-border-red-200 !sp-bg-red-50/60'][$tone] ?? '!sp-border-slate-200 !sp-bg-slate-50/60';
    return '<div class="!sp-rounded-lg !sp-border !sp-border-solid ' . $box . ' !sp-p-3"><span class="!sp-block !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</span><strong class="!sp-block !sp-mt-1 !sp-text-sm ' . ($tone === 'bad' ? '!sp-text-red-700' : '!sp-text-service-navy') . '">' . $value . '</strong></div>';
  }

  private static function alert(string $text, string $tone): string
  {
    $classes = $tone === 'bad' ? '!sp-bg-red-50 !sp-text-red-700 !sp-border-red-200' : '!sp-bg-blue-50 !sp-text-blue-800 !sp-border-blue-200';
    return '<p class="!sp-m-0 !sp-flex !sp-items-start !sp-gap-2 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid ' . $classes . ' !sp-text-[11px] !sp-font-semibold" role="' . ($tone === 'bad' ? 'alert' : 'status') . '">' . UI::icon('alert', '!sp-w-4 !sp-h-4') . '<span>' . self::e($text) . '</span></p>';
  }

  /** Íconos propios del módulo; el resto se toma de PublicServicesUi. */
  public static function icon(string $name, string $classes = '!sp-w-4 !sp-h-4'): string
  {
    $paths = [
      'cash' => 'M3 7h18v10H3V7zm9 2.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 1 0 0-5zM6 10v4m12-4v4',
      'chart' => 'M4 20V10m6 10V4m6 16v-7m4 7H2',
      'shield' => 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3zm-3 9 2 2 4-4',
      'target' => 'M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18zm0 4a5 5 0 1 0 0 10 5 5 0 1 0 0-10zm0 4a1 1 0 1 0 0 2 1 1 0 1 0 0-2z',
      'info' => 'M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18zM12 11v5m0-8h.01',
    ];
    if (!isset($paths[$name])) return UI::icon($name, $classes);
    return '<svg class="' . $classes . ' !sp-shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="' . $paths[$name] . '"></path></svg>';
  }

  public static function money($value, bool $signed = false): string
  {
    $number = (float) $value;
    return ($signed && $number < 0 ? '−' : '') . '$' . number_format(abs($number), 0, ',', '.');
  }

  public static function percent(float $rate): string
  {
    return number_format($rate * 100, 2, ',', '.') . ' %';
  }

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}
