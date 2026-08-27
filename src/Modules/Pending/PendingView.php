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
          . ' onclick="scmOpenCase(this)">'
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
          . ' data-scm-toggle-preventiva-tickets data-target="' . esc_attr($ticketsListId) . '">'
          . 'Ver tickets (' . esc_html((string) $ticketsCount) . ')</button>';
      }
      if ($contractPk !== '' && $estado !== 'recibido') {
        $html .= '<button type="button" class="scm-pending-action-btn"'
          . ' data-scm-postpone-preventiva data-contract-id="' . esc_attr($contractPk) . '"'
          . ' data-contract-code="' . esc_attr($contractCode !== '' ? $contractCode : $contractPk) . '">'
          . 'Pasar a proximo ano</button>';
      }
      $html .= '</td>';

      $html .= '</tr>';
      if ($ticketId !== '') {
        $html .= '<tr class="scm-tl-row" style="display:none;"><td colspan="12">'
          . $this->renderPreventivaTicketCaseSource((array) $ticket, $row)
          . '</td></tr>';
      }
      if ($ticketsCount > 0) {
        $html .= '<tr id="' . esc_attr($ticketsListId) . '" class="scm-preventiva-ticket-list-row" style="display:none;"><td colspan="12">'
          . $this->renderPreventivaTicketsList($allTickets, $row, (int) ($item['ultima'] ?? 0), (int) ($item['due'] ?? 0))
          . '</td></tr>';
      }
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
        . ' onclick="scmOpenCase(this)">Ver ticket</button></td>';
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
    $documentsHtml = $this->renderPendingTicketDocumentsSection($ticket['archivos'] ?? '', 'scm-sec-documentos');
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
    $cotId = trim((string) ($ticket['id_cotizacion_mantenimiento'] ?? ''));
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
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button>';
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button>';
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
    if ($documentsHtml !== '') {
      $html .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-documentos">Ver documentos</button>';
    }
    $html .= '</div>';
    $html .= '<div class="scm-case-hidden-sections" style="display:none;">';
    $html .= $this->renderPendingSingleRecordSection('Contrato', $contratoData, 'scm-sec-contrato');
    $html .= $this->renderPendingSingleRecordSection('Inmueble', $inmuebleData, 'scm-sec-inmueble');
    $html .= $this->renderPendingRecordSection('Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
    $html .= $documentsHtml;
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
    $cotizacionId = trim((string) ($ticket['id_cotizacion_mantenimiento'] ?? ''));
    $cotizacionUrl = $cotizacionId !== '' ? ('https://sucasainmobiliaria.com.co/cotizacion-de-mantenimiento/?numero=' . rawurlencode($cotizacionId)) : '';
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
      $html .= $this->renderPendingHistoryImages($item['evidencia'] ?? $item['imagen'] ?? '');
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
    if (filter_var($text, FILTER_VALIDATE_URL)) {
      return '<a class="scm-case-action-btn" href="' . esc_url($text) . '" target="_blank" rel="noopener noreferrer">Abrir enlace</a>';
    }
    return esc_html($this->pendingDecodedText($text));
  }

  private function pendingDetailContentHtml(string $text): string
  {
    $decoded = $this->pendingDecodedText($text);
    return wp_kses_post(nl2br(esc_html($decoded)));
  }

  private function pendingDecodedText(string $text): string
  {
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
      $html .= '<a class="scm-case-action-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
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
    return ucwords(str_replace('_', ' ', $key));
  }

  private function renderPendingHistoryImages($raw): string
  {
    $urls = $this->extractPendingAttachmentUrls($raw);
    if (empty($urls)) {
      return '';
    }
    $html = '<div class="scm-case-history-img">';
    foreach ($urls as $url) {
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url($url) . '" alt="Imagen adjunta" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:8px;margin-top:6px;"></a>';
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
      $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
      if ($url === '') {
        continue;
      }
      $html .= '<a class="scm-case-action-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
    }
    return $html . '</div>';
  }

  /** @return array<int,string> */
  private function extractPendingAttachmentUrls($raw): array
  {
    $value = trim((string) $raw);
    if ($value === '') {
      return [];
    }
    $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
    if (!is_array($decoded)) {
      return filter_var($value, FILTER_VALIDATE_URL) ? [$value] : [];
    }
    $out = [];
    foreach ($decoded as $item) {
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['archivo'] ?? $item['media_archivo'] ?? '')) : trim((string) $item);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = $url;
      }
    }
    return $out;
  }

  private function renderPendingTicketDocumentsSection($raw, string $sectionId): string
  {
    $docs = $this->extractPendingTicketDocuments($raw);
    if (empty($docs)) {
      return '';
    }

    $html = '<section class="scm-case-history scm-case-documents-section" id="' . esc_attr($sectionId) . '">';
    $html .= '<h4>Documentos del caso</h4>';
    $html .= '<div class="scm-case-document-grid">';
    foreach ($docs as $doc) {
      $label = trim((string) ($doc['nombre_archivo'] ?? ''));
      $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      if ($label === '') {
        $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
      }
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="scm-case-action-btn scm-case-document-link">' . esc_html($label) . '</a>';
    }
    $html .= '</div></section>';
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
        $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? $doc['url'] ?? ''));
      } else {
        $url = trim((string) $doc);
      }
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $out[] = ['nombre_archivo' => $label, 'media_archivo' => $url, 'archivo' => $url];
    }
    return $out;
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
