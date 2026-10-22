<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/** HTML del liquidador de créditos, del panel Métricas › Créditos y de su configuración. */
final class OwnerCreditView
{
  private const VACANCY = [
    'Baja' => 'Baja · plazo y valor solicitados, tasa de política',
    'Media' => 'Media · plazo máximo, tasa de referencia',
    'Alta' => 'Alta · 3 meses, tasa de riesgo',
  ];

  /** Contenedor de la pestaña; el contenido llega por AJAX. */
  public function shell(bool $canCreate, bool $canConfigure): string
  {
    $status = '<option value="">Todos</option>';
    foreach (OwnerCreditService::STATUSES as $key => $label) $status .= '<option value="' . $key . '">' . $label . '</option>';
    return '<section class="scm-owner-credits" data-scm-owner-credits data-scm-loaded="0">'
      . '<header class="scm-owner-credits-head"><div><span class="scm-eyebrow">Métricas</span><h2>Créditos a propietarios</h2><p>Solicitudes de financiación, decisiones y cartera vigente por contrato.</p></div><div class="scm-owner-credits-head-actions">'
      . ($canCreate ? '<button type="button" class="scm-btn-primary" data-owner-credit-new>Nuevo crédito</button>' : '')
      . ($canConfigure ? '<button type="button" class="scm-btn-secondary" data-owner-credit-settings>Configuración</button>' : '')
      . '</div></header>'
      . '<form class="scm-owner-credits-filters" data-owner-credit-filters autocomplete="off">'
      . '<label class="scm-cotizacion-dialog-field"><span>Estado</span><select name="status">' . $status . '</select></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Desde</span><input type="date" name="from"></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Hasta</span><input type="date" name="to"></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Buscar</span><input name="search" placeholder="Contrato, propietario, inmueble o cotización"></label>'
      . '<button type="submit" class="scm-btn-primary">Filtrar</button></form>'
      . '<div data-owner-credit-report><p class="scm-owner-credits-empty">Cargando créditos…</p></div></section>';
  }

