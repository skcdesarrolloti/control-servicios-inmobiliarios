<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Core\Database;
use SCM\Modules\OwnerCredits\OwnerCreditPromissoryService;
use SCM\Support\SchemaInspector;

/**
 * Carga en los contratos de arrendamiento los copropietarios del listado de SIMI («COPROPETARIOS ACTIVOS»): una fila
 * por copropietario con el inmueble, su nombre, cédula, % y contacto, y en cada fila el propietario principal y su %.
 *
 * - Se agrupa por inmueble; el principal es el índice 1 y los copropietarios 2..8 en el orden del archivo.
 * - Se cargan los contratos Entregado o Por entregar de ese inmueble cuyo principal es el del archivo.
 * - Manda el archivo: reemplaza `propietarios`, `id_propietario_2..8` y `porcentaje_1..8` del contrato.
 * - Un copropietario sin ficha se registra en Propietarios (por documento normalizado, como la hoja de cierre).
 * - Si el inmueble no suma 100 %, no tiene contrato, tiene otro principal o no caben sus propietarios, se omite y se
 *   lista el motivo. Si el mandato tiene otros copropietarios, se avisa: el liquidador usa el mandato.
 *
 * Sin $apply solo calcula lo que haría (bin/import-co-owners.php).
 */
final class CoOwnersImport
{
  /** Columnas del archivo (encabezados normalizados) por dato. */
  private const HEADERS = [
    'inmueble' => ['inmueble'],
    'name' => ['coop propietario', 'copropietario'],
    'percent' => ['porcentaje'],
    'document' => ['cedula coop', 'cedula copropietario'],
    'address' => ['direccion correspon', 'direccion'],
    'issued' => ['expedida'],
    'phone' => ['telefono correspon', 'telefono', 'celular'],
    'email' => ['email', 'correo'],
    'main_name' => ['nom propietario'],
    'main_document' => ['cedula pro'],
    'main_percent' => ['% propie', 'porcentaje propietario'],
  ];
  private const TOLERANCE = 0.05;

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /**
   * Agrupa las filas del archivo por inmueble. Las filas sin código de inmueble (pie «Registros: 143») se saltan.
   * @param list<list<string>> $rows primera fila = encabezados
   * @return list<array{inmueble:string,main:array{name:string,document:string,percent:string},owners:list<array<string,string>>}>
   */
  public static function groups(array $rows): array
  {
    $header = array_map(static fn($cell): string => self::fold((string) $cell), (array) array_shift($rows));
    $index = [];
    foreach (self::HEADERS as $key => $names) {
      foreach ($names as $name) {
        $found = array_search($name, $header, true);
        if ($found !== false) { $index[$key] = (int) $found; break; }
      }
    }
    $missing = array_diff(['inmueble', 'name', 'percent', 'document', 'main_name', 'main_document', 'main_percent'], array_keys($index));
    if ($missing !== []) throw new \InvalidArgumentException('Al archivo le faltan las columnas: ' . implode(', ', array_map(static fn(string $key): string => self::HEADERS[$key][0], $missing)) . '.');
    $groups = [];
    foreach ($rows as $row) {
      $value = static fn(string $key): string => isset($index[$key]) ? trim(preg_replace('/\s+/u', ' ', (string) ($row[$index[$key]] ?? '')) ?? '') : '';
      $code = $value('inmueble');
      if (preg_match('/^\d+$/', $code) !== 1) continue;
      $groups[$code] ??= ['inmueble' => $code, 'main' => ['name' => $value('main_name'), 'document' => $value('main_document'), 'percent' => ContractCoOwners::percentValue($value('main_percent'))], 'owners' => []];
      $groups[$code]['owners'][] = [
        'name' => $value('name'), 'document' => $value('document'), 'percent' => ContractCoOwners::percentValue($value('percent')),
        'email' => strtolower($value('email')), 'phone' => self::phone($value('phone')), 'address' => $value('address'), 'issued' => $value('issued'),
      ];
    }
    return array_values($groups);
  }

  /**
   * @param list<array<string,mixed>> $groups groups()
   * @param string $author id_empleado real del funcionario que ejecuta (autor de fichas e historial)
   * @return array{contracts:list<array<string,mixed>>,skipped:list<array<string,string>>,profiles:list<array<string,string>>}
   */
  public function run(array $groups, bool $apply, string $author = '', string $source = 'SIMI'): array
  {
    if ($apply && trim($author) === '') throw new \InvalidArgumentException('Falta el id_empleado del funcionario que ejecuta (--funcionario=<id_empleado>).');
    $out = ['contracts' => [], 'skipped' => [], 'profiles' => []];
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) throw new \RuntimeException('No existe la tabla de contratos de arrendamiento.');
    $now = date('Y-m-d H:i:s');
    $profiles = $this->profileIndex();
    $registered = [];

