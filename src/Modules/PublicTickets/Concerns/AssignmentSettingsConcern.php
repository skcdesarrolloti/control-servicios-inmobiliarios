<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait AssignmentSettingsConcern
{
  public static function getPqrThemes(): array
  {
    $hidden = [];
    foreach (self::INTERNAL_ONLY_PQR_THEMES as $theme) {
      $hidden[mb_strtolower(trim($theme), 'UTF-8')] = true;
    }

    $public = [];
    foreach (self::PQR_THEMES as $theme) {
      $key = mb_strtolower(trim($theme), 'UTF-8');
      if (!isset($hidden[$key])) {
        $public[] = $theme;
      }
    }
    return $public;
  }

  /** @return array<int,string> */
  public static function getPqrsTypes(): array
  {
    return self::getPqrThemes();
  }

  /** @return array<string,mixed> */
  public static function getCorresponsableAssignments(): array
  {
    $raw = get_option(self::OPTION_CORRESPONSABLES, []);
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $raw = $decoded;
      }
    }
    if (!is_array($raw)) {
      return [];
    }

    $out = [];
    foreach ($raw as $type => $employeeValue) {
      $normalizedType = self::normalizePqrsTypeValue((string) $type);
      if ($normalizedType === '') {
        continue;
      }

      $assignment = self::normalizeCorresponsableAssignmentValue($employeeValue);
      if (!empty($assignment)) {
        $out[$normalizedType] = $assignment;
      }
    }
    return $out;
  }

  public static function saveCorresponsableAssignments(array $map): bool
  {
    $clean = [];
    foreach ($map as $type => $employeeValue) {
      $normalizedType = self::normalizePqrsTypeValue((string) $type);
      if ($normalizedType === '') {
        continue;
      }

      $assignment = self::normalizeCorresponsableAssignmentValue($employeeValue);
      if (!empty($assignment)) {
        $clean[$normalizedType] = $assignment;
      }
    }
    return update_option(self::OPTION_CORRESPONSABLES, $clean);
  }

  /** @return array<int,string> */
  public static function getCorresponsableForType(string $type, string $actor = ''): array
  {
    $normalizedType = self::normalizePqrsTypeValue($type);
    if ($normalizedType === '') {
      return [];
    }
    $map = self::getCorresponsableAssignments();
    $value = $map[$normalizedType] ?? [];

    $actor = mb_strtolower(trim($actor), 'UTF-8');
    if ($actor !== '' && is_array($value) && self::isAssociativeArray($value)) {
      $actorValue = $value[$actor] ?? ($value['default'] ?? []);
      if (is_array($actorValue)) {
        $value = $actorValue;
      } else {
        $value = [];
      }
    } elseif ($actor === '' && is_array($value) && self::isAssociativeArray($value)) {
      $value = $value['default'] ?? [];
    }

    if (!is_array($value)) {
      return [];
    }

    $out = [];
    foreach ($value as $idRaw) {
      $id = trim((string) $idRaw);
      if ($id !== '' && !in_array($id, $out, true)) {
        $out[] = $id;
      }
    }
    return $out;
  }

  /** @return array<mixed> */
  private static function normalizeCorresponsableAssignmentValue($employeeValue): array
  {
    if (!is_array($employeeValue)) {
      $single = trim((string) $employeeValue);
      return $single !== '' ? [$single] : [];
    }

    if (!self::isAssociativeArray($employeeValue)) {
      return self::normalizeCorresponsableIds($employeeValue);
    }

    $out = [];
    foreach (['default', 'propietario', 'arrendatario', 'copropiedad', 'cliente'] as $actor) {
      if (!array_key_exists($actor, $employeeValue)) {
        continue;
      }

      $ids = is_array($employeeValue[$actor])
        ? self::normalizeCorresponsableIds($employeeValue[$actor])
        : self::normalizeCorresponsableIds([$employeeValue[$actor]]);
      if ($ids !== []) {
        $out[$actor] = $ids;
      }
    }

    return $out;
  }

  /** @return array<int,string> */
  private static function normalizeCorresponsableIds(array $values): array
  {
    $ids = [];
    foreach ($values as $idRaw) {
      if (is_array($idRaw)) {
        continue;
      }

      $id = trim((string) $idRaw);
      if ($id !== '' && !in_array($id, $ids, true)) {
        $ids[] = $id;
      }
    }

    return $ids;
  }

  private static function isAssociativeArray(array $value): bool
  {
    if ($value === []) {
      return false;
    }

    return array_keys($value) !== range(0, count($value) - 1);
  }

  private function getDepartmentForActor(string $actor): string
  {
    if ($actor === 'propietario') {
      return 'Servicio al propietario';
    }
    if ($actor === 'arrendatario') {
      return 'Servicio al arrendatario';
    }
    if ($actor === 'copropiedad') {
      return 'Servicio a la copropiedad';
    }
    return 'Servicio al cliente';
  }

  public static function getDepartmentForTheme(string $theme): string
  {
    $key = mb_strtolower(trim($theme), 'UTF-8');
    return self::PQR_THEME_DEPARTMENT_MAP[$key] ?? 'Servicio al cliente';
  }

  public static function getNotifResponsable(): string
  {
    $ids = self::getNotifResponsables();
    return $ids[0] ?? '';
  }

  public static function saveNotifResponsable(string $empleadoId): bool
  {
    $empleadoId = trim($empleadoId);
    return self::saveNotifResponsables($empleadoId === '' ? [] : [$empleadoId]);
  }

  /** @return array<int,string> */
  public static function getNotifResponsables(): array
  {
    $raw = get_option(self::OPTION_NOTIF_RESPONSABLE, []);
    $ids = [];

    if (is_array($raw)) {
      foreach ($raw as $candidate) {
        $id = trim((string) $candidate);
        if ($id !== '' && !in_array($id, $ids, true)) {
          $ids[] = $id;
        }
      }
      return $ids;
    }

    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        foreach ($decoded as $candidate) {
          $id = trim((string) $candidate);
          if ($id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
          }
        }
        return $ids;
      }

      $single = trim($raw);
      if ($single !== '') {
        $ids[] = $single;
      }
    }

    return $ids;
  }

  /** @param array<int,string> $empleadoIds */
  public static function saveNotifResponsables(array $empleadoIds): bool
  {
    $clean = [];
    foreach ($empleadoIds as $candidate) {
      $id = trim((string) $candidate);
      if ($id !== '' && !in_array($id, $clean, true)) {
        $clean[] = $id;
      }
    }
    return update_option(self::OPTION_NOTIF_RESPONSABLE, $clean);
  }

  /** @return array<int,string> */
}
