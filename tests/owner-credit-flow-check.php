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
$pdo->exec("INSERT INTO wp_jet_cct_confi_sistema(funcion, valor) VALUES ('control_servicios_config', '" . json_encode(['owner_credit_settings' => ['usury_auto' => false, 'usury_annual' => 0.2859, 'reference_rate' => 0.019, 'notify_staff_whatsapp' => true, 'require_promissory' => false, 'max_term' => 24]]) . "')");
$pdo->exec('CREATE TABLE wp_scm_owner_credits(id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER UNIQUE, ticket_id TEXT DEFAULT "", contract_id INTEGER DEFAULT 0, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", address TEXT DEFAULT "", owner_id TEXT DEFAULT "", owner_name TEXT DEFAULT "", owner_email TEXT DEFAULT "", owner_phone TEXT DEFAULT "", purpose TEXT DEFAULT "", status TEXT DEFAULT "pendiente", requested_amount REAL DEFAULT 0, requested_term INTEGER DEFAULT 0, vacancy TEXT DEFAULT "Baja", gross_income REAL DEFAULT 0, deductions_json TEXT, net_income REAL DEFAULT 0, max_amount REAL DEFAULT 0, max_months INTEGER DEFAULT 0, approved_amount REAL, approved_term INTEGER, approved_rate REAL, monthly_payment REAL, total_interest REAL, first_payment_ts INTEGER, policy_json TEXT, evaluation_json TEXT, policy_override INTEGER DEFAULT 0, decision_note TEXT, decided_by_id TEXT DEFAULT "", decided_by_name TEXT DEFAULT "", decided_at INTEGER, closed_at INTEGER, created_by_id TEXT DEFAULT "", created_by_name TEXT DEFAULT "", created_at INTEGER, updated_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_sources(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, contract_id INTEGER, contract_code TEXT DEFAULT "", property TEXT DEFAULT "", net_income REAL DEFAULT 0, end_ts INTEGER, is_primary INTEGER DEFAULT 0, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_promissory(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_document TEXT, owner_id TEXT DEFAULT "", owner_name TEXT DEFAULT "", owner_email TEXT DEFAULT "", owner_phone TEXT DEFAULT "", credit_id INTEGER DEFAULT 0, status TEXT DEFAULT "pendiente", payload_json TEXT, payload_hash TEXT, token_nonce TEXT, expires_at INTEGER, channels TEXT DEFAULT "email", otp_json TEXT, delivery_json TEXT, signed_at INTEGER, signed_json TEXT, signed_pdf BLOB, pdf_hash TEXT, pdf_hmac TEXT, voided_at INTEGER, void_note TEXT, voided_by_name TEXT DEFAULT "", created_by_id TEXT DEFAULT "", created_by_name TEXT DEFAULT "", created_at INTEGER, updated_at INTEGER)');
$pdo->exec('CREATE TABLE wp_scm_owner_credit_events(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, action TEXT, actor_id TEXT, actor_name TEXT, details_json TEXT, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, estado TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, direccion TEXT, propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, valor_canon TEXT, valor_administracion TEXT, tasa_administracion TEXT, aseguradora TEXT, asegurado TEXT, inicio_contrato TEXT, fin_contrato TEXT, esta_sinestrado TEXT, id_contrato_mandato TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, total_comision TEXT, incluye_garantia TEXT, precio_mes_garantia TEXT, incluye_garantia_servicios TEXT, precio_mes_servicios TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY, id_ticket TEXT, contrato TEXT, id_contrato TEXT, total TEXT, financiacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_ticket(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, id_empleado TEXT, cct_created TEXT, cct_modified TEXT, fecha INTEGER, id_ticket TEXT, id_cotizacion_mantenimiento TEXT, nombre TEXT, respuesta TEXT, respuesta_cct_ticket TEXT, fue_editada TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_contrato TEXT, contrato TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_scm_contract_renewal(contract_id INTEGER PRIMARY KEY, end_ts INTEGER, probability REAL, no_exit INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE wp_scm_contract_renewal_events(id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER, action TEXT, actor TEXT, details_json TEXT, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_jet_cct_propietarios(_ID INTEGER PRIMARY KEY, id_propietario TEXT, nombre TEXT, documento TEXT, documento_juridico TEXT)');

$end = (string) strtotime('+14 months');
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 30, 'total_comision' => '142.800', 'incluye_garantia' => 'Si', 'precio_mes_garantia' => '29.400', 'incluye_garantia_servicios' => 'Si', 'precio_mes_servicios' => '52.661,669']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 7, 'contrato' => '817', 'estado' => 'Entregado', 'inmueble' => '10420', 'id_inmueble' => '10420', 'direccion' => 'Calle 1', 'propietario' => 'Ana Dueña', 'id_propietario' => '956', 'correo_propietario' => 'ana@example.invalid', 'celular_propietario' => '3001234567', 'indicativo_propietario' => '57', 'valor_canon' => '1.075.000', 'valor_administracion' => '125.000', 'inicio_contrato' => (string) strtotime('-2 years'), 'fin_contrato' => $end, 'esta_sinestrado' => 'No', 'id_contrato_mandato' => '30']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 8, 'contrato' => '900', 'estado' => 'Entregado', 'inmueble' => '10500', 'propietario' => 'Ana Dueña', 'id_propietario' => '956', 'valor_canon' => '2.000.000', 'valor_administracion' => '0', 'tasa_administracion' => '10', 'fin_contrato' => (string) strtotime('+2 months'), 'esta_sinestrado' => 'Si']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 574, 'id_ticket' => '10821', 'contrato' => '817', 'id_contrato' => '7', 'total' => '893723', 'financiacion' => 'Si']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 575, 'id_ticket' => '10822', 'contrato' => '900', 'id_contrato' => '8', 'total' => '2500000', 'financiacion' => 'Si']);

