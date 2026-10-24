<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';

/** Fake DB: returns rows per table and records the SQL used to select them. */
final class DueOrdersAnnexesDb
{
  /** @var array<string,string> */
  public array $sql = [];

  /** @param array<string,array<int,array<string,mixed>>> $rows */
  public function __construct(private array $rows) {}

  public function table(string $name): string
  {
    return 'wp_' . $name;
  }

  public function getResults(string $sql, array $args = []): array
  {
    foreach ($this->rows as $table => $rows) {
      if (str_contains($sql, '`' . $table . '`')) {
        $this->sql[$table] = $sql;
        return $rows;
      }
    }
    return [];
  }
}

final class DueOrdersAnnexesProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;

  public function __construct(public DueOrdersAnnexesDb $db) {}

  public function orders(array $settings, int $fromTs, int $toTs): array
  {
    return $this->adminDueMaintenanceOrderItems($settings, $fromTs, $toTs);
  }

  public function annexes(array $settings, int $fromTs, int $toTs): array
  {
    return $this->adminDueLeaseAnnexItems($settings, $fromTs, $toTs);
  }

  private function table_exists(string $table): bool
  {
    return true;
  }

  private function parse_unix_ts($value): int
  {
    $value = trim((string) $value);
    if ($value === '') {
      return 0;
    }
    return ctype_digit($value) ? (int) $value : (int) (strtotime($value) ?: 0);
  }
}

$db = new DueOrdersAnnexesDb([
  'wp_jet_cct_ordenes' => [
    ['_ID' => '501', 'id_ticket' => '1200', 'estado' => 'Esperando respuesta', 'fecha' => (string) strtotime('today -6 days')],
    ['_ID' => '502', 'id_ticket' => '', 'estado' => '', 'fecha' => '', 'cct_created' => date('Y-m-d H:i:s', strtotime('today'))],
  ],
  'wp_jet_cct_contratos_arrendamiento' => [
    ['_ID' => '77', 'contrato' => '4410', 'direccion' => 'Calle 10 # 5-20', 'estado' => 'Entregado', 'se_envio' => 'No', 'fecha_entrega' => date('Y-m-d', strtotime('today -1 day'))],
  ],
]);
$probe = new DueOrdersAnnexesProbe($db);
$fromTs = strtotime('today -1 day');
$toTs = strtotime('today +15 days');
$settings = ['ordenes_sin_respuesta_dias' => 3, 'contratos_anexos_sin_enviar_dias' => 4];

$orders = array_column($probe->orders($settings, $fromTs, $toTs), null, 'id');
$late = $orders['orden-501'] ?? null;
$fresh = $orders['orden-502'] ?? null;
if (!is_array($late) || !is_array($fresh) || count($orders) !== 2) {
  throw new RuntimeException('Both pending maintenance orders must appear as due items.');
}
if ($late['tipo_vencimiento'] !== 'orden_mantenimiento_sin_respuesta' || $late['estado'] !== 'Vencido' || $late['fecha_vencimiento'] !== date('Y-m-d', strtotime('today -3 days'))) {
  throw new RuntimeException('An order created 6 days ago with a 3 day limit must be overdue since 3 days ago.');
}
if ($late['fecha_calendario'] !== date('Y-m-d')) {
  throw new RuntimeException('An overdue order must stay visible in the current calendar range.');
}
if ($fresh['estado'] !== 'Pendiente' || $fresh['fecha_vencimiento'] !== date('Y-m-d', strtotime('today +3 days'))) {
  throw new RuntimeException('An order without fecha must fall back to cct_created.');
}
if (!str_contains($db->sql['wp_jet_cct_ordenes'] ?? '', "IN ('', 'esperando respuesta')")) {
  throw new RuntimeException('Only orders waiting for a response may be selected.');
}

$annexes = $probe->annexes($settings, $fromTs, $toTs);
if (count($annexes) !== 1) {
  throw new RuntimeException('The delivered contract without annexes must appear as a due item.');
}
$annex = $annexes[0];
if ($annex['tipo_vencimiento'] !== 'contrato_anexos_sin_enviar' || $annex['estado'] !== 'Pendiente' || $annex['fecha_vencimiento'] !== date('Y-m-d', strtotime('today +3 days'))) {
  throw new RuntimeException('Contract annex due date must be delivery date plus the configured days.');
}
if (!str_contains($annex['titulo'], 'Contrato #4410')) {
  throw new RuntimeException('Contract annex title must use the contract code.');
}
$annexSql = $db->sql['wp_jet_cct_contratos_arrendamiento'] ?? '';
if (!str_contains($annexSql, "= 'entregado'") || !str_contains($annexSql, "`se_envio`, ''))) <> 'si'")) {
  throw new RuntimeException('Contract annexes must use the same rule as the "Anexos pendientes" tab.');
}

echo "Maintenance order and contract annex due checks passed\n";
