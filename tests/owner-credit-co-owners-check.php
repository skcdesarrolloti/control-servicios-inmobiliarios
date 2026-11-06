<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditView;

// Créditos a propietarios con copropietarios: el cupo personal (libre inversión) sale del % del solicitante; el crédito
// del inmueble (servicios públicos, administración, reparaciones) usa el 100 % de su contrato más los demás contratos
// del propietario y lo firman todos; sin % se toma partes iguales con la política en rojo; lo ya decidido no cambia.

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$near = static fn(float $a, float $b): bool => abs($a - $b) < 0.01;

final class OwnerCreditCoOwnersTestPdo extends PDO
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
    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }

  /** El DDL de MySQL de ensureSchema() se omite: las tablas se crean abajo en SQLite. */
  public function exec(string $statement): int|false
  {
    return str_contains($statement, 'ENGINE=InnoDB') ? 0 : parent::exec($statement);
  }
}

$pdo = new OwnerCreditCoOwnersTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT, cct_author_id INTEGER, cct_modified TEXT, departamento TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_confi_sistema(funcion, valor) VALUES ('control_servicios_config', '" . json_encode(['owner_credit_settings' => ['usury_auto' => false, 'usury_annual' => 0.2859, 'usury_updated_at' => date('Y-m-01'), 'reference_rate' => 0.019, 'require_promissory' => false, 'max_term' => 24, 'income_months' => 3]]) . "')");
$pdo->exec('CREATE TABLE wp_scm_owner_credits(id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER UNIQUE, ticket_id TEXT DEFAULT "", contract_id INTEGER DEFAULT 0, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", address TEXT DEFAULT "", owner_id TEXT DEFAULT "", owner_name TEXT DEFAULT "", owner_email TEXT DEFAULT "", owner_phone TEXT DEFAULT "", purpose TEXT DEFAULT "", status TEXT DEFAULT "pendiente", requested_amount REAL DEFAULT 0, requested_term INTEGER DEFAULT 0, vacancy TEXT DEFAULT "Baja", gross_income REAL DEFAULT 0, deductions_json TEXT, net_income REAL DEFAULT 0, max_amount REAL DEFAULT 0, max_months INTEGER DEFAULT 0, approved_amount REAL, approved_term INTEGER, approved_rate REAL, monthly_payment REAL, total_interest REAL, first_payment_ts INTEGER, policy_json TEXT, evaluation_json TEXT, policy_override INTEGER DEFAULT 0, decision_note TEXT, decided_by_id TEXT DEFAULT "", decided_by_name TEXT DEFAULT "", decided_at INTEGER, closed_at INTEGER, created_by_id TEXT DEFAULT "", created_by_name TEXT DEFAULT "", created_at INTEGER, updated_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_sources(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, contract_id INTEGER, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", net_income REAL DEFAULT 0, end_ts INTEGER, is_primary INTEGER DEFAULT 0, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_promissory(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_document TEXT, owner_id TEXT DEFAULT "", owner_name TEXT DEFAULT "", owner_email TEXT DEFAULT "", owner_phone TEXT DEFAULT "", credit_id INTEGER DEFAULT 0, status TEXT DEFAULT "pendiente", payload_json TEXT, payload_hash TEXT, token_nonce TEXT, expires_at INTEGER, channels TEXT DEFAULT "email", otp_json TEXT, delivery_json TEXT, signed_at INTEGER, signed_json TEXT, signed_pdf BLOB, pdf_hash TEXT, pdf_hmac TEXT, voided_at INTEGER, void_note TEXT, voided_by_name TEXT DEFAULT "", created_by_id TEXT DEFAULT "", created_by_name TEXT DEFAULT "", created_at INTEGER, updated_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_events(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, action TEXT, actor_id TEXT, actor_name TEXT, details_json TEXT, created_at INTEGER)');
$contractColumns = 'contrato TEXT, estado TEXT, inmueble TEXT, id_inmueble TEXT, direccion TEXT, propietario TEXT, documento_propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, valor_canon TEXT, inicio_contrato TEXT, fin_contrato TEXT, esta_sinestrado TEXT, id_contrato_mandato TEXT, propietarios TEXT';
for ($i = 1; $i <= 6; $i++) $contractColumns .= ', porcentaje_' . $i . ' TEXT' . ($i > 1 ? ', id_propietario_' . $i . ' TEXT' : '');
$pdo->exec("CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, {$contractColumns})");
$mandateColumns = 'total_comision TEXT, incluye_garantia TEXT, incluye_garantia_servicios TEXT, propietarios TEXT, id_propietario TEXT';
for ($i = 1; $i <= 6; $i++) $mandateColumns .= ", tipo_{$i} TEXT, nombre_{$i} TEXT, documento_{$i} TEXT, correo_{$i} TEXT, celular_{$i} TEXT, indicativo_{$i} TEXT, id_propietario_nuevo_{$i} TEXT, porcentaje_{$i} TEXT";
$pdo->exec("CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, {$mandateColumns})");
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY, id_ticket TEXT, contrato TEXT, id_contrato TEXT, total TEXT, financiacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_contrato TEXT, contrato TEXT, inmueble TEXT, id_inmueble TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_propietarios(_ID INTEGER PRIMARY KEY, id_propietario TEXT, nombre TEXT, documento TEXT, documento_juridico TEXT, correo TEXT, celular TEXT, indicativo TEXT)');

