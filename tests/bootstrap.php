<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composerAutoload = $root . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
  require_once $composerAutoload;
} else {
  require_once $root . '/src/Core/Autoloader.php';
  \SCM\Core\Autoloader::register($root . '/src');
}
