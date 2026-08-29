<?php

namespace SCM\Support;

use SCM\Core\Database;

final class FuncionarioOptions
{
  /** @var string[] */
  private const DEFAULT_PANEL_CARGO_IDS = ['3', '4', '5', '7', '8', '11', '13', '14', '18'];

  /** @return string[] */
  public static function panelCargoIds(): array
  {
    $raw = trim((string) (getenv('SCM_PANEL_FUNCIONARIO_CARGO_IDS') ?: ''));
    if ($raw === '') {
      return self::DEFAULT_PANEL_CARGO_IDS;
    }

    $ids = [];
    foreach (preg_split('/[,\s;|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
      $clean = trim((string) $id);
      if ($clean !== '') {
        $ids[$clean] = $clean;
      }
    }

    return $ids !== [] ? array_values($ids) : self::DEFAULT_PANEL_CARGO_IDS;
  }

  /**
   * @return array<int,array{
   *   id:string,
   *   label:string,
   *   name:string,
   *   employee_id:string,
   *   email:string,
   *   cargo:string,
   *   id_cargo:string
   * }>
   */
  public static function panelFuncionarios(Database $db, ?SchemaInspector $schema = null, string $idMode = 'employee'): array
  {
    $schema = $schema ?: new SchemaInspector($db);
    $table = $db->table('jet_cct_funcionarios');
    if (!$schema->tableExists($table)) {
      return [];
    }

    $primaryColumn = $schema->columnExists($table, '_ID') ? '_ID' : '';
    $employeeColumn = $schema->columnExists($table, 'id_empleado') ? 'id_empleado' : '';
    $idColumn = $idMode === 'primary' ? $primaryColumn : ($employeeColumn !== '' ? $employeeColumn : $primaryColumn);
    if ($idColumn === '') {
      return [];
    }

    $nameColumn = $schema->detectFirstExistingColumn($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    $emailColumn = $schema->detectFirstExistingColumn($table, ['correo', 'correo_dian', 'email']);
    $cargoColumn = $schema->columnExists($table, 'id_cargo') ? 'id_cargo' : '';
    $activeColumn = $schema->detectFirstExistingColumn($table, ['activo', 'cct_status']);
    $cargoTable = $db->table('jet_cct_cargos');
    $hasCargoNames = $cargoColumn !== ''
      && $schema->tableExists($cargoTable)
      && $schema->columnExists($cargoTable, '_ID')
      && $schema->columnExists($cargoTable, 'nombre_cargo');

    $where = ["TRIM(COALESCE(f.`{$idColumn}`, '')) <> ''"];
    $args = [];
    if ($employeeColumn !== '') {
      $where[] = "TRIM(COALESCE(f.`{$employeeColumn}`, '')) <> ''";
    }
    if ($activeColumn !== '') {
      if ($activeColumn === 'cct_status') {
        $where[] = "LOWER(TRIM(COALESCE(f.`{$activeColumn}`, 'publish'))) IN ('publish', 'published', 'si', 'sí', '1', 'true', 'activo', 'active')";
      } else {
        $where[] = "LOWER(TRIM(COALESCE(f.`{$activeColumn}`, 'si'))) IN ('si', 'sí', '1', 'true', 'activo', 'active', 'publish', 'published')";
      }
    }
    $allowedCargoIds = self::panelCargoIds();
    if ($cargoColumn !== '' && $allowedCargoIds !== []) {
      $where[] = 'TRIM(COALESCE(f.`' . $cargoColumn . "`, '')) IN (" . implode(',', array_fill(0, count($allowedCargoIds), '?')) . ')';
      array_push($args, ...$allowedCargoIds);
    }

    $select = [
      "TRIM(COALESCE(f.`{$idColumn}`, '')) AS id",
      $primaryColumn !== '' ? "TRIM(COALESCE(f.`{$primaryColumn}`, '')) AS primary_id" : "'' AS primary_id",
      $employeeColumn !== '' ? "TRIM(COALESCE(f.`{$employeeColumn}`, '')) AS employee_id" : "'' AS employee_id",
      $nameColumn !== '' ? "TRIM(COALESCE(f.`{$nameColumn}`, '')) AS nombre" : "'' AS nombre",
      $emailColumn !== '' ? "TRIM(COALESCE(f.`{$emailColumn}`, '')) AS correo" : "'' AS correo",
      $cargoColumn !== '' ? "TRIM(COALESCE(f.`{$cargoColumn}`, '')) AS id_cargo" : "'' AS id_cargo",
      $hasCargoNames ? "TRIM(COALESCE(c.`nombre_cargo`, '')) AS nombre_cargo" : "'' AS nombre_cargo",
    ];
    $join = $hasCargoNames
      ? " LEFT JOIN `{$cargoTable}` c ON TRIM(COALESCE(f.`{$cargoColumn}`, '')) = CAST(c.`_ID` AS CHAR)"
      : '';
    $rows = $db->getResults(
      'SELECT ' . implode(', ', $select)
      . " FROM `{$table}` f{$join}"
      . ' WHERE ' . implode(' AND ', $where)
      . ' ORDER BY nombre ASC, employee_id ASC, id ASC',
      $args
    );

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $seenKey = strtolower($idMode . ':' . $id);
      if (isset($seen[$seenKey])) {
        continue;
      }
      $seen[$seenKey] = true;
      $name = trim((string) ($row['nombre'] ?? ''));
      $employeeId = trim((string) ($row['employee_id'] ?? ''));
      $cargoName = trim((string) ($row['nombre_cargo'] ?? ''));
      $cargoId = trim((string) ($row['id_cargo'] ?? ''));
      $cargo = $cargoName !== '' ? $cargoName : ($cargoId !== '' ? 'Cargo ' . $cargoId : 'Funcionario');
      $displayName = $name !== '' ? $name : ('Funcionario #' . $id);
      $label = $employeeId !== '' && $idMode !== 'primary'
        ? $employeeId . ' - ' . $displayName
        : $displayName;

      $out[] = [
        'id' => $id,
        'label' => $label,
        'name' => $displayName,
        'employee_id' => $employeeId,
        'email' => trim((string) ($row['correo'] ?? '')),
        'cargo' => $cargo,
        'id_cargo' => $cargoId,
      ];
    }

    return $out;
  }
}
