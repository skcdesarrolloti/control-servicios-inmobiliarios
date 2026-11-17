<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

/**
 * Migración de las políticas al control documental (bin/migrate-policies-provider.php). Funciones puras sobre las
 * filas, para poder probarlas sin base de datos.
 *
 * - Versiones para proveedores: títulos que terminan en «(Prov.)», «(Prov,)», «( Prov.)» o «(Prov)» (cargos 8 y 17).
 *   Quedan con audiencia = Proveedores, sin el sufijo en el título y con id_politica_base = la política de empleados
 *   que tenga el mismo título normalizado (sin tildes, mayúsculas ni signos). Si no hay una o hay varias, se reporta y
 *   no se adivina.
 * - Las demás: audiencia = Empleados.
 * - Todas: estado = Vigente, version = 1 y un código POL-<abreviatura del área>-<NNN> (la primera área de la política;
 *   sin área, GEN). La versión para proveedores toma el código de su base con «-P» (POL-CON-012-P); si su base ya
 *   tiene una, «-P2», «-P3»…; sin base, su propio código con «-P».
 * - Solo se llena lo vacío (se puede repetir sin efecto) y nunca cambia un _ID.
 */
final class OrganizationPolicyMigration
{
  public const SUFFIX = '/\s*\(\s*prov\s*[.,]?\s*\)\s*$/iu';
  /** Cargos de los proveedores externos. */
  public const PROVIDER_CARGOS = [8, 17];
  /** Similitud mínima (%) para sugerir una base a un título sin coincidencia exacta (solo se informa). */
  private const SUGGESTION_MIN = 72.0;

  public static function isProvider(string $title): bool
  {
    return preg_match(self::SUFFIX, $title) === 1;
  }

  public static function stripSuffix(string $title): string
  {
    return trim((string) preg_replace(self::SUFFIX, '', $title));
  }

  /** «Tiempo de Elaboración de Cotizaciones» y «Tiempo de Elaboracion de  Cotizaciones» son el mismo título. */
  public static function normalize(string $title): string
  {
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', OrganizationPolicies::fold(self::stripSuffix($title))));
  }

