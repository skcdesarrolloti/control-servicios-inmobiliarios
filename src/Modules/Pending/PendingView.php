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
          <div class="scm-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));">
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

  public function renderServiciosPublicosPanel(array $filters, array $items, int $count, string $corte): string
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
        <?php echo $this->renderServiciosPublicosTable($items); ?>
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
              <label for="sra_ticket">Ticket</label>
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
    <div class="scm-pending-wrap scm-contracts-wrap" data-scm-contracts>
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Contratos de Arrendamiento</h2>
          <p>Consulta contratos por estado y crea tickets administrativos sin salir del panel.</p>
        </div>
        <div>
          <div class="scm-pending-count" id="sca-kpi-count">0</div>
          <div class="scm-pending-count-label">contratos filtrados</div>
        </div>
      </div>

      <div class="scm-status-subtabs scm-contract-subtabs" role="tablist" aria-label="Contratos de arrendamiento">
        <?php foreach ($bucketDefs as $key => $def): ?>
          <button class="scm-status-topic-tab scm-contract-tab<?php echo $key === $firstKey ? ' active' : ''; ?>" type="button" data-contract-bucket="<?php echo esc_attr((string) $key); ?>">
            <?php echo esc_html((string) ($def['label'] ?? $key)); ?>
          </button>
        <?php endforeach; ?>
      </div>

      <?php foreach ($bucketDefs as $key => $def): $active = $key === $firstKey; ?>
        <div class="scm-contract-panel<?php echo $active ? ' active' : ''; ?>" data-contract-panel="<?php echo esc_attr((string) $key); ?>" data-scm-loaded="0">
          <div class="scm-filter-card">
            <h3>Filtros - <?php echo esc_html((string) ($def['label'] ?? $key)); ?></h3>
            <form method="post" autocomplete="off" class="sca_form">
              <input type="hidden" name="sca_page" value="1">
              <div class="scm-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));">
                <div class="scm-field"><label>Contrato</label><input name="sca_contrato" type="text" placeholder="Codigo"></div>
                <div class="scm-field"><label>Inmueble</label><input name="sca_inmueble" type="text" placeholder="# inmueble"></div>
                <div class="scm-field"><label>Direccion</label><input name="sca_direccion" type="text" placeholder="Direccion"></div>
                <div class="scm-field"><label>Propietario</label><input name="sca_propietario" type="text" placeholder="Nombre"></div>
                <div class="scm-field"><label>Arrendatario</label><input name="sca_arrendatario" type="text" placeholder="Nombre"></div>
                <div class="scm-field"><label>Por pagina</label><select name="sca_per_page"><option value="30">30</option><option value="60">60</option><option value="100">100</option></select></div>
              </div>
              <div class="scm-actions">
                <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
                <button class="scm-btn-secondary" type="button" data-contract-clear>Limpiar</button>
                <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
              </div>
            </form>
          </div>

          <div class="scm-kpis">
            <div class="scm-kpi">
              <div class="scm-kpi-label">Vista</div>
              <div class="scm-kpi-value" style="font-size:18px;"><?php echo esc_html((string) ($def['label'] ?? $key)); ?></div>
            </div>
            <div class="scm-kpi">
              <div class="scm-kpi-label">Contratos filtrados</div>
              <div class="scm-kpi-value" data-contract-count>0</div>
            </div>
          </div>

          <div data-contract-table>
            <div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">Selecciona esta vista para cargar contratos.</p></div>
          </div>
          <div class="scm-pagination" data-contract-pagination></div>
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
      $html .= '<td class="scm-pending-action-cell">';
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-contract-received-btn"'
          . ' data-scm-mark-contract-received data-contract-context="preventiva"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Contrato recibido</button>';
      }
      if ($ticketId !== '') {
        $viewUrl = esc_attr('https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($ticketId));
        $html .= '<button type="button" class="scm-pending-action-btn"'
          . ' data-scm-open-iframe data-no-emp-check data-iframe-url="' . $viewUrl . '" data-iframe-title="Ver ticket">'
          . 'Ver ticket</button>';
      } else {
        $html .= '<button type="button" class="scm-pending-action-btn"'
          . ' data-scm-open-admin-ticket data-ticket-mode="preventiva"'
          . ' data-ticket-title="Crear ticket preventivo"'
          . $this->contractTicketAttrs($row)
          . '>'
          . 'Crear ticket</button>';
      }
      $html .= '</td>';

      $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
  }

  public function renderContratosArrendamientoTable(array $items): string
  {
    if (empty($items)) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay contratos con los filtros actuales.</p></div>';
    }

    $html = '<div class="scm-table-wrap">'
      . '<table class="scm-table scm-table-prev scm-contracts-table">'
      . '<thead><tr>'
      . '<th>Contrato</th><th>Estado</th><th>Inmueble</th><th>Direccion</th><th>Propietario</th><th>Arrendatario</th><th>Inicio</th><th>Fin</th><th>Acciones</th>'
      . '</tr></thead><tbody>';

    foreach ($items as $row) {
      $row = (array) $row;
      $estado = trim((string) ($row['estado'] ?? '-'));
      $estadoNorm = strtolower(trim($estado));
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">' . esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td>' . esc_html($estado !== '' ? $estado : '-') . '</td>';
      $html .= '<td><span class="scm-inmueble-badge">' . esc_html((string) ($row['inmueble'] ?? '-')) . '</span></td>';
      $html .= '<td style="max-width:220px;">' . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['propietario'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->fmt($this->ts($row['inicio_contrato'] ?? null))) . '</td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->fmt($this->ts($row['fin_contrato'] ?? null))) . '</td>';
      $html .= '<td class="scm-pending-action-cell">';
      if ($contractPk !== '' && $estadoNorm !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-contract-received-btn"'
          . ' data-scm-mark-contract-received data-contract-context="contratos"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Contrato recibido</button>';
      }
      $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;"'
        . ' data-scm-open-admin-ticket data-ticket-mode="administrativo" data-ticket-title="Crear ticket administrativo"'
        . $this->contractTicketAttrs($row)
        . '>Crear ticket</button>';
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
      return $total > 0 ? '<span class="scm-page-info">Mostrando ' . esc_html((string) $total) . ' contratos</span>' : '';
    }

    $html = '<div class="scm-page-controls">';
    $html .= '<button type="button" class="scm-page-btn-contracts" data-page="' . esc_attr((string) max(1, $page - 1)) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button>';
    $html .= '<span class="scm-page-info">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | ' . esc_html((string) $total) . ' contratos</span>';
    $html .= '<button type="button" class="scm-page-btn-contracts" data-page="' . esc_attr((string) min($totalPages, $page + 1)) . '"' . ($page >= $totalPages ? ' disabled' : '') . '>Siguiente</button>';
    return $html . '</div>';
  }

  public function renderServiciosPublicosTable(array $items): string
  {
    if (empty($items)) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay contratos pendientes con los filtros actuales.</p></div>';
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
      $link = (string) ($item['link'] ?? '#');
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
      $html .= '<td class="scm-date-cell scm-date-warn">' . esc_html($this->fmt((int) ($item['due'] ?? 0))) . '</td>';
      $html .= '<td class="scm-pending-action-cell">';
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn scm-contract-received-btn"'
          . ' data-scm-mark-contract-received data-contract-context="servicios-publicos"'
          . ' data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Contrato recibido</button>';
      }
      $html .= '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;"'
        . ' data-scm-open-iframe data-iframe-url="' . esc_attr($link) . '" data-iframe-title="Agregar revision">'
        . 'Agregar revision</button></td>';
      $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
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
