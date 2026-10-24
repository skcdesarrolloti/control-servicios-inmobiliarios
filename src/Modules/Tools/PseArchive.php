<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Archivador de Generar PSE: guarda los .txt generados fuera de public/ (antes quedaban en la carpeta
 * pública cargarpse/descargas). Un archivo con el mismo nombre se reemplaza, como en el original, y
 * history.json conserva quién generó cada versión.
 */
final class PseArchive
{
  private const NAME_PATTERN = '/^[0-9A-Za-z_-]{1,80}\.txt$/';
  private const HISTORY = 'history.json';
  /** Versiones guardadas por archivo en el historial. */
  private const HISTORY_LIMIT = 20;

  public function __construct(private string $directory) {}

  public static function default(): self
  {
    return new self(rtrim((string) SCM_STORAGE_PATH, '/\\') . '/data/herramientas-pse');
  }

  /** @param array{actor_id?:string,actor_name?:string,records?:int,origin?:string,at?:int} $meta */
  public function save(string $name, string $content, array $meta = []): void
  {
    $this->assertName($name);
    $this->ensureDirectory();
    $target = $this->directory . '/' . $name;
    $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temporary, $content, LOCK_EX) === false || !@rename($temporary, $target)) {
      @unlink($temporary);
      throw new \RuntimeException('No se pudo guardar ' . $name . ' en el archivador.');
    }
    $at = (int) ($meta['at'] ?? time());
    if (isset($meta['at'])) @touch($target, $at);
    $this->appendHistory($name, [
      'at' => $at,
      'actor_id' => (string) ($meta['actor_id'] ?? ''),
      'actor_name' => (string) ($meta['actor_name'] ?? ''),
      'records' => (int) ($meta['records'] ?? max(0, substr_count($content, "\n") - 1)),
      'origin' => (string) ($meta['origin'] ?? 'panel'),
    ]);
  }

  public function has(string $name): bool
  {
    return preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->directory . '/' . $name);
  }

  /** @return list<array{name:string,size:int,modified:int,records:int,actor_name:string,origin:string,versions:int}> más recientes primero */
  public function files(): array
  {
    $history = $this->history();
    $files = [];
    foreach (glob($this->directory . '/*.txt') ?: [] as $path) {
      $name = basename($path);
      if (preg_match(self::NAME_PATTERN, $name) !== 1 || !is_file($path)) continue;
      $content = (string) @file_get_contents($path);
      $versions = $history[$name] ?? [];
      $last = $versions === [] ? [] : $versions[count($versions) - 1];
      $files[] = [
        'name' => $name,
        'size' => (int) @filesize($path),
        'modified' => (int) @filemtime($path),
        'records' => max(0, substr_count($content, "\n") - 1),
        'actor_name' => (string) ($last['actor_name'] ?? ''),
        'origin' => (string) ($last['origin'] ?? ''),
        'versions' => count($versions),
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

  /** @return array<string,list<array{at:int,actor_id:string,actor_name:string,records:int,origin:string}>> */
  public function history(): array
  {
    $raw = @file_get_contents($this->directory . '/' . self::HISTORY);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
  }

  /** @param array{at:int,actor_id:string,actor_name:string,records:int,origin:string} $entry */
  private function appendHistory(string $name, array $entry): void
  {
    $path = $this->directory . '/' . self::HISTORY;
    $handle = @fopen($path, 'c+');
    if ($handle === false) return;
    try {
      flock($handle, LOCK_EX);
      $data = json_decode((string) stream_get_contents($handle), true);
      $data = is_array($data) ? $data : [];
      $data[$name] = array_slice(array_merge((array) ($data[$name] ?? []), [$entry]), -self::HISTORY_LIMIT);
      ftruncate($handle, 0);
      rewind($handle);
      fwrite($handle, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      fflush($handle);
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  private function ensureDirectory(): void
  {
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
      throw new \RuntimeException('No se pudo crear la carpeta del archivador de PSE.');
    }
  }

  private function assertName(string $name): void
  {
    if (preg_match(self::NAME_PATTERN, $name) !== 1) throw new \InvalidArgumentException('Nombre de archivo no válido.');
  }
}
