<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Core\Database;
use SCM\Modules\LegalCases\LegalHtmlSanitizer;
use SCM\Support\SchemaInspector;

/**
 * Control documental de las políticas (wp_jet_cct_politicas):
 * - columnas de control (bin/add-policy-control-fields.php): código, versión, estado, audiencia, política base,
 *   responsable, fechas de vigencia y revisión, motivo del cambio, categoría y proceso. Todo se escribe con
 *   SchemaInspector::filterTableData(), así que funciona aunque las columnas todavía no existan;
 * - historial de versiones (wp_gpo_politica_versiones): una copia en cada guardado. Una edición normal conserva la
 *   versión; «Nueva versión» la sube, pide el motivo y obliga a volver a confirmar la lectura;
 * - confirmación de lectura (wp_gpo_politica_lecturas): «Leí y entiendo» por funcionario y versión.
 * Los _ID nunca cambian: la hoja de cierre (ClosingSheetPolicies) referencia las políticas por _ID.
 */
final class OrganizationPolicies
{
  /** @var array<string,bool> tablas creadas en esta petición (SchemaInspector guarda en caché que no existían). */
  private array $created = [];

  private function ownTableExists(string $table): bool
  {
    return isset($this->created[$table]) || $this->schema->tableExists($table);
  }

  public const STATES = ['Borrador', 'Vigente', 'Derogada'];
  public const AUDIENCES = ['Empleados', 'Proveedores', 'Ambos'];
  /** Estados que no se muestran en Mi gestión. */
  public const HIDDEN_STATES = ['Borrador', 'Derogada'];
  public const CONTROL_COLUMNS = ['codigo', 'version', 'estado', 'audiencia', 'id_politica_base', 'id_responsable', 'vigencia_desde', 'fecha_revision', 'fecha_proxima_revision', 'motivo_cambio', 'categoria', 'id_proceso'];
  public const DATE_COLUMNS = ['vigencia_desde', 'fecha_revision', 'fecha_proxima_revision'];
  /** Campos que se copian al historial además del título y el texto. */
  private const SNAPSHOT = ['codigo', 'estado', 'audiencia', 'id_area', 'id_cargo', 'id_politica_base', 'id_responsable', 'vigencia_desde', 'fecha_revision', 'fecha_proxima_revision', 'categoria', 'id_proceso'];
  /** Código para políticas sin área. */
  public const GENERAL_PREFIX = 'GEN';
  private const STOPWORDS = ['y', 'e', 'o', 'u', 'de', 'del', 'la', 'las', 'el', 'los', 'a', 'al', 'en', 'para', 'por', 'con'];
  public const MIN_REASON = 5;

  public function __construct(private Database $db, private SchemaInspector $schema)
  {
  }

  public function table(): string
  {
    return $this->db->table('jet_cct_politicas');
  }

  public function historyTable(): string
  {
    return $this->db->table('gpo_politica_versiones');
  }

  public function readsTable(): string
  {
    return $this->db->table('gpo_politica_lecturas');
  }

