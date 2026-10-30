<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Lectura y escritura de filas CCT y metas del post del inmueble, compartidas por la hoja de cierre y su flujo
 * (revisión de servicios y respuesta). Solo escribe las columnas que existen en la tabla.
 * Requiere $this->db (Database) y $this->schema (SchemaInspector).
 */
trait ClosingSheetRecords
{
  /** @param array<string,mixed> $data */
  private function update(string $name, int $id, array $data): void
  {
    $table = $this->db->table($name);
    if ($id <= 0 || !$this->schema->tableExists($table)) {
      return;
    }
    $data = $this->schema->filterTableData($table, $data);
    if ($data !== []) {
      $this->db->update($table, $data, ['_ID' => $id]);
    }
  }

  /** Inserta con el autor CCT = id_empleado de quien actúa. @param array{employee_id:string} $sender @param array<string,mixed> $data */
  private function insert(string $name, array $sender, string $nowSql, array $data): int
  {
    $table = $this->db->table($name);
    if (!$this->schema->tableExists($table)) {
      return 0;
    }
    $data = $this->schema->filterTableData($table, ['cct_status' => 'publish', 'cct_author_id' => $sender['employee_id'], 'cct_created' => $nowSql, 'cct_modified' => $nowSql] + $data);
    if ($data === [] || !$this->db->insert($table, $data)) {
      throw new \RuntimeException('No se pudo guardar en ' . $name . '.');
    }
    return (int) $this->db->lastInsertId();
  }

  /** Metadatos del post "inmuebles" en WordPress, como la acción insert_post del formulario. @param array<string,string> $meta */
  private function propertyMeta(int $postId, array $meta): void
  {
    $posts = $this->db->table('posts');
    $postmeta = $this->db->table('postmeta');
    if ($postId <= 0 || $meta === [] || !$this->schema->tableExists($postmeta)
      || (string) $this->db->getVar("SELECT `post_type` FROM `{$posts}` WHERE `ID` = ? LIMIT 1", [$postId]) !== 'inmuebles') {
      return;
    }
    foreach ($meta as $key => $value) {
      $ids = $this->db->getCol("SELECT `meta_id` FROM `{$postmeta}` WHERE `post_id` = ? AND `meta_key` = ?", [$postId, $key]);
      if ($ids === []) {
        $this->db->insert($postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) $value]);
      } else {
        $this->db->update($postmeta, ['meta_value' => (string) $value], ['post_id' => $postId, 'meta_key' => $key]);
      }
    }
  }

  /** @return array<string,mixed> */
  private function row(string $name, mixed $id): array
  {
    $id = trim((string) $id);
    $table = $this->db->table($name);
    if (!ctype_digit($id) || (int) $id <= 0 || !$this->schema->tableExists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $id]) ?? [];
  }

}
