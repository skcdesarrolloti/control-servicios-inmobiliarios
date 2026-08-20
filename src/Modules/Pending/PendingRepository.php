<?php

namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

final class PendingRepository
{
  private Database $db;
  private ?SchemaInspector $schema = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  public function getDb(): Database
  {
    return $this->db;
  }

  private function schema(): SchemaInspector
  {
    if (!$this->schema instanceof SchemaInspector) {
      $this->schema = new SchemaInspector($this->db);
    }
    return $this->schema;
  }

  /** @return array<int,array{id:string,label:string}> */
  public function getFuncionarios(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $sql = "SELECT TRIM(COALESCE(id_empleado,'')) AS id, TRIM(COALESCE(nombre,id_empleado,'')) AS nombre, LOWER(TRIM(COALESCE(activo,'si'))) AS activo FROM `{$table}` WHERE TRIM(COALESCE(id_empleado,''))<>'' ORDER BY nombre ASC";
    $rows = $this->db->getResults($sql);

    $out = [];
    foreach ($rows as $row) {
      $id = trim((string)($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $activo = trim((string)($row['activo'] ?? ''));
      if ($activo !== '' && !in_array($activo, ['si', 'sí', '1', 'true', 'activo'], true)) {
        continue;
      }
      $nombre = trim((string)($row['nombre'] ?? ''));
      $out[] = ['id' => $id, 'label' => ($nombre !== '' ? $nombre : $id) . ' (' . $id . ')'];
    }

    return $out;
  }

  /** @return array<string,mixed>|null */
  public function getFuncionarioById(string $idEmpleado): ?array
  {
    $idEmpleado = trim($idEmpleado);
    if ($idEmpleado === '') {
      return null;
    }

    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema()->tableExists($table) || !$this->schema()->columnExists($table, 'id_empleado')) {
      return null;
    }

    return $this->db->getRow(
      "SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) = ? ORDER BY `_ID` DESC LIMIT 1",
      [$idEmpleado]
    );
  }

  /** @return array<string,mixed>|null */
  public function getContratoArrendamientoById(string $contractPk): ?array
  {
    $contractPk = trim($contractPk);
    if ($contractPk === '') {
      return null;
    }

    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema()->tableExists($table)) {
      return null;
    }

    $where = [];
    $args = [];
    if ($this->schema()->columnExists($table, '_ID')) {
      $where[] = "CAST(`_ID` AS CHAR) = ?";
      $args[] = $contractPk;
    }
    if ($this->schema()->columnExists($table, 'contrato')) {
      $where[] = "TRIM(COALESCE(`contrato`, '')) = ?";
      $args[] = $contractPk;
    }
    if (empty($where)) {
      return null;
    }

    return $this->db->getRow(
      "SELECT * FROM `{$table}` WHERE (" . implode(' OR ', $where) . ") ORDER BY `_ID` DESC LIMIT 1",
      $args
    );
  }

  /** @param array<string,mixed> $data */
  public function updateContratoArrendamiento(int $id, array $data): int
  {
    if ($id <= 0 || empty($data)) {
      return 0;
    }

    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema()->tableExists($table)) {
      return 0;
    }

    $payload = [];
    foreach ($data as $column => $value) {
      $column = trim((string) $column);
      if ($column !== '' && $this->schema()->columnExists($table, $column)) {
        $payload[$column] = $value;
      }
    }

    if (empty($payload)) {
      return 0;
    }