  /** Se llama antes de abrir una transacción (CREATE TABLE confirma la transacción en MySQL). */
  public function ensureSchema(): void
  {
    if (!$this->ownTableExists($this->historyTable())) {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->historyTable()}` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_politica BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, accion VARCHAR(30) NOT NULL DEFAULT '',
        titulo VARCHAR(255) NOT NULL DEFAULT '', texto LONGTEXT NULL, datos LONGTEXT NULL, motivo TEXT NULL,
        id_empleado VARCHAR(50) NOT NULL DEFAULT '', nombre VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        KEY politica_version (id_politica, version)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $this->created[$this->historyTable()] = true;
    }
    if (!$this->ownTableExists($this->readsTable())) {
      $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->readsTable()}` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_politica BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
        id_empleado VARCHAR(50) NOT NULL, nombre VARCHAR(120) NOT NULL DEFAULT '', confirmed_at DATETIME NOT NULL,
        UNIQUE KEY lectura (id_politica, version, id_empleado)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $this->created[$this->readsTable()] = true;
    }
  }

  /** Columnas de control que ya existen (el formulario solo muestra esas). @return list<string> */
  public function availableColumns(): array
  {
    return array_values(array_filter(self::CONTROL_COLUMNS, fn(string $column): bool => $this->schema->columnExists($this->table(), $column)));
  }

  // --- Texto -----------------------------------------------------------------------------------------------------

  /** HTML seguro: el texto plano (como están hoy las políticas) se pasa a párrafos; el HTML se limpia con lista blanca. */
  public static function cleanHtml(string $raw): string
  {
    $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
    if ($raw === '') {
      return '';
    }
    // Solo es HTML si trae etiquetas de texto del editor; cualquier otro «<» se escapa como texto.
    if (preg_match('/<\/?(?:p|br|strong|b|em|i|u|s|ul|ol|li|h[1-6]|blockquote|a|div|span)(?=[\s\/>])[^>]*>/i', $raw) !== 1) {
      $paragraphs = preg_split('/\n\s*\n/', $raw) ?: [];
      return implode('', array_map(static fn(string $part): string => '<p>' . nl2br(htmlspecialchars(trim($part), ENT_QUOTES, 'UTF-8'), false) . '</p>', array_filter($paragraphs, static fn(string $part): bool => trim($part) !== '')));
    }
    return LegalHtmlSanitizer::clean($raw);
  }

  /** Texto sin HTML (extractos, PDF y búsquedas). */
  public static function plain(string $stored): string
  {
    $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h2>', '</h3>', '</h4>'], "\n", $stored);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));
  }

  public static function excerpt(string $stored, int $length = 220): string
  {
    $text = trim((string) preg_replace('/\s+/u', ' ', self::plain($stored)));
    return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…' : $text;
  }

  /** Minúsculas sin tildes (abreviaturas y comparación de títulos). */
  public static function fold(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
  }

  // --- Códigos ---------------------------------------------------------------------------------------------------

  /**
   * Abreviatura de cada área para el código POL-<abreviatura>-<NNN>. Se recorre por _ID: iniciales de las palabras
   * (sin conectores) o las tres primeras letras si es una sola palabra; si queda corta o ya la tiene otra área, se
   * agregan letras de la última palabra. Ejemplos: «Mantenimiento, Aseo y Reparacion» → MAR, «Contractual» → CON,
   * «Contable» → CONT (CON ya era de Contractual).
   * @param array<int,string> $areas _ID => nombre @return array<int,string>
   */
  public static function areaAbbreviations(array $areas): array
  {
    ksort($areas, SORT_NUMERIC);
    $out = [];
    $used = [];
    foreach ($areas as $id => $name) {
      $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', self::fold((string) $name)) ?: [], static fn(string $word): bool => $word !== '' && !in_array($word, self::STOPWORDS, true)));
      if ($words === []) {
        $code = 'A' . (int) $id;
      } else {
        if (count($words) === 1) {
          $code = substr($words[0], 0, 3);
          $tail = substr($words[0], 3);
        } else {
          $code = implode('', array_map(static fn(string $word): string => $word[0], $words));
          $tail = substr((string) end($words), 1);
        }
        $code = strtoupper($code);
        $i = 0;
        while ((strlen($code) < 3 || isset($used[$code])) && $i < strlen($tail)) {
          $code .= strtoupper($tail[$i++]);
        }
        if (isset($used[$code])) {
          $code .= (int) $id;
        }
      }
      $used[$code] = true;
      $out[(int) $id] = $code;
    }
    return $out;
  }

  /** «POL-MAR» con la primera área de la política; sin área conocida, «POL-GEN». @param array<int,string> $abbreviations */
  public static function codePrefix(string $areaCsv, array $abbreviations): string
  {
    $first = OrganizationRepository::ids($areaCsv)[0] ?? 0;
    return 'POL-' . ($abbreviations[$first] ?? self::GENERAL_PREFIX);
  }

  /** Siguiente código libre del prefijo: POL-MAR-001, POL-MAR-002… @param iterable<string> $existingCodes */
  public static function nextCode(string $prefix, iterable $existingCodes): string
  {
    $max = 0;
    foreach ($existingCodes as $code) {
      if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)(?:-P\d*)?$/', strtoupper(trim((string) $code)), $match) === 1) {
        $max = max($max, (int) $match[1]);
      }
    }
    return $prefix . '-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
  }

  /** @return array<int,string> */
  public function abbreviations(): array
  {
    $table = $this->db->table('jet_cct_areas_organizacion');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $areas = [];
    foreach ($this->db->getResults("SELECT `_ID`, `nombre` FROM `{$table}`") as $row) {
      $areas[(int) $row['_ID']] = (string) ($row['nombre'] ?? '');
    }
    return self::areaAbbreviations($areas);
  }

  /** @return list<string> */
  private function existingCodes(int $exceptId = 0): array
  {
    if (!$this->schema->columnExists($this->table(), 'codigo')) {
      return [];
    }
    return array_map('strval', $this->db->getCol("SELECT `codigo` FROM `{$this->table()}` WHERE COALESCE(`codigo`, '') <> '' AND `_ID` <> ?", [$exceptId]));
  }

  public function suggestCode(string $areaCsv, int $exceptId = 0): string
  {
    return self::nextCode(self::codePrefix($areaCsv, $this->abbreviations()), $this->existingCodes($exceptId));
  }

  // --- Guardado --------------------------------------------------------------------------------------------------

  /** «2026-11-17» → timestamp (a medianoche de Bogotá), como guarda JetEngine las fechas. */
  public static function dateToTimestamp(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('America/Bogota'));
    if (!$date || $date->format('Y-m-d') !== $value) {
      throw new \DomainException('La fecha ' . $value . ' no es válida.');
    }
    return (string) $date->getTimestamp();
  }

  public static function timestampToDate(mixed $value): string
  {
    $ts = (int) $value;
    return $ts > 0 ? (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('America/Bogota'))->format('Y-m-d') : '';
  }

  /**
   * Datos de la política para guardar. Exige título y texto; la versión solo sube con $newVersion (que exige motivo).
   * @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed>
   */
  public function payload(array $input, ?array $existing, bool $newVersion = false): array
  {
    $text = static fn(string $key): string => trim(sanitize_text_field(is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''));
    $title = mb_substr($text('titulo'), 0, 250, 'UTF-8');
    if ($title === '') {
      throw new \DomainException('Escribe el título de la política.');
    }
    $html = self::cleanHtml((string) wp_unslash($input['politica'] ?? ''));
    if (trim(self::plain($html)) === '') {
      throw new \DomainException('Escribe el texto de la política.');
    }
    $data = [
      'titulo' => $title,
      'politica' => $html,
      'id_area' => OrganizationRepository::csv($input['id_area'] ?? ''),
      'id_cargo' => OrganizationRepository::csv($input['id_cargo'] ?? ''),
      'fecha_actualizacion' => (string) time(),
    ];
    if ($existing === null) {
      $data['fecha'] = (string) time();
    }
    $id = (int) ($existing['_ID'] ?? 0);

    $estado = $text('estado');
    $data['estado'] = in_array($estado, self::STATES, true) ? $estado : ((string) ($existing['estado'] ?? '') ?: 'Vigente');
    $audiencia = $text('audiencia');
    $data['audiencia'] = in_array($audiencia, self::AUDIENCES, true) ? $audiencia : ((string) ($existing['audiencia'] ?? '') ?: 'Empleados');

    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9-]+/', '', $text('codigo')));
    if ($code === '' && $this->schema->columnExists($this->table(), 'codigo')) {
      $code = (string) ($existing['codigo'] ?? '') ?: $this->suggestCode($data['id_area'], $id);
    }
    if ($code !== '' && in_array($code, array_map('strtoupper', $this->existingCodes($id)), true)) {
      throw new \DomainException('El código ' . $code . ' ya lo tiene otra política.');
    }
    $data['codigo'] = mb_substr($code, 0, 40, 'UTF-8');

    $current = max(1, (int) ($existing['version'] ?? 1));
    if ($newVersion) {
      if ($existing === null) {
        throw new \DomainException('Primero guarda la política.');
      }
      if (!$this->schema->columnExists($this->table(), 'version')) {
        throw new \DomainException('Falta la columna de versión de las políticas. Aplica primero bin/add-policy-control-fields.php.');
      }
    }
    $data['version'] = (string) ($existing === null ? 1 : ($newVersion ? $current + 1 : $current));

    $reason = trim(strip_tags(sanitize_textarea_field((string) ($input['motivo_cambio'] ?? ''))));
    if ($newVersion && mb_strlen($reason, 'UTF-8') < self::MIN_REASON) {
      throw new \DomainException('Escribe el motivo del cambio de versión.');
    }
    $data['motivo_cambio'] = mb_substr($reason, 0, 1000, 'UTF-8');

    $base = (int) ($input['id_politica_base'] ?? 0);
    if ($base > 0) {
      if ($base === $id) {
        throw new \DomainException('Una política no puede ser su propia política base.');
      }
      if ((int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->table()}` WHERE `_ID` = ?", [$base]) === 0) {
        throw new \DomainException('La política base #' . $base . ' no existe.');
      }
    }
    $data['id_politica_base'] = $base > 0 ? (string) $base : '';

    $responsible = $text('id_responsable');
    if ($responsible !== '' && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->db->table('jet_cct_funcionarios')}` WHERE `id_empleado` = ?", [$responsible]) === 0) {
      throw new \DomainException('El responsable elegido no es un funcionario.');
    }
    $data['id_responsable'] = $responsible;

    foreach (self::DATE_COLUMNS as $column) {
      $data[$column] = self::dateToTimestamp($text($column));
    }
    if ($data['fecha_revision'] !== '' && $data['fecha_proxima_revision'] !== '' && (int) $data['fecha_proxima_revision'] < (int) $data['fecha_revision']) {
      throw new \DomainException('La próxima revisión no puede ser anterior a la fecha de revisión.');
    }
    $data['categoria'] = mb_substr($text('categoria'), 0, 120, 'UTF-8');
    $process = (int) ($input['id_proceso'] ?? 0);
    $data['id_proceso'] = $process > 0 ? (string) $process : '';
    // Al editar, lo que el formulario no envía (campos ocultos o sin columna) conserva su valor.
    if ($existing !== null) {
      foreach (['id_area', 'id_cargo', 'codigo', 'estado', 'audiencia', 'id_politica_base', 'id_responsable', 'motivo_cambio', 'categoria', 'id_proceso', ...self::DATE_COLUMNS] as $key) {
        if (!array_key_exists($key, $input) && !($key === 'motivo_cambio' && $newVersion)) {
          unset($data[$key]);
        }
      }
    }
    return $data;
  }

  /** @param array<string,mixed> $row fila guardada @param array{id:string,name:string} $actor */
  public function record(array $row, string $action, array $actor, string $reason = ''): void
  {
    $snapshot = [];
    foreach (self::SNAPSHOT as $key) {
      if (array_key_exists($key, $row)) {
        $snapshot[$key] = (string) ($row[$key] ?? '');
      }
    }
    $this->db->insert($this->historyTable(), [
      'id_politica' => (int) $row['_ID'],
      'version' => max(1, (int) ($row['version'] ?? 1)),
      'accion' => $action,
      'titulo' => mb_substr((string) ($row['titulo'] ?? ''), 0, 255, 'UTF-8'),
      'texto' => (string) ($row['politica'] ?? ''),
      'datos' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'motivo' => $reason,
      'id_empleado' => mb_substr((string) $actor['id'], 0, 50, 'UTF-8'),
      'nombre' => mb_substr((string) $actor['name'], 0, 120, 'UTF-8'),
      'created_at' => date('Y-m-d H:i:s'),
    ]);
  }

  /** @return list<array<string,mixed>> */
  public function history(int $policyId): array
  {
    if (!$this->ownTableExists($this->historyTable())) {
      return [];
    }
    return array_map(static function (array $row): array {
      $data = json_decode((string) ($row['datos'] ?? ''), true);
      return [
        'id' => (int) $row['id'],
        'version' => (int) $row['version'],
        'accion' => (string) $row['accion'],
        'titulo' => (string) $row['titulo'],
        'html' => self::cleanHtml((string) ($row['texto'] ?? '')),
        'datos' => is_array($data) ? $data : [],
        'motivo' => (string) ($row['motivo'] ?? ''),
        'id_empleado' => (string) $row['id_empleado'],
        'nombre' => (string) $row['nombre'],
        'created_at' => (string) $row['created_at'],
      ];
    }, $this->db->getResults("SELECT * FROM `{$this->historyTable()}` WHERE `id_politica` = ? ORDER BY `id` DESC LIMIT 100", [$policyId]));
  }

  public function hasHistory(int $policyId): bool
  {
    return $this->ownTableExists($this->historyTable())
      && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->historyTable()}` WHERE `id_politica` = ?", [$policyId]) > 0;
  }

  // --- Confirmación de lectura -----------------------------------------------------------------------------------

  /** «Leí y entiendo» de la versión vigente. Repetirla no duplica. Devuelve la fecha de la confirmación. */
  public function confirmRead(array $policy, string $employeeId, string $name): string
  {
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
      throw new \DomainException('Tu usuario no tiene un id de empleado asociado.');
    }
    $this->ensureSchema();
    $version = max(1, (int) ($policy['version'] ?? 1));
    $args = [(int) $policy['_ID'], $version, $employeeId];
    $existing = $this->db->getVar("SELECT `confirmed_at` FROM `{$this->readsTable()}` WHERE `id_politica` = ? AND `version` = ? AND `id_empleado` = ?", $args);
    if ($existing !== null) {
      return (string) $existing;
    }
    $now = date('Y-m-d H:i:s');
    try {
      $this->db->insert($this->readsTable(), ['id_politica' => $args[0], 'version' => $version, 'id_empleado' => $employeeId, 'nombre' => mb_substr($name, 0, 120, 'UTF-8'), 'confirmed_at' => $now]);
    } catch (\Throwable $error) {
      // Doble clic: la llave única ya tiene la confirmación.
      $again = $this->db->getVar("SELECT `confirmed_at` FROM `{$this->readsTable()}` WHERE `id_politica` = ? AND `version` = ? AND `id_empleado` = ?", $args);
      if ($again === null) {
        throw $error;
      }
      return (string) $again;
    }
    return $now;
  }

  /** Confirmaciones del funcionario: _ID => [versión => fecha]. @param list<int> $policyIds @return array<int,array<int,string>> */
  public function readsFor(array $policyIds, string $employeeId): array
  {
    $policyIds = array_values(array_filter(array_map('intval', $policyIds)));
    if ($policyIds === [] || $employeeId === '' || !$this->ownTableExists($this->readsTable())) {
      return [];
    }
    $out = [];
    $placeholders = implode(',', array_fill(0, count($policyIds), '?'));
    foreach ($this->db->getResults("SELECT `id_politica`, `version`, `confirmed_at` FROM `{$this->readsTable()}` WHERE `id_empleado` = ? AND `id_politica` IN ({$placeholders})", array_merge([$employeeId], $policyIds)) as $row) {
      $out[(int) $row['id_politica']][(int) $row['version']] = (string) $row['confirmed_at'];
    }
    return $out;
  }

  /**
   * Quién confirmó la versión vigente y quién falta (funcionarios activos con un cargo de la política).
   * @param array<string,mixed> $policy
   * @param list<array{id_empleado:string,nombre:string,id_cargo:string}> $funcionarios activos
   * @param array<int,string> $cargoNames
   * @return array{version:int,confirmed:list<array<string,mixed>>,pending:list<array<string,string>>,expected:int}
   */
  public function readers(array $policy, array $funcionarios, array $cargoNames = []): array
  {
    $version = max(1, (int) ($policy['version'] ?? 1));
    $policyCargos = OrganizationRepository::ids($policy['id_cargo'] ?? '');
    $confirmed = [];
    if ($this->ownTableExists($this->readsTable())) {
      foreach ($this->db->getResults("SELECT `id_empleado`, `nombre`, `confirmed_at` FROM `{$this->readsTable()}` WHERE `id_politica` = ? AND `version` = ? ORDER BY `confirmed_at`", [(int) $policy['_ID'], $version]) as $row) {
        $confirmed[(string) $row['id_empleado']] = ['id_empleado' => (string) $row['id_empleado'], 'nombre' => (string) $row['nombre'], 'confirmed_at' => (string) $row['confirmed_at'], 'asignado' => false];
      }
    }
    $pending = [];
    $expected = 0;
    foreach ($funcionarios as $person) {
      $cargos = OrganizationRepository::ids($person['id_cargo'] ?? '');
      if (array_intersect($cargos, $policyCargos) === []) {
        continue;
      }
      $expected++;
      $employee = (string) $person['id_empleado'];
      if (isset($confirmed[$employee])) {
        $confirmed[$employee]['asignado'] = true;
        continue;
      }
      $pending[] = [
        'id_empleado' => $employee,
        'nombre' => (string) $person['nombre'],
        'cargo' => implode(', ', array_filter(array_map(static fn(int $id): string => $cargoNames[$id] ?? '', array_intersect($cargos, $policyCargos)))),
      ];
    }
    return ['version' => $version, 'confirmed' => array_values($confirmed), 'pending' => $pending, 'expected' => $expected];
  }

  // --- Presentación ----------------------------------------------------------------------------------------------

  /** @param array<string,mixed> $row @return array<string,mixed> */
  public static function present(array $row, bool $withHtml = true): array
  {
    $out = $row;
    $out['version'] = (string) max(1, (int) ($row['version'] ?? 1));
    $out['estado'] = (string) ($row['estado'] ?? '');
    $out['audiencia'] = (string) ($row['audiencia'] ?? '');
    $out['codigo'] = (string) ($row['codigo'] ?? '');
    $out['extracto'] = self::excerpt((string) ($row['politica'] ?? ''));
    foreach (self::DATE_COLUMNS as $column) {
      $out[$column] = self::timestampToDate($row[$column] ?? '');
    }
    $out['fecha_actualizacion_texto'] = self::timestampToDate($row['fecha_actualizacion'] ?? '');
    if ($withHtml) {
      $out['html'] = self::cleanHtml((string) ($row['politica'] ?? ''));
    }
    unset($out['politica']);
    if ($withHtml) {
      // El editor recibe el HTML ya limpio.
      $out['politica'] = $out['html'];
    }
    return $out;
  }

  /** @param array<string,mixed> $row */
  public static function visibleInManagement(array $row): bool
  {
    return !in_array(trim((string) ($row['estado'] ?? '')), self::HIDDEN_STATES, true);
  }
}
