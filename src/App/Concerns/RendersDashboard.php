<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;
use SCM\Modules\CollectionManagement\CollectionPortfolioService;
use SCM\Support\FuncionarioOptions;

trait RendersDashboard
{
  public function renderPanel(array $overrideConfig = []): string
  {
    $config = array_merge([
      'ticket_url'     => self::DEFAULT_TICKET_URL,
      'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
      'correctiva_url' => self::DEFAULT_CORRECTIVA_URL,
      'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
      'acta_url'       => self::DEFAULT_ACTA_URL,
      'calendar_app_url' => self::DEFAULT_CALENDAR_APP_URL,
      'calendar_api_url' => self::DEFAULT_CALENDAR_API_URL,
    ], $overrideConfig);

    $tabla = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($tabla)) {
      return '<div class="scm-error">No existe la tabla ' . self::h($tabla) . '.</div>';
    }

    $module = $this->get_servicios_inmobiliarios_module();
    $filterOptions = [
      'tema' => [],
      'estado' => ['Nuevo', 'En proceso'],
      'estado_admin' => [],
      'prioridad' => [],
      'cotizacion_estado' => [],
      'revision_estado' => [],
      'funcionarios' => [],
      'barrios' => [],
    ];
    $calendarAllowedFuncionarios = [];
    $config['calendar_allowed_cargos'] = FuncionarioOptions::panelCargoIds();
    $config['calendar_allowed_funcionarios'] = $calendarAllowedFuncionarios;
    $config['calendar_allowed_employee_ids'] = array_values(array_filter(array_map(static function ($row): string {
      return trim((string) ($row['id_empleado'] ?? ''));
    }, $calendarAllowedFuncionarios)));
    $params = $module->parseParams($_GET);
    $stats = [];
    $tbodyHtml = '';
    $paginationHtml = '';

    $genericTabDefs = $this->get_generic_tab_definitions();
    $tabRaw = trim((string) ($_GET['scm_tab'] ?? ($_GET['tab'] ?? '')));
    $tabKey = mb_strtolower($tabRaw, 'UTF-8');
    $openTopicAliases = [
      'mant' => 'mant',
      'mantenimiento' => 'mant',
      'scm-panel-mant' => 'mant',
      'revision_preventiva' => 'preventiva',
      'revisión preventiva' => 'preventiva',
      'revision preventiva' => 'preventiva',
      'preventiva' => 'preventiva',
      'scm-panel-preventiva' => 'preventiva',
      'entrega' => 'entrega',
      'scm-panel-entrega' => 'entrega',
      'recibo' => 'recibo',
      'scm-panel-recibo' => 'recibo',
      'contractual' => 'contractual',
      'scm-panel-contractual' => 'contractual',
      'contable' => 'contable',
      'scm-panel-contable' => 'contable',
      'certificaciones' => 'certificaciones',
      'scm-panel-certificaciones' => 'certificaciones',
    ];
    $initialOpenTopic = $openTopicAliases[$tabKey] ?? '';

    $genericResults = [];
    foreach ($genericTabDefs as $gTabKey => $gTabDef) {
      $gParams = $this->parse_params_generic($_GET, $gTabDef['prefix']);
      $genericResults[$gTabKey] = [
        'params' => $gParams,
        'result' => [
          'rows' => [],
          'stats' => ['total' => 0],
          'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1],
        ],
        'def' => $gTabDef,
        'loaded' => false,
      ];
    }

    $statusBucketDefs = $this->get_status_bucket_definitions();
    $statusTopicDefs = $this->get_status_topic_definitions();
    $statusResults = $this->build_status_bucket_results($statusBucketDefs, $statusTopicDefs, $config, $filterOptions, false);
    $currentEmployeeId = '';
    $config['calendar_current_employee_id'] = $currentEmployeeId;
    $myTicketsParams = $module->parseParams($_GET, 'scm_my_');
    $myTicketsParams['fEmpleado'] = $currentEmployeeId !== '' ? $currentEmployeeId : '__sin_funcionario__';
    $myTicketsParams['_scmEmpleadoExact'] = '1';
    $myTicketsResult = ['tbody' => $this->render_lazy_tickets_placeholder('Abre esta pestaña para cargar tus tickets.'), 'pagination_html' => ''];
    $myTicketsStats = ['total' => 0];
    $cotizacionesParams = $this->parse_cotizaciones_mantenimiento_params($_GET, 'scmqt_');
    $cotizacionesResult = [
      'rows' => [],
      'stats' => ['total' => 0, 'enviadas' => 0, 'no_enviadas' => 0, 'aprobadas' => 0, 'desaprobadas' => 0, 'esperando_respuesta' => 0, 'finalizadas' => 0, 'ordenes_total' => 0, 'valor_total' => 0],
      'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1],
    ];
    $openTopicDefs = [
      'mant' => ['label' => 'Mantenimiento'],
      'preventiva' => ['label' => 'Preventiva'],
      'entrega' => ['label' => 'Entrega'],
      'recibo' => ['label' => 'Recibo'],
      'contractual' => ['label' => 'Contractual'],
      'contable' => ['label' => 'Contable'],
      'certificaciones' => ['label' => 'Certificaciones'],
    ];

    $pendingController = $this->get_pending_controller();
    $preventivasPendientesHtml = $this->render_pending_preventivas_shell($_GET);
    $serviciosPublicosPendientesHtml = $this->render_pending_servicios_publicos_shell($_GET);
    $reportesAdministrativosPendientesHtml = $pendingController->renderReportesAdministrativosShell($filterOptions);
    $actasSatisfaccionHtml = $this->render_ticket_completion_acts_shell($_GET);
    $contratosArrendamientoHtml = $pendingController->renderContratosArrendamientoTab();
    $dashboardPermissionTabs = $this->dashboardPermissionTabs();
    $tabMap = [
      'inicio' => 'scm-panel-inicio',
      'home' => 'scm-panel-inicio',
      'resumen' => 'scm-panel-inicio',
      'scm-panel-inicio' => 'scm-panel-inicio',
      'abiertos' => 'scm-panel-abiertos',
      'abierto' => 'scm-panel-abiertos',
      'scm-panel-abiertos' => 'scm-panel-abiertos',
      'metricas' => 'scm-panel-metricas',
      'métricas' => 'scm-panel-metricas',
      'metrics' => 'scm-panel-metricas',
      'scm-panel-metricas' => 'scm-panel-metricas',
      'postergados' => 'scm-panel-postergados',
      'scm-panel-postergados' => 'scm-panel-postergados',
      'cerrados' => 'scm-panel-cerrados',
      'scm-panel-cerrados' => 'scm-panel-cerrados',
      'mis_tickets' => 'scm-panel-mis-tickets',
      'mis-tickets' => 'scm-panel-mis-tickets',
      'mis tickets' => 'scm-panel-mis-tickets',
      'scm-panel-mis-tickets' => 'scm-panel-mis-tickets',
      'cotizaciones_mantenimiento' => 'scm-panel-cotizaciones-mantenimiento',
      'cotizaciones-mantenimiento' => 'scm-panel-cotizaciones-mantenimiento',
      'cotizaciones' => 'scm-panel-cotizaciones-mantenimiento',
      'scm-panel-cotizaciones-mantenimiento' => 'scm-panel-cotizaciones-mantenimiento',
      'actas_satisfaccion' => 'scm-panel-actas-satisfaccion',
      'actas-satisfaccion' => 'scm-panel-actas-satisfaccion',
      'actas sin firmar' => 'scm-panel-actas-satisfaccion',
      'actas firmadas' => 'scm-panel-actas-satisfaccion',
      'scm-panel-actas-satisfaccion' => 'scm-panel-actas-satisfaccion',
      'calendario_actividades' => 'scm-panel-calendario-actividades',
      'calendario-actividades' => 'scm-panel-calendario-actividades',
      'calendario' => 'scm-panel-calendario-actividades',
      'agenda' => 'scm-panel-calendario-actividades',
      'scm-panel-calendario-actividades' => 'scm-panel-calendario-actividades',
      'notificaciones' => 'scm-panel-admin-notificaciones',
      'notificaciones-administrativas' => 'scm-panel-admin-notificaciones',
      'notificaciones_administrativas' => 'scm-panel-admin-notificaciones',
      'scm-panel-admin-notificaciones' => 'scm-panel-admin-notificaciones',
      'gestiones_cobro' => 'scm-panel-gestiones-cobro',
      'gestiones-cobro' => 'scm-panel-gestiones-cobro',
      'gestiones de cobro' => 'scm-panel-gestiones-cobro',
      'scm-panel-gestiones-cobro' => 'scm-panel-gestiones-cobro',
      'actividades_administrativas' => 'scm-panel-actividades-administrativas',
      'actividades-administrativas' => 'scm-panel-actividades-administrativas',
      'actividades administrativas' => 'scm-panel-actividades-administrativas',
      'scm-panel-actividades-administrativas' => 'scm-panel-actividades-administrativas',
      'contratos_arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos' => 'scm-panel-contratos-arrendamiento',
      'scm-panel-contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'preventivas_pendientes' => 'scm-panel-preventivas-pendientes',
      'preventivas-pendientes' => 'scm-panel-preventivas-pendientes',
      'revision preventiva pendientes' => 'scm-panel-preventivas-pendientes',
      'scm-panel-preventivas-pendientes' => 'scm-panel-preventivas-pendientes',
      'servicios_publicos_pendientes' => 'scm-panel-servicios-publicos-pendientes',
      'servicios-publicos-pendientes' => 'scm-panel-servicios-publicos-pendientes',
      'scm-panel-servicios-publicos-pendientes' => 'scm-panel-servicios-publicos-pendientes',
      'reportes_administrativos_pendientes' => 'scm-panel-reportes-administrativos-pendientes',
      'reportes-administrativos-pendientes' => 'scm-panel-reportes-administrativos-pendientes',
      'scm-panel-reportes-administrativos-pendientes' => 'scm-panel-reportes-administrativos-pendientes',
      'auditoria_canon_aseguradoras' => 'scm-panel-auditoria-canon-aseguradoras',
      'auditoria-canon-aseguradoras' => 'scm-panel-auditoria-canon-aseguradoras',
      'auditoria de canon y aseguradoras' => 'scm-panel-auditoria-canon-aseguradoras',
      'scm-panel-auditoria-canon-aseguradoras' => 'scm-panel-auditoria-canon-aseguradoras',
    ];
    $initialTab = $tabMap[$tabKey] ?? '';
    $administrativeActivityTabs = [
      'calendario_actividades' => [
        'panel' => 'scm-panel-calendario-actividades',
        'label' => $dashboardPermissionTabs['calendario_actividades'] ?? 'Calendario',
      ],
      'notificaciones' => [
        'panel' => 'scm-panel-admin-notificaciones',
        'label' => $dashboardPermissionTabs['notificaciones'] ?? 'Notificaciones',
      ],
      'gestiones_cobro' => [
        'panel' => 'scm-panel-gestiones-cobro',
        'label' => $dashboardPermissionTabs['gestiones_cobro'] ?? 'Gestiones de cobro',
      ],
      'cotizaciones_mantenimiento' => [
        'panel' => 'scm-panel-cotizaciones-mantenimiento',
        'label' => $dashboardPermissionTabs['cotizaciones_mantenimiento'] ?? 'Cotizaciones de Mantenimiento',
      ],
      'actas_satisfaccion' => [
        'panel' => 'scm-panel-actas-satisfaccion',
        'label' => $dashboardPermissionTabs['actas_satisfaccion'] ?? 'Actas de satisfacción',
      ],
      'preventivas_pendientes' => [
        'panel' => 'scm-panel-preventivas-pendientes',
        'label' => $dashboardPermissionTabs['preventivas_pendientes'] ?? 'Preventivas Pendientes',
      ],
      'servicios_publicos_pendientes' => [
        'panel' => 'scm-panel-servicios-publicos-pendientes',
        'label' => $dashboardPermissionTabs['servicios_publicos_pendientes'] ?? 'Servicios Publicos Pendientes',
      ],
      'reportes_administrativos_pendientes' => [
        'panel' => 'scm-panel-reportes-administrativos-pendientes',
        'label' => $dashboardPermissionTabs['reportes_administrativos_pendientes'] ?? 'Reportes Administrativos',
      ],
      'auditoria_canon_aseguradoras' => [
        'panel' => 'scm-panel-auditoria-canon-aseguradoras',
        'label' => $dashboardPermissionTabs['auditoria_canon_aseguradoras'] ?? 'Auditoría de canon y aseguradoras',
      ],
    ];
    $administrativePanelToTab = [];
    foreach ($administrativeActivityTabs as $activityTabKey => $activityDef) {
      $administrativePanelToTab[(string) ($activityDef['panel'] ?? '')] = $activityTabKey;
    }
    $initialAdministrativeActivityKey = $administrativePanelToTab[$initialTab] ?? '';
    if ($initialAdministrativeActivityKey !== '') {
      $initialTab = 'scm-panel-actividades-administrativas';
    }
    if ($initialTab === '' && $initialOpenTopic !== '') {
      $initialTab = 'scm-panel-abiertos';
    }
    $iframeModeRaw = mb_strtolower(trim((string) ($_GET['scm_iframe'] ?? ($_GET['iframe'] ?? ''))), 'UTF-8');
    $iframeMode = in_array($iframeModeRaw, ['1', 'true', 'yes', 'si'], true);

    $categoryMetrics = [
      'Mantenimiento' => (int)($stats['total'] ?? 0),
      'Entrega' => (int)($genericResults['entrega']['result']['stats']['total'] ?? 0),
      'Preventiva' => (int)($genericResults['preventiva']['result']['stats']['total'] ?? 0),
      'Recibo' => (int)($genericResults['recibo']['result']['stats']['total'] ?? 0),
      'Contable' => (int)($genericResults['contable']['result']['stats']['total'] ?? 0),
      'Certificaciones' => (int)($genericResults['certificaciones']['result']['stats']['total'] ?? 0),
      'Contractual' => (int)($genericResults['contractual']['result']['stats']['total'] ?? 0),
    ];
    // Las métricas reales se solicitan por AJAX al abrir la pestaña. El primer
    // HTML conserva la estructura para evitar saltos visuales, pero no bloquea
    // la navegación con agregaciones sobre todas las tablas.
    $webTicketStats = [];
    $dashboardAllowedTabs = $this->currentDashboardAllowedTabs();
    $dashboardPermissionConfig = $this->dashboardPermissionsConfig();
    $dashboardCargoOptions = $this->getDashboardCargoOptions();
    $dashboardFuncionarioCargoIds = FuncionarioOptions::panelCargoIds();
    $canManageDashboardPermissions = $this->canManageDashboardPermissions();
    $canManagePublicPqrSettings = $this->canManagePublicPqrSettings();
    $canManageInternalNotificationSettings = $this->canManageInternalNotificationSettings();
    $allowedAdministrativeActivityTabs = [];
    foreach (array_keys($administrativeActivityTabs) as $activityTabKey) {
      if (in_array($activityTabKey, $dashboardAllowedTabs, true)) {
        $allowedAdministrativeActivityTabs[] = $activityTabKey;
      }
    }
    $canAccessAdministrativeActivities = !empty($allowedAdministrativeActivityTabs);

    $dashboardPanelToTab = [
      'scm-panel-abiertos' => 'abiertos',
      'scm-panel-postergados' => 'postergados',
      'scm-panel-cerrados' => 'cerrados',
      'scm-panel-mis-tickets' => 'mis_tickets',
      'scm-panel-actividades-administrativas' => 'actividades_administrativas',
      'scm-panel-contratos-arrendamiento' => 'contratos_arrendamiento',
      'scm-panel-metricas' => 'metricas',
    ];
    if ($initialTab !== '') {
      $initialTabPermissionKey = $dashboardPanelToTab[$initialTab] ?? '';
      if ($initialTabPermissionKey === 'actividades_administrativas') {
        if ($initialAdministrativeActivityKey !== '' && !in_array($initialAdministrativeActivityKey, $allowedAdministrativeActivityTabs, true)) {
          $initialAdministrativeActivityKey = '';
        }
        if (!$canAccessAdministrativeActivities) {
          $initialTab = '';
        }
      } elseif ($initialTabPermissionKey !== '' && !in_array($initialTabPermissionKey, $dashboardAllowedTabs, true)) {
        $initialTab = '';
      }
    }
    if ($initialTab === '') {
      $initialTab = 'scm-panel-inicio';
    }
    if ($initialAdministrativeActivityKey === '' || !in_array($initialAdministrativeActivityKey, $allowedAdministrativeActivityTabs, true)) {
      $initialAdministrativeActivityKey = (string) ($allowedAdministrativeActivityTabs[0] ?? '');
    }

    $activeOpenTopic = isset($openTopicDefs[$initialOpenTopic]) ? $initialOpenTopic : 'mant';
    $hydrateMaintenanceRows = false;
    $result = [
      'rows' => [],
      'stats' => ['total' => 0],
      'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'total_pages' => 1],
      'tbody' => '',
      'pagination_html' => '',
    ];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $tbodyHtml = $hydrateMaintenanceRows
      ? (string)($result['tbody'] ?? '')
      : $this->render_lazy_tickets_placeholder('Abre Mantenimiento para cargar los tickets.');
    $paginationHtml = $hydrateMaintenanceRows ? (string)($result['pagination_html'] ?? '') : '';

    $homeDisplayName = trim((string) Auth::user());
    $homeNameParts = preg_split('/\s+/u', $homeDisplayName, -1, PREG_SPLIT_NO_EMPTY);
    $homeFirstName = (string)($homeNameParts[0] ?? '');
    $homeQuickLinks = [];
    if (in_array('abiertos', $dashboardAllowedTabs, true)) {
      $homeQuickLinks[] = ['panel' => 'scm-panel-abiertos', 'icon' => 'fa-inbox', 'label' => 'Tickets abiertos'];
    }
    if (in_array('mis_tickets', $dashboardAllowedTabs, true)) {
      $homeQuickLinks[] = ['panel' => 'scm-panel-mis-tickets', 'icon' => 'fa-user-check', 'label' => 'Mis tickets'];
    }
    if ($canAccessAdministrativeActivities) {
      $homeQuickLinks[] = ['panel' => 'scm-panel-actividades-administrativas', 'icon' => 'fa-calendar-check', 'label' => 'Actividades administrativas'];
    }
    if (in_array('contratos_arrendamiento', $dashboardAllowedTabs, true)) {
      $homeQuickLinks[] = ['panel' => 'scm-panel-contratos-arrendamiento', 'icon' => 'fa-file-contract', 'label' => 'Contratos'];
    }
    if (in_array('metricas', $dashboardAllowedTabs, true)) {
      $homeQuickLinks[] = ['panel' => 'scm-panel-metricas', 'icon' => 'fa-chart-line', 'label' => 'Métricas'];
    }

    $nonce = \SCM\Core\App::csrf()->token(self::NONCE_KEY);
    $apiUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/api.php') : '/api.php';
    $runtimeData = [
      'ajaxUrl'      => $apiUrl,
      'nonce'        => $nonce,
      'config'       => $config,
      'indicativos'  => $this->getIndicativoOptions(),
      'funcionarios' => $filterOptions['funcionarios'] ?? [],
      'initialTab' => $initialTab,
      'initialOpenTopic' => $initialOpenTopic,
      'iframeMode' => $iframeMode,
      'initialFilters' => array_map(
        static fn($value): string => is_scalar($value) ? trim((string) $value) : '',
        $_GET
      ),
      'guide'   => [
        'enabled' => true,
        'title'   => 'Guia de uso',
        'html'    => '<ul><li>Usa filtros para acotar resultados.</li><li>Atraso desde creacion = dias transcurridos desde que se creo el ticket.</li><li>Sin actualizar desde gestion = dias desde la ultima actualizacion o seguimiento.</li></ul>',
      ],
      'actions' => [
        'mant'            => self::AJAX_ACTION,
        'seg'             => self::AJAX_SEGUIMIENTO,
        'nota'            => self::AJAX_NOTA,
        'ticket_response' => self::AJAX_TICKET_RESPONSE,
        'postpone_ticket' => self::AJAX_POSTPONE_TICKET,
        'status_tickets'  => self::AJAX_STATUS_TICKETS,
        'my_tickets'      => self::AJAX_MY_TICKETS,
        'cotizaciones_mantenimiento' => self::AJAX_COTIZACIONES_MANTENIMIENTO,
        'actas_satisfaccion' => self::AJAX_TICKET_COMPLETION_LIST,
        'delete_cotizacion' => self::AJAX_DELETE_COTIZACION,
        'cotizacion_pdf' => self::AJAX_COTIZACION_MANTENIMIENTO_PDF,
        'activate_ticket' => self::AJAX_ACTIVATE_TICKET,
        'cotizacion_response' => self::AJAX_COTIZACION_RESPONSE,
        'close_ticket'    => self::AJAX_CLOSE_TICKET,
        'contacts_update' => self::AJAX_CONTACTS_UPDATE,
        'entrega'         => self::AJAX_ENTREGA,
        'preventiva'      => self::AJAX_PREVENTIVA,
        'recibo'          => self::AJAX_RECIBO,
        'contable'        => self::AJAX_CONTABLE,
        'certificaciones' => self::AJAX_CERTIFICACIONES,
        'contractual'     => self::AJAX_CONTRACTUAL,
        'preventivas_pendientes' => self::AJAX_PREVENTIVAS_PENDIENTES,
        'servicios_publicos_pendientes' => self::AJAX_SERVICIOS_PUBLICOS_PENDIENTES,
        'revision_servicios_publicos' => self::AJAX_REVISION_SERVICIOS_PUBLICOS,
        'reportes_administrativos_pendientes' => self::AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES,
        'contratos_arrendamiento' => self::AJAX_CONTRATOS_ARRENDAMIENTO,
        'contrato_recibido' => self::AJAX_CONTRATO_RECIBIDO,
        'contrato_ultima_preventiva' => self::AJAX_CONTRATO_ULTIMA_PREVENTIVA,
        'crear_ticket_administrativo' => self::AJAX_CREAR_TICKET_ADMINISTRATIVO,
        'aprobar_reporte_administrativo' => self::AJAX_APROBAR_REPORTE_ADMINISTRATIVO,
        'desaprobar_reporte_administrativo' => self::AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO,
        'damage_magnitude' => self::AJAX_DAMAGE_MAGNITUDE,
        'classify_magnitude' => self::AJAX_CLASSIFY_MAGNITUDE,
        'save_case_magnitude' => self::AJAX_SAVE_CASE_MAGNITUDE,
        'save_property_location' => self::AJAX_SAVE_PROPERTY_LOCATION,
        'trasladar_caso' => self::AJAX_TRASLADAR_CASO,
        'asignar_pqr_publico' => self::AJAX_ASIGNAR_PQR_PUBLICO,
        'filtrar_pqr_publico' => self::AJAX_FILTER_PQR_PUBLICO,
        'guardar_corresponsable_pqr_publico' => self::AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO,
        'notif_responsable_pqr' => self::AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR,
        'session_heartbeat' => self::AJAX_SESSION_HEARTBEAT,
        'dashboard_permissions_read' => self::AJAX_DASHBOARD_PERMISSIONS_READ,
        'dashboard_permissions_save' => self::AJAX_DASHBOARD_PERMISSIONS_SAVE,
        'calendar_cita_notify' => self::AJAX_CALENDAR_CITA_NOTIFY,
        'admin_notifications_recipients' => self::AJAX_ADMIN_NOTIFICATIONS_RECIPIENTS,
        'admin_notifications_panel' => self::AJAX_ADMIN_NOTIFICATIONS_PANEL,
        'admin_notifications_send' => self::AJAX_ADMIN_NOTIFICATIONS_SEND,
        'admin_notifications_import' => self::AJAX_ADMIN_NOTIFICATIONS_IMPORT,
        'admin_notifications_collection' => self::AJAX_ADMIN_NOTIFICATIONS_COLLECTION,
        'admin_notifications_collection_options' => self::AJAX_ADMIN_NOTIFICATIONS_COLLECTION_OPTIONS,
        'admin_notifications_collection_queue' => self::AJAX_ADMIN_NOTIFICATIONS_COLLECTION_QUEUE,
        'admin_notifications_collection_log' => self::AJAX_ADMIN_NOTIFICATIONS_COLLECTION_LOG,
        'collection_portfolio_import' => self::AJAX_COLLECTION_PORTFOLIO_IMPORT,
        'collection_portfolio_action' => self::AJAX_COLLECTION_PORTFOLIO_ACTION,
        'collection_portfolio_pdf' => self::AJAX_COLLECTION_PORTFOLIO_PDF,
        'internal_notifications_save' => self::AJAX_INTERNAL_NOTIFICATIONS_SAVE,
        'public_pqr_settings_read' => self::AJAX_PUBLIC_PQR_SETTINGS_READ,
        'internal_notifications_read' => self::AJAX_INTERNAL_NOTIFICATIONS_READ,
        'metrics_execution' => self::AJAX_METRICS_EXECUTION,
        'dashboard_home' => self::AJAX_DASHBOARD_HOME,
        'dashboard_metrics' => self::AJAX_DASHBOARD_METRICS,
        'dashboard_filter_options' => self::AJAX_DASHBOARD_FILTER_OPTIONS,
        'canon_insurance_audit_list' => self::AJAX_CANON_INSURANCE_AUDIT_LIST,
        'canon_insurance_audit_import' => self::AJAX_CANON_INSURANCE_AUDIT_IMPORT,
        'canon_insurance_audit_observation' => self::AJAX_CANON_INSURANCE_AUDIT_OBSERVATION,
        'canon_insurance_audit_report' => self::AJAX_CANON_INSURANCE_AUDIT_REPORT,
        'canon_insurance_audit_mandates' => self::AJAX_CANON_INSURANCE_AUDIT_MANDATES,
        'canon_insurance_audit_link_mandate' => self::AJAX_CANON_INSURANCE_AUDIT_LINK_MANDATE,
        'canon_insurance_audit_requests' => self::AJAX_CANON_INSURANCE_AUDIT_REQUESTS,
        'canon_insurance_audit_update_request' => self::AJAX_CANON_INSURANCE_AUDIT_UPDATE_REQUEST,
        'canon_insurance_audit_update_platform_values' => self::AJAX_CANON_INSURANCE_AUDIT_UPDATE_PLATFORM_VALUES,
        'canon_insurance_audit_purge' => self::AJAX_CANON_INSURANCE_AUDIT_PURGE,
        // Guía
        'guide_gcd_read' => self::AJAX_GUIDE_GCD_READ,
        'guide_gcd_save' => self::AJAX_GUIDE_GCD_SAVE,
        'guide_gcd_del'  => self::AJAX_GUIDE_GCD_DEL,
        'guide_grt_read' => self::AJAX_GUIDE_GRT_READ,
        'guide_grt_save' => self::AJAX_GUIDE_GRT_SAVE,
        'guide_grt_del'  => self::AJAX_GUIDE_GRT_DEL,
        'guide_gac_read' => self::AJAX_GUIDE_GAC_READ,
        'guide_gac_save' => self::AJAX_GUIDE_GAC_SAVE,
        'guide_gac_del'  => self::AJAX_GUIDE_GAC_DEL,
        'guide_gac_cats' => self::AJAX_GUIDE_GAC_CATS,
      ],
      'dashboardPermissions' => [
        'canManage' => $canManageDashboardPermissions,
        'cargo' => Auth::userCargo(),
        'allowedTabs' => $dashboardAllowedTabs,
        'tabs' => $dashboardPermissionTabs,
        'cargos' => $dashboardCargoOptions,
        'permissions' => $dashboardPermissionConfig,
        'employeeCargoIds' => $dashboardFuncionarioCargoIds,
      ],
      'session' => [
        'loginUrl' => rtrim((string) SCM_BASE_URL, '/') . '/login.php',
        'idleTimeoutSeconds' => defined('SCM_SESSION_IDLE_TIMEOUT') ? (int) SCM_SESSION_IDLE_TIMEOUT : 7200,
        'heartbeatIntervalMs' => 240000,
      ],
    ];

    $metricsData = [
      'total'          => (int)($stats['total'] ?? 0),
      'abiertos'       => (int)($stats['abiertos'] ?? 0),
      'cerrados'       => (int)($stats['cerrados'] ?? 0),
      'sla_vencido'    => (int)($stats['sla_vencido'] ?? 0),
      'sla_riesgo'     => (int)($stats['sla_riesgo'] ?? 0),
      'con_cotizacion' => (int)($stats['con_cotizacion'] ?? 0),
      'sin_cotizacion' => (int)($stats['sin_cotizacion'] ?? 0),
      'con_revision'   => (int)($stats['con_revision'] ?? 0),
      'sin_revision'   => (int)($stats['sin_revision'] ?? 0),
      'avg_first_h'    => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? (float)$stats['avg_first_h'] : null,
      'avg_close_h'    => isset($stats['avg_close_h']) && is_numeric($stats['avg_close_h']) ? (float)$stats['avg_close_h'] : null,
      'avg_stale_h'    => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? (float)$stats['avg_stale_h'] : null,
      'mes_actualizados' => (int)($stats['mes_actualizados'] ?? 0),
      'mes_cerrados' => (int)($stats['mes_cerrados'] ?? 0),
      'mes_seguimientos' => (int)($stats['mes_seguimientos'] ?? 0),
      'web' => $webTicketStats,
      'por_categoria' => $categoryMetrics,
      'detalle_por_categoria' => [
        'mantenimiento' => [
          'label' => 'Mantenimiento',
          'total' => (int)($stats['total'] ?? 0),
          'abiertos' => (int)($stats['abiertos'] ?? 0),
          'cerrados' => (int)($stats['cerrados'] ?? 0),
          'sla_vencido' => (int)($stats['sla_vencido'] ?? 0),
          'sla_riesgo' => (int)($stats['sla_riesgo'] ?? 0),
          'con_cotizacion' => (int)($stats['con_cotizacion'] ?? 0),
          'sin_cotizacion' => (int)($stats['sin_cotizacion'] ?? 0),
          'con_revision' => (int)($stats['con_revision'] ?? 0),
          'sin_revision' => (int)($stats['sin_revision'] ?? 0),
          'avg_first_h' => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? (float)$stats['avg_first_h'] : 0.0,
          'avg_close_h' => isset($stats['avg_close_h']) && is_numeric($stats['avg_close_h']) ? (float)$stats['avg_close_h'] : 0.0,
          'avg_stale_h' => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? (float)$stats['avg_stale_h'] : 0.0,
          'mes_actualizados' => (int)($stats['mes_actualizados'] ?? 0),
          'mes_cerrados' => (int)($stats['mes_cerrados'] ?? 0),
          'mes_seguimientos' => (int)($stats['mes_seguimientos'] ?? 0),
          'seg_por_funcionario' => is_array($stats['seg_por_funcionario'] ?? null) ? $stats['seg_por_funcionario'] : [],
          'abiertos_por_funcionario' => is_array($stats['abiertos_por_funcionario'] ?? null) ? $stats['abiertos_por_funcionario'] : [],
          'actualizados_por_funcionario' => is_array($stats['actualizados_por_funcionario'] ?? null) ? $stats['actualizados_por_funcionario'] : [],
        ],
        'entrega' => ['label' => 'Entrega'] + (array)($genericResults['entrega']['result']['stats'] ?? []),
        'preventiva' => ['label' => 'Preventiva'] + (array)($genericResults['preventiva']['result']['stats'] ?? []),
        'recibo' => ['label' => 'Recibo'] + (array)($genericResults['recibo']['result']['stats'] ?? []),
        'contable' => ['label' => 'Contable'] + (array)($genericResults['contable']['result']['stats'] ?? []),
        'certificaciones' => ['label' => 'Certificaciones'] + (array)($genericResults['certificaciones']['result']['stats'] ?? []),
        'contractual' => ['label' => 'Contractual'] + (array)($genericResults['contractual']['result']['stats'] ?? []),
      ],
    ];