  /**
   * @param list<array<string,mixed>> $rows filas de politicas (_ID, titulo, id_area, id_cargo y las columnas de control que existan)
   * @param array<int,string> $areas _ID => nombre
   * @return array{
   *   changes:list<array{id:int,titulo:string,set:array<string,string>,kind:string,base:int}>,
   *   unmatched:list<array{id:int,titulo:string,suggestion:?array{id:int,titulo:string,score:float}}>,
   *   ambiguous:list<array{id:int,titulo:string,candidates:list<int>}>,
   *   duplicates:list<array{titulo:string,ids:list<int>}>,
   *   provider_like:list<array{id:int,titulo:string,id_cargo:string}>,
   *   abbreviations:array<int,string>,
   *   counts:array<string,int>
   * }
   */
  public static function plan(array $rows, array $areas): array
  {
    usort($rows, static fn(array $a, array $b): int => (int) $a['_ID'] <=> (int) $b['_ID']);
    $abbr = OrganizationPolicies::areaAbbreviations($areas);
    $value = static fn(array $row, string $key): string => trim((string) ($row[$key] ?? ''));
    $isProv = static fn(array $row): bool => self::isProvider((string) $row['titulo']) || $value($row, 'audiencia') === 'Proveedores';

    $codes = [];
    foreach ($rows as $row) {
      if ($value($row, 'codigo') !== '') {
        $codes[] = strtoupper($value($row, 'codigo'));
      }
    }
    $baseIndex = [];
    foreach ($rows as $row) {
      if (!$isProv($row)) {
        $baseIndex[self::normalize((string) $row['titulo'])][] = (int) $row['_ID'];
      }
    }

    $assigned = [];
    $newCode = static function (string $areaCsv) use (&$codes, $abbr): string {
      $code = OrganizationPolicies::nextCode(OrganizationPolicies::codePrefix($areaCsv, $abbr), $codes);
      $codes[] = $code;
      return $code;
    };
    $common = static function (array $row, array $set) use ($value): array {
      if ($value($row, 'estado') === '') {
        $set['estado'] = 'Vigente';
      }
      if ($value($row, 'version') === '') {
        $set['version'] = '1';
      }
      return $set;
    };

    $changes = [];
    $unmatched = [];
    $ambiguous = [];
    // Primero las de empleados (sus códigos los heredan las de proveedores).
    foreach ($rows as $row) {
      if ($isProv($row)) {
        continue;
      }
      $id = (int) $row['_ID'];
      $set = [];
      if ($value($row, 'audiencia') === '') {
        $set['audiencia'] = 'Empleados';
      }
      $assigned[$id] = $value($row, 'codigo') !== '' ? strtoupper($value($row, 'codigo')) : $newCode((string) ($row['id_area'] ?? ''));
      if ($value($row, 'codigo') === '') {
        $set['codigo'] = $assigned[$id];
      }
      $set = $common($row, $set);
      if ($set !== []) {
        $changes[] = ['id' => $id, 'titulo' => (string) $row['titulo'], 'set' => $set, 'kind' => 'empleados', 'base' => 0];
      }
    }
    foreach ($rows as $row) {
      if (!$isProv($row)) {
        continue;
      }
      $id = (int) $row['_ID'];
      $title = (string) $row['titulo'];
      $set = [];
      $clean = self::stripSuffix($title);
      if ($clean !== $title) {
        $set['titulo'] = $clean;
      }
      if ($value($row, 'audiencia') === '') {
        $set['audiencia'] = 'Proveedores';
      }
      $base = (int) $value($row, 'id_politica_base');
      if ($base <= 0) {
        $candidates = $baseIndex[self::normalize($title)] ?? [];
        if (count($candidates) === 1) {
          $base = $candidates[0];
          $set['id_politica_base'] = (string) $base;
        } elseif (count($candidates) > 1) {
          $ambiguous[] = ['id' => $id, 'titulo' => $title, 'candidates' => $candidates];
        } else {
          $unmatched[] = ['id' => $id, 'titulo' => $title, 'suggestion' => self::suggest($title, $rows, $isProv)];
        }
      }
      if ($value($row, 'codigo') === '') {
        if ($base > 0 && isset($assigned[$base])) {
          $code = $assigned[$base] . '-P';
          for ($n = 2; in_array($code, $codes, true); $n++) {
            $code = $assigned[$base] . '-P' . $n;
          }
          $codes[] = $code;
        } else {
          $code = $newCode((string) ($row['id_area'] ?? '')) . '-P';
          $codes[] = $code;
        }
        $set['codigo'] = $code;
      }
      $set = $common($row, $set);
      if ($set !== []) {
        $changes[] = ['id' => $id, 'titulo' => $title, 'set' => $set, 'kind' => 'proveedores', 'base' => $base];
      }
    }
    usort($changes, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

    $duplicates = [];
    foreach ($baseIndex as $ids) {
      if (count($ids) > 1) {
        $first = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['_ID'] === $ids[0]))[0];
        $duplicates[] = ['titulo' => (string) $first['titulo'], 'ids' => $ids];
      }
    }
    $providerLike = [];
    foreach ($rows as $row) {
      $cargos = OrganizationRepository::ids($row['id_cargo'] ?? '');
      if (!$isProv($row) && $cargos !== [] && array_diff($cargos, self::PROVIDER_CARGOS) === []) {
        $providerLike[] = ['id' => (int) $row['_ID'], 'titulo' => (string) $row['titulo'], 'id_cargo' => implode(',', $cargos)];
      }
    }
    $provCount = count(array_filter($rows, $isProv));
    return [
      'changes' => $changes,
      'unmatched' => $unmatched,
      'ambiguous' => $ambiguous,
      'duplicates' => $duplicates,
      'provider_like' => $providerLike,
      'abbreviations' => $abbr,
      'counts' => [
        'total' => count($rows),
        'proveedores' => $provCount,
        'empleados' => count($rows) - $provCount,
        'con_base' => count(array_filter($changes, static fn(array $change): bool => isset($change['set']['id_politica_base']))),
        'sin_base' => count($unmatched),
        'ambiguas' => count($ambiguous),
        'cambios' => count($changes),
      ],
    ];
  }

  /** Título de empleados más parecido (solo para el informe; no se aplica). @param list<array<string,mixed>> $rows */
  private static function suggest(string $title, array $rows, callable $isProv): ?array
  {
    $target = self::normalize($title);
    $best = null;
    foreach ($rows as $row) {
      if ($isProv($row)) {
        continue;
      }
      similar_text($target, self::normalize((string) $row['titulo']), $score);
      if ($score >= self::SUGGESTION_MIN && ($best === null || $score > $best['score'])) {
        $best = ['id' => (int) $row['_ID'], 'titulo' => (string) $row['titulo'], 'score' => round($score, 1)];
      }
    }
    return $best;
  }
}