$service = new OwnerCreditService($db, new Settings($db));
$actor = ['employee_id' => '2999', 'name' => 'Analista QA'];
// La respuesta genera PDF con Chromium y los guarda en la carpeta de cargas: en la prueba se simulan.
$responsePdfs = [];
$responseFiles = [];
$fakeResponsePdf = static function (string $html, string $title) use (&$responsePdfs): string { $responsePdfs[] = ['title' => $title, 'html' => $html]; return '%PDF-1.4 ' . $title; };
$fakeResponseStore = static function (string $bytes, string $name) use (&$responseFiles): array { $responseFiles[] = $name; return ['path' => sys_get_temp_dir() . '/' . $name, 'url' => 'https://example.test/file.php?n=' . rawurlencode($name)]; };
$service->useResponseFiles($fakeResponsePdf, $fakeResponseStore);

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
$check($approvedWork['credit']['status'] === 'aprobado' && (int) $approved['disbursed_at'] === (int) strtotime('today') && (int) $approved['first_payment_ts'] === \SCM\Modules\OwnerCredits\OwnerCreditCoverage::firstDueTs((int) strtotime('today')) && $approvedWork['schedule'][0]['fecha'] === (int) $approved['first_payment_ts'] && in_array((int) date('j', (int) $approved['first_payment_ts']), [28, 29, 30], true), 'approval stores the disbursement date and the first installment on the agreed calendar (day 30, 15/16 cut-off)');
$check(count($db->getResults('SELECT * FROM wp_scm_owner_credit_sources WHERE credit_id = ?', [(int) $approved['id']])) === 1, 'approval stores the contracts that back the credit');
$check(abs((float) $approved['monthly_payment'] - 285423.85) < 1 && abs((float) $approved['approved_rate'] - 0.021) < 1e-9, 'monthly payment is recalculated on the server');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = ?', ['10821'])) === 3, 'request, decision and response are written to the case history');
$check((int) $approved['response_sent_at'] > 0 && $result['next']['response'] !== null && count($result['next']['response']['documents']) === 2, 'without a required promissory note the response (letter and statement) is sent on approval');
$contractHistory = $db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento WHERE id_contrato = ?', ['7']);
$check(count($contractHistory) === 3 && $contractHistory[2]['tipo_reporte'] === 'Respuesta de crédito enviada' && $contractHistory[0]['tipo_reporte'] === 'Solicitud de crédito' && str_contains($contractHistory[0]['observacion'], 'solicitado por el propietario Ana Dueña: $893.723 para la cotización #574') && $contractHistory[1]['tipo_reporte'] === 'Crédito aprobado' && str_contains($contractHistory[1]['observacion'], 'fuera de política'), 'request and decision are written to the contract history');
$propertyHistory = $db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = ?', ['10420']);
$check(count($propertyHistory) === 3 && $propertyHistory[0]['tipo_reporte'] === 'Solicitud de crédito' && $propertyHistory[1]['tipo_reporte'] === 'Crédito aprobado' && str_starts_with($propertyHistory[1]['observacion'], 'Contrato #817: Crédito #') && $propertyHistory[1]['nombre_empleado'] === 'Analista QA', 'request and decision are written to the property history with the contract');
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
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = ?', ['10420'])) === 4, 'marking as paid is also written to the property history');
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
// Con vigencia de 36 meses n − 2 pasa el tope de Configuración (24 en esta prueba): el liquidador limita al tope.
$capped = $service->workspace((int) $pending['id'], ['requested_term' => '40', 'contract_end' => $newEnd]);
$check($capped['evaluation']['longest_months'] - 2 > 24 && $capped['evaluation']['term_limit'] === 24 && str_contains($capped['term_error'], '− 2 de margen, tope 24 meses'), 'the liquidator caps n − 2 at the configured maximum term');
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
$people = $service->ownersByName('  SEGUNDA ');
$check(count($people) === 1 && $people[0]['document'] === '22233344' && $people[0]['name'] === 'Segunda Dueña', 'the new request finds the owner by name and returns their document');
$check($service->ownersByName('Nadie Inexistente') === [], 'a name with no owner returns nobody');
$check(array_column($portfolio, 'id') === [14, 9] && $portfolio[0]['net_income'] == 1900000.0, 'the owner portfolio lists the longest contract first, with its net income');
$work = $service->workspace((int) $chosen['id']);
$ev = $work['evaluation'];
$check(count($ev['other_contracts']) === 1 && abs($ev['total_net_income'] - 3300000) < 0.01 && abs($ev['max_amount'] - 9900000) < 0.01, 'the liquidator adds the other contracts to the cap');
$check($ev['longest_months'] === $portfolio[0]['max_months'] && $ev['term_limit'] === min($portfolio[0]['max_months'] - 2, 24), 'the liquidator term is the longest contract minus the margin, up to the cap');
foreach ([[0, $ev['max_months'], 'valor'], [9900000, 0, 'plazo']] as [$amount, $term, $missing]) {
  try {
    $service->simulation('22233344', 9, $amount, $term, 'Libre Destinación', 'mandato_2', $actor);
    $check(false, 'a simulation without ' . $missing . ' is rejected');
  } catch (DomainException $error) {
    $check(str_contains($error->getMessage(), $missing), 'a simulation without ' . $missing . ' is rejected');
  }
}
$simulation = $service->simulation(' 22.233.344 ', 9, 9900000, $ev['max_months'], 'Libre Destinación', 'mandato_2', $actor);
$check($simulation['credit']['status'] === 'simulacion' && $simulation['credit']['owner_name'] === 'Segunda Dueña' && abs($simulation['conditions']['amount'] - 9900000) < 0.01 && $simulation['conditions']['term'] === $ev['max_months'], 'a simulation uses the requested amount and term');
foreach (['createManual' => static fn() => $service->createManual(9, 800000, $ev['max_months'] + 1, 'Libre Destinación', $actor, '', 'mandato_2'), 'simulation' => static fn() => $service->simulation('22233344', 9, 800000, $ev['max_months'] + 1, 'Libre Destinación', 'mandato_2', $actor)] as $name => $call) {
  try {
    $call();
    $check(false, $name . ' rejects a term above the pre-approved maximum');
  } catch (DomainException $error) {
    $check(str_contains($error->getMessage(), 'supera el máximo de ' . $ev['max_months'] . ' cuotas'), $name . ' rejects a term above the pre-approved maximum');
  }
}
$credits = (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credits');
$service->recordSimulation($simulation, $actor);
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credits') === $credits && (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Simulación de crédito'") === 2, 'a simulation is not saved as a credit and goes to the history of every owner property');
$simulationHtml = \SCM\Modules\OwnerCredits\OwnerCreditPdf::html($simulation, 'Funcionario QA');
$check(str_contains($simulationHtml, 'Simulación de crédito · Contrato 950') && str_contains($simulationHtml, 'Ingreso neto total (2 contratos)') && str_contains($simulationHtml, '(principal)') && !str_contains($simulationHtml, 'Probabilidad de desocupación') && !str_contains($simulationHtml, 'Total intereses') && !str_contains($simulationHtml, 'Total a pagar') && str_contains($simulationHtml, '<th scope="row">Documento</th><td>22233344</td>') && str_contains($simulationHtml, 'no es una aprobación') && substr_count($simulationHtml, 'términos y condiciones de SKC SuCasa Inmobiliaria') === 2 && !str_contains($simulationHtml, 'Tasa ajustada de colocación') && !str_contains($simulationHtml, 'Tasa para el riesgo') && str_contains($simulationHtml, 'Tasa de usura vigente'), 'the simulation PDF warns it is subject to company policies and terms (top and scope), has one income table for all the contracts, the document apart, no vacancy or interest totals, and says it is not an approval');
// Aprobado con varios contratos: quedan como respaldo y en su historial; al vencer la última cuota queda pagado solo.
$service->decide((int) $chosen['id'], 'aprobado', ['requested_term' => '3', 'amount' => '$800.000', 'term' => '3', 'rate' => '1,9', 'disbursed_at' => date('Y-m-d', strtotime('-1 year')), 'note' => 'Desembolsado hace un año, prueba de cierre automático.'], $actor);
$check(count($db->getResults('SELECT * FROM wp_scm_owner_credit_sources WHERE credit_id = ?', [(int) $chosen['id']])) === 2, 'every contract of the owner backs the approved credit');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Crédito aprobado' AND observacion LIKE 'Contrato #954:%respalda el crédito%'") === 1, 'the approval is written to the history of the other backing contracts');
$settled = $service->workspace((int) $chosen['id']);
$check($settled['credit']['status'] === 'pagado' && str_contains((string) $settled['credit']['decision_note'], 'Pagado automáticamente') && $settled['events'][0]['actor_name'] === 'Sistema', 'a credit whose last instalment is past is marked as paid automatically');
$check($service->settleFinished() === 0, 'an automatically paid credit is not closed twice');

// Cambios frente al sistema: se ven en vivo, exigen justificación al aprobar y quedan guardados con la decisión.
$adjusted = (int) $service->createManual(9, 500000, 6, 'Libre Destinación', $actor, '', 'mandato_2');
$edits = ['requested_term' => '6', 'gross' => '$1.600.000', 'amount' => '$500.000', 'term' => '6', 'rate' => '1,85', 'vacancy' => 'Baja', 'siniestro' => 'no', 'has_credits' => 'no'];
$live = $service->workspace($adjusted, $edits);
$keys = array_column($live['adjustments'], 'note', 'key');
$rateCheck = static fn(array $work): bool => array_column($work['policies'], 'ok', 'key')['tasa_minima'];
$check($live['evaluation']['suggested']['rate'] === 0.019 && $rateCheck($live) && !$rateCheck($service->workspace($adjusted, ['rate' => '1,7'] + $edits)), 'the system suggests the reference rate and a discount beyond the maximum is outside policy');
$check(isset($keys['canon'], $keys['tasa']) && $keys['canon'] && $keys['tasa'] && !isset($keys['valor']), 'the liquidator lists the changes against the system while editing');
// Dentro de política, los cambios solo se registran: aprobar no exige justificación.
$service->decide($adjusted, 'aprobado', $edits + ['note' => ''], $actor);
$check($service->find($adjusted)['status'] === 'aprobado', 'approving with changes within policy needs no justification');
$stored = json_decode((string) $service->find($adjusted)['evaluation_json'], true)['adjustments'];
$event = $db->getRow("SELECT details_json FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'aprobado'", [$adjusted]);
$check(in_array('canon', array_column($stored, 'key'), true) && str_contains((string) $event['details_json'], 'Canon: $1.500.000 → $1.600.000'), 'the approved changes are saved with the decision and in its event');
$decidedWork = $service->workspace($adjusted);
$check(array_column($decidedWork['adjustments'], 'key') === array_column($stored, 'key') && str_contains((new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($decidedWork, true, true), 'Cambios aprobados frente al sistema') && str_contains(\SCM\Modules\OwnerCredits\OwnerCreditPdf::html($decidedWork, 'QA'), 'Canon: $1.500.000 → $1.600.000'), 'a decided credit shows the saved changes in the liquidator and the PDF');
$service->changeStatus($adjusted, 'anulado', 'Crédito de prueba de cambios frente al sistema.', $actor);

// Cupo preaprobado en el liquidador y cambio de beneficiario mientras está pendiente.
$pending = $service->workspace($service->createManual(9, 12000000, 6, 'Libre Destinación', $actor, '', 'mandato_2'));
$p = $pending['preapproval'];
$check(abs($p['amount'] - 9900000) < 0.01 && $p['contracts'] === 2 && $p['outstanding'] == 0.0 && abs($p['requested'] - 12000000) < 0.01, 'the liquidator shows the pre-approved amount of all the owner contracts');
$pendingHtml = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($pending, true, true);
$check(str_contains($pendingHtml, 'Otros contratos del propietario (ingreso neto)') && count($pending['evaluation']['other_contracts']) === 1, 'the income section lists the other contracts that add to the cap');
$single = $service->workspace((int) $manual['id']);
$check($single['evaluation']['other_contracts'] !== [] || str_contains((new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($single, true, true), 'Es el único contrato vigente'), 'a single active contract says why only one income shows');
$check(!$pending['policies_ok'] && str_contains($pendingHtml, 'data-owner-credit-needs-note') && str_contains($pendingHtml, 'style="display:none !important" data-owner-credit-note-box'), 'the note stays hidden until Aprobar is pressed, and the policies mark that it is needed');
$check(str_contains($pendingHtml, 'data-owner-credit-disclaimer') && str_contains(\SCM\Modules\OwnerCredits\OwnerCreditPdf::html($pending, 'QA'), 'Importante:</strong> Valores de referencia') && !str_contains(\SCM\Modules\OwnerCredits\OwnerCreditPdf::html(array_replace_recursive($pending, ['credit' => ['status' => 'aprobado', 'decided_at' => time()]]), 'QA'), 'Importante:</strong>'), 'the liquidator cupo and pending PDFs say values are subject to the company terms; approved ones do not');
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
$check(!$policy($cleared, 'prestamos')['ok'] && str_contains($policy($cleared, 'prestamos')['detail'], 'por fuera del sistema') && array_values(array_intersect(['siniestro', 'has_credits'], $cleared['evaluation']['analyst']['overrides'])) === ['siniestro', 'has_credits'] && count($cleared['evaluation']['analyst']['overrides']) === 2, 'credits outside the system marked by the analyst need a justification');
$checks = $service->requestChecks($service->ownerPortfolio('22233344'), ['outstanding' => 0, 'available' => 1, 'max_term' => 3]);
$check(array_column($checks, 'ok', 'label')['Sin siniestro'] === false && array_column($checks, 'ok', 'label')['Sin créditos vigentes'] === true, 'the request form shows the policy pre-check over every contract');
$moved = $service->changeContract((int) $changed['id'], 14, $actor);
$check((int) $moved['contract_id'] === 14 && $moved['contract_code'] === '954' && (float) $moved['gross_income'] === 2000000.0, 'the analyst can change the main contract to another contract of the owner');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'contrato_principal'", [(int) $moved['id']]) === 1 && (int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Cambio de contrato principal del crédito'") === 1, 'the main contract change is traced');
// Siniestro en otro contrato que no paga cuotas: solo aviso; si paga cuotas o es el principal, incumple.
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'No'], ['_ID' => 14]);
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'Si'], ['_ID' => 9]);
$aside = $service->workspace((int) $moved['id'], ['amount' => '$500.000', 'term' => '3']);
$check(!array_filter($aside['discount_plan'], static fn(array $s): bool => in_array('950', array_column($s['parts'], 'contrato'), true)) && $policy($aside, 'siniestro')['ok'] && str_starts_with($policy($aside, 'siniestro')['detail'], 'Aviso: Cartera marcó como siniestrado #950'), 'a siniestro on a contract that does not pay installments is only a warning');
$checksAside = $service->requestChecks($service->ownerPortfolio('22233344'), ['outstanding' => 0, 'available' => 1, 'max_term' => 3]);
$check(array_column($checksAside, 'ok', 'label')['Sin siniestro'] === true && str_contains(array_column($checksAside, 'detail', 'label')['Sin siniestro'], 'Aviso'), 'the request pre-check only fails for a siniestro on the main contract');
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'No'], ['_ID' => 9]);
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'Si'], ['_ID' => 14]);
$own = $service->workspace((int) $moved['id'], ['amount' => '$500.000', 'term' => '3']);
$check(!$policy($own, 'siniestro')['ok'] && str_contains($policy($own, 'siniestro')['detail'], '#954'), 'a siniestro on the main contract fails the policy');
try {
  $service->changeContract((int) $moved['id'], 7, $actor);
  $check(false, 'the main contract must be a contract of the owner');
} catch (DomainException) {
  $check(true, 'the main contract must be a contract of the owner');
}
$db->update('wp_jet_cct_contratos_arrendamiento', ['esta_sinestrado' => 'No'], ['_ID' => 14]);

