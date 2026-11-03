<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret-test-secret-test-secret-00');

use SCM\Core\Database;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;
use SCM\Modules\LeaseContracts\ContractCoOwnerContacts;
use SCM\Modules\LeaseContracts\LeaseContractsView;
use SCM\Modules\PublicTickets\PublicTicketsService;

// Fase 8 de copropietarios: cotización de reparaciones, búsqueda del solicitante, avisos administrativos y vistas.
$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

final class CoOwnersModulesTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare(str_replace('AS UNSIGNED', 'AS INTEGER', $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$pdo = new CoOwnersModulesTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
};
$insert = static function (string $name, array $row) use ($pdo): void {
  $pdo->prepare("INSERT INTO {$name}(" . implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', array_keys($row))) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
};
$call = static function (object $object, string $method, mixed ...$args): mixed {
  $reflection = new ReflectionMethod($object, $method);
  return $reflection->invoke($object, ...$args);
};

$contractColumns = ['contrato', 'estado', 'inmueble', 'id_inmueble', 'direccion', 'propietario', 'documento_propietario', 'correo_propietario', 'celular_propietario', 'indicativo_propietario', 'id_propietario', 'arrendatario', 'id_arrendatario', 'documento_arrendatario', 'id_contrato_mandato', 'propietarios', 'id_empleado', 'fin_contrato'];
for ($i = 2; $i <= 6; $i++) $contractColumns[] = 'id_propietario_' . $i;
$table('wp_jet_cct_contratos_arrendamiento', $contractColumns);
$mandateColumns = ['propietarios', 'id_propietario'];
for ($i = 1; $i <= 6; $i++) array_push($mandateColumns, 'nombre_' . $i, 'documento_' . $i, 'correo_' . $i, 'celular_' . $i, 'indicativo_' . $i, 'nit_' . $i, 'id_propietario_nuevo_' . $i, 'porcentaje_' . $i);
$table('wp_jet_cct_contrato_mandato', $mandateColumns);
$table('wp_jet_cct_propietarios', ['id_propietario', 'nombre', 'nombre_juridico', 'documento', 'documento_juridico', 'correo', 'celular', 'indicativo']);
$table('wp_jet_cct_arrendatarios', ['id_arrendatario', 'nombre', 'documento', 'documento_juridico']);
$table('wp_jet_cct_funcionarios', ['id_empleado', 'nombre', 'correo', 'celular']);

$insert('wp_jet_cct_propietarios', ['_ID' => 500, 'nombre' => 'Ana Dueña', 'documento' => '111.111', 'correo' => 'ana@example.invalid', 'celular' => '3000000001']);
$insert('wp_jet_cct_propietarios', ['_ID' => 501, 'nombre' => 'Luis Copropietario', 'documento' => '2.222.222', 'correo' => 'luis@example.invalid', 'celular' => '3000000002', 'indicativo' => '+57']);
$insert('wp_jet_cct_propietarios', ['_ID' => 502, 'nombre' => 'Marta Sin Mandato', 'documento' => '3333333', 'correo' => 'marta@example.invalid', 'celular' => '3000000003']);
// #31: mandato con Ana, Luis y Pedro (Pedro sin ficha, solo en el mandato).
$insert('wp_jet_cct_contrato_mandato', ['_ID' => 31, 'propietarios' => '3', 'id_propietario' => '500',
  'nombre_1' => 'Ana Dueña', 'documento_1' => '111111', 'correo_1' => 'ana@example.invalid', 'celular_1' => '3000000001', 'indicativo_1' => '+57', 'id_propietario_nuevo_1' => '500',
  'nombre_2' => 'Luis Copropietario', 'documento_2' => '2222222', 'correo_2' => 'luis@example.invalid', 'celular_2' => '3000000002', 'indicativo_2' => '+57', 'id_propietario_nuevo_2' => '501',
  'nombre_3' => 'Pedro Mandato', 'documento_3' => '4.444.444', 'correo_3' => 'pedro@example.invalid', 'celular_3' => '3000000004', 'indicativo_3' => '+57',
]);
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 10, 'contrato' => '701', 'estado' => 'Entregado', 'inmueble' => '9001', 'propietario' => 'Ana Dueña', 'documento_propietario' => '111111', 'correo_propietario' => 'ana@example.invalid', 'celular_propietario' => '3000000001', 'id_propietario' => '500', 'id_contrato_mandato' => '31', 'propietarios' => '3', 'id_propietario_2' => '501']);
// #11: sin mandato, con Marta como copropietaria registrada en el contrato.
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 11, 'contrato' => '702', 'estado' => 'Entregado', 'inmueble' => '9002', 'propietario' => 'Ana Dueña', 'id_propietario' => '500', 'propietarios' => '2', 'id_propietario_2' => '502']);
// #12: un solo propietario.
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 12, 'contrato' => '703', 'estado' => 'Entregado', 'inmueble' => '9003', 'propietario' => 'Ana Dueña', 'id_propietario' => '500']);
$contract = static fn(int $id): array => $pdo->query("SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = {$id}")->fetch(PDO::FETCH_ASSOC);