$owner = static fn(int $i, string $name, string $document, string $percent, string $id): array => [
  'tipo_' . $i => 'Natural', 'nombre_' . $i => $name, 'documento_' . $i => $document, 'correo_' . $i => strtolower(explode(' ', $name)[0]) . '@example.invalid',
  'celular_' . $i => '30000000' . $i, 'indicativo_' . $i => '+57', 'id_propietario_nuevo_' . $i => $id, 'porcentaje_' . $i => $percent,
];
// Mandato #40: Ana 30 %, Luis 50 %, Marta 20 %. Comisión 200.000 → ingreso neto 1.800.000.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 40, 'total_comision' => '200.000', 'incluye_garantia' => 'No', 'incluye_garantia_servicios' => 'No', 'propietarios' => '3', 'id_propietario' => '500']
  + $owner(1, 'Ana Uno', '111111', '30', '500') + $owner(2, 'Luis Dos', '222222', '50', '501') + $owner(3, 'Marta Tres', '333333', '20', '502'));
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 20, 'contrato' => '2020', 'estado' => 'Entregado', 'inmueble' => '7000', 'id_inmueble' => '7000', 'direccion' => 'Calle 20', 'propietario' => 'Ana Uno', 'id_propietario' => '500',
  'valor_canon' => '2.000.000', 'inicio_contrato' => (string) strtotime('-1 year'), 'fin_contrato' => (string) strtotime('+3 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '40', 'propietarios' => '3', 'id_propietario_2' => '501', 'id_propietario_3' => '502']);
// Mandato #41: dos dueños sin % registrado.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 41, 'total_comision' => '100.000', 'incluye_garantia' => 'No', 'incluye_garantia_servicios' => 'No', 'propietarios' => '2', 'id_propietario' => '510']
  + $owner(1, 'Sofia Sin', '555555', '', '510') + $owner(2, 'Tomas Sin', '666666', '', ''));
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 21, 'contrato' => '2121', 'estado' => 'Entregado', 'inmueble' => '7100', 'propietario' => 'Sofia Sin', 'id_propietario' => '510',
  'valor_canon' => '1.100.000', 'inicio_contrato' => (string) strtotime('-1 year'), 'fin_contrato' => (string) strtotime('+3 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '41']);
// Contrato #22 sin mandato: Pedro es copropietario solo por su ficha en id_propietario_2.
$db->insert('wp_jet_cct_propietarios', ['_ID' => 520, 'nombre' => 'Pedro Ficha', 'documento' => '7.777.777', 'correo' => 'pedro@example.invalid', 'celular' => '3007777777', 'indicativo' => '57']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 22, 'contrato' => '2222', 'estado' => 'Entregado', 'inmueble' => '7200', 'propietario' => 'Rosa Principal', 'documento_propietario' => '888888', 'id_propietario' => '530',
  'valor_canon' => '1.000.000', 'inicio_contrato' => (string) strtotime('-1 year'), 'fin_contrato' => (string) strtotime('+3 years'), 'esta_sinestrado' => 'No', 'propietarios' => '2', 'id_propietario_2' => '520', 'porcentaje_1' => '60', 'porcentaje_2' => '40']);

$service = new OwnerCreditService($db, new Settings($db));
$service->useResponseFiles(static fn(string $html, string $title): string => '%PDF-1.4 ' . $title, static fn(string $bytes, string $name): array => ['path' => sys_get_temp_dir() . '/' . $name, 'url' => 'https://example.test/file.php?n=' . rawurlencode($name)]);
$actor = ['employee_id' => '2999', 'name' => 'Analista QA'];
$policy = static fn(array $work, string $key): array => array_values(array_filter($work['policies'], static fn(array $p): bool => $p['key'] === $key))[0];
$note = 'Aprobado en prueba con justificación suficiente.';

