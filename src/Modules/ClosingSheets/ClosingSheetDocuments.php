<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\ContractCoOwners;
use SCM\Support\SchemaInspector;
use SCM\Support\StoredFileService;

/**
 * Documentos de la hoja de cierre y del contrato de arrendamiento (una sola carpeta por negocio), en el almacenamiento
 * del panel con enlace firmado (file.php).
 *
 * - Se suben desde la lista de chequeo (un archivo por ítem de documento; los del propietario, por cada propietario), con
 *   "Documentos adicionales" aparte, o desde la carpeta del contrato (documentos-contrato.php), también para contratos
 *   viejos sin hoja nativa.
 * - Al crear la hoja todavía no hay número: los archivos quedan del mandato y se pasan a la hoja al guardarla (claim).
 * - La carpeta del contrato junta los del contrato y los de sus hojas de cierre (id_cierre del contrato o
 *   id_contrato_arrendamiento de la hoja); el enlace queda en la columna link_documentos del contrato.
 * - Eliminar no borra el archivo: marca quién y cuándo (se ve en la carpeta como historial).
 */
final class ClosingSheetDocuments
{
  use ClosingSheetRecords;

  /** Lo que se adjunta al correo "Hoja de cierre respondida"; lo demás queda en la hoja (enlace). */
  public const MAX_EMAIL_BYTES = 15 * 1024 * 1024;
  public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
  public const LINK_COLUMN = 'link_documentos';

  private SchemaInspector $schema;
  /** @var callable(array<string,mixed>):?array{name:string,url:string,mime:string,bytes:int,sha256:string} */
  private $persist;

  /** @param (callable(array<string,mixed>):?array{name:string,url:string,mime:string,bytes:int,sha256:string})|null $persist guarda el archivo (las pruebas lo reemplazan) */
  public function __construct(private Database $db, ?callable $persist = null)
  {
    $this->schema = new SchemaInspector($db);
    $this->persist = $persist ?? static fn(array $file): ?array => StoredFileService::fromRuntime()->storeUploadedFile($file);
  }

  public function table(): string
  {
    return $this->db->table('scm_closing_documents');
  }

