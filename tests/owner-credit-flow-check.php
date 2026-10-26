<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\OwnerCredits\OwnerCreditService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

final class OwnerCreditTestPdo extends PDO
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

$pdo = new OwnerCreditTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
// Usura manual (sin red) y una tasa de referencia por debajo de la usura vigente.
$pdo->exec("INSERT INTO wp_jet_cct_confi_sistema(funcion, valor) VALUES ('control_servicios_config', '" . json_encode(['owner_credit_settings' => ['usury_auto' => false, 'usury_annual' => 0.2859, 'reference_rate' => 0.019, 'notify_staff_whatsapp' => true]]) . "')");
$pdo->exec('CREATE TABLE wp_scm_owner_credits(id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER UNIQUE, ticket_id TEXT DEFAULT "", contract_id INTEGER DEFAULT 0, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", address TEXT DEFAULT "", owner_id TEXT DEFAULT "", owner_name TEXT DEFAULT "", owner_email TEXT DEFAULT "", owner_phone TEXT DEFAULT "", purpose TEXT DEFAULT "", status TEXT DEFAULT "pendiente", requested_amount REAL DEFAULT 0, requested_term INTEGER DEFAULT 0, vacancy TEXT DEFAULT "Baja", gross_income REAL DEFAULT 0, deductions_json TEXT, net_income REAL DEFAULT 0, max_amount REAL DEFAULT 0, max_months INTEGER DEFAULT 0, approved_amount REAL, approved_term INTEGER, approved_rate REAL, monthly_payment REAL, total_interest REAL, first_payment_ts INTEGER, policy_json TEXT, evaluation_json TEXT, policy_override INTEGER DEFAULT 0, decision_note TEXT, decided_by_id TEXT DEFAULT "", decided_by_name TEXT DEFAULT "", decided_at INTEGER, closed_at INTEGER, created_by_id TEXT DEFAULT "", created_by_name TEXT DEFAULT "", created_at INTEGER, updated_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_sources(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, contract_id INTEGER, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", net_income REAL DEFAULT 0, end_ts INTEGER, is_primary INTEGER DEFAULT 0, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_events(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, action TEXT, actor_id TEXT, actor_name TEXT, details_json TEXT, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, estado TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, direccion TEXT, propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, valor_canon TEXT, valor_administracion TEXT, tasa_administracion TEXT, aseguradora TEXT, asegurado TEXT, inicio_contrato TEXT, fin_contrato TEXT, esta_sinestrado TEXT, id_contrato_mandato TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, total_comision TEXT, incluye_garantia TEXT, precio_mes_garantia TEXT, incluye_garantia_servicios TEXT, precio_mes_servicios TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY, id_ticket TEXT, contrato TEXT, id_contrato TEXT, total TEXT, financiacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_ticket(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, id_empleado TEXT, cct_created TEXT, cct_modified TEXT, fecha INTEGER, id_ticket TEXT, id_cotizacion_mantenimiento TEXT, nombre TEXT, respuesta TEXT, respuesta_cct_ticket TEXT, fue_editada TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_contrato TEXT, contrato TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_propietarios(_ID INTEGER PRIMARY KEY, id_propietario TEXT, nombre TEXT, documento TEXT, documento_juridico TEXT)');

