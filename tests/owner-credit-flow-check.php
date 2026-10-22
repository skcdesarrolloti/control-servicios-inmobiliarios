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
$pdo->exec('CREATE TABLE wp_scm_owner_credit_events(id INTEGER PRIMARY KEY AUTOINCREMENT, credit_id INTEGER, action TEXT, actor_id TEXT, actor_name TEXT, details_json TEXT, created_at INTEGER)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, estado TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, direccion TEXT, propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, valor_canon TEXT, valor_administracion TEXT, tasa_administracion TEXT, aseguradora TEXT, asegurado TEXT, inicio_contrato TEXT, fin_contrato TEXT, esta_sinestrado TEXT, id_contrato_mandato TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, total_comision TEXT, incluye_garantia TEXT, precio_mes_garantia TEXT, incluye_garantia_servicios TEXT, precio_mes_servicios TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cotizacion_mantenimiento(_ID INTEGER PRIMARY KEY, id_ticket TEXT, contrato TEXT, id_contrato TEXT, total TEXT, financiacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_ticket(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, id_empleado TEXT, cct_created TEXT, cct_modified TEXT, fecha INTEGER, id_ticket TEXT, id_cotizacion_mantenimiento TEXT, nombre TEXT, respuesta TEXT, respuesta_cct_ticket TEXT, fue_editada TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_contrato TEXT, contrato TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT)');

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
$check($credit['owner_id'] === '956' && $credit['ticket_id'] === '10821' && $credit['owner_phone'] === '573001234567', 'request keeps owner, case and phone with country code');
$service->syncFromQuote(574, 'Si', $actor);
$check(count($db->getResults('SELECT id FROM wp_scm_owner_credits')) === 1, 'repeating financing "Sí" does not duplicate the request');

$work = $service->workspace((int) $credit['id'], ['requested_term' => '12']);
$check($work['evaluation']['gross'] === 1075000.0 && abs($work['evaluation']['deductions_total'] - (142800 + 29400 + 52661.669)) < 0.01, 'income uses canon and mandate commission, guarantee and utilities policy');
$check($work['evaluation']['max_months'] >= 13 && $work['policies_ok'], 'a healthy contract passes every policy with the suggested conditions');

try {
  $service->decide((int) $credit['id'], 'aprobado', ['requested_term' => '12', 'amount' => '$5.000.000', 'term' => '12', 'rate' => '2,3'], $actor);
  $check(false, 'approving above the cap without justification must fail');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'justificación'), 'approving outside policy requires a justification');
}
$result = $service->decide((int) $credit['id'], 'aprobado', ['requested_term' => '12', 'amount' => '$3.000.000', 'term' => '12', 'rate' => '2,3', 'note' => 'Propietario con 10 años sin mora, se aprueba excepción.'], $actor);
$approved = $result['credit'];
$check(array_key_exists('internal_whatsapp', $result['queued']), 'staff WhatsApp is attempted on decisions without breaking them when no staff is configured');
$check($approved['status'] === 'aprobado' && (int) $approved['policy_override'] === 1 && (float) $approved['approved_amount'] === 3000000.0 && (int) $approved['approved_term'] === 12, 'approved outside policy with conditions and override flag');
$check(abs((float) $approved['monthly_payment'] - 288931.18) < 1 && abs((float) $approved['approved_rate'] - 0.023) < 1e-9, 'monthly payment is recalculated on the server');
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
$simulated = $service->workspace((int) $pending['id'], ['requested_term' => '30', 'contract_end' => $newEnd]);
$check($simulated['term_error'] === '' && $simulated['contract']['fin_ts'] !== $simulated['contract']['fin_saved_ts'], 'a corrected end date is simulated before saving');
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 7')['fin_contrato'] === $end, 'simulating does not touch the contract');
$check(!method_exists($service, 'updateContractEnd'), 'the end date is no longer saved with a separate button');
$service->decide((int) $pending['id'], 'rechazado', ['requested_term' => '30', 'contract_end' => $newEnd, 'note' => 'Se rechaza tras corregir la fecha fin.'], $actor);
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 7')['fin_contrato'] === (string) strtotime($newEnd . ' 00:00:00'), 'the corrected end date is saved in the contract at midnight when deciding');
$endHistory = $db->getResults("SELECT observacion FROM wp_jet_cct_historial_contrato_arrendamiento WHERE tipo_reporte = 'Actualización de fecha fin'");
$check(count($endHistory) === 1 && str_contains($endHistory[0]['observacion'], ' → ' . date('d/m/Y', strtotime($newEnd))), 'the end date change is written to the contract history with old and new dates');
try {
  $service->createManual(8, 1000000, 6, 'Libre Destinación', $actor);
  $check(false, 'manual credit longer than the contract must fail');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'no puede superar'), 'manual credit validates the requested term against the contract end');
}
$manual = $service->find($service->createManual(8, 1000000, 6, 'Libre Destinación', $actor, date('Y-m-d', strtotime('+1 year'))));
$check($manual['status'] === 'pendiente' && $manual['quote_id'] === null && (int) $manual['requested_term'] === 6, 'manual credit is created from the selected contract');
$check($db->getRow('SELECT fin_contrato FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 8')['fin_contrato'] === (string) strtotime(date('Y-m-d', strtotime('+1 year')) . ' 00:00:00'), 'manual credit saves the corrected contract end date');

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/OwnerCredits/OwnerCreditService.php');
$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-credito-propietario-funcionario-v2-template.json'), true);
$check(str_contains($source, "'" . $template['name'] . "'") && substr_count($template['components'][0]['text'], '{{') === 6, 'staff template name and its six variables match the code');

echo "Owner credit flow checks passed.\n";
