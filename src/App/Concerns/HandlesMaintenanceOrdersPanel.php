<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\MaintenanceOrders\MaintenanceOrdersView;

/** Listado de órdenes de mantenimiento dentro de Actividades administrativas › Mantenimiento. */
trait HandlesMaintenanceOrdersPanel
{
  private function renderMaintenanceOrdersPanel(): string
  {
    return (new MaintenanceOrdersView())->renderPanel(
      $this->canUseDashboardAction('quote_order_create')
    );
  }

  private function maintenance_orders_can_delete(): bool
  {
    return ($this->canUseDashboardAction('quote_order_delete') || $this->canManageDashboardPermissions())
      && $this->canAccessDashboardTab('ordenes_mantenimiento');
  }

  public function ajax_handler_maintenance_orders_list(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('ordenes_mantenimiento')) {
      $this->jsonFail('No tienes permiso para ver órdenes de mantenimiento.');
    }
    $table = $this->db->table('jet_cct_ordenes');
    if (!$this->table_exists($table)) {
      $this->jsonFail('La tabla de órdenes no está disponible.');
    }

    $scope = sanitize_key((string) ($_POST['scope'] ?? 'pendientes'));
    if (!in_array($scope, ['pendientes', 'aprobadas', 'desaprobadas'], true)) {
      $scope = 'pendientes';
    }
    $text = fn(string $key): string => trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($_POST['per_page'] ?? 20)));

    $where = ["LOWER(TRIM(COALESCE(o.`cct_status`, ''))) NOT IN ('trash', 'deleted')"];
    $args = [];
    $search = $text('q');
    if ($search !== '') {
      $like = '%' . $search . '%';
      $searchable = ['_ID', 'id_cotizacion', 'id_ticket', 'contrato', 'id_contrato', 'inmueble', 'id_inmueble', 'proveedor', 'direccion', 'concepto'];
      $where[] = '(' . implode(' OR ', array_map(static fn(string $column): string => "CAST(o.`{$column}` AS CHAR) LIKE ?", $searchable)) . ')';
      $args = array_merge($args, array_fill(0, count($searchable), $like));
    }
    $category = $this->maintenance_order_category($text('categoria'));
    if ($category !== '') {
      $where[] = 'TRIM(COALESCE(o.`categoria`, \'\')) = ?';
      $args[] = $category;
    }
    $creator = $text('creador');
    if ($creator !== '') {
      $where[] = 'o.`creador` LIKE ?';
      $args[] = '%' . $creator . '%';
    }
    foreach (['desde' => '>=', 'hasta' => '<='] as $field => $operator) {
      $date = $text($field);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        $where[] = "DATE(o.`cct_created`) {$operator} ?";
        $args[] = $date;
      }
    }

    $stateSql = "LOWER(TRIM(COALESCE(o.`estado`, '')))";
    $scopeConditions = [
      'pendientes' => "{$stateSql} IN ('', 'esperando respuesta')",
      'aprobadas' => "{$stateSql} = 'aprobada'",
      'desaprobadas' => "{$stateSql} = 'desaprobada'",
    ];
    $baseWhere = implode(' AND ', $where);
    $countsRow = $this->db->getRow(
      "SELECT SUM(CASE WHEN {$scopeConditions['pendientes']} THEN 1 ELSE 0 END) AS pendientes,
              SUM(CASE WHEN {$scopeConditions['aprobadas']} THEN 1 ELSE 0 END) AS aprobadas,
              SUM(CASE WHEN {$scopeConditions['desaprobadas']} THEN 1 ELSE 0 END) AS desaprobadas
         FROM `{$table}` o WHERE {$baseWhere}",
      $args
    ) ?? [];
    $counts = [];
    foreach (array_keys($scopeConditions) as $key) {
      $counts[$key] = (int) ($countsRow[$key] ?? 0);
    }

    $scopeWhere = $baseWhere . ' AND ' . $scopeConditions[$scope];
    $total = $counts[$scope];
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = $this->db->getResults(
      "SELECT o.* FROM `{$table}` o WHERE {$scopeWhere} ORDER BY o.`_ID` DESC LIMIT {$perPage} OFFSET {$offset}",
      $args
    );
    $sumValue = 0.0;
    foreach ($this->db->getResults("SELECT o.`valor` FROM `{$table}` o WHERE {$scopeWhere}", $args) as $valueRow) {
      $sumValue += $this->maintenance_order_money_value($valueRow['valor'] ?? 0);
    }

    $canRespond = $this->maintenance_order_can_respond();
    $canDelete = $this->maintenance_orders_can_delete();
    $prepared = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $dateTs = (int) ($row['fecha'] ?? 0);
      if ($dateTs <= 0) {
        $dateTs = strtotime((string) ($row['cct_created'] ?? '')) ?: 0;
      }
      $row['_fecha_label'] = $dateTs > 0 ? date('d/m/Y', $dateTs) : '-';
      $row['_hora_label'] = $dateTs > 0 ? date('g:i a', $dateTs) : '';
      $row['_valor_label'] = $this->format_cop_currency($row['valor'] ?? 0);
      $prepared[] = $row;
    }

    $view = new MaintenanceOrdersView();
    $this->jsonOk([
      'scope' => $scope,
      'counts' => $counts,
      'total' => $total,
      'total_value' => $this->format_cop_currency($sumValue),
      'table_html' => $view->renderTable($prepared, $scope, $canRespond, $canDelete),
      'pagination_html' => $view->renderPagination(['page' => $page, 'total_pages' => $totalPages, 'total' => $total]),
    ]);
  }

  public function ajax_handler_maintenance_orders_detail(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('ordenes_mantenimiento') || !$this->maintenance_order_can_view()) {
      $this->jsonFail('No tienes permiso para ver esta orden.');
    }
    $orderId = (int) ($_POST['id_orden'] ?? 0);
    $order = $this->public_cotizacion_order($orderId);
    if (!is_array($order) || in_array(strtolower(trim((string) ($order['cct_status'] ?? ''))), ['trash', 'deleted'], true)) {
      $this->jsonFail('Orden no encontrada.');
    }
    $quoteId = (int) ($order['id_cotizacion'] ?? 0);
    $quote = $quoteId > 0 ? $this->maintenance_order_find_cotizacion($quoteId) : null;
    $this->jsonOk([
      'id_orden' => (string) $orderId,
      'html' => $this->render_cotizacion_order_detail($order, $quote ?? []),
    ]);
  }

  public function ajax_handler_maintenance_orders_delete(): void
  {
    $this->verifyCsrf();
    if (!$this->maintenance_orders_can_delete()) {
      $this->jsonFail('No tienes permiso para eliminar órdenes de mantenimiento.');
    }
    $orderId = (int) ($_POST['id_orden'] ?? 0);
    $reason = $this->maintenance_order_clean($_POST['motivo'] ?? '');
    if ($orderId <= 0) {
      $this->jsonFail('Orden inválida.');
    }
    if ($reason === '') {
      $this->jsonFail('Escribe el motivo de la eliminación.');
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $ordersTable = $this->db->table('jet_cct_ordenes');
    $quoteTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$schema->tableExists($ordersTable)) {
      $this->jsonFail('La tabla de órdenes no está disponible.');
    }

    $restored = '';
    $pdo = $this->db->pdo();
    try {
      $pdo->beginTransaction();
      $order =$this->db->getRow("SELECT * FROM `{$ordersTable}` WHERE `_ID` = ? FOR UPDATE", [$orderId]);
      if (!is_array($order) || in_array(strtolower(trim((string) ($order['cct_status'] ?? ''))), ['trash', 'deleted'], true)) {
        throw new \DomainException('La orden no existe o ya fue eliminada.');
      }
      if (!hash_equals((string) ($order['cct_modified'] ?? ''), (string) ($_POST['order_version'] ?? ''))) {
        throw new \DomainException('La orden cambió mientras la revisabas. Actualiza el listado e inténtalo de nuevo.');
      }

      $now = time();
      $nowMysql = date('Y-m-d H:i:s', max($now, (strtotime((string) ($order['cct_modified'] ?? '')) ?: 0) + 1));
      $this->db->update($ordersTable, $schema->filterTableData($ordersTable, [
        'cct_status' => 'trash',
        'cct_modified' => $nowMysql,
      ]), ['_ID' => $orderId]);

      // La orden reservaba saldo de su categoría; al eliminarla se devuelve a la cotización.
      $quoteId = (int) ($order['id_cotizacion'] ?? 0);
      $category = $this->maintenance_order_category((string) ($order['categoria'] ?? ''));
      $value = $this->maintenance_order_money_value($order['valor'] ?? 0);
      if ($quoteId > 0 && $category !== '' && $value > 0 && $schema->tableExists($quoteTable)) {
        $quote = $this->db->getRow("SELECT * FROM `{$quoteTable}` WHERE `_ID` = ? FOR UPDATE", [$quoteId]);
        if (is_array($quote)) {
          $balanceField = $this->maintenance_order_balance_column($category);
          $totalField = ['saldo_obra' => 'total_mano_obra', 'saldo_materiales' => 'total_materiales', 'saldo_maquinarias' => 'total_maquinarias', 'saldo_otros_costo' => 'total_otros_costos'][$balanceField];
          $balance = $this->maintenance_order_money_value($quote[$balanceField] ?? 0) + $value;
          $categoryTotal = $this->maintenance_order_money_value($quote[$totalField] ?? 0);
          if ($categoryTotal > 0) {
            $balance = min($balance, $categoryTotal);
          }
          $this->db->update($quoteTable, $schema->filterTableData($quoteTable, [
            $balanceField => (string) (int) round($balance),
            'cct_modified' => $nowMysql,
          ]), ['_ID' => $quoteId]);
          $restored = ' Se devolvieron ' . $this->format_cop_currency($value) . ' al saldo de ' . strtolower($category) . ' de la cotización #' . $quoteId . '.';
        }
      }

      $user = $this->maintenance_order_current_user_payload();
      $employeeId = trim((string) $this->current_employee_id());
      if ($employeeId === '') {
        $employeeId = (string) Auth::userId();
      }
      $detail = 'Se ha eliminado la orden de ' . strtolower($category !== '' ? $category : 'mantenimiento') . ' #' . $orderId
        . ' por ' . $this->format_cop_currency($value) . ' (estado anterior: ' . (trim((string) ($order['estado'] ?? '')) ?: 'Esperando respuesta') . '). Motivo: ' . $reason . '.' . $restored;
      $this->maintenance_orders_insert_histories($schema, $order, $detail, $user, $employeeId, $now, $nowMysql);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[orden_mantenimiento_delete] ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo eliminar la orden de mantenimiento.');
    }

    $this->jsonOk([
      'message' => 'Orden #' . $orderId . ' eliminada.' . $restored,
      'id_orden' => (string) $orderId,
    ]);
  }

  /** Cotizaciones aprobadas con saldo disponible para crear una orden desde el panel. */
  public function ajax_handler_maintenance_orders_quotes(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('ordenes_mantenimiento') || !$this->maintenance_order_can_manage()) {
      $this->jsonFail('No tienes permiso para crear órdenes de mantenimiento.');
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($table)) {
      $this->jsonFail('La tabla de cotizaciones no está disponible.');
    }
    $where = ["LOWER(TRIM(COALESCE(`estado`, ''))) = 'aprobada'"];
    $args = [];
    $search = trim(sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))));
    if ($search !== '') {
      $searchable = ['_ID', 'id_ticket', 'contrato', 'id_contrato', 'inmueble', 'id_inmueble', 'direccion', 'destinatario'];
      $where[] = '(' . implode(' OR ', array_map(static fn(string $column): string => "CAST(`{$column}` AS CHAR) LIKE ?", $searchable)) . ')';
      $args = array_fill(0, count($searchable), '%' . $search . '%');
    }
    $rows = $this->db->getResults(
      'SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY `_ID` DESC LIMIT 200',
      $args
    );
    $quotes = [];
    foreach ($rows as $row) {
      $context = $this->maintenance_order_context_payload((array) $row);
      $available = 0.0;
      $balances = [];
      foreach ((array) $context['balances'] as $label => $balance) {
        $amount = (float) ($balance['value'] ?? 0);
        $available += $amount;
        if ($amount > 0) {
          $balances[] = ['label' => (string) $label, 'value' => (string) ($balance['label'] ?? '')];
        }
      }
      if ($available <= 0) {
        continue;
      }
      $quotes[] = [
        'id' => $context['id'],
        'ticket' => $context['ticket_pk'],
        'contrato' => $context['contrato'],
        'inmueble' => $context['inmueble'],
        'direccion' => $context['direccion'],
        'destinatario' => $context['destinatario'],
        'available' => $this->format_cop_currency($available),
        'balances' => $balances,
      ];
      if (count($quotes) >= 25) {
        break;
      }
    }
    $this->jsonOk(['quotes' => $quotes]);
  }

  /** @param array<string,mixed> $order @param array<string,string> $user */
  private function maintenance_orders_insert_histories(\SCM\Support\SchemaInspector $schema, array $order, string $detail, array $user, string $employeeId, int $now, string $nowMysql): void
  {
    $orderId = trim((string) ($order['_ID'] ?? ''));
    $ticketRef = trim((string) ($order['id_ticket'] ?? ''));
    $propertyRef = $this->maintenance_order_first([$order['id_inmueble'] ?? '', $order['inmueble'] ?? '']);
    $common = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_ticket' => $ticketRef,
      'fecha' => $now,
      'id_empleado' => $employeeId,
    ];
    $ticketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($ticketRef !== '' && $schema->tableExists($ticketTable)) {
      $payload = $schema->filterTableData($ticketTable, $common + [
        'nombre' => $user['nombre'],
        'correo' => $user['email'],
        'celular' => $user['celular'],
        'respuesta' => $detail,
        'respuesta_cct_ticket' => $detail,
        'id_cotizacion_mantenimiento' => trim((string) ($order['id_cotizacion'] ?? '')),
        'id_orden' => $orderId,
        'fue_editada' => 'Si',
      ]);
      if (!empty($payload)) {
        $this->db->insert($ticketTable, $payload);
      }
    }
    $propertyTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($propertyRef !== '' && $schema->tableExists($propertyTable)) {
      $payload = $schema->filterTableData($propertyTable, $common + [
        'id_inmueble' => $propertyRef,
        'id_inmueble_data' => $propertyRef,
        'tipo_reporte' => 'Mantenimiento',
        'observacion' => $detail,
        'observacion_his' => $detail,
        'funcionario' => $user['nombre'],
      ]);
      if (!empty($payload)) {
        $this->db->insert($propertyTable, $payload);
      }
    }
  }
}