$end = (string) strtotime('+14 months');
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 30, 'total_comision' => '142.800', 'incluye_garantia' => 'Si', 'precio_mes_garantia' => '29.400', 'incluye_garantia_servicios' => 'Si', 'precio_mes_servicios' => '52.661,669']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 7, 'contrato' => '817', 'estado' => 'Entregado', 'inmueble' => '10420', 'id_inmueble' => '10420', 'direccion' => 'Calle 1', 'propietario' => 'Ana Dueña', 'id_propietario' => '956', 'correo_propietario' => 'ana@example.invalid', 'celular_propietario' => '3001234567', 'indicativo_propietario' => '57', 'valor_canon' => '1.075.000', 'valor_administracion' => '125.000', 'inicio_contrato' => (string) strtotime('-2 years'), 'fin_contrato' => $end, 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '30']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 8, 'contrato' => '900', 'estado' => 'Entregado', 'inmueble' => '10500', 'propietario' => 'Ana Dueña', 'id_propietario' => '956', 'valor_canon' => '2.000.000', 'valor_administracion' => '0', 'tasa_administracion' => '10', 'fin_contrato' => (string) strtotime('+2 months'), 'esta_sinestrado' => 'Si']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 574, 'id_ticket' => '10821', 'contrato' => '817', 'id_contrato' => '7', 'total' => '893723', 'financiacion' => 'Si']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 575, 'id_ticket' => '10822', 'contrato' => '900', 'id_contrato' => '8', 'total' => '2500000', 'financiacion' => 'Si']);

$service = new OwnerCreditService($db, new Settings($db));
$actor = ['employee_id' => '2999', 'name' => 'Analista QA'];

$service->syncFromQuote(574, 'Si', $actor);
$credit = $service->byQuote(574);
$check($credit !== null && $credit['status'] === 'pendiente' && (float) $credit['requested_amount'] === 893723.0, 'financing "Sí" creates a pending request for the quote total');
$check($credit['beneficiary_type'] === 'propietario' && OwnerCreditService::beneficiaryLabel($credit) === 'Propietario', 'quote credits are categorized as owner credits');
$check($credit['owner_id'] === '956' && $credit['ticket_id'] === '10821' && $credit['owner_phone'] === '573001234567', 'request keeps owner, case and phone with country code');
$service->syncFromQuote(574, 'Si', $actor);
$check(count($db->getResults('SELECT id FROM wp_scm_owner_credits')) === 1, 'repeating financing "Sí" does not duplicate the request');

$work = $service->workspace((int) $credit['id'], ['requested_term' => '12']);
$check($work['evaluation']['gross'] === 1075000.0 && abs($work['evaluation']['deductions_total'] - (142800 + 29400 + 52661.669)) < 0.01, 'income uses canon and mandate commission, guarantee and utilities policy');
$check($work['evaluation']['contract_months'] >= 13 && $work['evaluation']['max_months'] === $work['evaluation']['contract_months'] - 2 && $work['policies_ok'], 'a healthy contract passes every policy with the suggested conditions');

