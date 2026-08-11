<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$projectRoot = dirname(__DIR__);
$sourceRoot = isset($argv[1]) ? realpath((string) $argv[1]) : false;
if ($sourceRoot === false || !is_dir($sourceRoot)) {
  fwrite(STDERR, "Uso: php bin/migrate-legacy-storage.php <directorio-del-proyecto-anterior>\n");
  exit(1);
}

$targets = [
  $sourceRoot . '/uploads' => $projectRoot . '/storage/uploads',
  $sourceRoot . '/data' => $projectRoot . '/storage/data',
];
$copied = 0;
$skipped = 0;

foreach ($targets as $sourceDirectory => $targetDirectory) {
  if (!is_dir($sourceDirectory)) {
    continue;
  }
  if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
    fwrite(STDERR, "No se pudo crear {$targetDirectory}.\n");
    exit(1);
  }

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $sourceFile) {
    if (!$sourceFile->isFile()) {
      continue;
    }
    $relative = substr($sourceFile->getPathname(), strlen($sourceDirectory) + 1);
    $destination = $targetDirectory . '/' . str_replace('\\', '/', $relative);
    if (is_file($destination)) {
      $skipped++;
      continue;
    }
    $destinationDirectory = dirname($destination);
    if (!is_dir($destinationDirectory)
      && !mkdir($destinationDirectory, 0750, true)
      && !is_dir($destinationDirectory)) {
      fwrite(STDERR, "No se pudo crear {$destinationDirectory}.\n");
      exit(1);
    }
    if (!copy($sourceFile->getPathname(), $destination)) {
      fwrite(STDERR, "No se pudo copiar {$relative}.\n");
      exit(1);
    }
    $copied++;
  }
}

echo "Migración terminada. Copiados: {$copied}; existentes omitidos: {$skipped}.\n";
