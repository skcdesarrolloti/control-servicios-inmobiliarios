<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Core\Settings;
use SCM\Support\FuncionarioOptions;

trait HandlesTicketWorkflowActions
{
  public function ajax_handler_session_heartbeat(): void
  {
    $this->verifyCsrf();
    $idleTimeout = defined('SCM_SESSION_IDLE_TIMEOUT')
      ? max(900, (int) SCM_SESSION_IDLE_TIMEOUT)
      : 7200;
    $this->jsonOk([
      'authenticated' => true,
      'idle_timeout_seconds' => $idleTimeout,
      'checked_at' => date(DATE_ATOM),
    ]);
  }

  /** @return array<string,mixed>|null */
  private function readDashboardPerformanceCache(string $name, int $ttl): ?array
  {
    if (!defined('SCM_STORAGE_PATH')) {
      return null;
    }
    $path = SCM_STORAGE_PATH . '/data/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) . '.json';
    if (!is_file($path) || (int) @filemtime($path) < time() - max(1, $ttl)) {
      return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
  }

  /** @param array<string,mixed> $value */
  private function writeDashboardPerformanceCache(string $name, array $value): void
  {
    if (!defined('SCM_STORAGE_PATH')) {
      return;
    }
    $directory = SCM_STORAGE_PATH . '/data';
    if (!is_dir($directory) || !is_writable($directory)) {
      return;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
      @file_put_contents($directory . '/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) . '.json', $json, LOCK_EX);
    }
  }

  private function clearDashboardPerformanceCache(string $name): void
  {
    if (!defined('SCM_STORAGE_PATH')) {
      return;
    }
    $path = SCM_STORAGE_PATH . '/data/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) . '.json';
    if (is_file($path)) {
      @unlink($path);
    }
  }

  public function ajax_handler_public_pqr_settings_read(): void
  {
    $this->verifyCsrf();
    if (!$this->canManagePublicPqrSettings()) {
      $this->jsonFail('No tienes permiso para configurar Guardian.');
    }
    $this->jsonOk(['html' => $this->renderDashboardPublicPqrSettingsModal()]);
  }

  public function ajax_handler_internal_notifications_read(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageInternalNotificationSettings()) {
      $this->jsonFail('No tienes permiso para configurar notificaciones internas.');
    }
    $this->jsonOk(['html' => $this->renderDashboardInternalNotificationsModal()]);
  }

  public function ajax_handler_dashboard_filter_options(): void
  {
    $this->verifyCsrf();
    $cacheName = 'dashboard-filter-options-v7';
    $payload = $this->readDashboardPerformanceCache($cacheName, 3600);
    if (
      is_array($payload)
      && (
        empty($payload['filter_options']['funcionarios'])
        || empty($payload['cotizacion_options']['funcionarios'])
        || empty($payload['calendar_allowed_funcionarios'])
      )
    ) {
      $payload = null;
    }
    if (!is_array($payload)) {
      $module = $this->get_servicios_inmobiliarios_module();
      $calendarFuncionarios = $this->get_calendar_allowed_funcionarios();
      $filterOptions = $module->getFilterOptions();
      $filterOptions['my_ticket_topics'] = $module->getTicketTopicOptionsExceptDepartment('Servicio al cliente');
      $payload = [
        'filter_options' => $filterOptions,
        'cotizacion_options' => [
          'funcionarios' => $this->cotizaciones_funcionario_options(),
          'tipos_mantenimiento' => $this->cotizaciones_distinct_options('tipo_mantenimiento'),
          'categorias' => $this->cotizaciones_distinct_options('categoria_cotizacion'),
        ],
        'calendar_allowed_funcionarios' => $calendarFuncionarios,
        'calendar_allowed_employee_ids' => array_values(array_filter(array_map(
          static fn(array $row): string => trim((string) ($row['id_empleado'] ?? '')),
          $calendarFuncionarios
        ))),
      ];
      $this->writeDashboardPerformanceCache($cacheName, $payload);
    }
    $payload['calendar_current_employee_id'] = $this->current_employee_id();
    $this->jsonOk($payload);
  }

  /** @return array<string,mixed> */
  private function dashboardMetricsSnapshot(): array
  {
    $cacheKey = 'scm_dashboard_metrics_v2_' . md5((string) SCM_ROOT);
    $metrics = null;
    if (function_exists('apcu_fetch')) {
      $cached = apcu_fetch($cacheKey, $cacheHit);
      if ($cacheHit && is_array($cached)) {
        $metrics = $cached;
      }
    }
    if (!is_array($metrics)) {
      $metrics = $this->readDashboardPerformanceCache('dashboard-metrics-v2', 900);
    }

    if (!is_array($metrics)) {
      $config = [
        'ticket_url' => self::DEFAULT_TICKET_URL,
        'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
        'correctiva_url' => self::defaultCorrectiveReviewUrl(),
        'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
        'acta_url' => self::DEFAULT_ACTA_URL,
      ];
      $module = $this->get_servicios_inmobiliarios_module();
      $maintenance = $module->summarizeMaintenance($module->parseParams([]));
      $maintenanceStats = is_array($maintenance['stats'] ?? null) ? $maintenance['stats'] : [];

      $genericStats = [];
      foreach ($this->get_generic_tab_definitions() as $key => $definition) {
        $params = $this->parse_params_generic([], (string) ($definition['prefix'] ?? 'scm_'));
        $result = $this->run_query_generic((array) ($definition['temas'] ?? []), $params, $config, false);
        $genericStats[$key] = is_array($result['stats'] ?? null) ? $result['stats'] : [];
      }

      $categoryMetrics = [
        'Mantenimiento' => (int) ($maintenanceStats['total'] ?? 0),
        'Entrega' => (int) ($genericStats['entrega']['total'] ?? 0),
        'Preventiva' => (int) ($genericStats['preventiva']['total'] ?? 0),
        'Recibo' => (int) ($genericStats['recibo']['total'] ?? 0),
        'Contable' => (int) ($genericStats['contable']['total'] ?? 0),
        'Certificaciones' => (int) ($genericStats['certificaciones']['total'] ?? 0),
        'Contractual' => (int) ($genericStats['contractual']['total'] ?? 0),
      ];

      $metrics = [
        'total' => (int) ($maintenanceStats['total'] ?? 0),
        'abiertos' => (int) ($maintenanceStats['abiertos'] ?? 0),
        'cerrados' => (int) ($maintenanceStats['cerrados'] ?? 0),
        'sla_vencido' => (int) ($maintenanceStats['sla_vencido'] ?? 0),
        'sla_riesgo' => (int) ($maintenanceStats['sla_riesgo'] ?? 0),
        'con_cotizacion' => (int) ($maintenanceStats['con_cotizacion'] ?? 0),
        'sin_cotizacion' => (int) ($maintenanceStats['sin_cotizacion'] ?? 0),
        'con_revision' => (int) ($maintenanceStats['con_revision'] ?? 0),
        'sin_revision' => (int) ($maintenanceStats['sin_revision'] ?? 0),
        'avg_first_h' => isset($maintenanceStats['avg_first_h']) && is_numeric($maintenanceStats['avg_first_h']) ? (float) $maintenanceStats['avg_first_h'] : null,
        'avg_close_h' => isset($maintenanceStats['avg_close_h']) && is_numeric($maintenanceStats['avg_close_h']) ? (float) $maintenanceStats['avg_close_h'] : null,
        'avg_stale_h' => isset($maintenanceStats['avg_stale_h']) && is_numeric($maintenanceStats['avg_stale_h']) ? (float) $maintenanceStats['avg_stale_h'] : null,
        'mes_actualizados' => (int) ($maintenanceStats['mes_actualizados'] ?? 0),
        'mes_cerrados' => (int) ($maintenanceStats['mes_cerrados'] ?? 0),
        'mes_seguimientos' => (int) ($maintenanceStats['mes_seguimientos'] ?? 0),
        'web' => $this->get_web_ticket_statistics(),
        'por_categoria' => $categoryMetrics,
        'detalle_por_categoria' => [
          'mantenimiento' => ['label' => 'Mantenimiento'] + $maintenanceStats,
          'entrega' => ['label' => 'Entrega'] + ($genericStats['entrega'] ?? []),
          'preventiva' => ['label' => 'Preventiva'] + ($genericStats['preventiva'] ?? []),
          'recibo' => ['label' => 'Recibo'] + ($genericStats['recibo'] ?? []),
          'contable' => ['label' => 'Contable'] + ($genericStats['contable'] ?? []),
          'certificaciones' => ['label' => 'Certificaciones'] + ($genericStats['certificaciones'] ?? []),
          'contractual' => ['label' => 'Contractual'] + ($genericStats['contractual'] ?? []),
        ],
      ];

      if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $metrics, 60);
      }
      $this->writeDashboardPerformanceCache('dashboard-metrics-v2', $metrics);
    }