try {
  $service->decide((int) $credit['id'], 'aprobado', ['requested_term' => '12', 'amount' => '$5.000.000', 'term' => '12', 'rate' => '2,1'], $actor);
  $check(false, 'approving above the cap without justification must fail');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'justificación'), 'approving outside policy requires a justification');
}
try {
  $service->decide((int) $credit['id'], 'aprobado', ['requested_term' => '12', 'amount' => '$3.000.000', 'term' => '12', 'rate' => '2,3', 'note' => 'Propietario con 10 años sin mora, se aprueba excepción.'], $actor);
  $check(false, 'approving above the usury must fail even with justification');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'usura'), 'approving above the usury fails even with justification');
}
$result = $service->decide((int) $credit['id'], 'aprobado', ['requested_term' => '12', 'amount' => '$3.000.000', 'term' => '12', 'rate' => '2,1', 'note' => 'Propietario con 10 años sin mora, se aprueba excepción.'], $actor);
$approved = $result['credit'];
$check(array_key_exists('internal_whatsapp', $result['queued']), 'staff WhatsApp is attempted on decisions without breaking them when no staff is configured');
$check($approved['status'] === 'aprobado' && (int) $approved['policy_override'] === 1 && (float) $approved['approved_amount'] === 3000000.0 && (int) $approved['approved_term'] === 12, 'approved outside policy with conditions and override flag');
$approvedWork = $service->workspace((int) $approved['id']);
$check($approvedWork['credit']['status'] === 'aprobado' && (int) $approved['disbursed_at'] === (int) strtotime('today') && (int) $approved['first_payment_ts'] === (int) strtotime('+1 month', strtotime('today')) && $approvedWork['schedule'][0]['fecha'] === (int) $approved['first_payment_ts'], 'approval stores the disbursement date and the first instalment one month later');
$check(count($db->getResults('SELECT * FROM wp_scm_owner_credit_sources WHERE credit_id = ?', [(int) $approved['id']])) === 1, 'approval stores the contracts that back the credit');
$check(abs((float) $approved['monthly_payment'] - 285423.85) < 1 && abs((float) $approved['approved_rate'] - 0.021) < 1e-9, 'monthly payment is recalculated on the server');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = ?', ['10821'])) === 2, 'request and decision are written to the case history');
$contractHistory = $db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento WHERE id_contrato = ?', ['7']);
$check(count($contractHistory) === 2 && $contractHistory[0]['tipo_reporte'] === 'Solicitud de crédito' && str_contains($contractHistory[0]['observacion'], 'solicitado por el propietario Ana Dueña: $893.723 para la cotización #574') && $contractHistory[1]['tipo_reporte'] === 'Crédito aprobado' && str_contains($contractHistory[1]['observacion'], 'fuera de política'), 'request and decision are written to the contract history');
$propertyHistory = $db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = ?', ['10420']);
$check(count($propertyHistory) === 2 && $propertyHistory[0]['tipo_reporte'] === 'Solicitud de crédito' && $propertyHistory[1]['tipo_reporte'] === 'Crédito aprobado' && str_starts_with($propertyHistory[1]['observacion'], 'Contrato #817: Crédito #') && $propertyHistory[1]['nombre_empleado'] === 'Analista QA', 'request and decision are written to the property history with the contract');
try {
  $service->decide((int) $credit['id'], 'rechazado', ['note' => 'Intento repetido de decisión.'], $actor);
  $check(false, 'a decided credit cannot be decided again');
} catch (DomainException) {
  $check(true, 'a decided credit cannot be decided again');
}

$service->syncFromQuote(575, 'Si', $actor);
$second = $service->byQuote(575);
$secondWork = $service->workspace((int) $second['id']);
$failed = array_column(array_filter($secondWork['policies'], static fn(array $p): bool => !$p['ok']), 'key');
$check(in_array('prestamos', $failed, true) && in_array('siniestro', $failed, true) && in_array('vigencia', $failed, true), 'second request flags active loan, siniestro and contract about to expire');
$usury = array_values(array_filter($secondWork['policies'], static fn(array $p): bool => $p['key'] === 'usura'))[0];
$check($usury['ok'] && str_contains($usury['detail'], '28,59 % E.A.'), 'usury policy compares against the effective annual rate');
$high = $service->workspace((int) $second['id'], ['rate' => '2,2']);
$check(!array_values(array_filter($high['policies'], static fn(array $p): bool => $p['key'] === 'usura'))[0]['ok'], '2,2 % monthly (29,8 % E.A.) is above the 28,59 % E.A. usury even though 28,59/12 = 2,38 %');
$check(str_contains($secondWork['income_source'], '15 %') && $secondWork['evaluation']['deductions']['otros'] === 300000.0 && $secondWork['evaluation']['deductions']['comision'] === 0.0, 'without mandate 15 % of the canon is deducted');
try {
  $service->decide((int) $second['id'], 'rechazado', ['note' => 'corto'], $actor);
  $check(false, 'rejecting needs a reason');
} catch (DomainException) {
  $check(true, 'rejecting needs a reason');
}
$service->decide((int) $second['id'], 'rechazado', ['note' => 'Contrato siniestrado y con crédito vigente.'], $actor);
$check($service->find((int) $second['id'])['status'] === 'rechazado', 'request is rejected');

