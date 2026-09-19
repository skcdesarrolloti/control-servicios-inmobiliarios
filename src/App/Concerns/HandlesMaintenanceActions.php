<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait HandlesMaintenanceActions
{
  public function ajax_handler_generic(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('abiertos')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $action = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_POST['action'] ?? '')));

    $defs = $this->get_generic_tab_definitions();
    $tabMap = [
      self::AJAX_ENTREGA         => ['tab' => 'entrega',         'temas' => $defs['entrega']['temas'],         'prefix' => 'scmeg_'],
      self::AJAX_PREVENTIVA      => ['tab' => 'preventiva',      'temas' => $defs['preventiva']['temas'],      'prefix' => 'scmpv_'],
      self::AJAX_RECIBO          => ['tab' => 'recibo',          'temas' => $defs['recibo']['temas'],          'prefix' => 'scmrc_'],
      self::AJAX_CONTABLE        => ['tab' => 'contable',        'temas' => $defs['contable']['temas'],        'prefix' => 'scmco_'],
      self::AJAX_CERTIFICACIONES => ['tab' => 'certificaciones', 'temas' => $defs['certificaciones']['temas'], 'prefix' => 'scmcr_'],
      self::AJAX_CONTRACTUAL     => ['tab' => 'contractual',     'temas' => $defs['contractual']['temas'],     'prefix' => 'scmct_'],
    ];
    if (!isset($tabMap[$action])) {
      $this->jsonFail('Tab no reconocido.');
    }

    $tabConf = $tabMap[$action];
    $tabKey  = $tabConf['tab'];
    $temas   = $tabConf['temas'];
    $prefix  = $tabConf['prefix'];

    $rawConfig = json_decode(stripslashes((string)($_POST['config'] ?? '{}')), true);
    $rawConfig = is_array($rawConfig) ? $rawConfig : [];
    $config = [
      'ticket_url'     => self::sanitizeUrl($rawConfig['ticket_url']     ?? self::DEFAULT_TICKET_URL),
      'preventiva_url' => self::sanitizeUrl($rawConfig['preventiva_url'] ?? self::DEFAULT_PREVENTIVA_URL),
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::defaultCorrectiveReviewUrl()),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
    ];

    $p = $this->parse_params_generic($_POST, $prefix);

    $result = $this->run_query_generic($temas, $p, $config);

    $rows   = $result['rows'];
    $stats  = $result['stats'];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];

    $cardsHtml = $this->render_generic_cards($rows, $config, $tabKey);

    $paginationHtml = $this->get_generic_tickets_module()->render_generic_pagination($tabKey, $pagination);
    $this->jsonOk([
      'cards'      => $cardsHtml,
      'count'      => (string)($stats['total'] ?? 0),
      'kpi_total' => (string)($stats['total'] ?? 0),
      'kpi_con_cotz' => (string)($stats['con_cotizacion'] ?? 0),
      'kpi_sin_cotz' => (string)($stats['sin_cotizacion'] ?? 0),
      'kpi_con_prev' => (string)($stats['con_revision'] ?? 0),
      'kpi_sin_prev' => (string)($stats['sin_revision'] ?? 0),
      'kpi_con_rev_entrega' => (string)($stats['con_revision_entrega'] ?? 0),
      'kpi_sin_rev_entrega' => (string)($stats['sin_revision_entrega'] ?? 0),
      'kpi_con_inventario' => (string)($stats['con_inventario'] ?? 0),
      'kpi_sin_inventario' => (string)($stats['sin_inventario'] ?? 0),
      'kpi_con_cita' => (string)($stats['con_cita'] ?? 0),
      'kpi_sin_cita' => (string)($stats['sin_cita'] ?? 0),
      'kpi_con_rev_recibo' => (string)($stats['con_revision_recibo'] ?? 0),
      'kpi_sin_rev_recibo' => (string)($stats['sin_revision_recibo'] ?? 0),
      'kpi_nuevo' => (string)($stats['estado_nuevo'] ?? 0),
      'kpi_en_proceso' => (string)($stats['estado_en_proceso'] ?? 0),
      'kpi_avg_first_h' => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? number_format((float) $stats['avg_first_h'], 1) . 'h' : '-',
      'kpi_avg_stale_h' => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? number_format((float) $stats['avg_stale_h'], 1) . 'h' : '-',
      'kpi_magnitud_critico' => (string)($stats['magnitud_critico'] ?? 0),
      'kpi_magnitud_alto' => (string)($stats['magnitud_alto'] ?? 0),
      'kpi_magnitud_medio' => (string)($stats['magnitud_medio'] ?? 0),
      'kpi_magnitud_bajo' => (string)($stats['magnitud_bajo'] ?? 0),
      'kpi_danos_si' => (string)($stats['danos_si'] ?? 0),
      'kpi_danos_no' => (string)($stats['danos_no'] ?? 0),
      'pagination' => $paginationHtml,
    ]);
  }

  public function ajax_handler_my_tickets(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('mis_tickets')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $rawConfig = json_decode(stripslashes((string)($_POST['config'] ?? '{}')), true);
    $rawConfig = is_array($rawConfig) ? $rawConfig : [];
    $config = [
      'ticket_url'     => self::sanitizeUrl($rawConfig['ticket_url']     ?? self::DEFAULT_TICKET_URL),
      'preventiva_url' => self::sanitizeUrl($rawConfig['preventiva_url'] ?? self::DEFAULT_PREVENTIVA_URL),
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::defaultCorrectiveReviewUrl()),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
      'acta_url'       => self::sanitizeUrl($rawConfig['acta_url']       ?? self::DEFAULT_ACTA_URL),
    ];

    $params = $this->parse_params_generic($_POST, 'scm_my_');
    $employeeId = $this->current_employee_id();
    $params['fEmpleado'] = $employeeId !== '' ? $employeeId : '__sin_funcionario__';
    $params['_scmStatusBucket'] = 'all';
    $params['_scmExcludeDepartamento'] = 'Servicio al cliente';
    $result = $this->run_query_generic([], $params, $config);
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];

    $this->jsonOk([
      'cards' => $this->render_generic_cards($rows, $config, 'mis_tickets', 'all'),
      'pagination' => $this->get_generic_tickets_module()->render_generic_pagination('mis_tickets', $pagination),
      'count' => (string) ($stats['total'] ?? 0),
      'kpi_total' => (string) ($stats['total'] ?? 0),
      'kpi_con_cotz' => (string)($stats['con_cotizacion'] ?? 0),
      'kpi_sin_cotz' => (string)($stats['sin_cotizacion'] ?? 0),
      'kpi_con_prev' => (string)($stats['con_revision'] ?? 0),
      'kpi_sin_prev' => (string)($stats['sin_revision'] ?? 0),
      'kpi_con_rev_entrega' => (string)($stats['con_revision_entrega'] ?? 0),
      'kpi_sin_rev_entrega' => (string)($stats['sin_revision_entrega'] ?? 0),
      'kpi_con_inventario' => (string)($stats['con_inventario'] ?? 0),
      'kpi_sin_inventario' => (string)($stats['sin_inventario'] ?? 0),
      'kpi_con_cita' => (string)($stats['con_cita'] ?? 0),
      'kpi_sin_cita' => (string)($stats['sin_cita'] ?? 0),
      'kpi_con_rev_recibo' => (string)($stats['con_revision_recibo'] ?? 0),
      'kpi_sin_rev_recibo' => (string)($stats['sin_revision_recibo'] ?? 0),
      'kpi_nuevo' => (string)($stats['estado_nuevo'] ?? 0),
      'kpi_en_proceso' => (string)($stats['estado_en_proceso'] ?? 0),
      'kpi_avg_first_h' => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? number_format((float) $stats['avg_first_h'], 1) . 'h' : '-',
      'kpi_avg_stale_h' => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? number_format((float) $stats['avg_stale_h'], 1) . 'h' : '-',
      'kpi_magnitud_critico' => (string)($stats['magnitud_critico'] ?? 0),
      'kpi_magnitud_alto' => (string)($stats['magnitud_alto'] ?? 0),
      'kpi_magnitud_medio' => (string)($stats['magnitud_medio'] ?? 0),
      'kpi_magnitud_bajo' => (string)($stats['magnitud_bajo'] ?? 0),
      'kpi_danos_si' => (string)($stats['danos_si'] ?? 0),
      'kpi_danos_no' => (string)($stats['danos_no'] ?? 0),
    ]);
  }

  public function ajax_handler_metrics_execution(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver metricas.');
    }

    $range = $this->metrics_execution_date_range($_POST);
    $funcionario = trim(sanitize_text_field(wp_unslash((string) ($_POST['funcionario'] ?? ''))));

    $ticketsTable = $this->db->table('jet_cct_tickets');
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    $segTable = $this->db->table('jet_cct_seguimiento_ticket');
    $funcTable = $this->db->table('jet_cct_funcionarios');

    if (!$this->table_exists($ticketsTable)) {
      $this->jsonFail('La tabla de tickets no esta disponible.');
    }

    $employees = $this->metrics_execution_employee_map($funcTable);
    $details = [];
    $summary = [];
    $seguimientoSignatures = [];

    if ($this->table_exists($segTable)) {
      $where = ['COALESCE(s.`fecha`, 0) BETWEEN ? AND ?'];
      $args = [$range['from_ts'], $range['to_ts']];
      if ($funcionario !== '') {
        $where[] = "(TRIM(COALESCE(s.`id_coordinador`, '')) = ? OR TRIM(COALESCE(s.`id_empleado`, '')) = ?)";
        $args[] = $funcionario;
        $args[] = $funcionario;
      }

      $rows = $this->db->getResults(
        "SELECT
            s.`_ID` AS movimiento_id,
            COALESCE(s.`fecha`, 0) AS fecha_ts,
            TRIM(COALESCE(s.`id_ticket`, '')) AS ticket_ref,
            TRIM(COALESCE(s.`id_coordinador`, '')) AS funcionario_id,
            TRIM(COALESCE(s.`nombre`, '')) AS funcionario_nombre,
            TRIM(COALESCE(s.`observacion`, '')) AS detalle,
            t.`_ID` AS ticket_pk,
            TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
            TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, '')) AS asunto,
            TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
            TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
            TRIM(COALESCE(t.`estado`, '')) AS estado,
            TRIM(COALESCE(t.`estado_administrativo`, '')) AS estado_admin
          FROM `{$segTable}` s
          LEFT JOIN `{$ticketsTable}` t
            ON TRIM(COALESCE(t.`id_ticket`, '')) = TRIM(COALESCE(s.`id_ticket`, ''))
            OR CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(s.`id_ticket`, ''))
          WHERE " . implode(' AND ', $where) . "
          ORDER BY COALESCE(s.`fecha`, 0) DESC
          LIMIT 900",
        $args
      );

      foreach ($rows as $row) {
        $detailText = $this->metrics_execution_clean_text((string) ($row['detalle'] ?? ''));
        if ($detailText === '') {
          continue;
        }
        $employee = $this->metrics_execution_employee_label($row, $employees);
        $item = $this->metrics_execution_detail_item($row, 'seguimiento', 'Seguimiento', $detailText, $employee);
        $details[] = $item;
        $this->metrics_execution_add_summary($summary, $item);
        $seguimientoSignatures[$this->metrics_execution_signature($item)] = true;
      }
    }

    if ($this->table_exists($histTable)) {
      $where = ['COALESCE(h.`fecha`, 0) BETWEEN ? AND ?'];
      $args = [$range['from_ts'], $range['to_ts']];
      if ($funcionario !== '') {
        $where[] = "TRIM(COALESCE(h.`id_empleado`, '')) = ?";
        $args[] = $funcionario;
      }

      $rows = $this->db->getResults(
        "SELECT
            h.`_ID` AS movimiento_id,
            COALESCE(h.`fecha`, 0) AS fecha_ts,
            CAST(h.`id_ticket` AS CHAR) AS ticket_ref,
            TRIM(COALESCE(h.`id_empleado`, '')) AS funcionario_id,
            TRIM(COALESCE(h.`nombre`, '')) AS funcionario_nombre,
            TRIM(COALESCE(h.`respuesta`, '')) AS detalle,
            t.`_ID` AS ticket_pk,
            TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
            TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, '')) AS asunto,
            TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
            TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
            TRIM(COALESCE(t.`estado`, '')) AS estado,
            TRIM(COALESCE(t.`estado_administrativo`, '')) AS estado_admin
          FROM `{$histTable}` h
          LEFT JOIN `{$ticketsTable}` t
            ON t.`_ID` = h.`id_ticket`
            OR TRIM(COALESCE(t.`id_ticket`, '')) = CAST(h.`id_ticket` AS CHAR)
          WHERE " . implode(' AND ', $where) . "
          ORDER BY COALESCE(h.`fecha`, 0) DESC
          LIMIT 1200",
        $args
      );

      foreach ($rows as $row) {
        $detailText = $this->metrics_execution_clean_text((string) ($row['detalle'] ?? ''));
        if ($detailText === '') {
          continue;
        }
        $classification = $this->metrics_execution_classify_history($detailText);
        if ($classification === 'ignore') {
          continue;
        }
        $employee = $this->metrics_execution_employee_label($row, $employees);
        $label = $classification === 'actualizacion' ? 'Actualizaci&oacute;n' : 'Respuesta';
        $item = $this->metrics_execution_detail_item($row, $classification, $label, $detailText, $employee);
        if (isset($seguimientoSignatures[$this->metrics_execution_signature($item)])) {
          continue;
        }
        $details[] = $item;
        $this->metrics_execution_add_summary($summary, $item);
      }
    }

    usort($details, static function (array $a, array $b): int {
      return (int) ($b['fecha_ts'] ?? 0) <=> (int) ($a['fecha_ts'] ?? 0);
    });

    $details = array_slice($details, 0, 1000);
    $summaryRows = array_values($summary);
    usort($summaryRows, static function (array $a, array $b): int {
      return (int) ($b['total_acciones'] ?? 0) <=> (int) ($a['total_acciones'] ?? 0);
    });

    $totals = [
      'funcionarios' => count($summaryRows),
      'respuestas' => 0,
      'actualizaciones' => 0,
      'seguimientos' => 0,
      'citas_realizadas' => 0,
      'casos' => 0,
      'total_acciones' => 0,
    ];
    $caseSet = [];
    foreach ($summaryRows as $row) {
      $totals['respuestas'] += (int) ($row['respuestas'] ?? 0);
      $totals['actualizaciones'] += (int) ($row['actualizaciones'] ?? 0);
      $totals['seguimientos'] += (int) ($row['seguimientos'] ?? 0);
      $totals['citas_realizadas'] += (int) ($row['citas_realizadas'] ?? 0);
      $totals['total_acciones'] += (int) ($row['total_acciones'] ?? 0);
      foreach ((array) ($row['_casos'] ?? []) as $caseKey => $_) {
        $caseSet[(string) $caseKey] = true;
      }
    }
    $totals['casos'] = count($caseSet);
    foreach ($summaryRows as &$row) {
      $caseKeys = array_keys((array) ($row['_casos'] ?? []));
      $row['case_keys'] = array_values(array_map('strval', $caseKeys));
      $row['casos'] = count($caseKeys);
      unset($row['_casos']);
    }
    unset($row);

    $this->jsonOk([
      'from' => $range['from'],
      'to' => $range['to'],
      'funcionario' => $funcionario,
      'totals' => $totals,
      'summary' => $summaryRows,
      'details' => $details,
    ]);
  }

  public function ajax_handler_dashboard_completed_activities(): void
  {
    $this->verifyCsrf();
    if (
      !$this->canAccessDashboardTab('calendario_actividades')
      && !$this->canAccessDashboardTab('metricas')
      && !$this->canAccessDashboardTab('abiertos')
    ) {
      $this->jsonFail('No tienes permiso para ver actividades realizadas.');
    }

    $range = $this->metrics_execution_date_range($_POST);
    $funcionario = trim(sanitize_text_field(wp_unslash((string) ($_POST['funcionario'] ?? ''))));
    if ($funcionario === '' && method_exists($this, 'current_employee_id')) {
      $funcionario = trim((string) $this->current_employee_id());
    }
    $payload = $this->dashboard_completed_activities_payload($range, $funcionario);
    $payload['generated_at'] = date(DATE_ATOM);
    $this->jsonOk($payload);
  }

  /** @param array{from:string,to:string,from_ts:int,to_ts:int} $range @return array<string,mixed> */
  private function dashboard_completed_activities_payload(array $range, string $funcionario = ''): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    $segTable = $this->db->table('jet_cct_seguimiento_ticket');
    $funcTable = $this->db->table('jet_cct_funcionarios');
    $calendarTable = 'calendario_actividades';
    $actsTable = $this->db->table('scm_ticket_completion_acts');
    $legacyActsTable = $this->db->table('jet_cct_actas_de_satisfaccion');
    $servicesTable = $this->db->table('jet_cct_revisiones_servicios');

    $ticketsAvailable = $this->table_exists($ticketsTable);
    $employees = $this->metrics_execution_employee_map($funcTable);
    $employeeName = $funcionario !== '' && isset($employees[$funcionario])
      ? trim((string) ($employees[$funcionario]['nombre'] ?? ''))
      : '';
    $events = [];
    $actions = [];
    $totals = [
      'eventos' => 0,
      'respuestas' => 0,
      'seguimientos' => 0,
      'actualizaciones' => 0,
      'actas' => 0,
      'cerrados' => 0,
      'revisiones_servicios' => 0,
      'total' => 0,
    ];

    if ($this->table_exists($calendarTable)) {
      $eventTsExpr = $this->dashboard_completed_date_expr($calendarTable, 'c', ['fecha_inicio', 'fecha', 'cct_created']);
      if ($eventTsExpr !== '') {
        $eventTicketSelect = '';
        $eventTicketJoin = '';
        if ($ticketsAvailable) {
          $eventTicketSelect = ",
            t.`_ID` AS ticket_pk,
            TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
            TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS ticket_contrato,
            TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS ticket_inmueble,
            TRIM(COALESCE(t.`direccion`, '')) AS ticket_direccion,
            TRIM(COALESCE(t.`estado`, '')) AS ticket_estado,
            TRIM(COALESCE(t.`estado_administrativo`, '')) AS ticket_estado_admin";
          $eventTicketJoin = "LEFT JOIN `{$ticketsTable}` t
            ON TRIM(COALESCE(t.`id_ticket`, '')) = TRIM(COALESCE(c.`id_ticket`, ''))
            OR CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_ticket`, ''))";
        }
        $where = [
          "{$eventTsExpr} BETWEEN ? AND ?",
          "LOWER(TRIM(COALESCE(c.`estado`, ''))) IN ('si', 'sí', 'realizado', 'realizada', '1', 'true')",
        ];
        $args = [$range['from_ts'], $range['to_ts']];
        if ($funcionario !== '') {
          $employeeWhere = [];
          if ($this->column_exists($calendarTable, 'id_empleado')) {
            $employeeWhere[] = $this->dashboard_completed_employee_equals('c', 'id_empleado');
            $args[] = $funcionario;
          }
          if (!empty($employeeWhere)) {
            $where[] = '(' . implode(' OR ', $employeeWhere) . ')';
          }
        }
        $rows = $this->db->getResults(
          "SELECT c.*, {$eventTsExpr} AS fecha_ts{$eventTicketSelect}
           FROM `{$calendarTable}` c
           {$eventTicketJoin}
           WHERE " . implode(' AND ', $where) . "
           ORDER BY fecha_ts DESC
           LIMIT 300",
          $args
        );
        foreach ($rows as $row) {
          $ts = (int) ($row['fecha_ts'] ?? 0);
          $ticket = trim((string) ($row['id_ticket'] ?? ''));
          $eventEmployeeId = trim((string) ($row['id_empleado'] ?? ''));
          $eventEmployee = $eventEmployeeId !== '' && isset($employees[$eventEmployeeId])
            ? (string) ($employees[$eventEmployeeId]['label'] ?? $employees[$eventEmployeeId]['nombre'] ?? $eventEmployeeId)
            : $eventEmployeeId;
          $events[] = [
            'id' => (string) ($row['id'] ?? $row['_ID'] ?? ''),
            'type' => 'evento',
            'label' => 'Evento realizado',
            'fecha_ts' => $ts,
            'fecha' => $ts > 0 ? date('d/m/Y H:i', $ts) : '-',
            'titulo' => trim((string) ($row['titulo'] ?? 'Evento realizado')),
            'detalle' => $this->metrics_execution_clean_text((string) ($row['descripcion'] ?? '')),
            'funcionario_id' => $eventEmployeeId,
            'funcionario' => $eventEmployee,
            'funcionario_label' => $eventEmployee,
            'ticket_pk' => trim((string) ($row['ticket_pk'] ?? '')),
            'ticket' => $ticket,
            'contrato' => trim((string) ($row['ticket_contrato'] ?? '')),
            'inmueble' => trim((string) ($row['ticket_inmueble'] ?? '')),
            'direccion' => trim((string) ($row['ticket_direccion'] ?? '')),
            'estado' => trim((string) ($row['ticket_estado'] ?? '')),
            'estado_admin' => trim((string) ($row['ticket_estado_admin'] ?? '')),
          ];
        }
        $totals['eventos'] = count($events);
      }
    }

    if ($ticketsAvailable && $this->table_exists($segTable)) {
      $where = ['COALESCE(s.`fecha`, 0) BETWEEN ? AND ?'];
      $args = [$range['from_ts'], $range['to_ts']];
      if ($funcionario !== '') {
        $employeeWhere = [];
        foreach (['id_coordinador', 'id_empleado'] as $column) {
          if ($this->column_exists($segTable, $column)) {
            $employeeWhere[] = $this->dashboard_completed_employee_equals('s', $column);
            $args[] = $funcionario;
          }
        }
        if (!empty($employeeWhere)) {
          $where[] = '(' . implode(' OR ', $employeeWhere) . ')';
        }
      }
      $rows = $this->db->getResults(
        "SELECT
            s.`_ID` AS movimiento_id,
            COALESCE(s.`fecha`, 0) AS fecha_ts,
            TRIM(COALESCE(s.`id_ticket`, '')) AS ticket_ref,
            TRIM(COALESCE(s.`id_coordinador`, s.`id_empleado`, '')) AS funcionario_id,
            TRIM(COALESCE(s.`nombre`, '')) AS funcionario_nombre,
            TRIM(COALESCE(s.`observacion`, '')) AS detalle,
            t.`_ID` AS ticket_pk,
            TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
            TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, '')) AS asunto,
            TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
            TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
            TRIM(COALESCE(t.`direccion`, '')) AS direccion,
            TRIM(COALESCE(t.`estado`, '')) AS estado,
            TRIM(COALESCE(t.`estado_administrativo`, '')) AS estado_admin
          FROM `{$segTable}` s
          LEFT JOIN `{$ticketsTable}` t
            ON TRIM(COALESCE(t.`id_ticket`, '')) = TRIM(COALESCE(s.`id_ticket`, ''))
            OR CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(s.`id_ticket`, ''))
          WHERE " . implode(' AND ', $where) . "
          ORDER BY COALESCE(s.`fecha`, 0) DESC
          LIMIT 300",
        $args
      );
      foreach ($rows as $row) {
        $detailText = $this->metrics_execution_clean_text((string) ($row['detalle'] ?? ''));
        if ($detailText === '') {
          continue;
        }
        $employee = $this->metrics_execution_employee_label($row, $employees);
        $actions[] = $this->metrics_execution_detail_item($row, 'seguimiento', 'Seguimiento', $detailText, $employee);
        $totals['seguimientos']++;
      }
    }

    if ($ticketsAvailable && $this->table_exists($histTable)) {
      $where = ['COALESCE(h.`fecha`, 0) BETWEEN ? AND ?'];
      $args = [$range['from_ts'], $range['to_ts']];
      if ($funcionario !== '') {
        if ($this->column_exists($histTable, 'id_empleado')) {
          $where[] = $this->dashboard_completed_employee_equals('h', 'id_empleado');
          $args[] = $funcionario;
        }
      }
      $rows = $this->db->getResults(
        "SELECT
            h.`_ID` AS movimiento_id,
            COALESCE(h.`fecha`, 0) AS fecha_ts,
            CAST(h.`id_ticket` AS CHAR) AS ticket_ref,
            TRIM(COALESCE(h.`id_empleado`, '')) AS funcionario_id,
            TRIM(COALESCE(h.`nombre`, '')) AS funcionario_nombre,
            TRIM(COALESCE(h.`respuesta`, '')) AS detalle,
            t.`_ID` AS ticket_pk,
            TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
            TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, '')) AS asunto,
            TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
            TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
            TRIM(COALESCE(t.`direccion`, '')) AS direccion,
            TRIM(COALESCE(t.`estado`, '')) AS estado,
            TRIM(COALESCE(t.`estado_administrativo`, '')) AS estado_admin
          FROM `{$histTable}` h
          LEFT JOIN `{$ticketsTable}` t
            ON t.`_ID` = h.`id_ticket`
            OR TRIM(COALESCE(t.`id_ticket`, '')) = CAST(h.`id_ticket` AS CHAR)
          WHERE " . implode(' AND ', $where) . "
          ORDER BY COALESCE(h.`fecha`, 0) DESC
          LIMIT 400",
        $args
      );
      foreach ($rows as $row) {
        $detailText = $this->metrics_execution_clean_text((string) ($row['detalle'] ?? ''));
        if ($detailText === '') {
          continue;
        }
        $classification = $this->metrics_execution_classify_history($detailText);
        if ($classification === 'ignore') {
          continue;
        }
        $employee = $this->metrics_execution_employee_label($row, $employees);
        $label = $classification === 'actualizacion' ? 'Actualización' : 'Respuesta';
        $actions[] = $this->metrics_execution_detail_item($row, $classification, $label, $detailText, $employee);
        if ($classification === 'actualizacion') {
          $totals['actualizaciones']++;
        } else {
          $totals['respuestas']++;
        }
      }
    }

    $this->dashboard_completed_add_closed_tickets($actions, $totals, $range, $ticketsTable, $funcionario);
    $this->dashboard_completed_add_acts($actions, $totals, $range, $actsTable, $legacyActsTable, $funcionario);
    $this->dashboard_completed_add_public_services_reviews($actions, $totals, $range, $servicesTable, $funcionario, $employeeName);

    usort($events, static function (array $a, array $b): int {
      return (int) ($b['fecha_ts'] ?? 0) <=> (int) ($a['fecha_ts'] ?? 0);
    });
    usort($actions, static function (array $a, array $b): int {
      return (int) ($b['fecha_ts'] ?? 0) <=> (int) ($a['fecha_ts'] ?? 0);
    });

    $totals['total'] = array_sum([
      $totals['eventos'],
      $totals['respuestas'],
      $totals['seguimientos'],
      $totals['actualizaciones'],
      $totals['actas'],
      $totals['cerrados'],
      $totals['revisiones_servicios'],
    ]);

    return [
      'from' => $range['from'],
      'to' => $range['to'],
      'funcionario' => $funcionario,
      'totals' => $totals,
      'events' => array_slice($events, 0, 18),
      'actions' => array_slice($actions, 0, 24),
    ];
  }

  public function ajax_handler_cotizaciones_mantenimiento(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $params = $this->parse_cotizaciones_mantenimiento_params($_POST, 'scmqt_');
    $result = $this->query_cotizaciones_mantenimiento($params);
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $tabStats = is_array($result['tab_stats'] ?? null) ? $result['tab_stats'] : $stats;
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];

    $this->jsonOk([
      'cards' => $this->render_cotizaciones_mantenimiento_cards($rows),
      'pagination' => $this->render_cotizaciones_mantenimiento_pagination($pagination),
      'count' => (string) ($stats['total'] ?? 0),
      'kpi_total' => (string) ($stats['total'] ?? 0),
      'kpi_no_enviadas' => (string) ($stats['no_enviadas'] ?? 0),
      'kpi_aprobadas' => (string) ($stats['aprobadas'] ?? 0),
      'kpi_desaprobadas' => (string) ($stats['desaprobadas'] ?? 0),
      'kpi_esperando_respuesta' => (string) ($stats['esperando_respuesta'] ?? 0),
      'kpi_sin_estado' => (string) ($stats['sin_estado'] ?? 0),
      'kpi_ordenes_total' => (string) ($stats['ordenes_total'] ?? 0),
      'kpi_valor_total' => $this->format_cop_currency($stats['valor_total'] ?? 0),
      'kpi_tab_total' => (string) ($tabStats['total'] ?? 0),
      'kpi_tab_no_enviadas' => (string) ($tabStats['no_enviadas'] ?? 0),
      'kpi_tab_aprobadas' => (string) ($tabStats['aprobadas'] ?? 0),
      'kpi_tab_desaprobadas' => (string) ($tabStats['desaprobadas'] ?? 0),
      'kpi_tab_esperando_respuesta' => (string) ($tabStats['esperando_respuesta'] ?? 0),
    ]);
  }

  /** @return array<string,mixed> */
  private function dashboard_completed_action_item(string $type, string $label, int $ts, string $title, string $detail = '', string $ticket = '', string $ticketPk = '', array $extra = []): array
  {
    return array_merge([
      'id' => sha1($type . '|' . $ts . '|' . $title . '|' . $ticket . '|' . $ticketPk),
      'type' => $type,
      'label' => $label,
      'fecha_ts' => $ts,
      'fecha' => $ts > 0 ? date('d/m/Y H:i', $ts) : '-',
      'ticket_pk' => $ticketPk,
      'ticket' => $ticket !== '' ? $ticket : ($ticketPk !== '' ? $ticketPk : '-'),
      'asunto' => $title,
      'detalle' => $this->metrics_execution_clean_text($detail),
    ], $extra);
  }

  private function dashboard_completed_date_expr(string $table, string $alias, array $columns): string
  {
    $parts = [];
    foreach ($columns as $column) {
      $column = trim((string) $column);
      if ($column === '' || !$this->column_exists($table, $column)) {
        continue;
      }
      if (in_array($column, ['cct_created', 'cct_modified', 'fecha_inicio', 'fecha_fin'], true)) {
        $parts[] = "UNIX_TIMESTAMP(NULLIF({$alias}.`{$column}`, ''))";
      } else {
        $parts[] = "NULLIF(CAST({$alias}.`{$column}` AS UNSIGNED), 0)";
      }
    }
    if (empty($parts)) {
      return '';
    }
    return 'COALESCE(' . implode(', ', $parts) . ')';
  }

  private function dashboard_completed_employee_equals(string $alias, string $column): string
  {
    $alias = trim($alias);
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "CAST(TRIM(COALESCE({$prefix}`{$column}`, '')) AS BINARY) = CAST(? AS BINARY)";
  }

  /** @param array<int,array<string,mixed>> $actions @param array<string,int> $totals @param array{from_ts:int,to_ts:int} $range */
  private function dashboard_completed_add_closed_tickets(array &$actions, array &$totals, array $range, string $ticketsTable, string $funcionario = ''): void
  {
    if (!$this->table_exists($ticketsTable)) {
      return;
    }
    $dateExpr = $this->dashboard_completed_date_expr($ticketsTable, 't', ['fecha_actualizacion', 'cct_modified', 'fecha_respuesta', 'fecha']);
    if ($dateExpr === '') {
      return;
    }
    $where = [
      "{$dateExpr} BETWEEN ? AND ?",
      "(
           LOWER(TRIM(COALESCE(t.`estado`, ''))) IN ('cerrado', 'resuelto', 'finalizado')
           OR LOWER(TRIM(COALESCE(t.`estado_administrativo`, ''))) IN ('cerrado', 'resuelto', 'finalizado')
         )",
    ];
    $args = [$range['from_ts'], $range['to_ts']];
    if ($funcionario !== '' && $this->column_exists($ticketsTable, 'id_empleado')) {
      $where[] = $this->dashboard_completed_employee_equals('t', 'id_empleado');
      $args[] = $funcionario;
    }
    $rows = $this->db->getResults(
      "SELECT t.`_ID` AS ticket_pk,
              TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
              TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, 'Ticket cerrado')) AS asunto,
              TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
              TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
              TRIM(COALESCE(t.`direccion`, '')) AS direccion,
              TRIM(COALESCE(t.`nombre_empleado`, t.`empleado`, '')) AS funcionario,
              TRIM(COALESCE(t.`id_empleado`, '')) AS funcionario_id,
              TRIM(COALESCE(t.`estado`, '')) AS estado,
              TRIM(COALESCE(t.`estado_administrativo`, '')) AS estado_admin,
              {$dateExpr} AS fecha_ts
       FROM `{$ticketsTable}` t
       WHERE " . implode(' AND ', $where) . "
       ORDER BY fecha_ts DESC
       LIMIT 250",
      $args
    );
    foreach ($rows as $row) {
      $ts = (int) ($row['fecha_ts'] ?? 0);
      $ticketPk = trim((string) ($row['ticket_pk'] ?? ''));
      $ticket = trim((string) ($row['ticket_logico'] ?? $ticketPk));
      $actions[] = $this->dashboard_completed_action_item(
        'cerrado',
        'Ticket cerrado',
        $ts,
        'Ticket #' . ($ticket !== '' ? $ticket : $ticketPk) . ' cerrado',
        (string) ($row['asunto'] ?? ''),
        $ticket,
        $ticketPk,
        [
          'contrato' => trim((string) ($row['contrato'] ?? '')),
          'inmueble' => trim((string) ($row['inmueble'] ?? '')),
          'direccion' => trim((string) ($row['direccion'] ?? '')),
          'funcionario_id' => trim((string) ($row['funcionario_id'] ?? '')),
          'funcionario' => trim((string) ($row['funcionario'] ?? '')),
          'funcionario_label' => trim((string) ($row['funcionario'] ?? '')),
          'estado' => trim((string) ($row['estado'] ?? '')),
          'estado_admin' => trim((string) ($row['estado_admin'] ?? '')),
        ]
      );
      $totals['cerrados']++;
    }
  }

  /** @param array<int,array<string,mixed>> $actions @param array<string,int> $totals @param array{from_ts:int,to_ts:int} $range */
  private function dashboard_completed_add_acts(array &$actions, array &$totals, array $range, string $actsTable, string $legacyActsTable, string $funcionario = ''): void
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if ($this->table_exists($ticketsTable) && $this->table_exists($actsTable) && $this->column_exists($actsTable, 'created_at')) {
      $dateExpr = $this->column_exists($actsTable, 'signed_at')
        ? "COALESCE(NULLIF(CAST(a.`signed_at` AS UNSIGNED), 0), NULLIF(CAST(a.`created_at` AS UNSIGNED), 0))"
        : "NULLIF(CAST(a.`created_at` AS UNSIGNED), 0)";
      $where = ["{$dateExpr} BETWEEN ? AND ?"];
      $args = [$range['from_ts'], $range['to_ts']];
      if ($funcionario !== '' && $this->column_exists($ticketsTable, 'id_empleado')) {
        $where[] = $this->dashboard_completed_employee_equals('t', 'id_empleado');
        $args[] = $funcionario;
      }
      $rows = $this->db->getResults(
        "SELECT a.`id` AS acta_id,
                a.`ticket_pk`,
                TRIM(COALESCE(a.`status`, '')) AS estado_acta,
                {$dateExpr} AS fecha_ts,
                TRIM(COALESCE(t.`id_ticket`, '')) AS ticket_logico,
                TRIM(COALESCE(t.`asunto`, t.`tema_ayuda`, 'Acta de satisfacción')) AS asunto,
                TRIM(COALESCE(t.`contrato`, t.`id_contrato`, '')) AS contrato,
                TRIM(COALESCE(t.`inmueble`, t.`id_inmueble`, '')) AS inmueble,
                TRIM(COALESCE(t.`direccion`, '')) AS direccion,
                TRIM(COALESCE(t.`nombre_empleado`, t.`empleado`, '')) AS funcionario,
                TRIM(COALESCE(t.`id_empleado`, '')) AS funcionario_id
         FROM `{$actsTable}` a
         LEFT JOIN `{$ticketsTable}` t ON t.`_ID` = a.`ticket_pk`
         WHERE " . implode(' AND ', $where) . "
         ORDER BY fecha_ts DESC
         LIMIT 250",
        $args
      );
      foreach ($rows as $row) {
        $ticketPk = trim((string) ($row['ticket_pk'] ?? ''));
        $ticket = trim((string) ($row['ticket_logico'] ?? $ticketPk));
        $status = trim((string) ($row['estado_acta'] ?? ''));
        $actions[] = $this->dashboard_completed_action_item(
          'acta',
          'Acta',
          (int) ($row['fecha_ts'] ?? 0),
          'Acta #' . trim((string) ($row['acta_id'] ?? '-')) . ' registrada',
          ($status !== '' ? 'Estado: ' . $status . '. ' : '') . (string) ($row['asunto'] ?? ''),
          $ticket,
          $ticketPk,
          [
            'contrato' => trim((string) ($row['contrato'] ?? '')),
            'inmueble' => trim((string) ($row['inmueble'] ?? '')),
            'direccion' => trim((string) ($row['direccion'] ?? '')),
            'funcionario_id' => trim((string) ($row['funcionario_id'] ?? '')),
            'funcionario' => trim((string) ($row['funcionario'] ?? '')),
            'funcionario_label' => trim((string) ($row['funcionario'] ?? '')),
          ]
        );
        $totals['actas']++;
      }
      return;
    }

    if (!$this->table_exists($legacyActsTable)) {
      return;
    }
    $dateExpr = $this->dashboard_completed_date_expr($legacyActsTable, 'a', ['fecha_satisfaccion', 'fecha', 'cct_created']);
    if ($dateExpr === '') {
      return;
    }
    $employees = $this->metrics_execution_employee_map($this->db->table('jet_cct_funcionarios'));
    $where = ["{$dateExpr} BETWEEN ? AND ?"];
    $args = [$range['from_ts'], $range['to_ts']];
    if ($funcionario !== '') {
      $employeeWhere = [];
      foreach (['id_empleado', 'cct_author_id'] as $column) {
        if ($this->column_exists($legacyActsTable, $column)) {
          $employeeWhere[] = $this->dashboard_completed_employee_equals('a', $column);
          $args[] = $funcionario;
        }
      }
      if (!empty($employeeWhere)) {
        $where[] = '(' . implode(' OR ', $employeeWhere) . ')';
      }
    }
    $rows = $this->db->getResults(
      "SELECT a.`_ID` AS acta_id,
              TRIM(COALESCE(a.`id_ticket`, '')) AS ticket_ref,
              TRIM(COALESCE(a.`id_contrato`, a.`contrato`, '')) AS contrato,
              TRIM(COALESCE(a.`id_inmueble`, a.`inmueble`, '')) AS inmueble,
              TRIM(COALESCE(a.`direccion`, '')) AS direccion,
              TRIM(COALESCE(a.`coordinador`, a.`creador`, '')) AS funcionario,
              TRIM(COALESCE(a.`id_empleado`, a.`cct_author_id`, '')) AS funcionario_id,
              {$dateExpr} AS fecha_ts
       FROM `{$legacyActsTable}` a
       WHERE " . implode(' AND ', $where) . "
       ORDER BY fecha_ts DESC
       LIMIT 250",
      $args
    );
    foreach ($rows as $row) {
      $ticket = trim((string) ($row['ticket_ref'] ?? ''));
      $actions[] = $this->dashboard_completed_action_item(
        'acta',
        'Acta',
        (int) ($row['fecha_ts'] ?? 0),
        'Acta #' . trim((string) ($row['acta_id'] ?? '-')) . ' registrada',
        'Acta de satisfacción registrada.',
        $ticket,
        $ticket,
        [
          'contrato' => trim((string) ($row['contrato'] ?? '')),
          'inmueble' => trim((string) ($row['inmueble'] ?? '')),
          'direccion' => trim((string) ($row['direccion'] ?? '')),
          'funcionario_id' => trim((string) ($row['funcionario_id'] ?? '')),
          'funcionario' => trim((string) ($row['funcionario'] ?? '')),
          'funcionario_label' => trim((string) ($row['funcionario'] ?? '')),
        ]
      );
      $totals['actas']++;
    }
  }

  /** @param array<int,array<string,mixed>> $actions @param array<string,int> $totals @param array{from_ts:int,to_ts:int} $range */
  private function dashboard_completed_add_public_services_reviews(array &$actions, array &$totals, array $range, string $servicesTable, string $funcionario = '', string $employeeName = ''): void
  {
    if (!$this->table_exists($servicesTable)) {
      return;
    }
    $dateExpr = $this->dashboard_completed_date_expr($servicesTable, 'r', ['fecha', 'cct_created']);
    if ($dateExpr === '') {
      return;
    }
    $where = ["{$dateExpr} BETWEEN ? AND ?"];
    $args = [$range['from_ts'], $range['to_ts']];
    if ($funcionario !== '') {
      $employeeWhere = [];
      foreach (['id_empleado', 'cct_author_id'] as $column) {
        if ($this->column_exists($servicesTable, $column)) {
          $employeeWhere[] = $this->dashboard_completed_employee_equals('r', $column);
          $args[] = $funcionario;
        }
      }
      if ($employeeName !== '' && $this->column_exists($servicesTable, 'realizado_por')) {
        // Avoid comparing names in SQL: production mixes collations across CCT tables.
        // The employee id columns above are the stable filter when available.
      }
      if (!empty($employeeWhere)) {
        $where[] = '(' . implode(' OR ', $employeeWhere) . ')';
      }
    }
    $rows = $this->db->getResults(
      "SELECT r.`_ID` AS revision_id,
              TRIM(COALESCE(r.`id_contrato`, r.`contrato`, '')) AS contrato,
              TRIM(COALESCE(r.`id_inmueble`, r.`inmueble`, '')) AS inmueble,
              TRIM(COALESCE(r.`direccion`, '')) AS direccion,
              TRIM(COALESCE(r.`id_empleado`, r.`cct_author_id`, '')) AS funcionario_id,
              TRIM(COALESCE(r.`realizado_por`, '')) AS funcionario,
              {$dateExpr} AS fecha_ts
       FROM `{$servicesTable}` r
       WHERE " . implode(' AND ', $where) . "
       ORDER BY fecha_ts DESC
       LIMIT 250",
      $args
    );
    foreach ($rows as $row) {
      $contract = trim((string) ($row['contrato'] ?? ''));
      $property = trim((string) ($row['inmueble'] ?? ''));
      $employeeId = trim((string) ($row['funcionario_id'] ?? ''));
      $employeeLabel = trim((string) ($row['funcionario'] ?? ''));
      if ($employeeLabel === '' && $employeeId !== '' && isset($employees[$employeeId])) {
        $employeeLabel = (string) ($employees[$employeeId]['label'] ?? $employees[$employeeId]['nombre'] ?? $employeeId);
      }
      $actions[] = $this->dashboard_completed_action_item(
        'revision_servicios',
        'Revisión servicios públicos',
        (int) ($row['fecha_ts'] ?? 0),
        'Revisión servicios públicos #' . trim((string) ($row['revision_id'] ?? '-')),
        trim('Contrato ' . ($contract !== '' ? '#' . $contract : '-') . ($property !== '' ? ' · Inmueble ' . $property : '')),
        '',
        '',
        [
          'contrato' => $contract,
          'inmueble' => $property,
          'direccion' => trim((string) ($row['direccion'] ?? '')),
          'funcionario_id' => $employeeId,
          'funcionario' => $employeeLabel,
          'funcionario_label' => $employeeLabel,
        ]
      );
      $totals['revisiones_servicios']++;
    }
  }

  public function ajax_handler_cotizacion_mantenimiento_form_context(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('abiertos') && !$this->canAccessDashboardTab('postergados') && !$this->canAccessDashboardTab('mis_tickets')) {
      $this->jsonFail('No tienes permiso para gestionar cotizaciones de mantenimiento.');
    }

    try {
      $mode = $this->maintenance_quote_mode($_POST['mode'] ?? 'create');
      if ($mode === 'create' && !$this->canUseDashboardAction('quote_create')) {
        $this->jsonFail('No tienes permiso para crear cotizaciones de mantenimiento.');
      }
      if (in_array($mode, ['edit', 'note'], true) && !$this->canUseDashboardAction('quote_edit')) {
        $this->jsonFail('No tienes permiso para editar cotizaciones de mantenimiento.');
      }
      $ticketPk = (int) ($_POST['ticket_pk'] ?? 0);
      $quoteId = (int) ($_POST['id_cotizacion'] ?? 0);
      $context = $this->maintenance_quote_form_context($mode, $ticketPk, $quoteId);
      unset($context['ticket_raw'], $context['revision_raw'], $context['cotizacion_raw']);
      $this->jsonOk($context);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      $this->jsonFail('No se pudo cargar el formulario de cotización.');
    }
  }

  public function ajax_handler_cotizacion_mantenimiento_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('abiertos') && !$this->canAccessDashboardTab('postergados') && !$this->canAccessDashboardTab('mis_tickets')) {
      $this->jsonFail('No tienes permiso para guardar cotizaciones de mantenimiento.');
    }

    $storedPhotos = [];
    $quoteIdSaved = 0;
    $quoteTable = '';
    $mode = 'create';
    $pdo = $this->db->pdo();
    try {
      $mode = $this->maintenance_quote_mode($_POST['mode'] ?? 'create');
      if ($mode === 'create' && !$this->canUseDashboardAction('quote_create')) {
        $this->jsonFail('No tienes permiso para crear cotizaciones de mantenimiento.');
      }
      if (in_array($mode, ['edit', 'note'], true) && !$this->canUseDashboardAction('quote_edit')) {
        $this->jsonFail('No tienes permiso para editar cotizaciones de mantenimiento.');
      }
      $ticketPk = (int) ($_POST['ticket_pk'] ?? 0);
      $quoteId = (int) ($_POST['id_cotizacion'] ?? 0);
      $context = $this->maintenance_quote_form_context($mode, $ticketPk, $quoteId);
      $schema = new \SCM\Support\SchemaInspector($this->db);
      $quoteTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
      $ticketTable = $this->db->table('jet_cct_tickets');
      if (!$schema->tableExists($quoteTable) || !$schema->tableExists($ticketTable)) {
        throw new \DomainException('No está disponible la tabla de cotizaciones o tickets.');
      }

      $ticket = is_array($context['ticket_raw'] ?? null) ? $context['ticket_raw'] : [];
      $revision = is_array($context['revision_raw'] ?? null) ? $context['revision_raw'] : [];
      $sourceQuote = is_array($context['cotizacion_raw'] ?? null) ? $context['cotizacion_raw'] : [];
      $actor = $this->ticketCompletionActor();
      $employeeId = trim((string) ($actor['employee_id'] ?? ''));
      if ($employeeId === '' && method_exists($this, 'current_employee_id')) {
        $employeeId = trim((string) $this->current_employee_id());
      }
      if ($employeeId === '') {
        $employeeId = (string) Auth::userId();
      }
      [$now, $nowSql] = $this->maintenance_quote_now_pair();

      $itemsMano = $this->maintenance_quote_repeater_items('items_mano_json', ['descripcion_mano', 'unidad_mano', 'cantidad_mano', 'valor_mano']);
      $itemsMateriales = $this->maintenance_quote_repeater_items('items_materiales_json', ['item_materiales', 'descripcion_materiales', 'unidad_materiales', 'cantidad_materiales', 'valor_unitario_materiales', 'valor_materiales', 'valor_total_materiales', 'provedor_materiales', 'proveedor_materiales']);
      $itemsMateriales = $this->maintenance_quote_merge_generated_material_rows($itemsMateriales, $this->maintenance_quote_generated_material_offer_rows('materiales_oferta_image'));
      $itemsEquipos = $this->maintenance_quote_repeater_items('items_otros_equi_json', ['descipcion_otros_equi', 'unidad_otros_equi', 'cantidad_otros_equi', 'valor_otros_equi']);
      $itemsOtros = $this->maintenance_quote_repeater_items('items_otros_costos_json', ['descipcion_otros_costos', 'unidad_otros_costos', 'cantidad_otros_costos', 'valor_otros_costos']);

      $totals = [
        'total_mano_obra' => $this->maintenance_quote_total_items($itemsMano, 'cantidad_mano', 'valor_mano'),
        'total_materiales' => $this->maintenance_quote_total_materials($itemsMateriales),
        'total_maquinarias' => $this->maintenance_quote_total_items($itemsEquipos, 'cantidad_otros_equi', 'valor_otros_equi'),
        'total_otros_costos' => $this->maintenance_quote_total_items($itemsOtros, 'cantidad_otros_costos', 'valor_otros_costos'),
      ];
      $totals['total'] = $totals['total_mano_obra'] + $totals['total_materiales'] + $totals['total_maquinarias'] + $totals['total_otros_costos'];
      if ($totals['total'] <= 0) {
        throw new \DomainException('Agrega al menos un valor a la cotización.');
      }
      $usedBalances = $mode === 'edit' ? $this->maintenance_quote_order_used_balances($schema, $quoteId) : [];

      $destinatario = $this->maintenance_quote_clean($_POST['destinatario'] ?? ($context['defaults']['destinatario'] ?? ''));
      $emailDestinatario = $this->maintenance_quote_clean($_POST['email_destinatario'] ?? ($context['defaults']['email_destinatario'] ?? ''));
      $celularDestinatario = $this->maintenance_quote_digits($_POST['celular_destinatario'] ?? ($context['defaults']['celular_destinatario'] ?? ''));
      if ($destinatario === '') {
        throw new \DomainException('Completa el destinatario de la cotización.');
      }

      $mejorOferta = $this->maintenance_quote_existing_media('mejor_oferta_existing', $sourceQuote['mejor_oferta'] ?? '');
      $otrasOferta = $this->maintenance_quote_existing_media('otras_oferta_existing', $sourceQuote['otras_oferta'] ?? '');
      $newMejor = $this->maintenance_quote_store_media('mejor_oferta', 10);
      $newOtras = $this->maintenance_quote_store_media('otras_oferta', 10);
      $storedPhotos = array_merge($storedPhotos, $newMejor, $newOtras);
      $materialesOfertaImages = $this->maintenance_quote_store_generated_materials_images('materiales_oferta_image');
      foreach ($materialesOfertaImages as $materialesOfertaImage) {
        $storedPhotos[] = $materialesOfertaImage;
        $newMejor[] = $materialesOfertaImage;
      }
      $mejorOferta = array_merge($mejorOferta, $this->maintenance_quote_stored_media_refs($newMejor));
      $otrasOferta = array_merge($otrasOferta, $this->maintenance_quote_stored_media_refs($newOtras));

      $tipoMantenimiento = $this->maintenance_quote_clean($_POST['tipo_mantenimiento'] ?? ($context['tipo_mantenimiento'] ?? 'Correctiva'));
      $tipoMantenimiento = stripos($tipoMantenimiento, 'prevent') !== false ? 'Preventiva' : 'Correctiva';
      $category = $mode === 'note' ? 'Nota' : ($mode === 'edit' ? trim((string) ($sourceQuote['categoria_cotizacion'] ?? 'Inicial')) : 'Inicial');
      if ($category === '') {
        $category = 'Inicial';
      }

      $idRevision = (int) ($context['revision_id'] ?? 0);
      $ticketId = trim((string) ($ticket['_ID'] ?? $ticketPk));
      $quoteData = [
        'cct_status' => 'publish',
        'items_mano' => serialize($itemsMano),
        'items_materiales' => serialize($itemsMateriales),
        'items_otros_equi' => serialize($itemsEquipos),
        'items_otros_costos' => serialize($itemsOtros),
        'total_mano_obra' => $totals['total_mano_obra'],
        'total_materiales' => $totals['total_materiales'],
        'total_maquinarias' => $totals['total_maquinarias'],
        'total_otros_costos' => $totals['total_otros_costos'],
        'total' => $totals['total'],
        'saldo_obra' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_obra', $totals['total_mano_obra'], $usedBalances['saldo_obra'] ?? null) : $totals['total_mano_obra'],
        'saldo_materiales' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_materiales', $totals['total_materiales'], $usedBalances['saldo_materiales'] ?? null) : $totals['total_materiales'],
        'saldo_maquinarias' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_maquinarias', $totals['total_maquinarias'], $usedBalances['saldo_maquinarias'] ?? null) : $totals['total_maquinarias'],
        'saldo_otros_costo' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_otros_costo', $totals['total_otros_costos'], $usedBalances['saldo_otros_costo'] ?? null) : $totals['total_otros_costos'],
        'observaciones' => wp_kses_post(wp_unslash((string) ($_POST['observaciones'] ?? ''))),
        'valides_oferta' => $this->maintenance_quote_number($_POST['valides_oferta'] ?? '0'),
        'duracion' => $this->maintenance_quote_number($_POST['duracion'] ?? '0'),
        'mejor_oferta' => implode(',', array_values(array_unique(array_filter($mejorOferta)))),
        'otras_oferta' => implode(',', array_values(array_unique(array_filter($otrasOferta)))),
        'celular_destinatario' => $celularDestinatario,
        'email_destinatario' => $emailDestinatario,
        'cct_author_id' => $employeeId,
        'cct_modified' => $nowSql,
        'fecha' => $mode === 'edit' ? (int) ($sourceQuote['fecha'] ?? $now) : $now,
        'se_envio' => 'No',
        'creador' => trim((string) ($actor['name'] ?? Auth::user())),
        'email_creador' => trim((string) ($actor['email'] ?? '')),
        'celular_creador' => trim((string) ($actor['phone'] ?? '')),
        'coordinador' => trim((string) ($actor['name'] ?? Auth::user())),
        'email_coordinador' => trim((string) ($actor['email'] ?? '')),
        'celular_coordinador' => trim((string) ($actor['phone'] ?? '')),
        'destinatario' => $destinatario,
        'id_ticket' => $ticketId,
        'id_inmueble' => $this->maintenance_quote_first([$revision['id_inmueble'] ?? '', $ticket['id_inmueble'] ?? '', $sourceQuote['id_inmueble'] ?? '']),
        'direccion' => $this->maintenance_quote_clean($_POST['direccion'] ?? ($revision['direccion'] ?? $ticket['direccion'] ?? $sourceQuote['direccion'] ?? '')),
        'perturbacion' => $this->maintenance_quote_number($_POST['perturbacion'] ?? '0'),
        'area_afectada' => $this->maintenance_quote_number($_POST['area_afectada'] ?? '0'),
        'valor_bonificacion' => $this->maintenance_quote_number($_POST['valor_bonificacion'] ?? '0'),
        'categoria_cotizacion' => $category,
        'contrato' => ltrim($this->maintenance_quote_first([$revision['contrato'] ?? '', $ticket['contrato'] ?? '', $sourceQuote['contrato'] ?? '']), '#'),
        'ejecutado' => $this->maintenance_quote_clean($_POST['ejecutado'] ?? ($sourceQuote['ejecutado'] ?? '')),
        'id_empleado' => $employeeId,
        'id_propietario' => $this->maintenance_quote_first([$revision['id_propietario'] ?? '', $ticket['id_propietario'] ?? '', $sourceQuote['id_propietario'] ?? '']),
        'id_coordinador' => $employeeId,
        'inmueble' => $this->maintenance_quote_first([$revision['inmueble'] ?? '', $ticket['inmueble'] ?? '', $sourceQuote['inmueble'] ?? '']),
        'id_arrendatario' => $this->maintenance_quote_first([$revision['id_arrendatario'] ?? '', $ticket['id_arrendatario'] ?? '', $sourceQuote['id_arrendatario'] ?? '']),
        'id_contrato' => $this->maintenance_quote_first([$revision['id_contrato'] ?? '', $ticket['id_contrato'] ?? '', $sourceQuote['id_contrato'] ?? '']),
        'sucursal' => $this->maintenance_quote_first([$revision['sucursal'] ?? '', $ticket['sucursal'] ?? '', $sourceQuote['sucursal'] ?? '1']),
        'estado' => '',
        'estado_respuesta_cotizacion_mantenimiento' => '',
        'fecha_respuesta' => 0,
        'observacion_respuesta' => '',
        'motivo' => '',
        'fecha_envio' => 0,
        'tuvo_seguimiento' => 'No',
        'tipo_mantenimiento' => $tipoMantenimiento,
        'id_revision' => $idRevision > 0 ? (string) $idRevision : '',
        'tipo_inmueble' => $this->maintenance_quote_clean($_POST['tipo_inmueble'] ?? ($revision['tip_inm'] ?? $revision['tipo_inmueble'] ?? $sourceQuote['tipo_inmueble'] ?? '')),
        'tipo_negocio' => $this->maintenance_quote_clean($_POST['tipo_negocio'] ?? ($revision['tipo_negocio'] ?? $sourceQuote['tipo_negocio'] ?? '')),
        'destinacion' => $this->maintenance_quote_clean($_POST['destinacion'] ?? ($revision['destinacion'] ?? $sourceQuote['destinacion'] ?? '')),
        'justificacion_perturbacion' => wp_kses_post(wp_unslash((string) ($_POST['justificacion_perturbacion'] ?? ''))),
        'indicativo_destinarario' => $this->maintenance_quote_clean($_POST['indicativo_destinarario'] ?? ($sourceQuote['indicativo_destinarario'] ?? '57')),
        'resumen_calculo_perturbacion' => wp_kses_post(wp_unslash((string) ($_POST['resumen_calculo_perturbacion'] ?? ''))),
        'dias_afectacion_calculados' => $this->maintenance_quote_number($_POST['dias_afectacion_calculados'] ?? '0'),
      ];

      $pdo->beginTransaction();
      $quoteIdSaved = $quoteId;
      $postSaveWarnings = [];
      $warnPostSave = static function (string $label, \Throwable $error) use (&$postSaveWarnings): void {
        $postSaveWarnings[] = $label;
        error_log('[cotizacion_mantenimiento_save] ' . $label . ': ' . $error->getMessage());
      };
      if ($mode === 'edit') {
        if ($quoteId <= 0 || empty($sourceQuote)) {
          throw new \DomainException('Cotización inválida para editar.');
        }
        unset($quoteData['cct_created']);
        $filtered = $schema->filterTableData($quoteTable, $quoteData);
        if (!$filtered || $this->db->update($quoteTable, $filtered, ['_ID' => $quoteId]) < 0) {
          throw new \DomainException('No fue posible actualizar la cotización.');
        }
      } else {
        $quoteData['cct_created'] = $nowSql;
        $filtered = $schema->filterTableData($quoteTable, $quoteData);
        if (!$filtered || !$this->db->insert($quoteTable, $filtered)) {
          throw new \DomainException('No fue posible crear la cotización.');
        }
        $quoteIdSaved = (int) $this->db->lastInsertId();
        if ($quoteIdSaved <= 0) {
          throw new \DomainException('La cotización se guardó sin identificador válido.');
        }
      }

      $autoRejected = 0;
      try {
        $this->maintenance_quote_update_revision_flag($schema, $tipoMantenimiento, $idRevision);
      } catch (\Throwable $error) {
        $warnPostSave('no se pudo marcar la revisión con cotización', $error);
      }
      try {
        if ($mode === 'create') {
          $autoRejected = $this->maintenance_quote_disapprove_previous_quotes($schema, $quoteIdSaved, $quoteData, $employeeId, $nowSql);
        }
      } catch (\Throwable $error) {
        $warnPostSave('no se pudieron desaprobar cotizaciones anteriores', $error);
      }
      $reportId = 0;
      try {
        if ($mode === 'create') {
          $reportId = $this->maintenance_quote_ensure_admin_report($schema, $quoteIdSaved, $quoteData, $ticket, $revision, $actor, $employeeId, $now, $nowSql);
        }
      } catch (\Throwable $error) {
        $warnPostSave('no se pudo crear/verificar el reporte administrativo', $error);
      }
      try {
        $this->maintenance_quote_update_ticket($schema, $ticket, $quoteIdSaved, $tipoMantenimiento, $now, $nowSql);
      } catch (\Throwable $error) {
        $warnPostSave('no se pudo actualizar el ticket', $error);
      }
      try {
        $this->maintenance_quote_insert_histories($schema, $quoteIdSaved, $mode, $quoteData, $ticket, $actor, $employeeId, $now, $nowSql);
      } catch (\Throwable $error) {
        $warnPostSave('no se pudo registrar el historial', $error);
      }
      $queued = 0;
      try {
        $queued = $this->maintenance_quote_enqueue_saved_notifications($mode, $quoteIdSaved, $quoteData, $actor);
      } catch (\Throwable $error) {
        $warnPostSave('no se pudieron encolar las notificaciones', $error);
      }
      $pdo->commit();

      $message = ($mode === 'edit' ? 'Cotización actualizada.' : ($mode === 'note' ? 'Nota de cotización creada.' : 'Cotización creada.'))
        . ($autoRejected > 0 ? ' Cotizaciones anteriores desaprobadas: ' . $autoRejected . '.' : '')
        . ($reportId > 0 ? ' Reporte administrativo #' . $reportId . ' creado.' : '')
        . ($queued > 0 ? ' Notificaciones en cola: ' . $queued . '.' : '');
      if (!empty($postSaveWarnings)) {
        $message .= ' Guardada con advertencias: ' . implode('; ', array_values(array_unique($postSaveWarnings))) . '.';
      }
      $this->jsonOk([
        'message' => $message,
        'id_cotizacion' => (string) $quoteIdSaved,
        'id_reporte' => $reportId > 0 ? (string) $reportId : '',
        'auto_rejected' => (string) $autoRejected,
        'warnings' => $postSaveWarnings,
      ]);
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $quotePersisted = $quoteIdSaved > 0
        && $quoteTable !== ''
        && $this->maintenance_quote_persisted_after_error($quoteTable, $quoteIdSaved, $storedPhotos);
      if ($storedPhotos && !$quotePersisted) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
      }
      if ($quotePersisted) {
        error_log('[cotizacion_mantenimiento_save] Cotización #' . $quoteIdSaved . ' persistió después de error: ' . $error->getMessage());
        $this->jsonOk([
          'message' => ($mode === 'edit' ? 'Cotización actualizada.' : 'Cotización creada.') . ' Se detectó una advertencia posterior al guardado; recarga el caso para verla actualizada.',
          'id_cotizacion' => (string) $quoteIdSaved,
          'id_reporte' => '',
          'auto_rejected' => '',
          'warnings' => ['advertencia posterior al guardado'],
        ]);
      }
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo guardar la cotización.');
    }
  }

  private function maintenance_quote_mode($raw): string
  {
    $mode = strtolower(trim((string) ($raw ?? 'create')));
    if (in_array($mode, ['create', 'edit', 'note'], true)) {
      return $mode;
    }
    throw new \DomainException('Modo de cotización inválido.');
  }

  /** @return array{0:int,1:string} */
  private function maintenance_quote_now_pair(): array
  {
    if (function_exists('current_time')) {
      $timestamp = (int) current_time('timestamp');
      $mysql = (string) current_time('mysql');
      if ($timestamp > 0 && $mysql !== '') {
        return [$timestamp, $mysql];
      }
    }
    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'America/Bogota');
    $now = new \DateTimeImmutable('now', $timezone);
    return [$now->getTimestamp(), $now->format('Y-m-d H:i:s')];
  }

  /** @return array<string,mixed> */
  private function maintenance_quote_form_context(string $mode, int $ticketPk, int $quoteId): array
  {
    $schema = new \SCM\Support\SchemaInspector($this->db);
    $ticketTable = $this->db->table('jet_cct_tickets');
    $quoteTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$schema->tableExists($ticketTable) || !$schema->tableExists($quoteTable)) {
      throw new \DomainException('No están disponibles las tablas de tickets o cotizaciones.');
    }

    $quote = [];
    if ($mode !== 'create') {
      if ($quoteId <= 0) {
        throw new \DomainException('Selecciona una cotización válida.');
      }
      $found = $this->db->getRow("SELECT * FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
      if (!is_array($found)) {
        throw new \DomainException('Cotización no encontrada.');
      }
      $quote = $found;
      if ($mode === 'edit' && strtolower(trim((string) ($quote['estado'] ?? ''))) === 'desaprobada') {
        throw new \DomainException('No se puede editar una cotización desaprobada.');
      }
      if ($ticketPk <= 0) {
        $ticketPk = (int) ($quote['id_ticket'] ?? 0);
      }
    }

    $ticket = null;
    if ($ticketPk > 0) {
      $ticket = $this->db->getRow("SELECT * FROM `{$ticketTable}` WHERE `_ID` = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketPk, (string) $ticketPk]);
    }
    if (!is_array($ticket) && !empty($quote['id_ticket'])) {
      $ticketRef = trim((string) $quote['id_ticket']);
      $ticket = ctype_digit($ticketRef)
        ? $this->db->getRow("SELECT * FROM `{$ticketTable}` WHERE `_ID` = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [(int) $ticketRef, $ticketRef])
        : $this->db->getRow("SELECT * FROM `{$ticketTable}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketRef]);
    }
    if (!is_array($ticket)) {
      throw new \DomainException('Ticket inválido.');
    }

    [$revision, $tipoMantenimiento, $revisionId] = $this->maintenance_quote_resolve_revision($ticket, $quote);
    if ($mode === 'create' && $revisionId <= 0) {
      throw new \DomainException('Solo puedes crear cotización si el caso tiene revisión correctiva o preventiva con daños.');
    }

    $actor = $this->ticketCompletionActor();
    $recipientOptions = $this->maintenance_quote_recipient_options($ticket, is_array($revision) ? $revision : [], $quote);
    $defaultExecutor = $this->maintenance_quote_first([$quote['ejecutado'] ?? '', $revision['ejecutado'] ?? '', 'Inmobiliaria']);
    $defaultRecipient = $recipientOptions[$this->maintenance_quote_executor_key($defaultExecutor)] ?? [];
    $defaultDestinatario = $this->maintenance_quote_first([
      $quote['destinatario'] ?? '',
      $defaultRecipient['destinatario'] ?? '',
      $revision['destinatario'] ?? '',
      $ticket['propietario'] ?? '',
      $ticket['arrendatario'] ?? '',
    ]);
    $defaultEmail = $this->maintenance_quote_first([
      $quote['email_destinatario'] ?? '',
      $defaultRecipient['email_destinatario'] ?? '',
      $revision['email_destinatario'] ?? '',
      $ticket['correo'] ?? '',
      $ticket['email'] ?? '',
    ]);
    $defaultCelular = $this->maintenance_quote_first([
      $quote['celular_destinatario'] ?? '',
      $defaultRecipient['celular_destinatario'] ?? '',
      $revision['celular_destinatario'] ?? '',
      $ticket['celular'] ?? '',
      $ticket['telefono'] ?? '',
    ]);
    $ticketId = trim((string) ($ticket['_ID'] ?? $ticketPk));
    $logicalTicket = trim((string) ($ticket['id_ticket'] ?? $ticketId));
    $cotizacion = [
      'id' => trim((string) ($quote['_ID'] ?? '')),
      'categoria' => trim((string) ($quote['categoria_cotizacion'] ?? ($mode === 'note' ? 'Nota' : 'Inicial'))),
      'items_mano' => $this->maintenance_quote_decode_items($quote['items_mano'] ?? ''),
      'items_materiales' => $this->maintenance_quote_decode_items($quote['items_materiales'] ?? ''),
      'items_otros_equi' => $this->maintenance_quote_decode_items($quote['items_otros_equi'] ?? ''),
      'items_otros_costos' => $this->maintenance_quote_decode_items($quote['items_otros_costos'] ?? ''),
      'totals' => [
        'mano' => $this->maintenance_quote_number($quote['total_mano_obra'] ?? 0),
        'materiales' => $this->maintenance_quote_number($quote['total_materiales'] ?? 0),
        'maquinarias' => $this->maintenance_quote_number($quote['total_maquinarias'] ?? 0),
        'otros' => $this->maintenance_quote_number($quote['total_otros_costos'] ?? 0),
        'total' => $this->maintenance_quote_number($quote['total'] ?? 0),
      ],
      'media' => [
        'mejor_oferta' => $this->maintenance_quote_media_list($quote['mejor_oferta'] ?? ''),
        'otras_oferta' => $this->maintenance_quote_media_list($quote['otras_oferta'] ?? ''),
      ],
    ];
    $existingQuotes = $mode === 'create'
      ? $this->maintenance_quote_existing_quotes_for_context($quoteTable, $ticket, $revisionId, $tipoMantenimiento)
      : [];
    $perturbationContext = $this->maintenance_quote_perturbation_context($ticket, is_array($revision) ? $revision : [], $quote);

    return [
      'mode' => $mode,
      'tipo_mantenimiento' => $tipoMantenimiento,
      'revision_id' => $revisionId > 0 ? (string) $revisionId : '',
      'ticket' => [
        'id' => $ticketId,
        'numero' => $logicalTicket,
        'inmueble' => $this->maintenance_quote_first([$revision['inmueble'] ?? '', $ticket['inmueble'] ?? '', $quote['inmueble'] ?? '']),
        'id_inmueble' => $this->maintenance_quote_first([$revision['id_inmueble'] ?? '', $ticket['id_inmueble'] ?? '', $quote['id_inmueble'] ?? '']),
        'contrato' => ltrim($this->maintenance_quote_first([$revision['contrato'] ?? '', $ticket['contrato'] ?? '', $quote['contrato'] ?? '']), '#'),
        'direccion' => $this->maintenance_quote_first([$quote['direccion'] ?? '', $revision['direccion'] ?? '', $ticket['direccion'] ?? '']),
      ],
      'defaults' => [
        'destinatario' => $defaultDestinatario,
        'email_destinatario' => $defaultEmail,
        'indicativo_destinarario' => $this->maintenance_quote_first([$quote['indicativo_destinarario'] ?? '', $defaultRecipient['indicativo_destinarario'] ?? '', '57']),
        'celular_destinatario' => $defaultCelular,
        'ejecutado' => $defaultExecutor,
        'valides_oferta' => trim((string) ($quote['valides_oferta'] ?? '')),
        'duracion' => trim((string) ($quote['duracion'] ?? '')),
        'observaciones' => trim((string) ($quote['observaciones'] ?? '')),
        'perturbacion' => trim((string) ($quote['perturbacion'] ?? '')),
        'area_afectada' => trim((string) ($quote['area_afectada'] ?? '')),
        'valor_bonificacion' => trim((string) ($quote['valor_bonificacion'] ?? '')),
        'justificacion_perturbacion' => trim((string) ($quote['justificacion_perturbacion'] ?? '')),
        'resumen_calculo_perturbacion' => trim((string) ($quote['resumen_calculo_perturbacion'] ?? '')),
        'dias_afectacion_calculados' => trim((string) ($quote['dias_afectacion_calculados'] ?? '')),
        'tipo_inmueble' => trim((string) ($quote['tipo_inmueble'] ?? $revision['tip_inm'] ?? $revision['tipo_inmueble'] ?? '')),
        'tipo_negocio' => trim((string) ($quote['tipo_negocio'] ?? $revision['tipo_negocio'] ?? '')),
        'destinacion' => trim((string) ($quote['destinacion'] ?? $revision['destinacion'] ?? '')),
      ],
      'cotizacion' => $cotizacion,
      'unit_options' => $this->maintenance_quote_unit_options(),
      'material_unit_options' => $this->maintenance_quote_material_unit_options(),
      'executor_options' => $this->maintenance_quote_glossary_options(853, [
        ['value' => 'Propietario', 'label' => 'Propietario'],
        ['value' => 'Arrendatario', 'label' => 'Arrendatario'],
        ['value' => 'Inmobiliaria', 'label' => 'Inmobiliaria'],
      ]),
      'recipient_options' => $recipientOptions,
      'existing_quotes' => $existingQuotes,
      'will_disapprove_previous' => $mode === 'create' && !empty($existingQuotes),
      'perturbation_context' => $perturbationContext,
      'actor' => [
        'name' => trim((string) ($actor['name'] ?? Auth::user())),
        'employee_id' => trim((string) ($actor['employee_id'] ?? '')),
      ],
      'ticket_raw' => $ticket,
      'revision_raw' => is_array($revision) ? $revision : [],
      'cotizacion_raw' => $quote,
    ];
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,array<string,string>> */
  private function maintenance_quote_recipient_options(array $ticket, array $revision, array $quote): array
  {
    $contract = $this->maintenance_quote_contract_row($ticket, $revision, $quote);
    $owner = $this->maintenance_quote_owner_contact($contract, $ticket, $revision, $quote);
    $tenant = $this->maintenance_quote_tenant_contact($contract, $ticket, $revision, $quote);
    $community = $this->maintenance_quote_community_contact($contract, $owner, $ticket, $revision, $quote);
    return [
      'propietario' => $owner,
      'inmobiliaria' => $owner,
      'arrendatario' => $tenant,
      'copropiedad' => $community,
      'externo' => [
        'destinatario' => '',
        'email_destinatario' => '',
        'celular_destinatario' => '',
        'indicativo_destinarario' => '',
        'indicativo_destinatario' => '',
      ],
    ];
  }

  /** @return array<int,array{id:string,estado:string,fecha:string,total:string}> */
  private function maintenance_quote_existing_quotes_for_context(string $quoteTable, array $ticket, int $revisionId, string $tipoMantenimiento): array
  {
    $ticketRef = trim((string) ($ticket['_ID'] ?? ''));
    if ($ticketRef === '' || !$this->table_exists($quoteTable)) {
      return [];
    }
    $where = ['TRIM(COALESCE(`id_ticket`, "")) = ?'];
    $params = [$ticketRef];
    if ($revisionId > 0) {
      $where[] = 'TRIM(COALESCE(`id_revision`, "")) = ?';
      $params[] = (string) $revisionId;
    }
    if ($tipoMantenimiento !== '') {
      $where[] = 'LOWER(TRIM(COALESCE(`tipo_mantenimiento`, ""))) = ?';
      $params[] = strtolower(trim($tipoMantenimiento));
    }
    $where[] = "LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN ('desaprobada','eliminada','anulada')";
    $rows = $this->db->getResults(
      "SELECT `_ID`, `estado`, `fecha`, `total` FROM `{$quoteTable}` WHERE " . implode(' AND ', $where) . " ORDER BY `_ID` DESC LIMIT 8",
      $params
    );
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'id' => trim((string) ($row['_ID'] ?? '')),
        'estado' => trim((string) ($row['estado'] ?? 'Sin estado')),
        'fecha' => trim((string) ($row['fecha'] ?? '')),
        'total' => trim((string) ($row['total'] ?? '0')),
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $quoteData */
  private function maintenance_quote_disapprove_previous_quotes(\SCM\Support\SchemaInspector $schema, int $newQuoteId, array $quoteData, string $employeeId, string $nowSql): int
  {
    $quoteTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if ($newQuoteId <= 0 || !$schema->tableExists($quoteTable)) {
      return 0;
    }
    $ticketRef = trim((string) ($quoteData['id_ticket'] ?? ''));
    if ($ticketRef === '') {
      return 0;
    }
    $where = ['`_ID` <> ?', 'TRIM(COALESCE(`id_ticket`, "")) = ?'];
    $params = [$newQuoteId, $ticketRef];
    $revisionId = trim((string) ($quoteData['id_revision'] ?? ''));
    if ($revisionId !== '') {
      $where[] = 'TRIM(COALESCE(`id_revision`, "")) = ?';
      $params[] = $revisionId;
    }
    $tipo = strtolower(trim((string) ($quoteData['tipo_mantenimiento'] ?? '')));
    if ($tipo !== '') {
      $where[] = 'LOWER(TRIM(COALESCE(`tipo_mantenimiento`, ""))) = ?';
      $params[] = $tipo;
    }
    $where[] = "LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN ('desaprobada','eliminada','anulada')";
    $ids = array_values(array_filter(array_map('intval', array_column(
      $this->db->getResults("SELECT `_ID` FROM `{$quoteTable}` WHERE " . implode(' AND ', $where), $params),
      '_ID'
    ))));
    if ($ids === []) {
      return 0;
    }
    $update = $schema->filterTableData($quoteTable, [
      'estado' => 'Desaprobada',
      'respuesta' => 'Desaprobada',
      'motivo_desaprobacion' => 'Reemplazada por cotización #' . $newQuoteId,
      'observacion_desaprobacion' => 'Se marcó automáticamente como desaprobada al crear una nueva cotización para el mismo caso/revisión.',
      'id_empleado' => $employeeId,
      'cct_modified' => $nowSql,
    ]);
    if (empty($update)) {
      return 0;
    }
    $affected = 0;
    foreach ($ids as $id) {
      if ($id > 0 && $this->db->update($quoteTable, $update, ['_ID' => $id]) >= 0) {
        $affected++;
      }
    }
    return $affected;
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,mixed> */
  private function maintenance_quote_perturbation_context(array $ticket, array $revision, array $quote): array
  {
    $idInmueble = $this->maintenance_quote_first([$quote['id_inmueble'] ?? '', $revision['id_inmueble'] ?? '', $ticket['id_inmueble'] ?? '', $quote['inmueble'] ?? '', $revision['inmueble'] ?? '', $ticket['inmueble'] ?? '']);
    $property = [];
    $table = $this->db->table('jet_cct_inmuebles');
    if ($idInmueble !== '' && $this->table_exists($table)) {
      $property = $this->db->getRow("SELECT `_ID`, `codigo`, `tipo_inmueble`, `precio_arriendo`, `precio_admin`, `area_construida` FROM `{$table}` WHERE `_ID` = ? OR TRIM(COALESCE(`codigo`, '')) = ? LIMIT 1", [(int) $idInmueble, $idInmueble]) ?: [];
    }
    $createdRaw = trim((string) ($ticket['cct_created'] ?? ''));
    $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : 0;
    [$now] = $this->maintenance_quote_now_pair();
    $diasDesdeTicket = $createdTs > 0 ? (int) ceil(max(0, $now - $createdTs) / 86400) : 0;
    $precioArriendo = $this->maintenance_quote_number($property['precio_arriendo'] ?? 0);
    $precioAdmin = $this->maintenance_quote_number($property['precio_admin'] ?? 0);
    return [
      'codigo' => trim((string) ($property['codigo'] ?? $idInmueble)),
      'tipo_inmueble' => trim((string) ($property['tipo_inmueble'] ?? $quote['tipo_inmueble'] ?? $revision['tip_inm'] ?? $revision['tipo_inmueble'] ?? '')),
      'precio_arriendo' => (int) round($precioArriendo),
      'precio_admin' => (int) round($precioAdmin),
      'canon_total' => (int) round($precioArriendo + $precioAdmin),
      'area_construida' => (float) $this->maintenance_quote_number($property['area_construida'] ?? 0),
      'id_ticket' => trim((string) ($ticket['_ID'] ?? $quote['id_ticket'] ?? '')),
      'fecha_ticket_texto' => $createdTs > 0 ? date('d/m/Y H:i', $createdTs) : '',
      'fecha_cot_texto' => date('d/m/Y H:i', $now),
      'dias_desde_ticket' => $diasDesdeTicket,
    ];
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,mixed> */
  private function maintenance_quote_contract_row(array $ticket, array $revision, array $quote): array
  {
    $contractId = (int) $this->maintenance_quote_first([
      $quote['id_contrato'] ?? '',
      $revision['id_contrato'] ?? '',
      $ticket['id_contrato'] ?? '',
      $quote['id_contrato_arrendamiento'] ?? '',
      $revision['id_contrato_arrendamiento'] ?? '',
      $ticket['id_contrato_arrendamiento'] ?? '',
    ]);
    if ($contractId <= 0) {
      return [];
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$contractId]);
    return is_array($row) ? $row : [];
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,string> */
  private function maintenance_quote_owner_contact(array $contract, array $ticket, array $revision, array $quote): array
  {
    $ownerId = (int) $this->maintenance_quote_first([$contract['id_propietario'] ?? '', $revision['id_propietario'] ?? '', $ticket['id_propietario'] ?? '', $quote['id_propietario'] ?? '']);
    $owner = [];
    $table = $this->db->table('jet_cct_propietarios');
    if ($ownerId > 0 && $this->table_exists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$ownerId]);
      if (is_array($row)) {
        $owner = $row;
      }
    }
    return $this->maintenance_quote_contact_payload(
      [$owner, $contract, $revision, $ticket, $quote],
      ['nombre', 'nombre_juridico', 'propietario', 'destinatario'],
      ['correo', 'email', 'email_propietario', 'correo_propietario', 'email_destinatario'],
      ['celular', 'telefono', 'celular_propietario', 'telefono_propietario', 'celular_destinatario'],
      ['indicativo', 'indicativo_propietario', 'indicativo_destinarario', 'indicativo_destinatario']
    );
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,string> */
  private function maintenance_quote_tenant_contact(array $contract, array $ticket, array $revision, array $quote): array
  {
    $tenantId = $this->maintenance_quote_first([$contract['id_arrendatario'] ?? '', $revision['id_arrendatario'] ?? '', $ticket['id_arrendatario'] ?? '', $quote['id_arrendatario'] ?? '']);
    $tenant = [];
    $table = $this->db->table('jet_cct_arrendatarios');
    if ($tenantId !== '' && $this->table_exists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_arrendatario`, '')) = ? LIMIT 1", [$tenantId]);
      if (is_array($row)) {
        $tenant = $row;
      }
    }
    return $this->maintenance_quote_contact_payload(
      [$tenant, $contract, $revision, $ticket, $quote],
      ['nombre', 'nombre_juridico', 'arrendatario', 'destinatario'],
      ['correo', 'email', 'email_arrendatario', 'correo_arrendatario', 'email_destinatario'],
      ['celular', 'telefono', 'celular_arrendatario', 'telefono_arrendatario', 'celular_destinatario'],
      ['indicativo', 'indicativo_arrendatario', 'indicativo_destinarario', 'indicativo_destinatario']
    );
  }

  /** @param array<string,mixed> $contract @param array<string,string> $owner @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $quote @return array<string,string> */
  private function maintenance_quote_community_contact(array $contract, array $owner, array $ticket, array $revision, array $quote): array
  {
    $communityId = (int) $this->maintenance_quote_first([$contract['id_copropiedad'] ?? '', $revision['id_copropiedad'] ?? '', $ticket['id_copropiedad'] ?? '', $quote['id_copropiedad'] ?? '']);
    $community = [];
    $table = $this->db->table('jet_cct_copropiedades');
    if ($communityId > 0 && $this->table_exists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$communityId]);
      if (is_array($row)) {
        $community = $row;
      }
    }
    $contact = $this->maintenance_quote_contact_payload(
      [$community, $contract, $revision, $ticket, $quote],
      ['administrador', 'copropiedad', 'nombre', 'destinatario'],
      ['correo', 'email', 'correo_copropiedad', 'email_copropiedad', 'email_destinatario'],
      ['contacto', 'celular', 'telefono', 'celular_copropiedad', 'telefono_copropiedad', 'celular_destinatario'],
      ['indicativo', 'indicativo_copropiedad', 'indicativo_destinarario', 'indicativo_destinatario']
    );
    return trim($contact['destinatario']) !== '' ? $contact : $owner;
  }

  /** @param array<int,array<string,mixed>> $rows @param array<int,string> $nameKeys @param array<int,string> $emailKeys @param array<int,string> $phoneKeys @param array<int,string> $indicativeKeys @return array<string,string> */
  private function maintenance_quote_contact_payload(array $rows, array $nameKeys, array $emailKeys, array $phoneKeys, array $indicativeKeys): array
  {
    $indicative = $this->maintenance_quote_digits($this->maintenance_quote_contact_first($rows, $indicativeKeys));
    if ($indicative === '') {
      $indicative = '57';
    }
    return [
      'destinatario' => $this->maintenance_quote_clean($this->maintenance_quote_contact_first($rows, $nameKeys)),
      'email_destinatario' => $this->maintenance_quote_clean($this->maintenance_quote_contact_first($rows, $emailKeys)),
      'celular_destinatario' => $this->maintenance_quote_digits($this->maintenance_quote_contact_first($rows, $phoneKeys)),
      'indicativo_destinarario' => $indicative,
      'indicativo_destinatario' => $indicative,
    ];
  }

  /** @param array<int,array<string,mixed>> $rows @param array<int,string> $keys */
  private function maintenance_quote_contact_first(array $rows, array $keys): string
  {
    foreach ($rows as $row) {
      foreach ($keys as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  private function maintenance_quote_executor_key(string $value): string
  {
    $plain = function_exists('remove_accents') ? remove_accents($value) : strtr($value, [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'Á' => 'A',
      'É' => 'E',
      'Í' => 'I',
      'Ó' => 'O',
      'Ú' => 'U',
      'ñ' => 'n',
      'Ñ' => 'N',
    ]);
    $normalized = strtolower(trim((string) $plain));
    if (str_contains($normalized, 'propiet')) {
      return 'propietario';
    }
    if (str_contains($normalized, 'arrend')) {
      return 'arrendatario';
    }
    if (str_contains($normalized, 'coprop')) {
      return 'copropiedad';
    }
    if (str_contains($normalized, 'extern')) {
      return 'externo';
    }
    return str_contains($normalized, 'inmobili') ? 'inmobiliaria' : $normalized;
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $quote @return array{0:array<string,mixed>,1:string,2:int} */
  private function maintenance_quote_resolve_revision(array $ticket, array $quote = []): array
  {
    $quoteType = strtolower(trim((string) ($quote['tipo_mantenimiento'] ?? '')));
    if (str_contains($quoteType, 'prevent')) {
      $preventiveId = (int) $this->maintenance_quote_first([$quote['id_revision'] ?? '', $ticket['id_revision_preventiva'] ?? '']);
      if ($preventiveId > 0) {
        $table = $this->db->table('jet_cct_revision_preventiva');
        if ($this->table_exists($table)) {
          $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$preventiveId]);
          if (is_array($row) && $this->maintenance_quote_preventive_has_damage($row, $ticket)) {
            return [$row, 'Preventiva', $preventiveId];
          }
        }
      }
    }

    $correctiveId = (int) $this->maintenance_quote_first([$quote['id_revision'] ?? '', $ticket['id_revision_correctiva'] ?? '']);
    if ($correctiveId > 0) {
      $table = $this->db->table('jet_cct_revision_correctiva');
      if ($this->table_exists($table)) {
        $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$correctiveId]);
        if (is_array($row)) {
          return [$row, 'Correctiva', $correctiveId];
        }
      }
    }

    $preventiveId = (int) $this->maintenance_quote_first([$ticket['id_revision_preventiva'] ?? '', $quote['id_revision'] ?? '']);
    if ($preventiveId > 0) {
      $table = $this->db->table('jet_cct_revision_preventiva');
      if ($this->table_exists($table)) {
        $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$preventiveId]);
        if (is_array($row) && $this->maintenance_quote_preventive_has_damage($row, $ticket)) {
          return [$row, 'Preventiva', $preventiveId];
        }
      }
    }

    return [[], '', 0];
  }

  /** @param array<string,mixed> $revision @param array<string,mixed> $ticket */
  private function maintenance_quote_preventive_has_damage(array $revision, array $ticket): bool
  {
    foreach ([$revision['encontro_danos'] ?? null, $ticket['se_encontraron_danos'] ?? null] as $value) {
      $normalized = strtolower(trim((string) ($value ?? '')));
      if (in_array($normalized, ['si', 'sí', '1', 'true', 'yes', 'con daños', 'con danos'], true)) {
        return true;
      }
    }
    $evaluation = trim((string) ($revision['evaluacion_de_danos'] ?? ''));
    if ($evaluation === '') {
      return false;
    }
    $decoded = json_decode($evaluation, true);
    if (is_array($decoded)) {
      return count(array_filter($decoded)) > 0;
    }
    return strlen(strip_tags($evaluation)) > 3;
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_quote_unit_options(): array
  {
    return $this->maintenance_quote_glossary_options(612, [
      ['value' => 'Und', 'label' => 'Und'],
      ['value' => 'Global', 'label' => 'Global'],
      ['value' => 'M2', 'label' => 'M2'],
      ['value' => 'M3', 'label' => 'M3'],
      ['value' => 'Ml', 'label' => 'Ml'],
      ['value' => 'Dia', 'label' => 'Dia'],
      ['value' => 'Jornal', 'label' => 'Jornal'],
      ['value' => 'Kg', 'label' => 'Kg'],
      ['value' => 'Tramo', 'label' => 'Tramo'],
      ['value' => 'Lb', 'label' => 'Lb'],
    ], true);
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_quote_material_unit_options(): array
  {
    return $this->maintenance_quote_merge_options($this->maintenance_quote_unit_options(), [
      ['value' => 'Cm', 'label' => 'Cm'],
      ['value' => 'Galón', 'label' => 'Galón'],
      ['value' => 'Cuñete', 'label' => 'Cuñete'],
      ['value' => 'Lata', 'label' => 'Lata'],
      ['value' => 'Bulto', 'label' => 'Bulto'],
      ['value' => 'Caja', 'label' => 'Caja'],
      ['value' => 'Rollo', 'label' => 'Rollo'],
      ['value' => 'Par', 'label' => 'Par'],
      ['value' => 'Juego', 'label' => 'Juego'],
      ['value' => 'Servicio', 'label' => 'Servicio'],
    ]);
  }

  /** @param array<int,array<string,string>> $fallback @return array<int,array<string,string>> */
  private function maintenance_quote_glossary_options(int $glossaryId, array $fallback, bool $appendGlossaryOptions = false): array
  {
    $fallback = $this->maintenance_quote_normalize_options($fallback);
    if (method_exists($this, 'correctiveReviewGlossaryOptions')) {
      $options = $this->correctiveReviewGlossaryOptions($glossaryId, $fallback);
      if (is_array($options) && $options !== []) {
        $options = $this->maintenance_quote_normalize_options($options);
        return $appendGlossaryOptions ? $this->maintenance_quote_merge_options($fallback, $options) : $options;
      }
    }
    return $fallback;
  }

  /** @param array<mixed> $options @return array<int,array<string,string>> */
  private function maintenance_quote_normalize_options(array $options): array
  {
    $out = [];
    foreach ($options as $key => $option) {
      if (is_array($option)) {
        $value = trim((string) ($option['value'] ?? $option['val'] ?? ''));
        $label = trim((string) ($option['label'] ?? $option['title'] ?? $option['name'] ?? $value));
      } else {
        $value = trim((string) $key);
        $label = trim((string) $option);
        if (is_int($key)) {
          $value = $label;
        }
      }
      if ($value === '' && $label !== '') {
        $value = $label;
      }
      if ($label === '' && $value !== '') {
        $label = $value;
      }
      if ($value === '' || $label === '') {
        continue;
      }
      $out[] = ['value' => $value, 'label' => $label];
    }
    return $this->maintenance_quote_merge_options([], $out);
  }

  /** @param array<int,array<string,string>> $base @param array<int,array<string,string>> $extra @return array<int,array<string,string>> */
  private function maintenance_quote_merge_options(array $base, array $extra): array
  {
    $out = [];
    $seen = [];
    foreach (array_merge($base, $extra) as $option) {
      $value = trim((string) ($option['value'] ?? ''));
      $label = trim((string) ($option['label'] ?? $value));
      if ($value === '') {
        continue;
      }
      $key = strtolower($value);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
    }
    return $out;
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_quote_repeater_items(string $key, array $allowed): array
  {
    $raw = wp_unslash((string) ($_POST[$key] ?? '[]'));
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return [];
    }
    $items = [];
    foreach ($decoded as $row) {
      if (!is_array($row)) {
        continue;
      }
      $clean = [];
      $hasValue = false;
      foreach ($allowed as $field) {
        $value = in_array($field, ['cantidad_mano', 'valor_mano', 'cantidad_materiales', 'valor_unitario_materiales', 'valor_materiales', 'valor_total_materiales', 'cantidad_otros_equi', 'valor_otros_equi', 'cantidad_otros_costos', 'valor_otros_costos'], true)
          ? (string) (int) round($this->maintenance_quote_number($row[$field] ?? 0))
          : $this->maintenance_quote_clean($row[$field] ?? '');
        if ($field !== 'item_materiales' && trim($value) !== '' && trim($value) !== '0') {
          $hasValue = true;
        }
        $clean[$field] = $value;
      }
      if (!$hasValue) {
        continue;
      }
      if (isset($clean['cantidad_mano'], $clean['valor_mano'])) {
        $clean['valor_total_item_mo'] = (string) ((int) $clean['cantidad_mano'] * (int) $clean['valor_mano']);
      }
      if (isset($clean['cantidad_materiales'], $clean['valor_unitario_materiales'])) {
        $clean['valor_total_materiales'] = (string) ((int) $clean['cantidad_materiales'] * (int) $clean['valor_unitario_materiales']);
        if ((int) ($clean['valor_total_materiales'] ?? 0) <= 0 && (int) ($clean['valor_materiales'] ?? 0) > 0) {
          $clean['valor_total_materiales'] = $clean['valor_materiales'];
        } elseif (trim((string) ($clean['valor_materiales'] ?? '')) === '' || (int) ($clean['valor_materiales'] ?? 0) <= 0) {
          $clean['valor_materiales'] = $clean['valor_total_materiales'];
        }
      }
      if (isset($clean['proveedor_materiales']) && trim((string) ($clean['provedor_materiales'] ?? '')) === '') {
        $clean['provedor_materiales'] = $clean['proveedor_materiales'];
      }
      if (isset($clean['cantidad_otros_equi'], $clean['valor_otros_equi'])) {
        $clean['valor_total_otros_equi'] = (string) ((int) $clean['cantidad_otros_equi'] * (int) $clean['valor_otros_equi']);
      }
      if (isset($clean['cantidad_otros_costos'], $clean['valor_otros_costos'])) {
        $clean['valor_total_otros_costos'] = (string) ((int) $clean['cantidad_otros_costos'] * (int) $clean['valor_otros_costos']);
      }
      $items[] = $clean;
    }
    return $items;
  }

  /** @param array<int,array<string,string>> $items */
  private function maintenance_quote_total_items(array $items, string $quantityKey, string $valueKey): float
  {
    $total = 0.0;
    foreach ($items as $item) {
      $value = $this->maintenance_quote_number($item[$valueKey] ?? 0);
      $quantity = $quantityKey !== '' ? max(1.0, $this->maintenance_quote_number($item[$quantityKey] ?? 1)) : 1.0;
      $total += $quantity * $value;
    }
    return (float) (int) round($total);
  }

  /** @param array<int,array<string,string>> $items */
  private function maintenance_quote_total_materials(array $items): float
  {
    $total = 0.0;
    foreach ($items as $item) {
      $total += $this->maintenance_quote_material_row_total($item);
    }
    return (float) (int) round($total);
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_quote_generated_material_offer_rows(string $field): array
  {
    $raw = trim((string) ($_POST[$field] ?? ''));
    if ($raw === '' || str_starts_with($raw, 'data:image/')) {
      return [];
    }
    $decoded = json_decode(wp_unslash($raw), true);
    if (!is_array($decoded)) {
      return [];
    }
    $rows = [];
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }
      $provider = $this->maintenance_quote_clean($item['provider'] ?? '');
      $total = (int) round($this->maintenance_quote_number($item['total'] ?? 0));
      if ($provider === '' || $total <= 0) {
        continue;
      }
      $rows[] = [
        'provedor_materiales' => $provider,
        'proveedor_materiales' => $provider,
        'valor_materiales' => (string) $total,
        'valor_total_materiales' => (string) $total,
      ];
    }
    return $rows;
  }

  /**
   * @param array<int,array<string,string>> $items
   * @param array<int,array<string,string>> $generatedRows
   * @return array<int,array<string,string>>
   */
  private function maintenance_quote_merge_generated_material_rows(array $items, array $generatedRows): array
  {
    if ($generatedRows === []) {
      return $items;
    }
    $out = [];
    $seen = [];
    foreach ($items as $item) {
      $provider = $this->maintenance_quote_first([$item['provedor_materiales'] ?? '', $item['proveedor_materiales'] ?? '', $item['descripcion_materiales'] ?? '']);
      $total = $this->maintenance_quote_material_row_total($item);
      if ($provider === '' && $total <= 0) {
        continue;
      }
      if ($provider !== '' && $total > 0) {
        $seen[mb_strtolower($provider, 'UTF-8') . '|' . (string) $total] = true;
      }
      $out[] = $item;
    }
    foreach ($generatedRows as $row) {
      $provider = $this->maintenance_quote_first([$row['provedor_materiales'] ?? '', $row['proveedor_materiales'] ?? '']);
      $total = $this->maintenance_quote_material_row_total($row);
      $key = mb_strtolower($provider, 'UTF-8') . '|' . (string) $total;
      if ($provider === '' || $total <= 0 || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $row;
    }
    return $out;
  }

  /** @param array<string,string> $item */
  private function maintenance_quote_material_row_total(array $item): int
  {
    $lineTotal = $this->maintenance_quote_number($item['valor_total_materiales'] ?? 0);
    if ($lineTotal <= 0) {
      $unit = $this->maintenance_quote_number($item['valor_unitario_materiales'] ?? 0);
      $quantity = max(1.0, $this->maintenance_quote_number($item['cantidad_materiales'] ?? 1));
      $lineTotal = $unit > 0 ? ($unit * $quantity) : $this->maintenance_quote_number($item['valor_materiales'] ?? 0);
    }
    return (int) round($lineTotal);
  }

  private function maintenance_quote_clean($value): string
  {
    return trim(sanitize_text_field(wp_unslash((string) ($value ?? ''))));
  }

  private function maintenance_quote_digits($value): string
  {
    return preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
  }

  private function maintenance_quote_number($value): float
  {
    return $this->maintenance_order_money_value($value);
  }

  /**
   * @param array<string,mixed> $quote
   * @return array{porcentaje_admon:string,iva:string,total_admon:string,iva_admon:string,total_admon_mas_iva:string,total:string}
   */
  private function maintenance_quote_send_totals(array $quote, $porcentajeRaw, $ivaRaw): array
  {
    $subtotal = 0.0;
    foreach (['total_materiales', 'total_mano_obra', 'total_maquinarias', 'total_otros_costos'] as $field) {
      $subtotal += $this->maintenance_quote_number($quote[$field] ?? 0);
    }
    if ($subtotal <= 0) {
      $subtotal = $this->maintenance_quote_number($quote['total'] ?? 0);
    }

    $porcentajeText = trim((string) ($porcentajeRaw ?? ''));
    if ($porcentajeText === '') {
      $porcentajeText = trim((string) ($quote['porcentaje_admon'] ?? ''));
    }
    $ivaText = trim((string) ($ivaRaw ?? ''));
    if ($ivaText === '') {
      $ivaText = trim((string) ($quote['iva'] ?? ''));
    }

    $porcentaje = $porcentajeText === '' ? 10.0 : $this->maintenance_quote_number($porcentajeText);
    $iva = $ivaText === '' ? 19.0 : $this->maintenance_quote_number($ivaText);
    $porcentaje = max(0.0, min(100.0, $porcentaje));
    $iva = max(0.0, min(100.0, $iva));

    $totalAdmon = (int) round($subtotal * ($porcentaje / 100));
    $ivaAdmon = (int) round($totalAdmon * ($iva / 100));
    $totalAdmonMasIva = $totalAdmon + $ivaAdmon;
    $total = (int) round($subtotal + $totalAdmonMasIva);

    return [
      'porcentaje_admon' => $this->maintenance_quote_decimal_text($porcentaje),
      'iva' => $this->maintenance_quote_decimal_text($iva),
      'total_admon' => (string) $totalAdmon,
      'iva_admon' => (string) $ivaAdmon,
      'total_admon_mas_iva' => (string) $totalAdmonMasIva,
      'total' => (string) $total,
    ];
  }

  private function maintenance_quote_decimal_text(float $value): string
  {
    $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $text === '' ? '0' : $text;
  }

  /** @param array<mixed> $values */
  private function maintenance_quote_first(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string) ($value ?? ''));
      if ($text !== '') {
        return $text;
      }
    }
    return '';
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_quote_decode_items($raw): array
  {
    if (is_array($raw)) {
      return $raw;
    }
    $text = trim((string) ($raw ?? ''));
    if ($text === '') {
      return [];
    }
    $decoded = @unserialize($text);
    if (is_array($decoded)) {
      return array_values($decoded);
    }
    $json = json_decode($text, true);
    return is_array($json) ? array_values($json) : [];
  }

  /** @return array<int,string> */
  private function maintenance_quote_media_list($raw): array
  {
    if (is_array($raw)) {
      return array_values(array_filter(array_map('strval', $raw)));
    }
    $text = trim((string) ($raw ?? ''));
    if ($text === '') {
      return [];
    }
    $json = json_decode($text, true);
    if (is_array($json)) {
      return array_values(array_filter(array_map('strval', $json)));
    }
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: [])));
  }

  /** @return array<int,string> */
  private function maintenance_quote_existing_media(string $field, $current): array
  {
    $posted = $this->maintenance_quote_media_list($_POST[$field] ?? '');
    return $posted !== [] ? $posted : $this->maintenance_quote_media_list($current);
  }

  /** @return array<int,array<string,mixed>> */
  private function maintenance_quote_store_media(string $field, int $maxFiles): array
  {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
      return [];
    }
    $stored = $this->handleImageUploadsDetailed($field, $maxFiles);
    foreach ($stored as $image) {
      $bytes = (int) ($image['bytes'] ?? $image['size'] ?? 0);
      if ($bytes > 1572864) {
        throw new \DomainException('Una imagen de la cotización supera 1.5 MB. Comprime la imagen antes de guardarla.');
      }
      $width = (int) ($image['width'] ?? 0);
      $height = (int) ($image['height'] ?? 0);
      if (($width > 0 && $width > 2000) || ($height > 0 && $height > 2000)) {
        throw new \DomainException('Una imagen de la cotización supera 2000px. Baja el tamaño antes de guardarla.');
      }
    }
    return $stored;
  }

  /** @return array<int,array{name:string,url:string,mime:string,width:int,height:int,bytes:int,sha256:string}> */
  private function maintenance_quote_store_generated_materials_images(string $field): array
  {
    $raw = trim((string) ($_POST[$field] ?? ''));
    if ($raw === '') {
      return [];
    }
    $dataUris = [];
    if (str_starts_with($raw, 'data:image/')) {
      $dataUris[] = $raw;
    } else {
      $decoded = json_decode(wp_unslash($raw), true);
      if (is_array($decoded)) {
        foreach ($decoded as $item) {
          $image = is_array($item) ? trim((string) ($item['image'] ?? '')) : trim((string) $item);
          if (str_starts_with($image, 'data:image/')) {
            $dataUris[] = $image;
          }
        }
      }
    }
    if ($dataUris === []) {
      return [];
    }
    $storedImages = [];
    foreach (array_slice($dataUris, 0, 10) as $dataUri) {
      $stored = $this->storedFiles()->storeImageDataUri($dataUri);
      if (!is_array($stored)) {
        $this->storedFiles()->deleteStoredImages($storedImages);
        throw new \DomainException('No fue posible generar la imagen de la cotización de materiales.');
      }
      $bytes = (int) ($stored['bytes'] ?? 0);
      if ($bytes > 1572864) {
        $this->storedFiles()->deleteStoredImages(array_merge($storedImages, [$stored]));
        throw new \DomainException('La imagen generada de materiales supera 1.5 MB.');
      }
      $width = (int) ($stored['width'] ?? 0);
      $height = (int) ($stored['height'] ?? 0);
      if (($width > 0 && $width > 2000) || ($height > 0 && $height > 2000)) {
        $this->storedFiles()->deleteStoredImages(array_merge($storedImages, [$stored]));
        throw new \DomainException('La imagen generada de materiales supera 2000px.');
      }
      $storedImages[] = $stored;
    }
    return $storedImages;
  }

  /**
   * @param array<int,array{name?:string,url?:string}> $images
   * @return array<int,string>
   */
  private function maintenance_quote_stored_media_refs(array $images): array
  {
    $refs = [];
    foreach ($images as $image) {
      $name = basename((string) ($image['name'] ?? ''));
      if ($name !== '' && preg_match('/^[a-f0-9]{24}_[0-9]+\.[a-z0-9]{1,8}$/D', $name)) {
        $refs[] = $name;
        continue;
      }
      $url = trim((string) ($image['url'] ?? ''));
      if ($url !== '') {
        $refs[] = $url;
      }
    }
    return $refs;
  }

  /**
   * @param array<int,array{name?:string,url?:string}> $storedPhotos
   */
  private function maintenance_quote_persisted_after_error(string $quoteTable, int $quoteId, array $storedPhotos): bool
  {
    if ($quoteId <= 0 || $quoteTable === '') {
      return false;
    }
    try {
      $row = $this->db->getRow("SELECT `_ID`, `mejor_oferta`, `otras_oferta` FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
    } catch (\Throwable) {
      return false;
    }
    if (!$row) {
      return false;
    }
    if ($storedPhotos === []) {
      return true;
    }

    $mediaText = (string) ($row['mejor_oferta'] ?? '') . ',' . (string) ($row['otras_oferta'] ?? '');
    foreach ($storedPhotos as $photo) {
      $name = basename((string) ($photo['name'] ?? ''));
      if ($name !== '' && str_contains($mediaText, $name)) {
        return true;
      }
      $url = trim((string) ($photo['url'] ?? ''));
      if ($url !== '' && str_contains($mediaText, $url)) {
        return true;
      }
    }
    return false;
  }

  /** @return array{name:string,url:string,mime:string,width:int,height:int,bytes:int,sha256:string}|null */
  private function maintenance_quote_store_generated_materials_image(string $field): ?array
  {
    $stored = $this->maintenance_quote_store_generated_materials_images($field);
    if ($stored === []) {
      return null;
    }
    return $stored[0];
  }

  /**
   * @return array{saldo_obra:float,saldo_materiales:float,saldo_maquinarias:float,saldo_otros_costo:float}|array<string,float>
   */
  private function maintenance_quote_order_used_balances(\SCM\Support\SchemaInspector $schema, int $quoteId): array
  {
    $table = $this->db->table('jet_cct_ordenes');
    if ($quoteId <= 0 || !$schema->tableExists($table)) {
      return [];
    }
    $rows = $this->db->getResults(
      "SELECT `categoria`, `valor`, `estado`, `cct_status` FROM `{$table}` WHERE TRIM(COALESCE(`id_cotizacion`, '')) = ?",
      [(string) $quoteId]
    );
    $used = [
      'saldo_obra' => 0.0,
      'saldo_materiales' => 0.0,
      'saldo_maquinarias' => 0.0,
      'saldo_otros_costo' => 0.0,
    ];
    foreach ($rows as $row) {
      $status = strtolower(trim((string) ($row['estado'] ?? '')));
      $cctStatus = strtolower(trim((string) ($row['cct_status'] ?? '')));
      if (in_array($status, ['eliminada', 'eliminado', 'anulada', 'anulado', 'cancelada', 'cancelado'], true)
        || in_array($cctStatus, ['trash', 'deleted'], true)) {
        continue;
      }
      $category = $this->maintenance_order_category((string) ($row['categoria'] ?? ''));
      if ($category === '') {
        continue;
      }
      $balanceField = $this->maintenance_order_balance_column($category);
      $used[$balanceField] = ($used[$balanceField] ?? 0.0) + $this->maintenance_quote_number($row['valor'] ?? 0);
    }
    return $used;
  }

  /** @param array<string,mixed> $sourceQuote */
  private function maintenance_quote_balance_after_edit(array $sourceQuote, string $saldoField, float $newTotal, ?float $usedOverride = null): string
  {
    $totalField = match ($saldoField) {
      'saldo_obra' => 'total_mano_obra',
      'saldo_materiales' => 'total_materiales',
      'saldo_maquinarias' => 'total_maquinarias',
      'saldo_otros_costo' => 'total_otros_costos',
      default => '',
    };
    if ($totalField === '') {
      return (string) (int) round($newTotal);
    }
    if ($usedOverride !== null) {
      $used = max(0.0, $usedOverride);
    } else {
      $oldTotal = $this->maintenance_quote_number($sourceQuote[$totalField] ?? 0);
      $oldBalance = $this->maintenance_quote_number($sourceQuote[$saldoField] ?? $oldTotal);
      $used = max(0.0, $oldTotal - $oldBalance);
    }
    return (string) max(0, (int) round($newTotal - $used));
  }

  private function maintenance_quote_update_revision_flag(\SCM\Support\SchemaInspector $schema, string $tipoMantenimiento, int $idRevision): void
  {
    if ($idRevision <= 0) {
      return;
    }
    $table = stripos($tipoMantenimiento, 'prevent') !== false
      ? $this->db->table('jet_cct_revision_preventiva')
      : $this->db->table('jet_cct_revision_correctiva');
    if (!$schema->tableExists($table)) {
      return;
    }
    $update = $schema->filterTableData($table, [
      'tiene_cotizacion' => 'Si',
      'cct_modified' => date('Y-m-d H:i:s'),
    ]);
    if (!empty($update)) {
      $this->db->update($table, $update, ['_ID' => $idRevision]);
    }
  }

  /** @param array<string,mixed> $ticket */
  private function maintenance_quote_update_ticket(\SCM\Support\SchemaInspector $schema, array $ticket, int $quoteId, string $tipoMantenimiento, int $now, string $nowSql): void
  {
    $ticketId = (int) ($ticket['_ID'] ?? 0);
    if ($ticketId <= 0) {
      return;
    }
    $table = $this->db->table('jet_cct_tickets');
    if (!$schema->tableExists($table)) {
      return;
    }
    $update = [
      'id_cotizacion_mantenimiento' => (string) $quoteId,
      'estado_acta_cotizacion_mantenimiento' => 'Si',
      'estado_cotizacion_mantenimiento' => 'Si',
      'estado_respuesta_cotizacion_mantenimiento' => '',
      'fecha_respuesta_cotizacion_mantenimiento' => 0,
      'fue_enviada_cotizacion_mantenimiento' => 'No',
      'estado_administrativo' => 'Cotizado',
      'fecha_actualizacion' => $now,
      'cct_modified' => $nowSql,
    ];
    $update = $schema->filterTableData($table, $update);
    if (!empty($update)) {
      $this->db->update($table, $update, ['_ID' => $ticketId]);
    }
  }

  /** @param array<string,mixed> $quoteData @param array<string,mixed> $ticket @param array<string,mixed> $actor */
  private function maintenance_quote_insert_histories(\SCM\Support\SchemaInspector $schema, int $quoteId, string $mode, array $quoteData, array $ticket, array $actor, string $employeeId, int $now, string $nowSql): void
  {
    $ticketRef = $this->maintenance_quote_first([$quoteData['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $propertyRef = $this->maintenance_quote_first([$quoteData['id_inmueble'] ?? '', $quoteData['inmueble'] ?? '']);
    $actorName = trim((string) ($actor['name'] ?? Auth::user()));
    $actorEmail = trim((string) ($actor['email'] ?? ''));
    $actorPhone = trim((string) ($actor['phone'] ?? ''));
    $message = match ($mode) {
      'edit' => 'Se actualizó la cotización de mantenimiento #' . $quoteId . '.',
      'note' => 'Se agregó una nota de cotización de mantenimiento #' . $quoteId . '.',
      default => 'Se ha elaborado la cotización de mantenimiento #' . $quoteId . '.',
    };

    $histTicketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($histTicketTable)) {
      $payload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_ticket' => $ticketRef,
        'fecha' => $now,
        'nombre' => $actorName,
        'correo' => $actorEmail,
        'celular' => $actorPhone,
        'respuesta' => $message,
        'respuesta_cct_ticket' => $message,
        'id_revision_correctiva' => trim((string) ($quoteData['id_revision'] ?? '')),
        'id_cotizacion_mantenimiento' => (string) $quoteId,
        'id_empleado' => $employeeId,
        'fue_editada' => 'Si',
      ];
      $payload = $schema->filterTableData($histTicketTable, $payload);
      if (!empty($payload)) {
        $this->db->insert($histTicketTable, $payload);
      }
    }

    $histInmuebleTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($schema->tableExists($histInmuebleTable)) {
      $payload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $propertyRef,
        'fecha' => $now,
        'tipo_de_reporte_his' => 'Mantenimiento',
        'tipo_reporte' => 'Mantenimiento',
        'observacion_his' => $message,
        'observacion' => $message,
        'funcionario' => $actorName,
        'id_ticket' => $ticketRef,
        'id_inmueble_data' => $propertyRef,
        'id_cotizacion_mantenimiento' => (string) $quoteId,
      ];
      $payload = $schema->filterTableData($histInmuebleTable, $payload);
      if (!empty($payload)) {
        $this->db->insert($histInmuebleTable, $payload);
      }
    }
  }

  /** @param array<string,mixed> $quoteData @param array<string,mixed> $ticket @param array<string,mixed> $revision @param array<string,mixed> $actor */
  private function maintenance_quote_ensure_admin_report(\SCM\Support\SchemaInspector $schema, int $quoteId, array $quoteData, array $ticket, array $revision, array $actor, string $employeeId, int $now, string $nowSql): int
  {
    $table = $this->db->table('jet_cct_reportes_administrativos');
    if (!$schema->tableExists($table) || $quoteId <= 0) {
      return 0;
    }
    $ticketRef = trim((string) ($quoteData['id_ticket'] ?? $ticket['_ID'] ?? ''));
    $revisionId = trim((string) ($quoteData['id_revision'] ?? ''));
    $tipo = trim((string) ($quoteData['tipo_mantenimiento'] ?? 'Correctiva'));
    $isPreventive = stripos($tipo, 'prevent') !== false;
    $reportCategory = $isPreventive ? 'Revision preventiva' : 'Revision correctiva';
    $where = ['TRIM(COALESCE(`id_ticket`, "")) = ?'];
    $params = [$ticketRef];
    if ($revisionId !== '') {
      $column = $isPreventive ? 'id_revision_preventiva' : 'id_revision_correctiva';
      $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
      $params[] = $revisionId;
    }
    $existingReportId = (int) $this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE " . implode(' AND ', $where) . " LIMIT 1", $params);

    $config = [];
    $configTable = $this->db->table('jet_cct_confi_sistema');
    if ($schema->tableExists($configTable)) {
      foreach ($this->db->getResults("SELECT `funcion`, `valor` FROM `{$configTable}` WHERE `funcion` IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv') ORDER BY `_ID`") as $row) {
        $config[(string) ($row['funcion'] ?? '')] = $row['valor'] ?? '';
      }
    }
    $fee = (float) (\SCM\Modules\TicketCompletion\CompletionPolicy::fee($config) ?? 0);
    $value = $fee > 0 ? $fee : 0.0;
    $actorName = trim((string) ($actor['name'] ?? Auth::user()));
    $description = 'Cobro administrativo por cotización de mantenimiento #' . $quoteId . ' asociada a ' . $reportCategory . '.';
    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketRef,
      'id_empleado' => $employeeId,
      'creador' => $actorName,
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'fecha' => $now,
      'fue_pagado' => 'No',
      'categoria' => $reportCategory,
      'descripcion' => $description,
      'valor' => (string) (int) round($value),
      'transporte' => '0',
      'valor_mantenimiento' => (string) (int) round($value),
      'valor_otro' => '0',
      'sucursal' => trim((string) ($quoteData['sucursal'] ?? $ticket['sucursal'] ?? '')),
      'valor_revision' => (string) (int) round($value),
      'contrato' => trim((string) ($quoteData['contrato'] ?? '')),
      'id_cotizacion_mantenimiento' => (string) $quoteId,
      'id_contrato' => trim((string) ($quoteData['id_contrato'] ?? '')),
      'id_inmueble' => trim((string) ($quoteData['id_inmueble'] ?? '')),
      'exportado' => 'No',
      'fecha_revision' => $now,
      'fecha_ticket' => trim((string) ($ticket['fecha'] ?? $ticket['cct_created'] ?? '')),
      'inmueble' => trim((string) ($quoteData['inmueble'] ?? '')),
      'arrendatario' => trim((string) ($ticket['arrendatario'] ?? '')),
    ];
    if ($revisionId !== '') {
      if ($isPreventive) {
        $payload['id_revision_preventiva'] = $revisionId;
      } else {
        $payload['id_revision_correctiva'] = $revisionId;
      }
    }

    $payload = $schema->filterTableData($table, $payload);
    if ($existingReportId > 0) {
      $reportUpdate = $payload;
      unset($reportUpdate['cct_created'], $reportUpdate['fecha'], $reportUpdate['fue_pagado'], $reportUpdate['exportado'], $reportUpdate['cct_author_id'], $reportUpdate['id_empleado'], $reportUpdate['creador']);
      if (empty($reportUpdate)) {
        return $existingReportId;
      }
      return $this->db->update($table, $reportUpdate, ['_ID' => $existingReportId]) >= 0 ? $existingReportId : 0;
    }
    if (empty($payload) || !$this->db->insert($table, $payload)) {
      return 0;
    }
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $quoteData @param array<string,mixed> $actor */
  private function maintenance_quote_enqueue_saved_notifications(string $mode, int $quoteId, array $quoteData, array $actor): int
  {
    $recipients = $this->maintenance_order_internal_email_recipients('cotizacion_mantenimiento_guardada');
    $creatorEmail = trim((string) ($quoteData['email_creador'] ?? ''));
    if ($creatorEmail !== '' && filter_var($creatorEmail, FILTER_VALIDATE_EMAIL)) {
      $recipients[] = [
        'name' => trim((string) ($quoteData['creador'] ?? $actor['name'] ?? '')),
        'email' => $creatorEmail,
      ];
    }
    $recipients = $this->maintenance_order_unique_email_recipients($recipients);
    if ($recipients === []) {
      return 0;
    }

    $ticket = trim((string) ($quoteData['id_ticket'] ?? ''));
    $subject = ($mode === 'edit' ? 'Cotización actualizada' : ($mode === 'note' ? 'Nota de cotización creada' : 'Cotización creada'))
      . ($ticket !== '' ? ' para el caso #' . $ticket : '');
    $lines = [
      'Cotización' => '#' . $quoteId,
      'Categoría' => trim((string) ($quoteData['categoria_cotizacion'] ?? '-')),
      'Tipo' => trim((string) ($quoteData['tipo_mantenimiento'] ?? 'Mantenimiento')),
      'Caso' => $ticket !== '' ? '#' . $ticket : '-',
      'Inmueble' => $this->maintenance_quote_first([$quoteData['inmueble'] ?? '', $quoteData['id_inmueble'] ?? '']),
      'Destinatario' => trim((string) ($quoteData['destinatario'] ?? '-')),
      'Total' => $this->format_cop_currency($quoteData['total'] ?? 0),
      'Creada por' => trim((string) ($actor['name'] ?? $quoteData['creador'] ?? '-')),
    ];
    $items = '';
    foreach ($lines as $label => $value) {
      $items .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-weight:700;">' . esc_html($label) . '</td><td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#0b1f3a;">' . esc_html((string) $value) . '</td></tr>';
    }
    $quoteUrl = self::signedMaintenanceQuotePublicUrl($quoteId);
    $content = '<p style="margin:0 0 16px;">Se registró una novedad de cotización de mantenimiento en Control Servicios Inmobiliarios.</p>'
      . '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">' . $items . '</table>';
    $html = \SCM\Support\EmailTemplate::render($subject, $content, [
      'cotizacion_url' => $quoteUrl,
    ]);

    return (new \SCM\Support\EmailQueue($this->db))->enqueue(array_column($recipients, 'email'), $subject, $html, [
      'source_module' => 'cotizaciones_mantenimiento',
      'dedupe_key' => 'cotizacion-mantenimiento-' . $mode . ':' . $quoteId,
      'meta' => [
        'event' => 'cotizacion_mantenimiento_guardada',
        'mode' => $mode,
        'id_cotizacion' => $quoteId,
        'id_ticket' => $ticket,
        'actor' => trim((string) ($actor['name'] ?? '')),
      ],
    ]);
  }

  /** @return array{from:string,to:string,from_ts:int,to_ts:int} */
  private function metrics_execution_date_range(array $input): array
  {
    $clean = static function ($value): string {
      return trim(sanitize_text_field(wp_unslash((string) ($value ?? ''))));
    };
    $from = $clean($input['fecha_desde'] ?? '');
    $to = $clean($input['fecha_hasta'] ?? '');
    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
      $from = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      $to = $today;
    }
    if (strtotime($from) > strtotime($to)) {
      [$from, $to] = [$to, $from];
    }
    return [
      'from' => $from,
      'to' => $to,
      'from_ts' => (int) strtotime($from . ' 00:00:00'),
      'to_ts' => (int) strtotime($to . ' 23:59:59'),
    ];
  }

  /** @return array<string,array{id:string,nombre:string,label:string}> */
  private function metrics_execution_employee_map(string $funcTable): array
  {
    if (!$this->table_exists($funcTable) || !$this->column_exists($funcTable, 'id_empleado')) {
      return [];
    }
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(`id_empleado`, '')) AS id,
              TRIM(COALESCE(`nombre`, `id_empleado`, '')) AS nombre
       FROM `{$funcTable}`
       WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''"
    );
    $map = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $name = trim((string) ($row['nombre'] ?? ''));
      $label = $name !== '' ? $name : $id;
      $map[$id] = ['id' => $id, 'nombre' => $name, 'label' => $label];
    }
    return $map;
  }

  /** @param array<string,mixed> $row @param array<string,array{id:string,nombre:string,label:string}> $employees @return array{id:string,nombre:string,label:string,key:string} */
  private function metrics_execution_employee_label(array $row, array $employees): array
  {
    $id = trim((string) ($row['funcionario_id'] ?? ''));
    $name = trim((string) ($row['funcionario_nombre'] ?? ''));
    if ($id !== '' && isset($employees[$id])) {
      $name = $name !== '' ? $name : $employees[$id]['nombre'];
      return [
        'id' => $id,
        'nombre' => $name !== '' ? $name : $employees[$id]['label'],
        'label' => $name !== '' ? ($name . ' (' . $id . ')') : $employees[$id]['label'] . ' (' . $id . ')',
        'key' => 'id:' . $id,
      ];
    }
    $label = $name !== '' ? $name : ($id !== '' ? $id : 'Sin funcionario');
    return [
      'id' => $id,
      'nombre' => $name !== '' ? $name : $label,
      'label' => $id !== '' && $name !== '' ? ($name . ' (' . $id . ')') : $label,
      'key' => $id !== '' ? ('id:' . $id) : ('name:' . mb_strtolower($label, 'UTF-8')),
    ];
  }

  private function metrics_execution_clean_text(string $value): string
  {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = wp_strip_all_tags($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?: '';
    return trim($value);
  }

  private function metrics_execution_classify_history(string $text): string
  {
    $plain = mb_strtolower($this->metrics_execution_clean_text($text), 'UTF-8');
    if ($plain === '') {
      return 'ignore';
    }
    if (preg_match('/ticket\s+(administrativo\s+)?creado|creado:\s*revision preventiva|se crea este ticket/u', $plain)) {
      return 'ignore';
    }
    if (preg_match('/postergad|activad|trasladad|cerrad|magnitud|ubicaci[oó]n|cotizaci[oó]n|orden|acta|estado/u', $plain)) {
      return 'actualizacion';
    }
    return 'respuesta';
  }

  /** @param array<string,mixed> $row @param array{id:string,nombre:string,label:string,key:string} $employee @return array<string,mixed> */
  private function metrics_execution_detail_item(array $row, string $type, string $label, string $detailText, array $employee): array
  {
    $ticketPk = trim((string) ($row['ticket_pk'] ?? ''));
    $ticketLogico = trim((string) ($row['ticket_logico'] ?? ''));
    $ticketRef = trim((string) ($row['ticket_ref'] ?? ''));
    $caseNumber = $ticketLogico !== '' ? $ticketLogico : ($ticketPk !== '' ? $ticketPk : $ticketRef);
    $ts = (int) ($row['fecha_ts'] ?? 0);
    return [
      'id' => (string) ($row['movimiento_id'] ?? ''),
      'type' => $type,
      'label' => $label,
      'fecha_ts' => $ts,
      'fecha' => $ts > 0 ? date('d/m/Y H:i', $ts) : '-',
      'funcionario_id' => $employee['id'],
      'funcionario' => $employee['nombre'],
      'funcionario_label' => $employee['label'],
      'funcionario_key' => $employee['key'],
      'ticket_pk' => $ticketPk,
      'ticket' => $caseNumber !== '' ? $caseNumber : '-',
      'asunto' => $this->metrics_execution_clean_text((string) ($row['asunto'] ?? '')),
      'contrato' => trim((string) ($row['contrato'] ?? '')),
      'inmueble' => trim((string) ($row['inmueble'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'estado' => trim((string) ($row['estado'] ?? '')),
      'estado_admin' => trim((string) ($row['estado_admin'] ?? '')),
      'detalle' => $detailText,
    ];
  }

  /** @param array<string,array<string,mixed>> $summary @param array<string,mixed> $item */
  private function metrics_execution_add_summary(array &$summary, array $item): void
  {
    $key = (string) ($item['funcionario_key'] ?? 'name:sin_funcionario');
    if (!isset($summary[$key])) {
      $summary[$key] = [
        'funcionario_key' => $key,
        'funcionario_id' => (string) ($item['funcionario_id'] ?? ''),
        'funcionario' => (string) ($item['funcionario'] ?? 'Sin funcionario'),
        'funcionario_label' => (string) ($item['funcionario_label'] ?? 'Sin funcionario'),
        'respuestas' => 0,
        'actualizaciones' => 0,
        'seguimientos' => 0,
        'citas_realizadas' => 0,
        'total_acciones' => 0,
        '_casos' => [],
      ];
    }
    $type = (string) ($item['type'] ?? '');
    if ($type === 'respuesta') {
      $summary[$key]['respuestas']++;
    } elseif ($type === 'actualizacion') {
      $summary[$key]['actualizaciones']++;
    } elseif ($type === 'seguimiento') {
      $summary[$key]['seguimientos']++;
    } elseif ($type === 'cita_realizada') {
      $summary[$key]['citas_realizadas']++;
    }
    $summary[$key]['total_acciones']++;
    $ticketKey = trim((string) ($item['ticket_pk'] ?? $item['ticket'] ?? ''));
    if ($ticketKey !== '' && $ticketKey !== '-') {
      $summary[$key]['_casos'][$ticketKey] = true;
    }
  }

  /** @param array<string,mixed> $item */
  private function metrics_execution_signature(array $item): string
  {
    $minuteBucket = (string) floor(max(0, (int) ($item['fecha_ts'] ?? 0)) / 60);
    $textHash = sha1(mb_strtolower((string) ($item['detalle'] ?? ''), 'UTF-8'));
    return implode('|', [
      (string) ($item['ticket'] ?? ''),
      (string) ($item['funcionario_key'] ?? ''),
      $minuteBucket,
      $textHash,
    ]);
  }

  public function ajax_handler_delete_cotizacion_mantenimiento(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') || !$this->canUseDashboardAction('quote_delete')) {
      $this->jsonFail('No tienes permiso para eliminar cotizaciones.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? 0);
    $motivo = trim(sanitize_text_field(wp_unslash((string) ($_POST['motivo'] ?? ''))));
    $observacion = trim(wp_kses_post(wp_unslash((string) ($_POST['observacion'] ?? ''))));
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }
    if ($motivo === '') {
      $this->jsonFail('Selecciona el motivo.');
    }

    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      $this->jsonFail('La tabla de cotizaciones no esta disponible.');
    }

    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    if (!is_array($row)) {
      $this->jsonFail('Cotizacion no encontrada.');
    }
    $currentEstado = strtolower(trim((string) ($row['estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
    if (!in_array($currentEstado, ['', 'esperando respuesta'], true)) {
      $this->jsonFail('Solo se puede eliminar/desaprobar una cotizacion sin estado o en Esperando respuesta.');
    }

    $now = time();
    $update = [
      'estado' => 'Desaprobada',
      'se_envio' => 'Si',
      'motivo' => $motivo,
      'observacion_respuesta' => $observacion !== '' ? $observacion : 'Cotizacion eliminada desde el panel.',
      'fecha_respuesta' => $now,
      'fecha_envio' => $now,
    ];
    if ($this->column_exists($table, 'cct_modified')) {
      $update['cct_modified'] = date('Y-m-d H:i:s', $now);
    }
    $this->db->update($table, $update, ['_ID' => $cotizacionId]);

    $this->jsonOk([
      'message' => 'Cotizacion eliminada/desaprobada correctamente.',
      'id_cotizacion' => (string) $cotizacionId,
    ]);
  }

  public function ajax_handler_approve_cotizacion_mantenimiento(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') || !$this->canUseDashboardAction('quote_approve')) {
      $this->jsonFail('No tienes permiso para actualizar cotizaciones.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? 0);
    $observacion = trim(wp_kses_post(wp_unslash((string) ($_POST['observacion'] ?? ''))));
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }

    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      $this->jsonFail('La tabla de cotizaciones no esta disponible.');
    }

    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    if (!is_array($row)) {
      $this->jsonFail('Cotizacion no encontrada.');
    }
    $currentEstado = strtolower(trim((string) ($row['estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
    if (!in_array($currentEstado, ['', 'esperando respuesta'], true)) {
      $this->jsonFail('Solo se puede marcar como aprobada una cotizacion sin estado o en Esperando respuesta.');
    }

    $now = time();
    $nowMysql = date('Y-m-d H:i:s', $now);
    $userId = Auth::userId();
    $employeeId = (string) $userId;
    $schema = new \SCM\Support\SchemaInspector($this->db);
    if (method_exists($this, 'current_employee_id')) {
      $currentEmployeeId = trim((string) $this->current_employee_id());
      if ($currentEmployeeId !== '') {
        $employeeId = $currentEmployeeId;
      }
    }

    $update = [
      'estado' => 'Aprobada',
      'estado_respuesta_cotizacion_mantenimiento' => 'Aprobada',
      'fecha_respuesta' => $now,
      'observacion_respuesta' => $observacion !== '' ? $observacion : 'Cotizacion marcada como aprobada desde el panel.',
      'motivo' => '',
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $now,
      'cct_modified' => $nowMysql,
      'cct_author_id' => $employeeId,
      'id_coordinador' => $employeeId,
    ];
    $update = $schema->filterTableData($table, $update);
    $updatedCotizacion = !empty($update) ? $this->db->update($table, $update, ['_ID' => $cotizacionId]) : 0;
    $updatedRow = $this->db->getRow("SELECT `estado` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    $updatedEstado = is_array($updatedRow) ? strtolower(trim((string) ($updatedRow['estado'] ?? ''))) : '';
    if (empty($update) || ($updatedCotizacion < 1 && $updatedEstado !== 'aprobada')) {
      $this->jsonFail('No se pudo marcar la cotizacion como aprobada.');
    }

    $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
    $ticketRowsUpdated = 0;
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if ($ticketRef !== '' && $this->table_exists($ticketsTable)) {
      $ticketRows = [];
      if (ctype_digit($ticketRef)) {
        $ticketRows = $this->db->getResults(
          "SELECT `_ID`, `id_cotizacion_mantenimiento` FROM `{$ticketsTable}` WHERE `_ID` = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 5",
          [(int) $ticketRef, $ticketRef]
        );
      } else {
        $ticketRows = $this->db->getResults(
          "SELECT `_ID`, `id_cotizacion_mantenimiento` FROM `{$ticketsTable}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 5",
          [$ticketRef]
        );
      }
      foreach ($ticketRows as $ticketRow) {
        $linkedCotIds = $this->cotizacion_split_ids((string) ($ticketRow['id_cotizacion_mantenimiento'] ?? ''));
        if (!empty($linkedCotIds) && !in_array((string) $cotizacionId, $linkedCotIds, true)) {
          continue;
        }
        $ticketUpdate = [
          'estado_cotizacion_mantenimiento' => 'Aprobada',
          'estado_respuesta_cotizacion_mantenimiento' => 'Aprobada',
          'fecha_respuesta_cotizacion_mantenimiento' => $now,
          'fecha_actualizacion' => $now,
          'cct_modified' => $nowMysql,
        ];
        $ticketUpdate = $schema->filterTableData($ticketsTable, $ticketUpdate);
        if (!empty($ticketUpdate)) {
          $ticketRowsUpdated += $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => (int) ($ticketRow['_ID'] ?? 0)]);
        }
      }
    }

    $this->jsonOk([
      'message' => 'Cotizacion marcada como aprobada.' . ($ticketRowsUpdated > 0 ? ' Ticket sincronizado.' : ''),
      'id_cotizacion' => (string) $cotizacionId,
    ]);
  }

  public function ajax_handler_cotizacion_order_context(): void
  {
    $this->verifyCsrf();
    if (!$this->maintenance_order_can_manage()) {
      $this->jsonFail('No tienes permiso para crear ordenes de mantenimiento.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? 0);
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }

    $cotizacion = $this->maintenance_order_find_cotizacion($cotizacionId);
    if (!is_array($cotizacion)) {
      $this->jsonFail('Cotizacion no encontrada.');
    }
    if (strtolower(trim((string) ($cotizacion['estado'] ?? ''))) !== 'aprobada') {
      $this->jsonFail('Solo puedes crear ordenes cuando la cotizacion esta aprobada.');
    }
    if ($this->maintenance_order_active_satisfaction_act($cotizacion) !== null) {
      $this->jsonFail('Esta cotizacion o caso ya tiene un acta de satisfaccion activa. No se pueden crear ordenes nuevas.');
    }

    $this->jsonOk([
      'cotizacion' => $this->maintenance_order_context_payload($cotizacion),
      'providers' => $this->maintenance_order_provider_options(),
      'banks' => $this->maintenance_order_bank_options(),
    ]);
  }

  public function ajax_handler_cotizacion_order_save(): void
  {
    $this->verifyCsrf();
    if (!$this->maintenance_order_can_manage()) {
      $this->jsonFail('No tienes permiso para crear ordenes de mantenimiento.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? 0);
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $ordersTable = $this->db->table('jet_cct_ordenes');
    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$schema->tableExists($ordersTable)) {
      $this->jsonFail('La tabla de ordenes no esta disponible.');
    }
    if (!$schema->tableExists($cotTable)) {
      $this->jsonFail('La tabla de cotizaciones no esta disponible.');
    }

    $cotizacion = $this->maintenance_order_find_cotizacion($cotizacionId);
    if (!is_array($cotizacion)) {
      $this->jsonFail('Cotizacion no encontrada.');
    }
    if (strtolower(trim((string) ($cotizacion['estado'] ?? ''))) !== 'aprobada') {
      $this->jsonFail('Solo puedes crear ordenes cuando la cotizacion esta aprobada.');
    }
    if ($this->maintenance_order_active_satisfaction_act($cotizacion) !== null) {
      $this->jsonFail('Esta cotizacion o caso ya tiene un acta de satisfaccion activa. No se pueden crear ordenes nuevas.');
    }

    $category = $this->maintenance_order_category((string) ($_POST['categoria'] ?? ''));
    if ($category === '') {
      $this->jsonFail('Selecciona el tipo de orden.');
    }
    $concept = $this->maintenance_order_clean($_POST['concepto'] ?? '');
    if ($concept === '') {
      $this->jsonFail('Escribe el concepto de la orden.');
    }
    $activity = $this->maintenance_order_clean($_POST['actividad'] ?? '');
    if ($activity === '') {
      $activity = $this->maintenance_order_default_activity($category);
    }
    $value = $this->maintenance_order_money($_POST['valor'] ?? 0);
    if ($value <= 0) {
      $this->jsonFail('El valor de la orden debe ser mayor a cero.');
    }

    $balanceColumn = $this->maintenance_order_balance_column($category);
    $currentBalance = $this->maintenance_order_money_value($cotizacion[$balanceColumn] ?? 0);
    if ($value > ($currentBalance + 0.01)) {
      $this->jsonFail('El valor supera el saldo disponible para ' . strtolower($category) . '. Saldo: ' . $this->format_cop_currency($currentBalance) . '.');
    }

    $providerPayload = $this->maintenance_order_provider_payload($_POST);
    foreach (['proveedor', 'identificacion_proveedor', 'correo_proveedor', 'celular_proveedor', 'titular_proveedor', 'identificacion_cuenta_proveedor', 'cuenta_proveedor', 'correo_pago_proveedor'] as $requiredProviderField) {
      if (trim((string) ($providerPayload[$requiredProviderField] ?? '')) === '') {
        $this->jsonFail('Completa los datos obligatorios del proveedor.');
      }
    }
    $providerId = $this->maintenance_order_save_provider($schema, (int) ($_POST['id_proveedor'] ?? 0), $providerPayload);

    $now = time();
    $nowMysql = date('Y-m-d H:i:s', $now);
    $userId = Auth::userId();
    $employeeId = trim((string) (method_exists($this, 'current_employee_id') ? $this->current_employee_id() : ''));
    if ($employeeId === '') {
      $employeeId = (string) $userId;
    }
    $user = $this->maintenance_order_current_user_payload();
    $orderItemPayload = $this->maintenance_order_item_payload($category, $concept, $providerPayload['proveedor'], $value);

    $orderData = array_merge([
      'cct_status' => 'publish',
      'fecha' => $now,
      'actividad' => $activity,
      'valor' => (string) (int) round($value),
      'categoria' => $category,
      'estado' => 'Esperando respuesta',
      'creador' => $user['nombre'],
      'email_creador' => $user['email'],
      'celular_creador' => $user['celular'],
      'direccion' => $this->maintenance_order_first([$cotizacion['direccion'] ?? '', $_POST['direccion'] ?? '']),
      'id_cotizacion' => (string) $cotizacionId,
      'id_ticket' => $this->maintenance_order_first([$cotizacion['id_ticket'] ?? '', $_POST['ticket_pk'] ?? '']),
      'id_inmueble' => $this->maintenance_order_first([$cotizacion['id_inmueble'] ?? '', $cotizacion['inmueble'] ?? '']),
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'coordinador' => $this->maintenance_order_first([$cotizacion['coordinador'] ?? '', $user['nombre']]),
      'autorizador' => '',
      'id_proveedor' => $providerId > 0 ? (string) $providerId : '',
      'contrato' => $this->maintenance_order_first([$cotizacion['contrato'] ?? '', $cotizacion['id_contrato'] ?? '']),
      'concepto' => $concept,
      'id_empleado' => $employeeId,
      'id_propietario' => trim((string) ($cotizacion['id_propietario'] ?? '')),
      'id_autorizador' => '',
      'id_coordinador' => trim((string) ($cotizacion['id_coordinador'] ?? '')),
      'destinatario' => trim((string) ($cotizacion['destinatario'] ?? '')),
      'email_destinatario' => trim((string) ($cotizacion['email_destinatario'] ?? '')),
      'celular_destinatario' => trim((string) ($cotizacion['celular_destinatario'] ?? '')),
      'id_contrato' => trim((string) ($cotizacion['id_contrato'] ?? '')),
      'inmueble' => $this->maintenance_order_first([$cotizacion['inmueble'] ?? '', $cotizacion['id_inmueble'] ?? '']),
      'sucursal' => trim((string) ($cotizacion['sucursal'] ?? '')),
      'id_arrendatario' => trim((string) ($cotizacion['id_arrendatario'] ?? '')),
    ], $providerPayload, $orderItemPayload);

    $orderData = $schema->filterTableData($ordersTable, $orderData);
    if (empty($orderData) || !$this->db->insert($ordersTable, $orderData)) {
      $this->jsonFail('No se pudo guardar la orden.');
    }
    $orderId = (int) $this->db->lastInsertId();

    $this->maintenance_order_update_cotizacion_balance($schema, $cotizacion, $category, $currentBalance, $value, $nowMysql);
    $this->maintenance_order_update_ticket($schema, $cotizacion, $orderId, $now, $nowMysql);
    $this->maintenance_order_insert_histories($schema, $cotizacion, $orderId, $category, $concept, $value, $user, $employeeId, $now, $nowMysql);
    $queued = $this->maintenance_order_enqueue_created_notifications(
      array_merge($orderData, [
        '_ID' => (string) $orderId,
        'id_cotizacion' => (string) $cotizacionId,
        'id_ticket' => $this->maintenance_order_first([$cotizacion['id_ticket'] ?? '', $_POST['ticket_pk'] ?? '']),
      ]),
      $cotizacion,
      $user,
      $orderId
    );

    $this->jsonOk([
      'message' => 'Orden de mantenimiento #' . $orderId . ' creada.' . ($queued > 0 ? ' Notificaciones en cola: ' . $queued . '.' : ''),
      'id_orden' => (string) $orderId,
      'id_cotizacion' => (string) $cotizacionId,
      'notifications_queued' => $queued,
    ]);
  }

  public function ajax_handler_cotizacion_order_response(): void
  {
    $this->verifyCsrf();
    if (!$this->maintenance_order_can_respond()) {
      $this->jsonFail('No tienes permiso para responder ordenes de mantenimiento.');
    }

    $orderId = (int) ($_POST['id_orden'] ?? 0);
    if ($orderId <= 0) {
      $this->jsonFail('Orden invalida.');
    }

    $estado = $this->maintenance_order_response_state((string) ($_POST['estado'] ?? ''));
    if ($estado === '') {
      $this->jsonFail('Selecciona si la orden fue aprobada o desaprobada.');
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $ordersTable = $this->db->table('jet_cct_ordenes');
    if (!$schema->tableExists($ordersTable)) {
      $this->jsonFail('La tabla de ordenes no esta disponible.');
    }

    $order = $this->db->getRow("SELECT * FROM `{$ordersTable}` WHERE `_ID` = ? LIMIT 1", [$orderId]);
    if (!is_array($order)) {
      $this->jsonFail('Orden no encontrada.');
    }

    $currentState = strtolower(trim((string) ($order['estado'] ?? '')));
    if ($currentState !== '' && $currentState !== 'esperando respuesta') {
      $this->jsonFail('Solo puedes responder una orden que este esperando respuesta.');
    }

    $now = time();
    $nowMysql = date('Y-m-d H:i:s', $now);
    $user = $this->maintenance_order_current_user_payload();
    $userId = Auth::userId();
    $employeeId = trim((string) (method_exists($this, 'current_employee_id') ? $this->current_employee_id() : ''));
    if ($employeeId === '') {
      $employeeId = (string) $userId;
    }
    $observacion = $this->maintenance_order_clean($_POST['observacion'] ?? '');

    $update = [
      'estado' => $estado,
      'autorizador' => $user['nombre'],
      'id_autorizador' => $employeeId,
      'cct_modified' => $nowMysql,
    ];
    $update = $schema->filterTableData($ordersTable, $update);
    if (empty($update)) {
      $this->jsonFail('No hay campos disponibles para actualizar la orden.');
    }
    $this->db->update($ordersTable, $update, ['_ID' => $orderId]);

    $updatedOrder = array_merge($order, $update);
    $this->maintenance_order_insert_response_histories($schema, $updatedOrder, $estado, $observacion, $user, $employeeId, $now, $nowMysql);
    $queued = $this->maintenance_order_enqueue_response_notifications($updatedOrder, $estado, $observacion, $user, $orderId);

    $this->jsonOk([
      'message' => 'Orden #' . $orderId . ' ' . strtolower($estado) . '.' . ($queued > 0 ? ' Notificaciones en cola: ' . $queued . '.' : ''),
      'id_orden' => (string) $orderId,
      'estado' => $estado,
      'notifications_queued' => $queued,
    ]);
  }

  /** @return array<string,mixed>|null */
  public function public_cotizacion_order(int $orderId): ?array
  {
    if ($orderId <= 0) {
      return null;
    }
    $ordersTable = $this->db->table('jet_cct_ordenes');
    if (!$this->table_exists($ordersTable)) {
      return null;
    }
    $row = $this->db->getRow("SELECT * FROM `{$ordersTable}` WHERE `_ID` = ? LIMIT 1", [$orderId]);
    return is_array($row) ? $row : null;
  }

  public function public_cotizacion_order_signature_valid(int $orderId, int $expires, string $signature): bool
  {
    if ($orderId <= 0 || $expires <= time() || trim($signature) === '') {
      return false;
    }
    $expected = $this->maintenance_order_public_signature($orderId, $expires);
    return hash_equals($expected, trim($signature));
  }

  /** @return array{message:string,id_orden:string,estado:string,notifications_queued:int} */
  public function public_respond_cotizacion_order(int $orderId, string $estadoRaw, string $observacionRaw, string $responderNameRaw): array
  {
    $estado = $this->maintenance_order_response_state($estadoRaw);
    if ($estado === '') {
      throw new \DomainException('Selecciona si la orden fue aprobada o desaprobada.');
    }

    $responderName = $this->maintenance_order_clean($responderNameRaw);
    if ($responderName === '') {
      throw new \DomainException('Escribe el nombre de quien responde la orden.');
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $ordersTable = $this->db->table('jet_cct_ordenes');
    if (!$schema->tableExists($ordersTable)) {
      throw new \DomainException('La tabla de ordenes no esta disponible.');
    }

    $order = $this->db->getRow("SELECT * FROM `{$ordersTable}` WHERE `_ID` = ? LIMIT 1", [$orderId]);
    if (!is_array($order)) {
      throw new \DomainException('Orden no encontrada.');
    }

    $currentState = strtolower(trim((string) ($order['estado'] ?? '')));
    if ($currentState !== '' && $currentState !== 'esperando respuesta') {
      throw new \DomainException('Esta orden ya fue respondida. No se puede cambiar desde el enlace público.');
    }

    $now = time();
    $nowMysql = date('Y-m-d H:i:s', $now);
    $observacion = $this->maintenance_order_clean($observacionRaw);
    $user = [
      'nombre' => $responderName,
      'email' => '',
      'celular' => '',
    ];

    $update = [
      'estado' => $estado,
      'autorizador' => $responderName,
      'id_autorizador' => '',
      'cct_modified' => $nowMysql,
    ];
    $update = $schema->filterTableData($ordersTable, $update);
    if (empty($update)) {
      throw new \DomainException('No hay campos disponibles para actualizar la orden.');
    }
    $this->db->update($ordersTable, $update, ['_ID' => $orderId]);

    $updatedOrder = array_merge($order, $update);
    $this->maintenance_order_insert_response_histories($schema, $updatedOrder, $estado, $observacion, $user, '', $now, $nowMysql);
    $queued = $this->maintenance_order_enqueue_response_notifications($updatedOrder, $estado, $observacion, $user, $orderId);

    return [
      'message' => 'Orden #' . $orderId . ' ' . strtolower($estado) . '.',
      'id_orden' => (string) $orderId,
      'estado' => $estado,
      'notifications_queued' => $queued,
    ];
  }

  public function ajax_handler_cotizacion_mantenimiento_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
      $this->jsonFail('No tienes permiso para generar este PDF.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? $_POST['cotizacion_id'] ?? 0);
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }

    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      $this->jsonFail('La tabla de cotizaciones no esta disponible.');
    }

    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    if (!is_array($row)) {
      $this->jsonFail('Cotizacion no encontrada.');
    }

    $rows = $this->attach_cotizacion_orders([$row]);
    $row = is_array($rows[0] ?? null) ? $rows[0] : $row;
    $orders = is_array($row['_scm_ordenes'] ?? null) ? $row['_scm_ordenes'] : [];
    $audience = strtolower(trim(sanitize_text_field((string) ($_POST['audience'] ?? 'funcionario'))));
    if (!in_array($audience, ['funcionario', 'destinatario'], true)) {
      $audience = 'funcionario';
    }
    $pdf = $this->build_cotizacion_mantenimiento_pdf($row, $orders, $audience);

    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $dir = (string) SCM_STORAGE_PATH . '/tmp';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
      $this->jsonFail('No se pudo preparar el PDF.');
    }
    $path = $dir . '/' . $basename;
    $pdf->save($path);

    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cotizacion-mantenimiento-' . $cotizacionId . '-' . $audience . '.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    @unlink($path);
    exit;
  }

  public function ajax_handler_send_cotizacion_mantenimiento(): void
  {
    $this->verifyCsrf();
    if ((!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('abiertos') && !$this->canAccessDashboardTab('postergados') && !$this->canAccessDashboardTab('mis_tickets')) || !$this->canUseDashboardAction('quote_send')) {
      $this->jsonFail('No tienes permiso para enviar cotizaciones de mantenimiento.');
    }

    $cotizacionId = (int) ($_POST['id_cotizacion'] ?? $_POST['cotizacion_id'] ?? 0);
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotización inválida.');
    }

    try {
      $schema = new \SCM\Support\SchemaInspector($this->db);
      $quoteTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
      $ticketTable = $this->db->table('jet_cct_tickets');
      if (!$schema->tableExists($quoteTable)) {
        throw new \DomainException('La tabla de cotizaciones no está disponible.');
      }

      $quote = $this->db->getRow("SELECT * FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
      if (!is_array($quote)) {
        throw new \DomainException('Cotización no encontrada.');
      }

      $estadoActual = strtolower(trim((string) ($quote['estado'] ?? $quote['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
      if (in_array($estadoActual, ['aprobada', 'aprobado', 'desaprobada', 'desaprobado'], true)) {
        throw new \DomainException('Esta cotización ya tiene respuesta y no se puede enviar nuevamente.');
      }
      $yaEnviada = in_array(strtolower(trim((string) ($quote['se_envio'] ?? ''))), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
      if ($yaEnviada && $estadoActual === 'esperando respuesta') {
        throw new \DomainException('Esta cotización ya fue enviada y está esperando respuesta.');
      }

      $destinatario = $this->maintenance_quote_clean($_POST['destinatario'] ?? ($quote['destinatario'] ?? ''));
      $email = strtolower($this->maintenance_quote_clean($_POST['email_destinatario'] ?? ($quote['email_destinatario'] ?? '')));
      $indicativo = $this->maintenance_quote_clean($_POST['indicativo_destinarario'] ?? ($quote['indicativo_destinarario'] ?? '57'));
      $celular = $this->maintenance_quote_digits($_POST['celular_destinatario'] ?? ($quote['celular_destinatario'] ?? ''));
      if ($destinatario === '') {
        throw new \DomainException('Completa el nombre del destinatario antes de enviar.');
      }
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \DomainException('Completa un correo válido del destinatario antes de enviar.');
      }
      $whatsappPhone = $this->maintenance_quote_whatsapp_phone($indicativo, $celular);
      if ($whatsappPhone === '') {
        throw new \DomainException('Completa un celular válido del destinatario antes de enviar por WhatsApp.');
      }

      $rows = $this->attach_cotizacion_orders([$quote]);
      $quote = is_array($rows[0] ?? null) ? $rows[0] : $quote;
      $orders = is_array($quote['_scm_ordenes'] ?? null) ? $quote['_scm_ordenes'] : [];
      $sendTotals = $this->maintenance_quote_send_totals(
        $quote,
        $_POST['porcentaje_admon'] ?? ($quote['porcentaje_admon'] ?? ''),
        $_POST['iva'] ?? ($quote['iva'] ?? '')
      );
      $quote = array_merge($quote, $sendTotals);
      $pdf = $this->build_cotizacion_mantenimiento_pdf(array_merge($quote, [
        'destinatario' => $destinatario,
        'email_destinatario' => $email,
        'celular_destinatario' => $celular,
        'indicativo_destinarario' => $indicativo,
      ]), $orders, 'destinatario');
      $document = $this->maintenance_quote_store_pdf_document($pdf, $cotizacionId, 'destinatario');
      $quoteUrl = self::signedMaintenanceQuotePublicUrl($cotizacionId);

      $actor = $this->ticketCompletionActor();
      $actorName = trim((string) ($actor['name'] ?? Auth::user())) ?: 'SKC SuCasa Inmobiliaria';
      $actorEmail = trim((string) ($actor['email'] ?? ''));
      $ticketRef = trim((string) ($quote['id_ticket'] ?? ''));
      $total = $this->format_cop_currency($sendTotals['total'] ?? ($quote['total'] ?? 0));
      $contratoRef = trim((string) ($quote['contrato'] ?? $quote['id_contrato'] ?? ''));
      $direccionRef = $this->maintenance_quote_clean($quote['direccion'] ?? '');
      $subject = 'Cotización de mantenimiento #' . $cotizacionId . ($ticketRef !== '' ? ' del caso #' . $ticketRef : '');

      $content = '<p style="font-weight:600;margin:0 0 14px;">Apreciado(a) ' . \SCM\Support\EmailTemplate::e($destinatario) . ',</p>'
        . '<p style="line-height:1.65;margin:0 0 14px;">Te compartimos la cotización de mantenimiento <b>#' . \SCM\Support\EmailTemplate::e((string) $cotizacionId) . '</b>' . ($ticketRef !== '' ? ' asociada al caso <b>#' . \SCM\Support\EmailTemplate::e($ticketRef) . '</b>' : '') . '.</p>'
        . '<p style="line-height:1.65;margin:0 0 14px;">Contrato: <b>#' . \SCM\Support\EmailTemplate::e($contratoRef !== '' ? $contratoRef : '-') . '</b><br>Dirección: <b>' . \SCM\Support\EmailTemplate::e($direccionRef !== '' ? $direccionRef : '-') . '</b></p>'
        . '<p style="line-height:1.65;margin:0 0 14px;">El valor total registrado es <b>' . \SCM\Support\EmailTemplate::e($total) . '</b>. Adjuntamos el PDF de la cotización y también puedes verla y responderla desde el botón seguro.</p>'
        . '<p style="line-height:1.65;margin:0;">Cordialmente,<br><b>' . \SCM\Support\EmailTemplate::e($actorName) . '</b><br>SKC SuCasa Inmobiliaria</p>';
      $html = \SCM\Support\EmailTemplate::render($subject, $content, [
        'buttons' => [
          ['url' => $quoteUrl, 'label' => 'Ver y responder cotización'],
          ['url' => (string) ($document['url'] ?? ''), 'label' => 'Ver PDF'],
        ],
      ]);

      $emailQueued = (new \SCM\Support\EmailQueue($this->db))->enqueue($email, $subject, $html, [
        'source_module' => 'cotizaciones_mantenimiento_envio',
        'destination_name' => $destinatario,
        'dedupe_key' => 'cotizacion_mantenimiento_envio:' . $cotizacionId . ':' . time(),
        'payload' => [
          'attachments' => [[
            'path' => (string) ($document['path'] ?? ''),
            'name' => (string) ($document['attachment_name'] ?? 'cotizacion-mantenimiento-' . $cotizacionId . '.pdf'),
          ]],
          'reply_to' => $actorEmail,
        ],
        'meta' => [
          'event' => 'cotizacion_mantenimiento_enviada',
          'id_cotizacion' => $cotizacionId,
          'id_ticket' => $ticketRef,
          'quote_url' => $quoteUrl,
          'pdf_url' => (string) ($document['url'] ?? ''),
          'actor' => $actorName,
        ],
      ]);

      $whatsappQueued = 0;
      $smsQueue = new \SCM\Support\SmsQueue($this->db);
      $buttonSuffix = $this->maintenance_quote_whatsapp_url_button_suffix($quoteUrl);
      $message = "Buen día, {$destinatario}.\n\n";
      $message .= "Te compartimos la cotización de mantenimiento #{$cotizacionId}" . ($ticketRef !== '' ? " del caso #{$ticketRef}" : '') . " por {$total}.\n\n";
      $message .= "Contrato: #" . ($contratoRef !== '' ? $contratoRef : '-') . ".\n";
      $message .= "Dirección: " . ($direccionRef !== '' ? $direccionRef : '-') . ".\n\n";
      $message .= "Puedes ver el PDF adjunto y responder la cotización desde el botón.\n\n";
      $message .= "Enlace directo: {$quoteUrl}\n\n";
      $message .= "Atentamente,\n{$actorName}\nSKC SuCasa Inmobiliaria";
      $whatsappOk = $smsQueue->enqueue($whatsappPhone, $destinatario, $message, [
        'source_module' => 'cotizaciones_mantenimiento_envio',
        'campaign_tag' => 'cotizaciones_mantenimiento_envio',
        'categoria_mensaje' => 'informacion',
        'id_ticket' => $ticketRef,
        'id_cotizacion' => $cotizacionId,
        'quote_url' => $quoteUrl,
        'pdf_url' => (string) ($document['url'] ?? ''),
        'document_url' => (string) ($document['url'] ?? ''),
        'document_filename' => (string) ($document['attachment_name'] ?? 'cotizacion-mantenimiento-' . $cotizacionId . '.pdf'),
        'button_url_mode' => 'dynamic_suffix',
        'dedupe_key' => 'cotizacion_mantenimiento_envio_whatsapp:' . $cotizacionId . ':' . time(),
        'template_name' => 'scm_cotizacion_mantenimiento_envio_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'header',
            'parameters' => [[
              'type' => 'document',
              'document' => [
                'link' => (string) ($document['url'] ?? ''),
                'filename' => (string) ($document['attachment_name'] ?? 'cotizacion-mantenimiento-' . $cotizacionId . '.pdf'),
              ],
            ]],
          ],
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($destinatario)],
              ['type' => 'text', 'text' => (string) $cotizacionId],
              ['type' => 'text', 'text' => $ticketRef !== '' ? $ticketRef : '-'],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($total)],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($contratoRef !== '' ? $contratoRef : '-')],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($direccionRef !== '' ? $direccionRef : '-')],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($actorName)],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
              ['type' => 'text', 'text' => $buttonSuffix],
            ],
          ],
        ],
      ]);
      $whatsappQueued = $whatsappOk ? 1 : 0;

      if ($emailQueued <= 0 && $whatsappQueued <= 0) {
        @unlink((string) ($document['path'] ?? ''));
        throw new \DomainException('No se pudo encolar el correo ni el WhatsApp. La cotización quedó sin marcar como enviada.');
      }

      [$now, $nowSql] = $this->maintenance_quote_now_pair();
      $employeeId = trim((string) ($actor['employee_id'] ?? ''));
      if ($employeeId === '' && method_exists($this, 'current_employee_id')) {
        $employeeId = trim((string) $this->current_employee_id());
      }
      if ($employeeId === '') {
        $employeeId = (string) Auth::userId();
      }

      $quoteUpdate = [
        'destinatario' => $destinatario,
        'email_destinatario' => $email,
        'celular_destinatario' => $celular,
        'indicativo_destinarario' => $indicativo,
        'porcentaje_admon' => $sendTotals['porcentaje_admon'],
        'iva' => $sendTotals['iva'],
        'total_admon' => $sendTotals['total_admon'],
        'iva_admon' => $sendTotals['iva_admon'],
        'total_admon_mas_iva' => $sendTotals['total_admon_mas_iva'],
        'total' => $sendTotals['total'],
        'se_envio' => 'Si',
        'estado' => 'Esperando respuesta',
        'estado_respuesta_cotizacion_mantenimiento' => 'Esperando respuesta',
        'fecha_envio' => $now,
        'fecha_respuesta' => 0,
        'observacion_respuesta' => '',
        'motivo' => '',
        'cct_modified' => $nowSql,
        'cct_author_id' => $employeeId,
      ];
      $quoteUpdate = $schema->filterTableData($quoteTable, $quoteUpdate);
      if (!empty($quoteUpdate)) {
        $this->db->update($quoteTable, $quoteUpdate, ['_ID' => $cotizacionId]);
      }

      $ticket = $this->maintenance_quote_ticket_row_for_quote($schema, $quote);
      if (is_array($ticket) && $schema->tableExists($ticketTable)) {
        $ticketUpdate = [
          'fue_enviada_cotizacion_mantenimiento' => 'Si',
          'estado_respuesta_cotizacion_mantenimiento' => 'Esperando respuesta',
          'fecha_envio_cotizacion_mantenimiento' => $now,
          'fecha_actualizacion' => $now,
          'cct_modified' => $nowSql,
        ];
        $ticketUpdate = $schema->filterTableData($ticketTable, $ticketUpdate);
        if (!empty($ticketUpdate)) {
          $this->db->update($ticketTable, $ticketUpdate, ['_ID' => (int) ($ticket['_ID'] ?? 0)]);
        }
      }
      $this->maintenance_quote_insert_send_histories($schema, $cotizacionId, array_merge($quote, $quoteUpdate), is_array($ticket) ? $ticket : [], $actor, $employeeId, $now, $nowSql);

      $warnings = [];
      if ($emailQueued <= 0) {
        $warnings[] = 'correo no encolado';
      }
      if ($whatsappQueued <= 0) {
        $warnings[] = 'WhatsApp no encolado';
      }
      $messageText = 'Cotización enviada. Correo en cola: ' . $emailQueued . '. WhatsApp en cola: ' . $whatsappQueued . '.';
      if ($warnings !== []) {
        $messageText .= ' Revisa: ' . implode(', ', $warnings) . '.';
      }
      $this->jsonOk([
        'message' => $messageText,
        'id_cotizacion' => (string) $cotizacionId,
        'email_queued' => (string) $emailQueued,
        'whatsapp_queued' => (string) $whatsappQueued,
        'quote_url' => $quoteUrl,
        'pdf_url' => (string) ($document['url'] ?? ''),
      ]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[cotizacion_mantenimiento_send] ' . $error->getMessage());
      $this->jsonFail('No se pudo enviar la cotización.');
    }
  }

  /** @return array<string,string> */
  public function public_respond_cotizacion_mantenimiento(int $cotizacionId, string $estadoRaw, string $observacionRaw, string $motivoRaw = '', string $financiacionRaw = '', string $responderNameRaw = ''): array
  {
    if ($cotizacionId <= 0) {
      return ['ok' => '0', 'message' => 'Cotización inválida.'];
    }
    $estado = trim(strip_tags($estadoRaw));
    if (!in_array($estado, ['Aprobada', 'Desaprobada'], true)) {
      return ['ok' => '0', 'message' => 'Selecciona si apruebas o desapruebas la cotización.'];
    }
    $observacion = trim(wp_kses_post($observacionRaw));
    if ($observacion === '') {
      $observacion = 'Respuesta registrada desde enlace público.';
    }
    $motivo = trim(strip_tags($motivoRaw));
    if ($estado === 'Desaprobada' && $motivo === '') {
      return ['ok' => '0', 'message' => 'Indica el motivo de la desaprobación.'];
    }
    $financiacion = $estado === 'Aprobada' ? trim(strip_tags($financiacionRaw)) : '';
    $responderName = trim(strip_tags($responderNameRaw));
    if ($responderName !== '') {
      $observacion .= "\n\nRespondido por: " . $responderName;
    }

    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      return ['ok' => '0', 'message' => 'La tabla de cotizaciones no está disponible.'];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    if (!is_array($row)) {
      return ['ok' => '0', 'message' => 'Cotización no encontrada.'];
    }
    $currentState = strtolower(trim((string) ($row['estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
    if (!in_array($currentState, ['', 'esperando respuesta'], true)) {
      return ['ok' => '0', 'message' => 'Esta cotización ya fue respondida.'];
    }
    $ticketPk = (int) ($row['id_ticket'] ?? 0);
    if ($ticketPk <= 0) {
      return ['ok' => '0', 'message' => 'La cotización no tiene ticket asociado.'];
    }
    return $this->get_seguimiento_service()->saveCotizacionResponse($ticketPk, $estado, $observacion, $motivo, $financiacion, [], $cotizacionId);
  }

  /** @return array{path:string,url:string,name:string,attachment_name:string} */
  private function maintenance_quote_store_pdf_document(\SCM\Support\SimplePdf $pdf, int $cotizacionId, string $audience): array
  {
    $dir = (string) SCM_UPLOAD_PATH;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
      throw new \DomainException('No se pudo preparar el PDF para adjuntar.');
    }
    $name = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = rtrim($dir, '/\\') . '/' . $name;
    $pdf->save($path);
    if (!is_file($path)) {
      throw new \DomainException('No se pudo generar el PDF de la cotización.');
    }
    $attachmentName = 'cotizacion-mantenimiento-' . $cotizacionId . '-' . ($audience === 'destinatario' ? 'destinatario' : 'funcionario') . '.pdf';
    return [
      'path' => $path,
      'url' => \SCM\Support\StoredFileService::fromRuntime()->urlFor($name),
      'name' => $name,
      'attachment_name' => $attachmentName,
    ];
  }

  private function maintenance_quote_whatsapp_phone(string $indicativo, string $celular): string
  {
    $indicativoDigits = preg_replace('/\D+/', '', $indicativo) ?: '';
    $phoneDigits = preg_replace('/\D+/', '', $celular) ?: '';
    if ($phoneDigits === '') {
      return '';
    }
    if (strlen($phoneDigits) > 10 || str_starts_with($phoneDigits, '57')) {
      return '+' . ltrim($phoneDigits, '+');
    }
    if ($indicativoDigits === '') {
      $indicativoDigits = '57';
    }
    return '+' . $indicativoDigits . $phoneDigits;
  }

  private function maintenance_quote_whatsapp_url_button_suffix(string $url): string
  {
    $parts = parse_url($url);
    if (!is_array($parts)) {
      return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    $query = trim((string) ($parts['query'] ?? ''));
    if ($host === 'sucasainmobiliaria.com.co' && $path !== '') {
      return $path . ($query !== '' ? '?' . $query : '');
    }
    return $url;
  }

  private function maintenance_quote_whatsapp_text(string $text): string
  {
    $text = preg_replace('/[\r\n\t]+/', ' ', trim($text)) ?: '';
    $text = preg_replace('/ {2,}/', ' ', $text) ?: $text;
    return mb_substr($text !== '' ? $text : '-', 0, 900, 'UTF-8');
  }

  /** @param array<string,mixed> $quote @return array<string,mixed>|null */
  private function maintenance_quote_ticket_row_for_quote(\SCM\Support\SchemaInspector $schema, array $quote): ?array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$schema->tableExists($table)) {
      return null;
    }
    $ticketRef = trim((string) ($quote['id_ticket'] ?? ''));
    if ($ticketRef === '') {
      return null;
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $ticketRef]);
    if (is_array($row)) {
      return $row;
    }
    if ($schema->columnExists($table, 'id_ticket')) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketRef]);
      if (is_array($row)) {
        return $row;
      }
    }
    return null;
  }

  /** @param array<string,mixed> $quote @param array<string,mixed> $ticket @param array<string,mixed> $actor */
  private function maintenance_quote_insert_send_histories(\SCM\Support\SchemaInspector $schema, int $quoteId, array $quote, array $ticket, array $actor, string $employeeId, int $now, string $nowSql): void
  {
    $ticketRef = $this->maintenance_quote_first([$quote['id_ticket'] ?? '', $ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $propertyRef = $this->maintenance_quote_first([$quote['id_inmueble'] ?? '', $quote['inmueble'] ?? '', $ticket['id_inmueble'] ?? '', $ticket['inmueble'] ?? '']);
    $propertyDataRef = $this->maintenance_quote_first([$ticket['id_inmueble_data'] ?? '', $propertyRef]);
    $actorName = trim((string) ($actor['name'] ?? Auth::user()));
    $actorEmail = trim((string) ($actor['email'] ?? ''));
    $actorPhone = trim((string) ($actor['phone'] ?? ''));
    $destinatario = trim((string) ($quote['destinatario'] ?? 'destinatario'));
    $message = 'Se envió la cotización de mantenimiento #' . $quoteId . ' a ' . ($destinatario !== '' ? $destinatario : 'destinatario') . ' por correo y WhatsApp. Quedó en Esperando respuesta.';

    $histTicketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($histTicketTable)) {
      $payload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_ticket' => $ticketRef,
        'fecha' => $now,
        'nombre' => $actorName,
        'correo' => $actorEmail,
        'celular' => $actorPhone,
        'respuesta' => $message,
        'respuesta_cct_ticket' => $message,
        'id_revision_correctiva' => trim((string) ($quote['id_revision'] ?? '')),
        'id_cotizacion_mantenimiento' => (string) $quoteId,
        'id_empleado' => $employeeId,
        'estado_cotizacion' => 'Esperando respuesta',
        'fue_editada' => 'Si',
      ];
      $payload = $schema->filterTableData($histTicketTable, $payload);
      if (!empty($payload)) {
        $this->db->insert($histTicketTable, $payload);
      }
    }

    $histInmuebleTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($schema->tableExists($histInmuebleTable)) {
      $payload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $propertyRef,
        'id_inmueble_data' => $propertyDataRef,
        'fecha' => $now,
        'tipo_de_reporte_his' => 'Mantenimiento',
        'tipo_reporte' => 'Mantenimiento',
        'observacion_his' => $message,
        'observacion' => $message,
        'funcionario' => $actorName,
        'id_ticket' => $ticketRef,
        'id_cotizacion_mantenimiento' => (string) $quoteId,
      ];
      $payload = $schema->filterTableData($histInmuebleTable, $payload);
      if (!empty($payload)) {
        $this->db->insert($histInmuebleTable, $payload);
      }
    }
  }

  private function maintenance_order_can_manage(): bool
  {
    return $this->canUseDashboardAction('quote_order_create') && (
      $this->canAccessDashboardTab('cotizaciones_mantenimiento')
      || $this->canAccessDashboardTab('abiertos')
      || $this->canAccessDashboardTab('postergados')
      || $this->canAccessDashboardTab('mis_tickets')
    );
  }

  private function maintenance_order_can_respond(): bool
  {
    return $this->canUseDashboardAction('quote_order_respond') && (
      $this->canAccessDashboardTab('cotizaciones_mantenimiento')
      || $this->canAccessDashboardTab('abiertos')
      || $this->canAccessDashboardTab('postergados')
      || $this->canAccessDashboardTab('mis_tickets')
    );
  }

  /** @return array<string,mixed>|null */
  private function maintenance_order_find_cotizacion(int $cotizacionId): ?array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      return null;
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$cotizacionId]);
    return is_array($row) ? $row : null;
  }

  /** @param array<string,mixed> $cotizacion @return array<string,mixed>|null */
  private function maintenance_order_active_satisfaction_act(array $cotizacion): ?array
  {
    $cotizacionId = trim((string) ($cotizacion['_ID'] ?? ''));
    $legacyActId = trim((string) ($cotizacion['id_acta_satisfaccion'] ?? ''));
    $ticketRef = trim((string) ($cotizacion['id_ticket'] ?? ''));
    $actsTable = $this->db->table('scm_ticket_completion_acts');

    if ($this->table_exists($actsTable)) {
      $conditions = [];
      $params = [];
      if ($legacyActId !== '') {
        $conditions[] = 'legacy_act_id = ?';
        $params[] = (int) $legacyActId;
      }
      if ($cotizacionId !== '') {
        $conditions[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.source.quote_id')) = ?";
        $params[] = $cotizacionId;
      }
      if ($ticketRef !== '' && ctype_digit($ticketRef)) {
        $conditions[] = 'ticket_pk = ?';
        $params[] = (int) $ticketRef;
      }
      if ($conditions !== []) {
        $row = $this->db->getRow(
          "SELECT id, status, legacy_act_id, ticket_pk FROM `{$actsTable}` WHERE status IN ('pending', 'signed') AND (" . implode(' OR ', $conditions) . ") ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'signed' THEN 1 ELSE 2 END, COALESCE(signed_at, created_at) DESC, id DESC LIMIT 1",
          $params
        );
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
          return $row;
        }
      }
    }

    if ($legacyActId !== '') {
      return ['id' => (int) $legacyActId, 'status' => 'legacy'];
    }

    if ($ticketRef !== '') {
      $ticketsTable = $this->db->table('jet_cct_tickets');
      if ($this->table_exists($ticketsTable)) {
        $ticket = ctype_digit($ticketRef)
          ? $this->db->getRow("SELECT `id_acta_satisfaccion`, `estado_acta_satisfaccion` FROM `{$ticketsTable}` WHERE `_ID` = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [(int) $ticketRef, $ticketRef])
          : $this->db->getRow("SELECT `id_acta_satisfaccion`, `estado_acta_satisfaccion` FROM `{$ticketsTable}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketRef]);
        if (is_array($ticket) && trim((string) ($ticket['id_acta_satisfaccion'] ?? '')) !== '') {
          $actState = strtolower(trim((string) ($ticket['estado_acta_satisfaccion'] ?? '')));
          if ($actState === '' || in_array($actState, ['si', 'sí', 'pending', 'signed', 'firmada', 'pendiente'], true)) {
            return [
              'id' => (int) ($ticket['id_acta_satisfaccion'] ?? 0),
              'status' => $actState !== '' ? $actState : 'legacy',
            ];
          }
        }
      }
    }

    return null;
  }

  /** @param array<string,mixed> $cotizacion @return array<string,mixed> */
  private function maintenance_order_context_payload(array $cotizacion): array
  {
    return [
      'id' => trim((string) ($cotizacion['_ID'] ?? '')),
      'ticket_pk' => trim((string) ($cotizacion['id_ticket'] ?? '')),
      'direccion' => trim((string) ($cotizacion['direccion'] ?? '')),
      'inmueble' => $this->maintenance_order_first([$cotizacion['inmueble'] ?? '', $cotizacion['id_inmueble'] ?? '']),
      'contrato' => $this->maintenance_order_first([$cotizacion['contrato'] ?? '', $cotizacion['id_contrato'] ?? '']),
      'destinatario' => trim((string) ($cotizacion['destinatario'] ?? '')),
      'balances' => [
        'Mano de obra' => [
          'field' => 'saldo_obra',
          'value' => $this->maintenance_order_money_value($cotizacion['saldo_obra'] ?? 0),
          'label' => $this->format_cop_currency($cotizacion['saldo_obra'] ?? 0),
        ],
        'Materiales' => [
          'field' => 'saldo_materiales',
          'value' => $this->maintenance_order_money_value($cotizacion['saldo_materiales'] ?? 0),
          'label' => $this->format_cop_currency($cotizacion['saldo_materiales'] ?? 0),
        ],
        'Maquinarias' => [
          'field' => 'saldo_maquinarias',
          'value' => $this->maintenance_order_money_value($cotizacion['saldo_maquinarias'] ?? 0),
          'label' => $this->format_cop_currency($cotizacion['saldo_maquinarias'] ?? 0),
        ],
        'Otros costos' => [
          'field' => 'saldo_otros_costo',
          'value' => $this->maintenance_order_money_value($cotizacion['saldo_otros_costo'] ?? 0),
          'label' => $this->format_cop_currency($cotizacion['saldo_otros_costo'] ?? 0),
        ],
      ],
    ];
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_order_provider_options(): array
  {
    $table = $this->db->table('jet_cct_proveedores');
    if (!$this->table_exists($table)) {
      return [];
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `proveedor`, `tipo_identificacion_proveedor`, `identificacion_proveedor`, `correo_proveedor`, `celular_proveedor`, `direccion_proveedor`, `titular_proveedor`, `identificacion_cuenta_proveedor`, `tipo_cuenta_proveedor`, `cuenta_proveedor`, `banco_proveedor`, `correo_pago_proveedor`, `compras`
         FROM `{$table}`
        WHERE `cct_status` = 'publish' OR `cct_status` IS NULL OR `cct_status` = ''
        ORDER BY `proveedor` ASC
        LIMIT 400"
    );
    $providers = [];
    foreach ($rows as $row) {
      $providers[] = [
        'id' => trim((string) ($row['_ID'] ?? '')),
        'proveedor' => trim((string) ($row['proveedor'] ?? '')),
        'tipo_identificacion_proveedor' => trim((string) ($row['tipo_identificacion_proveedor'] ?? '')),
        'identificacion_proveedor' => trim((string) ($row['identificacion_proveedor'] ?? '')),
        'correo_proveedor' => trim((string) ($row['correo_proveedor'] ?? '')),
        'celular_proveedor' => trim((string) ($row['celular_proveedor'] ?? '')),
        'direccion_proveedor' => trim((string) ($row['direccion_proveedor'] ?? '')),
        'titular_proveedor' => trim((string) ($row['titular_proveedor'] ?? '')),
        'identificacion_cuenta_proveedor' => trim((string) ($row['identificacion_cuenta_proveedor'] ?? '')),
        'tipo_cuenta_proveedor' => trim((string) ($row['tipo_cuenta_proveedor'] ?? '')),
        'cuenta_proveedor' => trim((string) ($row['cuenta_proveedor'] ?? '')),
        'banco_proveedor' => trim((string) ($row['banco_proveedor'] ?? '')),
        'correo_pago_proveedor' => trim((string) ($row['correo_pago_proveedor'] ?? '')),
        'compras' => trim((string) ($row['compras'] ?? '0')),
      ];
    }
    return $providers;
  }

  /** @return array<int,array<string,string>> */
  private function maintenance_order_bank_options(): array
  {
    $table = $this->db->table('jet_cct_bancos');
    if (!$this->table_exists($table)) {
      return [];
    }

    $rows = $this->db->getResults(
      "SELECT `_ID`, `banco`, `pais`
         FROM `{$table}`
        WHERE (`cct_status` = 'publish' OR `cct_status` IS NULL OR `cct_status` = '')
          AND TRIM(COALESCE(`banco`, '')) <> ''
        ORDER BY `banco` ASC
        LIMIT 400"
    );

    $banks = [];
    $seen = [];
    foreach ($rows as $row) {
      $name = trim((string) ($row['banco'] ?? ''));
      if ($name === '') {
        continue;
      }
      $key = strtolower($name);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $banks[] = [
        'id' => trim((string) ($row['_ID'] ?? '')),
        'value' => $name,
        'label' => $name,
        'pais' => trim((string) ($row['pais'] ?? '')),
      ];
    }
    return $banks;
  }

  private function maintenance_order_clean($value): string
  {
    return trim(sanitize_text_field(wp_unslash((string) ($value ?? ''))));
  }

  private function maintenance_order_email($value): string
  {
    return trim(sanitize_email(wp_unslash((string) ($value ?? ''))));
  }

  private function maintenance_order_category(string $raw): string
  {
    $raw = trim($raw);
    $normalized = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $raw));
    return match ($normalized) {
      'mano de obra' => 'Mano de obra',
      'materiales' => 'Materiales',
      'maquinarias', 'maquinaria', 'equipos', 'equipos / maquinarias' => 'Maquinarias',
      'otros costos', 'otros costo' => 'Otros costos',
      default => '',
    };
  }

  private function maintenance_order_response_state(string $raw): string
  {
    $normalized = strtolower(trim(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $raw)));
    return match ($normalized) {
      'aprobada', 'aprobado', 'aprobar', 'si', 's', 'yes' => 'Aprobada',
      'desaprobada', 'desaprobado', 'rechazada', 'rechazado', 'rechazar', 'no' => 'Desaprobada',
      default => '',
    };
  }

  private function maintenance_order_default_activity(string $category): string
  {
    return match ($category) {
      'Mano de obra' => 'MANO DE OBRA PARA REALIZAR TRABAJOS CORRESPONDIENTES',
      'Materiales' => 'MATERIAL PARA REALIZAR TRABAJOS CORRESPONDIENTES',
      'Maquinarias' => 'MAQUINARIA PARA REALIZAR TRABAJOS CORRESPONDIENTES',
      'Otros costos' => 'OTROS GASTOS PARA REALIZAR TRABAJOS CORRESPONDIENTES',
      default => '',
    };
  }

  private function maintenance_order_balance_column(string $category): string
  {
    return match ($category) {
      'Mano de obra' => 'saldo_obra',
      'Materiales' => 'saldo_materiales',
      'Maquinarias' => 'saldo_maquinarias',
      'Otros costos' => 'saldo_otros_costo',
      default => 'saldo_obra',
    };
  }

  private function maintenance_order_money($value): float
  {
    return $this->maintenance_order_money_value($value);
  }

  private function maintenance_order_money_value($value): float
  {
    if (is_int($value) || is_float($value)) {
      return (float) $value;
    }
    $text = trim((string) $value);
    if ($text === '') {
      return 0.0;
    }
    $text = preg_replace('/[^\d,.\-]/', '', $text) ?? '';
    if ($text === '' || $text === '-') {
      return 0.0;
    }
    $lastComma = strrpos($text, ',');
    $lastDot = strrpos($text, '.');
    if ($lastComma !== false && $lastDot !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $thousand = $decimal === ',' ? '.' : ',';
      $text = str_replace($thousand, '', $text);
      $text = str_replace($decimal, '.', $text);
    } elseif ($lastComma !== false) {
      $parts = explode(',', $text);
      if (strlen((string) end($parts)) <= 2) {
        $text = str_replace(',', '.', str_replace('.', '', $text));
      } else {
        $text = str_replace(',', '', $text);
      }
    } elseif ($lastDot !== false) {
      $parts = explode('.', $text);
      if (strlen((string) end($parts)) > 2) {
        $text = str_replace('.', '', $text);
      }
    }
    return is_numeric($text) ? max(0.0, (float) $text) : 0.0;
  }

  /** @param array<mixed> $values */
  private function maintenance_order_first(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string) ($value ?? ''));
      if ($text !== '') {
        return $text;
      }
    }
    return '';
  }

  /** @param array<string,mixed> $input @return array<string,string> */
  private function maintenance_order_provider_payload(array $input): array
  {
    return [
      'proveedor' => $this->maintenance_order_clean($input['proveedor'] ?? ''),
      'tipo_identificacion_proveedor' => $this->maintenance_order_clean($input['tipo_identificacion_proveedor'] ?? ''),
      'identificacion_proveedor' => $this->maintenance_order_clean($input['identificacion_proveedor'] ?? ''),
      'correo_proveedor' => $this->maintenance_order_email($input['correo_proveedor'] ?? ''),
      'celular_proveedor' => $this->maintenance_order_clean($input['celular_proveedor'] ?? ''),
      'direccion_proveedor' => $this->maintenance_order_clean($input['direccion_proveedor'] ?? ''),
      'titular_proveedor' => $this->maintenance_order_clean($input['titular_proveedor'] ?? ''),
      'identificacion_cuenta_proveedor' => $this->maintenance_order_clean($input['identificacion_cuenta_proveedor'] ?? ''),
      'tipo_cuenta_proveedor' => $this->maintenance_order_clean($input['tipo_cuenta_proveedor'] ?? ''),
      'cuenta_proveedor' => $this->maintenance_order_clean($input['cuenta_proveedor'] ?? ''),
      'banco_proveedor' => $this->maintenance_order_clean($input['banco_proveedor'] ?? ''),
      'correo_pago_proveedor' => $this->maintenance_order_email($input['correo_pago_proveedor'] ?? ''),
    ];
  }

  /** @param array<string,string> $providerPayload */
  private function maintenance_order_save_provider(\SCM\Support\SchemaInspector $schema, int $providerId, array $providerPayload): int
  {
    $table = $this->db->table('jet_cct_proveedores');
    if (!$schema->tableExists($table)) {
      return 0;
    }
    $nowMysql = date('Y-m-d H:i:s');
    $userId = Auth::userId();
    $employeeId = trim((string) (method_exists($this, 'current_employee_id') ? $this->current_employee_id() : ''));
    if ($employeeId === '') {
      $employeeId = (string) $userId;
    }

    if ($providerId > 0) {
      $exists = (int) ($this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$providerId]) ?? 0);
      if ($exists <= 0) {
        $providerId = 0;
      }
    }

    if ($providerId <= 0) {
      $providerId = $this->maintenance_order_find_existing_provider_id($schema, $table, $providerPayload);
    }

    if ($providerId > 0) {
      $currentCompras = (int) ($this->db->getVar("SELECT COALESCE(`compras`, 0) FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$providerId]) ?? 0);
      $update = array_merge($providerPayload, [
        'compras' => (string) ($currentCompras + 1),
        'cct_modified' => $nowMysql,
      ]);
      $update = $schema->filterTableData($table, $update);
      if (!empty($update)) {
        $this->db->update($table, $update, ['_ID' => $providerId]);
      }
      return $providerId;
    }

    $insert = array_merge($providerPayload, [
      'cct_status' => 'publish',
      'compras' => '1',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
    ]);
    $insert = $schema->filterTableData($table, $insert);
    if (!empty($insert) && $this->db->insert($table, $insert)) {
      return (int) $this->db->lastInsertId();
    }
    return 0;
  }

  /** @param array<string,string> $providerPayload */
  private function maintenance_order_find_existing_provider_id(\SCM\Support\SchemaInspector $schema, string $table, array $providerPayload): int
  {
    $activeSql = $schema->columnExists($table, 'cct_status')
      ? " AND (`cct_status` = 'publish' OR `cct_status` IS NULL OR `cct_status` = '')"
      : '';

    $identity = trim((string) ($providerPayload['identificacion_proveedor'] ?? ''));
    if ($identity !== '' && $schema->columnExists($table, 'identificacion_proveedor')) {
      $found = (int) ($this->db->getVar(
        "SELECT `_ID` FROM `{$table}` WHERE TRIM(`identificacion_proveedor`) = ?{$activeSql} ORDER BY `_ID` ASC LIMIT 1",
        [$identity]
      ) ?? 0);
      if ($found > 0) {
        return $found;
      }
    }

    $email = strtolower(trim((string) ($providerPayload['correo_proveedor'] ?? '')));
    if ($email !== '' && $schema->columnExists($table, 'correo_proveedor')) {
      $found = (int) ($this->db->getVar(
        "SELECT `_ID` FROM `{$table}` WHERE LOWER(TRIM(`correo_proveedor`)) = ?{$activeSql} ORDER BY `_ID` ASC LIMIT 1",
        [$email]
      ) ?? 0);
      if ($found > 0) {
        return $found;
      }
    }

    $phone = preg_replace('/\D+/', '', (string) ($providerPayload['celular_proveedor'] ?? '')) ?? '';
    if ($phone !== '' && $schema->columnExists($table, 'celular_proveedor')) {
      $rows = $this->db->getResults(
        "SELECT `_ID`, `celular_proveedor` FROM `{$table}` WHERE `celular_proveedor` IS NOT NULL AND TRIM(`celular_proveedor`) <> ''{$activeSql} ORDER BY `_ID` ASC LIMIT 400"
      );
      foreach ($rows as $row) {
        $candidatePhone = preg_replace('/\D+/', '', (string) ($row['celular_proveedor'] ?? '')) ?? '';
        if ($candidatePhone !== '' && $candidatePhone === $phone) {
          return (int) ($row['_ID'] ?? 0);
        }
      }
    }

    return 0;
  }

  /** @return array<string,string> */
  private function maintenance_order_current_user_payload(): array
  {
    $name = trim(Auth::user());
    $email = '';
    $phone = '';
    $funcTable = $this->db->table('jet_cct_funcionarios');
    $userId = Auth::userId();
    if ($userId > 0 && $this->table_exists($funcTable)) {
      $row = $this->db->getRow("SELECT * FROM `{$funcTable}` WHERE `_ID` = ? LIMIT 1", [$userId]);
      if (is_array($row)) {
        $name = $this->maintenance_order_first([$row['nombre'] ?? '', $name, 'Funcionario']);
        $email = $this->maintenance_order_first([$row['correo'] ?? '', $row['correo_empleado'] ?? '', $row['email'] ?? '']);
        $phone = $this->maintenance_order_first([$row['celular'] ?? '', $row['celular_empleado'] ?? '', $row['telefono'] ?? '']);
      }
    }
    if ($name === '') {
      $name = $userId > 0 ? 'Usuario #' . $userId : 'Sistema';
    }
    return ['nombre' => $name, 'email' => $email, 'celular' => $phone];
  }

  /** @return array<string,string> */
  private function maintenance_order_item_payload(string $category, string $concept, string $provider, float $value): array
  {
    $valueText = (string) (int) round($value);
    return match ($category) {
      'Mano de obra' => [
        'items_mano' => serialize([[
          'descripcion_mano' => $concept,
          'unidad_mano' => 'Unidad',
          'cantidad_mano' => '1',
          'valor_mano' => $valueText,
          'valor_total_item_mo' => $valueText,
        ]]),
      ],
      'Materiales' => [
        'items_materiales' => serialize([[
          'provedor_materiales' => $provider,
          'valor_materiales' => $valueText,
        ]]),
      ],
      'Maquinarias' => [
        'items_otros_equi' => serialize([[
          'provedor_maquinaria' => $provider,
          'valor_maquinarias' => $valueText,
        ]]),
      ],
      'Otros costos' => [
        'items_otros_costos' => serialize([[
          'descripcion_otros_costos' => $concept,
          'valor_otros_costos' => $valueText,
        ]]),
      ],
      default => [],
    };
  }

  /** @param array<string,mixed> $cotizacion */
  private function maintenance_order_update_cotizacion_balance(\SCM\Support\SchemaInspector $schema, array $cotizacion, string $category, float $currentBalance, float $value, string $nowMysql): void
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $balanceColumn = $this->maintenance_order_balance_column($category);
    $update = [
      $balanceColumn => (string) max(0, (int) round($currentBalance - $value)),
      'se_envio' => 'Si',
      'estado' => 'Aprobada',
      'cct_modified' => $nowMysql,
    ];
    $update = $schema->filterTableData($table, $update);
    if (!empty($update)) {
      $this->db->update($table, $update, ['_ID' => (int) ($cotizacion['_ID'] ?? 0)]);
    }
  }

  /** @param array<string,mixed> $cotizacion */
  private function maintenance_order_update_ticket(\SCM\Support\SchemaInspector $schema, array $cotizacion, int $orderId, int $now, string $nowMysql): void
  {
    $ticketRef = trim((string) ($cotizacion['id_ticket'] ?? ''));
    if ($ticketRef === '') {
      return;
    }
    $table = $this->db->table('jet_cct_tickets');
    if (!$schema->tableExists($table)) {
      return;
    }
    $ticket = null;
    if (ctype_digit($ticketRef)) {
      $ticket = $this->db->getRow("SELECT `_ID` FROM `{$table}` WHERE `_ID` = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [(int) $ticketRef, $ticketRef]);
    } else {
      $ticket = $this->db->getRow("SELECT `_ID` FROM `{$table}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketRef]);
    }
    if (!is_array($ticket)) {
      return;
    }
    $update = [
      'estado_administrativo' => 'En ejecucion por inmobiliaria',
      'fecha_actualizacion' => $now,
      'cct_modified' => $nowMysql,
    ];
    if ($orderId > 0) {
      $update['id_orden'] = (string) $orderId;
    }
    $update = $schema->filterTableData($table, $update);
    if (!empty($update)) {
      $this->db->update($table, $update, ['_ID' => (int) ($ticket['_ID'] ?? 0)]);
    }
  }

  /** @param array<string,mixed> $cotizacion @param array<string,string> $user */
  private function maintenance_order_insert_histories(\SCM\Support\SchemaInspector $schema, array $cotizacion, int $orderId, string $category, string $concept, float $value, array $user, string $employeeId, int $now, string $nowMysql): void
  {
    $ticketRef = trim((string) ($cotizacion['id_ticket'] ?? ''));
    $detail = 'Se ha elaborado orden de ' . strtolower($category) . ' #' . $orderId . ' por ' . $this->format_cop_currency($value) . '. Concepto: ' . $concept . '.';

    $histTicketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($histTicketTable)) {
      $ticketPayload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_ticket' => $ticketRef,
        'fecha' => $now,
        'nombre' => $user['nombre'],
        'correo' => $user['email'],
        'celular' => $user['celular'],
        'respuesta' => $detail,
        'id_revision_correctiva' => trim((string) ($cotizacion['id_revision'] ?? '')),
        'id_cotizacion_mantenimiento' => trim((string) ($cotizacion['_ID'] ?? '')),
        'id_empleado' => $employeeId,
        'id_orden' => (string) $orderId,
        'fue_editada' => 'Si',
      ];
      $ticketPayload = $schema->filterTableData($histTicketTable, $ticketPayload);
      if (!empty($ticketPayload)) {
        $this->db->insert($histTicketTable, $ticketPayload);
      }
    }

    $histInmuebleTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($schema->tableExists($histInmuebleTable)) {
      $propertyPayload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $this->maintenance_order_first([$cotizacion['id_inmueble'] ?? '', $cotizacion['inmueble'] ?? '']),
        'fecha' => $now,
        'tipo_reporte' => 'Mantenimiento',
        'observacion' => 'Se ha creado orden de mantenimiento. ' . $detail,
        'funcionario' => $user['nombre'],
        'id_ticket' => $ticketRef,
        'id_inmueble_data' => $this->maintenance_order_first([$cotizacion['id_inmueble'] ?? '', $cotizacion['inmueble'] ?? '']),
      ];
      $propertyPayload = $schema->filterTableData($histInmuebleTable, $propertyPayload);
      if (!empty($propertyPayload)) {
        $this->db->insert($histInmuebleTable, $propertyPayload);
      }
    }
  }

  /** @param array<string,mixed> $order @param array<string,string> $user */
  private function maintenance_order_insert_response_histories(\SCM\Support\SchemaInspector $schema, array $order, string $estado, string $observacion, array $user, string $employeeId, int $now, string $nowMysql): void
  {
    $orderId = trim((string) ($order['_ID'] ?? ''));
    $ticketRef = trim((string) ($order['id_ticket'] ?? ''));
    $category = trim((string) ($order['categoria'] ?? 'mantenimiento'));
    $propertyRef = $this->maintenance_order_first([$order['id_inmueble'] ?? '', $order['inmueble'] ?? '']);
    $detail = 'Se ha ' . ($estado === 'Aprobada' ? 'aprobado' : 'desaprobado') . ' la orden de ' . strtolower($category) . ($orderId !== '' ? ' #' . $orderId : '') . '.';
    if ($observacion !== '') {
      $detail .= ' Observacion: ' . $observacion;
    }

    $histTicketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($histTicketTable)) {
      $ticketPayload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_ticket' => $ticketRef,
        'fecha' => $now,
        'nombre' => $user['nombre'],
        'correo' => $user['email'],
        'celular' => $user['celular'],
        'respuesta' => $detail,
        'respuesta_cct_ticket' => $detail,
        'id_cotizacion_mantenimiento' => trim((string) ($order['id_cotizacion'] ?? '')),
        'id_empleado' => $employeeId,
        'id_orden' => $orderId,
        'fue_editada' => 'Si',
      ];
      $ticketPayload = $schema->filterTableData($histTicketTable, $ticketPayload);
      if (!empty($ticketPayload)) {
        $this->db->insert($histTicketTable, $ticketPayload);
      }
    }

    $histInmuebleTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($schema->tableExists($histInmuebleTable)) {
      $propertyPayload = [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $propertyRef,
        'fecha' => $now,
        'tipo_reporte' => 'Mantenimiento',
        'observacion' => $detail,
        'observacion_his' => $detail,
        'funcionario' => $user['nombre'],
        'id_ticket' => $ticketRef,
        'id_inmueble_data' => $propertyRef,
      ];
      $propertyPayload = $schema->filterTableData($histInmuebleTable, $propertyPayload);
      if (!empty($propertyPayload)) {
        $this->db->insert($histInmuebleTable, $propertyPayload);
      }
    }
  }

  /** @return array<int,array{name:string,email:string,phone:string,id:string}> */
  private function maintenance_order_internal_email_recipients(string $action): array
  {
    $selectedIds = array_map('strval', $this->internalNotificationRecipientsForAction($action));
    if ($selectedIds === []) {
      return [];
    }

    $selected = array_fill_keys($selectedIds, true);
    $recipients = [];
    foreach ($this->internalNotificationFuncionarioOptions() as $funcionario) {
      $id = trim((string) ($funcionario['id'] ?? ''));
      $email = trim((string) ($funcionario['email'] ?? ''));
      if ($id === '' || !isset($selected[$id]) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $recipients[] = [
        'id' => $id,
        'name' => trim((string) ($funcionario['name'] ?? '')),
        'email' => $email,
        'phone' => trim((string) ($funcionario['phone'] ?? '')),
      ];
    }

    return $this->maintenance_order_unique_email_recipients($recipients);
  }

  /** @return array<int,array{name:string,phone:string,id:string}> */
  private function maintenance_order_internal_whatsapp_recipients(string $action): array
  {
    $selectedIds = array_map('strval', $this->internalNotificationRecipientsForAction($action));
    if ($selectedIds === []) {
      return [];
    }

    $selected = array_fill_keys($selectedIds, true);
    $recipients = [];
    foreach ($this->internalNotificationFuncionarioOptions() as $funcionario) {
      $id = trim((string) ($funcionario['id'] ?? ''));
      $phone = trim((string) ($funcionario['phone'] ?? ''));
      if ($id === '' || !isset($selected[$id]) || $phone === '') {
        continue;
      }
      $recipients[] = [
        'id' => $id,
        'name' => trim((string) ($funcionario['name'] ?? 'Funcionario')),
        'phone' => $phone,
      ];
    }

    return $recipients;
  }

  /** @param array<int,array{name:string,email:string,phone?:string,id?:string}> $recipients @return array<int,array{name:string,email:string,phone:string,id:string}> */
  private function maintenance_order_unique_email_recipients(array $recipients): array
  {
    $seen = [];
    $out = [];
    foreach ($recipients as $recipient) {
      $email = strtolower(trim((string) ($recipient['email'] ?? '')));
      if ($email === '' || isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $seen[$email] = true;
      $out[] = [
        'id' => trim((string) ($recipient['id'] ?? '')),
        'name' => trim((string) ($recipient['name'] ?? '')),
        'email' => $email,
        'phone' => trim((string) ($recipient['phone'] ?? '')),
      ];
    }
    return $out;
  }

  private function maintenance_order_order_url(int $orderId): string
  {
    $expires = time() + (30 * 86400);
    return rtrim((string) SCM_BASE_URL, '/') . '/orden-publica.php?' . http_build_query([
      'numero' => $orderId,
      'expires' => $expires,
      'sig' => $this->maintenance_order_public_signature($orderId, $expires),
    ], '', '&', PHP_QUERY_RFC3986);
  }

  private function maintenance_order_public_signature(int $orderId, int $expires): string
  {
    return hash_hmac('sha256', 'maintenance-order-public|' . $orderId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  private function maintenance_order_email_html(string $title, array $lines, int $orderId): string
  {
    $items = '';
    foreach ($lines as $label => $value) {
      $items .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-weight:700;">' . esc_html((string) $label) . '</td><td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#0b1f3a;">' . esc_html((string) $value) . '</td></tr>';
    }
    $url = $this->maintenance_order_order_url($orderId);
    return '<div style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#0b1f3a;">'
      . '<div style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #dbe4ef;border-radius:16px;overflow:hidden;">'
      . '<div style="padding:20px 24px;background:#0b1f3a;color:#ffffff;"><h1 style="margin:0;font-size:21px;">' . esc_html($title) . '</h1></div>'
      . '<div style="padding:24px;"><p style="margin:0 0 16px;">Se registró una novedad de orden de mantenimiento en Control Servicios Inmobiliarios.</p>'
      . '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">' . $items . '</table>'
      . '<p style="text-align:center;margin:22px 0 0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#f97316;color:#ffffff;text-decoration:none;font-weight:700;">Ver orden</a></p>'
      . '</div></div></div>';
  }

  /** @param array<string,mixed> $order @param array<string,mixed> $cotizacion @param array<string,string> $user */
  private function maintenance_order_enqueue_created_notifications(array $order, array $cotizacion, array $user, int $orderId): int
  {
    $emailRecipients = $this->maintenance_order_internal_email_recipients('orden_mantenimiento_creada');
    $whatsappRecipients = $this->maintenance_order_internal_whatsapp_recipients('orden_mantenimiento_creada');
    if ($emailRecipients === [] && $whatsappRecipients === []) {
      return 0;
    }

    $category = trim((string) ($order['categoria'] ?? 'mantenimiento'));
    $ticket = $this->maintenance_order_first([$order['id_ticket'] ?? '', $cotizacion['id_ticket'] ?? '']);
    $orderUrl = $this->maintenance_order_order_url($orderId);
    $subject = 'Orden de ' . strtolower($category) . ($ticket !== '' ? ' del caso #' . $ticket : '') . ' pendiente';
    $html = $this->maintenance_order_email_html('Orden de mantenimiento creada', [
      'Orden' => '#' . $orderId,
      'Estado' => trim((string) ($order['estado'] ?? 'Esperando respuesta')),
      'Caso' => $ticket !== '' ? '#' . $ticket : '-',
      'Cotizacion' => '#' . trim((string) ($order['id_cotizacion'] ?? $cotizacion['_ID'] ?? '-')),
      'Inmueble' => $this->maintenance_order_first([$order['inmueble'] ?? '', $order['id_inmueble'] ?? '', $cotizacion['inmueble'] ?? '', $cotizacion['id_inmueble'] ?? '']),
      'Proveedor' => trim((string) ($order['proveedor'] ?? '-')),
      'Categoria' => $category !== '' ? $category : '-',
      'Valor' => $this->format_cop_currency($order['valor'] ?? 0),
      'Creada por' => trim((string) ($user['nombre'] ?? '-')),
    ], $orderId);

    $emailQueued = 0;
    if ($emailRecipients !== []) {
      $emailQueued = (new \SCM\Support\EmailQueue($this->db))->enqueue(array_column($emailRecipients, 'email'), $subject, $html, [
        'source_module' => 'ordenes_mantenimiento',
        'dedupe_key' => 'orden-mantenimiento-creada:' . $orderId,
        'meta' => [
          'event' => 'orden_mantenimiento_creada',
          'id_orden' => $orderId,
          'id_cotizacion' => trim((string) ($order['id_cotizacion'] ?? $cotizacion['_ID'] ?? '')),
          'id_ticket' => $ticket,
          'categoria' => $category,
          'actor' => trim((string) ($user['nombre'] ?? '')),
        ],
      ]);
    }

    $whatsappQueued = $this->maintenance_order_enqueue_created_whatsapp(
      $whatsappRecipients,
      $order,
      $cotizacion,
      $user,
      $orderId,
      $orderUrl,
      $category,
      $ticket
    );

    return $emailQueued + $whatsappQueued;
  }

  /** @param array<int,array{name:string,phone?:string,id?:string,email?:string}> $recipients @param array<string,mixed> $order @param array<string,mixed> $cotizacion @param array<string,string> $user */
  private function maintenance_order_enqueue_created_whatsapp(array $recipients, array $order, array $cotizacion, array $user, int $orderId, string $orderUrl, string $category, string $ticket): int
  {
    $buttonSuffix = $this->maintenance_quote_whatsapp_url_button_suffix($orderUrl);
    $smsQueue = new \SCM\Support\SmsQueue($this->db);
    $queued = 0;
    $sentPhones = [];
    $total = $this->format_cop_currency($order['valor'] ?? 0);
    $provider = $this->maintenance_order_first([$order['proveedor'] ?? '', '-']);
    $direction = $this->maintenance_order_first([$order['direccion'] ?? '', $cotizacion['direccion'] ?? '', '-']);
    $actor = $this->maintenance_order_first([$user['nombre'] ?? '', 'SKC SuCasa Inmobiliaria']);

    foreach ($recipients as $recipient) {
      $phone = trim((string) ($recipient['phone'] ?? ''));
      $phoneKey = preg_replace('/\D+/', '', $phone) ?? '';
      if ($phoneKey === '' || isset($sentPhones[$phoneKey])) {
        continue;
      }
      $sentPhones[$phoneKey] = true;
      $name = trim((string) ($recipient['name'] ?? 'Funcionario'));
      $message = "Buen día, {$name}.\n\n";
      $message .= "Tienes una orden de mantenimiento #{$orderId} pendiente por aprobar" . ($ticket !== '' ? " del caso #{$ticket}" : '') . " por {$total}.\n\n";
      $message .= "Categoría: " . ($category !== '' ? $category : '-') . ".\n";
      $message .= "Proveedor: {$provider}.\n";
      $message .= "Dirección: {$direction}.\n\n";
      $message .= "Puedes revisar y responder la orden desde el botón.\n\n";
      $message .= "Enlace directo: {$orderUrl}\n\n";
      $message .= "Atentamente,\n{$actor}\nSKC SuCasa Inmobiliaria";
      $ok = $smsQueue->enqueue($phone, $name, $message, [
        'source_module' => 'ordenes_mantenimiento',
        'campaign_tag' => 'ordenes_mantenimiento',
        'categoria_mensaje' => 'informacion',
        'id_orden' => (string) $orderId,
        'id_cotizacion' => trim((string) ($order['id_cotizacion'] ?? $cotizacion['_ID'] ?? '')),
        'id_ticket' => $ticket,
        'categoria' => $category,
        'order_url' => $orderUrl,
        'button_url_mode' => 'dynamic_suffix',
        'dedupe_key' => 'orden-mantenimiento-creada-whatsapp:' . $orderId,
        'template_name' => 'scm_orden_mantenimiento_funcionario_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($name)],
              ['type' => 'text', 'text' => (string) $orderId],
              ['type' => 'text', 'text' => $ticket !== '' ? $ticket : '-'],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($total)],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($category !== '' ? $category : '-')],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($provider)],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($direction)],
              ['type' => 'text', 'text' => $this->maintenance_quote_whatsapp_text($actor)],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
              ['type' => 'text', 'text' => $buttonSuffix],
            ],
          ],
        ],
      ]);
      if ($ok) {
        $queued++;
      }
    }

    return $queued;
  }

  /** @param array<string,mixed> $order @param array<string,string> $user */
  private function maintenance_order_enqueue_response_notifications(array $order, string $estado, string $observacion, array $user, int $orderId): int
  {
    $recipients = $this->maintenance_order_internal_email_recipients('respuesta_orden_mantenimiento');
    $creatorEmail = trim((string) ($order['email_creador'] ?? ''));
    if ($creatorEmail !== '' && filter_var($creatorEmail, FILTER_VALIDATE_EMAIL)) {
      $recipients[] = [
        'name' => trim((string) ($order['creador'] ?? '')),
        'email' => $creatorEmail,
      ];
    }
    $recipients = $this->maintenance_order_unique_email_recipients($recipients);
    if ($recipients === []) {
      return 0;
    }

    $emails = array_column($recipients, 'email');
    $category = trim((string) ($order['categoria'] ?? 'mantenimiento'));
    $ticket = trim((string) ($order['id_ticket'] ?? ''));
    $subject = 'Orden de ' . strtolower($category) . ($ticket !== '' ? ' del caso #' . $ticket : '') . ' ' . strtolower($estado);
    $html = $this->maintenance_order_email_html('Respuesta de orden de mantenimiento', [
      'Orden' => '#' . $orderId,
      'Respuesta' => $estado,
      'Caso' => $ticket !== '' ? '#' . $ticket : '-',
      'Cotizacion' => '#' . trim((string) ($order['id_cotizacion'] ?? '-')),
      'Proveedor' => trim((string) ($order['proveedor'] ?? '-')),
      'Categoria' => $category !== '' ? $category : '-',
      'Valor' => $this->format_cop_currency($order['valor'] ?? 0),
      'Respondida por' => trim((string) ($user['nombre'] ?? '-')),
      'Observacion' => $observacion !== '' ? $observacion : '-',
    ], $orderId);

    return (new \SCM\Support\EmailQueue($this->db))->enqueue($emails, $subject, $html, [
      'source_module' => 'ordenes_mantenimiento',
      'dedupe_key' => 'orden-mantenimiento-respuesta:' . $orderId . ':' . strtolower($estado),
      'meta' => [
        'event' => 'respuesta_orden_mantenimiento',
        'id_orden' => $orderId,
        'id_cotizacion' => trim((string) ($order['id_cotizacion'] ?? '')),
        'id_ticket' => $ticket,
        'categoria' => $category,
        'estado' => $estado,
        'actor' => trim((string) ($user['nombre'] ?? '')),
      ],
    ]);
  }

  /** @param array<string,mixed> $row @param array<int,array<string,mixed>> $orders */
  private function build_cotizacion_mantenimiento_pdf(array $row, array $orders, string $audience = 'funcionario'): \SCM\Support\SimplePdf
  {
    $audience = strtolower(trim($audience)) === 'destinatario' ? 'destinatario' : 'funcionario';
    $isFuncionario = $audience === 'funcionario';
    $pdf = new \SCM\Support\SimplePdf();
    $letterhead = dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg';
    $pdf->backgroundImage($letterhead);
    $pdf->layout(58, 166, 116);

    $id = trim((string) ($row['_ID'] ?? ''));
    $ticket = trim((string) ($row['id_ticket'] ?? ''));
    $tipo = trim((string) ($row['tipo_mantenimiento'] ?? 'Mantenimiento'));
    $estado = trim((string) ($row['estado'] ?? ''));
    $seEnvio = strtolower(trim((string) ($row['se_envio'] ?? '')));
    $enviada = in_array($seEnvio, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
    $fecha = $this->cotizacion_date_label($row['fecha'] ?? $row['cct_created'] ?? '');
    $fechaEnvio = $this->cotizacion_date_label($row['fecha_envio'] ?? '');
    $destinatario = $this->cotizacion_clean_text($row['destinatario'] ?? '-');
    $direccion = $this->cotizacion_clean_text($row['direccion'] ?? '-');
    $responsableCotizacion = $this->cotizacion_responsable_contact($row);
    $creador = $this->cotizacion_clean_text($row['creador'] ?? '');
    $creadorEmail = $this->cotizacion_clean_text($row['email_creador'] ?? '');
    $creadorCelular = $this->cotizacion_clean_text($row['celular_creador'] ?? '');
    $porcentajeAdmon = $this->cotizacion_clean_text($row['porcentaje_admon'] ?? '0');
    $porcentajeIva = $this->cotizacion_clean_text($row['iva'] ?? '0');

    $pdf->title('Cotización de mantenimiento #' . ($id !== '' ? $id : '-'));
    $pdf->line($isFuncionario ? 'VERSIÓN INTERNA PARA FUNCIONARIO' : 'COPIA PARA DESTINATARIO', 9, 'F2');
    $pdf->line('Estado: ' . ($estado !== '' ? $this->cotizacion_clean_text($estado) : 'Sin estado') . ' | Envío: ' . ($enviada ? 'Fue enviada' : 'Sin enviar'), 8, 'F2');
    $pdf->line('Fecha: ' . $fecha . ' | Fecha de envío: ' . $fechaEnvio, 8);
    $pdf->spacer(5);

    $pdf->heading('Datos generales');
    $pdf->table(['Campo', 'Información'], [
      ['Ticket / contrato', ($ticket !== '' ? '#' . $ticket : '-') . ' / ' . trim((string) ($row['contrato'] ?? '-'))],
      ['Inmueble SIMI', trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '-'))],
      ['Destinatario', $destinatario !== '' ? $destinatario : '-'],
      ['Contacto', $this->cotizacion_clean_text($row['celular_destinatario'] ?? '-')],
      ['Dirección', $direccion !== '' ? $direccion : '-'],
      ['Tipo de mantenimiento', $tipo !== '' ? $this->cotizacion_clean_text($tipo) : 'Mantenimiento'],
      ['Validez / duración', $this->cotizacion_days_label($row['valides_oferta'] ?? '') . ' / ' . $this->cotizacion_days_label($row['duracion'] ?? '')],
    ], [0.30, 0.70], 8);

    $administrationLabel = $isFuncionario && $porcentajeAdmon !== ''
      ? 'Administración (' . $porcentajeAdmon . '%)'
      : 'Administración';
    $ivaLabel = $isFuncionario && $porcentajeIva !== ''
      ? 'IVA sobre administración (' . $porcentajeIva . '%)'
      : 'IVA sobre administración';
    $economicRows = [
      ['Materiales', $this->format_cop_currency($row['total_materiales'] ?? 0)],
      ['Mano de obra', $this->format_cop_currency($row['total_mano_obra'] ?? 0)],
      ['Equipos / maquinarias', $this->format_cop_currency($row['total_maquinarias'] ?? 0)],
      ['Otros costos', $this->format_cop_currency($row['total_otros_costos'] ?? 0)],
      [$administrationLabel, $this->format_cop_currency($row['total_admon'] ?? 0)],
      [$ivaLabel, $this->format_cop_currency($row['iva_admon'] ?? 0)],
      ['TOTAL COTIZACIÓN', $this->format_cop_currency($row['total'] ?? 0)],
    ];

    $pdf->heading('Presupuesto detallado');
    foreach ([
      'Materiales' => ['items' => $this->cotizacion_parse_list($row['items_materiales'] ?? ''), 'total' => $row['total_materiales'] ?? 0],
      'Mano de obra' => ['items' => $this->cotizacion_parse_list($row['items_mano'] ?? ''), 'total' => $row['total_mano_obra'] ?? 0],
      'Equipos / maquinarias' => ['items' => $this->cotizacion_parse_list($row['items_otros_equi'] ?? ''), 'total' => $row['total_maquinarias'] ?? 0],
      'Otros costos' => ['items' => $this->cotizacion_parse_list($row['items_otros_costos'] ?? ''), 'total' => $row['total_otros_costos'] ?? 0],
    ] as $section => $data) {
      $items = is_array($data['items'] ?? null) ? $data['items'] : [];
      $budgetRows = [];
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $label = $this->cotizacion_first_matching_value($item, ['prove', 'descipcion', 'descripcion', 'actividad', 'concepto', 'detalle']);
        $value = method_exists($this, 'cotizacion_budget_item_total')
          ? $this->cotizacion_budget_item_total($item)
          : $this->cotizacion_first_matching_value($item, ['valor', 'total', 'saldo']);
        $budgetRows[] = [
          $label !== '' ? $this->cotizacion_clean_text($label) : 'Ítem',
          $this->format_cop_currency($value),
        ];
      }
      if (empty($budgetRows)) {
        $budgetRows[] = ['Sin ítems registrados', '$0'];
      }
      $budgetRows[] = ['TOTAL ' . strtoupper((string) $section), $this->format_cop_currency($data['total'] ?? 0)];
      $pdf->line(strtoupper((string) $section), 9, 'F2');
      $pdf->table(['Descripción / proveedor', 'Valor'], $budgetRows, [0.74, 0.26], 8, [1]);
    }

    if ($isFuncionario) {
      $pdf->heading('Control de saldos');
      $pdf->table(['Categoría', 'Presupuesto', 'Saldo'], [
        ['Materiales', $this->format_cop_currency($row['total_materiales'] ?? 0), $this->format_cop_currency($row['saldo_materiales'] ?? 0)],
        ['Mano de obra', $this->format_cop_currency($row['total_mano_obra'] ?? 0), $this->format_cop_currency($row['saldo_obra'] ?? 0)],
        ['Equipos / maquinarias', $this->format_cop_currency($row['total_maquinarias'] ?? 0), $this->format_cop_currency($row['saldo_maquinarias'] ?? 0)],
        ['Otros costos', $this->format_cop_currency($row['total_otros_costos'] ?? 0), $this->format_cop_currency($row['saldo_otros_costo'] ?? 0)],
        ['TOTAL', $this->format_cop_currency(
          $this->cotizacion_money_value($row, ['total_materiales'])
            + $this->cotizacion_money_value($row, ['total_mano_obra'])
            + $this->cotizacion_money_value($row, ['total_maquinarias'])
            + $this->cotizacion_money_value($row, ['total_otros_costos'])
        ), $this->format_cop_currency(
          $this->cotizacion_money_value($row, ['saldo_materiales'])
            + $this->cotizacion_money_value($row, ['saldo_obra'])
            + $this->cotizacion_money_value($row, ['saldo_maquinarias'])
            + $this->cotizacion_money_value($row, ['saldo_otros_costo'])
        )],
      ], [0.46, 0.27, 0.27], 8, [1, 2]);
    }

    $revision = $this->cotizacion_revision_row($row);
    $danos = $this->cotizacion_parse_list($revision['evaluacion_de_danos'] ?? '');
    $pdf->heading('Informe de daños');
    if (empty($danos)) {
      $pdf->callout('Estado del informe', 'Sin daños registrados para esta cotización.', 8);
    } else {
      foreach ($danos as $damageIndex => $damage) {
        if (!is_array($damage)) {
          continue;
        }
        $summary = $this->cotizacion_clean_text($damage['descripcion_dano'] ?? '');
        $consequence = $this->cotizacion_clean_text($damage['consecuencia'] ?? '');
        $responsableDano = $this->cotizacion_clean_text($damage['a_quien_corresponde'] ?? '-');
        $nivel = $this->cotizacion_clean_text($damage['nivel_dano'] ?? '-');
        $areas = array_values(array_filter(array_map(fn(string $key): string => $this->cotizacion_clean_text($damage[$key] ?? ''), [
          'area_afectada_1',
          'area_afectada_2',
          'area_afectada_3',
          'area_afectada_4',
        ])));
        $pdf->line('DAÑO EVALUADO #' . (string) ($damageIndex + 1), 10, 'F2');
        $pdf->table(['Campo', 'Detalle'], [
          ['Índice', $this->cotizacion_clean_text($damage['indice'] ?? '-')],
          ['Responsable', $responsableDano !== '' ? $responsableDano : '-'],
          ['Nivel', $nivel !== '' ? $nivel : '-'],
          ['Tiempo de atención', $this->cotizacion_clean_text($damage['tiempo_atencion'] ?? '-')],
          ['Áreas afectadas', !empty($areas) ? implode(', ', $areas) : '-'],
        ], [0.32, 0.68], 8);
        $pdf->callout('Descripción del daño', $summary !== '' ? $summary : 'Sin descripción registrada.', 8);
        $pdf->callout('Consecuencia', $consequence !== '' ? $consequence : 'Sin consecuencia registrada.', 8);
        $damageMedia = $this->cotizacion_media_items($this->cotizacion_split_media_refs($damage['registro_foto_dano'] ?? ''));
        foreach ($damageMedia as $mediaIndex => $media) {
          $url = trim((string) ($media['url'] ?? ''));
          if ($url !== '') {
            $pdf->linkText('Evidencia fotográfica ' . (string) ($mediaIndex + 1), $url);
          }
        }
      }
    }

    $mejorOferta = $this->cotizacion_media_items($this->cotizacion_split_media_refs($row['mejor_oferta'] ?? ''));
    $otrasOfertas = $this->cotizacion_media_items($this->cotizacion_split_media_refs($row['otras_oferta'] ?? ''));
    if (!empty($mejorOferta) || !empty($otrasOfertas)) {
      $pdf->heading('Soportes');
      foreach (array_merge($mejorOferta, $otrasOfertas) as $media) {
        $url = trim((string) ($media['url'] ?? ''));
        if ($url !== '') {
          $pdf->linkText(trim((string) ($media['title'] ?? 'Documento')), $url);
        }
      }
    }

    $resumenPerturbacion = $this->cotizacion_parse_json($row['resumen_calculo_perturbacion'] ?? '');
    $pdf->heading('Observaciones y perturbación');
    $pdf->table(['Concepto', 'Resultado'], [
      ['Perturbación sugerida', trim((string) ($row['perturbacion'] ?? '0')) . '%'],
      ['Bonificación sugerida', $this->format_cop_currency($row['valor_bonificacion'] ?? 0)],
      ['Días calculados', (string) ($row['dias_afectacion_calculados'] ?? '-')],
      ['Área afectada', trim((string) ($row['area_afectada'] ?? '')) !== '' ? trim((string) $row['area_afectada']) . ' m2' : '-'],
      ['Tipo de inmueble', $this->cotizacion_clean_text($row['tipo_inmueble'] ?? '-')],
      ['Destinación', $this->cotizacion_clean_text($row['destinacion'] ?? '-')],
    ], [0.42, 0.58], 8);
    $observaciones = $this->cotizacion_clean_text($row['observaciones'] ?? '');
    if ($observaciones !== '') {
      $pdf->callout('Observaciones', $observaciones, 8);
    }
    if (!empty($resumenPerturbacion['criterios']) && is_array($resumenPerturbacion['criterios'])) {
      $criteria = array_values(array_filter(array_map(fn($criterion): string => $this->cotizacion_clean_text($criterion), $resumenPerturbacion['criterios'])));
      if (!empty($criteria)) {
        $pdf->bullets($criteria, 8);
      }
    }
    $justificacion = $this->cotizacion_clean_text($row['justificacion_perturbacion'] ?? '');
    if ($justificacion !== '') {
      $pdf->callout('Justificación', $justificacion, 8);
    }

    $pdf->heading('Órdenes de mantenimiento');
    if (empty($orders)) {
      $pdf->callout('Órdenes', 'Sin órdenes registradas para esta cotización.', 8);
    } else {
      $orderRows = [];
      foreach ($orders as $order) {
        $orderRows[] = [
          '#' . trim((string) ($order['_ID'] ?? '-')),
          $this->cotizacion_clean_text($order['proveedor'] ?? '-'),
          $this->cotizacion_clean_text($order['actividad'] ?? '-'),
          $this->format_cop_currency($order['valor'] ?? 0),
          $this->cotizacion_clean_text($order['estado'] ?? '-'),
        ];
      }
      $pdf->table(['Orden', 'Proveedor', 'Actividad', 'Valor', 'Estado'], $orderRows, [0.11, 0.21, 0.36, 0.17, 0.15], 7, [3]);
    }

    $pdf->heading('Resumen económico');
    $pdf->table(['Concepto', 'Valor'], $economicRows, [0.68, 0.32], 8, [1]);

    $pdf->heading('Nota contractual');
    $pdf->paragraph('Cuando las reparaciones sean responsabilidad de los propietarios, el administrador informará la novedad. Si no se atiende dentro del plazo contractual, la administración podrá realizar la gestión y descontar el valor correspondiente del canon de arrendamiento, de acuerdo con el contrato de mandato vigente.', 8);
    $pdf->spacer(8);
    $pdf->signatureBlock('Responsable de cotización', $responsableCotizacion['nombre'] !== '' ? $responsableCotizacion['nombre'] : 'Control Servicios Inmobiliarios', trim(($responsableCotizacion['cargo'] !== '' ? $responsableCotizacion['cargo'] : 'Responsable de cotización') . ' | Email: ' . ($responsableCotizacion['email'] !== '' ? $responsableCotizacion['email'] : '-') . ' | Cel. ' . ($responsableCotizacion['celular'] !== '' ? $responsableCotizacion['celular'] : '-'), ' |'));
    $pdf->signatureBlock('Elaboró la cotización', $creador !== '' ? $creador : 'Control Servicios Inmobiliarios', trim('Email: ' . ($creadorEmail !== '' ? $creadorEmail : '-') . ' | Cel. ' . ($creadorCelular !== '' ? $creadorCelular : '-'), ' |'));
    $pdf->signatureBlock('Empresa', 'SKC SuCasa Inmobiliaria', 'NIT 900623242-4 | Cartagena de Indias - Colombia');

    return $pdf;
  }


  public function ajax_handler_damage_magnitude(): void
  {
    $this->verifyCsrf();

    $clean = static function ($v): string {
      return sanitize_text_field((string)($v ?? ''));
    };

    $filters = [
      'q'        => $clean($_POST['q'] ?? ''),
      'ticket'   => $clean($_POST['ticket'] ?? ''),
      'contrato' => $clean($_POST['contrato'] ?? ''),
      'inmueble' => $clean($_POST['inmueble'] ?? ''),
      'cotizacion' => $clean($_POST['cotizacion'] ?? ''),
      'estado'   => $clean($_POST['estado'] ?? ''),
      'sucursal' => $clean($_POST['sucursal'] ?? ''),
      'empleado' => $clean($_POST['empleado'] ?? ''),
      'magnitud' => $clean($_POST['magnitud'] ?? ''),
      'revision_type' => $clean($_POST['revision_type'] ?? 'correctiva'),
      'limit'    => max(1, min(500, (int)($_POST['limit'] ?? 150))),
      'offset'   => max(0, (int)($_POST['offset'] ?? 0)),
    ];

    $service = new \SCM\Modules\ServiciosInmobiliarios\DamageMagnitudeService($this->db->pdo(), $this->db->prefix());
    $this->jsonOk($service->getTicketsWithDamageMagnitude($filters));
  }

  public function ajax_handler_classify_magnitude(): void
  {
    $this->verifyCsrf();

    $type = strtolower(trim(sanitize_text_field((string)($_POST['revision_type'] ?? 'correctiva'))));
    if (!in_array($type, ['correctiva', 'preventiva'], true)) {
      $type = 'correctiva';
    }

    $table = $this->db->table('jet_cct_tickets');
    if (!$this->ensure_magnitud_caso_column($table)) {
      $this->jsonFail('No se pudo preparar la columna magnitud_caso en tickets.');
    }

    $service = new \SCM\Modules\ServiciosInmobiliarios\DamageMagnitudeService($this->db->pdo(), $this->db->prefix());
    $updated = 0;
    $total = 0;
    $summary = ['critico' => 0, 'alto' => 0, 'medio' => 0, 'bajo' => 0];

    for ($offset = 0; $offset < 5000; $offset += 500) {
      $data = $service->getTicketsWithDamageMagnitude([
        'revision_type' => $type,
        'limit' => 500,
        'offset' => $offset,
      ]);
      $tickets = is_array($data['tickets'] ?? null) ? $data['tickets'] : [];
      if (empty($tickets)) {
        break;
      }
      foreach ($tickets as $ticket) {
        $ticketPk = (int)($ticket['ticket_row_id'] ?? 0);
        $key = strtolower(trim((string)($ticket['magnitud']['key'] ?? '')));
        if ($ticketPk <= 0 || !isset($summary[$key])) {
          continue;
        }
        $total++;
        $summary[$key]++;
        $updated += $this->db->update($table, ['magnitud_caso' => $key], ['_ID' => $ticketPk]) > 0 ? 1 : 0;
      }
      if (count($tickets) < 500) {
        break;
      }
    }

    $this->jsonOk([
      'message' => 'Magnitud del dano actualizada.',
      'revision_type' => $type,
      'total' => (string)$total,
      'updated' => (string)$updated,
      'summary' => $summary,
    ]);
  }

  private function ensure_magnitud_caso_column(string $table): bool
  {
    if ($this->column_exists($table, 'magnitud_caso')) {
      return true;
    }

    try {
      $this->db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `magnitud_caso` VARCHAR(20) NULL DEFAULT ''");
      $this->columnExistsCache[$table . '::magnitud_caso'] = true;
      return true;
    } catch (\Throwable $e) {
      unset($this->columnExistsCache[$table . '::magnitud_caso']);
      return $this->column_exists($table, 'magnitud_caso');
    }
  }

  public function ajax_handler_save_case_magnitude(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('case_edit_magnitude')) {
      $this->jsonFail('No tienes permiso para editar la magnitud del caso.');
    }

    $ticketPk = (int)($_POST['ticket_pk'] ?? 0);
    $magnitud = strtolower(trim(sanitize_text_field((string)($_POST['magnitud'] ?? ''))));
    $allowed = ['critico', 'alto', 'medio', 'bajo'];

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if (!in_array($magnitud, $allowed, true)) {
      $this->jsonFail('Magnitud no valida.');
    }

    $table = $this->db->table('jet_cct_tickets');
    if (!$this->column_exists($table, 'magnitud_caso')) {
      $this->jsonFail('No existe la columna magnitud_caso en tickets.');
    }

    $this->db->update($table, ['magnitud_caso' => $magnitud], ['_ID' => $ticketPk]);

    $this->jsonOk([
      'message' => 'Magnitud del caso guardada.',
      'ticket_pk' => (string)$ticketPk,
      'magnitud' => $magnitud,
      'label' => ucfirst($magnitud),
    ]);
  }

  public function ajax_handler_save_property_location(): void
  {
    $this->verifyCsrf();

    $ticketPk = (int) ($_POST['ticket_pk'] ?? 0);
    $propertyRowId = trim(sanitize_text_field(wp_unslash((string) ($_POST['property_row_id'] ?? ''))));
    $propertyCode = trim(sanitize_text_field(wp_unslash((string) ($_POST['property_code'] ?? ''))));
    $mapsLocationRaw = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['manual_location'] ?? ''))));
    $mapsLocation = $this->normalizePropertyLocationInput($mapsLocationRaw);

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($propertyRowId === '' && $propertyCode === '') {
      $this->jsonFail('No se encontro el inmueble asociado al caso.');
    }

    $propertyTable = $this->db->table('jet_cct_inmuebles');
    if (!$this->table_exists($propertyTable)) {
      $this->jsonFail('La tabla de inmuebles no esta disponible.');
    }
    if (!$this->column_exists($propertyTable, 'ubicacion_google_maps')) {
      $this->jsonFail('La columna ubicacion_google_maps no existe en inmuebles.');
    }

    $property = null;
    if ($propertyCode !== '' && $this->column_exists($propertyTable, 'codigo')) {
      $property = $this->db->getRow(
        "SELECT * FROM `{$propertyTable}` WHERE TRIM(COALESCE(`codigo`, '')) = ? LIMIT 1",
        [$propertyCode]
      );
    }
    if (!is_array($property) && $propertyRowId !== '' && $this->column_exists($propertyTable, '_ID')) {
      $property = $this->db->getRow(
        "SELECT * FROM `{$propertyTable}` WHERE TRIM(COALESCE(`_ID`, '')) = ? LIMIT 1",
        [$propertyRowId]
      );
    }
    if (!is_array($property)) {
      $this->jsonFail('Inmueble no encontrado.');
    }

    $propertyPk = (int) ($property['_ID'] ?? 0);
    if ($propertyPk <= 0) {
      $this->jsonFail('No se pudo resolver el ID interno del inmueble.');
    }

    $update = ['ubicacion_google_maps' => $mapsLocation];
    if ($this->column_exists($propertyTable, 'cct_modified')) {
      $update['cct_modified'] = date('Y-m-d H:i:s');
    }

    $this->db->update($propertyTable, $update, ['_ID' => $propertyPk]);
    if ($mapsLocation !== '') {
      $this->maybeInsertPropertyLocationHistory($property, trim((string) ($property['codigo'] ?? $propertyCode)), $mapsLocation);
    }

    $this->jsonOk([
      'message' => $mapsLocation !== ''
        ? 'Ubicacion del inmueble guardada en Google Maps.'
        : 'Ubicacion del inmueble eliminada.',
      'ticket_pk' => (string) $ticketPk,
      'property_row_id' => (string) $propertyPk,
      'property_code' => trim((string) ($property['codigo'] ?? $propertyCode)),
      'manual_location' => $mapsLocation,
    ]);
  }

  public function ajax_handler_trasladar_caso(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('case_transfer')) {
      $this->jsonFail('No tienes permiso para trasladar casos.');
    }

    $ticketPk    = (int) ($_POST['ticket_pk'] ?? 0);
    $newEmpId    = trim(sanitize_text_field(wp_unslash((string) ($_POST['new_empleado_id'] ?? ''))));
    $notifyTargets = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    $notifyOldEmp  = false;
    $notifyNewEmp  = !empty($_POST['notify_funcionario']) || !empty($_POST['notify_nuevo']);

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($newEmpId === '') {
      $this->jsonFail('Debe seleccionar un funcionario.');
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    $funcTable    = $this->db->table('jet_cct_funcionarios');

    $ticket = $this->db->getRow(
      "SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($ticket)) {
      $this->jsonFail('Ticket no encontrado.');
    }

    $newEmp = $this->db->getRow(
      "SELECT * FROM `{$funcTable}` WHERE TRIM(COALESCE(`id_empleado`,'')) = ? LIMIT 1",
      [$newEmpId]
    );
    if (!is_array($newEmp)) {
      $this->jsonFail('Funcionario no encontrado.');
    }

    $oldEmpNombre = trim((string) ($ticket['empleado'] ?? $ticket['nombre_empleado'] ?? ''));
    $oldEmpCorreo = trim((string) ($ticket['correo_empleado'] ?? ''));

    $newNombre  = trim((string) ($newEmp['nombre'] ?? ''));
    $newCorreo  = trim((string) ($newEmp['correo'] ?? ''));
    $newCelular = '';
    foreach (['celular', 'celular_empleado', 'telefono', 'whatsapp', 'phone'] as $phoneColumn) {
      $candidatePhone = trim((string) ($newEmp[$phoneColumn] ?? ''));
      if ($candidatePhone !== '') {
        $newCelular = $candidatePhone;
        break;
      }
    }

    $nowTs    = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $update = $schema->filterTableData($ticketsTable, [
      'id_empleado'         => $newEmpId,
      'empleado'            => $newNombre,
      'nombre_empleado'     => $newNombre,
      'correo_empleado'     => $newCorreo,
      'celular_empleado'    => $newCelular,
      'estado_administrativo' => 'Trasladado',
      'fecha_actualizacion' => $nowTs,
      'cct_modified'        => $nowMysql,
    ]);

    if (empty($update)) {
      $this->jsonFail('No se pudo actualizar el ticket.');
    }

    $this->db->update($ticketsTable, $update, ['_ID' => $ticketPk]);

    $userName = \SCM\Core\Auth::user();
    if ($userName === '') {
      $userId   = \SCM\Core\Auth::userId();
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }

    $service = $this->get_seguimiento_service();

    // Registrar en el historial del ticket
    $histObservacion = 'Caso trasladado de "' . ($oldEmpNombre ?: 'sin asignar') . '" a "' . ($newNombre ?: $newEmpId) . '" por ' . $userName . '.';
    $service->addSeguimientoEntry($ticket, $ticketPk, $histObservacion);

    $emailsSent = $service->notifyTrasladoCaso(
      $ticket,
      $newNombre,
      $newCorreo,
      $oldEmpNombre,
      $oldEmpCorreo,
      $userName,
      $notifyTargets,
      $notifyOldEmp,
      $notifyNewEmp,
      $newCelular,
      $newEmpId
    );

    $this->jsonOk([
      'message'      => 'Caso trasladado correctamente a ' . ($newNombre ?: $newEmpId) . '.',
      'emails_sent'  => (string) $emailsSent,
      'nuevo_empleado' => $newNombre,
    ]);
  }

  // ══════════════════════════════════════════════════════════════════════
  // GUÍA – Correspondencias de Daños  (tabla: jet_cct_correspondencias)
  // ══════════════════════════════════════════════════════════════════════
}
