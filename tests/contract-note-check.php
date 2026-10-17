<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\ContractHistory\ContractNoteService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

final class ContractNoteTestPdo extends PDO
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

$pdo = new ContractNoteTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, inmueble TEXT, id_inmueble TEXT, id_inmueble_data TEXT, id_empleado TEXT, cct_author_id TEXT)');
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 5, 'contrato' => '777', 'inmueble' => '10532', 'id_inmueble' => '84016', 'id_inmueble_data' => '12466', 'id_empleado' => '1508', 'cct_author_id' => '1508']);
$service = new ContractNoteService($db);
$actor = ['employee_id' => '2999', 'name' => 'Coordinadora QA'];

// Sin tabla el popup avisa y no se guarda nada.
$check($service->options()['enabled'] === false, 'Sin tabla de historial el módulo queda deshabilitado.');
try {
  $service->create(['contract_id' => 5, 'tipo_reporte' => 'Nota', 'observacion' => 'x'], $actor);
  $check(false, 'Sin tabla no se puede guardar.');
} catch (DomainException) {
}

$pdo->exec('CREATE TABLE wp_jet_cct_historial_contrato_arrendamiento(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha INTEGER, tipo_reporte TEXT, observacion TEXT, funcionario TEXT, id_empleado TEXT, id_ticket TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, inmueble TEXT, contrato TEXT, id_contrato TEXT)');
$service = new ContractNoteService($db);
$options = $service->options();
$check($options['enabled'] && !$options['images_enabled'] && !$options['document_enabled'], 'Sin campos imagen/archivo los soportes quedan deshabilitados.');
$check(in_array('Llamada', $options['types'], true), 'Hay tipos de reporte sugeridos por defecto.');

foreach ([
  [['contract_id' => 5, 'tipo_reporte' => '', 'observacion' => 'Algo'], 'tipo de reporte'],
  [['contract_id' => 5, 'tipo_reporte' => 'Nota', 'observacion' => '  '], 'Describe'],
  [['contract_id' => 99, 'tipo_reporte' => 'Nota', 'observacion' => 'Algo'], 'contrato existente'],
] as [$input, $message]) {
  try {
    $service->create($input, $actor);
    $check(false, 'Debía rechazar: ' . $message);
  } catch (InvalidArgumentException $error) {
    $check(str_contains($error->getMessage(), $message), 'Mensaje inesperado: ' . $error->getMessage());
  }
}
try {
  $service->create(['contract_id' => 5, 'tipo_reporte' => 'Nota', 'observacion' => 'Con foto'], $actor, ['images' => ['https://x/file.php?n=a.jpg'], 'documents' => []]);
  $check(false, 'Sin campo imagen no se aceptan imágenes.');
} catch (DomainException $error) {
  $check(str_contains($error->getMessage(), 'imagen'), 'El error nombra el campo imagen.');
}

$pdo->exec('ALTER TABLE wp_jet_cct_historial_contrato_arrendamiento ADD COLUMN imagen TEXT');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, nombre_empleado TEXT, funcionario TEXT, id_inmueble TEXT, id_inmueble_data TEXT, fecha TEXT, tipo_reporte TEXT, observacion TEXT)');
$pdo->exec('ALTER TABLE wp_jet_cct_historial_contrato_arrendamiento ADD COLUMN archivo TEXT');
$service = new ContractNoteService($db);
$note = $service->create(
  ['contract_id' => 5, 'tipo_reporte' => '  Llamada ', 'observacion' => "Se llamó al arrendatario.\r\nAcordó pagar el viernes.<script>x</script>"],
  $actor,
  ['images' => ['https://x/file.php?n=a.jpg', 'https://x/file.php?n=b.jpg'], 'documents' => ['https://x/file.php?n=c.pdf', 'https://x/file.php?n=d.docx']]
);
$row = $db->getRow('SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento WHERE _ID = ?', [$note['id']]);
$check($note['contract_id'] === 5 && $note['contrato'] === '777', 'Devuelve el contrato de la nota.');
$check($row['id_contrato'] === '5' && $row['contrato'] === '777' && $row['inmueble'] === '10532' && $row['id_inmueble'] === '84016' && $row['id_inmueble_data'] === '12466', 'La nota guarda contrato e inmueble del contrato.');
$check($row['tipo_reporte'] === 'Llamada' && $row['observacion'] === "Se llamó al arrendatario.\nAcordó pagar el viernes.x", 'Tipo y observación se limpian.');
$check((string) $row['cct_author_id'] === '2999' && $row['id_empleado'] === '2999' && $row['funcionario'] === 'Coordinadora QA' && $row['cct_status'] === 'publish', 'La nota registra al funcionario con su id_empleado.');
$check($row['imagen'] === 'https://x/file.php?n=a.jpg,https://x/file.php?n=b.jpg' && $row['archivo'] === 'https://x/file.php?n=c.pdf,https://x/file.php?n=d.docx', 'Imágenes y documentos como URLs separadas por coma.');
$property = $db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble');
$check(count($property) === 1, 'La nota también queda en el historial del inmueble.');
$check($property[0]['id_inmueble'] === '84016' && $property[0]['id_inmueble_data'] === '12466' && $property[0]['tipo_reporte'] === 'Llamada', 'El historial del inmueble usa el inmueble del contrato y el mismo tipo.');
$check($property[0]['observacion'] === "Contrato #777: Se llamó al arrendatario.
Acordó pagar el viernes.x" && $property[0]['id_empleado'] === '2999' && $property[0]['funcionario'] === 'Coordinadora QA', 'El historial del inmueble indica el contrato y el funcionario.');
$check($db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 5')['id_empleado'] === '1508', 'La nota no modifica el contrato.');

$service->create(['contract_id' => 5, 'tipo_reporte' => 'Visita técnica', 'observacion' => 'Segunda'], $actor);
$recent = $service->recent(5);
$check(count($recent) === 2 && $recent[0]['observacion'] === 'Segunda', 'El historial reciente muestra primero lo último.');
$check(count($db->getResults('SELECT * FROM wp_jet_cct_historial_del_inmueble')) === 2, 'Cada nota agrega su registro en el historial del inmueble.');
$check(in_array('Visita técnica', $service->options()['types'], true), 'Los tipos ya usados se sugieren.');
$check($service->recent(6) === [], 'Otro contrato no ve estas notas.');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-contract-note.js');
$check(str_contains($js, "'scm_panel_case_search'") && str_contains($js, "addEventListener('paste'") && str_contains($js, 'request_id') && str_contains($js, "'archivos[]'") && str_contains($js, 'compressImage'), 'El popup reutiliza la búsqueda, permite pegar capturas y envía request_id.');

echo "Contract note checks passed.\n";
