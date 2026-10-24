<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Archivador de Generar PSE: guarda los .txt generados fuera de public/ (antes quedaban en la carpeta
 * pública cargarpse/descargas). Un archivo con el mismo nombre se reemplaza, como en el original.
 */
final class PseArchive
{
  private const NAME_PATTERN = '/^[0-9A-Za-z_-]{1,80}\.txt$/';

  public function __construct(private string $directory) {}

  public static function default(): self
  {
    return new self(rtrim((string) SCM_STORAGE_PATH, '/\\') . '/data/herramientas-pse');
  }

  public function save(string $name, string $content): void
  {
    $this->assertName($name);
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
      throw new \RuntimeException('No se pudo crear la carpeta del archivador de PSE.');
    }
    $target = $this->directory . '/' . $name;
    $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temporary, $content, LOCK_EX) === false || !@rename($temporary, $target)) {
      @unlink($temporary);
      throw new \RuntimeException('No se pudo guardar ' . $name . ' en el archivador.');
    }
  }

  /** @return list<array{name:string,size:int,modified:int,records:int}> más recientes primero */
  public function files(): array
  {
    $files = [];
    foreach (glob($this->directory . '/*.txt') ?: [] as $path) {
      $name = basename($path);
      if (preg_match(self::NAME_PATTERN, $name) !== 1 || !is_file($path)) continue;
      $content = (string) @file_get_contents($path);
      $files[] = [
        'name' => $name,
        'size' => (int) @filesize($path),
        'modified' => (int) @filemtime($path),
        'records' => max(0, substr_count($content, "\n") - 1),
      ];
    }
    usort($files, static fn(array $a, array $b): int => [$b['modified'], $b['name']] <=> [$a['modified'], $a['name']]);
    return $files;
  }

  public function read(string $name): string
  {
    $this->assertName($name);
    $path = $this->directory . '/' . $name;
    if (!is_file($path)) throw new \RuntimeException('El archivo ' . $name . ' ya no está en el archivador.');
    $content = @file_get_contents($path);
    if ($content === false) throw new \RuntimeException('No se pudo leer ' . $name . '.');
    return $content;
  }

  private function assertName(string $name): void
  {
    if (preg_match(self::NAME_PATTERN, $name) !== 1) throw new \InvalidArgumentException('Nombre de archivo no válido.');
  }
}
