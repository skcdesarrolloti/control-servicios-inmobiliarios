<?php

declare(strict_types=1);

namespace SCM\Modules\Calendar;

use SCM\Core\Database;

/**
 * Configuración › Categorías del calendario: nombre, color, descripción y a quién se avisa (`roles_notificar`, IDs de
 * funcionarios separados por coma, como lo guardaba calendario/frontend/src/views/GestionCategorias.jsx). La tabla
 * `categorias_calendario` es compartida con el panel comercial (control-servicios-comerciales, CommercialSettingsService::
 * saveCategories): mantener las mismas reglas. Una categoría con eventos no se elimina (la app anterior borraba sus eventos).
 */
final class CalendarCategorySettings
{
  private Database $db;
  private string $employees;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->employees = $db->table('jet_cct_funcionarios');
  }

  /**
   * Categorías con sus eventos y los funcionarios que se pueden elegir para avisar.
   * @return array{categories:array<int,array<string,mixed>>,employees:array<int,array{id:string,label:string,cargo:string,activo:bool}>}
   */
  public function load(): array
  {
    $rows = $this->db->getResults(
      'SELECT c.`id`, c.`nombre`, c.`color`, c.`descripcion`, c.`roles_notificar`, COUNT(e.`id`) AS eventos
         FROM `' . CalendarStore::CATEGORIES . '` c
         LEFT JOIN `' . CalendarStore::EVENTS . '` e ON e.`id_categoria` = c.`id`
        GROUP BY c.`id`, c.`nombre`, c.`color`, c.`descripcion`, c.`roles_notificar`
        ORDER BY c.`nombre` ASC'
    );
    $categories = [];
    $referenced = [];
    foreach ($rows as $row) {
      $notify = self::idList($row['roles_notificar'] ?? '');
      $referenced = array_merge($referenced, $notify);
      $categories[] = [
        'id' => (string) $row['id'],
        'nombre' => (string) ($row['nombre'] ?? ''),
        'color' => self::color((string) ($row['color'] ?? '')),
        'descripcion' => (string) ($row['descripcion'] ?? ''),
        'notificar' => $notify,
        'eventos' => (int) ($row['eventos'] ?? 0),
      ];
    }
    return ['categories' => $categories, 'employees' => $this->employeeOptions($referenced)];
  }

  /**
   * Valida todo antes de escribir para no dejar cambios a medias.
   * @param array<int,mixed> $rows
   * @param array<int,string> $deleted
   * @return array<int,string> IDs de las categorías creadas.
   */
  public function save(array $rows, array $deleted): array
  {
    $deleted = array_values(array_unique(array_filter(array_map(static fn($id): string => (string) (int) $id, $deleted), static fn(string $id): bool => $id !== '0')));
    foreach ($deleted as $id) {
      $name = $this->db->getVar('SELECT `nombre` FROM `' . CalendarStore::CATEGORIES . '` WHERE `id` = ?', [(int) $id]);
      if ($name !== null && (int) $this->db->getVar('SELECT COUNT(*) FROM `' . CalendarStore::EVENTS . '` WHERE `id_categoria` = ?', [(int) $id]) > 0) {
        throw new \InvalidArgumentException('La categoría "' . (string) $name . '" tiene eventos y no se puede eliminar.');
      }
    }

    $known = array_fill_keys(array_map('strval', $this->db->getCol('SELECT `id_empleado` FROM `' . $this->employees . '` WHERE `id_empleado` IS NOT NULL AND `id_empleado` <> \'\'')), true);
    $clean = [];
    $names = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $id = (int) ($row['id'] ?? 0);
      if ($id > 0 && in_array((string) $id, $deleted, true)) {
        continue;
      }
      $name = preg_replace('/\s+/u', ' ', trim((string) ($row['nombre'] ?? ''))) ?? '';
      if ($name === '' || mb_strlen($name) > 100) {
        throw new \InvalidArgumentException('Cada categoría necesita un nombre de hasta 100 caracteres.');
      }
      $color = trim((string) ($row['color'] ?? ''));
      if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
        throw new \InvalidArgumentException('El color de "' . $name . '" no es válido.');
      }
      $key = mb_strtolower($name);
      $taken = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . CalendarStore::CATEGORIES . '` WHERE LOWER(`nombre`) = LOWER(?) AND `id` <> ?', [$name, $id]) > 0;
      if (isset($names[$key]) || $taken) {
        throw new \InvalidArgumentException('Ya existe una categoría llamada "' . $name . '".');
      }
      $names[$key] = true;
      $description = trim((string) ($row['descripcion'] ?? ''));
      if (mb_strlen($description) > 500) {
        throw new \InvalidArgumentException('La descripción de "' . $name . '" supera los 500 caracteres.');
      }
      // Solo IDs de funcionarios que existen: un ID desconocido no recibiría el correo.
      $notify = array_values(array_filter(self::idList($row['notificar'] ?? []), static fn(string $employee): bool => isset($known[$employee])));
      $clean[] = ['id' => $id, 'data' => ['nombre' => $name, 'color' => strtolower($color), 'descripcion' => $description, 'roles_notificar' => implode(',', $notify)]];
    }

    $pdo = $this->db->pdo();
    $created = [];
    $pdo->beginTransaction();
    try {
      foreach ($clean as $row) {
        if ($row['id'] > 0) {
          $this->db->update(CalendarStore::CATEGORIES, $row['data'], ['id' => $row['id']]);
          continue;
        }
        $this->db->insert(CalendarStore::CATEGORIES, $row['data']);
        $created[] = (string) $this->db->lastInsertId();
      }
      foreach ($deleted as $id) {
        $this->db->delete(CalendarStore::CATEGORIES, ['id' => (int) $id]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    }
    return $created;
  }

  /** @return array<int,string> IDs de todas las categorías. */
  public function ids(): array
  {
    return array_map('strval', $this->db->getCol('SELECT `id` FROM `' . CalendarStore::CATEGORIES . '` ORDER BY `id` ASC'));
  }

  /**
   * Funcionarios activos (activo = Si) y, marcados como inactivos, los que una categoría ya tiene para no perderlos sin aviso.
   * @param array<int,string> $referenced
   * @return array<int,array{id:string,label:string,cargo:string,activo:bool}>
   */
  private function employeeOptions(array $referenced): array
  {
    $out = [];
    foreach ($this->db->getResults('SELECT `id_empleado`, `nombre`, `id_cargo`, `activo` FROM `' . $this->employees . '` WHERE `id_empleado` IS NOT NULL AND `id_empleado` <> \'\' ORDER BY `nombre` ASC') as $row) {
      $id = trim((string) $row['id_empleado']);
      $active = strcasecmp(trim((string) ($row['activo'] ?? '')), 'Si') === 0;
      if ($id === '' || isset($out[$id]) || (!$active && !in_array($id, $referenced, true))) {
        continue;
      }
      $out[$id] = ['id' => $id, 'label' => trim((string) ($row['nombre'] ?? '')) ?: 'Funcionario ' . $id, 'cargo' => trim((string) ($row['id_cargo'] ?? '')), 'activo' => $active];
    }
    return array_values($out);
  }

  /** @param mixed $value @return array<int,string> */
  private static function idList($value): array
  {
    $list = is_array($value) ? $value : explode(',', (string) $value);
    $out = [];
    foreach ($list as $id) {
      $id = trim((string) $id);
      if ($id !== '' && preg_match('/^[0-9A-Za-z_-]{1,40}$/', $id) === 1) {
        $out[$id] = $id;
      }
    }
    return array_values($out);
  }

  private static function color(string $color): string
  {
    return preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) === 1 ? strtolower(trim($color)) : '#1e3c76';
  }
}
