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
   * @return array<string,array<string,mixed>>             keyed by _ID string
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

    $select = [
      "TRIM(COALESCE(id_contrato,'')) AS id_contrato",
      "_ID AS ticket_id",
    ];
    foreach ([
      'id_ticket',
      'estado',
      'estado_administrativo',
      'prioridad',
      'tema_ayuda',
      'asunto',
      'descripcion',
      'contrato',
      'id_inmueble',
      'inmueble',
      'barrio',
      'direccion',
      'id_empleado',
      'empleado',
      'nombre_empleado',
      'id_propietario',
      'propietario',
      'correo_propietario',
      'celular_propietario',
      'indicativo_propietario',
      'id_arrendatario',
      'arrendatario',
      'correo_arrendatario',
      'celular_arrendatario',
      'indicativo_arrendatario',
      'solicitante',
      'creador_por',
      'fecha',
      'fecha_actualizacion',
      'cct_created',
      'id_revision_preventiva',
      'id_revision_correctiva',
      'id_cotizacion_mantenimiento',
      'estado_cotizacion_mantenimiento',
      'magnitud_caso',
      'perturbacion',
      'justificacion_perturbacion',
      'valor_bonificacion',
      'area_afectada',
      'resumen_calculo_perturbacion',
      'archivos',
      'acta_revision_preventiva_arrendatario',
      'acta_revision_preventiva_propietario',
    ] as $column) {
      if ($this->schema()->columnExists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }

    $sql = "SELECT " . implode(', ', $select)
      . " FROM `{$table}`"
      . " WHERE TRIM(COALESCE(id_contrato,'')) IN ({$placeholders})"
      . " AND LOWER(TRIM(COALESCE(estado,''))) IN ('nuevo','en proceso')"
      . " AND LOWER(TRIM(COALESCE(tema_ayuda,''))) = 'revision preventiva'"
      . " ORDER BY _ID DESC";

    $rows = $this->db->getResults($sql, $ids);
    try {
      $rows = $this->enrichPreventivaTicketRows($rows);
    } catch (\Throwable $exception) {
      error_log('[pending.preventiva.enrich] ' . $exception->getMessage());
    }

    $out = [];
    foreach ($rows as $row) {
      $idContrato = trim((string) ($row['id_contrato'] ?? ''));
      if ($idContrato !== '' && !isset($out[$idContrato])) {
        $out[$idContrato] = $row;
      }
    }

    return $out;
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
  private function enrichPreventivaTicketRows(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $ticketKeys = [];
    $contractIds = [];
    $propertyIds = [];
    foreach ($rows as $row) {
      foreach ([$row['ticket_id'] ?? '', $row['id_ticket'] ?? ''] as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
          $ticketKeys[$key] = true;
        }
      }
      foreach ([$row['id_contrato'] ?? '', $row['contrato'] ?? ''] as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
          $contractIds[$id] = true;
        }
      }
      foreach ([$row['id_inmueble'] ?? '', $row['inmueble'] ?? ''] as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
          $propertyIds[$id] = true;
        }
      }
    }

    $segByTicket = $this->fetchPendingRowsGrouped(
      $this->db->table('jet_cct_seguimiento_ticket'),
      'id_ticket',
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified', 'id_coordinador', 'id_empleado', 'evidencia', 'archivos']
    );
    $notesByTicket = $this->fetchPendingRowsGrouped(
      $this->db->table('jet_cct_notas_ticket'),
      'id_ticket',
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'id_empleado', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified']
    );
    $contractById = $this->fetchPendingSingleRows(
      $this->db->table('jet_cct_contratos_arrendamiento'),
      ['_ID', 'contrato'],
      array_keys($contractIds)
    );
    $propertyById = $this->fetchPendingSingleRows(
      $this->db->table('jet_cct_inmuebles'),
      ['codigo', '_ID', 'id_inmueble'],
      array_keys($propertyIds)
    );
    $histByProperty = $this->fetchPendingRowsGroupedByColumns(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_inmueble', 'id_inmueble_data'],
      array_keys($propertyIds),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id', 'archivos', 'imagen']
    );
    $histByTicket = $this->fetchPendingRowsGrouped(
      $this->db->table('jet_cct_historial_del_inmueble'),
      'id_ticket',
      array_keys($ticketKeys),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id', 'archivos', 'imagen']
    );

    foreach ($rows as &$row) {
      $row['_scm_seguimientos_ticket'] = [];
      $row['_scm_notas_ticket'] = [];
      $row['_scm_historial_items'] = [];
      $row['_scm_historial_inmueble'] = [];
      $row['_scm_contrato_data'] = [];
      $row['_scm_inmueble_data'] = [];

      foreach ([$row['ticket_id'] ?? '', $row['id_ticket'] ?? ''] as $key) {
        $key = trim((string) $key);
        if ($key === '') {
          continue;
        }
        if (!empty($segByTicket[$key])) {
          $row['_scm_seguimientos_ticket'] = array_merge($row['_scm_seguimientos_ticket'], $segByTicket[$key]);
        }
        if (!empty($notesByTicket[$key])) {
          $row['_scm_notas_ticket'] = array_merge($row['_scm_notas_ticket'], $notesByTicket[$key]);
        }
        if (!empty($histByTicket[$key])) {
          $row['_scm_historial_inmueble'] = array_merge($row['_scm_historial_inmueble'], $histByTicket[$key]);
        }
      }

      $contractId = trim((string) ($row['id_contrato'] ?? $row['contrato'] ?? ''));
      if ($contractId !== '' && isset($contractById[$contractId])) {
        $row['_scm_contrato_data'] = $contractById[$contractId];
      }

      $propertyId = '';
      foreach ([$row['inmueble'] ?? '', $row['id_inmueble'] ?? '', $row['_scm_contrato_data']['inmueble'] ?? '', $row['_scm_contrato_data']['id_inmueble'] ?? ''] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && isset($propertyById[$candidate])) {
          $propertyId = $candidate;
          break;
        }
      }
      if ($propertyId === '') {
        $propertyId = trim((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''));
      }
      if ($propertyId !== '' && isset($propertyById[$propertyId])) {
        $row['_scm_inmueble_data'] = $propertyById[$propertyId];
      }
      if ($propertyId !== '' && !empty($histByProperty[$propertyId])) {
        $row['_scm_historial_inmueble'] = array_merge($row['_scm_historial_inmueble'], $histByProperty[$propertyId]);
      }

      $created = trim((string) ($row['cct_created'] ?? $row['fecha'] ?? ''));
      $row['_scm_historial_items'][] = [
        'nombre' => trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? $row['id_empleado'] ?? 'Sistema')),
        'fecha' => $created,
        'observacion' => 'Ticket preventivo creado.',
        'tipo_reporte' => 'Creaci&oacute;n',
      ];

      $row['_scm_seguimientos_ticket'] = $this->sortPendingRowsByDate($this->uniquePendingRows($row['_scm_seguimientos_ticket']));
      $row['_scm_notas_ticket'] = $this->sortPendingRowsByDate($this->uniquePendingRows($row['_scm_notas_ticket']));
      $row['_scm_historial_items'] = $this->sortPendingRowsByDate($this->uniquePendingRows($row['_scm_historial_items']));
      $row['_scm_historial_inmueble'] = $this->sortPendingRowsByDate($this->uniquePendingRows($row['_scm_historial_inmueble']));
    }
    unset($row);

    return $rows;
  }

  /** @param array<int,string> $keys @param array<int,string> $wantedColumns @return array<string,array<int,array<string,mixed>>> */
  private function fetchPendingRowsGrouped(string $table, string $keyColumn, array $keys, array $wantedColumns): array
  {
    return $this->fetchPendingRowsGroupedByColumns($table, [$keyColumn], $keys, $wantedColumns);
  }

  /** @param array<int,string> $keyColumns @param array<int,string> $keys @param array<int,string> $wantedColumns @return array<string,array<int,array<string,mixed>>> */
  private function fetchPendingRowsGroupedByColumns(string $table, array $keyColumns, array $keys, array $wantedColumns): array
  {
    $keys = array_values(array_filter(array_unique(array_map('strval', $keys)), static fn($v): bool => trim($v) !== ''));
    if (empty($keys) || !$this->schema()->tableExists($table)) {
      return [];
    }
    $existingKeyColumns = array_values(array_filter($keyColumns, fn(string $column): bool => $this->schema()->columnExists($table, $column)));
    if (empty($existingKeyColumns)) {
      return [];
    }
    $columns = $this->pendingSelectableColumns($table, array_values(array_unique(array_merge($existingKeyColumns, $wantedColumns))));
    $where = [];
    $args = [];
    foreach ($existingKeyColumns as $column) {
      $where[] = "BINARY TRIM(COALESCE(`{$column}`,'')) IN (" . implode(',', array_fill(0, count($keys), '?')) . ")";
      foreach ($keys as $key) {
        $args[] = $key;
      }
    }
    $rows = $this->db->getResults(
      "SELECT " . implode(', ', $columns) . " FROM `{$table}` WHERE " . implode(' OR ', $where) . " ORDER BY `_ID` DESC",
      $args
    );
    $out = [];
    foreach ($rows as $row) {
      foreach ($existingKeyColumns as $column) {
        $key = trim((string) ($row[$column] ?? ''));
        if ($key === '') {
          continue;
        }
        $out[$key][] = $row;
      }
    }
    return $out;
  }

  /** @param array<int,string> $idColumns @param array<int,string> $ids @return array<string,array<string,mixed>> */
  private function fetchPendingSingleRows(string $table, array $idColumns, array $ids): array
  {
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn($v): bool => trim($v) !== ''));
    if (empty($ids) || !$this->schema()->tableExists($table)) {
      return [];
    }
    $where = [];
    $args = [];
    foreach ($idColumns as $column) {
      if (!$this->schema()->columnExists($table, $column)) {
        continue;
      }
      $where[] = "BINARY TRIM(COALESCE(`{$column}`,'')) IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }
    if (empty($where)) {
      return [];
    }
    $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " ORDER BY `_ID` DESC", $args);
    $out = [];
    foreach ($rows as $row) {
      foreach ($idColumns as $column) {
        $id = trim((string) ($row[$column] ?? ''));
        if ($id !== '' && !isset($out[$id])) {
          $out[$id] = $row;
        }
      }
    }
    return $out;
  }

  /** @param array<int,string> $columns @return array<int,string> */
  private function pendingSelectableColumns(string $table, array $columns): array
  {
    $select = [];
    foreach ($columns as $column) {
      if ($this->schema()->columnExists($table, $column)) {
        $select[] = "`{$column}`";
      }
    }
    return !empty($select) ? $select : ['`_ID`'];
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
  private function uniquePendingRows(array $rows): array
  {
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $key = trim((string) ($row['_ID'] ?? ''));
      if ($key === '') {
        $key = md5(json_encode($row) ?: serialize($row));
      }
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $row;
    }
    return $out;
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
  private function sortPendingRowsByDate(array $rows): array
  {
    usort($rows, function (array $a, array $b): int {
      return $this->pendingRowTs($b) <=> $this->pendingRowTs($a);
    });
    return $rows;
  }

  /** @param array<string,mixed> $row */
  private function pendingRowTs(array $row): int
  {
    foreach (['fecha', 'cct_created', 'cct_modified'] as $key) {
      $value = $row[$key] ?? '';
      if (is_numeric($value)) {
        return (int) $value;
      }
      $ts = strtotime((string) $value);
      if ($ts !== false) {
        return (int) $ts;
      }
    }
    return 0;
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
