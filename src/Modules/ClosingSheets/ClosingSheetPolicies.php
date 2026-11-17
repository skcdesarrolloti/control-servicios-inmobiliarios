<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\SchemaInspector;

/**
 * Políticas de la lista de chequeo de la hoja de cierre.
 *
 * Cada ítem puede tener políticas de wp_jet_cct_politicas y una regla escrita (por ejemplo, el término mínimo de 12
 * meses, que no está en la tabla). Si el ítem queda en uno de sus estados de incumplimiento ("No cumple" por defecto),
 * el formulario avisa con la política y exige una justificación. Las justificaciones se guardan en la columna
 * excepciones_politicas de la hoja y quien aprueba las ve en rojo antes de los botones.
 *
 * Se configura en Configuración › Políticas de la hoja de cierre (setting closing_sheet_policy_rules). Mientras no se
 * guarde, rige DEFAULT_RULES, con las versiones normales (sin "(Prov.)") de las políticas.
 */
final class ClosingSheetPolicies
{
  public const SETTING = 'closing_sheet_policy_rules';
  /** Ítems con documento cuyo archivo es obligatorio cuando quedan en «Cumple». */
  public const SETTING_FILES = 'closing_sheet_required_documents';
  public const KIND_POLICY = 'politica';
  public const KIND_FILE = 'archivo';
  /**
   * Por defecto: lo que sostiene el contrato y los pagos (identidad, RUT y cuenta del propietario, facturas de los servicios
   * de la hoja, garantía, empresas). Opcionales: escritura, predial, internet, teléfono, RUT del arrendatario y codeudores,
   * reglamento, manual, RUT y paz y salvo de la copropiedad.
   */
  public const DEFAULT_REQUIRED_FILES = [
    'estado_certificado_tradicion', 'estado_servicio_agua', 'estado_servicio_energia', 'estado_servicio_gas', 'estado_mora_servicios',
    'estado_acreditacion_propietario', 'estado_certificado_empresa_pro', 'estado_certificado_camara_pro', 'estado_documento_pro', 'estado_rut_pro', 'estado_bancaria_pro',
    'estado_certificado_empresa_arre', 'estado_certificado_camara_arre', 'estado_documento_arre',
    'estado_certificado_empresa_co1', 'estado_certificado_camara_co1', 'estado_documento_co1', 'estado_certificado_empresa_co2', 'estado_certificado_camara_co2', 'estado_documento_co2',
    'estado_certificado_empresa_co3', 'estado_certificado_camara_co3', 'estado_documento_co3', 'estado_certificado_empresa_co4', 'estado_certificado_camara_co4', 'estado_documento_co4',
    'estado_certificacion_copropiedad', 'estado_cuota_copropiedad', 'estado_asegurable',
  ];
  public const COLUMN = 'excepciones_politicas';
  public const STATES = ['No cumple', 'No aplica'];
  public const MIN_JUSTIFICATION = 10;

  /** ítem => [políticas, estados que incumplen, regla escrita]. */
  public const DEFAULT_RULES = [
    'estado_servicio_agua' => [[47, 45], ['No cumple'], ''],
    'estado_servicio_energia' => [[47, 45], ['No cumple'], ''],
    'estado_servicio_gas' => [[47, 45], ['No cumple'], ''],
    'estado_amparo_integral' => [[47, 23, 45], ['No cumple'], 'Todos los contratos deben incluir la garantía (amparo integral) de servicios públicos.'],
    'estado_servicios_compatidos' => [[66], ['No cumple'], ''],
    'estado_mora_servicios' => [[26, 41], ['No cumple'], ''],
    'estado_requiere_reparacion' => [[41], ['No cumple'], ''],
    'estado_encargado_copropiedad' => [[25, 15], ['No cumple'], ''],
    'estado_asegurable' => [[7, 19], ['No cumple'], ''],
    'estado_cobertura' => [[19], ['No cumple'], 'El asegurable debe tener una cobertura de 36 meses.'],
    'estado_termino_contrato' => [[], ['No cumple'], 'El término mínimo del contrato de arrendamiento es de 12 meses.'],
  ];

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,array{policies:list<int>,states:list<string>,note:string}> ítem => regla (solo ítems con algo) */
  public function rules(): array
  {
    $stored = (new Settings($this->db))->get(self::SETTING, null);
    if (is_array($stored)) {
      return self::normalize($stored);
    }
    $out = [];
    foreach (self::DEFAULT_RULES as $item => [$policies, $states, $note]) {
      $out[$item] = ['policies' => $policies, 'states' => $states, 'note' => $note];
    }
    return $out;
  }

