<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$source = isset($argv[1]) && trim((string) $argv[1]) !== ''
  ? (string) $argv[1]
  : SCM_STORAGE_PATH . '/data/settings.json';
if (!is_readable($source)) {
  fwrite(STDERR, "No se puede leer el JSON de origen: {$source}\n");
  exit(1);
}

$decoded = json_decode((string) file_get_contents($source), true);
if (!is_array($decoded)) {
  fwrite(STDERR, "El JSON de configuración no es válido.\n");
  exit(1);
}

$database = \SCM\Core\App::db();
$table = $database->table('jet_cct_confi_sistema');
$settings = \SCM\Core\App::settings();
try {
  $database->pdo()->beginTransaction();
  foreach ($decoded as $key => $value) {
    $settings->set((string) $key, $value, \SCM\Core\Auth::userId());
  }
  $database->pdo()->commit();
} catch (\Throwable $exception) {
  if ($database->pdo()->inTransaction()) {
    $database->pdo()->rollBack();
  }
  throw $exception;
}
$settings->refresh();

foreach ($decoded as $key => $value) {
  if ($settings->get((string) $key, new stdClass()) !== $value) {
    fwrite(STDERR, "Falló la verificación de la clave {$key}.\n");
    exit(1);
  }
}

echo 'Configuraciones migradas y verificadas: ' . count($decoded) . PHP_EOL;
echo 'Tabla destino: ' . $table . PHP_EOL;
echo 'Funcion destino: ' . \SCM\Core\Settings::FUNCTION_KEY . PHP_EOL;