// Personal al 30 %: tope y cobertura con el 30 % del ingreso; el neto se muestra al 100 %.
$personal = $service->workspace($service->createManual(20, 500000, 6, 'Libre inversión', $actor, '', 'mandato_1'));
$ev = $personal['evaluation'];
$check($near($ev['net_income'], 1800000) && $near($ev['share']['value'], 0.3) && $near($ev['attributable_income'], 540000) && $near($ev['max_amount'], 540000 * 3) && $ev['share']['mode'] === 'personal', 'a personal credit at 30 % gets 30 % of the income for its cap');
$check($near($personal['sources'][0]['net_income'], 540000), 'only the owner share of the contract pays the installment');
$check($policy($personal, 'participacion')['ok'] && str_contains($policy($personal, 'participacion')['detail'], '#2020 30 %'), 'the share policy passes when the percentage is registered');
$html = (new OwnerCreditView())->workspace($personal, true, true);
$check(str_contains($html, 'Participación del solicitante') && str_contains($html, '30 %') && str_contains($html, 'Ingreso atribuible'), 'the liquidator shows the share and the attributable income tiles');
$check(str_contains(\SCM\Modules\OwnerCredits\OwnerCreditPdf::html($personal, 'QA'), 'Ingreso atribuible del contrato #2020'), 'the internal PDF shows the attributable income');

// El origen del ingreso nombra el contrato de arrendamiento; al ajustar el canon, la comisión se escala en la misma
// proporción y no cuenta como cambio aparte (solo el canon).
$canonWork = $service->workspace((int) $personal['credit']['id'], ['gross' => '$2.200.000', 'ded_comision' => '$220.000']);
$check(str_starts_with((string) $canonWork['income_source'], 'Contrato de arrendamiento #2020 · inmueble 7000') && !str_contains((string) $canonWork['income_source'], 'mandato #'), 'the income source names the lease contract, not the mandate number');
$check(OwnerCreditService::scaledDeductions($canonWork['income_defaults'], 2200000)['comision'] === 220000.0 && in_array('canon', array_column($canonWork['adjustments'], 'key'), true) && !in_array('ded_comision', array_column($canonWork['adjustments'], 'key'), true), 'a commission scaled with the canon is not flagged as a separate change');

// Reparaciones: 100 % del contrato del inmueble (Ana aún no tiene otros contratos).
$repairId = $service->createManual(20, 1000000, 6, OwnerCreditSettings::REPAIR_PURPOSE, $actor, '', 'mandato_1');
$repair = $service->workspace($repairId);
$check($near($repair['evaluation']['share']['value'], 1.0) && $repair['evaluation']['share']['mode'] === 'reparaciones' && $near($repair['evaluation']['max_amount'], 1800000 * 3) && $repair['evaluation']['other_contracts'] === [], 'a repair credit uses 100 % of the property contract');
$check(str_contains($policy($repair, 'participacion')['detail'], 'firman los 3 copropietarios'), 'the repair share policy names every co-owner');
// Cambiar la destinación mientras se estudia cambia el cálculo.
$check($near($service->workspace($repairId, ['purpose' => 'Libre inversión'])['evaluation']['share']['value'], 0.3), 'changing the purpose while pending recalculates with the owner share');

// Aprobada la reparación, el modo y el % quedan congelados y su saldo pesa sobre cada copropietario según su %.
$service->decide($repairId, 'aprobado', ['requested_term' => '6', 'amount' => '$1.000.000', 'term' => '6', 'rate' => '1,9', 'note' => $note], $actor);
$approvedRepair = $service->find($repairId);
$check(OwnerCreditService::mode($approvedRepair) === 'reparaciones' && json_decode((string) $approvedRepair['evaluation_json'], true)['share']['mode'] === 'reparaciones', 'the repair mode is frozen with the decision');
$luis = $service->workspace($service->createManual(20, 300000, 6, 'Libre inversión', $actor, '', 'mandato_2'));
$check($near($luis['evaluation']['share']['value'], 0.5) && $near($luis['preapproval']['outstanding'], 500000) && in_array($repairId, $luis['preapproval']['credits'], true), 'a repair in force reduces each co-owner cap by their share (50 % of the balance)');
$request = $service->requestPreapproval('222222');
$check(count($request['contracts']) === 1 && $near($request['contracts'][0]['share'], 0.5) && $near($request['preapproval']['amount'], 900000 * 3) && $near($request['preapproval']['outstanding'], 500000), 'the new request cap uses the owner share and their part of the repairs in force');
$check(!empty($request['contracts'][0]['repair']['repair']) && $near($request['contracts'][0]['repair']['amount'], 1800000 * 3) && $near($request['contracts'][0]['repair']['outstanding'], 1000000), 'the new request also returns the repair cap of the contract at 100 %');

// Sin % registrado: partes iguales, política en rojo y aprobable con justificación.
$noShare = $service->workspace($service->createManual(21, 200000, 6, 'Libre inversión', $actor, '', 'mandato_2'));
$check($near($noShare['evaluation']['share']['value'], 0.5) && $noShare['evaluation']['share']['source'] === 'partes_iguales' && !$policy($noShare, 'participacion')['ok'] && !$noShare['policies_ok'], 'without percentages the share is equal and the policy is red');
try {
  $service->decide((int) $noShare['credit']['id'], 'aprobado', ['requested_term' => '6', 'amount' => '$200.000', 'term' => '6', 'rate' => '1,9', 'note' => ''], $actor);
  $check(false, 'approving without a registered share needs a justification');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'justificación'), 'approving without a registered share needs a justification');
}

