<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Modules\ContractMovements\ContractSimiAudit;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

final class SimiTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    $query = str_replace(' FOR UPDATE', '', $query);
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

// Valores y mensaje del historial.
foreach (['Correcto' => 'Correcto', ' incorrecto ' => 'Incorrecto', 'CORRECTO' => 'Correcto', '' => '', 'Pendiente' => ''] as $raw => $expected) {
  $check(ContractSimiAudit::normalizeState($raw) === $expected, 'Estado SIMI mal normalizado: ' . $raw);
}
$check(
  ContractSimiAudit::historyMessage('777', 'Incorrecto', 'Canon distinto en SIMI.') === 'Se ha realizado la auditoría del contrato de arrendamiento #777 de su inmueble. Se encontró incorrecto. Observaciones: Canon distinto en SIMI.',
  'El historial debe decir el resultado y las observaciones.'
);
$check(!str_contains(ContractSimiAudit::historyMessage('777', 'Correcto', ''), 'Observaciones'), 'Sin observaciones no se agrega ese texto.');

// El reporte expone las columnas SIMI.
$report = (new ReflectionClass(ContractMovementsReport::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ContractMovementsReport::class, 'timezone'))->setValue($report, new DateTimeZone('America/Bogota'));
$cols = array_flip(ContractMovementsReport::COLUMNS);
$row = $report->mapRow(['_ID' => 1, 'contrato' => '777', 'estado' => 'Entregado', 'fecha_entrega' => '1622955600', 'estado_simi' => 'incorrecto', 'observaciones_estado_simi' => ' Revisar canon ']);
$check($row[$cols['estado_simi']] === 'Incorrecto' && $row[$cols['obs_simi']] === 'Revisar canon', 'El reporte debe normalizar estado y observaciones SIMI.');
$row = $report->mapRow(['_ID' => 1, 'contrato' => '777', 'estado' => 'Entregado', 'fecha_entrega' => '1622955600']);
$check($row[$cols['estado_simi']] === '' && $row[$cols['obs_simi']] === '', 'Sin columnas SIMI el contrato queda sin auditar.');

// Guardado: solo toca los campos SIMI y deja historial con el funcionario que audita.
$pdo = new SimiTestPdo();
$db = new \SCM\Core\Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, id_empleado TEXT, realizado_por TEXT, cct_author_id TEXT, cct_modified TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha TEXT, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY, cct_status TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, funcionario TEXT, id_empleado TEXT, id_ticket TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, inmueble TEXT, contrato TEXT, id_contrato TEXT)');
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 5, 'contrato' => '777', 'inmueble' => '10532', 'id_inmueble' => '84016', 'id_inmueble_data' => '12466', 'id_empleado' => '1508', 'realizado_por' => 'Kellys Roqueme Camacho', 'cct_author_id' => '1508', 'cct_modified' => '2025-01-01 00:00:00']);

$audit = new ContractSimiAudit($db);
$actor = ['id_empleado' => '2999', 'name' => 'Coordinadora QA'];
try {
  $audit->save(5, 'Correcto', '', $actor);
  $check(false, 'Sin los campos de JetEngine debe pedir crearlos.');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'estado_simi'), 'El error debe nombrar los campos faltantes.');
}

$pdo->exec('ALTER TABLE wp_jet_cct_contratos_arrendamiento ADD COLUMN estado_simi TEXT');
$pdo->exec('ALTER TABLE wp_jet_cct_contratos_arrendamiento ADD COLUMN observaciones_estado_simi TEXT');
$audit = new ContractSimiAudit($db);
try {
  $audit->save(5, 'Tal vez', '', $actor);
  $check(false, 'Solo se acepta Correcto o Incorrecto.');
} catch (InvalidArgumentException) {
}

$result = $audit->save(5, 'incorrecto', "  Canon   distinto\r\nen SIMI ", $actor);
$check($result['changed'] && $result['estado_simi'] === 'Incorrecto' && $result['observaciones_estado_simi'] === "Canon distinto\nen SIMI", 'Debe guardar el estado normalizado y limpiar espacios.');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 5');
$check($contract['estado_simi'] === 'Incorrecto' && $contract['observaciones_estado_simi'] === "Canon distinto\nen SIMI", 'El contrato guarda la auditoría.');
$check($contract['id_empleado'] === '1508' && $contract['realizado_por'] === 'Kellys Roqueme Camacho' && $contract['cct_author_id'] === '1508', 'La auditoría no cambia el funcionario ni el autor del contrato.');
$history = $db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble');
$check(count($history) === 1, 'Debe quedar un registro en el historial del inmueble.');
$check($history[0]['id_inmueble'] === '84016' && $history[0]['id_inmueble_data'] === '12466', 'El historial se asocia al inmueble del contrato.');
$check($history[0]['id_empleado'] === '2999' && $history[0]['cct_author_id'] === '2999' && $history[0]['funcionario'] === 'Coordinadora QA', 'El historial registra al funcionario que audita con su id_empleado.');
$check($history[0]['tipo_reporte'] === 'Auditoría de contrato' && str_contains($history[0]['observacion'], 'Se encontró incorrecto. Observaciones: Canon distinto'), 'El historial describe el resultado.');

