<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait HandlesMaintenanceActions
{
  public function ajax_handler_generic(): void
  {
    $this->verifyCsrf();

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

  public function ajax_handler_cotizaciones_mantenimiento(): void
  {
    $this->verifyCsrf();

    $params = $this->parse_cotizaciones_mantenimiento_params($_POST, 'scmqt_');
    $result = $this->query_cotizaciones_mantenimiento($params);
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
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
    $notifyOldEmp  = !empty($_POST['notify_anterior']);
    $notifyNewEmp  = !empty($_POST['notify_nuevo']);

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
    $newCelular = trim((string) ($newEmp['celular'] ?? ''));

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
      $notifyNewEmp
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