// Lo decidido no cambia: un crédito anterior sin "share" queda histórico al 100 %, y uno nuevo conserva su % aunque cambie el mandato.
$personalId = (int) $personal['credit']['id'];
$service->decide($personalId, 'aprobado', ['requested_term' => '6', 'amount' => '$500.000', 'term' => '6', 'rate' => '1,9', 'note' => $note], $actor);
$pdo->exec("UPDATE wp_jet_cct_contrato_mandato SET porcentaje_1 = '60', porcentaje_2 = '20' WHERE _ID = 40");
$frozen = $service->workspace($personalId);
$check($near($frozen['evaluation']['share']['value'], 0.3) && OwnerCreditService::mode($frozen['credit']) === 'personal', 'a decided credit keeps the share it was decided with');
$db->insert('wp_scm_owner_credits', ['contract_id' => 20, 'contract_code' => '2020', 'owner_id' => '500', 'owner_name' => 'Ana Uno', 'purpose' => OwnerCreditSettings::REPAIR_PURPOSE, 'status' => 'aprobado', 'requested_amount' => 100000, 'requested_term' => 3,
  'gross_income' => 2000000, 'deductions_json' => json_encode(['comision' => 200000]), 'approved_amount' => 100000, 'approved_term' => 3, 'approved_rate' => 0.019, 'monthly_payment' => 34700, 'first_payment_ts' => strtotime('+1 month'),
  'evaluation_json' => json_encode(['other_contracts' => []]), 'decided_at' => strtotime('-1 day'), 'created_at' => strtotime('-2 days'), 'updated_at' => time()]);
$old = $service->workspace((int) $db->lastInsertId());
$check(OwnerCreditService::mode($old['credit']) === 'historico' && $near($old['evaluation']['share']['value'], 1.0) && $policy($old, 'participacion')['ok'], 'a credit decided before the share is historic at 100 %');
// Un crédito de reparaciones histórico no se reparte: sigue pesando solo sobre su beneficiaria.
$check($near($service->workspace($service->createManual(20, 100000, 3, 'Libre inversión', $actor, '', 'mandato_2'))['preapproval']['outstanding'], 1000000 * 0.2), 'historic repairs are not split among the co-owners (only the frozen repair counts, at the new 20 %)');

// Búsqueda por documento: el copropietario de un contrato sin mandato se encuentra por su ficha en id_propietario_2.
$found = $service->contractsByOwnerDocument('7777777');
$check(count($found) === 1 && (int) $found[0]['contract']['_ID'] === 22 && $found[0]['owner']['key'] === 'contrato_2' && $near($found[0]['owner']['share'], 0.4), 'a co-owner registered only in id_propietario_2 is found with their share');
$check($service->contractsByOwnerDocument('888888')[0]['owner']['key'] === 'contrato', 'the main owner of that contract is still found by the contract document');

// Firmas y avisos. Reparaciones: firman los tres, sin poder desmarcar, y a todos les llegan los avisos.
$recipients = (new ReflectionMethod(OwnerCreditService::class, 'creditRecipients'));
$repairCredit = $service->find($repairId);
$state = $service->promissoryState($repairCredit);
$check($state['locked'] && $state['required'] === ['mandato_1', 'mandato_2', 'mandato_3'] && count($state['missing']) === 3 && $state['contact_missing'] === [], 'a repair credit requires every co-owner to sign');
try {
  $service->setPromissorySigners($repairId, ['mandato_1'], $actor);
  $check(false, 'repair signers cannot be changed');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'firman todos los copropietarios'), 'repair signers cannot be changed');
}
$names = array_column($recipients->invoke($service, $repairCredit), 'name');
$check($names === ['Ana Uno', 'Luis Dos', 'Marta Tres'], 'the repair notices go to every co-owner, the beneficiary first');
$check(array_column($recipients->invoke($service, $service->find($personalId)), 'name') === ['Ana Uno'], 'a personal credit notifies only the requester');
$personalState = $service->promissoryState($service->find($personalId));
$check($personalState['locked'] && $personalState['required'] === ['mandato_1'], 'a personal credit is signed by the requester only, without choosing other signers');
$repairHtml = (new OwnerCreditView())->workspace($service->workspace($repairId), true, true);
$check(!str_contains($repairHtml, 'data-owner-credit-promissory-signer') && str_contains($repairHtml, 'lo firman todos los copropietarios'), 'the liquidator shows no signer checkboxes on a repair credit');
$letter = \SCM\Modules\OwnerCredits\OwnerCreditResponseDocuments::letter($repairCredit, ['signer' => ['name' => 'Analista QA', 'cargo' => ''], 'margin' => 2, 'date' => time(), 'holders' => [['name' => 'Ana Uno', 'document' => '111111'], ['name' => 'Luis Dos', 'document' => '222222'], ['name' => 'Marta Tres', 'document' => '333333']]], false);
$check(str_contains($letter, 'Señores:') && str_contains($letter, 'Luis Dos') && str_contains($letter, 'Marta Tres') && str_contains($letter, 'Estimados copropietarios'), 'the approval letter names every holder');
$check(!str_contains($letter, 'desembolso') && !str_contains(\SCM\Modules\OwnerCredits\OwnerCreditResponseDocuments::plan($repairCredit, ['signer' => ['name' => 'Analista QA', 'cargo' => ''], 'margin' => 2, 'date' => time()], false), 'desembolso'), 'the approval letter and the payment plan do not show the disbursement date');

