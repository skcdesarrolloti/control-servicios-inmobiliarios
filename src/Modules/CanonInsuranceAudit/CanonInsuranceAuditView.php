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
      <div class="scm-cia-modal" data-cia-mandate-modal hidden><div class="scm-cia-modal-backdrop" data-cia-close-mandates></div><section class="scm-cia-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scm-cia-mandate-title"><header><div><span class="scm-eyebrow">Saneamiento de datos</span><h3 id="scm-cia-mandate-title">Vincular mandatos faltantes</h3><p>Contratos de arrendamiento en estado Entregado sin <code>id_contrato_mandato</code>. Se muestran mandatos con el mismo <code>id_inmueble</code> y, cuando al mandato le falte ese dato, candidatos por propietario/correo.</p></div><button type="button" class="scm-cia-modal-close" data-cia-close-mandates aria-label="Cerrar">×</button></header><form class="scm-cia-mandate-search" data-cia-mandate-search autocomplete="off"><label for="scm_cia_mandate_search">Buscar contrato, solicitud, inmueble, arrendatario o propietario</label><div><input id="scm_cia_mandate_search" name="search" type="search" placeholder="Ej. 11827961, contrato, id_inmueble o correo"><button type="submit" class="scm-btn-secondary btn btn-outline">Buscar</button></div></form><div class="scm-cia-modal-status" data-cia-mandate-status aria-live="polite"></div><div class="scm-cia-mandate-content" data-cia-mandate-content><div class="scm-cia-loading" role="status">Abre el modulo para consultar contratos pendientes.</div></div></section></div>
      <div class="scm-cia-modal" data-cia-request-modal hidden><div class="scm-cia-modal-backdrop" data-cia-close-requests></div><section class="scm-cia-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scm-cia-request-title"><header><div><span class="scm-eyebrow">Saneamiento de datos</span><h3 id="scm-cia-request-title">Completar n&uacute;meros de solicitud</h3><p>Contratos de arrendamiento en estado Entregado sin <code>numero_solicitud</code>. Revisa el arrendatario y escribe la solicitud correcta para poder cruzar SIMI y aseguradoras.</p></div><button type="button" class="scm-cia-modal-close" data-cia-close-requests aria-label="Cerrar">×</button></header><form class="scm-cia-mandate-search" data-cia-request-search autocomplete="off"><label for="scm_cia_request_search">Buscar contrato, arrendatario, inmueble o estudio</label><div><input id="scm_cia_request_search" name="search" type="search" placeholder="Ej. nombre del arrendatario o contrato"><button type="submit" class="scm-btn-secondary btn btn-outline">Buscar</button></div></form><div class="scm-cia-modal-status" data-cia-request-status aria-live="polite"></div><div class="scm-cia-mandate-content" data-cia-request-content><div class="scm-cia-loading" role="status">Abre el modulo para consultar contratos sin solicitud.</div></div></section></div>
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
    $canPurge = !empty($payload['can_purge']);
    ob_start();
    if ($sourceAudits === []) {
      echo '<div class="scm-cia-empty"><strong>A&uacute;n no hay archivos procesados para ' . esc_html($this->periodLabel($period)) . '.</strong><span>Selecciona el periodo y carga SIMI junto con los extractos de las tres aseguradoras.</span></div>';
      echo $canPurge ? $this->renderPurgePanel($period, $audits) : '';
      return (string) ob_get_clean();
    }
