<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Trae al archivador de Herramientas › Generar PSE los .txt del cargarpse anterior.
// --from: carpeta local (p. ej. /home/.../cargarpse/descargas) o la URL del listado (https://sucasainmobiliaria.com.co/cargarpse/descargas/).
// Por defecto solo muestra lo que importaría; --apply copia. Los que ya existan en el archivador no se tocan salvo --overwrite.
$options = getopt('', ['from:', 'apply', 'overwrite']);
$from = trim((string) ($options['from'] ?? ''));
if ($from === '') {
  fwrite(STDERR, "Uso: php bin/import-pse-archive.php --from=<carpeta o URL de cargarpse/descargas> [--apply] [--overwrite]\n");
  exit(1);
}

$isUrl = preg_match('#^https?://#i', $from) === 1;
$fetch = static function (string $url): string {
  $curl = curl_init($url);
  curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
  $body = curl_exec($curl);
  $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
  curl_close($curl);
  if (!is_string($body) || $status !== 200) throw new RuntimeException('HTTP ' . $status . ' en ' . $url);
  return $body;
};

/** @var list<array{name:string,source:string,modified:int}> $sources */
$sources = [];
if ($isUrl) {
  $base = rtrim($from, '/') . '/';
  $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
  // El autoindex enlaza con rutas absolutas ("/cargarpse/descargas/x.txt"); también se aceptan relativas.
  preg_match_all('#href="([^"?]*?([^"?/]+\.txt))"#i', $fetch($base), $matches, PREG_SET_ORDER);
  $seen = [];
  foreach ($matches as $match) {
    $href = $match[1];
    $url = preg_match('#^https?://#i', $href) === 1 ? $href : (str_starts_with($href, '/') ? $origin . $href : $base . $href);
    $name = rawurldecode($match[2]);
    if (isset($seen[$name])) continue;
    $seen[$name] = true;
    $sources[] = ['name' => $name, 'source' => $url, 'modified' => 0];
  }
} else {
  if (!is_dir($from)) { fwrite(STDERR, "No existe la carpeta {$from}\n"); exit(1); }
  foreach (glob(rtrim($from, '/\\') . '/*.txt') ?: [] as $path) {
    $sources[] = ['name' => basename($path), 'source' => $path, 'modified' => (int) filemtime($path)];
  }
}

$archive = SCM\Modules\Tools\PseArchive::default();
$report = ['mode' => isset($options['apply']) ? 'apply' : 'dry-run', 'found' => count($sources), 'imported' => 0, 'skipped_existing' => 0, 'invalid' => [], 'files' => []];
foreach ($sources as $file) {
  if (preg_match('/^[0-9A-Za-z_-]{1,80}\.txt$/', $file['name']) !== 1) { $report['invalid'][] = $file['name']; continue; }
  if ($archive->has($file['name']) && !isset($options['overwrite'])) { $report['skipped_existing']++; continue; }
  $report['files'][] = $file['name'];
  if (!isset($options['apply'])) continue;
  $content = $isUrl ? $fetch($file['source']) : (string) file_get_contents($file['source']);
  if (!str_starts_with($content, SCM\Modules\Tools\PsePaymentFile::HEADER_PREFIX)) { $report['invalid'][] = $file['name'] . ' (no es un archivo PSE)'; continue; }
  // Fecha de publicación del nombre (AAAAMMDD_…): las copias y descargas pierden la fecha original del archivo.
  $published = DateTimeImmutable::createFromFormat('!Ymd', substr($file['name'], 0, 8));
  $modified = $published instanceof DateTimeImmutable ? $published->getTimestamp() : ($file['modified'] ?: time());
  $archive->save($file['name'], $content, ['origin' => 'historico', 'at' => $modified]);
  $report['imported']++;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
