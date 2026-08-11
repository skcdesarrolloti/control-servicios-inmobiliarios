<?php

/**
 * Bootstrap — carga configuración, autoloader, sesión y dependencias globales.
 * Incluir al inicio de index.php y de cualquier endpoint AJAX.
 */

// ── Mostrar errores (quitar en producción estable) ─────────────────────────
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

define('SCM_ROOT', __DIR__);
define('SCM_VERSION', '2.0.0');

// ── Configuración ──────────────────────────────────────────────────────────
$cfgFile = SCM_ROOT . '/config.php';
if (!file_exists($cfgFile)) {
  http_response_code(500);
  die('Error: no existe config.php. Copia config.example.php y edítalo.');
}
$scmConfig = require $cfgFile;

// ── Zona horaria ──────────────────────────────────────────────────────────
date_default_timezone_set($scmConfig['timezone'] ?? 'America/Bogota');

// ── Autoloader ────────────────────────────────────────────────────────────
require_once SCM_ROOT . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(SCM_ROOT . '/src');

// ── Sesión ────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
  session_name('scm_sess');
  session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
  ]);
}

// ── Base de datos (PDO) ───────────────────────────────────────────────────
try {
  $pdo = new PDO(
    sprintf(
      'mysql:host=%s;dbname=%s;charset=%s',
      $scmConfig['db_host'],
      $scmConfig['db_name'],
      $scmConfig['db_charset'] ?? 'utf8mb4'
    ),
    $scmConfig['db_user'],
    $scmConfig['db_pass'],
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  );
} catch (\PDOException $e) {
  http_response_code(500);
  die('Error de base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// ── Instancias globales ───────────────────────────────────────────────────
$scmDb       = new \SCM\Core\Database($pdo, $scmConfig['db_prefix'] ?? 'wp_');
$scmAuth     = new \SCM\Core\Auth($scmDb);
$scmCsrf     = new \SCM\Core\Csrf($scmConfig['app_secret'] ?? 'secret');
$scmSettings = new \SCM\Core\Settings(SCM_ROOT . '/data');

// ── Registro de servicios (App) ───────────────────────────────────────────
\SCM\Core\App::init($scmDb, $scmAuth, $scmCsrf, $scmSettings);

// ── Funciones helper globales (equivalentes a las funciones de WordPress) ─
require_once SCM_ROOT . '/src/Core/Helpers.php';

// ── Constantes de URLs/paths ──────────────────────────────────────────────
define('SCM_BASE_URL',  rtrim($scmConfig['base_url'] ?? '', '/'));
define('SCM_BASE_PATH', SCM_ROOT);
