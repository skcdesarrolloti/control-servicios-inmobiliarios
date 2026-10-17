<?php

declare(strict_types=1);

namespace SCM\Modules\ContractHistory;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;
use SCM\Support\StoredFileService;

/**
 * Notas del historial del contrato de arrendamiento (`jet_cct_historial_contrato_arrendamiento`).
 *
 * Un funcionario elige el contrato, el tipo de reporte y describe lo que se hizo. Puede adjuntar
 * imágenes (campo galería `imagen`, URLs separadas por coma como en los casos) y un documento
 * (campo media `archivo`, URL). Las imágenes se comprimen al guardarse. No toca el contrato.
 */
final class ContractNoteService
{
  public const TABLE = 'jet_cct_historial_contrato_arrendamiento';
  public const DEFAULT_TYPES = ['Nota', 'Llamada', 'Correo', 'WhatsApp', 'Visita', 'Acuerdo', 'Novedad', 'Documento'];
  public const MAX_IMAGES = 10;
  public const MAX_OBSERVATION = 5000;
  public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
  private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
  private const DOCUMENT_TYPES = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls' => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
  ];

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db, ?SchemaInspector $schema = null)
  {
    $this->db = $db;
    $this->schema = $schema ?: new SchemaInspector($db);
  }

  public function maxFileBytes(): int
  {
    return min(defined('SCM_UPLOAD_MAX_BYTES') ? (int) SCM_UPLOAD_MAX_BYTES : 10 * 1024 * 1024, 10 * 1024 * 1024);
  }

  /** @return array<string,mixed> */
  public function options(): array
  {
    $table = $this->db->table(self::TABLE);
    $exists = $this->schema->tableExists($table);
    $types = self::DEFAULT_TYPES;
    if ($exists && $this->schema->columnExists($table, 'tipo_reporte')) {
      // Los tipos ya usados en el historial se sugieren junto a los predeterminados.
      foreach ($this->db->getResults("SELECT DISTINCT TRIM(`tipo_reporte`) AS tipo FROM `{$table}` WHERE TRIM(COALESCE(`tipo_reporte`, '')) <> '' LIMIT 100") as $row) {
        $type = trim((string) ($row['tipo'] ?? ''));
        if ($type !== '' && !in_array(mb_strtolower($type, 'UTF-8'), array_map(static fn(string $t): string => mb_strtolower($t, 'UTF-8'), $types), true)) {
          $types[] = $type;
        }
      }
    }
    return [
      'enabled' => $exists,
      'types' => $types,
      'images_enabled' => $exists && $this->schema->columnExists($table, 'imagen'),
      'document_enabled' => $exists && $this->schema->columnExists($table, 'archivo'),
      'max_file_bytes' => $this->maxFileBytes(),
      'max_images' => self::MAX_IMAGES,
      'document_extensions' => array_keys(self::DOCUMENT_TYPES),
    ];
  }

  /** Últimas notas del contrato, para no repetir gestiones. @return list<array<string,string>> */
  public function recent(int $contractId, int $limit = 5): array
  {
    $table = $this->db->table(self::TABLE);
    if ($contractId <= 0 || !$this->schema->tableExists($table)) {
      return [];
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `fecha`, `tipo_reporte`, `observacion`, `funcionario` FROM `{$table}` WHERE `id_contrato` = ? ORDER BY `fecha` DESC, `_ID` DESC LIMIT " . max(1, min(20, $limit)),
      [(string) $contractId]
    );
    return array_map(static fn(array $row): array => [
      'id' => (string) ($row['_ID'] ?? ''),
      'fecha' => (int) ($row['fecha'] ?? 0) > 0 ? date('d/m/Y H:i', (int) $row['fecha']) : '',
      'tipo' => trim((string) ($row['tipo_reporte'] ?? '')),
      'observacion' => trim((string) ($row['observacion'] ?? '')),
      'funcionario' => trim((string) ($row['funcionario'] ?? '')),
    ], $rows);
  }

  /**
   * Valida los adjuntos de $_FILES antes de guardar nada.
   *
   * @param array<string,mixed> $files `imagenes` (varias) y `archivo` (uno)
   * @return array{images:int,document:bool}
   */
  public function validateFiles(array $files): array
  {
    $images = 0;
    $total = 0;
    $max = $this->maxFileBytes();
    foreach ($this->uploads($files['imagenes'] ?? null) as $file) {
      $this->assertUpload($file, $max);
      $info = @getimagesize((string) $file['tmp_name']);
      if (!$info || !in_array($info['mime'] ?? '', self::IMAGE_MIMES, true) || $info[0] * $info[1] > 16000000) {
        throw new \InvalidArgumentException('Usa imágenes JPG, PNG o WebP de máximo 16 megapíxeles.');
      }
      $images++;
      $total += (int) $file['size'];
    }
    if ($images > self::MAX_IMAGES) {
      throw new \InvalidArgumentException('Puedes adjuntar hasta ' . self::MAX_IMAGES . ' imágenes.');
    }
    $documents = $this->uploads($files['archivo'] ?? null);
    if (count($documents) > 1) {
      throw new \InvalidArgumentException('Adjunta un solo documento por nota.');
    }
    foreach ($documents as $file) {
      $this->assertUpload($file, $max);
      $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
      $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
      if (!isset(self::DOCUMENT_TYPES[$extension]) || !in_array($mime, self::DOCUMENT_TYPES[$extension], true)) {
        throw new \InvalidArgumentException('El documento debe ser PDF, Word o Excel.');
      }
      $total += (int) $file['size'];
    }
    if ($total > self::MAX_TOTAL_BYTES) {
      throw new \InvalidArgumentException('Los adjuntos superan 25 MB en total.');
    }
    return ['images' => $images, 'document' => $documents !== []];
  }

  /**
   * @param array<string,mixed> $input
   * @param array{employee_id:string,name:string} $actor
   * @param array{images:list<string>,document:string} $stored URLs ya guardadas
   * @return array{id:int,contract_id:int,contrato:string}
   */
  public function create(array $input, array $actor, array $stored = ['images' => [], 'document' => '']): array
  {
    $table = $this->db->table(self::TABLE);
    if (!$this->schema->tableExists($table)) {
      throw new \DomainException('Falta la tabla del historial del contrato de arrendamiento.');
    }
    $type = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($input['tipo_reporte'] ?? ''))) ?? '');
    $observation = trim(str_replace("\r\n", "\n", strip_tags((string) ($input['observacion'] ?? ''))));
    if ($type === '' || mb_strlen($type, 'UTF-8') > 80) {
      throw new \InvalidArgumentException('Indica el tipo de reporte (máximo 80 caracteres).');
    }
    if ($observation === '' || mb_strlen($observation, 'UTF-8') > self::MAX_OBSERVATION) {
      throw new \InvalidArgumentException('Describe lo que se hizo (máximo ' . self::MAX_OBSERVATION . ' caracteres).');
    }
    $employeeId = trim((string) ($actor['employee_id'] ?? ''));
    if ($employeeId === '') {
      throw new \DomainException('Tu usuario no tiene un id de funcionario asociado.');
    }
    if ($stored['images'] !== [] && !$this->schema->columnExists($table, 'imagen')) {
      throw new \DomainException('Falta el campo imagen en el historial del contrato (JetEngine).');
    }
    if ($stored['document'] !== '' && !$this->schema->columnExists($table, 'archivo')) {
      throw new \DomainException('Falta el campo archivo en el historial del contrato (JetEngine).');
    }
    $contract = $this->db->getRow(
      'SELECT `_ID`, `contrato`, `inmueble`, `id_inmueble`, `id_inmueble_data` FROM `' . $this->db->table('jet_cct_contratos_arrendamiento') . '` WHERE `_ID` = ? LIMIT 1',
      [(int) ($input['contract_id'] ?? 0)]
    );
    if (!$contract) {
      throw new \InvalidArgumentException('Selecciona un contrato existente.');
    }

    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $payload = $this->schema->filterTableData($table, [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'id_empleado' => $employeeId,
      'funcionario' => trim((string) ($actor['name'] ?? '')),
      'id_contrato' => (string) $contract['_ID'],
      'contrato' => trim((string) ($contract['contrato'] ?? '')),
      'inmueble' => trim((string) ($contract['inmueble'] ?? '')),
      'id_inmueble' => trim((string) ($contract['id_inmueble'] ?? '')),
      'id_inmueble_data' => trim((string) ($contract['id_inmueble_data'] ?? '')),
      'fecha' => $now,
      'tipo_reporte' => $type,
      'observacion' => $observation,
      'imagen' => implode(',', $stored['images']),
      'archivo' => $stored['document'],
    ]);
    if (!$this->db->insert($table, $payload)) {
      throw new \RuntimeException('No se pudo guardar la nota del contrato.');
    }
    return ['id' => (int) $this->db->lastInsertId(), 'contract_id' => (int) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? '')];
  }

  /**
   * Guarda los adjuntos ya validados: imágenes comprimidas y el documento tal cual.
   *
   * @return array{images:list<string>,document:string}
   */
  public static function storeFiles(StoredFileService $files): array
  {
    $images = array_column($files->storeImagesDetailed('imagenes', self::MAX_IMAGES), 'url');
    $documents = $files->storeDocuments('archivo', [], 1);
    return ['images' => array_values(array_map('strval', $images)), 'document' => (string) ($documents[0]['archivo'] ?? '')];
  }

  /** @return list<array<string,mixed>> */
  private function uploads($field): array
  {
    if (!is_array($field) || !isset($field['name'])) {
      return [];
    }
    $out = [];
    foreach ((array) $field['name'] as $index => $name) {
      $error = (int) (is_array($field['error']) ? ($field['error'][$index] ?? UPLOAD_ERR_NO_FILE) : $field['error']);
      if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
      }
      $out[] = [
        'name' => (string) $name,
        'error' => $error,
        'size' => (int) (is_array($field['size']) ? ($field['size'][$index] ?? 0) : $field['size']),
        'tmp_name' => (string) (is_array($field['tmp_name']) ? ($field['tmp_name'][$index] ?? '') : $field['tmp_name']),
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $file */
  private function assertUpload(array $file, int $max): void
  {
    if ((int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] < 1 || (int) $file['size'] > $max || !is_uploaded_file((string) $file['tmp_name'])) {
      throw new \InvalidArgumentException('No se pudo cargar ' . basename((string) $file['name']) . '. Cada archivo debe pesar máximo ' . (int) round($max / 1048576) . ' MB.');
    }
  }
}
