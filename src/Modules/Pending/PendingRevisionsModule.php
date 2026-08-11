<?php

namespace SCM\Modules\Pending;

use SCM\Core\Database;

final class PendingRevisionsModule
{
  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /** @return array<int,array{id:string,label:string}> */
  private function getFuncionarios(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $sql = "SELECT TRIM(COALESCE(id_empleado, '')) AS id, TRIM(COALESCE(nombre, id_empleado, '')) AS nombre, LOWER(TRIM(COALESCE(activo, 'si'))) AS activo FROM `{$table}` WHERE TRIM(COALESCE(id_empleado, '')) <> '' ORDER BY nombre ASC";
    try {
      $rows = $this->db->getResults($sql);
    } catch (\Throwable $e) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $activo = trim((string) ($row['activo'] ?? ''));
      if ($activo !== '' && !in_array($activo, ['si', 's\u00ed', '1', 'true', 'activo'], true)) {
        continue;
      }
      $nombre = trim((string) ($row['nombre'] ?? ''));
      $out[] = ['id' => $id, 'label' => ($nombre !== '' ? $nombre : $id) . ' (' . $id . ')'];
    }
    return $out;
  }

  /** @return array<string,string> */
  private function parseFilters(array $get, string $prefix): array
  {
    $clean = static fn($v): string => trim((string) ($v ?? ''));
    return [
      'mes' => $clean($get[$prefix . 'mes'] ?? ''),
      'inmueble' => $clean($get[$prefix . 'inmueble'] ?? ''),
      'propietario' => $clean($get[$prefix . 'propietario'] ?? ''),
      'arrendatario' => $clean($get[$prefix . 'arrendatario'] ?? ''),
      'contrato' => $clean($get[$prefix . 'contrato'] ?? ''),
      'empleado' => $clean($get[$prefix . 'id_empleado'] ?? ''),
    ];
  }

  private function parseTs($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function formatDate(int $ts): string
  {
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }

  private function addMonths(int $ts, int $months): int
  {
    if ($ts <= 0 || $months <= 0) {
      return 0;
    }
    try {
      $dt = new \DateTime('@' . $ts);
      $dt->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
      $dt->modify('+' . $months . ' months');
      return (int) $dt->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /** @return array<int,array<string,mixed>> */
  private function fetchContracts(array $filters): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = ["LOWER(TRIM(COALESCE(estado, ''))) = 'entregado'"];
    $args = [];

    foreach (['inmueble', 'propietario', 'arrendatario', 'contrato'] as $f) {
      if ($filters[$f] !== '') {
        $where[] = "COALESCE({$f}, '') LIKE ?";
        $args[] = '%' . $this->db->escapeLike($filters[$f]) . '%';
      }
    }

    $sql = "SELECT _ID, contrato, direccion, inmueble, id_arrendatario, id_propietario, sucursal, propietario, arrendatario, inicio_contrato, fin_contrato, fecha_entrega, fecha, ultima_revision_preventiva, tuvo_preventiva, ultima_revision_servicios, mes_revision_servicios FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY _ID DESC';
    try {
      return $this->db->getResults($sql, $args);
    } catch (\Throwable $e) {
      return [];
    }
  }

  /** @return array<string,mixed> */
  private function fetchEmployeeByTicket(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $sql = "SELECT TRIM(COALESCE(id_contrato, '')) AS id_contrato, TRIM(COALESCE(id_empleado, '')) AS id_empleado FROM `{$table}` WHERE TRIM(COALESCE(id_contrato, '')) <> '' AND TRIM(COALESCE(id_empleado, '')) <> '' ORDER BY _ID DESC";
    try {
      $rows = $this->db->getResults($sql);
    } catch (\Throwable $e) {
      return [];
    }

    $map = [];
    foreach ($rows as $row) {
      $cid = trim((string) ($row['id_contrato'] ?? ''));
      if ($cid === '' || isset($map[$cid])) {
        continue;
      }
      $map[$cid] = trim((string) ($row['id_empleado'] ?? ''));
    }
    return $map;
  }

  /** @return array<string,mixed> */
  public function buildPreventivas(array $get): array
  {
    $filters = $this->parseFilters($get, 'spp_');
    $funcionarios = $this->getFuncionarios();
    $rows = $this->fetchContracts($filters);
    $employeesByContract = $this->fetchEmployeeByTicket();
    $year = (int) date('Y');
    $items = [];

    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? ''));
      $employeeId = trim((string) ($employeesByContract[$contractId] ?? $employeesByContract[$contractCode] ?? ''));
      if ($filters['empleado'] !== '' && $employeeId !== $filters['empleado']) {
        continue;
      }
      $ultima = $this->parseTs($row['ultima_revision_preventiva'] ?? null);
      if ($ultima > 0 && (int) date('Y', $ultima) === $year) {
        continue;
      }
      $base = $this->parseTs($row['fecha_entrega'] ?? null);
      if ($base <= 0) {
        $base = $this->parseTs($row['inicio_contrato'] ?? null);
      }
      if ($base <= 0) {
        $base = $this->parseTs($row['fecha'] ?? null);
      }
      $due = $ultima > 0 ? $this->addMonths($ultima, 12) : ($base > 0 ? mktime(0, 0, 0, (int) date('n', $base), (int) date('j', $base), $year) : 0);
      $month = $due > 0 ? (int) date('n', $due) : 0;
      if ($filters['mes'] !== '' && (int) $filters['mes'] > 0 && (int) $filters['mes'] !== $month) {
        continue;
      }
      $link = 'https://sucasainmobiliaria.com.co/ticket/?id_contrato=' . rawurlencode($contractId);
      if ($employeeId !== '') {
        $link .= '&id_empleado=' . rawurlencode($employeeId);
      }
      $items[] = ['row' => $row, 'ultima' => $ultima, 'due' => $due, 'empleado' => $employeeId, 'link' => $link];
    }

    usort($items, static fn($a, $b): int => ($a['ultima'] <=> $b['ultima']));

    return ['filters' => $filters, 'funcionarios' => $funcionarios, 'items' => $items, 'count' => count($items), 'year' => $year];
  }

  /** @return array<string,mixed> */
  public function buildServiciosPublicos(array $get): array
  {
    $filters = $this->parseFilters($get, 'rsp_');
    $funcionarios = $this->getFuncionarios();
    $rows = $this->fetchContracts($filters);
    $employeesByContract = $this->fetchEmployeeByTicket();
    $items = [];
    $now = time();

    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      $contractCode = trim((string) ($row['contrato'] ?? ''));
      $employeeId = trim((string) ($employeesByContract[$contractId] ?? $employeesByContract[$contractCode] ?? ''));
      if ($filters['empleado'] !== '' && $employeeId !== $filters['empleado']) {
        continue;
      }
      $ultima = $this->parseTs($row['ultima_revision_servicios'] ?? null);
      $due = $ultima > 0 ? $this->addMonths($ultima, 3) : 0;
      if ($due > $now) {
        continue;
      }
      $month = $due > 0 ? (int) date('n', $due) : (int) ($row['mes_revision_servicios'] ?? 0);
      if ($filters['mes'] !== '' && (int) $filters['mes'] > 0 && (int) $filters['mes'] !== $month) {
        continue;
      }
      $link = 'https://sucasainmobiliaria.com.co/revision-de-servicios-publicos/?id_contrato=' . rawurlencode($contractId);
      if ($employeeId !== '') {
        $link .= '&id_empleado=' . rawurlencode($employeeId);
      }
      $items[] = ['row' => $row, 'ultima' => $ultima, 'due' => $due, 'empleado' => $employeeId, 'link' => $link];
    }

    usort($items, static fn($a, $b): int => ($a['due'] <=> $b['due']));

    return ['filters' => $filters, 'funcionarios' => $funcionarios, 'items' => $items, 'count' => count($items)];
  }

  public function renderTable(array $items, string $actionLabel): string
  {
    if (empty($items)) {
      return '<div class="scm-empty"><p>No hay registros pendientes con esos filtros.</p></div>';
    }

    $html = '<div class="scm-table-wrap"><table class="scm-table"><thead><tr><th>Contrato</th><th>Inmueble</th><th>Direccion</th><th>Propietario</th><th>Arrendatario</th><th>Ultima revision</th><th>Proxima revision</th><th>Funcionario</th><th>Accion</th></tr></thead><tbody>';
    foreach ($items as $item) {
      $row = $item['row'];
      $html .= '<tr>';
      $html .= '<td>' . esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['inmueble'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['propietario'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html($this->formatDate((int) ($item['ultima'] ?? 0))) . '</td>';
      $html .= '<td>' . esc_html($this->formatDate((int) ($item['due'] ?? 0))) . '</td>';
      $html .= '<td>' . esc_html((string) (($item['empleado'] ?? '') !== '' ? $item['empleado'] : '-')) . '</td>';
      $html .= '<td><a class="scm-btn-primary btn btn-primary btn-sm" href="' . esc_url((string) ($item['link'] ?? '#')) . '" target="_blank" rel="noopener noreferrer">' . esc_html($actionLabel) . '</a></td>';
      $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
  }

  /** @param array<int,array{id:string,label:string}> $funcionarios */
  public function renderFilters(string $prefix, array $filters, array $funcionarios): string
  {
    $mes = (int) ($filters['mes'] ?? 0);
    ob_start();
    ?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form method="get" autocomplete="off">
        <div class="scm-grid">
          <div class="scm-field"><label>Mes</label><select class="select select-bordered select-sm scm-select" name="<?php echo esc_attr($prefix . 'mes'); ?>"><option value="">Todos</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo esc_attr((string) $m); ?>" <?php selected($mes, $m); ?>><?php echo esc_html((string) $m); ?></option><?php endfor; ?></select></div>
          <div class="scm-field"><label>Funcionario</label><select class="select select-bordered select-sm scm-select" name="<?php echo esc_attr($prefix . 'id_empleado'); ?>"><option value="">Todos</option><?php foreach ($funcionarios as $func): ?><option value="<?php echo esc_attr($func['id']); ?>" <?php selected((string) ($filters['empleado'] ?? ''), (string) $func['id']); ?>><?php echo esc_html($func['label']); ?></option><?php endforeach; ?></select></div>
          <div class="scm-field"><label>Inmueble</label><input class="input input-bordered input-sm scm-input" type="text" name="<?php echo esc_attr($prefix . 'inmueble'); ?>" value="<?php echo esc_attr((string) ($filters['inmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label>Propietario</label><input class="input input-bordered input-sm scm-input" type="text" name="<?php echo esc_attr($prefix . 'propietario'); ?>" value="<?php echo esc_attr((string) ($filters['propietario'] ?? '')); ?>"></div>
          <div class="scm-field"><label>Arrendatario</label><input class="input input-bordered input-sm scm-input" type="text" name="<?php echo esc_attr($prefix . 'arrendatario'); ?>" value="<?php echo esc_attr((string) ($filters['arrendatario'] ?? '')); ?>"></div>
          <div class="scm-field"><label>Contrato</label><input class="input input-bordered input-sm scm-input" type="text" name="<?php echo esc_attr($prefix . 'contrato'); ?>" value="<?php echo esc_attr((string) ($filters['contrato'] ?? '')); ?>"></div>
        </div>
        <div class="scm-actions"><button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button></div>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
  }
}
