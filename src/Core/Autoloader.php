<?php


namespace SCM\Core;

final class Autoloader
{
    private static bool $registered = false;

    public static function register(string $baseDir): void
    {
        if (self::$registered) {
            return;
        }

        $baseDir = rtrim($baseDir, '\\/') . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($baseDir): void {
            $prefix = 'SCM\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            if ($relative === false || $relative === '') {
                return;
            }

            $path = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_readable($path)) {
                require_once $path;
            }
        });

        self::$registered = true;
    }
}
