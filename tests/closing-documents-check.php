<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret');
define('SCM_UPLOAD_MAX_BYTES', 1024 * 1024);

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetDocuments;
use SCM\Modules\ClosingSheets\ClosingSheetDocumentsView;
use SCM\Support\StoredFileService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$fails = static function (callable $fn, string $needle): string {
  try {
    $fn();
  } catch (\DomainException $error) {
    return str_contains($error->getMessage(), $needle) ? $error->getMessage() : 'otro mensaje: ' . $error->getMessage();
  }
  return 'no falló';
};

final class ClosingDocumentsTestPdo extends PDO
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
}

$pdo = new ClosingDocumentsTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
};
$table('wp_jet_cct_cierres', ['id_contrato', 'id_contrato_arrendamiento', 'propietario', 'propietarios', 'propietario_2', 'num_codeudores', 'propiedad_horizontal', 'estado_rut_pro', 'estado_asegurable', 'direccion']);
$table('wp_jet_cct_contratos_arrendamiento', ['contrato', 'id_cierre', 'id_contrato_mandato', 'propietario', 'documento_propietario', 'codeudor_1', 'codeudor_2', 'direccion', 'link_documentos']);
$table('wp_jet_cct_contrato_mandato', ['propietarios', 'nombre_1', 'documento_1']);
// La tabla propia del módulo (en MySQL la crea ensureSchema()).
$pdo->exec('CREATE TABLE wp_scm_closing_documents(id INTEGER PRIMARY KEY AUTOINCREMENT, closing_id INTEGER NOT NULL DEFAULT 0, mandate_id INTEGER NOT NULL DEFAULT 0,
  contract_id INTEGER NOT NULL DEFAULT 0, item TEXT NOT NULL, person INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT "", original_name TEXT NOT NULL DEFAULT "",
  file_name TEXT NOT NULL, url TEXT NOT NULL, mime TEXT NOT NULL DEFAULT "", bytes INTEGER NOT NULL DEFAULT 0, sha256 TEXT NOT NULL DEFAULT "",
  created_by TEXT NOT NULL DEFAULT "", created_by_name TEXT NOT NULL DEFAULT "", created_at INTEGER NOT NULL, deleted_at INTEGER NOT NULL DEFAULT 0,
  deleted_by TEXT NOT NULL DEFAULT "", deleted_by_name TEXT NOT NULL DEFAULT "")');

$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 636, 'propietarios' => '1', 'nombre_1' => 'Yesenis Morales', 'documento_1' => '3243894030']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 700, 'propietarios' => '1', 'nombre_1' => 'Dueño viejo', 'documento_1' => '111']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 430, 'id_contrato' => '636', 'id_contrato_arrendamiento' => '', 'propietario' => 'Yesenis Morales', 'propietarios' => '2', 'propietario_2' => 'Inversiones X SAS', 'num_codeudores' => '1', 'propiedad_horizontal' => 'No', 'estado_rut_pro' => 'Cumple', 'estado_asegurable' => 'Cumple', 'direccion' => 'Carrera 32B']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 900, 'contrato' => '4521', 'id_cierre' => '430', 'id_contrato_mandato' => '636', 'propietario' => 'Yesenis Morales', 'codeudor_1' => 'Ana', 'codeudor_2' => 'Luis', 'link_documentos' => '']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 901, 'contrato' => '1200', 'id_cierre' => '', 'id_contrato_mandato' => '700', 'propietario' => 'Dueño viejo', 'documento_propietario' => '111', 'link_documentos' => '']);