$service->changeStatus((int) $credit['id'], 'pagado', 'Se descontó la última cuota en el giro.', $actor);
$check($service->find((int) $credit['id'])['status'] === 'pagado', 'approved credit can be marked as paid');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = ?', ['10420'])) === 3, 'marking as paid is also written to the property history');
$check(!in_array('prestamos', array_column(array_filter($service->workspace((int) $second['id'])['policies'], static fn(array $p): bool => !$p['ok']), 'key'), true), 'a paid credit no longer counts as an active loan');

$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 576, 'id_ticket' => '10823', 'contrato' => '817', 'id_contrato' => '7', 'total' => '100000', 'financiacion' => 'Si']);
$service->syncFromQuote(576, 'Si', $actor);
$service->syncFromQuote(576, 'No', $actor);
$check($service->byQuote(576)['status'] === 'anulado', 'financing "No" annuls the pending request');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = ?', ['10823'])) === 2, 'automatic annulment is written to the histories');
$service->syncFromQuote(576, 'Si', $actor);
$check($service->byQuote(576)['status'] === 'pendiente', 'financing "Sí" again reopens the annulled request');

$report = $service->report([]);
$check($report['totals']['count'] === 3 && $report['totals']['pagado'] === 1 && $report['totals']['rechazado'] === 1 && $report['totals']['approval_rate'] === 0.5, 'report counts credits by status and approval rate');
$check(count($report['rows']) === 3 && $service->statusesForQuotes([574, 575, 999]) === [574 => 'pagado', 575 => 'rechazado'], 'quote cards read the credit status');

// Plazo solicitado limitado por la fecha fin, fecha fin editable desde el liquidador y crédito manual por contrato.
$pending = $service->byQuote(576);
$check((int) $pending['requested_term'] === 0 && str_contains($service->workspace((int) $pending['id'])['term_error'], 'Escribe el plazo'), 'new requests start without a requested term until someone types it');
$tooLong = $service->workspace((int) $pending['id'], ['requested_term' => '30']);
$check(str_contains($tooLong['term_error'], 'no puede superar'), 'requested term longer than the months left is an error');
try {
  $service->decide((int) $pending['id'], 'aprobado', ['requested_term' => '30', 'note' => 'Intento con plazo inválido.'], $actor);
  $check(false, 'approval with an invalid requested term must fail');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'no puede superar'), 'approval is blocked while the requested term exceeds the contract');
}
$newEnd = date('Y-m-d', strtotime('+3 years'));
$simulated = $service->workspace((int) $pending['id'], ['requested_term' => '10', 'contract_end' => $newEnd]);
$check($simulated['term_error'] === '' && $simulated['contract']['fin_ts'] !== $simulated['contract']['fin_saved_ts'], 'a corrected end date is simulated before saving');
// Con vigencia de sobra (36 meses − 2) el plazo no pasa del tope de política de 12 meses.
$capped = $service->workspace((int) $pending['id'], ['requested_term' => '30', 'contract_end' => $newEnd]);
$check($capped['evaluation']['max_months'] === 12 && str_contains($capped['term_error'], 'tope de política de 12 meses'), 'the maximum term never exceeds the 12-month policy cap');
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 7')['fin_contrato'] === $end, 'simulating does not touch the contract');
$check(!method_exists($service, 'updateContractEnd'), 'the end date is no longer saved with a separate button');
$service->decide((int) $pending['id'], 'rechazado', ['requested_term' => '30', 'contract_end' => $newEnd, 'note' => 'Se rechaza tras corregir la fecha fin.'], $actor);
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 7')['fin_contrato'] === (string) strtotime($newEnd . ' 00:00:00'), 'the corrected end date is saved in the contract at midnight when deciding');
$endHistory = $db->getResults("SELECT observacion FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Actualización de fecha fin'");
$check(count($endHistory) === 1 && str_contains($endHistory[0]['observacion'], ' → ' . date('d/m/Y', strtotime($newEnd))), 'the end date change is written to the contract history with old and new dates');
// El consultor no conoce la fecha fin: la solicitud se registra y el liquidador marca el plazo para el analista.
$longTerm = $service->createManual(8, 1000000, 6, 'Libre Destinación', $actor);
$check(str_contains($service->workspace($longTerm)['term_error'], 'no puede superar'), 'a request longer than the contract is registered and flagged in the liquidator');
$manual = $service->find($service->createManual(8, 1000000, 6, 'Libre Destinación', $actor, date('Y-m-d', strtotime('+1 year'))));
$check($manual['status'] === 'pendiente' && $manual['quote_id'] === null && (int) $manual['requested_term'] === 6, 'manual credit is created from the selected contract');
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 8')['fin_contrato'] === (string) strtotime(date('Y-m-d', strtotime('+1 year')) . ' 00:00:00'), 'manual credit saves the corrected contract end date');