// 1. Contactos de todos los propietarios del contrato (cotización de reparaciones).
$owners = ContractCoOwnerContacts::forContract($db, $contract(10));
$check(array_column($owners, 'name') === ['Ana Dueña', 'Luis Copropietario', 'Pedro Mandato'] && $owners[2]['email'] === 'pedro@example.invalid' && $owners[2]['phone'] === '573000000004', 'with a mandate every owner comes with email and phone');
$owners = ContractCoOwnerContacts::forContract($db, $contract(11));
$check(array_column($owners, 'name') === ['Ana Dueña', 'Marta Sin Mandato'] && $owners[1]['email'] === 'marta@example.invalid', 'without a mandate the co-owners come from their profile');
$check(count(ContractCoOwnerContacts::forContract($db, $contract(12))) === 1 && ContractCoOwnerContacts::forContract($db, []) === [], 'a single owner or no contract has no co-owners');

$quoteSender = new class($db) {
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  public function __construct(public Database $db) {}
  public function table_exists($table): bool { return (new \SCM\Support\SchemaInspector($this->db))->tableExists((string) $table); }
};
$recipients = $call($quoteSender, 'maintenance_quote_co_owner_recipients', ['id_contrato' => '10'], 'ana@example.invalid', '+573000000001');
$check(array_column($recipients, 'name') === ['Luis Copropietario', 'Pedro Mandato'] && $recipients[1]['phone'] === '+573000000004' && $recipients[0]['email'] === 'luis@example.invalid', 'the quote goes to the co-owners, skipping the main recipient');
$recipients = $call($quoteSender, 'maintenance_quote_co_owner_recipients', ['id_contrato' => '10'], 'luis@example.invalid', '');
$check(array_column($recipients, 'name') === ['Ana Dueña', 'Pedro Mandato'], 'when a co-owner is the main recipient, the main owner also receives it');
$check($call($quoteSender, 'maintenance_quote_co_owner_recipients', ['id_contrato' => '12'], 'ana@example.invalid', '') === [], 'a single-owner contract adds nobody');

