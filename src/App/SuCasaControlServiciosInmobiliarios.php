<?php

namespace SCM\App;

use SCM\Core\Database;
use SCM\Core\Auth;
use SCM\Support\FuncionarioOptions;

final class SuCasaControlServiciosInmobiliarios
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  use \SCM\App\Concerns\HandlesTicketCompletion;
  use \SCM\App\Concerns\HandlesCorrectiveReviewActions;
  use \SCM\App\Concerns\HandlesPreventiveReviewActions;
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  use \SCM\App\Concerns\HandlesOwnerCredits;
  use \SCM\App\Concerns\HandlesTools;
  use \SCM\App\Concerns\HandlesAdministrativeReports;
  use \SCM\App\Concerns\HandlesMaintenanceOrdersPanel;
  use \SCM\App\Concerns\HandlesLeaseContracts;
  use \SCM\App\Concerns\HandlesPublicPqrActions;
  use \SCM\App\Concerns\HandlesPanelCaseCreation;
  use \SCM\App\Concerns\HandlesAdministrativeNotifications;
  use \SCM\App\Concerns\HandlesCollectionManagement;
  use \SCM\App\Concerns\HandlesCanonInsuranceAudits;
  use \SCM\App\Concerns\HandlesRentIncreaseLetters;
  use \SCM\App\Concerns\HandlesPublicServicesLiquidator;
  use \SCM\App\Concerns\HandlesPropertyHistoryActions;
  use \SCM\App\Concerns\HandlesContractMovements;
  use \SCM\App\Concerns\HandlesContractNotes;
  use \SCM\App\Concerns\HandlesAutologinLinks;
  use \SCM\App\Concerns\HandlesContractOwnership;
  use \SCM\App\Concerns\HandlesMetricsPanels;
  use \SCM\App\Concerns\RendersPublicPqr;
  use \SCM\App\Concerns\RendersDashboard;

  const AJAX_ACTION      = 'scm_filtrar';
  const AJAX_SEGUIMIENTO = 'scm_guardar_seguimiento';
  const AJAX_NOTA        = 'scm_guardar_nota';
  const AJAX_TICKET_RESPONSE = 'scm_responder_ticket';
  const AJAX_POSTPONE_TICKET = 'scm_postergar_ticket';
  const AJAX_STATUS_TICKETS = 'scm_filtrar_tickets_estado';
  const AJAX_MY_TICKETS = 'scm_mis_tickets';
  const AJAX_EXPORT_CASES_EXCEL = 'scm_exportar_casos_excel';
  const AJAX_COTIZACIONES_MANTENIMIENTO = 'scm_cotizaciones_mantenimiento';
  const AJAX_DELETE_COTIZACION = 'scm_eliminar_cotizacion_mantenimiento';
  const AJAX_APPROVE_COTIZACION = 'scm_aprobar_cotizacion_mantenimiento';
  const AJAX_COTIZACION_FORM_CONTEXT = 'scm_cotizacion_mantenimiento_form_context';
  const AJAX_COTIZACION_SAVE = 'scm_guardar_cotizacion_mantenimiento';
  const AJAX_COTIZACION_ORDER_CONTEXT = 'scm_cotizacion_order_context';
  const AJAX_COTIZACION_ORDER_SAVE = 'scm_cotizacion_order_save';
  const AJAX_COTIZACION_BANK_SAVE = 'scm_cotizacion_bank_save';
  const AJAX_COTIZACION_ORDER_RESPONSE = 'scm_cotizacion_order_response';
  const AJAX_COTIZACION_MANTENIMIENTO_PDF = 'scm_cotizacion_mantenimiento_pdf';
  const AJAX_COTIZACION_ORDER_PDF = 'scm_cotizacion_order_pdf';
  const AJAX_SEND_COTIZACION_MANTENIMIENTO = 'scm_enviar_cotizacion_mantenimiento';
  const AJAX_ACTIVATE_TICKET = 'scm_activar_ticket';
  const AJAX_COTIZACION_RESPONSE = 'scm_responder_cotizacion';
  const AJAX_REPAIR_FOLLOWUP_NOTICE = 'scm_seguimiento_reparaciones_cotizacion';
  const AJAX_CLOSE_TICKET = 'scm_cerrar_ticket';
  const AJAX_TICKET_COMPLETION = 'scm_ticket_acta';
  const AJAX_TICKET_COMPLETION_LIST = 'scm_ticket_actas_satisfaccion';
  const AJAX_CORRECTIVE_REVIEW = 'scm_revision_correctiva';
  const AJAX_PREVENTIVE_REVIEW = 'scm_revision_preventiva';
  const AJAX_CONTACTS_UPDATE = 'scm_actualizar_contactos';
  const AJAX_ENTREGA         = 'scm_entrega';
  const AJAX_PREVENTIVA      = 'scm_preventiva';
  const AJAX_RECIBO          = 'scm_recibo';
  const AJAX_CONTABLE        = 'scm_contable';
  const AJAX_CERTIFICACIONES = 'scm_certificaciones';
  const AJAX_CONTRACTUAL     = 'scm_contractual';
  const AJAX_PREVENTIVAS_PENDIENTES = 'scm_preventivas_pendientes';
  const AJAX_SERVICIOS_PUBLICOS_PENDIENTES = 'scm_servicios_publicos_pendientes';
  const AJAX_REVISION_SERVICIOS_PUBLICOS = 'scm_revision_servicios_publicos';
  const AJAX_PUBLIC_SERVICES_LIQUIDATOR_SEARCH = 'scm_liquidador_servicios_publicos_buscar';
  const AJAX_PUBLIC_SERVICES_LIQUIDATOR_CALCULATE = 'scm_liquidador_servicios_publicos_calcular';
  const AJAX_PUBLIC_SERVICES_LIQUIDATOR_GENERATE = 'scm_liquidador_servicios_publicos_generar';
  const AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES = 'scm_reportes_administrativos_pendientes';
  const AJAX_ADMIN_DUE_CALENDAR = 'scm_admin_due_calendar';
  const AJAX_ADMIN_DUE_CASE = 'scm_admin_due_case';
  const AJAX_ADMIN_DUE_SETTINGS_SAVE = 'scm_admin_due_settings_save';
  const AJAX_CONTRATOS_ARRENDAMIENTO = 'scm_contratos_arrendamiento';
  const AJAX_CONTRATO_RECIBIDO = 'scm_contrato_recibido';
  const AJAX_CONTRATO_ULTIMA_PREVENTIVA = 'scm_contrato_ultima_preventiva';
  const AJAX_CREAR_TICKET_ADMINISTRATIVO = 'scm_crear_ticket_administrativo';
  const AJAX_APROBAR_REPORTE_ADMINISTRATIVO = 'scm_aprobar_reporte_administrativo';
  const AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO = 'scm_desaprobar_reporte_administrativo';
  const AJAX_DAMAGE_MAGNITUDE = 'damage_magnitude_tickets';
  const AJAX_CLASSIFY_MAGNITUDE = 'scm_clasificar_magnitud';
  const AJAX_SAVE_CASE_MAGNITUDE = 'scm_guardar_magnitud_caso';
  const AJAX_EDIT_CASE_DATA = 'scm_editar_datos_caso';
  const AJAX_SAVE_PROPERTY_LOCATION = 'scm_guardar_ubicacion_inmueble';
  const AJAX_TRASLADAR_CASO = 'scm_trasladar_caso';
  const AJAX_ASIGNAR_PQR_PUBLICO = 'scm_asignar_pqr_publico';
  const AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO = 'scm_guardar_corresponsable_pqr_publico';
  const AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR = 'scm_guardar_notif_responsable_pqr';
  const AJAX_TICKET_TOPIC_SETTINGS_SAVE = 'scm_ticket_topic_settings_save';
  const AJAX_CASE_CATALOG_SETTINGS_SAVE = 'scm_case_catalog_settings_save';
  const AJAX_FILTER_PQR_PUBLICO = 'scm_filtrar_pqr_publico';
  const AJAX_SESSION_HEARTBEAT = 'scm_session_heartbeat';
  const AJAX_DASHBOARD_PERMISSIONS_READ = 'scm_dashboard_permissions_read';
  const AJAX_DASHBOARD_PERMISSIONS_SAVE = 'scm_dashboard_permissions_save';
  const AJAX_CALENDAR_CITA_NOTIFY = 'scm_calendar_cita_notificar';
  const AJAX_ADMIN_NOTIFICATIONS_RECIPIENTS = 'scm_admin_notifications_recipients';
  const AJAX_ADMIN_NOTIFICATIONS_PANEL = 'scm_admin_notifications_panel';
  const AJAX_ADMIN_NOTIFICATIONS_STATS = 'scm_admin_notifications_stats';
  const AJAX_ADMIN_NOTIFICATIONS_SEND = 'scm_admin_notifications_send';
  const AJAX_ADMIN_NOTIFICATIONS_IMPORT = 'scm_admin_notifications_import';
  const AJAX_ADMIN_NOTIFICATIONS_PAYMENT_RECEIPTS_IMPORT = 'scm_admin_notifications_payment_receipts_import';
  const AJAX_ADMIN_NOTIFICATIONS_QUEUE = 'scm_admin_notifications_queue';
  const AJAX_ADMIN_NOTIFICATIONS_QUEUE_DELETE = 'scm_admin_notifications_queue_delete';
  const AJAX_ADMIN_NOTIFICATIONS_QUEUE_MESSAGE = 'scm_admin_notifications_queue_message';
  const AJAX_ADMIN_NOTIFICATIONS_FILTERED_IDS = 'scm_admin_notifications_filtered_ids';
  const AJAX_ADMIN_NOTIFICATIONS_COMMUNITY_GET = 'scm_admin_notifications_community_get';
  const AJAX_ADMIN_NOTIFICATIONS_COMMUNITY_SAVE = 'scm_admin_notifications_community_save';
  const AJAX_ADMIN_NOTIFICATIONS_COMMUNITY_CONTRACTS = 'scm_admin_notifications_community_contracts';
  const AJAX_ADMIN_NOTIFICATIONS_COMMUNITY_LINK = 'scm_admin_notifications_community_link';
  const AJAX_ADMIN_NOTIFICATIONS_COMMUNITY_CATALOG_ADD = 'scm_admin_notifications_community_catalog_add';
  const AJAX_ADMIN_NOTIFICATIONS_PAYMENT_RECEIPTS_CONFIRM = 'scm_admin_notifications_payment_receipts_confirm';
  const AJAX_ADMIN_NOTIFICATIONS_QUEUE_EXPORT = 'scm_admin_notifications_queue_export';
  const AJAX_ADMIN_NOTIFICATIONS_COLLECTION = 'scm_admin_notifications_collection';
  const AJAX_ADMIN_NOTIFICATIONS_COLLECTION_OPTIONS = 'scm_admin_notifications_collection_options';
  const AJAX_ADMIN_NOTIFICATIONS_COLLECTION_QUEUE = 'scm_admin_notifications_collection_queue';
  const AJAX_ADMIN_NOTIFICATIONS_COLLECTION_LOG = 'scm_admin_notifications_collection_log';
  const AJAX_COLLECTION_PORTFOLIO_IMPORT = 'scm_collection_portfolio_import';
  const AJAX_COLLECTION_PORTFOLIO_ACTION = 'scm_collection_portfolio_action';
  const AJAX_COLLECTION_PORTFOLIO_PDF = 'scm_collection_portfolio_pdf';
  const AJAX_COLLECTION_PORTFOLIO_TIMELINE = 'scm_collection_portfolio_timeline';
  const AJAX_COLLECTION_QUEUE_MESSAGE = 'scm_collection_queue_message';
  const AJAX_INTERNAL_NOTIFICATIONS_SAVE = 'scm_internal_notifications_save';
  const AJAX_INTERNAL_EVENTS_SYNC = 'scm_internal_events_sync';
  const AJAX_PUBLIC_PQR_SETTINGS_READ = 'scm_public_pqr_settings_read';
  const AJAX_INTERNAL_NOTIFICATIONS_READ = 'scm_internal_notifications_read';
  const AJAX_METRICS_EXECUTION = 'scm_metricas_ejecucion_funcionario';
  const AJAX_DASHBOARD_HOME = 'scm_dashboard_inicio';
  const AJAX_PROPERTY_HISTORY_REPORT = 'scm_historial_inmueble_consultar';
  const AJAX_PROPERTY_HISTORY_PDF = 'scm_historial_inmueble_pdf';
  const AJAX_CONTRACT_TERMINATION_REQUESTS = 'scm_solicitudes_terminacion_contrato';
  const AJAX_CONTRACT_TERMINATION_RESPOND = 'scm_responder_terminacion_contrato';
  const AJAX_CONTRACT_REQUEST_DELETE = 'scm_eliminar_solicitud_contractual';
  const AJAX_CONTRACT_REQUEST_REOPEN = 'scm_reabrir_solicitud_contractual';
  const AJAX_CONTRACT_NON_RENEWAL_REQUESTS = 'scm_solicitudes_no_prorroga_contrato';
  const AJAX_CONTRACT_NON_RENEWAL_RESPOND = 'scm_responder_no_prorroga_contrato';
  const AJAX_CONTRACTS_ENDING_MONTHS = 'scm_contratos_por_terminar';
  const AJAX_CONTRACTS_ENDING_CREATE_RETENTION = 'scm_contratos_por_terminar_retencion';
  const AJAX_CONTRACTS_ENDING_IMPORT_PREVIEW = 'scm_contratos_por_terminar_import_preview';
  const AJAX_CONTRACTS_ENDING_IMPORT_APPLY = 'scm_contratos_por_terminar_import_apply';
  const AJAX_CONTRACTS_ENDING_RENEWAL_SAVE = 'scm_contratos_renovacion_guardar';
  const AJAX_CONTRACTS_ENDING_HISTORY = 'scm_contratos_renovacion_historial';
  const AJAX_CONTRACTS_ENDING_HISTORY_MANAGE = 'scm_contratos_historial_gestionar';
  const AJAX_CONTRACTS_ENDING_CASE = 'scm_contratos_caso';
  const AJAX_CONTRACT_RECEIPT_SETTINGS_READ = 'scm_contratos_recibo_configuracion';
  const AJAX_CONTRACT_RECEIPT_SETTINGS_SAVE = 'scm_contratos_recibo_configuracion_guardar';
  const AJAX_DASHBOARD_METRICS = 'scm_dashboard_metricas';
  const AJAX_CONTRACT_MOVEMENTS = 'scm_ocupaciones_desocupaciones';
  const AJAX_CONTRACT_SIMI_AUDIT = 'scm_auditoria_simi_contrato';
  const AJAX_CASE_METRICS = 'scm_metricas_casos';
  const AJAX_QUOTE_METRICS = 'scm_metricas_cotizaciones';
  const AJAX_MAINTENANCE_METRICS = 'scm_metricas_mantenimiento';
  const AJAX_QUOTE_RESPONSE_DATE = 'scm_metricas_cotizacion_fecha_respuesta';
  const AJAX_QUOTE_ASSIGN_PROPERTY = 'scm_metricas_cotizacion_inmueble';
  const AJAX_QUOTE_LEDGER_UPDATE = 'scm_metricas_cotizacion_valores';
  const AJAX_QUOTE_LEDGER = 'scm_metricas_cotizaciones_auxiliar';
  const AJAX_CONTRACT_OWNERS = 'scm_funcionario_contratos';
  const AJAX_CONTRACT_OWNERS_SAVE = 'scm_funcionario_contratos_guardar';
  /** Acciones que no se conceden por defecto a cargos sin permisos configurados. */
  const SENSITIVE_DEFAULT_ACTIONS = ['contract_history_manage', 'contract_owner_change', 'quote_order_delete', 'case_export_excel', 'owner_credit_decide', 'owner_credit_manage', 'owner_credit_configure', 'admin_report_manage', 'admin_report_payments', 'admin_report_accounts', 'market_stats_admin', 'cleaning_internal_view', 'cleaning_configure', 'quote_fee_configure'];
  /** Acciones que existían antes del catálogo ampliado; las demás heredan permisos hasta guardar (applyNewDashboardActionDefaults). */
  const LEGACY_DASHBOARD_ACTION_KEYS = [
    'case_followup', 'case_schedule', 'case_note', 'case_postpone', 'case_respond', 'contract_history_manage', 'case_transfer',
    'case_activate', 'case_close', 'case_edit_magnitude', 'case_edit_data', 'case_edit_executor', 'case_completion_act',
    'corrective_review_manage', 'preventive_review_manage', 'preventive_review_send',
    'quote_manage', 'quote_create', 'quote_edit', 'quote_send', 'quote_respond', 'quote_approve', 'quote_delete',
    'quote_repair_followup', 'quote_order_view', 'quote_order_create', 'quote_order_respond', 'quote_order_delete', 'quote_acta_create',
    'contract_annexes_send', 'contract_desist', 'contract_create', 'contract_owner_change',
  ];
  const AJAX_DASHBOARD_FILTER_OPTIONS = 'scm_dashboard_filter_options';
  const AJAX_CANON_INSURANCE_AUDIT_LIST = 'scm_auditoria_canon_aseguradoras_listar';
  const AJAX_CANON_INSURANCE_AUDIT_IMPORT = 'scm_auditoria_canon_aseguradoras_importar';
  const AJAX_CANON_INSURANCE_AUDIT_OBSERVATION = 'scm_auditoria_canon_aseguradoras_observacion';
  const AJAX_CANON_INSURANCE_AUDIT_REPORT = 'scm_auditoria_canon_aseguradoras_informe';
  const AJAX_CANON_INSURANCE_AUDIT_MANDATES = 'scm_auditoria_canon_aseguradoras_mandatos';
  const AJAX_CANON_INSURANCE_AUDIT_LINK_MANDATE = 'scm_auditoria_canon_aseguradoras_vincular_mandato';
  const AJAX_CANON_INSURANCE_AUDIT_REQUESTS = 'scm_auditoria_canon_aseguradoras_solicitudes';
  const AJAX_CANON_INSURANCE_AUDIT_UPDATE_REQUEST = 'scm_auditoria_canon_aseguradoras_actualizar_solicitud';
  const AJAX_CANON_INSURANCE_AUDIT_UPDATE_PLATFORM_VALUES = 'scm_auditoria_canon_aseguradoras_actualizar_plataforma';
  const AJAX_CANON_INSURANCE_AUDIT_PURGE = 'scm_auditoria_canon_aseguradoras_borrar_pruebas';
  const AJAX_RENT_INCREASE_LETTERS_LIST = 'scm_cartas_aumento_arrendamiento_listar';
  const AJAX_RENT_INCREASE_LETTERS_CREATE = 'scm_cartas_aumento_arrendamiento_crear';
  const AJAX_MAINTENANCE_ORDERS_LIST = 'scm_ordenes_mantenimiento_listar';
  const AJAX_MAINTENANCE_ORDERS_DETAIL = 'scm_ordenes_mantenimiento_detalle';
  const AJAX_MAINTENANCE_ORDERS_DELETE = 'scm_ordenes_mantenimiento_eliminar';
  const AJAX_MAINTENANCE_ORDERS_QUOTES = 'scm_ordenes_mantenimiento_cotizaciones';
  const AJAX_LEASE_CONTRACT_VIEW = 'scm_contrato_arrendamiento_vista';
  const AJAX_LEASE_CONTRACT_SEND = 'scm_contrato_arrendamiento_enviar_anexos';
  const AJAX_LEASE_CONTRACT_DESIST = 'scm_contrato_arrendamiento_desistir';
  const AJAX_LEASE_CONTRACT_CREATE = 'scm_contrato_arrendamiento_crear';
  const AJAX_LEASE_CONTRACT_RESEND_ACTS = 'scm_contrato_arrendamiento_reenviar_actas';
  const AJAX_LEASE_CONTRACT_ANNEXES_OK = 'scm_contrato_arrendamiento_anexos_al_dia';

  // Guía – Correspondencias de Daños
  const AJAX_GUIDE_GCD_READ = 'scm_guide_gcd_read';
  const AJAX_GUIDE_GCD_SAVE = 'scm_guide_gcd_save';
  const AJAX_GUIDE_GCD_DEL  = 'scm_guide_gcd_del';

  // Guía – Banco de Respuestas
  const AJAX_GUIDE_GRT_READ = 'scm_guide_grt_read';
  const AJAX_GUIDE_GRT_SAVE = 'scm_guide_grt_save';
  const AJAX_GUIDE_GRT_DEL  = 'scm_guide_grt_del';

  // Guía – Artículos Código Civil
  const AJAX_GUIDE_GAC_READ = 'scm_guide_gac_read';
  const AJAX_GUIDE_GAC_SAVE = 'scm_guide_gac_save';
  const AJAX_GUIDE_GAC_DEL  = 'scm_guide_gac_del';
  const AJAX_GUIDE_GAC_CATS = 'scm_guide_gac_cats';

  const NONCE_KEY            = 'scm_nonce';

  const DEFAULT_TICKET_URL = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=';
  const DEFAULT_PREVENTIVA_URL = 'revision-preventiva.php?numero=';
  const DEFAULT_CORRECTIVA_URL = 'revision-correctiva.php?numero=';
  const DEFAULT_COTIZACION_URL = 'cotizacion-mantenimiento.php?numero=';
  const DEFAULT_ACTA_URL = 'https://sucasainmobiliaria.com.co/acta-de-satisfaccion/?numero=';
  const DEFAULT_CALENDAR_APP_URL = 'https://calendar-skc.netlify.app';
  const DEFAULT_CALENDAR_API_URL = 'https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=';

  private Database $db;
  private array $tableExistsCache = [];
  private array $columnExistsCache = [];
  private $serviciosInmobiliariosModule = null;
  private $genericTicketsModule = null;
  private $seguimientoService = null;
  private ?\SCM\Support\SchemaInspector $schemaInspector = null;
  private ?\SCM\Support\StoredFileService $storedFiles = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  private static function h(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }

  /** @return array<string,array<string,mixed>> */
  public static function defaultGenericTicketTabDefinitions(): array
  {
    return [
      'entrega' => ['label' => 'Entrega de Inmuebles', 'temas' => ['Entrega', 'Entrega de inmuebles', 'Entrega de inmueble', 'Entregas de inmuebles'], 'prefix' => 'scmeg_'],
      'preventiva' => ['label' => 'Revisiones Preventiva', 'temas' => ['Revision preventiva', 'Revision preventivas', 'Revisiones preventiva', 'Revisiones preventivas'], 'prefix' => 'scmpv_'],
      'recibo' => ['label' => 'Recibo de Inmuebles', 'temas' => ['Recibo de inmuebles', 'Recibo de inmueble', 'Recibos de inmuebles'], 'prefix' => 'scmrc_'],
      'contable' => ['label' => 'Contable y Tributaria', 'temas' => ['Contable y tributaria', 'Contable y tributario', 'Contabilidad tributaria'], 'prefix' => 'scmco_'],
      'certificaciones' => ['label' => 'Certificaciones Tributarias', 'temas' => ['Certificaciones tributarias', 'Certificacion tributaria', 'Certificado tributario', 'Certificados tributarios'], 'prefix' => 'scmcr_'],
      'contractual' => ['label' => 'Contractual', 'temas' => ['Procesos juridicos', 'Proceso juridico', 'Solicitud contractual', 'Solicitudes contractuales', 'Solicitud de servicios publicos', 'Solicitudes de servicios publicos', 'No prorroga de contrato', 'Terminacion de contrato', 'Retencion de contrato', 'Otros servicios'], 'prefix' => 'scmct_'],
    ];
  }

  /** @param array<string,array<string,mixed>> $defaults @return array<string,array<string,mixed>> */
  public static function configuredGenericTicketTabDefinitions(array $defaults = []): array
  {
    $defaults = $defaults !== [] ? $defaults : self::defaultGenericTicketTabDefinitions();
    $raw = [];
    if (class_exists('\SCM\Core\App')) {
      try {
        $stored = \SCM\Core\App::settings()->get('ticket_topic_tabs', []);
        if (is_array($stored)) {
          $raw = $stored;
        }
      } catch (\Throwable $e) {
        $raw = [];
      }
    }

    $out = $defaults;
    foreach ($defaults as $key => $definition) {
      $configured = self::sanitizeTicketTopicList($raw[$key] ?? null);
      if ($configured !== []) {
        $out[$key]['temas'] = $configured;
      } else {
        $out[$key]['temas'] = self::sanitizeTicketTopicList($definition['temas'] ?? []);
      }
    }

    return $out;
  }

  /** @param mixed $raw @return array<int,string> */
  public static function sanitizeTicketTopicList($raw): array
  {
    if (is_string($raw)) {
      $raw = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
    }
    if (!is_array($raw)) {
      return [];
    }

    $out = [];
    $seen = [];
    foreach ($raw as $item) {
      if (is_array($item)) {
        continue;
      }
      $value = trim((string) $item);
      $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
      if ($value === '') {
        continue;
      }
      $key = mb_strtolower($value, 'UTF-8');
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $out[] = $value;
      }
    }

    return $out;
  }

  /** @return array<int,string> */
  private function dashboardPermissionAdminCargos(): array
  {
    return ['11', '12', '13', '14'];
  }

  /** @return array<string,string> */
  private function dashboardPermissionTabs(): array
  {
    // Una pestaña por opción del menú del header. "calendario_actividades", "actividades_contractuales" y
    // "metricas" ya no se configuran: se derivan de sus opciones (dashboardPermissionCompatibilityTabs).
    return [
      'mi_calendario' => 'Mi calendario',
      'calendario_equipo' => 'Calendario equipo',
      'vencimientos' => 'Vencimientos',
      'historial_inmueble' => 'Historial inmueble',
      'abiertos' => 'Casos abiertos',
      'postergados' => 'Casos postergados',
      'cerrados' => 'Casos cerrados',
      'mis_tickets' => 'Mis casos',
      'cotizaciones_mantenimiento' => 'Cotizaciones de Mantenimiento',
      'ordenes_mantenimiento' => 'Órdenes de Mantenimiento',
      'actas_satisfaccion' => 'Actas de Satisfacción',
      'notificaciones' => 'Notificaciones',
      'gestiones_cobro' => 'Gestiones de Cobro',
      'preventivas_pendientes' => 'Revisión Preventiva',
      'contratos_arrendamiento' => 'Contratos de arrendamiento',
      'contratos_por_terminar' => 'Contratos por terminar',
      'solicitudes_terminacion' => 'Solicitudes de terminación de contrato',
      'solicitudes_no_prorroga' => 'Solicitudes de no prórroga de contrato',
      'funcionario_contratos' => 'Funcionario de contratos',
      'servicios_publicos_pendientes' => 'Revisiones de Servicios Públicos',
      'liquidador_servicios_publicos' => 'Liquidador de Servicios',
      'reportes_administrativos_pendientes' => 'Reportes Administrativos',
      'auditoria_canon_aseguradoras' => 'Auditoría Canon y Aseguradoras',
      'cartas_aumento' => 'Cartas de Aumento',
      'metricas_casos' => 'Casos',
      'metricas_cotizaciones' => 'Cotizaciones',
      'metricas_preventivas' => 'Revisión preventiva',
      'metricas_servicios_publicos' => 'Revisiones de servicios públicos',
      'metricas_ocupaciones' => 'Ocupaciones',
      'metricas_desocupaciones' => 'Desocupaciones',
      'metricas_ocupaciones_vs' => 'Ocupaciones vs Desocupaciones',
      'metricas_crecimiento' => 'Crecimiento de la empresa',
      'credito_propietario' => 'Créditos a propietarios',
      'herramienta_pse' => 'Generar PSE',
      'herramienta_mora' => 'Calculadora de mora',
      'herramienta_mercado' => 'Calculadora de mercado',
      'herramienta_aseo' => 'Calculadora de aseo',
    ];
  }

  /** Opciones que, si alguna está permitida, habilitan el panel o la verificación agrupada. */
  private const DERIVED_DASHBOARD_TABS = [
    'calendario_actividades' => ['mi_calendario', 'calendario_equipo', 'vencimientos', 'historial_inmueble'],
    'actividades_contractuales' => ['contratos_por_terminar', 'solicitudes_terminacion', 'solicitudes_no_prorroga'],
    'metricas' => ['metricas_casos', 'metricas_cotizaciones', 'metricas_preventivas', 'metricas_servicios_publicos', 'metricas_ocupaciones', 'metricas_desocupaciones', 'metricas_ocupaciones_vs', 'metricas_crecimiento'],
    // Panel compartido de ocupaciones y desocupaciones (una consulta para sus tres vistas).
    'metricas_contratos' => ['metricas_ocupaciones', 'metricas_desocupaciones', 'metricas_ocupaciones_vs'],
  ];

  private function canManageDashboardPermissions(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  private function canManagePublicPqrSettings(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  private function canManageContractReceiptSettings(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  private function canManageInternalNotificationSettings(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  /** @return array<string,array{label:string,items:array<string,array{label:string,description:string,channel:string}>}> */
  private function internalNotificationActionCatalog(): array
  {
    return [
      'cobranza' => [
        'label' => 'Cobranza',
        'items' => [
          'gestion_cobro' => [
            'label' => 'Gestión de cobro',
            'description' => 'Cuando se registra una gestión desde el módulo de Gestiones de cobro.',
            'channel' => 'Email interno',
          ],
          'cobro_prejuridico' => [
            'label' => 'Cobro prejurídico',
            'description' => 'Cuando se genera y envía la carta prejurídica desde Gestiones de cobro.',
            'channel' => 'Email interno',
          ],
          'contrato_siniestro' => [
            'label' => 'Contrato marcado como siniestro',
            'description' => 'Cuando un contrato se marca manualmente como siniestro desde Gestiones de cobro.',
            'channel' => 'Email interno',
          ],
        ],
      ],
      'gestion_caso' => [
        'label' => 'Gestión del caso',
        'items' => [
          'nuevo_caso_panel' => [
            'label' => 'Crear caso desde el panel',
            'description' => 'Copias del aviso de creación. El responsable asignado siempre recibe correo y, al activar las plantillas de Crear casos, WhatsApp.',
            'channel' => 'Correo y WhatsApp oficial configurable en cola',
          ],
          'respuesta_ticket' => [
            'label' => 'Responder caso',
            'description' => 'Cuando un funcionario responde un caso.',
            'channel' => 'Email interno en cola',
          ],
          'seguimiento_ticket' => [
            'label' => 'Agregar seguimiento',
            'description' => 'Cuando se registra un seguimiento en el caso.',
            'channel' => 'Email interno en cola',
          ],
          'nota_ticket' => [
            'label' => 'Agregar nota',
            'description' => 'Cuando se guarda una nota. Solo avisa a los funcionarios seleccionados; no se comparte con clientes.',
            'channel' => 'Email interno en cola',
          ],
          'trasladar_caso' => [
            'label' => 'Trasladar caso',
            'description' => 'Copias del traslado al seleccionar notificar a administración. Usa estos destinatarios y no los avisos generales.',
            'channel' => 'Email interno en cola',
          ],
          'postergar_ticket' => [
            'label' => 'Postergar caso',
            'description' => 'Copias al postergar un caso y seleccionar notificar a administración.',
            'channel' => 'Email interno en cola',
          ],
          'gestion_caso_admin' => [
            'label' => 'Avisos administrativos generales',
            'description' => 'Destinatarios internos usados cuando una acción del caso solicite notificar a administración y no tenga un evento más específico.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'agenda' => [
        'label' => 'Citas y calendario',
        'items' => [
          'agendar_cita' => [
            'label' => 'Agendar cita',
            'description' => 'Aviso interno al guardar una cita vinculada al historial de un caso. Se sincroniza desde el calendario y el cron del panel.',
            'channel' => 'Email interno en cola',
          ],
          'cita_realizada' => [
            'label' => 'Cita realizada',
            'description' => 'Aviso interno al confirmar como realizada una cita del caso. Se sincroniza desde el calendario y el cron del panel.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'servicios_publicos' => [
        'label' => 'Servicios públicos',
        'items' => [
          'servicios_publicos_critico' => ['label'=>'Revisión crítica · 72 horas','description'=>'Revisión, acta y caso Servicio publico critico con vencimiento a las 72 horas; se agenda en Google si el seleccionado tiene cuenta conectada. También se informa al creador y al arrendatario.','channel'=>'WhatsApp oficial, email en cola y calendario'],
          'acta_servicios_publicos' => [
            'label' => 'Acta de servicios públicos',
            'description' => 'Cuando se genera una revisión nativa de servicios públicos y sus actas.',
            'channel' => 'Email interno',
          ],
          'liquidador_servicios_publicos' => [
            'label' => 'Liquidador de servicios públicos',
            'description' => 'Cuando se generan ordenes de reembolso desde el liquidador de servicios publicos.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'contratos' => [
        'label' => 'Contratos',
        'items' => [
          'terminacion_contrato' => [
            'label' => 'Solicitudes de terminación de contrato',
            'description' => 'Al responder se genera el acta y se cierra el caso. Marca Funcionario configurado en la respuesta y elige correo o WhatsApp. Los destinatarios internos se seleccionan aquí.',
            'channel' => 'Correo; WhatsApp opcional al responder',
          ],
          'no_prorroga_contrato' => [
            'label' => 'Solicitudes de no prórroga de contrato',
            'description' => 'Al responder se genera el acta y se cierra el caso. Marca Funcionario configurado en la respuesta y elige correo o WhatsApp. Los destinatarios internos se seleccionan aquí.',
            'channel' => 'Correo; WhatsApp opcional al responder',
          ],
          'retencion_contrato_ticket' => [
            'label' => 'Caso comercial de retención de contrato',
            'description' => 'Cuando terminación o no prórroga crea un caso comercial de retención. Sus correos y los WhatsApp al solicitante y consultor son independientes del aviso con acta.',
            'channel' => 'Email interno en cola',
          ],
          'contrato_no_salida' => [
            'label' => 'No salida del inmueble',
            'description' => 'Recordatorios programados cuando se reporta que el arrendatario no realizará la salida.',
            'channel' => 'Email interno en cola',
          ],
          'contrato_recibo_automatico' => [
            'label' => 'Caso automático de recibo · 15 días',
            'description' => 'Avisos internos al crear el recibo antes de la fecha fin del contrato.',
            'channel' => 'Email interno en cola',
          ],
          'contrato_recibo_manual' => [
            'label' => 'Caso de recibo creado desde el panel',
            'description' => 'Cuando un funcionario crea un caso con tema Recibo de inmuebles desde Crear nuevo caso o desde Contratos. Incluye el aviso previo de revisión antes de la desocupación del inmueble.',
            'channel' => 'Email interno en cola',
          ],
          'contrato_arrendamiento_creado' => [
            'label' => 'Contrato de arrendamiento creado',
            'description' => 'Cuando se crea un contrato desde un registro "No asignado". Incluye el acta de notificación de ingreso y la carta a la copropiedad si aplica.',
            'channel' => 'Email interno en cola',
          ],
          'contrato_desistido' => [
            'label' => 'Contrato de arrendamiento desistido',
            'description' => 'Cuando se marca un contrato como desistido desde Contratos de arrendamiento, con el motivo y la observación.',
            'channel' => 'Email interno en cola',
          ],
          'anexos_contrato_arrendamiento' => [
            'label' => 'Envío de anexos del contrato de arrendamiento',
            'description' => 'Copia de cada correo enviado al propietario, arrendatario y codeudores con sus anexos desde Contratos de arrendamiento, y un resumen con todas las actas de bienvenida.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'creditos_propietarios' => [
        'label' => 'Créditos a propietarios',
        'items' => [
          'credito_propietario_solicitado' => [
            'label' => 'Solicitud de crédito',
            'description' => 'Cuando una cotización queda con financiación Sí (respuesta del propietario o cambio desde el panel) o se crea un crédito manual. Avisa para revisarlo en el liquidador. El WhatsApp se activa en la configuración de créditos.',
            'channel' => 'Email y WhatsApp en cola',
          ],
          'credito_propietario_pagare_firmado' => [
            'label' => 'Pagaré firmado',
            'description' => 'Cuando un propietario firma desde su enlace el pagaré en blanco y la carta de instrucciones. Se firma una sola vez por persona y desde ahí se pueden aprobar sus créditos.',
            'channel' => 'Email interno en cola',
          ],
          'credito_propietario_decidido' => [
            'label' => 'Crédito aprobado o rechazado',
            'description' => 'Cuando un funcionario aprueba o rechaza un crédito desde el liquidador, con las condiciones y la justificación si se aprobó fuera de política. El WhatsApp se activa en la configuración de créditos.',
            'channel' => 'Email y WhatsApp en cola',
          ],
        ],
      ],
      'cartas_aumento' => [
        'label' => 'Cartas de aumento',
        'items' => [
          'carta_aumento_canon' => [
            'label' => 'Carta de aumento de canon',
            'description' => 'Cuando se genera una carta de aumento de canon desde Actividades administrativas.',
            'channel' => 'Email interno en cola',
          ],
          'carta_aumento_administracion' => [
            'label' => 'Carta de aumento de administración',
            'description' => 'Cuando se genera una carta de aumento de administración desde Actividades administrativas.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'mantenimiento' => [
        'label' => 'Mantenimiento',
        'items' => [
          'acta_enviada' => [
            'label' => 'Acta enviada o reenviada a firma',
            'description' => 'Cuando se envía, reenvía o vuelve a enviar tras editar un acta de solución o satisfacción para firma. Es un aviso aparte: no incluye el enlace personal del firmante.',
            'channel' => 'Email interno en cola',
          ],
          'acta_firmada' => [
            'label' => 'Acta firmada',
            'description' => 'Al firmar un acta de solución o satisfacción se avisa al creador original y a los funcionarios seleccionados aquí.',
            'channel' => 'Email y WhatsApp en cola',
          ],
          'revision_correctiva_creada' => [
            'label' => 'Revisión correctiva creada',
            'description' => 'Cuando se registra una revisión correctiva desde el panel.',
            'channel' => 'Email interno en cola',
          ],
          'ticket_administrativo_creado' => [
            'label' => 'Caso administrativo creado',
            'description' => 'Cuando se crea un caso administrativo nativo y se marca notificación a administración.',
            'channel' => 'Email interno en cola',
          ],
          'orden_mantenimiento_creada' => [
            'label' => 'Orden de mantenimiento creada',
            'description' => 'Cuando se crea una orden desde una cotización aprobada.',
            'channel' => 'Email interno en cola',
          ],
          'respuesta_cotizacion_mantenimiento' => [
            'label' => 'Respuesta de cotización',
            'description' => 'Al responder una cotización se avisa al creador y a los funcionarios seleccionados aquí.',
            'channel' => 'Email y WhatsApp en cola',
          ],
          'respuesta_orden_mantenimiento' => [
            'label' => 'Respuesta de orden de mantenimiento',
            'description' => 'Cuando una orden de mantenimiento se aprueba o se desaprueba.',
            'channel' => 'Email interno en cola',
          ],
          'cotizacion_mantenimiento_guardada' => [
            'label' => 'Cotización de mantenimiento guardada',
            'description' => 'Cuando se crea, edita o agrega una nota de cotización de mantenimiento desde el panel.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
      'preventivas' => [
        'label' => 'Preventivas',
        'items' => [
          'crear_ticket_preventiva' => [
            'label' => 'Crear caso preventivo',
            'description' => 'Cuando se crea un caso administrativo de revisión preventiva.',
            'channel' => 'Email interno en cola',
          ],
          'comunicacion_no_acceso' => [
            'label' => 'Comunicación por no autorización',
            'description' => 'Recibe CC del correo con la comunicación por no acceso. Si no hay correo del arrendatario, el aviso es solo interno.',
            'channel' => 'Email interno en cola',
          ],
          'revision_preventiva_creada' => [
            'label' => 'Revisión preventiva creada',
            'description' => 'Cuando se registra una revisión preventiva desde el panel (reemplaza los correos fijos del formulario de WordPress).',
            'channel' => 'Email interno en cola',
          ],
          'revision_preventiva_enviada' => [
            'label' => 'Revisión preventiva enviada',
            'description' => 'Cuando se envía el informe de la revisión preventiva al propietario y al arrendatario (reemplaza los correos fijos del formulario "Enviar revisión preventiva").',
            'channel' => 'Email interno en cola',
          ],
          'acta_preventiva' => [
            'label' => 'Acta preventiva',
            'description' => 'CC de los ofrecimientos al crear el caso y marcar administración; aviso interno al enviar la revisión preventiva realizada. Las revisiones externas se sincronizan por el panel y su cron.',
            'channel' => 'Email interno en cola',
          ],
        ],
      ],
    ];
  }

  /** @return array<string,array<int,string>> */
  private function internalNotificationSettingsConfig(): array
  {
    $raw = \SCM\Core\App::settings()->get('internal_admin_notifications', []);
    return $this->sanitizeInternalNotificationSettings(is_array($raw) ? $raw : []);
  }

  /** @param array<mixed> $raw @return array<string,array<int,string>> */
  private function sanitizeInternalNotificationSettings(array $raw): array
  {
    $validActions = [];
    foreach ($this->internalNotificationActionCatalog() as $group) {
      foreach ((array) ($group['items'] ?? []) as $actionKey => $_item) {
        $validActions[(string) $actionKey] = true;
      }
    }

    $validFuncionarioIds = [];
    foreach ($this->internalNotificationFuncionarioOptions() as $funcionario) {
      $id = trim((string) ($funcionario['id'] ?? ''));
      if ($id !== '') {
        $validFuncionarioIds[$id] = true;
      }
    }
    $allActiveIds = array_fill_keys(array_column($this->internalNotificationFuncionarioOptions(true), 'id'), true);

    $out = [];
    foreach ($raw as $action => $ids) {
      $actionKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $action)) ?: '';
      if ($actionKey === '' || !isset($validActions[$actionKey])) {
        continue;
      }
      $selected = [];
      foreach ((array) $ids as $id) {
        $idKey = trim((string) ((int) $id));
        $allowed = in_array($actionKey, ['servicios_publicos_critico','servicios_publicos_pago_reportado','servicios_publicos_critico_calendario','contrato_recibo_automatico','contrato_recibo_manual','contrato_no_salida','nuevo_caso_panel'], true) ? $allActiveIds : $validFuncionarioIds;
        if ($idKey !== '' && $idKey !== '0' && isset($allowed[$idKey])) {
          $selected[$idKey] = $idKey;
        }
      }
      $out[$actionKey] = array_values($selected);
    }
    ksort($out);
    return $out;
  }

  /** @return array<int,array{id:string,label:string,name:string,email:string,phone:string,cargo:string}> */
  private function internalNotificationFuncionarioOptions(bool $allActive = false): array
  {
    $out = [];
    $rows = \SCM\Support\FuncionarioOptions::panelFuncionarios(
      $this->db,
      new \SCM\Support\SchemaInspector($this->db),
      'primary',
      $allActive ? [] : null
    );
    foreach ($rows as $row) {
      $id = (string) ((int) ($row['id'] ?? 0));
      if ($id === '0') {
        continue;
      }
      $name = trim((string) ($row['name'] ?? ''));
      $email = trim((string) ($row['email'] ?? ''));
      $phone = trim((string) ($row['phone'] ?? ''));
      $cargo = trim((string) ($row['cargo'] ?? ''));
      $employee = trim((string) ($row['employee_id'] ?? ''));
      $parts = [$name !== '' ? $name : ('Funcionario #' . $id), $cargo];
      if ($email !== '') {
        $parts[] = $email;
      }
      if ($employee !== '') {
        $parts[] = 'Empleado ' . $employee;
      }
      $out[] = [
        'id' => $id,
        'label' => implode(' · ', $parts),
        'name' => $name !== '' ? $name : ('Funcionario #' . $id),
        'email' => $email,
        'phone' => $phone,
        'cargo' => $cargo,
      ];
    }

    return $out;
  }

  /** @return int[] */
  private function internalNotificationRecipientsForAction(string $action): array
  {
    $actionKey = preg_replace('/[^a-z0-9_]/', '', strtolower($action)) ?: '';
    if ($actionKey === '') {
      return [];
    }
    $settings = $this->internalNotificationSettingsConfig();
    return array_values(array_unique(array_filter(array_map('intval', $settings[$actionKey] ?? []), static fn(int $id): bool => $id > 0)));
  }

  private function canManageCanonInsuranceAuditCleanup(): bool
  {
    return in_array(Auth::userCargo(), ['11', '13', '14'], true);
  }

  /** @return array<string,array<int,string>> */
  private function dashboardPermissionsConfig(): array
  {
    $raw = \SCM\Core\App::settings()->get('dashboard_tab_permissions', []);
    $raw = is_array($raw) ? $raw : [];
    // Permisos guardados antes de separar las opciones del menú: se traducen a lo que el cargo veía.
    if (!is_array(\SCM\Core\App::settings()->get('dashboard_tab_permissions_known_keys', null))) {
      foreach ($raw as $cargo => $tabs) {
        $raw[$cargo] = $this->expandLegacyDashboardTabs((array) $tabs);
      }
    }
    // "Ocupaciones y desocupaciones" se guardó un tiempo como una sola opción; ahora son tres.
    foreach ($raw as $cargo => $tabs) {
      if (in_array('metricas_contratos', (array) $tabs, true)) {
        $raw[$cargo] = array_merge((array) $tabs, self::DERIVED_DASHBOARD_TABS['metricas_contratos']);
      }
      // Créditos a propietarios se guardó primero como "metricas_creditos".
      if (in_array('metricas_creditos', (array) $raw[$cargo], true)) {
        $raw[$cargo] = array_merge((array) $raw[$cargo], ['credito_propietario']);
      }
    }
    return $this->sanitizeDashboardPermissions($raw);
  }

  /**
   * Traduce permisos de pestañas guardados con el formato anterior: aplica las reglas que entonces se
   * aplicaban siempre y reparte las pestañas agrupadas en las opciones del menú que habilitaban.
   * @param array<int,mixed> $tabs @return array<int,string>
   */
  private function expandLegacyDashboardTabs(array $tabs): array
  {
    $selected = [];
    foreach ($tabs as $tab) {
      $tabKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $tab)) ?: '';
      if ($tabKey !== '') $selected[$tabKey] = $tabKey;
    }
    if (!isset($selected['cartas_aumento'])) {
      foreach (['contratos_arrendamiento', 'auditoria_canon_aseguradoras', 'reportes_administrativos_pendientes', 'notificaciones'] as $relatedTab) {
        if (isset($selected[$relatedTab])) {
          $selected['cartas_aumento'] = 'cartas_aumento';
          break;
        }
      }
    }
    if (isset($selected['cotizaciones_mantenimiento'])) $selected['ordenes_mantenimiento'] = 'ordenes_mantenimiento';
    if (isset($selected['servicios_publicos_pendientes'])) $selected['liquidador_servicios_publicos'] = 'liquidador_servicios_publicos';
    // Antes todos los cargos tenían Inicio y las solicitudes contractuales (calendario_actividades se concedía siempre).
    foreach (array_merge(self::DERIVED_DASHBOARD_TABS['calendario_actividades'], self::DERIVED_DASHBOARD_TABS['actividades_contractuales']) as $tabKey) {
      $selected[$tabKey] = $tabKey;
    }
    if (isset($selected['contratos_arrendamiento'])) $selected['funcionario_contratos'] = 'funcionario_contratos';
    if (isset($selected['metricas'])) {
      foreach (self::DERIVED_DASHBOARD_TABS['metricas'] as $tabKey) $selected[$tabKey] = $tabKey;
    }
    unset($selected['calendario_actividades'], $selected['metricas']);
    return array_values($selected);
  }

  /** @return array<string,array{label:string,items:array<string,string>}> */
  private function dashboardActionPermissionCatalog(): array
  {
    return [
      'casos' => [
        'label' => 'Acciones del caso',
        'items' => [
          'case_followup' => 'Agregar seguimiento',
          'case_schedule' => 'Agendar cita del caso',
          'case_note' => 'Agregar nota',
          'case_postpone' => 'Postergar caso',
          'case_respond' => 'Responder caso',
          'contract_history_manage' => 'Editar y anular historial contractual',
          'case_transfer' => 'Trasladar caso',
          'case_activate' => 'Activar caso',
          'case_close' => 'Cerrar caso',
          'case_edit_magnitude' => 'Editar magnitud del caso',
          'case_edit_data' => 'Editar datos del caso (asunto, tema, prioridad, departamento)',
          'case_edit_executor' => 'Editar quién ejecuta el caso (Ejecutado por)',
          'case_completion_act' => 'Acta de solución y firma',
          'act_resend' => 'Reenviar acta para firma',
          'act_cancel' => 'Anular acta de satisfacción',
          'corrective_review_manage' => 'Gestionar revisión correctiva (casos de mantenimiento)',
          'preventive_review_manage' => 'Gestionar revisión preventiva (casos preventivos)',
          'preventive_review_send' => 'Enviar revisión preventiva al propietario y arrendatario',
          'case_contacts_edit' => 'Editar datos de contacto de arrendatario y propietario',
          'case_magnitude_calculate' => 'Calcular magnitud del daño (botón de los filtros)',
          'case_export_excel' => 'Exportar casos a Excel',
        ],
      ],
      'cotizaciones' => [
        'label' => 'Cotizaciones y órdenes de mantenimiento',
        'items' => [
          'quote_manage' => 'Gestionar cotizaciones del caso',
          'quote_create' => 'Añadir cotización',
          'quote_edit' => 'Editar cotización',
          'quote_financing' => 'Cambiar financiamiento de la cotización',
          'quote_send' => 'Enviar cotización',
          'quote_fee_configure' => 'Configurar honorarios de cotización (% de administración sugerido)',
          'quote_respond' => 'Responder cotización',
          'quote_approve' => 'Marcar cotización como aprobada',
          'quote_delete' => 'Eliminar/desaprobar cotización',
          'quote_repair_followup' => 'Seguimiento de reparaciones',
          'quote_order_view' => 'Ver órdenes de cotización',
          'quote_order_create' => 'Añadir orden de mantenimiento',
          'quote_order_edit' => 'Editar orden de mantenimiento',
          'quote_order_respond' => 'Responder orden de mantenimiento',
          'quote_order_delete' => 'Eliminar orden de mantenimiento',
          'quote_acta_create' => 'Crear acta de cotización',
        ],
      ],
      'administrativas' => [
        'label' => 'Actividades administrativas',
        'items' => [
          'admin_case_create' => 'Crear caso administrativo o preventivo (contratos y vencimientos)',
          'admin_report_review' => 'Aprobar o desaprobar reportes administrativos',
          'admin_report_pending_view' => 'Reportes administrativos: ver la pestaña Por aprobar',
          'admin_report_own' => 'Reportes administrativos: ver mis reportes y crear mis cuentas de cobro',
          'admin_report_manage' => 'Reportes administrativos: crear reportes desde un caso, editar valores y ver reportes de otros funcionarios',
          'admin_report_payments' => 'Reportes administrativos: consolidado de pagos (marcar reportes como pagados)',
          'admin_report_accounts' => 'Reportes administrativos: ver todas las cuentas de cobro y marcarlas como pagadas',
          'public_services_review_save' => 'Guardar revisión de servicios públicos',
          'public_services_liquidation_generate' => 'Generar liquidación de servicios públicos',
          'rent_increase_letter_create' => 'Generar cartas de aumento',
        ],
      ],
      'creditos' => [
        'label' => 'Créditos a propietarios',
        'items' => [
          'owner_credit_decide' => 'Aprobar o rechazar créditos (liquidador)',
          'owner_credit_manage' => 'Crear créditos manuales, marcarlos como pagados o anularlos',
          'owner_credit_configure' => 'Configurar variables y avisos de créditos',
        ],
      ],
      'herramientas' => [
        'label' => 'Herramientas',
        'items' => [
          'market_stats_admin' => 'Calculadora de mercado: estadística completa y tabla de control de inmuebles',
          'cleaning_internal_view' => 'Calculadora de aseo: ver costos, márgenes, utilidades y PDF internos (inmobiliaria y funcionario)',
          'cleaning_configure' => 'Calculadora de aseo: configurar salario, márgenes, insumos, transporte y tramos',
        ],
      ],
      'cartera' => [
        'label' => 'Actividades de cartera',
        'items' => [
          'collection_import' => 'Importar cartera',
          'collection_action' => 'Registrar gestiones de cobro',
          'canon_audit_import' => 'Importar archivos de auditoría de canon y aseguradoras',
          'canon_audit_edit' => 'Registrar observaciones, solicitudes y mandatos de auditoría',
        ],
      ],
      'notificaciones' => [
        'label' => 'Notificaciones',
        'items' => [
          'notifications_send' => 'Enviar notificaciones',
          'notifications_import' => 'Importar bases y recibos de pago',
          'notifications_queue_delete' => 'Eliminar mensajes de la cola',
          'notifications_community_edit' => 'Editar comunidades y catálogo',
        ],
      ],
      'contratos' => [
        'label' => 'Contratos de arrendamiento',
        'items' => [
          'contract_annexes_send' => 'Enviar anexos del contrato de arrendamiento',
          'contract_desist' => 'Marcar contrato de arrendamiento como desistido',
          'contract_create' => 'Crear contrato de arrendamiento (contratos No asignado)',
          'contract_owner_change' => 'Cambiar funcionario responsable del contrato',
        ],
      ],
    ];
  }

  /** @return array<int,string> */
  private function dashboardActionPermissionKeys(): array
  {
    $keys = [];
    foreach ($this->dashboardActionPermissionCatalog() as $group) {
      foreach (array_keys((array) ($group['items'] ?? [])) as $key) {
        $keys[] = (string) $key;
      }
    }
    return $keys;
  }

  /** @return array<string,array<int,string>> */
  private function dashboardActionPermissionsConfig(): array
  {
    $raw = \SCM\Core\App::settings()->get('dashboard_action_permissions', []);
    $permissions = $this->sanitizeDashboardActionPermissions(is_array($raw) ? $raw : []);
    if (!\SCM\Core\App::settings()->get('contract_history_permissions_configured', false)) {
      $permissions['11'] = array_values(array_unique(array_merge($permissions['11'] ?? $this->dashboardActionPermissionKeys(), ['contract_history_manage'])));
    }
    // Mientras no se guarden los permisos con la nueva opción, la preventiva hereda la correctiva
    // para no ocultar el botón a cargos que ya la gestionaban con el formulario de WordPress.
    if (!\SCM\Core\App::settings()->get('preventive_review_permissions_configured', false)) {
      foreach ($permissions as $cargo => $actions) {
        if (in_array('corrective_review_manage', $actions, true) && !in_array('preventive_review_manage', $actions, true)) {
          $permissions[$cargo][] = 'preventive_review_manage';
        }
      }
    }
    // El envío de anexos reemplaza el formulario de WordPress: hasta guardar permisos lo conservan todos los cargos.
    if (!\SCM\Core\App::settings()->get('contract_annexes_permissions_configured', false)) {
      foreach ($permissions as $cargo => $actions) {
        $permissions[$cargo] = array_values(array_unique(array_merge($actions, ['contract_annexes_send'])));
      }
    }
    // Desistir y crear contratos llegaron después; los conservan todos los cargos hasta que se guarden con estas opciones.
    if (!\SCM\Core\App::settings()->get('contract_lifecycle_permissions_configured', false)) {
      foreach ($permissions as $cargo => $actions) {
        $permissions[$cargo] = array_values(array_unique(array_merge($actions, ['contract_desist', 'contract_create'])));
      }
    }
    // "Editar ejecutado" se separó de "Editar datos del caso": hasta guardar permisos lo hereda de ese permiso.
    if (!\SCM\Core\App::settings()->get('case_executor_permissions_configured', false)) {
      foreach ($permissions as $cargo => $actions) {
        if (in_array('case_edit_data', $actions, true) && !in_array('case_edit_executor', $actions, true)) {
          $permissions[$cargo][] = 'case_edit_executor';
        }
      }
    }
    // Cambiar el responsable de un contrato es sensible: hasta que se configure, solo los cargos administradores.
    if (!\SCM\Core\App::settings()->get('contract_owner_permissions_configured', false)) {
      foreach ($this->dashboardPermissionAdminCargos() as $cargo) {
        $base = $permissions[$cargo] ?? array_values(array_diff($this->dashboardActionPermissionKeys(), self::SENSITIVE_DEFAULT_ACTIONS));
        $permissions[$cargo] = array_values(array_unique(array_merge($base, ['contract_owner_change'])));
      }
    }
    return $this->applyNewDashboardActionDefaults($permissions);
  }

  /**
   * Acciones agregadas al catálogo después de la última vez que se guardaron los permisos: cada cargo
   * conserva lo que podía hacer antes (heredando de la acción de la que se separó o, si antes solo se
   * exigía la pestaña, quedando permitida). Al guardar permisos se fija la lista y deja de heredarse.
   * @param array<string,array<int,string>> $permissions @return array<string,array<int,string>>
   */
  private function applyNewDashboardActionDefaults(array $permissions): array
  {
    $known = \SCM\Core\App::settings()->get('dashboard_action_permissions_known_keys', null);
    $known = is_array($known) ? array_map('strval', $known) : self::LEGACY_DASHBOARD_ACTION_KEYS;
    $newKeys = array_values(array_diff($this->dashboardActionPermissionKeys(), $known));
    if (!$newKeys) {
      return $permissions;
    }
    $inherits = [
      'quote_financing' => ['quote_edit'],
      'quote_order_edit' => ['quote_order_create'],
      'act_resend' => ['case_completion_act', 'quote_acta_create'],
      'act_cancel' => ['case_completion_act', 'quote_acta_create'],
    ];
    $adminCargos = $this->dashboardPermissionAdminCargos();
    foreach ($permissions as $cargo => $actions) {
      foreach ($newKeys as $key) {
        if (in_array($key, $actions, true)) continue;
        if (in_array($key, self::SENSITIVE_DEFAULT_ACTIONS, true)) {
          $grant = in_array((string) $cargo, $adminCargos, true);
        } elseif (isset($inherits[$key])) {
          $grant = (bool) array_intersect($inherits[$key], $actions);
        } else {
          $grant = true;
        }
        if ($grant) $permissions[$cargo][] = $key;
      }
    }
    // Cargos administradores sin configurar: conservan las acciones sensibles nuevas (antes solo ellos las tenían).
    foreach ($adminCargos as $cargo) {
      if (array_key_exists($cargo, $permissions)) continue;
      $sensitiveNew = array_values(array_intersect($newKeys, self::SENSITIVE_DEFAULT_ACTIONS));
      if ($sensitiveNew) {
        $permissions[$cargo] = array_values(array_unique(array_merge(array_values(array_diff($this->dashboardActionPermissionKeys(), self::SENSITIVE_DEFAULT_ACTIONS)), $sensitiveNew)));
      }
    }
    return $permissions;
  }

  /** @param array<mixed> $raw @return array<string,array<int,string>> */
  private function sanitizeDashboardActionPermissions(array $raw): array
  {
    $validActions = $this->dashboardActionPermissionKeys();
    $out = [];
    foreach ($raw as $cargo => $actions) {
      $cargoKey = trim((string) $cargo);
      if ($cargoKey === '') {
        continue;
      }
      $selected = [];
      foreach ((array) $actions as $action) {
        $actionKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $action)) ?: '';
        if ($actionKey !== '' && in_array($actionKey, $validActions, true)) {
          $selected[$actionKey] = $actionKey;
        }
      }
      $out[$cargoKey] = array_values($selected);
    }
    ksort($out);
    return $out;
  }

  /** @return array<int,string> */
  private function adminDuePopupCargoIdsConfig(): array
  {
    $raw = \SCM\Core\App::settings()->get('admin_due_popup_cargo_ids', null);
    if ($raw === null) {
      return $this->dashboardPermissionAdminCargos();
    }
    return $this->sanitizeAdminDuePopupCargoIds($raw);
  }

  /** @param mixed $raw @return array<int,string> */
  private function sanitizeAdminDuePopupCargoIds($raw): array
  {
    $validCargoIds = [];
    foreach ($this->getDashboardCargoOptions() as $cargo) {
      $id = trim((string) ($cargo['id'] ?? ''));
      if ($id !== '') {
        $validCargoIds[$id] = true;
      }
    }

    $selected = [];
    foreach (FuncionarioOptions::sanitizeCargoIds($raw) as $cargoId) {
      if (isset($validCargoIds[$cargoId])) {
        $selected[$cargoId] = $cargoId;
      }
    }

    return array_values($selected);
  }

  private function shouldShowAdminDuePopup(): bool
  {
    $cargo = trim((string) Auth::userCargo());
    if ($cargo === '' || !in_array($cargo, $this->adminDuePopupCargoIdsConfig(), true)) {
      return false;
    }
    return $this->canAccessDashboardTab('cotizaciones_mantenimiento') || $this->canAccessDashboardTab('preventivas_pendientes');
  }

  /** @param mixed $raw @return array<int,string> */
  private function sanitizeDashboardFuncionarioCargoIds($raw): array
  {
    $validCargoIds = [];
    foreach ($this->getDashboardCargoOptions() as $cargo) {
      $id = trim((string) ($cargo['id'] ?? ''));
      if ($id !== '') {
        $validCargoIds[$id] = true;
      }
    }

    $selected = [];
    foreach (FuncionarioOptions::sanitizeCargoIds($raw) as $cargoId) {
      if (isset($validCargoIds[$cargoId])) {
        $selected[$cargoId] = $cargoId;
      }
    }

    return $selected !== [] ? array_values($selected) : FuncionarioOptions::defaultPanelCargoIds();
  }

  /** @param array<mixed> $raw @return array<string,array<int,string>> */
  private function sanitizeDashboardPermissions(array $raw): array
  {
    $validTabs = array_keys($this->dashboardPermissionTabs());
    $out = [];
    foreach ($raw as $cargo => $tabs) {
      $cargoKey = trim((string) $cargo);
      if ($cargoKey === '') {
        continue;
      }
      $selected = [];
      foreach ((array) $tabs as $tab) {
        $tabKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $tab)) ?: '';
        if ($tabKey !== '' && in_array($tabKey, $validTabs, true)) {
          $selected[$tabKey] = $tabKey;
        }
      }
      $out[$cargoKey] = array_values($selected);
    }
    ksort($out);
    return $out;
  }

  /** @return array<int,string> */
  public function currentAllowedTabs(): array
  {
    return $this->currentDashboardAllowedTabs();
  }

  public function userCanAccessTab(string $tab): bool
  {
    return $this->canAccessDashboardTab($tab);
  }

  /** @return array<int,string> */
  private function currentDashboardAllowedTabs(): array
  {
    $cargo = Auth::userCargo();
    $permissions = $this->dashboardPermissionsConfig();
    $allTabs = array_keys($this->dashboardPermissionTabs());
    if ($cargo === '' || !array_key_exists($cargo, $permissions)) {
      return $this->dashboardPermissionCompatibilityTabs($allTabs);
    }
    $allowed = !empty($permissions[$cargo]) ? $permissions[$cargo] : ['mis_tickets'];
    return $this->dashboardPermissionCompatibilityTabs($allowed);
  }

  private function canAccessDashboardTab(string $tab): bool
  {
    $tab = preg_replace('/[^a-z0-9_]/', '', strtolower($tab)) ?: '';
    return $tab !== '' && in_array($tab, $this->currentDashboardAllowedTabs(), true);
  }

  /** @param array<int,string> $allowed @return array<int,string> */
  private function dashboardPermissionCompatibilityTabs(array $allowed): array
  {
    $selected = [];
    foreach ($allowed as $tab) {
      $tabKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $tab)) ?: '';
      if ($tabKey !== '') {
        $selected[$tabKey] = $tabKey;
      }
    }
    // Paneles agrupados (Inicio, Actividades contractuales, Métricas): se habilitan si alguna de sus opciones lo está.
    foreach (self::DERIVED_DASHBOARD_TABS as $derivedTab => $sourceTabs) {
      foreach ($sourceTabs as $sourceTab) {
        if (isset($selected[$sourceTab])) {
          $selected[$derivedTab] = $derivedTab;
          break;
        }
      }
    }
    return array_values($selected);
  }

  /** @return array<int,string> */
  private function currentDashboardAllowedActions(): array
  {
    $cargo = Auth::userCargo();
    $permissions = $this->dashboardActionPermissionsConfig();
    $allActions = $this->dashboardActionPermissionKeys();
    if ($cargo === '' || !array_key_exists($cargo, $permissions)) {
      return array_values(array_diff($allActions, self::SENSITIVE_DEFAULT_ACTIONS));
    }
    return !empty($permissions[$cargo]) ? $permissions[$cargo] : [];
  }

  /** Permiso para abrir una acción de cotización desde su página propia (cotizacion.php). */
  public function canOpenQuoteFunction(string $pageAction): bool
  {
    $map = ['crear' => 'quote_create', 'editar' => 'quote_edit', 'enviar' => 'quote_send', 'orden' => 'quote_order_create'];
    return isset($map[$pageAction]) && $this->canUseDashboardAction($map[$pageAction]);
  }

  private function canUseDashboardAction(string $action): bool
  {
    $action = preg_replace('/[^a-z0-9_]/', '', strtolower($action)) ?: '';
    return $action !== '' && in_array($action, $this->currentDashboardAllowedActions(), true);
  }

  /** Corta la petición si el cargo no tiene la acción del catálogo de permisos. */
  private function requireDashboardAction(string $action, string $message = 'Tu cargo no tiene permiso para esta acción.'): void
  {
    if (!$this->canUseDashboardAction($action)) {
      $this->jsonFail($message);
    }
  }

  /** @return array<int,array<string,string>> */
  private function getDashboardCargoOptions(): array
  {
    $cargos = [];
    foreach ($this->dashboardPermissionAdminCargos() as $cargo) {
      $cargos[$cargo] = ['id' => $cargo, 'label' => 'Cargo ' . $cargo, 'name' => 'Cargo ' . $cargo, 'total' => ''];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    if ($this->table_exists($table) && $this->column_exists($table, 'id_cargo')) {
      $cargoTable = $this->db->table('jet_cct_cargos');
      $hasCargoNames = $this->table_exists($cargoTable)
        && $this->column_exists($cargoTable, '_ID')
        && $this->column_exists($cargoTable, 'nombre_cargo');
      $nameSelect = $hasCargoNames ? "TRIM(COALESCE(c.`nombre_cargo`, ''))" : "''";
      $joinSql = $hasCargoNames
        ? " LEFT JOIN `{$cargoTable}` c ON TRIM(COALESCE(f.`id_cargo`, '')) = CAST(c.`_ID` AS CHAR)"
        : '';
      $rows = $this->db->getResults(
        "SELECT TRIM(COALESCE(f.`id_cargo`, '')) AS id_cargo,
                {$nameSelect} AS nombre_cargo,
                COUNT(*) AS total
           FROM `{$table}` f
           {$joinSql}
          WHERE TRIM(COALESCE(f.`id_cargo`, '')) <> ''
          GROUP BY TRIM(COALESCE(f.`id_cargo`, '')), {$nameSelect}
          ORDER BY CAST(TRIM(COALESCE(f.`id_cargo`, '')) AS UNSIGNED), TRIM(COALESCE(f.`id_cargo`, ''))"
      );
      foreach ($rows as $row) {
        $cargo = trim((string) ($row['id_cargo'] ?? ''));
        if ($cargo === '') {
          continue;
        }
        $total = (int) ($row['total'] ?? 0);
        $name = trim((string) ($row['nombre_cargo'] ?? ''));
        $displayName = $name !== '' ? $name : 'Cargo ' . $cargo;
        $cargos[$cargo] = [
          'id' => $cargo,
          'name' => $displayName,
          'label' => $displayName . ($total > 0 ? ' (' . $total . ')' : ''),
          'total' => (string) $total,
        ];
      }
    }
    ksort($cargos, SORT_NATURAL);
    return array_values($cargos);
  }

  private static function sanitizeUrl(string $url): string
  {
    return filter_var($url, FILTER_SANITIZE_URL) ?: $url;
  }

  public static function defaultPreventiveReviewUrl(): string
  {
    $base = defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') : '';
    return ($base !== '' ? $base . '/' : '') . self::DEFAULT_PREVENTIVA_URL;
  }

  public static function preventiveReviewPublicUrl(int $reviewId): string
  {
    return self::defaultPreventiveReviewUrl() . rawurlencode((string) $reviewId);
  }

  public static function signedPreventiveReviewPublicUrl(int $reviewId, ?int $expires = null): string
  {
    $expires = $expires && $expires > time() ? $expires : (time() + 180 * 86400);
    return self::preventiveReviewPublicUrl($reviewId)
      . '&expires=' . rawurlencode((string) $expires)
      . '&sig=' . rawurlencode(self::preventiveReviewPublicSignature($reviewId, $expires));
  }

  public static function preventiveReviewPublicSignature(int $reviewId, int $expires): string
  {
    return hash_hmac('sha256', 'revision-preventiva|' . $reviewId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  public static function preventiveReviewPublicSignatureValid(int $reviewId, int $expires, string $signature): bool
  {
    if ($reviewId <= 0 || $expires <= time() || trim($signature) === '') {
      return false;
    }
    return hash_equals(self::preventiveReviewPublicSignature($reviewId, $expires), trim($signature));
  }

  public static function defaultCorrectiveReviewUrl(): string
  {
    $base = defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') : '';
    return ($base !== '' ? $base . '/' : '') . self::DEFAULT_CORRECTIVA_URL;
  }

  public static function correctiveReviewPublicUrl(int $reviewId): string
  {
    return self::defaultCorrectiveReviewUrl() . rawurlencode((string) $reviewId);
  }

  public static function signedCorrectiveReviewPublicUrl(int $reviewId, ?int $expires = null): string
  {
    $expires = $expires && $expires > time() ? $expires : (time() + 180 * 86400);
    $signature = self::correctiveReviewPublicSignature($reviewId, $expires);
    return self::correctiveReviewPublicUrl($reviewId)
      . '&expires=' . rawurlencode((string) $expires)
      . '&sig=' . rawurlencode($signature);
  }

  public static function correctiveReviewPublicSignature(int $reviewId, int $expires): string
  {
    return hash_hmac('sha256', $reviewId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  public static function correctiveReviewPublicSignatureValid(int $reviewId, int $expires, string $signature): bool
  {
    if ($reviewId <= 0 || $expires <= time() || trim($signature) === '') {
      return false;
    }
    return hash_equals(self::correctiveReviewPublicSignature($reviewId, $expires), trim($signature));
  }

  public static function defaultMaintenanceQuoteUrl(): string
  {
    $base = defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') : '';
    return ($base !== '' ? $base . '/' : '') . self::DEFAULT_COTIZACION_URL;
  }

  public static function maintenanceQuotePublicUrl(int $quoteId): string
  {
    return self::defaultMaintenanceQuoteUrl() . rawurlencode((string) $quoteId);
  }

  public static function signedMaintenanceQuotePublicUrl(int $quoteId, ?int $expires = null): string
  {
    $expires = $expires && $expires > time() ? $expires : (time() + 180 * 86400);
    $signature = self::maintenanceQuotePublicSignature($quoteId, $expires);
    return self::maintenanceQuotePublicUrl($quoteId)
      . '&expires=' . rawurlencode((string) $expires)
      . '&sig=' . rawurlencode($signature);
  }

  public static function maintenanceQuotePublicSignature(int $quoteId, int $expires): string
  {
    return hash_hmac('sha256', 'cotizacion_mantenimiento|' . $quoteId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  public static function maintenanceQuotePublicSignatureValid(int $quoteId, int $expires, string $signature): bool
  {
    if ($quoteId <= 0 || $expires <= time() || trim($signature) === '') {
      return false;
    }
    return hash_equals(self::maintenanceQuotePublicSignature($quoteId, $expires), trim($signature));
  }

  private function normalizePropertyLocationInput(string $raw): string
  {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }

    $coords = $this->extractCoordinatesFromLocationInput($raw);
    if (is_array($coords)) {
      $lat = rtrim(rtrim(number_format((float) $coords['lat'], 7, '.', ''), '0'), '.');
      $lng = rtrim(rtrim(number_format((float) $coords['lng'], 7, '.', ''), '0'), '.');
      return 'https://www.google.com/maps?q=' . $lat . ',' . $lng;
    }

    return trim(self::sanitizeUrl($raw));
  }

  private function extractCoordinatesFromLocationInput(string $value): ?array
  {
    $value = trim($value);
    if ($value === '') {
      return null;
    }

    if (preg_match('#maps\.app\.goo\.gl|goo\.gl/maps#i', $value)) {
      $resolvedUrl = $this->resolveRedirectUrl($value);
      if ($resolvedUrl !== '') {
        $value = $resolvedUrl;
      }
    }

    $decoded = rawurldecode($value);
    $patterns = [
      '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i',
      '/[?&](?:q|ll|query|destination|marker)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i',
      '/[?&]mlat=(-?\d+(?:\.\d+)?).*?[?&]mlon=(-?\d+(?:\.\d+)?)/i',
      '/#map=\d+\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/i',
      '/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/',
    ];

    foreach ($patterns as $pattern) {
      if (!preg_match($pattern, $decoded, $matches)) {
        continue;
      }

      $lat = isset($matches[1]) ? (float) str_replace(',', '.', (string) $matches[1]) : null;
      $lng = isset($matches[2]) ? (float) str_replace(',', '.', (string) $matches[2]) : null;
      if ($lat === null || $lng === null) {
        continue;
      }
      if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        continue;
      }

      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }

  private function resolveRedirectUrl(string $url): string
  {
    if (!function_exists('wp_remote_head') || !function_exists('wp_remote_retrieve_header')) {
      return '';
    }

    $response = wp_remote_head($url, ['redirection' => 5, 'timeout' => 8]);
    if (is_wp_error($response)) {
      return '';
    }

    $location = wp_remote_retrieve_header($response, 'location');
    return is_string($location) ? trim($location) : '';
  }

  private function maybeInsertPropertyLocationHistory(array $property, string $propertyCode, string $mapsLocation): void
  {
    $coords = $this->extractCoordinatesFromLocationInput($mapsLocation);
    if (!is_array($coords)) {
      return;
    }

    $historyTable = $this->db->table('skc_ubicacion_inmuebles_mapa');
    if (!$this->table_exists($historyTable)) {
      return;
    }

    $payload = [
      'codigo_inmueble' => $propertyCode,
      'latitud' => (float) $coords['lat'],
      'longitud' => (float) $coords['lng'],
      'fuente' => 'manual',
      'google_maps_url' => $mapsLocation,
      'created_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
      'cct_created' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
    ];

    $optionalColumns = [
      'barrio' => trim((string) ($property['barrio'] ?? '')),
      'ruta' => trim((string) ($property['ruta'] ?? '')),
      'precision_metros' => 0,
      'notas' => 'Actualizado desde popup de caso',
      'page_url' => isset($_SERVER['REQUEST_URI']) ? self::sanitizeUrl((string) $_SERVER['REQUEST_URI']) : '',
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '',
    ];

    foreach ($optionalColumns as $column => $value) {
      if ($this->column_exists($historyTable, $column)) {
        $payload[$column] = $value;
      }
    }

    $this->db->insert($historyTable, $payload);
  }

  private function verifyCsrf(): void
  {
    $nonce = (string)($_POST['nonce'] ?? '');
    if (!\SCM\Core\App::csrf()->verify(self::NONCE_KEY, $nonce, false)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=UTF-8');
      header('X-SCM-Auth: csrf-expired');
      echo json_encode([
        'success' => false,
        'data' => [
          'message' => 'La verificación de seguridad venció. Inicia sesión nuevamente.',
          'code' => 'CSRF_EXPIRED',
        ],
      ]);
      exit;
    }
  }

  private function jsonOk(array $data): void
  {
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }

  private function jsonFail(string $message, array $extra = []): void
  {
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'data' => ['message' => $message] + $extra], JSON_UNESCAPED_UNICODE);
    exit;
  }

  private function storedFiles(): \SCM\Support\StoredFileService
  {
    if (!$this->storedFiles instanceof \SCM\Support\StoredFileService) {
      $this->storedFiles = \SCM\Support\StoredFileService::fromRuntime();
    }
    return $this->storedFiles;
  }

  private function handleImageUpload(string $fieldName): string
  {
    $uploads = $this->storedFiles()->storeImages($fieldName, 1);
    return (string) ($uploads[0] ?? '');
  }

  /** @return string[] */
  private function handleImageUploads(string $fieldName, int $maxFiles = 10): array
  {
    return $this->storedFiles()->storeImages($fieldName, $maxFiles);
  }

  /** @return array<int,array{name:string,url:string,mime:string,width:int,height:int,bytes:int,sha256:string}> */
  private function handleImageUploadsDetailed(string $fieldName, int $maxFiles = 10): array
  {
    return $this->storedFiles()->storeImagesDetailed($fieldName, $maxFiles);
  }

  /** @param string[] $titles @return array<int,array{nombre_archivo:string,archivo:string,media_archivo:string}> */
  private function handleDocumentUploads(string $fieldName, array $titles = [], int $maxFiles = 10): array
  {
    return $this->storedFiles()->storeDocuments($fieldName, $titles, $maxFiles);
  }
  private function get_servicios_inmobiliarios_module()
  {
    if ($this->serviciosInmobiliariosModule instanceof \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosModule) {
      return $this->serviciosInmobiliariosModule;
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $parser = new \SCM\Support\TimestampParser();
    $dateFormatter = new \SCM\Support\DateFormatter();
    $ticketsRepo = new \SCM\Repositories\TicketsRepository($this->db, $schema, $parser);

    $slaRisk    = 8;
    $slaOverdue = 15;
    $kpiRules   = [];
    $metrics = new \SCM\Metrics\ServiciosInmobiliariosMetricsService($parser, $slaRisk, $slaOverdue, $kpiRules);
    $timelineEngine = new \SCM\Timeline\TimelineEngine($parser);
    $presenter = new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosTablePresenter($dateFormatter, $parser, $timelineEngine, [$this, 'render_seguimiento_form']);
    $filters = new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosFilters();

    $this->serviciosInmobiliariosModule = new \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosModule(
      $ticketsRepo,
      $filters,
      $metrics,
      $presenter
    );

    return $this->serviciosInmobiliariosModule;
  }

  private function get_generic_tickets_module()
  {
    if ($this->genericTicketsModule instanceof \SCM\Modules\GenericTickets\GenericTicketsModule) {
      return $this->genericTicketsModule;
    }

    $this->genericTicketsModule = new \SCM\Modules\GenericTickets\GenericTicketsModule($this, $this->db);
    return $this->genericTicketsModule;
  }

  private function get_seguimiento_service()
  {
    if ($this->seguimientoService instanceof \SCM\Modules\ServiciosInmobiliarios\SeguimientoService) {
      return $this->seguimientoService;
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $this->seguimientoService = new \SCM\Modules\ServiciosInmobiliarios\SeguimientoService($this->db, $schema);
    $this->seguimientoService->setQueue(new \SCM\Support\EmailQueue($this->db));
    return $this->seguimientoService;
  }

  private function get_pending_controller(): \SCM\Modules\Pending\PendingController
  {
    $repo = new \SCM\Modules\Pending\PendingRepository($this->db);
    $service = new \SCM\Modules\Pending\PendingService($repo);
    $view = new \SCM\Modules\Pending\PendingView();
    return new \SCM\Modules\Pending\PendingController($service, $view);
  }

  public function table_exists($table)
  {
    if (array_key_exists($table, $this->tableExistsCache)) {
      return (bool)$this->tableExistsCache[$table];
    }
    if ($this->schemaInspector === null) {
      $this->schemaInspector = new \SCM\Support\SchemaInspector($this->db);
    }
    $ok = $this->schemaInspector->tableExists((string)$table);
    $this->tableExistsCache[$table] = $ok;
    return $ok;
  }

  public function column_exists($table, $column)
  {
    $key = $table . '::' . $column;
    if (array_key_exists($key, $this->columnExistsCache)) {
      return (bool)$this->columnExistsCache[$key];
    }
    if ($this->schemaInspector === null) {
      $this->schemaInspector = new \SCM\Support\SchemaInspector($this->db);
    }
    $ok = $this->schemaInspector->columnExists((string)$table, (string)$column);
    $this->columnExistsCache[$key] = $ok;
    return $ok;
  }

  public function detect_first_existing_column($table, array $candidates): string
  {
    foreach ($candidates as $candidate) {
      if ($this->column_exists($table, $candidate)) {
        return $candidate;
      }
    }
    return '';
  }

  /** @return array<int,array{codigo:string,pais:string,label:string}> */
  private function getIndicativoOptions(): array
  {
    $table = $this->db->table('jet_cct_paises');
    if (!$this->table_exists($table) || !$this->column_exists($table, 'codigo') || !$this->column_exists($table, 'pais')) {
      return [['codigo' => '+57', 'pais' => 'Colombia', 'label' => '+57 Colombia']];
    }

    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(`codigo`, '')) AS codigo, TRIM(COALESCE(`pais`, '')) AS pais FROM `{$table}` WHERE TRIM(COALESCE(`codigo`, '')) <> '' ORDER BY CASE WHEN TRIM(COALESCE(`codigo`, '')) = '+57' THEN 0 ELSE 1 END, pais ASC LIMIT 300"
    );

    $seen = [];
    $out = [];
    foreach ($rows as $row) {
      $codigo = trim((string)($row['codigo'] ?? ''));
      $pais = trim((string)($row['pais'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      if ($codigo[0] !== '+') {
        $codigo = '+' . ltrim($codigo, '+');
      }
      if (isset($seen[$codigo])) {
        continue;
      }
      $seen[$codigo] = true;
      $label = $codigo . ($pais !== '' ? (' ' . $pais) : '');
      $out[] = ['codigo' => $codigo, 'pais' => $pais, 'label' => $label];
    }

    return !empty($out) ? $out : [['codigo' => '+57', 'pais' => 'Colombia', 'label' => '+57 Colombia']];
  }

  public function parse_unix_ts($value)
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int)$value;
      if ($ts > 9999999999) {
        $ts = (int)floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string)$value);
    return $ts === false ? 0 : (int)$ts;
  }

  public function format_date($ts)
  {
    $ref = (int)$ts;
    if ($ref <= 0) {
      return '';
    }
    return date('d/m/Y', $ref);
  }

  public function format_date_time($ts)
  {
    $ref = (int)$ts;
    if ($ref <= 0) {
      return '';
    }
    return date('d/m/Y H:i', $ref);
  }

  public function estado_badge($estado, $prefix = 'scm')
  {
    $label = trim((string)$estado);
    $low = strtolower($label);
    $cls = 'badge-neutral';
    if ($low === 'nuevo' || $low === 'abierto') {
      $cls = 'badge-success';
    } elseif ($low === 'en proceso') {
      $cls = 'badge-warning';
    } elseif ($low === 'en espera de respuesta' || $low === 'postergado' || $low === 'postergada' || $low === 'postergados') {
      $cls = 'badge-info';
    } elseif ($low === 'cerrado' || $low === 'cerrada' || $low === 'cerrados' || $low === 'resuelto' || $low === 'finalizado') {
      $cls = 'badge-error';
    } elseif ($low === 'cancelado' || $low === 'vencido') {
      $cls = 'badge-error';
    }
    return '<span class="badge ' . self::h($cls) . ' badge-sm">' . self::h($label !== '' ? $label : '-') . '</span>';
  }

  public function render_seguimiento_form($ticketPk, bool $canSeguimiento, bool $hasCotizacion = false): string
  {
    $view = new \SCM\Views\SeguimientoFormView();
    return $view->render((int) $ticketPk, $canSeguimiento, $hasCotizacion);
  }

  private function parse_params_generic(array $input, string $prefix): array
  {
    return $this->get_generic_tickets_module()->parse_params_generic($input, $prefix);
  }

  private function run_query_generic(array $temas, array $p, array $config = [], bool $includeRows = true): array
  {
    return $this->get_generic_tickets_module()->run_query_generic($temas, $p, $config, $includeRows);
  }

  private function render_generic_cards(array $rows, array $config, string $tabKey, string $statusBucket = ''): string
  {
    return $this->get_generic_tickets_module()->render_generic_cards($rows, $config, $tabKey, $statusBucket);
  }

  private function render_generic_filter_form(string $tabKey, string $prefix, array $p, bool $showTema = false, array $temaOpts = [], array $filterOptions = [], string $baseTabKey = '', string $lockedStatusLabel = ''): string
  {
    return $this->get_generic_tickets_module()->render_generic_filter_form($tabKey, $prefix, $p, $showTema, $temaOpts, $filterOptions, $baseTabKey, $lockedStatusLabel);
  }

  /** @return array<string,array<string,mixed>> */
  private function get_generic_tab_definitions(): array
  {
    return self::configuredGenericTicketTabDefinitions();
  }

  /** @return array<string,array<string,string>> */
  private function get_status_bucket_definitions(): array
  {
    return [
      'postergados' => ['label' => 'Postergados', 'locked_label' => 'Postergados'],
      'cerrados' => ['label' => 'Cerrados', 'locked_label' => 'Cerrados'],
    ];
  }

  /** @return array<string,array<string,mixed>> */
  private function get_status_topic_definitions(): array
  {
    $generic = $this->get_generic_tab_definitions();
    return [
      'mantenimiento' => [
        'label' => 'Mantenimiento',
        'base' => 'mantenimiento',
        'kind' => 'maintenance',
        'temas' => \SCM\Repositories\TicketsRepository::MAINTENANCE_TOPICS,
      ],
      'preventiva' => ['label' => 'Preventiva', 'base' => 'preventiva', 'kind' => 'generic', 'temas' => $generic['preventiva']['temas']],
      'entrega' => ['label' => 'Entrega', 'base' => 'entrega', 'kind' => 'generic', 'temas' => $generic['entrega']['temas']],
      'recibo' => ['label' => 'Recibo', 'base' => 'recibo', 'kind' => 'generic', 'temas' => $generic['recibo']['temas']],
      'contractual' => ['label' => 'Contractual', 'base' => 'contractual', 'kind' => 'generic', 'temas' => $generic['contractual']['temas']],
      'contable' => ['label' => 'Contable', 'base' => 'contable', 'kind' => 'generic', 'temas' => $generic['contable']['temas']],
      'certificaciones' => ['label' => 'Certificaciones', 'base' => 'certificaciones', 'kind' => 'generic', 'temas' => $generic['certificaciones']['temas']],
    ];
  }

  private function status_dom_key(string $bucketKey, string $topicKey): string
  {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($bucketKey . '-' . $topicKey)) ?: 'status';
  }

  private function status_prefix(string $bucketKey, string $topicKey): string
  {
    $bucketCode = $bucketKey === 'cerrados' ? 'c' : 'p';
    $topicCode = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $topicKey))) ?: 'topic';
    return 'scms_' . $bucketCode . '_' . $topicCode . '_';
  }

  private function normalize_status_bucket(string $bucketKey): string
  {
    $bucketKey = preg_replace('/[^a-z0-9_-]/', '', strtolower($bucketKey)) ?: '';
    return isset($this->get_status_bucket_definitions()[$bucketKey]) ? $bucketKey : '';
  }

  private function normalize_status_topic(string $topicKey): string
  {
    $topicKey = preg_replace('/[^a-z0-9_-]/', '', strtolower($topicKey)) ?: '';
    return isset($this->get_status_topic_definitions()[$topicKey]) ? $topicKey : '';
  }

  /**
   * @param array<string,string> $bucketDefs
   * @param array<string,array<string,mixed>> $topicDefs
   * @param array<string,string> $config
   * @param array<string,mixed> $maintenanceFilterOptions
   * @return array<string,array<string,array<string,mixed>>>
   */
  private function build_status_bucket_results(array $bucketDefs, array $topicDefs, array $config, array $maintenanceFilterOptions, bool $hydrateRows = true): array
  {
    $out = [];
    foreach ($bucketDefs as $bucketKey => $_bucketDef) {
      $out[$bucketKey] = [];
      foreach ($topicDefs as $topicKey => $_topicDef) {
        $out[$bucketKey][$topicKey] = $this->build_status_topic_result($bucketKey, $topicKey, $config, $_GET, $maintenanceFilterOptions, $hydrateRows);
      }
    }
    return $out;
  }

  private function render_lazy_tickets_placeholder(string $message = 'Selecciona esta vista para cargar casos.'): string
  {
    return '<div class="scm-empty scm-lazy-empty"><p>' . esc_html($message) . '</p></div>';
  }

  private function render_lazy_filter_form(string $domKey, string $prefix): string
  {
    return '<div class="scm-filter-card card">'
      . '<form id="scm-form-' . esc_attr($domKey) . '" autocomplete="off">'
      . '<input type="hidden" name="' . esc_attr($prefix) . 'page" value="1">'
      . '<div class="scm-empty scm-lazy-empty"><p>Los filtros se cargarán con esta vista.</p></div>'
      . '<span class="scm-spinner" id="scm-spinner-' . esc_attr($domKey) . '"></span>'
      . '</form></div>';
  }

  /**
   * @param array<string,string> $config
   * @param array<string,mixed> $input
   * @param array<string,mixed> $maintenanceFilterOptions
   * @return array<string,mixed>
   */
  private function build_status_topic_result(string $bucketKey, string $topicKey, array $config, array $input, array $maintenanceFilterOptions, bool $hydrateRows = true): array
  {
    $bucketDefs = $this->get_status_bucket_definitions();
    $topicDefs = $this->get_status_topic_definitions();
    if (!isset($bucketDefs[$bucketKey], $topicDefs[$topicKey])) {
      return [
        'dom_key' => $this->status_dom_key($bucketKey, $topicKey),
        'prefix' => $this->status_prefix($bucketKey, $topicKey),
        'count' => 0,
        'cards' => '<div class="scm-empty"><p>No se encontraron casos.</p></div>',
        'pagination' => '',
        'form' => '',
        'stats' => ['total' => 0],
      ];
    }

    $def = $topicDefs[$topicKey];
    $domKey = $this->status_dom_key($bucketKey, $topicKey);
    $prefix = $this->status_prefix($bucketKey, $topicKey);
    $lockedStatusLabel = (string) ($bucketDefs[$bucketKey]['locked_label'] ?? '');
    $kind = (string) ($def['kind'] ?? 'generic');

    if ($kind === 'maintenance') {
      $module = $this->get_servicios_inmobiliarios_module();
      $params = $module->parseParams($input, $prefix);
      if (!$hydrateRows) {
        return [
          'dom_key' => $domKey,
          'prefix' => $prefix,
          'params' => $params,
          'count' => 0,
          'cards' => $this->render_lazy_tickets_placeholder(),
          'pagination' => '',
          'form' => $this->render_lazy_filter_form($domKey, $prefix),
          'stats' => ['total' => 0],
          'loaded' => false,
        ];
      }
      $result = $module->run($params, $config, $bucketKey);
      $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
      $maintenanceFilterOptions['estado_admin'] = $result['admin_status_options'] ?? [];
      return [
        'dom_key' => $domKey,
        'prefix' => $prefix,
        'params' => $params,
        'count' => (int) ($stats['total'] ?? 0),
        'cards' => (string) ($result['tbody'] ?? ''),
        'pagination' => (string) ($result['pagination_html'] ?? ''),
        'form' => $this->render_status_maintenance_filter_form($domKey, $prefix, $params, $maintenanceFilterOptions, $lockedStatusLabel, true, $bucketKey),
        'stats' => $stats,
        'loaded' => true,
      ];
    }

    $params = $this->parse_params_generic($input, $prefix);
    $params['_scmStatusBucket'] = $bucketKey;
    $temas = is_array($def['temas'] ?? null) ? $def['temas'] : [];
    $baseTabKey = (string) ($def['base'] ?? $topicKey);
    if (!$hydrateRows) {
      return [
        'dom_key' => $domKey,
        'prefix' => $prefix,
        'params' => $params,
        'count' => 0,
        'cards' => $this->render_lazy_tickets_placeholder(),
        'pagination' => '',
        'form' => $this->render_lazy_filter_form($domKey, $prefix),
        'stats' => ['total' => 0],
        'loaded' => false,
      ];
    }
    $result = $this->run_query_generic($temas, $params, $config, $hydrateRows);
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
    $filterOptions = $this->get_generic_tickets_module()->get_generic_filter_options($temas);
    $filterOptions['estado_admin'] = $result['admin_status_options'] ?? [];

    return [
      'dom_key' => $domKey,
      'prefix' => $prefix,
      'params' => $params,
      'count' => (int) ($stats['total'] ?? 0),
      'cards' => $hydrateRows ? $this->render_generic_cards($rows, $config, $baseTabKey, $bucketKey) : $this->render_lazy_tickets_placeholder(),
      'pagination' => $hydrateRows ? $this->get_generic_tickets_module()->render_generic_pagination($domKey, $pagination) : '',
      'form' => $this->render_generic_filter_form($domKey, $prefix, $params, false, [], $filterOptions, $baseTabKey, $lockedStatusLabel),
      'stats' => $stats,
      'loaded' => $hydrateRows,
    ];
  }

  private function render_status_maintenance_filter_form(string $domKey, string $prefix, array $p, array $filterOptions, string $lockedStatusLabel, bool $allowExport = false, string $statusBucket = ''): string
  {
    ob_start();
?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form id="scm-form-<?php echo esc_attr($domKey); ?>" autocomplete="off">
        <input type="hidden" id="<?php echo esc_attr($prefix); ?>page" name="<?php echo esc_attr($prefix); ?>page" value="<?php echo esc_attr((string)($p['fPage'] ?? 1)); ?>">
        <div class="scm-grid">
          <?= \SCM\Support\CaseFilterFields::administrativeStatus($prefix, $p, $filterOptions['estado_admin'] ?? []) ?>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>estado_bloqueado">Vista</label><input id="<?php echo esc_attr($prefix); ?>estado_bloqueado" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr($lockedStatusLabel); ?>" readonly></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>sort">Ordenar por</label><select id="<?php echo esc_attr($prefix); ?>sort" name="<?php echo esc_attr($prefix); ?>sort" class="select select-bordered select-sm scm-select scm-sort-select">
              <option value="created_desc" <?php selected((string) ($p['fSort'] ?? 'created_desc'), 'created_desc'); ?>>Fecha de creaci&oacute;n reciente</option>
              <option value="created_asc" <?php selected((string) ($p['fSort'] ?? ''), 'created_asc'); ?>>Fecha de creaci&oacute;n antigua</option>
              <option value="stale_desc" <?php selected((string) ($p['fSort'] ?? ''), 'stale_desc'); ?>>M&aacute;s tiempo sin actualizar</option>
              <option value="stale_asc" <?php selected((string) ($p['fSort'] ?? ''), 'stale_asc'); ?>>Actualizados recientemente</option>
              <option value="magnitude_desc" <?php selected((string) ($p['fSort'] ?? ''), 'magnitude_desc'); ?>>Mayor magnitud</option>
              <option value="magnitude_asc" <?php selected((string) ($p['fSort'] ?? ''), 'magnitude_asc'); ?>>Menor magnitud</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>id_empleado">Funcionario</label><select id="<?php echo esc_attr($prefix); ?>id_empleado" name="<?php echo esc_attr($prefix); ?>id_empleado" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["funcionarios"] ?? []) as $func): $fId = trim((string)($func["id"] ?? ""));
                                                if ($fId === "") continue; ?>
                <option value="<?php echo esc_attr($fId); ?>" <?php selected(($p["fEmpleado"] ?? ""), $fId); ?>><?php echo esc_html((string)($func["label"] ?? $fId)); ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>inmueble">Inmueble SIMI</label><input id="<?php echo esc_attr($prefix); ?>inmueble" name="<?php echo esc_attr($prefix); ?>inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p['fInmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>contrato">Contrato</label><input id="<?php echo esc_attr($prefix); ?>contrato" name="<?php echo esc_attr($prefix); ?>contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fContrato"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>caso"># caso</label><input id="<?php echo esc_attr($prefix); ?>caso" name="<?php echo esc_attr($prefix); ?>caso" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fCaso"] ?? "")); ?>" placeholder="Ej: 10368"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>asunto">Asunto</label><input id="<?php echo esc_attr($prefix); ?>asunto" name="<?php echo esc_attr($prefix); ?>asunto" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fAsunto"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>arrendatario">Arrendatario</label><input id="<?php echo esc_attr($prefix); ?>arrendatario" name="<?php echo esc_attr($prefix); ?>arrendatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fArrendatario"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>propietario">Propietario</label><input id="<?php echo esc_attr($prefix); ?>propietario" name="<?php echo esc_attr($prefix); ?>propietario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fPropietario"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>barrio">Barrio</label><select id="<?php echo esc_attr($prefix); ?>barrio" name="<?php echo esc_attr($prefix); ?>barrio" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["barrios"] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string)$barrioOpt); ?>" <?php selected((string)($p["fBarrio"] ?? ""), (string)$barrioOpt); ?>><?php echo esc_html((string)$barrioOpt); ?></option><?php endforeach; ?>
            </select></div>
          <div class="scm-field scm-date-range-field"><label id="<?php echo esc_attr($prefix); ?>fecha_rango_label">Fecha</label><div class="scm-date-range-control" role="group" aria-labelledby="<?php echo esc_attr($prefix); ?>fecha_rango_label"><div class="scm-date-range-part"><span>Desde</span><input id="<?php echo esc_attr($prefix); ?>fecha_desde" name="<?php echo esc_attr($prefix); ?>fecha_desde" type="date" value="<?php echo esc_attr((string)($p["fFechaDesde"] ?? "")); ?>" aria-label="Fecha desde"></div><span class="scm-date-range-separator" aria-hidden="true">&rarr;</span><div class="scm-date-range-part"><span>Hasta</span><input id="<?php echo esc_attr($prefix); ?>fecha_hasta" name="<?php echo esc_attr($prefix); ?>fecha_hasta" type="date" value="<?php echo esc_attr((string)($p["fFechaHasta"] ?? "")); ?>" aria-label="Fecha hasta"></div></div></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>magnitud_caso">Magnitud caso</label><select id="<?php echo esc_attr($prefix); ?>magnitud_caso" name="<?php echo esc_attr($prefix); ?>magnitud_caso" class="select select-bordered select-sm scm-select">
              <option value="">Todas las magnitudes</option>
              <option value="critico" <?php selected(($p['fMagnitudCaso'] ?? ''), 'critico'); ?>>Cr&iacute;tico</option>
              <option value="alto" <?php selected(($p['fMagnitudCaso'] ?? ''), 'alto'); ?>>Alto</option>
              <option value="medio" <?php selected(($p['fMagnitudCaso'] ?? ''), 'medio'); ?>>Medio</option>
              <option value="bajo" <?php selected(($p['fMagnitudCaso'] ?? ''), 'bajo'); ?>>Bajo</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>revision">Revisi&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>revision" name="<?php echo esc_attr($prefix); ?>revision" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(($p['fRevision'] ?? ''), 'has'); ?>>Con revisi&oacute;n</option>
              <option value="none" <?php selected(($p['fRevision'] ?? ''), 'none'); ?>>Sin revisi&oacute;n</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>perturbacion">Perturbaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>perturbacion" name="<?php echo esc_attr($prefix); ?>perturbacion" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'has'); ?>>Con perturbaci&oacute;n</option>
              <option value="none" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'none'); ?>>Sin perturbaci&oacute;n</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>cotizacion">Cotizaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>cotizacion" name="<?php echo esc_attr($prefix); ?>cotizacion" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(($p['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
              <option value="none" <?php selected(($p['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
            </select></div>
          <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_enviada">Fue enviada</label><select id="<?php echo esc_attr($prefix); ?>cotizacion_enviada" name="<?php echo esc_attr($prefix); ?>cotizacion_enviada" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="si" <?php selected(($p['fCotizacionEnviada'] ?? ''), 'si'); ?>>S&iacute;</option>
              <option value="no" <?php selected(($p['fCotizacionEnviada'] ?? ''), 'no'); ?>>No</option>
            </select></div>
          <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_estado">Estado cotizaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>cotizacion_estado" name="<?php echo esc_attr($prefix); ?>cotizacion_estado" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["cotizacion_estado"] ?? []) as $cotEstadoOpt): ?><option value="<?php echo esc_attr((string)$cotEstadoOpt); ?>" <?php selected((string)($p["fCotizacionEstado"] ?? ""), (string)$cotEstadoOpt); ?>><?php echo esc_html((string)$cotEstadoOpt); ?></option><?php endforeach; ?>
            </select></div>
          <?= \SCM\Support\CaseFilterFields::financing($prefix, $p) ?>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>atraso">Atraso desde creaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>atraso" name="<?php echo esc_attr($prefix); ?>atraso" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option>
              <option value="3" <?php selected(($p['fAtraso'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
              <option value="5" <?php selected(($p['fAtraso'] ?? ''), '5'); ?>>+5 d&iacute;as</option>
              <option value="10" <?php selected(($p['fAtraso'] ?? ''), '10'); ?>>+10 d&iacute;as</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>sin_actualizar">Sin actualizar desde gesti&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>sin_actualizar" name="<?php echo esc_attr($prefix); ?>sin_actualizar" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option>
              <option value="1" <?php selected(($p['fSinActualizar'] ?? ''), '1'); ?>>+1 d&iacute;a</option>
              <option value="3" <?php selected(($p['fSinActualizar'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
              <option value="7" <?php selected(($p['fSinActualizar'] ?? ''), '7'); ?>>+7 d&iacute;as</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>tuvo_seguimiento">Tuvo seguimiento</label><select id="<?php echo esc_attr($prefix); ?>tuvo_seguimiento" name="<?php echo esc_attr($prefix); ?>tuvo_seguimiento" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option>
              <option value="Si" <?php selected(($p['fTuvoSeguimiento'] ?? ''), 'Si'); ?>>Si</option>
              <option value="No" <?php selected(($p['fTuvoSeguimiento'] ?? ''), 'No'); ?>>No</option>
            </select></div>
          <?php echo \SCM\Support\CaseFilterFields::executor($prefix, $p); ?>
        </div>
        <div class="scm-actions">
          <button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button>
          <?php if ($allowExport && ($this->canManageDashboardPermissions() || $this->canUseDashboardAction('case_export_excel'))): ?>
            <button class="scm-btn-secondary btn btn-outline" type="button" data-scm-export-cases data-scm-export-topic="mantenimiento" data-scm-export-bucket="<?php echo esc_attr($statusBucket); ?>"><i class="fas fa-file-excel" aria-hidden="true"></i> Exportar Excel</button>
          <?php endif; ?>
          <button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear-<?php echo esc_attr($domKey); ?>">Limpiar</button>
          <span class="scm-spinner" id="scm-spinner-<?php echo esc_attr($domKey); ?>"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /**
   * @param array<string,string> $bucketDef
   * @param array<string,array<string,mixed>> $topicDefs
   * @param array<string,array<string,mixed>> $bucketResults
   */
  private function render_status_bucket_panel(string $bucketKey, array $bucketDef, array $topicDefs, array $bucketResults): string
  {
    ob_start();
    $activeTopicKey = '';
    foreach ($topicDefs as $topicKey => $_topicDef) {
      $data = is_array($bucketResults[$topicKey] ?? null) ? $bucketResults[$topicKey] : [];
      if ((int)($data['count'] ?? 0) > 0) {
        $activeTopicKey = $topicKey;
        break;
      }
    }
    if ($activeTopicKey === '') {
      $keys = array_keys($topicDefs);
      $activeTopicKey = (string)($keys[0] ?? '');
    }
?>
    <div class="scm-status-bucket" data-status-bucket="<?php echo esc_attr($bucketKey); ?>">
      <div class="scm-status-subtabs" role="tablist" aria-label="<?php echo esc_attr((string)($bucketDef['label'] ?? 'Casos')); ?>">
        <?php foreach ($topicDefs as $topicKey => $topicDef):
          $domKey = $this->status_dom_key($bucketKey, $topicKey);
          $isActive = $topicKey === $activeTopicKey; ?>
          <button class="scm-status-topic-tab<?php echo $isActive ? ' active' : ''; ?>" type="button" data-status-target="<?php echo esc_attr($domKey); ?>" data-status-bucket="<?php echo esc_attr($bucketKey); ?>" data-status-topic="<?php echo esc_attr($topicKey); ?>">
            <?php echo esc_html((string)($topicDef['label'] ?? $topicKey)); ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($topicDefs as $topicKey => $topicDef):
        $domKey = $this->status_dom_key($bucketKey, $topicKey);
        $data = is_array($bucketResults[$topicKey] ?? null) ? $bucketResults[$topicKey] : [];
        $isActive = $topicKey === $activeTopicKey; ?>
        <div class="scm-status-topic-panel<?php echo $isActive ? ' active' : ''; ?>" id="scm-status-panel-<?php echo esc_attr($domKey); ?>" data-status-key="<?php echo esc_attr($domKey); ?>" data-status-bucket="<?php echo esc_attr($bucketKey); ?>" data-status-topic="<?php echo esc_attr($topicKey); ?>" data-scm-loaded="<?php echo !empty($data['loaded']) ? '1' : '0'; ?>">
          <div class="scm-status-topic-head">
            <div>
              <h3><?php echo esc_html((string)($topicDef['label'] ?? $topicKey)); ?></h3>
              <p><?php echo esc_html((string)($bucketDef['label'] ?? 'Casos')); ?></p>
            </div>
            <span class="scm-status-count"><strong id="scm-<?php echo esc_attr($domKey); ?>-count"><?php echo esc_html((string)($data['count'] ?? 0)); ?></strong> casos</span>
          </div>
          <?php echo (string)($data['form'] ?? ''); ?>
          <div class="scm-cards-wrap">
            <div class="scm-ticket-cards" id="scm-status-cards-<?php echo esc_attr($domKey); ?>"><?php echo (string)($data['cards'] ?? ''); ?></div>
          </div>
          <div class="scm-pagination" id="scm-status-pagination-<?php echo esc_attr($domKey); ?>"><?php echo (string)($data['pagination'] ?? ''); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

}
