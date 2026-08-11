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
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    file_put_contents(
      $this->file,
      json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }
}