// Probabilidad de desocupación sugerida con el seguimiento de renovación (no prórroga / terminación).
$end14 = (int) $db->getVar('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 14');
$end9 = (int) $db->getVar('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 9');
$check($service->workspace((int) $moved['id'])['evaluation']['vacancy'] === 'Baja', 'without renewal follow-up the vacancy suggestion is low');
$db->insert('wp_scm_contract_renewal', ['contract_id' => 14, 'end_ts' => $end14, 'probability' => 0, 'no_exit' => 0]);
$db->insert('wp_scm_contract_renewal_events', ['contract_id' => 14, 'action' => 'renewal_saved', 'actor' => '10', 'details_json' => json_encode(['term' => 'dentro', 'case_number' => '777', 'source' => 'no prórroga']), 'created_at' => time()]);
$db->insert('wp_scm_contract_renewal', ['contract_id' => 9, 'end_ts' => $end9, 'probability' => 30, 'no_exit' => 0]);
$vacant = $service->workspace((int) $moved['id']);
$check($vacant['evaluation']['vacancy'] === 'Alta' && str_contains($vacant['evaluation']['vacancy_signal']['reason'], 'dentro del término, caso #777') && $vacant['evaluation']['suggested']['term'] === 3, 'a no-renewal answered within the term suggests high vacancy on the main contract');
$check(count($vacant['evaluation']['vacancy_signal']['alerts']) === 1 && str_contains($vacant['evaluation']['vacancy_signal']['alerts'][0], '#950 con renovación 30 %'), 'another contract at high risk is shown as an alert');
$chosenVacancy = $service->workspace((int) $moved['id'], ['vacancy' => 'Baja']);
$check($chosenVacancy['evaluation']['vacancy'] === 'Baja' && in_array('vacancy', $chosenVacancy['evaluation']['analyst']['overrides'], true), 'the analyst can change the suggested vacancy and it is kept as an override');
$vacancyChecks = array_column($service->requestChecks($service->ownerPortfolio('22233344'), ['outstanding' => 0, 'available' => 1, 'max_term' => 3]), null, 'label');
$check($vacancyChecks['Probabilidad de desocupación']['ok'] === false && str_contains($vacancyChecks['Probabilidad de desocupación']['detail'], 'caso #777'), 'the request pre-check shows the vacancy risk');
$db->update('wp_scm_contract_renewal', ['end_ts' => $end14 - 86400 * 365], ['contract_id' => 14]);
$check($service->workspace((int) $moved['id'])['evaluation']['vacancy'] === 'Baja', 'a renewal probability from a previous end-date cycle is ignored');
$db->update('wp_scm_contract_renewal', ['end_ts' => $end14, 'probability' => 65], ['contract_id' => 14]);
$check($service->workspace((int) $moved['id'])['evaluation']['vacancy'] === 'Media', 'a renewal between 50 % and 79 % suggests medium vacancy');
$db->update('wp_scm_contract_renewal', ['no_exit' => 1, 'probability' => 10], ['contract_id' => 14]);
$check($service->workspace((int) $moved['id'])['evaluation']['vacancy'] === 'Baja', 'a reported "no salida" suggests low vacancy');
$db->delete('wp_scm_contract_renewal', ['contract_id' => 14]);
$db->delete('wp_scm_contract_renewal', ['contract_id' => 9]);

