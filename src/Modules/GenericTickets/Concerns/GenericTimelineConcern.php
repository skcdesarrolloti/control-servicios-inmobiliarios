<?php

declare(strict_types=1);

namespace SCM\Modules\GenericTickets\Concerns;

use SCM\Core\Auth;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

trait GenericTimelineConcern
{
  public function get_default_timeline_hitos(): array
  {
    return [
      ['key' => 'creado', 'label' => 'Queja registrada', 'icon' => '01', 'ts_field' => 'cct_created', 'done_if' => 'always'],
      ['key' => 'en_proceso', 'label' => 'En proceso', 'icon' => '02', 'ts_field' => 'fecha_actualizacion', 'done_if' => 'field_in', 'done_field' => 'estado', 'done_values' => ['En proceso', 'Cerrado']],
      ['key' => 'seguimiento', 'label' => 'Seguimiento', 'icon' => '03', 'ts_field' => 'fecha_seguimiento', 'done_if' => 'field_equals', 'done_field' => 'tuvo_seguimiento', 'done_value' => 'Si'],
      ['key' => 'cerrado', 'label' => 'Cerrado', 'icon' => 'OK', 'ts_field' => 'fecha_actualizacion', 'done_if' => 'field_equals', 'done_field' => 'estado', 'done_value' => 'Cerrado'],
    ];
  }

  private function get_default_tab_timeline_map(): array
  {
    $base = $this->get_default_timeline_hitos();

    $maps = [
      'entrega' => EntregaTimelineMap::steps(),
      'preventiva' => PreventivaTimelineMap::steps(),
      'recibo' => ReciboTimelineMap::steps(),
      'contable' => ContableTimelineMap::steps(),
      'certificaciones' => CertificacionesTimelineMap::steps(),
    ];
    return array_merge($maps, ContractualTimelineMap::maps());
  }

  public function get_tab_timeline_hitos(string $tab_key, string $sub_key = ''): array
  {
    $defaults = $this->get_default_timeline_hitos();

    $allConfigs = apply_filters('scm_tab_timeline_hitos', $this->get_default_tab_timeline_map());

    if ($sub_key !== '' && isset($allConfigs[$sub_key]) && is_array($allConfigs[$sub_key])) {
      return $this->normalize_tab_timeline_hitos($sub_key, $allConfigs[$sub_key]);
    }
    if (isset($allConfigs[$tab_key]) && is_array($allConfigs[$tab_key])) {
      return $this->normalize_tab_timeline_hitos($tab_key, $allConfigs[$tab_key]);
    }

    return $defaults;
  }

  private function normalize_tab_timeline_hitos(string $lookupKey, array $hitos): array
  {
    if ($lookupKey !== 'preventiva') {
      return $hitos;
    }

    $hasRevisionSent = false;
    $hasDamageGate = false;
    foreach ($hitos as $hito) {
      if (!is_array($hito)) {
        continue;
      }
      if (in_array(($hito['key'] ?? ''), ['revision_preventiva_enviada', 'preventiva_enviada'], true)) {
        $hasRevisionSent = true;
      }
      if (
        in_array(($hito['key'] ?? ''), ['cotizacion_registrada', 'cotizacion_enviada', 'respuesta_cotizacion', 'trabajo_finalizado'], true)
        && !empty($hito['show_if'])
      ) {
        $hasDamageGate = true;
      }
    }
    if ($hasRevisionSent && $hasDamageGate) {
      return $hitos;
    }

    $defaults = $this->get_default_tab_timeline_map();
    return isset($defaults['preventiva']) && is_array($defaults['preventiva'])
      ? $defaults['preventiva']
      : $hitos;
  }

  public function eval_hito_done(array $hito, array $row): bool
  {
    return $this->eval_hito_condition($hito, $row, 'done', true);
  }

  public function eval_hito_visible(array $hito, array $row): bool
  {
    if (empty($hito['show_if'])) {
      return true;
    }

    return $this->eval_hito_condition($hito, $row, 'show', false);
  }

  private function eval_hito_condition(array $hito, array $row, string $prefix, bool $fallbackToTsField): bool
  {
    $condIf = $hito[$prefix . '_if'] ?? 'always';
    if ($condIf === 'always') {
      return true;
    }

    $condFields = [];
    if (!empty($hito[$prefix . '_fields']) && is_array($hito[$prefix . '_fields'])) {
      $condFields = array_values($hito[$prefix . '_fields']);
    }
    $condField = (string) ($hito[$prefix . '_field'] ?? '');
    if ($condField !== '') {
      array_unshift($condFields, $condField);
    }
    if (empty($condFields) && $fallbackToTsField) {
      if (!empty($hito['ts_fields']) && is_array($hito['ts_fields'])) {
        $condFields = array_values($hito['ts_fields']);
      } elseif (!empty($hito['ts_field'])) {
        $condFields[] = (string) $hito['ts_field'];
      }
    }

    if ($condIf === 'field_not_empty') {
      foreach ($condFields as $field) {
        if (trim((string) ($row[(string) $field] ?? '')) !== '') {
          return true;
        }
      }
      return false;
    }
    $condField = (string) ($condFields[0] ?? '');
    $val = trim((string) ($row[$condField] ?? ''));
    if ($condIf === 'field_equals') {
      return strtolower($val) === strtolower((string) ($hito[$prefix . '_value'] ?? ''));
    }
    if ($condIf === 'field_in') {
      $rawValues = $hito[$prefix . '_values'] ?? [];
      $values = is_array($rawValues) ? $rawValues : explode(',', (string) $rawValues);
      $haystack = array_map(
        static function ($item): string {
          return strtolower(trim((string) $item));
        },
        $values
      );
      return in_array(strtolower($val), $haystack, true);
    }

    return false;
  }

  public function render_generic_timeline(array $row, array $hitos): string
  {
    return $this->cardView()->renderGenericTimeline($row, $hitos);
  }

  public function resolve_hito_link(string $template, array $row): string
  {
    $template = trim($template);
    if ($template === '') {
      return '';
    }

    $context = $row;

    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($context) {
      $field = (string)($m[1] ?? '');
      if ($field === '') {
        return '';
      }
      return rawurlencode(trim((string)($context[$field] ?? '')));
    }, $template) ?? '';
  }

  public function render_generic_card(array $row, array $config, array $hitos, string $tabKey = '', string $statusBucket = ''): string
  {
    return $this->cardView()->renderGenericCard($row, $config, $hitos, $tabKey, $statusBucket);
  }

  public function render_generic_cards(array $rows, array $config, string $tabKey, string $statusBucket = ''): string
  {
    return $this->cardView()->renderGenericCards($rows, $config, $tabKey, $statusBucket);
  }

  /** @param array<string,mixed> $row */
  public function resolve_timeline_hitos_for_row(string $tabKey, array $row): array
  {
    if ($tabKey !== 'contractual') {
      return $this->get_tab_timeline_hitos($tabKey);
    }

    $tema = trim((string) ($row['tema_ayuda'] ?? ''));
    $subKeyMap = [
      'Procesos juridicos' => 'contractual_procesos_juridicos',
      'Solicitud contractual' => 'contractual_solicitud_contractual',
      'Solicitud de servicios publicos' => 'contractual_solicitud_sp',
      'Retencion de contrato' => 'contractual_retencion_contrato',
      'Otros servicios' => 'contractual_otros_servicios',
    ];
    $subKey = $subKeyMap[$tema] ?? 'contractual';
    return $this->get_tab_timeline_hitos('contractual', $subKey);
  }
}
