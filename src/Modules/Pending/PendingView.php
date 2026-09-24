<?php

namespace SCM\Modules\Pending;

final class PendingView
{
  // Preventivas ----------------------------------------------------------------

  public function renderPreventivasPanel(array $filters, array $funcionarios, array $items, int $count, string $corte): string
  {
    ob_start();
?>
    <div class="scm-pending-wrap">

      <!-- Header -->
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Revision Preventiva &mdash; Pendientes</h2>
          <p>Contratos <strong>estado = Entregado</strong> sin revision en <?php echo esc_html((string) date('Y')); ?></p>
        </div>
        <div>
          <div class="scm-pending-count" id="spp-kpi-count"><?php echo esc_html((string) $count); ?></div>
          <div class="scm-pending-count-label">contratos pendientes</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="scm-filter-card">
        <h3>Filtros</h3>
        <form method="post" autocomplete="off" id="spp_form">
          <div class="scm-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));">
            <div class="scm-field">
              <label for="spp_mes">Mes</label>
              <select id="spp_mes" name="spp_mes">
                <option value="0">Todos</option>
                <?php for ($m = 1; $m <= 12; $m++) : ?>
                  <option value="<?php echo esc_attr((string) $m); ?>" <?php selected((int) ($filters['mes'] ?? 0), $m); ?>><?php echo esc_html($this->monthName($m)); ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="scm-field">
              <label for="spp_inmueble">Inmueble</label>
              <input id="spp_inmueble" name="spp_inmueble" type="text" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>" placeholder="# inmueble">
            </div>
            <div class="scm-field">
              <label for="spp_propietario">Propietario</label>
              <input id="spp_propietario" name="spp_propietario" type="text" value="<?php echo esc_attr((string) ($filters['propietario'] ?? '')); ?>" placeholder="Nombre">
            </div>
            <div class="scm-field">
              <label for="spp_arrendatario">Arrendatario</label>
              <input id="spp_arrendatario" name="spp_arrendatario" type="text" value="<?php echo esc_attr((string) ($filters['arrendatario'] ?? '')); ?>" placeholder="Nombre">
            </div>
            <div class="scm-field">
              <label for="spp_contrato">Contrato</label>
              <input id="spp_contrato" name="spp_contrato" type="text" value="<?php echo esc_attr((string) ($filters['contrato'] ?? '')); ?>" placeholder="Codigo">
            </div>
            <div class="scm-field">
              <label for="spp_caso"># caso</label>
              <input id="spp_caso" name="spp_caso" type="text" value="<?php echo esc_attr((string) ($filters['id_ticket'] ?? '')); ?>" placeholder="Ej: 10368">
            </div>
          </div>
          <div class="scm-actions">
            <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
            <button class="scm-btn-secondary" type="button" data-pending-clear="spp_">Limpiar</button>
            <span class="scm-spinner" id="spp_spinner">
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
            </span>
          </div>
        </form>
      </div>

      <!-- KPIs -->
      <div class="scm-kpis" id="spp_kpis">
        <?php echo $this->renderPreventivasKpis($count, $corte); ?>
      </div>

      <!-- Tabla -->
      <div id="spp_table">
        <?php echo $this->renderPreventivasTable($items); ?>
      </div>

    </div>
  <?php
    return (string) ob_get_clean();
  }

  // Servicios Publicos ---------------------------------------------------------

  public function renderServiciosPublicosPanel(array $filters, array $items, int $count, string $corte, array $configurationItems = []): string
  {
    ob_start();
  ?>
    <div class="scm-pending-wrap">

      <!-- Header -->
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Servicios Publicos &mdash; Pendientes</h2>
          <p>Contratos <strong>estado = Entregado</strong> con siguiente revision calculada desde la ultima revision y el mes configurado</p>
        </div>
        <div>
          <div class="scm-pending-count" id="rsp-kpi-count"><?php echo esc_html((string) $count); ?></div>
          <div class="scm-pending-count-label">contratos pendientes</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="scm-filter-card">
        <h3>Filtros</h3>
        <form method="post" autocomplete="off" id="rsp_form">
          <div class="scm-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));">
            <div class="scm-field">
              <label for="rsp_mes">Mes de vencimiento</label>
              <select id="rsp_mes" name="rsp_mes">
                <option value="0">Todos</option>
                <?php for ($m = 1; $m <= 12; $m++) : ?>
                  <option value="<?php echo esc_attr((string) $m); ?>" <?php selected((int) ($filters['mes'] ?? 0), $m); ?>><?php echo esc_html($this->monthName($m)); ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="scm-field">
              <label for="rsp_inmueble">Inmueble</label>
              <input id="rsp_inmueble" name="rsp_inmueble" type="text" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>" placeholder="# inmueble">
            </div>
            <div class="scm-field">
              <label for="rsp_propietario">Propietario</label>
              <input id="rsp_propietario" name="rsp_propietario" type="text" value="<?php echo esc_attr((string) ($filters['propietario'] ?? '')); ?>" placeholder="Nombre">
            </div>
            <div class="scm-field">
              <label for="rsp_arrendatario">Arrendatario</label>
              <input id="rsp_arrendatario" name="rsp_arrendatario" type="text" value="<?php echo esc_attr((string) ($filters['arrendatario'] ?? '')); ?>" placeholder="Nombre">
            </div>
            <div class="scm-field">
              <label for="rsp_contrato">Contrato</label>
              <input id="rsp_contrato" name="rsp_contrato" type="text" value="<?php echo esc_attr((string) ($filters['contrato'] ?? '')); ?>" placeholder="Codigo">
            </div>
          </div>
          <div class="scm-actions">
            <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
            <button class="scm-btn-secondary" type="button" data-pending-clear="rsp_">Limpiar</button>
            <span class="scm-spinner" id="rsp_spinner">
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
            </span>
          </div>
        </form>
      </div>

      <!-- KPIs -->
      <div class="scm-kpis" id="rsp_kpis">
        <?php echo $this->renderServiciosPublicosKpis($count, $corte); ?>
      </div>

      <!-- Tabla -->
      <div id="rsp_table">
        <?php echo $this->renderServiciosPublicosTable($items, $configurationItems); ?>
      </div>

    </div>
<?php
    return (string) ob_get_clean();
  }

