<?php

declare(strict_types=1);

namespace SCM\Core;

final class EnvironmentLoader
{
  public static function load(string $path): void
  {
    if (!is_readable($path)) {
      return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
      return;
    }

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      if (str_starts_with($line, 'export ')) {
        $line = trim(substr($line, 7));
      }

      $separator = strpos($line, '=');
      if ($separator === false) {
        continue;
      }

      $key = trim(substr($line, 0, $separator));
      $value = trim(substr($line, $separator + 1));
      if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) || getenv($key) !== false) {
        continue;
      }

      if (strlen($value) >= 2) {
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
          $value = substr($value, 1, -1);
        }
      }

      putenv($key . '=' . $value);
      $_ENV[$key] = $value;
      $_SERVER[$key] = $value;
    }
  }
}
