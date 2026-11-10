<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * "También mostrar en": pestañas en las que un popup de alerta sale al entrar, por cargo y por alerta.
 * Se guardan con las claves de Permisos › Pestañas; el runtime compara con la opción del menú (`tab`).
 */
final class DashboardAlertTabs
{
  public const SETTING = 'dashboard_alert_tabs';

  /** Pestañas cuyo permiso se llama distinto a su opción del menú (includes/header.php). */
  private const MENU_TAB_BY_PERMISSION = [
    'mi_calendario' => 'mine',
    'calendario_equipo' => 'team',
    'vencimientos' => 'due',
    'historial_inmueble' => 'property_history',
    'contratos_por_terminar' => 'contracts_ending',
    'solicitudes_terminacion' => 'contract_termination',
    'solicitudes_no_prorroga' => 'contract_non_renewal',
    'funcionario_contratos' => 'contract_owners',
    'metricas_casos' => 'dashboard',
    'metricas_ocupaciones' => 'ocupaciones',
    'metricas_desocupaciones' => 'desocupaciones',
    'metricas_ocupaciones_vs' => 'ocupaciones_vs_desocupaciones',
  ];

  /**
   * @param mixed $raw
   * @param list<string> $cargos
   * @param list<string> $alerts
   * @param list<string> $tabs
   * @return array<string,array<string,list<string>>> cargo => alerta => pestañas
   */
  public static function sanitize($raw, array $cargos, array $alerts, array $tabs): array
  {
    $clean = [];
    foreach (is_array($raw) ? $raw : [] as $cargo => $byAlert) {
      $cargo = trim((string) $cargo);
      if (!in_array($cargo, $cargos, true) || !is_array($byAlert)) continue;
      foreach ($byAlert as $alert => $selected) {
        $alert = (string) $alert;
        if (!in_array($alert, $alerts, true) || !is_array($selected)) continue;
        $valid = array_values(array_unique(array_intersect(array_map('strval', $selected), $tabs)));
        if ($valid !== []) $clean[$cargo][$alert] = $valid;
      }
    }
    ksort($clean);
    return $clean;
  }

  /**
   * @param array<string,list<string>> $byAlert alerta => pestañas del cargo
   * @param list<string> $activeAlerts solo salen las que el cargo tiene activas
   * @return array<string,list<string>> opción del menú => alertas que salen al entrar
   */
  public static function byMenuTab(array $byAlert, array $activeAlerts): array
  {
    $map = [];
    foreach ($byAlert as $alert => $tabs) {
      if (!in_array((string) $alert, $activeAlerts, true)) continue;
      foreach ($tabs as $tab) {
        $map[self::MENU_TAB_BY_PERMISSION[$tab] ?? $tab][] = (string) $alert;
      }
    }
    return $map;
  }

  /** @param list<string> $labels nombres de las pestañas elegidas */
  public static function summary(array $labels): string
  {
    if ($labels === []) return 'También en: ninguna pestaña';
    return 'También en: ' . (count($labels) === 1 ? $labels[0] : count($labels) . ' pestañas');
  }
}
