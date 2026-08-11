<?php

namespace SCM\Core;

use PDO;
use PDOStatement;

/**
 * Wrapper PDO que replica los métodos principales de $wpdb.
 */
final class Database
{
  private PDO $pdo;
  private string $prefix;

  public function __construct(PDO $pdo, string $prefix = 'wp_')
  {
    $this->pdo    = $pdo;
    $this->prefix = $prefix;
  }

  public function prefix(): string
  {
    return $this->prefix;
  }

  public function pdo(): PDO
  {
    return $this->pdo;
  }

  public function table(string $name): string
  {
    return $this->prefix . $name;
  }

  /** Equivalente a $wpdb->get_results(prepare(...), ARRAY_A) */
  public function getResults(string $sql, array $args = []): array
  {
    $stmt = $this->run($sql, $args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /** Equivalente a $wpdb->get_row(prepare(...), ARRAY_A) */
  public function getRow(string $sql, array $args = []): ?array
  {
    $stmt = $this->run($sql, $args);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
  }

  /** Equivalente a $wpdb->get_var(prepare(...)) */
  public function getVar(string $sql, array $args = [])
  {
    $stmt = $this->run($sql, $args);
    $val  = $stmt->fetchColumn();
    return $val !== false ? $val : null;
  }

  /** Equivalente a $wpdb->get_col(sql, 0) */
  public function getCol(string $sql, array $args = [], int $colIndex = 0): array
  {
    $stmt = $this->run($sql, $args);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, $colIndex) ?: [];
  }

  /** Equivalente a $wpdb->update() — devuelve filas afectadas */
  public function update(string $table, array $data, array $where): int
  {
    if (empty($data) || empty($where)) {
      return 0;
    }

    $setClauses   = array_map(fn($k) => "`{$k}` = ?", array_keys($data));
    $whereClauses = array_map(fn($k) => "`{$k}` = ?", array_keys($where));

    $sql  = "UPDATE `{$table}` SET " . implode(', ', $setClauses)
      . " WHERE " . implode(' AND ', $whereClauses);
    $args = array_merge(array_values($data), array_values($where));

    return $this->run($sql, $args)->rowCount();
  }

  /** Equivalente a $wpdb->insert() */
  public function insert(string $table, array $data): bool
  {
    if (empty($data)) {
      return false;
    }

    $cols = array_map(fn($k) => "`{$k}`", array_keys($data));
    $placeholders = array_fill(0, count($data), '?');

    $sql = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ")"
      . " VALUES (" . implode(', ', $placeholders) . ")";

    $this->run($sql, array_values($data));
    return true;
  }

  /** Equivalente a $wpdb->esc_like() */
  public function escapeLike(string $str): string
  {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $str);
  }

  public function lastInsertId(): string
  {
    return $this->pdo->lastInsertId();
  }

  /** Equivalente a $wpdb->delete() — devuelve filas afectadas */
  public function delete(string $table, array $where): int
  {
    if (empty($where)) {
      return 0;
    }

    $whereClauses = array_map(fn($k) => "`{$k}` = ?", array_keys($where));
    $sql  = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereClauses);

    return $this->run($sql, array_values($where))->rowCount();
  }

  // ── interno ───────────────────────────────────────────────────────────

  private function run(string $sql, array $args): PDOStatement
  {
    if (empty($args)) {
      $stmt = $this->pdo->query($sql);
      if (!($stmt instanceof PDOStatement)) {
        $error = $this->pdo->errorInfo();
        throw new \RuntimeException('SQL query error: ' . (string)($error[2] ?? 'Unknown error'));
      }
      return $stmt;
    }

    $stmt = $this->pdo->prepare($sql);
    if (!($stmt instanceof PDOStatement)) {
      $error = $this->pdo->errorInfo();
      throw new \RuntimeException('SQL prepare error: ' . (string)($error[2] ?? 'Unknown error'));
    }

    if (!$stmt->execute(array_values($args))) {
      $error = $stmt->errorInfo();
      throw new \RuntimeException('SQL execute error: ' . (string)($error[2] ?? 'Unknown error'));
    }

    return $stmt;
  }
}