    foreach ($groups as $group) {
      $code = (string) $group['inmueble'];
      $skip = static function (string $reason) use (&$out, $code, $group): void {
        $out['skipped'][] = ['inmueble' => $code, 'principal' => (string) $group['main']['name'], 'motivo' => $reason];
      };
      $total = 1 + count($group['owners']);
      $percents = array_merge([$group['main']['percent']], array_column($group['owners'], 'percent'));
      $sum = array_sum(array_map('floatval', $percents));
      if (in_array('', $percents, true) || min(array_map('floatval', $percents)) <= 0) { $skip('hay un porcentaje vacío o en 0'); continue; }
      if (abs($sum - 100) > self::TOLERANCE) { $skip('los porcentajes suman ' . rtrim(rtrim(number_format($sum, 2, ',', '.'), '0'), ',') . ' %'); continue; }
      if ($total > ContractCoOwners::CONTRACT_MAX) { $skip($total . ' propietarios: el contrato admite hasta ' . ContractCoOwners::CONTRACT_MAX); continue; }
      $incomplete = array_values(array_filter($group['owners'], static fn(array $owner): bool => $owner['name'] === '' || OwnerCreditPromissoryService::normalizeDocument($owner['document']) === ''));
      if ($incomplete !== []) { $skip('copropietario sin nombre o sin cédula'); continue; }
      $missingColumns = [];
      for ($i = 1; $i <= $total; $i++) {
        foreach (array_filter(['porcentaje_' . $i, $i > 1 ? 'id_propietario_' . $i : '']) as $column) if (!$this->schema->columnExists($table, $column)) $missingColumns[] = $column;
      }
      if ($missingColumns !== []) { $skip('faltan las columnas ' . implode(', ', $missingColumns) . ' (corre bin/add-co-owner-7-8-fields.php --aplicar)'); continue; }
      $contracts = $this->db->getResults("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`inmueble`, '')) = ? AND LOWER(TRIM(COALESCE(`estado`, ''))) IN ('entregado', 'por entregar') ORDER BY `_ID` ASC", [$code]);
      if ($contracts === []) { $skip('sin contrato Entregado o Por entregar con ese inmueble'); continue; }

      foreach ($contracts as $contract) {
        $notes = [];
        $principal = $this->contractPrincipalDocument($contract);
        if ($principal !== '' && !ContractCoOwners::sameDocument($principal, (string) $group['main']['document'])) {
          $skip('el contrato ' . ($contract['contrato'] ?? $contract['_ID']) . ' tiene otro propietario principal (' . trim((string) ($contract['propietario'] ?? '')) . ', ' . $principal . '; el archivo dice ' . $group['main']['document'] . ')');
          continue;
        }
        if ($principal === '') $notes[] = 'el contrato no tiene documento del principal para comparar';

        $data = ['propietarios' => (string) $total, 'porcentaje_1' => (string) $group['main']['percent']];
        foreach (array_values($group['owners']) as $position => $owner) {
          $i = $position + 2;
          $document = OwnerCreditPromissoryService::normalizeDocument($owner['document']);
          $id = $profiles[$document] ?? $registered[$document] ?? '';
          if ($id === '') {
            $out['profiles'][] = ['inmueble' => $code, 'nombre' => $owner['name'], 'documento' => $owner['document'], 'accion' => 'registrar'];
            $id = $apply ? $this->registerProfile($owner, $author, $now) : 'nueva:' . $document;
            $registered[$document] = $id;
          }
          $data['id_propietario_' . $i] = $id;
          $data['porcentaje_' . $i] = (string) $owner['percent'];
        }
        // Manda el archivo: los puestos que sobran quedan vacíos.
        for ($i = $total + 1; $i <= ContractCoOwners::CONTRACT_MAX; $i++) {
          foreach (['id_propietario_' . $i, 'porcentaje_' . $i] as $column) $data[$column] = '';
        }
        $data = $this->schema->filterTableData($table, $data);
        $changes = [];
        foreach ($data as $column => $value) {
          $current = trim((string) ($contract[$column] ?? ''));
          $same = str_starts_with($column, 'porcentaje_') ? ContractCoOwners::percentValue($current) === $value : $current === $value;
          if (!$same) $changes[$column] = [$current, $value];
        }
        $mandateNote = $this->mandateNote($contract, $group);
        if ($mandateNote !== '') $notes[] = $mandateNote;
        $row = ['inmueble' => $code, 'contrato' => (string) ($contract['contrato'] ?? ''), 'id' => (string) $contract['_ID'], 'propietarios' => $total, 'cambios' => $changes, 'avisos' => $notes];
        $out['contracts'][] = $row;
        if ($apply && $changes !== []) {
          $this->db->update($table, $data + $this->schema->filterTableData($table, ['cct_modified' => $now]), ['_ID' => (int) $contract['_ID']]);
          $this->history($contract, $group, $author, $now, $source);
        }
      }
    }
    return $out;
  }

  /** Documento del principal del contrato: el del contrato o el de su ficha. */
  private function contractPrincipalDocument(array $contract): string
  {
    $document = trim((string) ($contract['documento_propietario'] ?? ''));
    if ($document !== '') return $document;
    $id = trim((string) ($contract['id_propietario'] ?? ''));
    $table = $this->db->table('jet_cct_propietarios');
    if (!ctype_digit($id) || !$this->schema->tableExists($table)) return '';
    return trim((string) ($this->db->getVar("SELECT `documento` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $id]) ?? ''));
  }

  /** Aviso si el mandato del contrato registra otros copropietarios (con más de uno, el liquidador usa el mandato). */
  private function mandateNote(array $contract, array $group): string
  {
    $id = trim((string) ($contract['id_contrato_mandato'] ?? ''));
    $table = $this->db->table('jet_cct_contrato_mandato');
    if (!ctype_digit($id) || !$this->schema->tableExists($table)) return '';
    $mandate = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $id]);
    $owners = is_array($mandate) ? ContractCoOwners::mandateOwners($mandate) : [];
    if (count($owners) <= 1) return '';
    $key = static fn(array $docs): array => array_values(array_unique(array_map([OwnerCreditPromissoryService::class, 'normalizeDocument'], $docs)));
    $mandateDocs = $key(array_column($owners, 'document'));
    $fileDocs = $key(array_merge([$group['main']['document']], array_column($group['owners'], 'document')));
    sort($mandateDocs);
    sort($fileDocs);
    return $mandateDocs === $fileDocs ? '' : 'el mandato #' . $id . ' tiene otros copropietarios (' . count($owners) . '): el liquidador usa el mandato; corrígelo en el mandato o en la hoja de cierre';
  }

  /** @return array<string,string> documento normalizado => _ID de la ficha */
  private function profileIndex(): array
  {
    $table = $this->db->table('jet_cct_propietarios');
    $index = [];
    if (!$this->schema->tableExists($table)) return $index;
    foreach ($this->db->getResults("SELECT `_ID`, `documento` FROM `{$table}` ORDER BY `_ID` ASC") as $row) {
      $document = OwnerCreditPromissoryService::normalizeDocument((string) ($row['documento'] ?? ''));
      if (strlen($document) >= 4 && !isset($index[$document])) $index[$document] = (string) $row['_ID'];
    }
    return $index;
  }

  /** Ficha nueva en Propietarios con los datos del archivo. */
  private function registerProfile(array $owner, string $author, string $now): string
  {
    $table = $this->db->table('jet_cct_propietarios');
    $phone = (string) $owner['phone'];
    $row = $this->schema->filterTableData($table, [
      'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now,
      'nombre' => $owner['name'], 'documento' => $owner['document'], 'tipo_persona' => self::personType($owner['name'], $owner['document']),
      'correo' => filter_var($owner['email'], FILTER_VALIDATE_EMAIL) ? $owner['email'] : '', 'celular' => $phone,
      'indicativo' => preg_match('/^3\d{9}$/', $phone) === 1 ? '+57' : '', 'direccion' => $owner['address'], 'expedicion' => $owner['issued'],
    ]);
    if ($row === [] || !$this->db->insert($table, $row)) throw new \RuntimeException('No se pudo registrar a ' . $owner['name'] . ' en Propietarios.');
    return (string) $this->db->lastInsertId();
  }

  /** Historial del contrato con los copropietarios cargados. */
  private function history(array $contract, array $group, string $author, string $now, string $source): void
  {
    $table = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if (!$this->schema->tableExists($table)) return;
    $owners = array_map(static fn(array $owner): string => $owner['name'] . ' ' . str_replace('.', ',', (string) $owner['percent']) . ' %', $group['owners']);
    $message = 'Copropietarios cargados desde ' . $source . ': principal ' . $group['main']['name'] . ' ' . str_replace('.', ',', (string) $group['main']['percent']) . ' %; ' . implode('; ', $owners) . '.';
    $this->db->insert($table, $this->schema->filterTableData($table, [
      'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now, 'fecha' => time(),
      'id_empleado' => $author, 'tipo_reporte' => 'Copropietarios', 'observacion' => $message,
      'id_contrato' => (string) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
    ]));
  }

  /** Empresa si el nombre lo dice (SAS, S.A., LTDA…) o el documento es un NIT (9 dígitos que empiezan por 8 o 9). */
  public static function personType(string $name, string $document): string
  {
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    $company = preg_match('/\b(S\.?\s?A\.?\s?S|S\.?\s?A|LTDA|E\.?\s?U|S\.?\s?EN\s?C)\.?\b/i', $name) === 1 || preg_match('/^[89]\d{8}$/', $digits) === 1;
    return $company ? 'Juridica' : 'Natural';
  }

  /** "(5)0000000" (sin teléfono) → ""; "(5)6000001" → "56000001"; "3000000001" igual. */
  public static function phone(string $value): string
  {
    $value = preg_replace('/^\(\s*5\s*\)\s*(?=\+)/', '', trim($value)) ?? '';
    $clean = preg_replace('/[^\d+]/', '', $value) ?? '';
    $digits = ltrim(preg_replace('/\D+/', '', $clean) ?? '', '5');
    return $digits === '' || trim($digits, '0') === '' ? '' : $clean;
  }

  /** Minúsculas, sin tildes y con un solo espacio, para comparar encabezados. */
  private static function fold(string $value): string
  {
    $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
  }
}
