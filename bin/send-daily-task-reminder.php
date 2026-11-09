<?php
declare(strict_types=1);
// Recordatorio diario por cargo: solo encola; lo entrega shared-notifications. Se puede ejecutar cada 15 minutos: decide
// solo si ya es hora (días hábiles desde las 9:00 a. m. de Colombia) y sale una vez al día.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$lock = fopen(sys_get_temp_dir() . '/scm-daily-task-reminder.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Recordatorio ya en ejecución.\n"; exit; }
try {
  $app = new \SCM\App\SuCasaControlServiciosInmobiliarios($scmDb);
  echo json_encode($app->processDailyTaskReminder(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
  echo json_encode($app->processLegalCaseReminders(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . PHP_EOL);
  exit(1);
} finally {
  flock($lock, LOCK_UN);
  fclose($lock);
}