// 2. Búsqueda del solicitante: el copropietario encuentra su contrato con sus propios datos.
$tickets = new PublicTicketsService($db, ['app_secret' => SCM_APP_SECRET]);
$rows = $call($tickets, 'lookupPropietario', '2.222.222');
$check(array_column($rows, 'contrato') === ['701'] && $rows[0]['contact_name'] === 'Luis Copropietario' && $rows[0]['contact_email'] === 'luis@example.invalid' && $rows[0]['actor_id'] === '501', 'a co-owner with a profile finds the contract with their own contact');
$rows = $call($tickets, 'lookupPropietario', '4444444');
$check(array_column($rows, 'contrato') === ['701'] && $rows[0]['contact_name'] === 'Pedro Mandato' && $rows[0]['contact_phone'] === '3000000004', 'a co-owner only in the mandate finds the contract by the mandate document');
$rows = $call($tickets, 'lookupPropietario', '111.111');
$check(array_column($rows, 'contrato') === ['703', '702', '701'] && array_unique(array_column($rows, 'contact_name')) === ['Ana Dueña'], 'the main owner still finds all their contracts');
$check($call($tickets, 'contractMatchesLookup', 'propietario', $contract(11), '3333333') && !$call($tickets, 'contractMatchesLookup', 'propietario', $contract(12), '3333333'), 'the selected contract matches the co-owner lookup');
$found = array_column($tickets->searchPanelContracts('3333333', 'documento'), 'contrato');
$check($found === ['702'], 'the panel document search finds contracts by co-owner profile');
$found = array_column($tickets->searchPanelContracts('2.222.222', 'propietario'), 'contrato');
$check($found === ['701'], 'the panel owner search finds contracts where the person is a co-owner');

// 3. Avisos administrativos: el copropietario cuenta como propietario del contrato.
$notifications = new AdministrativeNotificationsService($db);
$config = $call($notifications, 'typeConfig', 'propietarios_activos');
[$where, $args] = $call($notifications, 'contractRecipientMatch', $config, ['_ID' => 502]);
$ids = array_column($db->getResults("SELECT `_ID` FROM wp_jet_cct_contratos_arrendamiento WHERE {$where}", $args), '_ID');
$check(array_map('intval', $ids) === [11], 'a co-owner profile matches the contract where they are co-owner');
$sql = $call($notifications, 'contractActorComparisonSql', 'wp_jet_cct_propietarios', 'id_propietario', $config);
$ids = array_column($db->getResults("SELECT p.`_ID` FROM wp_jet_cct_propietarios p WHERE EXISTS (SELECT 1 FROM wp_jet_cct_contratos_arrendamiento ca WHERE " . str_replace('`wp_jet_cct_propietarios`.', 'p.', $sql) . " AND ca.contrato = '701')"), '_ID');
$check(array_map('intval', $ids) === [500, 501], 'the contract filter includes the co-owners');
$import = $call($notifications, 'importRecipientsFromContractMetas', 'propietarios_activos', $config, [['contrato_excel' => '701', 'inmueble_simi_excel' => '', 'documento_excel' => '']], 1);
$check(array_map('intval', array_column($import['rows'], '_ID')) === [500, 501] && $import['ambiguous'] === 0, 'a contract imported from Excel marks the owner and the co-owner');
$import = $call($notifications, 'importRecipientsFromContractMetas', 'propietarios_activos', $config, [['contrato_excel' => '703', 'inmueble_simi_excel' => '', 'documento_excel' => '']], 1);
$check(array_map('intval', array_column($import['rows'], '_ID')) === [500], 'a single-owner contract still marks only its owner');

// 4. Vistas del contrato: "+N copropietarios".
$check(LeaseContractsView::coOwnersLabel(['propietarios' => '3']) === '+2 copropietarios' && LeaseContractsView::coOwnersLabel(['propietarios' => '2']) === '+1 copropietario' && LeaseContractsView::coOwnersLabel(['propietarios' => '1']) === '' && LeaseContractsView::coOwnersLabel([]) === '', 'the co-owners label');
$view = new LeaseContractsView();
$html = $view->renderTable([$contract(10), $contract(12)], false);
$check(substr_count($html, '+2 copropietarios') === 1, 'the contract list shows the co-owners next to the owner');
$check(str_contains($view->renderDocumentation($contract(10)), 'Titular · +2 copropietarios'), 'the contract documentation shows the co-owners');

echo "Co-owners modules checks passed.\n";
