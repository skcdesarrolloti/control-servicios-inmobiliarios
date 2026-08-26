<?php

namespace SCM\App;

use SCM\Core\Database;
use SCM\Core\Auth;

final class SuCasaControlServiciosInmobiliarios
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  use \SCM\App\Concerns\HandlesPublicPqrActions;
  use \SCM\App\Concerns\HandlesAdministrativeNotifications;
  use \SCM\App\Concerns\HandlesCanonInsuranceAudits;
  use \SCM\App\Concerns\RendersPublicPqr;
  use \SCM\App\Concerns\RendersDashboard;

  const AJAX_ACTION      = 'scm_filtrar';
  const AJAX_SEGUIMIENTO = 'scm_guardar_seguimiento';
  const AJAX_NOTA        = 'scm_guardar_nota';
  const AJAX_TICKET_RESPONSE = 'scm_responder_ticket';
  const AJAX_POSTPONE_TICKET = 'scm_postergar_ticket';
  const AJAX_STATUS_TICKETS = 'scm_filtrar_tickets_estado';
  const AJAX_MY_TICKETS = 'scm_mis_tickets';
  const AJAX_COTIZACIONES_MANTENIMIENTO = 'scm_cotizaciones_mantenimiento';
  const AJAX_DELETE_COTIZACION = 'scm_eliminar_cotizacion_mantenimiento';
  const AJAX_COTIZACION_MANTENIMIENTO_PDF = 'scm_cotizacion_mantenimiento_pdf';
  const AJAX_ACTIVATE_TICKET = 'scm_activar_ticket';
  const AJAX_COTIZACION_RESPONSE = 'scm_responder_cotizacion';
  const AJAX_CLOSE_TICKET = 'scm_cerrar_ticket';
  const AJAX_CONTACTS_UPDATE = 'scm_actualizar_contactos';
  const AJAX_ENTREGA         = 'scm_entrega';
  const AJAX_PREVENTIVA      = 'scm_preventiva';
  const AJAX_RECIBO          = 'scm_recibo';
  const AJAX_CONTABLE        = 'scm_contable';
  const AJAX_CERTIFICACIONES = 'scm_certificaciones';
  const AJAX_CONTRACTUAL     = 'scm_contractual';
  const AJAX_PREVENTIVAS_PENDIENTES = 'scm_preventivas_pendientes';
  const AJAX_SERVICIOS_PUBLICOS_PENDIENTES = 'scm_servicios_publicos_pendientes';
  const AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES = 'scm_reportes_administrativos_pendientes';
  const AJAX_CONTRATOS_ARRENDAMIENTO = 'scm_contratos_arrendamiento';
  const AJAX_CONTRATO_RECIBIDO = 'scm_contrato_recibido';
  const AJAX_CREAR_TICKET_ADMINISTRATIVO = 'scm_crear_ticket_administrativo';
  const AJAX_APROBAR_REPORTE_ADMINISTRATIVO = 'scm_aprobar_reporte_administrativo';
  const AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO = 'scm_desaprobar_reporte_administrativo';
  const AJAX_DAMAGE_MAGNITUDE = 'damage_magnitude_tickets';
  const AJAX_CLASSIFY_MAGNITUDE = 'scm_clasificar_magnitud';
  const AJAX_SAVE_CASE_MAGNITUDE = 'scm_guardar_magnitud_caso';
  const AJAX_SAVE_PROPERTY_LOCATION = 'scm_guardar_ubicacion_inmueble';
  const AJAX_TRASLADAR_CASO = 'scm_trasladar_caso';
  const AJAX_ASIGNAR_PQR_PUBLICO = 'scm_asignar_pqr_publico';
  const AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO = 'scm_guardar_corresponsable_pqr_publico';
  const AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR = 'scm_guardar_notif_responsable_pqr';
  const AJAX_FILTER_PQR_PUBLICO = 'scm_filtrar_pqr_publico';
  const AJAX_DASHBOARD_PERMISSIONS_READ = 'scm_dashboard_permissions_read';
  const AJAX_DASHBOARD_PERMISSIONS_SAVE = 'scm_dashboard_permissions_save';
  const AJAX_CALENDAR_CITA_NOTIFY = 'scm_calendar_cita_notificar';
  const AJAX_ADMIN_NOTIFICATIONS_RECIPIENTS = 'scm_admin_notifications_recipients';
  const AJAX_ADMIN_NOTIFICATIONS_SEND = 'scm_admin_notifications_send';
  const AJAX_ADMIN_NOTIFICATIONS_IMPORT = 'scm_admin_notifications_import';
  const AJAX_ADMIN_NOTIFICATIONS_COLLECTION = 'scm_admin_notifications_collection';
  const AJAX_METRICS_EXECUTION = 'scm_metricas_ejecucion_funcionario';
  const AJAX_CANON_INSURANCE_AUDIT_LIST = 'scm_auditoria_canon_aseguradoras_listar';
  const AJAX_CANON_INSURANCE_AUDIT_IMPORT = 'scm_auditoria_canon_aseguradoras_importar';
  const AJAX_CANON_INSURANCE_AUDIT_OBSERVATION = 'scm_auditoria_canon_aseguradoras_observacion';
  const AJAX_CANON_INSURANCE_AUDIT_REPORT = 'scm_auditoria_canon_aseguradoras_informe';

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
  const DEFAULT_PREVENTIVA_URL = 'https://sucasainmobiliaria.com.co/revision-preventiva/?numero=';
  const DEFAULT_CORRECTIVA_URL = 'https://sucasainmobiliaria.com.co/revision-correctiva/?numero=';
  const DEFAULT_COTIZACION_URL = 'https://sucasainmobiliaria.com.co/cotizacion-de-mantenimiento/?numero=';
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

  /** @return array<int,string> */
  private function dashboardPermissionAdminCargos(): array
  {
    return ['11', '12', '13', '14'];
  }

  /** @return array<string,string> */
  private function dashboardPermissionTabs(): array
  {
    return [
      'abiertos' => 'Abiertos',
      'postergados' => 'Postergados',
      'cerrados' => 'Cerrados',
      'mis_tickets' => 'Mis tickets',
      'cotizaciones_mantenimiento' => 'Cotizaciones de Mantenimiento',
      'calendario_actividades' => 'Calendario',
      'notificaciones' => 'Notificaciones',
      'preventivas_pendientes' => 'Preventivas Pendientes',
      'contratos_arrendamiento' => 'Contratos de arrendamiento',
      'servicios_publicos_pendientes' => 'Servicios Publicos Pendientes',
      'reportes_administrativos_pendientes' => 'Reportes Administrativos',
      'auditoria_canon_aseguradoras' => 'Auditoría de canon y aseguradoras',
      'metricas' => 'Metricas',
    ];
  }

  private function canManageDashboardPermissions(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  private function canManagePublicPqrSettings(): bool
  {
    return in_array(Auth::userCargo(), $this->dashboardPermissionAdminCargos(), true);
  }

  /** @return array<string,array<int,string>> */
  private function dashboardPermissionsConfig(): array
  {
    $raw = \SCM\Core\App::settings()->get('dashboard_tab_permissions', []);
    return $this->sanitizeDashboardPermissions(is_array($raw) ? $raw : []);
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
  private function currentDashboardAllowedTabs(): array
  {
    $cargo = Auth::userCargo();
    $permissions = $this->dashboardPermissionsConfig();
    $allTabs = array_keys($this->dashboardPermissionTabs());
    if ($cargo === '' || !array_key_exists($cargo, $permissions)) {
      return $allTabs;
    }
    return !empty($permissions[$cargo]) ? $permissions[$cargo] : ['mis_tickets'];
  }

  private function canAccessDashboardTab(string $tab): bool
  {
    $tab = preg_replace('/[^a-z0-9_]/', '', strtolower($tab)) ?: '';
    return $tab !== '' && in_array($tab, $this->currentDashboardAllowedTabs(), true);
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
      echo json_encode(['success' => false, 'data' => ['message' => 'Verificacion de seguridad fallida.']]);
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

  private function jsonFail(string $message): void
  {
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'data' => ['message' => $message]], JSON_UNESCAPED_UNICODE);
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
    $defaults = [
      'entrega' => ['label' => 'Entrega de Inmuebles', 'temas' => ['Entrega de inmuebles'], 'prefix' => 'scmeg_'],
      'preventiva' => ['label' => 'Revisiones Preventiva', 'temas' => ['Revisiones Preventiva', 'Revisiones preventiva', 'Revision preventiva', 'Revision preventiva'], 'prefix' => 'scmpv_'],
      'recibo' => ['label' => 'Recibo de Inmuebles', 'temas' => ['Recibo de inmuebles'], 'prefix' => 'scmrc_'],
      'contable' => ['label' => 'Contable y Tributaria', 'temas' => ['Contable y tributaria'], 'prefix' => 'scmco_'],
      'certificaciones' => ['label' => 'Certificaciones Tributarias', 'temas' => ['Certificaciones tributarias'], 'prefix' => 'scmcr_'],
      'contractual' => ['label' => 'Contractual', 'temas' => ['Procesos juridicos', 'Solicitud contractual', 'Solicitud de servicios publicos', 'Retencion de contrato', 'Otros servicios'], 'prefix' => 'scmct_'],
    ];
    return $defaults;
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

  private function render_lazy_tickets_placeholder(string $message = 'Selecciona esta vista para cargar tickets.'): string
  {
    return '<div class="scm-empty scm-lazy-empty"><p>' . esc_html($message) . '</p></div>';
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
        'cards' => '<div class="scm-empty"><p>No se encontraron tickets.</p></div>',
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
        $count = $module->countMaintenance($params, $bucketKey);
        return [
          'dom_key' => $domKey,
          'prefix' => $prefix,
          'params' => $params,
          'count' => $count,
          'cards' => $this->render_lazy_tickets_placeholder(),
          'pagination' => '',
          'form' => $this->render_status_maintenance_filter_form($domKey, $prefix, $params, $maintenanceFilterOptions, $lockedStatusLabel),
          'stats' => ['total' => $count],
          'loaded' => false,
        ];
      }
      $result = $module->run($params, $config, $bucketKey);
      $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
      return [
        'dom_key' => $domKey,
        'prefix' => $prefix,
        'params' => $params,
        'count' => (int) ($stats['total'] ?? 0),
        'cards' => (string) ($result['tbody'] ?? ''),
        'pagination' => (string) ($result['pagination_html'] ?? ''),
        'form' => $this->render_status_maintenance_filter_form($domKey, $prefix, $params, $maintenanceFilterOptions, $lockedStatusLabel),
        'stats' => $stats,
        'loaded' => true,
      ];
    }

    $params = $this->parse_params_generic($input, $prefix);
    $params['_scmStatusBucket'] = $bucketKey;
    $temas = is_array($def['temas'] ?? null) ? $def['temas'] : [];
    $baseTabKey = (string) ($def['base'] ?? $topicKey);
    $result = $this->run_query_generic($temas, $params, $config, $hydrateRows);
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
    $filterOptions = $this->get_generic_tickets_module()->get_generic_filter_options($temas);

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

  private function render_status_maintenance_filter_form(string $domKey, string $prefix, array $p, array $filterOptions, string $lockedStatusLabel): string
  {
    ob_start();
?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form id="scm-form-<?php echo esc_attr($domKey); ?>" autocomplete="off">
        <input type="hidden" id="<?php echo esc_attr($prefix); ?>page" name="<?php echo esc_attr($prefix); ?>page" value="<?php echo esc_attr((string)($p['fPage'] ?? 1)); ?>">
        <div class="scm-grid">
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>estado_bloqueado">Vista</label><input id="<?php echo esc_attr($prefix); ?>estado_bloqueado" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr($lockedStatusLabel); ?>" readonly></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>origen">Origen</label><select id="<?php echo esc_attr($prefix); ?>origen" name="<?php echo esc_attr($prefix); ?>origen" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option>
              <option value="web" <?php selected(($p['fOrigen'] ?? ''), 'web'); ?>>Guardian</option>
              <option value="interno" <?php selected(($p['fOrigen'] ?? ''), 'interno'); ?>>No Guardian</option>
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
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>cotizacion">Cotizaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>cotizacion" name="<?php echo esc_attr($prefix); ?>cotizacion" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(($p['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
              <option value="none" <?php selected(($p['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
            </select></div>
          <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_estado">Estado cotizaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>cotizacion_estado" name="<?php echo esc_attr($prefix); ?>cotizacion_estado" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["cotizacion_estado"] ?? []) as $cotEstadoOpt): ?><option value="<?php echo esc_attr((string)$cotEstadoOpt); ?>" <?php selected((string)($p["fCotizacionEstado"] ?? ""), (string)$cotEstadoOpt); ?>><?php echo esc_html((string)$cotEstadoOpt); ?></option><?php endforeach; ?>
            </select></div>
          <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_enviada">Fue enviada</label><select id="<?php echo esc_attr($prefix); ?>cotizacion_enviada" name="<?php echo esc_attr($prefix); ?>cotizacion_enviada" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="si" <?php selected(($p['fCotizacionEnviada'] ?? ''), 'si'); ?>>S&iacute;</option>
              <option value="no" <?php selected(($p['fCotizacionEnviada'] ?? ''), 'no'); ?>>No</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>perturbacion">Perturbaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>perturbacion" name="<?php echo esc_attr($prefix); ?>perturbacion" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'has'); ?>>Con perturbaci&oacute;n</option>
              <option value="none" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'none'); ?>>Sin perturbaci&oacute;n</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>revision">Revisi&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>revision" name="<?php echo esc_attr($prefix); ?>revision" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(($p['fRevision'] ?? ''), 'has'); ?>>Con revisi&oacute;n</option>
              <option value="none" <?php selected(($p['fRevision'] ?? ''), 'none'); ?>>Sin revisi&oacute;n</option>
            </select></div>
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
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>magnitud_caso">Magnitud caso</label><select id="<?php echo esc_attr($prefix); ?>magnitud_caso" name="<?php echo esc_attr($prefix); ?>magnitud_caso" class="select select-bordered select-sm scm-select">
              <option value="">Todas las magnitudes</option>
              <option value="critico" <?php selected(($p['fMagnitudCaso'] ?? ''), 'critico'); ?>>Cr&iacute;tico</option>
              <option value="alto" <?php selected(($p['fMagnitudCaso'] ?? ''), 'alto'); ?>>Alto</option>
              <option value="medio" <?php selected(($p['fMagnitudCaso'] ?? ''), 'medio'); ?>>Medio</option>
              <option value="bajo" <?php selected(($p['fMagnitudCaso'] ?? ''), 'bajo'); ?>>Bajo</option>
            </select></div>
        </div>
        <div class="scm-actions">
          <button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button>
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
      <div class="scm-status-subtabs" role="tablist" aria-label="<?php echo esc_attr((string)($bucketDef['label'] ?? 'Tickets')); ?>">
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
              <p><?php echo esc_html((string)($bucketDef['label'] ?? 'Tickets')); ?></p>
            </div>
            <span class="scm-status-count"><strong id="scm-<?php echo esc_attr($domKey); ?>-count"><?php echo esc_html((string)($data['count'] ?? 0)); ?></strong> tickets</span>
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
