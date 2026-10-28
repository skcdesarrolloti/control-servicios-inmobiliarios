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
  public function report(array $report, bool $canOpen, bool $canVoid = false): string
  {
    $ui = UI::class;
    $t = $report['totals'];
    $metric = static fn(string $label, string $value, string $hint, string $icon): string => '<article class="' . $ui::CARD . ' !sp-p-4 !sp-flex !sp-gap-3 !sp-items-start">'
      . '<span class="!sp-p-2 !sp-rounded-lg !sp-bg-service-lilac !sp-text-service-blue">' . self::icon($icon, '!sp-w-4 !sp-h-4') . '</span>'
      . '<div class="!sp-min-w-0"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . self::e($label) . '</p><strong class="!sp-block !sp-mt-1 !sp-text-lg !sp-text-service-navy">' . $value . '</strong>'
      . ($hint !== '' ? '<p class="!sp-m-0 !sp-mt-0.5 !sp-text-[10px] !sp-text-service-muted">' . self::e($hint) . '</p>' : '') . '</div></article>';
    $html = '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 xl:!sp-grid-cols-5 !sp-gap-4 !sp-mb-5" data-owner-credit-total="' . (int) $t['count'] . '">'
      . $metric('Solicitudes', (string) $t['count'], $t['pendiente'] . ' pendientes de evaluar', 'document')
      . $metric('Aprobados', (string) ($t['aprobado'] + $t['pagado']), $t['rechazado'] . ' rechazados', 'check')
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
        . '<td class="' . $td . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-2" data-owner-credit-actions>'
        . ($canOpen ? '<button type="button" class="' . $ui::NAVY . ' !sp-whitespace-nowrap" data-owner-credit-open data-credit-id="' . (int) $row['id'] . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Ver solicitud</button>' : '')
        . ($canOpen && (int) ($row['promissory_id'] ?? 0) > 0 ? '<button type="button" class="' . $ui::SECONDARY . ' !sp-whitespace-nowrap" data-owner-credit-list-promissory="' . (int) $row['promissory_id'] . '" data-number="' . self::e($row['promissory_number']) . '" data-owner="' . self::e($row['owner_name']) . '" title="' . self::e('Pagaré ' . $row['promissory_number'] . ' firmado') . '">' . $ui::icon('document', '!sp-w-3 !sp-h-3') . 'Pagaré</button>' : '')
        . ($canVoid && $row['status'] === 'pendiente' ? '<button type="button" class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-red-200 !sp-text-red-700 !sp-text-xs !sp-font-semibold !sp-whitespace-nowrap !sp-cursor-pointer hover:!sp-bg-red-50" data-owner-credit-void data-credit-id="' . (int) $row['id'] . '">' . $ui::icon('close', '!sp-w-3 !sp-h-3') . 'Anular</button>' : '')
        . '</div></td></tr>';
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
    $analyst = $ev['analyst'] ?? ['siniestro' => $contract['siniestrado'], 'siniestrados' => [], 'has_credits' => false];
    $yesNo = static fn(string $name, bool $value, string $label): string => '<select class="' . $inputClass . '" name="' . $name . '" aria-label="' . self::e($label) . '"' . ($editable ? '' : ' disabled') . '><option value="no"' . ($value ? '' : ' selected') . '>No</option><option value="si"' . ($value ? ' selected' : '') . '>Sí</option></select>';
    $siniestroTile = $editable
      ? '<div class="!sp-rounded-lg !sp-border !sp-border-solid ' . ($analyst['siniestro'] ? '!sp-border-red-200 !sp-bg-red-50/60' : '!sp-border-service-yellow !sp-bg-amber-50/40') . ' !sp-p-3"><span class="!sp-block !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-mb-1.5">¿Siniestrado?</span>' . $yesNo('siniestro', (bool) $analyst['siniestro'], '¿Algún contrato siniestrado?')
        . '<span class="!sp-block !sp-mt-1.5 !sp-text-[10px] !sp-text-service-muted">' . self::e($analyst['siniestrados'] !== [] ? 'Cartera: ' . implode(', ', $analyst['siniestrados']) . '.' : 'Cartera no marcó ninguno de sus contratos.') . '</span></div>'
      : self::tile('Siniestro', $analyst['siniestro'] ? 'Siniestrado' : 'No', $analyst['siniestro'] ? 'bad' : 'key');
    $years = $contract['inicio_ts'] > 0 && $contract['fin_ts'] > 0 ? number_format(($contract['fin_ts'] - $contract['inicio_ts']) / 86400 / 365, 1, ',', '.') : '—';

    $endTile = $editable
      ? '<div class="!sp-rounded-lg !sp-border !sp-border-solid !sp-border-service-yellow !sp-bg-amber-50/40 !sp-p-3"><span class="!sp-block !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-mb-1.5">Fin del contrato</span>'
        . '<input class="' . $ui::INPUT . ' !sp-h-8 !sp-bg-white" type="date" name="contract_end" value="' . ($contract['fin_ts'] > 0 ? date('Y-m-d', $contract['fin_ts']) : '') . '" aria-label="Fin del contrato">'
        . '<span class="!sp-block !sp-mt-1.5 !sp-text-[10px] !sp-text-service-muted">Si la cambias, se guarda en el contrato al aprobar o rechazar.</span></div>'
      : self::tile('Fin del contrato', $contract['fin_ts'] > 0 ? date('d/m/Y', $contract['fin_ts']) : '—');

    $deductions = '';
    foreach (OwnerCreditService::DEDUCTIONS as $key => $label) $deductions .= $field('(−) ' . $label, $money('ded_' . $key, (float) ($ev['deductions'][$key] ?? 0)));
    $purposes = OwnerCreditSettings::PURPOSES;
    // Créditos antiguos pueden tener una destinación que ya no está en la lista: se conserva para no perderla al guardar.
    if ($credit['purpose'] !== '' && !in_array($credit['purpose'], $purposes, true)) array_unshift($purposes, (string) $credit['purpose']);
    $purposeOptions = '';
    foreach ($purposes as $purpose) $purposeOptions .= '<option' . ($purpose === $credit['purpose'] ? ' selected' : '') . '>' . self::e($purpose) . '</option>';
    $vacancyOptions = '';
    foreach (self::VACANCY as $key => $label) $vacancyOptions .= '<option value="' . $key . '"' . ($key === $ev['vacancy'] ? ' selected' : '') . '>' . self::e($label) . '</option>';

    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-5" data-owner-credit-form data-credit-id="' . (int) $credit['id'] . '" autocomplete="off">'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2">' . self::status((string) $credit['status'])
      . ($editable && ($ev['other_contracts'] ?? []) !== [] ? self::mainContractSelect($contract, $ev['other_contracts']) : $chip('Contrato principal', '#' . self::e($contract['code']))) . ((int) $credit['quote_id'] > 0 && $editable ? self::beneficiarySelect($credit) : '') . ($editable && count($work['owners'] ?? []) > 1 ? self::ownerSelect($credit, $work['owners']) : $chip(OwnerCreditService::beneficiaryLabel($credit), self::e($credit['owner_name']) . ((string) ($credit['owner_document'] ?? '') !== '' ? ' · ' . self::e($credit['owner_document']) : ''))) . $chip('Inmueble', self::e($credit['property']))
      . ((int) $credit['quote_id'] > 0 ? $chip('Cotización', '#' . (int) $credit['quote_id']) : $chip('Origen', 'Manual'))
      . '<button type="button" class="' . $ui::NAVY . ' !sp-ml-auto" data-owner-credit-pdf title="Documento interno para funcionarios">' . $ui::icon('document', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Ver documento del crédito</button></div>'

      . '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-12 !sp-gap-5 !sp-items-start">'
      // Columna de datos y entradas.
      . '<div class="lg:!sp-col-span-7 !sp-space-y-5">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('building') . 'Contrato de arriendo vigente</h4>' . self::guide('contrato', [
        'Fin del contrato' => 'Define cuántos meses se puede prestar: se cuentan los meses completos (de 30 días) que faltan hasta esa fecha. Si el contrato fue renovado y la fecha está desactualizada, corrígela aquí; se guarda en el contrato al aprobar o rechazar.',
        'Años arrendados' => 'Tiempo entre el inicio y el fin del contrato. Un historial largo da más confianza.',
        'Siniestro' => 'Lo marca Cartera cuando un contrato fue reportado a la aseguradora por mora. Incumple la política si está siniestrado el contrato principal o uno que paga cuotas del crédito; si es otro contrato del propietario, queda como aviso. Confírmalo con Cartera y corrígelo con Sí o No si el sistema no está al día.',
      ]) . '<div class="!sp-grid !sp-grid-cols-2 sm:!sp-grid-cols-3 !sp-gap-3">'
      . self::tile('Inicio del contrato', $contract['inicio_ts'] > 0 ? date('d/m/Y', $contract['inicio_ts']) : '—') . $endTile . self::tile('Años arrendados', $years)
      . self::tile('Estado', self::e($contract['estado'] ?: '—')) . self::tile('Aseguradora', self::e($contract['aseguradora'] ?: '—'))
      . $siniestroTile
      . '</div></section>'

      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('cash') . 'Ingreso del propietario</h4>' . self::guide('ingreso', [
        'Ingreso bruto (canon)' => 'Lo que paga el arrendatario cada mes. Si ya tuvo aumento, ajústalo: solo cambia este crédito, no el contrato.',
        'Descuentos' => 'Lo que se le descuenta al propietario cada mes antes de girarle: comisión de administración con IVA, garantía inmobiliaria (seguro de arrendamiento) y póliza de servicios públicos. Salen del contrato de mandato; sin mandato se estima un porcentaje del canon (Configuración).',
        'Ingreso neto (INO)' => 'Canon menos descuentos: lo que realmente recibe el propietario y con lo que paga la cuota.',
      ]) . '<p class="' . self::NOTE . ' !sp-mb-3">' . self::e($work['income_source']) . '</p>'
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-4">' . $field('Ingreso bruto (canon)', $money('gross', $ev['gross'])) . $deductions . '</div>'
      . ($editable ? '<p class="' . self::NOTE . ' !sp-mt-3">Puedes ajustar el canon (por ejemplo, si ya tuvo aumento) y los descuentos: solo cambian este crédito, no el contrato.</p>' : '')
      . self::otherIncome($ev) . '</section>'

      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $ui::icon('document') . 'Información de la solicitud</h4>' . self::guide('solicitud', [
        'Valor solicitado' => 'Lo que pide el propietario. Si viene de una cotización, es el total de la cotización.',
        'Plazo solicitado' => 'Meses en que quiere pagar. Escríbelo según lo que pidió; no puede superar los meses que faltan para el fin del contrato, porque las cuotas se descuentan del canon.',
        'Destinación' => 'Para qué usará el dinero.',
        '¿Tiene créditos vigentes?' => 'El sistema propone Sí cuando tiene créditos aprobados aquí. Con Sí, su saldo se resta del cupo y sus cuotas se restan del ingreso disponible en cada fecha de pago. Con No, no descuenta nada.',
        'Otras obligaciones mensuales' => 'Lo que el propietario ya tiene comprometido cada mes y que no está en los descuentos del contrato ni en créditos de este sistema. Se resta del ingreso disponible para la cuota; no repitas descuentos.',
        'Probabilidad de que desocupen' => 'La sugiere el sistema con el seguimiento de renovación del contrato principal: no prórroga o terminación respondida dentro del término (renovación 0 %) o renovación menor de 50 % = Alta; de 50 % a 79 % = Media; 80 % o más, o "no salida" reportada = Baja. Puedes cambiarla según tu criterio. Baja: se respeta lo pedido con la tasa de referencia. Media: plazo hasta el fin del contrato con la tasa de referencia. Alta: máximo 3 meses con la tasa de riesgo.',
      ]) . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-4">'
      . $field('Fecha de solicitud', '<input class="' . $ui::INPUT . ' ' . self::READONLY . '" value="' . date('d/m/Y', (int) $credit['created_at']) . '" readonly>')
      . $field('Valor solicitado', $money('requested_amount', (float) ($credit['requested_amount'] ?? 0)))
      . $field('Plazo solicitado (meses)', '<input class="' . $inputClass . '" name="requested_term" type="number" min="1" max="60" placeholder="Escribe los meses" value="' . ((int) $credit['requested_term'] > 0 ? (int) $credit['requested_term'] : '') . '"' . $lock . '>')
      . $field('Destinación del crédito', $select('purpose', $purposeOptions))
      . $field('Probabilidad de que desocupen', $select('vacancy', $vacancyOptions))
      . $field('¿Tiene créditos vigentes?', $yesNo('has_credits', (bool) $analyst['has_credits'], '¿Tiene créditos vigentes?'))
      . $field('Otras obligaciones mensuales', $money('other_obligations', (float) ($analyst['other_obligations'] ?? 0)))
      . '</div>' . self::vacancyNote($ev) . '</section>'

      . '<section class="' . self::SECTION . ' !sp-border-service-yellow"><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-3"><h4 class="' . self::H4 . ' !sp-mb-0">' . $ui::icon('edit') . 'Condiciones según el analista</h4>'
      . ($editable ? '<button type="button" class="' . $ui::SECONDARY . ' !sp-text-[10px]" data-owner-credit-use-suggested>' . $ui::icon('refresh', '!sp-w-3 !sp-h-3') . 'Usar condición sugerida</button>' : '') . '</div>'
      . self::guide('analista', [
        'Qué son' => 'Las condiciones que realmente se aprueban. Arrancan con la condición sugerida y las puedes ajustar.',
        'Valor a colocar' => 'El dinero que se presta.',
        'Plazo' => 'Número de cuotas mensuales.',
        'Tasa N.M.V' => 'Tasa nominal mes vencido: el interés de cada mes sobre el saldo. Se sugiere la tasa de referencia; puedes aplicar un descuento hasta el descuento máximo de Configuración. Más abajo queda fuera de política; por encima de la usura no se puede aprobar.',
        'Cuota' => 'Fija, calculada como la función PAGO de Excel; se descuenta de los giros del canon.',
        'Fecha de desembolso' => 'Día en que se entrega el dinero. La primera cuota se descuenta un mes después y el crédito queda pagado solo cuando vence la última.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-3 !sp-gap-4">'
      . $field('Valor a colocar', $money('amount', $c['amount']))
      . $field('Plazo (meses)', '<input class="' . $inputClass . '" name="term" type="number" min="1" max="60" value="' . (int) $c['term'] . '"' . $lock . '>')
      . $field('Tasa N.M.V (%)', '<input class="' . $inputClass . '" name="rate" inputmode="decimal" value="' . self::e(number_format($c['rate'] * 100, 4, ',', '')) . '"' . $lock . '>')
      . $field('Fecha de desembolso', '<input class="' . $inputClass . '" type="date" name="disbursed_at" value="' . date('Y-m-d', (int) ($work['disbursed_ts'] ?? 0) ?: time()) . '"' . $lock . '>')
      . '</div></section>'
      . '</div>'
      // Columna de resultados (se refresca al editar).
      . '<div class="lg:!sp-col-span-5 !sp-space-y-5" data-owner-credit-results>' . $this->results($work) . '</div>'
      . '</div>'

      . '<div data-owner-credit-schedule>' . $this->schedule($work) . '</div>'
      . $this->history($work)
      . self::promissorySection($work, $canDecide, $canManage)
      . $this->actions($credit, $canDecide, $canManage, $work['adjustments'] ?? [], $work)
      . '</form>'
      . ($credit['status'] === 'aprobado' ? self::prepaymentForm($work) : '');
  }

  /** Bloques calculados que se refrescan al editar el formulario. @param array<string,mixed> $work */
  public function results(array $work): string
  {
    $ui = UI::class;
    $ev = $work['evaluation'];
    $s = $ev['suggested'];
    $endChanged = (int) ($work['contract']['fin_saved_ts'] ?? 0) !== (int) ($work['contract']['fin_ts'] ?? 0);
    $termError = (string) ($work['term_error'] ?? '');
    $others = $ev['other_contracts'] ?? [];
    $policies = '';
    foreach ($work['policies'] as $policy) {
      $policies .= '<li class="!sp-flex !sp-gap-2.5 !sp-items-start !sp-py-2.5 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0">'
        . '<span class="!sp-mt-0.5 !sp-flex !sp-items-center !sp-justify-center !sp-w-5 !sp-h-5 !sp-rounded-full ' . ($policy['ok'] ? '!sp-bg-emerald-50 !sp-text-emerald-600' : '!sp-bg-red-50 !sp-text-red-600') . '">' . $ui::icon($policy['ok'] ? 'check' : 'close', '!sp-w-3 !sp-h-3') . '</span>'
        . '<span class="!sp-min-w-0"><b class="!sp-block !sp-text-[11px] ' . ($policy['ok'] ? '!sp-text-service-navy' : '!sp-text-red-700') . '">' . self::e($policy['label']) . '</b><span class="!sp-block !sp-text-[10px] !sp-text-service-muted">' . self::e($policy['detail']) . '</span></span></li>';
    }
    return ($termError !== '' ? self::alert($termError, 'bad') : '')
      . (($usury = self::usuryWarning((float) $work['settings']['reference_rate'], (float) $ev['usury_monthly_legal'])) !== '' ? self::alert($usury, 'bad') : '')
      . self::preapproval($work)
      . ($endChanged ? self::alert('Estás calculando con la fecha fin ' . date('d/m/Y', (int) $work['contract']['fin_ts']) . '. Se guardará en el contrato al aprobar o rechazar el crédito.', 'info') : '')
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('chart') . 'Política de créditos</h4>' . self::guide('politica', [
        'Valor máximo a prestar' => 'Tope = ingreso neto de todos los contratos del propietario × los meses de política (Configuración, hoy ' . (int) $work['settings']['income_months'] . ').',
        'Plazo máximo por vigencia' => 'Meses de vigencia que le quedan al contrato más largo del propietario menos el margen de Configuración (hoy ' . (int) ($work['settings']['term_margin_months'] ?? 2) . ' meses): n − 2. Es un límite: la cobertura de pago decide cuántas se pueden pagar.',
        'Mayor plazo con cobertura' => 'El plazo más largo, hasta el máximo por vigencia, en el que todas las cuotas del valor solicitado (a la tasa de referencia) tienen ingreso disponible.',
        'Tasa mínima' => 'Tasa de referencia menos el descuento máximo de Configuración: lo más bajo que puedes aprobar dentro de política.',
        'Cuota de política' => 'La cuota si se prestara el valor máximo al plazo máximo.',
        'Meses / valor que excede' => 'Diferencia entre la política y lo pedido; en rojo cuando lo pedido la supera.',
        'Tasa para el riesgo' => 'Solo cuando lo pedido supera el valor máximo: cobra la usura mensual sobre la parte que excede. Si lo pedido cabe en el tope, no aplica.',
      ]) . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3">'
      . self::tile($others !== [] ? 'Ingreso neto de este contrato' : 'Ingreso neto (INO)', self::money($ev['net_income']), 'key')
      . ($others !== [] ? self::tile('Ingreso neto total (' . (count($others) + 1) . ' contratos)', self::money($ev['total_net_income']), 'key') : '')
      . self::tile('Valor máximo a prestar', self::money($ev['max_amount']), 'key')
      . self::tile('Plazo máximo por vigencia', (int) ($ev['term_limit'] ?? $ev['max_months']) . ' meses')
      . self::tile('Mayor plazo con cobertura', (int) $ev['max_months'] > 0 ? (int) $ev['max_months'] . ' meses' : 'Ninguno', (int) $ev['max_months'] > 0 ? '' : 'bad')
      . self::tile('Tasa mínima (descuento máximo)', self::percent($ev['policy_rate']))
      . self::tile('Cuota de política', self::money($ev['policy_payment']))
      . self::tile('Tasa para el riesgo', ($ev['risk_applies'] ?? true) ? self::percent($ev['risk_rate']) : 'No aplica')
      . self::tile('Meses que excede', (string) $ev['exceed_months'], $ev['exceed_months'] < 0 ? 'bad' : '')
      . self::tile('Valor que excede', self::money($ev['exceed_amount'], true), $ev['exceed_amount'] < 0 ? 'bad' : '')
      . '</div><p class="' . self::NOTE . ' !sp-mt-3">Plazo máximo por vigencia: ' . self::e(OwnerCreditService::termBasis($ev)) . '.</p>'
      . ($others !== [] ? '<ul class="!sp-m-0 !sp-mt-2 !sp-pl-4 !sp-text-[10px] !sp-text-service-muted">' . implode('', array_map(static fn(array $row): string => '<li>Contrato #' . self::e($row['contrato']) . ' · SIMI ' . self::e($row['inmueble']) . ' · ingreso neto ' . self::money((float) $row['net_income']) . ' · ' . (int) $row['max_months'] . ' meses de vigencia</li>', $others)) . '</ul>' : '')
      . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('target') . 'Condición sugerida · probabilidad ' . self::e($ev['vacancy']) . '</h4>'
      . self::guide('sugerida', [
        'Qué es' => 'La propuesta automática del simulador según la probabilidad de desocupación elegida. Úsala con "Usar condición sugerida" o ajusta las condiciones del analista.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3" data-owner-credit-suggested data-amount="' . round((float) $s['amount']) . '" data-term="' . (int) $s['term'] . '" data-rate="' . self::e(number_format($s['rate'] * 100, 4, ',', '')) . '">'
      . self::tile('Plazo', $s['term'] . ' meses') . self::tile('Tasa', self::percent($s['rate']))
      . self::tile('Valor a colocar', self::money($s['amount'])) . self::tile('Cuota mensual', self::money($s['payment']), 'key')
      . '</div>' . (!empty($s['rate_capped']) ? '<p class="' . self::NOTE . ' !sp-mt-2">La tasa de esta condición superaba la usura; se sugiere la usura mes vencido (' . self::percent($ev['usury_monthly_legal']) . ').</p>' : '')
      . (!empty($s['uncovered']) ? '<p class="' . self::NOTE . ' !sp-mt-2 !sp-text-red-700">Con este valor ningún plazo tiene cobertura en todas sus cuotas: baja el valor.</p>' : '') . '</section>'
      . self::coverage($work)
      . self::discountPlan($work)
      . ($work['credit']['status'] === 'pendiente' ? self::adjustments($work['adjustments'] ?? [], true) : '')
      . '<section class="' . self::SECTION . '"' . (self::needsNote($work) ? ' data-owner-credit-needs-note' : '') . '><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-1"><h4 class="' . self::H4 . ' !sp-mb-0">' . self::icon('shield') . 'Políticas</h4>'
      . ($work['policies_ok'] ? '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-emerald-700">Cumple todas</span>' : '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-red-700">Incumple políticas</span>') . '</div>'
      . self::guide('politicas', [
        'Requisitos' => 'Contrato activo y no próximo a vencer, plazo dentro de la vigencia, sin siniestro, valor dentro del cupo disponible (tope menos el saldo de sus créditos vigentes), valor dentro del tope, tasa bajo la usura y cuota con cobertura en todas las fechas.',
        'Cobertura' => 'Si ningún plazo cubre todas las cuotas con el valor, no se puede aprobar ni con justificación: hay que bajar el valor.',
        'Si alguna está en rojo' => 'Puedes aprobar igual, pero con justificación: el crédito queda marcado "Fuera de política" y la razón queda en el historial.',
      ])
      . '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $policies . '</ul>'
      . ($work['policies_ok'] ? '' : '<p class="' . self::NOTE . ' !sp-mt-2">Para aprobar fuera de política escribe la justificación al final; quedará en el historial.</p>')
      . '</section>';
  }

  /**
   * De qué contrato se descuenta cada cuota. El crédito es de la persona: si un contrato termina, su parte pasa a los
   * demás; lo que ninguno cubre lo paga el propietario directo.
   * @param array<string,mixed> $work
   */
  private static function discountPlan(array $work): string
  {
    $plan = $work['discount_plan'] ?? [];
    if ($plan === []) return '';
    $money = static fn(float $value): string => self::money($value);
    $rows = '';
    foreach ($plan as $segment) {
      $range = $segment['from'] === $segment['to'] ? 'Cuota ' . $segment['from'] : 'Cuotas ' . $segment['from'] . '–' . $segment['to'];
      $parts = implode('', array_map(static fn(array $part): string => '<span class="!sp-block">Contrato #' . self::e($part['contrato']) . ' <b class="!sp-text-service-navy">' . $money((float) $part['amount']) . '</b></span>', $segment['parts']));
      if ($segment['direct'] >= 0.5) $parts .= '<span class="!sp-block !sp-font-semibold !sp-text-red-700">Pago directo del propietario ' . $money((float) $segment['direct']) . '</span>';
      $rows .= '<li class="!sp-flex !sp-gap-3 !sp-justify-between !sp-py-2 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0 !sp-text-[11px]"><b class="!sp-whitespace-nowrap">' . $range . '</b><span class="!sp-text-right !sp-text-service-muted">' . $parts . '</span></li>';
    }
    $dates = 'Desembolso ' . date('d/m/Y', (int) $work['disbursed_ts']) . ' · primera cuota ' . date('d/m/Y', (int) $work['first_payment_ts']) . ((int) $work['last_payment_ts'] > 0 ? ' · última cuota ' . date('d/m/Y', (int) $work['last_payment_ts']) : '') . '.';
    return '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('building') . 'Plan de descuento por contrato</h4>'
      . self::guide('plan', [
        'Qué es' => 'De qué giro se descuenta cada cuota. Se prefiere un solo contrato; si ninguno alcanza solo, la cuota se reparte en proporción al ingreso neto de cada contrato.',
        'Si un contrato termina' => 'Deja de cubrir cuotas y su parte pasa a los demás contratos. Si ninguno queda vigente, el propietario paga directo.',
        'Fin del crédito' => 'Queda pagado automáticamente cuando vence la última cuota.',
      ])
      . '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $rows . '</ul><p class="' . self::NOTE . ' !sp-mt-2">' . self::e($dates) . '</p></section>';
  }

  /** @param array<string,mixed> $work */
  public function schedule(array $work): string
  {
    $c = $work['conditions'];
    $th = '!sp-text-right !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-3 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200 first:!sp-text-left';
    $td = '!sp-text-right !sp-px-3 !sp-py-2.5 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100 first:!sp-text-left';
    $rows = '';
    foreach ($work['schedule'] as $line) {
      $rows .= '<tr><td class="' . $td . ' !sp-font-semibold">' . $line['mes'] . '</td><td class="' . $td . '">' . ((int) ($line['fecha'] ?? 0) > 0 ? date('d/m/Y', (int) $line['fecha']) : '') . '</td><td class="' . $td . '">' . self::money($line['saldo_inicial']) . '</td><td class="' . $td . ' !sp-font-semibold">' . self::money($line['cuota']) . '</td><td class="' . $td . '">' . self::money($line['interes']) . '</td><td class="' . $td . '">' . self::money($line['abono']) . '</td><td class="' . $td . '">' . self::money($line['saldo']) . '</td></tr>';
    }
    $head = '';
    foreach (['Mes', 'Fecha', 'Saldo de capital', 'Cuota', 'Intereses', 'Abono a capital', 'Saldo'] as $label) $head .= '<th class="' . $th . '">' . $label . '</th>';
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
    $labels = ['solicitado' => 'Solicitud registrada', 'reabierto' => 'Solicitud reabierta', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'pagado' => 'Marcado como pagado', 'anulado' => 'Anulado', 'fecha_fin' => 'Fecha fin del contrato corregida', 'beneficiario' => 'Beneficiario cambiado', 'contrato_principal' => 'Contrato principal cambiado'];
    $events = '';
    foreach ($work['events'] as $event) {
      $detail = trim((string) ($event['details']['nota'] ?? $event['details']['motivo'] ?? ''));
      $changes = array_values(array_filter((array) ($event['details']['ajustes'] ?? []), 'is_string'));
      $events .= '<li class="' . $item . '"><b>' . self::e($labels[$event['action']] ?? $event['action']) . '</b> <span class="!sp-text-service-muted">· ' . date('d/m/Y g:i a', (int) $event['created_at']) . ($event['actor_name'] !== '' ? ' · ' . self::e($event['actor_name']) : '') . '</span>' . ($detail !== '' ? '<span class="!sp-block !sp-text-[10px] !sp-text-service-muted">' . self::e($detail) . '</span>' : '')
        . ($changes !== [] ? '<span class="!sp-block !sp-text-[10px] !sp-text-service-muted">Cambios frente al sistema: ' . self::e(implode(' · ', $changes)) . '</span>' : '') . '</li>';
    }
    return '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-5">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('user') . 'Otros créditos del propietario</h4>' . ($others !== '' ? '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $others . '</ul>' : '<p class="' . self::NOTE . '">Sin otros créditos registrados.</p>') . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('clock') . 'Historial del crédito</h4><ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $events . '</ul></section>'
      . '</div>';
  }

  /** @param array<string,mixed> $credit */
  /** @param list<array<string,mixed>> $adjustments */
  /**
   * Los demás contratos del propietario que suman al ingreso (tope y cupo). Sin ellos, aclara por qué solo hay uno:
   * solo cuentan los contratos Entregado o Por entregar, de los que hay canon para descontar.
   * @param array<string,mixed> $ev
   */
  private static function otherIncome(array $ev): string
  {
    $others = (array) ($ev['other_contracts'] ?? []);
    if ($others === []) return '<p class="' . self::NOTE . ' !sp-mt-3" data-owner-credit-other-income>Es el único contrato vigente del propietario: solo suman los contratos Entregado o Por entregar (los recibidos o terminados no tienen canon del cual descontar).</p>';
    $td = '!sp-px-2 !sp-py-1.5 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
    $rows = '';
    foreach ($others as $row) $rows .= '<tr><td class="' . $td . '">#' . self::e((string) $row['contrato']) . ((string) ($row['inmueble'] ?? '') !== '' ? ' · Inm. ' . self::e((string) $row['inmueble']) : '') . ((string) ($row['estado'] ?? '') !== '' ? ' · ' . self::e((string) $row['estado']) : '') . '</td><td class="' . $td . ' !sp-text-right">' . self::money($row['net_income']) . '</td></tr>';
    // Los valores de arriba (principal) se recalculan al escribir; estos se toman tal como están en el sistema.
    return '<div class="!sp-mt-4" data-owner-credit-other-income><p class="' . UI::LABEL . '">Otros contratos del propietario (ingreso neto)</p>'
      . '<table class="!sp-w-full !sp-border-collapse !sp-text-[11px] !sp-text-service-navy">' . $rows
      . '<tr><td class="!sp-px-2 !sp-py-1.5 !sp-font-bold">Total otros contratos</td><td class="!sp-px-2 !sp-py-1.5 !sp-text-right !sp-font-bold">' . self::money(array_sum(array_map(static fn(array $row): float => (float) $row['net_income'], $others))) . '</td></tr></table>'
      . '<p class="' . self::NOTE . ' !sp-mt-2">Se suman al ingreso neto del contrato principal (arriba) para el tope y el cupo preaprobado; el total aparece en Políticas. Solo el principal se puede ajustar aquí.</p></div>';
  }

  /**
   * Cobertura de pago de las condiciones: ingreso disponible frente a la cuota en cada fecha, primera fecha con
   * faltante, mayor plazo con cobertura y capital máximo para conservar el plazo.
   * @param array<string,mixed> $work
   */
  private static function coverage(array $work): string
  {
    $c = $work['evaluation']['coverage'] ?? null;
    if (!is_array($c) || (float) ($work['conditions']['amount'] ?? 0) <= 0 || (int) ($work['conditions']['term'] ?? 0) <= 0) return '';
    $ok = (bool) $c['ok'];
    $status = $ok ? '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-emerald-700">Con cobertura</span>' : '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-red-700">Sin cobertura</span>';
    $gap = $c['shortfall'] ?? null;
    $rows = '';
    $td = '!sp-px-2 !sp-py-1.5 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
    foreach ((array) $c['rows'] as $row) {
      $rows .= '<tr class="' . ($row['ok'] ? '' : '!sp-bg-red-50 !sp-text-red-800') . '"><td class="' . $td . '">' . (int) $row['mes'] . '</td><td class="' . $td . '">' . date('d/m/Y', (int) $row['fecha']) . '</td>'
        . '<td class="' . $td . ' !sp-text-right">' . self::money($row['disponible']) . '</td><td class="' . $td . ' !sp-text-right">' . self::money($row['cuota']) . '</td>'
        . '<td class="' . $td . ' !sp-text-right">' . ($row['ratio'] === null ? '—' : number_format((float) $row['ratio'], 2, ',', '.')) . '</td></tr>';
    }
    $percent = number_format((float) $c['factor'] * 100, 0, ',', '.') . ' %';
    return '<section class="' . self::SECTION . '" data-owner-credit-coverage><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-1"><h4 class="' . self::H4 . ' !sp-mb-0">' . self::icon('shield') . 'Cobertura de pago</h4>' . $status . '</div>'
      . self::guide('cobertura', [
        'Qué revisa' => 'En cada fecha de pago suma el ingreso neto de los contratos vigentes ese día (× el % utilizable de Configuración) y le resta las cuotas de sus otros créditos y las otras obligaciones. Un contrato que termina antes de la fecha deja de aportar: no se supone que se renueva.',
        'Cuándo cumple' => 'Solo si en todas las cuotas el disponible es al menos la cuota × la cobertura mínima. Acortar el plazo sube la cuota, por eso se prueban todos los plazos.',
        'Valor máximo' => 'El mayor valor que se puede prestar conservando el plazo con cobertura en todas sus cuotas.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3">'
      . self::tile('Valor y plazo de las condiciones', $ok ? 'Con cobertura' : 'Sin cobertura', $ok ? 'key' : 'bad')
      . self::tile('Mayor plazo con cobertura', (int) $c['best_term'] > 0 ? (int) $c['best_term'] . ' meses · ' . self::money($c['best_payment']) : 'Ninguno', (int) $c['best_term'] > 0 ? '' : 'bad')
      . self::tile('Primera fecha con faltante', is_array($gap) ? date('d/m/Y', (int) $gap['fecha']) . ' · faltan ' . self::money((float) $gap['faltante_pago'] > 0 ? $gap['faltante_pago'] : $gap['faltante_cobertura']) : 'Ninguna', is_array($gap) ? 'bad' : '')
      . self::tile('Valor máximo para ' . (int) $c['max_capital_term'] . ' meses', self::money($c['max_capital']))
      . '</div>'
      . '<p class="' . self::NOTE . ' !sp-mt-3">Ingreso utilizable ' . $percent . ' · cobertura mínima ' . number_format((float) $c['min_coverage'], 2, ',', '.') . ((float) $c['other_obligations'] > 0 ? ' · otras obligaciones ' . self::money($c['other_obligations']) : '') . ((array) $c['credits'] !== [] ? ' · cuotas de los créditos ' . implode(', ', array_map(static fn(int $id): string => '#' . $id, (array) $c['credits'])) : '') . '.</p>'
      . ($rows !== '' ? '<details class="!sp-mt-2"><summary class="!sp-cursor-pointer !sp-text-[10px] !sp-font-semibold !sp-text-service-blue">Ver disponible por fecha</summary><table class="!sp-w-full !sp-mt-2 !sp-border-collapse !sp-text-[10px] !sp-text-service-navy"><thead><tr class="!sp-text-left !sp-text-service-muted"><th class="' . $td . '">Cuota</th><th class="' . $td . '">Fecha</th><th class="' . $td . ' !sp-text-right">Disponible</th><th class="' . $td . ' !sp-text-right">Cuota</th><th class="' . $td . ' !sp-text-right">Cobertura</th></tr></thead><tbody>' . $rows . '</tbody></table></details>' : '')
      . '</section>';
  }

  /**
   * Simulador de abono extraordinario de un crédito aprobado: formulario aparte del liquidador (no recalcula ni
   * guarda el crédito).
   * @param array<string,mixed> $work
   */
  private static function prepaymentForm(array $work): string
  {
    $ui = UI::class;
    $id = (int) $work['credit']['id'];
    return '<form class="' . self::SECTION . ' !sp-mt-5" data-owner-credit-prepay-form data-credit-id="' . $id . '" novalidate>'
      . '<h4 class="' . self::H4 . '">' . self::icon('cash') . 'Simular abono extraordinario</h4>'
      . self::guide('abono', [
        'Qué hace' => 'Compara qué pasa si el propietario abona a capital: reducir plazo (conserva la cuota y termina antes) o reducir cuota (conserva las fechas y baja la cuota). Es solo una simulación: no registra el pago ni cambia el crédito.',
        'Cómo se aplica el dinero' => 'Primero salda el interés causado a la fecha; el resto va a capital. Si supera el saldo, el crédito se cancela y la diferencia queda como excedente por conciliar.',
        'Supuestos' => 'El crédito está al día (las cuotas vencidas hasta esa fecha ya se descontaron) y sin otros cargos. Entre dos vencimientos, el interés se calcula con una prorrata por días que aún debe validar contabilidad.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-3 !sp-gap-4 !sp-items-end">'
      . '<label class="!sp-block"><span class="' . $ui::LABEL . '">Fecha del abono</span><input class="' . UI::field('!sp-bg-white') . '" type="date" name="prepay_date" value="' . date('Y-m-d') . '" min="' . date('Y-m-d', (int) $work['disbursed_ts']) . '"></label>'
      . '<label class="!sp-block"><span class="' . $ui::LABEL . '">Valor del abono</span><input class="' . UI::field('!sp-bg-white') . '" name="prepay_amount" inputmode="numeric" data-owner-credit-money placeholder="$0"></label>'
      . '<button type="submit" class="' . $ui::PRIMARY . '">' . $ui::icon('refresh', '!sp-w-3.5 !sp-h-3.5') . 'Simular abono</button>'
      . '</div><p class="!sp-m-0 !sp-mt-2 !sp-text-[11px]" data-owner-credit-status role="status" aria-live="polite"></p>'
      . '<div class="!sp-mt-4" data-owner-credit-prepay-results></div></form>';
  }

  /**
   * Resultado de la simulación de abono: liquidación del dinero y las dos alternativas lado a lado.
   * @param array<string,mixed> $r OwnerCreditService::prepayment()
   */
  public static function prepaymentResults(array $r): string
  {
    $l = $r['liquidation'];
    $date = static fn(int $ts): string => $ts > 0 ? date('d/m/Y', $ts) : '—';
    $cents = static fn(float $value): string => '$' . number_format($value, 2, ',', '.');
    $html = ($r['proposal'] ? self::alert('El abono cae entre dos vencimientos: el interés causado (' . $r['accrual']['days_elapsed'] . ' de ' . $r['accrual']['days_period'] . ' días) se calcula con una prorrata propuesta que aún debe validar contabilidad.', 'info') : '')
      . '<div class="!sp-grid !sp-grid-cols-2 sm:!sp-grid-cols-3 !sp-gap-3">'
      . self::tile('Saldo de capital', $cents($l['previous_balance']))
      . self::tile('Intereses causados a la fecha', $cents($l['interest_paid']))
      . self::tile('Abono a capital', $cents($l['capital']), 'key')
      . self::tile('Nuevo saldo', $cents($l['new_balance']), 'key')
      . ($l['surplus'] > 0 ? self::tile('Excedente por conciliar', $cents($l['surplus']), 'bad') : '')
      . '</div>';
    if ($r['paid_off']) {
      return $html . '<p class="' . self::NOTE . ' !sp-mt-3" data-owner-credit-prepay-paid-off>Con este abono el crédito queda <b>cancelado totalmente</b>: no quedan cuotas.' . ($l['surplus'] > 0 ? ' El excedente no se devuelve ni se registra solo: hay que conciliarlo.' : '') . '</p>';
    }
    $labels = ['reduce_term' => ['Reducir plazo', 'Conserva la cuota y termina antes.'], 'reduce_payment' => ['Reducir cuota', 'Conserva las fechas y baja la cuota.']];
    $td = '!sp-px-2 !sp-py-1 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
    $columns = '';
    foreach ($labels as $key => [$title, $hint]) {
      $o = $r['options'][$key];
      $last = $o['rows'] === [] ? null : $o['rows'][array_key_last($o['rows'])];
      $rows = implode('', array_map(static fn(array $row): string => '<tr><td class="' . $td . '">' . (int) $row['numero'] . '</td><td class="' . $td . '">' . date('d/m/Y', (int) $row['fecha']) . '</td><td class="' . $td . ' !sp-text-right">' . $cents($row['cuota']) . '</td><td class="' . $td . ' !sp-text-right">' . $cents($row['interes']) . '</td><td class="' . $td . ' !sp-text-right">' . $cents($row['saldo']) . '</td></tr>', $o['rows']));
      $columns .= '<article class="!sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-p-4" data-owner-credit-prepay-option="' . $key . '">'
        . '<h5 class="!sp-m-0 !sp-text-sm !sp-font-bold !sp-text-service-navy">' . $title . '</h5><p class="' . self::NOTE . ' !sp-mb-3">' . $hint . '</p>'
        . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-2">'
        . self::tile('Pagos pendientes', (string) $o['installments'])
        . self::tile('Cuota regular', $cents((float) ($o['rows'][0]['cuota'] ?? 0)))
        . self::tile('Último pago', $last !== null ? $cents((float) $last['cuota']) : '—')
        . self::tile('Fecha final', $date((int) $o['last_ts']))
        . self::tile('Intereses futuros', $cents($o['interest']))
        . self::tile('Ahorro de intereses', $cents($o['savings']), 'key')
        . '</div>'
        . ($o['early_close'] ? '<p class="' . self::NOTE . ' !sp-mt-2">El saldo se extingue una cuota antes por el ajuste de centavos.</p>' : '')
        . '<details class="!sp-mt-3"><summary class="!sp-cursor-pointer !sp-text-[10px] !sp-font-semibold !sp-text-service-blue">Ver cuotas</summary><table class="!sp-w-full !sp-mt-2 !sp-border-collapse !sp-text-[10px] !sp-text-service-navy"><thead><tr class="!sp-text-left !sp-text-service-muted"><th class="' . $td . '">#</th><th class="' . $td . '">Fecha</th><th class="' . $td . ' !sp-text-right">Cuota</th><th class="' . $td . ' !sp-text-right">Interés</th><th class="' . $td . ' !sp-text-right">Saldo</th></tr></thead><tbody>' . $rows . '</tbody></table></details>'
        . '</article>';
    }
    return $html . '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4 !sp-mt-4">' . $columns . '</div>'
      . '<p class="' . self::NOTE . ' !sp-mt-3">Sin abono quedarían ' . $r['reference']['installments'] . ' cuotas con ' . $cents($r['reference']['interest']) . ' de intereses futuros. '
      . 'Si SIMI exige abrir otro registro, sería por el nuevo saldo de capital (' . $cents($r['simi']['capital']) . ') con desembolso adicional de $0, ligado al crédito #' . (int) $r['simi']['original'] . '; soporte y contabilidad deben confirmar cómo retirar los cobros sustituidos.</p>'
      . '<p class="' . self::NOTE . ' !sp-mt-1">Simulación: no registra el pago ni cambia el crédito.</p>';
  }

  /**
   * Pagaré en blanco y carta de instrucciones: quién firma, en qué va cada uno y el envío del enlace. Cada persona lo
   * firma una vez y sirve para todos sus créditos; aprobar exige el de cada firmante elegido.
   * @param array<string,mixed> $work
   */
  private static function promissorySection(array $work, bool $canDecide, bool $canManage): string
  {
    $state = $work['promissory'] ?? null;
    if (!is_array($state) || $state['owners'] === []) return '';
    $ui = UI::class;
    $pending = $work['credit']['status'] === 'pendiente';
    $editable = $pending && $canDecide;
    $several = count($state['owners']) > 1;
    $done = $state['missing'] === [];
    $rows = '';
    foreach ($state['owners'] as $owner) {
      $status = match (true) {
        $owner['status'] === 'firmado' => '<span class="!sp-font-semibold !sp-text-emerald-700">Firmado el ' . date('d/m/Y', (int) $owner['signed_at']) . ' · ' . self::e($owner['number']) . '</span><span class="!sp-block !sp-text-service-muted">Sirve para todos sus créditos: no lo vuelve a firmar.</span>',
        $owner['status'] === 'pendiente' => '<span class="!sp-font-semibold ' . ($owner['expired'] ? '!sp-text-red-700' : '!sp-text-amber-800') . '">' . ($owner['expired'] ? 'Enlace vencido' : 'Pendiente de firma') . ' · ' . self::e($owner['number']) . '</span><span class="!sp-block !sp-text-service-muted">Enviado el ' . date('d/m/Y H:i', (int) $owner['sent_at']) . '.</span>',
        default => '<span class="!sp-text-service-muted">Sin enviar</span>',
      };
      $check = $several && $editable
        ? '<input type="checkbox" class="!sp-mt-0.5" name="promissory_signers[]" value="' . self::e($owner['key']) . '" data-owner-credit-promissory-signer' . ($owner['required'] ? ' checked' : '') . ' aria-label="' . self::e('Firma ' . $owner['name']) . '">'
        : '';
      // El PDF firmado se ve en el visor del mismo popup.
      $links = ($owner['status'] === 'firmado' ? '<button type="button" class="!sp-p-0 !sp-border-0 !sp-bg-transparent !sp-text-[10px] !sp-font-semibold !sp-text-service-blue !sp-cursor-pointer" data-owner-credit-promissory-pdf="' . (int) $owner['promissory_id'] . '" data-number="' . self::e($owner['number']) . '">Ver pagaré firmado</button>' : '')
        . ($owner['promissory_id'] > 0 && $canManage ? '<button type="button" class="!sp-p-0 !sp-border-0 !sp-bg-transparent !sp-text-[10px] !sp-font-semibold !sp-text-red-700 !sp-cursor-pointer" data-owner-credit-promissory-void="' . (int) $owner['promissory_id'] . '">Anular</button>' : '');
      $rows .= '<li class="!sp-flex !sp-gap-2.5 !sp-items-start !sp-py-2 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0 !sp-text-[11px]">' . $check
        . '<span class="!sp-min-w-0 !sp-flex-1"><b class="!sp-block !sp-text-service-navy">' . self::e($owner['name']) . ($owner['required'] ? '' : ' <span class="!sp-font-normal !sp-text-service-muted">· no firma este crédito</span>') . '</b>'
        . '<span class="!sp-block !sp-text-service-muted">' . (trim((string) $owner['document']) !== '' ? 'C.C. ' . self::e($owner['document']) : 'Sin documento registrado') . ' · ' . self::e(implode(' · ', array_filter([(string) $owner['email'], (string) $owner['phone']])) ?: 'sin contacto') . '</span>'
        . ($owner['required'] ? $status : '') . '</span>'
        . '<span class="!sp-flex !sp-flex-col !sp-items-end !sp-gap-1">' . $links . '</span></li>';
    }
    $missingSent = array_filter($state['owners'], static fn(array $o): bool => $o['required'] && $o['status'] !== 'firmado');
    $resend = array_filter($missingSent, static fn(array $o): bool => $o['status'] === 'pendiente') !== [];
    $send = $editable && !$done
      ? '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-3" data-owner-credit-promissory-send-box>'
        . '<span class="' . $ui::LABEL . ' !sp-mb-0">Enviar por</span>'
        . '<label class="!sp-flex !sp-items-center !sp-gap-1 !sp-text-[11px]"><input type="checkbox" name="promissory_channels[]" value="email" checked> Correo</label>'
        . '<label class="!sp-flex !sp-items-center !sp-gap-1 !sp-text-[11px]"><input type="checkbox" name="promissory_channels[]" value="whatsapp"> WhatsApp</label>'
        . '<button type="button" class="' . $ui::NAVY . ' !sp-ml-auto" data-owner-credit-promissory-send>' . $ui::icon('document', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . ($resend ? 'Reenviar enlace para firmar' : 'Enviar para firmar') . '</button></div>'
      : '';
    return '<section class="' . self::SECTION . ' !sp-mt-5" data-owner-credit-promissory><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-1"><h4 class="' . self::H4 . ' !sp-mb-0">' . self::icon('shield') . 'Pagaré y carta de instrucciones</h4>'
      . ($done ? '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-emerald-700">Firmado</span>' : '<span class="!sp-text-[10px] !sp-font-semibold !sp-text-red-700">Falta firma</span>') . '</div>'
      . self::guide('pagare', [
        'Qué es' => 'Un pagaré en blanco (sin valor, interés ni vencimiento) con su carta de instrucciones, que autoriza a llenarlo si el propietario incumple, por el total que deba de sus créditos del Pasaporte SuCasa.',
        'Una sola vez' => 'Cada persona lo firma una vez y respalda todos sus créditos. Si ya lo firmó, no se le vuelve a pedir.',
        'Quién firma' => 'Por defecto, el beneficiario del crédito. Si el contrato tiene varios propietarios, marca quiénes deben firmar: puede ser uno o todos.',
        'Cómo firma' => 'Recibe un enlace personal por correo o WhatsApp y firma con su nombre, su número de documento y un código de verificación, como el acta de satisfacción.',
        'Para aprobar' => 'El crédito solo se puede aprobar cuando todos los firmantes marcados tengan el pagaré firmado.',
      ])
      . ($several && $editable ? '<p class="' . self::NOTE . ' !sp-mb-1">Marca quiénes firman el pagaré de este crédito.</p>' : '')
      . '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $rows . '</ul>'
      . ($pending && !$done ? (!empty($work['settings']['require_promissory'])
        ? '<p class="' . self::NOTE . ' !sp-mt-2 !sp-text-red-700">Para aprobar falta el pagaré firmado de ' . self::e(implode(', ', $state['missing'])) . '.</p>'
        : '<p class="' . self::NOTE . ' !sp-mt-2">En Configuración el pagaré no es obligatorio para aprobar; falta el de ' . self::e(implode(', ', $state['missing'])) . '.</p>') : '')
      . $send
      . '<p class="!sp-m-0 !sp-mt-2 !sp-text-[11px]" data-owner-credit-promissory-status role="status" aria-live="polite"></p></section>';
  }

  /** Aprobar exige justificación solo cuando incumple alguna política. */
  private static function needsNote(array $work): bool
  {
    return !($work['policies_ok'] ?? true);
  }

  private function actions(array $credit, bool $canDecide, bool $canManage, array $adjustments = [], array $work = []): string
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
      . self::adjustments($adjustments, false)
      : '';
    if ($buttons === '') return $decision !== '' ? '<section class="' . self::SECTION . '">' . $decision . '</section>' : '';
    // La observación aparece al pulsar Rechazar, Marcar como pagado o Anular, y al pulsar Aprobar solo si incumple políticas.
    return '<section class="' . self::SECTION . '">' . $decision
      . '<label class="!sp-block" style="display:none !important" data-owner-credit-note-box><span class="' . $ui::LABEL . '">' . ($status === 'pendiente' ? 'Observación / justificación' : 'Observación') . '</span>'
      . '<textarea class="' . $ui::INPUT . ' !sp-h-20 !sp-py-2 !sp-resize-y" name="note" rows="3" maxlength="2000" placeholder="Explica el motivo (mínimo 10 caracteres); queda guardado con la decisión."></textarea></label>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-end !sp-gap-3 !sp-mt-4" data-owner-credit-actions>' . $buttons . '</div>'
      . '<p class="!sp-m-0 !sp-mt-2 !sp-text-[11px] !sp-text-right" data-owner-credit-status role="status" aria-live="polite"></p></section>';
  }

  /**
   * Cambios frente a lo que propone el sistema: en vivo mientras está pendiente y, decidido, los que quedaron guardados.
   * @param list<array<string,mixed>> $items
   */
  private static function adjustments(array $items, bool $live): string
  {
    if ($items === []) return $live ? '<section class="' . self::SECTION . '" data-owner-credit-adjustments><h4 class="' . self::H4 . '">' . self::icon('edit') . 'Cambios frente al sistema</h4><p class="' . self::NOTE . '">Sin cambios: apruebas lo que propone el sistema.</p></section>' : '';
    $rows = implode('', array_map(static fn(array $item): string => '<li class="!sp-flex !sp-gap-2 !sp-justify-between !sp-py-1.5 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 last:!sp-border-b-0 !sp-text-[11px]"><span><b class="!sp-text-service-navy">' . self::e($item['label']) . ':</b> ' . self::e($item['detail']) . '</span>'
      . ($live && $item['key'] === 'politicas' ? '<span class="!sp-whitespace-nowrap !sp-text-[10px] !sp-font-semibold !sp-text-amber-800">Requiere justificación</span>' : '') . '</li>', $items));
    return '<section class="' . self::SECTION . '" data-owner-credit-adjustments><h4 class="' . self::H4 . '">' . self::icon('edit') . ($live ? 'Cambios frente al sistema' : 'Cambios aprobados frente al sistema') . '</h4>'
      . ($live ? self::guide('cambios', ['Qué es' => 'Lo que cambiaste frente a lo que calcula o propone el sistema. Queda guardado con la decisión, con tu nombre y la justificación. Solo se registra: la justificación se pide únicamente si la solicitud incumple alguna política.']) : '')
      . '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' . $rows . '</ul></section>';
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
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . self::icon('cash') . 'Variables de entrada</h4>'
      . (($usury = self::usuryWarning((float) $settings['reference_rate'], (1 + (float) $settings['usury_annual']) ** (1 / 12) - 1)) !== '' ? '<div class="!sp-mb-4">' . self::alert($usury, 'bad') . '</div>' : '')
      . '<div class="' . $grid . '">'
      . $field('Tasa de referencia N.M.V (%)', $input('reference_rate', $pct($settings['reference_rate']), ' inputmode="decimal"'), 'Tasa normal de crédito de consumo de referencia.')
      . $field('Descuento máximo de tasa (%)', $input('policy_discount', $pct($settings['policy_discount'], 1), ' inputmode="decimal"'), 'El analista puede bajar la tasa de referencia hasta este porcentaje al aprobar; más abajo queda fuera de política. La simulación usa la tasa de referencia, sin descuento.')
      . $field('Descuento sin mandato (% del canon)', $input('no_mandate_deduction', $pct($settings['no_mandate_deduction'], 1), ' inputmode="decimal"'), 'Cuando el contrato no tiene mandato con comisión registrada.')
      . $field('Meses de ingreso neto a prestar', '<input class="' . $ui::INPUT . '" type="number" name="income_months" min="1" max="24" value="' . (int) $settings['income_months'] . '">', 'Tope = ingreso neto × estos meses.')
      . $field('Meses mínimos de contrato restantes', '<input class="' . $ui::INPUT . '" type="number" name="min_remaining_months" min="0" max="60" value="' . (int) $settings['min_remaining_months'] . '">', 'Por debajo se alerta "contrato próximo a vencer".')
      . $field('Ingreso utilizable para la cuota (%)', $input('usable_income_factor', $pct($settings['usable_income_factor'], 1), ' inputmode="decimal"'), 'Porción del ingreso neto de cada contrato (canon − descuentos) que se puede comprometer con la cuota. 100 % = todo.')
      . $field('Cobertura mínima (veces la cuota)', $input('min_coverage', number_format((float) $settings['min_coverage'], 2, ',', ''), ' inputmode="decimal"'), 'En cada fecha de pago el ingreso disponible debe ser al menos la cuota por este número. 1,00 = alcanza exacto; 1,20 exige 20 % de margen.')
      . $field('Meses de margen antes del fin del contrato', '<input class="' . $ui::INPUT . '" type="number" name="term_margin_months" min="0" max="12" value="' . (int) $settings['term_margin_months'] . '">', 'Plazo máximo = meses de vigencia que le quedan al contrato más largo menos estos meses (n − 2). Aplica a la solicitud, a la cotización y al liquidador.')
      . '</div></section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . UI::icon('bolt') . 'Avisos</h4><div class="' . $grid . '">'
      . $field('Exigir pagaré firmado para aprobar', $yesNo('require_promissory', (bool) ($settings['require_promissory'] ?? true)), 'Pagaré en blanco y carta de instrucciones firmados por quienes elija el analista. Cada persona lo firma una vez y sirve para todos sus créditos.')
      . $field('Correo al propietario al registrar la solicitud', $yesNo('notify_owner_request_email', (bool) $settings['notify_owner_request_email']), 'Confirma que recibimos su solicitud: valor, plazo, destinación y contrato.')
      . $field('WhatsApp al propietario al registrar la solicitud', $yesNo('notify_owner_request_whatsapp', (bool) $settings['notify_owner_request_whatsapp']), 'Plantilla scm_credito_propietario_solicitud_v1.')
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
    // El solicitante se identifica por su documento: así ya se sabe quién es y en qué contratos es propietario.
    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-5" data-owner-credit-new-form autocomplete="off"><input type="hidden" name="contract_id" value=""><input type="hidden" name="owner_key" value="">'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $step('1') . '¿Para quién es el crédito?</h4>'
      . self::guide('nuevo-tipo', [
        'Tipos' => 'Propietario, arrendatario, codeudor, externo o funcionario. Por ahora solo está habilitado el crédito a propietarios; los demás se habilitarán con su propia evaluación.',
      ])
      . self::beneficiaryTypes(array_keys(OwnerCreditService::BENEFICIARY_TYPES), 'propietario') . '</section>'
      . '<section class="' . self::SECTION . '"><h4 class="' . self::H4 . '">' . $step('2') . 'Documento o nombre del propietario</h4>'
      . self::guide('nuevo-documento', [
        'Qué escribir' => 'La cédula o el NIT de quien pide el crédito, con o sin puntos, o su nombre. Por nombre aparecen las personas que coinciden: elige una y se buscan sus contratos por su documento.',
        'Qué encuentra' => 'Los contratos de arrendamiento donde esa persona es propietaria (en el contrato, en su ficha o en el contrato de mandato, aunque sean varios propietarios).',
        'Contrato principal' => 'No se elige: el sistema toma el contrato de vigencia más larga, que es el que da el plazo máximo, y de él se descuenta primero la cuota. Si hace falta, el analista lo cambia en el liquidador.',
        'Revisión previa' => 'Las políticas que no dependen del valor (contrato activo, vigencia, siniestro, créditos vigentes) revisadas sobre todos sus contratos. Es informativa: la solicitud se puede registrar igual y el analista decide.',
        'Cupo preaprobado' => 'Suma del ingreso neto (canon − descuentos) de todos sus contratos × los meses de política. Las cuotas máximas son los meses de vigencia que le quedan al contrato más largo menos el margen de Configuración (n − 2); con el valor, la cobertura de pago puede bajarlas.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-[minmax(0,1fr)_auto] !sp-gap-3 !sp-items-end">'
      . $field('Cédula, NIT o nombre' . $required, '<input class="' . $ui::INPUT . '" name="document" data-owner-credit-search-query placeholder="Ej. 1143415938 o Royner Guardo">')
      . '<button type="button" class="' . $ui::NAVY . ' !sp-h-10" data-owner-credit-search>' . $ui::icon('filter', '!sp-w-3.5 !sp-h-3.5') . 'Buscar</button></div>'
      . '<p class="' . self::NOTE . ' !sp-mt-2" data-owner-credit-search-status></p>'
      . '<div class="!sp-mt-2 !sp-p-3 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200" data-owner-credit-person hidden></div>'
      . '<div class="!sp-mt-2 !sp-p-3 !sp-rounded-lg !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200" data-owner-credit-preapproval hidden></div>'
      . '<div class="!sp-mt-2 !sp-p-3 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200" data-owner-credit-checks hidden></div>'
      . '<div class="!sp-grid !sp-gap-2 !sp-mt-2" data-owner-credit-search-results aria-label="Contratos del propietario"></div></section>'
      . '<section class="' . self::SECTION . '" data-owner-credit-request hidden><h4 class="' . self::H4 . '">' . $step('3') . 'Datos de la solicitud</h4>'
      . self::guide('nuevo-datos', [
        'Primero simula' => 'Con "Simular y ver PDF" revisas con el propietario la cuota y la tabla de amortización. Si está de acuerdo, aparece "Registrar solicitud". Si cambias el valor, el plazo o la destinación, vuelve a simular.',
        'Qué pasa al registrar' => 'Solo se registra lo que pide el propietario: todavía no se aprueba nada. El analista lo evalúa en el liquidador y ahí se aprueba o rechaza.',
        'Plazo solicitado' => 'Los meses que pidió el propietario, hasta el plazo máximo del cupo preaprobado: meses de vigencia del contrato más largo menos el margen de Configuración (n − 2). La simulación revisa la cobertura de todas las cuotas. Si el contrato fue renovado y la fecha no está al día, regístralo con el máximo: el analista corrige la fecha fin en el liquidador.',
        'Simular y ver PDF' => 'Calcula la cuota y la tabla de amortización con la tasa de política sin registrar la solicitud. Primero escribe el valor y el plazo: sin ellos no se simula. La simulación queda en el historial de los contratos y de los inmuebles del propietario.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-3 !sp-gap-3">'
      . $field('Valor solicitado' . $required, '<input class="' . $ui::INPUT . '" name="amount" inputmode="numeric" data-owner-credit-money required>')
      . $field('Plazo solicitado (meses)' . $required, '<input class="' . $ui::INPUT . '" name="term" type="number" min="1" max="60" placeholder="Escribe los meses" required>')
      . $field('Destinación', '<select class="' . $ui::INPUT . '" name="purpose">' . $options . '</select>')
      . '</div><p class="' . self::NOTE . ' !sp-mt-2" data-owner-credit-term-hint></p>'
      . '<p class="!sp-m-0 !sp-mt-3 !sp-p-2.5 !sp-rounded-lg !sp-bg-slate-50 !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-[11px] !sp-font-semibold !sp-text-service-navy" data-owner-credit-disclaimer>' . self::e(OwnerCreditService::DISCLAIMER) . '</p></section>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-end !sp-gap-3"><p class="!sp-m-0 !sp-text-[11px]" data-owner-credit-status role="status" aria-live="polite"></p>'
      . '<p class="' . self::NOTE . ' !sp-m-0" data-owner-credit-register-hint>Primero simula y revisa el PDF con el propietario; si está de acuerdo, registra la solicitud.</p>'
      . '<button type="button" class="' . $ui::NAVY . ' !sp-text-xs !sp-px-4 !sp-py-2.5" data-owner-credit-simulate-pdf>' . $ui::icon('document', '!sp-w-3.5 !sp-h-3.5 !sp-text-service-yellow') . 'Simular y ver PDF</button>'
      // Solo aparece después de ver la simulación con los datos actuales.
      . '<button type="submit" class="' . $ui::PRIMARY . '" data-owner-credit-register style="display:none !important">' . $ui::icon('check', '!sp-w-3.5 !sp-h-3.5') . 'Registrar solicitud</button></div></form>';
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

  /**
   * Cupo preaprobado de la persona con todos sus contratos, también en las solicitudes que vienen de una cotización.
   * Solo mientras está pendiente: decidido, el cupo de hoy ya incluye este crédito.
   * @param array<string,mixed> $work
   */
  private static function preapproval(array $work): string
  {
    $p = $work['preapproval'] ?? null;
    if (!is_array($p) || !in_array($work['credit']['status'], ['pendiente', 'simulacion'], true)) return '';
    $requested = (float) $p['requested'];
    $fits = $requested <= (float) $p['available'] + 0.5;
    $note = $requested > 0
      ? ((int) $work['credit']['quote_id'] > 0 ? 'La cotización pide ' : 'Se solicitan ') . self::money($requested) . ($fits ? ': está dentro del cupo disponible.' : ': supera el cupo disponible en ' . self::money($requested - (float) $p['available']) . '.')
      : 'Escribe el valor solicitado para compararlo con el cupo.';
    return '<section class="' . self::SECTION . ' !sp-border-service-yellow" data-owner-credit-preapproval-summary><h4 class="' . self::H4 . '">' . self::icon('cash') . 'Cupo preaprobado del propietario</h4>'
      . self::guide('cupo', [
        'Cupo preaprobado' => 'Ingreso neto de todos sus contratos Entregado o Por entregar × los meses de política (Configuración).',
        'Saldo de créditos vigentes' => 'Lo que todavía debe de sus créditos aprobados; se resta del cupo.',
        'Plazo máximo' => 'Meses de vigencia del contrato más largo menos el margen de Configuración (n − 2). Con el valor pedido, la cobertura de pago puede bajarlo.',
      ])
      . '<div class="!sp-grid !sp-grid-cols-2 !sp-gap-3">'
      . self::tile('Cupo (' . (int) $p['contracts'] . ' contrato' . ((int) $p['contracts'] === 1 ? '' : 's') . ')', self::money((float) $p['amount']), 'key')
      . self::tile('Saldo de créditos vigentes', self::money((float) $p['outstanding']))
      . self::tile('Disponible', self::money((float) $p['available']), (float) $p['available'] < 0.5 ? 'bad' : 'key')
      . self::tile('Plazo máximo', (int) $p['max_term'] . ' meses', (int) $p['max_term'] <= 0 ? 'bad' : '')
      . '</div><p class="' . self::NOTE . ' !sp-mt-3' . ($fits ? '' : ' !sp-text-red-700 !sp-font-semibold') . '">' . self::e($note) . '</p>'
      . '<p class="' . self::NOTE . ' !sp-mt-2 !sp-font-semibold !sp-text-service-navy" data-owner-credit-disclaimer>' . self::e(OwnerCreditService::DISCLAIMER) . '</p></section>';
  }

  /**
   * La tasa de referencia (la que se sugiere y se simula) no puede superar la usura: el crédito se recorta a la usura,
   * pero la referencia de Configuración está desactualizada. Indica hasta cuánto bajarla.
   */
  public static function usuryWarning(float $referenceRate, float $usuryMonthly): string
  {
    if ($referenceRate <= $usuryMonthly + 1e-9) return '';
    // Con 4 decimales: la diferencia puede ser de milésimas (2,1200 % frente a 2,1176 %).
    $fine = static fn(float $value): string => number_format($value * 100, 4, ',', '.') . ' %';
    return 'La tasa de referencia (' . $fine($referenceRate) . ' mensual) supera la usura (' . $fine($usuryMonthly) . ' mensual): los créditos se recortan a la usura. Baja la tasa de referencia en Configuración a máximo ' . number_format(floor($usuryMonthly * 10000) / 100, 2, ',', '.') . ' %.';
  }

  /** Por qué el sistema sugiere esa probabilidad de desocupación y avisos de los demás contratos. @param array<string,mixed> $ev */
  private static function vacancyNote(array $ev): string
  {
    $signal = $ev['vacancy_signal'] ?? null;
    if (!is_array($signal)) return '';
    $differs = $signal['level'] !== $ev['vacancy'];
    return '<p class="' . self::NOTE . ' !sp-mt-3" data-owner-credit-vacancy-note>Probabilidad sugerida por el sistema: <b>' . self::e($signal['reason']) . '</b>' . ($differs ? ' Elegiste ' . self::e($ev['vacancy']) . '.' : '') . '</p>'
      . implode('', array_map(static fn(string $alert): string => self::alert($alert, 'bad'), $signal['alerts']));
  }

  /** @param array<string,mixed> $contract @param list<array<string,mixed>> $others */
  private static function mainContractSelect(array $contract, array $others): string
  {
    $options = '<option value="' . (int) $contract['id'] . '" selected>#' . self::e($contract['code']) . '</option>';
    foreach ($others as $row) $options .= '<option value="' . (int) $row['id'] . '">#' . self::e($row['contrato']) . ' · ' . (int) $row['max_months'] . ' meses</option>';
    return '<label class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-0.5 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-[10px] !sp-text-service-muted" title="La cuota se descuenta primero de este contrato">Contrato principal'
      . '<select class="!sp-h-6 !sp-py-0 !sp-pl-1 !sp-pr-6 !sp-rounded !sp-border-0 !sp-bg-transparent !sp-text-[10px] !sp-font-bold !sp-text-service-navy" name="main_contract_id" data-owner-credit-main-contract aria-label="Contrato principal">' . $options . '</select></label>';
  }

  /** @param list<array{key:string,name:string,document:string,current:bool}> $owners */
  private static function ownerSelect(array $credit, array $owners): string
  {
    $options = '';
    foreach ($owners as $owner) $options .= '<option value="' . self::e($owner['key']) . '"' . ($owner['current'] ? ' selected' : '') . '>' . self::e($owner['name'] . ($owner['document'] !== '' ? ' · ' . $owner['document'] : '')) . '</option>';
    return '<label class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-0.5 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-[10px] !sp-text-service-muted">' . self::e(OwnerCreditService::beneficiaryLabel($credit))
      . '<select class="!sp-h-6 !sp-py-0 !sp-pl-1 !sp-pr-6 !sp-rounded !sp-border-0 !sp-bg-transparent !sp-text-[10px] !sp-font-bold !sp-text-service-navy" name="owner_key" data-owner-credit-owner aria-label="A quién es el crédito">' . $options . '</select></label>';
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