  /** @param array<string,mixed> $report */
  public function report(array $report, bool $canOpen): string
  {
    $t = $report['totals'];
    $metric = static fn(string $label, string $value, string $hint = ''): string => '<article class="scm-owner-credits-metric"><span>' . self::e($label) . '</span><strong>' . $value . '</strong>' . ($hint !== '' ? '<small>' . self::e($hint) . '</small>' : '') . '</article>';
    $html = '<div class="scm-owner-credits-metrics">'
      . $metric('Solicitudes', (string) $t['count'], $t['pendiente'] . ' pendientes')
      . $metric('Aprobados', (string) ($t['aprobado'] + $t['pagado']), $t['rechazado'] . ' rechazados · ' . $t['anulado'] . ' anulados')
      . $metric('Tasa de aprobación', $t['approval_rate'] === null ? '—' : number_format($t['approval_rate'] * 100, 0) . ' %')
      . $metric('Monto aprobado', self::money($t['approved_amount']), 'Intereses: ' . self::money($t['interest']))
      . $metric('Saldo vigente', self::money($t['outstanding']), $t['aprobado'] . ' créditos por pagar')
      . '</div>';
    if ($report['rows'] === []) return $html . '<p class="scm-owner-credits-empty">No hay créditos con estos filtros.</p>';
    $html .= '<div class="scm-owner-credits-table-wrap"><table class="scm-owner-credits-table"><thead><tr><th>#</th><th>Contrato / propietario</th><th>Origen</th><th>Solicitado</th><th>Aprobado</th><th>Cuota</th><th>Saldo</th><th>Estado</th><th>Fecha</th><th></th></tr></thead><tbody>';
    foreach ($report['rows'] as $row) {
      $approved = in_array($row['status'], ['aprobado', 'pagado'], true);
      $html .= '<tr><td>' . (int) $row['id'] . '</td>'
        . '<td><b>Contrato #' . self::e($row['contract_code']) . '</b><small>' . self::e($row['owner_name']) . ' · Inm. ' . self::e($row['property']) . '</small></td>'
        . '<td>' . ((int) $row['quote_id'] > 0 ? 'Cotización #' . (int) $row['quote_id'] . ((string) $row['ticket_id'] !== '' ? '<small>Caso #' . self::e($row['ticket_id']) . '</small>' : '') : 'Manual') . '</td>'
        . '<td>' . self::money($row['requested_amount']) . '<small>' . (int) $row['requested_term'] . ' meses</small></td>'
        . '<td>' . ($approved ? self::money($row['approved_amount']) . '<small>' . (int) $row['approved_term'] . ' m · ' . self::percent((float) $row['approved_rate']) . '</small>' : '—') . '</td>'
        . '<td>' . ($approved ? self::money($row['monthly_payment']) : '—') . '</td>'
        . '<td>' . ($row['balance'] !== null ? self::money($row['balance']) : '—') . '</td>'
        . '<td>' . self::status((string) $row['status']) . ((int) $row['policy_override'] === 1 ? '<small>Fuera de política</small>' : '') . '</td>'
        . '<td>' . date('d/m/Y', (int) $row['created_at']) . ((int) $row['decided_at'] > 0 ? '<small>Decidido ' . date('d/m/Y', (int) $row['decided_at']) . '</small>' : '') . '</td>'
        . '<td>' . ($canOpen ? '<button type="button" class="scm-case-work-btn" data-owner-credit-open data-credit-id="' . (int) $row['id'] . '">Abrir liquidador</button>' : '') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
  }

  /** @param array<string,mixed> $work */
  public function workspace(array $work, bool $canDecide, bool $canManage): string
  {
    $credit = $work['credit'];
    $contract = $work['contract'];
    $ev = $work['evaluation'];
    $c = $work['conditions'];
    $pending = $credit['status'] === 'pendiente';
    $editable = $pending && $canDecide;
    $ro = $editable ? '' : ' readonly';
    $dis = $editable ? '' : ' disabled';
    $field = static fn(string $label, string $control, string $class = ''): string => '<label class="scm-cotizacion-dialog-field' . ($class !== '' ? ' ' . $class : '') . '"><span>' . self::e($label) . '</span>' . $control . '</label>';
    $money = static fn(string $name, float $value) => '<input name="' . $name . '" inputmode="numeric" data-owner-credit-money value="' . self::e(self::money($value)) . '"' . $ro . '>';
    $static = static fn(string $label, string $value): string => '<div class="scm-owner-credit-static"><span>' . self::e($label) . '</span><strong>' . $value . '</strong></div>';

    $years = $contract['inicio_ts'] > 0 && $contract['fin_ts'] > 0 ? number_format(($contract['fin_ts'] - $contract['inicio_ts']) / 86400 / 365, 1, ',', '.') : '—';
    $html = '<form class="scm-owner-credit-form" data-owner-credit-form data-credit-id="' . (int) $credit['id'] . '" autocomplete="off">'
      . '<div class="scm-owner-credit-summary">' . self::status((string) $credit['status'])
      . '<span>Contrato <b>#' . self::e($contract['code']) . '</b></span><span>' . self::e($credit['owner_name']) . '</span><span>Inmueble ' . self::e($credit['property']) . '</span>'
      . ((int) $credit['quote_id'] > 0 ? '<span>Cotización <b>#' . (int) $credit['quote_id'] . '</b></span>' : '<span>Crédito manual</span>') . '</div>'

      . '<section class="scm-maint-quote-section"><h4>Datos del contrato de arriendo vigente</h4><div class="scm-owner-credit-statics">'
      . $static('Inicio del contrato', $contract['inicio_ts'] > 0 ? date('d/m/Y', $contract['inicio_ts']) : '—')
      . ($editable
        ? '<div class="scm-owner-credit-static scm-owner-credit-end"><span>Fin del contrato</span><input type="date" name="contract_end" value="' . ($contract['fin_ts'] > 0 ? date('Y-m-d', $contract['fin_ts']) : '') . '" aria-label="Fin del contrato">'
          . '<button type="button" class="scm-owner-credit-link" data-owner-credit-save-end>Guardar en el contrato</button></div>'
        : $static('Fin del contrato', $contract['fin_ts'] > 0 ? date('d/m/Y', $contract['fin_ts']) : '—'))
      . $static('Años arrendados', $years)
      . $static('Estado', self::e($contract['estado'] ?: '—'))
      . $static('Aseguradora', self::e($contract['aseguradora'] ?: '—'))
      . $static('Siniestro', $contract['siniestrado'] ? '<em class="is-bad">Siniestrado</em>' : 'No')
      . '</div></section>'

      . '<section class="scm-maint-quote-section"><h4>Ingreso del propietario</h4><p class="scm-owner-credit-note">' . self::e($work['income_source']) . '</p><div class="scm-maint-quote-grid">'
      . $field('Ingreso bruto (canon)', $money('gross', $ev['gross']));
    foreach (OwnerCreditService::DEDUCTIONS as $key => $label) {
      $html .= $field('(−) ' . $label, $money('ded_' . $key, (float) ($ev['deductions'][$key] ?? 0)));
    }
    $purposeOptions = '';
    foreach (OwnerCreditSettings::PURPOSES as $purpose) $purposeOptions .= '<option' . ($purpose === $credit['purpose'] ? ' selected' : '') . '>' . self::e($purpose) . '</option>';
    $vacancyOptions = '';
    foreach (self::VACANCY as $key => $label) $vacancyOptions .= '<option value="' . $key . '"' . ($key === $ev['vacancy'] ? ' selected' : '') . '>' . self::e($label) . '</option>';
    $html .= '</div></section>'
      . '<section class="scm-maint-quote-section"><h4>Información de la solicitud</h4><div class="scm-maint-quote-grid">'
      . $field('Fecha de solicitud', '<input value="' . date('d/m/Y', (int) $credit['created_at']) . '" readonly>')
      . $field('Valor solicitado', $money('requested_amount', (float) ($credit['requested_amount'] ?? 0)))
      . $field('Plazo solicitado (meses)', '<input name="requested_term" type="number" min="1" max="60" value="' . (int) ($credit['requested_term'] ?: $work['settings']['default_term']) . '"' . $ro . '>')
      . $field('Destinación del crédito', '<select name="purpose"' . $dis . '>' . $purposeOptions . '</select>')
      . $field('Probabilidad de que desocupen', '<select name="vacancy"' . $dis . '>' . $vacancyOptions . '</select>', 'is-wide')
      . '</div></section>'
      . '<div data-owner-credit-results>' . $this->results($work) . '</div>'

      . '<section class="scm-maint-quote-section"><h4>Condiciones según el analista</h4><div class="scm-maint-quote-grid">'
      . $field('Valor a colocar', $money('amount', $c['amount']))
      . $field('Plazo (meses)', '<input name="term" type="number" min="1" max="60" value="' . (int) $c['term'] . '"' . $ro . '>')
      . $field('Tasa N.M.V (%)', '<input name="rate" inputmode="decimal" value="' . self::e(number_format($c['rate'] * 100, 4, ',', '')) . '"' . $ro . '>')
      . '</div>' . ($editable ? '<button type="button" class="scm-btn-secondary" data-owner-credit-use-suggested>Usar condición sugerida</button>' : '') . '</section>'
      . '<div data-owner-credit-schedule>' . $this->schedule($work) . '</div>'
      . $this->history($work)
      . $this->actions($credit, $canDecide, $canManage)
      . '<div class="scm-cia-modal-status" data-owner-credit-status role="status" aria-live="polite"></div>'
      . '</form>';
    return $html;
  }

  /** Bloques calculados que se refrescan al editar el formulario. @param array<string,mixed> $work */
  public function results(array $work): string
  {
    $ev = $work['evaluation'];
    $s = $ev['suggested'];
    $row = static fn(string $label, string $value, string $class = ''): string => '<div class="scm-owner-credit-static' . ($class !== '' ? ' ' . $class : '') . '"><span>' . self::e($label) . '</span><strong>' . $value . '</strong></div>';
    $policies = '';
    foreach ($work['policies'] as $policy) {
      $policies .= '<li class="' . ($policy['ok'] ? 'is-ok' : 'is-bad') . '"><b>' . ($policy['ok'] ? '✓' : '✕') . ' ' . self::e($policy['label']) . '</b><span>' . self::e($policy['detail']) . '</span></li>';
    }
    $endChanged = (int) ($work['contract']['fin_saved_ts'] ?? 0) !== (int) ($work['contract']['fin_ts'] ?? 0);
    return (($work['term_error'] ?? '') !== '' ? '<p class="scm-owner-credit-alert" role="alert">' . self::e($work['term_error']) . '</p>' : '')
      . ($endChanged ? '<p class="scm-owner-credit-alert is-info">Estás simulando con la fecha fin ' . date('d/m/Y', (int) $work['contract']['fin_ts']) . '. Usa "Guardar en el contrato" o se guardará al decidir el crédito.</p>' : '')
      . '<section class="scm-maint-quote-section"><h4>Política de créditos de la inmobiliaria</h4><div class="scm-owner-credit-statics">'
      . $row('Ingreso neto (INO)', self::money($ev['net_income']), 'is-key')
      . $row('Meses máximos a prestar', (string) $ev['max_months'])
      . $row('Tasa ajustada de colocación', self::percent($ev['policy_rate']))
      . $row('Valor máximo a prestar', self::money($ev['max_amount']), 'is-key')
      . $row('Cuota de política', self::money($ev['policy_payment']))
      . '</div><h5>Variables que superan la política</h5><div class="scm-owner-credit-statics">'
      . $row('Meses que excede', (string) $ev['exceed_months'], $ev['exceed_months'] < 0 ? 'is-warn' : '')
      . $row('Valor que excede', self::money($ev['exceed_amount'], true), $ev['exceed_amount'] < 0 ? 'is-warn' : '')
      . $row('Tasa para el riesgo', self::percent($ev['risk_rate']))
      . $row('Cuota con tasa de riesgo', self::money($ev['risk_payment']))
      . '</div></section>'
      . '<section class="scm-maint-quote-section"><h4>Condición sugerida · probabilidad ' . self::e($ev['vacancy']) . '</h4><div class="scm-owner-credit-statics" data-owner-credit-suggested data-amount="' . round((float) $s['amount']) . '" data-term="' . (int) $s['term'] . '" data-rate="' . self::e(number_format($s['rate'] * 100, 4, ',', '')) . '">'
      . $row('Plazo máximo', $s['term'] . ' meses')
      . $row('Tasa', self::percent($s['rate']))
      . $row('Valor máximo a colocar', self::money($s['amount']))
      . $row('Cuota mensual', self::money($s['payment']), 'is-key')
      . '</div></section>'
      . '<section class="scm-maint-quote-section"><h4>Políticas</h4><ul class="scm-owner-credit-policies">' . $policies . '</ul>'
      . ($work['policies_ok'] ? '' : '<p class="scm-owner-credit-note is-bad">La solicitud incumple políticas. Para aprobarla escribe la justificación; quedará en el historial.</p>')
      . '</section>';
  }

  /** @param array<string,mixed> $work */
  public function schedule(array $work): string
  {
    $c = $work['conditions'];
    $rows = '';
    foreach ($work['schedule'] as $line) {
      $rows .= '<tr><td>' . $line['mes'] . '</td><td>' . self::money($line['saldo_inicial']) . '</td><td>' . self::money($line['cuota']) . '</td><td>' . self::money($line['interes']) . '</td><td>' . self::money($line['abono']) . '</td><td>' . self::money($line['saldo']) . '</td></tr>';
    }
    return '<section class="scm-maint-quote-section"><h4>Tabla de amortización</h4><div class="scm-owner-credit-statics">'
      . '<div class="scm-owner-credit-static is-key"><span>Cuota mensual</span><strong>' . self::money($c['payment']) . '</strong></div>'
      . '<div class="scm-owner-credit-static"><span>Total intereses</span><strong>' . self::money($c['total_interest']) . '</strong></div>'
      . '<div class="scm-owner-credit-static"><span>Total a pagar</span><strong>' . self::money($c['amount'] + $c['total_interest']) . '</strong></div></div>'
      . ($rows === '' ? '<p class="scm-owner-credit-note">Indica valor y plazo para ver la tabla.</p>' : '<div class="scm-owner-credits-table-wrap"><table class="scm-owner-credits-table is-compact"><thead><tr><th>Mes</th><th>Saldo de capital</th><th>Cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
      . '</section>';
  }

  /** @param array<string,mixed> $work */
  private function history(array $work): string
  {
    $others = '';
    foreach ($work['other_credits'] as $row) {
      $others .= '<li>#' . (int) $row['id'] . ' · contrato ' . self::e($row['contract_code']) . ' · ' . self::status((string) $row['status']) . ' · ' . self::money((float) ($row['approved_amount'] ?? 0) ?: (float) $row['requested_amount']) . ' · ' . date('d/m/Y', (int) $row['created_at']) . '</li>';
    }
    $labels = ['solicitado' => 'Solicitud registrada', 'reabierto' => 'Solicitud reabierta', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'pagado' => 'Marcado como pagado', 'anulado' => 'Anulado'];
    $events = '';
    foreach ($work['events'] as $event) {
      $detail = trim((string) ($event['details']['nota'] ?? $event['details']['motivo'] ?? ''));
      $events .= '<li><b>' . self::e($labels[$event['action']] ?? $event['action']) . '</b> · ' . date('d/m/Y g:i a', (int) $event['created_at']) . ($event['actor_name'] !== '' ? ' · ' . self::e($event['actor_name']) : '') . ($detail !== '' ? '<span>' . self::e($detail) . '</span>' : '') . '</li>';
    }
    return '<section class="scm-maint-quote-section"><h4>Otros créditos del propietario</h4>' . ($others !== '' ? '<ul class="scm-owner-credit-list">' . $others . '</ul>' : '<p class="scm-owner-credit-note">Sin otros créditos registrados.</p>')
      . '<h4>Historial del crédito</h4><ul class="scm-owner-credit-list">' . $events . '</ul></section>';
  }

  /** @param array<string,mixed> $credit */
  private function actions(array $credit, bool $canDecide, bool $canManage): string
  {
    $status = (string) $credit['status'];
    $buttons = '';
    if ($status === 'pendiente' && $canDecide) {
      $buttons .= '<button type="button" class="scm-btn-secondary scm-danger-action" data-owner-credit-decide="rechazado">Rechazar</button><button type="button" class="scm-btn-primary" data-owner-credit-decide="aprobado">Aprobar crédito</button>';
    }
    if ($status === 'aprobado' && $canManage) {
      $buttons .= '<button type="button" class="scm-btn-primary" data-owner-credit-close="pagado">Marcar como pagado</button>';
    }
    if (in_array($status, ['pendiente', 'aprobado'], true) && $canManage) {
      $buttons .= '<button type="button" class="scm-btn-secondary scm-danger-action" data-owner-credit-close="anulado">Anular</button>';
    }
    $note = (string) ($credit['decision_note'] ?? '');
    return '<section class="scm-maint-quote-section">'
      . ($status !== 'pendiente' ? '<p class="scm-owner-credit-note"><b>Decisión:</b> ' . self::e(OwnerCreditService::STATUSES[$status] ?? $status) . ((string) $credit['decided_by_name'] !== '' ? ' por ' . self::e($credit['decided_by_name']) : '') . ((int) $credit['decided_at'] > 0 ? ' el ' . date('d/m/Y', (int) $credit['decided_at']) : '') . ($note !== '' ? '<br>' . nl2br(self::e($note)) : '') . '</p>' : '')
      . ($buttons !== '' ? '<label class="scm-cotizacion-dialog-field is-wide"><span>' . ($status === 'pendiente' ? 'Observación / justificación' : 'Observación') . '</span><textarea name="note" rows="3" maxlength="2000" placeholder="Obligatoria para rechazar, para aprobar fuera de política, para marcar pagado o anular."></textarea></label><div class="scm-public-services-review-actions">' . $buttons . '</div>' : '')
      . '</section>';
  }

  /** @param array<string,mixed> $settings */
  public function settingsForm(array $settings): string
  {
    $field = static fn(string $label, string $control, string $hint = ''): string => '<label class="scm-cotizacion-dialog-field"><span>' . self::e($label) . '</span>' . $control . ($hint !== '' ? '<small>' . self::e($hint) . '</small>' : '') . '</label>';
    $pct = static fn(float $value, int $decimals = 2): string => number_format($value * 100, $decimals, ',', '');
    $auto = (bool) $settings['usury_auto'];
    $lock = $auto ? ' readonly' : '';
    $source = $auto
      ? 'Automática desde datos.gov.co (Superfinanciera): interés bancario corriente de consumo y ordinario '
        . $pct((float) $settings['usury_bank_rate']) . ' % × 1,5'
        . ((string) $settings['usury_resolution'] !== '' ? ', resolución ' . $settings['usury_resolution'] : '')
        . ((string) $settings['usury_valid_until'] !== '' ? ', vigente hasta ' . date('d/m/Y', (int) strtotime((string) $settings['usury_valid_until'])) : '') . '.'
        . ((int) $settings['usury_synced_at'] > 0 ? ' Última consulta: ' . date('d/m/Y g:i a', (int) $settings['usury_synced_at']) . '.' : '')
      : 'Manual: se usa la tasa escrita aquí.';
    return '<form class="scm-owner-credit-form" data-owner-credit-settings-form autocomplete="off"><section class="scm-maint-quote-section"><h4>Tasa de usura</h4>'
      . '<p class="scm-owner-credit-note">' . self::e($source) . '</p>'
      . ((string) $settings['usury_sync_error'] !== '' ? '<p class="scm-owner-credit-note is-bad">No se pudo actualizar: ' . self::e($settings['usury_sync_error']) . ' Se conserva la última tasa.</p>' : '')
      . '<div class="scm-maint-quote-grid">'
      . $field('Origen de la tasa', '<select name="usury_auto"><option value="1"' . ($auto ? ' selected' : '') . '>Automática (datos.gov.co)</option><option value="0"' . ($auto ? '' : ' selected') . '>Manual</option></select>')
      . $field('Tasa de usura (efectiva anual, %)', '<input name="usury_annual" inputmode="decimal" value="' . $pct($settings['usury_annual']) . '"' . $lock . '>', 'Límite legal: la tasa mensual del crédito no puede superar su equivalente mes vencido.')
      . $field('Vigente desde', '<input type="date" name="usury_updated_at" value="' . self::e($settings['usury_updated_at']) . '"' . $lock . '>')
      . '</div>' . ($auto ? '<button type="button" class="scm-btn-secondary" data-owner-credit-usury-refresh>Actualizar ahora desde datos.gov.co</button>' : '')
      . '</section><section class="scm-maint-quote-section"><h4>Variables de entrada</h4><div class="scm-maint-quote-grid">'
      . $field('Tasa de referencia N.M.V (%)', '<input name="reference_rate" inputmode="decimal" value="' . $pct($settings['reference_rate']) . '">', 'Tasa normal de crédito de consumo de referencia.')
      . $field('Descuento por política (%)', '<input name="policy_discount" inputmode="decimal" value="' . $pct($settings['policy_discount'], 1) . '">')
      . $field('Descuento sin contrato de mandato (% del canon)', '<input name="no_mandate_deduction" inputmode="decimal" value="' . $pct($settings['no_mandate_deduction'], 1) . '">', 'Se usa cuando el contrato no tiene mandato con comisión registrada.')
      . $field('Meses de ingreso neto a prestar', '<input type="number" name="income_months" min="1" max="24" value="' . (int) $settings['income_months'] . '">', 'Tope = ingreso neto × estos meses.')
      . $field('Meses mínimos de contrato restantes', '<input type="number" name="min_remaining_months" min="0" max="60" value="' . (int) $settings['min_remaining_months'] . '">', 'Por debajo se alerta "contrato próximo a vencer".')
      . $field('Plazo sugerido de la solicitud (meses)', '<input type="number" name="default_term" min="1" max="60" value="' . (int) $settings['default_term'] . '">')
      . '</div></section><section class="scm-maint-quote-section"><h4>Avisos al propietario al decidir</h4><div class="scm-maint-quote-grid">'
      . $field('Correo con condiciones y tabla', '<select name="notify_owner_email"><option value="1"' . ($settings['notify_owner_email'] ? ' selected' : '') . '>Sí</option><option value="0"' . ($settings['notify_owner_email'] ? '' : ' selected') . '>No</option></select>')
      . $field('WhatsApp', '<select name="notify_owner_whatsapp"><option value="1"' . ($settings['notify_owner_whatsapp'] ? ' selected' : '') . '>Sí</option><option value="0"' . ($settings['notify_owner_whatsapp'] ? '' : ' selected') . '>No</option></select>', 'Actívalo cuando Meta apruebe la plantilla scm_credito_propietario_decision_v1.')
      . '</div></section><section class="scm-maint-quote-section"><h4>Avisos a funcionarios</h4><div class="scm-maint-quote-grid">'
      . $field('WhatsApp al solicitar y al decidir', '<select name="notify_staff_whatsapp"><option value="1"' . ($settings['notify_staff_whatsapp'] ? ' selected' : '') . '>Sí</option><option value="0"' . ($settings['notify_staff_whatsapp'] ? '' : ' selected') . '>No</option></select>', 'Actívalo cuando Meta apruebe la plantilla scm_credito_propietario_funcionario_v2.')
      . '</div><p class="scm-owner-credit-note">El correo interno siempre se envía. Los funcionarios que reciben correo y WhatsApp se eligen en Notificaciones internas › Créditos a propietarios.</p></section>'
      . '<div class="scm-cia-modal-status" data-owner-credit-status role="status" aria-live="polite"></div>'
      . '<div class="scm-public-services-review-actions"><button type="submit" class="scm-btn-primary">Guardar configuración</button></div></form>';
  }

  public function newForm(): string
  {
    $options = '';
    foreach (OwnerCreditSettings::PURPOSES as $purpose) $options .= '<option>' . self::e($purpose) . '</option>';
    // Como en "Crear caso": primero se busca y elige el contrato, luego se completan los datos de la solicitud.
    return '<form class="scm-owner-credit-form" data-owner-credit-new-form autocomplete="off"><input type="hidden" name="contract_id" value="">'
      . '<section class="scm-maint-quote-section"><h4>1. Busca el contrato</h4><div class="scm-owner-credit-search">'
      . '<label class="scm-cotizacion-dialog-field"><span>Buscar por</span><select data-owner-credit-search-by><option value="contrato">Contrato</option><option value="inmueble">Inmueble SIMI</option><option value="propietario">Propietario</option><option value="arrendatario">Arrendatario</option></select></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Dato a buscar</span><input data-owner-credit-search-query placeholder="Ej. 817, 10420 o nombre"></label>'
      . '<button type="button" class="scm-btn-secondary" data-owner-credit-search>Buscar</button></div>'
      . '<p class="scm-owner-credit-note" data-owner-credit-search-status></p><div class="scm-owner-credit-results" data-owner-credit-search-results></div>'
      . '<div class="scm-owner-credit-picked" data-owner-credit-picked hidden></div></section>'
      . '<section class="scm-maint-quote-section" data-owner-credit-request hidden><h4>2. Datos de la solicitud</h4><div class="scm-maint-quote-grid">'
      . '<label class="scm-cotizacion-dialog-field"><span>Valor solicitado <em>*</em></span><input name="amount" inputmode="numeric" data-owner-credit-money required></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Fin del contrato</span><input type="date" name="contract_end"></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Plazo solicitado (meses) <em>*</em></span><input name="term" type="number" min="1" max="60" required></label>'
      . '<label class="scm-cotizacion-dialog-field"><span>Destinación</span><select name="purpose">' . $options . '</select></label>'
      . '</div><p class="scm-owner-credit-note">El plazo no puede superar los meses que faltan para el fin del contrato. Si la fecha fin está desactualizada, corrígela aquí: se guardará en el contrato.</p><p class="scm-owner-credit-note" data-owner-credit-term-hint></p></section>'
      . '<div class="scm-cia-modal-status" data-owner-credit-status role="status" aria-live="polite"></div>'
      . '<div class="scm-public-services-review-actions"><button type="submit" class="scm-btn-primary">Crear y abrir liquidador</button></div></form>';
  }

  public static function status(string $status): string
  {
    return '<span class="scm-owner-credit-pill is-' . self::e($status) . '">' . self::e(OwnerCreditService::STATUSES[$status] ?? $status) . '</span>';
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
