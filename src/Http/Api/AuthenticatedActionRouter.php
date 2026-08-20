<?php

declare(strict_types=1);

namespace SCM\Http\Api;

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Http\Controller\GuideApiController;

final class AuthenticatedActionRouter
{
  private SuCasaControlServiciosInmobiliarios $application;

  /** @var array<string,string> */
  private array $routes;
  /** @var array<string,string> */
  private array $guideRoutes;
  private GuideApiController $guideController;

  public function __construct(SuCasaControlServiciosInmobiliarios $application)
  {
    $this->application = $application;
    $this->guideController = new GuideApiController(App::db(), App::csrf());
    $this->routes = [
      SuCasaControlServiciosInmobiliarios::AJAX_ACTION => 'ajax_handler',
      SuCasaControlServiciosInmobiliarios::AJAX_SEGUIMIENTO => 'ajax_handler_seguimiento',
      SuCasaControlServiciosInmobiliarios::AJAX_NOTA => 'ajax_handler_nota',
      SuCasaControlServiciosInmobiliarios::AJAX_TICKET_RESPONSE => 'ajax_handler_ticket_response',
      SuCasaControlServiciosInmobiliarios::AJAX_POSTPONE_TICKET => 'ajax_handler_postpone_ticket',
      SuCasaControlServiciosInmobiliarios::AJAX_STATUS_TICKETS => 'ajax_handler_status_tickets',
      SuCasaControlServiciosInmobiliarios::AJAX_MY_TICKETS => 'ajax_handler_my_tickets',
      SuCasaControlServiciosInmobiliarios::AJAX_COTIZACIONES_MANTENIMIENTO => 'ajax_handler_cotizaciones_mantenimiento',
      SuCasaControlServiciosInmobiliarios::AJAX_DELETE_COTIZACION => 'ajax_handler_delete_cotizacion_mantenimiento',
      SuCasaControlServiciosInmobiliarios::AJAX_ACTIVATE_TICKET => 'ajax_handler_activate_ticket',
      SuCasaControlServiciosInmobiliarios::AJAX_COTIZACION_RESPONSE => 'ajax_handler_cotizacion_response',
      SuCasaControlServiciosInmobiliarios::AJAX_CLOSE_TICKET => 'ajax_handler_close_ticket',
      SuCasaControlServiciosInmobiliarios::AJAX_CONTACTS_UPDATE => 'ajax_handler_contacts_update',
      SuCasaControlServiciosInmobiliarios::AJAX_PREVENTIVAS_PENDIENTES => 'ajax_handler_preventivas_pendientes',
      SuCasaControlServiciosInmobiliarios::AJAX_SERVICIOS_PUBLICOS_PENDIENTES => 'ajax_handler_servicios_publicos_pendientes',
      SuCasaControlServiciosInmobiliarios::AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES => 'ajax_handler_reportes_administrativos_pendientes',
      SuCasaControlServiciosInmobiliarios::AJAX_CONTRATOS_ARRENDAMIENTO => 'ajax_handler_contratos_arrendamiento',
      SuCasaControlServiciosInmobiliarios::AJAX_CONTRATO_RECIBIDO => 'ajax_handler_contrato_recibido',
      SuCasaControlServiciosInmobiliarios::AJAX_CREAR_TICKET_ADMINISTRATIVO => 'ajax_handler_crear_ticket_administrativo',
      SuCasaControlServiciosInmobiliarios::AJAX_APROBAR_REPORTE_ADMINISTRATIVO => 'ajax_handler_aprobar_reporte_administrativo',
      SuCasaControlServiciosInmobiliarios::AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO => 'ajax_handler_desaprobar_reporte_administrativo',
      SuCasaControlServiciosInmobiliarios::AJAX_DAMAGE_MAGNITUDE => 'ajax_handler_damage_magnitude',
      SuCasaControlServiciosInmobiliarios::AJAX_CLASSIFY_MAGNITUDE => 'ajax_handler_classify_magnitude',
      SuCasaControlServiciosInmobiliarios::AJAX_SAVE_CASE_MAGNITUDE => 'ajax_handler_save_case_magnitude',
      SuCasaControlServiciosInmobiliarios::AJAX_SAVE_PROPERTY_LOCATION => 'ajax_handler_save_property_location',
      SuCasaControlServiciosInmobiliarios::AJAX_TRASLADAR_CASO => 'ajax_handler_trasladar_caso',
      SuCasaControlServiciosInmobiliarios::AJAX_ASIGNAR_PQR_PUBLICO => 'ajax_handler_asignar_pqr_publico',
      SuCasaControlServiciosInmobiliarios::AJAX_FILTER_PQR_PUBLICO => 'ajax_handler_filtrar_pqr_publico',
      SuCasaControlServiciosInmobiliarios::AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO => 'ajax_handler_guardar_corresponsable_pqr_publico',
      SuCasaControlServiciosInmobiliarios::AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR => 'ajax_handler_guardar_notif_responsable_pqr',
      SuCasaControlServiciosInmobiliarios::AJAX_DASHBOARD_PERMISSIONS_READ => 'ajax_handler_dashboard_permissions_read',
      SuCasaControlServiciosInmobiliarios::AJAX_DASHBOARD_PERMISSIONS_SAVE => 'ajax_handler_dashboard_permissions_save',
      SuCasaControlServiciosInmobiliarios::AJAX_CALENDAR_CITA_NOTIFY => 'ajax_handler_calendar_cita_notify',
      SuCasaControlServiciosInmobiliarios::AJAX_ADMIN_NOTIFICATIONS_RECIPIENTS => 'ajax_handler_admin_notifications_recipients',
      SuCasaControlServiciosInmobiliarios::AJAX_ADMIN_NOTIFICATIONS_SEND => 'ajax_handler_admin_notifications_send',
      SuCasaControlServiciosInmobiliarios::AJAX_ADMIN_NOTIFICATIONS_IMPORT => 'ajax_handler_admin_notifications_import',
    ];

    $this->guideRoutes = [
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_READ => 'correspondenceRead',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_SAVE => 'correspondenceSave',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_DEL => 'correspondenceDelete',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_READ => 'responseRead',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_SAVE => 'responseSave',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_DEL => 'responseDelete',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_READ => 'articleRead',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_SAVE => 'articleSave',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_DEL => 'articleDelete',
      SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_CATS => 'articleCategories',
    ];

    foreach ([
      SuCasaControlServiciosInmobiliarios::AJAX_ENTREGA,
      SuCasaControlServiciosInmobiliarios::AJAX_PREVENTIVA,
      SuCasaControlServiciosInmobiliarios::AJAX_RECIBO,
      SuCasaControlServiciosInmobiliarios::AJAX_CONTABLE,
      SuCasaControlServiciosInmobiliarios::AJAX_CERTIFICACIONES,
      SuCasaControlServiciosInmobiliarios::AJAX_CONTRACTUAL,
    ] as $genericAction) {
      $this->routes[$genericAction] = 'ajax_handler_generic';
    }
  }

  public function dispatch(string $action): bool
  {
    $guideMethod = $this->guideRoutes[$action] ?? null;
    if ($guideMethod !== null) {
      $this->guideController->{$guideMethod}($_POST);
    }

    $method = $this->routes[$action] ?? null;
    if ($method === null) {
      return false;
    }

    $this->application->{$method}();
    return true;
  }
}