// Copropietarios con documento y contacto: si falta uno, no se envía el pagaré de la reparación.
$pdo->exec("UPDATE wp_jet_cct_contrato_mandato SET documento_3 = '', correo_3 = '', celular_3 = '' WHERE _ID = 40");
$state = $service->promissoryState($repairCredit);
$check($state['contact_missing'] === ['Marta Tres'], 'a co-owner without document or contact is flagged');
// Sin pagaré exigido la respuesta salió al aprobar: se deja como no enviada para llegar a la etapa de firma.
$pdo->exec("UPDATE wp_scm_owner_credits SET response_sent_at = NULL WHERE id = {$repairId}");
try {
  $service->sendPromissory($repairId, ['email'], $actor);
  $check(false, 'the promissory note is not sent while a co-owner lacks document or contact');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'Marta Tres'), 'the promissory note is not sent while a co-owner lacks document or contact');
}
// La respuesta le llevará el pagaré cuando firmen los demás: quien firma no recibe además una copia aparte.
$check($service->afterPromissorySigned(['owner_document' => '222222', 'credit_id' => $repairId]) === true && (int) ($service->find($repairId)['response_sent_at'] ?? 0) === 0, 'a co-owner who signs first gets no separate copy: the final response carries it');
$check($service->afterPromissorySigned(['owner_document' => '999999', 'credit_id' => $repairId]) === false, 'somebody who is not a signer of the credit keeps their own copy');

// API del bot de WhatsApp: firmada, solo sobre los contratos de la persona, con la lógica del panel.
$secret = 'bot-secret-bot-secret-123456';
$api = new \SCM\Modules\OwnerCredits\OwnerCreditBotApi($service, $secret);
$call = static function (array $payload, ?string $signature = null, ?int $ts = null) use ($api, $secret): array {
  $body = json_encode($payload + ['ts' => $ts ?? time(), 'phone' => '3000000002']);
  return $api->handle($body, $signature ?? \SCM\Modules\OwnerCredits\OwnerCreditBotApi::signature($body, $secret));
};
$check($call(['action' => 'list', 'document' => '222222'], 'firma-falsa')['status'] === 401 && $call(['action' => 'list', 'document' => '222222'], null, time() - 3600)['status'] === 401, 'the bot API rejects a bad signature and an old request');
$pre = $call(['action' => 'preapproval', 'document' => '222222']);
$data = $pre['body']['data'] ?? [];
$check($pre['status'] === 200 && array_column($data['contracts'], 'id') === [20] && $data['contracts'][0]['owner']['key'] === 'mandato_2' && $data['contracts'][0]['repair']['amount'] > $data['preapproval']['amount'] && in_array(OwnerCreditSettings::REPAIR_PURPOSE, $data['purposes'], true), 'the bot gets the person contracts, the personal and repair caps and the purposes');
$check($call(['action' => 'create', 'document' => '222222', 'contract_id' => 21, 'amount' => 100000, 'term' => 3, 'purpose' => 'Libre inversión'])['status'] === 422, 'the bot cannot request a credit on a contract of somebody else');
$check($call(['action' => 'create', 'document' => '222222', 'contract_id' => 20, 'amount' => 100000, 'term' => 3, 'purpose' => 'Viaje'])['status'] === 422, 'the bot only accepts the configured purposes');
$check(str_contains((string) ($call(['action' => 'create', 'document' => '222222', 'contract_id' => 20, 'amount' => 100000, 'term' => 99, 'purpose' => 'Libre inversión'])['body']['message'] ?? ''), 'supera el máximo'), 'the bot request respects the maximum term');
$created = $call(['action' => 'create', 'document' => '222222', 'contract_id' => 20, 'amount' => '1.500.000', 'term' => 6, 'purpose' => 'Libre inversión']);
$botCredit = $service->find((int) ($created['body']['data']['credit_id'] ?? 0));
$check($created['status'] === 200 && $botCredit !== null && $botCredit['owner_name'] === 'Luis Dos' && (float) $botCredit['requested_amount'] === 1500000.0 && str_starts_with((string) $botCredit['created_by_name'], 'Guardian (WhatsApp)'), 'the bot registers the request for the identified co-owner');
$list = $call(['action' => 'list', 'document' => '222.222'])['body']['data']['credits'] ?? [];
$check($list !== [] && $list[0]['id'] === (int) $botCredit['id'] && $list[0]['status_label'] === 'Pendiente de estudio' && $list[0]['requested_term'] === 6, 'the bot lists the person credits, newest first, with a readable status');

