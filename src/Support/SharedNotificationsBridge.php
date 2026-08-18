<?php

namespace SCM\Support;

use SCM\Core\Database;

final class SharedNotificationsBridge
{
  private const PROJECT_CODE = 'control-servicios-inmobiliarios';

  private Database $db;
  private string $packageRoot;

  /** @var array<string,mixed>|null */
  private ?array $config = null;

  private ?\SharedNotifications\Config\QueueConfig $queueConfig = null;
  private ?\SharedNotifications\Storage\PdoStorageAdapter $storage = null;
  private ?\SharedNotifications\NotificationQueue $queue = null;
  private ?\SharedNotifications\NotificationWorker $worker = null;
  private ?string $lastError = null;
  private bool $queueBooted = false;
  private bool $workerBooted = false;

  public function __construct(Database $db, string $packageRoot = '')
  {
    $this->db = $db;
    $configuredRoot = $packageRoot !== '' ? $packageRoot : (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: '');
    $this->packageRoot = $configuredRoot !== ''
      ? rtrim($configuredRoot, '/\\')
      : dirname(__DIR__, 3) . '/shared-notifications';
  }

  public function isAvailable(): bool
  {
    return $this->bootQueue();
  }

  public function lastError(): string
  {
    return $this->lastError ?? '';
  }

  public function projectCode(): string
  {
    return self::PROJECT_CODE;
  }

  public function queue(): ?\SharedNotifications\NotificationQueue
  {
    return $this->bootQueue() ? $this->queue : null;
  }

  public function worker(): ?\SharedNotifications\NotificationWorker
  {
    return $this->bootWorker() ? $this->worker : null;
  }

  public function queueTable(): string
  {
    return $this->queueConfig instanceof \SharedNotifications\Config\QueueConfig
      ? $this->queueConfig->queueTable()
      : 'skc_notification_queue';
  }

  /**
   * @return array{pending:int,processing:int,sent:int,failed:int,cancelled:int}
   */
  public function statsByChannel(string $channel): array
  {
    $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
    if (!$this->bootQueue()) {
      return $stats;
    }

    try {
      $rows = $this->db->getResults(
        "SELECT `status`, COUNT(*) AS cnt
           FROM `{$this->queueTable()}`
          WHERE `project_code` = ?
            AND `channel` = ?
          GROUP BY `status`",
        [self::PROJECT_CODE, trim($channel)]
      );
    } catch (\Throwable $exception) {
      $this->lastError = $exception->getMessage();
      return $stats;
    }

    foreach ($rows as $row) {
      $status = trim((string) ($row['status'] ?? ''));
      if (array_key_exists($status, $stats)) {
        $stats[$status] = (int) ($row['cnt'] ?? 0);
      }
    }

    return $stats;
  }

  private function bootQueue(): bool
  {
    if ($this->queueBooted) {
      return $this->queue instanceof \SharedNotifications\NotificationQueue;
    }

    $this->queueBooted = true;

    $autoloadFile = $this->packageRoot . '/autoload.php';
    $configFile = $this->packageRoot . '/config.php';
    if (!is_readable($autoloadFile)) {
      $this->lastError = 'No se encontro shared-notifications/autoload.php.';
      return false;
    }
    if (!is_readable($configFile)) {
      $this->lastError = 'No se encontro shared-notifications/config.php.';
      return false;
    }

    require_once $autoloadFile;

    $config = require $configFile;
    if (!is_array($config)) {
      $this->lastError = 'shared-notifications/config.php no devolvio un array valido.';
      return false;
    }

    $this->config = $config;

    $queueCfg = is_array($config['queue'] ?? null) ? $config['queue'] : [];
    $providersCfg = is_array($config['providers'] ?? null) ? $config['providers'] : [];

    $this->queueConfig = new \SharedNotifications\Config\QueueConfig(
      (string) ($queueCfg['queue_table'] ?? 'skc_notification_queue'),
      (string) ($queueCfg['attempts_table'] ?? 'skc_notification_attempts'),
      self::PROJECT_CODE,
      (int) ($queueCfg['default_max_attempts'] ?? 3)
    );

    $this->storage = new \SharedNotifications\Storage\PdoStorageAdapter($this->db->pdo());
    $this->queue = new \SharedNotifications\NotificationQueue($this->storage, $this->queueConfig);

    return true;
  }

  private function bootWorker(): bool
  {
    if ($this->workerBooted) {
      return $this->worker instanceof \SharedNotifications\NotificationWorker;
    }

    $this->workerBooted = true;

    if (!$this->bootQueue()) {
      return false;
    }

    $providersCfg = is_array($this->config['providers'] ?? null) ? $this->config['providers'] : [];

    $registry = new \SharedNotifications\Providers\ProviderRegistry();

    $smtpCfg = is_array($providersCfg['email_smtp'] ?? null) ? $providersCfg['email_smtp'] : [];
    if (!empty($smtpCfg['enabled'])) {
      $registry->add(new \SharedNotifications\Providers\EmailSmtpProvider($smtpCfg));
    }

    $smsCfg = is_array($providersCfg['sms_onurix'] ?? null) ? $providersCfg['sms_onurix'] : [];
    if (!empty($smsCfg['enabled'])) {
      $registry->add(new \SharedNotifications\Providers\SmsOnurixProvider($smsCfg));
    }

    $officialCfg = is_array($providersCfg['whatsapp_official'] ?? null) ? $providersCfg['whatsapp_official'] : [];
    if (!empty($officialCfg['enabled'])) {
      $registry->add(new \SharedNotifications\Providers\WhatsAppOfficialProvider($officialCfg));
    }

    $providerDelays = [];
    foreach ($providersCfg as $providerCode => $providerCfg) {
      if (!is_array($providerCfg)) {
        continue;
      }

      $minDelaySeconds = (int) ($providerCfg['min_delay_seconds'] ?? 0);
      if ($minDelaySeconds > 0) {
        $providerDelays[(string) $providerCode] = $minDelaySeconds;
      }
    }

    $this->worker = new \SharedNotifications\NotificationWorker(
      $this->storage,
      $registry,
      $this->queueConfig,
      'scm-control-servicios:' . getmypid(),
      [],
      $providerDelays
    );

    return true;
  }
}