  public function ensureSchema(): void
  {
    if ($this->schema->tableExists($this->table())) {
      return;
    }
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      closing_id BIGINT UNSIGNED NOT NULL DEFAULT 0, mandate_id BIGINT UNSIGNED NOT NULL DEFAULT 0, contract_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      item VARCHAR(80) NOT NULL, person TINYINT UNSIGNED NOT NULL DEFAULT 0, title VARCHAR(190) NOT NULL DEFAULT '',
      original_name VARCHAR(190) NOT NULL DEFAULT '', file_name VARCHAR(80) NOT NULL, url VARCHAR(500) NOT NULL,
      mime VARCHAR(100) NOT NULL DEFAULT '', bytes INT UNSIGNED NOT NULL DEFAULT 0, sha256 CHAR(64) NOT NULL DEFAULT '',
      created_by VARCHAR(50) NOT NULL DEFAULT '', created_by_name VARCHAR(120) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
      deleted_at BIGINT NOT NULL DEFAULT 0, deleted_by VARCHAR(50) NOT NULL DEFAULT '', deleted_by_name VARCHAR(120) NOT NULL DEFAULT '',
      KEY closing_docs (closing_id, deleted_at), KEY mandate_docs (mandate_id, closing_id), KEY contract_docs (contract_id, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  /**
   * Documentos que se pueden subir: ítem => grupo de la lista, nombre corto y si va por cada propietario.
   * @return array<string,array{group:string,label:string,owners:bool,column:string}>
   */
  public static function catalog(): array
  {
    $out = [];
    foreach (ClosingSheetForm::CHECKLIST as $notesColumn => [$group, , $items]) {
      foreach ($items as [$column]) {
        if (isset(ClosingSheetForm::DOCUMENT_ITEMS[$column])) {
          $out[$column] = ['group' => $group, 'label' => ClosingSheetForm::DOCUMENT_ITEMS[$column], 'owners' => $notesColumn === ClosingSheetForm::OWNER_DOCUMENTS, 'column' => $notesColumn];
        }
      }
    }
    $out[ClosingSheetForm::EXTRA_DOCUMENTS] = ['group' => 'Documentos adicionales', 'label' => 'Documento adicional', 'owners' => false, 'column' => ''];
    return $out;
  }

  /** "Propietario principal" o "Copropietario N", con el nombre si se conoce. */
  public static function personLabel(int $person, string $name = ''): string
  {
    $label = $person <= 1 ? 'Propietario principal' : 'Copropietario ' . $person;
    return trim($name) !== '' ? $label . ': ' . trim($name) : $label;
  }

  /** @return list<array<string,mixed>> */
  public function forClosing(int $closingId, bool $deleted = false): array
  {
    return $closingId > 0 ? $this->query('`closing_id` = ?', [$closingId], $deleted) : [];
  }

  /** Subidos al crear la hoja, antes de guardarla. @return list<array<string,mixed>> */
  public function pending(int $mandateId): array
  {
    return $mandateId > 0 ? $this->query('`mandate_id` = ? AND `closing_id` = 0 AND `contract_id` = 0', [$mandateId], false) : [];
  }

  /** Los del contrato y los de sus hojas de cierre. @param list<int> $closingIds @return list<array<string,mixed>> */
  public function forContract(int $contractId, array $closingIds, bool $deleted = false): array
  {
    $closingIds = array_values(array_unique(array_filter(array_map('intval', $closingIds), static fn(int $id): bool => $id > 0)));
    $where = '`contract_id` = ?' . ($closingIds !== [] ? ' OR `closing_id` IN (' . implode(',', array_fill(0, count($closingIds), '?')) . ')' : '');
    return $contractId > 0 ? $this->query('(' . $where . ')', array_merge([$contractId], $closingIds), $deleted) : [];
  }

  /** La hoja recién creada se queda con lo que se subió desde el mandato. */
  public function claim(int $mandateId, int $closingId): int
  {
    if ($mandateId <= 0 || $closingId <= 0 || !$this->schema->tableExists($this->table())) {
      return 0;
    }
    return $this->db->update($this->table(), ['closing_id' => $closingId], ['mandate_id' => $mandateId, 'closing_id' => 0, 'contract_id' => 0, 'deleted_at' => 0]);
  }

  /**
   * Guarda un archivo en la casilla de un documento.
   * @param array{closing_id?:int,mandate_id?:int,contract_id?:int} $scope hoja, contrato o (hoja sin crear) mandato
   * @param array<string,mixed> $file entrada de $_FILES
   * @param array{employee_id:string,name:string} $sender
   * @return array<string,mixed>
   */
  public function store(array $scope, string $item, int $person, string $title, array $file, array $sender): array
  {
    $catalog = self::catalog();
    if (!isset($catalog[$item])) {
      throw new \DomainException('Ese documento no está en la lista.');
    }
    $person = $catalog[$item]['owners'] ? max(1, min(ContractCoOwners::MAX, $person)) : 0;
    $scope = $this->scope($scope);
    $original = mb_substr(trim(strip_tags(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))))), 0, 150, 'UTF-8') ?: 'archivo';
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $max = defined('SCM_UPLOAD_MAX_BYTES') ? (int) SCM_UPLOAD_MAX_BYTES : 10485760;
    $limit = round($max / 1048576) . ' MB';
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > $max) {
      throw new \DomainException('«' . $original . '» pesa más de ' . $limit . '. Comprímelo o divídelo e inténtalo de nuevo.');
    }
    if ($error !== UPLOAD_ERR_OK || (string) ($file['tmp_name'] ?? '') === '') {
      throw new \DomainException('No se recibió «' . $original . '». Intenta adjuntarlo de nuevo.');
    }
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
      throw new \DomainException('«' . $original . '» no es un formato permitido. Usa PDF, JPG, PNG, Word o Excel.');
    }
    $hash = (string) @hash_file('sha256', (string) $file['tmp_name']);
    $this->ensureSchema();
    if ($hash !== '' && (int) $this->db->getVar(
      "SELECT COUNT(1) FROM `{$this->table()}` WHERE `sha256` = ? AND `item` = ? AND `person` = ? AND `closing_id` = ? AND `contract_id` = ? AND `mandate_id` = ? AND `deleted_at` = 0",
      [$hash, $item, $person, $scope['closing_id'], $scope['contract_id'], $scope['mandate_id']]
    ) > 0) {
      throw new \DomainException('«' . $original . '» ya está cargado en este documento.');
    }
    $stored = ($this->persist)($file);
    if (!is_array($stored) || ($stored['name'] ?? '') === '') {
      throw new \DomainException('No se pudo guardar «' . $original . '». Revisa que no esté dañado y que sea PDF, JPG, PNG, Word o Excel.');
    }
    // Las fotos se guardan como JPG comprimido: el nombre que se muestra lleva la extensión real.
    $storedExtension = strtolower(pathinfo((string) $stored['name'], PATHINFO_EXTENSION));
    if ($storedExtension !== '' && $storedExtension !== $extension && !($storedExtension === 'jpg' && $extension === 'jpeg')) {
      $original = pathinfo($original, PATHINFO_FILENAME) . '.' . $storedExtension;
    }
    $row = $scope + [
      'item' => $item, 'person' => $person,
      'title' => $item === ClosingSheetForm::EXTRA_DOCUMENTS ? mb_substr(trim(strip_tags($title)), 0, 120, 'UTF-8') : '',
      'original_name' => $original, 'file_name' => (string) $stored['name'], 'url' => (string) $stored['url'], 'mime' => (string) ($stored['mime'] ?? ''),
      'bytes' => (int) ($stored['bytes'] ?? 0), 'sha256' => $hash, 'created_by' => $sender['employee_id'], 'created_by_name' => mb_substr($sender['name'], 0, 120, 'UTF-8'),
      'created_at' => time(), 'deleted_at' => 0, 'deleted_by' => '', 'deleted_by_name' => '',
    ];
    if (!$this->db->insert($this->table(), $row)) {
      throw new \RuntimeException('No se pudo registrar el documento.');
    }
    if ($scope['contract_id'] > 0) {
      $this->linkContract($scope['contract_id']);
    }
    return self::format(['id' => (int) $this->db->lastInsertId()] + $row);
  }

  /** @param array{employee_id:string,name:string} $sender @return array<string,mixed> el documento eliminado */
  public function delete(int $id, array $sender): array
  {
    $row = $id > 0 && $this->schema->tableExists($this->table()) ? $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1", [$id]) : null;
    if (!is_array($row) || (int) $row['deleted_at'] > 0) {
      throw new \DomainException('Ese documento ya no está en la carpeta.');
    }
    $this->db->update($this->table(), ['deleted_at' => time(), 'deleted_by' => $sender['employee_id'], 'deleted_by_name' => mb_substr($sender['name'], 0, 120, 'UTF-8')], ['id' => $id]);
    return self::format($row);
  }

  /** @return array<string,mixed> */
  public function find(int $id): array
  {
    $row = $id > 0 && $this->schema->tableExists($this->table()) ? $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1", [$id]) : null;
    return is_array($row) ? self::format($row) : [];
  }

  /**
   * Carpeta de un contrato (y sus hojas de cierre) o de una hoja sola.
   * @return array{contract:array<string,mixed>,closing:array<string,mixed>,closing_ids:list<int>,documents:list<array<string,mixed>>,deleted:list<array<string,mixed>>,owners:array<int,string>,codebtors:int,scope:array<string,int>,url:string}
   */
  public function folder(int $contractId, int $closingId): array
  {
    $contract = $contractId > 0 ? $this->row('jet_cct_contratos_arrendamiento', $contractId) : [];
    if ($contractId > 0 && $contract === []) {
      throw new \DomainException('No existe el contrato de arrendamiento #' . $contractId . '.');
    }
    $closing = $closingId > 0 ? $this->row('jet_cct_cierres', $closingId) : [];
    if ($closingId > 0 && $closing === []) {
      throw new \DomainException('No existe la hoja de cierre #' . $closingId . '.');
    }
    if ($contract === [] && self::id($closing['id_contrato_arrendamiento'] ?? '') > 0) {
      $contract = $this->row('jet_cct_contratos_arrendamiento', self::id($closing['id_contrato_arrendamiento']));
    }
    $closingIds = $closingId > 0 ? [$closingId] : [];
    if ($contract !== []) {
      $closingIds[] = self::id($contract['id_cierre'] ?? '');
      $closings = $this->db->table('jet_cct_cierres');
      if ($this->schema->tableExists($closings) && $this->schema->columnExists($closings, 'id_contrato_arrendamiento')) {
        $closingIds = array_merge($closingIds, array_map('intval', $this->db->getCol("SELECT `_ID` FROM `{$closings}` WHERE TRIM(COALESCE(`id_contrato_arrendamiento`, '')) = ?", [(string) $contract['_ID']])));
      }
      $closingIds = array_values(array_unique(array_filter($closingIds, static fn(int $id): bool => $id > 0)));
      if ($closing === [] && $closingIds !== []) {
        $closing = $this->row('jet_cct_cierres', max($closingIds));
      }
    }
    $id = (int) ($contract['_ID'] ?? 0);
    return [
      'contract' => $contract, 'closing' => $closing, 'closing_ids' => $closingIds,
      'documents' => $id > 0 ? $this->forContract($id, $closingIds) : $this->forClosing($closingId),
      'deleted' => $id > 0 ? $this->forContract($id, $closingIds, true) : $this->forClosing($closingId, true),
      'codebtors' => $this->codebtors($closing, $contract),
      'owners' => $this->owners($closing, $contract),
      'scope' => $id > 0 ? ['contract_id' => $id] : ['closing_id' => $closingId],
      'url' => self::folderUrl($id, $closingId),
    ];
  }

  public static function folderUrl(int $contractId, int $closingId = 0): string
  {
    return rtrim((string) (defined('SCM_BASE_URL') ? SCM_BASE_URL : ''), '/') . '/documentos-contrato.php?' . ($contractId > 0 ? 'contrato=' . $contractId : 'cierre=' . $closingId);
  }

  /** Enlace de la carpeta en la columna link_documentos del contrato (si existe la columna). */
  public function linkContract(int $contractId): void
  {
    $this->update('jet_cct_contratos_arrendamiento', $contractId, [self::LINK_COLUMN => self::folderUrl($contractId)]);
  }

  /**
   * Adjuntos del correo hasta MAX_EMAIL_BYTES, en el orden de la lista; el resto se ve en la hoja.
   * @param list<array<string,mixed>> $documents
   * @return array{files:list<array{path:string,name:string}>,skipped:int}
   */
  public static function attachments(array $documents, ?StoredFileService $files = null): array
  {
    $files ??= StoredFileService::fromRuntime();
    $catalog = self::catalog();
    $order = array_flip(array_keys($catalog));
    usort($documents, static fn(array $a, array $b): int => [$order[$a['item']] ?? 999, $a['person'], $a['id']] <=> [$order[$b['item']] ?? 999, $b['person'], $b['id']]);
    $out = [];
    $total = 0;
    $skipped = 0;
    foreach ($documents as $doc) {
      $path = $files->pathFor((string) $doc['file']);
      $size = $path !== null ? (int) filesize($path) : 0;
      if ($path === null || $total + $size > self::MAX_EMAIL_BYTES) {
        $skipped++;
        continue;
      }
      $total += $size;
      $label = ($catalog[$doc['item']]['label'] ?? 'Documento') . ($doc['person'] > 1 ? ' copropietario ' . $doc['person'] : '');
      $name = $doc['item'] === ClosingSheetForm::EXTRA_DOCUMENTS ? $doc['name'] : $label . ' - ' . $doc['name'];
      $extension = pathinfo($path, PATHINFO_EXTENSION);
      $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $name) ?? '') ?: 'documento';
      if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower($extension)) {
        $name .= '.' . $extension;
      }
      $out[] = ['path' => $path, 'name' => $name];
    }
    return ['files' => $out, 'skipped' => $skipped];
  }

  /** @return array<string,mixed> */
  private static function format(array $row): array
  {
    $mime = (string) ($row['mime'] ?? '');
    $title = trim((string) ($row['title'] ?? ''));
    return [
      'id' => (int) $row['id'], 'item' => (string) $row['item'], 'person' => (int) $row['person'],
      'name' => $title !== '' ? $title : (string) $row['original_name'], 'original' => (string) $row['original_name'],
      'url' => (string) $row['url'], 'file' => (string) $row['file_name'], 'mime' => $mime,
      'kind' => $mime === 'application/pdf' ? 'pdf' : (str_starts_with($mime, 'image/') ? 'image' : 'file'),
      'bytes' => (int) $row['bytes'], 'by' => (string) $row['created_by_name'], 'at' => (int) $row['created_at'],
      'closing_id' => (int) $row['closing_id'], 'contract_id' => (int) $row['contract_id'],
      'deleted_at' => (int) ($row['deleted_at'] ?? 0), 'deleted_by' => (string) ($row['deleted_by_name'] ?? ''),
    ];
  }

  /** @param list<mixed> $args @return list<array<string,mixed>> */
  private function query(string $where, array $args, bool $deleted): array
  {
    if (!$this->schema->tableExists($this->table())) {
      return [];
    }
    $rows = $this->db->getResults("SELECT * FROM `{$this->table()}` WHERE {$where} AND `deleted_at` " . ($deleted ? '> 0' : '= 0') . ' ORDER BY `id`', $args);
    return array_map(static fn(array $row): array => self::format($row), $rows);
  }

  /** @param array{closing_id?:int,mandate_id?:int,contract_id?:int} $scope @return array{closing_id:int,mandate_id:int,contract_id:int} */
  private function scope(array $scope): array
  {
    $closingId = (int) ($scope['closing_id'] ?? 0);
    $contractId = (int) ($scope['contract_id'] ?? 0);
    $mandateId = (int) ($scope['mandate_id'] ?? 0);
    if ($closingId > 0) {
      $closing = $this->row('jet_cct_cierres', $closingId);
      if ($closing === []) {
        throw new \DomainException('No existe la hoja de cierre #' . $closingId . '.');
      }
      return ['closing_id' => $closingId, 'mandate_id' => self::id($closing['id_contrato'] ?? ''), 'contract_id' => $contractId > 0 ? $contractId : self::id($closing['id_contrato_arrendamiento'] ?? '')];
    }
    if ($contractId > 0) {
      $contract = $this->row('jet_cct_contratos_arrendamiento', $contractId);
      if ($contract === []) {
        throw new \DomainException('No existe el contrato de arrendamiento #' . $contractId . '.');
      }
      $closing = $this->row('jet_cct_cierres', self::id($contract['id_cierre'] ?? ''));
      return ['closing_id' => (int) ($closing['_ID'] ?? 0), 'mandate_id' => self::id($contract['id_contrato_mandato'] ?? ''), 'contract_id' => $contractId];
    }
    if ($mandateId > 0 && $this->row('jet_cct_contrato_mandato', $mandateId) !== []) {
      return ['closing_id' => 0, 'mandate_id' => $mandateId, 'contract_id' => 0];
    }
    throw new \DomainException('No se encontró la hoja, el contrato o el mandato del documento.');
  }

  /** Propietarios (índice => nombre): los de la hoja o, sin hoja, los del contrato y su mandato. @return array<int,string> */
  private function owners(array $closing, array $contract): array
  {
    if ($closing !== []) {
      $out = [1 => trim((string) ($closing['propietario'] ?? ''))];
      $count = max(1, min(ContractCoOwners::MAX, (int) ($closing['propietarios'] ?? 1)));
      for ($i = 2; $i <= $count; $i++) {
        $out[$i] = trim((string) ($closing['propietario_' . $i] ?? ''));
      }
      return $out;
    }
    if ($contract === []) {
      return [1 => ''];
    }
    $mandate = $this->row('jet_cct_contrato_mandato', $contract['id_contrato_mandato'] ?? '');
    $out = [];
    foreach (ContractCoOwners::resolve($contract, $mandate !== [] ? $mandate : null) as $owner) {
      $out[max(1, (int) $owner['index'])] = (string) $owner['name'];
    }
    return $out !== [] ? $out : [1 => trim((string) ($contract['propietario'] ?? ''))];
  }

  private function codebtors(array $closing, array $contract): int
  {
    $count = ctype_digit(trim((string) ($closing['num_codeudores'] ?? ''))) ? (int) $closing['num_codeudores'] : 0;
    for ($i = 1; $i <= 4; $i++) {
      if (trim((string) ($contract['codeudor_' . $i] ?? '')) !== '') {
        $count = max($count, $i);
      }
    }
    return min(4, $count);
  }

  private static function id(mixed $value): int
  {
    $value = trim((string) $value);
    return ctype_digit($value) ? (int) $value : 0;
  }
}
