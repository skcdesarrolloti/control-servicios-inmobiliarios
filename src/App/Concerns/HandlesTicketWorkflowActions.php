<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait HandlesTicketWorkflowActions
{
  public function ajax_handler()
  {
    $this->verifyCsrf();

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
    $result = $service->saveTicketResponse($ticketPk, $respuesta, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $imagenes, $documentos, $estadoCotizacion, $observacionCotizacion, $motivoCotizacion, $financiacionCotizacion);
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
      $this->respond_contratos_arrendamiento();
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
