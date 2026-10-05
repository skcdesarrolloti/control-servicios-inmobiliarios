<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Core\Settings;
use SCM\Support\FuncionarioOptions;
use SCM\Support\LegacyXlsReader;
use Shuchkin\SimpleXLSX;
use SCM\Modules\Contracts\ContractRenewalService;

trait HandlesTicketWorkflowActions
{
  private ?array $contractRetentionEmployeesCache = null;
  private ?array $contractReceiptEmployeesCache = null;
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

  public function ajax_handler_ticket_topic_settings_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canManagePublicPqrSettings()) {
      $this->jsonFail('No tienes permiso para configurar temas de casos.');
    }

    $defaults = self::defaultGenericTicketTabDefinitions();
    $topicsRaw = $_POST['topics'] ?? [];
    if (!is_array($topicsRaw)) {
      $this->jsonFail('La configuracion de temas no es valida.');
    }

    $settings = [];
    foreach ($defaults as $key => $definition) {
      $topics = self::sanitizeTicketTopicList($topicsRaw[$key] ?? []);
      if ($topics === []) {
        $this->jsonFail('La pestaña "' . (string) ($definition['label'] ?? $key) . '" debe tener al menos un tema.');
      }
      $settings[$key] = $topics;
    }

    \SCM\Core\App::settings()->set('ticket_topic_tabs', $settings, Auth::userId());
    \SCM\Core\App::settings()->refresh();
    $this->clearDashboardPerformanceCache('dashboard-metrics-v2');

    $this->jsonOk([
      'message' => 'Temas de pestañas guardados.',
      'ticket_topic_tabs' => $settings,
    ]);
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
        'cotizaciones_mantenimiento' => $this->maintenance_quote_month_metrics(date('Y-m')),
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

    $metrics = $this->dashboardMetricsSnapshot();
    $month = trim((string) ($_POST['cotizaciones_mes'] ?? ''));
    if ($month !== '') {
      $metrics['cotizaciones_mantenimiento'] = $this->maintenance_quote_month_metrics($month);
    }

    $this->jsonOk(['metrics' => $metrics]);
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
      'action_catalog' => $this->dashboardActionPermissionCatalog(),
      'action_permissions' => $this->dashboardActionPermissionsConfig(),
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
    $actionPermissions = $this->dashboardActionPermissionsConfig();
    if (array_key_exists('action_permissions', $_POST)) {
      $rawActionPermissions = stripslashes((string) ($_POST['action_permissions'] ?? '{}'));
      try {
        $decodedActionPermissions = json_decode($rawActionPermissions, true, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
        $this->jsonFail('Los permisos de acciones enviados no son validos.');
        return;
      }
      $actionPermissions = $this->sanitizeDashboardActionPermissions(is_array($decodedActionPermissions) ? $decodedActionPermissions : []);
    }
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
    \SCM\Core\App::settings()->set('dashboard_action_permissions', $actionPermissions, Auth::userId());
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
      'action_permissions' => $actionPermissions,
      'allowed_actions' => $this->currentDashboardAllowedActions(),
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

    $incomingSettings = $this->sanitizeInternalNotificationSettings(is_array($decoded) ? $decoded : []);
    $merge = in_array(strtolower(trim((string) ($_POST['merge'] ?? ''))), ['1', 'true', 'si', 'sí', 'yes'], true);
    if ($merge) {
      $settings = $this->internalNotificationSettingsConfig();
      foreach ($incomingSettings as $action => $ids) {
        $settings[$action] = $ids;
      }
      ksort($settings);
    } else {
      $settings = $incomingSettings;
    }
    $automation = null;
    if (array_key_exists('receipt_automation', $_POST)) {
      try {
        $automation = json_decode((string) $_POST['receipt_automation'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($automation)) throw new \InvalidArgumentException('Configuración de recibos inválida.');
        $automation = \SCM\Modules\Contracts\ContractReceiptSettings::validate($this->db, $automation);
        if ($automation['enabled'] && empty($settings['contrato_recibo_automatico'])) throw new \InvalidArgumentException('Selecciona destinatarios internos para Ticket automático de recibo · 15 días.');
      } catch (\Throwable $exception) { $this->jsonFail($exception->getMessage()); return; }
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if ($automation !== null) \SCM\Core\App::settings()->set(\SCM\Modules\Contracts\ContractReceiptSettings::KEY, $automation, (int) Auth::employeeId());
      \SCM\Core\App::settings()->set('internal_admin_notifications', $settings, (int) Auth::employeeId());
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      \SCM\Core\App::settings()->refresh();
      $this->jsonFail('No se pudo guardar la configuración.'); return;
    }
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
      $result = $module->run($params, $config);
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
    if (!$this->canUseDashboardAction('case_followup')) {
      $this->jsonFail('No tienes permiso para agregar seguimientos.');
    }

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
    if (!$this->canUseDashboardAction('case_note')) {
      $this->jsonFail('No tienes permiso para agregar notas.');
    }

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
    if (!$this->canUseDashboardAction('case_postpone')) {
      $this->jsonFail('No tienes permiso para postergar tickets.');
    }

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
    if (!$this->canUseDashboardAction('case_activate')) {
      $this->jsonFail('No tienes permiso para activar tickets.');
    }

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
    if (!$this->canUseDashboardAction('case_respond')) {
      $this->jsonFail('No tienes permiso para responder tickets.');
    }

    $solicitudId = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
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
    if (in_array($estadoCotizacion, ['Aprobada', 'Desaprobada'], true) && !$this->canUseDashboardAction('quote_respond')) {
      $this->jsonFail('No tienes permiso para responder cotizaciones.');
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
    if (!$this->canUseDashboardAction('quote_respond')) {
      $this->jsonFail('No tienes permiso para responder cotizaciones.');
    }

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
    if ($estado === 'Desaprobada' && !in_array($motivo, ['Por costo', 'Ejecución por cuenta propia', 'Ejecucción por cuenta propia'], true)) {
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

  public function ajax_handler_contract_termination_requests(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractTerminationRequests()) {
      $this->jsonFail('No tienes permiso para ver solicitudes de terminación de contrato.');
    }

    try {
      $items = $this->contractTerminationRequestItems(150);
    } catch (\Throwable $exception) {
      error_log('[contract_termination_requests] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las solicitudes de terminación.');
    }

    $this->jsonOk([
      'items' => $items,
      'count' => count($items),
      'generated_at' => date('d/m/Y H:i'),
    ]);
  }

  public function ajax_handler_contract_termination_respond(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('case_respond')) {
      $this->jsonFail('No tienes permiso para responder tickets.');
    }

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $solicitudId = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
    $term = sanitize_key((string) ($_POST['termino'] ?? ''));
    $endDate = trim(sanitize_text_field(wp_unslash((string) ($_POST['fecha_terminacion'] ?? ''))));
    $createRetentionTicket = trim((string) ($_POST['crear_ticket_retencion'] ?? '')) === '1';
    $retentionEmployeeId = trim(sanitize_text_field(wp_unslash((string) ($_POST['retencion_id_empleado'] ?? ''))));
    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($solicitudId <= 0 && $ticketPk <= 0) {
      $this->jsonFail('Solicitud o ticket inválido.');
    }
    if (!in_array($term, ['dentro', 'fuera'], true)) {
      $this->jsonFail('Selecciona si la solicitud está dentro o fuera de término.');
    }

    $ticket = $solicitudId > 0
      ? $this->contractTerminationSolicitudById($solicitudId)
      : $this->contractTerminationTicketByPk($ticketPk);
    if ($ticket === []) {
      $this->jsonFail('No se encontró la solicitud de terminación.');
    }
    $ticketPk = (int) ($ticket['ticket_pk'] ?? $ticket['_ID'] ?? $ticketPk);
    $solicitudId = (int) ($ticket['solicitud_id'] ?? $solicitudId);
    if ($ticketPk <= 0) {
      $this->jsonFail('La solicitud no tiene un caso vinculado válido.');
    }

    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: (string) $ticketPk;
    $creator = $this->calendarCitaCreatorContact();
    $creatorName = trim((string) ($creator['name'] ?? '')) ?: (Auth::user() ?: 'Funcionario de SKC SuCasa Inmobiliaria');
    $creatorDetails = $this->contractTerminationCreatorSignatureDetails($creator);
    $requestTs = $this->adminDueFirstTimestamp($ticket, ['solicitud_fecha', 'fecha', 'solicitud_created', 'cct_created']);
    $requestDate = $requestTs > 0 ? date('Y-m-d', $requestTs) : '';
    if ($endDate === '') {
      $finContratoTs = $this->contractTerminationTimestamp($ticket['fin_contrato'] ?? '');
      $endDate = $finContratoTs > 0 ? date('Y-m-d', $finContratoTs) : '';
    }
    $responseText = $this->contractTerminationResponseText($ticket, $term, $requestDate, $endDate);

    try {
      $acta = $this->generateContractTerminationActa($ticket, $term, $responseText, $requestDate, $endDate, $creatorName, $creatorDetails);
    } catch (\Throwable $exception) {
      error_log('[contract_termination_acta] ' . $exception->getMessage());
      $this->jsonFail('No se pudo generar el acta de terminación: ' . $exception->getMessage());
    }

    $documentos = [];
    $actaUrl = trim((string) ($acta['url'] ?? ''));
    if ($actaUrl !== '') {
      $documentos[] = [
        'nombre_archivo' => (string) ($acta['title'] ?? 'Acta de respuesta terminación de contrato'),
        'media_archivo' => $actaUrl,
        'archivo' => $actaUrl,
      ];
    }
    $retentionTicket = [];
    if ($createRetentionTicket) {
      if ($retentionEmployeeId === '') {
        $this->jsonFail('Selecciona el funcionario responsable del ticket de retención.');
      }
      $retentionTicket = $this->createContractRetentionTicketFromContractRequest($ticket, $term, $retentionEmployeeId, $responseText, $actaUrl, (string) ($acta['title'] ?? 'Acta de respuesta terminación de contrato'), 'terminación de contrato');
      if (($retentionTicket['ok'] ?? '0') !== '1') {
        $this->jsonFail((string) ($retentionTicket['message'] ?? 'No se pudo crear el ticket comercial de retención.'));
      }
    }

    $service = $this->get_seguimiento_service();
    $result = $service->saveTicketResponse($ticketPk, $responseText, 'Finalizado', true, ['none'], [], $documentos);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar la respuesta de terminación.'));
    }

    $this->contractTerminationInsertPropertyHistory($ticket, $term, $responseText, $actaUrl, $creatorName);
    $this->contractTerminationMarkResponded($solicitudId, $actaUrl, $term);
    $creatorWhatsappSignature = $this->contractTerminationCreatorSignatureInline($creatorName, $creatorDetails);
    $extraQueued = $this->notifyContractTerminationActa($ticket, $term, $responseText, $actaUrl, $notifyRecipients, $creatorWhatsappSignature);
    $result['acta_url'] = $actaUrl;
    $result['acta_title'] = (string) ($acta['title'] ?? '');
    $result['termination_email_queued'] = (string) ($extraQueued['email'] ?? 0);
    $result['termination_whatsapp_queued'] = (string) ($extraQueued['whatsapp'] ?? 0);
    if ($retentionTicket !== []) {
      $result['retention_ticket_id'] = (string) ($retentionTicket['ticket_id'] ?? '');
      $result['retention_ticket_url'] = (string) ($retentionTicket['ticket_url'] ?? '');
      $result['retention_whatsapp_queued'] = (string) ($retentionTicket['whatsapp_queued'] ?? 0);
    }
    $result['message'] = 'Solicitud respondida, acta generada y ticket cerrado.';
    if ($retentionTicket !== []) {
      $result['message'] .= ' Ticket comercial de retención #' . (string) ($retentionTicket['ticket_id'] ?? '') . ' creado.';
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_contract_non_renewal_requests(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractTerminationRequests()) {
      $this->jsonFail('No tienes permiso para ver solicitudes de no prórroga de contrato.');
    }

    try {
      $items = $this->contractNonRenewalRequestItems(150);
    } catch (\Throwable $exception) {
      error_log('[contract_non_renewal_requests] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las solicitudes de no prórroga.');
    }

    $this->jsonOk([
      'items' => $items,
      'count' => count($items),
      'generated_at' => date('d/m/Y H:i'),
    ]);
  }

  public function ajax_handler_contract_non_renewal_respond(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('case_respond')) {
      $this->jsonFail('No tienes permiso para responder tickets.');
    }

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $term = sanitize_key((string) ($_POST['termino'] ?? ''));
    $endDate = trim(sanitize_text_field(wp_unslash((string) ($_POST['fecha_terminacion'] ?? ''))));
    $createRetentionTicket = trim((string) ($_POST['crear_ticket_retencion'] ?? '')) === '1';
    $retentionEmployeeId = trim(sanitize_text_field(wp_unslash((string) ($_POST['retencion_id_empleado'] ?? ''))));
    $notifyRecipients = $this->parse_notify_recipients($_POST['notify_recipients'] ?? []);
    if (isset($_POST['notify_recipients_present']) && empty($notifyRecipients)) {
      $notifyRecipients = ['none'];
    }

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket inválido.');
    }
    if (!in_array($term, ['dentro', 'fuera'], true)) {
      $this->jsonFail('Selecciona si la solicitud está dentro o fuera de término.');
    }

    $ticket = $this->contractTerminationTicketByPk($ticketPk);
    if ($ticket === [] || !$this->isContractNonRenewalTicket($ticket)) {
      $this->jsonFail('No se encontró la solicitud de no prórroga.');
    }

    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: (string) $ticketPk;
    $creator = $this->calendarCitaCreatorContact();
    $creatorName = trim((string) ($creator['name'] ?? '')) ?: (Auth::user() ?: 'Funcionario de SKC SuCasa Inmobiliaria');
    $creatorDetails = $this->contractTerminationCreatorSignatureDetails($creator);
    $requestTs = $this->adminDueFirstTimestamp($ticket, ['fecha', 'cct_created']);
    $requestDate = $requestTs > 0 ? date('Y-m-d', $requestTs) : '';
    if ($endDate === '') {
      $finContratoTs = $this->contractTerminationTimestamp($ticket['fin_contrato'] ?? '');
      $endDate = $finContratoTs > 0 ? date('Y-m-d', $finContratoTs) : '';
    }
    $responseText = $this->contractNonRenewalResponseText($ticket, $term, $requestDate, $endDate);
    $retentionTicket = [];
    if ($createRetentionTicket) {
      if ($retentionEmployeeId === '') {
        $this->jsonFail('Selecciona el funcionario responsable del ticket de retención.');
      }
    }

    try {
      $acta = $this->generateContractNonRenewalActa($ticket, $term, $responseText, $creatorName, $creatorDetails);
    } catch (\Throwable $exception) {
      error_log('[contract_non_renewal_acta] ' . $exception->getMessage());
      $this->jsonFail('No se pudo generar el acta de no prórroga: ' . $exception->getMessage());
    }

    $documentos = [];
    $actaUrl = trim((string) ($acta['url'] ?? ''));
    if ($actaUrl !== '') {
      $documentos[] = [
        'nombre_archivo' => (string) ($acta['title'] ?? 'Acta de respuesta no prórroga de contrato'),
        'media_archivo' => $actaUrl,
        'archivo' => $actaUrl,
      ];
    }
    if ($createRetentionTicket) {
      $retentionTicket = $this->createContractRetentionTicketFromContractRequest($ticket, $term, $retentionEmployeeId, $responseText, $actaUrl, (string) ($acta['title'] ?? 'Acta de respuesta no prórroga de contrato'), 'no prórroga');
      if (($retentionTicket['ok'] ?? '0') !== '1') {
        $this->jsonFail((string) ($retentionTicket['message'] ?? 'No se pudo crear el ticket comercial de retención.'));
      }
    }

    $service = $this->get_seguimiento_service();
    $result = $service->saveTicketResponse($ticketPk, $responseText, 'Finalizado', true, ['none'], [], $documentos);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo guardar la respuesta de no prórroga.'));
    }

    $this->contractNonRenewalInsertPropertyHistory($ticket, $term, $responseText, $actaUrl, $creatorName);
    $creatorWhatsappSignature = $this->contractTerminationCreatorSignatureInline($creatorName, $creatorDetails);
    $extraQueued = $this->notifyContractNonRenewalActa($ticket, $term, $responseText, $actaUrl, $notifyRecipients, $creatorWhatsappSignature);
    $result['acta_url'] = $actaUrl;
    $result['acta_title'] = (string) ($acta['title'] ?? '');
    $result['non_renewal_email_queued'] = (string) ($extraQueued['email'] ?? 0);
    $result['non_renewal_whatsapp_queued'] = (string) ($extraQueued['whatsapp'] ?? 0);
    if ($retentionTicket !== []) {
      $result['retention_ticket_id'] = (string) ($retentionTicket['ticket_id'] ?? '');
      $result['retention_ticket_url'] = (string) ($retentionTicket['ticket_url'] ?? '');
      $result['retention_whatsapp_queued'] = (string) ($retentionTicket['whatsapp_queued'] ?? 0);
    }
    $result['message'] = 'Solicitud de no prórroga respondida, acta generada y ticket cerrado.';
    if ($retentionTicket !== []) {
      $result['message'] .= ' Ticket comercial de retención #' . (string) ($retentionTicket['ticket_id'] ?? '') . ' creado.';
    }

    $this->jsonOk($result);
  }

  public function ajax_handler_contracts_ending_months(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractsEndingPanel()) {
      $this->jsonFail('No tienes permiso para ver contratos por terminar.');
    }

    $year = max(2000, min(2100, (int) ($_POST['year'] ?? date('Y'))));
    $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
    try {
      (new ContractRenewalService($this->db))->ensureSchema();
      $items = $this->contractsEndingMonthItems($year, $month);
    } catch (\Throwable $exception) {
      error_log('[contracts_ending_months] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar los contratos por terminar.');
    }

    $monthTs = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month)) ?: time();
    $key = date('Y-m', $monthTs);
    $groups = [
      $key => [
        'key' => $key,
        'label' => $this->contractEndingMonthLabel($monthTs),
        'count' => 0,
        'items' => [],
      ],
    ];
    foreach ($items as $item) {
      $itemKey = (string) ($item['month_key'] ?? '');
      if ($itemKey === '') {
        continue;
      }
      if (!isset($groups[$itemKey])) {
        $groups[$itemKey] = [
          'key' => $itemKey,
          'label' => (string) ($item['month_label'] ?? $itemKey),
          'count' => 0,
          'items' => [],
        ];
      }
      $groups[$itemKey]['count']++;
      $groups[$itemKey]['items'][] = $item;
    }

    $this->jsonOk([
      'items' => $items,
      'groups' => array_values($groups),
      'count' => count($items),
      'year' => $year,
      'month' => $month,
      'generated_at' => date('d/m/Y H:i'),
      'can_write' => $this->canWriteContractRenewal(),
      'receipt_funcionarios' => $this->contractReceiptFuncionarios(),
    ]);
  }

  public function ajax_handler_contracts_ending_create_retention(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('case_respond') && !$this->canAccessDashboardTab('contratos_arrendamiento')) {
      $this->jsonFail('No tienes permiso para crear tickets de retención.');
    }

    $contractPk = trim(sanitize_text_field(wp_unslash((string) ($_POST['contract_pk'] ?? $_POST['id_contrato'] ?? ''))));
    $employeeId = trim(sanitize_text_field(wp_unslash((string) ($_POST['retencion_id_empleado'] ?? $_POST['id_empleado'] ?? ''))));
    if ($contractPk === '') {
      $this->jsonFail('Contrato inválido.');
    }
    if ($employeeId === '') {
      $this->jsonFail('Selecciona el funcionario responsable del ticket de retención.');
    }

    $contract = $this->contractEndingContractByPk($contractPk);
    if ($contract === []) {
      $this->jsonFail('No se encontró el contrato seleccionado.');
    }
    $existingTicket = $this->contractEndingRetentionTicketId($contract);
    if ($existingTicket !== '') {
      $this->jsonFail('Este contrato ya tiene ticket de retención #' . $existingTicket . '.');
    }

    $contractCode = $this->contractTerminationFirstText([$contract], ['contrato', 'id_contrato', '_ID']);
    $endTs = $this->contractTerminationTimestamp($contract['fin_contrato'] ?? '');
    $endLabel = $endTs > 0 ? date('d/m/Y', $endTs) : 'sin fecha fin registrada';
    $responseText = 'Ticket creado desde la pestaña Contratos por terminar para gestionar retención comercial. '
      . 'Contrato ' . ($contractCode !== '' ? $contractCode : $contractPk) . ', fecha fin ' . $endLabel . '.';

    $result = $this->createContractRetentionTicketFromContractRequest($contract, '', $employeeId, $responseText, '', '', 'contrato por terminar');
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo crear el ticket comercial de retención.'));
    }

    $this->jsonOk([
      'message' => 'Ticket comercial de retención #' . (string) ($result['ticket_id'] ?? '') . ' creado.',
      'ticket_id' => (string) ($result['ticket_id'] ?? ''),
      'ticket_url' => (string) ($result['ticket_url'] ?? ''),
      'whatsapp_queued' => (string) ($result['whatsapp_queued'] ?? 0),
    ]);
  }

  public function ajax_handler_contracts_ending_import_preview(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractsEndingPanel() || (!$this->canAccessDashboardTab('contratos_arrendamiento') && !$this->canUseDashboardAction('case_respond'))) {
      $this->jsonFail('No tienes permiso para actualizar fechas de contratos.');
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
      $this->jsonFail('Selecciona un archivo .xls o .xlsx.');
    }

    try {
      $preview = $this->contractsEndingImportPreview($file);
      $this->jsonOk($preview + [
        'message' => 'Revisa los cambios antes de aplicar.',
      ]);
    } catch (\Throwable $exception) {
      error_log('[contracts_ending_import_preview] ' . $exception->getMessage());
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_contracts_ending_import_apply(): void
  {
    $this->verifyCsrf();
    if (!$this->canWriteContractRenewal()) $this->jsonFail('No tienes permiso para actualizar fechas de contratos.');
    $changes = json_decode(wp_unslash((string) ($_POST['changes'] ?? '')), true);
    $token = trim((string) ($_POST['token'] ?? ''));
    $expires = (int) ($_POST['expires'] ?? 0);
    if (!is_array($changes) || !$changes || count($changes) > 5000) $this->jsonFail('No hay un lote válido para aplicar.');
    if ($expires < time() || !hash_equals($this->contractsEndingImportToken($changes, $expires), $token)) {
      $this->jsonFail('La previsualización venció o no es válida. Vuelve a subir el archivo.');
    }
    $service = new ContractRenewalService($this->db);
    $service->ensureSchema();
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $pdo = $this->db->pdo();
    $updated = 0;
    $seen = [];
    try {
      $pdo->beginTransaction();
      foreach ($changes as $change) {
        $id = (int) ($change['contract_id'] ?? 0);
        $newTs = (int) ($change['new_fin_contrato'] ?? 0);
        if ($id <= 0 || $newTs <= 0 || isset($seen[$id])) throw new \RuntimeException('El lote contiene contratos repetidos o inválidos.');
        $seen[$id] = true;
        $current = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [$id]);
        if (!$current || (string) ($current['fin_contrato'] ?? '') !== (string) ($change['old_fin_contrato'] ?? '')
          || (string) ($current['contrato'] ?? '') !== (string) ($change['contract_code'] ?? '')
          || (string) ($current['inmueble'] ?? '') !== (string) ($change['property_code'] ?? '')) {
          throw new \RuntimeException('El contrato #' . $id . ' cambió desde la previsualización. No se aplicó el lote; vuelve a revisarlo.');
        }
        $payload = ['fin_contrato' => (string) $newTs];
        if ($this->column_exists($table, 'cct_modified')) $payload['cct_modified'] = date('Y-m-d H:i:s');
        if (Auth::employeeId() !== '' && $this->column_exists($table, 'cct_author_id')) $payload['cct_author_id'] = Auth::employeeId();
        if ($this->db->update($table, $payload, ['_ID' => $id]) !== 1) throw new \RuntimeException('No se pudo actualizar el contrato #' . $id . '.');
        $service->audit($id, 'end_date_imported', Auth::employeeId(), ['before' => $current['fin_contrato'] ?? '', 'after' => $newTs]);
        $oldState = $service->get($id, $this->contractTerminationTimestamp($current['fin_contrato'] ?? ''));
        if ($oldState) {
          // A new end date starts a new renewal cycle; cancel reminders from the old cycle.
          $service->save($current, $newTs, null, false, (string) $oldState['reminder_days'], '', Auth::employeeId());
        }
        $updated++;
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $this->jsonFail($exception->getMessage());
    }
    $this->jsonOk(['message' => 'Fechas de fin actualizadas: ' . $updated . '.', 'updated' => $updated, 'skipped' => 0]);
  }

  /** @param array<string,mixed> $file @return array<string,mixed> */
  private function contractsEndingImportPreview(array $file): array
  {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      throw new \RuntimeException('No se pudo subir el archivo.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = basename((string) ($file['name'] ?? 'contratos.xls'));
    $size = (int) ($file['size'] ?? 0);
    $maxBytes = defined('SCM_UPLOAD_MAX_BYTES') ? (int) SCM_UPLOAD_MAX_BYTES : 10485760;
    if ($tmp === '' || !is_readable($tmp) || $size <= 0 || $size > $maxBytes) {
      throw new \RuntimeException('El archivo está vacío o supera el límite permitido.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
      throw new \RuntimeException('Formato no soportado. Sube un archivo .xls o .xlsx.');
    }

    $rows = $this->contractsEndingImportSpreadsheetRows($tmp, $name);
    if (count($rows) < 2) {
      throw new \RuntimeException('El archivo no tiene filas para procesar.');
    }
    $headers = array_map(fn($value): string => $this->contractsEndingImportNormalizeHeader((string) $value), $rows[0]);
    $contractIndex = $this->contractsEndingImportFindHeader($headers, ['no contrato', 'contrato', 'numero contrato', 'n contrato']);
    $propertyIndex = $this->contractsEndingImportFindHeader($headers, ['no inm', 'no inmueble', 'inmueble', 'numero inmueble', 'cod inmueble']);
    $endIndex = $this->contractsEndingImportFindHeader($headers, ['fin contrato', 'fecha fin contrato', 'fecha final contrato', 'fecha fin']);
    $tenantIndex = $this->contractsEndingImportFindHeader($headers, ['arrendatario', 'nombre arrendatario']);
    if ($contractIndex < 0 || $propertyIndex < 0 || $endIndex < 0) {
      throw new \RuntimeException('El archivo debe tener columnas No. Contrato, No. Inm y Fin Contrato.');
    }

    $importRows = [];
    for ($i = 1, $max = count($rows); $i < $max; $i++) {
      $row = $rows[$i];
      $contract = $this->contractsEndingImportCleanText($row[$contractIndex] ?? '');
      $property = $this->contractsEndingImportCleanText($row[$propertyIndex] ?? '');
      $endRaw = $row[$endIndex] ?? '';
      $tenant = $tenantIndex >= 0 ? $this->contractsEndingImportCleanText($row[$tenantIndex] ?? '') : '';
      if ($contract === '' && $property === '' && $this->contractsEndingImportCleanText($endRaw) === '') {
        continue;
      }
      $newTs = $this->contractsEndingImportDateTimestamp($endRaw);
      $importRows[] = [
        'line' => $i + 1,
        'contract' => $this->contractsEndingImportIdentifier($contract),
        'property' => $this->contractsEndingImportIdentifier($property),
        'tenant' => $tenant,
        'end_raw' => $this->contractsEndingImportCleanText($endRaw),
        'new_ts' => $newTs,
      ];
    }

    if ($importRows === []) {
      throw new \RuntimeException('No se encontraron filas con contratos en el archivo.');
    }

    $matches = $this->contractsEndingImportContractMatches($importRows);
    $changes = [];
    $previewRows = [];
    $stats = ['total' => count($importRows), 'changes' => 0, 'unchanged' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'invalid' => 0];
    $duplicateKeys = [];
    foreach ($importRows as $row) {
      $pair = $row['contract'] . '|' . $row['property'];
      $duplicateKeys[$pair] = ($duplicateKeys[$pair] ?? 0) + 1;
    }
    foreach ($importRows as $row) {
      $key = $row['line'];
      $candidates = $matches[$key] ?? [];
      $status = 'change';
      $note = '';
      $contractDb = [];
      $currentTs = 0;
      if ($row['contract'] === '' || $row['property'] === '' || $duplicateKeys[$row['contract'] . '|' . $row['property']] > 1) {
        $status = 'invalid';
        $note = 'Contrato e inmueble son obligatorios y la pareja no puede repetirse.';
        $stats['invalid']++;
      } elseif ((int) $row['new_ts'] <= 0) {
        $status = 'invalid';
        $note = 'Fecha fin inválida.';
        $stats['invalid']++;
      } elseif (count($candidates) === 0) {
        $status = 'unmatched';
        $note = 'No se encontró contrato con ese No. Contrato + No. Inm.';
        $stats['unmatched']++;
      } elseif (count($candidates) > 1) {
        $status = 'ambiguous';
        $note = 'Coincide con más de un contrato. No se actualizará automáticamente.';
        $stats['ambiguous']++;
      } else {
        $contractDb = $candidates[0];
        $currentTs = $this->contractTerminationTimestamp($contractDb['fin_contrato'] ?? '');
        if ($currentTs === (int) $row['new_ts']) {
          $status = 'unchanged';
          $note = 'Ya tiene la misma fecha fin.';
          $stats['unchanged']++;
        } else {
          $stats['changes']++;
          $changes[] = [
            'contract_id' => (string) ($contractDb['_ID'] ?? ''),
            'new_fin_contrato' => (string) $row['new_ts'],
            'old_fin_contrato' => (string) ($contractDb['fin_contrato'] ?? ''),
            'contract_code' => (string) ($contractDb['contrato'] ?? ''),
            'property_code' => (string) ($contractDb['inmueble'] ?? ''),
          ];
        }
      }

      $previewRows[] = [
        'line' => (string) $row['line'],
        'status' => $status,
        'note' => $note,
        'contract_id' => (string) ($contractDb['_ID'] ?? ''),
        'contrato_excel' => (string) $row['contract'],
        'inmueble_excel' => (string) $row['property'],
        'arrendatario_excel' => (string) $row['tenant'],
        'contrato_db' => trim((string) ($contractDb['contrato'] ?? '')),
        'inmueble_db' => trim((string) ($contractDb['inmueble'] ?? $contractDb['id_inmueble'] ?? $contractDb['codigo_inmueble_web'] ?? '')),
        'old_fin_label' => $currentTs > 0 ? date('d/m/Y', $currentTs) : '',
        'new_fin_label' => (int) $row['new_ts'] > 0 ? date('d/m/Y', (int) $row['new_ts']) : (string) $row['end_raw'],
      ];
    }

    $targetCounts = array_count_values(array_column($changes, 'contract_id'));
    foreach ($previewRows as &$previewRow) {
      if ($previewRow['status'] === 'change' && ($targetCounts[$previewRow['contract_id']] ?? 0) > 1) {
        $previewRow['status'] = 'ambiguous';
        $previewRow['note'] = 'Más de una fila pretende actualizar el mismo contrato. Revisa los identificadores.';
        $stats['changes']--;
        $stats['ambiguous']++;
      }
    }
    unset($previewRow);
    $changes = array_values(array_filter($changes, static fn(array $change): bool => ($targetCounts[$change['contract_id']] ?? 0) === 1));
    $expires = time() + 1800;
    return [
      'stats' => $stats,
      'rows' => $previewRows,
      'changes' => $changes,
      'token' => $this->contractsEndingImportToken($changes, $expires),
      'expires' => $expires,
      'filename' => $name,
      'generated_at' => date('d/m/Y H:i'),
    ];
  }

  /** @return array<int,array<int,mixed>> */
  private function contractsEndingImportSpreadsheetRows(string $path, string $name): array
  {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === 'xls') {
      $book = new LegacyXlsReader($path);
      if (!$book->success()) {
        throw new \RuntimeException('No se pudo leer el archivo XLS: ' . (string) $book->error());
      }
      $rowsEx = $book->rowsEx(0, 5002);
      if (count($rowsEx) > 5001) throw new \RuntimeException('El archivo supera 5.000 filas. Divide el archivo para revisar todas.');
      return array_map(function (array $row): array {
        return array_map(static fn($cell) => is_array($cell) ? ($cell['raw'] ?? $cell['value'] ?? '') : $cell, array_values($row));
      }, $rowsEx);
    }
    $book = SimpleXLSX::parseFile($path);
    if (!$book instanceof SimpleXLSX) {
      throw new \RuntimeException('No se pudo leer el archivo XLSX: ' . (string) SimpleXLSX::parseError());
    }
    $rows = $book->rows(0, 5002);
    if (count($rows) > 5001) throw new \RuntimeException('El archivo supera 5.000 filas. Divide el archivo para revisar todas.');
    return array_map(static fn(array $row): array => array_values($row), $rows);
  }

  /** @param array<int,array<string,mixed>> $importRows @return array<int,array<int,array<string,mixed>>> */
  private function contractsEndingImportContractMatches(array $importRows): array
  {
    $contracts = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string) ($row['contract'] ?? ''), $importRows))));
    if ($contracts === []) {
      return [];
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table)) {
      return [];
    }
    $columns = ['_ID', 'contrato', 'id_contrato', 'id_contrato_arrendamiento', 'inmueble', 'id_inmueble', 'codigo_inmueble_web', 'arrendatario', 'direccion', 'fin_contrato'];
    $select = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    if ($select === []) {
      return [];
    }

    $contractColumns = array_values(array_filter(['contrato'], fn($column): bool => $this->column_exists($table, $column)));
    if ($contractColumns === []) {
      return [];
    }
    $contractSet = array_fill_keys($contracts, true);
    $orderSql = $this->column_exists($table, '_ID') ? " ORDER BY CAST(COALESCE(`_ID`, 0) AS UNSIGNED) DESC" : '';
    $dbRows = $this->db->getResults("SELECT " . implode(', ', $select) . " FROM `{$table}` WHERE TRIM(LEADING '0' FROM TRIM(`contrato`)) IN (" . implode(',', array_fill(0, count($contracts), '?')) . "){$orderSql}", $contracts);
    $rowsByContract = [];
    foreach ($dbRows as $dbRow) {
      foreach ($contractColumns as $column) {
        $contractKey = $this->contractsEndingImportIdentifier($dbRow[$column] ?? '');
        if ($contractKey !== '' && isset($contractSet[$contractKey])) {
          $rowsByContract[$contractKey][] = $dbRow;
        }
      }
    }

    $matches = [];
    foreach ($importRows as $row) {
      $rowContract = (string) ($row['contract'] ?? '');
      $rowProperty = (string) ($row['property'] ?? '');
      $line = (int) ($row['line'] ?? 0);
      $seenRows = [];
      foreach (($rowsByContract[$rowContract] ?? []) as $dbRow) {
        $rowId = trim((string) ($dbRow['_ID'] ?? ''));
        $seenKey = $rowId !== '' ? $rowId : md5(json_encode($dbRow, JSON_UNESCAPED_UNICODE));
        if (isset($seenRows[$seenKey])) {
          continue;
        }
        $seenRows[$seenKey] = true;
        if ($rowProperty === '' || !$this->contractsEndingImportRowMatches($dbRow, ['inmueble', 'id_inmueble', 'codigo_inmueble_web'], $rowProperty)) {
          continue;
        }
        $matches[$line][] = $dbRow;
      }
    }
    return $matches;
  }

  /** @param array<string,mixed> $row @param array<int,string> $columns */
  private function contractsEndingImportRowMatches(array $row, array $columns, string $expected): bool
  {
    $expected = $this->contractsEndingImportIdentifier($expected);
    if ($expected === '') {
      return false;
    }
    foreach ($columns as $column) {
      if ($this->contractsEndingImportIdentifier($row[$column] ?? '') === $expected) {
        return true;
      }
    }
    return false;
  }

  private function contractsEndingImportToken(array $changes, int $expires = 0): string
  {
    if (!defined('SCM_APP_SECRET') || (string) SCM_APP_SECRET === '') throw new \RuntimeException('Falta configurar el secreto de la aplicación.');
    // Sign the ordered batch, its previous values, expiration and current actor.
    return hash_hmac('sha256', json_encode([$changes, $expires, Auth::employeeId(), Auth::userId()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) SCM_APP_SECRET);
  }

  /** @param array<int,string> $headers @param array<int,string> $aliases */
  private function contractsEndingImportFindHeader(array $headers, array $aliases): int
  {
    foreach ($headers as $index => $header) {
      foreach ($aliases as $alias) {
        if ($header === $this->contractsEndingImportNormalizeHeader($alias)) {
          return (int) $index;
        }
      }
    }
    return -1;
  }

  private function contractsEndingImportNormalizeHeader(string $value): string
  {
    $value = $this->contractsEndingImportCleanText($value);
    $value = function_exists('remove_accents') ? remove_accents($value) : strtr($value, [
      'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
      'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
  }

  private function contractsEndingImportCleanText($value): string
  {
    $text = trim(str_replace("\0", '', (string) $value));
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
  }

  private function contractsEndingImportIdentifier($value): string
  {
    $text = $this->contractsEndingImportCleanText($value);
    $text = preg_replace('/\.0$/', '', $text) ?? $text;
    $text = preg_replace('/[^0-9A-Za-z_-]+/', '', $text) ?? $text;
    return ltrim($text, '0') !== '' ? ltrim($text, '0') : $text;
  }

  private function contractsEndingImportDateTimestamp($value): int
  {
    if (is_numeric($value)) {
      $number = (float) $value;
      if ($number > 20000 && $number < 80000) {
        return (int) strtotime('1899-12-30 +' . (int) $number . ' days 00:00:00');
      }
    }
    $text = $this->contractsEndingImportCleanText($value);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $text, $m) === 1) {
      if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) return 0;
      return (int) strtotime(sprintf('%04d-%02d-%02d 00:00:00', (int) $m[3], (int) $m[2], (int) $m[1]));
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{2}:\d{2}:\d{2})?$/', $text, $m) === 1) {
      if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return 0;
      return (int) strtotime(sprintf('%04d-%02d-%02d 00:00:00', (int) $m[1], (int) $m[2], (int) $m[3]));
    }
    return 0;
  }

  public function ajax_handler_repair_followup_notice(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('quote_repair_followup')) {
      $this->jsonFail('No tienes permiso para generar seguimiento de reparaciones.');
    }

    $ticketPk = isset($_POST['ticket_pk']) ? (int) $_POST['ticket_pk'] : 0;
    $cotizacionId = isset($_POST['id_cotizacion']) ? (int) $_POST['id_cotizacion'] : 0;
    $observacion = trim(wp_kses_post(stripslashes((string) ($_POST['observacion'] ?? ''))));

    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }
    if ($cotizacionId <= 0) {
      $this->jsonFail('Cotizacion invalida.');
    }

    $service = $this->get_seguimiento_service();
    $result = $service->generateRepairFollowupNotice($ticketPk, $cotizacionId, $observacion);
    if (($result['ok'] ?? '0') !== '1') {
      $this->jsonFail((string) ($result['message'] ?? 'No se pudo generar el seguimiento de reparaciones.'));
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
    $kpis = $view->renderServiciosPublicosKpis((int)($payload['count'] ?? 0), (string)($payload['corte'] ?? ''), (array)($payload['items'] ?? []), (array)($payload['configuration_items'] ?? []));
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
    if (
      !$this->canAccessDashboardTab('cotizaciones_mantenimiento')
      && !$this->canAccessDashboardTab('preventivas_pendientes')
      && !$this->canAccessDashboardTab('servicios_publicos_pendientes')
      && !$this->canAccessDashboardTab('contractual')
      && !$this->canUseDashboardAction('case_respond')
    ) {
      $this->jsonFail('No tienes permiso para ver vencimientos administrativos.');
    }

    [$fromTs, $toTs] = $this->adminDueCalendarRange($_POST);
    $settings = $this->adminDueCalendarSettings();
    $items = $this->adminDueCalendarItems($settings, $fromTs, $toTs);
    $stats = $this->adminDueCalendarStats($items);
    $summaryGroups = !empty($_POST['include_summary'])
      ? $this->adminDueCalendarSummaryGroups($fromTs, $toTs)
      : [];

    $this->jsonOk([
      'settings' => $settings,
      'can_configure' => $this->canManageDashboardPermissions(),
      'eventos' => $items,
      'items' => $items,
      'stats' => $stats,
      'summary_groups' => $summaryGroups,
    ]);
  }

  public function ajax_handler_admin_due_case(): void
  {
    $this->verifyCsrf();
    if (
      !$this->canAccessDashboardTab('cotizaciones_mantenimiento')
      && !$this->canAccessDashboardTab('preventivas_pendientes')
      && !$this->canAccessDashboardTab('servicios_publicos_pendientes')
      && !$this->canAccessDashboardTab('contractual')
      && !$this->canUseDashboardAction('case_respond')
    ) {
      $this->jsonFail('No tienes permiso para ver vencimientos administrativos.');
    }

    $type = sanitize_key((string) ($_POST['tipo_vencimiento'] ?? $_POST['due_type'] ?? ''));
    $case = [];
    $ticketRefs = [
      trim((string) ($_POST['ticket_pk'] ?? '')),
      trim((string) ($_POST['id_ticket'] ?? '')),
      trim((string) ($_POST['ticket'] ?? '')),
    ];
    $ticketRefs = array_values(array_unique(array_filter($ticketRefs, static function ($ref): bool {
      return $ref !== '';
    })));
    if ($case === [] && in_array($type, ['calendar_ticket', 'terminacion_contrato_pendiente', 'no_prorroga_contrato_pendiente'], true) && $ticketRefs !== []) {
      $ticket = [];
      foreach ($ticketRefs as $ticketRef) {
        $ticket = $this->adminDueTicketByReference($ticketRef);
        if (!empty($ticket)) {
          break;
        }
      }
      $ticketPk = (int) ($ticket['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $case = $this->adminDueNativeTicketCasePayload($ticketPk, $this->adminDueStatusBucket($ticket));
      }
    }
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
    if ($case === [] && in_array($type, ['ticket_preventiva_sin_cita', 'preventiva_cita_sin_realizar', 'preventiva_pendiente'], true) && $this->canAccessDashboardTab('preventivas_pendientes')) {
      $ticket = [];
      foreach ($ticketRefs as $ticketRef) {
        $ticket = $this->adminDueTicketByReference($ticketRef);
        if (!empty($ticket)) {
          break;
        }
      }
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
      'preventivas_con_cita_sin_realizar_dias' => $this->adminDueDaysFromPost('preventivas_con_cita_sin_realizar_dias', 0, 0, 120),
      'preventivas_dias' => $this->adminDueDaysFromPost('preventivas_dias', 3, 1, 120),
      'cotizaciones_enviadas_sin_respuesta_dias' => $this->adminDueDaysFromPost('cotizaciones_enviadas_sin_respuesta_dias', 10, 1, 180),
      'terminacion_contrato_dias' => $this->adminDueDaysFromPost('terminacion_contrato_dias', 3, 1, 120),
      'no_prorroga_contrato_dias' => $this->adminDueDaysFromPost('no_prorroga_contrato_dias', 3, 1, 120),
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
      'preventivas_con_cita_sin_realizar_dias' => 0,
      'preventivas_dias' => 3,
      'cotizaciones_enviadas_sin_respuesta_dias' => 10,
      'terminacion_contrato_dias' => 3,
      'no_prorroga_contrato_dias' => 3,
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
      'preventivas_con_cita_sin_realizar_dias' => $this->adminDueClampDays($stored['preventivas_con_cita_sin_realizar_dias'] ?? $defaults['preventivas_con_cita_sin_realizar_dias'], 0, 120, $defaults['preventivas_con_cita_sin_realizar_dias']),
      'preventivas_dias' => $this->adminDueClampDays($stored['preventivas_dias'] ?? $defaults['preventivas_dias'], 1, 120, $defaults['preventivas_dias']),
      'cotizaciones_enviadas_sin_respuesta_dias' => $this->adminDueClampDays($stored['cotizaciones_enviadas_sin_respuesta_dias'] ?? $defaults['cotizaciones_enviadas_sin_respuesta_dias'], 1, 180, $defaults['cotizaciones_enviadas_sin_respuesta_dias']),
      'terminacion_contrato_dias' => $this->adminDueClampDays($stored['terminacion_contrato_dias'] ?? $defaults['terminacion_contrato_dias'], 1, 120, $defaults['terminacion_contrato_dias']),
      'no_prorroga_contrato_dias' => $this->adminDueClampDays($stored['no_prorroga_contrato_dias'] ?? $defaults['no_prorroga_contrato_dias'], 1, 120, $defaults['no_prorroga_contrato_dias']),
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
    if ($this->canAccessDashboardTab('servicios_publicos_pendientes')) {
      $critical = new \SCM\Modules\Pending\PublicServicesCritical($this->db);
      if ($critical->available()) foreach ($this->db->getResults('SELECT * FROM `' . $critical->table() . '` WHERE status <> ? ORDER BY deadline_at', ['verified']) as $row) {
        $due = (int)$row['deadline_at'];
        $placement = $this->adminDueCalendarPlacementTimestamp($due, $fromTs, $toTs);
        if (!$placement) continue;
        $p = json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        $item = $this->adminDueEvent([
          'id'=>'services-critical-'.$row['review_id'],'type'=>'servicios_publicos_critico','group'=>'Servicios públicos críticos · 72 horas',
          'title'=>'Pago crítico · contrato #'.$p['contract']['contrato'].' · revisión #'.$row['review_id'],
          'description'=>($row['status']==='reported'?'Pago reportado, pendiente de verificación. ':'Pendiente de pago. ').'Máximo 72 horas. Vence '.date('d/m/Y H:i',$due).' (Colombia). '.$critical->summary($p),
          'color'=>'#be123c','base_ts'=>(int)$row['created_at'],'due_ts'=>$due,'calendar_ts'=>$placement,'days_limit'=>3,
          'case'=>['critical_review_id'=>(string)$row['review_id']],
        ]);
        $item['estado'] = $due < time() ? 'Vencido' : 'Pendiente';
        $items[]=$item;
      }
    }
    if ($this->canAccessDashboardTab('cotizaciones_mantenimiento')) {
      $items = array_merge($items, $this->adminDueQuoteItems($settings, $fromTs, $toTs));
    }
    if ($this->canAccessDashboardTab('preventivas_pendientes')) {
      $items = array_merge($items, $this->adminDuePreventivaItems($settings, $fromTs, $toTs));
      $items = array_merge($items, $this->adminDuePreventivaTicketItems($settings, $fromTs, $toTs));
    }
    if ($this->canAccessDashboardTab('contractual') || $this->canUseDashboardAction('case_respond')) {
      $items = array_merge($items, $this->adminDueContractRequestItems($settings, $fromTs, $toTs));
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
  private function adminDueContractRequestItems(array $settings, int $fromTs, int $toTs): array
  {
    $items = [];
    $types = [
      [
        'requests' => $this->contractTerminationRequestItems(300, false),
        'type' => 'terminacion_contrato_pendiente',
        'group' => 'Solicitudes de terminación de contrato',
        'label' => 'Terminación de contrato',
        'days' => (int) $settings['terminacion_contrato_dias'],
        'color' => '#be123c',
      ],
      [
        'requests' => $this->contractNonRenewalRequestItems(300, false),
        'type' => 'no_prorroga_contrato_pendiente',
        'group' => 'Solicitudes de no prórroga de contrato',
        'label' => 'No prórroga de contrato',
        'days' => (int) $settings['no_prorroga_contrato_dias'],
        'color' => '#7c3aed',
      ],
    ];

    foreach ($types as $config) {
      foreach ($config['requests'] as $request) {
        if (($request['creado'] ?? '-') === '-') {
          continue;
        }
        $baseTs = strtotime((string) ($request['fecha_solicitud'] ?? '')) ?: 0;
        if ($baseTs <= 0) {
          continue;
        }
        $dueTs = strtotime('+' . $config['days'] . ' days', strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs);
        $calendarTs = $dueTs !== false ? $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs) : 0;
        if ($dueTs === false || $calendarTs <= 0) {
          continue;
        }
        $requestId = trim((string) ($request['solicitud_id'] ?? $request['ticket_pk'] ?? ''));
        $ticketLabel = trim((string) ($request['id_ticket'] ?? $request['ticket_pk'] ?? ''));
        $items[] = $this->adminDueEvent([
          'id' => $config['type'] . '-' . $requestId,
          'type' => $config['type'],
          'group' => $config['group'],
          'title' => 'Solicitud de ' . strtolower($config['label']) . ' · Ticket #' . ($ticketLabel !== '' ? $ticketLabel : '-'),
          'description' => 'Plazo operativo de respuesta: ' . $config['days'] . ' día(s) calendario desde la solicitud.',
          'color' => $config['color'],
          'base_ts' => $baseTs,
          'due_ts' => (int) $dueTs,
          'calendar_ts' => $calendarTs,
          'days_limit' => $config['days'],
          'case' => $this->adminDueLightCaseDataFromContractRequest($request),
        ]);
      }
    }
    return $items;
  }

  /** @param array<string,mixed> $request @return array<string,string> */
  private function adminDueLightCaseDataFromContractRequest(array $request): array
  {
    $ticketPk = trim((string) ($request['ticket_pk'] ?? ''));
    if ($ticketPk === '' || $ticketPk === '0') {
      return [];
    }
    return [
      'ticket_pk' => $ticketPk,
      'ticket' => trim((string) ($request['id_ticket'] ?? $ticketPk)) ?: $ticketPk,
      'asunto' => trim((string) ($request['asunto'] ?? 'Solicitud contractual')),
      'estado' => trim((string) ($request['estado'] ?? '-')),
      'admin' => trim((string) ($request['estado_administrativo'] ?? '-')),
      'contrato' => trim((string) ($request['contrato'] ?? '-')),
      'inmueble' => trim((string) ($request['inmueble'] ?? '-')),
      'direccion' => trim((string) ($request['direccion'] ?? '-')),
      'creado' => trim((string) ($request['creado'] ?? '-')),
      'tab_key' => 'contractual',
      'status_bucket' => 'abiertos',
      'case_source_html' => $this->adminDueLoadingCaseSourceHtml('Cargando detalle completo de la solicitud contractual.'),
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function adminDueCalendarSummaryGroups(int $fromTs, int $toTs): array
  {
    $groups = [];

    if ($this->canAccessDashboardTab('preventivas_pendientes')) {
      $groups[] = $this->adminDueCalendarSummaryGroup(
        'preventiva_pendiente',
        'Preventivas pendientes',
        'preventivas_pendientes',
        $this->adminDuePreventivasPendientesItems($fromTs, $toTs)
      );
    }

    if ($this->canAccessDashboardTab('servicios_publicos_pendientes')) {
      $groups[] = $this->adminDueCalendarSummaryGroup(
        'servicios_publicos_pendientes',
        'Servicios públicos pendientes',
        'servicios_publicos_pendientes',
        $this->adminDueServiciosPublicosItems($fromTs, $toTs)
      );
    }

    return $groups;
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,mixed> */
  private function adminDueCalendarSummaryGroup(string $type, string $label, string $targetTab, array $items): array
  {
    $today = date('Y-m-d');
    $vencidos = 0;
    $hoy = 0;
    foreach ($items as $item) {
      if (strtolower((string) ($item['estado'] ?? '')) === 'vencido') {
        $vencidos++;
      }
      if ((string) ($item['fecha_vencimiento'] ?? '') === $today) {
        $hoy++;
      }
    }

    return [
      'type' => $type,
      'label' => $label,
      'target_tab' => $targetTab,
      'count' => count($items),
      'vencidos' => $vencidos,
      'hoy' => $hoy,
    ];
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
    $appointmentDays = (int) ($settings['preventivas_con_cita_sin_realizar_dias'] ?? 0);
    $preventivaWhere = $this->adminDuePreventivaTicketWhereSql('t');
    $revisionAttachedWhere = $this->adminDuePreventivaRevisionAttachedWhereSql('t');
    $closedWhere = $this->adminDueOpenTicketWhereSql('t');
    $preventiveAppointmentWhere = $this->adminDuePreventivaAppointmentWhereSql('c');
    $preventiveAppointmentExistsWhere = $this->adminDuePreventivaAppointmentWhereSql('c2');
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
            AND {$revisionAttachedWhere}
            AND {$closedWhere}
            AND {$preventiveAppointmentWhere}
            AND LOWER(TRIM(COALESCE(c.`estado`, ''))) NOT IN ('si', 'sí', 'realizado', 'realizada', '1', 'true')
          ORDER BY c.`fecha_inicio` ASC
          LIMIT 2000"
      );
      foreach ($rows as $row) {
        $appointmentTs = $this->parse_unix_ts($row['_scm_cita_fecha_inicio'] ?? null);
        if ($appointmentTs <= 0) {
          continue;
        }
        $appointmentDayTs = strtotime(date('Y-m-d 00:00:00', $appointmentTs)) ?: $appointmentTs;
        $dueTs = strtotime('+' . max(0, $appointmentDays) . ' days', $appointmentDayTs) ?: $appointmentDayTs;
        $calendarTs = $this->adminDueCalendarPlacementTimestamp((int) $dueTs, $fromTs, $toTs);
        if ($calendarTs <= 0) {
          continue;
        }
        $ticketPk = trim((string) ($row['_ID'] ?? ''));
        $revisionId = trim((string) ($row['id_revision_preventiva'] ?? ''));
        $items[] = $this->adminDueEvent([
          'id' => 'prev-cita-' . $ticketPk . '-' . trim((string) ($row['_scm_cita_id'] ?? '')),
          'type' => 'preventiva_cita_sin_realizar',
          'group' => 'Preventivas con cita sin realizar',
          'title' => 'Preventiva #' . ($revisionId !== '' ? $revisionId : ($ticketPk !== '' ? $ticketPk : '-')) . ' con cita sin realizar',
          'description' => $appointmentDays > 0
            ? 'Vence ' . $appointmentDays . ' día(s) después de la cita preventiva agendada.'
            : 'Vence el día de la cita preventiva agendada.',
          'color' => '#7c3aed',
          'base_ts' => $appointmentTs,
          'due_ts' => (int) $dueTs,
          'calendar_ts' => $calendarTs,
          'days_limit' => $appointmentDays,
          'case' => $this->adminDueLightCaseDataFromPreventivaTicket($row, 'preventiva_cita_sin_realizar'),
        ]);
      }
    }

    $noAppointmentJoin = $this->table_exists($calendarTable)
      ? "AND NOT EXISTS (SELECT 1 FROM `{$calendarTable}` c2 WHERE {$calendarTicketExists} AND {$preventiveAppointmentExistsWhere})"
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

  /** @return array<int,array<string,mixed>> */
  private function adminDuePreventivasPendientesItems(int $fromTs, int $toTs): array
  {
    $controller = $this->get_pending_controller();
    $payload = $controller->buildPreventivasPayload([]);
    $items = [];
    foreach ((array) ($payload['items'] ?? []) as $item) {
      $item = (array) $item;
      $row = (array) ($item['row'] ?? []);
      $dueTs = (int) ($item['due'] ?? 0);
      if ($dueTs <= 0) {
        continue;
      }
      $calendarTs = $this->adminDueCalendarPlacementTimestamp($dueTs, $fromTs, $toTs);
      if ($calendarTs <= 0) {
        continue;
      }
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
      $ticket = is_array($item['ticket'] ?? null) ? (array) $item['ticket'] : [];
      $items[] = $this->adminDueEvent([
        'id' => 'prev-pendiente-' . ($contractPk !== '' ? $contractPk : md5((string) json_encode($row))),
        'type' => 'preventiva_pendiente',
        'group' => 'Preventivas pendientes',
        'title' => 'Preventiva pendiente contrato #' . ($contractCode !== '' ? $contractCode : '-'),
        'description' => empty($ticket) ? 'Contrato pendiente para crear ticket preventivo.' : 'Contrato con ticket preventivo activo.',
        'color' => '#14b8a6',
        'base_ts' => (int) ($item['ultima'] ?? 0),
        'due_ts' => $dueTs,
        'calendar_ts' => $calendarTs,
        'days_limit' => 0,
        'case' => !empty($ticket)
          ? $this->adminDueLightCaseDataFromPreventivaTicket($ticket, 'preventiva_pendiente')
          : $this->adminDueLightCreatePreventivaTicketData($row, $item),
      ]);
    }
    return $items;
  }

  /** @return array<int,array<string,mixed>> */
  private function adminDueServiciosPublicosItems(int $fromTs, int $toTs): array
  {
    $controller = $this->get_pending_controller();
    $payload = $controller->buildServiciosPublicosPayload([]);
    $items = [];
    $yearStartTs = strtotime(date('Y-01-01 00:00:00', $fromTs)) ?: $fromTs;
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    foreach ((array) ($payload['items'] ?? []) as $item) {
      $item = (array) $item;
      $row = (array) ($item['row'] ?? []);
      $dueTs = (int) ($item['due'] ?? 0);
      if ($dueTs <= 0) {
        continue;
      }
      if ($dueTs < $yearStartTs || $dueTs > $toTs) {
        continue;
      }
      $calendarTs = $dueTs < $fromTs ? ($todayTs >= $fromTs && $todayTs <= $toTs ? $todayTs : $fromTs) : $dueTs;
      $contractPk = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
      $fallbackId = md5((string) json_encode($row));
      $items[] = $this->adminDueEvent([
        'id' => 'serv-pub-' . ($contractPk !== '' ? $contractPk : $fallbackId),
        'type' => 'servicios_publicos_pendientes',
        'group' => 'Servicios públicos pendientes',
        'title' => 'Revisión servicios públicos contrato #' . ($contractCode !== '' ? $contractCode : '-'),
        'description' => 'Revisión de servicios públicos pendiente por realizar.',
        'color' => '#0ea5e9',
        'base_ts' => (int) ($item['ultima'] ?? 0),
        'due_ts' => $dueTs,
        'calendar_ts' => $calendarTs,
        'days_limit' => 0,
        'case' => $this->adminDueLightCaseDataFromServiciosPublicos($row, $item),
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

  private function adminDuePreventivaRevisionAttachedWhereSql(string $alias): string
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "TRIM(COALESCE({$p}`id_revision_preventiva`, '')) <> ''";
  }

  private function adminDuePreventivaAppointmentWhereSql(string $alias): string
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    $parts = [
      "LOWER(COALESCE({$p}`titulo`, '')) LIKE '%prevent%'",
      "LOWER(COALESCE({$p}`descripcion`, '')) LIKE '%prevent%'",
    ];
    if ($this->table_exists('categorias_calendario')) {
      $parts[] = "{$p}`id_categoria` IN (SELECT `id` FROM `categorias_calendario` WHERE LOWER(COALESCE(`nombre`, '')) LIKE '%prevent%')";
    }
    return '(' . implode(' OR ', $parts) . ')';
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
      'cotizacion_url' => $id !== '' ? self::signedMaintenanceQuotePublicUrl((int) $id) : '',
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
    $revisionId = trim((string) ($ticket['id_revision_preventiva'] ?? ''));
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
      'id_revision_preventiva' => $revisionId,
      'tab_key' => 'preventiva',
      'status_bucket' => $this->adminDueStatusBucket($ticket),
      'case_source_html' => $this->adminDueLoadingCaseSourceHtml(
        $type === 'preventiva_cita_sin_realizar'
          ? 'Cargando detalle completo de la cita preventiva pendiente.'
          : 'Cargando detalle completo del ticket preventivo sin cita.'
      ),
    ];
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $item @return array<string,string> */
  private function adminDueLightCaseDataFromServiciosPublicos(array $row, array $item): array
  {
    $contractPk = trim((string) ($row['_ID'] ?? ''));
    $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
    $dueTs = (int) ($item['due'] ?? 0);
    $lastTs = (int) ($item['ultima'] ?? 0);
    return [
      'public_services_review' => '1',
      'contract_pk' => $contractPk,
      'contract_code' => $contractCode !== '' ? $contractCode : $contractPk,
      'contrato' => $this->adminDueHashLabel($contractCode !== '' ? $contractCode : $contractPk),
      'inmueble' => $this->adminDueFirstText([$row], ['inmueble', 'id_inmueble']) ?: '-',
      'id_inmueble_web' => $this->adminDueFirstText([$row], ['id_inmueble', 'inmueble']) ?: '-',
      'direccion' => $this->adminDueFirstText([$row], ['direccion']) ?: '-',
      'propietario' => $this->adminDueFirstText([$row], ['propietario']),
      'arrendatario' => $this->adminDueFirstText([$row], ['arrendatario']),
      'creado' => $lastTs > 0 ? date('d/m/Y', $lastTs) : '-',
      'servicios_due' => $dueTs > 0 ? date('d/m/Y', $dueTs) : '-',
    ];
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $item @return array<string,string> */
  private function adminDueLightCreatePreventivaTicketData(array $row, array $item): array
  {
    $contractPk = trim((string) ($row['_ID'] ?? ''));
    $contractCode = trim((string) ($row['contrato'] ?? $contractPk));
    $dueTs = (int) ($item['due'] ?? 0);
    $lastTs = (int) ($item['ultima'] ?? 0);
    return [
      'admin_ticket_create' => '1',
      'ticket_mode' => 'preventiva',
      'ticket_title' => 'Crear ticket preventivo',
      'contract_pk' => $contractPk,
      'contract_code' => $contractCode,
      'contract_state' => (string) ($row['estado'] ?? ''),
      'id_inmueble' => (string) ($row['id_inmueble'] ?? ''),
      'inmueble' => (string) ($row['inmueble'] ?? ''),
      'direccion' => (string) ($row['direccion'] ?? ''),
      'barrio' => (string) ($row['barrio'] ?? ''),
      'id_arrendatario' => (string) ($row['id_arrendatario'] ?? ''),
      'arrendatario' => (string) ($row['arrendatario'] ?? ''),
      'correo_arrendatario' => (string) ($row['correo_arrendatario'] ?? ''),
      'celular_arrendatario' => (string) ($row['celular_arrendatario'] ?? ''),
      'id_propietario' => (string) ($row['id_propietario'] ?? ''),
      'propietario' => (string) ($row['propietario'] ?? ''),
      'correo_propietario' => (string) ($row['correo_propietario'] ?? ''),
      'celular_propietario' => (string) ($row['celular_propietario'] ?? ''),
      'id_sucursal' => (string) ($row['id_sucursal'] ?? $row['sucursal'] ?? ''),
      'id_inventario' => (string) ($row['id_inventario'] ?? ''),
      'registro_fotografico' => (string) ($row['registro_fotografico'] ?? ''),
      'fecha_final_contrato' => (string) ($row['fin_contrato'] ?? ''),
      'contrato' => $this->adminDueHashLabel($contractCode !== '' ? $contractCode : $contractPk),
      'creado' => $lastTs > 0 ? date('d/m/Y', $lastTs) : '-',
      'preventiva_due' => $dueTs > 0 ? date('d/m/Y', $dueTs) : '-',
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
    $settings = $this->adminDueCalendarSettings();
    $days = (int) ($settings['preventivas_con_cita_sin_realizar_dias'] ?? 0);
    $baseTs = $this->adminDueFirstTimestamp($appointment, ['fecha_inicio']);
    $baseDayTs = $baseTs > 0 ? (strtotime(date('Y-m-d 00:00:00', $baseTs)) ?: $baseTs) : 0;
    $dueTs = $baseDayTs > 0 ? (strtotime('+' . max(0, $days) . ' days', $baseDayTs) ?: $baseDayTs) : 0;
    $todayTs = strtotime(date('Y-m-d 00:00:00')) ?: time();
    return [
      'base_ts' => $baseTs,
      'due_ts' => $dueTs,
      'days_limit' => $days,
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
      'preventiva_pendiente' => 0,
      'preventiva_sin_enviar' => 0,
      'ticket_preventiva_sin_cita' => 0,
      'preventiva_cita_sin_realizar' => 0,
      'servicios_publicos_pendientes' => 0,
      'terminacion_contrato_pendiente' => 0,
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
      'cotizacion_url' => $id !== '' ? self::signedMaintenanceQuotePublicUrl((int) $id) : '',
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
      $case['cotizacion_url'] = $id !== '' ? self::signedMaintenanceQuotePublicUrl((int) $id) : '';
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
    $preventiveAppointmentWhere = $this->adminDuePreventivaAppointmentWhereSql('');
    $row = $this->db->getRow(
      "SELECT *
        FROM `{$table}`
        WHERE (" . implode(' OR ', $where) . ")
          AND {$preventiveAppointmentWhere}
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
    if (in_array($admin, ['postergado', 'postergada', 'postergados', 'en espera de respuesta'], true)) {
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
    $revisionId = trim((string) ($ticket['id_revision_preventiva'] ?? ''));
    $createdTs = $this->adminDueFirstTimestamp($ticket, ['cct_created', 'fecha']);
    $timing = $type === 'preventiva_cita_sin_realizar'
      ? $this->adminDuePreventivaAppointmentTiming($appointment)
      : $this->adminDuePreventivaTicketNoAppointmentTiming($ticket);
    $appointmentTs = $this->adminDueFirstTimestamp($appointment, ['fecha_inicio']);
    return '<section class="scm-case-history"><h4>Detalle del vencimiento preventivo</h4><article class="scm-case-history-item"><div class="scm-case-history-detail">'
      . '<p><strong>Tipo:</strong> ' . esc_html($type === 'preventiva_cita_sin_realizar' ? 'Preventiva con cita sin realizar' : 'Ticket sin cita preventiva') . '</p>'
      . '<p><strong>Ticket:</strong> #' . esc_html($ticketLabel !== '' ? $ticketLabel : '-') . '</p>'
      . '<p><strong>Revisión preventiva:</strong> ' . esc_html($revisionId !== '' ? $this->adminDueHashLabel($revisionId) : '-') . '</p>'
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
    $items = (array)($payload['items'] ?? []);
    $view = new \SCM\Modules\Pending\PendingView();
    $table = $view->renderContratosArrendamientoTable($items);
    $pagination = $view->renderContratosPagination((array)($payload['pagination'] ?? []));
    $this->jsonOk([
      'bucket' => (string)($payload['bucket'] ?? ''),
      'label' => (string)($payload['label'] ?? ''),
      'table_html' => $table,
      'pagination_html' => $pagination,
      'count' => (string)($payload['count'] ?? 0),
      'items' => $this->contratosArrendamientoPickerItems($items),
    ]);
  }

  /** @param array<int,array<string,mixed>> $items @return array<int,array<string,string>> */
  private function contratosArrendamientoPickerItems(array $items): array
  {
    $out = [];
    foreach ($items as $row) {
      $row = (array) $row;
      $id = trim((string) ($row['_ID'] ?? ''));
      $contract = trim((string) ($row['contrato'] ?? ''));
      $property = trim((string) (($row['inmueble'] ?? '') ?: ($row['id_inmueble'] ?? '')));
      $address = trim((string) ($row['direccion'] ?? ''));
      $tenant = trim((string) ($row['arrendatario'] ?? ''));
      $owner = trim((string) ($row['propietario'] ?? ''));
      $state = trim((string) ($row['estado'] ?? ''));
      if ($id === '' && $contract === '' && $property === '') {
        continue;
      }

      $parts = [];
      $parts[] = 'Contrato #' . ($contract !== '' ? $contract : $id);
      if ($property !== '') {
        $parts[] = 'Inmueble #' . $property;
      }
      if ($tenant !== '') {
        $parts[] = $tenant;
      } elseif ($owner !== '') {
        $parts[] = $owner;
      }

      $location = $address;
      if ($location === '') {
        $location = implode(' - ', array_slice($parts, 0, 2));
      }

      $out[] = [
        'id' => $id !== '' ? $id : ($contract !== '' ? $contract : $property),
        'contrato' => $contract,
        'inmueble' => $property,
        'direccion' => $address,
        'arrendatario' => $tenant,
        'propietario' => $owner,
        'estado' => $state,
        'label' => implode(' - ', $parts),
        'location' => $location,
      ];
    }

    return $out;
  }

  public function ajax_handler_revision_servicios_publicos(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('servicios_publicos_pendientes')) {
      $this->jsonFail('No tienes permiso para registrar revisiones de servicios públicos.');
    }

    $operation = sanitize_key((string) ($_POST['operation'] ?? 'load'));
    if (in_array($operation, ['critical_detail','critical_config','critical_verify'], true)) {
      try {
        $critical = new \SCM\Modules\Pending\PublicServicesCritical($this->db);
        $employee = (new \SCM\Modules\Pending\PendingRepository($this->db))->getFuncionarioByUserId(Auth::userId());
        if ($operation === 'critical_detail') $this->jsonOk(['html'=>$critical->detailHtml((int)($_POST['review_id']??0))]);
        if (!\SCM\Modules\Pending\PublicServicesCritical::admin($employee ?? [])) $this->jsonFail('Solo los administradores pueden configurar este flujo o verificar el pago.');
        if ($operation === 'critical_config') {
          $critical->saveConfig($_POST, $employee);
          $this->jsonOk(['html'=>$critical->configHtml(),'message'=>'Configuración guardada. WhatsApp solo se activa con plantillas aprobadas.']);
        }
        $critical->verify((int)($_POST['review_id']??0),(string)($_POST['decision']??''),sanitize_textarea_field(wp_unslash((string)($_POST['reason']??''))),$employee);
        $this->jsonOk(['html'=>$critical->detailHtml((int)$_POST['review_id']),'message'=>'Verificación guardada con trazabilidad.']);
      } catch (\Throwable $e) { $this->jsonFail($e->getMessage()); }
    }
    if (in_array($operation, ['templates', 'history', 'critical', 'save_template', 'preview_template'], true)) {
      try {
        $workspace = new \SCM\Modules\Pending\PublicServicesWorkspace($this->db);
        $type = sanitize_key((string) ($_POST['type'] ?? 'al_dia'));
        if ($operation === 'history') $this->jsonOk(['html' => $workspace->history($_POST)]);
        if ($operation === 'critical') $this->jsonOk(['html' => $workspace->critical($_POST)]);
        if ($operation === 'templates') $this->jsonOk(['html' => $workspace->templates($type)]);
        $templateInput = ['title' => wp_unslash((string) ($_POST['title'] ?? '')), 'body' => wp_unslash((string) ($_POST['body'] ?? '')), 'version' => (string) ($_POST['version'] ?? '')];
        if ($operation === 'preview_template') {
          $template = \SCM\Modules\Pending\PublicServicesActTemplates::validate($type, $templateInput);
          $this->jsonOk(['html' => \SCM\Modules\Pending\PublicServicesWorkspace::preview($template, $type)]);
        }
        $employee = (new \SCM\Modules\Pending\PendingRepository($this->db))->getFuncionarioByUserId(\SCM\Core\Auth::userId());
        if (!$employee || empty($employee['id_empleado'])) $this->jsonFail('No se pudo identificar al funcionario autenticado.');
        (new \SCM\Modules\Pending\PublicServicesActTemplates($this->db))->save($type, $templateInput, (int) $employee['id_empleado'], $employee['nombre']);
        $this->jsonOk(['html' => $workspace->templates($type), 'message' => 'Plantilla guardada. Se aplicará a las próximas actas.']);
      } catch (\Throwable $error) { $this->jsonFail($error->getMessage()); }
    }
    if ($operation === 'schedule_month') {
      $selection = json_decode(wp_unslash((string) ($_POST['contracts_json'] ?? '')), true);
      $result = $this->get_pending_controller()->scheduleServiciosPublicosMonth([
        'contracts' => is_array($selection) ? $selection : [],
        'target_month' => sanitize_text_field(wp_unslash((string) ($_POST['target_month'] ?? ''))),
        'reason' => sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
      ]);
      if (empty($result['ok'])) { $this->jsonFail((string) $result['message']); }
      unset($result['ok']); $this->jsonOk($result);
    }
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

    if ($operation === 'adjust_date') {
      $result = $controller->adjustServiciosPublicosReviewDate($contractId, [
        'request_token' => (string) ($_POST['request_token'] ?? ''),
        'last_review_date' => sanitize_text_field(wp_unslash((string) ($_POST['last_review_date'] ?? ''))),
        'adjustment_reason' => sanitize_textarea_field(wp_unslash((string) ($_POST['adjustment_reason'] ?? ''))),
      ]);
      if (empty($result['ok'])) { $this->jsonFail((string) ($result['message'] ?? 'No fue posible ajustar la fecha.')); }
      unset($result['ok']);
      $this->jsonOk($result);
    }

    if (!in_array($operation, ['submit', 'configure'], true)) {
      $this->jsonFail('Operación no válida.');
    }

    $input = [];
    $input['configuration_present'] = (string) ($_POST['configuration_present'] ?? '');
    $input['request_token'] = (string) ($_POST['request_token'] ?? '');
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
    if (!$this->canUseDashboardAction('case_close')) {
      $this->jsonFail('No tienes permiso para cerrar tickets.');
    }

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
    if (!$this->canUseDashboardAction('case_schedule')) {
      $this->jsonFail('No tienes permiso para agendar citas del caso.');
    }

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

  private function canAccessContractTerminationRequests(): bool
  {
    return $this->canUseDashboardAction('case_respond')
      || $this->canAccessDashboardTab('contractual')
      || $this->canAccessDashboardTab('mis_tickets')
      || $this->canAccessDashboardTab('abiertos');
  }

  /** @return array<int,array<string,mixed>> */
  private function contractTerminationRequestItems(int $limit = 150, bool $includeCase = true): array
  {
    $table = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    if (!$this->table_exists($table)) {
      return [];
    }
    [$whereSql, $args] = $this->contractTerminationWhereSql($table, 't');
    if ($whereSql === '') {
      return [];
    }
    $limit = max(1, min(300, $limit));
    $rows = $this->db->getResults(
      "SELECT t.* FROM `{$table}` t WHERE {$whereSql} " . $this->contractTerminationOrderSql($table, 't') . ' LIMIT ' . $limit,
      $args
    );

    return array_values(array_map(fn(array $row): array => $this->contractTerminationListItem($this->contractTerminationMergeSolicitudTicket($row), $includeCase), $rows));
  }

  /** @return array<int,array<string,mixed>> */
  private function contractNonRenewalRequestItems(int $limit = 150, bool $includeCase = true): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }
    $select = $this->contractTerminationTicketSelect($table);
    if ($select === []) {
      return [];
    }
    $where = $this->contractNonRenewalWhereSql($table, 't');
    if ($where === '') {
      return [];
    }
    $limit = max(1, min(300, $limit));
    $order = $this->contractTerminationOrderSql($table, 't');
    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` t WHERE {$where} {$order} LIMIT {$limit}"
    );

    return array_values(array_map(fn(array $row): array => $this->contractNonRenewalListItem($row, $includeCase), $rows));
  }

  private function contractNonRenewalPendingCount(): int
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return 0;
    }
    $where = $this->contractNonRenewalWhereSql($table, 't');
    if ($where === '') {
      return 0;
    }
    try {
      return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` t WHERE {$where}");
    } catch (\Throwable $exception) {
      error_log('[contract_non_renewal_pending_count] ' . $exception->getMessage());
      return 0;
    }
  }

  private function contractNonRenewalWhereSql(string $table, string $alias = 't'): string
  {
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($this->column_exists($table, 'tema_ayuda')) {
      $where = "LOWER(TRIM(COALESCE({$prefix}`tema_ayuda`, ''))) IN ('no prorroga de contrato', 'no prórroga de contrato')";
      if ($this->column_exists($table, 'estado')) {
        $where .= " AND LOWER(TRIM(COALESCE({$prefix}`estado`, ''))) NOT IN ('cerrado', 'cerrada', 'finalizado', 'finalizada', 'anulado', 'anulada')";
      }
      if ($this->column_exists($table, 'estado_administrativo')) {
        $where .= " AND LOWER(TRIM(COALESCE({$prefix}`estado_administrativo`, ''))) NOT IN ('finalizado', 'finalizada', 'cerrado', 'cerrada')";
      }
      return $where;
    }
    $topicCols = array_values(array_filter(
      ['tipo_pqrs', 'tema_ayuda', 'asunto'],
      fn(string $column): bool => $this->column_exists($table, $column)
    ));
    if ($topicCols === []) {
      return '';
    }
    $allTextCols = array_values(array_filter(
      ['tipo_pqrs', 'tema_ayuda', 'asunto', 'descripcion'],
      fn(string $column): bool => $this->column_exists($table, $column)
    ));
    $topicHaystack = "LOWER(CONCAT_WS(' ', " . implode(', ', array_map(fn(string $column): string => "COALESCE({$prefix}`{$column}`, '')", $topicCols)) . '))';
    $allHaystack = "LOWER(CONCAT_WS(' ', " . implode(', ', array_map(fn(string $column): string => "COALESCE({$prefix}`{$column}`, '')", $allTextCols)) . '))';
    $where = "({$topicHaystack} LIKE '%no prorroga%' OR {$topicHaystack} LIKE '%no prórroga%' OR {$topicHaystack} LIKE '%no renovacion%' OR {$topicHaystack} LIKE '%no renovación%' OR {$topicHaystack} LIKE '%no prorrogacion%' OR {$topicHaystack} LIKE '%no prorrogación%')";
    $where .= " AND {$allHaystack} NOT LIKE '%terminacion%' AND {$allHaystack} NOT LIKE '%terminación%' AND {$allHaystack} NOT LIKE '%desocupacion%' AND {$allHaystack} NOT LIKE '%desocupación%'";
    if ($this->column_exists($table, 'estado')) {
      $where .= " AND LOWER(TRIM(COALESCE({$prefix}`estado`, ''))) NOT IN ('cerrado', 'cerrada', 'finalizado', 'finalizada', 'anulado', 'anulada')";
    }
    if ($this->column_exists($table, 'estado_administrativo')) {
      $where .= " AND LOWER(TRIM(COALESCE({$prefix}`estado_administrativo`, ''))) NOT IN ('finalizado', 'finalizada', 'cerrado', 'cerrada')";
    }
    return $where;
  }

  private function contractTerminationPendingCount(): int
  {
    $table = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    if (!$this->table_exists($table)) {
      return 0;
    }
    [$whereSql, $args] = $this->contractTerminationWhereSql($table, 't');
    if ($whereSql === '') {
      return 0;
    }
    try {
      return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` t WHERE {$whereSql}", $args);
    } catch (\Throwable $exception) {
      error_log('[contract_termination_pending_count] ' . $exception->getMessage());
      return 0;
    }
  }

  /** @return array<string,mixed> */
  private function contractTerminationTicketByPk(int $ticketPk): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if ($ticketPk <= 0 || !$this->table_exists($table)) {
      return [];
    }
    $select = $this->contractTerminationTicketSelect($table);
    if ($select === []) {
      return [];
    }
    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` t WHERE t.`_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    return is_array($row) ? $row : [];
  }

  /** @return array<string,mixed> */
  private function contractTerminationSolicitudById(int $solicitudId): array
  {
    $table = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    if ($solicitudId <= 0 || !$this->table_exists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$solicitudId]);
    return is_array($row) ? $this->contractTerminationMergeSolicitudTicket($row) : [];
  }

  /** @return string[] */
  private function contractTerminationTicketSelect(string $table): array
  {
    $columns = [
      '_ID', 'id_ticket', 'tipo_pqrs', 'tema_ayuda', 'asunto', 'descripcion', 'estado', 'estado_administrativo',
      'id_contrato', 'contrato', 'id_inmueble', 'id_inmueble_data', 'codigo_inmueble_web', 'inmueble', 'direccion', 'barrio', 'ciudad',
      'fin_contrato', 'fecha_final_contrato', 'fecha_terminacion_contrato',
      'solicitante', 'solicitante_tipo', 'correo_solicitante', 'celular_solicitante',
      'arrendatario', 'correo_arrendatario', 'celular_arrendatario',
      'propietario', 'correo_propietario', 'celular_propietario',
      'empleado', 'nombre_empleado', 'id_empleado', 'correo_empleado', 'celular_empleado',
      'fecha', 'fecha_actualizacion', 'cct_created', 'cct_modified',
    ];
    $select = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $select[] = "t.`{$column}`";
      }
    }
    return $select;
  }

  /** @return array{0:string,1:array<int,mixed>} */
  private function contractTerminationWhereSql(string $table, string $alias): array
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    $where = [];
    if ($this->column_exists($table, 'tema_ayuda')) {
      $where[] = "LOWER(TRIM(COALESCE({$p}`tema_ayuda`, ''))) IN ('terminacion de contrato', 'terminación de contrato')";
    }
    if ($this->column_exists($table, 'estado')) {
      $where[] = "LOWER(TRIM(COALESCE({$p}`estado`, ''))) NOT IN ('respondida', 'respondido', 'dentro de término', 'dentro de termino', 'fuera de término', 'fuera de termino', 'cerrada', 'cerrado', 'finalizada', 'finalizado', 'anulada', 'anulado')";
    }
    return [$where !== [] ? implode(' AND ', $where) : '1 = 1', []];
  }

  private function contractTerminationOrderSql(string $table, string $alias): string
  {
    $p = trim($alias) !== '' ? trim($alias) . '.' : '';
    $parts = [];
    foreach (['fecha', 'cct_created', 'cct_modified', '_ID'] as $column) {
      if ($this->column_exists($table, $column)) {
        if (in_array($column, ['fecha', '_ID'], true)) {
          $parts[] = "{$p}`{$column}` DESC";
        } else {
          $parts[] = "COALESCE(UNIX_TIMESTAMP({$p}`{$column}`), 0) DESC";
        }
      }
    }
    return $parts !== [] ? 'ORDER BY ' . implode(', ', $parts) : '';
  }

  /** @param array<string,mixed> $solicitud @return array<string,mixed> */
  private function contractTerminationMergeSolicitudTicket(array $solicitud): array
  {
    $ticket = $this->contractTerminationTicketForSolicitud($solicitud);
    $merged = is_array($ticket) ? $ticket : [];
    $solicitudId = (int) ($solicitud['_ID'] ?? 0);
    $ticketPk = (int) ($ticket['_ID'] ?? 0);
    $logicalTicket = $this->contractTerminationFirstText([$ticket, $solicitud], ['id_ticket']);

    $merged['solicitud_id'] = $solicitudId;
    $merged['solicitud_estado'] = trim((string) ($solicitud['estado'] ?? ''));
    $merged['solicitud_fecha'] = $solicitud['fecha'] ?? '';
    $merged['solicitud_created'] = $solicitud['cct_created'] ?? '';
    $merged['motivo'] = trim((string) ($solicitud['motivo'] ?? ''));
    $merged['ticket_pk'] = $ticketPk;
    $merged['id_ticket'] = $logicalTicket;
    $merged['asunto'] = $this->contractTerminationFirstText([$ticket], ['asunto', 'tema_ayuda', 'tipo_pqrs']) ?: 'Solicitud de terminación de contrato';
    $merged['estado'] = $this->contractTerminationFirstText([$ticket], ['estado']) ?: $this->contractTerminationFirstText([$solicitud], ['estado']);
    $merged['estado_administrativo'] = $this->contractTerminationFirstText([$ticket], ['estado_administrativo']);

    foreach ([
      'id_contrato', 'contrato', 'id_inmueble', 'inmueble', 'direccion', 'barrio',
      'id_inmueble_data', 'codigo_inmueble_web',
      'id_arrendatario', 'arrendatario', 'documento_arrendatario',
      'correo_arrendatario', 'celular_arrendatario', 'fin_contrato',
    ] as $column) {
      $value = trim((string) ($solicitud[$column] ?? ''));
      if ($value !== '') {
        $merged[$column] = $value;
      }
    }
    $contract = $this->contractTerminationContractByContext($merged, $solicitud, $ticket);
    if ($contract !== []) {
      foreach ([
        'fin_contrato', 'inicio_contrato', 'id_contrato_arrendamiento', 'id_inmueble',
        'id_inmueble_data', 'codigo_inmueble_web', 'inmueble', 'direccion', 'barrio', 'ciudad', 'arrendatario', 'correo_arrendatario',
        'celular_arrendatario', 'propietario', 'correo_propietario', 'celular_propietario',
      ] as $column) {
        $current = trim((string) ($merged[$column] ?? ''));
        $value = trim((string) ($contract[$column] ?? ''));
        if ($value !== '' && ($current === '' || in_array($column, ['fin_contrato', 'inicio_contrato'], true))) {
          $merged[$column] = $value;
        }
      }
      if (trim((string) ($merged['contrato'] ?? '')) === '') {
        $merged['contrato'] = $this->contractTerminationFirstText([$contract], ['contrato', 'id_contrato', 'id_contrato_arrendamiento', '_ID']);
      }
      if (trim((string) ($merged['id_contrato'] ?? '')) === '') {
        $merged['id_contrato'] = $this->contractTerminationFirstText([$contract], ['_ID', 'id_contrato', 'id_contrato_arrendamiento']);
      }
    }
    if (trim((string) ($merged['solicitante'] ?? '')) === '' && trim((string) ($merged['arrendatario'] ?? '')) !== '') {
      $merged['solicitante'] = trim((string) $merged['arrendatario']);
    }
    if (trim((string) ($merged['correo_solicitante'] ?? '')) === '' && trim((string) ($merged['correo_arrendatario'] ?? '')) !== '') {
      $merged['correo_solicitante'] = trim((string) $merged['correo_arrendatario']);
    }
    if (trim((string) ($merged['celular_solicitante'] ?? '')) === '' && trim((string) ($merged['celular_arrendatario'] ?? '')) !== '') {
      $merged['celular_solicitante'] = trim((string) $merged['celular_arrendatario']);
    }
    return $merged;
  }

  /** @param array<string,mixed> ...$rows @return array<string,mixed> */
  private function contractTerminationContractByContext(array ...$rows): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table)) {
      return [];
    }
    foreach ($rows as $context) {
      if (array_key_exists('fin_contrato', $context) && isset($context['_ID'])) return $this->contractEndingContractByPk((string) $context['_ID']);
    }
    $contractRefs = $this->contractTerminationUniqueRefs($rows, ['contrato', 'id_contrato', 'id_contrato_arrendamiento']);
    $propertyRefs = $this->contractTerminationUniqueRefs($rows, ['id_inmueble', 'inmueble']);
    if ($contractRefs === [] && $propertyRefs === []) {
      return [];
    }
    $where = [];
    $args = [];
    foreach (['contrato', 'id_contrato', 'id_contrato_arrendamiento'] as $column) {
      if ($contractRefs !== [] && $this->column_exists($table, $column)) {
        $where[] = "`{$column}` IN (" . implode(', ', array_fill(0, count($contractRefs), '?')) . ')';
        array_push($args, ...$contractRefs);
      }
    }
    $numericRefs = array_values(array_filter($contractRefs, static fn(string $value): bool => ctype_digit($value)));
    if ($numericRefs !== [] && $this->column_exists($table, '_ID')) {
      $where[] = "`_ID` IN (" . implode(', ', array_fill(0, count($numericRefs), '?')) . ')';
      array_push($args, ...array_map('intval', $numericRefs));
    }
    foreach (['id_inmueble', 'inmueble'] as $column) {
      if ($propertyRefs !== [] && $this->column_exists($table, $column)) {
        $where[] = "`{$column}` IN (" . implode(', ', array_fill(0, count($propertyRefs), '?')) . ')';
        array_push($args, ...$propertyRefs);
      }
    }
    if ($where === []) {
      return [];
    }
    $candidates = $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . ' LIMIT 50', $args);
    if (!is_array($candidates) || $candidates === []) {
      return [];
    }
    $best = [];
    $bestScore = -1;
    foreach ($candidates as $candidate) {
      if (!is_array($candidate)) {
        continue;
      }
      $score = $this->contractTerminationContractScore($candidate, $contractRefs, $propertyRefs);
      if ($score > $bestScore) {
        $best = $candidate;
        $bestScore = $score;
      }
    }
    return $bestScore > 0 ? $best : [];
  }

  /** @param array<int,array<string,mixed>> $rows @param string[] $columns @return string[] */
  private function contractTerminationUniqueRefs(array $rows, array $columns): array
  {
    $refs = [];
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        foreach (preg_split('/[,\|]+/', (string) ($row[$column] ?? '')) ?: [] as $value) {
          $value = trim(ltrim(trim($value), '#'));
          if ($value !== '' && $value !== '-') {
            $refs[$value] = $value;
          }
        }
      }
    }
    return array_values($refs);
  }

  /** @param array<string,mixed> $candidate @param string[] $contractRefs @param string[] $propertyRefs */
  private function contractTerminationContractScore(array $candidate, array $contractRefs, array $propertyRefs): int
  {
    $score = 0;
    foreach (['contrato' => 90, 'id_contrato_arrendamiento' => 90, 'id_contrato' => 70, '_ID' => 50] as $column => $points) {
      $value = trim((string) ($candidate[$column] ?? ''));
      if ($value !== '' && in_array($value, $contractRefs, true)) {
        $score += $points;
      }
    }
    foreach (['id_inmueble', 'inmueble'] as $column) {
      $value = trim((string) ($candidate[$column] ?? ''));
      if ($value !== '' && in_array($value, $propertyRefs, true)) {
        $score += 200;
      }
    }
    if ($this->contractTerminationTimestamp($candidate['fin_contrato'] ?? '') > 0) {
      $score += 5;
    }
    return $score;
  }

  /** @param array<string,mixed> $solicitud @return array<string,mixed> */
  private function contractTerminationTicketForSolicitud(array $solicitud): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }
    $ticketRef = trim((string) ($solicitud['id_ticket'] ?? ''));
    if ($ticketRef === '') {
      return [];
    }
    $select = $this->contractTerminationTicketSelect($table);
    if ($select === []) {
      return [];
    }
    $where = [];
    $args = [];
    if ($this->column_exists($table, '_ID') && ctype_digit($ticketRef)) {
      $where[] = 't.`_ID` = ?';
      $args[] = (int) $ticketRef;
    }
    if ($this->column_exists($table, 'id_ticket')) {
      $where[] = "TRIM(COALESCE(t.`id_ticket`, '')) = ?";
      $args[] = $ticketRef;
    }
    if ($where === []) {
      return [];
    }
    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` t WHERE (" . implode(' OR ', $where) . ') LIMIT 1',
      $args
    );
    return is_array($row) ? $row : [];
  }

  private function contractTerminationMarkResponded(int $solicitudId, string $actaUrl, string $term): void
  {
    $table = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    if ($solicitudId <= 0 || !$this->table_exists($table)) {
      return;
    }
    $data = [];
    if ($this->column_exists($table, 'estado')) {
      $data['estado'] = $term === 'fuera' ? 'Fuera de término' : 'Dentro de término';
    }
    if ($actaUrl !== '' && $this->column_exists($table, 'carta_url')) {
      $data['carta_url'] = $actaUrl;
    }
    if ($actaUrl !== '' && $this->column_exists($table, 'carta_archivo')) {
      $data['carta_archivo'] = serialize([
        'tipo_respuesta' => $term === 'fuera' ? 'Fuera de término' : 'Dentro de término',
        'url' => $actaUrl,
        'fecha_respuesta' => date('Y-m-d H:i:s'),
      ]);
    }
    if ($this->column_exists($table, 'cct_modified')) {
      $data['cct_modified'] = date('Y-m-d H:i:s');
    }
    if ($data === []) {
      return;
    }
    try {
      $this->db->update($table, $data, ['_ID' => $solicitudId]);
    } catch (\Throwable $exception) {
      error_log('[contract_termination_mark_responded] ' . $exception->getMessage());
    }
  }

  /** @param array<string,mixed> $ticket */
  private function isContractTerminationTicket(array $ticket): bool
  {
    $tema = strtolower(trim((string) ($ticket['tema_ayuda'] ?? '')));
    if ($tema !== '') {
      return in_array($tema, ['terminacion de contrato', 'terminación de contrato'], true);
    }
    $haystack = strtolower($this->contractTerminationFirstText([$ticket], ['tipo_pqrs', 'asunto', 'descripcion']));
    return $haystack !== '' && (
      strpos($haystack, 'terminacion') !== false
      || strpos($haystack, 'terminación') !== false
      || strpos($haystack, 'desocupacion') !== false
      || strpos($haystack, 'desocupación') !== false
    );
  }

  /** @param array<string,mixed> $ticket */
  private function isContractNonRenewalTicket(array $ticket): bool
  {
    $tema = strtolower(trim((string) ($ticket['tema_ayuda'] ?? '')));
    if ($tema !== '') {
      return in_array($tema, ['no prorroga de contrato', 'no prórroga de contrato'], true);
    }
    $topicHaystack = strtolower(trim(implode(' ', array_map(
      fn(string $column): string => (string) ($ticket[$column] ?? ''),
      ['tipo_pqrs', 'asunto']
    ))));
    $allHaystack = strtolower(trim(implode(' ', array_map(
      fn(string $column): string => (string) ($ticket[$column] ?? ''),
      ['tipo_pqrs', 'asunto', 'descripcion']
    ))));
    if (
      $allHaystack === ''
      || strpos($allHaystack, 'terminacion') !== false
      || strpos($allHaystack, 'terminación') !== false
      || strpos($allHaystack, 'desocupacion') !== false
      || strpos($allHaystack, 'desocupación') !== false
    ) {
      return false;
    }
    return $topicHaystack !== '' && (
      strpos($topicHaystack, 'no prorroga') !== false
      || strpos($topicHaystack, 'no prórroga') !== false
      || strpos($topicHaystack, 'no renovacion') !== false
      || strpos($topicHaystack, 'no renovación') !== false
      || strpos($topicHaystack, 'no prorrogacion') !== false
      || strpos($topicHaystack, 'no prorrogación') !== false
    );
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function contractNonRenewalListItem(array $row, bool $includeCase = true): array
  {
    $createdTs = $this->adminDueFirstTimestamp($row, ['fecha', 'cct_created']);
    $ticketPk = trim((string) ($row['_ID'] ?? ''));
    $logicalTicket = $this->contractTerminationFirstText([$row], ['id_ticket', '_ID']);
    $subject = $this->contractTerminationFirstText([$row], ['asunto', 'tema_ayuda', 'tipo_pqrs']) ?: 'Solicitud de no prórroga de contrato';
    $termInfo = $this->contractTerminationTermInfo($row, $createdTs);
    $case = $includeCase && ctype_digit($ticketPk) ? $this->adminDueNativeTicketCasePayload((int) $ticketPk, $this->adminDueStatusBucket($row)) : [];
    return [
      'solicitud_id' => $ticketPk,
      'ticket_pk' => $ticketPk,
      'id_ticket' => $logicalTicket,
      'titulo' => 'Ticket #' . ($logicalTicket !== '' ? $logicalTicket : $ticketPk),
      'asunto' => $subject,
      'estado' => $this->contractTerminationFirstText([$row], ['estado']) ?: '-',
      'estado_solicitud' => 'Pendiente',
      'estado_administrativo' => $this->contractTerminationFirstText([$row], ['estado_administrativo']) ?: '-',
      'contrato' => $this->contractTerminationFirstText([$row], ['contrato', 'id_contrato']) ?: '-',
      'inmueble' => $this->contractTerminationFirstText([$row], ['inmueble', 'id_inmueble']) ?: '-',
      'direccion' => $this->contractTerminationFirstText([$row], ['direccion']) ?: '-',
      'solicitante' => $this->contractTerminationFirstText([$row], ['solicitante', 'arrendatario', 'propietario']) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y H:i', $createdTs) : '-',
      'fecha_solicitud' => $createdTs > 0 ? date('Y-m-d', $createdTs) : date('Y-m-d'),
      'fin_contrato' => $termInfo['fin_contrato'],
      'fin_contrato_label' => $termInfo['fin_contrato_label'],
      'fecha_limite_terminacion' => $termInfo['fecha_limite_terminacion'],
      'fecha_limite_label' => $termInfo['fecha_limite_label'],
      'term_status' => $termInfo['term_status'],
      'term_label' => $termInfo['term_label'],
      'term_hint' => $termInfo['term_hint'],
      'term_recommended' => $termInfo['term_recommended'],
      'recipients' => $includeCase ? $this->contractTerminationRecipientOptions($row, 'no_prorroga_contrato') : [],
      'retention_ticket' => $includeCase ? $this->contractRetentionTicketUiData($row) : [],
      'case' => $case,
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function contractRetentionEligibleFuncionarios(): array
  {
    if ($this->contractRetentionEmployeesCache !== null) return $this->contractRetentionEmployeesCache;
    $funcionarios = FuncionarioOptions::activeFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db), 'employee', true);
    return $this->contractRetentionEmployeesCache = array_values(array_filter($funcionarios, static function (array $funcionario): bool {
      $cargo = mb_strtolower(trim((string) ($funcionario['cargo'] ?? '')), 'UTF-8');
      $cargoId = trim((string) ($funcionario['id_cargo'] ?? ''));
      return $cargo === 'consultor de arriendo' || $cargoId === '13';
    }));
  }

  /** @param array<string,mixed> $ticket @return array<string,mixed> */
  private function contractRetentionTicketUiData(array $ticket): array
  {
    try {
      $funcionarios = $this->contractRetentionEligibleFuncionarios();
    } catch (\Throwable $exception) {
      error_log('[contract_retention_ticket_ui] funcionarios: ' . $exception->getMessage());
      return [
        'enabled' => false,
        'default_employee_id' => '',
        'funcionarios' => [],
        'assignment_help' => [],
      ];
    }
    $validIds = [];
    $options = [];
    foreach ($funcionarios as $funcionario) {
      $cargo = trim((string) ($funcionario['cargo'] ?? ''));
      $id = trim((string) ($funcionario['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $validIds[$id] = true;
      $name = trim((string) ($funcionario['name'] ?? $funcionario['label'] ?? $id));
      $options[] = [
        'id' => $id,
        'label' => $name,
        'name' => $name,
        'email' => trim((string) ($funcionario['email'] ?? '')),
        'phone' => trim((string) ($funcionario['phone'] ?? '')),
        'cargo' => $cargo,
      ];
    }

    $defaultEmployeeId = '';
    try {
      $defaultEmployeeId = $this->contractRetentionDefaultEmployeeId($ticket, array_keys($validIds));
    } catch (\Throwable $exception) {
      error_log('[contract_retention_ticket_ui] default_employee: ' . $exception->getMessage());
    }

    return [
      'enabled' => $options !== [],
      'default_employee_id' => $defaultEmployeeId,
      'funcionarios' => $options,
      'assignment_help' => $this->contractRetentionAssignmentHelp($ticket, $defaultEmployeeId),
    ];
  }

  /** @param array<string,mixed> $ticket @return array<int,array{label:string,value:string}> */
  private function contractRetentionAssignmentHelp(array $ticket, string $defaultEmployeeId): array
  {
    try {
      $contract = $this->contractTerminationContractByContext($ticket);
    } catch (\Throwable $exception) {
      error_log('[contract_retention_ticket_ui] assignment_help: ' . $exception->getMessage());
      $contract = [];
    }

    $help = [];
    $contractRef = $this->contractRetentionFirstCleanText([$ticket, $contract], ['contrato', 'id_contrato', 'id_contrato_arrendamiento', '_ID']);
    if ($contractRef !== '') {
      $help[] = ['label' => 'Contrato', 'value' => '#' . ltrim($contractRef, '#')];
    }

    $arrendatario = $this->contractRetentionFirstCleanText([$ticket, $contract], ['arrendatario', 'solicitante']);
    $propietario = $this->contractRetentionFirstCleanText([$ticket, $contract], ['propietario']);
    $parties = array_values(array_filter([
      $arrendatario !== '' ? 'Arrendatario: ' . $arrendatario : '',
      $propietario !== '' ? 'Propietario: ' . $propietario : '',
    ]));
    if ($parties !== []) {
      $help[] = ['label' => 'Contrato de', 'value' => implode(' / ', $parties)];
    }

    $propertyRef = $this->contractRetentionFirstCleanText([$ticket, $contract], ['inmueble', 'id_inmueble', 'codigo_inmueble_web', 'id_inmueble_data']);
    $address = $this->contractRetentionFirstCleanText([$ticket, $contract], ['direccion']);
    $propertyParts = array_values(array_filter([
      $propertyRef !== '' ? 'Inmueble ' . $propertyRef : '',
      $address,
    ]));
    if ($propertyParts !== []) {
      $help[] = ['label' => 'Inmueble', 'value' => implode(' · ', $propertyParts)];
    }

    $employee = $this->contractRetentionEmployeeFromRows([$contract, $ticket], [
      'id_empleado', 'id_funcionario', 'id_asesor', 'asesor_id', 'id_comercial', 'comercial_id',
      'id_captador', 'captador_id', 'funcionario_creador', 'id_funcionario_creador', 'cct_author_id',
    ]);
    if ($employee !== '') {
      $help[] = ['label' => 'Funcionario relacionado', 'value' => $employee];
    }

    $property = $this->contractRetentionProperty($contract ?: $ticket);
    if ($property) {
      if (trim((string) ($property['propietario'] ?? '')) !== '') $help[] = ['label' => 'Propietario del inmueble', 'value' => (string) $property['propietario']];
      $propertyEmployee = $this->contractRetentionEmployeeFromRows([$property], ['id_funcionario']);
      if ($propertyEmployee !== '') $help[] = ['label' => 'Responsable del inmueble', 'value' => $propertyEmployee];
      $help[] = ['label' => 'Recomendación', 'value' => 'Se prioriza el responsable activo del inmueble cuando su cargo permite gestionar retención.'];
    }
    $suggested = $this->contractRetentionEmployeeDisplayName($defaultEmployeeId, 'id_empleado');
    if ($suggested !== '') {
      $help[] = ['label' => 'Sugerido para asignar', 'value' => $suggested];
    }

    return $help;
  }

  /** @param array<int,array<string,mixed>> $rows @param string[] $columns */
  private function contractRetentionFirstCleanText(array $rows, array $columns): string
  {
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '' && $value !== '-') {
          return $value;
        }
      }
    }
    return '';
  }

  /** @param array<int,array<string,mixed>> $rows @param string[] $columns */
  private function contractRetentionEmployeeFromRows(array $rows, array $columns): string
  {
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value === '' || $value === '-') {
          continue;
        }
        $mode = 'id_empleado';
        $name = $this->contractRetentionEmployeeDisplayName($value, $mode);
        if ($name !== '') {
          return $name;
        }
      }
    }
    return '';
  }

  private function contractRetentionEmployeeDisplayName(string $value, string $mode): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    try {
      $employee = $this->calendarCitaFuncionarioRow($value, $mode);
      $name = trim((string) ($employee['name'] ?? ''));
      $cargo = trim((string) ($employee['cargo'] ?? ''));
      if ($name !== '') {
        return $cargo !== '' ? ($name . ' · ' . $cargo) : $name;
      }
      if ($mode !== 'internal' && ctype_digit($value)) {
        $employee = $this->calendarCitaFuncionarioRow($value, 'internal');
        $name = trim((string) ($employee['name'] ?? ''));
        $cargo = trim((string) ($employee['cargo'] ?? ''));
        if ($name !== '') {
          return $cargo !== '' ? ($name . ' · ' . $cargo) : $name;
        }
      }
    } catch (\Throwable $exception) {
      error_log('[contract_retention_ticket_ui] employee_display: ' . $exception->getMessage());
    }
    return '';
  }

  /** @param array<string,mixed> $ticket @param string[] $validEmployeeIds */
  private function contractRetentionProperty(array $contract): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->table_exists($table)) return [];
    $internal = trim((string) ($contract['id_inmueble_data'] ?? ''));
    if ($internal !== '' && ctype_digit($internal)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $internal]);
      if ($row) return $row;
    }
    $code = trim((string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''));
    if ($code === '' || !$this->column_exists($table, 'codigo')) return [];
    $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE TRIM(`codigo`) = ? LIMIT 2", [$code]);
    return count($rows) === 1 ? $rows[0] : [];
  }

  private function contractRetentionDefaultEmployeeId(array $ticket, array $validEmployeeIds): string
  {
    $valid = array_fill_keys(array_map('strval', $validEmployeeIds), true);
    $contract = $this->contractTerminationContractByContext($ticket);
    $columns = [
      'id_empleado', 'id_funcionario', 'id_asesor', 'asesor_id', 'id_comercial', 'comercial_id',
      'id_captador', 'captador_id', 'funcionario_creador', 'id_funcionario_creador', 'cct_author_id',
    ];

    $property = $this->contractRetentionProperty($contract ?: $ticket);
    foreach ([$property, $contract, $ticket] as $row) {
      foreach ($columns as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        $employeeId = $column === 'cct_author_id' ? (isset($valid[$value]) ? $value : '') : $this->contractRetentionNormalizeEmployeeId($value, $valid);
        if ($employeeId !== '') {
          return $employeeId;
        }
      }
    }

    return '';
  }

  /** @param array<string,bool> $valid */
  private function contractRetentionNormalizeEmployeeId(string $value, array $valid): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (isset($valid[$value])) {
      return $value;
    }
    if (ctype_digit($value)) {
      $func = $this->calendarCitaFuncionarioRow($value, 'internal');
      $employeeId = trim((string) ($func['id_empleado'] ?? ''));
      if ($employeeId !== '' && isset($valid[$employeeId])) {
        return $employeeId;
      }
    }
    return '';
  }

  private function canWriteContractRenewal(): bool
  {
    return $this->canAccessContractsEndingPanel() && ($this->canAccessDashboardTab('contratos_arrendamiento') || $this->canUseDashboardAction('case_respond'));
  }

  private function contractReceiptFuncionarios(): array
  {
    return $this->contractReceiptEmployeesCache ??= FuncionarioOptions::activeFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db), 'employee', true);
  }

  public function ajax_handler_contracts_ending_renewal_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canWriteContractRenewal()) $this->jsonFail('No tienes permiso para editar la gestión contractual.');
    $service = new ContractRenewalService($this->db);
    $service->ensureSchema();
    $pdo = $this->db->pdo();
    try {
      $probability = ContractRenewalService::probability(trim((string) ($_POST['probability'] ?? '')));
      $days = trim((string) ($_POST['reminder_days'] ?? '30,7,0'));
      $note = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))));
      $noExit = (string) ($_POST['no_exit'] ?? '0') === '1';
      if ($noExit && $note === '') throw new \RuntimeException('Describe el reporte o la evidencia de no salida.');
      $employee = trim((string) ($_POST['receipt_employee_id'] ?? ''));
      $active = array_column(FuncionarioOptions::activeFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db), 'employee', true), 'id');
      if ($employee !== '' && !in_array($employee, array_map('strval', $active), true)) throw new \RuntimeException('El responsable del recibo debe estar activo.');
      $pdo->beginTransaction();
      $table = $this->db->table('jet_cct_contratos_arrendamiento');
      $contract = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [(int) ($_POST['contract_pk'] ?? 0)]);
      if (!$contract) throw new \RuntimeException('Contrato no encontrado.');
      $endTs = $this->contractTerminationTimestamp($contract['fin_contrato'] ?? '');
      if ($endTs <= 0) throw new \RuntimeException('El contrato no tiene una fecha fin válida.');
      if ($noExit && !in_array(mb_strtolower(trim((string) ($contract['estado'] ?? ''))), ['entregado','por recibir'], true)) throw new \RuntimeException('No se pueden programar recordatorios para un contrato recibido o desistido.');
      $old = $service->get((int) $contract['_ID'], $endTs);
      if ($endTs !== (int) ($_POST['end_ts'] ?? 0) || (int) ($old['revision'] ?? 0) !== (int) ($_POST['revision'] ?? 0)) throw new \RuntimeException('El contrato cambió. Actualiza el listado antes de guardar.');
      if (!empty($old['no_exit']) && !$noExit && (string) ($_POST['confirm_retire'] ?? '') !== '1') throw new \RuntimeException('Confirma el retiro del reporte de no salida y la cancelación de sus recordatorios.');
      if (mb_strlen($note) > 4000) throw new \RuntimeException('Las observaciones no pueden superar 4.000 caracteres.');
      $result = $service->save($contract, $endTs, $probability, $noExit, $days, $note, Auth::employeeId(), $employee);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $this->jsonFail($exception->getMessage());
    }
    $this->jsonOk($result + ['message' => 'Gestión guardada. Recordatorios en cola: ' . $result['reminders_queued'] . '.']);
  }

  public function ajax_handler_contracts_ending_history(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractsEndingPanel()) $this->jsonFail('No tienes permiso para ver el historial.');
    $service = new ContractRenewalService($this->db);
    $service->ensureSchema();
    $this->jsonOk(['items' => $service->history((int) ($_POST['contract_pk'] ?? 0))]);
  }

  public function ajax_handler_contracts_ending_case(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessContractsEndingPanel()) $this->jsonFail('No tienes permiso para abrir el caso.');
    $contract = $this->contractEndingContractByPk((string) ($_POST['contract_pk'] ?? ''));
    if (!$contract) $this->jsonFail('Contrato no encontrado.');
    $id = (string) ($_POST['kind'] ?? '') === 'receipt' ? $this->contractEndingReceiptTicketId($contract) : $this->contractEndingRetentionTicketId($contract);
    if ($id === '') $this->jsonFail('No hay un ticket asociado a este ciclo del contrato.');
    $table = $this->db->table('jet_cct_tickets');
    $ticket = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_contrato` = ? AND `id_ticket` = ? LIMIT 1", [(string) $contract['_ID'], $id]);
    if (!$ticket) $this->jsonFail('No se encontró el ticket asociado.');
    $this->jsonOk(['case' => $this->adminDueNativeTicketCasePayload((int) $ticket['_ID'], $this->adminDueStatusBucket($ticket))]);
  }

  /** CLI domain automation; never called by rendering a panel. */
  public function processAutomaticContractReceipts(bool $dryRun = false): array
  {
    $config = \SCM\Modules\Contracts\ContractReceiptSettings::read($this->db);
    $stats = ['created' => 0, 'skipped' => 0, 'errors' => [], 'dry_run' => $dryRun, 'enabled' => (bool) $config['enabled'], 'contract_id' => (int) $config['contract_id'], 'candidates' => []];
    if (!$config['enabled']) return $stats;
    if ((int) $config['contract_id'] <= 0) throw new \RuntimeException('Configura un contrato específico para el cron.');
    $service = new ContractRenewalService($this->db);
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $receiptTable = $this->db->table('scm_contract_receipts');
    $from = strtotime('today');
    $to = strtotime('today +15 days 23:59:59');
    if (!$dryRun) {
      $service->ensureSchema();
      $service->reconcileCycles((int) $config['contract_id']);
    }
    $dateSql = "CASE WHEN TRIM(`fin_contrato`) REGEXP '^[0-9]+$' THEN CASE WHEN CAST(`fin_contrato` AS UNSIGNED) > 9999999999 THEN FLOOR(CAST(`fin_contrato` AS UNSIGNED) / 1000) ELSE CAST(`fin_contrato` AS UNSIGNED) END ELSE UNIX_TIMESTAMP(`fin_contrato`) END";
    $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` = ? AND {$dateSql} BETWEEN ? AND ? AND LOWER(TRIM(`estado`)) IN ('entregado','por recibir')", [(int) $config['contract_id'], $from, $to]);
    if ($dryRun) {
      $schema = new \SCM\Support\SchemaInspector($this->db);
      foreach ($rows as $row) {
        $endTs = $this->contractTerminationTimestamp($row['fin_contrato']);
        $state = $schema->tableExists($service->table()) ? $service->get((int) $row['_ID'], $endTs) : [];
        $reason = !empty($state['no_exit']) ? 'No salida reportada' : ((isset($state['probability']) && (float) $state['probability'] >= 100) ? 'Renovación al 100 %' : ($this->contractEndingReceiptTicketId($row) !== '' ? 'Ticket existente' : 'Dentro del plazo; validar responsable y destinatarios'));
        $stats['candidates'][] = ['contract_id' => (int) $row['_ID'], 'end_date' => date('Y-m-d', $endTs), 'employee_id' => $config['employee_id'], 'reason' => $reason];
      }
      if (!$rows) {
        $contract = $this->db->getRow("SELECT `_ID`, `fin_contrato`, `estado` FROM `{$table}` WHERE `_ID` = ?", [(int) $config['contract_id']]);
        if ($contract) $stats['next_receipt_date'] = date('Y-m-d', strtotime('-15 days', $this->contractTerminationTimestamp($contract['fin_contrato'])));
        $stats['message'] = 'El contrato configurado no está dentro del plazo o su estado no permite recibo.';
      }
      return $stats;
    }
    $receiptEmployees = FuncionarioOptions::activeFuncionarios($this->db, new \SCM\Support\SchemaInspector($this->db), 'employee', true);
    $active = array_map('strval', array_column($receiptEmployees, 'id'));
    foreach ($rows as $row) {
      $pdo = $this->db->pdo();
      try {
        $pdo->beginTransaction();
        $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [(int) $row['_ID']]);
        $endTs = $this->contractTerminationTimestamp($row['fin_contrato'] ?? '');
        $id = (int) $row['_ID'];
        $state = $service->get($id, $endTs);
        if (!in_array(mb_strtolower(trim((string) ($row['estado'] ?? ''))), ['entregado','por recibir'], true) || $endTs < $from || $endTs > $to || (isset($state['probability']) && (float) $state['probability'] >= 100) || !empty($state['no_exit'])) {
          $pdo->rollBack(); $stats['skipped']++; continue;
        }
        $existing = $this->contractEndingReceiptTicketId($row);
        if ($existing !== '' || $this->db->getRow("SELECT ticket_id FROM `{$receiptTable}` WHERE contract_id = ? AND end_ts = ?", [$id, $endTs])) {
          $pdo->rollBack(); $stats['skipped']++; continue;
        }
        $employee = (string) $config['employee_id'];
        if ($employee === '' || !in_array($employee, $active, true)) throw new \RuntimeException('Selecciona un responsable activo en Configuración → Notificaciones → Recibos automáticos.');
        if ($config['coordinator_id'] !== '' && !in_array((string) $config['coordinator_id'], $active, true)) throw new \RuntimeException('El coordinador configurado ya no está activo.');
        $assigned = array_values(array_filter($receiptEmployees, static fn(array $person): bool => (string) $person['id'] === $employee));
        if (!filter_var((string) ($assigned[0]['email'] ?? ''), FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('El funcionario asignado necesita un correo válido.');
        if (!filter_var(trim((string) ($row['correo_arrendatario'] ?? '')), FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('El arrendatario necesita un correo válido para recibir la carta.');
        if (!\SCM\Support\InternalNotificationRecipients::emailsForAction($this->db, 'contrato_recibo_automatico')) throw new \RuntimeException('Configura destinatarios internos para el recibo automático.');
        $result = $this->get_pending_controller()->createAdministrativeTicket([
          'ticket_mode' => 'administrativo', 'contract_pk' => (string) $id, 'id_empleado' => $employee,
          'solicitante_tipo' => 'arrendatario', 'prioridad' => 'Prioridad urgente', 'departamento' => 'Servicio al arrendatario',
          'tema_ayuda' => 'Recibo de inmuebles', 'asunto' => 'Recibo automático de contrato #' . (string) ($row['contrato'] ?? $id),
          'descripcion' => 'Ticket automático para coordinar el recibo 15 días antes de la terminación del contrato. Fecha fin: ' . date('d/m/Y', $endTs),
          'internal_notification_action' => 'contrato_recibo_automatico',
          'require_receipt_letter' => true, 'contractual_employee_id' => (string) $config['coordinator_id'],
        ], [], [], ['empleado', 'solicitante', 'admin']);
        if (($result['ok'] ?? '0') !== '1') throw new \RuntimeException((string) ($result['message'] ?? 'Error al crear recibo.'));
        if (!empty($result['warnings'])) throw new \RuntimeException(implode(' ', $result['warnings']));
        $this->db->insert($receiptTable, ['contract_id' => $id, 'end_ts' => $endTs, 'ticket_id' => (int) $result['ticket_id'], 'created_at' => time()]);
        $service->audit($id, 'receipt_created', 'Sistema', ['ticket_id' => $result['ticket_id'], 'end_ts' => $endTs]);
        $pdo->commit(); $stats['created']++;
      } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $stats['errors'][] = ['contract_id' => $row['_ID'] ?? '', 'message' => $exception->getMessage()];
      }
    }
    return $stats;
  }

  private function contractEndingReceiptTicketId(array $contract): string
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->column_exists($table, 'fecha_terminacion_contrato')) return '';
    $row = $this->db->getRow("SELECT `_ID`, `id_ticket` FROM `{$table}` WHERE `id_contrato` = ? AND LOWER(TRIM(`tema_ayuda`)) IN ('recibo de inmuebles','recibo de inmueble') AND `fecha_terminacion_contrato` = ? ORDER BY `_ID` DESC LIMIT 1", [(string) $contract['_ID'], (string) ($contract['fin_contrato'] ?? '')]);
    return $row ? (string) ($row['id_ticket'] ?: $row['_ID']) : '';
  }

  private function canAccessContractsEndingPanel(): bool
  {
    return $this->canAccessDashboardTab('contratos_arrendamiento')
      || $this->canAccessDashboardTab('contractual')
      || $this->canUseDashboardAction('case_respond');
  }

  /** @return array<int,array<string,mixed>> */
  private function contractsEndingMonthItems(int $year, int $month): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table) || !$this->column_exists($table, 'fin_contrato')) {
      return [];
    }
    $fromTs = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month)) ?: strtotime(date('Y-m-01 00:00:00'));
    $toTs = strtotime(date('Y-m-t 23:59:59', $fromTs)) ?: $fromTs;
    $columns = [
      '_ID', 'estado', 'contrato', 'id_contrato', 'id_contrato_arrendamiento', 'inmueble', 'id_inmueble',
      'codigo_inmueble_web', 'id_inmueble_data', 'direccion', 'barrio', 'ciudad', 'propietario', 'arrendatario',
      'correo_propietario', 'correo_arrendatario', 'celular_propietario', 'celular_arrendatario',
      'id_propietario', 'id_arrendatario', 'inicio_contrato', 'fin_contrato', 'valor_canon',
      'valor_administracion', 'id_empleado', 'id_funcionario', 'id_asesor', 'id_comercial',
      'id_captador', 'funcionario_creador', 'id_funcionario_creador', 'cct_author_id',
    ];
    $select = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    if ($select === []) {
      $select[] = '`_ID`';
    }

    $dateSql = "CASE WHEN TRIM(`fin_contrato`) REGEXP '^[0-9]+$' THEN CASE WHEN CAST(`fin_contrato` AS UNSIGNED) > 9999999999 THEN FLOOR(CAST(`fin_contrato` AS UNSIGNED) / 1000) ELSE CAST(`fin_contrato` AS UNSIGNED) END ELSE UNIX_TIMESTAMP(`fin_contrato`) END";
    $activeSql = $this->column_exists($table, 'estado') ? " AND LOWER(TRIM(`estado`)) IN ('entregado','por recibir')" : '';
    $rows = $this->db->getResults(
      "SELECT " . implode(', ', $select) . " FROM `{$table}` WHERE {$dateSql} BETWEEN ? AND ?{$activeSql} ORDER BY {$dateSql} ASC, `_ID` DESC", [$fromTs, $toTs]
    );
    if (!is_array($rows)) {
      return [];
    }

    $items = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $item = $this->contractEndingListItem($row);
      if ($item !== []) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function contractEndingListItem(array $row): array
  {
    $contractPk = $this->contractTerminationFirstText([$row], ['_ID', 'id_contrato', 'id_contrato_arrendamiento', 'contrato']);
    if ($contractPk === '') {
      return [];
    }
    $endTs = $this->contractTerminationTimestamp($row['fin_contrato'] ?? '');
    if ($endTs <= 0) {
      return [];
    }
    $contractCode = $this->contractTerminationFirstText([$row], ['contrato', 'id_contrato', 'id_contrato_arrendamiento', '_ID']);
    $property = $this->contractTerminationFirstText([$row], ['inmueble', 'id_inmueble', 'codigo_inmueble_web']);
    $address = $this->contractTerminationFirstText([$row], ['direccion']);
    $owner = $this->contractTerminationFirstText([$row], ['propietario']);
    $tenant = $this->contractTerminationFirstText([$row], ['arrendatario']);
    $existingTicket = $this->contractEndingRetentionTicketId($row);

    $renewal = (new ContractRenewalService($this->db))->get((int) $contractPk, $endTs);
    $receiptConfig = \SCM\Modules\Contracts\ContractReceiptSettings::read($this->db);
    $canon = ContractRenewalService::canon($row['valor_canon'] ?? '');
    $probability = isset($renewal['probability']) ? (float) $renewal['probability'] : null;
    return [
      'renewal' => $renewal,
      'estado_contrato' => (string) ($row['estado'] ?? ''),
      'end_ts' => $endTs,
      'receipt_ticket_id' => $this->contractEndingReceiptTicketId($row),
      'receipt_due_label' => date('d/m/Y', strtotime('-15 days', $endTs)),
      'receipt_automation_enabled' => !empty($receiptConfig['enabled']),
      'receipt_in_scope' => (int) $receiptConfig['contract_id'] === (int) $contractPk,
      'receipt_default_employee_id' => (string) $receiptConfig['employee_id'],
      'probability' => $probability,
      'canon' => $canon,
      'weighted_value' => $canon !== null && $probability !== null ? round($canon * $probability / 100, 2) : null,
      'contract_pk' => $contractPk,
      'contrato' => $contractCode,
      'inmueble' => $property,
      'direccion' => $address,
      'propietario' => $owner,
      'arrendatario' => $tenant,
      'inicio_contrato' => $this->contractEndingDateLabel($row['inicio_contrato'] ?? ''),
      'fin_contrato' => date('Y-m-d', $endTs),
      'fin_contrato_label' => date('d/m/Y', $endTs),
      'days_left' => (int) floor(($endTs - strtotime(date('Y-m-d 00:00:00'))) / 86400),
      'month_key' => date('Y-m', $endTs),
      'month_label' => $this->contractEndingMonthLabel($endTs),
      'existing_retention_ticket_id' => $existingTicket,
      'existing_retention_ticket_url' => $existingTicket !== '' ? $this->contractTicketWhatsappUrl($existingTicket) : '',
      'retention_ticket' => $this->contractRetentionTicketUiData($row),
    ];
  }

  private function contractEndingDateLabel($value): string
  {
    $ts = $this->contractTerminationTimestamp($value);
    return $ts > 0 ? date('d/m/Y', $ts) : '';
  }

  private function contractEndingMonthLabel(int $timestamp): string
  {
    $months = [
      1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
      5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
      9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $month = (int) date('n', $timestamp);
    return ($months[$month] ?? date('F', $timestamp)) . ' ' . date('Y', $timestamp);
  }

  /** @param array<string,mixed> $contract */
  private function contractEndingRetentionTicketId(array $contract): string
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable) || !$this->column_exists($ticketsTable, 'id_contrato')) {
      return '';
    }
    $contractRefs = [trim((string) ($contract['_ID'] ?? ''))];
    if ($contractRefs[0] === '') return '';
    $topicSql = $this->column_exists($ticketsTable, 'tema_ayuda')
      ? " AND LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) IN ('retencion de contrato', 'retención de contrato')"
      : '';
    $select = $this->column_exists($ticketsTable, 'id_ticket') ? '`_ID`, `id_ticket`' : '`_ID`';
    $rows = $this->db->getResults(
      "SELECT {$select} FROM `{$ticketsTable}` WHERE TRIM(COALESCE(`id_contrato`, '')) IN (" . implode(', ', array_fill(0, count($contractRefs), '?')) . "){$topicSql}" . ($this->column_exists($ticketsTable, 'fecha_terminacion_contrato') ? " AND TRIM(COALESCE(`fecha_terminacion_contrato`, '')) = ?" : '') . " ORDER BY CAST(COALESCE(`_ID`, 0) AS UNSIGNED) DESC LIMIT 1" . ($this->db->pdo()->inTransaction() ? " FOR UPDATE" : ""),
      $this->column_exists($ticketsTable, 'fecha_terminacion_contrato') ? array_merge($contractRefs, [(string) ($contract['fin_contrato'] ?? '')]) : $contractRefs
    );
    if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
      return '';
    }
    return $this->contractTerminationFirstText([$rows[0]], ['id_ticket', '_ID']);
  }

  private function contractEndingContractByPk(string $contractPk): array
  {
    $contractPk = trim($contractPk);
    if ($contractPk === '') {
      return [];
    }
    if (!ctype_digit($contractPk)) return [];
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $contractPk]) ?? [];
  }

  /** @param array<string,mixed> $ticket @return array<string,mixed> */
  private function createContractRetentionTicketFromContractRequest(array $ticket, string $term, string $employeeId, string $responseText, string $actaUrl = '', string $actaTitle = '', string $sourceLabel = 'no prórroga'): array
  {
    $service = new ContractRenewalService($this->db);
    $service->ensureSchema();
    $contract = array_key_exists('fin_contrato', $ticket) && isset($ticket['_ID']) ? $this->contractEndingContractByPk((string) $ticket['_ID']) : $this->contractTerminationContractByContext($ticket);
    $id = (int) ($contract['_ID'] ?? 0);
    if (!$id) return ['ok' => '0', 'message' => 'No se encontró el contrato.'];
    $pdo = $this->db->pdo();
    $owns = !$pdo->inTransaction();
    try {
      if ($owns) $pdo->beginTransaction();
      $table = $this->db->table('jet_cct_contratos_arrendamiento');
      $locked = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [$id]);
      if (!$locked) throw new \RuntimeException('El contrato ya no existe.');
      if (isset($locked['estado']) && !in_array(mb_strtolower(trim((string) $locked['estado'])), ['entregado', 'por recibir'], true)) throw new \RuntimeException('Solo se genera retención para contratos entregados o por recibir.');
      $state = $service->get($id, $this->contractTerminationTimestamp($locked['fin_contrato'] ?? ''));
      if (isset($state['probability']) && (float) $state['probability'] >= 100) throw new \RuntimeException('Renovación al 100 %: no se genera retención.');
      if ($this->contractEndingRetentionTicketId($locked) !== '') throw new \RuntimeException('Este contrato ya tiene ticket de retención. Actualiza el listado para abrir el caso.');
      $result = $this->createContractRetentionTicketUnlocked($ticket, $term, $employeeId, $responseText, $actaUrl, $actaTitle, $sourceLabel, $locked);
      if (($result['ok'] ?? '0') !== '1') throw new \RuntimeException((string) ($result['message'] ?? 'No se pudo crear la retención.'));
      $service->audit($id, 'retention_created', Auth::employeeId(), ['ticket_id' => $result['ticket_id'], 'end_ts' => $locked['fin_contrato'] ?? '']);
      if ($owns) $pdo->commit();
      return $result;
    } catch (\Throwable $exception) {
      if ($owns && $pdo->inTransaction()) $pdo->rollBack();
      return ['ok' => '0', 'message' => $exception->getMessage()];
    }
  }

  private function createContractRetentionTicketUnlocked(array $ticket, string $term, string $employeeId, string $responseText, string $actaUrl = '', string $actaTitle = '', string $sourceLabel = 'no prórroga', array $resolvedContract = []): array
  {
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
      return ['ok' => '0', 'message' => 'Selecciona el funcionario responsable del ticket de retención.'];
    }
    $activeIds = [];
    foreach ($this->contractRetentionEligibleFuncionarios() as $funcionario) {
      $id = trim((string) ($funcionario['id'] ?? ''));
      if ($id !== '') {
        $activeIds[$id] = true;
      }
    }
    if (!isset($activeIds[$employeeId])) {
      return ['ok' => '0', 'message' => 'El responsable debe tener activo = Si y ser consultor de arriendo o tener el cargo 13.'];
    }

    $contract = $resolvedContract ?: $this->contractTerminationContractByContext($ticket);
    $contractPk = $this->contractTerminationFirstText([$contract, $ticket], ['_ID', 'id_contrato', 'contrato']);
    if ($contractPk === '') {
      return ['ok' => '0', 'message' => 'No se encontró el contrato para crear el ticket de retención.'];
    }
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: '-';
    $contractCode = $this->contractTerminationFirstText([$contract, $ticket], ['contrato', 'id_contrato', '_ID']);
    $property = $this->contractTerminationFirstText([$contract, $ticket], ['inmueble', 'id_inmueble', 'codigo_inmueble_web']);
    $address = $this->contractTerminationFirstText([$contract, $ticket], ['direccion']);
    $status = $term === 'dentro' ? 'dentro de término' : ($term === 'fuera' ? 'fuera de término' : '');
    $sourceLabel = trim($sourceLabel) !== '' ? trim($sourceLabel) : 'solicitud contractual';
    if ($logicalTicket !== '-' && $term !== '') {
      $description = "Se crea ticket comercial de retención de contrato a partir de la {$sourceLabel} respondida en el ticket #{$logicalTicket}.\n\n";
    } else {
      $description = "Se crea ticket comercial de retención de contrato desde el control de {$sourceLabel}.\n\n";
    }
    $description .= "Objetivo: gestionar retención del contrato o iniciar búsqueda comercial para el inmueble asociado.\n";
    if ($status !== '') {
      $description .= "Clasificación de la solicitud: {$status}.\n";
    }
    if ($contractCode !== '') {
      $description .= "Contrato: {$contractCode}.\n";
    }
    if ($property !== '') {
      $description .= "Inmueble: {$property}.\n";
    }
    if ($address !== '') {
      $description .= "Dirección: {$address}.\n";
    }
    $description .= "\nRespuesta emitida:\n" . trim(strip_tags($responseText));

    $input = [
      'ticket_mode' => 'administrativo',
      'contract_pk' => $contractPk,
      'id_contrato' => $contractPk,
      'id_empleado' => $employeeId,
      'solicitante_tipo' => $this->contractRetentionRequesterType($ticket),
      'prioridad' => 'Prioridad urgente',
      'departamento' => 'Servicio al cliente',
      'tema_ayuda' => 'Retencion de contrato',
      'asunto' => 'Retención de contrato #' . ($contractCode !== '' ? $contractCode : $contractPk),
      'descripcion' => $description,
      'internal_notification_action' => 'retencion_contrato_ticket',
    ];
    $documentos = [];
    if ($actaUrl !== '') {
      $documentos[] = [
        'nombre_archivo' => $actaTitle !== '' ? $actaTitle : 'Acta de respuesta no prórroga de contrato',
        'media_archivo' => $actaUrl,
        'archivo' => $actaUrl,
      ];
    }

    $result = $this->get_pending_controller()->createAdministrativeTicket($input, [], $documentos, ['empleado', 'solicitante', 'admin']);
    if (($result['ok'] ?? '0') !== '1') {
      return $result;
    }
    $ticketId = (int) ($result['ticket_id'] ?? 0);
    $employee = $this->calendarCitaFuncionarioRow($employeeId, 'id_empleado');
    $result['whatsapp_queued'] = (string) $this->queueContractRetentionTicketWhatsApp($ticket, $ticketId, $employee, $input);
    return $result;
  }

  /** @param array<string,mixed> $ticket */
  private function contractRetentionRequesterType(array $ticket): string
  {
    $solicitante = strtolower(trim($this->contractTerminationFirstText([$ticket], ['solicitante'])));
    $arrendatario = strtolower(trim($this->contractTerminationFirstText([$ticket], ['arrendatario'])));
    if ($solicitante !== '' && $arrendatario !== '' && $solicitante === $arrendatario) {
      return 'arrendatario';
    }
    return 'propietario';
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $employee @param array<string,string> $input */
  private function queueContractRetentionTicketWhatsApp(array $ticket, int $ticketId, array $employee, array $input): int
  {
    if ($ticketId <= 0) {
      return 0;
    }
    $queue = new \SCM\Support\SmsQueue($this->db);
    $queued = 0;
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: (string) ($input['id_contrato'] ?? '-');
    $property = $this->contractTerminationFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-';
    $summary = 'Contrato ' . $contract . ', inmueble ' . $property . '. Gestionar retención o búsqueda comercial.';
    $ticketButtonSuffix = $this->contractTicketWhatsappButtonSuffix((string) $ticketId);
    $requesterType = $this->contractRetentionRequesterType($ticket);
    $requesterName = $this->contractTerminationFirstText([$ticket], [$requesterType, 'solicitante']) ?: 'cliente';
    $requesterPhone = $this->contractTerminationFirstText([$ticket], ['celular_' . $requesterType]);

    if ($requesterPhone !== '') {
      $ok = $queue->enqueue($requesterPhone, $requesterName, "Se creó el ticket comercial #{$ticketId} para gestionar la retención del contrato.", [
        'source_module' => 'retencion_contrato',
        'campaign_tag' => 'retencion_contrato',
        'categoria_mensaje' => 'informacion',
        'id_ticket' => (string) $ticketId,
        'ticket_url' => $this->contractTicketWhatsappUrl((string) $ticketId),
        'button_url_mode' => 'dynamic_suffix',
        'dedupe_key' => 'retencion_contrato_solicitante:' . $ticketId,
        'template_name' => 'scm_retencion_contrato_solicitante',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $requesterName],
              ['type' => 'text', 'text' => (string) $ticketId],
              ['type' => 'text', 'text' => $summary],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [['type' => 'text', 'text' => $ticketButtonSuffix]],
          ],
        ],
      ]);
      if ($ok) {
        $queued++;
      }
    }

    $employeePhone = trim((string) ($employee['phone'] ?? ''));
    if ($employeePhone !== '') {
      $employeeName = trim((string) ($employee['name'] ?? 'Funcionario')) ?: 'Funcionario';
      $ok = $queue->enqueue($employeePhone, $employeeName, "Se te asignó el ticket comercial #{$ticketId} de retención de contrato.", [
        'source_module' => 'retencion_contrato',
        'campaign_tag' => 'retencion_contrato',
        'categoria_mensaje' => 'informacion',
        'id_ticket' => (string) $ticketId,
        'ticket_url' => $this->contractTicketWhatsappUrl((string) $ticketId),
        'button_url_mode' => 'dynamic_suffix',
        'dedupe_key' => 'retencion_contrato_funcionario:' . $ticketId,
        'template_name' => 'scm_retencion_contrato_funcionario',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $employeeName],
              ['type' => 'text', 'text' => (string) $ticketId],
              ['type' => 'text', 'text' => $summary],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [['type' => 'text', 'text' => $ticketButtonSuffix]],
          ],
        ],
      ]);
      if ($ok) {
        $queued++;
      }
    }

    return $queued;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function contractTerminationListItem(array $row, bool $includeCase = true): array
  {
    $createdTs = $this->adminDueFirstTimestamp($row, ['solicitud_fecha', 'fecha', 'solicitud_created', 'cct_created']);
    $solicitudId = trim((string) ($row['solicitud_id'] ?? ''));
    $ticketPk = trim((string) ($row['ticket_pk'] ?? $row['_ID'] ?? ''));
    $logicalTicket = $this->contractTerminationFirstText([$row], ['id_ticket']);
    $subject = $this->contractTerminationFirstText([$row], ['asunto', 'tema_ayuda', 'tipo_pqrs']) ?: 'Solicitud de terminación de contrato';
    $termInfo = $this->contractTerminationTermInfo($row, $createdTs);
    $case = $includeCase && ctype_digit($ticketPk) ? $this->adminDueNativeTicketCasePayload((int) $ticketPk, $this->adminDueStatusBucket($row)) : [];
    return [
      'solicitud_id' => $solicitudId,
      'ticket_pk' => $ticketPk,
      'id_ticket' => $logicalTicket,
      'titulo' => 'Ticket #' . ($logicalTicket !== '' ? $logicalTicket : $ticketPk),
      'asunto' => $subject,
      'estado' => $this->contractTerminationFirstText([$row], ['estado']) ?: '-',
      'estado_solicitud' => $this->contractTerminationFirstText([$row], ['solicitud_estado']) ?: '-',
      'estado_administrativo' => $this->contractTerminationFirstText([$row], ['estado_administrativo']) ?: '-',
      'contrato' => $this->contractTerminationFirstText([$row], ['contrato', 'id_contrato']) ?: '-',
      'inmueble' => $this->contractTerminationFirstText([$row], ['inmueble', 'id_inmueble']) ?: '-',
      'direccion' => $this->contractTerminationFirstText([$row], ['direccion']) ?: '-',
      'solicitante' => $this->contractTerminationFirstText([$row], ['solicitante', 'arrendatario', 'propietario']) ?: '-',
      'creado' => $createdTs > 0 ? date('d/m/Y H:i', $createdTs) : '-',
      'fecha_solicitud' => $createdTs > 0 ? date('Y-m-d', $createdTs) : date('Y-m-d'),
      'fin_contrato' => $termInfo['fin_contrato'],
      'fin_contrato_label' => $termInfo['fin_contrato_label'],
      'fecha_limite_terminacion' => $termInfo['fecha_limite_terminacion'],
      'fecha_limite_label' => $termInfo['fecha_limite_label'],
      'term_status' => $termInfo['term_status'],
      'term_label' => $termInfo['term_label'],
      'term_hint' => $termInfo['term_hint'],
      'term_recommended' => $termInfo['term_recommended'],
      'recipients' => $includeCase ? $this->contractTerminationRecipientOptions($row) : [],
      'retention_ticket' => $includeCase ? $this->contractRetentionTicketUiData($row) : [],
      'case' => $case,
    ];
  }

  /** @param array<string,mixed> $ticket @return array<int,array<string,string|bool>> */
  private function contractTerminationRecipientOptions(array $ticket, string $internalAction = 'terminacion_contrato'): array
  {
    $options = [];
    $map = [
      'arrendatario' => ['Arrendatario', ['arrendatario'], ['correo_arrendatario'], ['celular_arrendatario']],
      'propietario' => ['Propietario', ['propietario'], ['correo_propietario'], ['celular_propietario']],
    ];
    foreach ($map as $value => $config) {
      [$label, $nameCols, $emailCols, $phoneCols] = $config;
      $name = $this->contractTerminationFirstText([$ticket], $nameCols);
      $email = $this->contractTerminationFirstText([$ticket], $emailCols);
      $phone = $this->contractTerminationFirstText([$ticket], $phoneCols);
      $options[] = [
        'value' => $value,
        'label' => $label,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'available' => $email !== '' || $phone !== '',
      ];
    }
    $adminEmails = \SCM\Support\InternalNotificationRecipients::emailsForAction($this->db, $internalAction);
    $options[] = [
      'value' => 'admin',
      'label' => 'Funcionario configurado',
      'name' => count($adminEmails) . ' destinatario(s) interno(s)',
      'email' => implode(', ', $adminEmails),
      'phone' => '',
      'available' => count($adminEmails) > 0,
    ];
    return $options;
  }

  /** @param array<int,array<string,mixed>> $rows @param string[] $columns */
  private function contractTerminationFirstText(array $rows, array $columns): string
  {
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  /** @param array<string,mixed> $creator */
  private function contractTerminationCreatorSignatureDetails(array $creator): string
  {
    $parts = [];
    $cargo = trim((string) ($creator['cargo'] ?? ''));
    $phone = trim((string) ($creator['phone'] ?? ''));
    if ($cargo !== '') {
      $parts[] = $cargo;
    }
    if ($phone !== '') {
      $parts[] = 'Cel. ' . $phone;
    }
    $parts[] = 'SKC SuCasa Inmobiliaria';
    return implode(' | ', array_values(array_unique($parts)));
  }

  private function contractTerminationCreatorSignatureInline(string $creatorName, string $creatorDetails): string
  {
    $parts = [$creatorName];
    foreach (explode('|', $creatorDetails) as $part) {
      $part = trim($part);
      if ($part !== '' && !in_array($part, $parts, true)) {
        $parts[] = $part;
      }
    }
    $signature = preg_replace('/\s+/', ' ', implode(' - ', $parts));
    return trim(is_string($signature) ? $signature : $creatorName);
  }

  /** @param array<string,mixed> $ticket */
  private function contractTerminationResponseText(array $ticket, string $term, string $requestDate, string $endDate): string
  {
    $recipient = $this->contractTerminationFirstText([$ticket], ['solicitante', 'arrendatario', 'propietario']) ?: 'cliente';
    $address = $this->contractTerminationFirstText([$ticket], ['direccion']) ?: 'el inmueble relacionado';
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $requestLabel = $this->contractTerminationHumanDate($requestDate);
    $endLabel = $this->contractTerminationHumanDate($endDate);

    if ($term === 'dentro') {
      $text = "Cartagena de Indias D.T. y C., " . date('d/m/Y') . "\n\n";
      $text .= "Señor(a): {$recipient}\n\n";
      $text .= "SKC SuCasa Inmobiliaria, en calidad de administradora del inmueble ubicado en {$address}, da respuesta a su solicitud de terminación del contrato de arrendamiento #{$contract}. ";
      $text .= "De acuerdo con la comunicación recibida el {$requestLabel}, la solicitud fue presentada dentro del término establecido, con no menos de tres (3) meses de antelación cuando aplique.\n\n";
      $text .= "En consecuencia, el contrato finalizará el día {$endLabel}, fecha en la cual deberá realizarse la entrega material del inmueble.\n\n";
      $text .= "Para la entrega debe contactar al coordinador de mantenimiento y presentar, con quince (15) días de anticipación, los últimos recibos de servicios públicos cancelados, paz y salvo de canon de arrendamiento, expensas de administración y cualquier otro valor pendiente. También deberá permitir la revisión previa del inmueble para determinar reparaciones o detalles necesarios para la entrega.\n\n";
      $text .= "Le recordamos que el inmueble debe entregarse en las mismas condiciones en que fue recibido, conforme al inventario que hace parte del contrato de arrendamiento.";
    } else {
      $text = "Cartagena de Indias D.T. y C., " . date('d/m/Y') . "\n\n";
      $text .= "Señor(a): {$recipient}\n\n";
      $text .= "Cordial saludo.\n\n";
      $text .= "SKC SuCasa Inmobiliaria, en calidad de administradora del inmueble ubicado en {$address}, da respuesta a la comunicación recibida el {$requestLabel}, mediante la cual manifiesta su intención de dar por terminado el contrato de arrendamiento #{$contract}.\n\n";
      $text .= "La solicitud se encuentra fuera de término frente a las condiciones del contrato. Por lo anterior, la terminación anticipada no es viable en los términos planteados y podrá generar a su cargo la sanción contractual equivalente al valor de tres (3) cánones de arrendamiento vigentes, o la continuidad hasta la fecha estipulada contractualmente.\n\n";
      $text .= "Sin perjuicio de lo anterior, se dará traslado al área comercial para intentar, sin compromiso de nuestra parte, conseguir un posible nuevo arrendatario que permita estudiar una cesión del contrato. En caso de lograrse, se informará oportunamente.";
    }
    return $text;
  }

  /** @param array<string,mixed> $ticket @return array{title:string,url:string,path:string} */
  private function generateContractTerminationActa(array $ticket, string $term, string $responseText, string $requestDate, string $endDate, string $creatorName, string $creatorDetails): array
  {
    if (!defined('SCM_UPLOAD_PATH')) {
      throw new \RuntimeException('No está configurada la ruta de almacenamiento.');
    }
    $ticketPk = (int) ($ticket['_ID'] ?? 0);
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: (string) $ticketPk;
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $property = $this->contractTerminationFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-';
    $title = $term === 'dentro'
      ? 'Acta de terminación dentro de término'
      : 'Acta de terminación fuera de término';
    $safeName = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $safeName;

    $pdf = new \SCM\Support\SimplePdf();
    $pdf->actaDesign('Gestión contractual');
    $membrete = defined('SCM_RESOURCES_PATH') ? SCM_RESOURCES_PATH . '/assets/membrete-sucasa.jpg' : '';
    if ($membrete !== '' && is_file($membrete)) {
      $pdf->backgroundImage($membrete);
    }
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Terminación de contrato');
    $pdf->actaHeader($title, 'Ticket #' . $logicalTicket . ' · Contrato #' . $contract . ' · Inmueble ' . $property, $term === 'dentro' ? 'Dentro de término' : 'Fuera de término');
    $pdf->sectionTitle('Respuesta emitida');
    foreach (preg_split('/\n{2,}/', $responseText) ?: [] as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $pdf->paragraph($paragraph, 8);
      }
    }
    $pdf->signatureGroup([
      ['label' => 'Atentamente', 'name' => $creatorName, 'details' => $creatorDetails !== '' ? $creatorDetails : 'SKC SuCasa Inmobiliaria'],
    ]);
    $pdf->save($path);

    return [
      'title' => $title . ' - Ticket #' . $logicalTicket,
      'url' => \SCM\Support\StoredFileService::fromRuntime()->urlFor($safeName),
      'path' => $path,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function contractNonRenewalResponseText(array $ticket, string $term, string $requestDate, string $endDate): string
  {
    $recipient = $this->contractTerminationFirstText([$ticket], ['solicitante', 'arrendatario', 'propietario']) ?: 'cliente';
    $address = $this->contractTerminationFirstText([$ticket], ['direccion']) ?: 'el inmueble relacionado';
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $requestLabel = $this->contractTerminationHumanDate($requestDate);
    $endLabel = $this->contractTerminationHumanDate($endDate);

    $text = "Cartagena de Indias D.T. y C., " . date('d/m/Y') . "\n\n";
    $text .= "Señor(a): {$recipient}\n\n";
    $text .= "SKC SuCasa Inmobiliaria, en calidad de administradora del inmueble ubicado en {$address}, da respuesta a la solicitud relacionada con la no prórroga del contrato de arrendamiento #{$contract}. ";
    if ($term === 'dentro') {
      $text .= "De acuerdo con la comunicación recibida el {$requestLabel}, la solicitud fue presentada dentro del término establecido para informar la no prórroga del contrato.\n\n";
      $text .= "En consecuencia, se deja constancia de la no prórroga y el contrato conservará su vigencia hasta el día {$endLabel}, fecha en la cual deberán adelantarse las actuaciones de cierre, entrega o restitución que correspondan según el contrato y la normatividad aplicable.";
    } else {
      $text .= "La comunicación recibida el {$requestLabel} se encuentra fuera del término previsto para informar la no prórroga del contrato.\n\n";
      $text .= "Por lo anterior, la solicitud no produce los efectos esperados en los términos planteados y el contrato podrá entenderse prorrogado o sujeto a las consecuencias contractuales y legales aplicables, sin perjuicio de las validaciones adicionales que realice el área encargada.";
    }
    return $text;
  }

  /** @param array<string,mixed> $ticket @return array{title:string,url:string,path:string} */
  private function generateContractNonRenewalActa(array $ticket, string $term, string $responseText, string $creatorName, string $creatorDetails): array
  {
    if (!defined('SCM_UPLOAD_PATH')) {
      throw new \RuntimeException('No está configurada la ruta de almacenamiento.');
    }
    $ticketPk = (int) ($ticket['_ID'] ?? 0);
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: (string) $ticketPk;
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $property = $this->contractTerminationFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-';
    $title = $term === 'dentro'
      ? 'Acta de no prórroga dentro de término'
      : 'Acta de no prórroga fuera de término';
    $safeName = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $safeName;

    $pdf = new \SCM\Support\SimplePdf();
    $pdf->actaDesign('Gestión contractual');
    $membrete = defined('SCM_RESOURCES_PATH') ? SCM_RESOURCES_PATH . '/assets/membrete-sucasa.jpg' : '';
    if ($membrete !== '' && is_file($membrete)) {
      $pdf->backgroundImage($membrete);
    }
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - No prórroga de contrato');
    $pdf->actaHeader($title, 'Ticket #' . $logicalTicket . ' · Contrato #' . $contract . ' · Inmueble ' . $property, $term === 'dentro' ? 'Dentro de término' : 'Fuera de término');
    $pdf->sectionTitle('Respuesta emitida');
    foreach (preg_split('/\n{2,}/', $responseText) ?: [] as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $pdf->paragraph($paragraph, 8);
      }
    }
    $pdf->signatureGroup([
      ['label' => 'Atentamente', 'name' => $creatorName, 'details' => $creatorDetails !== '' ? $creatorDetails : 'SKC SuCasa Inmobiliaria'],
    ]);
    $pdf->save($path);

    return [
      'title' => $title . ' - Ticket #' . $logicalTicket,
      'url' => \SCM\Support\StoredFileService::fromRuntime()->urlFor($safeName),
      'path' => $path,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function contractTerminationInsertPropertyHistory(array $ticket, string $term, string $responseText, string $actaUrl, string $creatorName): void
  {
    $histTable = $this->db->table('jet_cct_historial_del_inmueble');
    $schema = new \SCM\Support\SchemaInspector($this->db);
    if (!$schema->tableExists($histTable)) {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = Auth::userId();
    $employeeId = (string) $userId;
    $funcTable = $this->db->table('jet_cct_funcionarios');
    if ($userId > 0 && $this->table_exists($funcTable)) {
      $func = $this->db->getRow("SELECT * FROM `{$funcTable}` WHERE `_ID` = ? LIMIT 1", [$userId]);
      if (is_array($func) && trim((string) ($func['id_empleado'] ?? '')) !== '') {
        $employeeId = trim((string) $func['id_empleado']);
      }
    }

    $status = $term === 'dentro' ? 'dentro de término' : 'fuera de término';
    $title = $term === 'dentro' ? 'Respuesta dentro de término' : 'Respuesta fuera de término';
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']);
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']);
    $property = $this->contractTerminationFirstText([$ticket], ['codigo_inmueble_web', 'codigo', 'id_inmueble', 'inmueble']);
    $propertyData = $this->contractTerminationFirstText([$ticket], ['id_inmueble_data', 'inmueble']);
    $detail = 'Se emitió respuesta a la solicitud de terminación de contrato clasificada como ' . $status . '.';
    if ($contract !== '') {
      $detail .= ' Contrato #' . $contract . '.';
    }
    if ($actaUrl !== '') {
      $detail .= ' Acta generada y anexada al caso.';
    }

    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'id_inmueble' => $property,
      'id_inmueble_data' => $propertyData !== '' ? $propertyData : $property,
      'fecha' => $nowTs,
      'tipo_de_reporte_his' => $title,
      'tipo_reporte' => $title,
      'observacion_his' => $detail,
      'observacion' => $detail,
      'respuesta' => $responseText,
      'funcionario' => $creatorName,
      'id_ticket' => $logicalTicket !== '' ? $logicalTicket : $this->contractTerminationFirstText([$ticket], ['_ID']),
      'contrato' => $contract,
      'id_contrato' => $contract,
    ];
    if ($actaUrl !== '') {
      $payload['archivos'] = serialize([[
        'nombre_archivo' => 'Acta de terminación ' . $status,
        'media_archivo' => $actaUrl,
        'archivo' => $actaUrl,
      ]]);
    }

    $payload = $schema->filterTableData($histTable, $payload);
    if (!empty($payload)) {
      $this->db->insert($histTable, $payload);
    }
  }

  /** @param array<string,mixed> $ticket */
  private function contractNonRenewalInsertPropertyHistory(array $ticket, string $term, string $responseText, string $actaUrl, string $creatorName): void
  {
    $histTable = $this->db->table('jet_cct_historial_del_inmueble');
    $schema = new \SCM\Support\SchemaInspector($this->db);
    if (!$schema->tableExists($histTable)) {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = Auth::userId();
    $employeeId = (string) $userId;
    $funcTable = $this->db->table('jet_cct_funcionarios');
    if ($userId > 0 && $this->table_exists($funcTable)) {
      $func = $this->db->getRow("SELECT * FROM `{$funcTable}` WHERE `_ID` = ? LIMIT 1", [$userId]);
      if (is_array($func) && trim((string) ($func['id_empleado'] ?? '')) !== '') {
        $employeeId = trim((string) $func['id_empleado']);
      }
    }

    $status = $term === 'dentro' ? 'dentro de término' : 'fuera de término';
    $title = $term === 'dentro' ? 'No prórroga dentro de término' : 'No prórroga fuera de término';
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']);
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']);
    $property = $this->contractTerminationFirstText([$ticket], ['codigo_inmueble_web', 'codigo', 'id_inmueble', 'inmueble']);
    $propertyData = $this->contractTerminationFirstText([$ticket], ['id_inmueble_data', 'inmueble']);
    $detail = 'Se emitió respuesta a la solicitud de no prórroga de contrato clasificada como ' . $status . '.';
    if ($contract !== '') {
      $detail .= ' Contrato #' . $contract . '.';
    }
    if ($actaUrl !== '') {
      $detail .= ' Acta generada y anexada al caso.';
    }

    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'id_inmueble' => $property,
      'id_inmueble_data' => $propertyData !== '' ? $propertyData : $property,
      'fecha' => $nowTs,
      'tipo_de_reporte_his' => $title,
      'tipo_reporte' => $title,
      'observacion_his' => $detail,
      'observacion' => $detail,
      'respuesta' => $responseText,
      'funcionario' => $creatorName,
      'id_ticket' => $logicalTicket !== '' ? $logicalTicket : $this->contractTerminationFirstText([$ticket], ['_ID']),
      'contrato' => $contract,
      'id_contrato' => $contract,
    ];
    if ($actaUrl !== '') {
      $payload['archivos'] = serialize([[
        'nombre_archivo' => 'Acta de no prórroga ' . $status,
        'media_archivo' => $actaUrl,
        'archivo' => $actaUrl,
      ]]);
    }

    $payload = $schema->filterTableData($histTable, $payload);
    if (!empty($payload)) {
      $this->db->insert($histTable, $payload);
    }
  }

  /** @param array<string,mixed> $ticket @param string[] $notifyTargets @return array{email:int,whatsapp:int} */
  private function notifyContractTerminationActa(array $ticket, string $term, string $responseText, string $actaUrl, array $notifyTargets, string $creatorSignature): array
  {
    $targets = array_values(array_unique($notifyTargets));
    if ($actaUrl === '' || in_array('none', $targets, true) || $targets === []) {
      return ['email' => 0, 'whatsapp' => 0];
    }
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: '-';
    $subject = 'Respuesta solicitud de terminación de contrato - Ticket #' . $logicalTicket;
    $status = $term === 'dentro' ? 'dentro de término' : 'fuera de término';
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $property = $this->contractTerminationFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-';
    $address = $this->contractTerminationFirstText([$ticket], ['direccion']) ?: 'dirección registrada';
    $emailRecipients = $this->contractTerminationNotificationEmails($ticket, $targets);
    $html = \SCM\Support\EmailTemplate::render('Respuesta solicitud de terminación de contrato', nl2br(\SCM\Support\EmailTemplate::e($responseText)), [
      'buttons' => [['url' => $actaUrl, 'label' => 'Ver acta generada']],
    ]);
    $emailQueued = $emailRecipients !== []
      ? (new \SCM\Support\EmailQueue($this->db))->enqueue(array_values($emailRecipients), $subject, $html, [
        'source_module' => 'terminacion_contrato',
        'destination_name' => '',
        'dedupe_key' => 'terminacion_contrato:' . $logicalTicket . ':' . $term,
        'meta' => ['id_ticket' => $logicalTicket, 'acta_url' => $actaUrl, 'termino' => $status],
      ])
      : 0;

    $whatsappQueued = 0;
    foreach ($this->contractTerminationNotificationPhones($ticket, $targets) as $recipient) {
      try {
        $phone = (string) ($recipient['phone'] ?? '');
        $name = (string) ($recipient['name'] ?? 'cliente');
        $buttonSuffix = $this->contractTerminationWhatsappButtonSuffix($actaUrl);
        $message = "Buen dia, {$name}.\n\nSKC SuCasa Inmobiliaria emitio respuesta a la solicitud de terminacion del contrato #{$contract}, inmueble {$property}, direccion {$address}, asociada al ticket #{$logicalTicket}.\n\nLa solicitud fue clasificada como {$status}. Puedes consultar el acta en el boton.\n\nAtentamente,\n{$creatorSignature}";
        $smsQueue = new \SCM\Support\SmsQueue($this->db);
        $ok = $smsQueue->enqueue($phone, $name, $message, [
          'source_module' => 'terminacion_contrato',
          'campaign_tag' => 'terminacion_contrato',
          'categoria_mensaje' => 'informacion',
          'id_ticket' => $logicalTicket,
          'acta_url' => $actaUrl,
          'button_url_mode' => 'dynamic_suffix',
          'dedupe_key' => 'terminacion_contrato:' . $logicalTicket . ':' . $term,
          'template_name' => 'scm_terminacion_contrato_respuesta_v3',
          'template_language' => 'es_CO',
          'template_components' => [
            [
              'type' => 'body',
              'parameters' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'text', 'text' => $contract],
                ['type' => 'text', 'text' => $property],
                ['type' => 'text', 'text' => $address],
                ['type' => 'text', 'text' => $logicalTicket],
                ['type' => 'text', 'text' => $status],
                ['type' => 'text', 'text' => $creatorSignature],
              ],
            ],
            [
              'type' => 'button',
              'sub_type' => 'url',
              'index' => '0',
              'parameters' => [['type' => 'text', 'text' => $buttonSuffix]],
            ],
          ],
        ]);
        if ($ok) {
          $whatsappQueued++;
        }
      } catch (\Throwable $exception) {
        error_log('[contract_termination_whatsapp] ' . $exception->getMessage());
      }
    }

    return ['email' => $emailQueued, 'whatsapp' => $whatsappQueued];
  }

  /** @param array<string,mixed> $ticket @param string[] $notifyTargets @return array{email:int,whatsapp:int} */
  private function notifyContractNonRenewalActa(array $ticket, string $term, string $responseText, string $actaUrl, array $notifyTargets, string $creatorSignature): array
  {
    $targets = array_values(array_unique($notifyTargets));
    if ($actaUrl === '' || in_array('none', $targets, true) || $targets === []) {
      return ['email' => 0, 'whatsapp' => 0];
    }
    $logicalTicket = $this->contractTerminationFirstText([$ticket], ['id_ticket', '_ID']) ?: '-';
    $subject = 'Respuesta solicitud de no prórroga de contrato - Ticket #' . $logicalTicket;
    $status = $term === 'dentro' ? 'dentro de término' : 'fuera de término';
    $contract = $this->contractTerminationFirstText([$ticket], ['contrato', 'id_contrato']) ?: '-';
    $property = $this->contractTerminationFirstText([$ticket], ['inmueble', 'id_inmueble']) ?: '-';
    $address = $this->contractTerminationFirstText([$ticket], ['direccion']) ?: 'dirección registrada';
    $emailRecipients = $this->contractTerminationNotificationEmails($ticket, $targets, 'no_prorroga_contrato');
    $html = \SCM\Support\EmailTemplate::render('Respuesta solicitud de no prórroga de contrato', nl2br(\SCM\Support\EmailTemplate::e($responseText)), [
      'buttons' => [['url' => $actaUrl, 'label' => 'Ver acta generada']],
    ]);
    $emailQueued = $emailRecipients !== []
      ? (new \SCM\Support\EmailQueue($this->db))->enqueue(array_values($emailRecipients), $subject, $html, [
        'source_module' => 'no_prorroga_contrato',
        'destination_name' => '',
        'dedupe_key' => 'no_prorroga_contrato:' . $logicalTicket . ':' . $term,
        'meta' => ['id_ticket' => $logicalTicket, 'acta_url' => $actaUrl, 'termino' => $status],
      ])
      : 0;

    $whatsappQueued = 0;
    foreach ($this->contractTerminationNotificationPhones($ticket, $targets) as $recipient) {
      try {
        $phone = (string) ($recipient['phone'] ?? '');
        $name = (string) ($recipient['name'] ?? 'cliente');
        $summary = "No prórroga contrato #{$contract}, inmueble {$property}, dirección {$address}, ticket #{$logicalTicket}. Clasificación: {$status}. Acta: {$actaUrl}";
        $message = "Buen dia, {$name}.\n\nSKC SuCasa Inmobiliaria emitio respuesta sobre la no prorroga del contrato.\n\n{$summary}\n\nAtentamente,\n{$creatorSignature}";
        $smsQueue = new \SCM\Support\SmsQueue($this->db);
        $ok = $smsQueue->enqueue($phone, $name, $message, [
          'source_module' => 'no_prorroga_contrato',
          'campaign_tag' => 'no_prorroga_contrato',
          'categoria_mensaje' => 'informacion',
          'id_ticket' => $logicalTicket,
          'acta_url' => $actaUrl,
          'dedupe_key' => 'no_prorroga_contrato:' . $logicalTicket . ':' . $term,
          'template_name' => 'scm_no_prorroga_contrato_respuesta',
          'template_language' => 'es_CO',
          'template_components' => [[
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $name],
              ['type' => 'text', 'text' => $summary],
              ['type' => 'text', 'text' => $creatorSignature],
            ],
          ]],
        ]);
        if ($ok) {
          $whatsappQueued++;
        }
      } catch (\Throwable $exception) {
        error_log('[contract_non_renewal_whatsapp] ' . $exception->getMessage());
      }
    }

    return ['email' => $emailQueued, 'whatsapp' => $whatsappQueued];
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @return array<string,string> */
  private function contractTerminationNotificationEmails(array $ticket, array $targets, string $internalAction = 'terminacion_contrato'): array
  {
    $emails = [];
    $map = [
      'arrendatario' => ['correo_arrendatario'],
      'propietario' => ['correo_propietario'],
    ];
    foreach ($targets as $target) {
      if ($target === 'admin') {
        foreach (\SCM\Support\InternalNotificationRecipients::emailsForAction($this->db, $internalAction) as $email) {
          $emails[strtolower($email)] = $email;
        }
        continue;
      }
      foreach ($map[$target] ?? [] as $column) {
        $email = trim((string) ($ticket[$column] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $emails[strtolower($email)] = $email;
        }
      }
    }
    return $emails;
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @return array<int,array{name:string,phone:string}> */
  private function contractTerminationNotificationPhones(array $ticket, array $targets): array
  {
    $out = [];
    $map = [
      'arrendatario' => [['arrendatario'], ['celular_arrendatario']],
      'propietario' => [['propietario'], ['celular_propietario']],
    ];
    $seen = [];
    foreach ($targets as $target) {
      if (!isset($map[$target])) {
        continue;
      }
      [$nameCols, $phoneCols] = $map[$target];
      $phone = $this->contractTerminationFirstText([$ticket], $phoneCols);
      if ($phone === '') {
        continue;
      }
      $key = preg_replace('/\D+/', '', $phone);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = [
        'name' => $this->contractTerminationFirstText([$ticket], $nameCols) ?: 'cliente',
        'phone' => $phone,
      ];
    }
    return $out;
  }

  private function contractTerminationHumanDate(string $date): string
  {
    $date = trim($date);
    $ts = $date !== '' ? strtotime($date . ' 00:00:00') : false;
    return $ts !== false && $ts > 0 ? date('d/m/Y', (int) $ts) : 'según lo informado';
  }

  /** @param array<string,mixed> $row @return array<string,string> */
  private function contractTerminationTermInfo(array $row, int $requestTs): array
  {
    $finTs = $this->contractTerminationTimestamp($row['fin_contrato'] ?? '');
    $base = [
      'fin_contrato' => $finTs > 0 ? date('Y-m-d', $finTs) : '',
      'fin_contrato_label' => $finTs > 0 ? date('d/m/Y', $finTs) : '',
      'fecha_limite_terminacion' => '',
      'fecha_limite_label' => '',
      'term_status' => 'unknown',
      'term_label' => 'Sin fecha fin de contrato',
      'term_hint' => 'No se encontró fin_contrato para calcular el término.',
      'term_recommended' => 'dentro',
    ];
    if ($finTs <= 0) {
      return $base;
    }
    $limitTs = strtotime('-3 months', $finTs);
    $limitTs = $limitTs !== false ? (int) $limitTs : 0;
    $base['fecha_limite_terminacion'] = $limitTs > 0 ? date('Y-m-d', $limitTs) : '';
    $base['fecha_limite_label'] = $limitTs > 0 ? date('d/m/Y', $limitTs) : '';
    if ($requestTs <= 0 || $limitTs <= 0) {
      $base['term_label'] = 'Sin fecha de solicitud';
      $base['term_hint'] = 'No se pudo calcular contra la fecha de solicitud.';
      return $base;
    }
    $inside = date('Y-m-d', $requestTs) <= date('Y-m-d', $limitTs);
    $base['term_status'] = $inside ? 'dentro' : 'fuera';
    $base['term_label'] = $inside ? 'Dentro de término' : 'Fuera de término';
    $base['term_hint'] = $inside
      ? 'Solicitud recibida antes o el ' . $base['fecha_limite_label'] . '.'
      : 'Debía recibirse máximo el ' . $base['fecha_limite_label'] . '.';
    $base['term_recommended'] = $inside ? 'dentro' : 'fuera';
    return $base;
  }

  private function contractTerminationTimestamp($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function contractTerminationWhatsappButtonSuffix(string $url): string
  {
    $parts = parse_url($url);
    if (!is_array($parts)) {
      return $url;
    }
    $path = (string) ($parts['path'] ?? '');
    $query = isset($parts['query']) ? ('?' . (string) $parts['query']) : '';
    $suffix = ltrim($path . $query, '/');
    return $suffix !== '' ? $suffix : $url;
  }

  private function contractTicketWhatsappUrl(string $ticketId): string
  {
    $ticketId = trim($ticketId);
    return 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($ticketId);
  }

  private function contractTicketWhatsappButtonSuffix(string $ticketId): string
  {
    return $this->contractTerminationWhatsappButtonSuffix($this->contractTicketWhatsappUrl($ticketId));
  }

  /** @return array{name:string,phone:string,id_empleado:string,cargo:string,email:string} */
  private function calendarCitaFuncionarioRow(string $lookup, string $mode): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($table)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => '', 'cargo' => '', 'email' => ''];
    }

    $nameColumn = $this->detect_first_existing_column($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    $phoneColumn = $this->detect_first_existing_column($table, ['celular_empleado', 'celular', 'telefono', 'whatsapp', 'phone']);
    $emailColumn = $this->detect_first_existing_column($table, ['correo', 'correo_empleado', 'email']);
    $roleColumn = $this->detect_first_existing_column($table, ['rol', 'cargo']);
    $cargoColumn = $this->column_exists($table, 'id_cargo') ? 'id_cargo' : '';
    $cargoTable = $this->db->table('jet_cct_cargos');
    $hasCargoNames = $cargoColumn !== ''
      && $this->table_exists($cargoTable)
      && $this->column_exists($cargoTable, 'nombre_cargo');
    $select = [];
    $select[] = $nameColumn !== '' ? "TRIM(COALESCE(f.`{$nameColumn}`, '')) AS nombre" : "'' AS nombre";
    $select[] = $phoneColumn !== '' ? "TRIM(COALESCE(f.`{$phoneColumn}`, '')) AS telefono" : "'' AS telefono";
    $select[] = $emailColumn !== '' ? "TRIM(COALESCE(f.`{$emailColumn}`, '')) AS correo" : "'' AS correo";
    $select[] = $this->column_exists($table, 'id_empleado') ? "TRIM(COALESCE(f.`id_empleado`, '')) AS id_empleado" : "'' AS id_empleado";
    $select[] = $roleColumn !== '' ? "TRIM(COALESCE(f.`{$roleColumn}`, '')) AS rol" : "'' AS rol";
    $select[] = $hasCargoNames ? "TRIM(COALESCE(c.`nombre_cargo`, '')) AS nombre_cargo" : "'' AS nombre_cargo";

    $whereColumn = $mode === 'internal' ? '_ID' : 'id_empleado';
    if (!$this->column_exists($table, $whereColumn)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => '', 'cargo' => '', 'email' => ''];
    }

    if (ctype_digit($lookup)) {
      $whereSql = "CAST(f.`{$whereColumn}` AS UNSIGNED) = ?";
      $whereArgs = [(int) $lookup];
    } else {
      $whereSql = "CONVERT(TRIM(COALESCE(f.`{$whereColumn}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
      $whereArgs = [$lookup];
    }
    $join = $hasCargoNames ? " LEFT JOIN `{$cargoTable}` c ON CAST(c.`_ID` AS CHAR) = TRIM(COALESCE(f.`{$cargoColumn}`, ''))" : '';

    $row = $this->db->getRow(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` f{$join} WHERE {$whereSql} LIMIT 1",
      $whereArgs
    );
    if (!is_array($row)) {
      return ['name' => '', 'phone' => '', 'id_empleado' => '', 'cargo' => '', 'email' => ''];
    }

    return [
      'name' => trim((string) ($row['nombre'] ?? '')),
      'phone' => trim((string) ($row['telefono'] ?? '')),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'cargo' => $this->contractTerminationFirstText([$row], ['nombre_cargo', 'rol']),
      'email' => trim((string) ($row['correo'] ?? '')),
    ];
  }

  /** @return array{name:string,phone:string,cargo:string,email:string} */
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
      'cargo' => trim((string) ($creator['cargo'] ?? '')),
      'email' => trim((string) ($creator['email'] ?? '')),
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
