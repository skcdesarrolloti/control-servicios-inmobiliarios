<?php

namespace SCM\Core;

/**
 * Registro estático de servicios (service locator simple).
 * Se inicializa una vez en bootstrap.php y permite que las funciones helper
 * de Helpers.php accedan a Database, Auth, Csrf y Settings sin globals.
 */
final class App
{
  private static ?Database $db       = null;
  private static ?Auth     $auth     = null;
  private static ?Csrf     $csrf     = null;
  private static ?Settings $settings = null;

  public static function init(Database $db, Auth $auth, Csrf $csrf, Settings $settings): void
  {
    self::$db       = $db;
    self::$auth     = $auth;
    self::$csrf     = $csrf;
    self::$settings = $settings;
  }

  public static function db(): Database
  {
    if (self::$db === null) {
      throw new \RuntimeException('App::db() no inicializado. Llama App::init() desde bootstrap.php.');
    }
    return self::$db;
  }

  public static function auth(): Auth
  {
    if (self::$auth === null) {
      throw new \RuntimeException('App::auth() no inicializado.');
    }
    return self::$auth;
  }

  public static function csrf(): Csrf
  {
    if (self::$csrf === null) {
      throw new \RuntimeException('App::csrf() no inicializado.');
    }
    return self::$csrf;
  }

  public static function settings(): Settings
  {
    if (self::$settings === null) {
      throw new \RuntimeException('App::settings() no inicializado.');
    }
    return self::$settings;
  }
}