  public function renderReportesAdministrativosPanel(array $filters, array $funcionarios, array $items, int $count): string
  {
    ob_start();
?>
    <div class="scm-pending-wrap" id="sra_panel">
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Reportes Administrativos Pendientes</h2>
          <p>Reportes con estado <strong>Pendiente</strong> antes de pasar al consolidado administrativo</p>
        </div>
        <div>
          <div class="scm-pending-count" id="sra-kpi-count"><?php echo esc_html((string) $count); ?></div>
          <div class="scm-pending-count-label">reportes pendientes</div>
        </div>
      </div>

      <div class="scm-filter-card">
        <h3>Filtros</h3>
        <form method="post" autocomplete="off" id="sra_form">
          <div class="scm-grid" style="grid-template-columns:repeat(7,minmax(0,1fr));">
            <div class="scm-field">
              <label for="sra_estado">Estado</label>
              <select id="sra_estado" name="sra_estado">
                <option value="Pendiente" <?php selected((string) ($filters['estado'] ?? 'Pendiente'), 'Pendiente'); ?>>Pendiente</option>
                <option value="Todos" <?php selected((string) ($filters['estado'] ?? ''), 'Todos'); ?>>Todos</option>
                <option value="Aprobado" <?php selected((string) ($filters['estado'] ?? ''), 'Aprobado'); ?>>Aprobado</option>
                <option value="Desaprobado" <?php selected((string) ($filters['estado'] ?? ''), 'Desaprobado'); ?>>No Aprobado</option>
              </select>
            </div>
            <div class="scm-field">
              <label for="sra_id_empleado">Funcionario</label>
              <select id="sra_id_empleado" name="sra_id_empleado">
                <option value="">Todos</option>
                <?php foreach ($funcionarios as $func) : ?>
                  <option value="<?php echo esc_attr((string) ($func['id'] ?? '')); ?>" <?php selected((string) ($filters['id_empleado'] ?? ''), (string) ($func['id'] ?? '')); ?>><?php echo esc_html((string) ($func['label'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="scm-field">
              <label for="sra_arrendatario">Arrendatario</label>
              <input id="sra_arrendatario" name="sra_arrendatario" type="text" value="<?php echo esc_attr((string) ($filters['arrendatario'] ?? '')); ?>" placeholder="Nombre">
            </div>
            <div class="scm-field">
              <label for="sra_inmueble">Inmueble</label>
              <input id="sra_inmueble" name="sra_inmueble" type="text" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>" placeholder="# inmueble">
            </div>
            <div class="scm-field">
              <label for="sra_categoria">Categoria</label>
              <input id="sra_categoria" name="sra_categoria" type="text" value="<?php echo esc_attr((string) ($filters['categoria'] ?? '')); ?>" placeholder="Categoria">
            </div>
            <div class="scm-field">
              <label for="sra_ticket"># caso</label>
              <input id="sra_ticket" name="sra_ticket" type="text" value="<?php echo esc_attr((string) ($filters['id_ticket'] ?? '')); ?>" placeholder="ID ticket">
            </div>
            <div class="scm-field">
              <label for="sra_contrato">Contrato</label>
              <input id="sra_contrato" name="sra_contrato" type="text" value="<?php echo esc_attr((string) ($filters['contrato'] ?? '')); ?>" placeholder="Contrato">
            </div>
          </div>
          <div class="scm-actions">
            <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
            <button class="scm-btn-secondary" type="button" data-pending-clear="sra_">Limpiar</button>
            <span class="scm-spinner" id="sra_spinner">
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
              <span class="scm-spinner-dot"></span>
            </span>
          </div>
        </form>
      </div>

      <div id="sra_table">
        <?php echo $this->renderReportesAdministrativosTable($items); ?>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,array<string,string>> $bucketDefs */
  public function renderContratosArrendamientoPanel(array $bucketDefs): string
  {
    $firstKey = (string) (array_key_first($bucketDefs) ?? 'por_entregar');
    ob_start();
?>
    <div class="scm-pending-wrap scm-contracts-wrap flex flex-col gap-5 w-full" data-scm-contracts>
      <!-- Encabezado con título, contador registrado y acciones operativas -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div class="flex flex-col gap-1">
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Contratos de Arrendamiento</h1>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-[#0f1e36] border border-blue-200">
              <span id="sca-kpi-count" class="mr-1">0</span> registrados
            </span>
          </div>
          <p class="text-sm text-slate-500">
            Gestiona, filtra y supervisa los contratos de arrendamiento activos y genera requerimientos administrativos.
          </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 text-xs font-semibold shadow-2xs transition-all">
            <span class="material-symbols-outlined text-[18px] text-slate-500">upload_file</span>
            <span>Importar CSV</span>
          </button>
          <button type="button" data-scm-open-due-settings class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 text-xs font-semibold shadow-2xs transition-all">
            <span class="material-symbols-outlined text-[18px] text-slate-500">tune</span>
            <span>Configurar Vencimientos</span>
          </button>
          <button type="button" onclick="window.dispatchEvent(new CustomEvent('scm:open-nuevo-ticket'))" class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#0f1e36] text-white hover:bg-[#162846] text-xs font-semibold shadow-xs transition-all">
            <span class="material-symbols-outlined text-[18px]">add</span>
            <span>Nuevo Contrato</span>
          </button>
        </div>
      </div>

      <!-- Filtros rápidos por estado con contadores en burbuja -->
      <div class="scm-status-subtabs scm-contract-subtabs flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar" role="tablist" aria-label="Contratos de arrendamiento">
        <?php foreach ($bucketDefs as $key => $def): ?>
          <?php $isActive = ($key === $firstKey); ?>
          <button
            class="scm-status-topic-tab scm-contract-tab inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-semibold transition-all whitespace-nowrap <?php echo $isActive ? 'active bg-[#0f1e36] text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'; ?>"
            type="button"
            data-contract-bucket="<?php echo esc_attr((string) $key); ?>"
          >
            <span><?php echo esc_html((string) ($def['label'] ?? $key)); ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- Paneles por estado -->
      <?php foreach ($bucketDefs as $key => $def): $active = $key === $firstKey; ?>
        <div class="scm-contract-panel bg-white rounded-2xl p-5 border border-slate-200 shadow-subtle flex flex-col gap-4 <?php echo $active ? ' active' : ''; ?>" data-contract-panel="<?php echo esc_attr((string) $key); ?>" data-scm-loaded="0" <?php echo !$active ? 'style="display:none;"' : ''; ?>>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 gap-2">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <span class="material-symbols-outlined text-[18px] text-slate-400">tune</span>
              <span>Filtros — <?php echo esc_html((string) ($def['label'] ?? $key)); ?></span>
            </h3>
            <span class="text-xs text-slate-500 font-medium">Contratos filtrados: <strong class="text-slate-800" data-contract-count>0</strong></span>
          </div>

          <form method="post" autocomplete="off" class="sca_form flex flex-col gap-3">
            <input type="hidden" name="sca_page" value="1">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
              <div class="relative flex items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[18px] pointer-events-none">search</span>
                <input name="sca_contrato" type="text" placeholder="Código contrato" class="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
              </div>
              <div class="relative flex items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[18px] pointer-events-none">apartment</span>
                <input name="sca_inmueble" type="text" placeholder="# Inmueble" class="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
              </div>
              <div class="relative flex items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[18px] pointer-events-none">location_on</span>
                <input name="sca_direccion" type="text" placeholder="Dirección" class="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
              </div>
              <div class="relative flex items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[18px] pointer-events-none">person</span>
                <input name="sca_propietario" type="text" placeholder="Propietario" class="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
              </div>
              <div class="relative flex items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[18px] pointer-events-none">badge</span>
                <input name="sca_arrendatario" type="text" placeholder="Arrendatario" class="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
              </div>
              <div>
                <select name="sca_per_page" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 focus:border-slate-400 transition-all">
                  <option value="30">Mostrar: 30 filas</option>
                  <option value="60">Mostrar: 60 filas</option>
                  <option value="100">Mostrar: 100 filas</option>
                </select>
              </div>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
              <button class="scm-btn-secondary px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 hover:text-slate-900 transition-all" type="button" data-contract-clear>
                Limpiar
              </button>
              <button class="scm-btn-primary px-4 py-2 rounded-xl text-xs font-semibold text-white bg-[#0f1e36] hover:bg-[#162846] shadow-xs transition-all flex items-center gap-1.5" type="submit">
                <span class="material-symbols-outlined text-[16px]">filter_alt</span>
                <span>Filtrar</span>
              </button>
              <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
            </div>
          </form>

          <div data-contract-table class="w-full overflow-x-auto rounded-xl border border-slate-200">
            <div class="scm-table-wrap p-8 text-center text-slate-400 text-sm">
              Selecciona esta vista para cargar contratos.
            </div>
          </div>
          <div class="scm-pagination flex items-center justify-between pt-2" data-contract-pagination></div>
        </div>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  // KPI fragments --------------------------------------------------------------

  public function renderPreventivasKpis(int $count, string $corte, string $empleado = ''): string
  {
    return '<div class="scm-kpi">'
      . '<div class="scm-kpi-label">Corte</div>'
      . '<div class="scm-kpi-value" id="spp-kpi-corte" style="font-size:18px;">' . esc_html($corte) . '</div>'
      . '</div>'
      . '<div class="scm-kpi">'
      . '<div class="scm-kpi-label">Pendientes filtrados</div>'
      . '<div class="scm-kpi-value" id="spp-kpi-count2">' . esc_html((string) $count) . '</div>'
      . '</div>';
  }

  public function renderServiciosPublicosKpis(int $count, string $corte): string
  {
    return '<div class="scm-kpi">'
      . '<div class="scm-kpi-label">Corte</div>'
      . '<div class="scm-kpi-value" id="rsp-kpi-corte" style="font-size:18px;">' . esc_html($corte) . '</div>'
      . '</div>'
      . '<div class="scm-kpi">'
      . '<div class="scm-kpi-label">Pendientes filtrados</div>'
      . '<div class="scm-kpi-value" id="rsp-kpi-count2">' . esc_html((string) $count) . '</div>'
      . '</div>';
  }

  public function renderReportesAdministrativosTable(array $items): string
  {
    if (empty($items)) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay reportes administrativos para los filtros actuales.</p></div>';
    }

    $html = '<div class="scm-table-wrap">'
      . '<table class="scm-table scm-table-prev">'
      . '<thead><tr>'
      . '<th>ID</th><th>Fecha</th><th>Categoria</th><th>Descripcion</th>'
      . '<th>Valor</th><th>Inmueble</th><th>Contrato</th><th>Funcionario</th><th>Arrendatario</th><th>Ticket</th><th>Acciones</th>'
      . '</tr></thead><tbody>';

    foreach ($items as $item) {
      $row = (array) ($item['row'] ?? []);
      $preId = (int) ($row['_ID'] ?? 0);
      $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
      $ticketUrl = trim((string) ($item['ticket_url'] ?? ''));
      $valor = (int) ($row['valor'] ?? 0);
      $estado = strtolower(trim((string) ($row['estado'] ?? '')));

      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">' . esc_html((string) $preId) . '</span></td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->fmt($this->ts($row['fecha'] ?? null))) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['categoria'] ?? '-')) . '</td>';
      $html .= '<td style="max-width:280px;">' . esc_html((string) ($row['descripcion'] ?? '-')) . '</td>';
      $html .= '<td>$' . esc_html(number_format($valor, 0, ',', '.')) . '</td>';
      $html .= '<td><span class="scm-inmueble-badge">' . esc_html((string) ($row['inmueble'] ?? '-')) . '</span></td>';
      $html .= '<td>' . esc_html((string) ($row['contrato'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['creador'] ?? $row['id_empleado'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html($ticketRef !== '' ? $ticketRef : '-') . '</td>';
      $html .= '<td class="scm-pending-action-cell" style="display:flex;gap:8px;flex-wrap:wrap;">';
      if ($ticketUrl !== '') {
        $html .= '<button type="button" class="scm-pending-action-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($ticketUrl) . '" data-iframe-title="Ver ticket">Ver ticket</button>';
      }
      if ($estado === 'pendiente') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;" data-sra-action="approve" data-sra-id="' . esc_attr((string) $preId) . '">Aprobar</button>';
        $html .= '<button type="button" class="scm-pending-action-btn" data-sra-action="reject" data-sra-id="' . esc_attr((string) $preId) . '">No Aprobar</button>';
      }
      $html .= '</td>';
      $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
  }

  // Table fragments ------------------------------------------------------------

  public function renderPreventivasTable(array $items, string $empleado = ''): string
  {
    if (empty($items)) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay contratos pendientes con los filtros actuales.</p></div>';
    }

    $html = '<div class="scm-table-wrap">'
      . '<table class="scm-table scm-table-prev">'
      . '<thead><tr>'
      . '<th>Contrato</th><th>Estado</th><th>Inmueble</th><th>Direccion</th>'
      . '<th>Propietario</th><th>Arrendatario</th>'
      . '<th>Inicio</th><th>Fin</th><th>Entrega</th>'
      . '<th>Ult. revision</th><th>Sig. revision</th><th>Acciones</th>'
      . '</tr></thead><tbody>';

    foreach ($items as $item) {
      $row  = (array) ($item['row']  ?? []);
      $link = (string) ($item['link'] ?? '#');
      $estadoContrato = trim((string) ($row['estado'] ?? ''));
      $estadoNorm = strtolower($estadoContrato);
      $estadoClass = $estadoNorm === 'entregado'
        ? 'scm-contract-state--active'
        : ($estadoNorm === 'recibido' ? 'scm-contract-state--closed' : 'scm-contract-state--neutral');
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">'   . esc_html((string) ($row['contrato']  ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td><span class="scm-contract-state ' . esc_attr($estadoClass) . '">' . esc_html($estadoContrato !== '' ? $estadoContrato : '-') . '</span></td>';
      $html .= '<td><span class="scm-inmueble-badge">' . esc_html((string) ($row['inmueble']  ?? '-')) . '</span></td>';
      $html .= '<td style="max-width:200px;">'         . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td>'                                  . esc_html((string) ($row['propietario']  ?? '-')) . '</td>';
      $html .= '<td>'                                  . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['inicio_contrato'] ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['fin_contrato']    ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['fecha_entrega']   ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt((int) ($item['ultima'] ?? 0))) . '</td>';
      $html .= '<td class="scm-date-cell scm-date-warn">' . esc_html($this->fmt((int) ($item['due'] ?? 0))) . '</td>';

      // Actions cell: Ver ticket (link) if active ticket this year, else Crear ticket
      $ticket   = $item['ticket'] ?? null;
      $ticketId = $ticket !== null ? trim((string) ($ticket['ticket_id'] ?? '')) : '';
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
      $estado = strtolower(trim((string) ($row['estado'] ?? '')));
      $allTickets = array_values(array_filter((array) ($item['tickets'] ?? []), 'is_array'));
      if (is_array($ticket)) {
        $activeTicketPk = trim((string) ($ticket['ticket_id'] ?? $ticket['_ID'] ?? ''));
        $hasActiveInList = false;
        foreach ($allTickets as $existingTicket) {
          $existingPk = trim((string) ($existingTicket['ticket_id'] ?? $existingTicket['_ID'] ?? ''));
          if ($activeTicketPk !== '' && $existingPk === $activeTicketPk) {
            $hasActiveInList = true;
            break;
          }
        }
        if (!$hasActiveInList) {
          array_unshift($allTickets, (array) $ticket);
        }
      }
      $ticketsCount = count($allTickets);
      $ticketsListId = 'scm-prev-tickets-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $contractPk ?: uniqid('', false));
      $html .= '<td class="scm-pending-action-cell">';
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-contract-received-btn"'
          . ' data-scm-mark-contract-received data-contract-context="preventiva"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Contrato recibido</button>';
      }
      if ($ticketId !== '') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-btn-case"'
          . $this->preventivaTicketCaseAttrs((array) $ticket, $row, (int) ($item['ultima'] ?? 0), (int) ($item['due'] ?? 0))
          . '>'
          . 'Ver ticket</button>';
      } else {
        $html .= '<button type="button" class="scm-pending-action-btn"'
          . ' data-scm-open-admin-ticket data-ticket-mode="preventiva"'
          . ' data-ticket-title="Crear ticket preventivo"'
          . $this->contractTicketAttrs($row)
          . '>'
          . 'Crear ticket</button>';
      }
      if ($ticketsCount > 0) {
        $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;"'
          . ' data-scm-toggle-preventiva-tickets data-target="' . esc_attr($ticketsListId) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Ver tickets (' . esc_html((string) $ticketsCount) . ')</button>';
      }
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn"'
          . ' data-scm-postpone-preventiva data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Pasar a próximo año</button>';
      }
      $html .= '</td>';

      $html .= '</tr>';
      if ($ticketId !== '') {
        $html .= '<tr class="scm-tl-row" style="display:none;"><td colspan="12">'
          . $this->renderPreventivaTicketCaseSource((array) $ticket, $row)
          . '</td></tr>';
      }
      if ($ticketsCount > 0) {
        $html .= '<template id="' . esc_attr($ticketsListId) . '" data-scm-preventiva-tickets-template>'
          . $this->renderPreventivaTicketsList($allTickets, $row, (int) ($item['ultima'] ?? 0), (int) ($item['due'] ?? 0))
          . '</template>';
      }
    }

    return $html . '</tbody></table></div>';
  }

  public function renderContratosArrendamientoTable(array $items): string
  {
    if (empty($items)) {
      return '<div class="p-8 text-center text-slate-400 text-sm font-medium">No hay contratos con los filtros actuales.</div>';
    }

    $html = '<div class="overflow-x-auto w-full">'
      . '<table class="w-full text-left border-collapse text-xs">'
      . '<thead><tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">'
      . '<th class="py-3 px-4">Contrato</th>'
      . '<th class="py-3 px-4">Estado</th>'
      . '<th class="py-3 px-4">Inmueble</th>'
      . '<th class="py-3 px-4">Dirección</th>'
      . '<th class="py-3 px-4">Propietario</th>'
      . '<th class="py-3 px-4">Arrendatario</th>'
      . '<th class="py-3 px-4">Inicio</th>'
      . '<th class="py-3 px-4">Fin</th>'
      . '<th class="py-3 px-4 text-right">Acciones</th>'
      . '</tr></thead><tbody class="divide-y divide-slate-100 bg-white">';

    foreach ($items as $row) {
      $row = (array) $row;
      $estado = trim((string) ($row['estado'] ?? '-'));
      $estadoNorm = strtolower(trim($estado));
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));

      // Badge semántico de estado
      $badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
      if ($estadoNorm === 'entregado' || $estadoNorm === 'al dia' || $estadoNorm === 'vigente') {
        $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
      } elseif (str_contains($estadoNorm, 'entregar') || str_contains($estadoNorm, 'recibir') || str_contains($estadoNorm, 'proceso')) {
        $badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
      } elseif (str_contains($estadoNorm, 'desistido') || str_contains($estadoNorm, 'vencido') || str_contains($estadoNorm, 'cancelado')) {
        $badgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
      }

      $html .= '<tr class="hover:bg-slate-50/80 transition-colors">';
      $html .= '<td class="py-3 px-4 font-semibold text-slate-900"><span class="px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 font-mono text-[11px]">' . esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td class="py-3 px-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold border ' . $badgeClass . '">' . esc_html($estado !== '' ? $estado : '-') . '</span></td>';
      $html .= '<td class="py-3 px-4 font-medium text-slate-700">' . esc_html((string) ($row['inmueble'] ?? '-')) . '</td>';
      $html .= '<td class="py-3 px-4 text-slate-600 max-w-[200px] truncate" title="' . esc_attr((string) ($row['direccion'] ?? '')) . '">' . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td class="py-3 px-4 text-slate-700">' . esc_html((string) ($row['propietario'] ?? '-')) . '</td>';
      $html .= '<td class="py-3 px-4 text-slate-700 font-medium">' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="py-3 px-4 text-slate-500 whitespace-nowrap">' . esc_html($this->fmt($this->ts($row['inicio_contrato'] ?? null))) . '</td>';
      $html .= '<td class="py-3 px-4 text-slate-500 whitespace-nowrap">' . esc_html($this->fmt($this->ts($row['fin_contrato'] ?? null))) . '</td>';
      $html .= '<td class="py-3 px-4 text-right whitespace-nowrap">';
      $html .= '<div class="inline-flex items-center gap-1.5 justify-end">';

      if ($contractPk !== '' && $estadoNorm !== 'recibido') {
        $html .= '<button type="button" class="px-2.5 py-1 rounded-lg border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium text-xs transition-colors flex items-center gap-1"'
          . ' data-scm-mark-contract-received data-contract-context="contratos"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . '<span class="material-symbols-outlined text-[14px]">done_all</span><span>Recibido</span></button>';
      }

      $html .= '<button type="button" class="px-3 py-1 rounded-lg bg-[#0f1e36] text-white hover:bg-[#162846] font-medium text-xs shadow-2xs transition-all flex items-center gap-1"'
        . ' data-scm-open-admin-ticket data-ticket-mode="administrativo" data-ticket-title="Crear ticket administrativo"'
        . $this->contractTicketAttrs($row)
        . '><span class="material-symbols-outlined text-[14px]">add</span><span>Crear ticket</span></button>';

      $html .= '</div>';
      $html .= '</td>';
      $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
  }

  /** @param array<string,mixed> $pagination */
  public function renderContratosPagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($totalPages <= 1) {
      return $total > 0 ? '<span class="text-xs text-slate-500 font-medium">Mostrando ' . esc_html((string) $total) . ' contratos</span>' : '';
    }

    $html = '<div class="flex items-center justify-between w-full pt-3">';
    $html .= '<button type="button" class="scm-page-btn-contracts px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed shadow-2xs transition-all" data-page="' . esc_attr((string) max(1, $page - 1)) . '"' . ($page <= 1 ? ' disabled' : '') . '>← Anterior</button>';
    $html .= '<span class="text-xs text-slate-500 font-medium">Página <strong class="text-slate-800">' . esc_html((string) $page) . '</strong> de <strong class="text-slate-800">' . esc_html((string) $totalPages) . '</strong> <span class="text-slate-300 mx-1">|</span> ' . esc_html((string) $total) . ' contratos</span>';
    $html .= '<button type="button" class="scm-page-btn-contracts px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed shadow-2xs transition-all" data-page="' . esc_attr((string) min($totalPages, $page + 1)) . '"' . ($page >= $totalPages ? ' disabled' : '') . '>Siguiente →</button>';
    $html .= '</div>';
    return $html;
  }

  public function renderServiciosPublicosTable(array $items, array $configurationItems = []): string
  {
    $configurationHtml = empty($configurationItems) ? '' : '<details class="scm-public-services-unconfigured"><summary>Sin servicios configurados / por verificar (' . count($configurationItems) . ')</summary>'
      . '<p>Estos contratos no cuentan como revisiones pendientes. Puedes completar o corregir sus servicios. Este grupo no depende del filtro de mes.</p>'
      . $this->renderServiciosPublicosTable($configurationItems) . '</details>';
    if (empty($items)) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay contratos pendientes con los filtros actuales.</p></div>' . $configurationHtml;
    }

    $html = '<div class="scm-table-wrap">'
      . '<table class="scm-table scm-table-prev">'
      . '<thead><tr>'
      . '<th>Contrato</th><th>Inmueble</th><th>Direccion</th>'
      . '<th>Propietario</th><th>Arrendatario</th>'
      . '<th>Inicio</th><th>Fin</th><th>Entrega</th>'
      . '<th>Ult. revision</th><th>Sig. revision</th><th>Acciones</th>'
      . '</tr></thead><tbody>';

    foreach ($items as $item) {
      $row  = (array) ($item['row']  ?? []);
      $needsConfiguration = !empty($item['needs_service_configuration']);
      $estado = strtolower(trim((string) ($row['estado'] ?? '')));
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">'   . esc_html((string) ($row['contrato']  ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td><span class="scm-inmueble-badge">' . esc_html((string) ($row['inmueble']  ?? '-')) . '</span></td>';
      $html .= '<td style="max-width:200px;">'         . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td>'                                  . esc_html((string) ($row['propietario']  ?? '-')) . '</td>';
      $html .= '<td>'                                  . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['inicio_contrato'] ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['fin_contrato']    ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt($this->ts($row['fecha_entrega']   ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">'            . esc_html($this->fmt((int) ($item['ultima'] ?? 0))) . '</td>';
      $html .= '<td class="scm-date-cell scm-date-warn">' . ($needsConfiguration ? 'Sin configurar' : esc_html($this->fmt((int) ($item['due'] ?? 0)))) . '</td>';
      $html .= '<td class="scm-pending-action-cell">';
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-contract-received-btn"'
          . ' data-scm-mark-contract-received data-contract-context="servicios-publicos"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Contrato recibido</button>';
      }
      $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;"'
        . ' data-scm-open-public-services-review data-contract-id="' . esc_attr($contractPk) . '"'
        . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
        . ($needsConfiguration ? 'Configurar servicios' : 'Agregar revisión / editar servicios') . '</button></td>';
      $html .= '</tr>';
    }

    return $html . '</tbody></table></div>' . $configurationHtml;
  }

  /** @param array<string,mixed> $context */
  public function renderServiciosPublicosReviewForm(array $context): string
  {
    $contract = (array) ($context['contract'] ?? []);
    $services = (array) ($context['services'] ?? []);
    $employee = (array) ($context['employee'] ?? []);
    $contractId = (string) ($contract['_ID'] ?? '');
    $contractCode = (string) ($contract['contrato'] ?? $contractId);
    $reviewDate = (string) ($context['review_date'] ?? date('Y-m-d'));
    $currentMonth = (int) ($contract['mes_revision_servicios'] ?? 0);
    if ($currentMonth < 1 || $currentMonth > 12) {
      $currentMonth = (int) date('n');
    }
    $nextMonth = (($currentMonth + 3 - 1) % 12) + 1;

    ob_start();
?>
    <form class="scm-public-services-review-form" data-public-services-review-form autocomplete="off" novalidate>
      <input type="hidden" name="contract_id" value="<?php echo esc_attr($contractId); ?>">
      <input type="hidden" name="configuration_present" value="1">
      <div class="scm-public-services-review-summary">
        <div><span>Contrato</span><strong>#<?php echo esc_html($contractCode !== '' ? $contractCode : '-'); ?></strong></div>
        <div><span>Inmueble SIMI</span><strong>#<?php echo esc_html((string) ($contract['inmueble'] ?? '-')); ?></strong></div>
        <div><span>Fecha de revisión</span><strong><?php echo esc_html($reviewDate); ?></strong></div>
        <div><span>Funcionario autenticado</span><strong><?php echo esc_html((string) ($employee['nombre'] ?? '')); ?></strong><small>ID empleado: <?php echo esc_html((string) ($employee['id_empleado'] ?? '')); ?></small></div>
      </div>

      <div class="scm-public-services-review-address">
        <span>Dirección del inmueble</span>
        <strong><?php echo esc_html((string) ($contract['direccion'] ?? '-')); ?></strong>
        <small>Arrendatario: <?php echo esc_html((string) ($contract['arrendatario'] ?? '-')); ?></small>
      </div>

      <fieldset class="scm-public-services-review-services">
        <legend>Servicios del contrato</legend>
        <p class="scm-public-services-review-help">Activa los servicios que realmente tiene el inmueble y corrige sus identificadores o medidores. Desmarcar «Revisar ahora» conserva el servicio, pero no genera su acta. Desactivar el servicio lo retira de la configuración, sin borrar el historial.</p>
        <?php foreach ($services as $key => $service):
          $key = (string) $key;
          $service = (array) $service;
          $accountField = (string) ($service['account_field'] ?? '');
          $meterField = (string) ($service['meter_field'] ?? '');
          $statusField = (string) ($service['status_field'] ?? '');
          $amountField = (string) ($service['amount_field'] ?? '');
          $panelId = 'scm-public-service-fields-' . preg_replace('/[^a-z0-9_-]/i', '', $key);
          $configured = !empty($service['configured']);
        ?>
          <section class="scm-public-service-card<?php echo $configured ? ' is-selected' : ''; ?>" data-public-service-card="<?php echo esc_attr($key); ?>">
            <div class="scm-public-service-head">
              <label class="scm-public-service-toggle">
                <input type="checkbox" name="servicios_configurados[]" value="<?php echo esc_attr($key); ?>"<?php echo $configured ? ' checked' : ''; ?> aria-controls="<?php echo esc_attr($panelId); ?>" aria-expanded="<?php echo $configured ? 'true' : 'false'; ?>">
                <span class="scm-public-service-toggle-mark" aria-hidden="true"></span>
                <span><strong><?php echo esc_html((string) ($service['display_label'] ?? $service['label'] ?? $key)); ?></strong><small>El inmueble tiene este servicio</small></span>
              </label>
              <label class="scm-public-service-review-toggle">
                <input type="checkbox" name="servicios[]" value="<?php echo esc_attr($key); ?>"<?php echo $configured ? ' checked' : ''; ?>>
                <span><strong>Revisar ahora</strong><small>Generar su acta</small></span>
              </label>
            </div>
            <div class="scm-public-service-fields" id="<?php echo esc_attr($panelId); ?>">
              <label class="scm-seg-field">
                <span><?php echo esc_html((string) ($service['account_label'] ?? 'Cuenta')); ?> <b aria-hidden="true" data-public-service-required>*</b></span>
                <input type="text" name="<?php echo esc_attr($accountField); ?>" value="<?php echo esc_attr((string) ($service['account'] ?? '')); ?>" maxlength="180" data-public-service-account>
              </label>
              <label class="scm-seg-field">
                <span>Número de medidor <b aria-hidden="true" data-public-service-required>*</b></span>
                <input type="text" name="<?php echo esc_attr($meterField); ?>" value="<?php echo esc_attr((string) ($service['meter'] ?? '')); ?>" maxlength="180" data-public-service-meter>
              </label>
              <label class="scm-seg-field" data-public-service-review-only>
                <span>Resultado en tiempo <b aria-hidden="true">*</b></span>
                <select name="<?php echo esc_attr($statusField); ?>" required data-public-service-status>
                  <option value="">Selecciona un resultado</option>
                  <option value="Al dia">Al día</option>
                  <option value="30 dias">30 días</option>
                  <option value="60 dias">60 días</option>
                  <option value="Estado critico">Estado crítico</option>
                </select>
              </label>
              <label class="scm-seg-field" data-public-service-review-only>
                <span>Valor reportado (COP) <b aria-hidden="true">*</b></span>
                <input type="text" name="<?php echo esc_attr($amountField); ?>" value="0" inputmode="numeric" pattern="[0-9.$, ]+" required data-public-service-amount>
                <small>Usa 0 si el servicio está al día.</small>
              </label>
            </div>
          </section>
        <?php endforeach; ?>
      </fieldset>

      <aside class="scm-public-services-review-notice">
        <strong>Al guardar</strong>
        <span data-public-services-review-notice data-review-text="Se actualizarán siempre los servicios y sus datos. Además, se creará la revisión, se generarán las actas marcadas y se encolarán los correos. El próximo mes será <?php echo esc_attr($this->monthName($nextMonth)); ?>." data-config-text="Se actualizarán los servicios y sus datos, con historial del funcionario. Como no hay servicios marcados para revisar, no se crearán actas ni correos y no cambiará el próximo mes.">Se actualizarán siempre los servicios y sus datos. Además, se creará la revisión, se generarán las actas marcadas y se encolarán los correos. El próximo mes será <?php echo esc_html($this->monthName($nextMonth)); ?>.</span>
      </aside>

      <div class="scm-public-services-review-error" role="alert" aria-live="assertive" hidden></div>
      <div class="scm-public-services-review-actions">
        <button type="button" class="scm-btn-secondary" data-public-services-review-close>Cancelar</button>
        <button type="submit" class="scm-btn-primary" data-public-services-review-submit>Guardar revisión y generar actas</button>
      </div>
    </form>
<?php
    return (string) ob_get_clean();
  }

  // Helpers --------------------------------------------------------------------

  /** @param array<string,mixed> $row */
  private function contractTicketAttrs(array $row): string
  {
    $map = [
      'contractPk' => (string) ($row['_ID'] ?? ''),
      'contractCode' => (string) ($row['contrato'] ?? ''),
      'contractState' => (string) ($row['estado'] ?? ''),
      'idInmueble' => (string) ($row['id_inmueble'] ?? ''),
      'inmueble' => (string) ($row['inmueble'] ?? ''),
      'direccion' => (string) ($row['direccion'] ?? ''),
      'barrio' => (string) ($row['barrio'] ?? ''),
      'idArrendatario' => (string) ($row['id_arrendatario'] ?? ''),
      'arrendatario' => (string) ($row['arrendatario'] ?? ''),
      'correoArrendatario' => (string) ($row['correo_arrendatario'] ?? ''),
      'celularArrendatario' => (string) ($row['celular_arrendatario'] ?? ''),
      'idPropietario' => (string) ($row['id_propietario'] ?? ''),
      'propietario' => (string) ($row['propietario'] ?? ''),
      'correoPropietario' => (string) ($row['correo_propietario'] ?? ''),
      'celularPropietario' => (string) ($row['celular_propietario'] ?? ''),
      'idSucursal' => (string) ($row['id_sucursal'] ?? $row['sucursal'] ?? ''),
      'idInventario' => (string) ($row['id_inventario'] ?? ''),
      'registroFotografico' => (string) ($row['registro_fotografico'] ?? ''),
      'fechaFinalContrato' => (string) ($row['fin_contrato'] ?? ''),
    ];

    $attrs = '';
    foreach ($map as $key => $value) {
      $attr = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $key) ?: $key);
      $attrs .= ' data-' . esc_attr($attr) . '="' . esc_attr($value) . '"';
    }
    return $attrs;
  }

