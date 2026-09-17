<?php

declare(strict_types=1);

use SCM\Core\App;
use SCM\Core\Database;
use SCM\Support\StoredFileService;

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';

$options = getopt('', ['apply', 'limit::', 'table::', 'column::', 'no-download', 'help']);
if (isset($options['help'])) {
  echo <<<TXT
Uso:
  php bin/migrate-media-to-local-storage.php [--apply] [--limit=100] [--table=wp_jet_cct_tickets] [--column=imagenes] [--no-download]

Por defecto solo hace dry-run. Con --apply actualiza las columnas detectadas.
Sin --no-download, los adjuntos de WordPress se descargan a storage/uploads y se guardan como URL firmada de file.php.
Con --no-download, los IDs se reemplazan por guid de wp_posts, pero siguen dependiendo de WordPress.

TXT;
  exit(0);
}

$db = App::db();
$files = StoredFileService::fromRuntime();
$apply = array_key_exists('apply', $options);
$downloadRemote = !array_key_exists('no-download', $options);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;
$tableFilter = trim((string) ($options['table'] ?? ''));
$columnFilter = trim((string) ($options['column'] ?? ''));

$targets = [
  ['table' => 'jet_cct_tickets', 'pk' => '_ID', 'column' => 'imagenes', 'kind' => 'media_list'],
  ['table' => 'jet_cct_tickets', 'pk' => '_ID', 'column' => 'registro_fotografico', 'kind' => 'media_list'],
  ['table' => 'jet_cct_tickets', 'pk' => '_ID', 'column' => 'archivos', 'kind' => 'documents'],
  ['table' => 'jet_cct_historial_del_ticket', 'pk' => '_ID', 'column' => 'imagen', 'kind' => 'media_list'],
  ['table' => 'jet_cct_historial_del_ticket', 'pk' => '_ID', 'column' => 'archivos', 'kind' => 'documents'],
  ['table' => 'jet_cct_seguimiento_ticket', 'pk' => '_ID', 'column' => 'evidencia', 'kind' => 'media_list'],
  ['table' => 'jet_cct_cotizacion_mantenimiento', 'pk' => '_ID', 'column' => 'mejor_oferta', 'kind' => 'media_list'],
  ['table' => 'jet_cct_cotizacion_mantenimiento', 'pk' => '_ID', 'column' => 'otras_oferta', 'kind' => 'media_list'],
  ['table' => 'jet_cct_actas_de_satisfaccion', 'pk' => '_ID', 'column' => 'registro_fotografico', 'kind' => 'media_list'],
  ['table' => 'jet_cct_revision_correctiva', 'pk' => '_ID', 'column' => 'evaluacion_de_danos', 'kind' => 'damage_items'],
];