    return (int) $this->db->update($table, $payload, ['_ID' => $id]);
  }

  /** @return array<string,mixed>|null */
  public function getSucursalById(string $sucursalId): ?array
  {
    $sucursalId = trim($sucursalId);
    if ($sucursalId === '') {
      return null;
    }

    $table = $this->db->table('jet_cct_sucursales');
    if (!$this->schema()->tableExists($table)) {
      return null;
    }

    $where = [];
    $args = [];
    foreach (['_ID', 'id_sucursal'] as $column) {
      if ($this->schema()->columnExists($table, $column)) {
        $where[] = "CAST(`{$column}` AS CHAR) = ?";
        $args[] = $sucursalId;
      }
    }
    if (empty($where)) {
      return null;
    }

    return $this->db->getRow(
      "SELECT * FROM `{$table}` WHERE (" . implode(' OR ', $where) . ") ORDER BY `_ID` DESC LIMIT 1",
      $args
    );
  }

  /**
   * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
   */
  public function getContratosArrendamiento(array $filters, string $bucket): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema()->tableExists($table)) {
      return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 30, 'total_pages' => 1];
    }

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = (int) ($filters['per_page'] ?? 30);
    if ($perPage < 10) {
      $perPage = 10;
    }
    if ($perPage > 100) {
      $perPage = 100;
    }

    $where = [];
    $args = [];
    $bucket = strtolower(trim($bucket));
    if ($bucket === 'no_asignado') {
      $where[] = "LOWER(TRIM(COALESCE(`contrato`, ''))) = ?";
      $args[] = 'no asignado';
    } elseif (in_array($bucket, ['por_entregar', 'entregado', 'desistido', 'por_recibir'], true)) {
      $status = str_replace('_', ' ', $bucket);
      $where[] = "LOWER(TRIM(COALESCE(`estado`, ''))) = ?";
      $args[] = $status;
    } else {
      $where[] = '1=1';
    }

    $likeFilters = [
      'contrato' => ['contrato'],
      'inmueble' => ['inmueble', 'id_inmueble'],
      'direccion' => ['direccion'],
      'propietario' => ['propietario', 'id_propietario'],
      'arrendatario' => ['arrendatario', 'id_arrendatario'],
    ];
    foreach ($likeFilters as $filterKey => $columns) {
      $value = trim((string) ($filters[$filterKey] ?? ''));
      if ($value === '') {
        continue;
      }
      $parts = [];
      foreach ($columns as $column) {
        if ($this->schema()->columnExists($table, $column)) {
          $parts[] = "COALESCE(`{$column}`, '') LIKE ?";
          $args[] = '%' . $this->db->escapeLike($value) . '%';
        }
      }
      if (!empty($parts)) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    if (empty($where)) {
      $where[] = '1=1';
    }

    $whereSql = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$whereSql}", $args);
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = max(0, ($page - 1) * $perPage);

    $columns = [
      '_ID',
      'contrato',
      'estado',
      'direccion',
      'inmueble',
      'id_inmueble',
      'id_arrendatario',
      'id_propietario',
      'sucursal',
      'id_sucursal',
      'propietario',
      'arrendatario',
      'correo_propietario',
      'celular_propietario',
      'correo_arrendatario',
      'celular_arrendatario',
      'inicio_contrato',
      'fin_contrato',
      'fecha_entrega',
      'fecha',
      'ultima_revision_preventiva',
      'tuvo_preventiva',
      'id_inventario',
      'registro_fotografico',
      'barrio',
      'fecha_preventiva',
    ];
    $selectable = [];
    foreach ($columns as $column) {
      if ($this->schema()->columnExists($table, $column)) {
        $selectable[] = "`{$column}`";
      }
    }
    if (empty($selectable)) {
      $selectable[] = '`_ID`';
    }

    $rows = $this->db->getResults(
      "SELECT " . implode(', ', $selectable) . " FROM `{$table}` WHERE {$whereSql} ORDER BY `_ID` DESC LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );

    return [
      'rows' => $rows,
      'total' => $total,
      'page' => $page,
      'per_page' => $perPage,
      'total_pages' => $totalPages,
    ];
  }

  /** @return array<int,array<string,mixed>> */
  public function getContratosEntregados(array $filters): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = ["LOWER(TRIM(COALESCE(estado,'')))='entregado'"];
    $args = [];

    foreach (['inmueble', 'propietario', 'arrendatario', 'contrato'] as $k) {
      $v = trim((string)($filters[$k] ?? ''));
      if ($v === '') {
        continue;
      }
      $where[] = "COALESCE({$k},'') LIKE ?";
      $args[] = '%' . $this->db->escapeLike($v) . '%';
    }

    $columns = [
      '_ID',
      'contrato',
      'estado',
      'direccion',
      'inmueble',
      'id_inmueble',
      'id_arrendatario',
      'id_propietario',
      'sucursal',
      'id_sucursal',
      'propietario',
      'arrendatario',
      'correo_propietario',
      'celular_propietario',
      'correo_arrendatario',
      'celular_arrendatario',
      'id_inventario',
      'registro_fotografico',
      'barrio',
      'inicio_contrato',
      'fin_contrato',
      'fecha_entrega',
      'fecha',
      'ultima_revision_preventiva',
      'tuvo_preventiva',
      'ultima_revision_servicios',
      'mes_revision_servicios',
    ];
    $selectable = [];
    foreach ($columns as $column) {
      if ($this->schema()->columnExists($table, $column)) {
        $selectable[] = "`{$column}`";
      }
    }
    if (empty($selectable)) {
      $selectable[] = '`_ID`';
    }

    $sql = "SELECT " . implode(', ', $selectable) . " FROM `{$table}` WHERE " . implode(' AND ', $where) . " ORDER BY _ID DESC";
    return $this->db->getResults($sql, $args);
  }

  /**
   * Returns the most recent active preventiva ticket per contract,
   * keyed by the contract _ID (as string).
   *
   * Matches tickets where id_contrato = _ID of wp_jet_cct_contratos_arrendamiento,
   * tema_ayuda = 'Revision preventiva', and estado IN ('Nuevo','En proceso').
   *
   * @param  array<int,array<string,mixed>> $contractRows  rows from contratos table (each has _ID)
   * @return array<string,array<string,string>>            keyed by _ID string
   */
  public function getTicketsRevisionPreventiva(array $contractRows): array
  {
    if (empty($contractRows)) {
      return [];
    }

    $ids = [];
    foreach ($contractRows as $row) {
      $id = trim((string) ($row['_ID'] ?? ''));
      if ($id !== '') {
        $ids[] = $id;
      }
    }
    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
      return [];
    }

    $table        = $this->db->table('jet_cct_tickets');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT TRIM(COALESCE(id_contrato,'')) AS id_contrato, _ID AS ticket_id,"
      . " TRIM(COALESCE(estado,'')) AS estado"
      . " FROM `{$table}`"
      . " WHERE TRIM(COALESCE(id_contrato,'')) IN ({$placeholders})"
      . " AND LOWER(TRIM(COALESCE(estado,''))) IN ('nuevo','en proceso')"
      . " AND LOWER(TRIM(COALESCE(tema_ayuda,''))) = 'revision preventiva'"
      . " ORDER BY _ID DESC";

    $rows = $this->db->getResults($sql, $ids);

    $out = [];
    foreach ($rows as $row) {
      $idContrato = trim((string) ($row['id_contrato'] ?? ''));
      if ($idContrato !== '' && !isset($out[$idContrato])) {
        $out[$idContrato] = [
          'ticket_id' => (string) ($row['ticket_id'] ?? ''),
          'estado'    => (string) ($row['estado'] ?? ''),
        ];
      }
    }

    return $out;
  }

  /** @return array<string,string> */
  public function getEmpleadoByContrato(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $sql = "SELECT TRIM(COALESCE(id_contrato,'')) AS id_contrato, TRIM(COALESCE(id_empleado,'')) AS id_empleado FROM `{$table}` WHERE TRIM(COALESCE(id_contrato,''))<>'' AND TRIM(COALESCE(id_empleado,''))<>'' ORDER BY _ID DESC";
    $rows = $this->db->getResults($sql);

    $out = [];
    foreach ($rows as $row) {
      $idContrato = trim((string)($row['id_contrato'] ?? ''));
      if ($idContrato !== '' && !isset($out[$idContrato])) {
        $out[$idContrato] = trim((string)($row['id_empleado'] ?? ''));
      }
    }

    return $out;
  }

  /** @return array<int,array<string,mixed>> */
  public function getReportesAdministrativosPorVerificar(array $filters = []): array
  {
    $table = $this->db->table('jet_cct_pre_reportes_administrativos');
    $where = ['1=1'];
    $args = [];

    $estado = strtolower(trim((string) ($filters['estado'] ?? '')));
    if ($estado !== '' && $estado !== 'todos') {
      $where[] = "LOWER(TRIM(COALESCE(estado,''))) = ?";
      $args[] = $estado;
    }

    $idEmpleado = trim((string) ($filters['id_empleado'] ?? ''));
    if ($idEmpleado !== '') {
      $where[] = "TRIM(COALESCE(id_empleado,'')) = ?";
      $args[] = $idEmpleado;
    }

    foreach (['arrendatario', 'inmueble', 'categoria', 'id_ticket', 'contrato'] as $key) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value === '') {
        continue;
      }
      $where[] = "COALESCE({$key}, '') LIKE ?";
      $args[] = '%' . $this->db->escapeLike($value) . '%';
    }

    $sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where) . " ORDER BY fecha DESC, _ID DESC";
    return $this->db->getResults($sql, $args);
  }

  /** @return array<string,mixed>|null */
  public function getPreReporteAdministrativoById(int $id): ?array
  {
    if ($id <= 0) {
      return null;
    }

    $table = $this->db->table('jet_cct_pre_reportes_administrativos');
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]);
  }

  public function insertReporteAdministrativo(array $data): bool
  {
    $table = $this->db->table('jet_cct_reportes_administrativos');
    return $this->db->insert($table, $data);
  }

  public function updatePreReporteAdministrativo(int $id, array $data): int
  {
    if ($id <= 0) {
      return 0;
    }

    $table = $this->db->table('jet_cct_pre_reportes_administrativos');
    return $this->db->update($table, $data, ['_ID' => $id]);
  }

  /** @return array<string,mixed>|null */
  public function getTicketByReference(string $ticketRef): ?array
  {
    $ticketRef = trim($ticketRef);
    if ($ticketRef === '') {
      return null;
    }

    $table = $this->db->table('jet_cct_tickets');
    return $this->db->getRow(
      "SELECT * FROM `{$table}` WHERE CAST(`_ID` AS CHAR) = ? OR TRIM(COALESCE(`id_ticket`, '')) = ? ORDER BY `_ID` DESC LIMIT 1",
      [$ticketRef, $ticketRef]
    );
  }
}