    return $metrics;
  }

  public function ajax_handler_dashboard_home(): void
  {
    $this->verifyCsrf();
    $canViewSummary = $this->canAccessDashboardTab('abiertos') || $this->canAccessDashboardTab('metricas');
    if (!$canViewSummary) {
      $this->jsonOk([
        'summary' => null,
        'message' => 'Tu perfil no tiene acceso a los indicadores generales.',
      ]);
    }

    // Inicio prioriza respuesta inmediata: admite el último snapshot de hasta
    // seis horas. El comando de calentamiento lo renueva periódicamente y la
    // pestaña Métricas mantiene su ventana estricta de quince minutos.
    $metrics = $this->readDashboardPerformanceCache('dashboard-metrics-v2', 21600);
    if (!is_array($metrics)) {
      $metrics = $this->dashboardMetricsSnapshot();
    }
    $snapshotPath = defined('SCM_STORAGE_PATH')
      ? SCM_STORAGE_PATH . '/data/dashboard-metrics-v2.json'
      : '';
    $generatedAt = $snapshotPath !== '' && is_file($snapshotPath)
      ? date(DATE_ATOM, (int) filemtime($snapshotPath))
      : date(DATE_ATOM);
    $this->jsonOk([
      'summary' => [
        'total' => (int) ($metrics['total'] ?? 0),
        'abiertos' => (int) ($metrics['abiertos'] ?? 0),
        'cerrados' => (int) ($metrics['cerrados'] ?? 0),
        'sla_vencido' => (int) ($metrics['sla_vencido'] ?? 0),
        'sla_riesgo' => (int) ($metrics['sla_riesgo'] ?? 0),
        'sin_cotizacion' => (int) ($metrics['sin_cotizacion'] ?? 0),
        'sin_revision' => (int) ($metrics['sin_revision'] ?? 0),
        'por_categoria' => is_array($metrics['por_categoria'] ?? null) ? $metrics['por_categoria'] : [],
      ],
      'generated_at' => $generatedAt,
    ]);
  }

  public function ajax_handler_dashboard_metrics(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver esta pestaña.');
    }

    $this->jsonOk(['metrics' => $this->dashboardMetricsSnapshot()]);
  }

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
      'employee_cargo_ids' => FuncionarioOptions::panelCargoIds(),
      'admin_due_popup_cargo_ids' => $this->adminDuePopupCargoIdsConfig(),
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
    $rawEmployeeCargoIds = stripslashes((string) ($_POST['employee_cargo_ids'] ?? '[]'));
    try {
      $decodedEmployeeCargoIds = json_decode($rawEmployeeCargoIds, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      $this->jsonFail('Los cargos visibles enviados no son validos.');
      return;
    }
    $employeeCargoIds = $this->sanitizeDashboardFuncionarioCargoIds($decodedEmployeeCargoIds);
    $adminDuePopupCargoIds = $this->adminDuePopupCargoIdsConfig();
    if (array_key_exists('admin_due_popup_cargo_ids', $_POST)) {
      $rawDuePopupCargoIds = stripslashes((string) ($_POST['admin_due_popup_cargo_ids'] ?? '[]'));
      try {
        $decodedDuePopupCargoIds = json_decode($rawDuePopupCargoIds, true, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
        $this->jsonFail('Los cargos del popup de vencimientos no son validos.');
        return;
      }
      $adminDuePopupCargoIds = $this->sanitizeAdminDuePopupCargoIds($decodedDuePopupCargoIds);
    }

    \SCM\Core\App::settings()->set('dashboard_tab_permissions', $permissions, Auth::userId());
    \SCM\Core\App::settings()->set(FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY, $employeeCargoIds, Auth::userId());
    \SCM\Core\App::settings()->set('admin_due_popup_cargo_ids', $adminDuePopupCargoIds, Auth::userId());
    \SCM\Core\App::settings()->refresh();
    $this->clearDashboardPerformanceCache('dashboard-filter-options-v4');
    $this->clearDashboardPerformanceCache('dashboard-filter-options-v5');
    $this->clearDashboardPerformanceCache('dashboard-filter-options-v6');
    $this->clearDashboardPerformanceCache('dashboard-filter-options-v7');
    $calendarFuncionarios = $this->get_calendar_allowed_funcionarios();

    $this->jsonOk([
      'message' => 'Permisos y funcionarios visibles guardados.',
      'permissions' => $permissions,
      'employee_cargo_ids' => $employeeCargoIds,
      'admin_due_popup_cargo_ids' => $adminDuePopupCargoIds,
      'calendar_allowed_employee_ids' => array_values(array_filter(array_map(
        static fn(array $row): string => trim((string) ($row['id_empleado'] ?? '')),
        $calendarFuncionarios
      ))),
    ]);
  }

  public function ajax_handler_internal_notifications_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageInternalNotificationSettings()) {
      $this->jsonFail('No tienes permiso para configurar notificaciones internas.');
    }

    $raw = stripslashes((string) ($_POST['settings'] ?? '{}'));
    try {
      $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      $this->jsonFail('La configuracion enviada no es valida.');
      return;
    }

    $settings = $this->sanitizeInternalNotificationSettings(is_array($decoded) ? $decoded : []);
    \SCM\Core\App::settings()->set('internal_admin_notifications', $settings, Auth::userId());
    \SCM\Core\App::settings()->refresh();

    $totalRecipients = 0;
    foreach ($settings as $ids) {
      $totalRecipients += count((array) $ids);
    }

    $this->jsonOk([
      'message' => sprintf('Notificaciones internas guardadas: %d asignaciones configuradas.', $totalRecipients),
      'settings' => $settings,
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
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::defaultCorrectiveReviewUrl()),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
      'acta_url'       => self::sanitizeUrl($rawConfig['acta_url']       ?? self::DEFAULT_ACTA_URL),
    ];

    $module = $this->get_servicios_inmobiliarios_module();
    if ($module instanceof \SCM\Modules\ServiciosInmobiliarios\ServiciosInmobiliariosModule) {
      $params = $module->parseParams($_POST);
      $statsOverride = null;
      $statFilterKeys = array_diff(array_keys($params), ['fPage', 'fPerPage']);
      $hasStatFilters = false;
      foreach ($statFilterKeys as $filterKey) {
        if (trim((string) ($params[$filterKey] ?? '')) !== '') {
          $hasStatFilters = true;
          break;
        }
      }
      if (!$hasStatFilters) {
        $cachedMetrics = $this->readDashboardPerformanceCache('dashboard-metrics-v2', 900);
        $cachedMaintenance = is_array($cachedMetrics['detalle_por_categoria']['mantenimiento'] ?? null)
          ? $cachedMetrics['detalle_por_categoria']['mantenimiento']
          : [];
        $statsOverride = $cachedMaintenance;
      }
      $result = $module->run($params, $config, '', $statsOverride);
      $stats  = is_array($result['stats'] ?? null) ? $result['stats'] : [];

      $payload = [
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
      ];
      $this->jsonOk($payload);
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
      'correctiva_url' => self::sanitizeUrl($rawConfig['correctiva_url'] ?? self::defaultCorrectiveReviewUrl()),
      'cotizacion_url' => self::sanitizeUrl($rawConfig['cotizacion_url'] ?? self::DEFAULT_COTIZACION_URL),
      'acta_url'       => self::sanitizeUrl($rawConfig['acta_url']       ?? self::DEFAULT_ACTA_URL),
    ];

    $module = $this->get_servicios_inmobiliarios_module();
    $maintenanceFilterOptions = $module->getFilterOptions();
    $data = $this->build_status_topic_result($bucketKey, $topicKey, $config, $_POST, $maintenanceFilterOptions);
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

    $this->jsonOk([
      'cards' => (string) ($data['cards'] ?? ''),
      'form' => (string) ($data['form'] ?? ''),
      'pagination' => (string) ($data['pagination'] ?? ''),
      'count' => (string) ($stats['total'] ?? ($data['count'] ?? 0)),
      'kpi_total' => (string) ($stats['total'] ?? ($data['count'] ?? 0)),
      'kpi_con_cotz' => (string) ($stats['con_cotizacion'] ?? 0),
      'kpi_sin_cotz' => (string) ($stats['sin_cotizacion'] ?? 0),
      'kpi_con_prev' => (string) ($stats['con_revision'] ?? 0),
      'kpi_sin_prev' => (string) ($stats['sin_revision'] ?? 0),
    ]);
  }

  public function ajax_handler_export_cases_excel(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageDashboardPermissions()) {
      $this->jsonFail('No tienes permiso para exportar casos.');
    }

    $topicKey = $this->normalize_status_topic((string) ($_POST['topic'] ?? 'mantenimiento'));
    if ($topicKey === '') {
      $topicKey = 'mantenimiento';
    }
    if ($topicKey !== 'mantenimiento') {
      $this->jsonFail('La exportacion de Excel esta disponible para casos de mantenimiento.');
    }

    $bucketKey = $this->normalize_status_bucket((string) ($_POST['bucket'] ?? ''));
    $module = $this->get_servicios_inmobiliarios_module();
    $prefix = $bucketKey !== ''
      ? $this->status_prefix($bucketKey, $topicKey)
      : 'scm_';
    $params = $module->parseParams($_POST, $prefix);
    $rows = $module->exportRows($params, $bucketKey);

    $filename = 'casos-servicios-inmobiliarios-' . date('Ymd-His') . '.xls';
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}th,td{border:1px solid #b7b7b7;padding:6px;mso-number-format:"\@";}.text{mso-number-format:"\@";}</style>';
    echo '</head><body><table><thead><tr>';
    foreach (['Apro', 'Arre', 'Contrato', 'Inmueble', '# caso', 'Direccion', 'Observacion'] as $heading) {
      echo '<th>' . $this->excelExportCell($heading) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $ticket = trim((string) ($row['id_ticket'] ?? ''));
      if ($ticket === '') {
        $ticket = trim((string) ($row['_ID'] ?? ''));
      }
      $cells = [
        $row['propietario'] ?? '',
        $row['arrendatario'] ?? '',
        $row['contrato'] ?? '',
        $row['inmueble'] ?? ($row['id_inmueble'] ?? ''),
        $ticket,
        $row['direccion'] ?? '',
        '',
      ];
      echo '<tr>';
      foreach ($cells as $cell) {
        echo '<td class="text">' . $this->excelExportCell((string) $cell) . '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
  }

  private function excelExportCell(string $value): string
  {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $value) ?? $value;
    $normalized = preg_replace('/\s+/u', ' ', $value);
    $value = trim(is_string($normalized) ? $normalized : $value);
    if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
      $value = "'" . $value;
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    $targetCotizacionId = isset($_POST['id_cotizacion']) ? (int) $_POST['id_cotizacion'] : 0;
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
    $result = $service->saveTicketResponse($ticketPk, $respuesta, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $imagenes, $documentos, $estadoCotizacion, $observacionCotizacion, $motivoCotizacion, $financiacionCotizacion, $generarActaNoAccesoPreventiva, $targetCotizacionId);
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
    $targetCotizacionId = isset($_POST['id_cotizacion']) ? (int) $_POST['id_cotizacion'] : 0;
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
    $result = $service->saveCotizacionResponse($ticketPk, $estado, $observacion, $motivo, $financiacion, $notifyRecipients, $targetCotizacionId);
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
    $table = $view->renderServiciosPublicosTable((array)($payload['items'] ?? []), (array) ($payload['configuration_items'] ?? []));
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

  public function ajax_handler_admin_due_calendar(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('preventivas_pendientes')) {
      $this->jsonFail('No tienes permiso para ver vencimientos administrativos.');
    }

    [$fromTs, $toTs] = $this->adminDueCalendarRange($_POST);
    $settings = $this->adminDueCalendarSettings();
    $items = $this->adminDueCalendarItems($settings, $fromTs, $toTs);
    $stats = $this->adminDueCalendarStats($items);

    $this->jsonOk([
      'settings' => $settings,
      'can_configure' => $this->canManageDashboardPermissions(),
      'eventos' => $items,
      'items' => $items,
      'stats' => $stats,
    ]);
  }

  public function ajax_handler_admin_due_case(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cotizaciones_mantenimiento') && !$this->canAccessDashboardTab('preventivas_pendientes')) {
      $this->jsonFail('No tienes permiso para ver vencimientos administrativos.');
    }

    $type = sanitize_key((string) ($_POST['tipo_vencimiento'] ?? $_POST['due_type'] ?? ''));
    $case = [];
    if (strpos($type, 'cotizacion') === 0 && $this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
      $quoteId = (int) ($_POST['cotizacion_id'] ?? $_POST['id_cotizacion'] ?? 0);
      $quote = $this->adminDueQuoteById($quoteId);
      if (!empty($quote)) {
        $sent = $this->adminDueIsTruthy($quote['se_envio'] ?? $quote['fue_enviada_cotizacion_mantenimiento'] ?? $quote['fue_enviada'] ?? '');
        $case = $this->adminDueCaseDataFromQuote($quote, $sent, true);
      }
    }
    if ($case === [] && $type === 'preventiva_sin_enviar' && $this->canAccessDashboardTab('preventivas_pendientes')) {
      $revisionId = (int) ($_POST['id_revision_preventiva'] ?? $_POST['revision_id'] ?? 0);
      $revision = $this->adminDuePreventivaRevisionById($revisionId);
      if (!empty($revision)) {
        $case = $this->adminDueCaseDataFromPreventivaRevision($revision, true);
      }
    }
    if ($case === [] && in_array($type, ['ticket_preventiva_sin_cita', 'preventiva_cita_sin_realizar'], true) && $this->canAccessDashboardTab('preventivas_pendientes')) {
      $ticketRef = trim((string) ($_POST['ticket_pk'] ?? $_POST['id_ticket'] ?? $_POST['ticket'] ?? ''));
      $ticket = $this->adminDueTicketByReference($ticketRef);
      if (!empty($ticket)) {
        $appointment = $type === 'preventiva_cita_sin_realizar'
          ? $this->adminDuePreventivaPendingAppointmentByTicket((int) ($ticket['_ID'] ?? 0), (string) ($ticket['id_ticket'] ?? ''))
          : [];
        $case = $this->adminDueCaseDataFromPreventivaTicket($ticket, $type, true, $appointment);
      }
    }
    if ($case === []) {
      $this->jsonFail('No se pudo cargar el caso completo del vencimiento.');
    }

    $this->jsonOk(['case' => $case]);
  }

  public function ajax_handler_admin_due_settings_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canManageDashboardPermissions()) {
      $this->jsonFail('No tienes permiso para configurar vencimientos administrativos.');
    }

    $settings = [
      'cotizaciones_sin_enviar_dias' => $this->adminDueDaysFromPost('cotizaciones_sin_enviar_dias', 3, 1, 120),
      'tickets_preventivos_sin_cita_dias' => $this->adminDueDaysFromPost('tickets_preventivos_sin_cita_dias', 3, 1, 120),
      'preventivas_dias' => $this->adminDueDaysFromPost('preventivas_dias', 3, 1, 120),
      'cotizaciones_enviadas_sin_respuesta_dias' => $this->adminDueDaysFromPost('cotizaciones_enviadas_sin_respuesta_dias', 10, 1, 180),
    ];

    try {
      (new Settings($this->db))->set('admin_due_calendar_days', $settings, Auth::userId());
    } catch (\Throwable $exception) {
      error_log('[admin_due_settings_save] ' . $exception->getMessage());
      $this->jsonFail('No se pudo guardar la configuración de vencimientos.');
    }

    $this->jsonOk([
      'settings' => $settings,
      'message' => 'Configuración guardada.',
    ]);
  }

  /** @return array<string,int> */
  private function adminDueCalendarSettings(): array
  {
    $defaults = [
      'cotizaciones_sin_enviar_dias' => 3,
      'tickets_preventivos_sin_cita_dias' => 3,
      'preventivas_dias' => 3,
      'cotizaciones_enviadas_sin_respuesta_dias' => 10,
    ];

    try {
      $stored = (new Settings($this->db, true))->get('admin_due_calendar_days', []);
    } catch (\Throwable $exception) {
      error_log('[admin_due_settings_read] ' . $exception->getMessage());
      $stored = [];
    }
    if (!is_array($stored)) {
      return $defaults;
    }

    return [
      'cotizaciones_sin_enviar_dias' => $this->adminDueClampDays($stored['cotizaciones_sin_enviar_dias'] ?? $defaults['cotizaciones_sin_enviar_dias'], 1, 120, $defaults['cotizaciones_sin_enviar_dias']),
      'tickets_preventivos_sin_cita_dias' => $this->adminDueClampDays($stored['tickets_preventivos_sin_cita_dias'] ?? $defaults['tickets_preventivos_sin_cita_dias'], 1, 120, $defaults['tickets_preventivos_sin_cita_dias']),
      'preventivas_dias' => $this->adminDueClampDays($stored['preventivas_dias'] ?? $defaults['preventivas_dias'], 1, 120, $defaults['preventivas_dias']),
      'cotizaciones_enviadas_sin_respuesta_dias' => $this->adminDueClampDays($stored['cotizaciones_enviadas_sin_respuesta_dias'] ?? $defaults['cotizaciones_enviadas_sin_respuesta_dias'], 1, 180, $defaults['cotizaciones_enviadas_sin_respuesta_dias']),
    ];
  }

  private function adminDueDaysFromPost(string $key, int $default, int $min, int $max): int
  {
    return $this->adminDueClampDays($_POST[$key] ?? $default, $min, $max, $default);
  }

  private function adminDueClampDays($value, int $min, int $max, int $default): int
  {
    $days = (int) sanitize_text_field(wp_unslash((string) $value));
    if ($days < $min || $days > $max) {
      return $default;
    }
    return $days;
  }

  /** @return array{0:int,1:int} */
  private function adminDueCalendarRange(array $input): array
  {
    $from = trim(sanitize_text_field(wp_unslash((string) ($input['fecha_inicio'] ?? $input['from'] ?? ''))));
    $to = trim(sanitize_text_field(wp_unslash((string) ($input['fecha_fin'] ?? $input['to'] ?? ''))));
    $fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : strtotime(date('Y-m-01 00:00:00'));
    $toTs = $to !== '' ? strtotime($to . ' 23:59:59') : strtotime(date('Y-m-t 23:59:59'));
    if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
      $fromTs = strtotime(date('Y-m-01 00:00:00')) ?: time();
      $toTs = strtotime(date('Y-m-t 23:59:59')) ?: time();
    }
    return [(int) $fromTs, (int) $toTs];
  }

  /** @param array<string,int> $settings @return array<int,array<string,mixed>> */
  private function adminDueCalendarItems(array $settings, int $fromTs, int $toTs): array
  {
    $items = [];
    if ($this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
      $items = array_merge($items, $this->adminDueQuoteItems($settings, $fromTs, $toTs));
    }
    if ($this->canAccessDashboardTab('preventivas_pendientes')) {
      $items = array_merge($items, $this->adminDuePreventivaItems($settings, $fromTs, $toTs));
      $items = array_merge($items, $this->adminDuePreventivaTicketItems($settings, $fromTs, $toTs));
    }

    usort($items, static function (array $a, array $b): int {
      $date = strcmp((string) ($a['fecha_inicio'] ?? ''), (string) ($b['fecha_inicio'] ?? ''));
      if ($date !== 0) {
        return $date;
      }
      return strcmp((string) ($a['titulo'] ?? ''), (string) ($b['titulo'] ?? ''));
    });
    return $items;
  }

  /** @param array<string,int> $settings @return array<int,array<string,mixed>> */
  private function adminDueQuoteItems(array $settings, int $fromTs, int $toTs): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      return [];
    }

    $rows = $this->db->getResults(
      "SELECT * FROM `{$table}` ORDER BY COALESCE(NULLIF(`fecha`, 0), UNIX_TIMESTAMP(`cct_created`), `_ID`) DESC LIMIT 2000"
    );
    $items = [];
    foreach ($rows as $row) {
      $estado = strtolower(trim((string) ($row['estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? '')));
      if (in_array($estado, ['aprobada', 'desaprobada', 'aprobado', 'desaprobado', 'aceptada', 'rechazada', 'cerrada', 'cerrado'], true)) {
        continue;
      }
      if ($this->adminDueFirstTimestamp($row, ['fecha_respuesta_cotizacion_mantenimiento', 'fecha_respuesta']) > 0) {
        continue;
      }

      $sent = $this->adminDueIsTruthy($row['se_envio'] ?? $row['fue_enviada_cotizacion_mantenimiento'] ?? $row['fue_enviada'] ?? '');
      $days = $sent
        ? (int) $settings['cotizaciones_enviadas_sin_respuesta_dias']
        : (int) $settings['cotizaciones_sin_enviar_dias'];
      $baseTs = $sent
        ? $this->adminDueFirstTimestamp($row, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo', 'cct_modified', 'fecha', 'cct_created'])
        : $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
      if ($baseTs <= 0) {
        continue;
      }
      $dueTs = strtotime('+' . $days . ' days', strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs);
      $calendarTs = $dueTs !== false ? $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs) : 0;
      if ($dueTs === false || $calendarTs <= 0) {
        continue;
      }

      $items[] = $this->adminDueEvent([
        'id' => 'cot-' . (string) ($row['_ID'] ?? '') . '-' . ($sent ? 'respuesta' : 'envio'),
        'type' => $sent ? 'cotizacion_enviada_sin_respuesta' : 'cotizacion_sin_enviar',
        'group' => $sent ? 'Cotizaciones enviadas sin respuesta' : 'Cotizaciones sin enviar',
        'title' => 'Cotización #' . trim((string) ($row['_ID'] ?? '-')) . ($sent ? ' sin respuesta' : ' sin enviar'),
        'description' => $sent
          ? 'Han pasado ' . $this->adminDueElapsedDays($baseTs) . ' día(s) desde el envío.'
          : 'Vence ' . $days . ' día(s) después de la creación.',
        'color' => $sent ? '#dc2626' : '#f59e0b',
        'base_ts' => $baseTs,
        'due_ts' => (int) $dueTs,
        'calendar_ts' => $calendarTs,
        'days_limit' => $days,
        'case' => $this->adminDueLightCaseDataFromQuote($row, $sent),
      ]);
    }
    return $items;
  }

  /** @param array<string,int> $settings @return array<int,array<string,mixed>> */
  private function adminDuePreventivaItems(array $settings, int $fromTs, int $toTs): array
  {
    $table = $this->db->table('jet_cct_revision_preventiva');
    if (!$this->table_exists($table)) {
      return [];
    }

    $rows = $this->db->getResults(
      "SELECT * FROM `{$table}` ORDER BY COALESCE(UNIX_TIMESTAMP(`cct_created`), `_ID`) DESC LIMIT 2000"
    );
    $items = [];
    $days = (int) $settings['preventivas_dias'];
    foreach ($rows as $row) {
      if ($this->adminDueIsTruthy($row['se_envio'] ?? '')) {
        continue;
      }
      if ($this->adminDueFirstTimestamp($row, ['fecha_envio']) > 0) {
        continue;
      }
      $baseTs = $this->adminDueFirstTimestamp($row, ['cct_created', 'fecha']);
      if ($baseTs <= 0) {
        continue;
      }
      $dueTs = strtotime('+' . $days . ' days', strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs);
      $calendarTs = $dueTs !== false ? $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs) : 0;
      if ($dueTs === false || $calendarTs <= 0) {
        continue;
      }

      $revisionId = trim((string) ($row['_ID'] ?? '-'));
      $items[] = $this->adminDueEvent([
        'id' => 'prev-' . $revisionId,
        'type' => 'preventiva_sin_enviar',
        'group' => 'Preventivas sin enviar',
        'title' => 'Preventiva #' . $revisionId . ' sin enviar',
        'description' => 'Vence ' . $days . ' día(s) después de crear la revisión preventiva.',
        'color' => '#2563eb',
        'base_ts' => $baseTs,
        'due_ts' => (int) $dueTs,
        'calendar_ts' => $calendarTs,
        'days_limit' => $days,
        'case' => $this->adminDueLightCaseDataFromPreventivaRevision($row),
      ]);
    }
    return $items;
  }

  /** @param array<string,int> $settings @return array<int,array<string,mixed>> */
  private function adminDuePreventivaTicketItems(array $settings, int $fromTs, int $toTs): array
  {
    $ticketTable = $this->db->table('jet_cct_tickets');
    $calendarTable = 'calendario_actividades';
    if (!$this->table_exists($ticketTable)) {
      return [];
    }

    $items = [];
    $days = (int) ($settings['tickets_preventivos_sin_cita_dias'] ?? 3);
    $preventivaWhere = $this->adminDuePreventivaTicketWhereSql('t');
    $closedWhere = $this->adminDueOpenTicketWhereSql('t');
    $calendarTicketJoin = "TRIM(COALESCE(c.`id_ticket`, '')) = TRIM(COALESCE(t.`_ID`, ''))";
    $calendarTicketExists = "TRIM(COALESCE(c2.`id_ticket`, '')) = TRIM(COALESCE(t.`_ID`, ''))";
    if ($this->column_exists($ticketTable, 'id_ticket')) {
      $calendarTicketJoin = '(' . $calendarTicketJoin . " OR TRIM(COALESCE(c.`id_ticket`, '')) = TRIM(COALESCE(t.`id_ticket`, '')))";
      $calendarTicketExists = '(' . $calendarTicketExists . " OR TRIM(COALESCE(c2.`id_ticket`, '')) = TRIM(COALESCE(t.`id_ticket`, '')))";
    }

    if ($this->table_exists($calendarTable)) {
      $rows = $this->db->getResults(
        "SELECT t.*, c.`id` AS `_scm_cita_id`, c.`titulo` AS `_scm_cita_titulo`, c.`descripcion` AS `_scm_cita_descripcion`,
            c.`fecha_inicio` AS `_scm_cita_fecha_inicio`, c.`fecha_fin` AS `_scm_cita_fecha_fin`, c.`estado` AS `_scm_cita_estado`
          FROM `{$ticketTable}` t
          INNER JOIN `{$calendarTable}` c ON {$calendarTicketJoin}
          WHERE {$preventivaWhere}
            AND {$closedWhere}
            AND LOWER(TRIM(COALESCE(c.`estado`, ''))) NOT IN ('si', 'sí', 'realizado', 'realizada', '1', 'true')
          ORDER BY c.`fecha_inicio` ASC
          LIMIT 2000"
      );
      foreach ($rows as $row) {
        $appointmentTs = $this->parse_unix_ts($row['_scm_cita_fecha_inicio'] ?? null);
        if ($appointmentTs <= 0) {
          continue;
        }
        $dueTs = strtotime(date('Y-m-d 00:00:00', $appointmentTs)) ?: $appointmentTs;
        $calendarTs = $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs);
        if ($calendarTs <= 0) {
          continue;
        }
        $ticketPk = trim((string) ($row['_ID'] ?? ''));
        $items[] = $this->adminDueEvent([
          'id' => 'prev-cita-' . $ticketPk . '-' . trim((string) ($row['_scm_cita_id'] ?? '')),
          'type' => 'preventiva_cita_sin_realizar',
          'group' => 'Preventivas con cita sin realizar',
          'title' => 'Preventiva #' . ($ticketPk !== '' ? $ticketPk : '-') . ' con cita sin realizar',
          'description' => 'Vence el día de la cita preventiva agendada.',
          'color' => '#7c3aed',
          'base_ts' => $appointmentTs,
          'due_ts' => (int) $dueTs,
          'calendar_ts' => $calendarTs,
          'days_limit' => 0,
          'case' => $this->adminDueLightCaseDataFromPreventivaTicket($row, 'preventiva_cita_sin_realizar'),
        ]);
      }
    }

    $noAppointmentJoin = $this->table_exists($calendarTable)
      ? "AND NOT EXISTS (SELECT 1 FROM `{$calendarTable}` c2 WHERE {$calendarTicketExists})"
      : '';
    $rows = $this->db->getResults(
      "SELECT t.*
        FROM `{$ticketTable}` t
        WHERE {$preventivaWhere}
          AND {$closedWhere}
          {$noAppointmentJoin}
        ORDER BY COALESCE(UNIX_TIMESTAMP(t.`cct_created`), NULLIF(t.`fecha`, 0), t.`_ID`) DESC
        LIMIT 2000"
    );
    foreach ($rows as $row) {
      $baseTs = $this->adminDueFirstTimestamp($row, ['cct_created', 'fecha']);
      if ($baseTs <= 0) {
        continue;
      }
      $dueTs = strtotime('+' . max(1, $days) . ' days', strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs);
      $calendarTs = $dueTs !== false ? $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs) : 0;
      if ($dueTs === false || $calendarTs <= 0) {
        continue;
      }
      $ticketPk = trim((string) ($row['_ID'] ?? ''));
      $items[] = $this->adminDueEvent([
        'id' => 'prev-ticket-sin-cita-' . ($ticketPk !== '' ? $ticketPk : uniqid('', false)),
        'type' => 'ticket_preventiva_sin_cita',
        'group' => 'Tickets sin cita preventiva',
        'title' => 'Ticket #' . ($ticketPk !== '' ? $ticketPk : '-') . ' sin cita preventiva',
        'description' => 'Vence ' . $days . ' día(s) después de crear el ticket preventivo.',
        'color' => '#0f766e',
        'base_ts' => $baseTs,
        'due_ts' => (int) $dueTs,
        'calendar_ts' => $calendarTs,
        'days_limit' => $days,
        'case' => $this->adminDueLightCaseDataFromPreventivaTicket($row, 'ticket_preventiva_sin_cita'),
      ]);
    }

    return $items;
  }

  private function adminDuePreventivaTicketWhereSql(string $alias): string
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "(
      LOWER(COALESCE({$p}`tema_ayuda`, '')) LIKE '%prevent%'
      OR LOWER(COALESCE({$p}`asunto`, '')) LIKE '%prevent%'
      OR TRIM(COALESCE({$p}`id_revision_preventiva`, '')) <> ''
    )";
  }

  private function adminDueOpenTicketWhereSql(string $alias): string
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "LOWER(TRIM(COALESCE({$p}`estado`, ''))) NOT IN ('cerrado', 'cerrada', 'resuelto', 'resuelta', 'finalizado', 'finalizada', 'anulado', 'anulada')
      AND LOWER(TRIM(COALESCE({$p}`estado_administrativo`, ''))) NOT IN ('cerrado', 'cerrada', 'resuelto', 'resuelta', 'finalizado', 'finalizada', 'anulado', 'anulada')";
  }

  /** @param array<string,mixed> $data @return array<string,mixed> */
  private function adminDueEvent(array $data): array
  {
    $baseTs = (int) ($data['base_ts'] ?? 0);
    $dueTs = (int) ($data['due_ts'] ?? 0);
    $calendarTs = (int) ($data['calendar_ts'] ?? $dueTs);
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    $overdue = $dueTs < $todayTs;
    $daysOverdue = $overdue ? max(0, (int) floor(($todayTs - $dueTs) / 86400)) : 0;

    return [
      'id' => (string) ($data['id'] ?? ''),
      'titulo' => (string) ($data['title'] ?? 'Vencimiento'),
      'descripcion' => (string) ($data['description'] ?? ''),
      'grupo' => (string) ($data['group'] ?? ''),
      'tipo_vencimiento' => (string) ($data['type'] ?? ''),
      'estado' => $overdue ? 'Vencido' : 'Pendiente',
      'color' => (string) ($data['color'] ?? '#f59e0b'),
      'fecha_base' => $baseTs > 0 ? date('Y-m-d', $baseTs) : '',
      'fecha_vencimiento' => $dueTs > 0 ? date('Y-m-d', $dueTs) : '',
      'fecha_calendario' => $calendarTs > 0 ? date('Y-m-d', $calendarTs) : '',
      'fecha_inicio' => $calendarTs > 0 ? date('Y-m-d 08:00:00', $calendarTs) : '',
      'fecha_fin' => $calendarTs > 0 ? date('Y-m-d 18:00:00', $calendarTs) : '',
      'acumulado_vencido' => $calendarTs > 0 && $dueTs > 0 && date('Y-m-d', $calendarTs) !== date('Y-m-d', $dueTs) ? 1 : 0,
      'dias_limite' => (int) ($data['days_limit'] ?? 0),
      'dias_transcurridos' => $baseTs > 0 ? $this->adminDueElapsedDays($baseTs) : 0,
      'dias_vencido' => $daysOverdue,
      'case' => is_array($data['case'] ?? null) ? $data['case'] : [],
    ];
  }

  /** @param array<string,mixed> $row @param array<int,string> $columns */
  private function adminDueFirstTimestamp(array $row, array $columns): int
  {
    foreach ($columns as $column) {
      $ts = $this->parse_unix_ts($row[$column] ?? null);
      if ($ts > 0) {
        return $ts;
      }
    }
    return 0;
  }

  private function adminDueCalendarPlacementTimestamp(int $dueTs, int $fromTs, int $toTs): int
  {
    if ($dueTs <= 0) {
      return 0;
    }
    if ($dueTs >= $fromTs && $dueTs <= $toTs) {
      return $dueTs;
    }
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    if ($dueTs < $fromTs && $todayTs >= $fromTs && $todayTs <= $toTs) {
      return $todayTs;
    }
    return 0;
  }

  private function adminDueIsTruthy($value): bool
  {
    return in_array(strtolower(trim((string) $value)), ['si', 'sí', 'yes', '1', 'true', 'enviada', 'enviado'], true);
  }

  private function adminDueElapsedDays(int $fromTs): int
  {
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    $fromDayTs = strtotime(date('Y-m-d 00:00:00', $fromTs)) ?: $fromTs;
    return max(0, (int) floor(($todayTs - $fromDayTs) / 86400));
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private function adminDueLightCaseDataFromQuote(array $row, bool $sent): array
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
    $contrato = trim((string) ($row['contrato'] ?? $row['id_contrato'] ?? ''));
    $inmueble = trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''));
    $createdTs = $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
    $estado = trim((string) ($row['estado'] ?? ''));
    return [
      'ticket' => $ticketRef !== '' ? $ticketRef : ('Cot ' . ($id !== '' ? $id : '-')),
      'ticket_pk' => $ticketRef,
      'asunto' => trim((string) ($row['asunto'] ?? $row['categoria_cotizacion'] ?? $row['tipo_mantenimiento'] ?? 'Cotización de mantenimiento')) ?: 'Cotización de mantenimiento',
      'estado' => trim((string) ($row['estado_ticket'] ?? $row['estado_caso'] ?? '')) ?: '-',
      'admin' => trim((string) ($row['estado_administrativo'] ?? $row['estado_administrativo_ticket'] ?? '')) ?: '-',
      'contrato' => $contrato !== '' ? $this->adminDueHashLabel($contrato) : '-',
      'inmueble' => $inmueble !== '' ? $inmueble : '-',
      'id_inmueble_web' => trim((string) ($row['id_inmueble'] ?? $inmueble)) ?: '-',
      'barrio' => trim((string) ($row['barrio'] ?? '')) ?: '-',
      'direccion' => trim((string) ($row['direccion'] ?? '')) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y', $createdTs) : '-',
      'empleado' => trim((string) ($row['coordinador'] ?? $row['creador'] ?? $row['id_empleado'] ?? '')) ?: '-',
      'cotizacion_id' => $id,
      'cotizacion_url' => $id !== '' ? self::DEFAULT_COTIZACION_URL . rawurlencode($id) : '',
      'cot_estado' => $estado !== '' ? $estado : ($sent ? 'Enviada sin respuesta' : 'Sin enviar'),
      'tab_key' => 'mantenimiento',
      'status_bucket' => 'abiertos',
      'case_source_html' => $this->adminDueLoadingCaseSourceHtml('Cargando detalle completo de la cotización #' . ($id !== '' ? $id : '-') . '.'),
    ];
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private function adminDueLightCaseDataFromPreventivaRevision(array $row): array
  {
    $revisionId = trim((string) ($row['_ID'] ?? ''));
    $ticketRef = trim((string) ($row['id_ticket'] ?? $row['ticket_id'] ?? $row['numero_ticket'] ?? ''));
    $contrato = trim((string) ($row['contrato'] ?? $row['id_contrato'] ?? ''));
    $inmueble = trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''));
    $createdTs = $this->adminDueFirstTimestamp($row, ['cct_created', 'fecha']);
    return [
      'ticket' => $ticketRef !== '' ? $ticketRef : ('Rev ' . ($revisionId !== '' ? $revisionId : '-')),
      'ticket_pk' => $ticketRef,
      'asunto' => trim((string) ($row['asunto'] ?? $row['tema_ayuda'] ?? $row['tema'] ?? 'Revisión preventiva sin enviar')) ?: 'Revisión preventiva sin enviar',
      'estado' => trim((string) ($row['cct_status'] ?? '')) ?: '-',
      'admin' => '-',
      'contrato' => $contrato !== '' ? $this->adminDueHashLabel($contrato) : '-',
      'inmueble' => $inmueble !== '' ? $inmueble : '-',
      'id_inmueble_web' => trim((string) ($row['id_inmueble'] ?? $inmueble)) ?: '-',
      'barrio' => trim((string) ($row['barrio'] ?? '')) ?: '-',
      'direccion' => trim((string) ($row['direccion'] ?? '')) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y', $createdTs) : '-',
      'empleado' => trim((string) ($row['empleado'] ?? $row['id_empleado'] ?? '')) ?: '-',
      'empleado_id' => trim((string) ($row['id_empleado'] ?? '')),
      'id_revision_preventiva' => $revisionId,
      'tab_key' => 'preventiva',
      'status_bucket' => 'abiertos',
      'case_source_html' => $this->adminDueLoadingCaseSourceHtml('Cargando detalle completo de la revisión preventiva #' . ($revisionId !== '' ? $revisionId : '-') . '.'),
    ];
  }

  /** @param array<string,mixed> $ticket @return array<string,string> */
  private function adminDueLightCaseDataFromPreventivaTicket(array $ticket, string $type): array
  {
    $ticketPk = trim((string) ($ticket['_ID'] ?? ''));
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketPk));
    $createdTs = $this->adminDueFirstTimestamp($ticket, ['cct_created', 'fecha']);
    return [
      'ticket' => $ticketLabel !== '' ? $ticketLabel : ($ticketPk !== '' ? $ticketPk : '-'),
      'ticket_pk' => $ticketPk,
      'asunto' => $this->adminDueFirstText([$ticket], ['asunto', 'tema_ayuda']) ?: 'Revisión preventiva',
      'estado' => $this->adminDueFirstText([$ticket], ['estado']) ?: '-',
      'admin' => $this->adminDueFirstText([$ticket], ['estado_administrativo']) ?: '-',
      'prioridad' => $this->adminDueFirstText([$ticket], ['prioridad']) ?: '-',
      'magnitud_caso' => $this->adminDueFirstText([$ticket], ['magnitud_caso']) ?: '-',
      'departamento' => $this->adminDueFirstText([$ticket], ['departamento']) ?: '-',
      'tema' => $this->adminDueFirstText([$ticket], ['tema_ayuda']) ?: '-',
      'contrato' => $this->adminDueHashLabel($this->adminDueFirstText([$ticket], ['contrato', 'id_contrato'])),
      'inmueble' => $this->adminDueFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-',
      'id_inmueble_web' => $this->adminDueFirstText([$ticket], ['id_inmueble', 'inmueble']) ?: '-',
      'id_inmueble_data' => $this->adminDueFirstText([$ticket], ['id_inmueble_data']),
      'barrio' => $this->adminDueFirstText([$ticket], ['barrio']) ?: '-',
      'direccion' => $this->adminDueFirstText([$ticket], ['direccion']) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y', $createdTs) : '-',
      'empleado' => $this->adminDueFirstText([$ticket], ['nombre_empleado', 'empleado', 'id_empleado']) ?: '-',
      'empleado_id' => $this->adminDueFirstText([$ticket], ['id_empleado']),
      'propietario' => $this->adminDueFirstText([$ticket], ['propietario']),
      'arrendatario' => $this->adminDueFirstText([$ticket], ['arrendatario']),
      'ticket_url' => $ticketPk !== '' ? self::DEFAULT_TICKET_URL . rawurlencode($ticketPk) : '',
      'id_revision_preventiva' => $this->adminDueFirstText([$ticket], ['id_revision_preventiva']),
      'tab_key' => 'preventiva',
      'status_bucket' => $this->adminDueStatusBucket($ticket),
      'case_source_html' => $this->adminDueLoadingCaseSourceHtml(
        $type === 'preventiva_cita_sin_realizar'
          ? 'Cargando detalle completo de la cita preventiva pendiente.'
          : 'Cargando detalle completo del ticket preventivo sin cita.'
      ),
    ];
  }

  private function adminDueLoadingCaseSourceHtml(string $message): string
  {
    return '<div class="scm-case-description"><strong>Detalle del vencimiento:</strong><div class="scm-case-description-content">'
      . esc_html($message)
      . '</div></div>';
  }

  /** @return array{base_ts:int,due_ts:int,days_limit:int,elapsed_days:int,overdue_days:int} */
  private function adminDueQuoteTiming(array $row, bool $sent): array
  {
    $settings = $this->adminDueCalendarSettings();
    $days = $sent
      ? (int) $settings['cotizaciones_enviadas_sin_respuesta_dias']
      : (int) $settings['cotizaciones_sin_enviar_dias'];
    $baseTs = $sent
      ? $this->adminDueFirstTimestamp($row, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo', 'cct_modified', 'fecha', 'cct_created'])
      : $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
    return $this->adminDueTimingFromBase($baseTs, $days);
  }

  /** @return array{base_ts:int,due_ts:int,days_limit:int,elapsed_days:int,overdue_days:int} */
  private function adminDuePreventivaTiming(array $row): array
  {
    $settings = $this->adminDueCalendarSettings();
    $baseTs = $this->adminDueFirstTimestamp($row, ['cct_created', 'fecha']);
    return $this->adminDueTimingFromBase($baseTs, (int) $settings['preventivas_dias']);
  }

  /** @return array{base_ts:int,due_ts:int,days_limit:int,elapsed_days:int,overdue_days:int} */
  private function adminDuePreventivaTicketNoAppointmentTiming(array $ticket): array
  {
    $settings = $this->adminDueCalendarSettings();
    $baseTs = $this->adminDueFirstTimestamp($ticket, ['cct_created', 'fecha']);
    return $this->adminDueTimingFromBase($baseTs, (int) ($settings['tickets_preventivos_sin_cita_dias'] ?? 3));
  }

  /** @return array{base_ts:int,due_ts:int,days_limit:int,elapsed_days:int,overdue_days:int} */
  private function adminDuePreventivaAppointmentTiming(array $appointment): array
  {
    $baseTs = $this->adminDueFirstTimestamp($appointment, ['fecha_inicio']);
    $dueTs = $baseTs > 0 ? (strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs) : 0;
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    return [
      'base_ts' => $baseTs,
      'due_ts' => $dueTs,
      'days_limit' => 0,
      'elapsed_days' => $baseTs > 0 ? $this->adminDueElapsedDays($baseTs) : 0,
      'overdue_days' => $dueTs > 0 && $dueTs < $todayTs ? max(0, (int) floor(($todayTs - $dueTs) / 86400)) : 0,
    ];
  }

  /** @return array{base_ts:int,due_ts:int,days_limit:int,elapsed_days:int,overdue_days:int} */
  private function adminDueTimingFromBase(int $baseTs, int $days): array
  {
    $dueTs = $baseTs > 0 ? strtotime('+' . max(1, $days) . ' days', strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs) : false;
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    $dueDayTs = $dueTs !== false && $dueTs > 0 ? (strtotime(date('Y-m-d 00:00:00', (int) $dueTs)) ?: (int) $dueTs) : 0;
    return [
      'base_ts' => $baseTs,
      'due_ts' => $dueTs !== false ? (int) $dueTs : 0,
      'days_limit' => max(1, $days),
      'elapsed_days' => $baseTs > 0 ? $this->adminDueElapsedDays($baseTs) : 0,
      'overdue_days' => $dueDayTs > 0 && $dueDayTs < $todayTs ? max(0, (int) floor(($todayTs - $dueDayTs) / 86400)) : 0,
    ];
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,int> */
  private function adminDueCalendarStats(array $items): array
  {
    $today = date('Y-m-d');
    $stats = [
      'total' => count($items),
      'vencidos' => 0,
      'hoy' => 0,
      'cotizacion_sin_enviar' => 0,
      'cotizacion_enviada_sin_respuesta' => 0,
      'preventiva_sin_enviar' => 0,
      'ticket_preventiva_sin_cita' => 0,
      'preventiva_cita_sin_realizar' => 0,
    ];
    foreach ($items as $item) {
      $type = (string) ($item['tipo_vencimiento'] ?? '');
      if (array_key_exists($type, $stats)) {
        $stats[$type]++;
      }
      if ((string) ($item['fecha_vencimiento'] ?? '') === $today) {
        $stats['hoy']++;
      }
      if (strtolower((string) ($item['estado'] ?? '')) === 'vencido') {
        $stats['vencidos']++;
      }
    }
    return $stats;
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private function adminDueCaseDataFromQuote(array $row, bool $sent, bool $includeNativeCase = false): array
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
    $ticketRow = $this->adminDueTicketByReference($ticketRef);
    $ticketPk = trim((string) ($ticketRow['_ID'] ?? $ticketRef));
    $ticketLabel = trim((string) ($ticketRow['id_ticket'] ?? $ticketRef));
    $contractRef = $this->adminDueFirstText([$row, $ticketRow], ['id_contrato', 'id_contrato_arrendamiento', 'contrato']);
    $contract = $this->adminDueContractByReference($contractRef);
    $createdTs = $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
    $estado = trim((string) ($row['estado'] ?? ''));
    $inmueble = $this->adminDueFirstText([$ticketRow, $row, $contract], ['inmueble', 'id_inmueble', 'codigo', 'codigo_inmueble']);
    $contrato = $this->adminDueFirstText([$ticketRow, $row, $contract], ['contrato', 'id_contrato', 'id_contrato_arrendamiento', '_ID']);
    $idInmuebleWeb = $this->adminDueFirstText([$ticketRow, $row, $contract], ['id_inmueble', 'inmueble', 'codigo', 'codigo_inmueble']);
    $idInmuebleData = $this->adminDueFirstText([$ticketRow, $contract], ['id_inmueble_data', 'inmueble_data_id']);
    $enrichedRow = array_merge($contract, $ticketRow, $row);
    if ($ticketPk !== '') {
      $enrichedRow['id_ticket'] = $ticketPk;
    }
    if ($contrato !== '') {
      $enrichedRow['contrato'] = ltrim($contrato, '#');
      $enrichedRow['id_contrato'] = $this->adminDueFirstText([$row, $ticketRow, $contract], ['id_contrato', '_ID', 'id_contrato_arrendamiento']);
    }
    if ($inmueble !== '') {
      $enrichedRow['inmueble'] = $inmueble;
    }
    if ($idInmuebleWeb !== '') {
      $enrichedRow['id_inmueble'] = $idInmuebleWeb;
    }
    $statusBucket = $this->adminDueStatusBucket($ticketRow);
    $nativeCase = $includeNativeCase && $ticketPk !== '' ? $this->adminDueNativeTicketCasePayload((int) $ticketPk, $statusBucket) : [];
    $case = [
      'ticket' => $ticketLabel !== '' ? $ticketLabel : ('Cot ' . ($id !== '' ? $id : '-')),
      'ticket_pk' => $ticketPk,
      'asunto' => $this->adminDueFirstText([$ticketRow, $row], ['asunto', 'categoria_cotizacion', 'tipo_mantenimiento']) ?: 'Cotización de mantenimiento',
      'estado' => $this->adminDueFirstText([$ticketRow, $row], ['estado', 'estado_ticket', 'estado_caso']) ?: '-',
      'admin' => $this->adminDueFirstText([$ticketRow, $row], ['estado_administrativo', 'estado_administrativo_ticket']) ?: '-',
      'prioridad' => $this->adminDueFirstText([$ticketRow, $row], ['prioridad']) ?: '-',
      'magnitud_caso' => $this->adminDueFirstText([$ticketRow, $row], ['magnitud_caso']) ?: '-',
      'departamento' => $this->adminDueFirstText([$ticketRow, $row], ['departamento']) ?: '-',
      'tema' => $this->adminDueFirstText([$ticketRow, $row], ['tema_ayuda', 'tema']) ?: '-',
      'contrato' => $contrato !== '' ? $this->adminDueHashLabel($contrato) : '-',
      'inmueble' => $inmueble !== '' ? $inmueble : '-',
      'id_inmueble_web' => $idInmuebleWeb !== '' ? $idInmuebleWeb : '-',
      'id_inmueble_data' => $idInmuebleData,
      'barrio' => $this->adminDueFirstText([$ticketRow, $row, $contract], ['barrio']) ?: '-',
      'direccion' => $this->adminDueFirstText([$ticketRow, $row, $contract], ['direccion', 'direccion_fisica']) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y', $createdTs) : '-',
      'empleado' => $this->adminDueFirstText([$ticketRow, $row], ['nombre_empleado', 'empleado', 'coordinador', 'creador', 'id_empleado']) ?: '-',
      'empleado_id' => $this->adminDueFirstText([$ticketRow, $row], ['id_empleado']),
      'propietario' => $this->adminDueFirstText([$ticketRow, $row, $contract], ['propietario', 'nombre_propietario', 'nombre']),
      'arrendatario' => $this->adminDueFirstText([$ticketRow, $row, $contract], ['arrendatario', 'nombre_arrendatario']),
      'ticket_url' => $ticketPk !== '' ? self::DEFAULT_TICKET_URL . rawurlencode($ticketPk) : '',
      'cotizacion_id' => $id,
      'cotizacion_url' => $id !== '' ? self::DEFAULT_COTIZACION_URL . rawurlencode($id) : '',
      'cot_estado' => $estado !== '' ? $estado : ($sent ? 'Enviada sin respuesta' : 'Sin enviar'),
      'tab_key' => 'mantenimiento',
      'status_bucket' => $statusBucket,
      'case_source_html' => $this->adminDueQuoteCaseSourceHtml($enrichedRow, $sent, $ticketPk !== '' ? (int) $ticketPk : 0, true, $includeNativeCase),
    ];
    if (!empty($nativeCase)) {
      foreach ($nativeCase as $key => $value) {
        if (in_array($key, ['case_source_html', 'cotizacion_id', 'cotizacion_url', 'cot_estado'], true)) {
          continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
          $case[$key] = $value;
        }
      }
      $case['case_source_html'] = ($nativeCase['case_source_html'] ?? '') . $this->adminDueQuoteCaseSourceHtml($enrichedRow, $sent, $ticketPk !== '' ? (int) $ticketPk : 0, false, true);
      $case['cotizacion_id'] = $id;
      $case['cotizacion_url'] = $id !== '' ? self::DEFAULT_COTIZACION_URL . rawurlencode($id) : '';
      $case['cot_estado'] = $estado !== '' ? $estado : ($sent ? 'Enviada sin respuesta' : 'Sin enviar');
      $case['tab_key'] = 'mantenimiento';
      $case['status_bucket'] = $statusBucket;
    }
    return $case;
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private function adminDueCaseDataFromPreventivaRevision(array $row, bool $includeNativeCase = false): array
  {
    $revisionId = trim((string) ($row['_ID'] ?? ''));
    $ticketRef = trim((string) ($row['id_ticket'] ?? $row['ticket_id'] ?? $row['numero_ticket'] ?? ''));
    $ticket = $this->adminDueTicketByReference($ticketRef);
    $ticketPk = trim((string) ($ticket['_ID'] ?? ''));
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketRef));
    $contractRef = $this->adminDueFirstText([$row, $ticket], ['id_contrato', 'id_contrato_arrendamiento', 'contrato']);
    $contract = $this->adminDueContractByReference($contractRef);
    $contrato = $this->adminDueFirstText([$row, $ticket, $contract], ['contrato', 'id_contrato', 'id_contrato_arrendamiento', '_ID']);
    $inmueble = $this->adminDueFirstText([$row, $ticket, $contract], ['inmueble', 'id_inmueble', 'codigo', 'codigo_inmueble']);
    $idInmuebleWeb = $this->adminDueFirstText([$row, $ticket, $contract], ['id_inmueble', 'inmueble', 'codigo', 'codigo_inmueble']);
    $createdTs = $this->adminDueFirstTimestamp($row, ['cct_created', 'fecha']);
    $statusBucket = $this->adminDueStatusBucket($ticket);
    $nativeCase = $includeNativeCase && $ticketPk !== '' ? $this->adminDueNativeTicketCasePayload((int) $ticketPk, $statusBucket) : [];
    $case = [
      'ticket' => $ticketLabel !== '' ? $ticketLabel : ('Rev ' . ($revisionId !== '' ? $revisionId : '-')),
      'ticket_pk' => $ticketPk !== '' ? $ticketPk : $ticketLabel,
      'asunto' => $this->adminDueFirstText([$ticket, $row], ['asunto', 'tema_ayuda', 'tema']) ?: 'Revisión preventiva sin enviar',
      'estado' => $this->adminDueFirstText([$ticket, $row], ['estado', 'cct_status']) ?: '-',
      'admin' => $this->adminDueFirstText([$ticket], ['estado_administrativo']) ?: '-',
      'prioridad' => $this->adminDueFirstText([$ticket, $row], ['prioridad']) ?: '-',
      'magnitud_caso' => $this->adminDueFirstText([$ticket, $row], ['magnitud_caso']) ?: '-',
      'departamento' => $this->adminDueFirstText([$ticket, $row], ['departamento']) ?: '-',
      'tema' => $this->adminDueFirstText([$ticket, $row], ['tema_ayuda', 'tema']) ?: '-',
      'contrato' => $contrato !== '' ? $this->adminDueHashLabel($contrato) : '-',
      'inmueble' => $inmueble !== '' ? $inmueble : '-',
      'id_inmueble_web' => $idInmuebleWeb !== '' ? $idInmuebleWeb : '-',
      'id_inmueble_data' => $this->adminDueFirstText([$ticket, $contract], ['id_inmueble_data', 'inmueble_data_id']),
      'barrio' => $this->adminDueFirstText([$ticket, $row, $contract], ['barrio']) ?: '-',
      'direccion' => $this->adminDueFirstText([$ticket, $row, $contract], ['direccion', 'direccion_fisica']) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y', $createdTs) : '-',
      'empleado' => $this->adminDueFirstText([$ticket, $row], ['nombre_empleado', 'empleado', 'id_empleado']) ?: '-',
      'empleado_id' => $this->adminDueFirstText([$ticket, $row], ['id_empleado']),
      'propietario' => $this->adminDueFirstText([$ticket, $row, $contract], ['propietario', 'nombre_propietario', 'nombre']),
      'arrendatario' => $this->adminDueFirstText([$ticket, $row, $contract], ['arrendatario', 'nombre_arrendatario']),
      'ticket_url' => $ticketPk !== '' ? self::DEFAULT_TICKET_URL . rawurlencode($ticketPk) : '',
      'id_revision_preventiva' => $revisionId,
      'tab_key' => 'preventiva',
      'status_bucket' => $statusBucket,
      'case_source_html' => $this->adminDuePreventivaCaseSourceHtml($row, $ticket, $contract),
    ];
    if (!empty($nativeCase)) {
      foreach ($nativeCase as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
          $case[$key] = $value;
        }
      }
      $case['case_source_html'] = ($nativeCase['case_source_html'] ?? '') . $this->adminDuePreventivaDueDetailHtml($row, $ticket, $contract);
      $case['id_revision_preventiva'] = $revisionId !== '' ? $revisionId : (string) ($nativeCase['id_revision_preventiva'] ?? '');
      $case['tab_key'] = 'preventiva';
      $case['status_bucket'] = $statusBucket;
    }
    return $case;
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $appointment @return array<string,string> */
  private function adminDueCaseDataFromPreventivaTicket(array $ticket, string $type, bool $includeNativeCase = false, array $appointment = []): array
  {
    $ticketPk = trim((string) ($ticket['_ID'] ?? ''));
    $statusBucket = $this->adminDueStatusBucket($ticket);
    $nativeCase = $includeNativeCase && $ticketPk !== '' ? $this->adminDueNativeTicketCasePayload((int) $ticketPk, $statusBucket) : [];
    $case = $this->adminDueLightCaseDataFromPreventivaTicket($ticket, $type);
    $case['status_bucket'] = $statusBucket;
    $detailHtml = $this->adminDuePreventivaTicketDueDetailHtml($ticket, $type, $appointment);
    if (!empty($nativeCase)) {
      foreach ($nativeCase as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
          $case[$key] = $value;
        }
      }
      $case['case_source_html'] = ($nativeCase['case_source_html'] ?? '') . $detailHtml;
      $case['tab_key'] = 'preventiva';
      $case['status_bucket'] = $statusBucket;
    } else {
      $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketPk));
      $case['case_source_html'] = '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">'
        . esc_html($type === 'preventiva_cita_sin_realizar'
          ? 'Control de vencimiento para cita preventiva pendiente del ticket #' . ($ticketLabel !== '' ? $ticketLabel : '-')
          : 'Control de vencimiento para ticket preventivo sin cita #' . ($ticketLabel !== '' ? $ticketLabel : '-'))
        . '.</div></div>'
        . ($ticketPk !== '' ? '<div class="scm-seg-wrap">' . $this->render_seguimiento_form((int) $ticketPk, Auth::isLoggedIn(), false) . '</div>' : '')
        . $detailHtml;
    }
    return $case;
  }

  /** @return array<string,mixed> */
  private function adminDueTicketByReference(string $ticketRef): array
  {
    $ticketRef = trim($ticketRef);
    if ($ticketRef === '') {
      return [];
    }
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }
    $where = ['`_ID` = ?'];
    $args = [(int) $ticketRef];
    foreach (['id_ticket', 'ticket_id', 'numero_ticket'] as $column) {
      if ($this->column_exists($table, $column)) {
        $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
        $args[] = $ticketRef;
      }
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " LIMIT 1", $args);
    return is_array($row) ? $row : [];
  }

  /** @return array<string,mixed> */
  private function adminDueQuoteById(int $quoteId): array
  {
    if ($quoteId <= 0) {
      return [];
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
    if (!is_array($row)) {
      return [];
    }
    $rows = $this->attach_cotizacion_orders([$row]);
    return is_array($rows[0] ?? null) ? $rows[0] : $row;
  }

  /** @return array<string,mixed> */
  private function adminDuePreventivaRevisionById(int $revisionId): array
  {
    if ($revisionId <= 0) {
      return [];
    }
    $table = $this->db->table('jet_cct_revision_preventiva');
    if (!$this->table_exists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$revisionId]);
    return is_array($row) ? $row : [];
  }

  /** @return array<string,mixed> */
  private function adminDuePreventivaPendingAppointmentByTicket(int $ticketPk, string $ticketLabel = ''): array
  {
    if ($ticketPk <= 0) {
      return [];
    }
    $table = 'calendario_actividades';
    if (!$this->table_exists($table)) {
      return [];
    }
    $where = ["TRIM(COALESCE(`id_ticket`, '')) = ?"];
    $args = [(string) $ticketPk];
    $ticketLabel = trim($ticketLabel);
    if ($ticketLabel !== '' && $ticketLabel !== (string) $ticketPk) {
      $where[] = "TRIM(COALESCE(`id_ticket`, '')) = ?";
      $args[] = $ticketLabel;
    }
    $row = $this->db->getRow(
      "SELECT *
        FROM `{$table}`
        WHERE (" . implode(' OR ', $where) . ")
          AND LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN ('si', 'sí', 'realizado', 'realizada', '1', 'true')
        ORDER BY `fecha_inicio` ASC
        LIMIT 1",
      $args
    );
    return is_array($row) ? $row : [];
  }

  /** @return array<string,mixed> */
  private function adminDueContractByReference(string $contractRef): array
  {
    $contractRef = trim(ltrim($contractRef, '#'));
    if ($contractRef === '') {
      return [];
    }
    static $cache = [];
    if (array_key_exists($contractRef, $cache)) {
      return $cache[$contractRef];
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table)) {
      $cache[$contractRef] = [];
      return [];
    }
    $where = ['`_ID` = ?'];
    $args = [(int) $contractRef];
    foreach (['contrato', 'id_contrato', 'id_contrato_arrendamiento'] as $column) {
      if ($this->column_exists($table, $column)) {
        $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
        $args[] = $contractRef;
      }
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " LIMIT 1", $args);
    $cache[$contractRef] = is_array($row) ? $row : [];
    return $cache[$contractRef];
  }

  /** @param array<int,array<string,mixed>> $rows @param array<int,string> $columns */
  private function adminDueFirstText(array $rows, array $columns): string
  {
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      foreach ($columns as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  private function adminDueHashLabel(string $value): string
  {
    $value = trim($value);
    if ($value === '' || $value === '-') {
      return '-';
    }
    return strpos($value, '#') === 0 ? $value : '#' . $value;
  }

  /** @param array<string,mixed> $ticket */
  private function adminDueStatusBucket(array $ticket): string
  {
    $estado = strtolower(trim((string) ($ticket['estado'] ?? '')));
    $admin = strtolower(trim((string) ($ticket['estado_administrativo'] ?? '')));
    if (in_array($estado, ['cerrado', 'resuelto', 'finalizado'], true) || in_array($admin, ['cerrado', 'resuelto', 'finalizado'], true)) {
      return 'cerrados';
    }
    if ($admin === 'postergado') {
      return 'postergados';
    }
    return 'abiertos';
  }

  /** @return array<string,string> */
  private function adminDueNativeTicketCasePayload(int $ticketPk, string $statusBucket): array
  {
    if ($ticketPk <= 0) {
      return [];
    }
    try {
      $cards = $this->get_servicios_inmobiliarios_module()->renderCardsByTicketIds(
        [$ticketPk],
        [
          'ticket_url' => self::DEFAULT_TICKET_URL,
          'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
          'correctiva_url' => self::defaultCorrectiveReviewUrl(),
          'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
          'acta_url' => self::DEFAULT_ACTA_URL,
        ],
        $statusBucket
      );
    } catch (\Throwable $e) {
      return [];
    }
    $html = trim((string) ($cards[(string) $ticketPk] ?? $cards[$ticketPk] ?? ''));
    if ($html === '' || !class_exists(\DOMDocument::class)) {
      return [];
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="scm-due-native-card">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new \DOMXPath($dom);
    $source = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " scm-case-source ")]')->item(0);
    $button = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " scm-btn-case ")]')->item(0);
    if (!$source instanceof \DOMNode || !$button instanceof \DOMElement) {
      return [];
    }

    $payload = ['case_source_html' => $this->adminDueDomInnerHtml($source)];
    foreach ($button->attributes as $attr) {
      if (!$attr instanceof \DOMAttr || strpos($attr->name, 'data-') !== 0) {
        continue;
      }
      $key = str_replace('-', '_', substr($attr->name, 5));
      $payload[$key] = $attr->value;
    }
    return $payload;
  }

  private function adminDueDomInnerHtml(\DOMNode $node): string
  {
    $html = '';
    foreach ($node->childNodes as $child) {
      $html .= $node->ownerDocument ? $node->ownerDocument->saveHTML($child) : '';
    }
    return $html;
  }

  /** @param array<string,mixed> $row */
  private function adminDueQuoteCaseSourceHtml(array $row, bool $sent, int $ticketPk, bool $includeCaseShell = true, bool $includeNativeQuoteSource = false): string
  {
    $cotizacionId = trim((string) ($row['_ID'] ?? ''));
    $estado = trim((string) ($row['estado'] ?? '')) ?: ($sent ? 'Enviada sin respuesta' : 'Sin enviar');
    $createdTs = $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
    $timing = $this->adminDueQuoteTiming($row, $sent);
    $html = '';
    if ($includeCaseShell) {
      $html .= '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">'
        . esc_html('Control de vencimiento para cotización #' . ($cotizacionId !== '' ? $cotizacionId : '-') . '.')
        . '</div></div>';
      if ($ticketPk > 0) {
        $html .= '<div class="scm-seg-wrap">' . $this->render_seguimiento_form($ticketPk, Auth::isLoggedIn(), true) . '</div>';
      }
    }
    $orders = is_array($row['_scm_ordenes'] ?? null) ? $row['_scm_ordenes'] : [];
    $html .= '<section class="scm-case-history"><h4>Detalle del vencimiento</h4><article class="scm-case-history-item"><div class="scm-case-history-detail">'
      . '<p><strong>Tipo:</strong> ' . esc_html($sent ? 'Cotización enviada sin respuesta' : 'Cotización sin enviar') . '</p>'
      . '<p><strong>Cotización:</strong> #' . esc_html($cotizacionId !== '' ? $cotizacionId : '-') . '</p>'
      . '<p><strong>Estado:</strong> ' . esc_html($estado) . '</p>'
      . '<p><strong>Fecha de creación:</strong> ' . esc_html($createdTs > 0 ? date('d/m/Y', $createdTs) : '-') . '</p>'
      . '<p><strong>Fecha base del control:</strong> ' . esc_html($timing['base_ts'] > 0 ? date('d/m/Y', $timing['base_ts']) : '-') . '</p>'
      . '<p><strong>Debió hacerse el:</strong> ' . esc_html($timing['due_ts'] > 0 ? date('d/m/Y', $timing['due_ts']) : '-') . '</p>'
      . '<p><strong>Días transcurridos:</strong> ' . esc_html((string) $timing['elapsed_days']) . '</p>'
      . '<p><strong>Días vencido:</strong> ' . esc_html((string) $timing['overdue_days']) . '</p>'
      . '<p><strong>Contrato:</strong> ' . esc_html((string) ($row['contrato'] ?? $row['id_contrato'] ?? '-')) . '</p>'
      . '<p><strong>Inmueble:</strong> ' . esc_html((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '-')) . '</p>'
      . '<p><strong>Dirección:</strong> ' . esc_html((string) ($row['direccion'] ?? '-')) . '</p>'
      . '</div></article></section>';
    if ($cotizacionId !== '') {
      $html .= '<section class="scm-case-history"><h4>Acciones de la cotizaci&oacute;n</h4><article class="scm-case-history-item"><div class="scm-case-work-action-list">'
        . '<button type="button" class="scm-case-work-btn" data-scm-view-cotizacion-native data-cotizacion-id="' . esc_attr($cotizacionId) . '">Ver cotizaci&oacute;n</button>'
        . '<button type="button" class="scm-case-work-btn" data-scm-edit-cotizacion data-cotizacion-mode="edit" data-cotizacion-id="' . esc_attr($cotizacionId) . '" data-ticket-pk="' . esc_attr((string) $ticketPk) . '">Editar cotizaci&oacute;n</button>'
        . '<button type="button" class="scm-case-work-btn" data-scm-view-case-cotizaciones data-ticket-pk="' . esc_attr((string) $ticketPk) . '" data-ticket="' . esc_attr((string) ($row['id_ticket'] ?? $ticketPk)) . '" data-cotizacion-id="' . esc_attr($cotizacionId) . '">Gestionar cotizaciones del caso</button>'
        . (!empty($orders) ? '<button type="button" class="scm-case-work-btn" data-scm-view-cotizacion-orders data-cotizacion-id="' . esc_attr($cotizacionId) . '">Ver &oacute;rdenes</button>' : '')
        . ($sent ? '<button type="button" class="scm-case-work-btn" data-scm-cotizacion-response-standalone data-ticket-pk="' . esc_attr((string) $ticketPk) . '" data-ticket="' . esc_attr((string) ($row['id_ticket'] ?? $ticketPk)) . '" data-cotizacion-id="' . esc_attr($cotizacionId) . '">Responder cotizaci&oacute;n</button>' : '')
        . '</div></article></section>';
      if ($includeNativeQuoteSource) {
        $html .= '<div class="scm-calendar-due-cotizacion-source" style="display:none;" aria-hidden="true">'
          . $this->render_cotizacion_mantenimiento_card($row, [])
          . '</div>';
      }
    }
    return $html;
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $ticket @param array<string,mixed> $contract */
  private function adminDuePreventivaCaseSourceHtml(array $row, array $ticket, array $contract): string
  {
    $ticketPk = (int) ($ticket['_ID'] ?? 0);
    $revisionId = trim((string) ($row['_ID'] ?? ''));
    $html = '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">'
      . esc_html('Control de vencimiento para revisión preventiva #' . ($revisionId !== '' ? $revisionId : '-') . ' sin enviar.')
      . '</div></div>';
    if ($ticketPk > 0) {
      $html .= '<div class="scm-seg-wrap">' . $this->render_seguimiento_form($ticketPk, Auth::isLoggedIn(), false) . '</div>';
    }
    $html .= $this->adminDuePreventivaDueDetailHtml($row, $ticket, $contract);
    return $html;
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $ticket @param array<string,mixed> $contract */
  private function adminDuePreventivaDueDetailHtml(array $row, array $ticket, array $contract): string
  {
    $revisionId = trim((string) ($row['_ID'] ?? ''));
    $timing = $this->adminDuePreventivaTiming($row);
    return '<section class="scm-case-history"><h4>Detalle de la revisión preventiva</h4><article class="scm-case-history-item"><div class="scm-case-history-detail">'
      . '<p><strong>Revisión preventiva:</strong> #' . esc_html($revisionId !== '' ? $revisionId : '-') . '</p>'
      . '<p><strong>Envío:</strong> Sin enviar</p>'
      . '<p><strong>Fecha de creación:</strong> ' . esc_html($timing['base_ts'] > 0 ? date('d/m/Y', $timing['base_ts']) : '-') . '</p>'
      . '<p><strong>Debió hacerse el:</strong> ' . esc_html($timing['due_ts'] > 0 ? date('d/m/Y', $timing['due_ts']) : '-') . '</p>'
      . '<p><strong>Días transcurridos:</strong> ' . esc_html((string) $timing['elapsed_days']) . '</p>'
      . '<p><strong>Días vencido:</strong> ' . esc_html((string) $timing['overdue_days']) . '</p>'
      . '<p><strong>Contrato:</strong> ' . esc_html($this->adminDueFirstText([$row, $ticket, $contract], ['contrato', 'id_contrato', '_ID']) ?: '-') . '</p>'
      . '<p><strong>Inmueble:</strong> ' . esc_html($this->adminDueFirstText([$row, $ticket, $contract], ['inmueble', 'id_inmueble', 'codigo', 'codigo_inmueble']) ?: '-') . '</p>'
      . '<p><strong>Dirección:</strong> ' . esc_html($this->adminDueFirstText([$row, $ticket, $contract], ['direccion', 'direccion_fisica']) ?: '-') . '</p>'
      . '</div></article></section>';
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $appointment */
  private function adminDuePreventivaTicketDueDetailHtml(array $ticket, string $type, array $appointment = []): string
  {
    $ticketPk = trim((string) ($ticket['_ID'] ?? ''));
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? $ticketPk));
    $createdTs = $this->adminDueFirstTimestamp($ticket, ['cct_created', 'fecha']);
    $timing = $type === 'preventiva_cita_sin_realizar'
      ? $this->adminDuePreventivaAppointmentTiming($appointment)
      : $this->adminDuePreventivaTicketNoAppointmentTiming($ticket);
    $appointmentTs = $this->adminDueFirstTimestamp($appointment, ['fecha_inicio']);
    return '<section class="scm-case-history"><h4>Detalle del vencimiento preventivo</h4><article class="scm-case-history-item"><div class="scm-case-history-detail">'
      . '<p><strong>Tipo:</strong> ' . esc_html($type === 'preventiva_cita_sin_realizar' ? 'Preventiva con cita sin realizar' : 'Ticket sin cita preventiva') . '</p>'
      . '<p><strong>Ticket:</strong> #' . esc_html($ticketLabel !== '' ? $ticketLabel : '-') . '</p>'
      . '<p><strong>Estado:</strong> ' . esc_html($this->adminDueFirstText([$ticket], ['estado']) ?: '-') . '</p>'
      . '<p><strong>Estado administrativo:</strong> ' . esc_html($this->adminDueFirstText([$ticket], ['estado_administrativo']) ?: '-') . '</p>'
      . '<p><strong>Fecha de creación:</strong> ' . esc_html($createdTs > 0 ? date('d/m/Y', $createdTs) : '-') . '</p>'
      . ($type === 'preventiva_cita_sin_realizar' ? '<p><strong>Fecha de la cita:</strong> ' . esc_html($appointmentTs > 0 ? date('d/m/Y H:i', $appointmentTs) : '-') . '</p>' : '')
      . '<p><strong>Fecha base del control:</strong> ' . esc_html($timing['base_ts'] > 0 ? date('d/m/Y', $timing['base_ts']) : '-') . '</p>'
      . '<p><strong>Debió hacerse el:</strong> ' . esc_html($timing['due_ts'] > 0 ? date('d/m/Y', $timing['due_ts']) : '-') . '</p>'
      . '<p><strong>Días transcurridos:</strong> ' . esc_html((string) $timing['elapsed_days']) . '</p>'
      . '<p><strong>Días vencido:</strong> ' . esc_html((string) $timing['overdue_days']) . '</p>'
      . '<p><strong>Contrato:</strong> ' . esc_html($this->adminDueHashLabel($this->adminDueFirstText([$ticket], ['contrato', 'id_contrato']))) . '</p>'
      . '<p><strong>Inmueble:</strong> ' . esc_html($this->adminDueFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-') . '</p>'
      . '<p><strong>Dirección:</strong> ' . esc_html($this->adminDueFirstText([$ticket], ['direccion']) ?: '-') . '</p>'
      . '</div></article></section>';
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

  public function ajax_handler_revision_servicios_publicos(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('servicios_publicos_pendientes')) {
      $this->jsonFail('No tienes permiso para registrar revisiones de servicios públicos.');
    }

    $operation = sanitize_key((string) ($_POST['operation'] ?? 'load'));
    $contractId = (int) ($_POST['contract_id'] ?? $_POST['id_contrato'] ?? 0);
    if ($contractId <= 0) {
      $this->jsonFail('ID de contrato inválido.');
    }

    $controller = $this->get_pending_controller();
    if ($operation === 'load') {
      $result = $controller->buildServiciosPublicosReviewForm($contractId);
      if (empty($result['ok'])) {
        $this->jsonFail((string) ($result['message'] ?? 'No se pudo cargar el formulario.'));
      }
      $this->jsonOk([
        'form_html' => (string) ($result['form_html'] ?? ''),
        'review_date' => (string) ($result['review_date'] ?? ''),
      ]);
    }

    if (!in_array($operation, ['submit', 'configure'], true)) {
      $this->jsonFail('Operación no válida.');
    }

    $input = [];
    $input['configuration_present'] = (string) ($_POST['configuration_present'] ?? '');
    $configuredRaw = is_array($_POST['servicios_configurados'] ?? null) ? $_POST['servicios_configurados'] : [];
    $input['servicios_configurados'] = array_map(static fn($value): string => is_scalar($value) ? sanitize_key(wp_unslash((string) $value)) : '', $configuredRaw);
    $servicesRaw = is_array($_POST['servicios'] ?? null) ? $_POST['servicios'] : [];
    $input['servicios'] = array_values(array_filter(array_map(
      static fn($value): string => is_scalar($value) ? sanitize_key(wp_unslash((string) $value)) : '',
      $servicesRaw
    )));
    foreach ([
      'nic',
      'medidor_luz',
      'resultado_tiempo_luz',
      'resultado_valores_luz',
      'poliza',
      'medidor_agua',
      'resultado_tiempo_agua',
      'resultado_valores_agua',
      'numero_contrato',
      'medidor_gas',
      'resultado_tiempo_gas',
      'resultado_valores_gas',
    ] as $field) {
      $input[$field] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? ''))));
    }

    $result = $operation === 'configure'
      ? $controller->saveServiciosPublicosConfiguration($contractId, $input)
      : $controller->createServiciosPublicosReview($contractId, $input);
    if (empty($result['ok'])) {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo registrar la revisión.'));
    }
    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Revisión agregada con éxito.'),
      'review_id' => (int) ($result['review_id'] ?? 0),
      'documents' => (array) ($result['documents'] ?? []),
      'notifications_queued' => (int) ($result['notifications_queued'] ?? 0),
      'ultima_revision_servicios' => (int) ($result['ultima_revision_servicios'] ?? 0),
      'mes_revision_servicios' => (int) ($result['mes_revision_servicios'] ?? 0),
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
      $this->jsonFail('La fecha de última preventiva es obligatoria.');
    }

    $result = $this->get_pending_controller()->postponePreventivaToNextYear($contractId, $fechaUltima);
    if (empty($result['ok'])) {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo actualizar la última preventiva.'));
    }

    $this->jsonOk([
      'message' => (string) ($result['message'] ?? 'Última preventiva actualizada.'),
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