$migrator = new class($db, $files, $apply, $downloadRemote) {
  private Database $db;
  private StoredFileService $files;
  private bool $apply;
  private bool $downloadRemote;
  /** @var array<string,string> */
  private array $refCache = [];
  /** @var array<string,int> */
  public array $stats = [
    'scanned' => 0,
    'changed' => 0,
    'updated' => 0,
    'resolved_ids' => 0,
    'downloaded' => 0,
    'unresolved' => 0,
    'failed_downloads' => 0,
  ];

  public function __construct(Database $db, StoredFileService $files, bool $apply, bool $downloadRemote)
  {
    $this->db = $db;
    $this->files = $files;
    $this->apply = $apply;
    $this->downloadRemote = $downloadRemote;
  }

  /** @return array{new:string,changed:bool} */
  public function transform(string $raw, string $kind): array
  {
    return match ($kind) {
      'documents' => $this->transformDocuments($raw),
      'damage_items' => $this->transformDamageItems($raw),
      default => $this->transformMediaList($raw),
    };
  }

  /** @return array{new:string,changed:bool} */
  private function transformMediaList(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') {
      return ['new' => $raw, 'changed' => false];
    }

    $decoded = $this->decodeStructured($raw);
    if ($decoded['format'] !== 'plain') {
      $changed = false;
      $value = $this->walkRefs($decoded['value'], $changed);
      return ['new' => $this->encodeStructured($value, $decoded['format']), 'changed' => $changed];
    }

    $parts = preg_split('/[\s,]+/', $raw) ?: [];
    $out = [];
    $changed = false;
    foreach ($parts as $part) {
      $part = trim((string) $part);
      if ($part === '') {
        continue;
      }
      $new = $this->convertRef($part);
      if ($new !== $part) {
        $changed = true;
      }
      $out[] = $new;
    }

    return ['new' => implode(',', array_values(array_unique($out))), 'changed' => $changed];
  }

  /** @return array{new:string,changed:bool} */
  private function transformDocuments(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') {
      return ['new' => $raw, 'changed' => false];
    }

    $decoded = $this->decodeStructured($raw);
    if ($decoded['format'] === 'plain') {
      $new = $this->convertRef($raw);
      return ['new' => $new, 'changed' => $new !== $raw];
    }

    $changed = false;
    $value = $decoded['value'];
    if (is_array($value)) {
      $value = $this->walkDocumentRefs($value, $changed);
    }

    return ['new' => $this->encodeStructured($value, $decoded['format']), 'changed' => $changed];
  }

  /** @return array{new:string,changed:bool} */
  private function transformDamageItems(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') {
      return ['new' => $raw, 'changed' => false];
    }

    $decoded = $this->decodeStructured($raw);
    if ($decoded['format'] === 'plain' || !is_array($decoded['value'])) {
      return ['new' => $raw, 'changed' => false];
    }

    $changed = false;
    $items = $decoded['value'];
    foreach ($items as $index => $item) {
      if (!is_array($item) || !array_key_exists('registro_foto_dano', $item)) {
        continue;
      }
      $result = $this->transformMediaList((string) $item['registro_foto_dano']);
      if ($result['changed']) {
        $items[$index]['registro_foto_dano'] = $result['new'];
        $changed = true;
      }
    }

    return ['new' => $this->encodeStructured($items, $decoded['format']), 'changed' => $changed];
  }

  /** @return array{format:string,value:mixed} */
  private function decodeStructured(string $raw): array
  {
    if (preg_match('/^[aOsibdN]:/', $raw) === 1) {
      $value = @unserialize($raw, ['allowed_classes' => false]);
      if ($value !== false || $raw === 'b:0;') {
        return ['format' => 'serialize', 'value' => $value];
      }
    }

    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
      return ['format' => 'json', 'value' => $json];
    }

    return ['format' => 'plain', 'value' => $raw];
  }

  private function encodeStructured($value, string $format): string
  {
    if ($format === 'json') {
      return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if ($format === 'serialize') {
      return serialize($value);
    }
    return (string) $value;
  }

  private function walkRefs($value, bool &$changed)
  {
    if (is_string($value) || is_numeric($value)) {
      $old = trim((string) $value);
      $new = $this->convertRef($old);
      if ($new !== $old) {
        $changed = true;
      }
      return $new;
    }
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $key => $item) {
      $value[$key] = $this->walkRefs($item, $changed);
    }
    return $value;
  }

  private function walkDocumentRefs(array $value, bool &$changed): array
  {
    foreach ($value as $key => $item) {
      if (!is_array($item)) {
        $old = trim((string) $item);
        $new = $this->convertRef($old);
        if ($new !== $old) {
          $changed = true;
        }
        $value[$key] = $new;
        continue;
      }

      foreach (['archivo', 'media_archivo', 'url'] as $urlKey) {
        if (!array_key_exists($urlKey, $item)) {
          continue;
        }
        $old = trim((string) $item[$urlKey]);
        $new = $this->convertRef($old);
        if ($new !== $old) {
          $changed = true;
        }
        $item[$urlKey] = $new;
      }
      if (isset($item['archivo']) || isset($item['media_archivo'])) {
        $canonical = trim((string) ($item['archivo'] ?? $item['media_archivo'] ?? ''));
        if ($canonical !== '') {
          $item['archivo'] = $canonical;
          $item['media_archivo'] = $canonical;
        }
      }
      $value[$key] = $item;
    }
    return $value;
  }

  private function convertRef(string $ref): string
  {
    $ref = trim($ref);
    if ($ref === '') {
      return $ref;
    }

    $cacheKey = $this->downloadRemote ? 'download|' . $ref : 'resolve|' . $ref;
    if (isset($this->refCache[$cacheKey])) {
      return $this->refCache[$cacheKey];
    }

    if (preg_match('/^\d+$/', $ref) === 1) {
      $url = $this->resolveAttachmentUrl((int) $ref);
      if ($url === '') {
        $this->stats['unresolved']++;
        return $this->refCache[$cacheKey] = $ref;
      }
      $this->stats['resolved_ids']++;
      if ($this->downloadRemote) {
        $local = $this->downloadToLocal($url);
        return $this->refCache[$cacheKey] = ($local !== '' ? $local : $ref);
      }
      return $this->refCache[$cacheKey] = $url;
    }

    if ($this->isLocalSignedUrl($ref)) {
      return $this->refCache[$cacheKey] = $ref;
    }

    if (preg_match('/^[a-f0-9]{24}_[0-9]+\.[a-z0-9]{1,8}$/D', basename((string) parse_url($ref, PHP_URL_PATH))) === 1) {
      $name = basename((string) parse_url($ref, PHP_URL_PATH));
      return $this->refCache[$cacheKey] = $this->files->urlFor($name);
    }

    if ($this->downloadRemote && $this->isLegacyWpMediaUrl($ref)) {
      $local = $this->downloadToLocal($ref);
      if ($local !== '') {
        return $this->refCache[$cacheKey] = $local;
      }
    }

    return $this->refCache[$cacheKey] = $ref;
  }

  private function resolveAttachmentUrl(int $attachmentId): string
  {
    $postsTable = $this->db->table('posts');
    $row = $this->db->getRow(
      "SELECT `guid` FROM `{$postsTable}` WHERE `ID` = ? AND TRIM(COALESCE(`guid`, '')) <> '' LIMIT 1",
      [$attachmentId]
    );
    $url = trim((string) ($row['guid'] ?? ''));
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
  }

  private function isLocalSignedUrl(string $url): bool
  {
    $path = (string) parse_url($url, PHP_URL_PATH);
    return str_ends_with($path, '/file.php') || basename($path) === 'file.php';
  }

  private function isLegacyWpMediaUrl(string $url): bool
  {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return false;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    return str_contains($host, 'sucasainmobiliaria.com.co') && str_contains($path, '/wp-content/uploads/');
  }

  private function downloadToLocal(string $url): string
  {
    if (!$this->apply) {
      return $this->dryRunLocalUrl($url);
    }

    $maxBytes = max(1024, (int) SCM_UPLOAD_MAX_BYTES);
    $context = stream_context_create([
      'http' => [
        'timeout' => 25,
        'follow_location' => 1,
        'user_agent' => 'SKC media migration',
      ],
      'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
      ],
    ]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!is_resource($handle)) {
      $this->stats['failed_downloads']++;
      return '';
    }
    $binary = stream_get_contents($handle, $maxBytes + 1);
    fclose($handle);
    if (!is_string($binary) || $binary === '' || strlen($binary) > $maxBytes) {
      $this->stats['failed_downloads']++;
      return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'scm_media_');
    if (!is_string($tmp) || file_put_contents($tmp, $binary) === false) {
      $this->stats['failed_downloads']++;
      return '';
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $ext = $this->extensionForMime((string) $mime, $url);
    if ($ext === '') {
      @unlink($tmp);
      $this->stats['failed_downloads']++;
      return '';
    }

    if (!is_dir(SCM_UPLOAD_PATH) && !mkdir(SCM_UPLOAD_PATH, 0750, true) && !is_dir(SCM_UPLOAD_PATH)) {
      @unlink($tmp);
      $this->stats['failed_downloads']++;
      return '';
    }

    $name = bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;
    $target = rtrim((string) SCM_UPLOAD_PATH, '/\\') . '/' . $name;
    if (!@rename($tmp, $target)) {
      @unlink($tmp);
      $this->stats['failed_downloads']++;
      return '';
    }

    $this->stats['downloaded']++;
    return $this->files->urlFor($name);
  }

  private function dryRunLocalUrl(string $url): string
  {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $name = basename($path) ?: 'archivo';
    return rtrim((string) SCM_BASE_URL, '/') . '/file.php?n=' . rawurlencode('dry-run-' . $name) . '&s=dry-run';
  }

  private function extensionForMime(string $mime, string $url): string
  {
    $map = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'application/pdf' => 'pdf',
      'text/plain' => 'txt',
      'text/csv' => 'csv',
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    if (isset($map[$mime])) {
      return $map[$mime];
    }
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return preg_match('/^(jpe?g|png|gif|webp|pdf|docx?|xlsx?|csv|txt)$/', $ext) === 1
      ? ($ext === 'jpeg' ? 'jpg' : $ext)
      : '';
  }
};

