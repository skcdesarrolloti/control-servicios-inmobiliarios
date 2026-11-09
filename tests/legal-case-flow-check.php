<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\LegalCases\LegalCaseService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$fails = static function (callable $work, string $expected) use ($check): void {
  try {
    $work();
  } catch (\DomainException $error) {
    $check(str_contains($error->getMessage(), $expected), 'Rechaza: ' . $expected);
    return;
  }
  $check(false, 'Debió rechazar: ' . $expected);
};

/** SQLite con el DDL real de MySQL traducido, para comprobar las columnas que crea ensureSchema(). */
final class LegalCaseTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }

  public function exec(string $statement): int|false
  {
    if ($this->inTransaction() && preg_match('/^\s*(CREATE|ALTER|DROP)\s/i', $statement) === 1) throw new RuntimeException('DDL inside a transaction: ' . substr(trim($statement), 0, 60));
    if (str_contains($statement, 'ENGINE=InnoDB')) {
      $statement = str_replace(['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['INTEGER PRIMARY KEY AUTOINCREMENT', ''], $statement);
      $statement = (string) preg_replace('/,\s*UNIQUE KEY \w+ \((\w+)\)/', ', UNIQUE ($1)', $statement);
      $statement = (string) preg_replace('/,\s*KEY \w+ \(\w+\)/', '', $statement);
    }
    return parent::exec($statement);
  }
}

$pdo = new LegalCaseTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, correo TEXT, celular TEXT, id_cargo TEXT, activo TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_funcionarios VALUES (7, '5007', 'Ana Contractual', 'ana@example.com', '3001112222', '7', 'Si'), (8, '5008', 'Retirado', 'r@example.com', '3001112223', '7', 'No')");
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, inmueble TEXT, direccion TEXT, propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, arrendatario TEXT, id_arrendatario TEXT, correo_arrendatario TEXT, celular_arrendatario TEXT, indicativo_arrendatario TEXT, id_contrato_mandato TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_contratos_arrendamiento VALUES (9, 'C-100', '401', 'Calle 1 # 2-3', 'Pedro Dueño', '', 'pedro@example.com', '3101234567', '57', 'Laura Inquilina', '', 'laura@example.com', '3117654321', '57', '')");
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY, codigo TEXT, direccion TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_inmuebles VALUES (1, 'INM-55', 'Carrera 9 # 8-7')");

$settings = new Settings($db);
$service = new LegalCaseService($db, $settings);
$service->ensureSchema();
$service->ensureSchema();
$actor = ['id' => '5007', 'name' => 'Ana Contractual', 'email' => 'ana@example.com', 'phone' => '3001112222', 'cargo' => 'Contractual'];

$types = $service->types(true);
$check(count($types) === 6 && in_array('Siniestro', array_column($types, 'name'), true), 'Crea los tipos iniciales una sola vez.');
$typeId = $types[0]['id'];
$check(array_column($service->responsibles(), 'id') === ['5007'], 'Solo ofrece funcionarios activos con su id_empleado real.');

$parties = $service->contractParties(9);
$check(count($parties) === 2 && $parties[0]['role'] === 'propietario' && $parties[1]['role'] === 'arrendatario' && $parties[1]['phone'] === '+573117654321', 'Precarga propietario y arrendatario del contrato.');

$base = ['name' => 'Caso incendio apto 401', 'type_id' => $typeId, 'description' => 'Hechos', 'contract_id' => 9, 'responsible_id' => '5007', 'opened_date' => '2026-11-01', 'due_date' => '2026-11-20', 'next_action' => 'Llamar'];
$id = $service->create($base, $parties, $actor);
$check($id === 1 && LegalCaseService::code($id) === 'CASO-0001', 'El consecutivo sale del id.');
$detail = $service->detail($id);
$check($detail['case']['contract_code'] === 'C-100' && $detail['case']['property'] === '401' && $detail['case']['responsible_name'] === 'Ana Contractual', 'Toma contrato, inmueble y responsable del servidor.');
$check(count($detail['parties']) === 2 && $detail['events'][0]['action'] === 'caso_creado', 'Guarda partes y deja el evento de creación.');

$fails(static fn() => $service->create(['name' => ''] + $base, [], $actor), 'nombre del caso');
$fails(static fn() => $service->create(['type_id' => 999] + $base, [], $actor), 'tipo de caso');
$fails(static fn() => $service->create(['responsible_id' => '5008'] + $base, [], $actor), 'responsable activo');
$fails(static fn() => $service->create(['due_date' => '2026-10-01'] + $base, [], $actor), 'anterior a la apertura');
$fails(static fn() => $service->create(['opened_date' => '2026-13-40'] + $base, [], $actor), 'no es válida');
$fails(static fn() => $service->create($base, [['role' => 'tercero', 'name' => 'X', 'email' => 'malo']], $actor), 'correo de X');
$fails(static fn() => $service->create($base, [['role' => 'jefe', 'name' => 'X']], $actor), 'Rol de la parte');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_cases') === 1, 'Lo rechazado no deja casos.');

$service->update($id, ['next_action' => 'Enviar carta'] + $base, $actor);
$service->update($id, ['next_action' => 'Enviar carta'] + $base, $actor);
$edited = array_values(array_filter($service->detail($id)['events'], static fn(array $event): bool => $event['action'] === 'caso_editado'));
$check(count($edited) === 1 && $edited[0]['details']['cambios'][0]['despues'] === 'Enviar carta', 'Registra el cambio y no repite eventos si no hubo cambios.');

$service->changeStatus($id, 'en_tramite', 'Inicia gestión', $actor);
$fails(static fn() => $service->changeStatus($id, 'en_tramite', '', $actor), 'ya está en ese estado');
$fails(static fn() => $service->changeStatus($id, 'perdido', '', $actor), 'Estado no válido');
$service->changeStatus($id, 'cerrado', '', $actor);
$check((int) $db->getVar('SELECT closed_at FROM wp_scm_legal_cases WHERE id = 1') > 0, 'Registra la fecha de cierre.');
$service->changeStatus($id, 'abierto', 'Se reabre', $actor);
$check($db->getVar('SELECT closed_at FROM wp_scm_legal_cases WHERE id = 1') === null, 'Reabrir limpia la fecha de cierre.');

$partyId = $service->addParty($id, ['role' => 'tercero', 'name' => 'Gigante del Hogar', 'company' => 'Gigante SAS', 'email' => 'GERENCIA@example.com', 'phone' => '3205556666'], $actor);
$check($service->detail($id)['parties'][2]['email'] === 'gerencia@example.com', 'Normaliza el correo de la parte.');
$service->removeParty($id, $partyId, $actor);
$check(count($service->detail($id)['parties']) === 2 && (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_case_parties') === 3, 'Retirar una parte conserva el registro.');
$fails(static fn() => $service->removeParty($id, $partyId, $actor), 'ya no está');

$second = $service->create(['name' => 'Reclamación ascensor', 'contract_id' => 0, 'property' => 'INM-55', 'address' => 'Carrera 9 # 8-7', 'due_date' => '2020-01-01', 'opened_date' => '2020-01-01'] + $base, [['role' => 'tercero', 'name' => 'Ascensores Ltda', 'email' => 'a@example.com']], $actor);
$check($service->searchProperties('INM-5')[0]['code'] === 'INM-55', 'Busca inmuebles sin contrato.');
$check($service->list(['search' => 'Ascensores'])['rows'][0]['id'] === $second, 'Busca por parte.');
$check($service->list(['search' => 'caso-0002'])['rows'][0]['id'] === $second, 'Busca por código.');
$check($service->list(['search' => '0001'])['rows'][0]['id'] === $id, 'Busca por número.');
$check($service->list(['status' => 'abierto', 'property' => 'INM-55'])['total'] === 1, 'Filtra por estado e inmueble.');
$check($service->list(['type_id' => '999'])['total'] === 0 && $service->list(['responsible' => '5007'])['total'] === 2, 'Filtra por tipo y responsable.');
$list = $service->list([]);
$check($list['kpis']['abierto'] === 2 && $list['kpis']['vencidos'] >= 1 && $list['rows'][0]['overdue'] === true, 'Calcula indicadores y marca vencidos.');
for ($i = 0; $i < 30; $i++) $service->create(['name' => 'Masivo ' . $i, 'contract_id' => 0, 'property' => '', 'address' => ''] + $base, [], $actor);
$paged = $service->list([], 2);
$check($paged['total'] === 32 && $paged['pages'] === 2 && count($paged['rows']) === 7, 'Pagina los resultados.');
$check($service->list([], 99)['page'] === 2, 'Una página inexistente cae en la última.');

$service->addNote($id, 'Nota privada', $actor);
$fails(static fn() => $service->addNote($id, '   ', $actor), 'Escribe la nota');
$fails(static fn() => $service->detail(9999), 'no existe');

$file = ['original_name' => 'carta.pdf', 'name' => 'abc_1.pdf', 'mime' => 'application/pdf', 'bytes' => 2048, 'sha256' => str_repeat('a', 64)];
$reply = ['channel' => 'Correo', 'from' => 'Gigante', 'subject' => 'Re: daño', 'received_date' => date('Y-m-d'), 'body' => 'Aceptamos'];
$service->addManualReply($id, $reply, [$file], $actor);
$detail = $service->detail($id);
$last = $detail['events'][count($detail['events']) - 1];
$check($last['action'] === 'respuesta_registrada' && $last['details']['texto'] === 'Aceptamos' && $detail['documents'][0]['event_id'] === $last['id'] && $detail['documents'][0]['sha256'] === str_repeat('a', 64), 'La respuesta queda en la línea de tiempo con su adjunto y hash.');
$fails(static fn() => $service->addManualReply($id, ['channel' => 'Paloma'] + $reply, [], $actor), 'por dónde llegó');
$fails(static fn() => $service->addManualReply($id, ['from' => ''] + $reply, [], $actor), 'quién respondió');
$fails(static fn() => $service->addManualReply($id, ['received_date' => date('Y-m-d', strtotime('+2 days'))] + $reply, [], $actor), 'futura');
$fails(static fn() => $service->addManualReply($id, ['body' => ''] + $reply, [], $actor), 'contenido');
$service->addDocuments($id, [$file], $actor);
$detail = $service->detail($id);
$last = $detail['events'][count($detail['events']) - 1];
$check($last['action'] === 'documento' && $last['details']['archivos'] === ['carta.pdf'] && count($detail['documents']) === 2, 'Los documentos cargados dejan evento.');
$fails(static fn() => $service->addDocuments($id, [], $actor), 'al menos un archivo');

$root = dirname(__DIR__);
foreach (['legal_case_manage', 'legal_case_send', 'legal_case_config', 'legal_case_export'] as $action) {
  $check(str_contains((string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php'), "'{$action}' =>"), "La acción {$action} está en el catálogo.");
}
$handlers = (string) file_get_contents($root . '/src/App/Concerns/HandlesLegalCases.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
preg_match_all('/function (ajax_handler_legal_case_\w+)/', $handlers, $matches);
foreach ($matches[1] as $handler) $check(str_contains($router, "'{$handler}'"), "La ruta de {$handler} está registrada.");

echo "Casos jurídicos: verificación completa.\n";
