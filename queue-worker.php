<?php

/**
 * Worker de compatibilidad para control-servicios-inmobiliarios.
 *
 * Sigue siendo util si ya tienes el cron apuntando aqui, pero ahora procesa
 * la cola unificada de shared-notifications filtrada por este proyecto.
 */

define('SCM_ROOT', __DIR__);
require_once __DIR__ . '/bootstrap.php';

$isHttp = (PHP_SAPI !== 'cli');
if ($isHttp) {
  $expectedToken = defined('SCM_WORKER_TOKEN') ? SCM_WORKER_TOKEN : '';
  $sentToken = trim((string) ($_POST['worker_token'] ?? $_GET['worker_token'] ?? ''));
  if ($expectedToken === '' || !hash_equals($expectedToken, $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
  }
  header('Content-Type: application/json; charset=UTF-8');
}

$lockFile = sys_get_temp_dir() . '/scm-email-worker.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  $msg = '[' . date('Y-m-d H:i:s') . '] Worker ya en ejecucion, saltando.';
  if ($isHttp) {
    echo json_encode(['ok' => false, 'message' => $msg]);
  } else {
    echo $msg . PHP_EOL;
  }
  exit;
}

try {
  $bridge = new \SCM\Support\SharedNotificationsBridge($scmDb);
  if (!$bridge->isAvailable()) {
    throw new \RuntimeException($bridge->lastError() !== '' ? $bridge->lastError() : 'No se pudo iniciar shared-notifications.');
  }

  $requestedLimit = (int) ($_GET['limit'] ?? $_POST['limit'] ?? 0);
  $legacyEmailLimit = (int) ($_GET['email_limit'] ?? $_POST['email_limit'] ?? 20);
  $legacyWhatsappLimit = (int) ($_GET['sms_limit'] ?? $_POST['sms_limit'] ?? 20);
  $limit = $requestedLimit > 0 ? $requestedLimit : ($legacyEmailLimit + $legacyWhatsappLimit);
  $limit = max(1, min(200, $limit));

  $worker = $bridge->worker();
  if (!$worker instanceof \SharedNotifications\NotificationWorker) {
    throw new \RuntimeException('No se pudo crear el worker de shared-notifications.');
  }

  $stats = $worker->run($limit, $bridge->projectCode());
  if (!$isHttp && (int) ($stats['retried'] ?? 0) > 0) {
    sleep(10);
    $followUp = $worker->run($limit, $bridge->projectCode());
    foreach (['picked', 'processed', 'sent', 'failed', 'retried'] as $key) {
      $stats[$key] = (int) ($stats[$key] ?? 0) + (int) ($followUp[$key] ?? 0);
    }
  }
  $emailStats = $bridge->statsByChannel('email');
  $whatsAppStats = $bridge->statsByChannel('whatsapp');

  $msg = '[' . date('Y-m-d H:i:s') . '] Cola unificada procesada: ' . $stats['processed']
    . ' | Email pendientes: ' . $emailStats['pending']
    . ' | Email enviados: ' . $emailStats['sent']
    . ' | Email fallidos: ' . $emailStats['failed']
    . ' || WhatsApp pendientes: ' . $whatsAppStats['pending']
    . ' | WhatsApp enviados: ' . $whatsAppStats['sent']
    . ' | WhatsApp fallidos: ' . $whatsAppStats['failed'];

  if ($isHttp) {
    echo json_encode([
      'ok' => true,
      'project' => $bridge->projectCode(),
      'worker' => $stats,
      'stats' => [
        'email' => $emailStats,
        'whatsapp' => $whatsAppStats,
        'sms' => $whatsAppStats,
      ],
    ]);
  } else {
    echo $msg . PHP_EOL;
  }
} catch (\Throwable $e) {
  $errMsg = '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage();
  if ($isHttp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  } else {
    echo $errMsg . PHP_EOL;
  }
} finally {
  if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
  }
}
