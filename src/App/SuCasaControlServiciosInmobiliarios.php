<?php

namespace SCM\App;

use SCM\Core\Database;
use SCM\Core\Auth;

final class SuCasaControlServiciosInmobiliarios
{
  const AJAX_ACTION      = 'scm_filtrar';
  const AJAX_SEGUIMIENTO = 'scm_guardar_seguimiento';
  const AJAX_NOTA        = 'scm_guardar_nota';
  const AJAX_TICKET_RESPONSE = 'scm_responder_ticket';
  const AJAX_POSTPONE_TICKET = 'scm_postergar_ticket';
  const AJAX_STATUS_TICKETS = 'scm_filtrar_tickets_estado';
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

  private Database $db;
  private array $tableExistsCache = [];
  private array $columnExistsCache = [];
  private $serviciosInmobiliariosModule = null;
  private $genericTicketsModule = null;
  private $seguimientoService = null;
  private ?\SCM\Support\SchemaInspector $schemaInspector = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  private static function h(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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

  private function handleImageUpload(string $fieldName): string
  {
    $uploads = $this->handleImageUploads($fieldName, 1);
    return (string) ($uploads[0] ?? '');
  }

  /**
   * @return array<int,string>
   */
  private function handleImageUploads(string $fieldName, int $maxFiles = 10): array
  {
    $files = $this->normalizeUploadedFiles($fieldName);
    if (empty($files)) {
      return [];
    }

    $out = [];
    foreach ($files as $file) {
      if (count($out) >= $maxFiles) {
        break;
      }
      $url = $this->storeUploadedImage($file);
      if ($url !== '') {
        $out[] = $url;
      }
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $file
   */
  private function storeUploadedImage(array $file): string
  {
    if (empty($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      return '';
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/heic', 'image/heif', 'image/tiff'];

    $detectedMime = function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : (string) ($file['type'] ?? '');
    if (!in_array($detectedMime, $allowedMimes, true)) {
      return '';
    }

    $uploadDir = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . '/uploads') : (SCM_ROOT . '/uploads');
    $uploadUrl = defined('SCM_BASE_URL')  ? (SCM_BASE_URL  . '/uploads') : '';

    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return '';
      }
    }

    // Siempre guardar como JPEG para máxima compresión (excepto GIF que se copia directo)
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.jpg';
    $destPath = $uploadDir . '/' . $basename;

    if ($detectedMime === 'image/gif' || !extension_loaded('gd')) {
      // GIF animado o sin GD: copiar directo sin recomprimir
      $ext      = $detectedMime === 'image/gif' ? 'gif' : 'jpg';
      $basename = bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;
      $destPath = $uploadDir . '/' . $basename;
      if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return '';
      }
      return $uploadUrl !== '' ? ($uploadUrl . '/' . $basename) : $basename;
    }

    // Cargar imagen en memoria
    $src = null;
    switch ($detectedMime) {
      case 'image/jpeg':
        $src = @imagecreatefromjpeg($file['tmp_name']);
        break;
      case 'image/png':
        $src = @imagecreatefrompng($file['tmp_name']);
        break;
      case 'image/webp':
        $src = @imagecreatefromwebp($file['tmp_name']);
        break;
    }
    if (!$src) {
      // Fallback: mover sin procesar
      $extMap = [
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/tiff' => 'tiff',
      ];
      $ext = $extMap[$detectedMime] ?? 'jpg';
      $basename = bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;
      $destPath = $uploadDir . '/' . $basename;
      if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return '';
      }
      return $uploadUrl !== '' ? ($uploadUrl . '/' . $basename) : $basename;
    }

    // Redimensionar si supera 1600 px en cualquier dimensión
    $origW = imagesx($src);
    $origH = imagesy($src);
    $maxDim = 1600;
    if ($origW > $maxDim || $origH > $maxDim) {
      $ratio  = min($maxDim / $origW, $maxDim / $origH);
      $newW   = (int) round($origW * $ratio);
      $newH   = (int) round($origH * $ratio);
      $canvas = imagecreatetruecolor($newW, $newH);
      if ($canvas) {
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $src = $canvas;
      }
    }

    // Guardar como JPEG con calidad 78
    $saved = imagejpeg($src, $destPath, 78);
    imagedestroy($src);

    if (!$saved) {
      return '';
    }

    return $uploadUrl !== '' ? ($uploadUrl . '/' . $basename) : $basename;
  }

  /**
   * @param array<int,string> $titles
   * @return array<int,array{nombre_archivo:string,archivo:string}>
   */
  private function handleDocumentUploads(string $fieldName, array $titles = [], int $maxFiles = 10): array
  {
    $files = $this->normalizeUploadedFiles($fieldName);
    if (empty($files)) {
      return [];
    }

    $out = [];
    foreach ($files as $i => $file) {
      if (count($out) >= $maxFiles) {
        break;
      }
      $url = $this->storeUploadedDocument($file);
      if ($url === '') {
        continue;
      }
      $title = trim(strip_tags(stripslashes((string) ($titles[$i] ?? ''))));
      $out[] = [
        'nombre_archivo' => $title,
        'archivo' => $url,
      ];
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $file
   */
  private function storeUploadedDocument(array $file): string
  {
    if (empty($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      return '';
    }

    $allowedMimes = [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'image/bmp',
      'image/tiff',
      'application/pdf',
      'application/msword',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/zip',
      'application/x-zip-compressed',
      'application/x-rar-compressed',
      'application/vnd.rar',
      'application/x-gzip',
      'text/html',
      'text/plain',
      'text/csv',
    ];
    $detectedMime = function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : (string) ($file['type'] ?? '');
    if (!in_array($detectedMime, $allowedMimes, true)) {
      return '';
    }

    $uploadDir = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . '/uploads') : (SCM_ROOT . '/uploads');
    $uploadUrl = defined('SCM_BASE_URL')  ? (SCM_BASE_URL  . '/uploads') : '';
    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return '';
      }
    }

    $original = (string) ($file['name'] ?? 'archivo');
    $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
    $safeExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.' . $safeExt;
    $destPath = $uploadDir . '/' . $basename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
      return '';
    }

