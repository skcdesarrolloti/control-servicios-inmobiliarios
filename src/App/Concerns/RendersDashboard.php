<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;

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
    $filterOptions = $module->getFilterOptions();
    $calendarAllowedFuncionarios = $this->get_calendar_allowed_funcionarios();
    $config['calendar_allowed_cargos'] = ['3', '4', '5', '7', '8', '11', '12', '14', '18'];
    $config['calendar_allowed_funcionarios'] = $calendarAllowedFuncionarios;
    $config['calendar_allowed_employee_ids'] = array_values(array_filter(array_map(static function ($row): string {
      return trim((string) ($row['id_empleado'] ?? ''));
    }, $calendarAllowedFuncionarios)));
    $params = $module->parseParams($_GET);
    $result = $module->run($params, $config);
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $tbodyHtml = (string)($result['tbody'] ?? '');
    $paginationHtml = (string)($result['pagination_html'] ?? '');

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
      $hydrateGenericRows = $initialOpenTopic !== '' && $initialOpenTopic === $gTabKey;
      $gResult = $this->run_query_generic($gTabDef['temas'], $gParams, $config, $hydrateGenericRows);
      $genericResults[$gTabKey] = ['params' => $gParams, 'result' => $gResult, 'def' => $gTabDef, 'loaded' => $hydrateGenericRows];
    }

    $statusBucketDefs = $this->get_status_bucket_definitions();
    $statusTopicDefs = $this->get_status_topic_definitions();
    $statusResults = $this->build_status_bucket_results($statusBucketDefs, $statusTopicDefs, $config, $filterOptions, false);
    $currentEmployeeId = $this->current_employee_id();
    $config['calendar_current_employee_id'] = $currentEmployeeId;
    $myTicketsParams = $module->parseParams($_GET, 'scm_my_');
    $myTicketsParams['fEmpleado'] = $currentEmployeeId !== '' ? $currentEmployeeId : '__sin_funcionario__';
    $myTicketsParams['_scmEmpleadoExact'] = '1';
    $myTicketsResult = ['tbody' => $this->render_lazy_tickets_placeholder('Abre esta pestaña para cargar tus tickets.'), 'pagination_html' => ''];
    $myTicketsStats = ['total' => 0];
    $cotizacionesParams = $this->parse_cotizaciones_mantenimiento_params($_GET, 'scmqt_');
    $cotizacionesResult = [
      'rows' => [],
      'stats' => ['total' => 0, 'enviadas' => 0, 'no_enviadas' => 0, 'aprobadas' => 0, 'desaprobadas' => 0],
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
    $preventivasPendientesHtml = $pendingController->renderPreventivasTab($_GET);
    $serviciosPublicosPendientesHtml = $pendingController->renderServiciosPublicosTab($_GET);
    $reportesAdministrativosPendientesHtml = $pendingController->renderReportesAdministrativosTab();
    $contratosArrendamientoHtml = $pendingController->renderContratosArrendamientoTab();
    $dashboardPermissionTabs = $this->dashboardPermissionTabs();
    $tabMap = [
      'abiertos' => 'scm-panel-abiertos',
      'abierto' => 'scm-panel-abiertos',
      'scm-panel-abiertos' => 'scm-panel-abiertos',
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
      'calendario_actividades' => 'scm-panel-calendario-actividades',
      'calendario-actividades' => 'scm-panel-calendario-actividades',
      'calendario' => 'scm-panel-calendario-actividades',
      'agenda' => 'scm-panel-calendario-actividades',
      'scm-panel-calendario-actividades' => 'scm-panel-calendario-actividades',
      'notificaciones' => 'scm-panel-admin-notificaciones',
      'notificaciones-administrativas' => 'scm-panel-admin-notificaciones',
      'notificaciones_administrativas' => 'scm-panel-admin-notificaciones',
      'scm-panel-admin-notificaciones' => 'scm-panel-admin-notificaciones',
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
      'cotizaciones_mantenimiento' => [
        'panel' => 'scm-panel-cotizaciones-mantenimiento',
        'label' => $dashboardPermissionTabs['cotizaciones_mantenimiento'] ?? 'Cotizaciones de Mantenimiento',
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
    $webTicketStats = $this->get_web_ticket_statistics();
    $dashboardAllowedTabs = $this->currentDashboardAllowedTabs();
    $dashboardPermissionConfig = $this->dashboardPermissionsConfig();
    $canManageDashboardPermissions = $this->canManageDashboardPermissions();
    $canManagePublicPqrSettings = $this->canManagePublicPqrSettings();
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
      foreach ($dashboardPanelToTab as $panelId => $permissionKey) {
        if ($permissionKey === 'actividades_administrativas') {
          if ($canAccessAdministrativeActivities) {
            $initialTab = $panelId;
            break;
          }
          continue;
        }
        if (in_array($permissionKey, $dashboardAllowedTabs, true)) {
          $initialTab = $panelId;
          break;
        }
      }
    }
    if ($initialAdministrativeActivityKey === '' || !in_array($initialAdministrativeActivityKey, $allowedAdministrativeActivityTabs, true)) {
      $initialAdministrativeActivityKey = (string) ($allowedAdministrativeActivityTabs[0] ?? '');
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
        'delete_cotizacion' => self::AJAX_DELETE_COTIZACION,
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
        'reportes_administrativos_pendientes' => self::AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES,
        'contratos_arrendamiento' => self::AJAX_CONTRATOS_ARRENDAMIENTO,
        'contrato_recibido' => self::AJAX_CONTRATO_RECIBIDO,
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
        'dashboard_permissions_read' => self::AJAX_DASHBOARD_PERMISSIONS_READ,
        'dashboard_permissions_save' => self::AJAX_DASHBOARD_PERMISSIONS_SAVE,
        'calendar_cita_notify' => self::AJAX_CALENDAR_CITA_NOTIFY,
        'admin_notifications_recipients' => self::AJAX_ADMIN_NOTIFICATIONS_RECIPIENTS,
        'admin_notifications_send' => self::AJAX_ADMIN_NOTIFICATIONS_SEND,
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
        'cargos' => $this->getDashboardCargoOptions(),
        'permissions' => $dashboardPermissionConfig,
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
    $dashboardRuntimeJsRel = 'assets/js/admin-dashboard-runtime.js';
    $guideJsRel = 'assets/js/admin-guide.js';
    $damageCssRel = 'assets/css/scm-damage-magnitude.css';
    $damageJsRel  = 'assets/js/scm-damage-magnitude.js';
    $cssPath = $assetBasePath . $cssRel;
    $jsPath  = $assetBasePath . $jsRel;
    $dashboardRuntimeJsPath = $assetBasePath . $dashboardRuntimeJsRel;
    $guideJsPath = $assetBasePath . $guideJsRel;
    $damageCssPath = $assetBasePath . $damageCssRel;
    $damageJsPath  = $assetBasePath . $damageJsRel;
    $cssVer = file_exists($cssPath) ? (string)filemtime($cssPath) : SCM_VERSION;
    $jsVer  = file_exists($jsPath)  ? (string)filemtime($jsPath)  : SCM_VERSION;
    $dashboardRuntimeJsVer = file_exists($dashboardRuntimeJsPath) ? (string)filemtime($dashboardRuntimeJsPath) : SCM_VERSION;
    $guideJsVer = file_exists($guideJsPath) ? (string)filemtime($guideJsPath) : SCM_VERSION;
    $damageCssVer = file_exists($damageCssPath) ? (string)filemtime($damageCssPath) : SCM_VERSION;
    $damageJsVer  = file_exists($damageJsPath)  ? (string)filemtime($damageJsPath)  : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $jsUrl  = self::h($assetBaseUrl . $jsRel  . '?v=' . rawurlencode($jsVer));
    $dashboardRuntimeJsUrl = self::h($assetBaseUrl . $dashboardRuntimeJsRel . '?v=' . rawurlencode($dashboardRuntimeJsVer));
    $guideJsUrl = self::h($assetBaseUrl . $guideJsRel . '?v=' . rawurlencode($guideJsVer));
    $damageCssUrl = self::h($assetBaseUrl . $damageCssRel . '?v=' . rawurlencode($damageCssVer));
    $damageJsUrl  = self::h($assetBaseUrl . $damageJsRel  . '?v=' . rawurlencode($damageJsVer));

    ob_start();
  ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" media="all">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" media="all">
    <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
    <link rel="stylesheet" href="<?php echo $damageCssUrl; ?>" media="all">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy" data-scm-runtime="<?php echo self::h((string)$runtimeJson); ?>">
      <div class="scm-guide-bar">
        <?php if ($canManageDashboardPermissions): ?>
          <button class="scm-guide-btn scm-permissions-btn" type="button" id="scm-open-permissions">Configurar permisos</button>
        <?php endif; ?>
        <?php if ($canManagePublicPqrSettings): ?>
          <button class="scm-guide-btn scm-pqr-settings-shortcut" type="button" id="scm-open-pqr-settings">Configurar notificaciones</button>
        <?php endif; ?>
        <button class="scm-guide-btn" type="button" id="scm-open-guide"><i class="fas fa-book-open"></i> Ver gu&iacute;as</button>
      </div>
      <div class="scm-tabs scm-main-tabs">
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

      <?php $activeOpenTopic = isset($openTopicDefs[$initialOpenTopic]) ? $initialOpenTopic : 'mant'; ?>
      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-abiertos' ? ' active' : ''; ?>" id="scm-panel-abiertos" data-permission-tab="abiertos">
        <div class="scm-status-bucket scm-open-bucket" data-open-bucket="abiertos">
          <div class="scm-status-subtabs scm-open-subtabs" role="tablist" aria-label="Tickets abiertos">
            <?php foreach ($openTopicDefs as $openTopicKey => $openTopicDef): ?>
              <button class="scm-status-topic-tab scm-open-topic-tab<?php echo $activeOpenTopic === $openTopicKey ? ' active' : ''; ?>" type="button" data-open-target="<?php echo esc_attr($openTopicKey); ?>"><?php echo esc_html((string)($openTopicDef['label'] ?? $openTopicKey)); ?></button>
            <?php endforeach; ?>
          </div>

      <div class="scm-open-topic-panel<?php echo $activeOpenTopic === 'mant' ? ' active' : ''; ?>" id="scm-panel-mant" data-open-topic="mant" data-scm-loaded="1">
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
        $gFilterOptions = $this->get_generic_tickets_module()->get_generic_filter_options($gTabData['def']['temas']);
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
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'notificaciones' ? ' active' : ''; ?>" id="scm-panel-admin-notificaciones" data-permission-tab="notificaciones" data-admin-activity-panel="notificaciones">
              <?php echo $this->render_admin_notifications_panel(); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('cotizaciones_mantenimiento', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'cotizaciones_mantenimiento' ? ' active' : ''; ?>" id="scm-panel-cotizaciones-mantenimiento" data-permission-tab="cotizaciones_mantenimiento" data-admin-activity-panel="cotizaciones_mantenimiento">
              <?php echo $this->render_cotizaciones_mantenimiento_panel($cotizacionesResult, $cotizacionesParams); ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('preventivas_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'preventivas_pendientes' ? ' active' : ''; ?>" id="scm-panel-preventivas-pendientes" data-permission-tab="preventivas_pendientes" data-admin-activity-panel="preventivas_pendientes">
              <?php echo $preventivasPendientesHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('servicios_publicos_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'servicios_publicos_pendientes' ? ' active' : ''; ?>" id="scm-panel-servicios-publicos-pendientes" data-permission-tab="servicios_publicos_pendientes" data-admin-activity-panel="servicios_publicos_pendientes">
              <?php echo $serviciosPublicosPendientesHtml; ?>
            </div>
          <?php endif; ?>

          <?php if (in_array('reportes_administrativos_pendientes', $allowedAdministrativeActivityTabs, true)): ?>
            <div class="scm-admin-activity-panel<?php echo $initialAdministrativeActivityKey === 'reportes_administrativos_pendientes' ? ' active' : ''; ?>" id="scm-panel-reportes-administrativos-pendientes" data-permission-tab="reportes_administrativos_pendientes" data-admin-activity-panel="reportes_administrativos_pendientes">
              <?php echo $reportesAdministrativosPendientesHtml; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/admin-dashboard-inline.js?v=' . SCM_VERSION); ?>" defer></script>

      <div class="scm-tab-panel<?php echo $initialTab === 'scm-panel-metricas' ? ' active' : ''; ?>" id="scm-panel-metricas" data-permission-tab="metricas" data-scm-metrics="<?php echo self::h((string)$metricsJson); ?>">
        <div class="scm-header scm-header-metricas">
          <div>
            <h2>Metricas Operativas</h2>
            <p>Visualizacion consolidada del Control de Servicios Inmobiliarios.</p>
          </div>
          <div class="scm-header-counter-wrap"><span class="scm-header-counter" id="scm-metrics-total"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></span></div>
        </div>
        <div class="scm-tabs scm-metric-tabs" id="scm-metric-tabs">
          <button class="scm-tab active" type="button" data-scm-metric-cat="mantenimiento">Mantenimiento</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="entrega">Entrega</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="preventiva">Preventiva</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="recibo">Recibo</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contable">Contable</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="certificaciones">Certificaciones</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contractual">Contractual</button>
          <button class="scm-tab" type="button" data-scm-metric-panel="guardian">Solicitudes Guardian</button>
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
      </div>

      <?php echo \SCM\Views\GuideModalView::render(); ?>
      <?php if ($canManageDashboardPermissions): ?>
        <?php echo $this->renderDashboardPermissionsModal($dashboardPermissionTabs, $this->getDashboardCargoOptions(), $dashboardPermissionConfig); ?>
      <?php endif; ?>
      <?php if ($canManagePublicPqrSettings): ?>
        <?php echo $this->renderDashboardPublicPqrSettingsModal(); ?>
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

  /** @param array<string,string> $tabs @param array<int,array<string,string>> $cargos @param array<string,array<int,string>> $permissions */
  private function renderDashboardPermissionsModal(array $tabs, array $cargos, array $permissions): string
  {
    $activityPermissionKeys = [
      'cotizaciones_mantenimiento',
      'calendario_actividades',
      'notificaciones',
      'preventivas_pendientes',
      'servicios_publicos_pendientes',
      'reportes_administrativos_pendientes',
    ];
    $mainPermissionTabs = array_diff_key($tabs, array_flip($activityPermissionKeys));
    $activityPermissionTabs = array_intersect_key($tabs, array_flip($activityPermissionKeys));
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

    return [
      'fPage' => (string) $page,
      'fPerPage' => (string) $perPage,
      'fCotizacion' => $clean($input[$prefix . 'cotizacion'] ?? ''),
      'fTicket' => $clean($input[$prefix . 'ticket'] ?? ''),
      'fFecha' => $singleDate,
      'fFechaDesde' => $dateFrom,
      'fFechaHasta' => $dateTo,
      'fDestinatario' => $clean($input[$prefix . 'destinatario'] ?? ''),
      'fFuncionario' => $clean($input[$prefix . 'funcionario'] ?? ''),
      'fInmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'fContrato' => $clean($input[$prefix . 'contrato'] ?? ''),
      'fEnviada' => $clean($input[$prefix . 'enviada'] ?? ''),
      'fEstado' => $clean($input[$prefix . 'estado'] ?? ''),
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

    $like('_ID', (string) ($p['fCotizacion'] ?? ''));
    $like('id_ticket', (string) ($p['fTicket'] ?? ''));
    $like('destinatario', (string) ($p['fDestinatario'] ?? ''));
    $like('id_empleado', (string) ($p['fFuncionario'] ?? ''));
    if (($p['fInmueble'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $p['fInmueble']) . '%';
      $where[] = "(COALESCE(c.`inmueble`, '') LIKE ? OR COALESCE(c.`id_inmueble`, '') LIKE ?)";
      $args[] = $term;
      $args[] = $term;
    }
    if (($p['fContrato'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $p['fContrato']) . '%';
      $where[] = "(COALESCE(c.`contrato`, '') LIKE ? OR COALESCE(c.`id_contrato`, '') LIKE ?)";
      $args[] = $term;
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

    if (($p['fEstado'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(c.`estado`, ''))) = ?";
      $args[] = strtolower(trim((string) $p['fEstado']));
    }
    if (($p['fTipoMantenimiento'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(c.`tipo_mantenimiento`, ''))) = ?";
      $args[] = strtolower(trim((string) $p['fTipoMantenimiento']));
    }
    if (($p['fCategoria'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(c.`categoria_cotizacion`, ''))) = ?";
      $args[] = strtolower(trim((string) $p['fCategoria']));
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
      "SELECT `_ID`, `id_cotizacion`, `id_ticket`, `estado`, `proveedor`, `valor`, `actividad`, `fecha`
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

  /** @return array<string,int> */
  private function aggregate_cotizaciones_mantenimiento(string $whereSql, array $args): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $row = $this->db->getRow(
      "SELECT
        COUNT(1) AS total,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado') THEN 1 ELSE 0 END) AS enviadas,
        SUM(CASE WHEN TRIM(COALESCE(c.`se_envio`, '')) = '' OR LOWER(TRIM(COALESCE(c.`se_envio`, ''))) IN ('no', '0', 'false') THEN 1 ELSE 0 END) AS no_enviadas,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'aprobada' THEN 1 ELSE 0 END) AS aprobadas,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'desaprobada' THEN 1 ELSE 0 END) AS desaprobadas
       FROM `{$table}` c
       WHERE {$whereSql}",
      $args
    ) ?: [];

    return [
      'total' => (int) ($row['total'] ?? 0),
      'enviadas' => (int) ($row['enviadas'] ?? 0),
      'no_enviadas' => (int) ($row['no_enviadas'] ?? 0),
      'aprobadas' => (int) ($row['aprobadas'] ?? 0),
      'desaprobadas' => (int) ($row['desaprobadas'] ?? 0),
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
    $allowedCargos = is_array($config['calendar_allowed_cargos'] ?? null) ? $config['calendar_allowed_cargos'] : ['3', '4', '5', '7', '8', '11', '12', '14', '18'];
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
    $allowedCargos = ['3', '4', '5', '7', '8', '11', '12', '14', '18'];
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($table) || !$this->column_exists($table, 'id_empleado') || !$this->column_exists($table, 'id_cargo')) {
      return [];
    }
    $nameColumn = $this->detect_first_existing_column($table, ['nombre', 'empleado', 'nombre_funcionario']);
    $roleColumn = $this->column_exists($table, 'rol') ? 'rol' : '';
    $nameSelect = $nameColumn !== '' ? "TRIM(COALESCE(`{$nameColumn}`, ''))" : "TRIM(COALESCE(`id_empleado`, ''))";
    $roleSelect = $roleColumn !== '' ? "TRIM(COALESCE(`{$roleColumn}`, ''))" : "''";
    $placeholders = implode(',', array_fill(0, count($allowedCargos), '?'));
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              {$nameSelect} AS nombre,
              {$roleSelect} AS rol,
              TRIM(COALESCE(`id_cargo`, '')) AS id_cargo
         FROM `{$table}`
        WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''
          AND TRIM(COALESCE(`id_cargo`, '')) IN ({$placeholders})
        ORDER BY {$nameSelect} ASC",
      $allowedCargos
    );
    $out = [];
    foreach ($rows as $row) {
      $employeeId = trim((string) ($row['id_empleado'] ?? ''));
      if ($employeeId === '') {
        continue;
      }
      $out[] = [
        'id_empleado' => $employeeId,
        'nombre' => trim((string) ($row['nombre'] ?? $employeeId)),
        'rol' => trim((string) ($row['rol'] ?? '')),
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
    $messageGuides = $this->admin_notification_message_guides();
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

      <section class="scm-admin-notif-card scm-admin-notif-filter-card">
        <form data-admin-notif-search autocomplete="off">
          <div class="scm-admin-notif-filter-grid">
            <div class="scm-field">
              <label for="scm-admin-notif-type">Destinatarios</label>
              <select id="scm-admin-notif-type" name="type" class="select select-bordered select-sm scm-select" data-admin-notif-type>
                <?php foreach ($types as $typeKey => $typeDef): ?>
                  <option value="<?php echo esc_attr((string) $typeKey); ?>"><?php echo esc_html((string) ($typeDef['label'] ?? $typeKey)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="scm-field scm-admin-notif-search-field">
              <label for="scm-admin-notif-q">Buscar</label>
              <input id="scm-admin-notif-q" name="q" type="search" class="input input-bordered input-sm scm-input" placeholder="Nombre, correo, celular, documento..." data-admin-notif-query>
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
              <button type="button" class="scm-case-work-btn" data-admin-notif-open-filtered>Enviar a todos los filtrados</button>
              <button type="button" class="scm-btn-primary btn btn-primary" data-admin-notif-open-composer>Enviar notificaci&oacute;n</button>
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
              <p>Prepara el mensaje, elige canales y revisa la vista previa antes de encolar. Si hay texto escrito, el cierre pide confirmaci&oacute;n.</p>
            </div>
            <button type="button" class="scm-modal-close" data-admin-notif-close-composer aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <form data-admin-notif-send autocomplete="off">
            <input type="hidden" name="type" value="<?php echo esc_attr($firstType); ?>" data-admin-notif-send-type>
            <input type="hidden" name="q" value="" data-admin-notif-send-query>
            <input type="hidden" name="contract_status" value="" data-admin-notif-send-contract-status>
            <input type="hidden" name="inmueble_simi" value="" data-admin-notif-send-inmueble-simi>
            <input type="hidden" name="contract_number" value="" data-admin-notif-send-contract-number>

            <div class="scm-admin-notif-section-head scm-admin-notif-selected-head">
              <div>
                <span class="scm-calendar-action-kicker">Destinatarios</span>
                <h4><strong data-admin-notif-selected-count>0</strong> seleccionados</h4>
                <p>Escoge canales, plantilla y revisa la vista previa antes de encolar.</p>
              </div>
              <button type="button" class="scm-admin-notif-send-filtered" data-admin-notif-send-filtered>
                Enviar a todos los filtrados
              </button>
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
              <span>Enviar a todos los resultados filtrados</span>
            </label>

            <div class="scm-admin-notif-template-card" data-admin-notif-email-template-wrap>
              <div class="scm-admin-notif-template-row">
                <div class="scm-field">
                  <label for="scm-admin-notif-email-template">Plantilla Email</label>
                  <select id="scm-admin-notif-email-template" name="email_template" class="select select-bordered select-sm scm-select" data-admin-notif-email-template>
                    <?php foreach ($emailTemplates as $template): ?>
                      <option value="<?php echo esc_attr((string) ($template['name'] ?? '')); ?>"
                        data-subject="<?php echo esc_attr((string) ($template['subject'] ?? '')); ?>"
                        data-message="<?php echo esc_attr((string) ($template['editable_message'] ?? $template['body'] ?? '')); ?>"
                        data-preview-template="<?php echo esc_attr((string) ($template['body'] ?? '')); ?>"
                        data-message-only="<?php echo !empty($template['message_only']) ? '1' : '0'; ?>"
                        data-template-html="<?php echo !empty($template['is_html']) ? '1' : '0'; ?>"
                        data-template-full="<?php echo !empty($template['is_full_document']) ? '1' : '0'; ?>">
                        <?php echo esc_html((string) ($template['label'] ?? $template['name'] ?? 'Plantilla')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="button" class="scm-case-work-btn" data-admin-notif-apply-email-template>Usar plantilla</button>
              </div>
              <?php foreach ($emailTemplates as $template): ?>
                <div class="scm-admin-notif-template-guide" data-admin-notif-email-guide="<?php echo esc_attr((string) ($template['name'] ?? '')); ?>">
                  <strong><?php echo esc_html((string) ($template['label'] ?? $template['name'] ?? 'Plantilla')); ?></strong>
                  <span>Origen: <?php echo esc_html((string) ($template['source'] ?? 'sistema')); ?></span>
                  <span>Asunto: <?php echo esc_html((string) ($template['subject'] ?? '')); ?></span>
                  <span>Tipo: <?php echo !empty($template['is_full_document']) ? 'HTML completo con diseno propio' : (!empty($template['message_only']) ? 'Base editable con {{mensaje}}' : 'Contenido editable'); ?></span>
                  <div class="scm-admin-notif-template-summary"><?php echo esc_html((string) ($template['preview_excerpt'] ?? 'Revisa la vista previa para confirmar el diseno final.')); ?></div>
                  <small><?php echo esc_html((string) ($template['description'] ?? '')); ?></small>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="scm-field" data-admin-notif-subject-wrap>
              <label for="scm-admin-notif-subject">Asunto para Email</label>
              <input id="scm-admin-notif-subject" name="subject" type="text" class="input input-bordered input-sm scm-input" placeholder="Ej: Informaci&oacute;n importante" value="<?php echo esc_attr($defaultEmailSubject); ?>" data-admin-notif-subject>
            </div>

            <div class="scm-field scm-admin-notif-message-field">
              <label for="scm-admin-notif-message">Mensaje</label>
              <div class="scm-admin-notif-message-toolbar" aria-label="Herramientas del mensaje">
                <button type="button" data-admin-notif-insert-var="{{nombre}}">{{nombre}}</button>
                <button type="button" data-admin-notif-insert-var="{{correo}}">{{correo}}</button>
                <button type="button" data-admin-notif-insert-var="{{celular}}">{{celular}}</button>
                <button type="button" data-admin-notif-insert-var="{{tipo_actor}}">{{tipo_actor}}</button>
                <button type="button" data-admin-notif-insert-var="{{firma_funcionario}}">Firma</button>
                <button type="button" data-admin-notif-preview-toggle>Ocultar vista previa</button>
                <button type="button" data-admin-notif-copy-message>Copiar</button>
                <button type="button" data-admin-notif-clear-message>Limpiar</button>
              </div>
              <section class="scm-admin-notif-guides" aria-label="Guias de mensajes">
                <div class="scm-admin-notif-guides-head">
                  <div>
                    <span class="scm-calendar-action-kicker">Gu&iacute;as copiables</span>
                    <strong>Ejemplos por actor</strong>
                    <p>Usa estas bases para Email, SMS o WhatsApp. En WhatsApp se env&iacute;an dentro de la plantilla gen&eacute;rica oficial.</p>
                  </div>
                  <small>Variables permitidas: {{nombre}}, {{funcionario}}, {{firma_funcionario_linea}}</small>
                </div>
                <div class="scm-admin-notif-guide-tabs" role="tablist" aria-label="Actores">
                  <?php $guideIndex = 0; foreach ($messageGuides as $actorKey => $actorGuide): ?>
                    <button type="button" class="<?php echo $guideIndex === 0 ? 'active' : ''; ?>" data-admin-notif-guide-tab="<?php echo esc_attr($actorKey); ?>" role="tab" aria-selected="<?php echo $guideIndex === 0 ? 'true' : 'false'; ?>">
                      <?php echo esc_html((string) ($actorGuide['label'] ?? $actorKey)); ?>
                    </button>
                  <?php $guideIndex++; endforeach; ?>
                </div>
                <?php $guideIndex = 0; foreach ($messageGuides as $actorKey => $actorGuide): ?>
                  <div class="scm-admin-notif-guide-panel<?php echo $guideIndex === 0 ? ' active' : ''; ?>" data-admin-notif-guide-panel="<?php echo esc_attr($actorKey); ?>">
                    <?php foreach ((array) ($actorGuide['items'] ?? []) as $item): $guideText = (string) ($item['text'] ?? ''); ?>
                      <article class="scm-admin-notif-guide-card">
                        <div>
                          <strong><?php echo esc_html((string) ($item['title'] ?? 'Mensaje sugerido')); ?></strong>
                          <p><?php echo nl2br(esc_html($guideText)); ?></p>
                        </div>
                        <div class="scm-admin-notif-guide-actions">
                          <button type="button" class="scm-case-work-btn" data-admin-notif-guide-use="<?php echo esc_attr($guideText); ?>">Usar mensaje</button>
                          <button type="button" class="scm-case-work-btn" data-admin-notif-guide-copy="<?php echo esc_attr($guideText); ?>">Copiar</button>
                        </div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php $guideIndex++; endforeach; ?>
              </section>
              <textarea id="scm-admin-notif-message" name="message" class="textarea textarea-bordered scm-textarea" rows="8" placeholder="Escribe el mensaje..." data-admin-notif-message></textarea>
              <small class="scm-admin-notif-helper">Variables: {{nombre}}, {{correo}}, {{celular}}, {{tipo_actor}}, {{rol_persona}}, {{funcionario}}, {{cargo_funcionario}}, {{celular_funcionario}}, {{firma_funcionario}}, {{firma_funcionario_linea}}.</small>
              <small class="scm-admin-notif-sms-counter" data-admin-notif-sms-counter>0/160 SMS</small>
              <div class="scm-admin-notif-preview" data-admin-notif-preview></div>
            </div>

            <div class="scm-admin-notif-template-card" data-admin-notif-whatsapp-template-wrap>
              <div class="scm-field">
                <label for="scm-admin-notif-whatsapp-template">Plantilla WhatsApp oficial</label>
                <select id="scm-admin-notif-whatsapp-template" name="whatsapp_template" class="select select-bordered select-sm scm-select" data-admin-notif-whatsapp-template>
                  <?php foreach ($whatsappTemplates as $template): ?>
                    <option value="<?php echo esc_attr((string) ($template['name'] ?? '')); ?>">
                      <?php echo esc_html((string) ($template['label'] ?? $template['name'] ?? 'Plantilla')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php foreach ($whatsappTemplates as $template): ?>
                <div class="scm-admin-notif-template-guide" data-admin-notif-template-guide="<?php echo esc_attr((string) ($template['name'] ?? '')); ?>">
                  <strong><?php echo esc_html((string) ($template['name'] ?? '')); ?></strong>
                  <span>Idioma: <?php echo esc_html((string) ($template['language'] ?? 'es_CO')); ?></span>
                  <p><?php echo nl2br(esc_html((string) ($template['body'] ?? ''))); ?></p>
                  <small>Este selector solo muestra las plantillas WhatsApp configuradas para este m&oacute;dulo.</small>
                  <small>Crea esta plantilla en Meta como Utilidad/Utility con el cuerpo exacto mostrado arriba.</small>
                  <small>Variables en Meta: {{1}} = nombre del destinatario · {{2}} = mensaje escrito arriba · {{3}} = Nombre - Cargo - Celular del funcionario.</small>
                  <small>No agregues botones a esta plantilla. La firma del funcionario queda dentro del cuerpo del mensaje.</small>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="scm-admin-notif-submit-row">
              <span class="scm-spinner" data-admin-notif-spinner><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
              <button type="submit" class="scm-btn-primary btn btn-primary" data-admin-notif-submit>Encolar notificaci&oacute;n</button>
            </div>
            <p class="scm-admin-notif-result" data-admin-notif-result aria-live="polite"></p>
          </form>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
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
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    return '<span id="scm-cotizaciones_mantenimiento-count" style="display:none;">' . esc_html((string) ($stats['total'] ?? 0)) . '</span>'
      . '<div class="scm-status-topic-head"><div><h3>Cotizaciones de Mantenimiento</h3><p>Control de envio, respuesta, ordenes y acciones de cotizacion.</p></div><span class="scm-status-count"><strong id="scm-cotizaciones_mantenimiento-kpi-total">' . esc_html((string) ($stats['total'] ?? 0)) . '</strong> cotizaciones</span></div>'
      . '<div class="scm-kpis scm-kpis-daisy scm-cotizaciones-kpis"><div class="scm-kpi"><div class="scm-kpi-label">Enviadas</div><div class="scm-kpi-value" id="scm-cotizaciones_mantenimiento-kpi-enviadas">' . esc_html((string) ($stats['enviadas'] ?? 0)) . '</div></div><div class="scm-kpi"><div class="scm-kpi-label">No enviadas</div><div class="scm-kpi-value" id="scm-cotizaciones_mantenimiento-kpi-no-enviadas">' . esc_html((string) ($stats['no_enviadas'] ?? 0)) . '</div></div><div class="scm-kpi"><div class="scm-kpi-label">Aprobadas</div><div class="scm-kpi-value" id="scm-cotizaciones_mantenimiento-kpi-aprobadas">' . esc_html((string) ($stats['aprobadas'] ?? 0)) . '</div></div><div class="scm-kpi"><div class="scm-kpi-label">Desaprobadas</div><div class="scm-kpi-value" id="scm-cotizaciones_mantenimiento-kpi-desaprobadas">' . esc_html((string) ($stats['desaprobadas'] ?? 0)) . '</div></div></div>'
      . $this->render_cotizaciones_mantenimiento_filter_form($params)
      . '<div class="scm-cotizaciones-list" id="scm-cards-cotizaciones_mantenimiento">' . $this->render_cotizaciones_mantenimiento_cards($rows) . '</div>'
      . '<div class="scm-pagination" id="scm-pagination-cotizaciones_mantenimiento">' . $this->render_cotizaciones_mantenimiento_pagination($pagination) . '</div>';
  }

  private function render_cotizaciones_mantenimiento_filter_form(array $p): string
  {
    ob_start();
?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form id="scm-form-cotizaciones_mantenimiento" autocomplete="off">
        <input type="hidden" id="scmqt_page" name="scmqt_page" value="<?php echo esc_attr((string) ($p['fPage'] ?? '1')); ?>">
        <div class="scm-grid">
          <div class="scm-field"><label for="scmqt_cotizacion">Cotizaci&oacute;n</label><input id="scmqt_cotizacion" name="scmqt_cotizacion" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fCotizacion'] ?? '')); ?>" placeholder="Ej: 528"></div>
          <div class="scm-field"><label for="scmqt_ticket">Ticket</label><input id="scmqt_ticket" name="scmqt_ticket" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fTicket'] ?? '')); ?>" placeholder="Ej: 10055"></div>
          <div class="scm-field scm-date-range-field"><label id="scmqt_fecha_rango_label">Fecha</label><div class="scm-date-range-control" role="group" aria-labelledby="scmqt_fecha_rango_label"><div class="scm-date-range-part"><span>Desde</span><input id="scmqt_fecha_desde" name="scmqt_fecha_desde" type="date" value="<?php echo esc_attr((string) ($p['fFechaDesde'] ?? '')); ?>" aria-label="Fecha desde"></div><span class="scm-date-range-separator" aria-hidden="true">&rarr;</span><div class="scm-date-range-part"><span>Hasta</span><input id="scmqt_fecha_hasta" name="scmqt_fecha_hasta" type="date" value="<?php echo esc_attr((string) ($p['fFechaHasta'] ?? '')); ?>" aria-label="Fecha hasta"></div></div></div>
          <div class="scm-field"><label for="scmqt_destinatario">Destinatario</label><input id="scmqt_destinatario" name="scmqt_destinatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fDestinatario'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_funcionario">Funcionario</label><input id="scmqt_funcionario" name="scmqt_funcionario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fFuncionario'] ?? '')); ?>" placeholder="ID empleado"></div>
          <div class="scm-field"><label for="scmqt_inmueble">Inmueble SIMI</label><input id="scmqt_inmueble" name="scmqt_inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fInmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_contrato">Contrato</label><input id="scmqt_contrato" name="scmqt_contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string) ($p['fContrato'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="scmqt_enviada">Fue enviada</label><select id="scmqt_enviada" name="scmqt_enviada" class="select select-bordered select-sm scm-select"><option value="">Todas</option><option value="si" <?php selected((string) ($p['fEnviada'] ?? ''), 'si'); ?>>S&iacute;</option><option value="no" <?php selected((string) ($p['fEnviada'] ?? ''), 'no'); ?>>No</option></select></div>
          <div class="scm-field"><label for="scmqt_estado">Estado</label><select id="scmqt_estado" name="scmqt_estado" class="select select-bordered select-sm scm-select"><option value="">Todos</option><?php foreach ($this->cotizaciones_distinct_options('estado') as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected((string) ($p['fEstado'] ?? ''), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?></select></div>
          <div class="scm-field"><label for="scmqt_tipo_mantenimiento">Tipo mantenimiento</label><select id="scmqt_tipo_mantenimiento" name="scmqt_tipo_mantenimiento" class="select select-bordered select-sm scm-select"><option value="">Todos</option><?php foreach ($this->cotizaciones_distinct_options('tipo_mantenimiento') as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected((string) ($p['fTipoMantenimiento'] ?? ''), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?></select></div>
          <div class="scm-field"><label for="scmqt_categoria">Categoria</label><select id="scmqt_categoria" name="scmqt_categoria" class="select select-bordered select-sm scm-select"><option value="">Todas</option><?php foreach ($this->cotizaciones_distinct_options('categoria_cotizacion') as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected((string) ($p['fCategoria'] ?? ''), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="scm-actions"><button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button><button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear-cotizaciones_mantenimiento">Limpiar</button><span class="scm-spinner" id="scm-spinner-cotizaciones_mantenimiento"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span></div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
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
    $html = '';
    foreach ($rows as $row) {
      $html .= $this->render_cotizacion_mantenimiento_card($row);
    }
    return $html;
  }

  /** @param array<string,mixed> $row */
  private function render_cotizacion_mantenimiento_card(array $row): string
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $ticket = trim((string) ($row['id_ticket'] ?? ''));
    $inmueble = trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''));
    $idInmueble = trim((string) ($row['id_inmueble'] ?? $inmueble));
    $contrato = trim((string) ($row['contrato'] ?? $row['id_contrato'] ?? ''));
    $idContrato = trim((string) ($row['id_contrato'] ?? $contrato));
    $sucursal = trim((string) ($row['sucursal'] ?? '1'));
    $estado = trim((string) ($row['estado'] ?? 'Inicial'));
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
    $ticketUrl = $ticket !== '' ? self::DEFAULT_TICKET_URL . rawurlencode($ticket) : '';
    $cotUrl = $id !== '' ? self::DEFAULT_COTIZACION_URL . rawurlencode($id) : '';
    $noteUrl = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-nota-a-cotizacion-de-mantenimiento/?id_cotizacion=' . rawurlencode($id) . '&id_inmueble=' . rawurlencode($idInmueble) . '&id_sucursal=' . rawurlencode($sucursal) . '&id_contrato=' . rawurlencode($idContrato);
    $orderUrl = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-orden-de-mantenimiento/?id_cotizacion=' . rawurlencode($id) . '&id_inmueble=' . rawurlencode($idInmueble);
    $actaUrl = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-acta-de-satisfaccion/?id_cotizacion=' . rawurlencode($id) . '&id_rev_correctiva=' . rawurlencode(trim((string) ($row['id_revision'] ?? ''))) . '&id_propietario=' . rawurlencode(trim((string) ($row['id_propietario'] ?? ''))) . '&id_arrendatario=' . rawurlencode(trim((string) ($row['id_arrendatario'] ?? ''))) . '&id_inmueble=' . rawurlencode($idInmueble) . '&id_sucursal=' . rawurlencode($sucursal);
    $orders = is_array($row['_scm_ordenes'] ?? null) ? $row['_scm_ordenes'] : [];
    $ordersHtml = empty($orders) ? '<p class="scm-case-history-empty">Sin ordenes registradas para esta cotizacion.</p>' : '';
    foreach ($orders as $order) {
      $ordersHtml .= '<article class="scm-case-history-item"><div class="scm-case-history-meta"><strong>Orden #' . esc_html((string) ($order['_ID'] ?? '')) . '</strong><span>' . esc_html((string) ($order['estado'] ?? '-')) . '</span></div><div class="scm-case-history-detail"><p><strong>Proveedor:</strong> ' . esc_html((string) ($order['proveedor'] ?? '-')) . '</p><p><strong>Actividad:</strong> ' . esc_html((string) ($order['actividad'] ?? '-')) . '</p><p><strong>Valor:</strong> ' . esc_html((string) ($order['valor'] ?? '-')) . '</p></div></article>';
    }

    return '<article class="scm-cotizacion-card card" data-cotizacion-id="' . esc_attr($id) . '">'
      . '<div class="scm-cotizacion-main"><div><span class="scm-ticket-badge badge badge-primary">#' . esc_html($id) . '</span><h3>' . esc_html($direccion !== '' ? $direccion : 'Cotizacion de mantenimiento') . '</h3><p>Ticket <strong>#' . esc_html($ticket !== '' ? $ticket : '-') . '</strong> · Inmueble <strong>' . esc_html($inmueble !== '' ? $inmueble : '-') . '</strong> · Contrato <strong>' . esc_html($contrato !== '' ? $contrato : '-') . '</strong></p></div><div class="scm-cotizacion-status"><span class="scm-cotizacion-pill ' . ($enviada ? 'is-sent' : 'is-pending') . '">' . ($enviada ? 'Fue enviada' : 'No fue enviada') . '</span><span class="scm-cotizacion-pill is-state">' . esc_html($estado !== '' ? $estado : '-') . '</span></div></div>'
      . '<div class="scm-cotizacion-meta"><div><span>Fecha</span><strong>' . esc_html($fecha) . '</strong></div><div><span>Destinatario</span><strong>' . esc_html($destinatario !== '' ? $destinatario : '-') . '</strong></div><div><span>Contacto</span><strong>' . esc_html($contacto !== '' ? $contacto : '-') . '</strong></div><div><span>Empleado</span><strong>' . esc_html($empleado !== '' ? $empleado : '-') . '</strong></div><div><span>Mano de obra</span><strong>' . esc_html((string) ($row['saldo_obra'] ?? $row['total_mano_obra'] ?? '-')) . '</strong></div><div><span>Materiales</span><strong>' . esc_html((string) ($row['saldo_materiales'] ?? $row['total_materiales'] ?? '-')) . '</strong></div><div><span>Otros costos</span><strong>' . esc_html((string) ($row['saldo_otros_costo'] ?? $row['total_otros_costos'] ?? '-')) . '</strong></div><div><span>Ordenes</span><strong>' . esc_html((string) ($row['ordenes_total'] ?? count($orders))) . '</strong></div></div>'
      . '<div class="scm-cotizacion-actions">'
      . ($cotUrl !== '' ? '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($cotUrl) . '" data-iframe-title="Cotizacion #' . esc_attr($id) . '">Ver cotizacion</button>' : '')
      . ($ticketUrl !== '' ? '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($ticketUrl) . '" data-iframe-title="Ticket #' . esc_attr($ticket) . '">Ver ticket</button>' : '')
      . '<button type="button" class="scm-case-work-btn" data-scm-view-cotizacion-orders>Ver ordenes</button>'
      . '<button type="button" class="scm-case-work-btn" data-scm-cotizacion-response-standalone data-ticket-pk="' . esc_attr($ticket) . '" data-ticket="' . esc_attr($ticket) . '" data-cotizacion-id="' . esc_attr($id) . '">Responder cotizacion</button>'
      . '<button type="button" class="scm-case-work-btn scm-danger-action" data-scm-delete-cotizacion data-cotizacion-id="' . esc_attr($id) . '">Eliminar cotizacion</button>'
      . '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($noteUrl) . '" data-iframe-title="Anadir nota a cotizacion">Anadir nota</button>'
      . '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($orderUrl) . '" data-iframe-title="Anadir orden de mantenimiento">Anadir orden</button>'
      . '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($actaUrl) . '" data-iframe-title="Anadir acta de satisfaccion">Anadir acta</button>'
      . '</div><div class="scm-cotizacion-orders-source" style="display:none;">' . $ordersHtml . '</div></article>';
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