// Contrato sin mandato: el documento sale de la ficha del propietario y el saldo se encuentra también por la ficha.
$db->insert('wp_jet_cct_propietarios', ['_ID' => 970, 'id_propietario' => '970', 'nombre' => 'Sin Mandato', 'documento' => '55.555.555']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 20, 'contrato' => '980', 'estado' => 'Entregado', 'inmueble' => '10700', 'propietario' => 'Sin Mandato', 'id_propietario' => '970', 'valor_canon' => '1.000.000', 'fin_contrato' => (string) strtotime('+2 years'), 'esta_sinestrado' => 'No']);
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 590, 'id_ticket' => '10900', 'contrato' => '980', 'id_contrato' => '20', 'total' => '400000', 'financiacion' => 'Si']);
$service->syncFromQuote(590, 'Si', $actor);
$check($service->byQuote(590)['owner_document'] === '55.555.555', 'a quote credit on a contract without mandate takes the document from the owner record');
// Financiación Sí desde la cotización: se elige a cuántos meses, hasta el máximo del propietario.
$quoteLimit = $service->quoteTermLimit(590);
$check($quoteLimit > 0, 'a quote has a maximum financing term');
foreach ([0 => 'Indica a cuántos meses', $quoteLimit + 1 => 'El plazo máximo para financiar la cotización #590 es de ' . $quoteLimit . ' meses'] as $badTerm => $expected) {
  try {
    $service->assertQuoteTerm(590, $badTerm);
    $check(false, 'a financing term of ' . $badTerm . ' months is rejected');
  } catch (DomainException $error) {
    $check(str_contains($error->getMessage(), $expected), 'a financing term of ' . $badTerm . ' months is rejected: ' . $error->getMessage());
  }
}
$db->insert('wp_jet_cct_cotizacion_mantenimiento', ['_ID' => 591, 'id_ticket' => '10901', 'contrato' => '980', 'id_contrato' => '20', 'total' => '300000', 'financiacion' => 'Si']);
$service->syncFromQuote(591, 'Si', $actor, 1);
$check((int) $service->byQuote(591)['requested_term'] === 1 && $service->byQuote(591)['purpose'] === 'Reparaciones y mantenimiento', 'the quote request keeps the months chosen when answering, for repairs');
$service->syncFromQuote(591, 'Si', $actor, min(2, $quoteLimit));
$check((int) $service->byQuote(591)['requested_term'] === min(2, $quoteLimit), 'changing only the months updates the pending request');
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

$voided = (int) $service->createManual(9, 700000, 6, 'Libre Destinación', $actor, '', 'mandato_2');
$eventsBefore = (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credit_events');
$historiesBefore = (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento');
$service->voidRequest($voided);
$check($service->find($voided)['status'] === 'anulado', 'a pending request can be voided from the list');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credit_events') === $eventsBefore && (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_contrato_arrendamiento') === $historiesBefore, 'voiding a request writes no event nor history');
$check(!in_array($voided, array_map('intval', array_column($service->report([])['rows'], 'id')), true) && in_array($voided, array_map('intval', array_column($service->report(['status' => 'anulado'])['rows'], 'id')), true), 'voided requests leave the report unless filtered by Anulado');
$listing = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->report($service->report([]), true, true);
$check(str_contains($listing, 'Ver solicitud') && !str_contains($listing, 'Abrir liquidador') && str_contains($listing, 'data-owner-credit-void'), 'the list shows "Ver solicitud" and "Anular" for pending requests');
$check(!str_contains((new \SCM\Modules\OwnerCredits\OwnerCreditView())->report($service->report([]), true, false), 'data-owner-credit-void'), 'without the manage permission there is no "Anular"');
try { $service->voidRequest($voided); $check(false, 'only pending requests can be voided'); } catch (\DomainException $e) {}

