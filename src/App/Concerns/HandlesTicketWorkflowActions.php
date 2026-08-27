<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait HandlesTicketWorkflowActions
{
  public function ajax_handler_dashboard_permissions_read(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageDashboardPermissions()) {
      $this->jsonFail('No tienes permiso para configurar pestañas.');
    }

    $this->jsonOk([
      'tabs' => $this->dashboardPermissionTabs(),
      'cargos' => $this->getDashboardCargoOptions(),
      'permissions' => $this->dashboardPermissionsConfig(),
    ]);
  }

  public function ajax_handler_dashboard_permissions_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageDashboardPermissions()) {
      $this->jsonFail('No tienes permiso para configurar pestañas.');
    }

    $raw = stripslashes((string) ($_POST['permissions'] ?? '{}'));
    try {
      $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      $this->jsonFail('La configuracion enviada no es valida.');
      return;
    }
    $permissions = $this->sanitizeDashboardPermissions(is_array($decoded) ? $decoded : []);
    \SCM\Core\App::settings()->set('dashboard_tab_permissions', $permissions, Auth::userId());
    \SCM\Core\App::settings()->refresh();

    $this->jsonOk([
      'message' => 'Permisos de pestañas guardados.',
      'permissions' => $permissions,
    ]);
  }

  public function ajax_handler()
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('abiertos')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $rawConfig = [];
    if (!empty($_POST['config'])) {
      $decoded = json_decode(stripslashes($_POST['config']), true);
      if (is_array($decoded)) {
        $rawConfig = $decoded;
      }
    }
    $config = [
      'ticket_url'     => self::sanitizeUrl($rawConfig['ticket_url']     ?? self::DEFAULT_TICKET_URL),
      'preventiva_url' => self::sanitizeUrl($rawConfig['preventiva_url'] ?? self::DEFAULT_PREVENTIVA_URL),
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::DEFAULT_CORRECTIVA_URL),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
      'acta_url'       => self::sanitizeUrl($rawConfig['acta_url']       ?? self::DEFAULT_ACTA_URL),
    ];

    $module = $this->get_servicios_inmobiliarios_module();
    if ($module instanceof \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosModule) {
      $params = $module->parseParams($_POST);
      $result = $module->run($params, $config);
      $stats  = is_array($result['stats'] ?? null) ? $result['stats'] : [];

      $this->jsonOk([
        'tbody'           => (string)($result['tbody'] ?? ''),
        'pagination'      => (string)($result['pagination_html'] ?? ''),
        'kpi_total'       => (string)($stats['total'] ?? 0),
        'kpi_sin_cotz'    => (string)($stats['sin_cotizacion'] ?? 0),
        'kpi_con_cotz'    => (string)($stats['con_cotizacion'] ?? 0),
        'kpi_sin_prev'    => (string)($stats['sin_revision'] ?? 0),
        'kpi_con_prev'    => (string)($stats['con_revision'] ?? 0),
        'kpi_abiertos'    => (string)($stats['abiertos'] ?? 0),
        'kpi_cerrados'    => (string)($stats['cerrados'] ?? 0),
        'kpi_vencidos'    => (string)($stats['sla_vencido'] ?? 0),
        'kpi_en_riesgo'   => (string)($stats['sla_riesgo'] ?? 0),
        'kpi_avg_first_h' => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? number_format((float) $stats['avg_first_h'], 1) . 'h' : '-',
        'kpi_avg_close_h' => isset($stats['avg_close_h']) && is_numeric($stats['avg_close_h']) ? number_format((float) $stats['avg_close_h'], 1) . 'h' : '-',
        'kpi_avg_stale_h' => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? number_format((float) $stats['avg_stale_h'], 1) . 'h' : '-',
        'kpi_mes_actualizados' => (string)($stats['mes_actualizados'] ?? 0),
        'kpi_mes_cerrados' => (string)($stats['mes_cerrados'] ?? 0),
        'kpi_mes_seguimientos' => (string)($stats['mes_seguimientos'] ?? 0),
        'kpi_seg_por_funcionario' => is_array($stats['seg_por_funcionario'] ?? null) ? $stats['seg_por_funcionario'] : [],
        'kpi_abiertos_por_funcionario' => is_array($stats['abiertos_por_funcionario'] ?? null) ? $stats['abiertos_por_funcionario'] : [],
        'kpi_actualizados_por_funcionario' => is_array($stats['actualizados_por_funcionario'] ?? null) ? $stats['actualizados_por_funcionario'] : [],
        'kpi_magnitud_critico' => (string)($stats['magnitud_critico'] ?? 0),
        'kpi_magnitud_alto' => (string)($stats['magnitud_alto'] ?? 0),
        'kpi_magnitud_medio' => (string)($stats['magnitud_medio'] ?? 0),
        'kpi_magnitud_bajo' => (string)($stats['magnitud_bajo'] ?? 0),
      ]);
    }

    $this->jsonFail('Modulo principal de Control de Servicios Inmobiliarios no disponible.');
  }

  public function ajax_handler_seguimiento()
  {
    $this->verifyCsrf();

    $ticketPk             = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $observacion          = trim(stripslashes((string) ($_POST['observacion'] ?? '')));
    $estadoTicket         = trim(strip_tags(stripslashes((string) ($_POST['estado_ticket'] ?? '__keep__'))));
    $estadoCotizacion     = trim(strip_tags(stripslashes((string) ($_POST['estado_cotizacion'] ?? '__keep__'))));
    $observacionCotizacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion_cotizacion'] ?? ''))));
    $motivoCotizacion     = trim(strip_tags(stripslashes((string) ($_POST['motivo_cotizacion'] ?? ''))));
    $financiacionCotizacion = trim(strip_tags(stripslashes((string) ($_POST['financiacion_cotizacion'] ?? ''))));
    $estadoAdministrativo = trim(strip_tags(stripslashes((string) ($_POST['estado_administrativo'] ?? '__keep__'))));
    $cerrarTicket         = !empty($_POST['cerrar_ticket']) && (string) $_POST['cerrar_ticket'] === '1';
    $notifyRecipients     = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($observacion === '') {
      $this->jsonFail('La observacion es obligatoria.');
    }

    $service = $this->get_seguimiento_service();
    if (!($service instanceof \SCM\Modules\ServiciosInmobiliarios\SeguimientoService)) {
      $this->jsonFail('Servicio de seguimiento no disponible.');
    }

    $evidencias = $this->handleImageUploads('evidencia', 10);
    $documentTitles = isset($_POST['documento_nombre']) && is_array($_POST['documento_nombre']) ? $_POST['documento_nombre'] : [];
    $documentos = $this->handleDocumentUploads('documento', $documentTitles, 10);
    $result = $service->save($ticketPk, $observacion, $estadoTicket, $estadoCotizacion, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $evidencias, $documentos, $observacionCotizacion, $motivoCotizacion, $financiacionCotizacion);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar seguimiento.'));
    }

    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Seguimiento guardado.'),
      'seg_saved' => (string) ($result['seg_saved'] ?? '0'),
      'hist_saved' => (string) ($result['hist_saved'] ?? '0'),
      'cot_rows' => (string) ($result['cot_rows'] ?? '0'),
    ]);
  }

  public function ajax_handler_nota()
  {
    $this->verifyCsrf();

    $ticketPk    = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $observacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion'] ?? ''))));

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($observacion === '') {
      $this->jsonFail('La nota no puede estar vacia.');
    }

    $service = $this->get_seguimiento_service();
    if (!($service instanceof \SCM\Modules\ServiciosInmobiliarios\SeguimientoService)) {
      $this->jsonFail('Servicio de notas no disponible.');
    }

    $result = $service->saveNote($ticketPk, $observacion);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar la nota.'));
    }

    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Nota guardada.'),
      'note_id' => (string) ($result['note_id'] ?? ''),
    ]);
  }

  public function ajax_handler_postpone_ticket()
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $observacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion'] ?? ''))));
    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($observacion === '') {
      $this->jsonFail('El motivo de postergacion es obligatorio.');
    }

    $service = $this->get_seguimiento_service();
    if (!($service instanceof \SCM\Modules\ServiciosInmobiliarios\SeguimientoService)) {
      $this->jsonFail('Servicio de seguimiento no disponible.');
    }

    $evidencias = $this->handleImageUploads('evidencia', 10);
    $documentTitles = isset($_POST['documento_nombre']) && is_array($_POST['documento_nombre']) ? $_POST['documento_nombre'] : [];
    $documentos = $this->handleDocumentUploads('documento', $documentTitles, 10);
    $result = $service->postponeTicket($ticketPk, $observacion, $notifyRecipients, $evidencias, $documentos);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo postergar el ticket.'));
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_status_tickets(): void
  {
    $this->verifyCsrf();

    $bucketKey = $this->normalize_status_bucket((string) ($_POST['bucket'] ?? ''));
    $topicKey = $this->normalize_status_topic((string) ($_POST['topic'] ?? ''));
    if ($bucketKey === '' || $topicKey === '') {
      $this->jsonFail('Vista de tickets no reconocida.');
    }
    if (!$this->canAccessDashboardTab($bucketKey)) {
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
    $maintenanceFilterOptions = $module->getFilterOptions();
    $data = $this->build_status_topic_result($bucketKey, $topicKey, $config, $_POST, $maintenanceFilterOptions);
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

    $this->jsonOk([
      'cards' => (string) ($data['cards'] ?? ''),
      'pagination' => (string) ($data['pagination'] ?? ''),
      'count' => (string) ($stats['total'] ?? ($data['count'] ?? 0)),
      'kpi_total' => (string) ($stats['total'] ?? ($data['count'] ?? 0)),
      'kpi_con_cotz' => (string) ($stats['con_cotizacion'] ?? 0),
      'kpi_sin_cotz' => (string) ($stats['sin_cotizacion'] ?? 0),
      'kpi_con_prev' => (string) ($stats['con_revision'] ?? 0),
      'kpi_sin_prev' => (string) ($stats['sin_revision'] ?? 0),
    ]);
  }

  public function ajax_handler_activate_ticket(): void
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $motivo = trim(wp_kses_post(stripslashes((string) ($_POST['motivo'] ?? ($_POST['observacion'] ?? '')))));
    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($motivo === '') {
      $this->jsonFail('El motivo de activacion es obligatorio.');
    }

    $service = $this->get_seguimiento_service();
    if (!($service instanceof \SCM\Modules\ServiciosInmobiliarios\SeguimientoService)) {
      $this->jsonFail('Servicio de seguimiento no disponible.');
    }

    $result = $service->activateTicket($ticketPk, $motivo);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo activar el ticket.'));
    }
    $this->jsonOk($result);
  }

  public function ajax_handler_ticket_response()
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $respuesta = trim(wp_kses_post(stripslashes((string) ($_POST['respuesta'] ?? ''))));
    $estadoAdministrativo = trim(strip_tags(stripslashes((string) ($_POST['estado_administrativo'] ?? '__keep__'))));
    $estadoCotizacion = trim(strip_tags(stripslashes((string) ($_POST['estado_cotizacion'] ?? '__keep__'))));
    $observacionCotizacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion_cotizacion'] ?? ''))));
    $motivoCotizacion = trim(strip_tags(stripslashes((string) ($_POST['motivo_cotizacion'] ?? ''))));
    $financiacionCotizacion = trim(strip_tags(stripslashes((string) ($_POST['financiacion_cotizacion'] ?? ''))));
    $cerrarTicket = !empty($_POST['cerrar_ticket']) && (string) $_POST['cerrar_ticket'] === '1';
    $generarActaNoAccesoPreventiva = !empty($_POST['generar_acta_no_acceso_preventiva']) && (string) $_POST['generar_acta_no_acceso_preventiva'] === '1';
    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($respuesta === '') {
      $this->jsonFail('La respuesta no puede estar vacia.');
    }

    $service = $this->get_seguimiento_service();
    $imagenes = $this->handleImageUploads('imagen', 10);
    $documentTitles = isset($_POST['documento_nombre']) && is_array($_POST['documento_nombre']) ? $_POST['documento_nombre'] : [];
    $documentos = $this->handleDocumentUploads('documento', $documentTitles, 10);
    $result = $service->saveTicketResponse($ticketPk, $respuesta, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $imagenes, $documentos, $estadoCotizacion, $observacionCotizacion, $motivoCotizacion, $financiacionCotizacion, $generarActaNoAccesoPreventiva);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar la respuesta.'));
    }
    $this->jsonOk($result);
  }

  public function ajax_handler_cotizacion_response()
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $estado = trim(strip_tags(stripslashes((string) ($_POST['estado'] ?? ''))));
    $observacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion'] ?? 'Ninguna'))));
    $motivo = trim(strip_tags(stripslashes((string) ($_POST['motivo'] ?? ''))));
    $financiacion = trim(strip_tags(stripslashes((string) ($_POST['financiacion'] ?? ''))));
    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if (!in_array($estado, ['Aprobada', 'Desaprobada'], true)) {
      $this->jsonFail('Selecciona si la cotizacion fue aprobada o desaprobada.');
    }
    if ($observacion === '') {
      $observacion = 'Ninguna';
    }
    if ($estado === 'Desaprobada' && $motivo === '') {
      $this->jsonFail('Indica el motivo cuando la cotizacion fue desaprobada.');
    }
    if ($estado === 'Desaprobada' && !in_array($motivo, ['Por costo', 'Ejecucción por cuenta propia'], true)) {
      $this->jsonFail('Selecciona un motivo valido para cotizacion desaprobada.');
    }
    if ($estado !== 'Aprobada') {
      $financiacion = '';
    }

    $service = $this->get_seguimiento_service();
    $result = $service->saveCotizacionResponse($ticketPk, $estado, $observacion, $motivo, $financiacion, $notifyRecipients);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar la respuesta de cotizacion.'));
    }
    $this->jsonOk($result);
  }

  public function ajax_handler_preventivas_pendientes()
  {
    $this->verifyCsrf();
    $scope = sanitize_key($_POST['pending_scope'] ?? $_POST['scope'] ?? '');
    if ($scope === 'contratos_arrendamiento') {
      if (!$this->canAccessDashboardTab('contratos_arrendamiento')) {
        $this->jsonFail('No tienes permiso para ver esta pestaña.');
      }
      $this->respond_contratos_arrendamiento();
    }
    if (!$this->canAccessDashboardTab('preventivas_pendientes')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $controller = $this->get_pending_controller();
    $payload = $controller->buildPreventivasPayload($_POST);
    $view = new \SCM\Modules\Pending\PendingView();
    $kpis = $view->renderPreventivasKpis((int)($payload['count'] ?? 0), (string)($payload['corte'] ?? ''));
    $table = $view->renderPreventivasTable((array)($payload['items'] ?? []));
    $this->jsonOk([
      'kpis_html' => $kpis,
      'table_html' => $table,
      'count' => (string)($payload['count'] ?? 0),
    ]);
  }

  public function ajax_handler_servicios_publicos_pendientes()
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('servicios_publicos_pendientes')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }
    $controller = $this->get_pending_controller();
    $payload = $controller->buildServiciosPublicosPayload($_POST);
    $view = new \SCM\Modules\Pending\PendingView();
    $kpis = $view->renderServiciosPublicosKpis((int)($payload['count'] ?? 0), (string)($payload['corte'] ?? ''));
    $table = $view->renderServiciosPublicosTable((array)($payload['items'] ?? []));
    $this->jsonOk([
      'kpis_html' => $kpis,
      'table_html' => $table,
      'count' => (string)($payload['count'] ?? 0),
    ]);
  }

  public function ajax_handler_reportes_administrativos_pendientes()
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('reportes_administrativos_pendientes')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }
    $controller = $this->get_pending_controller();
    $payload = $controller->buildReportesAdministrativosPayload($_POST);
    $view = new \SCM\Modules\Pending\PendingView();
    $table = $view->renderReportesAdministrativosTable((array)($payload['items'] ?? []));
    $this->jsonOk([
      'table_html' => $table,
      'count' => (string)($payload['count'] ?? 0),
    ]);
  }

  public function ajax_handler_contratos_arrendamiento(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }
    $this->respond_contratos_arrendamiento();
  }

  private function respond_contratos_arrendamiento(): void
  {
    $controller = $this->get_pending_controller();
    $payload = $controller->buildContratosArrendamientoPayload($_POST);
    $view = new \SCM\Modules\Pending\PendingView();
    $table = $view->renderContratosArrendamientoTable((array)($payload['items'] ?? []));
    $pagination = $view->renderContratosPagination((array)($payload['pagination'] ?? []));
    $this->jsonOk([
      'bucket' => (string)($payload['bucket'] ?? ''),
      'label' => (string)($payload['label'] ?? ''),
      'table_html' => $table,
      'pagination_html' => $pagination,
      'count' => (string)($payload['count'] ?? 0),
    ]);
  }

  public function ajax_handler_contrato_recibido(): void
  {
    $this->verifyCsrf();
    if (
      !$this->canAccessDashboardTab('contratos_arrendamiento')
      && !$this->canAccessDashboardTab('preventivas_pendientes')
      && !$this->canAccessDashboardTab('servicios_publicos_pendientes')
    ) {
      $this->jsonFail('No tienes permiso para modificar contratos de arrendamiento.');
    }

    $contractId = (int) ($_POST['contract_id'] ?? $_POST['_ID'] ?? $_POST['id'] ?? 0);
    $fechaRecibo = trim(sanitize_text_field(wp_unslash((string) ($_POST['fecha_recibo'] ?? ''))));
    if ($contractId <= 0) {
      $this->jsonFail('ID de contrato invalido.');
    }
    if ($fechaRecibo === '') {
      $this->jsonFail('La fecha de recibo es obligatoria.');
    }

    $result = $this->get_pending_controller()->markContratoRecibido($contractId, $fechaRecibo);
    if (empty($result['ok'])) {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo marcar el contrato como recibido.'));
    }

    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Contrato marcado como recibido.'),
      'estado' => (string) ($result['estado'] ?? 'Recibido'),
      'tipo' => (string) ($result['tipo'] ?? 'Ex'),
      'fecha_recibo' => (string) ($result['fecha_recibo'] ?? ''),
      'fecha_recibo_date' => (string) ($result['fecha_recibo_date'] ?? ''),
    ]);
  }

  public function ajax_handler_contrato_ultima_preventiva(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('preventivas_pendientes')) {
      $this->jsonFail('No tienes permiso para modificar preventivas pendientes.');
    }

    $contractId = (int) ($_POST['contract_id'] ?? $_POST['_ID'] ?? $_POST['id'] ?? 0);
    $fechaUltima = trim(sanitize_text_field(wp_unslash((string) ($_POST['ultima_revision_preventiva'] ?? $_POST['fecha_ultima_preventiva'] ?? ''))));
    if ($contractId <= 0) {
      $this->jsonFail('ID de contrato invalido.');
    }
    if ($fechaUltima === '') {
      $this->jsonFail('La fecha de ultima preventiva es obligatoria.');
    }

    $result = $this->get_pending_controller()->postponePreventivaToNextYear($contractId, $fechaUltima);
    if (empty($result['ok'])) {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo actualizar la ultima preventiva.'));
    }

    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Ultima preventiva actualizada.'),
      'ultima_revision_preventiva' => (string) ($result['ultima_revision_preventiva'] ?? ''),
      'ultima_revision_preventiva_date' => (string) ($result['ultima_revision_preventiva_date'] ?? ''),
      'siguiente_revision_preventiva' => (string) ($result['siguiente_revision_preventiva'] ?? ''),
      'siguiente_revision_preventiva_date' => (string) ($result['siguiente_revision_preventiva_date'] ?? ''),
    ]);
  }

  public function ajax_handler_crear_ticket_administrativo(): void
  {
    $this->verifyCsrf();

    $clean = static function (string $key): string {
      return trim(wp_kses_post(wp_unslash((string) ($_POST[$key] ?? ''))));
    };
    $input = [];
    foreach ([
      'ticket_mode',
      'contract_pk',
      'id_contrato',
      'id_inmueble',
      'id_arrendatario',
      'id_propietario',
      'id_sucursal',
      'id_inventario',
      'id_empleado',
      'solicitante_tipo',
      'prioridad',
      'departamento',
      'tema_ayuda',
      'asunto',
      'descripcion',
      'contrato',
      'inmueble',
      'direccion',
      'barrio',
      'propietario',
      'correo_propietario',
      'celular_propietario',
      'arrendatario',
      'correo_arrendatario',
      'celular_arrendatario',
      'registro_fotografico',
      'fecha_final_contrato',
    ] as $key) {
      $input[$key] = $clean($key);
    }

    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }
    $imagenes = $this->handleImageUploads('imagen', 10);
    $documentTitles = isset($_POST['documento_nombre']) && is_array($_POST['documento_nombre']) ? $_POST['documento_nombre'] : [];
    $documentos = $this->handleDocumentUploads('documento', $documentTitles, 10);

    $controller = $this->get_pending_controller();
    $result = $controller->createAdministrativeTicket($input, $imagenes, $documentos, $notifyRecipients);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo crear el ticket.'));
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_aprobar_reporte_administrativo()
  {
    $this->verifyCsrf();
    $preId = isset($_POST['pre_id']) ? (int) $_POST['pre_id'] : 0;
    if ($preId <= 0) {
      $this->jsonFail('Reporte administrativo invalido.');
    }

    $controller = $this->get_pending_controller();
    $result = $controller->approveReporteAdministrativo($preId);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo aprobar el reporte.'));
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_desaprobar_reporte_administrativo()
  {
    $this->verifyCsrf();
    $preId = isset($_POST['pre_id']) ? (int) $_POST['pre_id'] : 0;
    if ($preId <= 0) {
      $this->jsonFail('Reporte administrativo invalido.');
    }

    $controller = $this->get_pending_controller();
    $result = $controller->rejectReporteAdministrativo($preId);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo desaprobar el reporte.'));
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_close_ticket()
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $observacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion'] ?? ($_POST['motivo'] ?? '')))));
    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($observacion === '') {
      $this->jsonFail('El mensaje de cierre es obligatorio.');
    }

    $service = $this->get_seguimiento_service();
    $result = $service->closeTicket($ticketPk, $observacion);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo cerrar el ticket.'));
    }
    $this->jsonOk($result);
  }

  public function ajax_handler_calendar_cita_notify(): void
  {
    $this->verifyCsrf();

    try {
    $rawAppointments = stripslashes((string) ($_POST['appointments'] ?? '[]'));
    $appointments = json_decode($rawAppointments, true);
    if (!is_array($appointments)) {
      $appointments = [];
    }

    if (empty($appointments)) {
      $appointments[] = [
        'id_ticket' => $_POST['id_ticket'] ?? '',
        'id_empleado' => $_POST['id_empleado'] ?? '',
        'categoria' => $_POST['categoria'] ?? '',
        'titulo' => $_POST['titulo'] ?? '',
        'fecha_inicio' => $_POST['fecha_inicio'] ?? '',
        'fecha_fin' => $_POST['fecha_fin'] ?? '',
        'ubicacion' => $_POST['ubicacion'] ?? '',
        'es_cita' => $_POST['es_cita'] ?? 'si',
      ];
    }

    $smsQueue = new \SCM\Support\SmsQueue($this->db);
    $creator = $this->calendarCitaCreatorContact();
    $queued = 0;
    $skipped = 0;
    $errors = [];
    $requesterDedupe = [];

    foreach ($appointments as $appointment) {
      if (!is_array($appointment)) {
        $skipped++;
        continue;
      }

      $isCita = strtolower(trim((string) ($appointment['es_cita'] ?? 'si'))) === 'si';
      $ticketLookup = trim(strip_tags((string) ($appointment['id_ticket'] ?? '')));
      $employeeId = trim(strip_tags((string) ($appointment['id_empleado'] ?? '')));
      if (!$isCita || $ticketLookup === '') {
        $skipped++;
        continue;
      }

      $ticket = $this->calendarCitaTicketRow($ticketLookup);
      $logicalTicket = trim((string) ($ticket['id_ticket'] ?? ''));
      if ($logicalTicket === '') {
        $logicalTicket = $ticketLookup;
      }
      $category = trim(strip_tags((string) ($appointment['categoria'] ?? '')));
      if ($category === '') {
        $category = trim((string) ($ticket['tipo_pqrs'] ?? $ticket['tema_ayuda'] ?? $ticket['asunto'] ?? 'cita'));
      }
      $location = trim(strip_tags((string) ($appointment['ubicacion'] ?? '')));
      if ($location === '') {
        $location = trim((string) ($ticket['direccion'] ?? ''));
      }
      $start = trim(strip_tags((string) ($appointment['fecha_inicio'] ?? '')));
      $end = trim(strip_tags((string) ($appointment['fecha_fin'] ?? '')));
      $dateLabel = $this->calendarCitaDateLabel($start);
      $timeLabel = $this->calendarCitaTimeRangeLabel($start, $end);

      if ($employeeId !== '') {
        $employee = $this->calendarCitaFuncionarioRow($employeeId, 'id_empleado');
        $employeeName = trim((string) ($employee['name'] ?? ''));
        if ($employeeName === '') {
          $employeeName = 'Funcionario';
        }
        $employeePhone = trim((string) ($employee['phone'] ?? ''));
        if ($employeePhone !== '') {
          $ok = $smsQueue->enqueue($employeePhone, $employeeName, $this->calendarCitaEmployeeMessage($employeeName, $category, $logicalTicket, $dateLabel, $timeLabel, $location), [
            'source_module' => 'calendar_cita_funcionario',
            'campaign_tag' => 'calendar_cita_funcionario',
            'categoria_mensaje' => 'informacion',
            'id_funcionario' => is_numeric($employeeId) ? (int) $employeeId : 0,
            'id_ticket' => $logicalTicket,
            'ticket_pk' => (string) ($ticket['_ID'] ?? $ticketLookup),
            'categoria_cita' => $category,
            'fecha_inicio' => $start,
            'fecha_fin' => $end,
            'creado_por' => $creator['name'],
            'creado_por_telefono' => $creator['phone'],
            'dedupe_key' => 'calendar_cita_funcionario:' . $logicalTicket . ':' . $employeeId . ':' . $start,
            'template_name' => 'scm_cita_funcionario_v1',
            'template_language' => 'es_CO',
            'template_components' => [
              [
                'type' => 'body',
                'parameters' => [
                  ['type' => 'text', 'text' => $employeeName],
                  ['type' => 'text', 'text' => $category],
                  ['type' => 'text', 'text' => $logicalTicket],
                  ['type' => 'text', 'text' => $dateLabel],
                  ['type' => 'text', 'text' => $timeLabel],
                  ['type' => 'text', 'text' => $location !== '' ? $location : '-'],
                ],
              ],
              [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [
                  ['type' => 'text', 'text' => $logicalTicket],
                ],
              ],
            ],
          ]);
          if ($ok) {
            $queued++;
          } else {
            $detail = method_exists($smsQueue, 'lastError') ? trim((string) $smsQueue->lastError()) : '';
            $errors[] = 'No se pudo encolar WhatsApp al funcionario del ticket #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : '.') ;
          }
        } else {
          $skipped++;
        }
      }

      $requester = $this->calendarCitaRequesterContact($ticket);
      $requesterPhone = trim((string) ($requester['phone'] ?? ''));
      $requesterName = trim((string) ($requester['name'] ?? ''));
      $requesterKey = $logicalTicket . ':' . $start . ':' . $requesterPhone;
      if ($requesterPhone !== '' && !isset($requesterDedupe[$requesterKey])) {
        $requesterDedupe[$requesterKey] = true;
        $creatorContact = $this->calendarCitaCreatorLabel($creator);
        $ok = $smsQueue->enqueue($requesterPhone, $requesterName, $this->calendarCitaRequesterMessage($requesterName, $category, $logicalTicket, $dateLabel, $timeLabel, $location, $creatorContact), [
          'source_module' => 'calendar_cita_solicitante',
          'campaign_tag' => 'calendar_cita_solicitante',
          'categoria_mensaje' => 'informacion',
          'id_ticket' => $logicalTicket,
          'ticket_pk' => (string) ($ticket['_ID'] ?? $ticketLookup),
          'categoria_cita' => $category,
          'fecha_inicio' => $start,
          'fecha_fin' => $end,
          'creado_por' => $creator['name'],
          'creado_por_telefono' => $creator['phone'],
          'dedupe_key' => 'calendar_cita_solicitante:' . $logicalTicket . ':' . $start,
          'template_name' => 'scm_cita_solicitante_v1',
          'template_language' => 'es_CO',
          'template_components' => [
            [
              'type' => 'body',
              'parameters' => [
                ['type' => 'text', 'text' => $requesterName !== '' ? $requesterName : 'Cliente'],
                ['type' => 'text', 'text' => $category],
                ['type' => 'text', 'text' => $logicalTicket],
                ['type' => 'text', 'text' => $dateLabel],
                ['type' => 'text', 'text' => $timeLabel],
                ['type' => 'text', 'text' => $location !== '' ? $location : '-'],
                ['type' => 'text', 'text' => $creatorContact],
              ],
            ],
            [
              'type' => 'button',
              'sub_type' => 'url',
              'index' => '0',
              'parameters' => [
                ['type' => 'text', 'text' => $logicalTicket],
              ],
            ],
          ],
        ]);
        if ($ok) {
          $queued++;
        } else {
          $detail = method_exists($smsQueue, 'lastError') ? trim((string) $smsQueue->lastError()) : '';
          $errors[] = 'No se pudo encolar WhatsApp al solicitante del ticket #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : '.') ;
        }
      } elseif ($requesterPhone === '') {
        $skipped++;
      }
    }

    $this->jsonOk([
      'message' => $queued > 0 ? 'Notificaciones de cita encoladas.' : 'No habia destinatarios con WhatsApp para notificar.',
      'queued' => $queued,
      'skipped' => $skipped,
      'errors' => $errors,
    ]);
    } catch (\Throwable $exception) {
      error_log(
        'control-servicios-inmobiliarios: error preparando WhatsApp de cita: '
        . $exception->getMessage()
        . ' in '
        . $exception->getFile()
        . ':'
        . $exception->getLine()
      );
      $this->jsonOk([
        'message' => 'Evento creado, pero no se pudo preparar WhatsApp.',
        'queued' => 0,
        'skipped' => 0,
        'errors' => [
          'Error preparando WhatsApp: ' . $exception->getMessage(),
        ],
      ]);
    }
  }

  /** @return array<string,string> */
  private function calendarCitaTicketRow(string $ticketLookup): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }

    $candidates = [
      '_ID',
      'id_ticket',
      'tipo_pqrs',
      'tema_ayuda',
      'asunto',
      'direccion',
      'solicitante',
      'celular_solicitante',
      'creador_por',
      'creado_por',
      'propietario',
      'celular_propietario',
      'arrendatario',
      'celular_arrendatario',
    ];
    $select = [];
    foreach ($candidates as $column) {
      if ($this->column_exists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    if (empty($select)) {
      return [];
    }

    $where = [];
    $args = [];
    if ($this->column_exists($table, '_ID') && ctype_digit($ticketLookup)) {
      $where[] = '`_ID` = ?';
      $args[] = (int) $ticketLookup;
    }
    if ($this->column_exists($table, 'id_ticket')) {
      if (ctype_digit($ticketLookup)) {
        $where[] = 'CAST(`id_ticket` AS UNSIGNED) = ?';
        $args[] = (int) $ticketLookup;
      } else {
        $where[] = "CONVERT(TRIM(COALESCE(`id_ticket`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $args[] = $ticketLookup;
      }
    }
    if (empty($where)) {
      return [];
    }

    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE (" . implode(' OR ', $where) . ') LIMIT 1',
      $args
    );

    return is_array($row) ? $row : [];
  }

  /** @return array{name:string,phone:string,id_empleado:string} */
  private function calendarCitaFuncionarioRow(string $lookup, string $mode): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($table)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => ''];
    }

    $nameColumn = $this->detect_first_existing_column($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    $phoneColumn = $this->detect_first_existing_column($table, ['celular_empleado', 'celular', 'telefono', 'whatsapp', 'phone']);
    $select = [];
    $select[] = $nameColumn !== '' ? "TRIM(COALESCE(`{$nameColumn}`, '')) AS nombre" : "'' AS nombre";
    $select[] = $phoneColumn !== '' ? "TRIM(COALESCE(`{$phoneColumn}`, '')) AS telefono" : "'' AS telefono";
    $select[] = $this->column_exists($table, 'id_empleado') ? "TRIM(COALESCE(`id_empleado`, '')) AS id_empleado" : "'' AS id_empleado";

    $whereColumn = $mode === 'internal' ? '_ID' : 'id_empleado';
    if (!$this->column_exists($table, $whereColumn)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => ''];
    }

    if (ctype_digit($lookup)) {
      $whereSql = "CAST(`{$whereColumn}` AS UNSIGNED) = ?";
      $whereArgs = [(int) $lookup];
    } else {
      $whereSql = "CONVERT(TRIM(COALESCE(`{$whereColumn}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
      $whereArgs = [$lookup];
    }

    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE {$whereSql} LIMIT 1",
      $whereArgs
    );
    if (!is_array($row)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => ''];
    }

    return [
      'name' => trim((string) ($row['nombre'] ?? '')),
      'phone' => trim((string) ($row['telefono'] ?? '')),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
    ];
  }

  /** @return array{name:string,phone:string} */
  private function calendarCitaCreatorContact(): array
  {
    $creator = $this->calendarCitaFuncionarioRow((string) Auth::userId(), 'internal');
    $name = trim((string) ($creator['name'] ?? ''));
    if ($name === '') {
      $name = Auth::user();
    }
    return [
      'name' => $name !== '' ? $name : 'Funcionario de Su Casa',
      'phone' => trim((string) ($creator['phone'] ?? '')),
    ];
  }

  /** @param array<string,mixed> $ticket @return array{name:string,phone:string} */
  private function calendarCitaRequesterContact(array $ticket): array
  {
    $creator = strtolower(trim((string) ($ticket['creador_por'] ?? $ticket['creado_por'] ?? '')));
    $name = trim((string) ($ticket['solicitante'] ?? ''));
    $phone = trim((string) ($ticket['celular_solicitante'] ?? ''));

    if ($phone === '' && strpos($creator, 'propiet') !== false) {
      $phone = trim((string) ($ticket['celular_propietario'] ?? ''));
      $name = trim((string) ($ticket['propietario'] ?? $name));
    }
    if ($phone === '' && strpos($creator, 'arrend') !== false) {
      $phone = trim((string) ($ticket['celular_arrendatario'] ?? ''));
      $name = trim((string) ($ticket['arrendatario'] ?? $name));
    }

    return ['name' => $name !== '' ? $name : 'Cliente', 'phone' => $phone];
  }

  /** @param array{name:string,phone:string} $creator */
  private function calendarCitaCreatorLabel(array $creator): string
  {
    $name = trim((string) ($creator['name'] ?? ''));
    $phone = trim((string) ($creator['phone'] ?? ''));
    if ($name === '') {
      $name = 'la persona que agendo la cita';
    }
    return $phone !== '' ? ($name . ' - ' . $phone) : $name;
  }

  private function calendarCitaDateLabel(string $start): string
  {
    $ts = strtotime($start);
    if ($ts <= 0) {
      return '-';
    }
    return date('d/m/Y', $ts);
  }

  private function calendarCitaTimeRangeLabel(string $start, string $end): string
  {
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $startLabel = $startTs > 0 ? $this->calendarCitaHourLabel($startTs) : '-';
    $endLabel = $endTs > 0 ? $this->calendarCitaHourLabel($endTs) : '';
    return $endLabel !== '' ? ($startLabel . ' a ' . $endLabel) : $startLabel;
  }

  private function calendarCitaHourLabel(int $ts): string
  {
    $label = date('g:i a', $ts);
    return str_replace(['am', 'pm'], ['a. m.', 'p. m.'], $label);
  }

  private function calendarCitaEmployeeMessage(string $name, string $category, string $ticket, string $date, string $time, string $location): string
  {
    return "Hola {$name}, se te ha agendado una cita de {$category}.\n\nTicket: #{$ticket}\nFecha: {$date}\nHora: {$time}\nDireccion: " . ($location !== '' ? $location : '-');
  }

  private function calendarCitaRequesterMessage(string $name, string $category, string $ticket, string $date, string $time, string $location, string $creatorContact): string
  {
    return "Hola {$name}, se te ha agendado una cita de {$category}.\n\nTicket: #{$ticket}\nFecha: {$date}\nHora: {$time}\nLugar: " . ($location !== '' ? $location : '-') . "\n\nSi no puedes atenderla, contacta a {$creatorContact}.";
  }

  /** @param mixed $raw @return string[] */
  private function parse_notify_recipients($raw): array
  {
    $items = is_array($raw) ? $raw : [$raw];
    $allowed = ['solicitante', 'arrendatario', 'propietario', 'empleado', 'admin', 'none'];
    $out = [];
    foreach ($items as $item) {
      $key = preg_replace('/[^a-z_]/', '', strtolower((string) $item));
      if (in_array($key, $allowed, true)) {
        $out[$key] = true;
      }
    }
    return array_keys($out);
  }

  public function ajax_handler_contacts_update()
  {
    $this->verifyCsrf();

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }

    $clean = static function (string $key): string {
      return trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
    };

    $data = [
      'propietario' => $clean('propietario'),
      'indicativo_propietario' => $clean('indicativo_propietario'),
      'correo_propietario' => $clean('correo_propietario'),
      'celular_propietario' => $clean('celular_propietario'),
      'arrendatario' => $clean('arrendatario'),
      'indicativo_arrendatario' => $clean('indicativo_arrendatario'),
      'correo_arrendatario' => $clean('correo_arrendatario'),
      'celular_arrendatario' => $clean('celular_arrendatario'),
    ];

    foreach (['correo_propietario', 'correo_arrendatario'] as $emailKey) {
      if ($data[$emailKey] !== '' && !filter_var($data[$emailKey], FILTER_VALIDATE_EMAIL)) {
        $this->jsonFail('Correo invalido en ' . str_replace('_', ' ', $emailKey) . '.');
      }
    }

    $service = $this->get_seguimiento_service();
    $result = $service->saveContactData($ticketPk, $data);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudieron actualizar los contactos.'));
    }

    $this->jsonOk($result);
  }
}