    return $uploadUrl !== '' ? ($uploadUrl . '/' . $basename) : $basename;
  }

  /**
   * Normaliza tanto inputs simples/multiple (imagen[]) como estructuras anidadas.
   *
   * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
   */
  private function normalizeUploadedFiles(string $fieldName): array
  {
    $file = $_FILES[$fieldName] ?? null;
    if (!is_array($file) || !isset($file['name'])) {
      return [];
    }

    if (!is_array($file['name'])) {
      return [[
        'name' => (string) ($file['name'] ?? ''),
        'type' => (string) ($file['type'] ?? ''),
        'tmp_name' => (string) ($file['tmp_name'] ?? ''),
        'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($file['size'] ?? 0),
      ]];
    }

    $out = [];
    foreach ($file['name'] as $i => $name) {
      $out[] = [
        'name' => (string) $name,
        'type' => (string) ($file['type'][$i] ?? ''),
        'tmp_name' => (string) ($file['tmp_name'][$i] ?? ''),
        'error' => (int) ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($file['size'][$i] ?? 0),
      ];
    }
    return $out;
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
      $cls = 'badge-info';
    } elseif ($low === 'en proceso') {
      $cls = 'badge-warning';
    } elseif ($low === 'cerrado') {
      $cls = 'badge-success';
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
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>id_empleado">Funcionario</label><select id="<?php echo esc_attr($prefix); ?>id_empleado" name="<?php echo esc_attr($prefix); ?>id_empleado" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["funcionarios"] ?? []) as $func): $fId = trim((string)($func["id"] ?? ""));
                                                if ($fId === "") continue; ?>
                <option value="<?php echo esc_attr($fId); ?>" <?php selected(($p["fEmpleado"] ?? ""), $fId); ?>><?php echo esc_html((string)($func["label"] ?? $fId)); ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>busqueda">B&uacute;squeda</label><input id="<?php echo esc_attr($prefix); ?>busqueda" name="<?php echo esc_attr($prefix); ?>busqueda" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p['fBusqueda'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>inmueble">Inmueble</label><input id="<?php echo esc_attr($prefix); ?>inmueble" name="<?php echo esc_attr($prefix); ?>inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p['fInmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>contrato">Contrato</label><input id="<?php echo esc_attr($prefix); ?>contrato" name="<?php echo esc_attr($prefix); ?>contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fContrato"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>asunto">Asunto</label><input id="<?php echo esc_attr($prefix); ?>asunto" name="<?php echo esc_attr($prefix); ?>asunto" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fAsunto"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>arrendatario">Arrendatario</label><input id="<?php echo esc_attr($prefix); ?>arrendatario" name="<?php echo esc_attr($prefix); ?>arrendatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fArrendatario"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>propietario">Propietario</label><input id="<?php echo esc_attr($prefix); ?>propietario" name="<?php echo esc_attr($prefix); ?>propietario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($p["fPropietario"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>barrio">Barrio</label><select id="<?php echo esc_attr($prefix); ?>barrio" name="<?php echo esc_attr($prefix); ?>barrio" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option><?php foreach (($filterOptions["barrios"] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string)$barrioOpt); ?>" <?php selected((string)($p["fBarrio"] ?? ""), (string)$barrioOpt); ?>><?php echo esc_html((string)$barrioOpt); ?></option><?php endforeach; ?>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>fecha_desde">Fecha desde</label><input id="<?php echo esc_attr($prefix); ?>fecha_desde" name="<?php echo esc_attr($prefix); ?>fecha_desde" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($p["fFechaDesde"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>fecha_hasta">Fecha hasta</label><input id="<?php echo esc_attr($prefix); ?>fecha_hasta" name="<?php echo esc_attr($prefix); ?>fecha_hasta" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($p["fFechaHasta"] ?? "")); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>cotizacion">Cotizaci&oacute;n</label><select id="<?php echo esc_attr($prefix); ?>cotizacion" name="<?php echo esc_attr($prefix); ?>cotizacion" class="select select-bordered select-sm scm-select">
              <option value="">Todas</option>
              <option value="has" <?php selected(($p['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
              <option value="none" <?php selected(($p['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
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
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>atraso">Atraso ticket</label><select id="<?php echo esc_attr($prefix); ?>atraso" name="<?php echo esc_attr($prefix); ?>atraso" class="select select-bordered select-sm scm-select">
              <option value="">Todos</option>
              <option value="3" <?php selected(($p['fAtraso'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
              <option value="5" <?php selected(($p['fAtraso'] ?? ''), '5'); ?>>+5 d&iacute;as</option>
              <option value="10" <?php selected(($p['fAtraso'] ?? ''), '10'); ?>>+10 d&iacute;as</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>sin_actualizar">Sin actualizar</label><select id="<?php echo esc_attr($prefix); ?>sin_actualizar" name="<?php echo esc_attr($prefix); ?>sin_actualizar" class="select select-bordered select-sm scm-select">
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
    $result = $service->save($ticketPk, $observacion, $estadoTicket, $estadoCotizacion, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $evidencias, $documentos);
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
    $result = $service->saveTicketResponse($ticketPk, $respuesta, $estadoAdministrativo, $cerrarTicket, $notifyRecipients, $imagenes, $documentos);
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
    if ($ticketPk <= 0) {
      $this->jsonFail('Ticket invalido.');
    }

    $service = $this->get_seguimiento_service();
    $result = $service->closeTicket($ticketPk);
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


  public function ajax_handler_damage_magnitude(): void
  {
    $this->verifyCsrf();

    require_once SCM_ROOT . '/src/DamageMagnitudeServicePhp7.php';

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

    $service = new \DamageMagnitudeServicePhp7($this->db->pdo(), $this->db->prefix());
    $this->jsonOk($service->getTicketsWithDamageMagnitude($filters));
  }

  public function ajax_handler_classify_magnitude(): void
  {
    $this->verifyCsrf();

    require_once SCM_ROOT . '/src/DamageMagnitudeServicePhp7.php';

    $type = strtolower(trim(sanitize_text_field((string)($_POST['revision_type'] ?? 'correctiva'))));
    if (!in_array($type, ['correctiva', 'preventiva'], true)) {
      $type = 'correctiva';
    }

    $table = $this->db->table('jet_cct_tickets');
    if (!$this->ensure_magnitud_caso_column($table)) {
      $this->jsonFail('No se pudo preparar la columna magnitud_caso en tickets.');
    }

    $service = new \DamageMagnitudeServicePhp7($this->db->pdo(), $this->db->prefix());
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

  public function ajax_handler_asignar_pqr_publico(): void
  {
    $this->verifyCsrf();
    $result = [];

    try {
      $result = $this->assign_public_pqr(
        (int) ($_POST['ticket_pk'] ?? 0),
        (string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? ''),
        (string) ($_POST['new_empleado_id'] ?? ''),
        '',
        'assigned',
        (string) ($_POST['departamento'] ?? '')
      );
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }

    $this->jsonOk($result);
  }

  /** @return array<string,string> */
  public function assign_public_pqr(int $ticketPk, string $tipoPqrsInput, string $newEmpIdInput = '', string $authorizedEmployeeId = '', string $accessScope = 'assigned', string $departmentInput = ''): array
  {
    $tipoPqrsRaw = trim(sanitize_text_field(wp_unslash($tipoPqrsInput)));
    $newEmpId = trim(sanitize_text_field(wp_unslash($newEmpIdInput)));
    $authorizedEmployeeId = trim(sanitize_text_field(wp_unslash($authorizedEmployeeId)));
    $accessScope = mb_strtolower(trim(sanitize_text_field(wp_unslash($accessScope))), 'UTF-8');
    $departmentInput = trim(sanitize_text_field(wp_unslash($departmentInput)));
    if ($accessScope !== 'all') {
      $accessScope = 'assigned';
    }

    if ($ticketPk <= 0) {
      throw new \InvalidArgumentException('Ticket invalido.');
    }
    if ($tipoPqrsRaw === '') {
      throw new \InvalidArgumentException('Debes seleccionar un tipo de PQR/Peticion.');
    }

    $tipoPqrs = '';
    $tipoNeedle = mb_strtolower($tipoPqrsRaw, 'UTF-8');
    $temaOpts = \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
    foreach ($temaOpts as $temaOpt) {
      if (mb_strtolower(trim((string) $temaOpt), 'UTF-8') === $tipoNeedle) {
        $tipoPqrs = (string) $temaOpt;
        break;
      }
    }
    if ($tipoPqrs === '') {
      throw new \InvalidArgumentException('Tipo de PQR/Peticion no valido.');
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    $ticket = $this->db->getRow(
      "SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($ticket)) {
      throw new \RuntimeException('Ticket no encontrado.');
    }

    if ($authorizedEmployeeId !== '' && $accessScope !== 'all') {
      $currentEmployeeId = trim((string) ($ticket['id_empleado'] ?? ''));
      if ($currentEmployeeId === '' || $currentEmployeeId !== $authorizedEmployeeId) {
        throw new \RuntimeException('No tienes acceso a esta solicitud.');
      }
    }

    $departmentFromTheme = \SCM\Modules\PublicTickets\PublicTicketsService::getDepartmentForTheme($tipoPqrs);
    $departmentOptions = $this->get_public_pqr_department_options();
    $departmentManual = '';
    if ($departmentInput !== '') {
      foreach ($departmentOptions as $departmentOption) {
        if (mb_strtolower($departmentOption, 'UTF-8') === mb_strtolower($departmentInput, 'UTF-8')) {
          $departmentManual = $departmentOption;
          break;
        }
      }
      if ($departmentManual === '') {
        throw new \InvalidArgumentException('Departamento no valido.');
      }
    }
    $oldTipo = trim((string) ($ticket['tema_ayuda'] ?? $ticket['tipo_pqrs'] ?? ''));
    $oldDepartment = trim((string) ($ticket['departamento'] ?? ''));
    $oldEmpId = trim((string) ($ticket['id_empleado'] ?? ''));
    $oldEmpNombre = trim((string) ($ticket['empleado'] ?? $ticket['nombre_empleado'] ?? ''));
    $tipoCambio = mb_strtolower($oldTipo, 'UTF-8') !== mb_strtolower($tipoPqrs, 'UTF-8');
    $department = $departmentManual;
    if ($department === '') {
      $department = $oldDepartment;
      if ($tipoCambio || $department === '') {
        $department = trim($departmentFromTheme);
        if ($department === '') {
          $department = $oldDepartment !== '' ? $oldDepartment : 'Servicio al cliente';
        }
      }
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $updatePayload = [
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $department,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ];

    $newNombre = '';
    if ($newEmpId !== '') {
      $funcTable = $this->db->table('jet_cct_funcionarios');
      $newEmp = $this->db->getRow(
        "SELECT `id_empleado`, `nombre`, `correo`, `celular` FROM `{$funcTable}` WHERE TRIM(COALESCE(`id_empleado`,'')) = ? LIMIT 1",
        [$newEmpId]
      );
      if (!is_array($newEmp)) {
        throw new \RuntimeException('Funcionario no encontrado.');
      }

      $newNombre = trim((string) ($newEmp['nombre'] ?? ''));
      $updatePayload['id_empleado'] = $newEmpId;
      $updatePayload['empleado'] = $newNombre;
      $updatePayload['nombre_empleado'] = $newNombre;
      $updatePayload['correo_empleado'] = trim((string) ($newEmp['correo'] ?? ''));
      $updatePayload['celular_empleado'] = trim((string) ($newEmp['celular'] ?? ''));
      if ($oldEmpId !== $newEmpId) {
        $updatePayload['estado_administrativo'] = 'Trasladado';
      }
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $update = $schema->filterTableData($ticketsTable, $updatePayload);
    if (empty($update)) {
      throw new \RuntimeException('No se pudo actualizar el PQR.');
    }

    $this->db->update($ticketsTable, $update, ['_ID' => $ticketPk]);

    $empCambio = $newEmpId !== '' && $newEmpId !== $oldEmpId;

    if ($tipoCambio || $empCambio) {
      $changes = [];
      if ($tipoCambio) {
        $changes[] = 'Tipo PQR/Peticion: "' . ($oldTipo !== '' ? $oldTipo : '-') . '" -> "' . $tipoPqrs . '"';
      }
      if ($empCambio) {
        $changes[] = 'Funcionario: "' . ($oldEmpNombre !== '' ? $oldEmpNombre : 'sin asignar') . '" -> "' . ($newNombre !== '' ? $newNombre : $newEmpId) . '"';
      }
      $obs = 'Actualizacion Solicitud web. ' . implode(' | ', $changes) . '.';
      $service = $this->get_seguimiento_service();
      $service->addSeguimientoEntry($ticket, $ticketPk, $obs);
    }

    if ($empCambio) {
      $updatedTicket = array_merge($ticket, [
        '_ID' => $ticketPk,
        'id_ticket' => $ticket['id_ticket'] ?? $ticketPk,
        'tema_ayuda' => $tipoPqrs,
        'departamento' => $department,
        'id_empleado' => $newEmpId,
        'empleado' => $newNombre,
        'nombre_empleado' => $newNombre,
        'correo_empleado' => trim((string) ($newEmp['correo'] ?? '')),
        'celular_empleado' => trim((string) ($newEmp['celular'] ?? '')),
      ]);
      $this->notifyPublicPqrTransferToEmployee(
        $updatedTicket,
        $newEmpId,
        $newNombre,
        trim((string) ($newEmp['correo'] ?? '')),
        trim((string) ($newEmp['celular'] ?? '')),
        $oldEmpNombre,
        $tipoPqrs
      );
    }

    $msg = 'PQR actualizado correctamente.';
    if ($newEmpId !== '') {
      $msg .= ' Responsable: ' . ($newNombre !== '' ? $newNombre : $newEmpId) . '.';
    }

    return [
      'message' => $msg,
      'ticket_pk' => (string) $ticketPk,
      'tipo_pqrs' => $tipoPqrs,
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $department,
      'id_empleado' => $newEmpId,
      'empleado' => $newNombre,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function notifyPublicPqrTransferToEmployee(array $ticket, string $employeeId, string $employeeName, string $employeeEmail, string $employeePhone, string $oldEmployeeName, string $tipoPqrs): void
  {
    if ($employeeId === '' || ($employeeEmail === '' && $employeePhone === '')) {
      return;
    }

    $configFile = defined('SCM_ROOT') ? (SCM_ROOT . '/config.php') : '';
    $appConfig = [];
    if ($configFile !== '' && is_readable($configFile)) {
      $loadedConfig = require $configFile;
      if (is_array($loadedConfig)) {
        $appConfig = $loadedConfig;
      }
    }

    $publicService = new \SCM\Modules\PublicTickets\PublicTicketsService($this->db, $appConfig);
    $publicRequestsUrl = $publicService->buildResponsiblePublicPqrUrl($employeeId, 2592000, 'assigned');
    $logicalTicket = trim((string) ($ticket['id_ticket'] ?? $ticket['_ID'] ?? ''));
    $ticketUrl = self::DEFAULT_TICKET_URL . rawurlencode($logicalTicket);
    $contractLabel = trim((string) ($ticket['contrato'] ?? $ticket['id_contrato'] ?? ''));
    $subject = 'Solicitud web trasladada #' . $logicalTicket;
    $displayName = $employeeName !== '' ? $employeeName : ('Funcionario #' . $employeeId);

    if ($employeeEmail !== '' && filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
      $content = '<p style="font-weight:600;margin:0 0 12px;">Se te traslado una solicitud web para gestion.</p>';
      $content .= '<p style="margin:4px 0;"><b>Solicitud:</b> #' . \SCM\Support\EmailTemplate::e($logicalTicket) . '</p>';
      if ($contractLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . \SCM\Support\EmailTemplate::e($contractLabel) . '</p>';
      }
      $content .= '<p style="margin:4px 0;"><b>Tema:</b> ' . \SCM\Support\EmailTemplate::e($tipoPqrs) . '</p>';
      $content .= '<p style="margin:4px 0;"><b>Funcionario anterior:</b> ' . \SCM\Support\EmailTemplate::e($oldEmployeeName !== '' ? $oldEmployeeName : 'Sin asignar') . '</p>';
      $buttons = [];
      if ($publicRequestsUrl !== '') {
        $buttons[] = ['url' => $publicRequestsUrl, 'label' => 'Ver tus solicitudes web'];
      }
      $html = \SCM\Support\EmailTemplate::render($subject, $content, [
        'ticket_url' => $ticketUrl,
        'buttons' => $buttons,
      ]);
      (new \SCM\Support\EmailQueue($this->db))->enqueue($employeeEmail, $subject, $html, [
        'source' => 'public_pqr_transfer',
        'destination_name' => $displayName,
      ]);
    }

    if ($employeePhone !== '') {
      $message = "🔁 *Solicitud web trasladada*\n";
      $message .= '*Solicitud:* #' . $logicalTicket . "\n";
      if ($contractLabel !== '') {
        $message .= '*Contrato:* ' . $contractLabel . "\n";
      }
      $message .= '*Tema:* ' . $tipoPqrs . "\n";
      if ($oldEmployeeName !== '') {
        $message .= '*Funcionario anterior:* ' . $oldEmployeeName . "\n";
      }
      $message .= "\n*Ver solicitud:*\n" . $ticketUrl;
      if ($publicRequestsUrl !== '') {
        $message .= "\n\n*Tus solicitudes web:*\n" . $publicRequestsUrl;
      }
      (new \SCM\Support\SmsQueue($this->db))->enqueue($employeePhone, $displayName, $message, [
        'id_funcionario' => is_numeric($employeeId) ? (int) $employeeId : 0,
        'campaign_tag' => 'pqr_portal_publico_traslado',
        'categoria_mensaje' => 'informacion',
        'dedupe_key' => 'pqr_trasladada_funcionario:' . $logicalTicket . ':' . $employeeId,
        'template_name' => 'pqr_trasladada_funcionario_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $displayName],
              ['type' => 'text', 'text' => $logicalTicket],
              ['type' => 'text', 'text' => $tipoPqrs],
              ['type' => 'text', 'text' => $oldEmployeeName !== '' ? $oldEmployeeName : 'Sin asignar'],
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
    }
  }

  public function ajax_handler_guardar_notif_responsable_pqr(): void
  {
    $this->verifyCsrf();

    $idsRaw = $_POST['notif_responsable_ids'] ?? ($_POST['notif_responsable_id'] ?? []);
    if (!is_array($idsRaw)) {
      $idsRaw = [$idsRaw];
    }

    $allowedCandidates = $this->get_public_pqr_corresponsable_candidates();
    $allowedById = [];
    foreach ($allowedCandidates as $candidate) {
      $cid = trim((string) ($candidate['id'] ?? ''));
      if ($cid !== '') {
        $allowedById[$cid] = trim((string) ($candidate['label'] ?? $cid));
      }
    }

    $notifIds = [];
    foreach ($idsRaw as $idRaw) {
      $id = trim(sanitize_text_field(wp_unslash((string) $idRaw)));
      if ($id === '') {
        continue;
      }
      if (!isset($allowedById[$id])) {
        $this->jsonFail('Uno de los funcionarios seleccionados no existe o esta inactivo.');
      }
      if (!in_array($id, $notifIds, true)) {
        $notifIds[] = $id;
      }
    }

    $saved = \SCM\Modules\PublicTickets\PublicTicketsService::saveNotifResponsables($notifIds);
    if (!$saved) {
      $this->jsonFail('No se pudo guardar la configuracion.');
    }

    $labels = [];
    foreach ($notifIds as $id) {
      $labels[] = isset($allowedById[$id]) ? $allowedById[$id] : $id;
    }
    $message = empty($labels)
      ? 'Sin funcionarios de notificacion.'
      : 'Notificacion guardada para: ' . implode(', ', $labels) . '.';

    $this->jsonOk([
      'message' => $message,
      'notif_responsable_ids' => $notifIds,
    ]);
  }

  public function ajax_handler_filtrar_pqr_publico(): void
  {
    $this->verifyCsrf();

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($_POST['id_empleado'] ?? '')));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }
    $filters = $this->get_public_pqr_filters_from_input($_POST);
    $rows = $this->fetch_public_pqr_rows(180, $employeeIdFilter, $filters);
    $funcionarios = [];
    try {
      $funcionarios = (array) ($this->get_servicios_inmobiliarios_module()->getFilterOptions()['funcionarios'] ?? []);
    } catch (\Throwable $e) {
      $funcionarios = [];
    }

    $html = $this->render_public_pqr_tab(
      $rows,
      $funcionarios,
      self::DEFAULT_TICKET_URL,
      $employeeIdFilter,
      false,
      [],
      $filters
    );

    $this->jsonOk([
      'tab_html' => $html,
      'count' => (string) count($rows),
    ]);
  }

  public function ajax_handler_guardar_corresponsable_pqr_publico(): void
  {
    $this->verifyCsrf();

    $tipoRaw = trim(sanitize_text_field(wp_unslash((string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? ''))));
    $corresponsableIdsRaw = $_POST['corresponsable_ids'] ?? [];
    $corresponsableActorRaw = $_POST['corresponsable_actor'] ?? [];

    if ($tipoRaw === '') {
      $this->jsonFail('Debes seleccionar el tipo de PQR/Peticion.');
    }

    $tipoPqrs = '';
    $tipoNeedle = mb_strtolower($tipoRaw, 'UTF-8');
    $tipos = \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
    foreach ($tipos as $tipo) {
      if (mb_strtolower(trim((string) $tipo), 'UTF-8') === $tipoNeedle) {
        $tipoPqrs = (string) $tipo;
        break;
      }
    }
    if ($tipoPqrs === '') {
      $this->jsonFail('Tipo de PQR/Peticion no valido.');
    }

    if (!is_array($corresponsableIdsRaw)) {
      $corresponsableIdsRaw = [$corresponsableIdsRaw];
    }
    if (!is_array($corresponsableActorRaw)) {
      $corresponsableActorRaw = [];
    }

    $allowedCandidates = $this->get_public_pqr_corresponsable_candidates();
    $allowedById = [];
    foreach ($allowedCandidates as $candidate) {
      $cid = trim((string) ($candidate['id'] ?? ''));
      if ($cid === '') {
        continue;
      }
      $allowedById[$cid] = [
        'id' => $cid,
        'label' => trim((string) ($candidate['label'] ?? $cid)),
      ];
    }

    $normalizeIds = function ($rawIds) use ($allowedById): array {
      if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
      }

      $ids = [];
      foreach ($rawIds as $rawId) {
        $id = trim(sanitize_text_field(wp_unslash((string) $rawId)));
        if ($id === '') {
          continue;
        }
        if (!isset($allowedById[$id])) {
          $this->jsonFail('Uno de los corresponsables seleccionados no existe o esta inactivo.');
        }
        if (!in_array($id, $ids, true)) {
          $ids[] = $id;
        }
      }

      return $ids;
    };

    $corresponsableIds = $normalizeIds($corresponsableIdsRaw);
    $actorAssignments = [];
    foreach (['propietario', 'arrendatario', 'copropiedad', 'cliente'] as $actorKey) {
      $ids = $normalizeIds($corresponsableActorRaw[$actorKey] ?? []);
      if ($ids !== []) {
        $actorAssignments[$actorKey] = $ids;
      }
    }

    $map = \SCM\Modules\PublicTickets\PublicTicketsService::getCorresponsableAssignments();
    if (!empty($actorAssignments)) {
      if (!empty($corresponsableIds)) {
        $actorAssignments['default'] = $corresponsableIds;
      }
      $map[$tipoPqrs] = $actorAssignments;
    } elseif (empty($corresponsableIds)) {
      unset($map[$tipoPqrs]);
    } else {
      $map[$tipoPqrs] = $corresponsableIds;
    }

    $saved = \SCM\Modules\PublicTickets\PublicTicketsService::saveCorresponsableAssignments($map);
    if (!$saved) {
      $this->jsonFail('No se pudo guardar la configuracion de corresponsable.');
    }

    $allSavedIds = $corresponsableIds;
    foreach ($actorAssignments as $ids) {
      foreach ($ids as $id) {
        if (!in_array($id, $allSavedIds, true)) {
          $allSavedIds[] = $id;
        }
      }
    }

    $labels = [];
    foreach ($allSavedIds as $id) {
      $labels[] = (string) ($allowedById[$id]['label'] ?? $id);
    }
    $message = empty($allSavedIds)
      ? 'Corresponsables eliminados para "' . $tipoPqrs . '".'
      : 'Corresponsables guardados para "' . $tipoPqrs . '": ' . implode(', ', $labels) . '.';

    $this->jsonOk([
      'message' => $message,
      'tipo_pqrs' => $tipoPqrs,
      'corresponsable_ids' => $allSavedIds,
      'corresponsable_actor' => $actorAssignments,
      'corresponsable_labels' => $labels,
    ]);
  }

  public function ajax_handler_guide_gcd_read(): void
  {
    $this->verifyCsrf();

    $table = $this->db->table('jet_cct_correspondencias');

    $clasificacion = trim(strip_tags((string)($_POST['clasificacion'] ?? '')));
    $responsable   = trim(strip_tags((string)($_POST['quien_corresponde'] ?? '')));
    $busqueda      = trim(strip_tags((string)($_POST['busqueda'] ?? '')));

    $where  = ['1 = 1'];
    $params = [];

    if ($clasificacion !== '') {
      $where[]  = '`clasificacion` = ?';
      $params[] = $clasificacion;
    }
    if ($responsable !== '') {
      $where[]  = '`quien_corresponde` = ?';
      $params[] = $responsable;
    }
    if ($busqueda !== '') {
      $like     = '%' . $this->db->escapeLike($busqueda) . '%';
      $where[]  = '(`descripcion` LIKE ? OR `observaciones` LIKE ?)';
      $params[] = $like;
      $params[] = $like;
    }

    $sql  = "SELECT `_ID`, `descripcion`, `clasificacion`, `quien_corresponde`,
                    `fundamento_legal`, `reembolso`, `observaciones`
             FROM `{$table}`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY `clasificacion` ASC, `descripcion` ASC";

    $rows = $this->db->getResults($sql, $params);
    $this->jsonOk(['rows' => $rows]);
  }

  public function ajax_handler_guide_gcd_save(): void
  {
    $this->verifyCsrf();

    $id            = (int)($_POST['id'] ?? 0);
    $descripcion   = trim(strip_tags((string)($_POST['descripcion'] ?? '')));
    $clasificacion = trim(strip_tags((string)($_POST['clasificacion'] ?? '')));
    $responsable   = trim(strip_tags((string)($_POST['quien_corresponde'] ?? '')));
    $legal         = trim(strip_tags((string)($_POST['fundamento_legal'] ?? '')));
    $reembolso     = trim(strip_tags((string)($_POST['reembolso'] ?? '')));
    $observaciones = trim(strip_tags((string)($_POST['observaciones'] ?? '')));

    if ($descripcion === '' || $clasificacion === '' || $responsable === '') {
      $this->jsonFail('Situación, Clasificación y Responsable son requeridos.');
    }

    $table = $this->db->table('jet_cct_correspondencias');
    $data  = [
      'descripcion'       => $descripcion,
      'clasificacion'     => $clasificacion,
      'quien_corresponde' => $responsable,
      'fundamento_legal'  => $legal,
      'reembolso'         => $reembolso,
      'observaciones'     => $observaciones,
      'cct_modified'      => date('Y-m-d H:i:s'),
      'cct_author_id'     => Auth::userId(),
    ];

    if ($id > 0) {
      $this->db->update($table, $data, ['_ID' => $id]);
      $this->jsonOk(['message' => 'Correspondencia actualizada.', 'id' => $id]);
    } else {
      $data['cct_created'] = date('Y-m-d H:i:s');
      $data['cct_status']  = 'publish';
      $this->db->insert($table, $data);
      $this->jsonOk(['message' => 'Correspondencia creada.', 'id' => (int)$this->db->lastInsertId()]);
    }
  }

  public function ajax_handler_guide_gcd_del(): void
  {
    $this->verifyCsrf();

    if (Auth::userRol() !== 'admin') {
      $this->jsonFail('Sin permisos para eliminar.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->jsonFail('ID inválido.');
    }

    $this->db->delete($this->db->table('jet_cct_correspondencias'), ['_ID' => $id]);
    $this->jsonOk(['message' => 'Correspondencia eliminada.']);
  }

  // ══════════════════════════════════════════════════════════════════════
  // GUÍA – Banco de Respuestas  (tabla: jet_cct_respuesta_de_ticket)
  // ══════════════════════════════════════════════════════════════════════

  public function ajax_handler_guide_grt_read(): void
  {
    $this->verifyCsrf();

    $table    = $this->db->table('jet_cct_respuesta_de_ticket');
    $categoria = trim(strip_tags((string)($_POST['categoria'] ?? '')));
    $estado    = trim(strip_tags((string)($_POST['estado']    ?? '')));
    $situacion = trim(strip_tags((string)($_POST['situacion'] ?? '')));
    $respuesta = trim(strip_tags((string)($_POST['respuesta'] ?? '')));

    $where  = ['1 = 1'];
    $params = [];

    if ($categoria !== '') {
      $where[]  = '`categoria` = ?';
      $params[] = $categoria;
    }
    if ($estado !== '') {
      $where[]  = '`estado` LIKE ?';
      $params[] = '%' . $this->db->escapeLike($estado) . '%';
    }
    if ($situacion !== '') {
      $where[]  = '`situacion` LIKE ?';
      $params[] = '%' . $this->db->escapeLike($situacion) . '%';
    }
    if ($respuesta !== '') {
      $where[]  = '`respuesta` LIKE ?';
      $params[] = '%' . $this->db->escapeLike($respuesta) . '%';
    }

    $sql  = "SELECT `_ID`, `categoria`, `estado`, `situacion`, `respuesta`
             FROM `{$table}`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY `categoria` ASC, `situacion` ASC";

    $rows = $this->db->getResults($sql, $params);
    $this->jsonOk(['rows' => $rows]);
  }

  public function ajax_handler_guide_grt_save(): void
  {
    $this->verifyCsrf();

    $id        = (int)($_POST['id'] ?? 0);
    $categoria = trim(strip_tags((string)($_POST['categoria'] ?? '')));
    $estado    = trim(strip_tags((string)($_POST['estado'] ?? '')));
    $situacion = trim(strip_tags((string)($_POST['situacion'] ?? '')));
    $respuesta = trim((string)($_POST['respuesta'] ?? ''));

    if ($respuesta === '') {
      $this->jsonFail('El campo Respuesta es requerido.');
    }

    $table = $this->db->table('jet_cct_respuesta_de_ticket');
    $data  = [
      'categoria'    => $categoria,
      'estado'       => $estado,
      'situacion'    => $situacion,
      'respuesta'    => $respuesta,
      'cct_modified' => date('Y-m-d H:i:s'),
      'cct_author_id' => Auth::userId(),
    ];

    if ($id > 0) {
      $this->db->update($table, $data, ['_ID' => $id]);
      $this->jsonOk(['message' => 'Respuesta actualizada.', 'id' => $id]);
    } else {
      $data['cct_created'] = date('Y-m-d H:i:s');
      $data['cct_status']  = 'publish';
      $this->db->insert($table, $data);
      $this->jsonOk(['message' => 'Respuesta creada.', 'id' => (int)$this->db->lastInsertId()]);
    }
  }

  public function ajax_handler_guide_grt_del(): void
  {
    $this->verifyCsrf();

    $rol = Auth::userRol();
    if ($rol !== 'admin' && $rol !== 'editor') {
      $this->jsonFail('Sin permisos para eliminar.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->jsonFail('ID inválido.');
    }

    $this->db->delete($this->db->table('jet_cct_respuesta_de_ticket'), ['_ID' => $id]);
    $this->jsonOk(['message' => 'Respuesta eliminada.']);
  }

  // ══════════════════════════════════════════════════════════════════════
  // GUÍA – Artículos Código Civil  (tabla: jet_cct_articulos_codigo)
  // ══════════════════════════════════════════════════════════════════════

  public function ajax_handler_guide_gac_read(): void
  {
    $this->verifyCsrf();

    $table    = $this->db->table('jet_cct_articulos_codigo');
    $categoria = trim(strip_tags((string)($_POST['categoria'] ?? '')));
    $busqueda  = trim(strip_tags((string)($_POST['busqueda'] ?? '')));

    $where  = ['1 = 1'];
    $params = [];

    if ($categoria !== '') {
      $where[]  = '`categoria` = ?';
      $params[] = $categoria;
    }
    if ($busqueda !== '') {
      $like     = '%' . $this->db->escapeLike($busqueda) . '%';
      $where[]  = '(`codigo_civil` LIKE ? OR CAST(`_ID` AS CHAR) LIKE ?)';
      $params[] = $like;
      $params[] = $like;
    }

    $sql  = "SELECT `_ID`, `categoria`, `codigo_civil`
             FROM `{$table}`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY `categoria` ASC, `_ID` ASC";

    $rows = $this->db->getResults($sql, $params);
    $this->jsonOk(['rows' => $rows]);
  }

  public function ajax_handler_guide_gac_save(): void
  {
    $this->verifyCsrf();

    $id        = (int)($_POST['id'] ?? 0);
    $categoria = trim(strip_tags((string)($_POST['categoria'] ?? '')));
    $contenido = trim((string)($_POST['codigo_civil'] ?? ''));

    if ($categoria === '' || $contenido === '') {
      $this->jsonFail('Categoría y Contenido son requeridos.');
    }

    $table = $this->db->table('jet_cct_articulos_codigo');
    $data  = [
      'categoria'    => $categoria,
      'codigo_civil' => $contenido,
      'cct_modified' => date('Y-m-d H:i:s'),
      'cct_author_id' => Auth::userId(),
    ];

    if ($id > 0) {
      $this->db->update($table, $data, ['_ID' => $id]);
      $this->jsonOk(['message' => 'Artículo actualizado.', 'id' => $id]);
    } else {
      $data['cct_created'] = date('Y-m-d H:i:s');
      $data['cct_status']  = 'publish';
      $this->db->insert($table, $data);
      $this->jsonOk(['message' => 'Artículo creado.', 'id' => (int)$this->db->lastInsertId()]);
    }
  }

  public function ajax_handler_guide_gac_del(): void
  {
    $this->verifyCsrf();

    if (Auth::userRol() !== 'admin') {
      $this->jsonFail('Sin permisos para eliminar.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->jsonFail('ID inválido.');
    }

    $this->db->delete($this->db->table('jet_cct_articulos_codigo'), ['_ID' => $id]);
    $this->jsonOk(['message' => 'Artículo eliminado.']);
  }

  public function ajax_handler_guide_gac_cats(): void
  {
    $this->verifyCsrf();

    $table = $this->db->table('jet_cct_articulos_codigo');
    $sql   = "SELECT DISTINCT `categoria` FROM `{$table}` WHERE `categoria` != '' ORDER BY `categoria` ASC";
    $rows  = $this->db->getResults($sql, []);
    $cats  = array_values(array_filter(array_column($rows, 'categoria')));
    $this->jsonOk(['categories' => $cats]);
  }

  /** @return array<int,string> */
  private function get_public_pqr_themes(): array
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
  }

  private function get_public_pqr_department_for_theme(string $theme): string
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getDepartmentForTheme($theme);
  }

  /** @return array<int,string> */
  private function get_public_pqr_department_options(): array
  {
    return [
      'Servicio al propietario',
      'Servicio al arrendatario',
      'Servicio a la copropiedad',
      'Servicio al cliente',
    ];
  }

  /** @return array<string,array<int,string>> */
  private function get_public_pqr_corresponsables(): array
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getCorresponsableAssignments();
  }

  /** @return array<int,string> */
  private function flatten_public_pqr_corresponsable_ids(array $value): array
  {
    $ids = [];
    foreach ($value as $entry) {
      if (is_array($entry)) {
        foreach ($this->flatten_public_pqr_corresponsable_ids($entry) as $nestedId) {
          if ($nestedId !== '' && !in_array($nestedId, $ids, true)) {
            $ids[] = $nestedId;
          }
        }
        continue;
      }

      $id = trim((string) $entry);
      if ($id !== '' && !in_array($id, $ids, true)) {
        $ids[] = $id;
      }
    }

    return $ids;
  }

  /** @return array<int,string> */
  private function public_pqr_corresponsable_scope_ids(array $value, string $scope): array
  {
    $scope = mb_strtolower(trim($scope), 'UTF-8');
    if ($scope === '') {
      return [];
    }

    $keys = array_keys($value);
    $isList = $value === [] || $keys === range(0, count($value) - 1);
    if ($isList) {
      return $scope === 'default' ? $this->flatten_public_pqr_corresponsable_ids($value) : [];
    }

    $scopeValue = $value[$scope] ?? [];
    if (!is_array($scopeValue)) {
      $scopeValue = [$scopeValue];
    }

    return $this->flatten_public_pqr_corresponsable_ids($scopeValue);
  }

  /** @return array<int,array{id:string,label:string}> */
  private function get_public_pqr_corresponsable_candidates(): array
  {
    $funcTable = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($funcTable) || !$this->column_exists($funcTable, 'id_empleado')) {
      return [];
    }

    $nameCol = $this->column_exists($funcTable, 'nombre') ? 'nombre' : ($this->column_exists($funcTable, 'empleado') ? 'empleado' : 'id_empleado');
    $activeFilter = $this->column_exists($funcTable, 'activo')
      ? " AND LOWER(TRIM(COALESCE(`activo`, 'si'))) <> 'no'"
      : '';

    $sql = "SELECT
              TRIM(COALESCE(`id_empleado`, '')) AS id,
              TRIM(COALESCE(`{$nameCol}`, `id_empleado`, 'Sin nombre')) AS nombre,
              TRIM(COALESCE(`id_cargo`, '')) AS id_cargo
            FROM `{$funcTable}`
            WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''
              {$activeFilter}
            ORDER BY nombre ASC";

    $rows = $this->db->getResults($sql, []);
    $out = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $nombre = trim((string) ($row['nombre'] ?? ''));
      if ($nombre === '') {
        $nombre = $id;
      }
      $out[] = [
        'id' => $id,
        'label' => $nombre . ' (' . $id . ')',
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $input @return array{estado:string,empleado:string} */
  private function get_public_pqr_filters_from_input(array $input): array
  {
    $estado = trim((string) ($input['public_pqr_estado'] ?? ''));
    $empleado = trim((string) ($input['public_pqr_empleado'] ?? ''));

    $estado = sanitize_text_field($estado);
    $empleado = preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field($empleado));
    if (!is_string($empleado)) {
      $empleado = '';
    }

    return [
      'estado' => $estado,
      'empleado' => $empleado,
    ];
  }

  /** @return array{estado:string,empleado:string} */
  private function get_public_pqr_filters_from_request(): array
  {
    return $this->get_public_pqr_filters_from_input($_GET);
  }

  /** @return array<int,array<string,mixed>> */
  private function fetch_public_pqr_rows(int $limit = 160, string $employeeIdFilter = '', array $filters = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable)) {
      return [];
    }

    $limit = max(20, min(500, $limit));
    $creatorLabels = ['propietario', 'arrendatario', 'copropiedad', 'cliente'];
    $medioLabels = ['portal propietario', 'portal arrendatario', 'portal copropiedad', 'whatsapp cliente'];

    $selectCreadoPor = $this->column_exists($ticketsTable, 'creado_por')
      ? "TRIM(COALESCE(`creado_por`, '')) AS creado_por"
      : "'' AS creado_por";
    $selectCreadorPor = $this->column_exists($ticketsTable, 'creador_por')
      ? "TRIM(COALESCE(`creador_por`, '')) AS creador_por"
      : "'' AS creador_por";
    $hasTemaAyuda = $this->column_exists($ticketsTable, 'tema_ayuda');
    $hasTipoPqrs = $this->column_exists($ticketsTable, 'tipo_pqrs');
    if ($hasTemaAyuda && $hasTipoPqrs) {
      $selectTipoPqrs = "TRIM(COALESCE(`tema_ayuda`, `tipo_pqrs`, '')) AS tipo_pqrs";
    } elseif ($hasTemaAyuda) {
      $selectTipoPqrs = "TRIM(COALESCE(`tema_ayuda`, '')) AS tipo_pqrs";
    } else {
      $selectTipoPqrs = "TRIM(COALESCE(`tipo_pqrs`, '')) AS tipo_pqrs";
    }

    $wherePublic = [];
    $args = [];
    $creatorPh = implode(',', array_fill(0, count($creatorLabels), '?'));
    if ($this->column_exists($ticketsTable, 'creado_por')) {
      $wherePublic[] = "LOWER(TRIM(COALESCE(`creado_por`, ''))) IN ({$creatorPh})";
      $args = array_merge($args, $creatorLabels);
    }
    if ($this->column_exists($ticketsTable, 'creador_por')) {
      $wherePublic[] = "LOWER(TRIM(COALESCE(`creador_por`, ''))) IN ({$creatorPh})";
      $args = array_merge($args, $creatorLabels);
    }
    $medioPh = implode(',', array_fill(0, count($medioLabels), '?'));
    $wherePublic[] = "LOWER(TRIM(COALESCE(`medio`, ''))) IN ({$medioPh})";
    $args = array_merge($args, $medioLabels);
    $wherePublic[] = "LOWER(TRIM(COALESCE(`departamento`, ''))) = 'servicio al cliente'";

    $whereSql = '(' . implode(' OR ', $wherePublic) . ')';
    if ($this->column_exists($ticketsTable, 'creador_por')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`creador_por`, ''))) <> 'funcionario'";
    }
    if ($this->column_exists($ticketsTable, 'estado')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) <> 'cerrado'";
    }
    $employeeIdFilter = trim($employeeIdFilter);
    if ($employeeIdFilter !== '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $employeeIdFilter;
    }
    $estadoFilter = strtolower(trim((string) ($filters['estado'] ?? '')));
    if ($estadoFilter !== '' && $this->column_exists($ticketsTable, 'estado')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) = ?";
      $args[] = $estadoFilter;
    }
    $empleadoFilter = trim((string) ($filters['empleado'] ?? ''));
    if ($empleadoFilter !== '' && $employeeIdFilter === '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $empleadoFilter;
    }
    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`asunto`, '')) AS asunto,
              TRIM(COALESCE(`solicitante`, '')) AS solicitante,
              TRIM(COALESCE(`correo_solicitante`, '')) AS correo_solicitante,
              TRIM(COALESCE(`celular_solicitante`, '')) AS celular_solicitante,
              {$selectTipoPqrs},
              TRIM(COALESCE(`departamento`, '')) AS departamento,
              TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              TRIM(COALESCE(`empleado`, '')) AS empleado,
              TRIM(COALESCE(`estado`, '')) AS estado,
              TRIM(COALESCE(`medio`, '')) AS medio,
              COALESCE(`fecha_actualizacion`, `fecha`, 0) AS fecha_ref,
              {$selectCreadoPor},
              {$selectCreadorPor}
            FROM `{$ticketsTable}`
            WHERE {$whereSql}
            ORDER BY `_ID` DESC
            LIMIT {$limit}";

    return $this->db->getResults($sql, $args);
  }

  /** @param array<int,array<string,mixed>> $rows @param array<int,mixed> $funcionarios */
  private function render_public_pqr_tab(array $rows, array $funcionarios, string $ticketUrl = '', string $employeeIdFilter = '', bool $readOnly = false, array $publicAccess = [], array $filters = []): string
  {
    $themes = $readOnly ? [] : $this->get_public_pqr_themes();
    $corresponsables = $readOnly ? [] : $this->get_public_pqr_corresponsables();
    $corresponsableCandidates = $readOnly ? [] : $this->get_public_pqr_corresponsable_candidates();
    $publicToken = trim((string) ($publicAccess['token'] ?? ''));
    $publicEmployeeId = trim((string) ($publicAccess['id_empleado'] ?? ''));
    $publicExp = trim((string) ($publicAccess['exp'] ?? ''));
    $publicSig = trim((string) ($publicAccess['sig'] ?? ''));
    $publicScope = mb_strtolower(trim((string) ($publicAccess['scope'] ?? 'assigned')), 'UTF-8');
    if ($publicScope !== 'all') {
      $publicScope = 'assigned';
    }
    $isSignedPublicAccess = $publicEmployeeId !== '' && ($publicToken !== '' || ($publicExp !== '' && $publicSig !== ''));
    $isGlobalSignedAccess = $isSignedPublicAccess && $publicScope === 'all';

    $allowedCorrespCargos = ['11', '12', '13', '14'];
    $isAdmin = !$readOnly && !$isSignedPublicAccess && in_array(Auth::userCargo(), $allowedCorrespCargos, true);

    // ── Build settings modal HTML (only for allowed cargos) ──────────────────
    $settingsModalHtml = '';
    if ($isAdmin) {
      $currentNotifIds = \SCM\Modules\PublicTickets\PublicTicketsService::getNotifResponsables();
      $selectedNotifMap = [];
      foreach ($currentNotifIds as $notifId) {
        $notifId = trim((string) $notifId);
        if ($notifId !== '') {
          $selectedNotifMap[$notifId] = true;
        }
      }
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

      $correspGridHtml = '';
      foreach ($themes as $theme) {
        $assignmentValue = is_array($corresponsables[$theme] ?? null) ? $corresponsables[$theme] : [];

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

        $generalOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'default'));
        $ownerOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'propietario'));
        $tenantOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'arrendatario'));
        $coproOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'copropiedad'));
        $clientOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'cliente'));

        $correspGridHtml .= '<form class="scm-public-pqr-corresponsable-form scm-pqr-config-form" method="post" autocomplete="off" style="display:flex;flex-direction:column;gap:6px;background:#f8fbff;border:1px solid #e5edf7;border-radius:8px;padding:8px;">';
        $correspGridHtml .= '<input type="hidden" name="tema_ayuda" value="' . self::h((string) $theme) . '">';
        $correspGridHtml .= '<label style="font-size:12px;font-weight:600;color:#33465d;">' . self::h((string) $theme) . '</label>';
        $correspGridHtml .= '<small style="color:#5d728a;">General aplica cuando no hay responsable especifico por actor.</small>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;">General</label>';
        $correspGridHtml .= '<select name="corresponsable_ids[]" class="select select-bordered select-sm scm-select" multiple size="4">' . $generalOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Propietario</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[propietario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $ownerOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Arrendatario</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[arrendatario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $tenantOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Copropiedad</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[copropiedad][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $coproOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Cliente</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[cliente][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $clientOptions . '</select>';
        $correspGridHtml .= '<small style="color:#5d728a;">Ctrl/Command + click para seleccionar varios o quitar seleccion.</small>';
        $correspGridHtml .= '<div style="display:flex;align-items:center;gap:8px;">';
        $correspGridHtml .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button>';
        $correspGridHtml .= '<small class="scm-public-pqr-corresponsable-msg" aria-live="polite"></small>';
        $correspGridHtml .= '</div>';
        $correspGridHtml .= '</form>';
      }

      if (empty($corresponsableCandidates)) {
        $correspGridHtml = '<p style="margin:0 0 8px;color:#a95b26;font-size:12px;">No hay funcionarios disponibles para configurar.</p>';
      }

      $settingsModalHtml  = '<div id="scm-pqr-settings-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);overflow-y:auto;padding:18px 10px;align-items:flex-start;justify-content:center;" onclick="if(event.target===this)this.style.display=\'none\'">';
      $settingsModalHtml .= '<div style="background:#fff;border-radius:14px;max-width:760px;width:95%;max-height:calc(100vh - 36px);overflow:auto;margin:0;padding:24px 20px 20px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18);">';
      $settingsModalHtml .= '<button type="button" onclick="document.getElementById(\'scm-pqr-settings-modal\').style.display=\'none\'" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:#4c6077;" aria-label="Cerrar">&times;</button>';
      $settingsModalHtml .= '<h3 style="margin:0 0 18px;font-size:16px;font-weight:700;color:#1a2d42;">Configuracion de Solicitudes Web</h3>';
      // Notif responsable
      $settingsModalHtml .= '<div style="border:1px solid #dbe6f1;border-radius:10px;padding:14px;margin:0 0 16px;">';
      $settingsModalHtml .= '<h4 style="margin:0 0 6px;font-size:13px;font-weight:700;">Funcionario que recibe notificaciones de nuevas Solicitudes</h4>';
      $settingsModalHtml .= '<p style="margin:0 0 8px;color:#5d728a;font-size:12px;">Recibira WhatsApp y correo cada vez que se cree una solicitud desde el portal. Puedes seleccionar varios funcionarios.</p>';
      $settingsModalHtml .= '<form class="scm-notif-responsable-form scm-pqr-config-form" method="post" autocomplete="off" style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">';
      $settingsModalHtml .= '<div style="flex:1 1 320px;min-width:260px;">';
      $settingsModalHtml .= '<select name="notif_responsable_ids[]" class="select select-bordered select-sm scm-select" style="min-width:220px;" multiple size="6">' . $notifOptions . '</select>';
      $settingsModalHtml .= '<small style="display:block;margin-top:4px;color:#5d728a;">Ctrl/Command + click para seleccionar o quitar varios funcionarios.</small>';
      $settingsModalHtml .= '</div>';
      $settingsModalHtml .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button>';
      $settingsModalHtml .= '<small class="scm-notif-responsable-msg" aria-live="polite"></small>';
      $settingsModalHtml .= '</form>';
      $settingsModalHtml .= '</div>';
      // Corresponsables por tipo
      $settingsModalHtml .= '<div style="border:1px solid #dbe6f1;border-radius:10px;padding:14px;">';
      $settingsModalHtml .= '<h4 style="margin:0 0 4px;font-size:13px;font-weight:700;">Corresponsable por tipo de Solicitud/Peticion</h4>';
      $settingsModalHtml .= '<p style="margin:0 0 10px;color:#5d728a;font-size:12px;">Puedes seleccionar varios corresponsables por tipo.</p>';
      $settingsModalHtml .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;max-height:60vh;overflow:auto;padding-right:4px;">' . $correspGridHtml . '</div>';
      $settingsModalHtml .= '</div>';
      $settingsModalHtml .= '</div></div>';
    }

    $html = '<div class="scm-filter-card card">';
    // Header row with title + settings button
    $html .= '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">';
    $html .= '<h3 style="margin:0;">Solicitudes Web</h3>';
    if ($isAdmin) {
      $html .= '<button type="button" onclick="document.getElementById(\'scm-pqr-settings-modal\').style.display=\'flex\'" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:5px;"><svg xmlns=\'http://www.w3.org/2000/svg\' width=\'14\' height=\'14\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><circle cx=\'12\' cy=\'12\' r=\'3\'></circle><path d=\'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z\'></path></svg> Configuracion</button>';
    }
    $html .= '</div>';
    $html .= '<p style="margin:0 0 10px;color:#4c6077;">' . ($readOnly ? 'Consulta publica de solicitudes creadas desde los portales publicos.' : ($isGlobalSignedAccess ? 'Este acceso corporativo puede revisar y trasladar todas las solicitudes web.' : ($isSignedPublicAccess ? 'Este acceso corporativo puede revisar y trasladar las solicitudes asignadas a tu ID.' : 'Gestiona las solicitudes creadas por Propietario, Arrendatario, Copropiedad y Cliente.'))) . '</p>';
    $kpiTotal = count($rows);
    $kpiNuevo = 0;
    $kpiEnProceso = 0;
    $filterFuncionarios = [];
    foreach ($rows as $kpiRow) {
      $estadoKpi = mb_strtolower(trim((string) ($kpiRow['estado'] ?? '')), 'UTF-8');
      if ($estadoKpi === 'nuevo') {
        $kpiNuevo++;
      } elseif ($estadoKpi === 'en proceso') {
        $kpiEnProceso++;
      }
      $filterEmpleadoId = trim((string) ($kpiRow['id_empleado'] ?? ''));
      if ($filterEmpleadoId !== '') {
        $filterEmpleadoLabel = trim((string) ($kpiRow['empleado'] ?? ''));
        if ($filterEmpleadoLabel === '') {
          $filterEmpleadoLabel = 'ID ' . $filterEmpleadoId;
        }
        $filterFuncionarios[$filterEmpleadoId] = [
          'id' => $filterEmpleadoId,
          'label' => $filterEmpleadoLabel . ' (' . $filterEmpleadoId . ')',
        ];
      }
    }
    if (!empty($filterFuncionarios)) {
      uasort($filterFuncionarios, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
      });
    }
    $html .= '<div class="scm-kpis scm-kpis-daisy scm-public-pqr-kpis">';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">Total</div><div class="scm-kpi-value">' . self::h((string) $kpiTotal) . '</div></div>';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">Nuevo</div><div class="scm-kpi-value">' . self::h((string) $kpiNuevo) . '</div></div>';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">En proceso</div><div class="scm-kpi-value">' . self::h((string) $kpiEnProceso) . '</div></div>';
    $html .= '</div>';
    $currentEstado = trim((string) ($filters['estado'] ?? ''));
    $currentEmpleado = trim((string) ($filters['empleado'] ?? ''));
    $showEmployeeFilter = !$readOnly || $isGlobalSignedAccess;
    if ($employeeIdFilter !== '' && ($readOnly || $isSignedPublicAccess)) {
      $showEmployeeFilter = false;
    }
    $clearQuery = ['scm_tab' => 'scm-panel-pqr-publico'];
    if ($isSignedPublicAccess && $publicToken !== '') {
      $clearQuery['k'] = $publicToken;
    } elseif ($isSignedPublicAccess) {
      $clearQuery['id_empleado'] = $publicEmployeeId;
      $clearQuery['scope'] = $publicScope;
      $clearQuery['exp'] = $publicExp;
      $clearQuery['sig'] = $publicSig;
    } elseif ($employeeIdFilter !== '') {
      $clearQuery['id_empleado'] = $employeeIdFilter;
    }
    $clearUrl = remove_query_arg(['public_pqr_estado', 'public_pqr_empleado', 'scm_tab', 'tab', 'k', 'scope', 'exp', 'sig', 'id_empleado']);
    $clearUrl = add_query_arg($clearQuery, $clearUrl);
    $html .= '<form method="get" autocomplete="off" class="scm-public-pqr-filters scm-public-pqr-filter-form">';
    if ($isSignedPublicAccess && $publicToken !== '') {
      $html .= '<input type="hidden" name="k" value="' . self::h($publicToken) . '">';
    } elseif ($isSignedPublicAccess) {
      $html .= '<input type="hidden" name="id_empleado" value="' . self::h($publicEmployeeId) . '">';
      $html .= '<input type="hidden" name="scope" value="' . self::h($publicScope) . '">';
      $html .= '<input type="hidden" name="exp" value="' . self::h($publicExp) . '">';
      $html .= '<input type="hidden" name="sig" value="' . self::h($publicSig) . '">';
    } elseif ($employeeIdFilter !== '') {
      $html .= '<input type="hidden" name="id_empleado" value="' . self::h($employeeIdFilter) . '">';
    }
    $html .= '<input type="hidden" name="scm_tab" value="scm-panel-pqr-publico">';
    $html .= '<div class="scm-public-pqr-filter-field"><label>Estado</label>';
    $html .= '<select name="public_pqr_estado" class="select select-bordered select-sm scm-select">';
    $html .= '<option value="">Todos</option>';
    foreach (['Nuevo', 'En proceso'] as $estadoOption) {
      $selected = strtolower($currentEstado) === strtolower($estadoOption) ? ' selected' : '';
      $html .= '<option value="' . self::h($estadoOption) . '"' . $selected . '>' . self::h($estadoOption) . '</option>';
    }
    $html .= '</select></div>';
    if ($showEmployeeFilter) {
      $html .= '<div class="scm-public-pqr-filter-field scm-public-pqr-filter-field-wide"><label>Funcionario</label>';
      $html .= '<select name="public_pqr_empleado" class="select select-bordered select-sm scm-select">';
      $html .= '<option value="">Todos</option>';
      foreach ($filterFuncionarios as $func) {
        $fId = trim((string) ($func['id'] ?? ''));
        if ($fId === '') {
          continue;
        }
        $selected = $currentEmpleado === $fId ? ' selected' : '';
        $html .= '<option value="' . self::h($fId) . '"' . $selected . '>' . self::h((string) ($func['label'] ?? $fId)) . '</option>';
      }
      $html .= '</select></div>';
    }
    $html .= '<div class="scm-public-pqr-filter-actions">';
    $html .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Filtrar</button>';
    $html .= '<a href="' . self::h($clearUrl) . '" class="btn btn-sm">Limpiar</a>';
    $html .= '</div>';
    $html .= '</form>';
    if (empty($rows)) {
      $html .= '<p style="margin:0;color:#4c6077;">' . ($readOnly ? 'No hay solicitudes web disponibles para este acceso.' : 'No hay solicitudes creadas desde portales publicos para gestionar.') . '</p>';
      $html .= '</div>';
      return $html . $settingsModalHtml;
    }

    $html .= '<div class="result-wrap"><table class="scm-go-table"><thead><tr>';
    $html .= '<th>ID</th><th>Fecha</th><th>Creado por</th><th>Solicitante</th><th>Asunto</th><th>Tipo Solicitud/Peticion</th><th>Estado</th><th>Departamento</th><th>Funcionario</th><th>' . ($readOnly ? 'Detalle' : 'Accion') . '</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk <= 0) {
        continue;
      }
      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId === '') {
        $logicalId = (string) $ticketPk;
      }
      $tipoActual = trim((string) ($row['tema_ayuda'] ?? $row['tipo_pqrs'] ?? ''));
      $deptoActual = trim((string) ($row['departamento'] ?? ''));
      $empActual = trim((string) ($row['empleado'] ?? ''));
      $empIdActual = trim((string) ($row['id_empleado'] ?? ''));
      if ($empActual === '' && $empIdActual !== '') {
        $empActual = 'ID ' . $empIdActual;
      }

      $creadoPor = trim((string) ($row['creado_por'] ?? ''));
      if ($creadoPor === '') {
        $creadoPor = trim((string) ($row['creador_por'] ?? ''));
      }
      if ($creadoPor === '') {
        $medio = mb_strtolower(trim((string) ($row['medio'] ?? '')), 'UTF-8');
        if (strpos($medio, 'propietario') !== false) {
          $creadoPor = 'Propietario';
        } elseif (strpos($medio, 'arrendatario') !== false) {
          $creadoPor = 'Arrendatario';
        } elseif (strpos($medio, 'copropiedad') !== false) {
          $creadoPor = 'Copropiedad';
        } elseif (strpos($medio, 'cliente') !== false) {
          $creadoPor = 'Cliente';
        }
      }

      $fechaRef = $this->parse_unix_ts($row['fecha_ref'] ?? 0);
      $fechaTxt = $this->format_date_time($fechaRef);
      if ($fechaTxt === '') {
        $fechaTxt = '-';
      }
      $estadoActual = trim((string) ($row['estado'] ?? ''));

      $tipoOptsRow = '';
      $deptoOptsRow = '';
      $empOptsRow = '';
      if (!$readOnly) {
        foreach ($themes as $theme) {
          $selected = mb_strtolower($theme, 'UTF-8') === mb_strtolower($tipoActual, 'UTF-8') ? ' selected' : '';
          $tipoOptsRow .= '<option value="' . self::h((string) $theme) . '"' . $selected . '>' . self::h((string) $theme) . '</option>';
        }

        foreach ($this->get_public_pqr_department_options() as $departmentOption) {
          $selectedDept = mb_strtolower($departmentOption, 'UTF-8') === mb_strtolower($deptoActual, 'UTF-8') ? ' selected' : '';
          $deptoOptsRow .= '<option value="' . self::h((string) $departmentOption) . '"' . $selectedDept . '>' . self::h((string) $departmentOption) . '</option>';
        }

        $empOptsRow = '<option value="">Sin cambio de responsable</option>';
        foreach ($funcionarios as $func) {
          $id = trim((string) ($func['id'] ?? ''));
          if ($id === '') {
            continue;
          }
          $label = trim((string) ($func['label'] ?? $id));
          $selected = $id === $empIdActual ? ' selected' : '';
          $empOptsRow .= '<option value="' . self::h($id) . '"' . $selected . '>' . self::h($label) . '</option>';
        }
      }

      $html .= '<tr data-pqr-row="' . self::h((string) $ticketPk) . '">';
      $html .= '<td>#' . self::h($logicalId) . '</td>';
      $html .= '<td>' . self::h($fechaTxt) . '</td>';
      $html .= '<td>' . self::h($creadoPor !== '' ? $creadoPor : '-') . '</td>';
      $html .= '<td>' . self::h(trim((string) ($row['solicitante'] ?? ''))) . '<br><small>' . self::h(trim((string) ($row['celular_solicitante'] ?? ''))) . '</small></td>';
      $html .= '<td>' . self::h(trim((string) ($row['asunto'] ?? ''))) . '</td>';
      $html .= '<td><span class="scm-public-pqr-current-topic">' . self::h($tipoActual !== '' ? $tipoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-state">' . $this->estado_badge($estadoActual !== '' ? $estadoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-department">' . self::h($deptoActual !== '' ? $deptoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-employee">' . self::h($empActual !== '' ? $empActual : '-') . '</span></td>';
      $html .= '<td class="scm-public-pqr-action-cell">';
      $html .= '<div class="scm-public-pqr-actions">';
      if ($ticketUrl !== '' && $logicalId !== '') {
        if ($readOnly) {
          $html .= '<a href="' . self::h($ticketUrl . rawurlencode($logicalId)) . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm scm-public-pqr-view-btn">Ver solicitud</a>';
        } else {
          $html .= '<button type="button" data-scm-open-iframe data-no-emp-check data-iframe-url="' . self::h($ticketUrl . rawurlencode($logicalId)) . '" data-iframe-title="Solicitud #' . self::h($logicalId) . '" class="btn btn-sm scm-public-pqr-view-btn">Ver solicitud</button>';
        }
      }
      if (!$readOnly) {
        $html .= '<button type="button" class="scm-btn-primary btn btn-primary btn-sm scm-public-pqr-transfer-btn" data-scm-open-pqr-transfer data-ticket-pk="' . self::h((string) $ticketPk) . '" data-ticket-logical="' . self::h($logicalId) . '">Trasladar</button>';
        $html .= '<small class="scm-public-pqr-row-msg" aria-live="polite"></small>';
      }
      $html .= '</div>';
      if (!$readOnly) {
        $formClass = $isSignedPublicAccess ? 'scm-public-pqr-public-form' : 'scm-public-pqr-form scm-public-pqr-form-inline';
        $html .= '<form class="' . self::h($formClass) . '" method="post" autocomplete="off" data-ticket-pk="' . self::h((string) $ticketPk) . '" style="display:none;">';
        if ($isSignedPublicAccess) {
          $html .= '<input type="hidden" name="public_action" value="asignar_pqr_publico">';
          if ($publicToken !== '') {
            $html .= '<input type="hidden" name="access_token" value="' . self::h($publicToken) . '">';
          }
          $html .= '<input type="hidden" name="id_empleado" value="' . self::h($publicEmployeeId) . '">';
          $html .= '<input type="hidden" name="scope" value="' . self::h($publicScope) . '">';
          $html .= '<input type="hidden" name="exp" value="' . self::h($publicExp) . '">';
          $html .= '<input type="hidden" name="sig" value="' . self::h($publicSig) . '">';
        }
        $html .= '<input type="hidden" name="ticket_pk" value="' . self::h((string) $ticketPk) . '">';
        $html .= '<label style="display:block;margin:2px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Tipo de solicitud</label>';
        $html .= '<select name="tema_ayuda" class="select select-bordered select-sm scm-select" required>' . $tipoOptsRow . '</select>';
        $html .= '<label style="display:block;margin:8px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Departamento</label>';
        $html .= '<select name="departamento" class="select select-bordered select-sm scm-select" required>' . $deptoOptsRow . '</select>';
        $html .= '<label style="display:block;margin:8px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Funcionario responsable</label>';
        $html .= '<select name="new_empleado_id" class="select select-bordered select-sm scm-select">' . $empOptsRow . '</select>';
        $html .= '<div style="display:flex;align-items:center;gap:8px;margin-top:6px;">';
        $html .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Trasladar</button>';
        if (!$isSignedPublicAccess) {
          $html .= '<small class="scm-public-pqr-msg" aria-live="polite"></small>';
        }
        $html .= '</div>';
        $html .= '</form>';
      }
      $html .= '</td></tr>';
    }

    $html .= '</tbody></table></div></div>';
    return $html . $settingsModalHtml;
  }

  public function renderPublicPqrReadonlyPage(string $employeeIdFilter = '', array $overrideConfig = []): string
  {
    $config = array_merge([
      'ticket_url' => self::DEFAULT_TICKET_URL,
    ], $overrideConfig);

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeIdFilter));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }

    $filters = $this->get_public_pqr_filters_from_request();
    $rows = $this->fetch_public_pqr_rows(180, $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $rows,
      [],
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $employeeIdFilter,
      true,
      [],
      $filters
    );

    $assetBaseUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    $cssRel = 'assets/css/scm-admin.css';
    $cssPath = $assetBasePath . $cssRel;
    $cssVer = file_exists($cssPath) ? (string) filemtime($cssPath) : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));

    ob_start();
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Solicitudes Web</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
      <style>
        body {
          margin: 0;
          font-family: 'Poppins', sans-serif;
          background:
            radial-gradient(circle at 8% 0%, rgba(255, 122, 0, .10), transparent 26%),
            radial-gradient(circle at 95% 0%, rgba(47, 111, 167, .10), transparent 32%),
            #f3f7fc;
          color: #1c3048;
        }

        .scm-public-shell {
          max-width: 1240px;
          margin: 0 auto;
          padding: 24px 16px 40px;
        }

        .scm-public-header {
          margin-bottom: 18px;
        }

        .scm-public-brand-logo {
          width: 154px;
          min-height: 48px;
          border-radius: 8px;
          background: #fff;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 7px 10px;
          margin-bottom: 12px;
          box-shadow: 0 12px 26px rgba(22, 48, 77, .12);
        }

        .scm-public-brand-logo img {
          display: block;
          width: auto;
          max-width: 100%;
          max-height: 34px;
          object-fit: contain;
        }

        .scm-public-kicker {
          display: inline-block;
          margin-bottom: 8px;
          padding: 6px 10px;
          border-radius: 999px;
          background: rgba(31, 76, 120, .10);
          color: #1f4c78;
          font-size: 12px;
          font-weight: 600;
        }

        .scm-public-header h1 {
          margin: 0 0 6px;
          font-size: 28px;
          color: #17304d;
        }

        .scm-public-header p {
          margin: 0;
          color: #4c6077;
        }
      </style>
    </head>

    <body>
      <main class="scm-public-shell">
        <header class="scm-public-header">
          <span class="scm-public-brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span class="scm-public-kicker">Acceso publico</span>
          <h1>Solicitudes Web</h1>
          <p>Consulta de solo lectura para revisar solicitudes creadas desde los portales publicos.</p>
        </header>
        <?php echo $contentHtml; ?>
      </main>
    </body>

    </html>
  <?php
    return (string) ob_get_clean();
  }

  public function renderPublicPqrSignedAccessPage(string $employeeIdFilter = '', string $expiresAt = '', string $signature = '', array $overrideConfig = [], string $flashMessage = '', string $flashType = 'success', string $accessScope = 'assigned', string $accessToken = ''): string
  {
    $config = array_merge([
      'ticket_url' => self::DEFAULT_TICKET_URL,
    ], $overrideConfig);

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeIdFilter));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }
    $accessScope = mb_strtolower(trim($accessScope), 'UTF-8');
    if ($accessScope !== 'all') {
      $accessScope = 'assigned';
    }

    $filters = $this->get_public_pqr_filters_from_request();
    $rows = $this->fetch_public_pqr_rows(180, $accessScope === 'all' ? '' : $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $rows,
      $this->get_public_pqr_corresponsable_candidates(),
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $accessScope === 'all' ? '' : $employeeIdFilter,
      false,
      [
        'token' => $accessToken,
        'id_empleado' => $employeeIdFilter,
        'scope' => $accessScope,
        'exp' => $expiresAt,
        'sig' => $signature,
      ],
      $filters
    );

    $assetBaseUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    $cssRel = 'assets/css/scm-admin.css';
    $cssPath = $assetBasePath . $cssRel;
    $cssVer = file_exists($cssPath) ? (string) filemtime($cssPath) : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $flashType = $flashType === 'error' ? 'error' : 'success';

    ob_start();
  ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Solicitudes Web</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
      <style>
        body {
          margin: 0;
          font-family: 'Poppins', sans-serif;
          background:
            radial-gradient(circle at 8% 0%, rgba(255, 122, 0, .10), transparent 26%),
            radial-gradient(circle at 95% 0%, rgba(47, 111, 167, .10), transparent 32%),
            #f3f7fc;
          color: #1c3048;
        }

        .scm-public-shell {
          max-width: 1360px;
          margin: 0 auto;
          padding: 28px 20px 56px;
        }

        .scm-public-header {
          margin-bottom: 22px;
          padding: 28px 30px;
          border: 1px solid rgba(199, 213, 229, .9);
          border-radius: 24px;
          background: linear-gradient(145deg, rgba(255, 255, 255, .97), rgba(242, 247, 253, .95));
          box-shadow: 0 22px 50px rgba(22, 48, 77, .08);
        }

        .scm-public-brand-logo {
          width: 154px;
          min-height: 48px;
          border-radius: 8px;
          background: #fff;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 7px 10px;
          margin-bottom: 12px;
          box-shadow: 0 12px 26px rgba(22, 48, 77, .12);
        }

        .scm-public-brand-logo img {
          display: block;
          width: auto;
          max-width: 100%;
          max-height: 34px;
          object-fit: contain;
        }

        .scm-public-kicker {
          display: inline-block;
          margin-bottom: 8px;
          padding: 7px 12px;
          border-radius: 999px;
          background: rgba(31, 76, 120, .10);
          color: #1f4c78;
          font-size: 12px;
          font-weight: 700;
          letter-spacing: .02em;
        }

        .scm-public-header h1 {
          margin: 0 0 8px;
          font-size: 40px;
          line-height: 1.05;
          color: #17304d;
        }

        .scm-public-header p {
          margin: 0;
          max-width: 720px;
          color: #4c6077;
          font-size: 18px;
          line-height: 1.55;
        }

        .scm-public-alert {
          margin: 0 0 16px;
          padding: 14px 16px;
          border-radius: 14px;
          font-size: 14px;
          font-weight: 600;
          box-shadow: 0 10px 24px rgba(22, 48, 77, .06);
        }

        .scm-public-alert-success {
          background: #ecfdf3;
          border: 1px solid #86efac;
          color: #166534;
        }

        .scm-public-alert-error {
          background: #fef2f2;
          border: 1px solid #fca5a5;
          color: #991b1b;
        }

        .scm-public-pqr-public-form {
          display: block;
          margin-top: 10px;
        }

        .scm-public-pqr-public-form .scm-select {
          width: 100%;
          min-height: 42px;
          border: 1px solid #cad8e8;
          border-radius: 12px;
          background: #fff;
          box-shadow: inset 0 1px 2px rgba(15, 23, 42, .04);
        }

        .scm-filter-card.card {
          border: 1px solid rgba(199, 213, 229, .95);
          border-radius: 22px;
          background: rgba(255, 255, 255, .97);
          box-shadow: 0 18px 40px rgba(22, 48, 77, .08);
          padding: 22px 22px 24px;
        }

        .scm-filter-card.card h3 {
          margin: 0 0 6px;
          font-size: 18px;
          color: #17304d;
        }

        .result-wrap {
          margin-top: 18px;
          overflow: auto;
          border: 1px solid #d6e2ef;
          border-radius: 18px;
          background: #fff;
        }

        .scm-go-table {
          width: 100%;
          border-collapse: collapse;
          min-width: 1120px;
        }

        .scm-go-table thead th {
          padding: 16px 14px;
          background: linear-gradient(135deg, #17304d, #223d5b);
          color: #fff;
          font-size: 12px;
          letter-spacing: .03em;
          text-transform: uppercase;
          text-align: left;
          border: 0;
        }

        .scm-go-table tbody td {
          padding: 16px 14px;
          vertical-align: top;
          border-bottom: 1px solid #e8eef5;
          color: #26425f;
          font-size: 14px;
          line-height: 1.45;
          background: #fff;
        }

        .scm-go-table tbody tr:hover td {
          background: #f7fbff;
        }

        .scm-go-table tbody tr:last-child td {
          border-bottom: 0;
        }

        .scm-public-pqr-current-topic,
        .scm-public-pqr-current-department,
        .scm-public-pqr-current-employee {
          display: inline-block;
          padding: 6px 10px;
          border-radius: 999px;
          background: #edf4fb;
          color: #204567;
          font-weight: 600;
          font-size: 12px;
        }

        .scm-public-pqr-actions {
          display: flex;
          flex-direction: column;
          align-items: stretch;
          gap: 10px;
        }

        .scm-public-pqr-view-btn,
        .scm-public-pqr-transfer-btn,
        .scm-public-pqr-public-form button[type="submit"] {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          min-height: 42px;
          padding: 10px 14px;
          border: 0;
          border-radius: 12px;
          text-decoration: none;
          font-size: 13px;
          font-weight: 700;
          cursor: pointer;
          transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
        }

        .scm-public-pqr-view-btn {
          background: linear-gradient(135deg, #17304d, #28507b);
          color: #fff;
          box-shadow: 0 10px 22px rgba(23, 48, 77, .18);
        }

        .scm-public-pqr-transfer-btn {
          background: linear-gradient(135deg, #f59120, #db7d11);
          color: #fff;
          box-shadow: 0 10px 22px rgba(245, 145, 32, .24);
        }

        .scm-public-pqr-public-form button[type="submit"] {
          background: linear-gradient(135deg, #f59120, #db7d11);
          color: #fff;
          box-shadow: 0 10px 22px rgba(245, 145, 32, .24);
        }

        .scm-public-pqr-view-btn:hover,
        .scm-public-pqr-public-form button[type="submit"]:hover {
          transform: translateY(-1px);
        }

        .scm-public-pqr-public-form>div {
          justify-content: flex-start;
        }

        .scm-pqr-transfer-overlay,
        .scm-public-iframe-overlay {
          position: fixed;
          inset: 0;
          z-index: 99999;
          display: none;
        }

        .scm-pqr-transfer-overlay.open,
        .scm-public-iframe-overlay.open {
          display: block;
        }

        .scm-pqr-transfer-backdrop,
        .scm-public-iframe-backdrop {
          position: absolute;
          inset: 0;
          background: rgba(11, 22, 35, .62);
        }

        .scm-pqr-transfer-dialog,
        .scm-public-iframe-dialog {
          position: relative;
          margin: 4vh auto 0;
          width: min(760px, 96vw);
          max-height: 92vh;
          overflow: auto;
          border-radius: 16px;
          border: 1px solid #d8e5f2;
          background: #fff;
          box-shadow: 0 18px 44px rgba(13, 30, 47, .34);
          padding: 16px 16px 14px;
        }

        .scm-public-iframe-dialog {
          width: min(1240px, 98vw);
          height: 92vh;
          padding: 14px;
          display: flex;
          flex-direction: column;
          overflow: hidden;
        }

        .scm-pqr-transfer-close,
        .scm-public-iframe-close {
          position: absolute;
          top: 8px;
          right: 10px;
          width: 34px;
          height: 34px;
          border: 0;
          border-radius: 10px;
          background: #f2f7fd;
          color: #17304d;
          font-size: 24px;
          cursor: pointer;
        }

        .scm-pqr-transfer-title,
        .scm-public-iframe-title {
          margin: 0 40px 12px 0;
          font-size: 18px;
          color: #17304d;
        }

        .scm-public-iframe-frame {
          width: 100%;
          height: calc(100% - 40px);
          border: 1px solid #d6e2ef;
          border-radius: 12px;
          background: #fff;
        }

        @media (max-width: 860px) {
          .scm-public-shell {
            padding: 18px 12px 36px;
          }

          .scm-public-header {
            padding: 22px 18px;
            border-radius: 18px;
          }

          .scm-public-header h1 {
            font-size: 32px;
          }

          .scm-public-header p {
            font-size: 16px;
          }
        }
      </style>
    </head>

    <body>
      <main class="scm-public-shell">
        <header class="scm-public-header">
          <span class="scm-public-brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span class="scm-public-kicker">Acceso corporativo</span>
          <h1>Solicitudes Web</h1>
          <p><?php echo $accessScope === 'all' ? 'Desde este enlace puedes revisar y trasladar todas las solicitudes web.' : 'Desde este enlace puedes revisar y trasladar las solicitudes asignadas a tu ID.'; ?></p>
        </header>
        <?php if ($flashMessage !== ''): ?>
          <div class="scm-public-alert scm-public-alert-<?php echo self::h($flashType); ?>"><?php echo self::h($flashMessage); ?></div>
        <?php endif; ?>
        <?php echo $contentHtml; ?>
      </main>
      <script>
        (function() {
          var panel = document.getElementById('scm-panel-pqr-publico') || document.querySelector('.scm-filter-card.card');
          if (!panel) return;

          function ensureTransferModal() {
            var modal = document.getElementById('scm-pqr-transfer-modal');
            if (modal) return modal;
            modal = document.createElement('div');
            modal.id = 'scm-pqr-transfer-modal';
            modal.className = 'scm-pqr-transfer-overlay';
            modal.innerHTML =
              '<div class="scm-pqr-transfer-backdrop" data-close-pqr-transfer="1"></div>' +
              '<div class="scm-pqr-transfer-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-pqr-transfer-title">' +
              '<button type="button" class="scm-pqr-transfer-close" data-close-pqr-transfer="1" aria-label="Cerrar">&times;</button>' +
              '<h4 id="scm-pqr-transfer-title" class="scm-pqr-transfer-title">Trasladar Solicitud</h4>' +
              '<div class="scm-pqr-transfer-body"></div>' +
              '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function(ev) {
              var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-pqr-transfer="1"]') : null;
              if (!closeTarget) return;
              closeTransferModal();
            });
            return modal;
          }

          function closeTransferModal() {
            var modal = document.getElementById('scm-pqr-transfer-modal');
            if (!modal) return;
            modal.classList.remove('open');
            var body = modal.querySelector('.scm-pqr-transfer-body');
            if (body) body.innerHTML = '';
          }

          function openTransferModal(triggerBtn) {
            if (!triggerBtn) return;
            var row = triggerBtn.closest('tr[data-pqr-row]');
            if (!row) return;
            var sourceForm = row.querySelector('form.scm-public-pqr-form-inline, form.scm-public-pqr-public-form');
            if (!sourceForm) return;

            var modal = ensureTransferModal();
            var body = modal.querySelector('.scm-pqr-transfer-body');
            if (!body) return;
            body.innerHTML = '';

            var clonedForm = sourceForm.cloneNode(true);
            clonedForm.classList.remove('scm-public-pqr-form-inline');
            clonedForm.classList.add('scm-public-pqr-form-modal');
            clonedForm.style.display = 'block';
            body.appendChild(clonedForm);

            var logicalId = (triggerBtn.getAttribute('data-ticket-logical') || '').trim();
            var title = modal.querySelector('.scm-pqr-transfer-title');
            if (title) {
              title.textContent = logicalId ? ('Trasladar Solicitud #' + logicalId) : 'Trasladar Solicitud';
            }

            modal.classList.add('open');
          }

          function ensureIframeModal() {
            var modal = document.getElementById('scm-public-iframe-modal');
            if (modal) return modal;
            modal = document.createElement('div');
            modal.id = 'scm-public-iframe-modal';
            modal.className = 'scm-public-iframe-overlay';
            modal.innerHTML =
              '<div class="scm-public-iframe-backdrop" data-close-public-iframe="1"></div>' +
              '<div class="scm-public-iframe-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-public-iframe-title">' +
              '<button type="button" class="scm-public-iframe-close" data-close-public-iframe="1" aria-label="Cerrar">&times;</button>' +
              '<h4 id="scm-public-iframe-title" class="scm-public-iframe-title">Solicitud</h4>' +
              '<iframe class="scm-public-iframe-frame" src="about:blank" loading="lazy"></iframe>' +
              '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function(ev) {
              var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-public-iframe="1"]') : null;
              if (!closeTarget) return;
              closeIframeModal();
            });
            return modal;
          }

          function closeIframeModal() {
            var modal = document.getElementById('scm-public-iframe-modal');
            if (!modal) return;
            modal.classList.remove('open');
            var iframe = modal.querySelector('iframe.scm-public-iframe-frame');
            if (iframe) iframe.src = 'about:blank';
          }

          function openIframeModal(triggerBtn) {
            if (!triggerBtn) return;
            var url = (triggerBtn.getAttribute('data-iframe-url') || '').trim();
            if (!url) return;
            var titleText = (triggerBtn.getAttribute('data-iframe-title') || 'Solicitud').trim();
            var modal = ensureIframeModal();
            var title = modal.querySelector('.scm-public-iframe-title');
            var iframe = modal.querySelector('iframe.scm-public-iframe-frame');
            if (title) title.textContent = titleText;
            if (iframe) iframe.src = url;
            modal.classList.add('open');
          }

          panel.addEventListener('click', function(e) {
            var transferBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-pqr-transfer]') : null;
            if (transferBtn) {
              e.preventDefault();
              openTransferModal(transferBtn);
              return;
            }
            var iframeBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-iframe]') : null;
            if (iframeBtn) {
              e.preventDefault();
              openIframeModal(iframeBtn);
            }
          });

          panel.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || !form.classList || !form.classList.contains('scm-public-pqr-form-modal')) return;
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Trasladando...';
            }
          });

          document.addEventListener('keydown', function(ev) {
            if (ev.key !== 'Escape') return;
            closeTransferModal();
            closeIframeModal();
          });
        })();
      </script>
    </body>

    </html>
  <?php
    return (string) ob_get_clean();
  }

  /** Panel principal - antes de render_shortcode(). Llamar desde index.php. */
  public function renderPanel(array $overrideConfig = []): string
  {
    $config = array_merge([
      'ticket_url'     => self::DEFAULT_TICKET_URL,
      'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
      'correctiva_url' => self::DEFAULT_CORRECTIVA_URL,
      'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
      'acta_url'       => self::DEFAULT_ACTA_URL,
    ], $overrideConfig);

    $tabla = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($tabla)) {
      return '<div class="scm-error">No existe la tabla ' . self::h($tabla) . '.</div>';
    }

    $module = $this->get_servicios_inmobiliarios_module();
    $filterOptions = $module->getFilterOptions();
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
    $publicPqrEmployeeFilter = trim((string) ($_GET['id_empleado'] ?? ''));
    $publicPqrEmployeeFilter = preg_replace('/[^A-Za-z0-9_-]/', '', $publicPqrEmployeeFilter);
    if (!is_string($publicPqrEmployeeFilter)) {
      $publicPqrEmployeeFilter = '';
    }
    $publicPqrFilters = $this->get_public_pqr_filters_from_request();

    $tabMap = [
      'abiertos' => 'scm-panel-abiertos',
      'abierto' => 'scm-panel-abiertos',
      'scm-panel-abiertos' => 'scm-panel-abiertos',
      'pqr_publico' => 'scm-panel-pqr-publico',
      'pqr-publico' => 'scm-panel-pqr-publico',
      'solicitudes_web' => 'scm-panel-pqr-publico',
      'solicitudes-web' => 'scm-panel-pqr-publico',
      'scm-panel-pqr-publico' => 'scm-panel-pqr-publico',
      'postergados' => 'scm-panel-postergados',
      'scm-panel-postergados' => 'scm-panel-postergados',
      'cerrados' => 'scm-panel-cerrados',
      'scm-panel-cerrados' => 'scm-panel-cerrados',
      'contratos_arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos' => 'scm-panel-contratos-arrendamiento',
      'scm-panel-contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
    ];
    $initialTab = $tabMap[$tabKey] ?? '';
    if ($initialTab === '' && $initialOpenTopic !== '') {
      $initialTab = 'scm-panel-abiertos';
    }
    if ($initialTab === '' && ($publicPqrEmployeeFilter !== '' || $publicPqrFilters['estado'] !== '' || $publicPqrFilters['empleado'] !== '')) {
      $initialTab = 'scm-panel-pqr-publico';
    }
    $iframeModeRaw = mb_strtolower(trim((string) ($_GET['scm_iframe'] ?? ($_GET['iframe'] ?? ''))), 'UTF-8');
    $iframeMode = in_array($iframeModeRaw, ['1', 'true', 'yes', 'si'], true);

    $publicPqrRows = $this->fetch_public_pqr_rows(180, $publicPqrEmployeeFilter, $publicPqrFilters);
    $publicPqrHtml = $this->render_public_pqr_tab(
      $publicPqrRows,
      (array) ($filterOptions['funcionarios'] ?? []),
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $publicPqrEmployeeFilter,
      false,
      [],
      $publicPqrFilters
    );

    $categoryMetrics = [
      'Mantenimiento' => (int)($stats['total'] ?? 0),
      'Entrega' => (int)($genericResults['entrega']['result']['stats']['total'] ?? 0),
      'Preventiva' => (int)($genericResults['preventiva']['result']['stats']['total'] ?? 0),
      'Recibo' => (int)($genericResults['recibo']['result']['stats']['total'] ?? 0),
      'Contable' => (int)($genericResults['contable']['result']['stats']['total'] ?? 0),
      'Certificaciones' => (int)($genericResults['certificaciones']['result']['stats']['total'] ?? 0),
      'Contractual' => (int)($genericResults['contractual']['result']['stats']['total'] ?? 0),
    ];

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
      'publicPqrEmployeeFilter' => $publicPqrEmployeeFilter,
      'guide'   => [
        'enabled' => true,
        'title'   => 'Guia de uso',
        'html'    => '<ul><li>Usa filtros para acotar resultados.</li><li>Atraso ticket = tiempo desde creacion.</li><li>Sin actualizar = tiempo desde ultima actualizacion.</li></ul>',
      ],
      'actions' => [
        'mant'            => self::AJAX_ACTION,
        'seg'             => self::AJAX_SEGUIMIENTO,
        'nota'            => self::AJAX_NOTA,
        'ticket_response' => self::AJAX_TICKET_RESPONSE,
        'postpone_ticket' => self::AJAX_POSTPONE_TICKET,
        'status_tickets'  => self::AJAX_STATUS_TICKETS,
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
    $damageCssRel = 'assets/css/scm-damage-magnitude.css';
    $damageJsRel  = 'assets/js/scm-damage-magnitude.js';
    $cssPath = $assetBasePath . $cssRel;
    $jsPath  = $assetBasePath . $jsRel;
    $damageCssPath = $assetBasePath . $damageCssRel;
    $damageJsPath  = $assetBasePath . $damageJsRel;
    $cssVer = file_exists($cssPath) ? (string)filemtime($cssPath) : SCM_VERSION;
    $jsVer  = file_exists($jsPath)  ? (string)filemtime($jsPath)  : SCM_VERSION;
    $damageCssVer = file_exists($damageCssPath) ? (string)filemtime($damageCssPath) : SCM_VERSION;
    $damageJsVer  = file_exists($damageJsPath)  ? (string)filemtime($damageJsPath)  : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $jsUrl  = self::h($assetBaseUrl . $jsRel  . '?v=' . rawurlencode($jsVer));
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
        <button class="scm-guide-btn" type="button" id="scm-open-guide"><i class="fas fa-book-open"></i> Ver gu&iacute;as</button>
      </div>
      <div class="scm-tabs scm-main-tabs">
        <button class="scm-tab active" data-tab="scm-panel-abiertos" type="button">Abiertos</button>
        <button class="scm-tab" data-tab="scm-panel-postergados" type="button">Postergados</button>
        <button class="scm-tab" data-tab="scm-panel-cerrados" type="button">Cerrados</button>
        <button class="scm-tab" data-tab="scm-panel-preventivas-pendientes" type="button">Preventivas Pendientes</button>
        <button class="scm-tab" data-tab="scm-panel-contratos-arrendamiento" type="button">Contratos de arrendamiento</button>
        <button class="scm-tab" data-tab="scm-panel-servicios-publicos-pendientes" type="button">Servicios P&uacute;blicos Pendientes</button>
        <button class="scm-tab" data-tab="scm-panel-reportes-administrativos-pendientes" type="button">Reportes Administrativos</button>
        <button class="scm-tab" data-tab="scm-panel-pqr-publico" type="button">Solicitudes Web</button>
        <button class="scm-tab" data-tab="scm-panel-metricas" type="button">Metricas</button>
      </div>

      <?php $activeOpenTopic = isset($openTopicDefs[$initialOpenTopic]) ? $initialOpenTopic : 'mant'; ?>
      <div class="scm-tab-panel active" id="scm-panel-abiertos">
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
              <div class="scm-field"><label for="scm_id_empleado">Funcionario</label><select id="scm_id_empleado" name="scm_id_empleado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["funcionarios"] ?? []) as $func): $fId = trim((string)($func["id"] ?? ""));
                                                    if ($fId === "") continue; ?>
                    <option value="<?php echo esc_attr($fId); ?>" <?php selected(($params["fEmpleado"] ?? ""), $fId); ?>><?php echo esc_html((string)($func["label"] ?? $fId)); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_busqueda">B&uacute;squeda</label><input id="scm_busqueda" name="scm_busqueda" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params['fBusqueda'] ?? '')); ?>"></div>
              <div class="scm-field"><label for="scm_inmueble">Inmueble</label><input id="scm_inmueble" name="scm_inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params['fInmueble'] ?? '')); ?>"></div>
              <div class="scm-field"><label for="scm_contrato">Contrato</label><input id="scm_contrato" name="scm_contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fContrato"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_asunto">Asunto</label><input id="scm_asunto" name="scm_asunto" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fAsunto"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_arrendatario">Arrendatario</label><input id="scm_arrendatario" name="scm_arrendatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fArrendatario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_propietario">Propietario</label><input id="scm_propietario" name="scm_propietario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fPropietario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_barrio">Barrio</label><select id="scm_barrio" name="scm_barrio" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["barrios"] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string)$barrioOpt); ?>" <?php selected((string)($params["fBarrio"] ?? ""), (string)$barrioOpt); ?>><?php echo esc_html((string)$barrioOpt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_fecha_desde">Fecha desde</label><input id="scm_fecha_desde" name="scm_fecha_desde" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($params["fFechaDesde"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_fecha_hasta">Fecha hasta</label><input id="scm_fecha_hasta" name="scm_fecha_hasta" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($params["fFechaHasta"] ?? "")); ?>"></div>

              <div class="scm-field"><label for="scm_cotizacion">Cotizaci&oacute;n</label><select id="scm_cotizacion" name="scm_cotizacion" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(($params['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
                  <option value="none" <?php selected(($params['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
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
              <div class="scm-field"><label for="scm_atraso">Atraso ticket</label><select id="scm_atraso" name="scm_atraso" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="3" <?php selected(($params['fAtraso'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
                  <option value="5" <?php selected(($params['fAtraso'] ?? ''), '5'); ?>>+5 d&iacute;as</option>
                  <option value="10" <?php selected(($params['fAtraso'] ?? ''), '10'); ?>>+10 d&iacute;as</option>
                </select></div>
              <div class="scm-field"><label for="scm_sin_actualizar">Sin actualizar</label><select id="scm_sin_actualizar" name="scm_sin_actualizar" class="select select-bordered select-sm scm-select">
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

      <div class="scm-tab-panel" id="scm-panel-preventivas-pendientes">
        <?php echo $preventivasPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-contratos-arrendamiento">
        <?php echo $contratosArrendamientoHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-servicios-publicos-pendientes">
        <?php echo $serviciosPublicosPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-reportes-administrativos-pendientes">
        <?php echo $reportesAdministrativosPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-pqr-publico">
        <?php echo $publicPqrHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-postergados">
        <?php echo $this->render_status_bucket_panel('postergados', $statusBucketDefs['postergados'], $statusTopicDefs, $statusResults['postergados'] ?? []); ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-cerrados">
        <?php echo $this->render_status_bucket_panel('cerrados', $statusBucketDefs['cerrados'], $statusTopicDefs, $statusResults['cerrados'] ?? []); ?>
      </div>

      <script>
        (function() {
          function setup(prefix, action, tableId, kpisId) {
            var form = document.getElementById(prefix + 'form');
            if (!form) return;
            var spinner = document.getElementById(prefix + 'spinner');
            var runtimeRaw = document.getElementById('scm-app')?.getAttribute('data-scm-runtime') || '{}';
            var runtime = {};
            try {
              runtime = JSON.parse(runtimeRaw);
            } catch (e) {}
            var ajaxUrl = runtime.ajaxUrl || '/api.php';
            var nonce = runtime.nonce || '';

            function submitForm() {
              var fd = new FormData(form);
              fd.append('action', action);
              fd.append('nonce', nonce);
              if (spinner) spinner.classList.add('active');
              fetch(ajaxUrl, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) return;
                  var d = json.data || {};
                  var t = document.getElementById(tableId);
                  var k = document.getElementById(kpisId);
                  if (t && typeof d.table_html === 'string') t.innerHTML = d.table_html;
                  if (k && typeof d.kpis_html === 'string') k.innerHTML = d.kpis_html;
                  if (prefix === 'spp_') {
                    var headerCount = document.getElementById('spp-kpi-count');
                    if (headerCount && typeof d.count === 'string') headerCount.textContent = d.count;
                  }
                  if (prefix === 'rsp_') {
                    var rspHeaderCount = document.getElementById('rsp-kpi-count');
                    if (rspHeaderCount && typeof d.count === 'string') rspHeaderCount.textContent = d.count;
                  }
                })
                .finally(function() {
                  if (spinner) spinner.classList.remove('active');
                });
            }

            form.addEventListener('submit', function(e) {
              e.preventDefault();
              submitForm();
            });
            var clearBtn = document.querySelector('[data-pending-clear="' + prefix + '"]');
            if (clearBtn) {
              clearBtn.addEventListener('click', function() {
                form.querySelectorAll('input[type="text"],input[type="date"]').forEach(function(i) {
                  i.value = '';
                });
                form.querySelectorAll('select').forEach(function(s) {
                  s.selectedIndex = 0;
                });
                submitForm();
              });
            }
          }
          setup('spp_', 'scm_preventivas_pendientes', 'spp_table', 'spp_kpis');
          setup('rsp_', 'scm_servicios_publicos_pendientes', 'rsp_table', 'rsp_kpis');

          var sraPanel = document.getElementById('sra_panel');
          if (sraPanel) {
            var runtimeRawSra = document.getElementById('scm-app')?.getAttribute('data-scm-runtime') || '{}';
            var runtimeSra = {};
            try {
              runtimeSra = JSON.parse(runtimeRawSra);
            } catch (e) {}
            var ajaxUrlSra = runtimeSra.ajaxUrl || '/api.php';
            var nonceSra = runtimeSra.nonce || '';
            var actionsSra = runtimeSra.actions || {};
            var sraForm = document.getElementById('sra_form');
            var sraSpinner = document.getElementById('sra_spinner');

            function reloadReportesAdministrativos() {
              var fd = sraForm ? new FormData(sraForm) : new FormData();
              fd.append('action', actionsSra.reportes_administrativos_pendientes || 'scm_reportes_administrativos_pendientes');
              fd.append('nonce', nonceSra);
              if (sraSpinner) sraSpinner.classList.add('active');
              fetch(ajaxUrlSra, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) return;
                  var data = json.data || {};
                  var table = document.getElementById('sra_table');
                  var count = document.getElementById('sra-kpi-count');
                  if (table && typeof data.table_html === 'string') table.innerHTML = data.table_html;
                  if (count && typeof data.count === 'string') count.textContent = data.count;
                })
                .finally(function() {
                  if (sraSpinner) sraSpinner.classList.remove('active');
                });
            }

            if (sraForm) {
              sraForm.addEventListener('submit', function(e) {
                e.preventDefault();
                reloadReportesAdministrativos();
              });
            }

            var sraClearBtn = document.querySelector('[data-pending-clear="sra_"]');
            if (sraClearBtn && sraForm) {
              sraClearBtn.addEventListener('click', function() {
                sraForm.querySelectorAll('input[type="text"],input[type="date"]').forEach(function(i) {
                  i.value = '';
                });
                sraForm.querySelectorAll('select').forEach(function(s) {
                  s.selectedIndex = 0;
                });
                reloadReportesAdministrativos();
              });
            }

            sraPanel.addEventListener('click', function(e) {
              var iframeBtn = e.target.closest('[data-scm-open-iframe]');
              if (iframeBtn) {
                if (typeof window.openIframeModal === 'function') {
                  window.openIframeModal(iframeBtn.dataset.iframeUrl || '', iframeBtn.dataset.iframeTitle || '');
                }
                return;
              }

              var actionBtn = e.target.closest('[data-sra-action]');
              if (!actionBtn) return;

              var preId = actionBtn.getAttribute('data-sra-id') || '';
              var kind = actionBtn.getAttribute('data-sra-action') || '';
              var action = kind === 'approve' ? actionsSra.aprobar_reporte_administrativo : actionsSra.desaprobar_reporte_administrativo;
              var question = kind === 'approve' ? '¿Aprobar este reporte administrativo?' : '¿Marcar este reporte como no aprobado?';
              if (!action || !preId || !window.confirm(question)) return;

              actionBtn.disabled = true;
              var fd = new FormData();
              fd.append('action', action);
              fd.append('nonce', nonceSra);
              fd.append('pre_id', preId);

              fetch(ajaxUrlSra, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    window.alert((json && json.data && json.data.message) || 'No se pudo procesar el reporte.');
                    return;
                  }
                  reloadReportesAdministrativos();
                })
                .catch(function() {
                  window.alert('No se pudo procesar el reporte.');
                })
                .finally(function() {
                  actionBtn.disabled = false;
                });
            });
          }

          var pqrPanel = document.getElementById('scm-panel-pqr-publico');
          if (pqrPanel) {
            function initSelect2OnSelect(selectEl) {
              if (!selectEl || !selectEl.classList || !selectEl.classList.contains('scm-select')) return;
              if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) return;
              var $ = window.jQuery;
              var $select = $(selectEl);
              if ($select.data('select2')) return;

              var isMultiple = !!selectEl.multiple;
              var parentModal = selectEl.closest('#scm-pqr-settings-modal, .scm-pqr-transfer-dialog');
              var config = {
                width: '100%',
                closeOnSelect: !isMultiple,
                placeholder: isMultiple ? 'Buscar y seleccionar...' : 'Seleccionar...',
                allowClear: !isMultiple,
                language: {
                  noResults: function() {
                    return 'Sin resultados';
                  },
                  searching: function() {
                    return 'Buscando...';
                  }
                }
              };
              if (parentModal) {
                config.dropdownParent = $(parentModal);
              }
              $select.select2(config);
            }

            function initEnhancedSelects(scope) {
              if (!scope || !scope.querySelectorAll) return;
              scope.querySelectorAll('select.scm-select').forEach(function(selectEl) {
                if (selectEl.closest && selectEl.closest('.scm-public-pqr-form-inline')) {
                  return;
                }
                initSelect2OnSelect(selectEl);
              });
            }

            function setupSettingsObserver() {
              var settingsModal = document.getElementById('scm-pqr-settings-modal');
              if (!(settingsModal && window.MutationObserver) || settingsModal.dataset.scmObserved === '1') {
                return;
              }
              settingsModal.dataset.scmObserved = '1';
              var observer = new MutationObserver(function() {
                if (settingsModal.style.display === 'flex') {
                  initEnhancedSelects(settingsModal);
                }
              });
              observer.observe(settingsModal, {
                attributes: true,
                attributeFilter: ['style']
              });
            }

            function findPqrRowByTicketPk(ticketPk) {
              ticketPk = (ticketPk || '').trim();
              if (ticketPk === '') return null;
              return pqrPanel.querySelector('tr[data-pqr-row="' + ticketPk.replace(/"/g, '\\"') + '"]');
            }

            function ensureTransferModal() {
              var modal = pqrPanel.querySelector('#scm-pqr-transfer-modal');
              if (modal) return modal;

              modal = document.createElement('div');
              modal.id = 'scm-pqr-transfer-modal';
              modal.className = 'scm-pqr-transfer-overlay';
              modal.setAttribute('aria-hidden', 'true');
              modal.innerHTML =
                '<div class="scm-pqr-transfer-backdrop" data-close-pqr-transfer="1"></div>' +
                '<div class="scm-pqr-transfer-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-pqr-transfer-title">' +
                '<button type="button" class="scm-pqr-transfer-close" data-close-pqr-transfer="1" aria-label="Cerrar">&times;</button>' +
                '<h4 id="scm-pqr-transfer-title" class="scm-pqr-transfer-title">Trasladar Solicitud</h4>' +
                '<div class="scm-pqr-transfer-body"></div>' +
                '</div>';
              pqrPanel.appendChild(modal);

              modal.addEventListener('click', function(ev) {
                var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-pqr-transfer="1"]') : null;
                if (!closeTarget) return;
                closeTransferModal();
              });
              return modal;
            }

            function closeTransferModal() {
              var modal = pqrPanel.querySelector('#scm-pqr-transfer-modal');
              if (!modal) return;
              if (window.jQuery && window.jQuery.fn) {
                window.jQuery(modal).find('select.scm-select').each(function() {
                  var $select = window.jQuery(this);
                  if ($select.data('select2')) {
                    $select.select2('destroy');
                  }
                });
              }
              modal.classList.remove('open');
              modal.setAttribute('aria-hidden', 'true');
              var body = modal.querySelector('.scm-pqr-transfer-body');
              if (body) body.innerHTML = '';
            }

            function openTransferModal(triggerBtn) {
              if (!triggerBtn) return;
              var row = triggerBtn.closest('tr[data-pqr-row]');
              if (!row) return;
              var sourceForm = row.querySelector('form.scm-public-pqr-form-inline, form.scm-public-pqr-public-form');
              if (!sourceForm) return;

              var modal = ensureTransferModal();
              var body = modal.querySelector('.scm-pqr-transfer-body');
              if (!body) return;
              body.innerHTML = '';

              var clonedForm = sourceForm.cloneNode(true);
              clonedForm.classList.remove('scm-public-pqr-form-inline');
              clonedForm.classList.add('scm-public-pqr-form-modal');
              clonedForm.style.display = 'block';
              body.appendChild(clonedForm);

              var logicalId = (triggerBtn.getAttribute('data-ticket-logical') || '').trim();
              var title = modal.querySelector('.scm-pqr-transfer-title');
              if (title) {
                title.textContent = logicalId ? ('Trasladar Solicitud #' + logicalId) : 'Trasladar Solicitud';
              }

              modal.classList.add('open');
              modal.setAttribute('aria-hidden', 'false');
              initEnhancedSelects(clonedForm);
            }

            document.addEventListener('keydown', function(ev) {
              if (ev.key === 'Escape') {
                closeTransferModal();
              }
            });

            setupSettingsObserver();

            pqrPanel.addEventListener('click', function(e) {
              var clearFilterBtn = e.target && e.target.closest ? e.target.closest('.scm-public-pqr-filter-actions a.btn') : null;
              if (clearFilterBtn) {
                var filterFormForClear = pqrPanel.querySelector('form.scm-public-pqr-filter-form');
                if (filterFormForClear) {
                  e.preventDefault();
                  filterFormForClear.querySelectorAll('select[name="public_pqr_estado"], select[name="public_pqr_empleado"]').forEach(function(selectEl) {
                    selectEl.value = '';
                    if (window.jQuery && window.jQuery.fn && window.jQuery(selectEl).data('select2')) {
                      window.jQuery(selectEl).val('').trigger('change.select2');
                    }
                  });
                  filterFormForClear.dispatchEvent(new Event('submit', {
                    bubbles: true,
                    cancelable: true
                  }));
                  return;
                }
              }
              var transferBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-pqr-transfer]') : null;
              if (!transferBtn) return;
              e.preventDefault();
              openTransferModal(transferBtn);
            });

            pqrPanel.addEventListener('submit', function(e) {
              var form = e.target;
              if (!form || !form.classList) return;
              var isFilterForm = form.classList.contains('scm-public-pqr-filter-form');
              var isAssignForm = form.classList.contains('scm-public-pqr-form');
              var isCorresponsableForm = form.classList.contains('scm-public-pqr-corresponsable-form');
              var isNotifForm = form.classList.contains('scm-notif-responsable-form');
              if (!isFilterForm && !isAssignForm && !isCorresponsableForm && !isNotifForm) return;
              e.preventDefault();

              if (isFilterForm) {
                var appFilter = document.getElementById('scm-app');
                var filterRuntimeRaw = appFilter ? (appFilter.getAttribute('data-scm-runtime') || '{}') : '{}';
                var filterRuntime = {};
                try {
                  filterRuntime = JSON.parse(filterRuntimeRaw);
                } catch (ex) {}
                var filterAjaxUrl = filterRuntime.ajaxUrl || '/api.php';
                var filterNonce = filterRuntime.nonce || '';
                var filterAction = (filterRuntime.actions && filterRuntime.actions.filtrar_pqr_publico) ? filterRuntime.actions.filtrar_pqr_publico : 'scm_filtrar_pqr_publico';
                var filterBtn = form.querySelector('button[type="submit"]');
                if (filterBtn) filterBtn.disabled = true;
                pqrPanel.classList.add('is-loading');

                var filterFd = new FormData(form);
                filterFd.append('action', filterAction);
                filterFd.append('nonce', filterNonce);

                fetch(filterAjaxUrl, {
                    method: 'POST',
                    body: filterFd,
                    credentials: 'same-origin'
                  })
                  .then(function(r) {
                    return r.json();
                  })
                  .then(function(json) {
                    if (!json || !json.success) {
                      throw new Error((json && json.data && json.data.message) || 'No se pudo filtrar Solicitudes Web.');
                    }
                    var data = json.data || {};
                    if (typeof data.tab_html === 'string') {
                      pqrPanel.innerHTML = data.tab_html;
                      initEnhancedSelects(pqrPanel);
                      setupSettingsObserver();
                    }
                  })
                  .catch(function(err) {
                    if (window.Swal && typeof window.Swal.fire === 'function') {
                      window.Swal.fire({
                        icon: 'error',
                        title: 'No se pudo filtrar',
                        text: err && err.message ? err.message : 'No se pudo filtrar Solicitudes Web.',
                        confirmButtonColor: '#1f4f99'
                      });
                    }
                  })
                  .finally(function() {
                    pqrPanel.classList.remove('is-loading');
                    if (filterBtn) filterBtn.disabled = false;
                  });
                return;
              }

              var msgClass = isAssignForm ? '.scm-public-pqr-msg' : (isCorresponsableForm ? '.scm-public-pqr-corresponsable-msg' : '.scm-notif-responsable-msg');
              var msg = form.querySelector(msgClass);
              var rowMsg = null;
              if (isAssignForm) {
                var rowForMsg = form.closest('tr[data-pqr-row]');
                if (!rowForMsg) {
                  var ticketInputForMsg = form.querySelector('input[name="ticket_pk"]');
                  if (ticketInputForMsg) {
                    rowForMsg = findPqrRowByTicketPk(ticketInputForMsg.value || '');
                  }
                }
                if (rowForMsg) {
                  rowMsg = rowForMsg.querySelector('.scm-public-pqr-row-msg');
                }
              }
              var btn = form.querySelector('button[type="submit"]');
              if (btn) btn.disabled = true;
              if (msg) msg.textContent = 'Guardando...';
              if (rowMsg) rowMsg.textContent = 'Guardando...';

              var app = document.getElementById('scm-app');
              var runtimeRaw = app ? (app.getAttribute('data-scm-runtime') || '{}') : '{}';
              var runtime = {};
              try {
                runtime = JSON.parse(runtimeRaw);
              } catch (ex) {}

              var ajaxUrl = runtime.ajaxUrl || '/api.php';
              var nonce = runtime.nonce || '';
              var action = isAssignForm ?
                ((runtime.actions && runtime.actions.asignar_pqr_publico) ? runtime.actions.asignar_pqr_publico : 'scm_asignar_pqr_publico') :
                (isCorresponsableForm ?
                  ((runtime.actions && runtime.actions.guardar_corresponsable_pqr_publico) ? runtime.actions.guardar_corresponsable_pqr_publico : 'scm_guardar_corresponsable_pqr_publico') :
                  ((runtime.actions && runtime.actions.notif_responsable_pqr) ? runtime.actions.notif_responsable_pqr : 'scm_guardar_notif_responsable_pqr')
                );
              var fd = new FormData(form);
              fd.append('action', action);
              fd.append('nonce', nonce);

              fetch(ajaxUrl, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error(
                      (json && json.data && json.data.message) ||
                      (
                        isAssignForm ?
                        'No se pudo actualizar el PQR.' :
                        (isCorresponsableForm ? 'No se pudo guardar el corresponsable.' : 'No se pudo guardar la notificacion.')
                      )
                    );
                  }
                  var data = json.data || {};
                  if (msg) msg.textContent = data.message || 'Actualizado';
                  if (rowMsg) rowMsg.textContent = data.message || 'Actualizado';

                  if (isAssignForm) {
                    var row = form.closest('tr[data-pqr-row]');
                    if (!row) {
                      var ticketInput = form.querySelector('input[name="ticket_pk"]');
                      if (ticketInput) {
                        row = findPqrRowByTicketPk(ticketInput.value || '');
                      }
                    }
                    if (row) {
                      var topic = row.querySelector('.scm-public-pqr-current-topic');
                      var dept = row.querySelector('.scm-public-pqr-current-department');
                      var emp = row.querySelector('.scm-public-pqr-current-employee');
                      var tipo = '';
                      if (typeof data.tipo_pqrs === 'string') tipo = data.tipo_pqrs;
                      else if (typeof data.tema_ayuda === 'string') tipo = data.tema_ayuda;
                      if (topic) topic.textContent = tipo || '-';
                      if (dept && typeof data.departamento === 'string') dept.textContent = data.departamento || '-';
                      if (emp) {
                        var empName = (data.empleado || '').trim();
                        var empId = (data.id_empleado || '').trim();
                        if (empName !== '') {
                          emp.textContent = empName;
                        } else if (empId !== '') {
                          emp.textContent = 'ID ' + empId;
                        }
                      }
                    }

                    if (form.classList.contains('scm-public-pqr-form-modal')) {
                      setTimeout(function() {
                        closeTransferModal();
                      }, 220);
                    }
                  }
                })
                .catch(function(err) {
                  var fallbackError = isAssignForm ? 'Error actualizando PQR.' : (isCorresponsableForm ? 'Error guardando corresponsable.' : 'Error guardando notificacion.');
                  if (msg) msg.textContent = err && err.message ? err.message : fallbackError;
                  if (rowMsg) rowMsg.textContent = err && err.message ? err.message : fallbackError;
                })
                .finally(function() {
                  if (btn) btn.disabled = false;
                });
            });
          }
        })();
      </script>

      <div class="scm-tab-panel" id="scm-panel-metricas" data-scm-metrics="<?php echo self::h((string)$metricsJson); ?>">
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
        </div>

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

      <?php echo \SCM\Views\GuideModalView::render(); ?>

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
    <script src="<?php echo $damageJsUrl; ?>" defer></script>
<?php
    return (string)ob_get_clean();
  }
}