// Cobertura de pago en el liquidador: se muestra, se guarda con la decisión y sin plazo con cobertura no se aprueba.
$coverageCredit = (int) $service->createManual(9, 800000, 6, 'Libre Destinación', $actor, '', 'mandato_2');
$cw = $service->workspace($coverageCredit);
$cc = $cw['evaluation']['coverage'];
$check($cc['rule'] === \SCM\Modules\OwnerCredits\OwnerCreditCoverage::RULE && $cc['checked'] && count($cc['rows']) === (int) $cw['conditions']['term'] && $cc['factor'] === 1.0 && $cc['min_coverage'] === 1.0, 'the liquidator checks the coverage of every installment with the configured parameters');
$cwHtml = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($cw, true, true);
$check(str_contains($cwHtml, 'data-owner-credit-coverage') && str_contains($cwHtml, 'Mayor plazo con cobertura') && str_contains($cwHtml, 'name="other_obligations"'), 'the liquidator shows the coverage section and the other obligations field');
$check(abs(array_sum(array_column($cw['schedule'], 'abono')) - $cw['conditions']['amount']) < 0.001 && end($cw['schedule'])['saldo'] == 0.0, 'the amortization table is in cents and the last installment leaves no balance');
$check((int) date('j', (int) $cw['schedule'][1]['fecha']) >= 28, 'installments fall on the 30th (or the end of February)');
// Otras obligaciones que se comen todo el ingreso: ningún plazo cubre y aprobar queda bloqueado aun con justificación.
$heavy = $service->workspace($coverageCredit, ['other_obligations' => '$900.000.000']);
$check(!$heavy['evaluation']['coverage']['ok'] && $heavy['evaluation']['coverage']['best_term'] === 0 && !array_column($heavy['policies'], 'ok', 'key')['cuota'], 'other obligations above the income leave no covered term');
try {
  $service->decide($coverageCredit, 'aprobado', ['other_obligations' => '$900.000.000', 'note' => 'Intento de aprobar sin cobertura de pago.'], $actor);
  $check(false, 'without a covered term the credit cannot be approved');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'Ningún plazo tiene cobertura'), 'without a covered term the credit cannot be approved, even with a justification');
}
// Un plazo sin cobertura con otro que sí cubre: falla la política (con justificación) y dice el mayor plazo.
$tight = $service->workspace($coverageCredit, ['other_obligations' => '$' . number_format(max(0, $cc['rows'][0]['ingreso'] - $cc['payment'] + 1), 0, '', '.')]);
$tc = $tight['evaluation']['coverage'];
$check(!$tc['ok'] && is_array($tc['shortfall']) && str_contains(array_column($tight['policies'], 'detail', 'key')['cuota'], 'disponibles frente a una cuota'), 'an installment short by one peso fails the coverage policy and shows the first shortfall');
$service->decide($coverageCredit, 'aprobado', ['note' => ''], $actor);
$savedCoverage = json_decode((string) $service->find($coverageCredit)['evaluation_json'], true)['coverage'];
$check($savedCoverage['rule'] === \SCM\Modules\OwnerCredits\OwnerCreditCoverage::RULE && $savedCoverage['ok'] && isset($savedCoverage['max_capital']), 'the coverage result, parameters and rule are saved with the decision');
// Con ese crédito aprobado, sus cuotas se restan del disponible de una nueva solicitud del mismo propietario.
$next = $service->workspace((int) $service->createManual(9, 800000, 6, 'Libre Destinación', $actor, '', 'mandato_2'));
$check($next['evaluation']['analyst']['has_credits'] && in_array($coverageCredit, $next['evaluation']['coverage']['credits'], true) && $next['evaluation']['coverage']['rows'][0]['obligaciones'] > 0, 'the installments of the owner approved credits are subtracted from the available income');
// Abono extraordinario simulado sobre el crédito aprobado: no cambia el crédito ni deja eventos.
$approvedCoverage = $service->workspace($coverageCredit);
$eventsBeforePrepay = (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ?', [$coverageCredit]);
$prepay = $service->prepayment($coverageCredit, date('Y-m-d', (int) $approvedCoverage['disbursed_ts']), 300000);
$check($prepay['paid_installments'] === 0 && !$prepay['proposal'] && abs($prepay['liquidation']['new_balance'] - ($approvedCoverage['conditions']['amount'] - 300000)) < 0.001, 'a prepayment on the disbursement day goes fully to capital');
$check($prepay['options']['reduce_term']['installments'] < count($approvedCoverage['schedule']) && $prepay['options']['reduce_payment']['installments'] === count($approvedCoverage['schedule']) && $prepay['options']['reduce_payment']['rows'][0]['cuota'] < $approvedCoverage['conditions']['payment'], 'reduce term ends earlier and reduce payment keeps the dates with a lower installment');
$check($prepay['options']['reduce_term']['savings'] > $prepay['options']['reduce_payment']['savings'] && $prepay['options']['reduce_payment']['savings'] > 0, 'both options save interest, reducing the term saves more');
$midway = $service->prepayment($coverageCredit, date('Y-m-d', strtotime('+10 days', (int) $approvedCoverage['schedule'][0]['fecha'])), 300000);
$check($midway['paid_installments'] === 1 && $midway['proposal'] && $midway['liquidation']['interest_paid'] > 0 && abs($midway['liquidation']['previous_balance'] - $approvedCoverage['schedule'][0]['saldo']) < 0.001, 'between due dates the first installment counts as paid and the accrued interest is a proposal');
$payoff = $service->prepayment($coverageCredit, '', 99999999);
$check($payoff['paid_off'] && $payoff['liquidation']['surplus'] > 0, 'paying more than the balance cancels the credit and shows the surplus');
$prepayHtml = \SCM\Modules\OwnerCredits\OwnerCreditView::prepaymentResults($prepay);
$check(str_contains($prepayHtml, 'data-owner-credit-prepay-option="reduce_term"') && str_contains($prepayHtml, 'Reducir cuota') && str_contains($prepayHtml, 'desembolso adicional de $0'), 'the comparison shows both options and the SIMI note');
$check(str_contains((new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($approvedCoverage, true, true), 'data-owner-credit-prepay-form') && !str_contains($cwHtml, 'data-owner-credit-prepay-form'), 'only approved credits show the prepayment simulator');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ?', [$coverageCredit]) === $eventsBeforePrepay && $service->find($coverageCredit)['status'] === 'aprobado', 'simulating a prepayment records nothing');
try {
  $service->prepayment((int) $next['credit']['id'], '', 100000);
  $check(false, 'pending credits cannot simulate prepayments');
} catch (DomainException) {
  $check(true, 'pending credits cannot simulate prepayments');
}
$service->changeStatus($coverageCredit, 'anulado', 'Crédito de prueba de cobertura de pago.', $actor);

// Pagaré en blanco + carta de instrucciones: se firma después de aprobar (una vez por persona) y con la firma sale la respuesta.
$requirePromissory = static function (bool $on) use ($db): void {
  $config = json_decode((string) $db->getVar("SELECT valor FROM wp_jet_cct_confi_sistema WHERE funcion = 'control_servicios_config'"), true);
  $config['owner_credit_settings']['require_promissory'] = $on;
  $db->update('wp_jet_cct_confi_sistema', ['valor' => json_encode($config)], ['funcion' => 'control_servicios_config']);
};
$requirePromissory(true);
$pagareService = new OwnerCreditService($db, new Settings($db));
$pagareService->useResponseFiles($fakeResponsePdf, $fakeResponseStore);
$sentMessages = [];
$promissory = new \SCM\Modules\OwnerCredits\OwnerCreditPromissoryService($db, 'test-secret', 'https://example.test',
  static function (string $channel, string $to, array $message) use (&$sentMessages): int { $sentMessages[] = ['channel' => $channel, 'to' => $to] + $message; return 1; },
  static fn(array $row, array $payload): string => '%PDF-1.4 pagare ' . $payload['number']);
$pagareService->usePromissory($promissory);
$pagareCredit = (int) $pagareService->createManual(9, 800000, 6, 'Libre Destinación', $actor, '', 'mandato_2');
$state = $pagareService->promissoryState($pagareService->find($pagareCredit));
$check($state['required'] === ['mandato_2'] && $state['missing'] === ['Segunda Dueña'] && count($state['owners']) === 2, 'by default only the beneficiary must sign the promissory note');
$check($pagareService->workspace($pagareCredit)['stage'] === 'estudio', 'a pending request is in the study stage');
$both = $pagareService->setPromissorySigners($pagareCredit, ['mandato_1', 'mandato_2'], $actor);
$check($both['required'] === ['mandato_1', 'mandato_2'] && count($both['missing']) === 2, 'the analyst can ask every owner of the contract to sign');
$pagareService->setPromissorySigners($pagareCredit, ['mandato_2'], $actor);
try {
  $pagareService->sendPromissory($pagareCredit, ['email'], $actor);
  $check(false, 'the promissory note is sent after approving');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'después de aprobar'), 'the promissory note is sent after approving, not while the request is pending');
}
$approval = $pagareService->decide($pagareCredit, 'aprobado', ['note' => 'Aprobación antes de firmar el pagaré.'], $actor);
$invitation = array_values(array_filter($sentMessages, static fn(array $m): bool => $m['event'] === 'invitation'))[0] ?? null;
$check($approval['credit']['status'] === 'aprobado' && $approval['next']['promissory'] !== [] && $approval['next']['response'] === null && $invitation !== null && $invitation['to'] === 'segunda@example.invalid' && str_contains($invitation['url'], 'credito-pagare.php?id='), 'approving without the signed note sends the signing link and waits for the signature');
$check(str_contains((string) $invitation['html'], 'fue aprobada') && $pagareService->workspace($pagareCredit)['stage'] === 'firma', 'the invitation says the credit was approved and the credit waits in the signing stage');
try {
  $pagareService->sendResponse($pagareCredit, $actor);
  $check(false, 'the response needs the signed note');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'Falta el pagaré firmado de Segunda Dueña'), 'the response is not sent before the signature');
}
parse_str((string) parse_url($invitation['url'], PHP_URL_QUERY), $query);
$pid = (int) $query['id'];
$token = (string) $query['token'];
$row = $promissory->find($pid);
$payload = $promissory->payloadOf($row);
$check($row['status'] === 'pendiente' && $payload['number'] === \SCM\Modules\OwnerCredits\OwnerCreditPromissoryService::number($pid) && $payload['debtor']['document'] === '22233344', 'the promissory note is numbered and stores the debtor with a document fingerprint');
$html = \SCM\Modules\OwnerCredits\OwnerCreditPromissoryDocument::render($row, $payload);
$check(str_contains($html, 'SOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S.') && str_contains($html, 'Carta de instrucciones') && str_contains($html, 'artículo 622 del Código de Comercio') && str_contains($html, 'scm-promissory-blank') && !str_contains($html, 'Signio'), 'the document has the promissory note in blank and the instructions letter, signed in the panel (no Signio)');
try { $promissory->publicRow($pid, str_repeat('0', 64)); $check(false, 'a wrong token is rejected'); } catch (DomainException) { $check(true, 'a wrong token is rejected'); }
$promissory->requestCode($pid, $token, 'email');
$otp = array_values(array_filter($sentMessages, static fn(array $m): bool => $m['event'] === 'otp'));
$code = (string) end($otp)['code'];
$check(preg_match('/^\d{6}$/', $code) === 1 && !str_contains((string) $promissory->find($pid)['otp_json'], $code), 'a 6-digit code is sent and only its HMAC is stored');
$signInput = ['accepted' => '1', 'signature_name' => 'Segunda Dueña', 'document' => '22.233.344', 'otp_code' => $code, 'document_hash' => $row['payload_hash']];
try { $promissory->sign($pid, $token, ['otp_code' => '000000'] + $signInput, '10.0.0.1', 'QA'); $check(false, 'a wrong code is rejected'); } catch (DomainException) {}
$check((int) (json_decode((string) $promissory->find($pid)['otp_json'], true)['failures'] ?? 0) === 1, 'a wrong code is rejected and the failed try stays counted');
try { $promissory->sign($pid, $token, ['document' => '99999999'] + $signInput, '10.0.0.1', 'QA'); $check(false, 'the document number must match'); } catch (DomainException $error) { $check(str_contains($error->getMessage(), 'documento no coincide'), 'the document number must match'); }
try { $promissory->sign($pid, $token, ['signature_name' => 'Otra Persona'] + $signInput, '10.0.0.1', 'QA'); $check(false, 'the typed name must match'); } catch (DomainException $error) { $check(str_contains($error->getMessage(), 'nombre'), 'the typed name must match'); }
$responsePdfs = [];
$responseFiles = [];
$signedRow = $promissory->sign($pid, $token, $signInput, '10.0.0.1', 'QA browser');
$evidence = json_decode((string) $signedRow['signed_json'], true);
$check($signedRow['status'] === 'firmado' && $evidence['ip'] === '10.0.0.1' && $evidence['document_hash'] === $row['payload_hash'] && isset($evidence['evidence_hmac']) && $evidence['verification']['channel'] === 'email', 'signing stores the evidence: IP, document fingerprint, verification and HMAC');
$check($promissory->pdf($signedRow) === '%PDF-1.4 pagare ' . $payload['number'], 'the signed PDF is stored with its HMAC');
$answered = $pagareService->find($pagareCredit);
$response = json_decode((string) $answered['response_json'], true);
$check((int) $answered['response_sent_at'] > 0 && count($response['documents']) === 3 && count($responseFiles) === 3 && str_contains($response['documents'][1], $payload['number']), 'signing sends the response with the approval letter, the signed note and the account statement');
$check(array_column($responsePdfs, 'title') === ['Carta de aprobación · Crédito #' . $pagareCredit, 'Estado de cuenta · Crédito #' . $pagareCredit] && array_filter($sentMessages, static fn(array $m): bool => $m['event'] === 'signed') === [], 'the letter and the statement are generated and the signer does not get a second copy of the note');
$check($pagareService->workspace($pagareCredit)['stage'] === 'completo' && (int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'respuesta_enviada'", [$pagareCredit]) === 1, 'the credit completes its stages and the response stays in its history');
$letter = $responsePdfs[0]['html'];
$check(str_contains($letter, 'Notificación de aprobación de crédito') && str_contains($letter, 'Segunda Dueña') && str_contains($letter, '$800.000') && str_contains($letter, 'como mínimo, dos meses antes de su vencimiento') && str_contains($letter, 'Analista QA') && str_contains($letter, 'SKC SuCasa Inmobiliaria'), 'the approval letter follows the model with the approved conditions and who approved');
$statement = $responsePdfs[1]['html'];
$check(str_contains($statement, 'Estado de cuenta') && str_contains($statement, 'Saldo a capital en pesos') && str_contains($statement, 'Distribución de la cuota a pagar') && str_contains($statement, 'Plan de pagos') && str_contains($statement, 'Realizado por') && !str_contains($statement, 'sucursal'), 'the account statement shows balance, next installment, plan and closes with "Realizado por"');
$check($promissory->sign($pid, $token, $signInput, '10.0.0.1', 'QA')['status'] === 'firmado' && (int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'respuesta_enviada'", [$pagareCredit]) === 1, 'signing twice does nothing');
$approvedView = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($pagareService->workspace($pagareCredit), true, true);
$check(str_contains($approvedView, 'data-owner-credit-stepper') && str_contains($approvedView, 'data-owner-credit-stage="respuesta"') && str_contains($approvedView, 'data-owner-credit-document="carta"') && str_contains($approvedView, 'data-owner-credit-document="estado"') && str_contains($approvedView, 'Reenviar respuesta'), 'the liquidator shows the stages, the response documents and the resend button');
$pagareService->sendResponse($pagareCredit, $actor);
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'respuesta_enviada'", [$pagareCredit]) === 2, 'the response can be resent and the resend is traced');
$nextCredit = (int) $pagareService->createManual(9, 500000, 3, 'Libre Destinación', $actor, '', 'mandato_2');
$check($pagareService->promissoryState($pagareService->find($nextCredit))['missing'] === [], 'a later credit of the same person does not ask to sign again');
try { $pagareService->voidPromissory($pid, 'corto', $actor); $check(false, 'voiding needs a reason'); } catch (DomainException) { $check(true, 'voiding a promissory note needs a reason'); }
$pagareService->voidPromissory($pid, 'Se reemplaza por un pagaré con datos corregidos.', $actor);
$check($promissory->find($pid)['status'] === 'anulado' && $pagareService->promissoryState($pagareService->find($nextCredit))['missing'] === ['Segunda Dueña'] && (int) $db->getVar("SELECT COUNT(*) FROM wp_scm_owner_credit_events WHERE credit_id = ? AND action = 'pagare_anulado'", [$pagareCredit]) === 1, 'a voided note is traced and the person must sign a new one');
try { $promissory->publicRow($pid, $token); $check(false, 'a voided note cannot be opened to sign'); } catch (DomainException) { $check(true, 'a voided note cannot be opened to sign'); }
$liquidator = (new \SCM\Modules\OwnerCredits\OwnerCreditView())->workspace($pagareService->workspace($nextCredit), true, true);
$check(str_contains($liquidator, 'data-owner-credit-promissory') && str_contains($liquidator, 'Falta firma') && !str_contains($liquidator, 'data-owner-credit-promissory-send') && str_contains($liquidator, 'Al aprobar se enviará el pagaré para firmar a Segunda Dueña') && str_contains($liquidator, 'name="promissory_signers[]"'), 'while pending, the liquidator shows who signs and that the note goes out on approval');
$pagareService->changeStatus($pagareCredit, 'anulado', 'Crédito de prueba del pagaré.', $actor);
$pagareService->changeStatus($nextCredit, 'anulado', 'Crédito de prueba del pagaré.', $actor);
$requirePromissory(false);

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/OwnerCredits/OwnerCreditService.php');
$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-respuesta-funcionario-template.json'), true);
$check(str_contains($source, "'" . $template['name'] . "'") && substr_count($template['components'][0]['text'], '{{') === 6 && !str_contains($source, 'scm_credito_propietario_funcionario_v2'), 'staff decision template has its own six variables and the generic v2 is no longer used');
foreach (['carta' => ['whatsapp-credito-carta-aprobacion-template.json', 7], 'pagare' => ['whatsapp-credito-pagare-firmado-doc-template.json', 6], 'estado' => ['whatsapp-credito-estado-cuenta-template.json', 7]] as $kind => [$file, $variables]) {
  $responseTemplate = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/' . $file), true);
  $body = $responseTemplate['components'][1]['text'];
  $check(OwnerCreditService::RESPONSE_TEMPLATES[$kind] === $responseTemplate['name'] && $responseTemplate['components'][0]['format'] === 'DOCUMENT' && substr_count($body, '{{') === $variables && count($responseTemplate['components'][1]['example']['body_text'][0]) === $variables && !str_ends_with(trim($body), '}}') && str_contains($body, 'Atentamente,'), 'response template ' . $responseTemplate['name'] . ' carries the PDF in its header and its ' . $variables . ' variables');
}
$staffRequestTemplate = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-solicitud-funcionario-template.json'), true);
$check(str_contains($source, "'" . $staffRequestTemplate['name'] . "'") && substr_count($staffRequestTemplate['components'][0]['text'], '{{') === 6 && str_contains($staffRequestTemplate['components'][0]['text'], 'Destinación: {{4}}') && !str_contains($staffRequestTemplate['components'][0]['text'], 'número'), 'staff request template is short, without the credit number, with the purpose, and its six variables match the code');
$ownerTemplate = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-solicitud-template.json'), true);
$check(str_contains($source, "'" . $ownerTemplate['name'] . "'") && substr_count($ownerTemplate['components'][0]['text'], '{{') === 4 && !str_ends_with(trim($ownerTemplate['components'][0]['text']), '}}'), 'owner request template name and its four variables match the code');
$requestBody = (new ReflectionMethod(OwnerCreditService::class, 'ownerRequestEmailBody'))->invoke(null, ['owner_name' => 'Ana <b>', 'requested_amount' => 893723, 'requested_term' => 10, 'purpose' => 'Pago de Impuestos', 'contract_code' => '817', 'property' => '10420']);
$check(str_contains($requestBody, '$893.723') && str_contains($requestBody, '10 meses') && str_contains($requestBody, 'Pago de Impuestos') && str_contains($requestBody, 'Ana &lt;b&gt;') && str_contains($requestBody, 'No constituyen una aprobación'), 'the owner gets a request confirmation with amount, term, purpose and the disclaimer');
$check(str_contains($requestBody, 'Usted realizó una solicitud de crédito con estos datos') && str_contains($requestBody, 'sujeto a estudio, verificación y aprobación') && !str_contains($requestBody, 'Cotización'), 'the manual request email tells the owner it is subject to study, verification and approval');
$quoteBody = (new ReflectionMethod(OwnerCreditService::class, 'ownerRequestEmailBody'))->invoke(null, ['owner_name' => 'Ana', 'quote_id' => 574, 'requested_amount' => 893723, 'requested_term' => 12, 'purpose' => 'Reparaciones y mantenimiento', 'contract_code' => '817', 'property' => '10420']);
$signedBody = (new ReflectionMethod(OwnerCreditService::class, 'ownerRequestEmailBody'))->invoke(null, ['owner_name' => 'Ana', 'requested_amount' => 1, 'requested_term' => 1, 'purpose' => '', 'contract_code' => '817', 'property' => ''], ['name' => 'Laura <i>', 'cargo' => 'Coordinadora', 'email' => 'laura@example.invalid']);
$check(str_contains($signedBody, 'Atentamente,<br><b>Laura &lt;i&gt;</b><br>Coordinadora<br>laura@example.invalid<br><b>SKC SuCasa Inmobiliaria</b>'), 'the owner request email is signed with name, position and email');
$phoneBody = (new ReflectionMethod(OwnerCreditService::class, 'ownerRequestEmailBody'))->invoke(null, ['owner_name' => 'Ana', 'requested_amount' => 1, 'requested_term' => 1, 'purpose' => '', 'contract_code' => '817', 'property' => ''], ['name' => 'Laura', 'cargo' => 'Coordinadora', 'email' => 'laura@example.invalid', 'phone' => '573001234567']);
$check(str_contains($phoneBody, 'Coordinadora<br>laura@example.invalid<br>Cel. 3001234567<br><b>SKC SuCasa Inmobiliaria</b>'), 'the owner request email signature adds the phone');
$signerLine = new ReflectionMethod(OwnerCreditService::class, 'signerLine');
$check($signerLine->invoke(null, ['name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI', 'email' => 'x@example.invalid', 'phone' => '+57 301 523 2054']) === 'Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054', 'the WhatsApp signature is name - position - Cel. phone, without the email or country code');
$check($signerLine->invoke(null, ['name' => 'Laura', 'cargo' => '', 'email' => 'laura@example.invalid']) === 'Laura', 'the WhatsApp signature skips missing data');
foreach (['cct_author_id', 'id_coordinador', 'coordinador', 'email_coordinador'] as $column) $pdo->exec("ALTER TABLE wp_jet_cct_cotizacion_mantenimiento ADD COLUMN {$column} TEXT DEFAULT ''");
$db->update('wp_jet_cct_cotizacion_mantenimiento', ['cct_author_id' => '111', 'id_coordinador' => '', 'coordinador' => 'Quien Envía', 'email_coordinador' => 'envia@example.invalid'], ['_ID' => 574]);
$signer = (new ReflectionMethod(OwnerCreditService::class, 'requestSigner'))->invoke($service, $service->byQuote(574), $actor);
$check($signer['name'] === 'Quien Envía' && $signer['email'] === 'envia@example.invalid', 'a quote request is signed by whoever sent the quote, not by whoever registered the response');
$signer = (new ReflectionMethod(OwnerCreditService::class, 'requestSigner'))->invoke($service, $service->find($longTerm), $actor);
$check($signer['name'] === 'Analista QA', 'a manual request is signed by whoever registered it');
$check(str_contains($quoteBody, 'Al responder la cotización #574 usted realizó una solicitud de crédito para financiarla') && str_contains($quoteBody, 'Cotización: <b>#574</b>'), 'the quote request email names the quote it finances');
$check(str_contains($source, "self::EVENT_QUOTE_REQUESTED, 'Crédito solicitado al responder la cotización #'") && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/App/SuCasaControlServiciosInmobiliarios.php'), "'" . OwnerCreditService::EVENT_QUOTE_REQUESTED . "' => ["), 'quote requests notify their own configurable internal event');
$check(in_array('Reparaciones y mantenimiento', \SCM\Modules\OwnerCredits\OwnerCreditSettings::PURPOSES, true) && $service->byQuote(574)['purpose'] === 'Reparaciones y mantenimiento', 'quote requests use a purpose from the list');
$check(\SCM\Modules\OwnerCredits\OwnerCreditSettings::normalize([])['notify_owner_request_email'] === true && \SCM\Modules\OwnerCredits\OwnerCreditSettings::normalize([])['notify_owner_request_whatsapp'] === false, 'the owner request email is on and the WhatsApp is off by default');

$decisionTemplate = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-decision-template.json'), true);
$decisionText = $decisionTemplate['components'][0]['text'];
$check(str_contains($source, "'" . $decisionTemplate['name'] . "'") && substr_count($decisionText, '{{') === 5 && !str_ends_with(trim($decisionText), '}}') && !str_contains($decisionText, 'Comunícate') && !str_contains($source, 'Comunícate con nosotros'), 'owner decision template has five variables, ends with fixed text and speaks of usted');
$check(str_contains($source, 'mb_strtolower((string) $credit[\'purpose\']) ?: \'libre inversión\', (string) $credit[\'contract_code\'], $detail, self::signerLine($signer)'), 'owner decision WhatsApp sends the purpose and the signature of whoever decided');
$decisionBody = (new ReflectionMethod(OwnerCreditService::class, 'ownerEmailBody'))->invoke($service, ['owner_name' => 'Ana', 'status' => 'rechazado', 'requested_amount' => 893723, 'purpose' => 'Libre inversión', 'contract_code' => '817'], ['name' => 'Laura', 'cargo' => 'Analista de crédito', 'email' => 'laura@example.invalid']);
$check(str_contains($decisionBody, 'para libre inversión') && str_contains($decisionBody, 'Atentamente,<br><b>Laura</b><br>Analista de crédito<br>laura@example.invalid<br><b>SKC SuCasa Inmobiliaria</b>'), 'the owner decision email names the purpose and is signed by whoever decided');

// Los avisos internos (correo y WhatsApp) llevan al liquidador de ese crédito, también después del login.
$check(OwnerCreditService::panelPath(2) === 'index.php?tab=credito_propietario&credito=2' && OwnerCreditService::panelPath(0) === 'index.php?tab=credito_propietario', 'staff notices link to the credit itself');
$check(substr_count($source, 'self::panelPath(') === 1 && substr_count($source, 'self::panelUrl(') === 3 && str_contains($source, "'Abrir crédito #' . \$creditId"), 'the staff email buttons (decision and response) and the WhatsApp button use the credit link');
$creditsJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-owner-credits.js');
$loginPhp = (string) file_get_contents(dirname(__DIR__) . '/public/login.php');
$check(str_contains($creditsJs, 'url.searchParams.get("credito")') && str_contains($creditsJs, 'openCredit({ credit_id: linkedCredit })') && str_contains($loginPhp, "'index.php?tab=credito_propietario' . (isset(\$query['credito'])"), 'the panel opens the linked credit and the login returns to it');

// Reinicio de cotización para pruebas: el crédito aprobado se anula y se suelta, y la próxima financiación crea uno nuevo.
$quoteCreditId = (int) $service->byQuote(574)['id'];
$db->update('wp_scm_owner_credits', ['status' => 'pagado'], ['id' => $quoteCreditId]);
try {
  $service->detachFromQuoteForReset(574, $actor);
  $check(false, 'a paid credit must block the reset');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'pagado'), 'a paid credit blocks the quote reset');
}
$db->update('wp_scm_owner_credits', ['status' => 'aprobado'], ['id' => $quoteCreditId]);
$resetId = $service->detachFromQuoteForReset(574, $actor);
$check($resetId === $quoteCreditId && $service->byQuote(574) === null && $service->find($resetId)['status'] === 'anulado', 'resetting the quote annuls its credit and frees the quote');
$service->syncFromQuote(574, 'Si', $actor);
$check((int) ($service->byQuote(574)['id'] ?? 0) > 0 && (int) $service->byQuote(574)['id'] !== $resetId && $service->byQuote(574)['status'] === 'pendiente', 'after a reset, financing "Sí" opens a new request');

echo "Owner credit flow checks passed.\n";
