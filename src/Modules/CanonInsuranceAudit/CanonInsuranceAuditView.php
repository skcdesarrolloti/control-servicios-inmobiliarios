<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

final class CanonInsuranceAuditView
{
  public function renderPanel(): string
  {
    $month = date('m');
    $year = date('Y');
    ob_start();
?>
    <section class="scm-cia" data-canon-insurance-audit>
      <header class="scm-cia-hero">
        <div>
          <span class="scm-eyebrow">Conciliaci&oacute;n mensual</span>
          <h2>Auditor&iacute;a de canon y aseguradoras</h2>
          <p>Compara canon, administraci&oacute;n e IVA entre SIMI, las aseguradoras y los valores vigentes de la plataforma.</p>
        </div>
        <span class="scm-cia-trace">Cada carga y observaci&oacute;n queda registrada</span>
      </header>

      <aside class="scm-cia-guide" aria-labelledby="scm-cia-guide-title">
        <div><strong id="scm-cia-guide-title">Gu&iacute;a para nombrar los archivos</strong><span>Usa mes y a&ntilde;o con n&uacute;meros; deben coincidir con el periodo seleccionado.</span></div>
        <ul><li><code data-cia-filename="simi" data-extension="xls">simi_<?php echo esc_html($month . '_' . $year); ?>.xls</code></li><li><code data-cia-filename="libertador" data-extension="xlsx">libertador_<?php echo esc_html($month . '_' . $year); ?>.xlsx</code></li><li><code data-cia-filename="fianza_bogota" data-extension="xlsx">fianza_bogota_<?php echo esc_html($month . '_' . $year); ?>.xlsx</code></li><li><code data-cia-filename="unifianza" data-extension="xls">unifianza_<?php echo esc_html($month . '_' . $year); ?>.xls</code></li></ul>
      </aside>

      <form class="scm-cia-upload" data-cia-upload-form enctype="multipart/form-data" autocomplete="off">
        <div class="scm-field"><label for="scm_cia_period">Periodo auditado</label><input id="scm_cia_period" name="period" type="month" value="<?php echo esc_attr(date('Y-m')); ?>" required></div>
        <div class="scm-field scm-cia-file-field"><label for="scm_cia_file">SIMI y extractos de aseguradoras</label><input id="scm_cia_file" name="files" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required><small>Puedes seleccionar los cuatro archivos al mismo tiempo. Cada uno se valida por nombre y estructura.</small></div>
        <button type="submit" class="scm-btn-primary btn btn-primary" data-cia-upload-button>Auditar archivos</button>
      </form>
      <div class="scm-cia-legend" aria-label="Significado del semaforo"><span class="is-green">Verde: correcto</span><span class="is-red">Rojo: valor equivocado</span><span class="is-yellow">Amarillo: anomal&iacute;a o dato faltante</span></div>
      <div class="scm-cia-status" data-cia-status aria-live="polite">Carga los archivos o consulta una auditor&iacute;a registrada.</div>
      <div class="scm-cia-content" data-cia-content><div class="scm-cia-loading" role="status">Cargando auditor&iacute;as registradas&hellip;</div></div>
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
    $reports = (array) ($payload['reports'] ?? []);
    $filters = (array) ($payload['filters'] ?? []);
    ob_start();
    if ($selected === null) {
      echo '<div class="scm-cia-empty"><strong>A&uacute;n no hay auditor&iacute;as mensuales.</strong><span>Selecciona el periodo y carga SIMI o uno de los extractos.</span></div>';
      echo $this->renderIncrementHistory($changes);
      return (string) ob_get_clean();
    }
?>
    <div class="scm-cia-latest">
      <div><span>Auditor&iacute;a seleccionada</span><strong><?php echo esc_html($this->periodLabel((string) ($selected['period'] ?? ''))); ?></strong><small><?php echo esc_html((string) ($selected['source_filename'] ?? '')); ?> &middot; <?php echo esc_html((string) ($selected['insurer'] ?? '')); ?></small></div>
      <div><span>Cargado por</span><strong><?php echo esc_html((string) ($selected['uploaded_by_name'] ?? '')); ?></strong><small><?php echo esc_html($this->dateTime((string) ($selected['uploaded_at'] ?? ''))); ?></small></div>
    </div>

    <div class="scm-cia-kpis" aria-label="Resumen de auditoria">
      <?php echo $this->kpi('Filas', (int) ($selected['total_rows'] ?? 0), 'neutral'); ?>
      <?php echo $this->kpi('Verde · correctos', (int) ($selected['compliant_rows'] ?? 0), 'success'); ?>
      <?php echo $this->kpi('Rojo · equivocados', (int) ($selected['difference_rows'] ?? 0), 'danger'); ?>
      <?php echo $this->kpi('Amarillo · anomalías', (int) ($selected['incomplete_rows'] ?? 0), 'warning'); ?>
    </div>

    <form class="scm-cia-filters" data-cia-filter-form autocomplete="off">
      <div class="scm-field"><label for="scm_cia_audit_id">Auditor&iacute;a registrada</label><select id="scm_cia_audit_id" name="audit_id"><?php foreach ($audits as $audit): ?><option value="<?php echo esc_attr((string) ($audit['id'] ?? '')); ?>" <?php echo (int) ($filters['audit_id'] ?? 0) === (int) ($audit['id'] ?? 0) ? 'selected' : ''; ?>><?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? '')) . ' — ' . (string) ($audit['insurer'] ?? '') . ' — ' . (string) ($audit['source_filename'] ?? '')); ?></option><?php endforeach; ?></select></div>
      <div class="scm-field"><label for="scm_cia_status_filter">Sem&aacute;foro</label><select id="scm_cia_status_filter" name="status"><?php foreach ($this->statusOptions() as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php echo (string) ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
      <div class="scm-field scm-cia-search-field"><label for="scm_cia_search">Solicitud, contrato o persona</label><input id="scm_cia_search" name="search" type="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" placeholder="Ej. 11827961 o arrendatario"></div>
      <button type="submit" class="scm-btn-secondary btn btn-outline">Aplicar filtros</button>
    </form>

    <?php echo $this->renderReportPanel($selected, $reports); ?>
    <?php echo $this->renderItemsTable($items, (string) ($selected['format_key'] ?? '')); ?>
    <?php echo $this->renderAuditHistory($audits); ?>
    <?php echo $this->renderIncrementHistory($changes); ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $audit @param array<int,array<string,mixed>> $reports */
  private function renderReportPanel(array $audit, array $reports): string
  {
    $pending = (int) ($audit['difference_rows'] ?? 0) + (int) ($audit['incomplete_rows'] ?? 0);
    ob_start();
?>
    <section class="scm-cia-report">
      <div><strong>Informe para Coordinaci&oacute;n Contractual</strong><span>Incluye &uacute;nicamente filas rojas y amarillas, junto con las observaciones guardadas.</span><?php if ($reports !== []): $last = $reports[0]; ?><small>&Uacute;ltimo env&iacute;o: <?php echo esc_html($this->dateTime((string) ($last['sent_at'] ?? ''))); ?> por <?php echo esc_html((string) ($last['sent_by_name'] ?? '')); ?>.</small><?php endif; ?></div>
      <form data-cia-report-form><input type="hidden" name="audit_id" value="<?php echo esc_attr((string) ($audit['id'] ?? '')); ?>"><button type="submit" class="scm-btn-primary btn btn-primary" <?php echo $pending === 0 ? 'disabled' : ''; ?>>Enviar informe (<?php echo esc_html((string) $pending); ?>)</button></form>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $items */
  private function renderItemsTable(array $items, string $sourceKey): string
  {
    if ($items === []) return '<div class="scm-cia-empty"><strong>No hay filas con estos filtros.</strong><span>Cambia el sem&aacute;foro o la b&uacute;squeda.</span></div>';
    ob_start();
?>
    <section class="scm-cia-section">
      <div class="scm-cia-section-head"><div><h3>Resultado de la comparaci&oacute;n</h3><p>Excel frente a los valores vigentes de contrato y mandato.</p><?php if ($sourceKey === 'libertador'): ?><p class="scm-cia-libertador-note">El Libertador entrega canon con IVA incluido; se separa usando total &divide; 1,19 cuando el mandato indica IVA.</p><?php endif; ?></div><span><?php echo esc_html((string) count($items)); ?> filas visibles</span></div>
      <div class="scm-cia-table-wrap"><table class="scm-cia-table scm-cia-results-table"><thead><tr><th>Sem&aacute;foro</th><th>Solicitud / contrato</th><th>Arrendatario e inmueble</th><th>Canon</th><th>Administraci&oacute;n</th><th>IVA</th><th>Hallazgo y observaci&oacute;n</th></tr></thead><tbody>
      <?php foreach ($items as $item): $status = (string) ($item['status'] ?? 'anomalia'); ?><tr class="scm-cia-row--<?php echo esc_attr($status); ?>">
        <td><span class="scm-cia-badge scm-cia-badge--<?php echo esc_attr($status); ?>"><?php echo esc_html($this->statusLabel($status)); ?></span><small>Fila <?php echo esc_html((string) ($item['source_row'] ?? '')); ?></small></td>
        <td><strong><?php echo esc_html((string) ($item['request_number'] ?? '')); ?></strong><span>Contrato <?php echo esc_html((string) (($item['contract_number'] ?? '') ?: '—')); ?></span><small>Mandato <?php echo esc_html((string) (($item['mandate_id'] ?? '') ?: '—')); ?></small></td>
        <td><strong><?php echo esc_html((string) (($item['tenant'] ?? '') ?: '—')); ?></strong><span><?php echo esc_html((string) (($item['property_address'] ?? '') ?: '—')); ?></span></td>
        <?php echo $this->comparisonCell($item, 'excel_canon', 'system_canon', 'difference_canon'); ?><?php echo $this->comparisonCell($item, 'excel_administration', 'system_administration', 'difference_administration'); ?><?php echo $this->comparisonCell($item, 'excel_iva', 'system_iva', 'difference_iva'); ?>
        <td><?php echo $this->differenceList((string) ($item['differences_json'] ?? '[]')); ?><?php if ($status !== 'correcto'): ?><form class="scm-cia-observation" data-cia-observation-form><input type="hidden" name="item_id" value="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>"><label>Observaci&oacute;n del funcionario<textarea name="observation" maxlength="1000" rows="2" placeholder="Explica la anomal&iacute;a o la gesti&oacute;n requerida"><?php echo esc_textarea((string) ($item['observation'] ?? '')); ?></textarea></label><button type="submit" class="scm-btn-secondary btn btn-outline">Guardar</button><?php if (($item['observation_at'] ?? '') !== ''): ?><small>Guardada por <?php echo esc_html((string) ($item['observation_by_name'] ?? '')); ?> · <?php echo esc_html($this->dateTime((string) $item['observation_at'])); ?></small><?php endif; ?></form><?php endif; ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $audits */
  private function renderAuditHistory(array $audits): string
  {
    if ($audits === []) return '';
    ob_start();
?>
    <section class="scm-cia-section"><div class="scm-cia-section-head"><div><h3>Registro de auditor&iacute;as</h3><p>Archivo, responsable, fecha y resultado de cada carga.</p></div></div><div class="scm-cia-history-grid"><?php foreach ($audits as $audit): ?><article class="scm-cia-history-card"><div><strong><?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? ''))); ?></strong><span><?php echo esc_html((string) ($audit['insurer'] ?? '')); ?></span></div><p title="<?php echo esc_attr((string) ($audit['source_filename'] ?? '')); ?>"><?php echo esc_html((string) ($audit['source_filename'] ?? '')); ?></p><dl><div><dt>Verdes</dt><dd><?php echo esc_html((string) ($audit['compliant_rows'] ?? 0)); ?></dd></div><div><dt>Rojos</dt><dd><?php echo esc_html((string) ($audit['difference_rows'] ?? 0)); ?></dd></div><div><dt>Amarillos</dt><dd><?php echo esc_html((string) ($audit['incomplete_rows'] ?? 0)); ?></dd></div></dl><small><?php echo esc_html((string) ($audit['uploaded_by_name'] ?? '')); ?> &middot; <?php echo esc_html($this->dateTime((string) ($audit['uploaded_at'] ?? ''))); ?></small></article><?php endforeach; ?></div></section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $changes */
  private function renderIncrementHistory(array $changes): string
  {
    ob_start();
?>
    <section class="scm-cia-section scm-cia-increments"><?php $count = count($changes); ?><div class="scm-cia-section-head"><div><h3>Historial de cambios de incrementos</h3><p>Registra qui&eacute;n modific&oacute; fechas, porcentajes o documentos de incremento, cu&aacute;ndo lo hizo y en qu&eacute; contrato.</p></div><span><?php echo esc_html((string) $count); ?> cambios recientes</span></div><?php if ($changes === []): ?><div class="scm-cia-empty"><strong>No hay modificaciones de incrementos registradas.</strong><span>Los cambios aparecer&aacute;n aqu&iacute; al sincronizar esta pesta&ntilde;a.</span></div><?php else: ?><div class="scm-cia-table-wrap"><table class="scm-cia-table scm-cia-change-table"><thead><tr><th>Cu&aacute;ndo</th><th>Qui&eacute;n</th><th>Contrato</th><th>Campo modificado</th><th>Valor anterior</th><th>Valor nuevo</th></tr></thead><tbody><?php foreach ($changes as $change): ?><tr><td><?php echo esc_html($this->dateTime((string) ($change['changed_at'] ?? ''))); ?></td><td><strong><?php echo esc_html((string) ($change['changed_by_name'] ?? 'No identificado')); ?></strong><small><?php $employeeId = trim((string) ($change['changed_by_id'] ?? '')); echo $employeeId !== '' ? 'ID ' . esc_html($employeeId) : 'ID de empleado no disponible'; ?></small></td><td><?php echo esc_html((string) (($change['contract_number'] ?? '') ?: '—')); ?><small>ID <?php echo esc_html((string) ($change['contract_id'] ?? '')); ?></small></td><td><strong><?php echo esc_html((string) ($change['field_label'] ?? '')); ?></strong><small><?php echo esc_html((string) ($change['source_location'] ?? '')); ?></small></td><td><?php echo esc_html((string) (($change['old_value'] ?? '') !== '' ? $change['old_value'] : 'Sin valor')); ?></td><td><?php echo esc_html((string) (($change['new_value'] ?? '') !== '' ? $change['new_value'] : 'Sin valor')); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
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
    return $messages === [] ? '<span>Sin hallazgos.</span>' : '<ul class="scm-cia-findings"><li>' . implode('</li><li>', array_map('esc_html', $messages)) . '</li></ul>';
  }

  private function kpi(string $label, int $value, string $tone): string { return '<div class="scm-cia-kpi scm-cia-kpi--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>'; }
  /** @return array<string,string> */
  private function statusOptions(): array { return ['' => 'Todos', 'incorrecto' => 'Rojo · Incorrecto', 'anomalia' => 'Amarillo · Anomalía', 'correcto' => 'Verde · Correcto']; }
  private function statusLabel(string $status): string { return $this->statusOptions()[$status] ?? ['diferencia' => 'Rojo · Incorrecto', 'coincide' => 'Verde · Correcto'][$status] ?? 'Amarillo · Anomalía'; }
  private function money(mixed $value, bool $signed = false): string { if ($value === null || $value === '') return '—'; $number = (float) $value; return ($signed && $number > 0 ? '+$' : '$') . number_format($number, 2, ',', '.'); }
  private function periodLabel(string $period): string { if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1) return $period; $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']; return ($months[(int) $match[2]] ?? $match[2]) . ' ' . $match[1]; }
  private function dateTime(string $value): string { $timestamp = strtotime($value); return $timestamp === false ? $value : date('d/m/Y g:i a', $timestamp); }
}