  /** @param array<mixed> $raw @return array<string,array{policies:list<int>,states:list<string>,note:string}> */
  public static function normalize(array $raw): array
  {
    $items = self::items();
    $out = [];
    foreach ($raw as $item => $rule) {
      if (!is_string($item) || !isset($items[$item]) || !is_array($rule)) {
        continue;
      }
      $policies = array_values(array_unique(array_filter(array_map('intval', is_array($rule['policies'] ?? null) ? $rule['policies'] : []), static fn(int $id): bool => $id > 0)));
      $states = array_values(array_intersect(self::STATES, is_array($rule['states'] ?? null) ? array_map('strval', $rule['states']) : []));
      $note = trim(mb_substr(strip_tags((string) ($rule['note'] ?? '')), 0, 600, 'UTF-8'));
      if (($policies !== [] || $note !== '') && $states !== []) {
        $out[$item] = ['policies' => $policies, 'states' => $states, 'note' => $note];
      }
    }
    return $out;
  }

  /** @param array<mixed> $raw */
  public function save(array $raw, int $updatedBy = 0): array
  {
    $rules = self::normalize($raw);
    (new Settings($this->db))->set(self::SETTING, $rules, $updatedBy);
    return $rules;
  }

  /** Ítems con archivo obligatorio en «Cumple». @return list<string> */
  public function requiredFiles(): array
  {
    $stored = (new Settings($this->db))->get(self::SETTING_FILES, null);
    return is_array($stored) ? self::normalizeFiles($stored) : self::DEFAULT_REQUIRED_FILES;
  }

  /** @param array<mixed> $raw @return list<string> */
  public static function normalizeFiles(array $raw): array
  {
    $wanted = array_map('strval', $raw);
    return array_values(array_filter(array_keys(ClosingSheetForm::DOCUMENT_ITEMS), static fn(string $item): bool => in_array($item, $wanted, true)));
  }

  /** @param array<mixed> $raw @return list<string> */
  public function saveFiles(array $raw, int $updatedBy = 0): array
  {
    $files = self::normalizeFiles($raw);
    (new Settings($this->db))->set(self::SETTING_FILES, $files, $updatedBy);
    return $files;
  }

