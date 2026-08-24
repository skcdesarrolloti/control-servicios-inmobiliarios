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
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::DEFAULT_CORRECTIVA_URL),
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
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::DEFAULT_CORRECTIVA_URL),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
      'acta_url'       => self::sanitizeUrl($rawConfig['acta_url']       ?? self::DEFAULT_ACTA_URL),
    ];

    $module = $this->get_servicios_inmobiliarios_module();
    $params = $module->parseParams($_POST, 'scm_my_');
    $employeeId = $this->current_employee_id();
    $params['fEmpleado'] = $employeeId !== '' ? $employeeId : '__sin_funcionario__';
    $params['_scmEmpleadoExact'] = '1';
    $result = $module->run($params, $config);
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];

    $this->jsonOk([
      'cards' => (string) ($result['tbody'] ?? ''),
      'pagination' => (string) ($result['pagination_html'] ?? ''),
      'count' => (string) ($stats['total'] ?? 0),
      'kpi_total' => (string) ($stats['total'] ?? 0),
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
      'kpi_enviadas' => (string) ($stats['enviadas'] ?? 0),
      'kpi_no_enviadas' => (string) ($stats['no_enviadas'] ?? 0),
      'kpi_aprobadas' => (string) ($stats['aprobadas'] ?? 0),
      'kpi_desaprobadas' => (string) ($stats['desaprobadas'] ?? 0),
      'kpi_esperando_respuesta' => (string) ($stats['esperando_respuesta'] ?? 0),
      'kpi_finalizadas' => (string) ($stats['finalizadas'] ?? 0),
      'kpi_sin_estado' => (string) ($stats['sin_estado'] ?? 0),
      'kpi_ordenes_total' => (string) ($stats['ordenes_total'] ?? 0),
      'kpi_valor_total' => $this->format_cop_currency($stats['valor_total'] ?? 0),
      'kpi_tab_total' => (string) ($tabStats['total'] ?? 0),
      'kpi_tab_enviadas' => (string) ($tabStats['enviadas'] ?? 0),
      'kpi_tab_no_enviadas' => (string) ($tabStats['no_enviadas'] ?? 0),
      'kpi_tab_aprobadas' => (string) ($tabStats['aprobadas'] ?? 0),
      'kpi_tab_desaprobadas' => (string) ($tabStats['desaprobadas'] ?? 0),
      'kpi_tab_esperando_respuesta' => (string) ($tabStats['esperando_respuesta'] ?? 0),
      'kpi_tab_finalizadas' => (string) ($tabStats['finalizadas'] ?? 0),
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
    $pdf = $this->build_cotizacion_mantenimiento_pdf($row, $orders);

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
    header('Content-Disposition: attachment; filename="cotizacion-mantenimiento-' . $cotizacionId . '.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    @unlink($path);
    exit;
  }

  /** @param array<string,mixed> $row @param array<int,array<string,mixed>> $orders */
  private function build_cotizacion_mantenimiento_pdf(array $row, array $orders): \SCM\Support\SimplePdf
  {
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
    $destinatario = trim((string) ($row['destinatario'] ?? '-'));
    $direccion = trim((string) ($row['direccion'] ?? '-'));
    $responsable = trim((string) ($row['coordinador'] ?? $row['creador'] ?? 'Control Servicios Inmobiliarios'));

    $pdf->title('Cotizacion de mantenimiento #' . ($id !== '' ? $id : '-'));
    $pdf->line('Estado: ' . ($estado !== '' ? $estado : 'Sin estado') . ' | Envio: ' . ($enviada ? 'Fue enviada' : 'Sin enviar'), 8, 'F2');
    $pdf->line('Fecha: ' . $fecha . ' | Fecha de envio: ' . $fechaEnvio, 8);
    $pdf->spacer(5);

    $pdf->heading('Datos generales');
    $pdf->line('Ticket: ' . ($ticket !== '' ? '#' . $ticket : '-') . ' | Contrato: ' . trim((string) ($row['contrato'] ?? '-')) . ' | Inmueble SIMI: ' . trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '-')), 8, 'F2');
    $pdf->line('Destinatario: ' . ($destinatario !== '' ? $destinatario : '-') . ' | Contacto: ' . trim((string) ($row['celular_destinatario'] ?? '-')), 8);
    $pdf->paragraph('Direccion: ' . ($direccion !== '' ? $direccion : '-'), 8);
    $pdf->line('Tipo de mantenimiento: ' . ($tipo !== '' ? $tipo : 'Mantenimiento'), 8);

    $pdf->heading('Resumen economico');
    $pdf->line('Materiales: ' . $this->format_cop_currency($row['total_materiales'] ?? 0) . ' | Mano de obra: ' . $this->format_cop_currency($row['total_mano_obra'] ?? 0) . ' | Equipos: ' . $this->format_cop_currency($row['total_maquinarias'] ?? 0), 8, 'F2');
    $pdf->line('Otros costos: ' . $this->format_cop_currency($row['total_otros_costos'] ?? 0) . ' | Administracion: ' . $this->format_cop_currency($row['total_admon'] ?? 0) . ' | IVA admon: ' . $this->format_cop_currency($row['iva_admon'] ?? 0), 8);
    $pdf->line('TOTAL COTIZACION: ' . $this->format_cop_currency($row['total'] ?? 0), 12, 'F2');

    foreach ([
      'Materiales' => ['items' => $this->cotizacion_parse_list($row['items_materiales'] ?? ''), 'total' => $row['total_materiales'] ?? 0],
      'Mano de obra' => ['items' => $this->cotizacion_parse_list($row['items_mano'] ?? ''), 'total' => $row['total_mano_obra'] ?? 0],
      'Equipos / maquinarias' => ['items' => $this->cotizacion_parse_list($row['items_otros_equi'] ?? ''), 'total' => $row['total_maquinarias'] ?? 0],
      'Otros costos' => ['items' => $this->cotizacion_parse_list($row['items_otros_costos'] ?? ''), 'total' => $row['total_otros_costos'] ?? 0],
    ] as $section => $data) {
      $items = is_array($data['items'] ?? null) ? $data['items'] : [];
      if (empty($items)) {
        continue;
      }
      $pdf->heading((string) $section);
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $label = $this->cotizacion_first_matching_value($item, ['prove', 'descripcion', 'actividad', 'concepto', 'detalle']);
        $value = $this->cotizacion_first_matching_value($item, ['valor', 'total', 'saldo']);
        $pdf->line('- ' . ($label !== '' ? $label : 'Item') . ': ' . $this->format_cop_currency($value), 8);
      }
      $pdf->line('Subtotal ' . strtolower((string) $section) . ': ' . $this->format_cop_currency($data['total'] ?? 0), 8, 'F2');
    }

    $revision = $this->cotizacion_revision_row($row);
    $danos = $this->cotizacion_parse_list($revision['evaluacion_de_danos'] ?? '');
    if (!empty($danos)) {
      $pdf->heading('Danos evaluados');
      foreach ($danos as $damage) {
        if (!is_array($damage)) {
          continue;
        }
        $summary = trim((string) ($damage['descripcion_dano'] ?? ''));
        $summary = trim(strip_tags(html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $responsableDano = trim((string) ($damage['a_quien_corresponde'] ?? '-'));
        $nivel = trim((string) ($damage['nivel_dano'] ?? '-'));
        $pdf->paragraph('Corresponde a: ' . ($responsableDano !== '' ? $responsableDano : '-') . ' | Nivel: ' . ($nivel !== '' ? $nivel : '-') . '. ' . ($summary !== '' ? $summary : 'Sin descripcion registrada.'), 8);
      }
    }

    $observaciones = trim(strip_tags(html_entity_decode((string) ($row['observaciones'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($observaciones !== '') {
      $pdf->heading('Observaciones');
      $pdf->paragraph($observaciones, 8);
    }

    $mejorOferta = $this->cotizacion_media_items($this->cotizacion_split_ids($row['mejor_oferta'] ?? ''));
    $otrasOfertas = $this->cotizacion_media_items($this->cotizacion_split_ids($row['otras_oferta'] ?? ''));
    if (!empty($mejorOferta) || !empty($otrasOfertas)) {
      $pdf->heading('Soportes');
      foreach (array_merge($mejorOferta, $otrasOfertas) as $media) {
        $url = trim((string) ($media['url'] ?? ''));
        if ($url !== '') {
          $pdf->linkText(trim((string) ($media['title'] ?? 'Documento')), $url);
        }
      }
    }

    $pdf->heading('Ordenes de mantenimiento');
    if (empty($orders)) {
      $pdf->paragraph('Sin ordenes registradas para esta cotizacion.', 8);
    } else {
      foreach ($orders as $order) {
        $pdf->line('Orden #' . trim((string) ($order['_ID'] ?? '-')) . ' | Estado: ' . trim((string) ($order['estado'] ?? '-')) . ' | Valor: ' . $this->format_cop_currency($order['valor'] ?? 0), 8, 'F2');
        $pdf->paragraph('Proveedor: ' . trim((string) ($order['proveedor'] ?? '-')) . '. Actividad: ' . trim((string) ($order['actividad'] ?? '-')), 8);
      }
    }

    $pdf->heading('Nota contractual');
    $pdf->paragraph('Cuando las reparaciones sean responsabilidad de los propietarios, el administrador informara la novedad. Si no se atiende dentro del plazo contractual, la administracion podra realizar la gestion y descontar el valor correspondiente del canon de arrendamiento, de acuerdo con el contrato de mandato vigente.', 8);
    $pdf->spacer(8);
    $pdf->signatureBlock('Responsable de la cotizacion', $responsable !== '' ? $responsable : 'Control Servicios Inmobiliarios', trim('Email: ' . (string) ($row['email_coordinador'] ?? $row['email_creador'] ?? '-') . ' | Cel. ' . (string) ($row['celular_coordinador'] ?? $row['celular_creador'] ?? '-'), ' |'));

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
