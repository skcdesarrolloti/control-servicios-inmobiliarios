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
      <header class="scm-cia-hero"><div><span class="scm-eyebrow">Conciliaci&oacute;n mensual</span><h2>Auditor&iacute;a de canon y aseguradoras</h2><p>Compara canon, administraci&oacute;n e IVA entre Plataforma, SIMI, El Libertador, Fianza Bogot&aacute; y Unifianza.</p></div><span class="scm-cia-trace">Cada carga y observaci&oacute;n queda registrada</span></header>
      <aside class="scm-cia-guide" aria-labelledby="scm-cia-guide-title"><div><strong id="scm-cia-guide-title">Gu&iacute;a para nombrar los archivos</strong><span>El mes y el a&ntilde;o deben coincidir con el periodo seleccionado.</span></div><ul><li><code data-cia-filename="simi" data-extension="xls">simi_<?php echo esc_html($month . '_' . $year); ?>.xls</code></li><li><code data-cia-filename="libertador" data-extension="xlsx">libertador_<?php echo esc_html($month . '_' . $year); ?>.xlsx</code></li><li><code data-cia-filename="fianza_bogota" data-extension="xlsx">fianza_bogota_<?php echo esc_html($month . '_' . $year); ?>.xlsx</code></li><li><code data-cia-filename="unifianza" data-extension="xls">unifianza_<?php echo esc_html($month . '_' . $year); ?>.xls</code></li></ul></aside>
      <form class="scm-cia-upload" data-cia-upload-form enctype="multipart/form-data" autocomplete="off"><div class="scm-field"><label for="scm_cia_period">Periodo auditado</label><input id="scm_cia_period" name="period" type="month" value="<?php echo esc_attr(date('Y-m')); ?>" required></div><div class="scm-field scm-cia-file-field"><label for="scm_cia_file">SIMI y extractos de aseguradoras</label><input id="scm_cia_file" name="files" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required><small>Selecciona los cuatro archivos. La conciliaci&oacute;n del mes se actualiza despu&eacute;s de cada carga.</small></div><button type="submit" class="scm-btn-primary btn btn-primary" data-cia-upload-button>Auditar archivos</button></form>
      <div class="scm-cia-status" data-cia-status aria-live="polite">Carga los archivos o consulta una conciliaci&oacute;n registrada.</div>
      <div class="scm-cia-content" data-cia-content><div class="scm-cia-loading" role="status">Cargando conciliaci&oacute;n&hellip;</div></div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $payload */
  public function renderContent(array $payload): string
  {
    $audits = (array) ($payload['audits'] ?? []);
    $periods = (array) ($payload['periods'] ?? []);
    $period = (string) ($payload['selected_period'] ?? date('Y-m'));
    $sourceAudits = (array) ($payload['source_audits'] ?? []);
    $summary = (array) ($payload['summary'] ?? []);
    $items = (array) ($payload['items'] ?? []);
    $reports = (array) ($payload['reports'] ?? []);
    $filters = (array) ($payload['filters'] ?? []);
    ob_start();
    if ($sourceAudits === []) {
      echo '<div class="scm-cia-empty"><strong>A&uacute;n no hay archivos procesados para ' . esc_html($this->periodLabel($period)) . '.</strong><span>Selecciona el periodo y carga SIMI junto con los extractos de las tres aseguradoras.</span></div>';
      return (string) ob_get_clean();
    }
?>
    <section class="scm-cia-period-head"><div><span>Conciliaci&oacute;n mensual</span><strong><?php echo esc_html($this->periodLabel($period)); ?></strong><small><?php echo esc_html((string) count($sourceAudits)); ?> de 4 fuentes cargadas</small></div><div class="scm-cia-source-state"><?php foreach (AuditSourceCatalog::labels() as $key => $label): $audit = $sourceAudits[$key] ?? null; ?><span class="<?php echo is_array($audit) ? 'is-ready' : 'is-missing'; ?>"><b><?php echo esc_html($label); ?></b><?php echo is_array($audit) ? esc_html((string) ($audit['source_filename'] ?? '')) : 'Pendiente'; ?></span><?php endforeach; ?></div></section>

    <div class="scm-cia-kpis" aria-label="Resumen de conciliacion"><?php echo $this->kpi('Contratos revisados', (int) ($summary['total'] ?? 0), 'neutral'); ?><?php echo $this->kpi('Verde · correctos', (int) ($summary['compliant'] ?? 0), 'success'); ?><?php echo $this->kpi('Rojo · equivocados', (int) ($summary['differences'] ?? 0), 'danger'); ?><?php echo $this->kpi('Amarillo · anomalías', (int) ($summary['incomplete'] ?? 0), 'warning'); ?></div>

    <form class="scm-cia-filters" data-cia-filter-form autocomplete="off"><div class="scm-field"><label for="scm_cia_period_filter">Periodo consolidado</label><select id="scm_cia_period_filter" name="period"><?php if (!in_array($period, $periods, true)): ?><option value="<?php echo esc_attr($period); ?>" selected><?php echo esc_html($this->periodLabel($period)); ?></option><?php endif; ?><?php foreach ($periods as $availablePeriod): ?><option value="<?php echo esc_attr((string) $availablePeriod); ?>" <?php echo $period === (string) $availablePeriod ? 'selected' : ''; ?>><?php echo esc_html($this->periodLabel((string) $availablePeriod)); ?></option><?php endforeach; ?></select></div><div class="scm-field"><label for="scm_cia_status_filter">Resultado</label><select id="scm_cia_status_filter" name="status"><?php foreach ($this->statusOptions() as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php echo (string) ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div><div class="scm-field scm-cia-search-field"><label for="scm_cia_search">Solicitud, contrato o persona</label><input id="scm_cia_search" name="search" type="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" placeholder="Ej. 11827961 o arrendatario"></div><button type="submit" class="scm-btn-secondary btn btn-outline">Aplicar filtros</button></form>

    <?php echo $this->renderReportPanel($period, $summary, $reports); ?>
    <?php echo $this->renderItemsTable($items, $sourceAudits); ?>
    <?php echo $this->renderAuditHistory($audits); ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $summary @param array<int,array<string,mixed>> $reports */
  private function renderReportPanel(string $period, array $summary, array $reports): string
  {
    $pending = (int) ($summary['differences'] ?? 0) + (int) ($summary['incomplete'] ?? 0);
    ob_start();
?>
    <section class="scm-cia-report"><div><strong>Informe consolidado para Coordinaci&oacute;n Contractual</strong><span>Incluye las filas rojas y amarillas con los valores de todas las fuentes y las observaciones guardadas.</span><?php if ($reports !== []): $last = $reports[0]; ?><small>&Uacute;ltimo env&iacute;o: <?php echo esc_html($this->dateTime((string) ($last['sent_at'] ?? ''))); ?> por <?php echo esc_html((string) ($last['sent_by_name'] ?? '')); ?>.</small><?php endif; ?></div><form data-cia-report-form><input type="hidden" name="period" value="<?php echo esc_attr($period); ?>"><button type="submit" class="scm-btn-primary btn btn-primary" <?php echo $pending === 0 ? 'disabled' : ''; ?>>Enviar informe (<?php echo esc_html((string) $pending); ?>)</button></form></section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,array<string,mixed>> $sourceAudits */
  private function renderItemsTable(array $items, array $sourceAudits): string
  {
    if ($items === []) return '<div class="scm-cia-empty"><strong>No hay contratos con estos filtros.</strong><span>Cambia el resultado o la b&uacute;squeda.</span></div>';
    $groups = $this->itemsByInsurer($items);
    ob_start();
?>
    <section class="scm-cia-section"><div class="scm-cia-section-head"><div><h3>Comparaci&oacute;n por aseguradora</h3><p>Cada contrato se muestra en la aseguradora asignada. El color de fondo pertenece al sem&aacute;foro de cada Canon, Administraci&oacute;n e IVA.</p></div><span><?php echo esc_html((string) count($items)); ?> filas visibles</span></div><div class="scm-cia-insurer-accordion">
      <?php foreach ($groups as $groupKey => $group): $groupItems = (array) ($group['items'] ?? []); $label = (string) ($group['label'] ?? 'Sin aseguradora'); $counts = $this->statusCounts($groupItems); $open = $counts['incorrecto'] > 0 || $counts['anomalia'] > 0; ?>
        <details class="scm-cia-insurer-group" <?php echo $open ? 'open' : ''; ?>><summary><span><?php echo esc_html($label); ?></span><small><?php echo esc_html((string) count($groupItems)); ?> contratos · <?php echo esc_html((string) $counts['incorrecto']); ?> rojos · <?php echo esc_html((string) $counts['anomalia']); ?> amarillos · <?php echo esc_html((string) $counts['correcto']); ?> verdes</small></summary><div class="scm-cia-table-wrap"><table class="scm-cia-table scm-cia-results-table"><thead><tr><th>Solicitud / contrato</th><th>Arrendatario e inmueble</th><th>Plataforma</th><th>SIMI</th><th><?php echo esc_html($label); ?></th><th>Hallazgo y observaci&oacute;n</th></tr></thead><tbody>
          <?php foreach ($groupItems as $item): $status = (string) ($item['status'] ?? 'anomalia'); $sources = (array) ($item['sources'] ?? []); $sourceKey = (string) ($item['expected_insurer'] ?? ''); ?><tr class="scm-cia-row--<?php echo esc_attr($status); ?>"><td><strong><?php echo esc_html((string) (($item['request_number'] ?? '') ?: '—')); ?></strong><span>Contrato <?php echo esc_html((string) (($item['contract_number'] ?? '') ?: '—')); ?></span><small>Mandato <?php echo esc_html((string) (($item['mandate_id'] ?? '') ?: '—')); ?></small></td><td><strong><?php echo esc_html((string) (($item['tenant'] ?? '') ?: '—')); ?></strong><span><?php echo esc_html((string) (($item['property_address'] ?? '') ?: '—')); ?></span><small>Aseguradora: <?php echo esc_html($label); ?></small></td><?php echo $this->sourceValueCell((array) ($item['platform'] ?? []), 'platform', true); ?><?php echo $this->sourceValueCell($sources['simi'] ?? null, 'simi', isset($sourceAudits['simi'])); ?><?php echo $this->sourceValueCell($sourceKey !== '' ? ($sources[$sourceKey] ?? null) : null, $sourceKey, $sourceKey !== '' && isset($sourceAudits[$sourceKey])); ?><td><?php echo $this->differenceList((string) ($item['differences_json'] ?? '[]')); ?><?php if ($status !== 'correcto' && (int) ($item['observation_item_id'] ?? 0) > 0): ?><form class="scm-cia-observation" data-cia-observation-form><input type="hidden" name="item_id" value="<?php echo esc_attr((string) ($item['observation_item_id'] ?? '')); ?>"><label>Observaci&oacute;n del funcionario<textarea name="observation" maxlength="1000" rows="2" placeholder="Explica la anomal&iacute;a o la gesti&oacute;n requerida"><?php echo esc_textarea((string) ($item['observation'] ?? '')); ?></textarea></label><button type="submit" class="scm-btn-secondary btn btn-outline">Guardar</button><?php if (($item['observation_at'] ?? '') !== ''): ?><small>Guardada por <?php echo esc_html((string) ($item['observation_by_name'] ?? '')); ?> · <?php echo esc_html($this->dateTime((string) $item['observation_at'])); ?></small><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
      <?php endforeach; ?>
    </div><div class="scm-cia-legend" aria-label="Significado del semaforo"><span class="is-green">Verde: el valor coincide</span><span class="is-red">Rojo: el valor est&aacute; equivocado</span><span class="is-yellow">Amarillo: falta la fuente o el dato</span></div></section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed>|null $source */
  private function sourceValueCell(?array $source, string $sourceKey, bool $fileLoaded): string
  {
    $metrics = ['canon' => 'Canon', 'administration' => 'Administraci&oacute;n', 'iva' => 'IVA'];
    if ($source === null || $source === []) {
      $message = $sourceKey === '' ? 'Sin aseguradora asignada' : ($fileLoaded ? 'No aparece' : 'Archivo pendiente');
      $values = '';
      foreach ($metrics as $label) {
        $values .= '<span class="is-anomalia">' . $this->metricStatusBadge('anomalia') . '<b>' . $label . '</b><em>—</em></span>';
      }
      return '<td class="scm-cia-source-values is-empty"><small class="scm-cia-source-note">' . esc_html($message) . '</small><div class="scm-cia-value-grid">' . $values . '</div></td>';
    }
    $status = (string) ($source['status'] ?? '');
    $metricStatuses = (array) ($source['metric_statuses'] ?? []);
    $values = '';
    foreach ($metrics as $key => $label) {
      $metricStatus = $sourceKey === 'platform'
        ? (($source[$key] ?? null) === null || ($source[$key] ?? '') === '' ? 'anomalia' : 'reference')
        : (string) ($metricStatuses[$key] ?? 'anomalia');
      $values .= '<span class="is-' . esc_attr($metricStatus) . '">' . $this->metricStatusBadge($metricStatus) . '<b>' . $label . '</b><em>' . esc_html($this->money($source[$key] ?? null)) . '</em></span>';
    }
    return '<td class="scm-cia-source-values' . ($status !== '' ? ' is-' . esc_attr($status) : '') . '"><div class="scm-cia-value-grid">' . $values . '</div></td>';
  }

  private function metricStatusBadge(string $status): string
  {
    $labels = ['correcto' => 'Verde', 'incorrecto' => 'Rojo', 'anomalia' => 'Amarillo', 'reference' => 'Referencia'];
    $status = isset($labels[$status]) ? $status : 'anomalia';
    return '<small class="scm-cia-metric-status is-' . esc_attr($status) . '">' . esc_html($labels[$status]) . '</small>';
  }

  /** @param array<int,array<string,mixed>> $audits */
  private function renderAuditHistory(array $audits): string
  {
    if ($audits === []) return '';
    ob_start();
?>
    <section class="scm-cia-section"><div class="scm-cia-section-head"><div><h3>Registro de archivos procesados</h3><p>Fuente, periodo, funcionario y resultado de cada carga vigente.</p></div></div><div class="scm-cia-history-grid"><?php foreach ($audits as $audit): ?><article class="scm-cia-history-card"><div><strong><?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? ''))); ?></strong><span><?php echo esc_html((string) ($audit['insurer'] ?? '')); ?></span></div><p title="<?php echo esc_attr((string) ($audit['source_filename'] ?? '')); ?>"><?php echo esc_html((string) ($audit['source_filename'] ?? '')); ?></p><dl><div><dt>Verdes</dt><dd><?php echo esc_html((string) ($audit['compliant_rows'] ?? 0)); ?></dd></div><div><dt>Rojos</dt><dd><?php echo esc_html((string) ($audit['difference_rows'] ?? 0)); ?></dd></div><div><dt>Amarillos</dt><dd><?php echo esc_html((string) ($audit['incomplete_rows'] ?? 0)); ?></dd></div></dl><small><?php echo esc_html((string) ($audit['uploaded_by_name'] ?? '')); ?> &middot; <?php echo esc_html($this->dateTime((string) ($audit['uploaded_at'] ?? ''))); ?></small></article><?php endforeach; ?></div></section>
<?php
    return (string) ob_get_clean();
  }

  private function differenceList(string $json): string
  {
    $decoded = json_decode($json, true);
    $messages = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn(string $message): bool => !str_contains(AuditValueNormalizer::key($message), 'prima'))) : [];
    return $messages === [] ? '<span>Sin hallazgos.</span>' : '<ul class="scm-cia-findings"><li>' . implode('</li><li>', array_map('esc_html', $messages)) . '</li></ul>';
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,array{label:string,items:array<int,array<string,mixed>>}> */
  private function itemsByInsurer(array $items): array
  {
    $order = ['libertador', 'fianza_bogota', 'unifianza', 'sin_aseguradora'];
    $groups = [];
    foreach ($items as $item) {
      $key = (string) ($item['expected_insurer'] ?? '');
      $key = in_array($key, ['libertador', 'fianza_bogota', 'unifianza'], true) ? $key : 'sin_aseguradora';
      if (!isset($groups[$key])) {
        $groups[$key] = ['label' => $key === 'sin_aseguradora' ? 'Sin aseguradora asignada' : AuditSourceCatalog::label($key), 'items' => []];
      }
      $groups[$key]['items'][] = $item;
    }
    uksort($groups, static function (string $a, string $b) use ($order): int {
      $aIndex = array_search($a, $order, true);
      $bIndex = array_search($b, $order, true);
      return ($aIndex === false ? 99 : $aIndex) <=> ($bIndex === false ? 99 : $bIndex);
    });
    return $groups;
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,int> */
  private function statusCounts(array $items): array
  {
    $counts = ['correcto' => 0, 'incorrecto' => 0, 'anomalia' => 0];
    foreach ($items as $item) {
      $status = (string) ($item['status'] ?? 'anomalia');
      $counts[isset($counts[$status]) ? $status : 'anomalia']++;
    }
    return $counts;
  }

  private function kpi(string $label, int $value, string $tone): string { return '<div class="scm-cia-kpi scm-cia-kpi--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>'; }
  /** @return array<string,string> */
  private function statusOptions(): array { return ['' => 'Todos', 'incorrecto' => 'Rojo · Incorrecto', 'anomalia' => 'Amarillo · Anomalía', 'correcto' => 'Verde · Correcto']; }
  private function statusLabel(string $status): string { return $this->statusOptions()[$status] ?? 'Amarillo · Anomalía'; }
  private function money(mixed $value): string { return ($value === null || $value === '') ? '—' : '$' . number_format((float) $value, 2, ',', '.'); }
  private function periodLabel(string $period): string { if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1) return $period; $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']; return ($months[(int) $match[2]] ?? $match[2]) . ' ' . $match[1]; }
  private function dateTime(string $value): string { $timestamp = strtotime($value); return $timestamp === false ? $value : date('d/m/Y g:i a', $timestamp); }
}