// Mandato con dos propietarios: se elige a quién se le hace el crédito.
foreach (['propietarios', 'tipo_1', 'nombre_1', 'documento_1', 'correo_1', 'celular_1', 'indicativo_1', 'empresa_1', 'nit_1', 'id_propietario_nuevo_1', 'tipo_2', 'nombre_2', 'documento_2', 'correo_2', 'celular_2', 'indicativo_2', 'empresa_2', 'nit_2', 'id_propietario_nuevo_2'] as $column) {
  $pdo->exec("ALTER TABLE wp_jet_cct_contrato_mandato ADD COLUMN {$column} TEXT");
}
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 31, 'total_comision' => '100.000', 'incluye_garantia' => 'No', 'incluye_garantia_servicios' => 'No', 'propietarios' => '2',
  'tipo_1' => 'Natural', 'nombre_1' => 'Primer Dueño', 'documento_1' => '111', 'celular_1' => '3001110000', 'indicativo_1' => '+57', 'id_propietario_nuevo_1' => '960',
  'tipo_2' => 'Natural', 'nombre_2' => 'Segunda Dueña', 'documento_2' => '22233344', 'correo_2' => 'segunda@example.invalid', 'celular_2' => '+1 (813) 389-8958', 'indicativo_2' => '+57']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 9, 'contrato' => '950', 'estado' => 'Entregado', 'inmueble' => '10600', 'propietario' => 'Primer Dueño', 'id_propietario' => '960', 'valor_canon' => '1.500.000', 'fin_contrato' => (string) strtotime('+2 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '31']);
$owners = $service->contractOwners(9);
$byDocument = $service->contractsByOwnerDocument('2223');
$check(count($byDocument) === 0, 'document search is exact, not partial');
foreach ([11 => 'Por entregar', 12 => 'Terminado', 13 => 'Desocupado'] as $contractId => $estado) {
  $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => $contractId, 'contrato' => (string) (940 + $contractId), 'estado' => $estado, 'inmueble' => '10600', 'propietario' => 'Primer Dueño', 'id_propietario' => '960', 'valor_canon' => '1.500.000', 'fin_contrato' => (string) strtotime('+2 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '31']);
}
$byDocument = $service->contractsByOwnerDocument(' 22.233.344 ');
$check(array_map(static fn(array $row): string => (string) $row['contract']['estado'], $byDocument) === ['Entregado', 'Por entregar'], 'only Entregado and Por entregar contracts are offered, Entregado first');
$pdo->exec('DELETE FROM wp_jet_cct_contratos_arrendamiento WHERE _ID IN (11, 12, 13)');
$byDocument = $service->contractsByOwnerDocument(' 22.233.344 ');
$check(count($byDocument) === 1 && (int) $byDocument[0]['contract']['_ID'] === 9 && $byDocument[0]['owner']['key'] === 'mandato_2' && $byDocument[0]['owner']['name'] === 'Segunda Dueña', 'a mandate co-owner is found by document and resolved as the beneficiary');
try {
  $service->contractsByOwnerDocument('12');
  $check(false, 'short documents are rejected');
} catch (InvalidArgumentException) {
  $check(true, 'short documents are rejected');
}
$check(count($owners) === 2 && $owners[1]['name'] === 'Segunda Dueña' && $owners[1]['phone'] === '18133898958' && $owners[0]['phone'] === '573001110000', 'mandate owners are offered as beneficiaries with normalized phones');
try {
  $service->createManual(9, 800000, 6, 'Libre Destinación', $actor);
  $check(false, 'with several owners the beneficiary must be chosen');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'a quién se le hace el crédito'), 'with several owners the beneficiary must be chosen');
}
$chosen = $service->find($service->createManual(9, 800000, 6, 'Libre Destinación', $actor, '', 'mandato_2'));
$check($chosen['owner_name'] === 'Segunda Dueña' && $chosen['owner_document'] === '22233344' && $chosen['owner_email'] === 'segunda@example.invalid' && $chosen['owner_id'] === 'doc:22233344', 'the credit is registered to the chosen owner');
$check($chosen['beneficiary_type'] === 'propietario', 'manual credits are categorized as owner credits');
try {
  $service->createManual(9, 800000, 6, 'Libre Destinación', $actor, '', 'mandato_2', 'funcionario');
  $check(false, 'beneficiary types without a flow are rejected');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'todavía no están disponibles'), 'beneficiary types without a flow are rejected');
}

