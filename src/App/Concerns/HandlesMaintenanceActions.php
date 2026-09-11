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

  public function ajax_handler_cotizacion_mantenimiento_form_context(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('abiertos') && !$this->canAccessDashboardTab('postergados') && !$this->canAccessDashboardTab('mis_tickets')) {
      $this->jsonFail('No tienes permiso para gestionar cotizaciones de mantenimiento.');
    }

    try {
      $mode = $this->maintenance_quote_mode($_POST['mode'] ?? 'create');
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
    $pdo = $this->db->pdo();
    try {
      $mode = $this->maintenance_quote_mode($_POST['mode'] ?? 'create');
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
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);

      $itemsMano = $this->maintenance_quote_repeater_items('items_mano_json', ['descripcion_mano', 'unidad_mano', 'cantidad_mano', 'valor_mano']);
      $itemsMateriales = $this->maintenance_quote_repeater_items('items_materiales_json', ['provedor_materiales', 'valor_materiales']);
      $itemsEquipos = $this->maintenance_quote_repeater_items('items_otros_equi_json', ['descipcion_otros_equi', 'unidad_otros_equi', 'cantidad_otros_equi', 'valor_otros_equi']);
      $itemsOtros = $this->maintenance_quote_repeater_items('items_otros_costos_json', ['descipcion_otros_costos', 'unidad_otros_costos', 'cantidad_otros_costos', 'valor_otros_costos']);

      $totals = [
        'total_mano_obra' => $this->maintenance_quote_total_items($itemsMano, 'cantidad_mano', 'valor_mano'),
        'total_materiales' => $this->maintenance_quote_total_items($itemsMateriales, '', 'valor_materiales'),
        'total_maquinarias' => $this->maintenance_quote_total_items($itemsEquipos, 'cantidad_otros_equi', 'valor_otros_equi'),
        'total_otros_costos' => $this->maintenance_quote_total_items($itemsOtros, 'cantidad_otros_costos', 'valor_otros_costos'),
      ];
      $totals['total'] = $totals['total_mano_obra'] + $totals['total_materiales'] + $totals['total_maquinarias'] + $totals['total_otros_costos'];
      if ($totals['total'] <= 0) {
        throw new \DomainException('Agrega al menos un valor a la cotización.');
      }

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
      $mejorOferta = array_merge($mejorOferta, array_map(static fn(array $photo): string => (string) ($photo['url'] ?? ''), $newMejor));
      $otrasOferta = array_merge($otrasOferta, array_map(static fn(array $photo): string => (string) ($photo['url'] ?? ''), $newOtras));

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
        'saldo_obra' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_obra', $totals['total_mano_obra']) : $totals['total_mano_obra'],
        'saldo_materiales' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_materiales', $totals['total_materiales']) : $totals['total_materiales'],
        'saldo_maquinarias' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_maquinarias', $totals['total_maquinarias']) : $totals['total_maquinarias'],
        'saldo_otros_costo' => $mode === 'edit' ? $this->maintenance_quote_balance_after_edit($sourceQuote, 'saldo_otros_costo', $totals['total_otros_costos']) : $totals['total_otros_costos'],
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
        'estado' => $mode === 'edit' ? ($sourceQuote['estado'] ?? '') : '',
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

      $this->maintenance_quote_update_revision_flag($schema, $tipoMantenimiento, $idRevision);
      $reportId = 0;
      if ($mode === 'create' && stripos($tipoMantenimiento, 'correct') !== false) {
        $reportId = $this->maintenance_quote_ensure_admin_report($schema, $quoteIdSaved, $quoteData, $ticket, $revision, $actor, $employeeId, $now, $nowSql);
      }
      $this->maintenance_quote_update_ticket($schema, $ticket, $quoteIdSaved, $tipoMantenimiento, $now, $nowSql);
      $this->maintenance_quote_insert_histories($schema, $quoteIdSaved, $mode, $quoteData, $ticket, $actor, $employeeId, $now, $nowSql);
      $queued = $this->maintenance_quote_enqueue_saved_notifications($mode, $quoteIdSaved, $quoteData, $actor);
      $pdo->commit();

      $this->jsonOk([
        'message' => ($mode === 'edit' ? 'Cotización actualizada.' : ($mode === 'note' ? 'Nota de cotización creada.' : 'Cotización creada.')) . ($reportId > 0 ? ' Reporte administrativo #' . $reportId . ' creado.' : '') . ($queued > 0 ? ' Notificaciones en cola: ' . $queued . '.' : ''),
        'id_cotizacion' => (string) $quoteIdSaved,
        'id_reporte' => $reportId > 0 ? (string) $reportId : '',
      ]);
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($storedPhotos) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
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
    $defaultDestinatario = $this->maintenance_quote_first([
      $quote['destinatario'] ?? '',
      $revision['destinatario'] ?? '',
      $ticket['propietario'] ?? '',
      $ticket['arrendatario'] ?? '',
    ]);
    $defaultEmail = $this->maintenance_quote_first([
      $quote['email_destinatario'] ?? '',
      $revision['email_destinatario'] ?? '',
      $ticket['correo'] ?? '',
      $ticket['email'] ?? '',
    ]);
    $defaultCelular = $this->maintenance_quote_first([
      $quote['celular_destinatario'] ?? '',
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
        'indicativo_destinarario' => $this->maintenance_quote_first([$quote['indicativo_destinarario'] ?? '', '57']),
        'celular_destinatario' => $defaultCelular,
        'ejecutado' => trim((string) ($quote['ejecutado'] ?? '')),
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
      'unit_options' => $this->maintenance_quote_glossary_options(612, [
        ['value' => 'Unidad', 'label' => 'Unidad'],
        ['value' => 'Metro', 'label' => 'Metro'],
        ['value' => 'Global', 'label' => 'Global'],
      ]),
      'executor_options' => $this->maintenance_quote_glossary_options(853, [
        ['value' => 'Propietario', 'label' => 'Propietario'],
        ['value' => 'Arrendatario', 'label' => 'Arrendatario'],
        ['value' => 'Inmobiliaria', 'label' => 'Inmobiliaria'],
      ]),
      'actor' => [
        'name' => trim((string) ($actor['name'] ?? Auth::user())),
        'employee_id' => trim((string) ($actor['employee_id'] ?? '')),
      ],
      'ticket_raw' => $ticket,
      'revision_raw' => is_array($revision) ? $revision : [],
      'cotizacion_raw' => $quote,
    ];
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

  /** @param array<int,array<string,string>> $fallback @return array<int,array<string,string>> */
  private function maintenance_quote_glossary_options(int $glossaryId, array $fallback): array
  {
    if (method_exists($this, 'correctiveReviewGlossaryOptions')) {
      $options = $this->correctiveReviewGlossaryOptions($glossaryId, $fallback);
      if (is_array($options) && $options !== []) {
        return $options;
      }
    }
    return $fallback;
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
        $value = in_array($field, ['cantidad_mano', 'valor_mano', 'valor_materiales', 'cantidad_otros_equi', 'valor_otros_equi', 'cantidad_otros_costos', 'valor_otros_costos'], true)
          ? (string) (int) round($this->maintenance_quote_number($row[$field] ?? 0))
          : $this->maintenance_quote_clean($row[$field] ?? '');
        if (trim($value) !== '' && trim($value) !== '0') {
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

  /** @param array<string,mixed> $sourceQuote */
  private function maintenance_quote_balance_after_edit(array $sourceQuote, string $saldoField, float $newTotal): string
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
    $oldTotal = $this->maintenance_quote_number($sourceQuote[$totalField] ?? 0);
    $oldBalance = $this->maintenance_quote_number($sourceQuote[$saldoField] ?? $oldTotal);
    $used = max(0.0, $oldTotal - $oldBalance);
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
    $where = ['TRIM(COALESCE(`id_ticket`, "")) = ?'];
    $params = [$ticketRef];
    if ($revisionId !== '') {
      $column = stripos($tipo, 'prevent') !== false ? 'id_revision_preventiva' : 'id_revision_correctiva';
      $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
      $params[] = $revisionId;
    }
    $existing = $this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE " . implode(' AND ', $where) . " LIMIT 1", $params);
    if ((int) $existing > 0) {
      return 0;
    }

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
    $description = 'Cobro administrativo por cotización de mantenimiento #' . $quoteId . '.';
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
      'categoria' => 'Cotización de mantenimiento',
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
      if (stripos($tipo, 'prevent') !== false) {
        $payload['id_revision_preventiva'] = $revisionId;
      } else {
        $payload['id_revision_correctiva'] = $revisionId;
      }
    }

    $payload = $schema->filterTableData($table, $payload);
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
    $html = '<div style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#0b1f3a;">'
      . '<div style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #dbe4ef;border-radius:16px;overflow:hidden;">'
      . '<div style="padding:20px 24px;background:#0b1f3a;color:#ffffff;"><h1 style="margin:0;font-size:21px;">Cotización de mantenimiento</h1></div>'
      . '<div style="padding:24px;"><p style="margin:0 0 16px;">Se registró una novedad de cotización de mantenimiento en Control Servicios Inmobiliarios.</p>'
      . '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">' . $items . '</table>'
      . '</div></div></div>';

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
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
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
    if (!$this->maintenance_order_can_manage()) {
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

  private function maintenance_order_can_manage(): bool
  {
    return $this->canAccessDashboardTab('cotizaciones_mantenimiento')
      || $this->canAccessDashboardTab('abiertos')
      || $this->canAccessDashboardTab('postergados')
      || $this->canAccessDashboardTab('mis_tickets');
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

  /** @return array<int,array{name:string,email:string}> */
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
        'name' => trim((string) ($funcionario['name'] ?? '')),
        'email' => $email,
      ];
    }

    return $this->maintenance_order_unique_email_recipients($recipients);
  }

  /** @param array<int,array{name:string,email:string}> $recipients @return array<int,array{name:string,email:string}> */
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
        'name' => trim((string) ($recipient['name'] ?? '')),
        'email' => $email,
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
    $recipients = $this->maintenance_order_internal_email_recipients('orden_mantenimiento_creada');
    if ($recipients === []) {
      return 0;
    }

    $emails = array_column($recipients, 'email');
    $category = trim((string) ($order['categoria'] ?? 'mantenimiento'));
    $ticket = $this->maintenance_order_first([$order['id_ticket'] ?? '', $cotizacion['id_ticket'] ?? '']);
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

    return (new \SCM\Support\EmailQueue($this->db))->enqueue($emails, $subject, $html, [
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
        $label = $this->cotizacion_first_matching_value($item, ['prove', 'descripcion', 'actividad', 'concepto', 'detalle']);
        $value = $this->cotizacion_first_matching_value($item, ['valor', 'total', 'saldo']);
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
