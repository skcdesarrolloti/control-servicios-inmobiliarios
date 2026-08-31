<?php

declare(strict_types=1);

// Production preflight. SELECT/DESCRIBE only: no migrations, reviews, PDFs or notifications.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(1); });
$db = \SCM\Core\App::db();
$schema = new \SCM\Support\SchemaInspector($db);
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
  echo ($ok ? 'OK: ' : 'ERROR: ') . $message . PHP_EOL;
  if (!$ok) { $failures++; }
};
foreach (['pdo_mysql', 'mbstring', 'iconv', 'fileinfo'] as $extension) {
  $check(extension_loaded($extension), 'Extensión ' . $extension);
}
$check(is_readable(SCM_ROOT . '/resources/assets/membrete-sucasa.jpg'), 'Membrete institucional legible');
$check(is_dir(SCM_UPLOAD_PATH) && is_writable(SCM_UPLOAD_PATH), 'storage/uploads existe y permite escritura al usuario CLI (verificar también usuario PHP web)');
$check(str_starts_with(SCM_BASE_URL, 'https://'), 'BASE_URL usa HTTPS para los enlaces firmados');
$required = [
  'jet_cct_funcionarios' => ['_ID', 'id_empleado', 'nombre', 'correo', 'activo'],
  'jet_cct_sucursales' => ['_ID', 'representante_legal', 'celular_legal'],
  'jet_cct_contratos_arrendamiento' => ['_ID', 'contrato', 'id_inmueble', 'servicios_publicos', 'luz', 'agua', 'gas', 'id_empleado', 'realizado_por', 'ultima_revision_servicios', 'mes_revision_servicios', 'revisiones_servicios', 'id_revision_servicios_publicos', 'tuvo_revision', 'cct_modified'],
  'jet_cct_revisiones_servicios' => ['_ID', 'id_contrato', 'id_inmueble', 'fecha', 'id_empleado', 'realizado_por', 'cct_author_id', 'cct_created', 'cct_modified', 'nic', 'poliza', 'numero_contrato'],
  'jet_cct_historial_del_inmueble' => ['_ID', 'id_inmueble', 'id_empleado', 'funcionario', 'observacion', 'fecha', 'tipo_reporte', 'cct_author_id', 'cct_created', 'cct_modified'],
  'posts' => ['ID', 'post_type'],
  'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
];
foreach (['luz', 'agua', 'gas'] as $service) {
  foreach (['jet_cct_contratos_arrendamiento', 'jet_cct_revisiones_servicios'] as $table) {
    foreach (['medidor_', 'fecha_revision_', 'resultado_tiempo_', 'resultado_valores_', 'acta_felicitaciones_', 'acta_mora_'] as $prefix) {
      $required[$table][] = $prefix . $service;
    }
  }
}
foreach ($required as $suffix => $columns) {
  $table = $db->table($suffix);
  $missing = array_diff($columns, $schema->getTableColumns($table));
  $check(!$missing, $suffix . ($missing ? ': faltan ' . implode(', ', $missing) : ': columnas verificadas'));
  if (in_array($suffix, ['jet_cct_contratos_arrendamiento', 'jet_cct_revisiones_servicios', 'jet_cct_historial_del_inmueble', 'postmeta'], true)) {
    $engine = (string) $db->getVar('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    $check(strtolower($engine) === 'innodb', $suffix . ': motor transaccional InnoDB');
  }
}
$package = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
$check(is_readable($package . '/autoload.php') && is_readable($package . '/config.php'), 'Paquete shared-notifications y configuración disponibles');
if (is_readable($package . '/config.php')) {
  $config = require $package . '/config.php';
  foreach (['queue_table' => 'skc_notification_queue', 'attempts_table' => 'skc_notification_attempts'] as $key => $fallback) {
    $table = (string) ($config['queue'][$key] ?? $fallback);
    $check($schema->tableExists($table), 'Tabla de notificaciones ' . $table);
  }
  $bridge = new \SCM\Support\SharedNotificationsBridge($db);
  $check($bridge->isAvailable(), 'Inicialización de la cola compartida, sin encolar ni procesar trabajos');
}
echo "No se modificaron datos ni se enviaron mensajes. El cron y la entrega SMTP deben comprobarse en el servidor.\n";
exit($failures > 0 ? 1 : 0);
