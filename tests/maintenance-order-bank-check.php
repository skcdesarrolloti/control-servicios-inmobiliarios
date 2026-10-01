<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/App/Concerns/HandlesMaintenanceActions.php';

final class BankMemoryDb
{
  public array $rows = [];
  public array $inserted = [];
  public bool $busy = false;
  public int $released = 0;
  public function table(string $name): string { return $name; }
  public function getVar(string $sql, array $args): int {
    if (str_contains($sql, 'GET_LOCK')) return $this->busy ? 0 : 1;
    $this->released++;
    return 1;
  }
  public function getResults(string $sql): array { return $this->rows; }
  public function insert(string $table, array $data): bool { $this->inserted[] = $data; $this->rows[] = array_merge($data, ['_ID' => '9']); return true; }
  public function lastInsertId(): string { return '9'; }
}
final class BankHandler
{
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  public function __construct(public BankMemoryDb $db) {}
  private function table_exists(string $table): bool { return true; }
  private function current_employee_id(): string { return '77'; }
  public function save(string $name, string $country): array { return $this->maintenance_order_save_bank($name, $country); }
}
$check = static function (bool $condition, string $label): void {
  if (!$condition) throw new RuntimeException($label);
  echo 'OK ' . $label . PHP_EOL;
};
$db = new BankMemoryDb();
$handler = new BankHandler($db);
$bank = $handler->save('  Banco   Nuevo  ', ' Colombia ');
$check($bank['created'] && $bank['value'] === 'Banco Nuevo' && $bank['pais'] === 'Colombia', 'new bank uses normalized name and country');
$check(count($db->inserted) === 1 && $db->inserted[0]['cct_author_id'] === '77' && $db->inserted[0]['cct_status'] === 'publish', 'new bank is available in the catalog with the real employee author');
$again = $handler->save('banco  NUEVO', 'Colombia');
$check(!$again['created'] && $again['value'] === 'Banco Nuevo' && count($db->inserted) === 1, 'existing name is reused regardless of case and repeated spaces');
$check($db->released === 2, 'catalog lock releases after both insert and reuse');
$db->rows[] = ['_ID' => '10', 'banco' => 'Banco inactivo', 'pais' => 'Colombia', 'cct_status' => 'trash'];
foreach ([['Banco inactivo','Colombia'], ['', 'Colombia'], ['Banco', ''], [str_repeat('a', 161), 'Colombia']] as [$name, $country]) {
  try { $handler->save($name, $country); throw new RuntimeException('Expected rejection'); }
  catch (DomainException $error) { $check(count($db->inserted) === 1, 'invalid/inactive bank does not create a duplicate'); }
}
$db->busy = true;
try { $handler->save('Otro banco', 'Colombia'); throw new RuntimeException('Expected lock rejection'); }
catch (DomainException $error) { $check(count($db->inserted) === 1, 'busy catalog cannot insert concurrently'); }
