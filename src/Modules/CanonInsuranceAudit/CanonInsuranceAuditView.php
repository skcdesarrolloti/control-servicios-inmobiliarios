<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

final class CanonInsuranceAuditView
{
  public function renderPanel(): string
  {
    ob_start();
?>
    <section class="scm-cia" data-canon-insurance-audit>
      <header class="scm-cia-hero">
        <div>
          <span class="scm-eyebrow">Conciliaci&oacute;n mensual</span>
          <h2>Auditor&iacute;a de canon y aseguradoras</h2>
          <p>Compara el extracto de la aseguradora con canon y administraci&oacute;n del contrato, y con primas y servicios p&uacute;blicos del mandato asociado.</p>
        </div>
        <span class="scm-cia-trace">Cada carga queda registrada</span>
      </header>

      <form class="scm-cia-upload" data-cia-upload-form enctype="multipart/form-data" autocomplete="off">
        <div class="scm-field">
          <label for="scm_cia_period">Periodo auditado</label>
          <input id="scm_cia_period" name="period" type="month" value="<?php echo esc_attr(date('Y-m')); ?>" required>
        </div>
        <div class="scm-field scm-cia-file-field">
          <label for="scm_cia_file">Extracto de aseguradora</label>
          <input id="scm_cia_file" name="file" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          <small>Formatos admitidos: XLS y XLSX. El formato se detecta autom&aacute;ticamente.</small>
        </div>
        <button type="submit" class="scm-btn-primary btn btn-primary" data-cia-upload-button>Auditar archivo</button>
      </form>
      <div class="scm-cia-status" data-cia-status aria-live="polite">Carga un extracto o consulta el &uacute;ltimo registro.</div>
      <div class="scm-cia-content" data-cia-content>
        <div class="scm-cia-loading" role="status">Cargando auditor&iacute;as registradas&hellip;</div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $payload */
  public function renderContent(array $payload): string
  {
    $audits = (array) ($payload['audits'] ?? []);
    $selected = is_array($payload['selected_audit'] ?? null) ? $payload['selected_audit'] : null;
    $items = (array) ($payload['items'] ?? []);
    $changes = (array) ($payload['increment_changes'] ?? []);
    $filters = (array) ($payload['filters'] ?? []);

    ob_start();
    if ($selected === null) {
?>
      <div class="scm-cia-empty">
        <strong>A&uacute;n no hay auditor&iacute;as mensuales.</strong>
        <span>Selecciona el periodo, carga uno de los extractos y la comparaci&oacute;n quedar&aacute; disponible aqu&iacute;.</span>
      </div>
      <?php echo $this->renderIncrementHistory($changes); ?>
<?php
      return (string) ob_get_clean();
    }
?>
    <div class="scm-cia-latest">
      <div>
        <span>&Uacute;ltima selecci&oacute;n</span>
        <strong><?php echo esc_html($this->periodLabel((string) ($selected['period'] ?? ''))); ?></strong>
        <small><?php echo esc_html((string) ($selected['source_filename'] ?? '')); ?> &middot; <?php echo esc_html((string) ($selected['insurer'] ?? 'Aseguradora no identificada')); ?></small>
      </div>
      <div>
        <span>Cargado por</span>
        <strong><?php echo esc_html((string) ($selected['uploaded_by_name'] ?? '')); ?></strong>
        <small><?php echo esc_html($this->dateTime((string) ($selected['uploaded_at'] ?? ''))); ?></small>
      </div>
    </div>

    <div class="scm-cia-kpis" aria-label="Resumen de auditoria">
      <?php echo $this->kpi('Filas', (int) ($selected['total_rows'] ?? 0), 'neutral'); ?>
      <?php echo $this->kpi('Coinciden', (int) ($selected['compliant_rows'] ?? 0), 'success'); ?>
      <?php echo $this->kpi('Con diferencias', (int) ($selected['difference_rows'] ?? 0), 'danger'); ?>
      <?php echo $this->kpi('Sin contrato', (int) ($selected['unmatched_rows'] ?? 0), 'warning'); ?>
      <?php echo $this->kpi('Incompletos', (int) ($selected['incomplete_rows'] ?? 0), 'muted'); ?>
    </div>

    <form class="scm-cia-filters" data-cia-filter-form autocomplete="off">
      <div class="scm-field">
        <label for="scm_cia_audit_id">Auditor&iacute;a registrada</label>
        <select id="scm_cia_audit_id" name="audit_id">
          <?php foreach ($audits as $audit): ?>
            <option value="<?php echo esc_attr((string) ($audit['id'] ?? '')); ?>" <?php echo (int) ($filters['audit_id'] ?? 0) === (int) ($audit['id'] ?? 0) ? 'selected' : ''; ?>>
              <?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? '')) . ' — ' . (string) ($audit['insurer'] ?? '') . ' — ' . (string) ($audit['source_filename'] ?? '')); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="scm-field">
        <label for="scm_cia_status_filter">Estado</label>
        <select id="scm_cia_status_filter" name="status">
          <?php foreach ($this->statusOptions() as $value => $label): ?>
            <option value="<?php echo esc_attr($value); ?>" <?php echo (string) ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="scm-field scm-cia-search-field">
        <label for="scm_cia_search">Solicitud, contrato o persona</label>
        <input id="scm_cia_search" name="search" type="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" placeholder="Ej. 11827961 o arrendatario">
      </div>
      <button type="submit" class="scm-btn-secondary btn btn-outline">Aplicar filtros</button>
    </form>

    <?php echo $this->renderItemsTable($items); ?>
    <?php echo $this->renderAuditHistory($audits); ?>
    <?php echo $this->renderIncrementHistory($changes); ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $items */
  private function renderItemsTable(array $items): string
  {
    if ($items === []) {
      return '<div class="scm-cia-empty"><strong>No hay filas con estos filtros.</strong><span>Cambia el estado o la busqueda para ver otros resultados.</span></div>';
    }
    ob_start();
?>
    <section class="scm-cia-section">
      <div class="scm-cia-section-head"><div><h3>Resultado de la comparaci&oacute;n</h3><p>Excel frente a los valores vigentes de contrato y mandato.</p></div><span><?php echo esc_html((string) count($items)); ?> filas visibles</span></div>
      <div class="scm-cia-table-wrap">
        <table class="scm-cia-table">
          <thead><tr><th>Estado</th><th>Solicitud / contrato</th><th>Arrendatario e inmueble</th><th>Canon</th><th>Administraci&oacute;n</th><th>Prima seguro</th><th>Servicios p&uacute;blicos</th><th>Hallazgos</th></tr></thead>
          <tbody>
          <?php foreach ($items as $item): $status = (string) ($item['status'] ?? 'datos_incompletos'); ?>
            <tr>
              <td><span class="scm-cia-badge scm-cia-badge--<?php echo esc_attr($status); ?>"><?php echo esc_html($this->statusLabel($status)); ?></span><small>Fila <?php echo esc_html((string) ($item['source_row'] ?? '')); ?></small></td>
              <td><strong><?php echo esc_html((string) ($item['request_number'] ?? '')); ?></strong><span>Contrato <?php echo esc_html((string) ($item['contract_number'] ?? '—')); ?></span><small>Mandato <?php echo esc_html((string) ($item['mandate_id'] ?? '—')); ?></small></td>
              <td><strong><?php echo esc_html((string) ($item['tenant'] ?? '—')); ?></strong><span><?php echo esc_html((string) ($item['property_address'] ?? '—')); ?></span></td>
              <?php echo $this->comparisonCell($item, 'excel_canon', 'system_canon', 'difference_canon'); ?>
              <?php echo $this->comparisonCell($item, 'excel_administration', 'system_administration', 'difference_administration'); ?>
              <?php echo $this->comparisonCell($item, 'excel_premium', 'system_premium', 'difference_premium'); ?>
              <td class="scm-cia-value-cell">
                <span><b>Prima E:</b> <?php echo esc_html($this->money($item['excel_service_premium'] ?? null)); ?></span>
                <span><b>Prima S:</b> <?php echo esc_html($this->money($item['system_service_premium'] ?? null)); ?></span>
                <span><b>Cobertura E:</b> <?php echo esc_html($this->money($item['excel_service_coverage'] ?? null)); ?></span>
                <span><b>Cobertura S:</b> <?php echo esc_html($this->money($item['system_service_coverage'] ?? null)); ?></span>
              </td>
              <td><?php echo $this->differenceList((string) ($item['differences_json'] ?? '[]')); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $audits */
  private function renderAuditHistory(array $audits): string
  {
    if ($audits === []) {
      return '';
    }
    ob_start();
?>
    <section class="scm-cia-section">
      <div class="scm-cia-section-head"><div><h3>Registro de auditor&iacute;as</h3><p>Archivo, responsable, fecha y resultado de cada conciliaci&oacute;n.</p></div></div>
      <div class="scm-cia-history-grid">
        <?php foreach ($audits as $audit): ?>
          <article class="scm-cia-history-card">
            <div><strong><?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? ''))); ?></strong><span><?php echo esc_html((string) ($audit['insurer'] ?? '')); ?></span></div>
            <p title="<?php echo esc_attr((string) ($audit['source_filename'] ?? '')); ?>"><?php echo esc_html((string) ($audit['source_filename'] ?? '')); ?></p>
            <dl><div><dt>Coinciden</dt><dd><?php echo esc_html((string) ($audit['compliant_rows'] ?? 0)); ?></dd></div><div><dt>Diferencias</dt><dd><?php echo esc_html((string) ($audit['difference_rows'] ?? 0)); ?></dd></div><div><dt>Sin asociar</dt><dd><?php echo esc_html((string) ($audit['unmatched_rows'] ?? 0)); ?></dd></div></dl>
            <small><?php echo esc_html((string) ($audit['uploaded_by_name'] ?? '')); ?> &middot; <?php echo esc_html($this->dateTime((string) ($audit['uploaded_at'] ?? ''))); ?></small>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $changes */
  private function renderIncrementHistory(array $changes): string
  {
    ob_start();
?>
    <section class="scm-cia-section scm-cia-increments">
      <div class="scm-cia-section-head"><div><h3>Trazabilidad de actas de incremento</h3><p>Registra qui&eacute;n cambi&oacute; el dato, cu&aacute;ndo ocurri&oacute; y en qu&eacute; campo del contrato.</p></div><span><?php echo esc_html((string) count($changes)); ?> cambios recientes</span></div>
      <?php if ($changes === []): ?>
        <div class="scm-cia-empty"><strong>No hay cambios de incremento registrados.</strong><span>Los nuevos cambios de canon o administraci&oacute;n aparecer&aacute;n aqu&iacute; al sincronizar esta pesta&ntilde;a.</span></div>
      <?php else: ?>
        <div class="scm-cia-table-wrap"><table class="scm-cia-table scm-cia-change-table"><thead><tr><th>Cu&aacute;ndo</th><th>Qui&eacute;n</th><th>Contrato</th><th>D&oacute;nde</th><th>Valor anterior</th><th>Valor nuevo</th></tr></thead><tbody>
        <?php foreach ($changes as $change): ?>
          <tr><td><?php echo esc_html($this->dateTime((string) ($change['changed_at'] ?? ''))); ?></td><td><strong><?php echo esc_html((string) ($change['changed_by_name'] ?? 'No identificado')); ?></strong><small><?php $employeeId = trim((string) ($change['changed_by_id'] ?? '')); echo $employeeId !== '' ? 'ID ' . esc_html($employeeId) : 'ID de empleado no disponible'; ?></small></td><td><?php echo esc_html((string) ($change['contract_number'] ?? '—')); ?><small>ID <?php echo esc_html((string) ($change['contract_id'] ?? '')); ?></small></td><td><strong><?php echo esc_html((string) ($change['field_label'] ?? '')); ?></strong><small><?php echo esc_html((string) ($change['source_location'] ?? '')); ?></small></td><td><?php echo esc_html((string) (($change['old_value'] ?? '') !== '' ? $change['old_value'] : 'Sin valor')); ?></td><td><?php echo esc_html((string) (($change['new_value'] ?? '') !== '' ? $change['new_value'] : 'Sin valor')); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $item */
  private function comparisonCell(array $item, string $excelKey, string $systemKey, string $differenceKey): string
  {
    $difference = $item[$differenceKey] ?? null;
    $class = $difference !== null && abs((float) $difference) > 1 ? ' is-different' : '';
    return '<td class="scm-cia-value-cell' . $class . '"><span><b>Excel:</b> ' . esc_html($this->money($item[$excelKey] ?? null)) . '</span><span><b>Sistema:</b> ' . esc_html($this->money($item[$systemKey] ?? null)) . '</span><small>Diferencia: ' . esc_html($this->money($difference, true)) . '</small></td>';
  }

  private function differenceList(string $json): string
  {
    $decoded = json_decode($json, true);
    $messages = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    if ($messages === []) {
      return '<span>Sin observaciones.</span>';
    }
    return '<ul class="scm-cia-findings"><li>' . implode('</li><li>', array_map('esc_html', $messages)) . '</li></ul>';
  }

  private function kpi(string $label, int $value, string $tone): string
  {
    return '<div class="scm-cia-kpi scm-cia-kpi--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
  }

  /** @return array<string,string> */
  private function statusOptions(): array
  {
    return ['' => 'Todos', 'diferencia' => 'Con diferencias', 'coincide' => 'Coinciden', 'datos_incompletos' => 'Datos incompletos', 'sin_mandato' => 'Sin mandato', 'sin_contrato' => 'Sin contrato'];
  }

  private function statusLabel(string $status): string
  {
    return $this->statusOptions()[$status] ?? 'Datos incompletos';
  }

  private function money(mixed $value, bool $signed = false): string
  {
    if ($value === null || $value === '') {
      return '—';
    }
    $number = (float) $value;
    $prefix = $signed && $number > 0 ? '+$' : '$';
    return $prefix . number_format($number, 2, ',', '.');
  }

  private function periodLabel(string $period): string
  {
    if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1) {
      return $period;
    }
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return ($months[(int) $match[2]] ?? $match[2]) . ' ' . $match[1];
  }

  private function dateTime(string $value): string
  {
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('d/m/Y g:i a', $timestamp);
  }
}