  /**
   * Archivos obligatorios: un ítem en «Cumple» sin archivo exige decir por qué no se adjunta ahora. Un ítem que ya estaba
   * en «Cumple» en la hoja guardada sin esa justificación (hojas anteriores a la regla) no la exige mientras no cambie.
   * @param array<string,mixed> $values @param array<mixed> $posted justificacion_archivo[ítem] @param list<string> $withFiles ítems con archivo
   * @param array<string,mixed> $saved @param list<array<string,mixed>> $savedEntries
   * @return array{0:list<array<string,mixed>>,1:list<string>}
   */
  public function collectFiles(array $values, array $posted, array $withFiles, array $saved, array $savedEntries, string $by, int $now): array
  {
    $items = self::items();
    $previous = [];
    foreach ($savedEntries as $entry) {
      if (($entry['kind'] ?? '') === self::KIND_FILE) {
        $previous[(string) ($entry['item'] ?? '')] = $entry;
      }
    }
    $entries = [];
    $errors = [];
    foreach ($this->requiredFiles() as $item) {
      if (!isset($items[$item]) || trim((string) ($values[$item] ?? '')) !== 'Cumple' || in_array($item, $withFiles, true)) {
        continue;
      }
      $text = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string) ($posted[$item] ?? ''))), 0, 2000, 'UTF-8'));
      $before = $previous[$item] ?? null;
      if ($text === '' && $before !== null) {
        $text = (string) ($before['justification'] ?? '');
      }
      if (mb_strlen($text, 'UTF-8') < self::MIN_JUSTIFICATION) {
        $legacy = $saved !== [] && trim((string) ($saved[$item] ?? '')) === 'Cumple' && $before === null;
        if (!$legacy) {
          $errors[] = 'Lista de chequeo: "' . mb_strimwidth($items[$item][1], 0, 70, '…', 'UTF-8') . '" está en «Cumple» y su archivo es obligatorio. Adjúntalo o escribe por qué no se adjunta ahora (mínimo ' . self::MIN_JUSTIFICATION . ' caracteres).';
        }
        continue;
      }
      $same = $before !== null && (string) ($before['justification'] ?? '') === $text;
      $entries[] = [
        'kind' => self::KIND_FILE, 'item' => $item, 'state' => 'Cumple', 'note' => 'Falta el archivo obligatorio.', 'policies' => [],
        'justification' => $text, 'by' => $same ? (string) ($before['by'] ?? $by) : $by, 'at' => $same ? (int) ($before['at'] ?? $now) : $now,
      ];
    }
    return [$entries, $errors];
  }

  /** Ítems de la lista: columna del estado => [grupo, texto]. @return array<string,array{0:string,1:string}> */
  public static function items(): array
  {
    $out = [];
    foreach (ClosingSheetForm::CHECKLIST as [$group, , $items]) {
      foreach ($items as [$column, $text]) {
        $out[$column] = [$group, $text];
      }
    }
    return $out;
  }

  /**
   * Políticas de la tabla (todas o las pedidas), con el texto sin HTML.
   * @param list<int>|null $ids
   * @return array<int,array{id:int,title:string,text:string,code?:string,audience?:string,state?:string}> code, audience y state solo si existen las columnas
   */
  public function policies(?array $ids = null): array
  {
    $table = $this->db->table('jet_cct_politicas');
    if (($ids !== null && $ids === []) || !$this->schema->tableExists($table)) {
      return [];
    }
    // Código, audiencia y estado del control documental (Gestor organizacional), si las columnas ya existen.
    $keys = ['codigo' => 'code', 'audiencia' => 'audience', 'estado' => 'state'];
    $extra = array_values(array_filter(array_keys($keys), fn(string $column): bool => $this->schema->columnExists($table, $column)));
    $sql = 'SELECT `_ID`, `titulo`, `politica`' . implode('', array_map(static fn(string $column): string => ", `{$column}`", $extra)) . " FROM `{$table}`";
    $params = [];
    if ($ids !== null) {
      $sql .= ' WHERE `_ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
      $params = array_values($ids);
    }
    $out = [];
    foreach ($this->db->getResults($sql . ' ORDER BY `_ID`', $params) as $row) {
      $id = (int) $row['_ID'];
      $out[$id] = ['id' => $id, 'title' => self::clean((string) ($row['titulo'] ?? '')) ?: 'Política #' . $id, 'text' => self::clean((string) ($row['politica'] ?? ''))];
      foreach ($extra as $column) {
        $out[$id][$keys[$column]] = trim((string) ($row[$column] ?? ''));
      }
    }
    return $out;
  }

  /**
   * Lo que el formulario necesita: por ítem, sus estados de incumplimiento, la regla y las políticas con su texto.
   * @return array<string,array{states:list<string>,note:string,policies:list<array{id:int,title:string,text:string}>}>
   */
  public function forForm(): array
  {
    $rules = $this->rules();
    $catalog = $this->policies(array_values(array_unique(array_merge([], ...array_column($rules, 'policies')))));
    $out = [];
    foreach ($rules as $item => $rule) {
      $out[$item] = [
        'states' => $rule['states'], 'note' => $rule['note'],
        'policies' => array_values(array_filter(array_map(static fn(int $id): ?array => $catalog[$id] ?? null, $rule['policies']))),
      ];
    }
    return $out;
  }

  /**
   * Excepciones de una hoja para mostrarlas a quien aprueba: las guardadas con su justificación y, en hojas anteriores
   * a esta regla, los ítems que hoy incumplen sin justificación.
   * Los archivos obligatorios justificados solo se muestran mientras sigan sin archivo ($documents de la hoja).
   * @param array<string,mixed> $closing fila de cierres @param list<array<string,mixed>>|null $documents archivos de la hoja
   * @return list<array{kind:string,item:string,group:string,text:string,state:string,note:string,policies:list<array{id:int,title:string,text:string}>,justification:string,by:string,at:int}>
   */
  public function exceptions(array $closing, ?array $documents = null): array
  {
    $items = self::items();
    $saved = self::decode((string) ($closing[self::COLUMN] ?? ''));
    $rules = $this->forForm();
    $withFiles = $documents === null ? [] : array_values(array_unique(array_map(static fn(array $doc): string => (string) ($doc['item'] ?? ''), $documents)));
    $out = [];
    foreach ($saved as $entry) {
      $item = (string) ($entry['item'] ?? '');
      $kind = (string) ($entry['kind'] ?? self::KIND_POLICY);
      if (!isset($items[$item]) || trim((string) ($closing[$item] ?? '')) !== (string) ($entry['state'] ?? '')
        || ($kind === self::KIND_FILE && in_array($item, $withFiles, true))) {
        continue;
      }
      $out[$item] = [
        'kind' => $kind, 'item' => $item, 'group' => $items[$item][0], 'text' => $items[$item][1], 'state' => (string) $entry['state'],
        'note' => (string) ($entry['note'] ?? ''), 'policies' => is_array($entry['policies'] ?? null) ? $entry['policies'] : [],
        'justification' => (string) ($entry['justification'] ?? ''), 'by' => (string) ($entry['by'] ?? ''), 'at' => (int) ($entry['at'] ?? 0),
      ];
    }
    foreach ($rules as $item => $rule) {
      $state = trim((string) ($closing[$item] ?? ''));
      if (!isset($out[$item]) && isset($items[$item]) && in_array($state, $rule['states'], true)) {
        $out[$item] = ['kind' => self::KIND_POLICY, 'item' => $item, 'group' => $items[$item][0], 'text' => $items[$item][1], 'state' => $state, 'note' => $rule['note'], 'policies' => $rule['policies'], 'justification' => '', 'by' => '', 'at' => 0];
      }
    }
    // Texto vigente de cada política (lo guardado solo trae id y título).
    $ids = [];
    foreach ($out as $entry) {
      foreach ($entry['policies'] as $policy) {
        $ids[] = (int) ($policy['id'] ?? 0);
      }
    }
    $catalog = $this->policies(array_values(array_unique(array_filter($ids))));
    foreach ($out as $item => $entry) {
      $out[$item]['policies'] = array_map(static fn(array $policy): array => [
        'id' => (int) ($policy['id'] ?? 0), 'title' => (string) ($catalog[(int) ($policy['id'] ?? 0)]['title'] ?? $policy['title'] ?? ''),
        'text' => (string) ($catalog[(int) ($policy['id'] ?? 0)]['text'] ?? ''),
      ], $entry['policies']);
    }
    // En el orden de la lista de chequeo.
    return array_values(array_filter(array_map(static fn(string $item): ?array => $out[$item] ?? null, array_keys($items))));
  }

  /**
   * Valida las justificaciones al guardar la hoja y arma lo que se guarda en excepciones_politicas.
   * Un ítem que ya estaba en ese estado en la hoja guardada (hojas anteriores a la regla) no exige justificación.
   * @param array<string,mixed> $values estados ya validados @param array<mixed> $posted justificacion[ítem]
   * @param array<string,mixed> $saved valores guardados (editar) @param list<array<string,mixed>> $savedEntries
   * @return array{0:list<array<string,mixed>>,1:list<string>} excepciones y errores
   */
  public function collect(array $values, array $posted, array $saved, array $savedEntries, string $by, int $now): array
  {
    $items = self::items();
    $previous = [];
    foreach ($savedEntries as $entry) {
      if (($entry['kind'] ?? self::KIND_POLICY) === self::KIND_POLICY) {
        $previous[(string) ($entry['item'] ?? '')] = $entry;
      }
    }
    $entries = [];
    $errors = [];
    foreach ($this->forForm() as $item => $rule) {
      $state = trim((string) ($values[$item] ?? ''));
      if (!isset($items[$item]) || !in_array($state, $rule['states'], true)) {
        continue;
      }
      $text = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string) ($posted[$item] ?? ''))), 0, 2000, 'UTF-8'));
      $before = $previous[$item] ?? null;
      if ($text === '' && $before !== null && (string) ($before['state'] ?? '') === $state) {
        $text = (string) ($before['justification'] ?? '');
      }
      $legacy = $saved !== [] && trim((string) ($saved[$item] ?? '')) === $state && $before === null;
      if (mb_strlen($text, 'UTF-8') < self::MIN_JUSTIFICATION) {
        if (!$legacy) {
          $errors[] = 'Lista de chequeo: "' . mb_strimwidth($items[$item][1], 0, 70, '…', 'UTF-8') . '" queda en "' . $state . '" e incumple una política. Escribe la justificación (mínimo ' . self::MIN_JUSTIFICATION . ' caracteres).';
        }
        continue;
      }
      $same = $before !== null && (string) ($before['state'] ?? '') === $state && (string) ($before['justification'] ?? '') === $text;
      $entries[] = [
        'kind' => self::KIND_POLICY, 'item' => $item, 'state' => $state, 'note' => $rule['note'],
        'policies' => array_map(static fn(array $policy): array => ['id' => $policy['id'], 'title' => $policy['title']], $rule['policies']),
        'justification' => $text, 'by' => $same ? (string) ($before['by'] ?? $by) : $by, 'at' => $same ? (int) ($before['at'] ?? $now) : $now,
      ];
    }
    return [$entries, $errors];
  }

  /** @return list<array<string,mixed>> */
  public static function decode(string $json): array
  {
    $data = json_decode(trim($json), true);
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
  }

  private static function clean(string $value): string
  {
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", stripslashes($value))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));
  }
}