    $runtimeJson   = json_encode($runtimeData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $metricsJson   = json_encode($metricsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $assetBaseUrl  = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

    $cssRel = 'assets/css/scm-admin.css';
    $jsRel  = 'assets/js/scm-admin.js';
    $sessionGuardJsRel = 'assets/js/scm-session-guard.js';
    $dashboardRuntimeJsRel = 'assets/js/admin-dashboard-runtime.js';
    $collectionManagementJsRel = 'assets/js/admin-collection-management.js';
    $canonInsuranceAuditJsRel = 'assets/js/admin-canon-insurance-audit.js';
    $guideJsRel = 'assets/js/admin-guide.js';
    $damageCssRel = 'assets/css/scm-damage-magnitude.css';
    $damageJsRel  = 'assets/js/scm-damage-magnitude.js';
    $cssPath = $assetBasePath . $cssRel;
    $jsPath  = $assetBasePath . $jsRel;
    $sessionGuardJsPath = $assetBasePath . $sessionGuardJsRel;
    $dashboardRuntimeJsPath = $assetBasePath . $dashboardRuntimeJsRel;
    $collectionManagementJsPath = $assetBasePath . $collectionManagementJsRel;
    $canonInsuranceAuditJsPath = $assetBasePath . $canonInsuranceAuditJsRel;
    $guideJsPath = $assetBasePath . $guideJsRel;
    $damageCssPath = $assetBasePath . $damageCssRel;
    $damageJsPath  = $assetBasePath . $damageJsRel;
    $assetVersion = static function (string $path): string {
      $baseVersion = defined('SCM_VERSION') ? (string) SCM_VERSION : '1';
      return $baseVersion . '-' . (file_exists($path) ? (string) filemtime($path) : '0');
    };
    $cssVer = $assetVersion($cssPath);
    $jsVer  = $assetVersion($jsPath);
    $sessionGuardJsVer = $assetVersion($sessionGuardJsPath);
    $dashboardRuntimeJsVer = $assetVersion($dashboardRuntimeJsPath);
    $collectionManagementJsVer = $assetVersion($collectionManagementJsPath);
    $canonInsuranceAuditJsVer = $assetVersion($canonInsuranceAuditJsPath);
    $guideJsVer = $assetVersion($guideJsPath);
    $damageCssVer = $assetVersion($damageCssPath);
    $damageJsVer  = $assetVersion($damageJsPath);
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $jsUrl  = self::h($assetBaseUrl . $jsRel  . '?v=' . rawurlencode($jsVer));
    $sessionGuardJsUrl = self::h($assetBaseUrl . $sessionGuardJsRel . '?v=' . rawurlencode($sessionGuardJsVer));
    $dashboardRuntimeJsUrl = self::h($assetBaseUrl . $dashboardRuntimeJsRel . '?v=' . rawurlencode($dashboardRuntimeJsVer));
    $collectionManagementJsUrl = self::h($assetBaseUrl . $collectionManagementJsRel . '?v=' . rawurlencode($collectionManagementJsVer));
    $canonInsuranceAuditJsUrl = self::h($assetBaseUrl . $canonInsuranceAuditJsRel . '?v=' . rawurlencode($canonInsuranceAuditJsVer));
    $guideJsUrl = self::h($assetBaseUrl . $guideJsRel . '?v=' . rawurlencode($guideJsVer));
    $damageCssUrl = self::h($assetBaseUrl . $damageCssRel . '?v=' . rawurlencode($damageCssVer));
    $damageJsUrl  = self::h($assetBaseUrl . $damageJsRel  . '?v=' . rawurlencode($damageJsVer));

    ob_start();
  ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" media="all">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" media="all">
    <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
    <link rel="stylesheet" href="<?php echo $damageCssUrl; ?>" media="all">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <script src="<?php echo $sessionGuardJsUrl; ?>" defer></script>
    <div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy" data-scm-runtime="<?php echo self::h((string)$runtimeJson); ?>">
      <div class="scm-panel-loader" data-scm-panel-loader hidden aria-hidden="true">
        <div class="scm-panel-loader-card" role="status" aria-live="polite" aria-atomic="true">
          <span class="scm-panel-loader-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M21 12a9 9 0 0 0-9-9"></path></svg>
          </span>
          <span class="scm-panel-loader-kicker">Preparando la vista</span>
          <strong data-scm-panel-loader-title>Cargando informaci&oacute;n</strong>
          <span data-scm-panel-loader-detail>Espera un momento mientras consultamos los datos.</span>
        </div>
      </div>
      <div class="scm-guide-bar">
        <?php if ($canManageDashboardPermissions): ?>
          <button class="scm-guide-btn scm-permissions-btn" type="button" id="scm-open-permissions">Configurar permisos</button>
        <?php endif; ?>
        <?php if ($canManagePublicPqrSettings): ?>
          <button class="scm-guide-btn scm-pqr-settings-shortcut" type="button" id="scm-open-pqr-settings">Configurar notificaciones</button>
        <?php endif; ?>
        <?php if ($canManageInternalNotificationSettings): ?>
          <button class="scm-guide-btn scm-internal-notifications-shortcut" type="button" id="scm-open-internal-notifications">Notificaciones internas</button>
        <?php endif; ?>
        <button class="scm-guide-btn" type="button" id="scm-open-guide"><i class="fas fa-book-open"></i> Ver gu&iacute;as</button>
      </div>
      <div class="scm-tabs scm-main-tabs">
        <button class="scm-tab<?php echo $initialTab === 'scm-panel-inicio' ? ' active' : ''; ?>" data-tab="scm-panel-inicio" type="button"><i class="fas fa-house" aria-hidden="true"></i> Inicio</button>
        <?php foreach ($dashboardPanelToTab as $panelId => $permissionKey): ?>
          <?php
          if ($permissionKey === 'actividades_administrativas') {
            if (!$canAccessAdministrativeActivities) continue;
            $tabLabel = 'Actividades administrativas';
          } else {
            if (!in_array($permissionKey, $dashboardAllowedTabs, true)) continue;
            $tabLabel = (string)($dashboardPermissionTabs[$permissionKey] ?? $permissionKey);
          }
          ?>
          <button class="scm-tab<?php echo $initialTab === $panelId ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($panelId); ?>" data-permission-tab="<?php echo esc_attr($permissionKey); ?>" type="button"><?php echo esc_html($tabLabel); ?></button>
        <?php endforeach; ?>
      </div>

      <section class="scm-tab-panel scm-home-panel<?php echo $initialTab === 'scm-panel-inicio' ? ' active' : ''; ?>" id="scm-panel-inicio" data-scm-loaded="0" aria-busy="true" aria-labelledby="scm-home-title">
        <div class="scm-home-hero">
          <div class="scm-home-hero-copy">
            <span class="scm-home-eyebrow">Resumen operativo</span>
            <h1 id="scm-home-title"><?php echo $homeFirstName !== '' ? 'Hola, ' . esc_html($homeFirstName) : 'Hola'; ?></h1>
            <p>Consulta lo más importante del día y entra directamente al proceso que necesitas.</p>
          </div>
          <?php if (in_array('abiertos', $dashboardAllowedTabs, true)): ?>
            <button class="scm-home-primary-action" type="button" data-scm-home-target="scm-panel-abiertos">
              Ver tickets abiertos <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>
          <?php elseif (in_array('metricas', $dashboardAllowedTabs, true)): ?>
            <button class="scm-home-primary-action" type="button" data-scm-home-target="scm-panel-metricas">
              Ver métricas <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
        </div>

        <div class="scm-home-status" data-scm-home-status role="status" aria-live="polite">
          <span class="scm-home-status-spinner" aria-hidden="true"></span>
          <span data-scm-home-status-text>Cargando el resumen&hellip;</span>
          <button type="button" data-scm-home-retry hidden>Reintentar</button>
        </div>

        <div class="scm-home-kpis" aria-label="Indicadores principales">
          <article class="scm-home-kpi scm-home-kpi-total">
            <span class="scm-home-kpi-icon"><i class="fas fa-layer-group" aria-hidden="true"></i></span>
            <div><span>Total de casos</span><strong data-scm-home-metric="total">&mdash;</strong></div>
          </article>
          <article class="scm-home-kpi scm-home-kpi-open">
            <span class="scm-home-kpi-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
            <div><span>Abiertos</span><strong data-scm-home-metric="abiertos">&mdash;</strong></div>
          </article>
          <article class="scm-home-kpi scm-home-kpi-overdue">
            <span class="scm-home-kpi-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
            <div><span>SLA vencido</span><strong data-scm-home-metric="sla_vencido">&mdash;</strong></div>
          </article>
          <article class="scm-home-kpi scm-home-kpi-risk">
            <span class="scm-home-kpi-icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
            <div><span>En riesgo</span><strong data-scm-home-metric="sla_riesgo">&mdash;</strong></div>
          </article>
          <article class="scm-home-kpi scm-home-kpi-closed">
            <span class="scm-home-kpi-icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
            <div><span>Cerrados</span><strong data-scm-home-metric="cerrados">&mdash;</strong></div>
          </article>
        </div>

        <div class="scm-home-content-grid">
          <section class="scm-home-card" aria-labelledby="scm-home-priorities-title">
            <div class="scm-home-card-heading">
              <div>
                <span class="scm-home-card-kicker">Atención</span>
                <h2 id="scm-home-priorities-title">Prioridades de hoy</h2>
              </div>
              <?php if (in_array('abiertos', $dashboardAllowedTabs, true)): ?>
                <button class="scm-home-text-action" type="button" data-scm-home-target="scm-panel-abiertos">Revisar casos</button>
              <?php endif; ?>
            </div>
            <div class="scm-home-priority-list">
              <div class="scm-home-priority scm-home-priority-danger">
                <span class="scm-home-priority-dot" aria-hidden="true"></span>
                <div><strong data-scm-home-metric="sla_vencido">&mdash;</strong><span>casos con SLA vencido</span></div>
              </div>
              <div class="scm-home-priority scm-home-priority-warning">
                <span class="scm-home-priority-dot" aria-hidden="true"></span>
                <div><strong data-scm-home-metric="sin_revision">&mdash;</strong><span>casos sin revisión</span></div>
              </div>
              <div class="scm-home-priority scm-home-priority-neutral">
                <span class="scm-home-priority-dot" aria-hidden="true"></span>
                <div><strong data-scm-home-metric="sin_cotizacion">&mdash;</strong><span>casos sin cotización</span></div>
              </div>
            </div>
          </section>

          <section class="scm-home-card" aria-labelledby="scm-home-processes-title">
            <div class="scm-home-card-heading">
              <div>
                <span class="scm-home-card-kicker">Volumen</span>
                <h2 id="scm-home-processes-title">Casos por proceso</h2>
              </div>
              <?php if (in_array('metricas', $dashboardAllowedTabs, true)): ?>
                <button class="scm-home-text-action" type="button" data-scm-home-target="scm-panel-metricas">Ver detalle</button>
              <?php endif; ?>
            </div>
            <div class="scm-home-process-list">
              <?php foreach (['Mantenimiento', 'Entrega', 'Preventiva', 'Recibo'] as $homeCategory): ?>
                <div class="scm-home-process">
                  <span><?php echo esc_html($homeCategory); ?></span>
                  <strong data-scm-home-category="<?php echo esc_attr($homeCategory); ?>">&mdash;</strong>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        </div>

        <?php if (!empty($homeQuickLinks)): ?>
          <section class="scm-home-shortcuts" aria-labelledby="scm-home-shortcuts-title">
            <div class="scm-home-card-heading">
              <div>
                <span class="scm-home-card-kicker">Navegación</span>
                <h2 id="scm-home-shortcuts-title">Accesos rápidos</h2>
              </div>
              <span class="scm-home-updated" data-scm-home-updated></span>
            </div>
            <div class="scm-home-shortcut-grid">
              <?php foreach ($homeQuickLinks as $homeQuickLink): ?>
                <button class="scm-home-shortcut" type="button" data-scm-home-target="<?php echo esc_attr((string)$homeQuickLink['panel']); ?>">
                  <span><i class="fas <?php echo esc_attr((string)$homeQuickLink['icon']); ?>" aria-hidden="true"></i></span>
                  <strong><?php echo esc_html((string)$homeQuickLink['label']); ?></strong>
                  <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </button>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </section>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-abiertos' ? ' active' : ''; ?>" id="scm-panel-abiertos" data-permission-tab="abiertos">
        <div class="scm-status-bucket scm-open-bucket" data-open-bucket="abiertos">
          <div class="scm-status-subtabs scm-open-subtabs" role="tablist" aria-label="Tickets abiertos">
            <?php foreach ($openTopicDefs as $openTopicKey => $openTopicDef): ?>
              <button class="scm-status-topic-tab scm-open-topic-tab<?php echo $activeOpenTopic === $openTopicKey ? ' active' : ''; ?>" type="button" data-open-target="<?php echo esc_attr($openTopicKey); ?>"><?php echo esc_html((string)($openTopicDef['label'] ?? $openTopicKey)); ?></button>
            <?php endforeach; ?>
          </div>

      <div class="scm-open-topic-panel<?php echo $activeOpenTopic === 'mant' ? ' active' : ''; ?>" id="scm-panel-mant" data-open-topic="mant" data-scm-loaded="<?php echo $hydrateMaintenanceRows ? '1' : '0'; ?>">
        <div class="scm-kpis scm-kpis-daisy">
          <div class="scm-kpi">
            <div class="scm-kpi-label">Total</div>
            <div class="scm-kpi-value" id="scm-kpi-total"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Con cotizaci&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-con-cotz"><?php echo esc_html((string)($stats['con_cotizacion'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Sin cotizaci&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-sin-cotz"><?php echo esc_html((string)($stats['sin_cotizacion'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Con revisi&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-con-prev"><?php echo esc_html((string)($stats['con_revision'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Sin revisi&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-sin-prev"><?php echo esc_html((string)($stats['sin_revision'] ?? 0)); ?></div>
          </div>

          <div class="scm-kpi scm-kpi-magnitud scm-kpi-critico">
            <div class="scm-kpi-label">Cr&iacute;ticos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-critico"><?php echo esc_html((string)($stats['magnitud_critico'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-alto">
            <div class="scm-kpi-label">Altos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-alto"><?php echo esc_html((string)($stats['magnitud_alto'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-medio">
            <div class="scm-kpi-label">Medios</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-medio"><?php echo esc_html((string)($stats['magnitud_medio'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-bajo">
            <div class="scm-kpi-label">Bajos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-bajo"><?php echo esc_html((string)($stats['magnitud_bajo'] ?? 0)); ?></div>
          </div>
        </div>

        <div class="scm-filter-card card">
          <h3>Filtros</h3>
          <form id="scm-form" autocomplete="off">
            <input type="hidden" id="scm_page" name="scm_page" value="<?php echo esc_attr((string)($params['fPage'] ?? 1)); ?>">
            <div class="scm-grid">
              <div class="scm-field"><label for="scm_estado">Estado</label><select id="scm_estado" name="scm_estado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions['estado'] ?? []) as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected(($params['fEstado'] ?? ''), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_estado_admin">Estado administrativo</label><select id="scm_estado_admin" name="scm_estado_admin" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["estado_admin"] ?? []) as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected(($params["fEstadoAdmin"] ?? ""), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_origen">Origen</label><select id="scm_origen" name="scm_origen" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="web" <?php selected(($params['fOrigen'] ?? ''), 'web'); ?>>Guardian</option>
                  <option value="interno" <?php selected(($params['fOrigen'] ?? ''), 'interno'); ?>>No Guardian</option>
                </select></div>
              <div class="scm-field"><label for="scm_id_empleado">Funcionario</label><select id="scm_id_empleado" name="scm_id_empleado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["funcionarios"] ?? []) as $func): $fId = trim((string)($func["id"] ?? ""));
                                                    if ($fId === "") continue; ?>
                    <option value="<?php echo esc_attr($fId); ?>" <?php selected(($params["fEmpleado"] ?? ""), $fId); ?>><?php echo esc_html((string)($func["label"] ?? $fId)); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_inmueble">Inmueble SIMI</label><input id="scm_inmueble" name="scm_inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params['fInmueble'] ?? '')); ?>"></div>
              <div class="scm-field"><label for="scm_contrato">Contrato</label><input id="scm_contrato" name="scm_contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fContrato"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_caso"># caso</label><input id="scm_caso" name="scm_caso" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fCaso"] ?? "")); ?>" placeholder="Ej: 10368"></div>
              <div class="scm-field"><label for="scm_asunto">Asunto</label><input id="scm_asunto" name="scm_asunto" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fAsunto"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_arrendatario">Arrendatario</label><input id="scm_arrendatario" name="scm_arrendatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fArrendatario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_propietario">Propietario</label><input id="scm_propietario" name="scm_propietario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fPropietario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_barrio">Barrio</label><select id="scm_barrio" name="scm_barrio" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["barrios"] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string)$barrioOpt); ?>" <?php selected((string)($params["fBarrio"] ?? ""), (string)$barrioOpt); ?>><?php echo esc_html((string)$barrioOpt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field scm-date-range-field"><label id="scm_fecha_rango_label">Fecha</label><div class="scm-date-range-control" role="group" aria-labelledby="scm_fecha_rango_label"><div class="scm-date-range-part"><span>Desde</span><input id="scm_fecha_desde" name="scm_fecha_desde" type="date" value="<?php echo esc_attr((string)($params["fFechaDesde"] ?? "")); ?>" aria-label="Fecha desde"></div><span class="scm-date-range-separator" aria-hidden="true">&rarr;</span><div class="scm-date-range-part"><span>Hasta</span><input id="scm_fecha_hasta" name="scm_fecha_hasta" type="date" value="<?php echo esc_attr((string)($params["fFechaHasta"] ?? "")); ?>" aria-label="Fecha hasta"></div></div></div>

              <div class="scm-field"><label for="scm_cotizacion">Cotizaci&oacute;n</label><select id="scm_cotizacion" name="scm_cotizacion" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(($params['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
                  <option value="none" <?php selected(($params['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
                </select></div>
              <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="scm_cotizacion"><label for="scm_cotizacion_estado">Estado cotizaci&oacute;n</label><select id="scm_cotizacion_estado" name="scm_cotizacion_estado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["cotizacion_estado"] ?? []) as $cotEstadoOpt): ?><option value="<?php echo esc_attr((string)$cotEstadoOpt); ?>" <?php selected((string)($params["fCotizacionEstado"] ?? ""), (string)$cotEstadoOpt); ?>><?php echo esc_html((string)$cotEstadoOpt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="scm_cotizacion"><label for="scm_cotizacion_enviada">Fue enviada</label><select id="scm_cotizacion_enviada" name="scm_cotizacion_enviada" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="si" <?php selected(($params['fCotizacionEnviada'] ?? ''), 'si'); ?>>S&iacute;</option>
                  <option value="no" <?php selected(($params['fCotizacionEnviada'] ?? ''), 'no'); ?>>No</option>
                </select></div>
              <div class="scm-field"><label for="scm_perturbacion">Perturbaci&oacute;n</label><select id="scm_perturbacion" name="scm_perturbacion" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(strtolower((string) ($params['fPerturbacion'] ?? '')), 'has'); ?>>Con perturbaci&oacute;n</option>
                  <option value="none" <?php selected(strtolower((string) ($params['fPerturbacion'] ?? '')), 'none'); ?>>Sin perturbaci&oacute;n</option>
                </select></div>
              <div class="scm-field"><label for="scm_revision">Revisi&oacute;n</label><select id="scm_revision" name="scm_revision" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(($params['fRevision'] ?? ''), 'has'); ?>>Con revisi&oacute;n</option>
                  <option value="none" <?php selected(($params['fRevision'] ?? ''), 'none'); ?>>Sin revisi&oacute;n</option>
                </select></div>
              <div class="scm-field"><label for="scm_atraso">Atraso desde creaci&oacute;n</label><select id="scm_atraso" name="scm_atraso" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="3" <?php selected(($params['fAtraso'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
                  <option value="5" <?php selected(($params['fAtraso'] ?? ''), '5'); ?>>+5 d&iacute;as</option>
                  <option value="10" <?php selected(($params['fAtraso'] ?? ''), '10'); ?>>+10 d&iacute;as</option>
                </select></div>
              <div class="scm-field"><label for="scm_sin_actualizar">Sin actualizar desde gesti&oacute;n</label><select id="scm_sin_actualizar" name="scm_sin_actualizar" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="1" <?php selected(($params['fSinActualizar'] ?? ''), '1'); ?>>+1 d&iacute;a</option>
                  <option value="3" <?php selected(($params['fSinActualizar'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
                  <option value="7" <?php selected(($params['fSinActualizar'] ?? ''), '7'); ?>>+7 d&iacute;as</option>
                </select></div>
              <div class="scm-field"><label for="scm_tuvo_seguimiento">Tuvo seguimiento</label><select id="scm_tuvo_seguimiento" name="scm_tuvo_seguimiento" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="Si" <?php selected(($params['fTuvoSeguimiento'] ?? ''), 'Si'); ?>>Si</option>
                  <option value="No" <?php selected(($params['fTuvoSeguimiento'] ?? ''), 'No'); ?>>No</option>
                </select></div>
              <div class="scm-field"><label for="scm_magnitud_caso">Magnitud caso</label><select id="scm_magnitud_caso" name="scm_magnitud_caso" class="select select-bordered select-sm scm-select">
                  <option value="">Todas las magnitudes</option>
                  <option value="critico" <?php selected(($params['fMagnitudCaso'] ?? ''), 'critico'); ?>>Cr&iacute;tico</option>
                  <option value="alto" <?php selected(($params['fMagnitudCaso'] ?? ''), 'alto'); ?>>Alto</option>
                  <option value="medio" <?php selected(($params['fMagnitudCaso'] ?? ''), 'medio'); ?>>Medio</option>
                  <option value="bajo" <?php selected(($params['fMagnitudCaso'] ?? ''), 'bajo'); ?>>Bajo</option>
                </select></div>
            </div>
            <div class="scm-actions">
              <button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button>
              <button class="scm-btn-secondary btn btn-outline scm-classify-magnitude" type="button" data-revision-type="correctiva">Calcular magnitud da&ntilde;o</button>
              <button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear">Limpiar</button>
              <span class="scm-spinner" id="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
            </div>
          </form>
        </div>

        <div class="scm-cards-wrap">
          <div class="scm-ticket-cards" id="scm-tbody"><?php echo $tbodyHtml; ?></div>
        </div>
        <div class="scm-pagination" id="scm-pagination"><?php echo $paginationHtml; ?></div>
      </div>

      <?php foreach (['preventiva', 'entrega', 'recibo', 'contractual', 'contable', 'certificaciones'] as $gTabKey): $gTabData = $genericResults[$gTabKey];
        $gParams = $gTabData['params'];
        $gRows = $gTabData['result']['rows'];
        $gStats = $gTabData['result']['stats'];
        $gLoaded = !empty($gTabData['loaded']);
        $gPagination = is_array($gTabData['result']['pagination'] ?? null) ? $gTabData['result']['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
        $gCardsHtml = $gLoaded ? $this->render_generic_cards($gRows, $config, $gTabKey) : $this->render_lazy_tickets_placeholder();
        $showTema = false;
        $gFilterOptions = $filterOptions;
        $gFormHtml = $this->render_generic_filter_form($gTabKey, $gTabData['def']['prefix'], $gParams, $showTema, ['Procesos juridicos', 'Solicitud contractual', 'Solicitud de servicios publicos', 'Retencion de contrato', 'Otros servicios'], $gFilterOptions);
        $gPaginationHtml = $gLoaded ? $this->get_generic_tickets_module()->render_generic_pagination($gTabKey, $gPagination) : ''; ?>
        <div class="scm-open-topic-panel<?php echo $activeOpenTopic === $gTabKey ? ' active' : ''; ?>" id="scm-panel-<?php echo esc_attr($gTabKey); ?>" data-open-topic="<?php echo esc_attr($gTabKey); ?>" data-scm-loaded="<?php echo $gLoaded ? '1' : '0'; ?>">
          <span id="scm-<?php echo esc_attr($gTabKey); ?>-count" style="display:none;"><?php echo esc_html((string)($gStats['total'] ?? 0)); ?></span>
          <div class="scm-kpis scm-kpis-daisy">
            <div class="scm-kpi">
              <div class="scm-kpi-label">Total</div>
              <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-total"><?php echo esc_html((string)($gStats['total'] ?? 0)); ?></div>
            </div>
            <?php if ($gTabKey === 'entrega'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n de entrega</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-con-rev-entrega"><?php echo esc_html((string)($gStats['con_revision_entrega'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n de entrega</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-sin-rev-entrega"><?php echo esc_html((string)($gStats['sin_revision_entrega'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con inventario</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-con-inventario"><?php echo esc_html((string)($gStats['con_inventario'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin inventario</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-sin-inventario"><?php echo esc_html((string)($gStats['sin_inventario'] ?? 0)); ?></div>
              </div>
            <?php elseif (in_array($gTabKey, ['contractual', 'contable', 'certificaciones'], true)): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Nuevo</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-nuevo"><?php echo esc_html((string)($gStats['estado_nuevo'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">En proceso</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-en-proceso"><?php echo esc_html((string)($gStats['estado_en_proceso'] ?? 0)); ?></div>
              </div>
            <?php elseif ($gTabKey === 'recibo'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con cita</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-con-cita"><?php echo esc_html((string)($gStats['con_cita'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin cita</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-sin-cita"><?php echo esc_html((string)($gStats['sin_cita'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n de recibo</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-con-rev-recibo"><?php echo esc_html((string)($gStats['con_revision_recibo'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n de recibo</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-sin-rev-recibo"><?php echo esc_html((string)($gStats['sin_revision_recibo'] ?? 0)); ?></div>
              </div>
            <?php else: ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con cotizaci&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-con-cotz"><?php echo esc_html((string)($gStats['con_cotizacion'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin cotizaci&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-sin-cotz"><?php echo esc_html((string)($gStats['sin_cotizacion'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-con-prev"><?php echo esc_html((string)($gStats['con_revision'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-sin-prev"><?php echo esc_html((string)($gStats['sin_revision'] ?? 0)); ?></div>
              </div>
            <?php endif; ?>

            <?php if ($gTabKey === 'preventiva'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con da&ntilde;os</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-danos-si"><?php echo esc_html((string)($gStats['danos_si'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin da&ntilde;os</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-danos-no"><?php echo esc_html((string)($gStats['danos_no'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-critico">
                <div class="scm-kpi-label">Cr&iacute;ticos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-critico"><?php echo esc_html((string)($gStats['magnitud_critico'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-alto">
                <div class="scm-kpi-label">Altos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-alto"><?php echo esc_html((string)($gStats['magnitud_alto'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-medio">
                <div class="scm-kpi-label">Medios</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-medio"><?php echo esc_html((string)($gStats['magnitud_medio'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-bajo">
                <div class="scm-kpi-label">Bajos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-bajo"><?php echo esc_html((string)($gStats['magnitud_bajo'] ?? 0)); ?></div>
              </div>
            <?php endif; ?>
          </div>
          <?php echo $gFormHtml; ?>
          <div class="scm-cards-wrap">
            <div class="scm-ticket-cards" id="scm-cards-<?php echo esc_attr($gTabKey); ?>"><?php echo $gCardsHtml; ?></div>
          </div>
          <div class="scm-pagination" id="scm-pagination-<?php echo esc_attr($gTabKey); ?>"><?php echo $gPaginationHtml; ?></div>
        </div>
      <?php endforeach; ?>
        </div>
      </div>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-mis-tickets' ? ' active' : ''; ?>" id="scm-panel-mis-tickets" data-permission-tab="mis_tickets">
        <?php echo $this->render_my_tickets_panel($myTicketsResult, $myTicketsStats, $myTicketsParams, $filterOptions); ?>
      </div>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-contratos-arrendamiento' ? ' active' : ''; ?>" id="scm-panel-contratos-arrendamiento" data-permission-tab="contratos_arrendamiento">
        <?php echo $contratosArrendamientoHtml; ?>
      </div>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-postergados' ? ' active' : ''; ?>" id="scm-panel-postergados" data-permission-tab="postergados">
        <?php echo $this->render_status_bucket_panel('postergados', $statusBucketDefs['postergados'], $statusTopicDefs, $statusResults['postergados'] ?? []); ?>
      </div>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-cerrados' ? ' active' : ''; ?>" id="scm-panel-cerrados" data-permission-tab="cerrados">
        <?php echo $this->render_status_bucket_panel('cerrados', $statusBucketDefs['cerrados'], $statusTopicDefs, $statusResults['cerrados'] ?? []); ?>
      </div>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-actividades-administrativas' ? ' active' : ''; ?>" id="scm-panel-actividades-administrativas" data-permission-tab="actividades_administrativas">
        <div class="scm-admin-activities">
          <div class="scm-status-subtabs scm-admin-activity-subtabs" role="tablist" aria-label="Actividades administrativas">
            <?php foreach ($administrativeActivityTabs as $activityTabKey => $activityDef): ?>
              <?php if (!in_array($activityTabKey, $allowedAdministrativeActivityTabs, true)) continue; ?>
              <?php $activityPanelId = (string)($activityDef['panel'] ?? ''); ?>
              <button class="scm-status-topic-tab scm-admin-activity-tab<?php echo $initialAdministrativeActivityKey === $activityTabKey ? ' active' : ''; ?>" type="button" data-admin-activity-key="<?php echo esc_attr($activityTabKey); ?>" data-admin-activity-target="<?php echo esc_attr($activityPanelId); ?>"><?php echo esc_html((string)($activityDef['label'] ?? $activityTabKey)); ?></button>
            <?php endforeach; ?>
          </div>

          <?php if (in_array('calendario_actividades', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'calendario_actividades' ? ' active' : ''; ?>" id="scm-panel-calendario-actividades" data-permission-tab="calendario_actividades" data-admin-activity-panel="calendario_actividades">
              <?php echo $this->render_calendario_actividades_panel($config); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('notificaciones', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'notificaciones' ? ' active' : ''; ?>" id="scm-panel-admin-notificaciones" data-permission-tab="notificaciones" data-admin-activity-panel="notificaciones" data-scm-loaded="0">
              <?php echo $this->render_admin_notifications_shell(); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('gestiones_cobro', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'gestiones_cobro' ? ' active' : ''; ?>" id="scm-panel-gestiones-cobro" data-permission-tab="gestiones_cobro" data-admin-activity-panel="gestiones_cobro" data-scm-loaded="0">
              <?php echo $this->render_collection_management_shell(); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('cotizaciones_mantenimiento', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'cotizaciones_mantenimiento' ? ' active' : ''; ?>" id="scm-panel-cotizaciones-mantenimiento" data-permission-tab="cotizaciones_mantenimiento" data-admin-activity-panel="cotizaciones_mantenimiento">
              <?php echo $this->render_cotizaciones_mantenimiento_panel($cotizacionesResult, $cotizacionesParams); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('actas_satisfaccion', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'actas_satisfaccion' ? ' active' : ''; ?>" id="scm-panel-actas-satisfaccion" data-permission-tab="actas_satisfaccion" data-admin-activity-panel="actas_satisfaccion" data-scm-loaded="0">
              <?php echo $actasSatisfaccionHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('preventivas_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'preventivas_pendientes' ? ' active' : ''; ?>" id="scm-panel-preventivas-pendientes" data-permission-tab="preventivas_pendientes" data-admin-activity-panel="preventivas_pendientes" data-scm-loaded="0">
              <?php echo $preventivasPendientesHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('servicios_publicos_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'servicios_publicos_pendientes' ? ' active' : ''; ?>" id="scm-panel-servicios-publicos-pendientes" data-permission-tab="servicios_publicos_pendientes" data-admin-activity-panel="servicios_publicos_pendientes" data-scm-loaded="0">
              <?php echo $serviciosPublicosPendientesHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('reportes_administrativos_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'reportes_administrativos_pendientes' ? ' active' : ''; ?>" id="scm-panel-reportes-administrativos-pendientes" data-permission-tab="reportes_administrativos_pendientes" data-admin-activity-panel="reportes_administrativos_pendientes" data-scm-loaded="0">
              <?php echo $reportesAdministrativosPendientesHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('auditoria_canon_aseguradoras', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'auditoria_canon_aseguradoras' ? ' active' : ''; ?>" id="scm-panel-auditoria-canon-aseguradoras" data-permission-tab="auditoria_canon_aseguradoras" data-admin-activity-panel="auditoria_canon_aseguradoras" data-scm-loaded="0">
              <?php echo (new \SCM\Modules\CanonInsuranceAudit\CanonInsuranceAuditView())->renderPanel(); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/admin-dashboard-inline.js?v=' . SCM_VERSION); ?>" defer></script>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-metricas' ? ' active' : ''; ?>" id="scm-panel-metricas" data-permission-tab="metricas" data-scm-metrics="<?php echo self::h((string)$metricsJson); ?>" data-scm-loaded="0">
        <div class="scm-header scm-header-metricas">
          <div>
            <h2>Metricas Operativas</h2>
            <p>Visualizacion consolidada del Control de Servicios Inmobiliarios.</p>
          </div>
          <div class="scm-header-counter-wrap"><span class="scm-header-counter" id="scm-metrics-total"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></span></div>
        </div>
        <div class="scm-metrics-loading-state" data-scm-metrics-loading role="status" aria-live="polite">Cargando indicadores&hellip;</div>
        <div class="scm-tabs scm-metric-tabs" id="scm-metric-tabs">
          <button class="scm-tab active" type="button" data-scm-metric-cat="mantenimiento">Mantenimiento</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="entrega">Entrega</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="preventiva">Preventiva</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="recibo">Recibo</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contable">Contable</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="certificaciones">Certificaciones</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contractual">Contractual</button>
          <button class="scm-tab" type="button" data-scm-metric-panel="guardian">Solicitudes Guardian</button>
          <button class="scm-tab" type="button" data-scm-metric-panel="ejecucion">Ejecuci&oacute;n por funcionario</button>
        </div>

        <div class="scm-metrics-pane active" data-scm-metrics-pane="operativas">
        <div class="scm-metrics-grid">
          <section class="scm-metrics-card">
            <h3>Estado general</h3>
            <div class="scm-donut-wrap">
              <div class="scm-donut" id="scm-chart-estado-ring"><span id="scm-chart-estado-center"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></span></div>
              <div class="scm-metrics-legend">
                <div class="scm-metrics-legend-item"><span class="dot dot-open"></span>Abiertos <strong id="scm-metric-abiertos"><?php echo esc_html((string)($stats['abiertos'] ?? 0)); ?></strong></div>
                <div class="scm-metrics-legend-item"><span class="dot dot-closed"></span>Cerrados <strong id="scm-metric-cerrados"><?php echo esc_html((string)($stats['cerrados'] ?? 0)); ?></strong></div>
              </div>
            </div>
          </section>

          <section class="scm-metrics-card">
            <h3>Salud SLA</h3>
            <div class="scm-bars" id="scm-chart-sla"></div>
          </section>

          <section class="scm-metrics-card">
            <h3>Flujo operativo</h3>
            <div class="scm-bars" id="scm-chart-flujo"></div>
          </section>

          <section class="scm-metrics-card">
            <h3>Tiempos promedio</h3>
            <div class="scm-bars" id="scm-chart-tiempos"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Produccion mensual</h3>
            <div class="scm-bars" id="scm-chart-produccion"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Categorias</h3>
            <div class="scm-bars" id="scm-chart-categorias"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Seguimientos del mes por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-seg-funcionario"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Actualizados del mes por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-actualizados-funcionario"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Tickets abiertos por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-abiertos-funcionario"></div>
          </section>
        </div>
        </div>
        <div class="scm-metrics-pane" data-scm-metrics-pane="guardian">
          <div class="scm-metrics-grid">
            <section class="scm-metrics-card">
              <h3>Solicitudes Guardian</h3>
              <div class="scm-bars" id="scm-chart-web"></div>
            </section>
            <section class="scm-metrics-card">
              <h3>Guardian por estado comercial</h3>
              <div class="scm-bars" id="scm-chart-web-comercial"></div>
            </section>
            <section class="scm-metrics-card">
              <h3>Guardian por estado administrativo</h3>
              <div class="scm-bars" id="scm-chart-web-admin"></div>
            </section>
          </div>
        </div>
        <div class="scm-metrics-pane" data-scm-metrics-pane="ejecucion">
          <section class="scm-execution-panel" data-scm-execution-panel>
            <div class="scm-execution-head">
              <div>
                <span class="scm-eyebrow">Resumen para gerencia</span>
                <h3>Ejecuci&oacute;n por funcionario</h3>
                <p>Consulta respuestas, actualizaciones, seguimientos y citas realizadas en cada caso durante el rango seleccionado.</p>
              </div>
            </div>
            <form class="scm-execution-filters" data-scm-execution-form autocomplete="off">
              <div class="scm-field">
                <label for="scm_exec_desde">Fecha desde</label>
                <input id="scm_exec_desde" name="fecha_desde" class="input input-bordered input-sm" type="date" value="<?php echo esc_attr(date('Y-m-01')); ?>">
              </div>
              <div class="scm-field">
                <label for="scm_exec_hasta">Fecha hasta</label>
                <input id="scm_exec_hasta" name="fecha_hasta" class="input input-bordered input-sm" type="date" value="<?php echo esc_attr(date('Y-m-d')); ?>">
              </div>
              <div class="scm-field scm-execution-funcionario-field">
                <label for="scm_exec_funcionario">Funcionario</label>
                <select id="scm_exec_funcionario" name="funcionario" class="select select-bordered select-sm scm-select scm-select2" data-placeholder="Todos los funcionarios">
                  <option value="">Todos</option>
                  <?php foreach (($filterOptions['funcionarios'] ?? []) as $func): $fId = trim((string)($func['id'] ?? '')); if ($fId === '') continue; ?>
                    <option value="<?php echo esc_attr($fId); ?>"><?php echo esc_html((string)($func['label'] ?? $fId)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="scm-btn-primary btn btn-primary">Ver resumen</button>
            </form>
            <div class="scm-execution-status" data-scm-execution-status aria-live="polite">Selecciona un rango y consulta la ejecuci&oacute;n.</div>
            <div class="scm-execution-kpis" data-scm-execution-kpis></div>
            <div class="scm-execution-summary" data-scm-execution-summary></div>
            <div class="scm-execution-details" data-scm-execution-details></div>
          </section>
        </div>
      </div>

      <?php echo \SCM\Views\GuideModalView::render(); ?>
      <?php if ($canManageDashboardPermissions): ?>
        <?php echo $this->renderDashboardPermissionsModal($dashboardPermissionTabs, $dashboardCargoOptions, $dashboardPermissionConfig, $dashboardFuncionarioCargoIds); ?>
      <?php endif; ?>
      <?php if ($canManagePublicPqrSettings): ?>
        <div id="scm-pqr-settings-modal" data-scm-lazy-settings="public-pqr" aria-hidden="true"></div>
      <?php endif; ?>
      <?php if ($canManageInternalNotificationSettings): ?>
        <div id="scm-internal-notifications-modal" data-scm-lazy-settings="internal-notifications" aria-hidden="true"></div>
      <?php endif; ?>

      <div class="scm-case-modal" id="scm-case-modal" aria-hidden="true">
        <div class="scm-case-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-case-title">
          <button class="scm-case-close" type="button" aria-label="Cerrar vista del caso" onclick="scmCloseCase(this)">&times;</button>
          <div class="scm-case-head">
            <div>
              <h3 id="scm-case-title">Vista del caso</h3>
              <p id="scm-case-subtitle">Ticket de servicios inmobiliarios</p>
            </div>
            <div class="scm-case-head-actions" id="scm-case-head-actions"></div>
            <div class="scm-case-head-meta" id="scm-case-meta"></div>
          </div>
          <div class="scm-case-body" id="scm-case-body"></div>
        </div>
      </div>
    </div>
    <script src="<?php echo $jsUrl; ?>" defer></script>
    <script src="<?php echo $dashboardRuntimeJsUrl; ?>" defer></script>
    <script src="<?php echo $collectionManagementJsUrl; ?>" defer></script>
    <script src="<?php echo $canonInsuranceAuditJsUrl; ?>" defer></script>
    <script src="<?php echo $guideJsUrl; ?>" defer></script>
    <script src="<?php echo $damageJsUrl; ?>" defer></script>
<?php
    return (string)ob_get_clean();
  }

  private function renderDashboardPublicPqrSettingsModal(): string
  {
    $currentNotifIds = \SCM\Modules\PublicTickets\PublicTicketsService::getNotifResponsables();
    $selectedNotifMap = [];
    foreach ($currentNotifIds as $notifId) {
      $notifId = trim((string) $notifId);
      if ($notifId !== '') {
        $selectedNotifMap[$notifId] = true;
      }
    }

    $themes = $this->get_public_pqr_themes();
    $corresponsables = $this->get_public_pqr_corresponsables();
    $corresponsableCandidates = $this->get_public_pqr_corresponsable_candidates();

    $notifOptions = '';
    foreach ($corresponsableCandidates as $func) {
      $id = trim((string) ($func['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $label = trim((string) ($func['label'] ?? $id));
      $sel = isset($selectedNotifMap[$id]) ? ' selected' : '';
      $notifOptions .= '<option value="' . self::h($id) . '"' . $sel . '>' . self::h($label) . '</option>';
    }

    $buildCorrespOptions = function (array $selectedIds) use ($corresponsableCandidates): string {
      $selectedCorresponsables = [];
      foreach ($selectedIds as $idSaved) {
        $idSaved = trim((string) $idSaved);
        if ($idSaved !== '') {
          $selectedCorresponsables[$idSaved] = true;
        }
      }

      $options = '';
      foreach ($corresponsableCandidates as $func) {
        $id = trim((string) ($func['id'] ?? ''));
        if ($id === '') {
          continue;
        }
        $label = trim((string) ($func['label'] ?? $id));
        $selected = isset($selectedCorresponsables[$id]) ? ' selected' : '';
        $options .= '<option value="' . self::h($id) . '"' . $selected . '>' . self::h($label) . '</option>';
      }

      return $options;
    };

    $correspGridHtml = '';
    foreach ($themes as $theme) {
      $assignmentValue = is_array($corresponsables[$theme] ?? null) ? $corresponsables[$theme] : [];
      $generalOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'default'));
      $ownerOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'propietario'));
      $tenantOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'arrendatario'));
      $coproOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'copropiedad'));
      $clientOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'cliente'));

      $correspGridHtml .= '<form class="scm-public-pqr-corresponsable-form scm-pqr-config-form scm-dashboard-pqr-config-form" method="post" autocomplete="off">';
      $correspGridHtml .= '<input type="hidden" name="tema_ayuda" value="' . self::h((string) $theme) . '">';
      $correspGridHtml .= '<label class="scm-pqr-config-theme">' . self::h((string) $theme) . '</label>';
      $correspGridHtml .= '<small>General aplica cuando no hay responsable especifico por actor.</small>';
      $correspGridHtml .= '<label>General</label>';
      $correspGridHtml .= '<select name="corresponsable_ids[]" class="select select-bordered select-sm scm-select" multiple size="4">' . $generalOptions . '</select>';
      $correspGridHtml .= '<label>Propietario</label>';
      $correspGridHtml .= '<select name="corresponsable_actor[propietario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $ownerOptions . '</select>';
      $correspGridHtml .= '<label>Arrendatario</label>';
      $correspGridHtml .= '<select name="corresponsable_actor[arrendatario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $tenantOptions . '</select>';
      $correspGridHtml .= '<label>Copropiedad</label>';
      $correspGridHtml .= '<select name="corresponsable_actor[copropiedad][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $coproOptions . '</select>';
      $correspGridHtml .= '<label>Cliente</label>';
      $correspGridHtml .= '<select name="corresponsable_actor[cliente][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $clientOptions . '</select>';
      $correspGridHtml .= '<small>Ctrl/Command + click para seleccionar varios o quitar seleccion.</small>';
      $correspGridHtml .= '<div class="scm-pqr-config-actions"><button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button><small class="scm-public-pqr-corresponsable-msg" aria-live="polite"></small></div>';
      $correspGridHtml .= '</form>';
    }

    if (empty($corresponsableCandidates)) {
      $correspGridHtml = '<p class="scm-pqr-config-empty">No hay funcionarios disponibles para configurar.</p>';
    }

    ob_start();
?>
    <div id="scm-pqr-settings-modal" class="scm-pqr-settings-modal" data-scm-dashboard-pqr-settings="1" aria-hidden="true">
      <div class="scm-pqr-settings-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-pqr-settings-title">
        <button type="button" class="scm-pqr-settings-close" id="scm-close-pqr-settings" aria-label="Cerrar">&times;</button>
        <div class="scm-pqr-settings-head">
          <h3 id="scm-pqr-settings-title">Configuraci&oacute;n de Guardian</h3>
          <p>Define qui&eacute;n recibe notificaciones nuevas y qui&eacute;nes quedan como corresponsables por tipo de solicitud.</p>
        </div>
        <section class="scm-pqr-settings-section">
          <h4>Funcionarios que reciben notificaciones</h4>
          <p>Recibir&aacute;n WhatsApp y correo cada vez que se cree una solicitud desde Guardian. Puedes seleccionar varios funcionarios.</p>
          <form class="scm-notif-responsable-form scm-pqr-config-form scm-dashboard-pqr-config-form" method="post" autocomplete="off">
            <div class="scm-pqr-settings-select-wrap">
              <select name="notif_responsable_ids[]" class="select select-bordered select-sm scm-select" multiple size="6"><?php echo $notifOptions; ?></select>
              <small>Ctrl/Command + click para seleccionar o quitar varios funcionarios.</small>
            </div>
            <button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button>
            <small class="scm-notif-responsable-msg" aria-live="polite"></small>
          </form>
        </section>
        <section class="scm-pqr-settings-section">
          <h4>Corresponsables por tipo de solicitud</h4>
          <p>Puedes seleccionar responsables generales o espec&iacute;ficos por propietario, arrendatario, copropiedad y cliente.</p>
          <div class="scm-pqr-settings-grid"><?php echo $correspGridHtml; ?></div>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function renderDashboardInternalNotificationsModal(): string
  {
    $settings = $this->internalNotificationSettingsConfig();
    $catalog = $this->internalNotificationActionCatalog();
    $funcionarios = $this->internalNotificationFuncionarioOptions();
    $buildOptions = function (array $selectedIds) use ($funcionarios): string {
      $selected = [];
      foreach ($selectedIds as $selectedId) {
        $selected[(string) ((int) $selectedId)] = true;
      }
      $html = '';
      foreach ($funcionarios as $funcionario) {
        $id = trim((string) ($funcionario['id'] ?? ''));
        if ($id === '') {
          continue;
        }
        $label = trim((string) ($funcionario['label'] ?? $id));
        $html .= '<option value="' . esc_attr($id) . '"' . (isset($selected[$id]) ? ' selected' : '') . '>' . esc_html($label) . '</option>';
      }
      return $html;
    };

    $groupsHtml = '';
    foreach ($catalog as $groupKey => $group) {
      $groupsHtml .= '<section class="scm-internal-notif-group" data-internal-notif-group="' . esc_attr((string) $groupKey) . '">';
      $groupsHtml .= '<h4>' . esc_html((string) ($group['label'] ?? $groupKey)) . '</h4>';
      foreach ((array) ($group['items'] ?? []) as $actionKey => $item) {
        $action = trim((string) $actionKey);
        if ($action === '') {
          continue;
        }
        $selectedIds = is_array($settings[$action] ?? null) ? $settings[$action] : [];
        $groupsHtml .= '<div class="scm-internal-notif-action">';
        $groupsHtml .= '<div class="scm-internal-notif-action-head">';
        $groupsHtml .= '<strong>' . esc_html((string) ($item['label'] ?? $action)) . '</strong>';
        $groupsHtml .= '<span>' . esc_html((string) ($item['channel'] ?? 'Email interno')) . '</span>';
        $groupsHtml .= '</div>';
        $groupsHtml .= '<p>' . esc_html((string) ($item['description'] ?? '')) . '</p>';
        $groupsHtml .= '<select name="settings[' . esc_attr($action) . '][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $buildOptions($selectedIds) . '</select>';
        $groupsHtml .= '</div>';
      }
      $groupsHtml .= '</section>';
    }
    if ($funcionarios === []) {
      $groupsHtml = '<p class="scm-pqr-config-empty">No hay funcionarios activos disponibles para configurar.</p>';
    }

    ob_start();
?>
    <div id="scm-internal-notifications-modal" class="scm-pqr-settings-modal scm-internal-notifications-modal" aria-hidden="true">
      <div class="scm-pqr-settings-dialog scm-internal-notifications-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-internal-notifications-title">
        <button type="button" class="scm-pqr-settings-close" id="scm-close-internal-notifications" aria-label="Cerrar">&times;</button>
        <div class="scm-pqr-settings-head">
          <h3 id="scm-internal-notifications-title">Notificaciones internas administrativas</h3>
          <p>Clasifica a qu&eacute; funcionarios se les avisa por cada acci&oacute;n del panel. Hoy Gesti&oacute;n de cobro ya encola Email interno real; las dem&aacute;s acciones quedan listas para conectar al flujo correspondiente.</p>
        </div>
        <form id="scm-internal-notifications-form" class="scm-internal-notifications-form" autocomplete="off">
          <section class="scm-pqr-settings-section">
            <h4>Acciones y destinatarios internos</h4>
            <p>Selecciona uno o varios funcionarios por acci&oacute;n. Si una acci&oacute;n queda vac&iacute;a, no se env&iacute;an avisos internos para esa actividad.</p>
            <div class="scm-internal-notif-grid"><?php echo $groupsHtml; ?></div>
          </section>
          <div class="scm-pqr-config-actions scm-internal-notif-actions">
            <small id="scm-internal-notifications-msg" aria-live="polite"></small>
            <button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar notificaciones internas</button>
          </div>
        </form>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,string> $tabs @param array<int,array<string,string>> $cargos @param array<string,array<int,string>> $permissions @param array<int,string> $employeeCargoIds */
  private function renderDashboardPermissionsModal(array $tabs, array $cargos, array $permissions, array $employeeCargoIds): string
  {
    $activityPermissionKeys = [
      'cotizaciones_mantenimiento',
      'actas_satisfaccion',
      'calendario_actividades',
      'notificaciones',
      'preventivas_pendientes',
      'servicios_publicos_pendientes',
      'reportes_administrativos_pendientes',
    ];
    $mainPermissionTabs = array_diff_key($tabs, array_flip($activityPermissionKeys));
    $activityPermissionTabs = array_intersect_key($tabs, array_flip($activityPermissionKeys));
    $selectedEmployeeCargoIds = [];
    foreach ($employeeCargoIds as $cargoId) {
      $cargoId = trim((string) $cargoId);
      if ($cargoId !== '') {
        $selectedEmployeeCargoIds[$cargoId] = true;
      }
    }
    ob_start();
?>
    <div class="scm-permissions-modal" id="scm-permissions-modal" aria-hidden="true">
      <div class="scm-permissions-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-permissions-title">
        <button type="button" class="scm-permissions-close" id="scm-close-permissions" aria-label="Cerrar">&times;</button>
        <div class="scm-permissions-head">
          <div>
            <h3 id="scm-permissions-title">Permisos por cargo</h3>
            <p>Activa o desactiva las pesta&ntilde;as que puede ver cada cargo. Si un cargo no aparece configurado, seguir&aacute; viendo todas las pesta&ntilde;as.</p>
          </div>
        </div>
        <form class="scm-permissions-form" id="scm-permissions-form">
          <div class="scm-permissions-toolbar">
            <span>Selecciona permisos por cargo</span>
            <small>Los cambios se aplican al guardar.</small>
          </div>
          <div class="scm-permissions-cards">
            <?php foreach ($cargos as $cargo): $cargoId = trim((string)($cargo['id'] ?? '')); if ($cargoId === '') continue; $allowed = $permissions[$cargoId] ?? array_keys($tabs); $cargoName = trim((string)($cargo['name'] ?? ($cargo['label'] ?? ('Cargo ' . $cargoId)))); $cargoTotal = trim((string)($cargo['total'] ?? '')); ?>
              <section class="scm-permission-card" data-permission-cargo="<?php echo esc_attr($cargoId); ?>">
                <div class="scm-permission-card-head">
                  <div>
                    <h4><?php echo esc_html($cargoName !== '' ? $cargoName : ('Cargo ' . $cargoId)); ?></h4>
                    <p>ID cargo <strong><?php echo esc_html($cargoId); ?></strong><?php if ($cargoTotal !== ''): ?> · <?php echo esc_html($cargoTotal); ?> funcionario<?php echo $cargoTotal === '1' ? '' : 's'; ?><?php endif; ?></p>
                  </div>
                  <button type="button" class="scm-permission-select-all" data-scm-perm-all="<?php echo esc_attr($cargoId); ?>">Todo</button>
                </div>
                <div class="scm-permission-options">
                  <?php foreach ($mainPermissionTabs as $tabKey => $tabLabel): $isChecked = in_array($tabKey, $allowed, true); ?>
                    <label class="scm-permissions-check<?php echo $isChecked ? ' is-checked' : ''; ?>">
                      <input type="checkbox" name="permissions[<?php echo esc_attr($cargoId); ?>][]" value="<?php echo esc_attr($tabKey); ?>" <?php checked($isChecked); ?>>
                      <span><?php echo esc_html($tabLabel); ?></span>
                    </label>
                  <?php endforeach; ?>
                  <?php if (!empty($activityPermissionTabs)): ?>
                    <div class="scm-permissions-option-group">
                      <div class="scm-permissions-option-group-title">Actividades administrativas</div>
                      <div class="scm-permissions-option-group-grid">
                        <?php foreach ($activityPermissionTabs as $tabKey => $tabLabel): $isChecked = in_array($tabKey, $allowed, true); ?>
                          <label class="scm-permissions-check<?php echo $isChecked ? ' is-checked' : ''; ?>">
                            <input type="checkbox" name="permissions[<?php echo esc_attr($cargoId); ?>][]" value="<?php echo esc_attr($tabKey); ?>" <?php checked($isChecked); ?>>
                            <span><?php echo esc_html($tabLabel); ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
          <section class="scm-permission-employee-cargos" aria-labelledby="scm-employee-cargos-title">
            <div class="scm-permission-employee-cargos-head">
              <div>
                <h4 id="scm-employee-cargos-title">Funcionarios visibles por cargo</h4>
                <p>Estos cargos aparecer&aacute;n en filtros de funcionario, traslados, calendario, responsables y notificaciones internas del panel.</p>
              </div>
              <small>No cambia permisos de acceso; solo controla qui&eacute;n aparece como funcionario operativo.</small>
            </div>
            <div class="scm-permission-employee-cargos-grid">
              <?php foreach ($cargos as $cargo): $cargoId = trim((string)($cargo['id'] ?? '')); if ($cargoId === '') continue; $cargoName = trim((string)($cargo['name'] ?? ($cargo['label'] ?? ('Cargo ' . $cargoId)))); $cargoTotal = trim((string)($cargo['total'] ?? '')); $isSelectedCargo = isset($selectedEmployeeCargoIds[$cargoId]); ?>
                <label class="scm-permissions-check scm-funcionario-cargo-check<?php echo $isSelectedCargo ? ' is-checked' : ''; ?>">
                  <input type="checkbox" name="employee_cargo_ids[]" value="<?php echo esc_attr($cargoId); ?>" <?php checked($isSelectedCargo); ?>>
                  <span><?php echo esc_html($cargoName !== '' ? $cargoName : ('Cargo ' . $cargoId)); ?><?php if ($cargoTotal !== ''): ?> · <?php echo esc_html($cargoTotal); ?><?php endif; ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>
          <div class="scm-permissions-actions">
            <p class="scm-permissions-msg" id="scm-permissions-msg" aria-live="polite"></p>
            <button type="submit" class="scm-btn-primary btn btn-primary">Guardar permisos</button>
          </div>
        </form>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function current_employee_id(): string
  {
    $userId = Auth::userId();
    if ($userId <= 0) {
      return '';
    }

    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($table)) {
      return '';
    }

    $row = $this->db->getRow(
      "SELECT TRIM(COALESCE(`id_empleado`, '')) AS id_empleado FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [$userId]
    );

    return is_array($row) ? trim((string) ($row['id_empleado'] ?? '')) : '';
  }

  /** @return array<string,string> */
  private function parse_cotizaciones_mantenimiento_params(array $input, string $prefix = 'scmqt_'): array
  {
    $clean = static function ($value): string {
      return trim(sanitize_text_field(wp_unslash((string) ($value ?? ''))));
    };
    $page = max(1, (int) $clean($input[$prefix . 'page'] ?? '1'));
    $perPage = max(10, min(60, (int) $clean($input[$prefix . 'per_page'] ?? '20')));

    $singleDate = $clean($input[$prefix . 'fecha'] ?? '');
    $dateFrom = $clean($input[$prefix . 'fecha_desde'] ?? '');
    $dateTo = $clean($input[$prefix . 'fecha_hasta'] ?? '');
    if ($singleDate !== '' && $dateFrom === '' && $dateTo === '') {
      $dateFrom = $singleDate;
      $dateTo = $singleDate;
    }
    $estado = $clean($input[$prefix . 'estado'] ?? '');
    if (in_array(strtolower(trim($estado)), ['sin_estado', 'sin estado'], true)) {
      $estado = '__sin_estado__';
    }

    return [
      'fPage' => (string) $page,
      'fPerPage' => (string) $perPage,
      'fCotizacion' => $clean($input[$prefix . 'cotizacion'] ?? ''),
      'fTicket' => $clean($input[$prefix . 'caso'] ?? $input[$prefix . 'ticket'] ?? $input[$prefix . 'id_ticket'] ?? ''),
      'fFecha' => $singleDate,
      'fFechaDesde' => $dateFrom,
      'fFechaHasta' => $dateTo,
      'fDestinatario' => $clean($input[$prefix . 'destinatario'] ?? ''),
      'fFuncionario' => $clean($input[$prefix . 'funcionario'] ?? ''),
      'fInmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'fContrato' => $clean($input[$prefix . 'contrato'] ?? ''),
      'fEnviada' => $clean($input[$prefix . 'enviada'] ?? ''),
      'fEstado' => $estado,
      'fTipoMantenimiento' => $clean($input[$prefix . 'tipo_mantenimiento'] ?? ''),
      'fCategoria' => $clean($input[$prefix . 'categoria'] ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  private function query_cotizaciones_mantenimiento(array $p): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $ordersTable = $this->db->table('jet_cct_ordenes');
    if (!$this->table_exists($table)) {
      return ['rows' => [], 'stats' => ['total' => 0], 'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1]];
    }

    $where = ['1=1'];
    $args = [];
    $like = function (string $column, string $value) use (&$where, &$args): void {
      if ($value === '') {
        return;
      }
      $where[] = "COALESCE(c.`{$column}`, '') LIKE ?";
      $args[] = '%' . $this->db->escapeLike($value) . '%';
    };

    if (($p['fCotizacion'] ?? '') !== '') {
      $where[] = 'CAST(c.`_ID` AS CHAR) LIKE ?';
      $args[] = '%' . $this->db->escapeLike((string) $p['fCotizacion']) . '%';
    }
    $like('id_ticket', (string) ($p['fTicket'] ?? ''));
    $like('destinatario', (string) ($p['fDestinatario'] ?? ''));
    if (($p['fFuncionario'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $p['fFuncionario']) . '%';
      $where[] = "(COALESCE(c.`id_empleado`, '') LIKE ? OR COALESCE(c.`coordinador`, '') LIKE ? OR COALESCE(c.`creador`, '') LIKE ?)";
      $args[] = $term;
      $args[] = $term;
      $args[] = $term;
    }
    if (($p['fInmueble'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $p['fInmueble']) . '%';
      $where[] = "(COALESCE(c.`inmueble`, '') LIKE ? OR COALESCE(c.`id_inmueble`, '') LIKE ?)";
      $args[] = $term;
      $args[] = $term;
    }
    if (($p['fContrato'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $p['fContrato']) . '%';
      $where[] = "CAST(c.`contrato` AS CHAR) LIKE ?";
      $args[] = $term;
    }

    if (($p['fFechaDesde'] ?? '') !== '') {
      $ts = strtotime((string) $p['fFechaDesde'] . ' 00:00:00');
      if ($ts !== false) {
        $where[] = 'COALESCE(c.`fecha`, 0) >= ?';
        $args[] = (int) $ts;
      }
    }
    if (($p['fFechaHasta'] ?? '') !== '') {
      $ts = strtotime((string) $p['fFechaHasta'] . ' 23:59:59');
      if ($ts !== false) {
        $where[] = 'COALESCE(c.`fecha`, 0) <= ?';
        $args[] = (int) $ts;
      }
    }

    if (($p['fTipoMantenimiento'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(c.`tipo_mantenimiento`, ''))) = ?";
      $args[] = strtolower(trim((string) $p['fTipoMantenimiento']));
    }
    if (($p['fCategoria'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(c.`categoria_cotizacion`, ''))) = ?";
      $args[] = strtolower(trim((string) $p['fCategoria']));
    }

    $tabWhereSql = implode(' AND ', $where);
    $tabArgs = $args;

    if (($p['fEstado'] ?? '') !== '') {
      $stateFilter = strtolower(trim((string) $p['fEstado']));
      if (in_array($stateFilter, ['__sin_estado__', 'sin_estado', 'sin estado'], true)) {
        $where[] = "(TRIM(COALESCE(c.`estado`, '')) = '' OR LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'sin estado')";
      } else {
        if (in_array($stateFilter, ['finalizado', 'finalizada'], true)) {
          $where[] = "LOWER(TRIM(COALESCE(c.`estado`, ''))) IN ('finalizado', 'finalizada')";
        } else {
          $where[] = "LOWER(TRIM(COALESCE(c.`estado`, ''))) = ?";
          $args[] = $stateFilter;
        }
      }
    }
    if (($p['fEnviada'] ?? '') !== '') {
      $sent = strtolower(trim((string) $p['fEnviada']));
      if (in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true)) {
        $where[] = "LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
      } elseif (in_array($sent, ['no', '0', 'false', 'none', 'sin'], true)) {
        $where[] = "(TRIM(COALESCE(c.`se_envio`, '')) = '' OR LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('no', '0', 'false'))";
      }
    }

    $whereSql = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` c WHERE {$whereSql}", $args);
    $page = max(1, (int) ($p['fPage'] ?? 1));
    $perPage = max(10, min(60, (int) ($p['fPerPage'] ?? 20)));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $ordersSelect = $this->table_exists($ordersTable)
      ? "(SELECT COUNT(1) FROM `{$ordersTable}` o WHERE TRIM(COALESCE(o.`id_cotizacion`, '')) = CAST(c.`_ID` AS CHAR)) AS ordenes_total"
      : '0 AS ordenes_total';
    $rows = $this->db->getResults(
      "SELECT c.*, {$ordersSelect}
       FROM `{$table}` c
       WHERE {$whereSql}
       ORDER BY COALESCE(NULLIF(c.`fecha`, 0), UNIX_TIMESTAMP(c.`cct_created`)) DESC, c.`_ID` DESC
       LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );

    return [
      'rows' => $this->attach_cotizacion_orders(is_array($rows) ? $rows : []),
      'stats' => $this->aggregate_cotizaciones_mantenimiento($whereSql, $args),
      'tab_stats' => $this->aggregate_cotizaciones_mantenimiento($tabWhereSql, $tabArgs),
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
    ];
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
  private function attach_cotizacion_orders(array $rows): array
  {
    $ordersTable = $this->db->table('jet_cct_ordenes');
    if (empty($rows) || !$this->table_exists($ordersTable)) {
      return $rows;
    }

    $ids = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['_ID'] ?? ''));
      if ($id !== '') {
        $ids[$id] = true;
      }
    }
    if (empty($ids)) {
      return $rows;
    }

    $idList = array_keys($ids);
    $ph = implode(',', array_fill(0, count($idList), '?'));
    $orderRows = $this->db->getResults(
      "SELECT `_ID`, `id_cotizacion`, `id_ticket`, `fecha`, `estado`, `proveedor`, `valor`, `actividad`,
              `categoria`, `concepto`, `direccion`, `creador`, `coordinador`, `autorizador`, `contrato`,
              `id_contrato`, `inmueble`, `id_inmueble`, `sucursal`, `tipo_identificacion_proveedor`,
              `identificacion_proveedor`, `correo_proveedor`, `celular_proveedor`, `direccion_proveedor`,
              `titular_proveedor`, `identificacion_cuenta_proveedor`, `tipo_cuenta_proveedor`,
              `cuenta_proveedor`, `banco_proveedor`, `correo_pago_proveedor`, `cct_created`, `cct_modified`
       FROM `{$ordersTable}`
       WHERE TRIM(COALESCE(`id_cotizacion`, '')) IN ({$ph})
       ORDER BY `_ID` DESC",
      $idList
    );
    $map = [];
    foreach ($orderRows as $order) {
      $idCot = trim((string) ($order['id_cotizacion'] ?? ''));
      if ($idCot !== '') {
        $map[$idCot][] = $order;
      }
    }
    foreach ($rows as &$row) {
      $id = trim((string) ($row['_ID'] ?? ''));
      $row['_scm_ordenes'] = $id !== '' && isset($map[$id]) ? $map[$id] : [];
    }
    unset($row);

    return $rows;
  }

  /** @return array<string,int|float> */
  private function aggregate_cotizaciones_mantenimiento(string $whereSql, array $args): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $ordersTable = $this->db->table('jet_cct_ordenes');
    $row = $this->db->getRow(
      "SELECT
        COUNT(1) AS total,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado') THEN 1 ELSE 0 END) AS enviadas,
        SUM(CASE WHEN TRIM(COALESCE(c.`se_envio`, '')) = '' OR LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('no', '0', 'false') THEN 1 ELSE 0 END) AS no_enviadas,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'aprobada' THEN 1 ELSE 0 END) AS aprobadas,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'desaprobada' THEN 1 ELSE 0 END) AS desaprobadas,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'esperando respuesta' THEN 1 ELSE 0 END) AS esperando_respuesta,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) IN ('finalizado', 'finalizada') THEN 1 ELSE 0 END) AS finalizadas,
        SUM(CASE WHEN TRIM(COALESCE(c.`estado`, '')) = '' OR LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'sin estado' THEN 1 ELSE 0 END) AS sin_estado,
        SUM(COALESCE(c.`total`, 0)) AS valor_total
       FROM `{$table}` c
       WHERE {$whereSql}",
      $args
    ) ?: [];

    $ordersTotal = 0;
    if ($this->table_exists($ordersTable)) {
      $ordersTotal = (int) $this->db->getVar(
        "SELECT COUNT(1)
         FROM `{$ordersTable}` o
         INNER JOIN `{$table}` c ON TRIM(COALESCE(o.`id_cotizacion`, '')) = CAST(c.`_ID` AS CHAR)
         WHERE {$whereSql}",
        $args
      );
    }

    return [
      'total' => (int) ($row['total'] ?? 0),
      'enviadas' => (int) ($row['enviadas'] ?? 0),
      'no_enviadas' => (int) ($row['no_enviadas'] ?? 0),
      'aprobadas' => (int) ($row['aprobadas'] ?? 0),
      'desaprobadas' => (int) ($row['desaprobadas'] ?? 0),
      'esperando_respuesta' => (int) ($row['esperando_respuesta'] ?? 0),
      'finalizadas' => (int) ($row['finalizadas'] ?? 0),
      'sin_estado' => (int) ($row['sin_estado'] ?? 0),
      'ordenes_total' => $ordersTotal,
      'valor_total' => (float) ($row['valor_total'] ?? 0),
    ];
  }

  private function render_my_tickets_panel(array $result, array $stats, array $params, array $filterOptions): string
  {
    $cards = (string) ($result['tbody'] ?? '');
    $pagination = (string) ($result['pagination_html'] ?? '');
    $form = $this->render_status_maintenance_filter_form('mis_tickets', 'scm_my_', $params, $filterOptions, 'Mis tickets');
    return '<span id="scm-mis_tickets-count" style="display:none;">' . esc_html((string) ($stats['total'] ?? 0)) . '</span>'
      . '<div class="scm-status-topic-head"><div><h3>Mis tickets</h3><p>Tickets abiertos asignados a tu funcionario.</p></div><span class="scm-status-count"><strong>' . esc_html((string) ($stats['total'] ?? 0)) . '</strong> tickets</span></div>'
      . $form
      . '<div class="scm-cards-wrap"><div class="scm-ticket-cards" id="scm-cards-mis_tickets">' . $cards . '</div></div>'
      . '<div class="scm-pagination" id="scm-pagination-mis_tickets">' . $pagination . '</div>';
  }

  /** @param array<string,mixed> $config */
  private function render_calendario_actividades_panel(array $config): string
  {
    $appUrl = rtrim(trim((string) ($config['calendar_app_url'] ?? self::DEFAULT_CALENDAR_APP_URL)), '/');
    $apiUrl = trim((string) ($config['calendar_api_url'] ?? self::DEFAULT_CALENDAR_API_URL));
    if ($appUrl === '') {
      $appUrl = rtrim(self::DEFAULT_CALENDAR_APP_URL, '/');
    }
    if ($apiUrl === '') {
      $apiUrl = self::DEFAULT_CALENDAR_API_URL;
    }
    $allowedFuncionarios = is_array($config['calendar_allowed_funcionarios'] ?? null) ? $config['calendar_allowed_funcionarios'] : [];
    $allowedCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? $config['calendar_allowed_cargos'] : FuncionarioOptions::panelCargoIds();
    $currentCalendarEmployeeId = trim((string) ($config['calendar_current_employee_id'] ?? ''));
    $allowedFuncionariosJson = json_encode($allowedFuncionarios, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $todayLabel = date('Y-m-d');

    ob_start();
?>
    <div class="scm-calendar-panel"
      data-scm-calendar-panel
      data-calendar-app-url="<?php echo esc_attr($appUrl); ?>"
      data-calendar-api-url="<?php echo esc_attr($apiUrl); ?>"
      data-calendar-current-employee-id="<?php echo esc_attr($currentCalendarEmployeeId); ?>"
      data-calendar-allowed-cargos="<?php echo esc_attr(implode(',', array_map('strval', $allowedCargos))); ?>"
      data-calendar-employees-json="<?php echo esc_attr($allowedFuncionariosJson ?: '[]'); ?>">
      <div class="scm-status-topic-head scm-calendar-head">
        <div>
          <h3>Calendario</h3>
          <p>Agenda citas, consulta disponibilidad y crea eventos solo para los cargos operativos permitidos.</p>
        </div>
        <div class="scm-calendar-head-actions">
          <span class="scm-status-count"><strong data-scm-calendar-total>0</strong> eventos</span>
          <button type="button" class="scm-btn-primary btn btn-primary" data-scm-calendar-open-create data-calendar-mode="single">Crear evento</button>
          <button type="button" class="scm-case-work-btn" data-scm-calendar-open-create data-calendar-mode="multiple">Evento m&uacute;ltiple</button>
          <button type="button" class="scm-case-work-btn" data-scm-calendar-open-pending>Eventos pendientes</button>
          <button type="button" class="scm-case-work-btn scm-calendar-report-btn" data-scm-calendar-open-report>Informe del d&iacute;a</button>
        </div>
      </div>

      <div class="scm-calendar-kpis">
        <div class="scm-kpi"><div class="scm-kpi-label">Pendientes</div><div class="scm-kpi-value" data-scm-calendar-pending>0</div></div>
        <div class="scm-kpi"><div class="scm-kpi-label">Realizados</div><div class="scm-kpi-value" data-scm-calendar-done>0</div></div>
        <div class="scm-kpi"><div class="scm-kpi-label">Hoy</div><div class="scm-kpi-value" data-scm-calendar-today>0</div></div>
        <div class="scm-kpi"><div class="scm-kpi-label">Mes visible</div><div class="scm-kpi-value scm-calendar-range-value" data-scm-calendar-range><?php echo esc_html($todayLabel); ?></div></div>
      </div>

      <section class="scm-calendar-card scm-calendar-filter-card">
        <form class="scm-calendar-filter-form" data-scm-calendar-filters autocomplete="off">
          <div class="scm-grid">
            <div class="scm-field"><label>Funcionario</label><select class="select select-bordered select-sm scm-select" name="id_empleado" data-scm-calendar-filter-employees><option value="">Selecciona funcionario</option></select></div>
            <div class="scm-field"><label>Categor&iacute;a</label><select class="select select-bordered select-sm scm-select" name="id_categoria" data-scm-calendar-filter-categories><option value="">Todas</option></select></div>
            <div class="scm-field"><label>Estado</label><select class="select select-bordered select-sm scm-select" name="estado"><option value="">Todos</option><option value="No" selected>Pendientes</option><option value="Si">Realizados</option></select></div>
          </div>
          <div class="scm-actions">
            <button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button>
            <button class="scm-btn-secondary btn btn-outline" type="button" data-scm-calendar-clear>Limpiar</button>
            <span class="scm-spinner" data-scm-calendar-spinner><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
          </div>
        </form>
      </section>

      <div class="scm-calendar-layout">
        <section class="scm-calendar-card scm-calendar-board-card">
          <div class="scm-calendar-card-head">
            <div>
              <span class="scm-calendar-action-kicker">Vista mensual</span>
              <h4 data-scm-calendar-title>Calendario</h4>
              <p>Haz clic en un d&iacute;a para ver sus eventos o crear uno nuevo.</p>
            </div>
            <div class="scm-calendar-month-actions">
              <button type="button" class="scm-case-work-btn" data-scm-calendar-prev aria-label="Mes anterior">&lsaquo;</button>
              <button type="button" class="scm-case-work-btn" data-scm-calendar-today-btn>Hoy</button>
              <button type="button" class="scm-case-work-btn" data-scm-calendar-next aria-label="Mes siguiente">&rsaquo;</button>
            </div>
          </div>
          <div class="scm-calendar-weekdays" aria-hidden="true"><span>Lun</span><span>Mar</span><span>Mi&eacute;</span><span>Jue</span><span>Vie</span><span>S&aacute;b</span><span>Dom</span></div>
          <div class="scm-calendar-month-grid" data-scm-calendar-grid aria-live="polite">
            <div class="scm-calendar-loading">Cargando calendario...</div>
          </div>
        </section>

        <section class="scm-calendar-card scm-calendar-day-card">
          <div class="scm-calendar-card-head">
            <div>
              <span class="scm-calendar-action-kicker">Agenda del d&iacute;a</span>
              <h4 data-scm-calendar-day-title>Selecciona un d&iacute;a</h4>
              <p data-scm-calendar-day-subtitle>Los eventos se muestran seg&uacute;n funcionario, estado y categor&iacute;a.</p>
            </div>
            <button type="button" class="scm-case-work-btn" data-scm-calendar-refresh>Actualizar</button>
          </div>
          <div class="scm-calendar-events" data-scm-calendar-events><div class="scm-empty scm-empty-cards">Selecciona un d&iacute;a del calendario.</div></div>
          <button type="button" class="scm-btn-primary btn btn-primary scm-calendar-day-create" data-scm-calendar-open-create data-calendar-mode="single">Crear evento para este d&iacute;a</button>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @return array<int,array<string,string>> */
  private function get_calendar_allowed_funcionarios(): array
  {
    $rows = FuncionarioOptions::panelFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db));
    $out = [];
    foreach ($rows as $row) {
      $employeeId = trim((string) ($row['employee_id'] ?? $row['id'] ?? ''));
      if ($employeeId === '') {
        continue;
      }
      $out[] = [
        'id_empleado' => $employeeId,
        'nombre' => trim((string) ($row['name'] ?? $employeeId)),
        'rol' => trim((string) ($row['cargo'] ?? '')),
        'id_cargo' => trim((string) ($row['id_cargo'] ?? '')),
      ];
    }
    return $out;
  }

  private function render_admin_notifications_panel(): string
  {
    $service = new AdministrativeNotificationsService($this->db);
    $types = $service->types();
    $stats = $service->stats();
    $emailTemplates = $service->emailTemplates();
    $whatsappTemplates = $service->whatsappTemplates();
    $senderProfile = $service->senderProfile();
    $firstType = (string) array_key_first($types);
    $firstEmailTemplate = is_array(reset($emailTemplates)) ? reset($emailTemplates) : [];
    $defaultEmailSubject = (string) ($firstEmailTemplate['subject'] ?? '');
    $emailBannerUrl = function_exists('system_image')
      ? system_image('banner_sitio_web', 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/banner-sitio-web.png')
      : 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/banner-sitio-web.png';

    ob_start();
?>
    <div class="scm-admin-notifications"
      data-scm-admin-notifications
      data-admin-notif-sms-prefix="<?php echo esc_attr(\SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService::SMS_PREFIX); ?>"
      data-admin-notif-sender-name="<?php echo esc_attr((string) ($senderProfile['name'] ?? '')); ?>"
      data-admin-notif-sender-cargo="<?php echo esc_attr((string) ($senderProfile['cargo'] ?? '')); ?>"
      data-admin-notif-sender-phone="<?php echo esc_attr((string) ($senderProfile['phone'] ?? '')); ?>"
      data-admin-notif-sender-signature="<?php echo esc_attr((string) ($senderProfile['signature'] ?? '')); ?>"
      data-admin-notif-email-banner="<?php echo esc_attr((string) $emailBannerUrl); ?>">
      <div class="scm-status-topic-head scm-admin-notif-head">
        <div>
          <h3>Notificaciones</h3>
          <p>Busca destinatarios y encola mensajes por Email, SMS o WhatsApp sin crear campa&ntilde;as.</p>
        </div>
        <span class="scm-status-count"><strong data-admin-notif-total>0</strong> destinatarios</span>
      </div>

      <div class="scm-admin-notif-stats" aria-label="Resumen de destinatarios">
        <?php foreach ($types as $typeKey => $typeDef): $typeStats = $stats[$typeKey] ?? ['total' => 0, 'email' => 0, 'phone' => 0]; ?>
          <button type="button" class="scm-admin-notif-stat<?php echo $typeKey === $firstType ? ' active' : ''; ?>" data-admin-notif-type-shortcut="<?php echo esc_attr((string) $typeKey); ?>">
            <span><?php echo esc_html((string) ($typeDef['label'] ?? $typeKey)); ?></span>
            <strong><?php echo esc_html((string) ($typeStats['total'] ?? 0)); ?></strong>
            <small><?php echo esc_html((string) ($typeStats['email'] ?? 0)); ?> email · <?php echo esc_html((string) ($typeStats['phone'] ?? 0)); ?> celular</small>
          </button>
        <?php endforeach; ?>
      </div>

      <section class="scm-admin-notif-card scm-admin-notif-import-card" data-admin-notif-import-wrap>
        <form data-admin-notif-import enctype="multipart/form-data">
          <div class="scm-admin-notif-import-copy">
            <span class="scm-calendar-action-kicker">Cruce por Excel SIMI</span>
            <h4>Importar y seleccionar destinatarios</h4>
            <p>Sube un .xls, .xlsx o .csv con columnas como <strong>contrato</strong>, <strong>inmueble_simi</strong>, <strong>NoInm</strong>, <strong>cedula</strong> y <strong>canon</strong>. El sistema cruza el archivo solo con la pesta&ntilde;a activa.</p>
            <p class="scm-admin-notif-import-scope" data-admin-notif-import-scope>Se cruzar&aacute; solo en la pesta&ntilde;a activa.</p>
            <p class="scm-admin-notif-import-examples">
              <a href="assets/examples/notificaciones-importacion-simi.xlsx" download>Descargar ejemplo XLSX</a>
              <a href="assets/examples/notificaciones-importacion-simi.csv" download>Descargar ejemplo CSV</a>
            </p>
          </div>
          <div class="scm-admin-notif-import-controls">
            <input type="file" name="file" accept=".xls,.xlsx,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-admin-notif-import-file>
            <button type="submit" class="scm-btn-primary btn btn-primary">Importar Excel</button>
            <button type="button" class="scm-btn-secondary btn btn-outline" data-admin-notif-import-report hidden>Descargar cruce</button>
            <button type="button" class="scm-btn-secondary btn btn-outline" data-admin-notif-import-clear>Quitar importaci&oacute;n</button>
          </div>
          <p class="scm-admin-notif-import-result" data-admin-notif-import-result aria-live="polite"></p>
        </form>
      </section>

      <section class="scm-admin-notif-card scm-admin-notif-filter-card">
        <form data-admin-notif-search autocomplete="off">
          <input type="hidden" id="scm-admin-notif-type" name="type" value="<?php echo esc_attr($firstType); ?>" data-admin-notif-type>
          <input type="hidden" id="scm-admin-notif-q" name="q" value="" data-admin-notif-query>
          <div class="scm-admin-notif-filter-grid">
            <div class="scm-field scm-admin-notif-search-field">
              <label for="scm-admin-notif-name">Nombre</label>
              <input id="scm-admin-notif-name" name="nombre" type="search" class="input input-bordered input-sm scm-input" placeholder="Nombre o raz&oacute;n social" data-admin-notif-name>
            </div>
            <div class="scm-field scm-admin-notif-search-field">
              <label for="scm-admin-notif-email">Correo</label>
              <input id="scm-admin-notif-email" name="correo" type="search" class="input input-bordered input-sm scm-input" placeholder="correo@ejemplo.com" data-admin-notif-email>
            </div>
            <div class="scm-field scm-admin-notif-search-field">
              <label for="scm-admin-notif-phone">Celular</label>
              <input id="scm-admin-notif-phone" name="celular" type="search" inputmode="tel" class="input input-bordered input-sm scm-input" placeholder="3001234567" data-admin-notif-phone>
            </div>
            <div class="scm-field scm-admin-notif-search-field">
              <label for="scm-admin-notif-document">Documento</label>
              <input id="scm-admin-notif-document" name="documento" type="search" class="input input-bordered input-sm scm-input" placeholder="C&eacute;dula, NIT o documento" data-admin-notif-document>
            </div>
            <div class="scm-field scm-admin-notif-contract-extra" data-admin-notif-contract-extra-wrap>
              <label for="scm-admin-notif-inmueble-simi">Inmueble SIMI</label>
              <input id="scm-admin-notif-inmueble-simi" name="inmueble_simi" type="text" class="input input-bordered input-sm scm-input" placeholder="Ej: 10628" data-admin-notif-inmueble-simi>
            </div>
            <div class="scm-field scm-admin-notif-contract-extra" data-admin-notif-contract-extra-wrap>
              <label for="scm-admin-notif-contract-number">Contrato</label>
              <input id="scm-admin-notif-contract-number" name="contract_number" type="text" class="input input-bordered input-sm scm-input" placeholder="N&uacute;mero de contrato" data-admin-notif-contract-number>
            </div>
            <div class="scm-admin-notif-filter-actions">
              <button type="submit" class="scm-btn-primary btn btn-primary">Buscar</button>
              <button type="button" class="scm-btn-secondary btn btn-outline" data-admin-notif-clear>Limpiar</button>
            </div>
          </div>
        </form>
      </section>

      <div class="scm-admin-notif-layout scm-admin-notif-layout--single">
        <section class="scm-admin-notif-card">
          <div class="scm-admin-notif-section-head">
            <div>
              <span class="scm-calendar-action-kicker">Lista de contactos</span>
              <h4 data-admin-notif-list-title>Propietarios</h4>
              <p>Selecciona registros visibles o marca el env&iacute;o a todos los resultados filtrados.</p>
            </div>
            <div class="scm-admin-notif-section-actions">
              <button type="button" class="scm-case-work-btn" data-admin-notif-select-visible>Seleccionar visibles</button>
              <button type="button" class="scm-admin-notif-channel-launch scm-admin-notif-channel-launch--email" data-admin-notif-open-channel="email">Notificaci&oacute;n Email</button>
              <button type="button" class="scm-admin-notif-channel-launch scm-admin-notif-channel-launch--sms" data-admin-notif-open-channel="sms">Notificaci&oacute;n SMS</button>
              <button type="button" class="scm-admin-notif-channel-launch scm-admin-notif-channel-launch--whatsapp" data-admin-notif-open-channel="whatsapp">Notificaci&oacute;n WhatsApp</button>
              <button type="button" class="scm-admin-notif-channel-launch scm-admin-notif-channel-launch--all" data-admin-notif-open-all-channels>Todos los canales</button>
            </div>
          </div>
          <div class="scm-admin-notif-recipients" data-admin-notif-recipients>
            <div class="scm-admin-notif-empty"><strong>Cargando destinatarios...</strong><span>Un momento mientras preparamos la lista.</span></div>
          </div>
          <div class="scm-admin-notif-pagination" data-admin-notif-pagination></div>
        </section>
      </div>

      <div class="scm-admin-notif-modal" data-admin-notif-modal hidden role="dialog" aria-modal="true" aria-labelledby="scm-admin-notif-modal-title">
        <div class="scm-admin-notif-modal-backdrop" aria-hidden="true"></div>
        <section class="scm-admin-notif-card scm-admin-notif-composer scm-admin-notif-modal-panel">
          <div class="scm-admin-notif-modal-head">
            <div class="scm-admin-notif-modal-titleblock">
              <span class="scm-calendar-action-kicker">Mensaje</span>
              <h4 id="scm-admin-notif-modal-title">Enviar notificaci&oacute;n</h4>
              <p data-admin-notif-modal-description>Prepara el mensaje, elige canales y revisa la vista previa antes de encolar. Si hay texto escrito, el cierre pide confirmaci&oacute;n.</p>
            </div>
            <button type="button" class="scm-modal-close" data-admin-notif-close-composer aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <form data-admin-notif-send autocomplete="off">
            <input type="hidden" name="type" value="<?php echo esc_attr($firstType); ?>" data-admin-notif-send-type>
            <input type="hidden" name="q" value="" data-admin-notif-send-query>
            <input type="hidden" name="nombre" value="" data-admin-notif-send-name>
            <input type="hidden" name="correo" value="" data-admin-notif-send-email>
            <input type="hidden" name="celular" value="" data-admin-notif-send-phone>
            <input type="hidden" name="documento" value="" data-admin-notif-send-document>
            <input type="hidden" name="contract_status" value="" data-admin-notif-send-contract-status>
            <input type="hidden" name="inmueble_simi" value="" data-admin-notif-send-inmueble-simi>
            <input type="hidden" name="contract_number" value="" data-admin-notif-send-contract-number>
            <input type="hidden" name="import_payload" value="" data-admin-notif-import-payload>

            <div class="scm-admin-notif-section-head scm-admin-notif-selected-head">
              <div>
                <span class="scm-calendar-action-kicker">Destinatarios</span>
                <h4><strong data-admin-notif-selected-count>0</strong> seleccionados</h4>
                <p>Escoge canales, plantilla y revisa la vista previa antes de encolar.</p>
              </div>
              <button type="button" class="scm-btn-secondary btn btn-outline" data-admin-notif-open-selected>Ver seleccionados</button>
            </div>

            <div class="scm-admin-notif-channel-group" role="group" aria-label="Canales">
              <label class="scm-admin-notif-channel-pill scm-admin-notif-channel-pill--email">
                <input type="checkbox" name="channels[]" value="email" data-admin-notif-channel checked>
                <span class="scm-admin-notif-channel-icon" aria-hidden="true">E</span>
                <span class="scm-admin-notif-channel-copy"><strong>Email</strong><small>HTML con banner y firma</small></span>
              </label>
              <label class="scm-admin-notif-channel-pill scm-admin-notif-channel-pill--sms">
                <input type="checkbox" name="channels[]" value="sms" data-admin-notif-channel>
                <span class="scm-admin-notif-channel-icon" aria-hidden="true">S</span>
                <span class="scm-admin-notif-channel-copy"><strong>SMS</strong><small>Texto corto con marca</small></span>
              </label>
              <label class="scm-admin-notif-channel-pill scm-admin-notif-channel-pill--whatsapp">
                <input type="checkbox" name="channels[]" value="whatsapp" data-admin-notif-channel>
                <span class="scm-admin-notif-channel-icon" aria-hidden="true">W</span>
                <span class="scm-admin-notif-channel-copy"><strong>WhatsApp</strong><small>Plantilla oficial Meta</small></span>
              </label>
            </div>

            <label class="scm-admin-notif-all">
              <input type="checkbox" name="all_filtered" value="1" data-admin-notif-all-filtered>
              <span>Usar todos los resultados filtrados al encolar</span>
            </label>

            <input type="hidden"
              name="email_template"
              value="<?php echo esc_attr((string) ($firstEmailTemplate['name'] ?? '')); ?>"
              data-admin-notif-email-template
              data-subject="<?php echo esc_attr((string) ($firstEmailTemplate['subject'] ?? '')); ?>"
              data-message="<?php echo esc_attr((string) ($firstEmailTemplate['editable_message'] ?? $firstEmailTemplate['body'] ?? '')); ?>"
              data-preview-template="<?php echo esc_attr((string) ($firstEmailTemplate['body'] ?? '')); ?>"
              data-requires-message="<?php echo !empty($firstEmailTemplate['requires_message']) ? '1' : '0'; ?>"
              data-message-only="<?php echo !empty($firstEmailTemplate['message_only']) ? '1' : '0'; ?>"
              data-template-html="<?php echo !empty($firstEmailTemplate['is_html']) ? '1' : '0'; ?>"
              data-template-fixed="<?php echo !empty($firstEmailTemplate['fixed_body']) ? '1' : '0'; ?>"
              data-template-full="<?php echo !empty($firstEmailTemplate['is_full_document']) ? '1' : '0'; ?>">

            <div class="scm-admin-notif-template-card" data-admin-notif-whatsapp-template-wrap>
              <div class="scm-field">
                <label for="scm-admin-notif-whatsapp-template">Tipo de mensaje</label>
                <select id="scm-admin-notif-whatsapp-template" name="whatsapp_template" class="select select-bordered select-sm scm-select" data-admin-notif-whatsapp-template>
                  <?php foreach ($whatsappTemplates as $templateKey => $template):
                    $linkedEmailTemplateName = (string) ($template['email_template'] ?? \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE);
                    $linkedEmailTemplate = is_array($emailTemplates[$linkedEmailTemplateName] ?? null)
                      ? $emailTemplates[$linkedEmailTemplateName]
                      : ($emailTemplates[\SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE] ?? $firstEmailTemplate);
                    $templateActors = implode(',', array_map('strval', (array) ($template['actors'] ?? [])));
                    ?>
                    <option value="<?php echo esc_attr((string) $templateKey); ?>"
                      data-template-name="<?php echo esc_attr((string) ($template['name'] ?? $templateKey)); ?>"
                      data-actors="<?php echo esc_attr($templateActors); ?>"
                      data-template-mode="<?php echo esc_attr((string) ($template['parameter_mode'] ?? 'name_message_signature')); ?>"
                      data-template-body="<?php echo esc_attr((string) ($template['body'] ?? '')); ?>"
                      data-template-variables="<?php echo esc_attr((string) json_encode(array_values((array) ($template['variables'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                      data-email-template="<?php echo esc_attr((string) ($linkedEmailTemplate['name'] ?? $linkedEmailTemplateName)); ?>"
                      data-email-subject="<?php echo esc_attr((string) ($linkedEmailTemplate['subject'] ?? '')); ?>"
                      data-email-message="<?php echo esc_attr((string) ($linkedEmailTemplate['editable_message'] ?? $linkedEmailTemplate['body'] ?? '')); ?>"
                      data-email-preview-template="<?php echo esc_attr((string) ($linkedEmailTemplate['body'] ?? '')); ?>"
                      data-email-requires-message="<?php echo !empty($linkedEmailTemplate['requires_message']) ? '1' : '0'; ?>"
                      data-email-message-only="<?php echo !empty($linkedEmailTemplate['message_only']) ? '1' : '0'; ?>"
                      data-email-template-html="<?php echo !empty($linkedEmailTemplate['is_html']) ? '1' : '0'; ?>"
                      data-email-template-fixed="<?php echo !empty($linkedEmailTemplate['fixed_body']) ? '1' : '0'; ?>"
                      data-email-template-full="<?php echo !empty($linkedEmailTemplate['is_full_document']) ? '1' : '0'; ?>">
                      <?php echo esc_html((string) ($template['label'] ?? $template['name'] ?? 'Plantilla')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="scm-field" data-admin-notif-subject-wrap>
              <label for="scm-admin-notif-subject">Asunto para Email</label>
              <input id="scm-admin-notif-subject" name="subject" type="text" class="input input-bordered input-sm scm-input" placeholder="Ej: Informaci&oacute;n importante" value="<?php echo esc_attr($defaultEmailSubject); ?>" data-admin-notif-subject>
            </div>

            <div class="scm-field scm-admin-notif-message-field" data-admin-notif-message-field>
              <label for="scm-admin-notif-message">Mensaje</label>
              <div class="scm-admin-notif-message-toolbar" aria-label="Herramientas del mensaje">
                <button type="button" data-admin-notif-preview-toggle>Ocultar vista previa</button>
                <button type="button" data-admin-notif-copy-message>Copiar</button>
                <button type="button" data-admin-notif-clear-message>Limpiar</button>
              </div>
              <textarea id="scm-admin-notif-message" name="message" class="textarea textarea-bordered scm-textarea" rows="8" placeholder="Escribe el mensaje..." data-admin-notif-message></textarea>
              <small class="scm-admin-notif-sms-counter" data-admin-notif-sms-counter>0/160 SMS</small>
            </div>

            <div class="scm-admin-notif-no-message-note" data-admin-notif-no-message-note hidden>
              <strong>Esta plantilla no necesita mensaje adicional.</strong>
              <span>Solo revisa la vista previa y encola la notificaci&oacute;n.</span>
            </div>

            <div class="scm-admin-notif-preview" data-admin-notif-preview></div>

            <div class="scm-admin-notif-submit-row">
              <span class="scm-spinner" data-admin-notif-spinner><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
              <button type="submit" class="scm-btn-primary btn btn-primary" data-admin-notif-submit>Encolar notificaci&oacute;n</button>
            </div>
            <p class="scm-admin-notif-result" data-admin-notif-result aria-live="polite"></p>
          </form>
        </section>
        <div class="scm-admin-notif-confirm" data-admin-notif-confirm hidden role="dialog" aria-modal="true" aria-labelledby="scm-admin-notif-confirm-title">
          <div class="scm-admin-notif-confirm-backdrop" aria-hidden="true"></div>
          <section class="scm-admin-notif-confirm-panel">
            <span class="scm-calendar-action-kicker">Confirmaci&oacute;n</span>
            <h5 id="scm-admin-notif-confirm-title">Cerrar notificaci&oacute;n</h5>
            <p>Tienes una notificaci&oacute;n en edici&oacute;n. &iquest;Quieres cerrar sin enviarla?</p>
            <div class="scm-admin-notif-confirm-actions">
              <button type="button" class="scm-case-work-btn" data-admin-notif-confirm-cancel>Seguir editando</button>
              <button type="button" class="scm-btn-primary btn btn-primary" data-admin-notif-confirm-accept>Cerrar sin enviar</button>
            </div>
          </section>
        </div>
      </div>

      <div class="scm-admin-notif-modal scm-admin-notif-selected-modal" data-admin-notif-selected-modal hidden role="dialog" aria-modal="true" aria-labelledby="scm-admin-notif-selected-modal-title">
        <div class="scm-admin-notif-modal-backdrop" aria-hidden="true"></div>
        <section class="scm-admin-notif-card scm-admin-notif-modal-panel">
          <div class="scm-admin-notif-modal-head">
            <div class="scm-admin-notif-modal-titleblock">
              <span class="scm-calendar-action-kicker">Destinatarios</span>
              <h4 id="scm-admin-notif-selected-modal-title">Seleccionados para enviar</h4>
              <p data-admin-notif-selected-modal-summary>Revisa los destinatarios marcados y quita los que no deban recibir la notificaci&oacute;n.</p>
            </div>
            <button type="button" class="scm-modal-close" data-admin-notif-close-selected aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="scm-admin-notif-selected-tools">
            <button type="button" class="scm-btn-secondary btn btn-outline" data-admin-notif-selected-clear>Deseleccionar todos</button>
          </div>
          <div class="scm-admin-notif-selected-list" data-admin-notif-selected-list></div>
        </section>
      </div>

    </div>
<?php
    return (string) ob_get_clean();
  }

  private function render_admin_notifications_shell(): string
  {
    return '<div class="scm-admin-notif-empty" data-scm-admin-notifications-shell role="status" aria-live="polite">'
      . '<strong>Notificaciones listas para consultar</strong>'
      . '<span>Abre esta sección para cargar destinatarios y plantillas.</span>'
      . '</div>';
  }

  private function render_collection_management_panel(array $input): string
  {
    $portfolioFilters = [
      'status' => trim((string) ($input['scmgc_estado'] ?? '')),
      'stage' => trim((string) ($input['scmgc_etapa'] ?? '')),
      'movement' => trim((string) ($input['scmgc_movimiento'] ?? '')),
      'search' => trim((string) ($input['scmgc_buscar'] ?? '')),
      'page' => max(1, (int) ($input['scmgc_cartera_page'] ?? 1)),
      'per_page' => 40,
    ];
    $portfolio = (new CollectionPortfolioService($this->db))->dashboard($portfolioFilters);
    $portfolioRows = is_array($portfolio['rows'] ?? null) ? $portfolio['rows'] : [];
    $portfolioSummary = is_array($portfolio['summary'] ?? null) ? $portfolio['summary'] : [];
    $portfolioApplied = is_array($portfolio['filters'] ?? null) ? $portfolio['filters'] : [];
    $portfolioPagination = is_array($portfolio['pagination'] ?? null) ? $portfolio['pagination'] : [];
    $latestImport = is_array($portfolio['latest_import'] ?? null) ? $portfolio['latest_import'] : null;

    $managementFilters = [
      'date_from' => trim((string) ($input['scmgc_fecha_desde'] ?? '')),
      'date_to' => trim((string) ($input['scmgc_fecha_hasta'] ?? '')),
      'type' => trim((string) ($input['scmgc_tipo'] ?? '')),
    ];
    $page = max(1, (int) ($input['scmgc_page'] ?? 1));
    $service = new AdministrativeNotificationsService($this->db);
    $report = $service->collectionManagementReport($managementFilters, $page, 20);
    $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    $stats = is_array($report['stats'] ?? null) ? $report['stats'] : ['total' => 0, 'by_type' => []];
    $byType = is_array($stats['by_type'] ?? null) ? $stats['by_type'] : [];
    $types = array_values(array_unique(array_filter(array_map('strval', (array) ($report['types'] ?? [])))));
    foreach (['Canon', 'Administracion', 'Servicios publicos'] as $fixedType) {
      if (!in_array($fixedType, $types, true)) {
        $types[] = $fixedType;
      }
    }
    $pagination = is_array($report['pagination'] ?? null) ? $report['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
    $applied = is_array($report['filters'] ?? null) ? $report['filters'] : ['date_from' => '', 'date_to' => '', 'type' => ''];
    $currentTotal = (int) ($portfolioPagination['total'] ?? 0);
    $dateFrom = (string) ($applied['date_from'] ?? '');
    $dateTo = (string) ($applied['date_to'] ?? '');
    $currentType = (string) ($applied['type'] ?? '');
    $activeView = sanitize_key((string) ($input['scmgc_view'] ?? 'principal'));
    if (!in_array($activeView, ['principal', 'informe', 'historial'], true)) {
      $activeView = 'principal';
    }
    $totalAccounts = (int) ($portfolioSummary['total'] ?? 0);
    $debtorAccounts = (int) ($portfolioSummary['debtors'] ?? 0);
    $currentAccounts = (int) ($portfolioSummary['current'] ?? 0);
    $creditAccounts = (int) ($portfolioSummary['credits'] ?? 0);
    $upToDateAccounts = $currentAccounts;
    $notUpToDateAccounts = $debtorAccounts;
    $reviewAccounts = (int) ($portfolioSummary['without_data'] ?? 0) + (int) ($portfolioSummary['unmatched'] ?? 0);
    $prelegalAccounts = (int) ($portfolioSummary['prejuridical'] ?? 0);
    $claimAccounts = (int) ($portfolioSummary['claims'] ?? 0);
    $normalDebtAccounts = max(0, $debtorAccounts - $prelegalAccounts - $claimAccounts);
    $portfolioBalance = (float) ($portfolioSummary['balance'] ?? 0);
    $averageDebt = $debtorAccounts > 0 ? $portfolioBalance / $debtorAccounts : 0.0;
    $debtRate = $totalAccounts > 0 ? (int) round(($debtorAccounts / $totalAccounts) * 100) : 0;
    $matchedRate = $totalAccounts > 0 ? (int) round(((max(0, $totalAccounts - $reviewAccounts)) / $totalAccounts) * 100) : 0;
    $stageTotal = $normalDebtAccounts + $prelegalAccounts + $claimAccounts;
    $normalStageRate = $stageTotal > 0 ? (int) round(($normalDebtAccounts / $stageTotal) * 100) : 0;
    $prelegalStageRate = $stageTotal > 0 ? (int) round(($prelegalAccounts / $stageTotal) * 100) : 0;
    $claimStageRate = $stageTotal > 0 ? max(0, 100 - $normalStageRate - $prelegalStageRate) : 0;
    $reportCutoff = is_array($latestImport)
      ? $this->collection_date((string) ($latestImport['source_date'] ?? ''))
      : 'Sin auxiliar cargado';

    ob_start();
?>
    <div class="scm-collection-log scm-portfolio" data-scm-collection-log data-scm-collection-log-loaded="1" data-scm-portfolio>
      <div class="scm-status-topic-head scm-collection-log-head">
        <div>
          <span class="scm-calendar-action-kicker">Cartera y cobranza</span>
          <h3>Control de cartera y gestiones de cobro</h3>
          <p>Actualiza saldos con el auxiliar 1380, identifica pagos, registra gestiones y prepara cartas prejur&iacute;dicas o de siniestro desde un solo lugar.</p>
        </div>
        <span class="scm-status-count"><strong><?php echo esc_html((string) $currentTotal); ?></strong> cuentas visibles</span>
      </div>

      <div class="scm-portfolio-tabs" role="tablist" aria-label="Vistas de control de cartera">
        <?php foreach (['principal' => ['Cartera principal', 'Operación diaria'], 'informe' => ['Informe gerencial', 'Resumen para dirección'], 'historial' => ['Historial', 'Trazabilidad completa']] as $viewKey => $viewMeta): ?>
          <button type="button" id="scm-portfolio-tab-<?php echo esc_attr($viewKey); ?>" class="scm-portfolio-tab<?php echo $activeView === $viewKey ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $activeView === $viewKey ? 'true' : 'false'; ?>" aria-controls="scm-portfolio-panel-<?php echo esc_attr($viewKey); ?>" tabindex="<?php echo $activeView === $viewKey ? '0' : '-1'; ?>" data-scm-portfolio-tab="<?php echo esc_attr($viewKey); ?>">
            <strong><?php echo esc_html($viewMeta[0]); ?></strong><span><?php echo esc_html($viewMeta[1]); ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <section id="scm-portfolio-panel-informe" class="scm-portfolio-view scm-portfolio-report" role="tabpanel" aria-labelledby="scm-portfolio-tab-informe" data-scm-portfolio-panel="informe"<?php echo $activeView === 'informe' ? '' : ' hidden'; ?>>
        <div class="scm-portfolio-report-head">
          <div><span class="scm-calendar-action-kicker">Informe para gerencia administrativa</span><h4>Estado ejecutivo de la cartera</h4><p>Cifras consolidadas del &uacute;ltimo auxiliar y de las gestiones registradas. Corte contable: <strong><?php echo esc_html($reportCutoff); ?></strong>.</p></div>
          <button type="button" class="scm-btn-secondary btn btn-outline" data-scm-portfolio-report-print>Imprimir / guardar PDF</button>
        </div>

      <div class="scm-portfolio-kpis" aria-label="Resumen ejecutivo de cartera">
        <article class="scm-portfolio-kpi scm-portfolio-kpi--money"><span>Cartera pendiente</span><strong><?php echo esc_html($this->collection_money($portfolioSummary['balance'] ?? 0)); ?></strong><small><?php echo esc_html((string) ((int) ($portfolioSummary['debtors'] ?? 0))); ?> contratos con saldo</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--danger"><span>Contratos no al d&iacute;a</span><strong><?php echo esc_html((string) $notUpToDateAccounts); ?></strong><small>Saldo positivo pendiente</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--success"><span>Contratos al d&iacute;a</span><strong><?php echo esc_html((string) $upToDateAccounts); ?></strong><small>Saldo exacto en 0</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--credit"><span>Saldo a favor</span><strong><?php echo esc_html((string) $creditAccounts); ?></strong><small>No registran deuda</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--paid"><span>Pagaron en el &uacute;ltimo cargue</span><strong><?php echo esc_html((string) ((int) ($portfolioSummary['paid'] ?? 0))); ?></strong><small>Dejaron de tener saldo positivo</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--warning"><span>Sin cruce</span><strong><?php echo esc_html((string) ((int) ($portfolioSummary['without_data'] ?? 0) + (int) ($portfolioSummary['unmatched'] ?? 0))); ?></strong><small>Requieren verificaci&oacute;n</small></article>
        <article class="scm-portfolio-kpi scm-portfolio-kpi--claim"><span>Siniestrados</span><strong><?php echo esc_html((string) ((int) ($portfolioSummary['claims'] ?? 0))); ?></strong><small><?php echo esc_html((string) ((int) ($portfolioSummary['prejuridical'] ?? 0))); ?> en prejur&iacute;dico</small></article>
      </div>

        <div class="scm-portfolio-report-grid">
          <article class="scm-portfolio-report-card scm-portfolio-report-card--summary">
            <span class="scm-calendar-action-kicker">Lectura ejecutiva</span>
            <h5>Resumen del corte</h5>
            <p>La cartera pendiente es de <strong><?php echo esc_html($this->collection_money($portfolioBalance)); ?></strong>, distribuida en <strong><?php echo esc_html((string) $debtorAccounts); ?> contratos</strong>. El saldo promedio por contrato moroso es de <strong><?php echo esc_html($this->collection_money($averageDebt)); ?></strong>.</p>
            <ul>
              <li><strong><?php echo esc_html((string) $upToDateAccounts); ?></strong> contratos est&aacute;n al d&iacute;a y <strong><?php echo esc_html((string) $notUpToDateAccounts); ?></strong> no est&aacute;n al d&iacute;a por saldo positivo.</li>
              <li><strong><?php echo esc_html((string) ((int) ($portfolioSummary['paid'] ?? 0))); ?></strong> contratos pagaron desde el cargue anterior.</li>
              <li><strong><?php echo esc_html((string) $prelegalAccounts); ?></strong> est&aacute;n en etapa prejur&iacute;dica y <strong><?php echo esc_html((string) $claimAccounts); ?></strong> marcados como siniestro.</li>
              <li><strong><?php echo esc_html((string) $reviewAccounts); ?></strong> registros requieren verificaci&oacute;n de cruce con contratos.</li>
              <li>Se han registrado <strong><?php echo esc_html((string) ((int) ($stats['total'] ?? 0))); ?></strong> gestiones de cobro en el historial.</li>
            </ul>
          </article>
          <article class="scm-portfolio-report-card">
            <span class="scm-calendar-action-kicker">Indicadores de control</span>
            <h5>Calidad y exposici&oacute;n</h5>
            <div class="scm-portfolio-report-metric"><span>Contratos no al d&iacute;a</span><strong><?php echo esc_html((string) $notUpToDateAccounts); ?></strong><small><?php echo esc_html((string) $debtRate); ?>% de la cartera vigente</small></div>
            <div class="scm-portfolio-report-metric"><span>Contratos al d&iacute;a</span><strong><?php echo esc_html((string) $upToDateAccounts); ?></strong><small>Saldo exacto en 0</small></div>
            <div class="scm-portfolio-report-metric"><span>Cuentas cruzadas</span><strong><?php echo esc_html((string) $matchedRate); ?>%</strong><small><?php echo esc_html((string) max(0, $totalAccounts - $reviewAccounts)); ?> cuentas identificadas</small></div>
            <div class="scm-portfolio-report-metric"><span>Al d&iacute;a o a favor</span><strong><?php echo esc_html((string) ($currentAccounts + $creditAccounts)); ?></strong><small><?php echo esc_html((string) $creditAccounts); ?> presentan saldo a favor</small></div>
          </article>
        </div>

        <div class="scm-portfolio-report-grid scm-portfolio-report-grid--lower">
          <article class="scm-portfolio-report-card">
            <span class="scm-calendar-action-kicker">Etapas de cobro</span>
            <h5>Distribuci&oacute;n de contratos con deuda</h5>
            <?php foreach ([['Cobro normal', $normalDebtAccounts, $normalStageRate, 'normal'], ['Prejurídico', $prelegalAccounts, $prelegalStageRate, 'prelegal'], ['Siniestro', $claimAccounts, $claimStageRate, 'claim']] as $stageMetric): ?>
              <div class="scm-portfolio-report-bar-row"><div><span><?php echo esc_html($stageMetric[0]); ?></span><strong><?php echo esc_html((string) $stageMetric[1]); ?></strong></div><div class="scm-portfolio-report-bar" role="img" aria-label="<?php echo esc_attr($stageMetric[0] . ': ' . $stageMetric[1] . ' contratos'); ?>"><i class="scm-portfolio-report-bar-fill scm-portfolio-report-bar-fill--<?php echo esc_attr($stageMetric[3]); ?>" style="--scm-portfolio-bar: <?php echo esc_attr((string) max(0, min(100, (int) $stageMetric[2]))); ?>%"></i></div></div>
            <?php endforeach; ?>
          </article>
          <article class="scm-portfolio-report-card">
            <span class="scm-calendar-action-kicker">Actividad del equipo</span>
            <h5>Gestiones por concepto</h5>
            <?php if ($byType === []): ?><p class="scm-portfolio-report-empty">A&uacute;n no hay gestiones clasificadas para resumir.</p><?php else: ?>
              <dl class="scm-portfolio-report-types"><?php foreach ($byType as $typeLabel => $typeTotal): ?><div><dt><?php echo esc_html((string) $typeLabel); ?></dt><dd><?php echo esc_html((string) ((int) $typeTotal)); ?></dd></div><?php endforeach; ?></dl>
            <?php endif; ?>
          </article>
        </div>
      </section>

      <section id="scm-portfolio-panel-principal" class="scm-portfolio-view" role="tabpanel" aria-labelledby="scm-portfolio-tab-principal" data-scm-portfolio-panel="principal"<?php echo $activeView === 'principal' ? '' : ' hidden'; ?>>
        <div class="scm-portfolio-workflow" aria-label="Guía de acciones de cartera">
          <article><span>1</span><div><strong>Hacer gesti&oacute;n</strong><p>Registra llamada, acuerdo o compromiso; puede programar seguimiento y enviar mensajes. No cambia la etapa de cobro.</p></div></article>
          <article><span>2</span><div><strong>Preparar carta</strong><p>Abre una vista previa. Descargar o enviar la carta registra la etapa prejur&iacute;dica o de siniestro.</p></div></article>
          <article><span>3</span><div><strong>Marcar siniestro</strong><p>Solo cambia el estado del contrato y deja trazabilidad. No crea ni env&iacute;a una carta por s&iacute; solo.</p></div></article>
        </div>

      <section class="scm-admin-notif-card scm-portfolio-import-card">
        <form data-scm-portfolio-import enctype="multipart/form-data">
          <div>
            <span class="scm-calendar-action-kicker">Fuente de saldos</span>
            <h4>Actualizar con auxiliar 1380</h4>
            <p>El saldo final de cada inmueble y NIT define el estado: <strong>positivo = con deuda</strong>, <strong>0 = al d&iacute;a</strong> y <strong>negativo = saldo a favor</strong>. Cada cargue reemplaza la foto vigente y conserva el historial.</p>
            <?php if (is_array($latestImport)): ?>
              <small>&Uacute;ltimo cargue: <?php echo esc_html((string) ($latestImport['source_filename'] ?? '')); ?> &middot; corte <?php echo esc_html($this->collection_date((string) ($latestImport['source_date'] ?? ''))); ?> &middot; <?php echo esc_html($this->format_collection_management_date($latestImport['uploaded_at'] ?? '')); ?> por <?php echo esc_html((string) ($latestImport['uploaded_by_name'] ?? '')); ?></small>
            <?php else: ?>
              <small>A&uacute;n no se ha cargado un auxiliar de cartera.</small>
            <?php endif; ?>
          </div>
          <div class="scm-portfolio-import-actions">
            <label class="scm-portfolio-file"><span>Archivo .xls o .xlsx</span><input type="file" name="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
            <button type="submit" class="scm-btn-primary btn btn-primary">Procesar cartera</button>
            <button type="button" class="scm-btn-secondary btn btn-outline scm-portfolio-reset-btn" data-scm-portfolio-reset>Limpiar pruebas 1380</button>
          </div>
          <p data-scm-portfolio-import-result class="scm-admin-notif-result" aria-live="polite"></p>
        </form>
      </section>

      <section class="scm-admin-notif-card scm-portfolio-control">
        <div class="scm-portfolio-section-head">
          <div><span class="scm-calendar-action-kicker">Cartera actual</span><h4>Contratos, saldos y etapa de cobranza</h4><p>Busca, filtra y ejecuta la siguiente acci&oacute;n sin salir del m&oacute;dulo.</p></div>
        </div>
        <form method="get" autocomplete="off" data-scm-collection-log-form class="scm-portfolio-filter-form">
          <input type="hidden" name="scm_tab" value="gestiones_cobro">
          <input type="hidden" name="scmgc_view" value="principal">
          <input type="hidden" name="scmgc_cartera_page" value="1">
          <div class="scm-portfolio-filter-grid">
            <div class="scm-field">
              <label for="scmgc_buscar">Buscar</label>
              <input id="scmgc_buscar" name="scmgc_buscar" type="search" class="input input-bordered input-sm scm-input" value="<?php echo esc_attr((string) ($portfolioApplied['search'] ?? '')); ?>" placeholder="Arrendatario, documento, contrato o inmueble">
            </div>
            <div class="scm-field">
              <label for="scmgc_estado">Estado de saldo</label>
              <select id="scmgc_estado" name="scmgc_estado" class="select select-bordered select-sm scm-select">
                <?php foreach (['' => 'Todos', 'deuda' => 'Con deuda', 'al_dia' => 'Al día', 'saldo_favor' => 'Saldo a favor', 'sin_dato' => 'Contrato sin dato', 'sin_contrato' => 'Cuenta sin contrato'] as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($portfolioApplied['status'] ?? ''), $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="scm-field">
              <label for="scmgc_etapa">Etapa</label>
              <select id="scmgc_etapa" name="scmgc_etapa" class="select select-bordered select-sm scm-select">
                <?php foreach (['' => 'Todas', 'normal' => 'Cobro normal', 'prejuridico' => 'Prejurídico', 'siniestro' => 'Siniestro'] as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($portfolioApplied['stage'] ?? ''), $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="scm-field">
              <label for="scmgc_movimiento">Movimiento</label>
              <select id="scmgc_movimiento" name="scmgc_movimiento" class="select select-bordered select-sm scm-select">
                <option value="">Todos</option>
                <option value="paid_latest" <?php selected((string) ($portfolioApplied['movement'] ?? ''), 'paid_latest'); ?>>Pagaron en el &uacute;ltimo cargue</option>
              </select>
            </div>
            <div class="scm-collection-log-actions">
              <button type="submit" class="scm-btn-primary btn btn-primary">Filtrar</button>
              <a class="scm-btn-secondary btn btn-outline" href="?scm_tab=gestiones_cobro" data-scm-collection-log-clear>Limpiar</a>
            </div>
          </div>
        </form>
      </section>

      <section class="scm-admin-notif-card scm-collection-log-table-card scm-portfolio-table-card">
        <?php if ($portfolioRows === []): ?>
          <div class="scm-admin-notif-empty">
            <strong>Sin registros de cartera</strong>
            <span>Carga el auxiliar 1380 o cambia los filtros actuales.</span>
          </div>
        <?php else: ?>
          <div class="scm-collection-log-table-wrap scm-portfolio-table-wrap">
            <table class="scm-collection-log-table scm-portfolio-table">
              <thead>
                <tr>
                  <th>Estado</th>
                  <th>Arrendatario</th>
                  <th>Contrato</th>
                  <th>Inmueble</th>
                  <th>Saldo actual</th>
                  <th>Variaci&oacute;n</th>
                  <th>Etapa</th>
                  <th>&Uacute;ltima acci&oacute;n</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($portfolioRows as $row):
                  $rowStatus = (string) ($row['status'] ?? 'sin_dato');
                  $rowStage = (string) ($row['collection_stage'] ?? 'normal');
                  $balance = $row['balance'] !== null ? (float) $row['balance'] : null;
                  $previous = $row['previous_balance'] !== null ? (float) $row['previous_balance'] : null;
                  $variation = $balance !== null && $previous !== null ? $balance - $previous : null;
                  $canManage = (int) ($row['contract_id'] ?? 0) > 0;
                  $canCollect = $canManage && $rowStatus === 'deuda';
                ?>
                  <tr>
                    <td><span class="scm-portfolio-status scm-portfolio-status--<?php echo esc_attr($rowStatus); ?>"><?php echo esc_html($this->collection_portfolio_status_label($rowStatus)); ?></span></td>
                    <td><strong><?php echo esc_html((string) (($row['tenant_name'] ?? '') ?: 'Sin nombre en plataforma')); ?></strong><small><?php echo esc_html((string) (($row['tenant_document'] ?? '') ?: '-')); ?></small></td>
                    <td><span class="scm-collection-log-pill"><?php echo esc_html((string) (($row['contract_number'] ?? '') ?: '-')); ?></span></td>
                    <td><strong><?php echo esc_html((string) (($row['property_code'] ?? '') ?: '-')); ?></strong><small><?php echo esc_html((string) (($row['property_address'] ?? '') ?: 'Sin dirección')); ?></small></td>
                    <td class="scm-portfolio-money"><?php echo $balance === null ? '&mdash;' : esc_html($this->collection_money($balance)); ?></td>
                    <td class="scm-portfolio-variation<?php echo $variation !== null && $variation < 0 ? ' is-down' : ($variation !== null && $variation > 0 ? ' is-up' : ''); ?>"><?php echo $variation === null ? '&mdash;' : esc_html(($variation > 0 ? '+' : '') . $this->collection_money($variation)); ?></td>
                    <td><span class="scm-portfolio-stage scm-portfolio-stage--<?php echo esc_attr($rowStage); ?>"><?php echo esc_html($this->collection_portfolio_stage_label($rowStage)); ?></span></td>
                    <td><?php echo esc_html($this->collection_portfolio_action_label((string) ($row['last_action_type'] ?? ''))); ?><small><?php echo esc_html($this->format_collection_management_date($row['last_action_at'] ?? '')); ?></small></td>
                    <td>
                      <?php if ($canManage): ?>
                        <div class="scm-portfolio-actions">
                          <button type="button" class="scm-case-work-btn scm-portfolio-balance-btn" data-scm-portfolio-balance data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" data-current-balance="<?php echo esc_attr($balance === null ? '' : (string) $balance); ?>" data-tenant-name="<?php echo esc_attr((string) ($row['tenant_name'] ?? '')); ?>" data-contract-number="<?php echo esc_attr((string) ($row['contract_number'] ?? '')); ?>" title="Anexar o corregir saldo manual para pruebas o ajustes de cartera">Anexar saldo</button>
                          <button type="button" class="scm-case-work-btn scm-portfolio-due-btn" data-scm-portfolio-due-date data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" data-tenant-name="<?php echo esc_attr((string) ($row['tenant_name'] ?? '')); ?>" data-contract-number="<?php echo esc_attr((string) ($row['contract_number'] ?? '')); ?>" title="Registrar gestión Canon y enviar notificación informativa de fecha de pago">Notificar pago</button>
                          <button type="button" class="scm-case-work-btn scm-portfolio-management-btn" data-scm-portfolio-management data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" data-tenant-id="<?php echo esc_attr((string) ((int) ($row['tenant_id'] ?? 0))); ?>" data-contract-id="<?php echo esc_attr((string) ((int) ($row['contract_id'] ?? 0))); ?>" data-tenant-name="<?php echo esc_attr((string) ($row['tenant_name'] ?? '')); ?>" data-contract-number="<?php echo esc_attr((string) ($row['contract_number'] ?? '')); ?>" title="Registrar contacto, acuerdo, compromiso o seguimiento sin cambiar la etapa">Hacer gesti&oacute;n</button>
                          <?php if ($canCollect): ?>
                            <button type="button" class="scm-case-work-btn" data-scm-portfolio-letter="prejuridico" data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" title="Revisar la carta antes de descargarla o enviarla">Preparar prejur&iacute;dico</button>
                            <button type="button" class="scm-case-work-btn" data-scm-portfolio-letter="siniestro" data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" title="Revisar el aviso antes de descargarlo o enviarlo">Preparar siniestro</button>
                            <button type="button" class="scm-case-work-btn scm-portfolio-stage-btn" data-scm-portfolio-stage="<?php echo $rowStage === 'siniestro' ? 'normal' : 'siniestro'; ?>" data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>" title="<?php echo $rowStage === 'siniestro' ? 'Devuelve el contrato a cobro normal' : 'Cambia la etapa sin generar ni enviar una carta'; ?>"><?php echo $rowStage === 'siniestro' ? 'Quitar siniestro' : 'Marcar siniestro'; ?></button>
                          <?php elseif ($rowStage !== 'normal'): ?>
                            <button type="button" class="scm-case-work-btn scm-portfolio-stage-btn" data-scm-portfolio-stage="normal" data-portfolio-id="<?php echo esc_attr((string) ((int) $row['id'])); ?>">Normalizar etapa</button>
                          <?php else: ?><span class="scm-portfolio-no-action">Sin saldo para escalar</span><?php endif; ?>
                        </div>
                      <?php else: ?><span class="scm-portfolio-no-action">Cruce pendiente</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php echo $this->render_collection_portfolio_pagination($portfolioPagination, $portfolioApplied); ?>
        <?php endif; ?>
      </section>

      </section>

      <section id="scm-portfolio-panel-historial" class="scm-portfolio-view" role="tabpanel" aria-labelledby="scm-portfolio-tab-historial" data-scm-portfolio-panel="historial"<?php echo $activeView === 'historial' ? '' : ' hidden'; ?>>
      <section class="scm-admin-notif-card scm-collection-history-card">
        <div class="scm-portfolio-section-head"><div><span class="scm-calendar-action-kicker">Trazabilidad</span><h4>Historial de gestiones de cobro</h4><p>Todos los registros operativos y sus notificaciones, ya centralizados en este m&oacute;dulo.</p></div><span class="scm-status-count"><strong><?php echo esc_html((string) ((int) ($stats['total'] ?? 0))); ?></strong> gestiones</span></div>
        <form method="get" autocomplete="off" data-scm-collection-log-form class="scm-collection-log-filters">
          <input type="hidden" name="scm_tab" value="gestiones_cobro"><input type="hidden" name="scmgc_view" value="historial"><input type="hidden" name="scmgc_page" value="1">
          <div class="scm-collection-log-filter-grid">
            <div class="scm-field"><label for="scmgc_fecha_desde">Fecha desde</label><input id="scmgc_fecha_desde" name="scmgc_fecha_desde" type="date" class="input input-bordered input-sm scm-input" value="<?php echo esc_attr($dateFrom); ?>"></div>
            <div class="scm-field"><label for="scmgc_fecha_hasta">Fecha hasta</label><input id="scmgc_fecha_hasta" name="scmgc_fecha_hasta" type="date" class="input input-bordered input-sm scm-input" value="<?php echo esc_attr($dateTo); ?>"></div>
            <div class="scm-field"><label for="scmgc_tipo">Concepto</label><select id="scmgc_tipo" name="scmgc_tipo" class="select select-bordered select-sm scm-select"><option value="">Todos</option><?php foreach ($types as $typeOption): ?><option value="<?php echo esc_attr($typeOption); ?>" <?php selected($currentType, $typeOption); ?>><?php echo esc_html($this->collection_management_type_label($typeOption)); ?></option><?php endforeach; ?></select></div>
            <div class="scm-collection-log-actions"><button type="submit" class="scm-btn-primary btn btn-primary">Filtrar historial</button></div>
          </div>
        </form>
        <?php if ($rows === []): ?><div class="scm-admin-notif-empty"><strong>Sin gestiones registradas</strong><span>No hay registros con los filtros actuales.</span></div><?php else: ?>
          <div class="scm-collection-log-table-wrap"><table class="scm-collection-log-table"><thead><tr><th>Fecha</th><th>Contrato</th><th>Inmueble</th><th>Arrendatario</th><th>Concepto</th><th>Realizado por</th><th>Observaci&oacute;n</th><th>Mensajes</th></tr></thead><tbody>
          <?php foreach ($rows as $row): ?><tr><td><?php echo esc_html($this->format_collection_management_date($row['fecha_raw'] ?? '')); ?></td><td><span class="scm-collection-log-pill"><?php echo esc_html((string) (($row['contrato'] ?? '') ?: '-')); ?></span></td><td><?php echo esc_html((string) (($row['inmueble'] ?? '') ?: '-')); ?></td><td><?php echo esc_html((string) (($row['arrendatario'] ?? '') ?: '-')); ?></td><td><span class="scm-collection-log-type"><?php echo esc_html($this->collection_management_type_label((string) ($row['tipo_gestion'] ?? ''))); ?></span></td><td><?php echo esc_html((string) (($row['realizado_por'] ?? '') ?: '-')); ?></td><td class="scm-collection-log-note"><?php echo esc_html((string) (($row['observacion'] ?? '') ?: '-')); ?></td><td><button type="button" class="scm-case-work-btn scm-collection-log-notify-btn" data-scm-collection-queue="<?php echo esc_attr((string) ((int) ($row['id'] ?? 0))); ?>">Ver</button></td></tr><?php endforeach; ?>
          </tbody></table></div><?php echo $this->render_collection_management_pagination($pagination, $applied); ?>
        <?php endif; ?>
      </section>
      </section>

      <?php echo $this->render_collection_management_modal(); ?>
      <?php echo $this->render_collection_letter_preview_modal(); ?>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function render_collection_management_shell(): string
  {
    return '<div class="scm-collection-log" data-scm-collection-log data-scm-collection-log-loaded="0">'
      . '<div class="scm-admin-notif-empty" role="status" aria-live="polite">'
      . '<strong>Gestiones de cobro listas para consultar</strong>'
      . '<span>Abre esta sección para cargar el reporte.</span>'
      . '</div></div>';
  }

  private function render_collection_management_modal(): string
  {
    ob_start();
?>
    <div class="scm-admin-notif-modal scm-portfolio-management-modal" data-scm-portfolio-management-modal hidden role="dialog" aria-modal="true" aria-labelledby="scm-portfolio-management-title">
      <div class="scm-admin-notif-modal-backdrop" data-scm-portfolio-management-close aria-hidden="true"></div>
      <section class="scm-admin-notif-card scm-admin-notif-composer scm-admin-notif-modal-panel">
        <div class="scm-admin-notif-modal-head">
          <div class="scm-admin-notif-modal-titleblock"><span class="scm-calendar-action-kicker">Cobranza</span><h4 id="scm-portfolio-management-title">Registrar gesti&oacute;n de cobro</h4><p data-scm-portfolio-management-context>Selecciona un contrato de la cartera.</p></div>
          <button type="button" class="scm-modal-close" data-scm-portfolio-management-close aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <form data-scm-portfolio-management-form autocomplete="off">
          <input type="hidden" name="type" value="arrendatarios_activos">
          <input type="hidden" name="portfolio_id" value="">
          <input type="hidden" name="ids[]" value="">
          <input type="hidden" name="contract_ids[]" value="">
          <div class="scm-portfolio-management-grid">
            <div class="scm-field"><label for="scm-portfolio-management-type">Concepto</label><select id="scm-portfolio-management-type" name="tipo_gestion_cobro" class="select select-bordered select-sm scm-select"><option value="Canon">Canon</option><option value="Administracion">Administraci&oacute;n</option><option value="Servicios publicos">Servicios p&uacute;blicos</option></select></div>
            <div class="scm-field"><label for="scm-portfolio-management-followup">Pr&oacute;xima gesti&oacute;n</label><select id="scm-portfolio-management-followup" name="volver_llamar" class="select select-bordered select-sm scm-select" data-scm-portfolio-followup><option value="No">No programar</option><option value="Si">Programar seguimiento</option></select></div>
            <div class="scm-field" data-scm-portfolio-followup-field hidden><label for="scm-portfolio-management-date">Fecha</label><input id="scm-portfolio-management-date" name="siguiente_fecha" type="date" class="input input-bordered input-sm scm-input"></div>
            <div class="scm-field" data-scm-portfolio-followup-field hidden><label for="scm-portfolio-management-time">Hora</label><input id="scm-portfolio-management-time" name="siguiente_hora" type="time" class="input input-bordered input-sm scm-input"></div>
          </div>
          <div class="scm-field scm-portfolio-management-observation"><label for="scm-portfolio-management-observation">Observaci&oacute;n <span aria-hidden="true">*</span></label><textarea id="scm-portfolio-management-observation" name="observacion" class="textarea textarea-bordered scm-textarea" rows="5" maxlength="2000" required aria-describedby="scm-portfolio-management-observation-help" placeholder="Ejemplo: Se llam&oacute; al arrendatario. Se comprometi&oacute; a pagar el 30/08 y enviar el soporte por WhatsApp."></textarea><small id="scm-portfolio-management-observation-help">Describe el contacto, el compromiso y el siguiente paso. Esta nota quedar&aacute; en el historial.</small></div>
          <div class="scm-field" data-scm-portfolio-followup-field hidden><label for="scm-portfolio-management-schedule-note">Detalle del horario</label><input id="scm-portfolio-management-schedule-note" name="otro_horario_cobro" type="text" class="input input-bordered input-sm scm-input" placeholder="Ej: llamar después de las 3:00 p. m."></div>
          <div class="scm-portfolio-management-notify">
            <div><strong>Notificar al guardar</strong><span>Opcional. Usa la plantilla de gesti&oacute;n de cobro para el arrendatario y los codeudores seleccionados.</span></div>
            <div class="scm-admin-notif-collection-channels" role="group" aria-label="Canales de notificaci&oacute;n"><label class="scm-admin-notif-mini-channel"><input type="checkbox" name="notify_channels[]" value="whatsapp" checked><span>WhatsApp</span></label><label class="scm-admin-notif-mini-channel"><input type="checkbox" name="notify_channels[]" value="email"><span>Email</span></label><label class="scm-admin-notif-mini-channel"><input type="checkbox" name="notify_channels[]" value="sms"><span>SMS</span></label></div>
          </div>
          <div class="scm-admin-notif-codeudores" data-scm-portfolio-codeudores hidden><div class="scm-admin-notif-codeudores-head"><div><strong>Codeudores</strong><span>Selecciona qui&eacute;nes tambi&eacute;n recibir&aacute;n la notificaci&oacute;n.</span></div></div><div class="scm-admin-notif-codeudores-list" data-scm-portfolio-codeudores-list></div></div>
          <div class="scm-admin-notif-submit-row"><span class="scm-spinner" data-scm-portfolio-management-spinner><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span><button type="submit" class="scm-btn-primary btn btn-primary">Guardar gesti&oacute;n</button></div>
          <p class="scm-admin-notif-result" data-scm-portfolio-management-result aria-live="polite"></p>
        </form>
      </section>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function render_collection_letter_preview_modal(): string
  {
    ob_start();
?>
    <div class="scm-admin-notif-modal scm-portfolio-letter-preview-modal" data-scm-portfolio-letter-preview-modal hidden role="dialog" aria-modal="true" aria-labelledby="scm-portfolio-letter-preview-title">
      <div class="scm-admin-notif-modal-backdrop" data-scm-portfolio-letter-preview-close aria-hidden="true"></div>
      <section class="scm-admin-notif-card scm-admin-notif-modal-panel scm-portfolio-letter-preview-panel">
        <div class="scm-admin-notif-modal-head">
          <div class="scm-admin-notif-modal-titleblock"><span class="scm-calendar-action-kicker">Vista previa</span><h4 id="scm-portfolio-letter-preview-title" data-scm-portfolio-letter-preview-title>Carta de cartera</h4><p data-scm-portfolio-letter-preview-context>Revisa el documento antes de registrarlo o enviarlo.</p></div>
          <button type="button" class="scm-modal-close" data-scm-portfolio-letter-preview-close aria-label="Cerrar vista previa"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="scm-portfolio-letter-preview-help"><strong>La vista previa no cambia la etapa ni env&iacute;a mensajes.</strong><span>Al descargar se registra la carta y su etapa. Al enviar tambi&eacute;n se encola el correo para los destinatarios correspondientes.</span></div>
        <div class="scm-portfolio-letter-preview-frame"><iframe src="about:blank" title="Vista previa de la carta en PDF" data-scm-portfolio-letter-preview-frame></iframe></div>
        <div class="scm-portfolio-letter-preview-actions">
          <button type="button" class="scm-btn-secondary btn btn-outline" data-scm-portfolio-letter-preview-close>Cerrar</button>
          <button type="button" class="scm-case-work-btn" data-scm-portfolio-letter-preview-download>Descargar y registrar</button>
          <button type="button" class="scm-btn-primary btn btn-primary" data-scm-portfolio-letter-preview-send>Enviar y registrar</button>
        </div>
      </section>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function collection_money(mixed $value): string
  {
    return '$' . number_format((float) $value, 0, ',', '.');
  }

  private function collection_date(string $value): string
  {
    $timestamp = strtotime($value);
    return $timestamp === false ? '-' : date('d/m/Y', $timestamp);
  }

  private function collection_portfolio_status_label(string $status): string
  {
    return [
      'deuda' => 'Con deuda',
      'al_dia' => 'Al día',
      'saldo_favor' => 'Saldo a favor',
      'sin_dato' => 'Sin dato',
      'sin_contrato' => 'Sin contrato',
    ][$status] ?? 'Por revisar';
  }

  private function collection_portfolio_stage_label(string $stage): string
  {
    return [
      'normal' => 'Cobro normal',
      'prejuridico' => 'Prejurídico',
      'siniestro' => 'Siniestro',
    ][$stage] ?? 'Cobro normal';
  }

  private function collection_portfolio_action_label(string $action): string
  {
    if ($action === '') {
      return 'Sin acciones';
    }
    $labels = [
      'gestion_cobro' => 'Gestión registrada',
      'notificacion_fecha_pago' => 'Notificación de fecha de pago',
      'recordatorio_fecha_cobro' => 'Notificación de fecha de pago',
      'saldo_manual' => 'Saldo anexado',
      'pago_detectado' => 'Pago detectado',
      'estado_normal' => 'Etapa normalizada',
      'estado_prejuridico' => 'Marcado prejurídico',
      'estado_siniestro' => 'Marcado siniestro',
      'carta_prejuridico_generada' => 'Carta prejurídica generada',
      'carta_prejuridico_enviada' => 'Carta prejurídica enviada',
      'carta_siniestro_generada' => 'Carta de siniestro generada',
      'carta_siniestro_enviada' => 'Carta de siniestro enviada',
    ];
    return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
  }

  private function render_collection_portfolio_pagination(array $pagination, array $filters): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0 || $totalPages <= 1) {
      return '';
    }
    $base = [
      'scm_tab' => 'gestiones_cobro',
      'scmgc_view' => 'principal',
      'scmgc_buscar' => (string) ($filters['search'] ?? ''),
      'scmgc_estado' => (string) ($filters['status'] ?? ''),
      'scmgc_etapa' => (string) ($filters['stage'] ?? ''),
      'scmgc_movimiento' => (string) ($filters['movement'] ?? ''),
    ];
    $link = static fn(int $target): string => '?' . http_build_query($base + ['scmgc_cartera_page' => $target]);
    $html = '<div class="scm-pagination-card card scm-collection-log-pagination"><div class="scm-pagination-summary">Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div><div class="scm-pagination-controls">';
    foreach ([[1, '&laquo;'], [max(1, $page - 1), '&lsaquo;']] as [$target, $label]) {
      $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page <= 1 ? ' disabled' : '') . '" href="' . esc_url($link((int) $target)) . '">' . $label . '</a>';
    }
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
      $html .= '<a class="scm-page-btn btn btn-sm ' . ($i === $page ? 'btn-primary is-active' : 'btn-outline') . '" href="' . esc_url($link($i)) . '">' . esc_html((string) $i) . '</a>';
    }
    foreach ([[min($totalPages, $page + 1), '&rsaquo;'], [$totalPages, '&raquo;']] as [$target, $label]) {
      $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page >= $totalPages ? ' disabled' : '') . '" href="' . esc_url($link((int) $target)) . '">' . $label . '</a>';
    }
    return $html . '</div></div>';
  }

  private function collection_management_type_label(string $type): string
  {
    $normalized = strtolower(strtr(trim($type), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    if (str_contains($normalized, 'servicio')) {
      return 'Servicios públicos';
    }
    if (str_contains($normalized, 'admin')) {
      return 'Administración';
    }
    if (str_contains($normalized, 'canon')) {
      return 'Canon';
    }
    return trim($type) !== '' ? trim($type) : 'Sin tipo';
  }

  private function format_collection_management_date($value): string
  {
    $raw = trim((string) $value);
    if ($raw === '') {
      return '-';
    }
    if (ctype_digit($raw)) {
      $ts = (int) $raw;
    } else {
      $ts = strtotime($raw);
    }
    return $ts > 0 ? date('d/m/Y H:i', $ts) : $raw;
  }

  private function render_collection_management_pagination(array $pagination, array $filters): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0 || $totalPages <= 1) {
      return '';
    }
    $base = [
      'scm_tab' => 'gestiones_cobro',
      'scmgc_view' => 'historial',
      'scmgc_fecha_desde' => (string) ($filters['date_from'] ?? ''),
      'scmgc_fecha_hasta' => (string) ($filters['date_to'] ?? ''),
      'scmgc_tipo' => (string) ($filters['type'] ?? ''),
    ];
    $link = static function (int $targetPage) use ($base): string {
      return '?' . http_build_query($base + ['scmgc_page' => $targetPage]);
    };
    $html = '<div class="scm-pagination-card card scm-collection-log-pagination"><div class="scm-pagination-summary">P&aacute;gina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div><div class="scm-pagination-controls">';
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);
    $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page <= 1 ? ' disabled' : '') . '" href="' . esc_url($link(1)) . '">&laquo;</a>';
    $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page <= 1 ? ' disabled' : '') . '" href="' . esc_url($link($prev)) . '">&lsaquo;</a>';
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
      $html .= '<a class="scm-page-btn btn btn-sm ' . ($i === $page ? 'btn-primary is-active' : 'btn-outline') . '" href="' . esc_url($link($i)) . '">' . esc_html((string) $i) . '</a>';
    }
    $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page >= $totalPages ? ' disabled' : '') . '" href="' . esc_url($link($next)) . '">&rsaquo;</a>';
    $html .= '<a class="scm-page-btn btn btn-sm btn-outline' . ($page >= $totalPages ? ' disabled' : '') . '" href="' . esc_url($link($totalPages)) . '">&raquo;</a>';
    return $html . '</div></div>';
  }

  /** @return array<string,array{label:string,items:array<int,array{title:string,text:string}>}> */
  private function admin_notification_message_guides(): array
  {
    return [
      'propietarios' => [
        'label' => 'Propietarios',
        'items' => [
          [
            'title' => 'Renta abonada',
            'text' => "Sr(a). Propietario(a), cordial saludo.\n\nLe informamos que el pago de la renta correspondiente a su inmueble en administración fue abonado a la cuenta registrada.\n\nAgradecemos verificar la transacción y comunicarnos cualquier novedad.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Confirmación de gestión',
            'text' => "Sr(a). Propietario(a), cordial saludo.\n\nLe confirmamos que su solicitud fue recibida y se encuentra en gestión por parte de nuestro equipo. Le estaremos informando cualquier avance o requerimiento adicional.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Actualización de datos',
            'text' => "Sr(a). Propietario(a), cordial saludo.\n\nCon el fin de mantener actualizada la información de contacto y pagos, agradecemos confirmar si sus datos registrados continúan vigentes.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
        ],
      ],
      'arrendatarios' => [
        'label' => 'Arrendatarios',
        'items' => [
          [
            'title' => 'Recordatorio amable',
            'text' => "Sr(a). Arrendatario(a), cordial saludo.\n\nLe recordamos revisar sus obligaciones pendientes relacionadas con el inmueble arrendado. Si ya realizó el pago o gestión correspondiente, puede hacer caso omiso a este mensaje.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Soporte recibido',
            'text' => "Sr(a). Arrendatario(a), cordial saludo.\n\nConfirmamos la recepción del soporte enviado. Nuestro equipo realizará la verificación correspondiente y le informará si se requiere información adicional.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Solicitud de información',
            'text' => "Sr(a). Arrendatario(a), cordial saludo.\n\nPara continuar con la gestión solicitada, agradecemos enviarnos la información o soporte pendiente por este mismo medio.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
        ],
      ],
      'copropiedades' => [
        'label' => 'Copropiedades',
        'items' => [
          [
            'title' => 'Envío de soportes de pago',
            'text' => "Buenos días, cordial saludo.\n\nAdjuntamos los soportes de pago correspondientes al mes de agosto de 2026 del Apto \"----------\", para su respectiva verificación.\n\nAgradecemos su colaboración con el envío del recibo de caja correspondiente a dichos pagos.\n\nQuedamos atentos.\n\nCordialmente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Solicitud de recibo de caja',
            'text' => "Buenos días, cordial saludo.\n\nAgradecemos nos compartan el recibo de caja correspondiente al pago realizado del inmueble \"----------\", con el fin de completar el soporte en nuestro sistema.\n\nQuedamos atentos a su amable respuesta.\n\nCordialmente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Confirmación de pago administración',
            'text' => "Buenos días, cordial saludo.\n\nLes informamos que se realizó el pago de administración correspondiente al inmueble \"----------\". Agradecemos validar el ingreso y remitir el soporte o recibo de caja correspondiente.\n\nCordialmente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Consulta de estado de cuenta',
            'text' => "Buenos días, cordial saludo.\n\nSolicitamos amablemente el estado de cuenta actualizado del inmueble \"----------\" para realizar la revisión y gestión correspondiente.\n\nQuedamos atentos.\n\nCordialmente,\n{{firma_funcionario_linea}}",
          ],
        ],
      ],
      'proveedores' => [
        'label' => 'Proveedores',
        'items' => [
          [
            'title' => 'Solicitud de cotización',
            'text' => "Cordial saludo.\n\nAgradecemos nos compartan cotización para la solicitud relacionada, incluyendo descripción del servicio, valor, tiempo estimado de ejecución y condiciones de garantía.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Seguimiento de servicio',
            'text' => "Cordial saludo.\n\nSolicitamos amablemente confirmar el estado del servicio asignado e informar la fecha estimada de atención o finalización.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
        ],
      ],
      'funcionarios' => [
        'label' => 'Funcionarios',
        'items' => [
          [
            'title' => 'Asignación interna',
            'text' => "Hola {{nombre}}.\n\nSe te ha asignado una gestión para revisión. Por favor valida la información y actualiza el estado correspondiente cuando avances.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
          [
            'title' => 'Recordatorio interno',
            'text' => "Hola {{nombre}}.\n\nTe recordamos revisar las gestiones pendientes asignadas para mantener actualizada la trazabilidad del proceso.\n\nAtentamente,\n{{firma_funcionario_linea}}",
          ],
        ],
      ],
    ];
  }

  private function render_cotizaciones_mantenimiento_panel(array $result, array $params): string
  {
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $tabStats = is_array($result['tab_stats'] ?? null) ? $result['tab_stats'] : $stats;
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    return '<span id="scm-cotizaciones_mantenimiento-count" style="display:none;">' . esc_html((string) ($stats['total'] ?? 0)) . '</span>'
      . '<div class="scm-status-topic-head"><div><h3>Cotizaciones de Mantenimiento</h3><p>Control de envio, respuesta, ordenes y acciones de cotizacion.</p></div><span class="scm-status-count"><strong id="scm-cotizaciones_mantenimiento-kpi-total">' . esc_html((string) ($stats['total'] ?? 0)) . '</strong> cotizaciones</span></div>'
      . $this->render_cotizaciones_mantenimiento_state_tabs($params, $tabStats)
      . $this->render_cotizaciones_mantenimiento_kpis($stats)
      . $this->render_cotizaciones_mantenimiento_filter_form($params)
      . '<div class="scm-cotizaciones-list" id="scm-cards-cotizaciones_mantenimiento">' . $this->render_cotizaciones_mantenimiento_cards($rows) . '</div>'
      . '<div class="scm-pagination" id="scm-pagination-cotizaciones_mantenimiento">' . $this->render_cotizaciones_mantenimiento_pagination($pagination) . '</div>';
  }

  private function render_cotizaciones_mantenimiento_kpis(array $stats): string
  {
    $items = [
      ['key' => 'total', 'label' => 'Total', 'id' => 'total-card', 'value' => (string) ($stats['total'] ?? 0)],
      ['key' => 'enviadas', 'label' => 'Enviadas', 'id' => 'enviadas', 'value' => (string) ($stats['enviadas'] ?? 0)],
      ['key' => 'no-enviadas', 'label' => 'Sin enviar', 'id' => 'no-enviadas', 'value' => (string) ($stats['no_enviadas'] ?? 0)],
      ['key' => 'aprobadas', 'label' => 'Aprobadas', 'id' => 'aprobadas', 'value' => (string) ($stats['aprobadas'] ?? 0)],
      ['key' => 'desaprobadas', 'label' => 'Desaprobadas', 'id' => 'desaprobadas', 'value' => (string) ($stats['desaprobadas'] ?? 0)],
      ['key' => 'esperando-respuesta', 'label' => 'Esperando respuesta', 'id' => 'esperando-respuesta', 'value' => (string) ($stats['esperando_respuesta'] ?? 0)],
      ['key' => 'finalizadas', 'label' => 'Finalizadas', 'id' => 'finalizadas', 'value' => (string) ($stats['finalizadas'] ?? 0)],
      ['key' => 'ordenes', 'label' => '&Oacute;rdenes', 'id' => 'ordenes', 'value' => (string) ($stats['ordenes_total'] ?? 0)],
      ['key' => 'valor-total', 'label' => 'Total cotizaciones', 'id' => 'valor-total', 'value' => $this->format_cop_currency($stats['valor_total'] ?? 0), 'class' => ' scm-kpi-money'],
    ];

    $html = '<div class="scm-kpis scm-kpis-daisy scm-cotizaciones-kpis">';
    foreach ($items as $item) {
      $html .= '<div class="scm-kpi' . esc_attr((string) ($item['class'] ?? '')) . '" data-scm-cotizacion-kpi="' . esc_attr((string) $item['key']) . '"><div class="scm-kpi-label">' . (string) $item['label'] . '</div><div class="scm-kpi-value" id="scm-cotizaciones_mantenimiento-kpi-' . esc_attr((string) $item['id']) . '">' . esc_html((string) $item['value']) . '</div></div>';
    }
    return $html . '</div>';
  }

  private function render_cotizaciones_mantenimiento_filter_form(array $p): string
  {
    $funcionarios = [];
    ob_start();
?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form id="scm-form-cotizaciones_mantenimiento" autocomplete="off">
        <input type="hidden" id="scmqt_page" name="scmqt_page" value="<?php echo esc_attr((string) ($p['fPage'] ?? '1')); ?>">
        <input type="hidden" id="scmqt_estado" name="scmqt_estado" value="<?php echo esc_attr((string) ($p['fEstado'] ?? '')); ?>">
        <div class="scm-grid">
          <div class="scm-field"><label for="scmqt_cotizacion">Cotizaci&oacute;n</label><input id="scmqt_cotizacion" name="scmqt_cotizacion" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fCotizacion'] ?? '')); ?>" placeholder="Ej: 528"></div>
          <div class="scm-field"><label for="scmqt_ticket"># caso</label><input id="scmqt_ticket" name="scmqt_ticket" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fTicket'] ?? '')); ?>" placeholder="Ej: 10055"></div>
          <div class="scm-field scm-date-range-field"><label id="scmqt_fecha_rango_label">Fecha</label><div class="scm-date-range-control" role="group" aria-labelledby="scmqt_fecha_rango_label"><div class="scm-date-range-part"><span>Desde</span><input id="scmqt_fecha_desde" name="scmqt_fecha_desde" type="date" value="<?php echo esc_attr((string) ($p['fFechaDesde'] ?? '')); ?>" aria-label="Fecha desde"></div><span class="scm-date-range-separator" aria-hidden="true">&rarr;</span><div class="scm-date-range-part"><span>Hasta</span><input id="scmqt_fecha_hasta" name="scmqt_fecha_hasta" type="date" value="<?php echo esc_attr((string) ($p['fFechaHasta'] ?? '')); ?>" aria-label="Fecha hasta"></div></div></div>
          <div class="scm-field"><label for="scmqt_destinatario">Destinatario</label><input id="scmqt_destinatario" name="scmqt_destinatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fDestinatario'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_funcionario">Funcionario</label><select id="scmqt_funcionario" name="scmqt_funcionario" class="select select-bordered select-sm scm-select scm-select2" data-placeholder="Buscar funcionario..."><option value="">Todos</option><?php foreach ($funcionarios as $func): $fId = trim((string) ($func['id'] ?? '')); ?><option value="<?php echo esc_attr($fId); ?>" <?php selected((string) ($p['fFuncionario'] ?? ''), $fId); ?>><?php echo esc_html((string) ($func['label'] ?? $fId)); ?></option><?php endforeach; ?></select></div>
          <div class="scm-field"><label for="scmqt_inmueble">Inmueble SIMI</label><input id="scmqt_inmueble" name="scmqt_inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fInmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_contrato">Contrato</label><input id="scmqt_contrato" name="scmqt_contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fContrato'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_enviada">Fue enviada</label><select id="scmqt_enviada" name="scmqt_enviada" class="select select-bordered select-sm scm-select"><option value="">Todas</option><option value="si" <?php selected((string) ($p['fEnviada'] ?? ''), 'si'); ?>>S&iacute;</option><option value="no" <?php selected((string) ($p['fEnviada'] ?? ''), 'no'); ?>>No</option></select></div>
          <div class="scm-field"><label for="scmqt_tipo_mantenimiento">Tipo mantenimiento</label><select id="scmqt_tipo_mantenimiento" name="scmqt_tipo_mantenimiento" class="select select-bordered select-sm scm-select"><option value="">Todos</option></select></div>
          <div class="scm-field"><label for="scmqt_categoria">Categoria</label><select id="scmqt_categoria" name="scmqt_categoria" class="select select-bordered select-sm scm-select"><option value="">Todas</option></select></div>
        </div>
        <div class="scm-actions"><button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button><button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear-cotizaciones_mantenimiento">Limpiar</button><span class="scm-spinner" id="scm-spinner-cotizaciones_mantenimiento"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span></div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function render_cotizaciones_mantenimiento_state_tabs(array $p, array $stats = []): string
  {
    $activeState = strtolower(trim((string) ($p['fEstado'] ?? '')));
    $activeSent = strtolower(trim((string) ($p['fEnviada'] ?? '')));
    $states = [
      ['state' => '', 'sent' => '', 'label' => 'Todos', 'key' => 'total'],
      ['state' => '', 'sent' => 'no', 'label' => 'Sin enviar', 'key' => 'no_enviadas'],
      ['state' => '', 'sent' => 'si', 'label' => 'Enviadas', 'key' => 'enviadas'],
      ['state' => 'Aprobada', 'sent' => '', 'label' => 'Aprobadas', 'key' => 'aprobadas'],
      ['state' => 'Desaprobada', 'sent' => '', 'label' => 'Desaprobadas', 'key' => 'desaprobadas'],
      ['state' => 'Esperando respuesta', 'sent' => '', 'label' => 'Esperando respuesta', 'key' => 'esperando_respuesta'],
      ['state' => 'Finalizado', 'sent' => '', 'label' => 'Finalizadas', 'key' => 'finalizadas'],
    ];

    $html = '<div class="scm-cotizacion-state-tabs" role="tablist" aria-label="Estados de cotizaciones">';
    foreach ($states as $tab) {
      $state = (string) ($tab['state'] ?? '');
      $sent = (string) ($tab['sent'] ?? '');
      $label = (string) ($tab['label'] ?? '');
      $key = (string) ($tab['key'] ?? '');
      $normalizedState = strtolower(trim($state));
      $normalizedSent = strtolower(trim($sent));
      $isActive = $normalizedSent !== ''
        ? ($activeSent === $normalizedSent && $activeState === '')
        : ($activeSent === '' && ($activeState === $normalizedState || ($activeState === '' && $normalizedState === '')));
      $count = $key !== '' ? (string) ($stats[$key] ?? 0) : '0';
      $countId = $key !== '' ? ' id="scm-cotizaciones_mantenimiento-tab-' . esc_attr(str_replace('_', '-', $key)) . '"' : '';
      $html .= '<button type="button" class="scm-cotizacion-state-tab' . ($isActive ? ' active' : '') . '" data-cotizacion-state="' . esc_attr($state) . '" data-cotizacion-sent="' . esc_attr($sent) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '"><span>' . esc_html($label) . '</span><strong' . $countId . '>' . esc_html($count) . '</strong></button>';
    }
    return $html . '</div>';
  }

  /** @return array<int,array{id:string,label:string}> */
  private function cotizaciones_funcionario_options(): array
  {
    return \SCM\Support\FuncionarioOptions::panelFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db));
  }

  /** @return array<int,string> */
  private function cotizaciones_distinct_options(string $column): array
  {
    $allowed = ['estado', 'tipo_mantenimiento', 'categoria_cotizacion'];
    if (!in_array($column, $allowed, true)) {
      return [];
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table) || !$this->column_exists($table, $column)) {
      return [];
    }
    $rows = $this->db->getCol("SELECT DISTINCT TRIM(COALESCE(`{$column}`, '')) AS val FROM `{$table}` WHERE TRIM(COALESCE(`{$column}`, '')) <> '' ORDER BY val ASC LIMIT 120");
    $out = [];
    foreach ($rows as $row) {
      $value = trim((string) $row);
      if ($value !== '') {
        $out[$value] = $value;
      }
    }
    return array_values($out);
  }

  /** @param array<int,array<string,mixed>> $rows */
  private function render_cotizaciones_mantenimiento_cards(array $rows): string
  {
    if (empty($rows)) {
      return '<div class="scm-empty scm-empty-cards">No hay cotizaciones con los filtros actuales.</div>';
    }
    $linkedTicketCards = $this->cotizacion_linked_ticket_cards_by_rows($rows);
    $html = '';
    foreach ($rows as $row) {
      $html .= $this->render_cotizacion_mantenimiento_card($row, $linkedTicketCards);
    }
    return $html;
  }

  /** @param array<string,mixed> $row @param array<string,string> $linkedTicketCards */
  private function render_cotizacion_mantenimiento_card(array $row, array $linkedTicketCards = []): string
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $ticket = trim((string) ($row['id_ticket'] ?? ''));
    $inmueble = trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''));
    $idInmueble = trim((string) ($row['id_inmueble'] ?? $inmueble));
    $contrato = trim((string) ($row['contrato'] ?? $row['id_contrato'] ?? ''));
    $idContrato = trim((string) ($row['id_contrato'] ?? $contrato));
    $sucursal = trim((string) ($row['sucursal'] ?? '1'));
    $estado = trim((string) ($row['estado'] ?? ''));
    $cotizacionAprobada = strtolower($estado) === 'aprobada';
    $seEnvio = strtolower(trim((string) ($row['se_envio'] ?? '')));
    $enviada = in_array($seEnvio, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
    $fechaTs = (int) ($row['fecha'] ?? 0);
    if ($fechaTs <= 0) {
      $fechaTs = strtotime((string) ($row['cct_created'] ?? '')) ?: 0;
    }
    $fecha = $fechaTs > 0 ? date('d/m/Y', $fechaTs) : '-';
    $destinatario = trim((string) ($row['destinatario'] ?? '-'));
    $contacto = trim((string) ($row['celular_destinatario'] ?? '-'));
    $empleado = trim((string) ($row['coordinador'] ?? $row['creador'] ?? $row['id_empleado'] ?? '-'));
    $direccion = trim((string) ($row['direccion'] ?? '-'));
    $asuntoCaso = trim((string) ($row['asunto'] ?? $row['categoria_cotizacion'] ?? $row['tipo_mantenimiento'] ?? 'Cotizacion de mantenimiento'));
    $estadoTicket = trim((string) ($row['estado_ticket'] ?? $row['estado_caso'] ?? ''));
    $estadoAdminTicket = trim((string) ($row['estado_administrativo'] ?? $row['estado_administrativo_ticket'] ?? ''));
    $propietario = trim((string) ($row['propietario'] ?? $row['nombre_propietario'] ?? ''));
    $arrendatario = trim((string) ($row['arrendatario'] ?? $row['nombre_arrendatario'] ?? ''));
    $barrio = trim((string) ($row['barrio'] ?? ''));
    $ticketUrl = $ticket !== '' ? self::DEFAULT_TICKET_URL . rawurlencode($ticket) : '';
    $cotUrl = $id !== '' ? self::DEFAULT_COTIZACION_URL . rawurlencode($id) : '';
    $noteUrl = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-nota-a-cotizacion-de-mantenimiento/?id_cotizacion=' . rawurlencode($id) . '&id_inmueble=' . rawurlencode($idInmueble) . '&id_sucursal=' . rawurlencode($sucursal) . '&id_contrato=' . rawurlencode($idContrato);
    $orderUrl = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-orden-de-mantenimiento/?id_cotizacion=' . rawurlencode($id) . '&id_inmueble=' . rawurlencode($idInmueble);
    $actaUrl = rtrim((string) SCM_BASE_URL, '/') . '/crear-acta.php?' . http_build_query([
      'ticket_pk' => $ticket,
      'id_cotizacion' => $id,
      'source_flow' => 'approved_quote',
    ], '', '&', PHP_QUERY_RFC3986);
    $orders = is_array($row['_scm_ordenes'] ?? null) ? $row['_scm_ordenes'] : [];
    $ordersHtml = empty($orders)
      ? '<div class="scm-cotizacion-orders-empty"><span aria-hidden="true">&#128203;</span><strong>Sin &oacute;rdenes registradas</strong><p>Esta cotizaci&oacute;n todav&iacute;a no tiene &oacute;rdenes de mantenimiento asociadas.</p></div>'
      : '<div class="scm-cotizacion-orders-summary"><span>Cotizaci&oacute;n #' . esc_html($id !== '' ? $id : '-') . '</span><strong>' . esc_html((string) count($orders)) . ' ' . (count($orders) === 1 ? 'orden registrada' : '&oacute;rdenes registradas') . '</strong></div><div class="scm-cotizacion-orders-list">';
    $orderDetailsHtml = '';
    $ordersHistoryHtml = empty($orders) ? '<p class="scm-case-history-empty">Sin &oacute;rdenes registradas para esta cotizaci&oacute;n.</p>' : '';
    foreach ($orders as $orderIndex => $order) {
      $orderId = trim((string) ($order['_ID'] ?? ''));
      $orderKey = $orderId !== '' ? $orderId : 'item-' . (string) $orderIndex;
      $orderState = trim((string) ($order['estado'] ?? ''));
      $orderProvider = trim((string) ($order['proveedor'] ?? ''));
      $orderActivity = trim((string) ($order['actividad'] ?? ''));
      $orderCategory = trim((string) ($order['categoria'] ?? ''));
      $orderDateTs = (int) ($order['fecha'] ?? 0);
      if ($orderDateTs <= 0) {
        $orderDateTs = strtotime((string) ($order['cct_created'] ?? '')) ?: 0;
      }
      $orderDate = $orderDateTs > 0 ? date('d/m/Y', $orderDateTs) : '-';
      $ordersHtml .= '<article class="scm-cotizacion-order-card">'
        . '<div class="scm-cotizacion-order-card-head"><div><span>Orden de mantenimiento</span><strong>#' . esc_html($orderId !== '' ? $orderId : '-') . '</strong></div><span class="scm-cotizacion-order-state">' . esc_html($orderState !== '' ? $orderState : 'Sin estado') . '</span></div>'
        . '<div class="scm-cotizacion-order-card-body"><div><span>Proveedor</span><strong>' . esc_html($orderProvider !== '' ? $orderProvider : '-') . '</strong></div><div><span>Categor&iacute;a</span><strong>' . esc_html($orderCategory !== '' ? $orderCategory : '-') . '</strong></div><div><span>Fecha</span><strong>' . esc_html($orderDate) . '</strong></div><div><span>Valor</span><strong>' . esc_html($this->format_cop_currency($order['valor'] ?? 0)) . '</strong></div></div>'
        . '<p class="scm-cotizacion-order-activity">' . esc_html($orderActivity !== '' ? $orderActivity : 'Sin actividad registrada.') . '</p>'
        . '<button type="button" class="scm-cotizacion-order-view" data-scm-view-cotizacion-order="' . esc_attr($orderKey) . '" aria-label="Ver detalle de la orden ' . esc_attr($orderId !== '' ? '#' . $orderId : '') . '">Ver orden <span aria-hidden="true">&rarr;</span></button>'
        . '</article>';
      $ordersHistoryHtml .= '<article class="scm-case-history-item"><div class="scm-case-history-meta"><strong>Orden #' . esc_html($orderId !== '' ? $orderId : '-') . '</strong><span>' . esc_html($orderState !== '' ? $orderState : '-') . '</span></div><div class="scm-case-history-detail"><p><strong>Proveedor:</strong> ' . esc_html($orderProvider !== '' ? $orderProvider : '-') . '</p><p><strong>Actividad:</strong> ' . esc_html($orderActivity !== '' ? $orderActivity : '-') . '</p><p><strong>Valor:</strong> ' . esc_html($this->format_cop_currency($order['valor'] ?? 0)) . '</p></div></article>';
      $orderDetailsHtml .= '<template class="scm-cotizacion-order-detail-source" data-scm-cotizacion-order-detail="' . esc_attr($orderKey) . '" data-order-number="' . esc_attr($orderId !== '' ? $orderId : '-') . '">' . $this->render_cotizacion_order_detail($order) . '</template>';
    }
    if (!empty($orders)) {
      $ordersHtml .= '</div>';
    }

    $saldoObra = $this->format_cop_currency($this->cotizacion_money_value($row, ['saldo_obra']));
    $saldoMateriales = $this->format_cop_currency($this->cotizacion_money_value($row, ['saldo_materiales']));
    $saldoMaquinarias = $this->format_cop_currency($this->cotizacion_money_value($row, ['saldo_maquinarias']));
    $saldoOtros = $this->format_cop_currency($this->cotizacion_money_value($row, ['saldo_otros_costo']));
    $totalObra = $this->format_cop_currency($this->cotizacion_money_value($row, ['total_mano_obra']));
    $totalMateriales = $this->format_cop_currency($this->cotizacion_money_value($row, ['total_materiales']));
    $totalMaquinarias = $this->format_cop_currency($this->cotizacion_money_value($row, ['total_maquinarias']));
    $totalOtros = $this->format_cop_currency($this->cotizacion_money_value($row, ['total_otros_costos']));
    $totalCotizacion = $this->format_cop_currency($this->cotizacion_money_value($row, ['total']));
    $ordenesTotal = (string) ($row['ordenes_total'] ?? count($orders));
    $nativeCotizacionFuncionarioHtml = $this->render_native_cotizacion_mantenimiento_view($row, $orders, 'funcionario');
    $nativeCotizacionDestinatarioHtml = $this->render_native_cotizacion_mantenimiento_view($row, $orders, 'destinatario');
    $cotizacionSinResponder = in_array(strtolower($estado), ['', 'esperando respuesta'], true);
    $caseDescription = 'Cotizacion de mantenimiento #' . ($id !== '' ? $id : '-') . ($ticket !== '' ? ' relacionada con el ticket #' . $ticket . '.' : '.');
    if ($direccion !== '' && $direccion !== '-') {
      $caseDescription .= ' Direccion: ' . $direccion . '.';
    }
    $caseSource = '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">' . esc_html($caseDescription) . '</div></div>';
    if ($ticket !== '') {
      $caseSource .= '<div class="scm-seg-wrap">' . $this->render_seguimiento_form((int) $ticket, Auth::isLoggedIn(), $cotizacionSinResponder) . '</div>';
    }
    $caseSource .= '<section class="scm-case-history"><h4>Detalle de la cotizaci&oacute;n</h4><article class="scm-case-history-item"><div class="scm-case-history-detail">';
    $caseSource .= '<p><strong>Cotizaci&oacute;n:</strong> #' . esc_html($id !== '' ? $id : '-') . '</p>';
    $caseSource .= '<p><strong>Estado cotizaci&oacute;n:</strong> ' . esc_html($estado !== '' ? $estado : 'Sin estado') . '</p>';
    $caseSource .= '<p><strong>Env&iacute;o:</strong> ' . esc_html($enviada ? 'Fue enviada' : 'Sin enviar') . '</p>';
    $caseSource .= '<p><strong>Total cotizaci&oacute;n:</strong> ' . esc_html($totalCotizacion) . '</p>';
    $caseSource .= '<p><strong>Destinatario:</strong> ' . esc_html($destinatario !== '' ? $destinatario : '-') . '</p>';
    $caseSource .= '<p><strong>Contacto:</strong> ' . esc_html($contacto !== '' ? $contacto : '-') . '</p>';
    $caseSource .= '</div></article></section>';
    $caseSource .= '<section class="scm-case-history"><h4>&Oacute;rdenes de mantenimiento</h4>' . $ordersHistoryHtml . '</section>';
    $ticketCaseButton = '';
    $linkedTicketCardHtml = $ticket !== '' ? (string) ($linkedTicketCards[$ticket] ?? '') : '';
    $linkedTicketSource = $linkedTicketCardHtml !== ''
      ? '<template class="scm-cotizacion-linked-ticket-source">' . $linkedTicketCardHtml . '</template>'
      : '';
    if ($ticket !== '') {
      if ($linkedTicketCardHtml !== '') {
        $ticketCaseButton = '<button type="button" class="scm-case-work-btn" data-scm-open-linked-ticket-case data-ticket-pk="' . esc_attr($ticket) . '">Ver ticket</button>';
      } else {
        $ticketCaseButton = '<button type="button" class="scm-case-work-btn scm-btn-case" onclick="scmOpenCase(this)"'
          . ' data-ticket="' . esc_attr($ticket) . '"'
          . ' data-ticket-pk="' . esc_attr($ticket) . '"'
          . ' data-asunto="' . esc_attr($asuntoCaso !== '' ? $asuntoCaso : 'Cotizacion de mantenimiento') . '"'
          . ' data-estado="' . esc_attr($estadoTicket !== '' ? $estadoTicket : '-') . '"'
          . ' data-admin="' . esc_attr($estadoAdminTicket !== '' ? $estadoAdminTicket : '-') . '"'
          . ' data-contrato="' . esc_attr($contrato !== '' ? '#' . $contrato : '-') . '"'
          . ' data-inmueble="' . esc_attr($inmueble !== '' ? $inmueble : '-') . '"'
          . ' data-id-inmueble-web="' . esc_attr($idInmueble !== '' ? $idInmueble : '-') . '"'
          . ' data-barrio="' . esc_attr($barrio !== '' ? $barrio : '-') . '"'
          . ' data-direccion="' . esc_attr($direccion !== '' ? $direccion : '-') . '"'
          . ' data-creado="' . esc_attr($fecha) . '"'
          . ' data-empleado="' . esc_attr($empleado !== '' ? $empleado : '-') . '"'
          . ' data-propietario="' . esc_attr($propietario) . '"'
          . ' data-arrendatario="' . esc_attr($arrendatario) . '"'
          . ' data-ticket-url="' . esc_attr($ticketUrl) . '"'
          . ' data-cotizacion-id="' . esc_attr($id) . '"'
          . ' data-cotizacion-url="' . esc_attr($cotUrl) . '"'
          . ' data-cot-estado="' . esc_attr($estado) . '"'
          . '>Ver ticket</button>';
      }
    }

    return '<article class="scm-cotizacion-card card" data-cotizacion-id="' . esc_attr($id) . '">'
      . '<div class="scm-cotizacion-main"><div><span class="scm-ticket-badge badge badge-primary">#' . esc_html($id) . '</span><h3>' . esc_html($direccion !== '' ? $direccion : 'Cotizacion de mantenimiento') . '</h3><p>Ticket <strong>#' . esc_html($ticket !== '' ? $ticket : '-') . '</strong> · Inmueble <strong>' . esc_html($inmueble !== '' ? $inmueble : '-') . '</strong> · Contrato <strong>' . esc_html($contrato !== '' ? $contrato : '-') . '</strong></p></div><div class="scm-cotizacion-status"><span class="scm-cotizacion-pill ' . ($enviada ? 'is-sent' : 'is-pending') . '">' . ($enviada ? 'Fue enviada' : 'Sin enviar') . '</span><span class="scm-cotizacion-pill is-state">' . esc_html($estado !== '' ? $estado : 'Sin estado') . '</span></div></div>'
      . '<div class="scm-cotizacion-meta"><div><span>Fecha</span><strong>' . esc_html($fecha) . '</strong></div><div><span>Destinatario</span><strong>' . esc_html($destinatario !== '' ? $destinatario : '-') . '</strong></div><div><span>Contacto</span><strong>' . esc_html($contacto !== '' ? $contacto : '-') . '</strong></div><div><span>Empleado</span><strong>' . esc_html($empleado !== '' ? $empleado : '-') . '</strong></div><div><span>Ordenes</span><strong>' . esc_html($ordenesTotal) . '</strong></div></div>'
      . '<div class="scm-cotizacion-finance-actions"><button type="button" class="scm-case-work-btn" data-scm-cotizacion-toggle-panel="saldos" aria-expanded="false">Ver saldos</button><button type="button" class="scm-case-work-btn" data-scm-cotizacion-toggle-panel="totales" aria-expanded="false">Ver totales</button></div>'
      . '<div class="scm-cotizacion-finance-panel" data-scm-cotizacion-finance-panel="saldos" hidden><div class="scm-cotizacion-finance-grid"><div><span>Saldo mano de obra</span><strong>' . esc_html($saldoObra) . '</strong></div><div><span>Saldo materiales</span><strong>' . esc_html($saldoMateriales) . '</strong></div><div><span>Saldo equipos</span><strong>' . esc_html($saldoMaquinarias) . '</strong></div><div><span>Saldo otros costos</span><strong>' . esc_html($saldoOtros) . '</strong></div></div></div>'
      . '<div class="scm-cotizacion-finance-panel" data-scm-cotizacion-finance-panel="totales" hidden><div class="scm-cotizacion-finance-grid"><div><span>Total mano de obra</span><strong>' . esc_html($totalObra) . '</strong></div><div><span>Total materiales</span><strong>' . esc_html($totalMateriales) . '</strong></div><div><span>Total equipos</span><strong>' . esc_html($totalMaquinarias) . '</strong></div><div><span>Total otros costos</span><strong>' . esc_html($totalOtros) . '</strong></div><div class="scm-cotizacion-finance-total"><span>Total cotizaci&oacute;n</span><strong>' . esc_html($totalCotizacion) . '</strong></div></div></div>'
      . '<div class="scm-cotizacion-actions">'
      . ($id !== '' ? '<button type="button" class="scm-case-work-btn" data-scm-view-cotizacion-native data-cotizacion-id="' . esc_attr($id) . '">Ver cotizaci&oacute;n</button>' : '')
      . $ticketCaseButton
      . ($cotizacionAprobada ? '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-view-cotizacion-orders>Ver &oacute;rdenes <span class="scm-action-count">' . esc_html((string) count($orders)) . '</span></button>' : '')
      . '<button type="button" class="scm-case-work-btn" data-scm-cotizacion-response-standalone data-ticket-pk="' . esc_attr($ticket) . '" data-ticket="' . esc_attr($ticket) . '" data-cotizacion-id="' . esc_attr($id) . '">Responder cotizaci&oacute;n</button>'
      . '<button type="button" class="scm-case-work-btn scm-danger-action" data-scm-delete-cotizacion data-cotizacion-id="' . esc_attr($id) . '">Eliminar cotizaci&oacute;n</button>'
      . '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($noteUrl) . '" data-iframe-title="A&ntilde;adir nota a cotizaci&oacute;n">A&ntilde;adir nota</button>'
      . ($cotizacionAprobada ? '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($orderUrl) . '" data-iframe-title="A&ntilde;adir orden de mantenimiento">A&ntilde;adir orden</button>' : '')
      . ($cotizacionAprobada ? '<a class="scm-case-work-btn" href="' . esc_attr($actaUrl) . '">A&ntilde;adir acta</a>' : '')
      . '</div><div class="scm-cotizacion-orders-source" style="display:none;">' . $ordersHtml . '</div>' . $orderDetailsHtml
      . '<template class="scm-cotizacion-native-source" data-scm-cotizacion-native-audience="funcionario">' . $nativeCotizacionFuncionarioHtml . '</template>'
      . '<template class="scm-cotizacion-native-source" data-scm-cotizacion-native-audience="destinatario">' . $nativeCotizacionDestinatarioHtml . '</template>'
      . $linkedTicketSource . '<div class="scm-case-source" aria-hidden="true" style="display:none;">' . $caseSource . '</div></article>';
  }

  /** @param array<string,mixed> $order */
  private function render_cotizacion_order_detail(array $order): string
  {
    $value = static fn(string $key, string $fallback = '-'): string => trim((string) ($order[$key] ?? '')) !== '' ? trim((string) $order[$key]) : $fallback;
    $dateTs = (int) ($order['fecha'] ?? 0);
    if ($dateTs <= 0) {
      $dateTs = strtotime((string) ($order['cct_created'] ?? '')) ?: 0;
    }
    $date = $dateTs > 0 ? date('d/m/Y h:i a', $dateTs) : '-';
    $createdTs = strtotime((string) ($order['cct_created'] ?? '')) ?: 0;
    $modifiedTs = strtotime((string) ($order['cct_modified'] ?? '')) ?: 0;
    $detailItem = static function (string $label, string $content, bool $wide = false): string {
      return '<div class="scm-cotizacion-order-detail-item' . ($wide ? ' is-wide' : '') . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($content !== '' ? $content : '-') . '</strong></div>';
    };

    $html = '<div class="scm-cotizacion-order-detail">';
    $html .= '<div class="scm-cotizacion-order-detail-hero"><div><span>Orden de mantenimiento</span><strong>#' . esc_html($value('_ID')) . '</strong></div><span class="scm-cotizacion-order-state">' . esc_html($value('estado', 'Sin estado')) . '</span></div>';
    $html .= '<section><h3>Informaci&oacute;n de la orden</h3><div class="scm-cotizacion-order-detail-grid">';
    $html .= $detailItem('Fecha', $date);
    $html .= $detailItem('Categoría', $value('categoria'));
    $html .= $detailItem('Concepto', $value('concepto'));
    $html .= $detailItem('Valor', $this->format_cop_currency($order['valor'] ?? 0));
    $html .= $detailItem('Actividad', $value('actividad'), true);
    $html .= '</div></section>';
    $html .= '<section><h3>Ubicaci&oacute;n y referencias</h3><div class="scm-cotizacion-order-detail-grid">';
    $html .= $detailItem('Cotización', '#' . $value('id_cotizacion'));
    $html .= $detailItem('Ticket', '#' . $value('id_ticket'));
    $html .= $detailItem('Contrato', '#' . $value('contrato', $value('id_contrato')));
    $html .= $detailItem('Inmueble', $value('inmueble', $value('id_inmueble')));
    $html .= $detailItem('Sucursal', $value('sucursal'));
    $html .= $detailItem('Dirección', $value('direccion'), true);
    $html .= '</div></section>';
    $html .= '<section><h3>Proveedor</h3><div class="scm-cotizacion-order-detail-grid">';
    $html .= $detailItem('Nombre', $value('proveedor'));
    $html .= $detailItem('Identificación', trim($value('tipo_identificacion_proveedor', '')) . ' ' . $value('identificacion_proveedor'));
    $html .= $detailItem('Correo', $value('correo_proveedor'));
    $html .= $detailItem('Celular', $value('celular_proveedor'));
    $html .= $detailItem('Dirección', $value('direccion_proveedor'), true);
    $html .= '</div></section>';
    $html .= '<section><h3>Datos para pago</h3><div class="scm-cotizacion-order-detail-grid">';
    $html .= $detailItem('Titular', $value('titular_proveedor'));
    $html .= $detailItem('Identificación del titular', $value('identificacion_cuenta_proveedor'));
    $html .= $detailItem('Banco', $value('banco_proveedor'));
    $html .= $detailItem('Tipo de cuenta', $value('tipo_cuenta_proveedor'));
    $html .= $detailItem('Cuenta', $value('cuenta_proveedor'));
    $html .= $detailItem('Correo de pago', $value('correo_pago_proveedor'));
    $html .= '</div></section>';
    $html .= '<section><h3>Responsables y trazabilidad</h3><div class="scm-cotizacion-order-detail-grid">';
    $html .= $detailItem('Creador', $value('creador'));
    $html .= $detailItem('Coordinador', $value('coordinador'));
    $html .= $detailItem('Autorizador', $value('autorizador'));
    $html .= $detailItem('Creada', $createdTs > 0 ? date('d/m/Y h:i a', $createdTs) : '-');
    $html .= $detailItem('Última actualización', $modifiedTs > 0 ? date('d/m/Y h:i a', $modifiedTs) : '-');
    $html .= '</div></section></div>';

    return $html;
  }

  /** @param array<int,array<string,mixed>> $rows @return array<string,string> */
  private function cotizacion_linked_ticket_cards_by_rows(array $rows): array
  {
    $refs = [];
    foreach ($rows as $row) {
      $ref = trim((string) ($row['id_ticket'] ?? ''));
      if ($ref !== '') {
        $refs[$ref] = $ref;
      }
    }
    if (empty($refs)) {
      return [];
    }

    $primaryByRef = $this->cotizacion_resolve_ticket_primary_keys(array_values($refs));
    if (empty($primaryByRef)) {
      return [];
    }

    try {
      $cardsByPk = $this->get_servicios_inmobiliarios_module()->renderCardsByTicketIds(
        array_values(array_unique(array_values($primaryByRef))),
        [
          'ticket_url' => self::DEFAULT_TICKET_URL,
          'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
          'correctiva_url' => self::DEFAULT_CORRECTIVA_URL,
          'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
          'acta_url' => self::DEFAULT_ACTA_URL,
        ]
      );
    } catch (\Throwable $e) {
      return [];
    }

    $out = [];
    foreach ($primaryByRef as $ref => $pk) {
      $card = (string) ($cardsByPk[$pk] ?? '');
      if ($card !== '') {
        $out[$ref] = $card;
      }
    }
    return $out;
  }

  /** @param array<int,string> $ticketRefs @return array<string,string> */
  private function cotizacion_resolve_ticket_primary_keys(array $ticketRefs): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }

    $refs = [];
    foreach ($ticketRefs as $ref) {
      $ref = trim((string) $ref);
      if ($ref !== '') {
        $refs[$ref] = $ref;
      }
    }
    if (empty($refs)) {
      return [];
    }

    $refs = array_values($refs);
    $placeholders = implode(',', array_fill(0, count($refs), '?'));
    $where = ["CAST(`_ID` AS CHAR) IN ({$placeholders})"];
    $args = $refs;
    if ($this->column_exists($table, 'id_ticket')) {
      $where[] = "CAST(`id_ticket` AS CHAR) IN ({$placeholders})";
      $args = array_merge($args, $refs);
    }

    $rows = $this->db->getResults(
      "SELECT `_ID`, " . ($this->column_exists($table, 'id_ticket') ? '`id_ticket`' : "'' AS `id_ticket`") . " FROM `{$table}` WHERE " . implode(' OR ', $where),
      $args
    );
    if (empty($rows)) {
      return [];
    }

    $wanted = array_flip($refs);
    $out = [];
    foreach ($rows as $row) {
      $pk = trim((string) ($row['_ID'] ?? ''));
      if ($pk === '') {
        continue;
      }
      if (isset($wanted[$pk])) {
        $out[$pk] = $pk;
      }
      $logical = trim((string) ($row['id_ticket'] ?? ''));
      if ($logical !== '' && isset($wanted[$logical])) {
        $out[$logical] = $pk;
      }
    }
    return $out;
  }

  /** @param array<string,mixed> $row @param array<int,array<string,mixed>> $orders */
  private function render_native_cotizacion_mantenimiento_view(array $row, array $orders, string $audience = 'funcionario'): string
  {
    $audience = strtolower(trim($audience)) === 'destinatario' ? 'destinatario' : 'funcionario';
    $isFuncionario = $audience === 'funcionario';
    $id = trim((string) ($row['_ID'] ?? ''));
    $tipo = trim((string) ($row['tipo_mantenimiento'] ?? 'Mantenimiento'));
    $ticket = trim((string) ($row['id_ticket'] ?? ''));
    $fecha = $this->cotizacion_date_label($row['fecha'] ?? $row['cct_created'] ?? '');
    $fechaEnvio = $this->cotizacion_date_label($row['fecha_envio'] ?? '');
    $estado = trim((string) ($row['estado'] ?? ''));
    $seEnvio = strtolower(trim((string) ($row['se_envio'] ?? '')));
    $enviada = in_array($seEnvio, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
    $logo = function_exists('system_image') ? system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL) : SCM_DEFAULT_PORTAL_LOGO_URL;
    $revision = $this->cotizacion_revision_row($row);
    $danos = $this->cotizacion_parse_list($revision['evaluacion_de_danos'] ?? '');
    $mejorOferta = $this->cotizacion_media_items($this->cotizacion_split_ids($row['mejor_oferta'] ?? ''));
    $otrasOfertas = $this->cotizacion_media_items($this->cotizacion_split_ids($row['otras_oferta'] ?? ''));
    $materiales = $this->cotizacion_parse_list($row['items_materiales'] ?? '');
    $mano = $this->cotizacion_parse_list($row['items_mano'] ?? '');
    $equipos = $this->cotizacion_parse_list($row['items_otros_equi'] ?? '');
    $otros = $this->cotizacion_parse_list($row['items_otros_costos'] ?? '');
    $resumen = $this->cotizacion_parse_json($row['resumen_calculo_perturbacion'] ?? '');
    $totalCotizacion = $this->format_cop_currency($row['total'] ?? 0);
    $coordinadorNombre = $this->cotizacion_clean_text($row['coordinador'] ?? '');
    $coordinadorEmail = $this->cotizacion_clean_text($row['email_coordinador'] ?? '');
    $coordinadorCelular = $this->cotizacion_clean_text($row['celular_coordinador'] ?? '');
    $coordinadorCargo = $this->cotizacion_clean_text($row['cargo_coordinador'] ?? 'Coordinador contractual');
    $creadorNombre = $this->cotizacion_clean_text($row['creador'] ?? '');
    $creadorEmail = $this->cotizacion_clean_text($row['email_creador'] ?? '');
    $creadorCelular = $this->cotizacion_clean_text($row['celular_creador'] ?? '');

    $html = '<article class="scm-cotizacion-native-doc is-audience-' . esc_attr($audience) . '" data-cotizacion-audience="' . esc_attr($audience) . '" data-cotizacion-print-title="Cotización #' . esc_attr($id) . '">';
    $html .= '<div class="scm-cotizacion-native-audience"><span>' . esc_html($isFuncionario ? 'Vista interna' : 'Copia para destinatario') . '</span><strong>' . esc_html($isFuncionario ? 'Documento completo para funcionario' : 'Documento comercial para compartir') . '</strong></div>';
    $html .= '<header class="scm-cotizacion-native-hero">';
    $html .= '<div class="scm-cotizacion-native-brand"><div class="scm-cotizacion-native-logo"><img src="' . esc_attr($logo) . '" alt="SuCasa Inmobiliaria"><strong>SuCasa Inmobiliaria</strong><span>Control Servicios Inmobiliarios</span></div><div class="scm-cotizacion-native-number"><span>Cotización de mantenimiento</span><strong>#' . esc_html($id !== '' ? $id : '-') . '</strong></div></div>';
    $html .= '<div class="scm-cotizacion-native-hero-bottom"><div><p class="scm-cotizacion-native-eyebrow">Documento comercial</p><h2>Cotización de mantenimiento para revisión ' . esc_html($tipo !== '' ? strtolower($this->cotizacion_clean_text($tipo)) : 'de mantenimiento') . '</h2></div><div class="scm-cotizacion-native-state"><span class="' . ($enviada ? 'is-sent' : 'is-pending') . '">' . esc_html($enviada ? 'Fue enviada' : 'Sin enviar') . '</span><strong>' . esc_html($estado !== '' ? $this->cotizacion_clean_text($estado) : 'Sin estado') . '</strong><em>Total ' . esc_html($totalCotizacion) . '</em></div></div>';
    $html .= '</header>';

    $html .= '<section class="scm-cotizacion-native-grid scm-cotizacion-native-summary">';
    foreach ([
      'Fecha' => $fecha,
      'Fecha de envío' => $fechaEnvio,
      'Contrato' => (string) ($row['contrato'] ?? '-'),
      'Destinatario' => (string) ($row['destinatario'] ?? '-'),
      'Validez' => $this->cotizacion_days_label($row['valides_oferta'] ?? ''),
      'Duración del trabajo' => $this->cotizacion_days_label($row['duracion'] ?? ''),
      'Inmueble' => (string) ($row['inmueble'] ?? '-'),
      'Dirección' => (string) ($row['direccion'] ?? '-'),
      'Coordinador contractual' => $coordinadorNombre !== '' ? $coordinadorNombre : '-',
      'Elaboró la cotización' => $creadorNombre !== '' ? $creadorNombre : '-',
      'Ticket / Inmueble' => ($ticket !== '' ? '#' . $ticket : '-') . ' / ' . trim((string) ($row['id_inmueble'] ?? '-')),
    ] as $label => $value) {
      $cleanValue = $this->cotizacion_clean_text($value);
      $html .= '<div><span>' . esc_html($label) . '</span><strong>' . esc_html($cleanValue !== '' ? $cleanValue : '-') . '</strong></div>';
    }
    $html .= '</section>';

    $html .= '<section class="scm-cotizacion-native-section scm-cotizacion-native-offers"><h3>Ofertas y soportes</h3><div class="scm-cotizacion-native-two-col">';
    $html .= '<div><h4>Mejor oferta</h4>' . $this->render_cotizacion_media_grid($mejorOferta, 'Sin mejor oferta adjunta.') . '</div>';
    $html .= '<div><h4>Otras ofertas</h4>' . $this->render_cotizacion_media_grid($otrasOfertas, 'No se presentó ninguna otra oferta.') . '</div>';
    $html .= '</div></section>';

    $html .= '<section class="scm-cotizacion-native-section scm-cotizacion-native-damage-report"><div class="scm-cotizacion-native-section-title"><div><span>Informe t&eacute;cnico</span><h3>Da&ntilde;os encontrados</h3></div><strong>' . esc_html((string) count($danos)) . ' ' . (count($danos) === 1 ? 'hallazgo' : 'hallazgos') . '</strong></div>';
    if (empty($danos)) {
      $html .= '<p class="scm-cotizacion-native-empty">Sin daños registrados.</p>';
    }
    foreach ($danos as $damageIndex => $damage) {
      $damageMedia = $this->cotizacion_media_items($this->cotizacion_split_ids($damage['registro_foto_dano'] ?? ''));
      $areas = array_filter([
        trim((string) ($damage['area_afectada_1'] ?? '')),
        trim((string) ($damage['area_afectada_2'] ?? '')),
        trim((string) ($damage['area_afectada_3'] ?? '')),
        trim((string) ($damage['area_afectada_4'] ?? '')),
      ]);
      $html .= '<article class="scm-cotizacion-damage-card">';
      $html .= '<div class="scm-cotizacion-damage-title"><div><span>Hallazgo</span><strong>#' . esc_html((string) ($damageIndex + 1)) . '</strong></div><p>' . esc_html($this->cotizacion_clean_text($damage['area_afectada_1'] ?? 'Da&ntilde;o evaluado')) . '</p></div>';
      $html .= '<div class="scm-cotizacion-damage-head"><div><span>Índice</span><strong>' . esc_html($this->cotizacion_clean_text($damage['indice'] ?? '-')) . '</strong></div><div><span>Corresponde a</span><strong>' . esc_html($this->cotizacion_clean_text($damage['a_quien_corresponde'] ?? '-')) . '</strong></div><div><span>Nivel</span><strong>' . esc_html($this->cotizacion_clean_text($damage['nivel_dano'] ?? '-')) . '</strong></div><div><span>Tiempo de atención</span><strong>' . esc_html($this->cotizacion_clean_text($damage['tiempo_atencion'] ?? '-')) . '</strong></div></div>';
      $html .= '<div class="scm-cotizacion-native-tags">' . (empty($areas) ? '<span>-</span>' : implode('', array_map(fn($area): string => '<span>' . esc_html($this->cotizacion_clean_text($area)) . '</span>', $areas))) . '</div>';
      $html .= '<div class="scm-cotizacion-native-rich"><h4>Descripción del daño</h4>' . $this->cotizacion_rich_text($damage['descripcion_dano'] ?? '') . '<h4>Consecuencia</h4>' . $this->cotizacion_rich_text($damage['consecuencia'] ?? '') . '</div>';
      $html .= $this->render_cotizacion_media_grid($damageMedia, 'Sin registro fotográfico.');
      $html .= '</article>';
    }
    $html .= '</section>';

    $html .= '<section class="scm-cotizacion-native-section"><h3>Presupuesto</h3>';
    $html .= $this->render_cotizacion_budget_rows('Materiales', $materiales, ['total_materiales'], $row);
    $html .= $this->render_cotizacion_budget_rows('Mano de obra', $mano, ['total_mano_obra'], $row);
    $html .= $this->render_cotizacion_budget_rows('Equipos / maquinarias', $equipos, ['total_maquinarias'], $row);
    $html .= $this->render_cotizacion_budget_rows('Otros costos', $otros, ['total_otros_costos'], $row);
    $html .= '<div class="scm-cotizacion-native-total-box">';
    $administrationLabel = $isFuncionario
      ? 'Administración ' . esc_html((string) ($row['porcentaje_admon'] ?? '0')) . '%'
      : 'Administración';
    $ivaLabel = $isFuncionario
      ? 'IVA ' . esc_html((string) ($row['iva'] ?? '0')) . '%'
      : 'IVA sobre administración';
    foreach ([
      'Subtotal materiales' => $row['total_materiales'] ?? 0,
      'Mano de obra' => $row['total_mano_obra'] ?? 0,
      'Equipos' => $row['total_maquinarias'] ?? 0,
      'Otros costos' => $row['total_otros_costos'] ?? 0,
      $administrationLabel => $row['total_admon'] ?? 0,
      $ivaLabel => $row['iva_admon'] ?? 0,
    ] as $label => $value) {
      $html .= '<div><span>' . $label . '</span><strong>' . esc_html($this->format_cop_currency($value)) . '</strong></div>';
    }
    $html .= '<div class="is-grand-total"><span>Total cotización</span><strong>' . esc_html($totalCotizacion) . '</strong></div>';
    $html .= '</div></section>';

    $html .= '<section class="scm-cotizacion-native-section scm-cotizacion-native-balances"><div class="scm-cotizacion-native-section-title"><div><span>Control financiero</span><h3>Saldos de la cotizaci&oacute;n</h3></div></div>';
    $html .= $this->render_cotizacion_balance_table($row);
    $html .= '</section>';

    $html .= '<section class="scm-cotizacion-native-section"><h3>Observaciones y perturbación</h3>';
    if (trim((string) ($row['observaciones'] ?? '')) !== '') {
      $html .= '<div class="scm-cotizacion-native-note">' . $this->cotizacion_rich_text($row['observaciones']) . '</div>';
    }
    $html .= '<div class="scm-cotizacion-native-grid">';
    foreach ([
      'Perturbación sugerida' => trim((string) ($row['perturbacion'] ?? '')) . '%',
      'Bonificación sugerida' => $this->format_cop_currency($row['valor_bonificacion'] ?? 0),
      'Días calculados' => (string) ($row['dias_afectacion_calculados'] ?? '-'),
      'Área afectada' => trim((string) ($row['area_afectada'] ?? '')) !== '' ? trim((string) ($row['area_afectada'] ?? '')) . ' m2' : '-',
      'Tipo inmueble' => (string) ($row['tipo_inmueble'] ?? '-'),
      'Destinación' => (string) ($row['destinacion'] ?? '-'),
    ] as $label => $value) {
      $html .= '<div><span>' . esc_html($label) . '</span><strong>' . esc_html($this->cotizacion_clean_text($value)) . '</strong></div>';
    }
    $html .= '</div>';
    if (!empty($resumen['criterios']) && is_array($resumen['criterios'])) {
      $html .= '<h4>Criterios seleccionados</h4><ol class="scm-cotizacion-native-criteria">';
      foreach ($resumen['criterios'] as $criterion) {
        $html .= '<li>' . esc_html((string) $criterion) . '</li>';
      }
      $html .= '</ol>';
    }
    if (trim((string) ($row['justificacion_perturbacion'] ?? '')) !== '') {
      $html .= '<h4>Justificación</h4><div class="scm-cotizacion-native-note">' . $this->cotizacion_rich_text($row['justificacion_perturbacion']) . '</div>';
    }
    $html .= '</section>';

    $html .= '<section class="scm-cotizacion-native-section scm-cotizacion-native-legal"><h3>Favor tener en cuenta</h3><p>Cuando las reparaciones sean responsabilidad de los propietarios, el administrador informará la novedad. Si no se atiende dentro del plazo contractual, la administración podrá realizar la gestión y descontar el valor correspondiente del canon de arrendamiento, de acuerdo con el contrato de mandato vigente.</p></section>';
    $html .= '<footer class="scm-cotizacion-native-footer"><div><span>Coordinador contractual</span><strong>' . esc_html($coordinadorNombre !== '' ? $coordinadorNombre : '-') . '</strong><p>' . esc_html($coordinadorCargo !== '' ? $coordinadorCargo : 'Coordinador contractual') . '</p><p>Email: ' . esc_html($coordinadorEmail !== '' ? $coordinadorEmail : '-') . '</p><p>Celular: ' . esc_html($coordinadorCelular !== '' ? $coordinadorCelular : '-') . '</p></div><div><span>Elaboró la cotización</span><strong>' . esc_html($creadorNombre !== '' ? $creadorNombre : '-') . '</strong><p>Email: ' . esc_html($creadorEmail !== '' ? $creadorEmail : '-') . '</p><p>Celular: ' . esc_html($creadorCelular !== '' ? $creadorCelular : '-') . '</p></div><div><span>Empresa</span><strong>SKC SuCasa Inmobiliaria</strong><p>NIT 900623242-4</p><p>Cartagena de Indias - Colombia</p></div></footer>';
    return $html . '</article>';
  }

  private function cotizacion_date_label($raw): string
  {
    if (is_numeric($raw)) {
      $ts = (int) $raw;
    } else {
      $ts = strtotime((string) $raw) ?: 0;
    }
    return $ts > 0 ? date('d-m-Y', $ts) : '-';
  }

  private function cotizacion_clean_text($value): string
  {
    $text = (string) $value;
    for ($i = 0; $i < 3; $i++) {
      $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($decoded === $text) {
        break;
      }
      $text = $decoded;
    }
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text) ?: '';
    return trim($text);
  }

  private function cotizacion_days_label($value): string
  {
    $text = $this->cotizacion_clean_text($value);
    if ($text === '' || $text === '-') {
      return '-';
    }
    $numeric = $this->parse_cop_number($text);
    if ($numeric === null) {
      return $text;
    }
    $days = (int) round($numeric);
    if ($days <= 0) {
      return '-';
    }
    return $days . ' ' . ($days === 1 ? 'día' : 'días');
  }

  /** @return array<string,mixed> */
  private function cotizacion_revision_row(array $row): array
  {
    $idRevision = trim((string) ($row['id_revision'] ?? ''));
    if ($idRevision === '') {
      return [];
    }
    $tipo = strtolower(trim((string) ($row['tipo_mantenimiento'] ?? '')));
    $tableName = strpos($tipo, 'prevent') !== false ? 'jet_cct_revision_preventiva' : 'jet_cct_revision_correctiva';
    $table = $this->db->table($tableName);
    if (!$this->table_exists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$idRevision]) ?: [];
  }

  /** @return array<int,array<string,mixed>> */
  private function cotizacion_parse_list($raw): array
  {
    if (is_array($raw)) {
      $value = $raw;
    } else {
      $text = trim((string) $raw);
      if ($text === '') {
        return [];
      }
      $value = @unserialize($text, ['allowed_classes' => false]);
      if ($value === false && $text !== 'b:0;') {
        $json = json_decode($text, true);
        $value = is_array($json) ? $json : [];
      }
    }
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $item) {
      if (is_array($item)) {
        $out[] = $item;
      }
    }
    return $out;
  }

  /** @return array<string,mixed> */
  private function cotizacion_parse_json($raw): array
  {
    $json = json_decode((string) $raw, true);
    return is_array($json) ? $json : [];
  }

  /** @return array<int,string> */
  private function cotizacion_split_ids($raw): array
  {
    $ids = preg_split('/[,\s]+/', trim((string) $raw)) ?: [];
    $out = [];
    foreach ($ids as $id) {
      $id = preg_replace('/\D+/', '', $id) ?: '';
      if ($id !== '') {
        $out[$id] = $id;
      }
    }
    return array_values($out);
  }

  /** @param array<int,string> $ids @return array<int,array<string,string>> */
  private function cotizacion_media_items(array $ids): array
  {
    if (empty($ids)) {
      return [];
    }
    $posts = $this->db->table('posts');
    if (!$this->table_exists($posts)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->db->getResults("SELECT ID, post_title, guid, post_mime_type FROM `{$posts}` WHERE ID IN ({$placeholders})", $ids);
    $out = [];
    foreach ($rows as $row) {
      $url = trim((string) ($row['guid'] ?? ''));
      if ($url === '') {
        continue;
      }
      $out[] = [
        'id' => (string) ($row['ID'] ?? ''),
        'title' => trim((string) ($row['post_title'] ?? 'Documento')),
        'url' => $url,
        'mime' => trim((string) ($row['post_mime_type'] ?? '')),
      ];
    }
    return $out;
  }

  /** @param array<int,array<string,string>> $items */
  private function render_cotizacion_media_grid(array $items, string $empty): string
  {
    if (empty($items)) {
      return '<p class="scm-cotizacion-native-empty">' . esc_html($empty) . '</p>';
    }
    $html = '<div class="scm-cotizacion-media-grid">';
    foreach ($items as $item) {
      $isImage = strpos(strtolower($item['mime'] ?? ''), 'image/') === 0;
      $title = $this->cotizacion_clean_text($item['title'] !== '' ? $item['title'] : 'Ver adjunto');
      $html .= '<a href="' . esc_attr($item['url']) . '" target="_blank" rel="noopener noreferrer" class="scm-cotizacion-media-item">';
      if ($isImage) {
        $html .= '<img src="' . esc_attr($item['url']) . '" alt="' . esc_attr($title !== '' ? $title : 'Imagen adjunta') . '">';
      } else {
        $html .= '<span class="scm-cotizacion-media-file">Documento</span>';
      }
      $html .= '<strong>' . esc_html($title !== '' ? $title : 'Ver adjunto') . '</strong></a>';
    }
    return $html . '</div>';
  }

  /** @param array<int,array<string,mixed>> $items @param array<int,string> $totalKeys */
  private function render_cotizacion_budget_rows(string $title, array $items, array $totalKeys, array $row): string
  {
    $html = '<div class="scm-cotizacion-budget-block"><h4>' . esc_html($title) . '</h4>';
    if (empty($items)) {
      $html .= '<p class="scm-cotizacion-native-empty">Sin items registrados.</p>';
    } else {
      $html .= '<table class="scm-cotizacion-budget-table"><thead><tr><th>Descripción / proveedor</th><th>Valor</th></tr></thead><tbody>';
      foreach ($items as $item) {
        $label = $this->cotizacion_first_matching_value($item, ['prove', 'descripcion', 'actividad', 'concepto', 'detalle']);
        $value = $this->cotizacion_first_matching_value($item, ['valor', 'total', 'saldo']);
        $html .= '<tr><td>' . esc_html($label !== '' ? $this->cotizacion_clean_text($label) : '-') . '</td><td>' . esc_html($this->format_cop_currency($value)) . '</td></tr>';
      }
      $html .= '</tbody></table>';
    }
    $html .= '<div class="scm-cotizacion-budget-subtotal"><span>Total ' . esc_html(strtolower($title)) . '</span><strong>' . esc_html($this->format_cop_currency($this->cotizacion_money_value($row, $totalKeys))) . '</strong></div>';
    return $html . '</div>';
  }

  /** @param array<string,mixed> $row */
  private function render_cotizacion_balance_table(array $row): string
  {
    $rows = [
      ['Materiales', ['total_materiales'], ['saldo_materiales']],
      ['Mano de obra', ['total_mano_obra'], ['saldo_obra']],
      ['Equipos / maquinarias', ['total_maquinarias'], ['saldo_maquinarias']],
      ['Otros costos', ['total_otros_costos'], ['saldo_otros_costo']],
    ];
    $html = '<div class="scm-cotizacion-table-wrap"><table class="scm-cotizacion-balance-table"><thead><tr><th>Categor&iacute;a</th><th>Presupuesto</th><th>Saldo disponible</th></tr></thead><tbody>';
    $budgetTotal = 0.0;
    $balanceTotal = 0.0;
    foreach ($rows as [$label, $budgetKeys, $balanceKeys]) {
      $budget = $this->cotizacion_money_value($row, $budgetKeys);
      $balance = $this->cotizacion_money_value($row, $balanceKeys);
      $budgetTotal += $budget;
      $balanceTotal += $balance;
      $html .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($this->format_cop_currency($budget)) . '</td><td>' . esc_html($this->format_cop_currency($balance)) . '</td></tr>';
    }
    $html .= '</tbody><tfoot><tr class="is-total"><th scope="row">Total</th><td>' . esc_html($this->format_cop_currency($budgetTotal)) . '</td><td>' . esc_html($this->format_cop_currency($balanceTotal)) . '</td></tr></tfoot>';
    return $html . '</table></div>';
  }

  /** @param array<string,mixed> $item @param array<int,string> $needles */
  private function cotizacion_first_matching_value(array $item, array $needles): string
  {
    foreach ($item as $key => $value) {
      $keyNorm = strtolower((string) $key);
      foreach ($needles as $needle) {
        if (strpos($keyNorm, $needle) !== false) {
          return $this->cotizacion_clean_text($value);
        }
      }
    }
    return '';
  }

  private function cotizacion_rich_text($value): string
  {
    $html = (string) $value;
    for ($i = 0; $i < 3; $i++) {
      $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($decoded === $html) {
        break;
      }
      $html = $decoded;
    }
    $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li>');
    $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', $html) ?: '';
    $html = trim($html);
    return $html !== '' ? $html : '<p>-</p>';
  }

  /** @param array<string,mixed> $row @param array<int,string> $keys */
  private function cotizacion_money_value(array $row, array $keys): float
  {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $row)) {
        continue;
      }
      $value = $this->parse_cop_number($row[$key]);
      if ($value !== null) {
        return $value;
      }
    }
    return 0.0;
  }

  private function parse_cop_number($value): ?float
  {
    if (is_int($value) || is_float($value)) {
      return (float) $value;
    }
    $text = trim((string) $value);
    if ($text === '' || $text === '-') {
      return null;
    }
    $text = preg_replace('/[^\d,.\-]/', '', $text) ?: '';
    if ($text === '' || $text === '-') {
      return null;
    }
    $hasComma = strpos($text, ',') !== false;
    $hasDot = strpos($text, '.') !== false;
    if ($hasComma && $hasDot) {
      $text = str_replace('.', '', $text);
      $text = str_replace(',', '.', $text);
    } elseif ($hasComma) {
      $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float) $text : null;
  }

  private function format_cop_currency($value): string
  {
    $amount = $this->parse_cop_number($value);
    if ($amount === null) {
      $amount = 0.0;
    }
    return '$' . number_format((float) $amount, 0, ',', '.');
  }

  private function render_cotizaciones_mantenimiento_pagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0) {
      return '';
    }
    $html = '<div class="scm-pagination-card card"><div class="scm-pagination-summary">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div><div class="scm-pagination-controls">';
    foreach ([1 => '&laquo;', max(1, $page - 1) => '&lsaquo;'] as $p => $label) {
      $html .= '<button type="button" class="scm-page-btn btn btn-sm btn-outline scm-page-btn-generic" data-tab="cotizaciones_mantenimiento" data-page="' . esc_attr((string) $p) . '"' . ($page <= 1 ? ' disabled' : '') . '>' . $label . '</button>';
    }
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
      $html .= '<button type="button" class="scm-page-btn btn btn-sm scm-page-btn-generic ' . ($i === $page ? 'btn-primary is-active' : 'btn-outline') . '" data-tab="cotizaciones_mantenimiento" data-page="' . esc_attr((string) $i) . '">' . esc_html((string) $i) . '</button>';
    }
    foreach ([min($totalPages, $page + 1) => '&rsaquo;', $totalPages => '&raquo;'] as $p => $label) {
      $html .= '<button type="button" class="scm-page-btn btn btn-sm btn-outline scm-page-btn-generic" data-tab="cotizaciones_mantenimiento" data-page="' . esc_attr((string) $p) . '"' . ($page >= $totalPages ? ' disabled' : '') . '>' . $label . '</button>';
    }
    return $html . '</div></div>';
  }

  /** @return array<string,string> */
  private function parse_pending_shell_filters(array $input, string $prefix): array
  {
    $clean = static fn($value): string => trim((string) ($value ?? ''));

    return [
      'mes' => $clean($input[$prefix . 'mes'] ?? ''),
      'empleado' => $clean($input[$prefix . 'id_empleado'] ?? ''),
      'tuvo' => $clean($input[$prefix . 'tuvo_preventiva'] ?? ''),
      'inmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'propietario' => $clean($input[$prefix . 'propietario'] ?? ''),
      'arrendatario' => $clean($input[$prefix . 'arrendatario'] ?? ''),
      'contrato' => $clean($input[$prefix . 'contrato'] ?? ''),
      'id_ticket' => $clean($input[$prefix . 'caso'] ?? $input[$prefix . 'ticket'] ?? $input[$prefix . 'id_ticket'] ?? ''),
    ];
  }

  private function render_pending_preventivas_shell(array $input): string
  {
    $view = new \SCM\Modules\Pending\PendingView();
    $html = $view->renderPreventivasPanel(
      $this->parse_pending_shell_filters($input, 'spp_'),
      [],
      [],
      0,
      (string) date('Y')
    );

    return str_replace(
      'No hay contratos pendientes con los filtros actuales.',
      'Abre esta pestaña para cargar preventivas pendientes.',
      $html
    );
  }

  private function render_pending_servicios_publicos_shell(array $input): string
  {
    $view = new \SCM\Modules\Pending\PendingView();
    $html = $view->renderServiciosPublicosPanel(
      $this->parse_pending_shell_filters($input, 'rsp_'),
      [],
      0,
      (string) date('Y')
    );

    return str_replace(
      'No hay contratos pendientes con los filtros actuales.',
      'Abre esta pestaña para cargar servicios publicos pendientes.',
      $html
    );
  }

  private function render_ticket_completion_acts_shell(array $input): string
  {
    $view = new \SCM\Modules\TicketCompletion\CompletionView();
    $filters = [
      'estado' => 'pending',
      'caso' => trim((string) ($input['sacta_caso'] ?? '')),
      'inmueble' => trim((string) ($input['sacta_inmueble'] ?? '')),
      'contrato' => trim((string) ($input['sacta_contrato'] ?? '')),
      'firmante' => trim((string) ($input['sacta_firmante'] ?? '')),
      'page' => 1,
      'per_page' => 30,
    ];
    if (isset($input['sacta_estado']) && is_scalar($input['sacta_estado'])) {
      $filters['estado'] = trim((string) $input['sacta_estado']) ?: 'pending';
    }

    return $view->dashboardPanel(
      $filters,
      [],
      ['pending' => 0, 'signed' => 0, 'cancelled' => 0, 'all' => 0],
      0,
      ['page' => 1, 'per_page' => 30, 'total' => 0, 'total_pages' => 1],
      new \SCM\Modules\TicketCompletion\CompletionService(new \SCM\Modules\TicketCompletion\CompletionRepository($this->db), SCM_APP_SECRET, SCM_BASE_URL),
      $this->canDeleteAnyTicketCompletionActs()
    );
  }

  /** @return array<string,mixed> */
  private function get_web_ticket_statistics(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [
        'total' => 0,
        'abiertos' => 0,
        'en_proceso' => 0,
        'cerrados' => 0,
        'por_estado_comercial' => [],
        'por_estado_administrativo' => [],
      ];
    }

    if ($this->column_exists($table, 'creador_por')) {
      $creadorExpr = "LOWER(TRIM(COALESCE(`creador_por`, '')))";
      $webWhere = "({$creadorExpr} <> '' AND {$creadorExpr} <> 'funcionario')";
    } else {
      $webWhere = '0 = 1';
    }

    $estadoExpr = $this->column_exists($table, 'estado') ? "LOWER(TRIM(COALESCE(`estado`, '')))" : "''";
    $estadoAdminExpr = $this->column_exists($table, 'estado_administrativo') ? "LOWER(TRIM(COALESCE(`estado_administrativo`, '')))" : "''";
    $closedExpr = "({$estadoExpr} IN ('cerrado', 'resuelto', 'finalizado') OR {$estadoAdminExpr} IN ('cerrado', 'resuelto', 'finalizado'))";

    $row = $this->db->getRow(
      "SELECT
        COUNT(1) AS total,
        SUM(CASE WHEN {$estadoExpr} = 'nuevo' AND NOT {$closedExpr} THEN 1 ELSE 0 END) AS abiertos,
        SUM(CASE WHEN {$estadoExpr} = 'en proceso' AND NOT {$closedExpr} THEN 1 ELSE 0 END) AS en_proceso,
        SUM(CASE WHEN {$closedExpr} THEN 1 ELSE 0 END) AS cerrados
       FROM `{$table}`
       WHERE {$webWhere}"
    ) ?: [];

    $byCommercial = [];
    if ($this->column_exists($table, 'estado_comercial')) {
      $rows = $this->db->getResults(
        "SELECT TRIM(COALESCE(`estado_comercial`, 'Sin estado')) AS label, COUNT(1) AS total
         FROM `{$table}`
         WHERE {$webWhere}
         GROUP BY TRIM(COALESCE(`estado_comercial`, 'Sin estado'))
         ORDER BY total DESC, label ASC"
      );
      foreach ($rows as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        $byCommercial[$label !== '' ? $label : 'Sin estado'] = (int) ($item['total'] ?? 0);
      }
    }

    $byAdmin = [];
    if ($this->column_exists($table, 'estado_administrativo')) {
      $rows = $this->db->getResults(
        "SELECT TRIM(COALESCE(`estado_administrativo`, 'Sin estado')) AS label, COUNT(1) AS total
         FROM `{$table}`
         WHERE {$webWhere}
         GROUP BY TRIM(COALESCE(`estado_administrativo`, 'Sin estado'))
         ORDER BY total DESC, label ASC"
      );
      foreach ($rows as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        $byAdmin[$label !== '' ? $label : 'Sin estado'] = (int) ($item['total'] ?? 0);
      }
    }

    return [
      'total' => (int) ($row['total'] ?? 0),
      'abiertos' => (int) ($row['abiertos'] ?? 0),
      'en_proceso' => (int) ($row['en_proceso'] ?? 0),
      'cerrados' => (int) ($row['cerrados'] ?? 0),
      'por_estado_comercial' => $byCommercial,
      'por_estado_administrativo' => $byAdmin,
    ];
  }
}