  /** @param array<int,array<string,mixed>> $tickets @param array<string,mixed> $contractRow */
  private function renderPreventivaTicketsList(array $tickets, array $contractRow, int $ultimaTs, int $dueTs): string
  {
    if (empty($tickets)) {
      return '<div class="scm-case-history-empty">Este contrato no tiene tickets preventivos registrados.</div>';
    }

    $html = '<div class="scm-preventiva-ticket-list">'
      . '<strong>Tickets preventivos del contrato</strong>'
      . '<div class="scm-table-wrap"><table class="scm-table scm-table-prev">'
      . '<thead><tr><th>Ticket</th><th>Estado</th><th>Estado administrativo</th><th>Fecha</th><th>Asunto</th><th>Acciones</th></tr></thead><tbody>';

    foreach ($tickets as $ticket) {
      $ticket = (array) $ticket;
      $ticketPk = trim((string) ($ticket['ticket_id'] ?? $ticket['_ID'] ?? ''));
      if ($ticketPk === '') {
        continue;
      }
      $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketPk));
      $createdTs = $this->ts($ticket['cct_created'] ?? $ticket['fecha'] ?? null);
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">' . esc_html($ticketLabel !== '' ? $ticketLabel : $ticketPk) . '</span></td>';
      $html .= '<td>' . esc_html(trim((string) ($ticket['estado'] ?? '')) ?: '-') . '</td>';
      $html .= '<td>' . esc_html(trim((string) ($ticket['estado_administrativo'] ?? '')) ?: '-') . '</td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->fmt($createdTs)) . '</td>';
      $html .= '<td>' . esc_html(trim((string) ($ticket['asunto'] ?? 'REVISION PREVENTIVA')) ?: '-') . '</td>';
      $html .= '<td><button type="button" class="scm-pending-action-btn scm-btn-case"'
        . $this->preventivaTicketCaseAttrs($ticket, $contractRow, $ultimaTs, $dueTs)
        . '>Ver ticket</button></td>';
      $html .= '</tr>';
      $html .= '<tr class="scm-tl-row" style="display:none;"><td colspan="6">'
        . $this->renderPreventivaTicketCaseSource($ticket, $contractRow)
        . '</td></tr>';
    }

    return $html . '</tbody></table></div></div>';
  }

  private function renderPreventivaTicketCaseSource(array $ticket, array $contractRow = []): string
  {
    $descripcion = trim((string) ($ticket['descripcion'] ?? ''));
    $documentsHtml = $this->renderPendingTicketAttachmentsSection([$ticket['imagenes'] ?? '', $ticket['imagen'] ?? '', $ticket['evidencia'] ?? ''], $ticket['archivos'] ?? '', 'scm-sec-documentos');
    $contratoData = is_array($ticket['_scm_contrato_data'] ?? null) ? $ticket['_scm_contrato_data'] : [];
    $inmuebleData = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    if (empty($contratoData)) {
      $contratoData = $this->preventivaContractFallbackData($ticket, $contractRow);
    }
    if (empty($inmuebleData)) {
      $inmuebleData = $this->preventivaPropertyFallbackData($ticket, $contractRow);
    }
    $historialItems = is_array($ticket['_scm_historial_items'] ?? null) ? $ticket['_scm_historial_items'] : [];
    $seguimientosItems = is_array($ticket['_scm_seguimientos_ticket'] ?? null) ? $ticket['_scm_seguimientos_ticket'] : [];
    $notasItems = is_array($ticket['_scm_notas_ticket'] ?? null) ? $ticket['_scm_notas_ticket'] : [];
    $historialInmuebleItems = is_array($ticket['_scm_historial_inmueble'] ?? null) ? $ticket['_scm_historial_inmueble'] : [];
    $ticketPk = (int) ($ticket['ticket_id'] ?? $ticket['_ID'] ?? 0);
    $cotId = trim((string) ($ticket['id_cotizacion_mantenimiento'] ?? $ticket['cot_id'] ?? $ticket['id_cotizacion'] ?? ''));
    $cotEstado = strtolower(trim((string) ($ticket['estado_cotizacion_mantenimiento'] ?? $ticket['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
    $hasCotizacionPendiente = $cotId !== '' && in_array($cotEstado, ['', 'esperando respuesta'], true);

    $html = '';
    if ($descripcion !== '') {
      $html .= '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">' . wp_kses_post($descripcion) . '</div></div>';
    }
    if ($html === '') {
      $html = '<div class="scm-case-description"><strong>Detalle del caso:</strong><div class="scm-case-description-content">Ticket preventivo creado desde contratos pendientes.</div></div>';
    }
    $seguimientoHtml = '<div class="scm-seg-readonly">No se pudo cargar el formulario de seguimiento en este momento.</div>';
    try {
      $seguimientoHtml = (new \SCM\Views\SeguimientoFormView())->render($ticketPk, \SCM\Core\Auth::isLoggedIn(), $hasCotizacionPendiente);
    } catch (\Throwable $exception) {
      error_log('[pending.preventiva.followup-form] ' . $exception->getMessage());
    }
    $html .= '<div class="scm-seg-wrap">' . $seguimientoHtml . '</div>';
    $html .= $this->renderPendingHistorialBlock($historialItems);
    $html .= $this->renderPendingRecordSection('Seguimientos realizados', $seguimientosItems, '', ['evidencia' => 'Evidencia']);
    $html .= $this->renderPendingRecordSection('Notas del ticket', $notasItems);
    $html .= '<div class="scm-case-action-buttons">';
    if ($documentsHtml !== '') {
      $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-documentos">Adjuntos del caso</button>';
    }
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button>';
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button>';
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
    $html .= '</div>';
    $html .= '<div class="scm-case-hidden-sections" style="display:none;">';
    if ($documentsHtml !== '') {
      $html .= $documentsHtml;
    }
    $html .= $this->renderPendingSingleRecordSection('Contrato', $contratoData, 'scm-sec-contrato');
    $html .= $this->renderPendingSingleRecordSection('Inmueble', $inmuebleData, 'scm-sec-inmueble');
    $html .= $this->renderPendingRecordSection('Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
    $html .= '</div>';
    return $html;
  }

  private function preventivaTicketCaseAttrs(array $ticket, array $contractRow, int $ultimaTs, int $dueTs): string
  {
    $ticketPk = trim((string) ($ticket['ticket_id'] ?? $ticket['_ID'] ?? ''));
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketPk));
    $estado = trim((string) ($ticket['estado'] ?? ''));
    $estadoAdmin = trim((string) ($ticket['estado_administrativo'] ?? ''));
    $asunto = trim((string) ($ticket['asunto'] ?? 'REVISION PREVENTIVA'));
    $contrato = trim((string) ($ticket['contrato'] ?? $contractRow['contrato'] ?? $contractRow['_ID'] ?? ''));
    $inmueble = trim((string) ($ticket['inmueble'] ?? $contractRow['inmueble'] ?? ''));
    $direccion = trim((string) ($ticket['direccion'] ?? $contractRow['direccion'] ?? ''));
    $barrio = trim((string) ($ticket['barrio'] ?? $contractRow['barrio'] ?? ''));
    $empleado = trim((string) ($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? $ticket['id_empleado'] ?? ''));
    $inmuebleData = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    $propertyDataId = trim((string) ($inmuebleData['_ID'] ?? $inmuebleData['id_inmueble_data'] ?? ''));
    $propertyGoogleMaps = trim((string) ($inmuebleData['ubicacion_google_maps'] ?? ''));
    $cotizacionId = trim((string) ($ticket['id_cotizacion_mantenimiento'] ?? $ticket['cot_id'] ?? $ticket['id_cotizacion'] ?? ''));
    $cotizacionUrl = $cotizacionId !== '' ? \SCM\App\SuCasaControlServiciosInmobiliarios::signedMaintenanceQuotePublicUrl((int) $cotizacionId) : '';
    $createdTs = $this->ts($ticket['cct_created'] ?? $ticket['fecha'] ?? null);
    $updatedTs = $this->ts($ticket['fecha_actualizacion'] ?? null);
    $total = $createdTs > 0 ? $this->durationSince($createdTs) : '';
    $sinActualizar = ($updatedTs > 0 || $createdTs > 0) ? $this->durationSince($updatedTs > 0 ? $updatedTs : $createdTs) : '';
    $estadoNorm = strtolower(trim($estado));
    $statusBucket = 'abiertos';
    if (in_array($estadoNorm, ['cerrado', 'cerrada', 'finalizado', 'finalizada'], true)) {
      $statusBucket = 'cerrados';
    } elseif (in_array($estadoNorm, ['postergado', 'postergada'], true)) {
      $statusBucket = 'postergados';
    }

    return ' data-ticket="' . esc_attr($ticketLabel !== '' ? $ticketLabel : $ticketPk) . '"'
      . ' data-ticket-pk="' . esc_attr($ticketPk) . '"'
      . ' data-asunto="' . esc_attr($asunto !== '' ? $asunto : '-') . '"'
      . ' data-estado="' . esc_attr($estado !== '' ? $estado : '-') . '"'
      . ' data-admin="' . esc_attr($estadoAdmin !== '' ? $estadoAdmin : '-') . '"'
      . ' data-prioridad="' . esc_attr(trim((string) ($ticket['prioridad'] ?? '')) ?: '-') . '"'
      . ' data-magnitud-caso="' . esc_attr(trim((string) ($ticket['magnitud_caso'] ?? '')) ?: '-') . '"'
      . ' data-perturbacion="' . esc_attr((string) ($ticket['perturbacion'] ?? '')) . '"'
      . ' data-justificacion-perturbacion="' . esc_attr((string) ($ticket['justificacion_perturbacion'] ?? '')) . '"'
      . ' data-valor-bonificacion="' . esc_attr((string) ($ticket['valor_bonificacion'] ?? '')) . '"'
      . ' data-area-afectada="' . esc_attr((string) ($ticket['area_afectada'] ?? '')) . '"'
      . ' data-resumen-calculo-perturbacion="' . esc_attr((string) ($ticket['resumen_calculo_perturbacion'] ?? '')) . '"'
      . ' data-contrato="' . esc_attr($contrato !== '' ? ('#' . ltrim($contrato, '#')) : '-') . '"'
      . ' data-inmueble="' . esc_attr($inmueble !== '' ? $inmueble : '-') . '"'
      . ' data-id-inmueble-web="' . esc_attr(trim((string) ($ticket['id_inmueble'] ?? $contractRow['id_inmueble'] ?? '')) ?: '-') . '"'
      . ' data-id-inmueble-data="' . esc_attr($propertyDataId) . '"'
      . ' data-ubicacion-google-maps="' . esc_attr($propertyGoogleMaps) . '"'
      . ' data-barrio="' . esc_attr($barrio !== '' ? $barrio : '-') . '"'
      . ' data-direccion="' . esc_attr($direccion !== '' ? $direccion : '-') . '"'
      . ' data-creado="' . esc_attr($createdTs > 0 ? $this->fmt($createdTs) : '-') . '"'
      . ' data-empleado="' . esc_attr($empleado !== '' ? $empleado : '-') . '"'
      . ' data-empleado-id="' . esc_attr((string) ($ticket['id_empleado'] ?? '')) . '"'
      . ' data-propietario="' . esc_attr((string) ($ticket['propietario'] ?? $contractRow['propietario'] ?? '')) . '"'
      . ' data-correo-propietario="' . esc_attr((string) ($ticket['correo_propietario'] ?? '')) . '"'
      . ' data-celular-propietario="' . esc_attr((string) ($ticket['celular_propietario'] ?? '')) . '"'
      . ' data-indicativo-propietario="' . esc_attr((string) ($ticket['indicativo_propietario'] ?? '')) . '"'
      . ' data-arrendatario="' . esc_attr((string) ($ticket['arrendatario'] ?? $contractRow['arrendatario'] ?? '')) . '"'
      . ' data-correo-arrendatario="' . esc_attr((string) ($ticket['correo_arrendatario'] ?? '')) . '"'
      . ' data-celular-arrendatario="' . esc_attr((string) ($ticket['celular_arrendatario'] ?? '')) . '"'
      . ' data-indicativo-arrendatario="' . esc_attr((string) ($ticket['indicativo_arrendatario'] ?? '')) . '"'
      . ' data-id-revision-preventiva="' . esc_attr((string) ($ticket['id_revision_preventiva'] ?? '')) . '"'
      . ' data-id-revision-correctiva="' . esc_attr((string) ($ticket['id_revision_correctiva'] ?? '')) . '"'
      . ' data-prev-encontro-danos="' . esc_attr((string) ($ticket['_scm_prev_encontro_danos'] ?? $ticket['se_encontraron_danos'] ?? $ticket['encontro_danos'] ?? '')) . '"'
      . ' data-cotizacion-id="' . esc_attr($cotizacionId) . '"'
      . ' data-cotizacion-url="' . esc_attr($cotizacionUrl) . '"'
      . ' data-cot-estado="' . esc_attr((string) ($ticket['estado_cotizacion_mantenimiento'] ?? '')) . '"'
      . ' data-ejecucion="' . esc_attr($total) . '"'
      . ' data-sin-actualizar="' . esc_attr($sinActualizar) . '"'
      . ' data-tab-key="preventiva"'
      . ' data-status-bucket="' . esc_attr($statusBucket) . '"';
  }

  /** @param array<int,array<string,mixed>> $items */
  private function renderPendingHistorialBlock(array $items): string
  {
    return $this->renderPendingRecordSection('Historial del caso', $items);
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,string> $visibleFields */
  private function renderPendingRecordSection(string $title, array $items, string $sectionId = '', array $visibleFields = []): string
  {
    static $recordSeq = 0;
    $recordSeq++;
    $listId = 'scm-pending-record-list-' . $recordSeq;
    $sectionAttr = $sectionId !== '' ? ' id="' . esc_attr($sectionId) . '"' : '';
    $empty = $title === 'Historial del caso' ? 'Sin historial registrado.' : 'Sin registros.';
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . esc_html($title) . '</h4>';
    if (empty($items)) {
      return $html . '<p class="scm-case-history-empty">' . esc_html($empty) . '</p></section>';
    }

    $perPage = 10;
    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $html .= '<div class="scm-case-history-list" id="' . esc_attr($listId) . '" data-current-page="1">';
    $index = 0;
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $index++;
      $page = (int) ceil($index / $perPage);
      $itemStyle = $page === 1 ? '' : ' style="display:none;"';
      $detail = trim((string) ($item['observacion'] ?? $item['observacion_his'] ?? $item['respuesta'] ?? $item['descripcion'] ?? ''));
      $type = trim((string) ($item['tipo_reporte'] ?? $item['tipo_de_reporte_his'] ?? ''));
      if ($detail === '') {
        $detail = 'Sin detalle';
      }
      $html .= '<article class="scm-case-history-item scm-case-record-card" data-page="' . esc_attr((string) $page) . '"' . $itemStyle . '>';
      $html .= '<div class="scm-case-record-head"><div class="scm-case-record-title"><span class="scm-case-record-user-icon" aria-hidden="true"></span><strong>' . esc_html($this->pendingRecordAuthor($item)) . '</strong></div></div>';
      $html .= '<div class="scm-case-history-detail scm-case-record-detail">';
      if ($type !== '') {
        $html .= '<strong>' . esc_html($this->pendingDecodedText($type)) . ':</strong> ';
      }
      $html .= $this->pendingDetailContentHtml($detail) . '</div>';
      foreach ($visibleFields as $field => $label) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value === '') {
          continue;
        }
        $html .= '<div class="scm-case-history-detail"><p><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</p></div>';
      }
      $html .= $this->renderPendingCaseActionButtons($this->pendingHistoryItemButtons($item, $title));
      $html .= $this->renderPendingHistoryImages([$item['imagenes'] ?? '', $item['evidencia'] ?? '', $item['imagen'] ?? '']);
      $html .= $this->renderPendingHistoryDocuments($item['archivos'] ?? '');
      $html .= '<div class="scm-case-record-date"><span class="scm-case-record-date-icon" aria-hidden="true"></span><strong>' . esc_html($this->pendingRecordDate($item)) . '</strong></div>';
      $html .= '</article>';
    }
    $html .= '</div>';
    if ($totalPages > 1) {
      $firstEnd = min($perPage, $totalItems);
      $html .= '<div class="scm-history-pagination">';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($listId) . '" data-dir="prev" disabled>&lsaquo; Anterior</button>';
      $html .= '<span class="scm-history-page-status" data-target="' . esc_attr($listId) . '" data-total="' . esc_attr((string) $totalItems) . '" data-per-page="' . esc_attr((string) $perPage) . '">Mostrando 1-' . esc_html((string) $firstEnd) . ' de ' . esc_html((string) $totalItems) . ' | Pagina 1 de ' . esc_html((string) $totalPages) . '</span>';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($listId) . '" data-dir="next">Siguiente &rsaquo;</button>';
      $html .= '</div>';
    }
    return $html . '</section>';
  }

  /** @param array<string,mixed> $record */
  private function renderPendingSingleRecordSection(string $title, array $record, string $sectionId): string
  {
    $html = '<section class="scm-case-history" id="' . esc_attr($sectionId) . '"><h4>' . esc_html($title) . '</h4>';
    if (empty($record)) {
      return $html . '<p class="scm-case-history-empty">Sin datos.</p></section>';
    }
    $details = '';
    $fields = $this->pendingSingleRecordFields($title, $record);
    foreach ($fields as $key => $label) {
      if (!array_key_exists($key, $record)) {
        continue;
      }
      $value = $record[$key];
      if (is_array($value) || strpos((string) $key, '_scm_') === 0) {
        continue;
      }
      $text = $this->pendingRecordValue((string) $key, $value);
      if ($text === '') {
        continue;
      }
      $details .= '<p><strong>' . esc_html((string) $label) . ':</strong> ' . $this->pendingDetailValueHtml($text) . '</p>';
    }
    if ($details === '') {
      return $html . '<p class="scm-case-history-empty">Sin datos.</p></section>';
    }
    $buttons = $this->renderPendingCaseActionButtons($this->pendingHistoryItemButtons($record, $title));
    return $html . '<article class="scm-case-history-item"><div class="scm-case-history-detail">' . $details . '</div>' . $buttons . '</article></section>';
  }

  /** @param array<string,mixed> $record @return array<string,string> */
  private function pendingSingleRecordFields(string $title, array $record): array
  {
    if ($title === 'Contrato') {
      return [
        'contrato' => 'Contrato',
        'estado' => 'Estado',
        'tipo' => 'Tipo',
        'direccion' => 'Dirección',
        'inmueble' => 'Inmueble Simi',
        'id_inmueble' => 'Código inmueble web',
        'barrio' => 'Barrio',
        'ciudad' => 'Ciudad',
        'propietario' => 'Propietario',
        'celular_propietario' => 'Celular propietario',
        'correo_propietario' => 'Correo propietario',
        'arrendatario' => 'Arrendatario',
        'celular_arrendatario' => 'Celular arrendatario',
        'correo_arrendatario' => 'Correo arrendatario',
        'valor_canon' => 'Canon',
        'valor_administracion' => 'Administración',
        'tasa_administracion' => 'Tasa administración',
        'derechos_inmobiliarios' => 'Derechos inmobiliarios',
        'aseguradora' => 'Aseguradora',
        'numero_solicitud' => 'Número solicitud',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
        'id_contrato_mandato' => 'Contrato de mandato',
        'id_revision_preventiva' => 'Revisión preventiva',
        'id_revision_entrega' => 'Revisión de entrega',
        'id_revision_recibo' => 'Revisión de recibo',
        'id_cierre' => 'Hoja de cierre',
        'id_hoja_cierre' => 'Hoja de cierre',
        'inicio_contrato' => 'Inicio contrato',
        'fecha_entrega' => 'Fecha entrega',
        'fin_contrato' => 'Fin contrato',
        'registro_fotografico' => 'Registro fotográfico',
      ];
    }

    if ($title === 'Inmueble') {
      return [
        'codigo' => 'Código Simi',
        'inmueble' => 'Inmueble Simi',
        'id_inmueble' => 'Código inmueble web',
        'codigo_inmueble_web' => 'Código inmueble web',
        'direccion' => 'Dirección',
        'direccion_fisica' => 'Dirección física',
        'barrio' => 'Barrio',
        'ciudad' => 'Ciudad',
        'estado' => 'Estado',
        'tipo_inmueble' => 'Tipo',
        'tipo_negocio' => 'Negocio',
        'destinacion' => 'Destinación',
        'precio_arriendo' => 'Precio arriendo',
        'precio_venta' => 'Precio venta',
        'precio_admin' => 'Administración',
        'area_construida' => 'Área construida',
        'area_privada' => 'Área privada',
        'habitaciones' => 'Habitaciones',
        'banos' => 'Baños',
        'parqueaderos' => 'Parqueaderos',
        'estrato' => 'Estrato',
        'copropiedad' => 'Copropiedad',
        'propietario' => 'Propietario',
        'arrendatario' => 'Arrendatario',
        'matricula_inmobiliaria' => 'Matrícula inmobiliaria',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
        'id_contrato_mandato' => 'Contrato de mandato',
        'id_hoja_cierre' => 'Hoja de cierre',
        'id_contrato_arrendamiento' => 'Contrato de arrendamiento',
        'ubicacion_google_maps' => 'Google Maps',
        'ubicacion_openstreetmap' => 'OpenStreetMap',
      ];
    }

    $fields = [];
    foreach ($record as $key => $value) {
      if ((string) $key === '_ID' || strpos((string) $key, '_scm_') === 0 || is_array($value)) {
        continue;
      }
      $fields[(string) $key] = $this->pendingLabel((string) $key);
    }
    return $fields;
  }

  private function pendingRecordValue(string $key, $value): string
  {
    $text = trim((string) $value);
    if ($text === '') {
      return '';
    }
    if (in_array($key, ['fecha', 'fecha_entrega', 'inicio_contrato', 'fin_contrato', 'cct_created', 'cct_modified'], true)) {
      $ts = $this->ts($text);
      return $ts > 0 ? $this->fmt($ts) : $text;
    }
    return $text;
  }

  private function pendingDetailValueHtml(string $text): string
  {
    $decoded = $this->pendingDecodedText($text);
    if (filter_var($text, FILTER_VALIDATE_URL)) {
      return '<button type="button" class="scm-case-action-btn" data-scm-open-iframe data-iframe-url="' . esc_url($text) . '" data-iframe-title="Detalle">Abrir enlace</button>';
    }
    if ($this->pendingLooksLikeHtml($decoded)) {
      return wp_kses_post($decoded);
    }
    return esc_html($decoded);
  }

  private function pendingDetailContentHtml(string $text): string
  {
    $decoded = $this->pendingDecodedText($text);
    if ($this->pendingLooksLikeHtml($decoded)) {
      return wp_kses_post($decoded);
    }
    return wp_kses_post(nl2br(esc_html($decoded)));
  }

  private function pendingDecodedText(string $text): string
  {
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  private function pendingLooksLikeHtml(string $text): bool
  {
    return preg_match('/<\/?[a-z][^>]*>/i', $text) === 1;
  }

  /** @param array<int,array{url:string,label:string}> $buttons */
  private function renderPendingCaseActionButtons(array $buttons): string
  {
    if (empty($buttons)) {
      return '';
    }

    $html = '<div class="scm-case-actions">';
    $seen = [];
    foreach ($buttons as $button) {
      $url = trim((string) ($button['url'] ?? ''));
      $label = trim((string) ($button['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $html .= '<button type="button" class="scm-case-action-btn" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="' . esc_attr($label) . '">' . esc_html($label) . '</button>';
    }

    return $html . '</div>';
  }

  /** @param array<string,mixed> $item @return array<int,array{url:string,label:string}> */
  private function pendingHistoryItemButtons(array $item, string $context = ''): array
  {
    $buttons = [];
    foreach (\SCM\Support\HistoryLinkMap::idButtons() as $field => $meta) {
      if (in_array($context, ['Contrato', 'Inmueble', 'Historial del inmueble'], true) && $field === 'id_inmueble') {
        continue;
      }
      if (!array_key_exists($field, $item)) {
        continue;
      }

      $raw = trim((string) ($item[$field] ?? ''));
      if ($raw === '' || $raw === '-' || $raw === '0') {
        continue;
      }

      $values = preg_split('/[\s,;|]+/', $raw) ?: [];
      foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '-' || $value === '0') {
          continue;
        }

        $label = (string) ($meta['label'] ?? 'Ver detalle');
        $url = (string) ($meta['base'] ?? '') . rawurlencode($value);
        if ($field === 'id_estudio_aseguradora') {
          $ticketId = trim((string) ($item['id_ticket'] ?? $item['ticket'] ?? ''));
          if ($ticketId !== '') {
            $url .= '&id_ticket=' . rawurlencode($ticketId);
          }
        }

        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    $hojaCierre = trim((string) ($item['id_hoja_cierre'] ?? ''));
    if ($hojaCierre !== '' && $hojaCierre !== '-' && $hojaCierre !== '0') {
      $url = 'https://sucasainmobiliaria.com.co/hoja-de-cierre/?numero=' . rawurlencode($hojaCierre);
      $extraMap = [
        'id_inmueble' => 'id_inmueble',
        'id_inventario' => 'id_inmueble_data',
        'id_contrato' => 'id_contrato',
        'id_empleado' => 'id_empleado',
      ];
      foreach ($extraMap as $field => $param) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '' && $value !== '-' && $value !== '0') {
          $url .= '&' . $param . '=' . rawurlencode($value);
        }
      }
      $buttons[] = ['url' => $url, 'label' => 'Ver hoja de cierre'];
    }

    $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['observacion_his'] ?? $item['descripcion'] ?? '');
    if ($raw !== '') {
      foreach ($this->pendingLinksFromHtml($raw) as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));
        if ($label === '' || $url === '') {
          continue;
        }
        if (stripos($label, 'ver ') !== 0) {
          $label = 'Ver recurso';
        }
        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    if (in_array($context, ['Contrato', 'Inmueble'], true)) {
      $webId = trim((string) ($item['codigo_inmueble_web'] ?? $item['codigo'] ?? $item['id_inmueble'] ?? ''));
      if ($webId !== '' && $webId !== '-' && $webId !== '0') {
        $buttons[] = [
          'url' => 'https://sucasainmobiliaria.com.co/inmuebles/inmueble/' . rawurlencode($webId),
          'label' => 'Ver inmueble en web',
        ];
      }
    }

    $unique = [];
    $out = [];
    foreach ($buttons as $button) {
      $url = trim((string) ($button['url'] ?? ''));
      $label = trim((string) ($button['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }

    return $out;
  }

  /** @return array<int,array{url:string,label:string}> */
  private function pendingLinksFromHtml(string $raw): array
  {
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded === '') {
      return [];
    }
    if (!preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $decoded, $matches, PREG_SET_ORDER)) {
      return [];
    }

    $links = [];
    foreach ($matches as $match) {
      $href = trim((string) ($match[1] ?? ''));
      $labelRaw = trim((string) ($match[2] ?? ''));
      if ($href === '' || $labelRaw === '') {
        continue;
      }
      $label = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($labelRaw)));
      if ($label === '') {
        continue;
      }
      $links[] = ['url' => $href, 'label' => $label];
    }

    return $links;
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $contractRow */
  private function preventivaContractFallbackData(array $ticket, array $contractRow): array
  {
    return array_filter([
      'contrato' => $ticket['contrato'] ?? $contractRow['contrato'] ?? $contractRow['_ID'] ?? '',
      'id_contrato' => $ticket['id_contrato'] ?? $contractRow['_ID'] ?? '',
      'estado' => $contractRow['estado'] ?? '',
      'inmueble' => $ticket['inmueble'] ?? $contractRow['inmueble'] ?? '',
      'id_inmueble' => $ticket['id_inmueble'] ?? $contractRow['id_inmueble'] ?? '',
      'direccion' => $ticket['direccion'] ?? $contractRow['direccion'] ?? '',
      'barrio' => $ticket['barrio'] ?? $contractRow['barrio'] ?? '',
      'propietario' => $ticket['propietario'] ?? $contractRow['propietario'] ?? '',
      'arrendatario' => $ticket['arrendatario'] ?? $contractRow['arrendatario'] ?? '',
      'valor_canon' => $contractRow['valor_canon'] ?? '',
      'valor_administracion' => $contractRow['valor_administracion'] ?? '',
      'id_estudio_aseguradora' => $contractRow['id_estudio_aseguradora'] ?? '',
      'numero_solicitud' => $contractRow['numero_solicitud'] ?? '',
      'id_contrato_mandato' => $contractRow['id_contrato_mandato'] ?? '',
      'id_hoja_cierre' => $contractRow['id_hoja_cierre'] ?? $contractRow['id_cierre'] ?? '',
      'inicio_contrato' => $this->fmt($this->ts($contractRow['inicio_contrato'] ?? null)),
      'fin_contrato' => $this->fmt($this->ts($contractRow['fin_contrato'] ?? null)),
      'fecha_entrega' => $this->fmt($this->ts($contractRow['fecha_entrega'] ?? null)),
    ], static fn($value): bool => trim((string) $value) !== '');
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $contractRow */
  private function preventivaPropertyFallbackData(array $ticket, array $contractRow): array
  {
    return array_filter([
      'codigo' => $ticket['inmueble'] ?? $contractRow['inmueble'] ?? '',
      'codigo_inmueble_web' => $ticket['id_inmueble'] ?? $contractRow['id_inmueble'] ?? '',
      'direccion' => $ticket['direccion'] ?? $contractRow['direccion'] ?? '',
      'barrio' => $ticket['barrio'] ?? $contractRow['barrio'] ?? '',
      'propietario' => $ticket['propietario'] ?? $contractRow['propietario'] ?? '',
      'arrendatario' => $ticket['arrendatario'] ?? $contractRow['arrendatario'] ?? '',
      'id_estudio_aseguradora' => $contractRow['id_estudio_aseguradora'] ?? '',
      'id_contrato_mandato' => $contractRow['id_contrato_mandato'] ?? '',
      'id_hoja_cierre' => $contractRow['id_hoja_cierre'] ?? $contractRow['id_cierre'] ?? '',
    ], static fn($value): bool => trim((string) $value) !== '');
  }

  /** @param array<string,mixed> $item */
  private function pendingRecordAuthor(array $item): string
  {
    $author = trim((string) ($item['nombre'] ?? $item['funcionario'] ?? $item['reporte_realizado_por_his'] ?? ''));
    if ($author !== '') {
      return $author;
    }
    $authorId = trim((string) ($item['id_empleado'] ?? $item['cct_author_id'] ?? ''));
    return $authorId !== '' ? ('Usuario #' . $authorId) : 'Registro';
  }

  /** @param array<string,mixed> $item */
  private function pendingRecordDate(array $item): string
  {
    $ts = $this->ts($item['fecha'] ?? $item['cct_created'] ?? $item['cct_modified'] ?? null);
    return $ts > 0 ? $this->fmt($ts) : '-';
  }

  private function pendingLabel(string $key): string
  {
    static $labels = [
      '_ID' => 'ID',
      'cct_status' => 'Estado de publicación',
      'cct_created' => 'Creado',
      'cct_modified' => 'Modificado',
      'cct_author_id' => 'Autor',
      'id_ticket' => 'Ticket',
      'ticket_id' => 'Ticket',
      'id_tickets' => 'Ticket',
      'tickets_id' => 'Ticket',
      'id_ticket_mantenimiento' => 'Ticket mantenimiento',
      'ticket_pk' => 'Ticket',
      'id_contrato' => 'Contrato',
      'contrato' => 'Contrato',
      'id_inmueble' => 'Inmueble',
      'id_inmueble_data' => 'Código inmueble web',
      'codigo' => 'Código SIMI',
      'codigo_inmueble_web' => 'Código inmueble web',
      'tipo_reporte' => 'Tipo de reporte',
      'tipo_de_reporte_his' => 'Tipo de reporte',
      'observacion' => 'Observación',
      'observacion_his' => 'Observación',
      'descripcion' => 'Descripción',
      'respuesta' => 'Respuesta',
      'funcionario' => 'Funcionario',
      'reporte_realizado_por_his' => 'Reportado por',
      'id_empleado' => 'Funcionario',
      'id_coordinador' => 'Coordinador',
      'fecha' => 'Fecha',
      'nombre' => 'Nombre',
      'evidencia' => 'Evidencia',
      'archivos' => 'Archivos',
      'imagen' => 'Imagen',
      'imagenes' => 'Imagenes',
      'id_revision_preventiva' => 'Revisión preventiva',
      'id_revision_correctiva' => 'Revisión correctiva',
      'id_revision_entrega' => 'Revisión de entrega',
      'id_revision_recibo' => 'Revisión de recibo',
      'id_revision_sp' => 'Revisión servicios públicos',
      'id_revision_servicios_publicos' => 'Revisión servicios públicos',
      'id_cotizacion_mantenimiento' => 'Cotización mantenimiento',
      'id_cotizacion_comercial' => 'Cotización comercial',
      'id_acta_satisfaccion' => 'Acta de satisfacción',
      'id_acta_entrega' => 'Acta de entrega',
      'id_acta_revision' => 'Acta de revisión',
      'id_acta_revision_notificacion' => 'Notificación acta de revisión',
      'id_acta_recibo' => 'Acta de recibo',
      'id_ticket_danos_entrega' => 'Ticket daños entrega',
      'id_ticket_danos_recibo' => 'Ticket daños recibo',
      'id_hoja_cierre' => 'Hoja de cierre',
      'seguimiento_reparaciones' => 'Seguimiento de reparaciones',
    ];
    if (isset($labels[$key])) {
      return $labels[$key];
    }
    $label = str_replace('_', ' ', $key);
    $label = preg_replace('/\s+/', ' ', $label) ?: $label;
    $label = trim($label);
    if ($label === '') {
      return $key;
    }
    if (function_exists('mb_convert_case')) {
      return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords($label);
  }

  private function renderPendingHistoryImages($raw): string
  {
    $urls = $this->extractPendingAttachmentUrls($raw);
    if (empty($urls)) {
      return '';
    }
    $html = '<div class="scm-case-history-img">';
    foreach ($urls as $url) {
      $html .= '<button type="button" class="scm-case-attachment-image-btn" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="Imagen adjunta" style="background:none;border:0;padding:0;margin:0;cursor:zoom-in;"><img src="' . esc_url($url) . '" alt="Imagen adjunta" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:8px;margin-top:6px;"></button>';
    }
    return $html . '</div>';
  }

  private function renderPendingHistoryDocuments($raw): string
  {
    $docs = $this->extractPendingTicketDocuments($raw);
    if (empty($docs)) {
      return '';
    }
    $html = '<div class="scm-case-actions">';
    foreach ($docs as $doc) {
      $label = trim((string) ($doc['nombre_archivo'] ?? '')) ?: 'Ver documento';
      $url = $this->normalizePendingAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
      if ($url === '') {
        continue;
      }
      $html .= '<button type="button" class="scm-case-action-btn" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="' . esc_attr($label) . '">' . esc_html($label) . '</button>';
    }
    return $html . '</div>';
  }

  /** @return array<int,string> */
  private function extractPendingAttachmentUrls($raw): array
  {
    if (is_array($raw)) {
      $items = [];
      foreach ($raw as $entry) {
        if (is_array($entry)) {
          $items[] = $entry;
          continue;
        }
        $value = trim((string) $entry);
        if ($value === '') {
          continue;
        }
        $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        if (is_array($decoded)) {
          $items = array_merge($items, $decoded);
          continue;
        }
        $json = json_decode($value, true);
        if (is_array($json)) {
          $items = array_merge($items, $json);
          continue;
        }
        $items[] = $value;
      }
    } else {
      $value = trim((string) $raw);
      if ($value === '') {
        return [];
      }
      $items = [$value];
      $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
      if (is_array($decoded)) {
        $items = $decoded;
      } else {
        $json = json_decode($value, true);
        if (is_array($json)) {
          $items = $json;
        }
      }
    }

    $out = [];
    foreach ($items as $item) {
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['imagenes'] ?? $item['imagen'] ?? $item['evidencia'] ?? $item['archivo'] ?? $item['media_archivo'] ?? '')) : trim((string) $item);
      $url = $this->normalizePendingAttachmentUrl($url);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = $url;
      }
    }
    return $out;
  }

  private function renderPendingTicketAttachmentsSection($imageRaw, $documentRaw, string $sectionId): string
  {
    $images = $this->extractPendingAttachmentUrls($imageRaw);
    $docs = $this->extractPendingTicketDocuments($documentRaw);
    $fileDocs = [];
    foreach ($docs as $doc) {
      $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
      if ($url === '') {
        continue;
      }
      if ($this->isPendingImageAttachmentUrl($url)) {
        $images[] = $url;
      } else {
        $fileDocs[] = $doc;
      }
    }
    $images = array_values(array_unique($images));
    if (empty($images) && empty($fileDocs)) {
      return '';
    }

    $html = '<section class="scm-case-history scm-case-documents-section" id="' . esc_attr($sectionId) . '">';
    $html .= '<h4>Adjuntos del caso</h4>';
    if (!empty($images)) {
      $html .= '<div class="scm-case-history-img">';
      foreach ($images as $url) {
        $html .= '<button type="button" class="scm-case-attachment-image-btn" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="Imagen del caso" style="background:none;border:0;padding:0;margin:0;cursor:zoom-in;">'
          . '<img src="' . esc_url($url) . '" alt="Imagen del caso" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:8px;margin-top:6px;">'
          . '</button>';
      }
      $html .= '</div>';
    }
    if (!empty($fileDocs)) {
      $html .= '<div class="scm-case-document-grid">';
      foreach ($fileDocs as $doc) {
        $label = trim((string) ($doc['nombre_archivo'] ?? ''));
        $url = $this->normalizePendingAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
          continue;
        }
        if ($label === '') {
          $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
        }
        $html .= '<button type="button" class="scm-case-action-btn scm-case-document-link" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="' . esc_attr($label) . '">' . esc_html($label) . '</button>';
      }
      $html .= '</div>';
    }
    $html .= '</section>';
    return $html;
  }

  /** @return array<int,array{nombre_archivo:string,archivo:string,media_archivo:string}> */
  private function extractPendingTicketDocuments($raw): array
  {
    if (is_array($raw)) {
      $decoded = $raw;
    } else {
      $value = trim((string) $raw);
      if ($value === '') {
        return [];
      }
      $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
      if (!is_array($decoded)) {
        $json = json_decode($value, true);
        $decoded = is_array($json) ? $json : [['nombre_archivo' => '', 'archivo' => $value]];
      }
    }

    $out = [];
    foreach ($decoded as $doc) {
      $label = '';
      $url = '';
      if (is_array($doc)) {
        $label = trim((string) ($doc['nombre_archivo'] ?? $doc['title'] ?? $doc['label'] ?? ''));
        $url = $this->normalizePendingAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? $doc['url'] ?? '')));
      } else {
        $url = $this->normalizePendingAttachmentUrl(trim((string) $doc));
      }
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $out[] = ['nombre_archivo' => $label, 'media_archivo' => $url, 'archivo' => $url];
    }
    return $out;
  }

  private function normalizePendingAttachmentUrl(string $url): string
  {
    $url = trim($url);
    if ($url === '') {
      return '';
    }

    if (preg_match('/^\d+$/', $url) && function_exists('wp_get_attachment_url')) {
      $attachmentUrl = (string) wp_get_attachment_url((int) $url);
      if ($attachmentUrl !== '' && filter_var($attachmentUrl, FILTER_VALIDATE_URL)) {
        return $attachmentUrl;
      }
    }
    if (preg_match('/^\d+$/', $url)) {
      $attachmentUrl = $this->resolvePendingAttachmentUrlFromId((int) $url);
      if ($attachmentUrl !== '') {
        return $attachmentUrl;
      }
    }

    if (filter_var($url, FILTER_VALIDATE_URL)) {
      return $url;
    }
    if (strpos($url, 'file.php?') === 0 || strpos($url, 'legacy-file.php?') === 0) {
      return rtrim((string) SCM_BASE_URL, '/') . '/' . $url;
    }
    if (strpos($url, '/file.php?') === 0 || strpos($url, '/legacy-file.php?') === 0) {
      return rtrim((string) SCM_BASE_URL, '/') . $url;
    }
    $fileName = basename((string) parse_url($url, PHP_URL_PATH));
    if ($this->isSafePendingAttachmentName($fileName)) {
      return rtrim((string) SCM_BASE_URL, '/') . '/legacy-file.php?n=' . rawurlencode($fileName);
    }
    return $url;
  }

  private function resolvePendingAttachmentUrlFromId(int $attachmentId): string
  {
    if ($attachmentId <= 0) {
      return '';
    }

    try {
      $postsTable = $this->db->table('posts');
      $url = trim((string) ($this->db->getVar(
        "SELECT `guid` FROM `{$postsTable}` WHERE `ID` = ? AND TRIM(COALESCE(`guid`, '')) <> '' LIMIT 1",
        [$attachmentId]
      ) ?? ''));
      return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    } catch (\Throwable $e) {
      return '';
    }
  }

  private function isPendingImageAttachmentUrl(string $url): bool
  {
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    $query = (string) parse_url($url, PHP_URL_QUERY);
    if ($query !== '') {
      parse_str($query, $parts);
      if (!empty($parts['n'])) {
        $name = basename((string) $parts['n']);
      }
    }
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff'], true);
  }

  private function isSafePendingAttachmentName(string $fileName): bool
  {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName)) {
      return false;
    }
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'bmp', 'heic', 'heif', 'tif', 'tiff'], true);
  }

  private function durationSince(int $ts): string
  {
    if ($ts <= 0) {
      return '';
    }
    $seconds = max(0, time() - $ts);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    if ($days > 0) {
      return $days . 'd ' . $hours . 'h';
    }
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
      return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
  }

  private function monthName(int $month): string
  {
    $months = [
      '',
      'Enero',
      'Febrero',
      'Marzo',
      'Abril',
      'Mayo',
      'Junio',
      'Julio',
      'Agosto',
      'Septiembre',
      'Octubre',
      'Noviembre',
      'Diciembre'
    ];
    return $months[$month] ?? (string) $month;
  }

  private function ts($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function fmt(int $ts): string
  {
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }
}
