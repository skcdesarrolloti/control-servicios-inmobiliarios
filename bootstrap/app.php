<?php

declare(strict_types=1);

define('SCM_ROOT', dirname(__DIR__));
define('SCM_PUBLIC_PATH', SCM_ROOT . '/public');
define('SCM_STORAGE_PATH', SCM_ROOT . '/storage');
define('SCM_RESOURCES_PATH', SCM_ROOT . '/resources');
define('SCM_BASE_PATH', SCM_PUBLIC_PATH);
define('SCM_VERSION', '3.1.81');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logsDir = SCM_STORAGE_PATH . '/logs';
if ((is_dir($logsDir) || @mkdir($logsDir, 0750, true)) && is_writable($logsDir)) {
  ini_set('error_log', $logsDir . '/php-error.log');
}

$runtimeDataDir = SCM_STORAGE_PATH . '/data';
if (PHP_SAPI !== 'cli' && function_exists('opcache_reset') && (is_dir($runtimeDataDir) || @mkdir($runtimeDataDir, 0750, true))) {
  $opcacheVersionFile = $runtimeDataDir . '/opcache-version.txt';
  $lastOpcacheVersion = is_readable($opcacheVersionFile) ? trim((string) @file_get_contents($opcacheVersionFile)) : '';
  if ($lastOpcacheVersion !== (string) SCM_VERSION) {
    @opcache_reset();
    if (is_writable($runtimeDataDir)) {
      @file_put_contents($opcacheVersionFile, (string) SCM_VERSION, LOCK_EX);
    }
  }
}

set_exception_handler(static function (\Throwable $exception): never {
  error_log(sprintf(
    '[uncaught] %s in %s:%d',
    $exception->getMessage(),
    $exception->getFile(),
    $exception->getLine()
  ));

  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
  }

  echo 'No fue posible iniciar la aplicación.';
  exit(1);
});

$composerAutoload = SCM_ROOT . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
  require_once $composerAutoload;
} else {
  require_once SCM_ROOT . '/src/Core/Autoloader.php';
  \SCM\Core\Autoloader::register(SCM_ROOT . '/src');
}

\SCM\Core\EnvironmentLoader::load(SCM_ROOT . '/.env');

$configFile = SCM_ROOT . '/config/app.php';
if (!is_readable($configFile)) {
  http_response_code(500);
  exit('La configuración de la aplicación no está disponible.');
}

/** @var array<string,mixed> $scmConfig */
$scmConfig = require $configFile;

$requiredConfig = ['db_host', 'db_name', 'db_user', 'db_pass', 'app_secret', 'base_url'];
foreach ($requiredConfig as $requiredKey) {
  if (trim((string) ($scmConfig[$requiredKey] ?? '')) === '') {
    throw new \RuntimeException('Falta la variable de entorno requerida: ' . strtoupper($requiredKey));
  }
}
if (strlen((string) $scmConfig['app_secret']) < 32) {
  throw new \RuntimeException('APP_SECRET debe tener al menos 32 caracteres.');
}

define('SCM_APP_SECRET', (string) $scmConfig['app_secret']);
define('SCM_UPLOAD_MAX_BYTES', max(1024, (int) ($scmConfig['upload_max_bytes'] ?? 10485760)));
define('SCM_SESSION_IDLE_TIMEOUT', max(900, (int) ($scmConfig['session_idle_timeout'] ?? 7200)));

if (!is_dir($logsDir) && !mkdir($logsDir, 0750, true) && !is_dir($logsDir)) {
  throw new \RuntimeException('No se pudo crear el directorio de logs.');
}

$debug = (bool) ($scmConfig['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('error_log', $logsDir . '/php-error.log');

date_default_timezone_set((string) ($scmConfig['timezone'] ?? 'America/Bogota'));

if (session_status() === PHP_SESSION_NONE) {
  ini_set('session.use_strict_mode', '1');
  ini_set('session.gc_maxlifetime', (string) SCM_SESSION_IDLE_TIMEOUT);
  session_name('scm_sess');
  session_start([
    'cookie_httponly' => true,
    'cookie_secure' => (bool) ($scmConfig['secure_cookies'] ?? true),
    'cookie_samesite' => 'Lax',
  ]);
}

try {
  $pdo = new \PDO(
    sprintf(
      'mysql:host=%s;port=%d;dbname=%s;charset=%s',
      (string) $scmConfig['db_host'],
      (int) ($scmConfig['db_port'] ?? 3306),
      (string) $scmConfig['db_name'],
      (string) ($scmConfig['db_charset'] ?? 'utf8mb4')
    ),
    (string) $scmConfig['db_user'],
    (string) $scmConfig['db_pass'],
    [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
} catch (\PDOException $exception) {
  error_log('[database] ' . $exception->getMessage());
  http_response_code(500);
  exit('No fue posible iniciar la aplicación.');
}

$scmDb = new \SCM\Core\Database($pdo, (string) ($scmConfig['db_prefix'] ?? 'wp_'));
$scmAuth = new \SCM\Core\Auth($scmDb, (bool) ($scmConfig['allow_legacy_passwords'] ?? false));
$scmCsrf = new \SCM\Core\Csrf((string) $scmConfig['app_secret']);
$scmSettings = new \SCM\Core\Settings($scmDb);

\SCM\Core\App::init($scmDb, $scmAuth, $scmCsrf, $scmSettings);

require_once SCM_ROOT . '/src/Core/Helpers.php';

define('SCM_BASE_URL', rtrim((string) $scmConfig['base_url'], '/'));
define('SCM_UPLOAD_PATH', SCM_STORAGE_PATH . '/uploads');