// Varios contratos del mismo propietario: el de vigencia más larga va primero y suma al tope del liquidador.
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 14, 'contrato' => '954', 'estado' => 'Entregado', 'inmueble' => '10601', 'propietario' => 'Primer Dueño', 'id_propietario' => '960', 'valor_canon' => '2.000.000', 'fin_contrato' => (string) strtotime('+3 years'), 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '31']);
$portfolio = $service->ownerPortfolio('22233344');
$check(array_column($portfolio, 'id') === [14, 9] && $portfolio[0]['net_income'] == 1900000.0, 'the owner portfolio lists the longest contract first, with its net income');
$work = $service->workspace((int) $chosen['id']);
$ev = $work['evaluation'];
$check(count($ev['other_contracts']) === 1 && abs($ev['total_net_income'] - 3300000) < 0.01 && abs($ev['max_amount'] - 9900000) < 0.01, 'the liquidator adds the other contracts to the cap');
$check($ev['longest_months'] === $portfolio[0]['max_months'] && $ev['max_months'] === min(12, $portfolio[0]['max_months'] - 2), 'the liquidator term is the longest contract minus the margin, capped at 12');
$simulation = $service->simulation(' 22.233.344 ', 9, 0, 0, 'Libre Destinación', 'mandato_2', $actor);
$check($simulation['credit']['status'] === 'simulacion' && $simulation['credit']['owner_name'] === 'Segunda Dueña' && abs($simulation['conditions']['amount'] - 9900000) < 0.01 && $simulation['conditions']['term'] === $ev['max_months'], 'an empty simulation uses the pre-approved amount and the maximum term');
$credits = (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credits');
$service->recordSimulation($simulation, $actor);
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credits') === $credits && (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Simulación de crédito'") === 2, 'a simulation is not saved as a credit and goes to the history of every owner property');
$simulationHtml = \SCM\Modules\OwnerCredits\OwnerCreditPdf::html($simulation, 'Funcionario QA');
$check(str_contains($simulationHtml, 'Simulación de crédito · Contrato 950') && str_contains($simulationHtml, 'Ingreso neto total (2 contratos)') && str_contains($simulationHtml, '(principal)') && !str_contains($simulationHtml, 'Probabilidad de desocupación') && str_contains($simulationHtml, 'no es una aprobación'), 'the simulation PDF has one income table for all the contracts, no vacancy and says it is not an approval');
// Aprobado con varios contratos: quedan como respaldo y en su historial; al vencer la última cuota queda pagado solo.
$service->decide((int) $chosen['id'], 'aprobado', ['requested_term' => '3', 'amount' => '$800.000', 'term' => '3', 'rate' => '1,9', 'disbursed_at' => date('Y-m-d', strtotime('-1 year')), 'note' => 'Desembolsado hace un año, prueba de cierre automático.'], $actor);
$check(count($db->getResults('SELECT * FROM wp_scm_owner_credit_sources WHERE credit_id = ?', [(int) $chosen['id']])) === 2, 'every contract of the owner backs the approved credit');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Crédito aprobado' AND observacion LIKE 'Contrato #954:%respalda el crédito%'") === 1, 'the approval is written to the history of the other backing contracts');
$settled = $service->workspace((int) $chosen['id']);
$check($settled['credit']['status'] === 'pagado' && str_contains((string) $settled['credit']['decision_note'], 'Pagado automáticamente') && $settled['events'][0]['actor_name'] === 'Sistema', 'a credit whose last instalment is past is marked as paid automatically');
$check($service->settleFinished() === 0, 'an automatically paid credit is not closed twice');

// Cupo preaprobado en el liquidador y cambio de beneficiario mientras está pendiente.
$pending = $service->workspace($service->createManual(9, 12000000, 6, 'Libre Destinación', $actor, '', 'mandato_2'));
$p = $pending['preapproval'];
$check(abs($p['amount'] - 9900000) < 0.01 && $p['contracts'] === 2 && $p['outstanding'] == 0.0 && abs($p['requested'] - 12000000) < 0.01, 'the liquidator shows the pre-approved amount of all the owner contracts');
$pendingHtml = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($pending, true, true);
$check(str_contains($pendingHtml, 'Cupo preaprobado del propietario') && str_contains($pendingHtml, 'supera el cupo disponible') && str_contains($pendingHtml, 'name="owner_key"'), 'the liquidator shows the cupo, warns when the request exceeds it and lets the owner be changed');
$check(array_column(array_filter($pending['owners'], static fn(array $o): bool => $o['current']), 'key') === ['mandato_2'], 'the current beneficiary is preselected among the contract owners');
$changed = $service->changeOwner((int) $pending['credit']['id'], 'mandato_1', $actor);
$check($changed['owner_name'] === 'Primer Dueño' && $changed['owner_document'] === '111' && $changed['owner_id'] === '960', 'a pending credit can be moved to another owner of the contract');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'beneficiario'", [(int) $changed['id']]) === 1 && (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Cambio de beneficiario del crédito'") === 1, 'the owner change is traced in the credit and contract histories');
try {
  $service->changeOwner((int) $chosen['id'], 'mandato_1', $actor);
  $check(false, 'the owner of a decided credit cannot be changed');
} catch (DomainException) {
  $check(true, 'the owner of a decided credit cannot be changed');
}

