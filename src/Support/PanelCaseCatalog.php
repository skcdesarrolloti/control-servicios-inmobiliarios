<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Modules\Pending\PublicServicesCriticalTicket;

/**
 * Temas de ayuda y categorías del calendario que el panel ofrece como opciones.
 * Se configuran en Configuración → Temas de ayuda y categorías.
 */
final class PanelCaseCatalog
{
  public const HELP_TOPICS_SETTING = 'case_help_topics';
  public const CALENDAR_CATEGORIES_SETTING = 'panel_calendar_categories';

  /** @return array<int,string> */
  public static function defaultHelpTopics(): array
  {
    return [
      'Reparaciones necesarias',
      'Reparaciones locativas',
      'Mejoras utiles',
      'Reparaciones voluntarias',
      'Contable y tributaria',
      'Certificaciones tributarias',
      'Procesos juridicos',
      'Solicitud contractual',
      'Solicitud de servicios publicos',
      'Otros servicios',
      'Reparaciones antes de la entrega',
      'Reparaciones antes del recibo',
      PublicServicesCriticalTicket::TOPIC,
    ];
  }

  /** Temas que se pueden elegir al crear o editar un caso desde el panel. @return array<int,string> */
  public static function helpTopics(): array
  {
    $configured = self::sanitizeList(self::stored(self::HELP_TOPICS_SETTING));
    return $configured !== [] ? $configured : self::defaultHelpTopics();
  }

  /** IDs de `categorias_calendario` visibles en el panel; vacío = todas. @return array<int,string> */
  public static function calendarCategoryIds(): array
  {
    return self::sanitizeList(self::stored(self::CALENDAR_CATEGORIES_SETTING));
  }

  /** @param mixed $raw @return array<int,string> */
  public static function sanitizeList($raw): array
  {
    if (is_string($raw)) {
      $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    }
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
      if (!is_scalar($item)) {
        continue;
      }
      $value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $item)) ?? '');
      $key = mb_strtolower($value, 'UTF-8');
      if ($value === '' || mb_strlen($value) > 120 || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $value;
    }
    return $out;
  }

  /** @return mixed */
  private static function stored(string $key)
  {
    try {
      return \SCM\Core\App::settings()->get($key, []);
    } catch (\Throwable $e) {
      return [];
    }
  }
}