$contractHistory = $db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento');
$check(count($contractHistory) === 1, 'Debe quedar un registro en el historial del contrato.');
$check($contractHistory[0]['id_contrato'] === '5' && $contractHistory[0]['contrato'] === '777' && $contractHistory[0]['inmueble'] === '10532' && $contractHistory[0]['id_inmueble'] === '84016', 'El historial del contrato identifica contrato e inmueble.');
$check((string) $contractHistory[0]['cct_author_id'] === '2999' && $contractHistory[0]['id_empleado'] === '2999' && $contractHistory[0]['funcionario'] === 'Coordinadora QA', 'El historial del contrato registra al funcionario que audita.');
$check($contractHistory[0]['tipo_reporte'] === 'Auditoría de contrato' && $contractHistory[0]['observacion'] === $history[0]['observacion'], 'Ambos historiales describen la misma auditoría.');

$same = $audit->save(5, 'Incorrecto', "Canon distinto\nen SIMI", $actor);
$check(!$same['changed'] && count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble')) === 1, 'Repetir la misma auditoría no agrega historial.');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento')) === 1, 'Repetir la misma auditoría tampoco duplica el historial del contrato.');
$fixed = $audit->save(5, 'Correcto', '', $actor);
$history = $db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble ORDER BY _ID');
$check($fixed['changed'] && count($history) === 2 && str_ends_with($history[1]['observacion'], 'Se encontró correcto.'), 'Un nuevo resultado agrega su propio registro al historial.');

// Correcciones de condiciones del contrato en la misma auditoría.
foreach (['valor_canon', 'valor_administracion', 'meses_contrato', 'derechos_inmobiliarios', 'tasa_administracion', 'dias_gracia'] as $column) {
  $pdo->exec('ALTER TABLE wp_jet_cct_contratos_arrendamiento ADD COLUMN ' . $column . ' TEXT');
}
$db->update('wp_jet_cct_contratos_arrendamiento', ['valor_canon' => '1.000.000', 'valor_administracion' => '120000.00', 'meses_contrato' => '12', 'derechos_inmobiliarios' => '10', 'tasa_administracion' => '8%', 'dias_gracia' => ''], ['_ID' => 5]);
$audit = new ContractSimiAudit($db);
$check(ContractSimiAudit::correctableKeys($db, new \SCM\Support\SchemaInspector($db)) === ['canon', 'admin', 'meses', 'derechos', 'tasa_admin', 'dias_gracia'], 'Las seis condiciones se pueden corregir cuando existen.');
foreach ([['meses', '0'], ['derechos', '120'], ['dias_gracia', '-1'], ['canon', 'abc'], ['otro', '1']] as [$key, $bad]) {
  try {
    $audit->save(5, 'Correcto', '', $actor, [$key => $bad]);
    $check(false, 'Debía rechazar ' . $key . '=' . $bad);
  } catch (InvalidArgumentException) {
  }
}
$historyBefore = count($db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento'));
$fix = $audit->save(5, 'Correcto', '', $actor, ['canon' => '1.100.000', 'admin' => '120000', 'derechos' => '10,5', 'dias_gracia' => '15']);
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 5');
$check($contract['valor_canon'] === '1100000.00' && $contract['derechos_inmobiliarios'] === '10.5' && $contract['dias_gracia'] === '15', 'Las condiciones corregidas se guardan en el contrato.');
$check($contract['valor_administracion'] === '120000.00' && $contract['meses_contrato'] === '12' && $contract['tasa_administracion'] === '8%', 'Lo que no cambia no se reescribe.');
$check($contract['id_empleado'] === '1508' && $contract['cct_author_id'] === '1508', 'Corregir condiciones no cambia el funcionario ni el autor.');
$check($fix['changed'] && $fix['values'] === ['canon' => 1100000, 'admin' => 120000, 'derechos' => 10.5, 'dias_gracia' => 15], 'Devuelve los valores aplicados.');
$check($fix['adjustments'] === ['Canon de $1.000.000 a $1.100.000', '% derechos de 10% a 10,5%', 'Días de gracia de sin dato a 15 días'], 'Registra el valor anterior y el nuevo: ' . json_encode($fix['adjustments'], JSON_UNESCAPED_UNICODE));
$last = $db->getRow('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento ORDER BY _ID DESC LIMIT 1');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento')) === $historyBefore + 1 && str_contains($last['observacion'], 'Se encontró correcto. Ajustes: Canon de $1.000.000 a $1.100.000; % derechos de 10% a 10,5%'), 'El historial del contrato describe los ajustes.');
$lastProperty = $db->getRow('SELECT * FROM wp_jet_cct_historial_del_inmueble ORDER BY _ID DESC LIMIT 1');
$check(str_contains($lastProperty['observacion'], 'Ajustes: Canon de $1.000.000'), 'El historial del inmueble también describe los ajustes.');
$same = $audit->save(5, 'Correcto', '', $actor, ['canon' => '1100000']);
$check(!$same['changed'] && $same['adjustments'] === [], 'Repetir el mismo valor no agrega historial.');

echo "Contract SIMI audit checks passed.\n";
