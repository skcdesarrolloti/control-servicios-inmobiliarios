<?php

declare(strict_types=1);

namespace SCM\Core;

/**
 * Configuración compartida dentro de wp_jet_cct_confi_sistema.
 *
 * El panel es el único escritor. Otros consumidores, como el bot, construyen
 * este repositorio en modo de solo lectura.
 */
final class Settings
{
  public const FUNCTION_KEY = 'control_servicios_config';

  private Database $db;
  private string $table;
  private bool $readOnly;

  /** @var array<string,mixed>|null */
  private ?array $data = null;

  public function __construct(Database $db, bool $readOnly = false)
  {
    $this->db = $db;
    $this->table = $db->table('jet_cct_confi_sistema');
    $this->readOnly = $readOnly;
  }

  public function get(string $key, $default = null)
  {
    $this->load();
    return array_key_exists($key, $this->data ?? [])
      ? $this->data[$key]
      : $default;
  }

  public function set(string $key, $value, int $updatedBy = 0): void
  {
    if ($this->readOnly) {
      throw new \LogicException('Este consumidor no tiene permitido modificar la configuración.');
    }

    $key = trim($key);
    if ($key === '' || strlen($key) > 191) {
      throw new \InvalidArgumentException('La clave de configuración no es válida.');
    }

    $pdo = $this->db->pdo();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }

    try {
      $lockClause = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
      $row = $this->db->getRow(
        "SELECT `_ID`, `valor` FROM `{$this->table}` WHERE `funcion` = ? LIMIT 1{$lockClause}",
        [self::FUNCTION_KEY]
      );
      $settings = $this->decodeSettingsDocument((string) ($row['valor'] ?? ''));
      $settings[$key] = $value;
      $encoded = $this->encodeSettingsDocument($settings);
      $now = gmdate('Y-m-d H:i:s');
      $author = $updatedBy > 0 ? $updatedBy : null;

      if (is_array($row)) {
        $this->db->update($this->table, [
          'valor' => $encoded,
          'cct_author_id' => $author,
          'cct_modified' => $now,
          'departamento' => 'Control Servicios',
        ], ['_ID' => (int) $row['_ID']]);
      } else {
        $this->db->insert($this->table, [
          'cct_status' => 'publish',
          'cct_author_id' => $author,
          'cct_created' => $now,
          'cct_modified' => $now,
          'funcion' => self::FUNCTION_KEY,
          'valor' => $encoded,
          'departamento' => 'Control Servicios',
        ]);
      }

    } catch (\Throwable $exception) {
      if ($ownsTransaction) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    if ($ownsTransaction) {
      $pdo->commit();
    }
    $this->data = $settings;
  }

  /** @return array<string,mixed> */
  public function all(): array
  {
    $this->load();
    return $this->data ?? [];
  }

  public function refresh(): void
  {
    $this->data = null;
  }

  private function load(): void
  {
    if ($this->data !== null) {
      return;
    }

    $row = $this->db->getRow(
      "SELECT `valor` FROM `{$this->table}` WHERE `funcion` = ? LIMIT 1",
      [self::FUNCTION_KEY]
    );
    $this->data = $this->decodeSettingsDocument((string) ($row['valor'] ?? ''));
  }

  /** @return array<string,mixed> */
  private function decodeSettingsDocument(string $raw): array
  {
    if (trim($raw) === '') {
      return [];
    }

    try {
      $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      throw new \RuntimeException('control_servicios_config contiene JSON inválido.', 0, $exception);
    }

    if (!is_array($decoded)) {
      return [];
    }
    $settings = $decoded['settings'] ?? $decoded;
    return is_array($settings) ? $settings : [];
  }

  /** @param array<string,mixed> $settings */
  private function encodeSettingsDocument(array $settings): string
  {
    return json_encode([
      'version' => 1,
      'settings' => $settings,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }
}