foreach ($targets as $target) {
  $table = $db->table($target['table']);
  $column = (string) $target['column'];
  $pk = (string) $target['pk'];
  $kind = (string) $target['kind'];

  if ($tableFilter !== '' && !in_array($tableFilter, [$table, $target['table']], true)) {
    continue;
  }
  if ($columnFilter !== '' && $columnFilter !== $column) {
    continue;
  }

  $exists = (int) $db->getVar(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$table, $column]
  );
  if ($exists === 0) {
    echo "Saltando {$table}.{$column}: columna no existe.\n";
    continue;
  }

  $sql = "SELECT `{$pk}` AS pk_value, `{$column}` AS raw_value FROM `{$table}` WHERE TRIM(COALESCE(`{$column}`, '')) <> '' ORDER BY `{$pk}`";
  if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
  }
  $rows = $db->getResults($sql);
  $targetChanged = 0;
  $targetUpdated = 0;

  foreach ($rows as $row) {
    $migrator->stats['scanned']++;
    $raw = (string) ($row['raw_value'] ?? '');
    $result = $migrator->transform($raw, $kind);
    if (!$result['changed']) {
      continue;
    }
    $targetChanged++;
    $migrator->stats['changed']++;
    if ($apply) {
      $targetUpdated += $db->update($table, [$column => $result['new']], [$pk => $row['pk_value']]);
    }
  }

  $migrator->stats['updated'] += $targetUpdated;
  echo sprintf(
    "%s.%s: revisados=%d, cambiarian=%d, actualizados=%d\n",
    $table,
    $column,
    count($rows),
    $targetChanged,
    $targetUpdated
  );
}

echo "\nModo: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo 'Descarga local: ' . ($downloadRemote ? 'Si' : 'No') . "\n";
foreach ($migrator->stats as $key => $value) {
  echo "{$key}: {$value}\n";
}