// Políticas sobre todos los contratos de la persona; el analista corrige siniestro y créditos con Sí/No.
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'Si'], ['_ID' => 14]);
$policy = static fn(array $work, string $key): array => array_values(array_filter($work['policies'], static fn(array $p): bool => $p['key'] === $key))[0];
// El documento "111" es muy corto para buscar contratos: se vuelve a la copropietaria.
$changed = $service->changeOwner((int) $changed['id'], 'mandato_2', $actor);
$all = $service->workspace((int) $changed['id']);
$check(!$policy($all, 'siniestro')['ok'] && str_contains($policy($all, 'siniestro')['detail'], '#954') && $all['evaluation']['analyst']['siniestro'] === true, 'a siniestro on another contract of the owner fails the policy');
$check($policy($all, 'contrato_activo')['ok'] && str_contains($policy($all, 'contrato_activo')['detail'], 'de 2 contratos Entregado'), 'the active contract policy looks at every contract');
$cleared = $service->workspace((int) $changed['id'], ['siniestro' => 'no', 'has_credits' => 'si']);
$check($policy($cleared, 'siniestro')['ok'] && str_contains($policy($cleared, 'siniestro')['detail'], 'el analista indicó que no'), 'the analyst can clear the siniestro after checking with Cartera');
$check(!$policy($cleared, 'prestamos')['ok'] && str_contains($policy($cleared, 'prestamos')['detail'], 'por fuera del sistema') && $cleared['evaluation']['analyst']['overrides'] === ['siniestro', 'has_credits'], 'credits outside the system marked by the analyst need a justification');
$checks = $service->requestChecks($service->ownerPortfolio('22233344'), ['outstanding' => 0, 'available' => 1, 'max_term' => 3]);
$check(array_column($checks, 'ok', 'label')['Sin siniestro'] === false && array_column($checks, 'ok', 'label')['Sin créditos vigentes'] === true, 'the request form shows the policy pre-check over every contract');
$moved = $service->changeContract((int) $changed['id'], 14, $actor);
$check((int) $moved['contract_id'] === 14 && $moved['contract_code'] === '954' && (float) $moved['gross_income'] === 2000000.0, 'the analyst can change the main contract to another contract of the owner');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'contrato_principal'", [(int) $moved['id']]) === 1 && (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Cambio de contrato principal del crédito'") === 1, 'the main contract change is traced');
try {
  $service->changeContract((int) $moved['id'], 7, $actor);
  $check(false, 'the main contract must be a contract of the owner');
} catch (DomainException) {
  $check(true, 'the main contract must be a contract of the owner');
}
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'No'], ['_ID' => 14]);

