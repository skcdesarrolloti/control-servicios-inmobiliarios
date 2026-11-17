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
   * @return array<int,array{id:int,title:string,text:string}>
   */
  public function policies(?array $ids = null): array
  {
    $table = $this->db->table('jet_cct_politicas');
    if (($ids !== null && $ids === []) || !$this->schema->tableExists($table)) {
      return [];
    }
    $sql = "SELECT `_ID`, `titulo`, `politica` FROM `{$table}`";
    $params = [];
    if ($ids !== null) {
      $sql .= ' WHERE `_ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
      $params = array_values($ids);
    }
    $out = [];
    foreach ($this->db->getResults($sql . ' ORDER BY `_ID`', $params) as $row) {
      $id = (int) $row['_ID'];
      $out[$id] = ['id' => $id, 'title' => self::clean((string) ($row['titulo'] ?? '')) ?: 'Política #' . $id, 'text' => self::clean((string) ($row['politica'] ?? ''))];
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
   * @param array<string,mixed> $closing fila de cierres
   * @return list<array{item:string,group:string,text:string,state:string,note:string,policies:list<array{id:int,title:string,text:string}>,justification:string,by:string,at:int}>
   */
  public function exceptions(array $closing): array
  {
    $items = self::items();
    $saved = self::decode((string) ($closing[self::COLUMN] ?? ''));
    $rules = $this->forForm();
    $out = [];
    foreach ($saved as $entry) {
      $item = (string) ($entry['item'] ?? '');
      if (!isset($items[$item]) || trim((string) ($closing[$item] ?? '')) !== (string) ($entry['state'] ?? '')) {
        continue;
      }
      $out[$item] = [
        'item' => $item, 'group' => $items[$item][0], 'text' => $items[$item][1], 'state' => (string) $entry['state'],
        'note' => (string) ($entry['note'] ?? ''), 'policies' => is_array($entry['policies'] ?? null) ? $entry['policies'] : [],
        'justification' => (string) ($entry['justification'] ?? ''), 'by' => (string) ($entry['by'] ?? ''), 'at' => (int) ($entry['at'] ?? 0),
      ];
    }
    foreach ($rules as $item => $rule) {
      $state = trim((string) ($closing[$item] ?? ''));
      if (!isset($out[$item]) && isset($items[$item]) && in_array($state, $rule['states'], true)) {
        $out[$item] = ['item' => $item, 'group' => $items[$item][0], 'text' => $items[$item][1], 'state' => $state, 'note' => $rule['note'], 'policies' => $rule['policies'], 'justification' => '', 'by' => '', 'at' => 0];
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
      $previous[(string) ($entry['item'] ?? '')] = $entry;
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
        'item' => $item, 'state' => $state, 'note' => $rule['note'],
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