?>
    <section class="scm-cia-period-head"><div><span>Conciliaci&oacute;n mensual</span><strong><?php echo esc_html($this->periodLabel($period)); ?></strong><small><?php echo esc_html((string) count($sourceAudits)); ?> de 4 fuentes cargadas</small></div><div class="scm-cia-source-state"><?php foreach (AuditSourceCatalog::labels() as $key => $label): $audit = $sourceAudits[$key] ?? null; ?><span class="<?php echo is_array($audit) ? 'is-ready' : 'is-missing'; ?>"><b><?php echo esc_html($label); ?></b><?php echo is_array($audit) ? esc_html((string) ($audit['source_filename'] ?? '')) : 'Pendiente'; ?></span><?php endforeach; ?></div></section>

    <div class="scm-cia-kpis" aria-label="Resumen de conciliacion"><?php echo $this->kpi('Contratos revisados', (int) ($summary['total'] ?? 0), 'neutral'); ?><?php echo $this->kpi('Verde · correctos', (int) ($summary['compliant'] ?? 0), 'success'); ?><?php echo $this->kpi('Rojo · equivocados', (int) ($summary['differences'] ?? 0), 'danger'); ?><?php echo $this->kpi('Amarillo · anomalías', (int) ($summary['incomplete'] ?? 0), 'warning'); ?></div>

    <form class="scm-cia-filters" data-cia-filter-form autocomplete="off"><div class="scm-field"><label for="scm_cia_period_filter">Periodo consolidado</label><select id="scm_cia_period_filter" name="period"><?php if (!in_array($period, $periods, true)): ?><option value="<?php echo esc_attr($period); ?>" selected><?php echo esc_html($this->periodLabel($period)); ?></option><?php endif; ?><?php foreach ($periods as $availablePeriod): ?><option value="<?php echo esc_attr((string) $availablePeriod); ?>" <?php echo $period === (string) $availablePeriod ? 'selected' : ''; ?>><?php echo esc_html($this->periodLabel((string) $availablePeriod)); ?></option><?php endforeach; ?></select></div><div class="scm-field"><label for="scm_cia_status_filter">Resultado</label><select id="scm_cia_status_filter" name="status"><?php foreach ($this->statusOptions() as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php echo (string) ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div><div class="scm-field scm-cia-search-field"><label for="scm_cia_search">Solicitud, contrato o persona</label><input id="scm_cia_search" name="search" type="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" placeholder="Ej. 11827961 o arrendatario"></div><button type="submit" class="scm-btn-secondary btn btn-outline">Aplicar filtros</button></form>

    <section class="scm-cia-admin-actions" aria-label="Herramientas administrativas">
      <div class="scm-cia-section-head"><div><h3>Herramientas administrativas</h3><p>Acciones puntuales para limpiar pruebas o enviar el consolidado. La revisi&oacute;n diaria est&aacute; m&aacute;s abajo.</p></div></div>
      <div class="scm-cia-admin-actions-grid">
        <?php echo $canPurge ? $this->renderPurgePanel($period, $audits) : ''; ?>
        <?php echo $this->renderReportPanel($period, $summary, $reports); ?>
      </div>
    </section>
    <?php echo $this->renderItemsTable($items, $sourceAudits, $audits); ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $payload */
  public function renderMandateLinking(array $payload): string
  {
    $rows = (array) ($payload['rows'] ?? []);
    $search = (string) ($payload['search'] ?? '');
    ob_start();
    if ($rows === []) {
      echo '<div class="scm-cia-empty"><strong>No hay contratos pendientes de mandato' . ($search !== '' ? ' para esta busqueda' : '') . '.</strong><span>Cuando todos los contratos Entregado tengan mandato, la auditoria podra comparar IVA con datos reales de Plataforma.</span></div>';
      return (string) ob_get_clean();
    }
?>
    <div class="scm-cia-mandate-list">
      <?php foreach ($rows as $row): $candidates = (array) ($row['candidates'] ?? []); ?>
        <article class="scm-cia-mandate-card">
          <div class="scm-cia-mandate-contract">
            <span>Contrato de arrendamiento</span>
            <strong><?php echo esc_html((string) (($row['contract_number'] ?? '') ?: 'Sin contrato')); ?></strong>
            <small>Solicitud <?php echo esc_html((string) (($row['request_number'] ?? '') ?: '—')); ?> · ID contrato <?php echo esc_html((string) ($row['contract_id'] ?? '')); ?></small>
            <p><?php echo esc_html((string) (($row['tenant'] ?? '') ?: 'Arrendatario sin nombre')); ?></p>
            <p><?php echo esc_html((string) (($row['property_address'] ?? '') ?: 'Inmueble sin direccion')); ?></p>
            <dl><div><dt>id_inmueble</dt><dd><?php echo esc_html((string) (($row['property_id'] ?? '') ?: '—')); ?></dd></div><div><dt>Aseguradora</dt><dd><?php echo esc_html((string) (($row['insurer'] ?? '') ?: '—')); ?></dd></div><div><dt>Propietario</dt><dd><?php echo esc_html((string) (($row['owner_name'] ?? '') ?: '—')); ?></dd></div><div><dt>Correo propietario</dt><dd><?php echo esc_html((string) (($row['owner_email'] ?? '') ?: '—')); ?></dd></div><div><dt>Canon</dt><dd><?php echo esc_html($this->money($row['canon'] ?? null)); ?></dd></div><div><dt>Administraci&oacute;n</dt><dd><?php echo esc_html($this->money($row['administration'] ?? null)); ?></dd></div></dl>
          </div>
          <div class="scm-cia-mandate-candidates">
            <div class="scm-cia-mandate-candidates-head"><strong>Mandatos encontrados</strong><span><?php echo esc_html((string) count($candidates)); ?> candidato(s)</span></div>
            <?php if ($candidates === []): ?>
              <div class="scm-cia-mandate-empty">No hay mandatos con el mismo <code>id_inmueble</code> ni candidatos por propietario/correo.</div>
            <?php else: foreach ($candidates as $candidate): ?>
              <div class="scm-cia-mandate-option">
                <div><strong>Mandato <?php echo esc_html((string) ($candidate['mandate_id'] ?? '')); ?></strong><span><?php echo esc_html((string) (($candidate['insurer'] ?? '') ?: 'Sin aseguradora')); ?></span><small>IVA: <?php echo esc_html((string) (($candidate['includes_iva'] ?? '') ?: 'Sin dato')); ?></small></div>
                <div class="scm-cia-mandate-meta" aria-label="Datos para validar candidato">
                  <span><?php echo esc_html((string) (($candidate['match_reason'] ?? '') ?: 'Candidato')); ?></span>
                  <p><strong>Propietario:</strong> <?php echo esc_html((string) (($candidate['owner_name'] ?? '') ?: '—')); ?></p>
                  <p><strong>Correo:</strong> <?php echo esc_html((string) (($candidate['owner_email'] ?? '') ?: '—')); ?></p>
                  <p><strong>Direcci&oacute;n mandato:</strong> <?php echo esc_html((string) (($candidate['mandate_address'] ?? '') ?: '—')); ?></p>
                  <p><strong>id_inmueble mandato:</strong> <?php echo esc_html((string) (($candidate['property_id'] ?? '') ?: '—')); ?></p>
                </div>
                <dl><div><dt>Canon</dt><dd><?php echo esc_html($this->money($candidate['canon'] ?? null)); ?></dd></div><div><dt>Administraci&oacute;n</dt><dd><?php echo esc_html($this->money($candidate['administration'] ?? null)); ?></dd></div><div><dt>IVA</dt><dd><?php echo esc_html($this->money($candidate['iva'] ?? null)); ?></dd></div></dl>
                <form data-cia-link-mandate-form><input type="hidden" name="contract_id" value="<?php echo esc_attr((string) ($row['contract_id'] ?? '')); ?>"><input type="hidden" name="mandate_id" value="<?php echo esc_attr((string) ($candidate['mandate_id'] ?? '')); ?>"><button type="submit" class="scm-btn-primary btn btn-primary">Vincular</button></form>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $payload */
  public function renderRequestNumbers(array $payload): string
  {
    $rows = (array) ($payload['rows'] ?? []);
    $search = (string) ($payload['search'] ?? '');
    ob_start();
    if ($rows === []) {
      echo '<div class="scm-cia-empty"><strong>No hay contratos Entregado sin numero de solicitud' . ($search !== '' ? ' para esta busqueda' : '') . '.</strong><span>Cuando el contrato tenga solicitud, la auditoria podra cruzarlo contra SIMI y los extractos.</span></div>';
      return (string) ob_get_clean();
    }
?>
    <div class="scm-cia-request-list">
      <?php foreach ($rows as $row): $suggested = (string) ($row['study_request'] ?? ''); ?>
        <article class="scm-cia-request-card">
          <div class="scm-cia-request-main">
            <span>Contrato sin solicitud</span>
            <strong><?php echo esc_html((string) (($row['tenant'] ?? '') ?: 'Arrendatario sin nombre')); ?></strong>
            <small>Contrato <?php echo esc_html((string) (($row['contract_number'] ?? '') ?: '—')); ?> · ID <?php echo esc_html((string) ($row['contract_id'] ?? '')); ?></small>
            <p><?php echo esc_html((string) (($row['property_address'] ?? '') ?: 'Inmueble sin direccion')); ?></p>
            <dl><div><dt>id_inmueble</dt><dd><?php echo esc_html((string) (($row['property_id'] ?? '') ?: '—')); ?></dd></div><div><dt>Aseguradora contrato</dt><dd><?php echo esc_html((string) (($row['insurer'] ?? '') ?: '—')); ?></dd></div><div><dt>Estudio</dt><dd><?php echo esc_html((string) (($row['study_id'] ?? '') ?: '—')); ?></dd></div><div><dt>Solicitud sugerida</dt><dd><?php echo esc_html($suggested !== '' ? $suggested : '—'); ?></dd></div></dl>
            <?php if (($row['study_insurer'] ?? '') !== '' || ($row['study_status'] ?? '') !== ''): ?><div class="scm-cia-request-study"><strong>Dato del estudio:</strong> <?php echo esc_html((string) (($row['study_insurer'] ?? '') ?: 'Sin aseguradora')); ?><?php echo ($row['study_status'] ?? '') !== '' ? ' · ' . esc_html((string) $row['study_status']) : ''; ?></div><?php endif; ?>
          </div>
          <form class="scm-cia-request-form" data-cia-update-request-form>
            <input type="hidden" name="contract_id" value="<?php echo esc_attr((string) ($row['contract_id'] ?? '')); ?>">
            <label>N&uacute;mero de solicitud correcto<input name="request_number" type="text" inputmode="numeric" autocomplete="off" placeholder="Ej. 11988353" value="<?php echo esc_attr($suggested); ?>" required></label>
            <button type="submit" class="scm-btn-primary btn btn-primary">Guardar solicitud</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $summary @param array<int,array<string,mixed>> $reports */
  private function renderReportPanel(string $period, array $summary, array $reports): string
  {
    $pending = (int) ($summary['differences'] ?? 0) + (int) ($summary['incomplete'] ?? 0);
    ob_start();
?>
    <section class="scm-cia-report"><div><strong>Informe consolidado por correo</strong><span>Incluye las filas rojas y amarillas con los valores de todas las fuentes y las observaciones guardadas.</span><?php if ($reports !== []): $last = $reports[0]; ?><small>&Uacute;ltimo env&iacute;o: <?php echo esc_html($this->dateTime((string) ($last['sent_at'] ?? ''))); ?> por <?php echo esc_html((string) ($last['sent_by_name'] ?? '')); ?>.</small><?php endif; ?></div><form data-cia-report-form><input type="hidden" name="period" value="<?php echo esc_attr($period); ?>"><label>Correo destino<input name="recipients" type="text" inputmode="email" autocomplete="email" placeholder="correo@empresa.com o varios separados por coma" required></label><button type="submit" class="scm-btn-primary btn btn-primary" <?php echo $pending === 0 ? 'disabled' : ''; ?>>Enviar informe (<?php echo esc_html((string) $pending); ?>)</button></form></section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $audits */
  private function renderPurgePanel(string $period, array $audits): string
  {
    $auditCount = count(array_filter($audits, static fn(array $audit): bool => (string) ($audit['period'] ?? '') === $period));
    $confirmation = 'BORRAR ' . $period;
    ob_start();
?>
    <section class="scm-cia-purge"><div><strong>Zona de limpieza de pruebas</strong><span>Borra todas las auditor&iacute;as cargadas para <?php echo esc_html($this->periodLabel($period)); ?>, junto con sus filas comparadas e informes asociados.</span><small><?php echo esc_html((string) $auditCount); ?> carga(s) detectadas para este periodo. Acci&oacute;n disponible solo para administraci&oacute;n/desarrollo.</small></div><form data-cia-purge-form autocomplete="off"><input type="hidden" name="period" value="<?php echo esc_attr($period); ?>"><label>Confirmaci&oacute;n<input name="confirmation" type="text" placeholder="<?php echo esc_attr($confirmation); ?>" aria-label="Escribe <?php echo esc_attr($confirmation); ?> para confirmar"></label><button type="submit" class="scm-cia-danger-btn btn" <?php echo $auditCount === 0 ? 'disabled' : ''; ?>>Borrar auditor&iacute;as</button></form></section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,array<string,mixed>> $sourceAudits @param array<int,array<string,mixed>> $audits */
  private function renderItemsTable(array $items, array $sourceAudits, array $audits): string
  {
    $groups = $this->itemsByInsurer($items);
    if ($groups === []) {
      $groups['sin_resultados'] = ['label' => 'Sin resultados', 'items' => []];
    }
    $activeKey = array_key_first($groups) ?: 'archivos';
    ob_start();
?>
    <section class="scm-cia-section scm-cia-workspace" data-cia-tabs>
      <div class="scm-cia-section-head scm-cia-section-head--work">
        <div>
          <h3>Área de revisión</h3>
          <p>Primero elige si quieres revisar la comparación o el historial de archivos. Luego filtra por aseguradora.</p>
        </div>
        <span><?php echo esc_html((string) count($items)); ?> filas visibles</span>
      </div>

      <div class="scm-cia-main-tabs" role="tablist" aria-label="Vista de auditoría">
        <button type="button" class="scm-cia-main-tab active" role="tab" aria-selected="true" data-cia-tab-button data-cia-tab-target="scm-cia-panel-comparacion"><strong>Comparación</strong><small>Contratos por aseguradora</small></button>
        <button type="button" class="scm-cia-main-tab" role="tab" aria-selected="false" data-cia-tab-button data-cia-tab-target="scm-cia-panel-archivos"><strong>Archivos procesados</strong><small><?php echo esc_html((string) count($audits)); ?> carga(s)</small></button>
      </div>

      <div class="scm-cia-tab-panel active" id="scm-cia-panel-comparacion" role="tabpanel" data-cia-tab-panel>
        <div class="scm-cia-comparison-shell" data-cia-tabs>
          <div class="scm-cia-insurer-head">
            <div>
              <h4>Comparación por aseguradora</h4>
              <p>Los contadores muestran total, rojos, amarillos y verdes para que sepas dónde revisar primero.</p>
            </div>
            <div class="scm-cia-legend" aria-label="Significado del semaforo"><span class="is-red">Rojo: valor equivocado</span><span class="is-yellow">Amarillo: falta dato/fuente</span><span class="is-green">Verde: coincide</span></div>
          </div>
          <div class="scm-cia-insurer-tabs" role="tablist" aria-label="Aseguradoras auditadas">
            <?php foreach ($groups as $groupKey => $group): $groupItems = (array) ($group['items'] ?? []); $label = (string) ($group['label'] ?? 'Sin aseguradora'); $counts = $this->statusCounts($groupItems); $tabId = 'scm-cia-panel-insurer-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $groupKey)); ?>
              <button type="button" class="scm-cia-insurer-tab<?php echo $groupKey === $activeKey ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo $groupKey === $activeKey ? 'true' : 'false'; ?>" data-cia-tab-button data-cia-tab-target="<?php echo esc_attr($tabId); ?>"><strong><?php echo esc_html($label); ?></strong><?php echo $this->tabStatusPills($counts, count($groupItems)); ?></button>
            <?php endforeach; ?>
          </div>
          <div class="scm-cia-tabpanels">
            <?php foreach ($groups as $groupKey => $group): $groupItems = (array) ($group['items'] ?? []); $label = (string) ($group['label'] ?? 'Sin aseguradora'); $tabId = 'scm-cia-panel-insurer-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $groupKey)); ?>
              <div class="scm-cia-tab-panel<?php echo $groupKey === $activeKey ? ' active' : ''; ?>" id="<?php echo esc_attr($tabId); ?>" role="tabpanel" data-cia-tab-panel <?php echo $groupKey === $activeKey ? '' : 'hidden'; ?>>
                <?php echo $this->renderInsurerTable($label, $groupItems, $sourceAudits); ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="scm-cia-tab-panel" id="scm-cia-panel-archivos" role="tabpanel" data-cia-tab-panel hidden><?php echo $this->renderAuditHistory($audits); ?></div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $groupItems @param array<string,array<string,mixed>> $sourceAudits */
  private function renderInsurerTable(string $label, array $groupItems, array $sourceAudits): string
  {
    if ($groupItems === []) {
      return '<div class="scm-cia-empty"><strong>No hay contratos con estos filtros.</strong><span>Cambia el resultado o la b&uacute;squeda.</span></div>';
    }
    ob_start();
?>
    <div class="scm-cia-table-wrap"><table class="scm-cia-table scm-cia-results-table"><thead><tr><th>Solicitud / contrato</th><th>Arrendatario e inmueble</th><th>Plataforma</th><th>SIMI</th><th><?php echo esc_html($label); ?></th><th>Hallazgo y observaci&oacute;n</th></tr></thead><tbody>
      <?php foreach ($groupItems as $item): $status = (string) ($item['status'] ?? 'anomalia'); $sources = (array) ($item['sources'] ?? []); $sourceKey = (string) ($item['expected_insurer'] ?? ''); ?><tr class="scm-cia-row--<?php echo esc_attr($status); ?>" data-cia-contract-row data-cia-contract-id="<?php echo esc_attr((string) ($item['contract_id'] ?? '')); ?>"><td><strong><?php echo esc_html((string) (($item['request_number'] ?? '') ?: '—')); ?></strong><span>Contrato <?php echo esc_html((string) (($item['contract_number'] ?? '') ?: '—')); ?></span><small>Mandato <?php echo esc_html((string) (($item['mandate_id'] ?? '') ?: '—')); ?></small><?php echo $this->rowRepairActions($item); ?></td><td><strong><?php echo esc_html((string) (($item['tenant'] ?? '') ?: '—')); ?></strong><span><?php echo esc_html((string) (($item['property_address'] ?? '') ?: '—')); ?></span><small>Aseguradora: <?php echo esc_html($label); ?></small></td><?php echo $this->sourceValueCell((array) ($item['platform'] ?? []), 'platform', true, $this->platformCorrectionActions($item)); ?><?php echo $this->sourceValueCell($sources['simi'] ?? null, 'simi', isset($sourceAudits['simi'])); ?><?php echo $this->sourceValueCell($sourceKey !== '' ? ($sources[$sourceKey] ?? null) : null, $sourceKey, $sourceKey !== '' && isset($sourceAudits[$sourceKey])); ?><td><?php echo $this->differenceList((string) ($item['differences_json'] ?? '[]')); ?><?php if ($status !== 'correcto' && (int) ($item['observation_item_id'] ?? 0) > 0): ?><form class="scm-cia-observation" data-cia-observation-form><input type="hidden" name="item_id" value="<?php echo esc_attr((string) ($item['observation_item_id'] ?? '')); ?>"><label>Observaci&oacute;n del funcionario<textarea name="observation" maxlength="1000" rows="2" placeholder="Explica la anomal&iacute;a o la gesti&oacute;n requerida"><?php echo esc_textarea((string) ($item['observation'] ?? '')); ?></textarea></label><button type="submit" class="scm-btn-secondary btn btn-outline">Guardar</button><?php if (($item['observation_at'] ?? '') !== ''): ?><small>Guardada por <?php echo esc_html((string) ($item['observation_by_name'] ?? '')); ?> · <?php echo esc_html($this->dateTime((string) $item['observation_at'])); ?></small><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed>|null $source */
  private function sourceValueCell(?array $source, string $sourceKey, bool $fileLoaded, string $extraHtml = ''): string
  {
    $metrics = ['canon' => 'Canon', 'administration' => 'Administraci&oacute;n', 'iva' => 'IVA'];
    if ($source === null || $source === []) {
      $message = $sourceKey === '' ? 'Sin aseguradora asignada' : ($fileLoaded ? 'No aparece' : 'Archivo pendiente');
      $values = '';
      foreach ($metrics as $label) {
        $values .= '<span class="is-anomalia">' . $this->metricStatusBadge('anomalia') . '<b>' . $label . '</b><em>—</em></span>';
      }
      return '<td class="scm-cia-source-values is-empty"><small class="scm-cia-source-note">' . esc_html($message) . '</small><div class="scm-cia-value-grid">' . $values . '</div>' . $extraHtml . '</td>';
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
    return '<td class="scm-cia-source-values' . ($status !== '' ? ' is-' . esc_attr($status) : '') . '"><div class="scm-cia-value-grid">' . $values . '</div>' . $extraHtml . '</td>';
  }

  /** @param array<string,mixed> $item */
  private function platformCorrectionActions(array $item): string
  {
    $contractId = (int) ($item['contract_id'] ?? 0);
    if ($contractId <= 0) {
      return '';
    }
    $platform = (array) ($item['platform'] ?? []);
    $simi = (array) (((array) ($item['sources'] ?? []))['simi'] ?? []);
    $hasSimiValues = $this->hasMoneyValue($simi['canon'] ?? null)
      && $this->hasMoneyValue($simi['administration'] ?? null);
    ob_start();
?>
    <div class="scm-cia-platform-tools">
      <?php if ($hasSimiValues): ?>
        <form data-cia-platform-values-form>
          <input type="hidden" name="contract_id" value="<?php echo esc_attr((string) $contractId); ?>">
          <input type="hidden" name="canon" value="<?php echo esc_attr($this->moneyInputValue($simi['canon'] ?? null)); ?>">
          <input type="hidden" name="administration" value="<?php echo esc_attr($this->moneyInputValue($simi['administration'] ?? null)); ?>">
          <button type="submit" class="scm-cia-platform-copy">Usar SIMI</button>
        </form>
      <?php endif; ?>
      <details class="scm-cia-platform-edit">
        <summary>Editar valores</summary>
        <form data-cia-platform-values-form>
          <input type="hidden" name="contract_id" value="<?php echo esc_attr((string) $contractId); ?>">
          <label>Canon<input name="canon" type="text" inputmode="decimal" value="<?php echo esc_attr($this->moneyInputValue($platform['canon'] ?? null)); ?>" placeholder="0"></label>
          <label>Administraci&oacute;n<input name="administration" type="text" inputmode="decimal" value="<?php echo esc_attr($this->moneyInputValue($platform['administration'] ?? null)); ?>" placeholder="0"></label>
          <button type="submit" class="scm-btn-secondary btn btn-outline">Guardar valores</button>
        </form>
      </details>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $item */
  private function rowRepairActions(array $item): string
  {
    $contractId = (int) ($item['contract_id'] ?? 0);
    if ($contractId <= 0) {
      return '';
    }
    $request = AuditValueNormalizer::requestNumber($item['request_number'] ?? '');
    $mandateId = (int) ($item['mandate_id'] ?? 0);
    $tenant = (string) ($item['tenant'] ?? '');
    $out = '';
    if ($request === '') {
      $out .= '<form class="scm-cia-inline-repair scm-cia-inline-request" data-cia-update-request-form>'
        . '<input type="hidden" name="contract_id" value="' . esc_attr((string) $contractId) . '">'
        . '<label>Completar solicitud<input name="request_number" type="text" inputmode="numeric" autocomplete="off" placeholder="N&uacute;mero solicitud" required></label>'
        . '<button type="submit" class="scm-btn-secondary btn btn-outline">Guardar</button>'
        . '</form>';
      if (trim($tenant) !== '') {
        $out .= '<button type="button" class="scm-cia-inline-repair-button scm-btn-secondary btn btn-outline" data-cia-open-requests data-cia-request-search-value="' . esc_attr($tenant) . '">Revisar por arrendatario</button>';
      }
    }
    if ($mandateId <= 0) {
      $search = (string) (($item['contract_number'] ?? '') ?: (($item['tenant'] ?? '') ?: ($item['property_address'] ?? '')));
      $out .= '<button type="button" class="scm-cia-inline-repair-button scm-btn-secondary btn btn-outline" data-cia-open-mandates data-cia-mandate-contract-id="' . esc_attr((string) $contractId) . '" data-cia-mandate-search-value="' . esc_attr($search) . '">Vincular mandato</button>';
    }
    return $out !== '' ? '<div class="scm-cia-row-actions">' . $out . '</div>' : '';
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
    if ($audits === []) return '<div class="scm-cia-empty"><strong>No hay archivos procesados.</strong><span>Cuando cargues archivos, aparecer&aacute;n aqu&iacute; con fecha, funcionario y resultado.</span></div>';
    ob_start();
?>
    <div class="scm-cia-section-head"><div><h3>Registro de archivos procesados</h3><p>Fuente, periodo, funcionario y resultado de cada carga vigente.</p></div></div><div class="scm-cia-history-grid"><?php foreach ($audits as $audit): ?><article class="scm-cia-history-card"><div><strong><?php echo esc_html($this->periodLabel((string) ($audit['period'] ?? ''))); ?></strong><span><?php echo esc_html((string) ($audit['insurer'] ?? '')); ?></span></div><p title="<?php echo esc_attr((string) ($audit['source_filename'] ?? '')); ?>"><?php echo esc_html((string) ($audit['source_filename'] ?? '')); ?></p><dl><div><dt>Verdes</dt><dd><?php echo esc_html((string) ($audit['compliant_rows'] ?? 0)); ?></dd></div><div><dt>Rojos</dt><dd><?php echo esc_html((string) ($audit['difference_rows'] ?? 0)); ?></dd></div><div><dt>Amarillos</dt><dd><?php echo esc_html((string) ($audit['incomplete_rows'] ?? 0)); ?></dd></div></dl><small><?php echo esc_html((string) ($audit['uploaded_by_name'] ?? '')); ?> &middot; <?php echo esc_html($this->dateTime((string) ($audit['uploaded_at'] ?? ''))); ?></small></article><?php endforeach; ?></div>
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

  /** @param array<string,int> $counts */
  private function tabStatusPills(array $counts, int $total): string
  {
    return '<span class="scm-cia-tab-pills">'
      . '<i class="is-total"><b>Total</b><em>' . esc_html((string) $total) . '</em></i>'
      . '<i class="is-red"><b>Rojos</b><em>' . esc_html((string) ($counts['incorrecto'] ?? 0)) . '</em></i>'
      . '<i class="is-yellow"><b>Amarillos</b><em>' . esc_html((string) ($counts['anomalia'] ?? 0)) . '</em></i>'
      . '<i class="is-green"><b>Verdes</b><em>' . esc_html((string) ($counts['correcto'] ?? 0)) . '</em></i>'
      . '</span>';
  }

  private function kpi(string $label, int $value, string $tone): string { return '<div class="scm-cia-kpi scm-cia-kpi--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>'; }
  /** @return array<string,string> */
  private function statusOptions(): array { return ['' => 'Todos', 'incorrecto' => 'Rojo · Incorrecto', 'anomalia' => 'Amarillo · Anomalía', 'correcto' => 'Verde · Correcto']; }
  private function statusLabel(string $status): string { return $this->statusOptions()[$status] ?? 'Amarillo · Anomalía'; }
  private function money(mixed $value): string { return ($value === null || $value === '') ? '—' : '$' . number_format((float) $value, 2, ',', '.'); }
  private function hasMoneyValue(mixed $value): bool { return $value !== null && $value !== ''; }
  private function moneyInputValue(mixed $value): string { return $this->hasMoneyValue($value) ? number_format((float) $value, 2, '.', '') : '0.00'; }
  private function periodLabel(string $period): string { if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1) return $period; $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']; return ($months[(int) $match[2]] ?? $match[2]) . ' ' . $match[1]; }
  private function dateTime(string $value): string { $timestamp = strtotime($value); return $timestamp === false ? $value : date('d/m/Y g:i a', $timestamp); }
}