// Contrato sin mandato: el documento sale de la ficha del propietario y el saldo se encuentra también por la ficha.
$db->insert('wp_jet_cct_propietarios', ['_ID' => 970, 'id_propietario' => '970', 'nombre' => 'Sin Mandato', 'documento' => '55.555.555']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 20, 'contrato' => '980', 'estado' => 'Entregado', 'inmueble' => '10700', 'propietario' => 'Sin Mandato', 'id_propietario' => '970', 'valor_canon' => '1.000.000', 'fin_contrato' => (string) strtotime('+2 years'), 'esta_sinestrado' => 'No']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 590, 'id_ticket' => '10900', 'contrato' => '980', 'id_contrato' => '20', 'total' => '400000', 'financiacion' => 'Si']);
$service->syncFromQuote(590, 'Si', $actor);
$check($service->byQuote(590)['owner_document'] === '55.555.555', 'a quote credit on a contract without mandate takes the document from the owner record');
$db->insert('wp_scm_owner_credits', ['contract_id' => 20, 'contract_code' => '980', 'owner_id' => '970', 'owner_name' => 'Sin Mandato', 'owner_document' => '', 'status' => 'pendiente', 'requested_amount' => 300000, 'created_at' => time(), 'updated_at' => time()]);
$legacy = (int) $db->lastInsertId();
$service->workspace($legacy);
$check($service->find($legacy)['owner_document'] === '55.555.555', 'opening a pending credit saved without document completes it');
$db->insert('wp_scm_owner_credits', ['contract_id' => 20, 'contract_code' => '980', 'owner_id' => '970', 'owner_name' => 'Sin Mandato', 'owner_document' => '', 'status' => 'aprobado', 'approved_amount' => 1000000, 'approved_term' => 10, 'approved_rate' => 0.02, 'first_payment_ts' => strtotime('+1 month'), 'created_at' => time(), 'updated_at' => time()]);
$check(abs($service->outstandingByDocument('55555555') - 0) < 0.01 && abs($service->outstandingByDocument('55555555', ['970']) - 1000000) < 0.01, 'the outstanding balance also finds approved credits by owner record');

try {
  $service->simulation('22233344', 12345, 0, 0, '', '', $actor);
  $check(false, 'a simulation needs a contract of the owner');
} catch (DomainException) {
  $check(true, 'a simulation needs a contract of the owner');
}

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/OwnerCredits/OwnerCreditService.php');
$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-funcionario-v2-template.json'), true);
$check(str_contains($source, "'" . $template['name'] . "'") && substr_count($template['components'][0]['text'], '{{') === 6, 'staff template name and its six variables match the code');

echo "Owner credit flow checks passed.\n";