// Servicios públicos y cuotas de administración son créditos del inmueble como reparaciones: el contrato del inmueble
// al 100 % más los demás contratos del propietario (cupo sumado, plazo con el más largo) y firman todos.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 42, 'total_comision' => '100.000', 'incluye_garantia' => 'No', 'incluye_garantia_servicios' => 'No', 'propietarios' => '1', 'id_propietario' => '500'] + $owner(1, 'Ana Uno', '111111', '100', '500'));
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 23, 'contrato' => '2323', 'estado' => 'Entregado', 'inmueble' => '7300', 'propietario' => 'Ana Uno', 'id_propietario' => '500',
  'valor_canon' => '1.000.000', 'inicio_contrato' => (string) strtotime('-1 year'), 'fin_contrato' => (string) strtotime('+5 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '42']);
$check(OwnerCreditService::isRepair(['purpose' => 'Servicios públicos']) && OwnerCreditService::isRepair(['purpose' => 'Cuotas de administración']) && !OwnerCreditService::isRepair(['purpose' => 'Libre inversión']), 'public services, HOA fees and repairs are property credits; free use is personal');
$servicesId = $service->createManual(20, 600000, 6, 'Servicios públicos', $actor, '', 'mandato_1');
$sev = $service->workspace($servicesId)['evaluation'];
$check($sev['share']['mode'] === 'reparaciones' && array_column($sev['other_contracts'], 'contrato') === ['2323'] && $near($sev['max_amount'], (1800000 + 900000) * 3), 'a property credit adds the owner other contracts to the cap (property contract at 100 %)');
$check((int) $sev['longest_months'] === \SCM\Modules\OwnerCredits\OwnerCreditCalculator::monthsUntil(time(), (int) strtotime('+5 years')) && (int) $sev['longest_months'] > (int) $sev['contract_months'], 'a property credit takes its maximum term from the longest owner contract');
$servicesState = $service->promissoryState($service->find($servicesId));
$check($servicesState['locked'] && $servicesState['required'] === ['mandato_1', 'mandato_2', 'mandato_3'], 'a public services credit is signed by every co-owner');
// La solicitud se confirma a una sola persona: libre inversión, a su beneficiario; crédito del inmueble, al principal.
$requestRecipient = new ReflectionMethod(OwnerCreditService::class, 'requestRecipient');
$repairByCoOwner = $service->find($service->createManual(20, 200000, 3, OwnerCreditSettings::REPAIR_PURPOSE, $actor, '', 'mandato_2'));
$freeByCoOwner = $service->find($service->createManual(20, 200000, 3, 'Libre inversión', $actor, '', 'mandato_2'));
$check($requestRecipient->invoke($service, $repairByCoOwner)['key'] === 'mandato_1', 'a property credit request goes only to the principal owner, even if a co-owner asked');
$check($requestRecipient->invoke($service, $freeByCoOwner)['name'] === $freeByCoOwner['owner_name'], 'a free investment request goes to its beneficiary');
// Cada destinación tiene su lista; una lista sin funcionarios cae en la de inmueble para que nadie se quede sin aviso.
$requestEvent = new ReflectionMethod(OwnerCreditService::class, 'requestEvent');
$check($requestEvent->invoke($service, $repairByCoOwner) === OwnerCreditService::EVENT_REQUESTED && $requestEvent->invoke($service, $freeByCoOwner) === OwnerCreditService::EVENT_REQUESTED, 'a list without staff falls back to the general request list');
$listsSettings = new Settings($db);
$rawLists = $db->getVar("SELECT valor FROM wp_jet_cct_confi_sistema WHERE funcion = 'control_servicios_config'");
$listsSettings->set(\SCM\Support\InternalNotificationRecipients::SETTINGS_KEY, [OwnerCreditService::EVENT_REPAIR_REQUESTED => [1], OwnerCreditService::EVENT_FREE_REQUESTED => [2], OwnerCreditService::EVENT_REQUESTED => [3]]);
$check($requestEvent->invoke($service, $repairByCoOwner) === OwnerCreditService::EVENT_REPAIR_REQUESTED, 'a manual repair request goes to the repairs list');
$check($requestEvent->invoke($service, ['purpose' => OwnerCreditSettings::REPAIR_PURPOSE, 'quote_id' => 574]) === OwnerCreditService::EVENT_REPAIR_REQUESTED, 'a quote request goes to the repairs list');
$check($requestEvent->invoke($service, $freeByCoOwner) === OwnerCreditService::EVENT_FREE_REQUESTED, 'a free investment request goes to its own list');
$check($requestEvent->invoke($service, ['purpose' => 'Cuotas de administración', 'quote_id' => 0]) === OwnerCreditService::EVENT_REQUESTED && $requestEvent->invoke($service, ['purpose' => 'Servicios públicos', 'quote_id' => 0]) === OwnerCreditService::EVENT_REQUESTED, 'services and HOA fees go to the property list');
$db->update('wp_jet_cct_confi_sistema', ['valor' => $rawLists], ['funcion' => 'control_servicios_config']);
$hoaState = $service->promissoryState($service->find($service->createManual(20, 300000, 6, 'Cuotas de administración', $actor, '', 'mandato_1')));
$check($hoaState['locked'] && count($hoaState['required']) === 3, 'an HOA fees credit is signed by every co-owner');
$freeState = $service->promissoryState($service->find($service->createManual(20, 300000, 6, 'Libre inversión', $actor, '', 'mandato_1')));
$check($freeState['locked'] && $freeState['required'] === ['mandato_1'], 'a free use credit is signed only by the requester');
$byId = array_column($service->requestPreapproval('111111')['contracts'], null, 'id');
$check($near($byId[20]['repair']['amount'], (1800000 + 900000) * 3) && $near($byId[23]['repair']['amount'], (1800000 * 0.6 + 900000) * 3), 'the new request property cap of each contract adds the owner other contracts');

// Descuento según política: el analista elige de 0 a 5 % (de a 0,5 %) sobre la tasa base; queda con la decisión, en el
// historial, en el documento interno y en la carta de aprobación.
$check(OwnerCreditService::rateDiscount('1,5') === 0.015 && OwnerCreditService::rateDiscount('7') === 0.05 && OwnerCreditService::rateDiscount('1,3') === 0.015 && OwnerCreditService::rateDiscount('') === 0.0 && count(OwnerCreditService::rateDiscounts()) === 11, 'the policy discount goes from 0 to 5 % in steps of 0,5 %');
$discountId = $service->createManual(20, 200000, 3, 'Libre inversión', $actor, '', 'mandato_2');
$discountInput = ['rate' => '1,9', 'rate_discount' => '1.50', 'amount' => '$200.000', 'term' => '3', 'requested_term' => '3'];
$discounted = $service->workspace($discountId, $discountInput);
$check($near($discounted['conditions']['rate'] * 10000, 0.019 * 0.985 * 10000) && $near($discounted['conditions']['base_rate'] * 10000, 190) && in_array('descuento', array_column($discounted['adjustments'], 'key'), true), 'the discount lowers the base rate and is listed among the changes');
$discountHtml = (new OwnerCreditView(true))->workspace($discounted, true, true);
$check(str_contains($discountHtml, 'name="rate_discount"') && str_contains($discountHtml, '<option value="1.50" selected>') && str_contains($discountHtml, 'data-owner-credit-results-below') && str_contains($discountHtml, 'data-owner-credit-reference-rate') && !str_contains($discountHtml, 'Guardar tasa de referencia') && str_contains($discountHtml, 'name="reference_rate"'), 'the liquidator shows the discount select, the blocks below and, with permission, the reference rate field');
$check(!str_contains((new OwnerCreditView(false))->workspace($discounted, true, true), 'name="reference_rate"'), 'without the configure permission the reference rate is not editable from the liquidator');
$service->decide($discountId, 'aprobado', $discountInput + ['note' => $note], $actor);
$discountCredit = $service->find($discountId);
$check($near(OwnerCreditService::rateDiscountOf($discountCredit) * 1000, 15) && $near((float) $discountCredit['approved_rate'] * 1e6, round(0.019 * 0.985, 6) * 1e6), 'the approved rate and the discount are stored with the decision');
$decidedWork = $service->workspace($discountId);
$check($near($decidedWork['conditions']['rate_discount'] * 1000, 15) && $near($decidedWork['conditions']['base_rate'] * 10000, 190), 'a decided credit keeps its base rate and discount');
$events = $db->getResults("SELECT details_json FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'aprobado'", [$discountId]);
$check(str_contains((string) $events[0]['details_json'], 'rate_discount') && str_contains((string) $db->getVar("SELECT observacion FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Crédito aprobado' ORDER BY _ID DESC LIMIT 1"), 'descuento según política de 1,50 %'), 'the discount is traced in the credit and contract histories');
$check(str_contains(\SCM\Modules\OwnerCredits\OwnerCreditPdf::html($decidedWork, 'QA'), 'Descuento según política') && str_contains(\SCM\Modules\OwnerCredits\OwnerCreditResponseDocuments::letter($discountCredit, ['signer' => ['name' => 'Analista QA', 'cargo' => ''], 'margin' => 2, 'date' => time()]), 'incluye descuento de 1,50 %'), 'the internal document and the approval letter show the discount');

// Transparencia: con la usura de otro periodo no se aprueba (rechazar sí). La automática rige hasta su "vigente hasta";
// la manual, hasta fin del mes en que empezó.
$usury = \SCM\Modules\OwnerCredits\OwnerCreditUsuryRate::class;
$check($usury::staleMessage(['usury_auto' => true, 'usury_updated_at' => '2026-10-01', 'usury_valid_until' => '2026-10-31'], '2026-10-31') === ''
  && str_contains($usury::staleMessage(['usury_auto' => true, 'usury_updated_at' => '2026-10-01', 'usury_valid_until' => '2026-10-31'], '2026-11-03'), 'Actualizar usura')
  && $usury::staleMessage(['usury_auto' => false, 'usury_updated_at' => '2026-11-01', 'usury_valid_until' => ''], '2026-11-30') === ''
  && str_contains($usury::staleMessage(['usury_auto' => false, 'usury_updated_at' => '2026-10-15', 'usury_valid_until' => '2026-12-31'], '2026-11-01'), 'Configuración'), 'the usury is current only inside its period (manual: until the end of its month)');
// Semáforo del liquidador: verde si la usura rige hoy y la referencia no la supera; rojo en caso contrario.
$current = ['usury_auto' => true, 'usury_annual' => 0.2834, 'usury_updated_at' => '2026-11-01', 'usury_valid_until' => '2026-11-30', 'reference_rate' => 0.021007];
$check($usury::status($current, '2026-11-06')['label'] === 'Tasa correcta', 'a reference rate at the current usury shows as correct');
$check(!$usury::status($current, '2026-12-01')['ok'] && str_contains($usury::status($current, '2026-12-01')['detail'], 'Actualizar usura'), 'a usury from another period shows as incorrect and says how to fix it');
$check($usury::status(['reference_rate' => 0.0242] + $current, '2026-11-06')['label'] === 'Tasa incorrecta', 'a reference rate above the usury shows as incorrect');
$rawConfig = $db->getVar("SELECT valor FROM wp_jet_cct_confi_sistema WHERE funcion = 'control_servicios_config'");
$capSettings = new Settings($db);
$capConfig = \SCM\Modules\OwnerCredits\OwnerCreditSettings::read($capSettings);
$capped = $usury::capReference($capSettings, ['reference_rate' => 0.0242, 'usury_annual' => 0.2834] + $capConfig, 1);
$check(abs($capped['after'] - 0.02101) < 1e-7 && $capped['after'] <= $usury::monthly(['usury_annual' => 0.2834]) && $usury::capReference($capSettings, ['reference_rate' => 0.02, 'usury_annual' => 0.2834] + $capConfig, 1) === null, 'updating lowers a reference rate above the usury to the usury and never raises a lower one');
$db->update('wp_jet_cct_confi_sistema', ['valor' => $rawConfig], ['funcion' => 'control_servicios_config']);
$config = json_decode((string) $db->getVar("SELECT valor FROM wp_jet_cct_confi_sistema WHERE funcion = 'control_servicios_config'"), true);
$config['owner_credit_settings']['usury_updated_at'] = date('Y-m-01', strtotime('first day of last month'));
$db->update('wp_jet_cct_confi_sistema', ['valor' => json_encode($config)], ['funcion' => 'control_servicios_config']);
$staleService = new OwnerCreditService($db, new Settings($db));
$staleId = $staleService->createManual(20, 100000, 3, 'Libre inversión', $actor, '', 'mandato_2');
$staleWork = $staleService->workspace($staleId);
$check($staleWork['usury_stale'] !== '' && str_contains((new OwnerCreditView())->workspace($staleWork, true, true), 'no está vigente hoy'), 'the liquidator warns when the usury is not current');
try {
  $staleService->decide($staleId, 'aprobado', ['rate' => '1,9', 'amount' => '$100.000', 'term' => '3', 'requested_term' => '3', 'note' => $note], $actor);
  $check(false, 'a credit cannot be approved with a usury from another period');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'no está vigente hoy') && $staleService->find($staleId)['status'] === 'pendiente', 'a credit cannot be approved with a usury from another period');
}
$check($staleService->decide($staleId, 'rechazado', ['note' => 'Rechazo de prueba con usura vencida.'], $actor)['credit']['status'] === 'rechazado', 'a credit can still be rejected while the usury is not current');

echo "Owner credit co-owner checks passed.\n";
