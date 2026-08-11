<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$failed = false;
$checked = 0;

foreach ($iterator as $file) {
  if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
    continue;
  }
  $path = $file->getPathname();
  if (str_contains(str_replace('\\', '/', $path), '/vendor/')) {
    continue;
  }

  $process = proc_open(
    [PHP_BINARY, '-l', $path],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
  );
  if (!is_resource($process)) {
    fwrite(STDERR, "[ERROR] No se pudo ejecutar php -l para {$path}.\n");
    exit(1);
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($process);
  $checked++;

  if ($exitCode !== 0) {
    $failed = true;
    fwrite(STDERR, trim($stdout . PHP_EOL . $stderr) . PHP_EOL);
  }
}

if ($failed) {
  exit(1);
}

echo "Sintaxis PHP válida: {$checked} archivos.\n";
