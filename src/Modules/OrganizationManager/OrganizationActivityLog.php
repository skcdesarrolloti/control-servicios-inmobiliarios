<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Bitácora del Gestor organizacional: quién creó, editó, eliminó o desactivó cada registro (áreas, cargos, políticas,
 * procesos, funciones, compensaciones y funcionarios). Al eliminar se guarda una copia del registro en `detalle`.
 */
final class OrganizationActivityLog
{
  /** @var array<string,bool> tablas creadas en esta petición (SchemaInspector guarda en caché que no existían). */
  private array $created = [];

  private function ownTableExists(string $table): bool
  {
    return isset($this->created[$table]) || $this->schema->tableExists($table);
  }

  public const ACTIONS = [
    'crear' => 'Creó',
    'editar' => 'Editó',
    'nueva_version' => 'Publicó una nueva versión de',
    'eliminar' => 'Eliminó',
    'desactivar' => 'Desactivó',
    'migracion' => 'Migró',
  ];

  public function __construct(private Database $db, private SchemaInspector $schema)
  {
  }

  public function table(): string
  {
    return $this->db->table('gpo_actividad');
  }

  /** Se llama antes de abrir una transacción: en MySQL un CREATE TABLE confirma la transacción en curso. */
  public function ensureSchema(): void
  {
    if ($this->ownTableExists($this->table())) {
      return;
    }
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      tipo VARCHAR(40) NOT NULL, registro_id BIGINT UNSIGNED NOT NULL DEFAULT 0, accion VARCHAR(30) NOT NULL,
      resumen TEXT NULL, detalle LONGTEXT NULL,
      id_empleado VARCHAR(50) NOT NULL DEFAULT '', nombre VARCHAR(120) NOT NULL DEFAULT '', fecha DATETIME NOT NULL,
      KEY registro (tipo, registro_id), KEY fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->created[$this->table()] = true;
  }

  /** @param array{id:string,name:string} $actor @param array<string,mixed> $detail */
  public function log(string $type, int $recordId, string $action, string $summary, array $actor, array $detail = []): void
  {
    $this->db->insert($this->table(), [
      'tipo' => $type,
      'registro_id' => max(0, $recordId),
      'accion' => $action,
      'resumen' => mb_substr($summary, 0, 1000, 'UTF-8'),
      'detalle' => $detail !== [] ? (string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      'id_empleado' => mb_substr((string) $actor['id'], 0, 50, 'UTF-8'),
      'nombre' => mb_substr((string) $actor['name'], 0, 120, 'UTF-8'),
      'fecha' => date('Y-m-d H:i:s'),
    ]);
  }

  /** @return list<array{id:int,tipo:string,registro_id:int,accion:string,accion_label:string,resumen:string,id_empleado:string,nombre:string,fecha:string}> */
  public function recent(string $type = '', int $recordId = 0, int $limit = 40): array
  {
    if (!$this->ownTableExists($this->table())) {
      return [];
    }
    $where = [];
    $args = [];
    if ($type !== '') {
      $where[] = '`tipo` = ?';
      $args[] = $type;
    }
    if ($recordId > 0) {
      $where[] = '`registro_id` = ?';
      $args[] = $recordId;
    }
    $limit = max(1, min(200, $limit));
    $rows = $this->db->getResults(
      "SELECT `id`, `tipo`, `registro_id`, `accion`, `resumen`, `id_empleado`, `nombre`, `fecha` FROM `{$this->table()}`"
      . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY `id` DESC LIMIT {$limit}",
      $args
    );
    return array_map(static fn(array $row): array => [
      'id' => (int) $row['id'],
      'tipo' => (string) $row['tipo'],
      'registro_id' => (int) $row['registro_id'],
      'accion' => (string) $row['accion'],
      'accion_label' => self::ACTIONS[(string) $row['accion']] ?? (string) $row['accion'],
      'resumen' => (string) ($row['resumen'] ?? ''),
      'id_empleado' => (string) $row['id_empleado'],
      'nombre' => (string) $row['nombre'],
      'fecha' => (string) $row['fecha'],
    ], $rows);
  }
}
