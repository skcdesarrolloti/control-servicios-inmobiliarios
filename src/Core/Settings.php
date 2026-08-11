<?php

namespace SCM\Core;

/**
 * Configuración persistente en JSON (reemplaza get_option/update_option de WordPress).
 */
final class Settings
{
  private string $file;
  private array $data = [];

  public function __construct(string $dataDir)
  {
    $this->file = rtrim($dataDir, '/\\') . '/settings.json';
    $this->load();
  }

  public function get(string $key, $default = null)
  {
    return $this->data[$key] ?? $default;
  }

  public function set(string $key, $value): void
  {
    $this->data[$key] = $value;
    $this->save();
  }

  public function all(): array
  {
    return $this->data;
  }

  // ── interno ──────────────────────────────────────────────────────────

  private function load(): void
  {
    if (!file_exists($this->file)) {
      $this->data = [];
      return;
    }

    $raw = file_get_contents($this->file);
    if ($raw === false) {
      $this->data = [];
      return;
    }

    $decoded = json_decode($raw, true);
    $this->data = is_array($decoded) ? $decoded : [];
  }

  private function save(): void
  {
    $dir = dirname($this->file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
      throw new \RuntimeException('No se pudo crear el directorio de configuración persistente.');
    }

    $encoded = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temporary = tempnam($dir, 'settings_');
    if ($temporary === false) {
      throw new \RuntimeException('No se pudo crear el archivo temporal de configuración.');
    }

    try {
      if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        throw new \RuntimeException('No se pudo escribir la configuración persistente.');
      }
      if (!rename($temporary, $this->file)) {
        throw new \RuntimeException('No se pudo reemplazar la configuración persistente.');
      }
    } finally {
      if (is_file($temporary)) {
        @unlink($temporary);
      }
    }
  }
}