// Archivos de prueba: se "guardan" sin pasar por is_uploaded_file.
$dir = sys_get_temp_dir() . '/scm-closing-docs-' . bin2hex(random_bytes(4));
mkdir($dir);
$storage = new StoredFileService($dir, SCM_BASE_URL, SCM_APP_SECRET, SCM_UPLOAD_MAX_BYTES);
$counter = 0;
$persist = static function (array $file) use ($dir, $storage, &$counter): ?array {
  $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
  $name = str_pad(dechex(++$counter), 24, '0', STR_PAD_LEFT) . '_' . time() . '.' . ($extension === 'png' ? 'jpg' : $extension);
  copy((string) $file['tmp_name'], $dir . '/' . $name);
  return ['name' => $name, 'url' => $storage->urlFor($name), 'mime' => $extension === 'pdf' ? 'application/pdf' : 'image/jpeg', 'bytes' => (int) filesize($dir . '/' . $name), 'sha256' => ''];
};
$upload = static function (string $name, string $content) use ($dir): array {
  $tmp = $dir . '/tmp-' . bin2hex(random_bytes(4));
  file_put_contents($tmp, $content);
  return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
};
$sender = ['employee_id' => '1508', 'name' => 'Kellys Roqueme'];
$docs = new ClosingSheetDocuments($db, $persist);

$catalog = ClosingSheetDocuments::catalog();
$check($catalog['estado_asegurable']['label'] === 'Estudio de la aseguradora' && $catalog['estado_rut_pro']['owners'] && !$catalog['estado_rut_arre']['owners'] && isset($catalog['adicional'], $catalog['estado_documento_co4']), 'catalog: insurer study, documents per owner, codebtors and additional documents');
$check(!isset($catalog['estado_amparo_integral']) && !isset($catalog['estado_nombre_administrador']), 'questions and data of the checklist do not take files');

// Hoja nueva (aún sin número): los archivos quedan del mandato.
$tradicion = $docs->store(['mandate_id' => 636], 'estado_certificado_tradicion', 3, '', $upload('Tradición.pdf', 'pdf-1'), $sender);
$check($tradicion['person'] === 0 && $tradicion['kind'] === 'pdf' && $tradicion['by'] === 'Kellys Roqueme' && str_contains($tradicion['url'], 'file.php?n='), 'a document is stored for the mandate with a signed link; a non-owner item has no person');
$rut2 = $docs->store(['mandate_id' => 636], 'estado_rut_pro', 2, '', $upload('rut socio.png', 'png-1'), $sender);
$check($rut2['person'] === 2 && $rut2['name'] === 'rut socio.jpg', 'owner documents keep the co-owner; a compressed photo is named .jpg');
$extra = $docs->store(['mandate_id' => 636], 'adicional', 0, 'Paz y salvo de la anterior inmobiliaria', $upload('ps.pdf', 'pdf-2'), $sender);
$check($extra['name'] === 'Paz y salvo de la anterior inmobiliaria', 'an additional document can have its own name');
$check(str_contains($fails(fn() => $docs->store(['mandate_id' => 636], 'estado_certificado_tradicion', 0, '', $upload('otra copia.pdf', 'pdf-1'), $sender), 'ya está cargado'), 'ya está cargado'), 'the same file twice in the same document is rejected');
$check(str_contains($fails(fn() => $docs->store(['mandate_id' => 636], 'estado_rut_arre', 0, '', $upload('virus.exe', 'x'), $sender), 'no es un formato permitido'), 'no es un formato'), 'formats other than PDF, image, Word or Excel are rejected');
$check(str_contains($fails(fn() => $docs->store(['mandate_id' => 636], 'estado_rut_arre', 0, '', ['size' => 2 * 1024 * 1024] + $upload('grande.pdf', 'x'), $sender), 'pesa más de 1 MB'), 'pesa más'), 'files over the upload limit are rejected with the limit');
$check(str_contains($fails(fn() => $docs->store(['mandate_id' => 636], 'estado_amparo_integral', 0, '', $upload('a.pdf', 'a'), $sender), 'no está en la lista'), 'no está en la lista'), 'only document items accept files');
$check(str_contains($fails(fn() => $docs->store(['mandate_id' => 999], 'estado_rut_arre', 0, '', $upload('a.pdf', 'b'), $sender), 'No se encontró'), 'No se encontró'), 'an unknown mandate cannot receive files');
$check(count($docs->pending(636)) === 3 && $docs->forClosing(430) === [], 'while the sheet has no number, the files are pending in the mandate');

// Al guardar la hoja, se queda con ellos.
$check($docs->claim(636, 430) === 3 && $docs->pending(636) === [] && count($docs->forClosing(430)) === 3, 'saving the new sheet claims the pending files');

// Desde el contrato: quedan en el contrato y en su hoja, y el contrato guarda el enlace de la carpeta.
$asegurable = $docs->store(['contract_id' => 900], 'estado_asegurable', 0, '', $upload('estudio.pdf', 'pdf-3'), $sender);
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 900');
$check($asegurable['contract_id'] === 900 && $asegurable['closing_id'] === 430 && $contract['link_documentos'] === 'https://example.test/panel/documentos-contrato.php?contrato=900', 'a contract upload also belongs to its closing sheet and the contract keeps the folder link');
$check(count($docs->forClosing(430)) === 4, 'the closing sheet view shows the files uploaded from the contract');

$folder = $docs->folder(900, 0);
$check(count($folder['documents']) === 4 && $folder['closing']['_ID'] == 430 && $folder['owners'] === [1 => 'Yesenis Morales', 2 => 'Inversiones X SAS'] && $folder['codebtors'] === 2 && $folder['scope'] === ['contract_id' => 900], 'contract folder: its files and the sheet files, owners from the sheet and codebtors from the contract');
$html = ClosingSheetDocumentsView::folder($folder, ['api' => 'x/api.php', 'nonce' => 'n', 'upload' => 'up', 'delete' => 'del', 'scope' => $folder['scope']]);
$check(substr_count($html, 'data-cd-item="estado_rut_pro"') === 2 && str_contains($html, 'Copropietario 2: Inversiones X SAS') && str_contains($html, 'data-cd-item="estado_rut_co2"') && !str_contains($html, 'data-cd-item="estado_rut_co3"') && !str_contains($html, 'data-cd-item="estado_reglamento_copropiedad"'), 'folder: one slot per owner, only existing codebtors, no building documents without PH');
$check(str_contains($html, 'data-cd-add') && str_contains($html, 'csf-result is-good">Cumple'), 'folder: upload buttons and the checklist state of each document');
$readOnly = ClosingSheetDocumentsView::folder($folder, null);
$check(!str_contains($readOnly, 'data-cd-add') && !str_contains($readOnly, 'data-cd-delete') && str_contains($readOnly, 'data-cd-preview'), 'without permission the folder only shows and previews');

// Eliminar deja rastro.
$docs->delete($rut2['id'], ['employee_id' => '77', 'name' => 'Ana Contractual']);
$folder = $docs->folder(900, 0);
$check(count($folder['documents']) === 3 && $folder['deleted'][0]['deleted_by'] === 'Ana Contractual' && $folder['deleted'][0]['deleted_at'] > 0, 'a deleted file leaves the folder and keeps who and when');
$check(str_contains(ClosingSheetDocumentsView::folder($folder, null), 'Eliminados (1)'), 'the folder lists deleted files as history');
$check(str_contains($fails(fn() => $docs->delete($rut2['id'], $sender), 'ya no está'), 'ya no está'), 'a file cannot be deleted twice');

// Contrato viejo sin hoja nativa: carpeta propia con el propietario del mandato.
$old = $docs->store(['contract_id' => 901], 'estado_documento_arre', 0, '', $upload('cedula.jpg', 'jpg-1'), $sender);
$folder = $docs->folder(901, 0);
$check($old['closing_id'] === 0 && count($folder['documents']) === 1 && $folder['closing'] === [] && $folder['owners'] === [1 => 'Dueño viejo'], 'an old contract without a native sheet gets its own folder');
$check(str_contains($fails(fn() => $docs->folder(5555, 0), 'No existe'), 'No existe'), 'an unknown contract has no folder');

// Correo de la respuesta: adjuntos en el orden de la lista, con nombre del documento.
$attachments = ClosingSheetDocuments::attachments($docs->forClosing(430), $storage);
$names = array_column($attachments['files'], 'name');
$check($attachments['skipped'] === 0 && $names === ['Certificado de tradición y libertad - Tradición.pdf', 'Estudio de la aseguradora - estudio.pdf', 'Paz y salvo de la anterior inmobiliaria.pdf'] && is_file($attachments['files'][0]['path']), 'email attachments follow the checklist order and carry the document name');

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
