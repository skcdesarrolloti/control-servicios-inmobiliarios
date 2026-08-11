<?php

namespace SCM\Modules\GenericTickets;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

final class GenericTicketsModule
{
  /** @var object */
  private $owner;

  private Database $db;
  private ?\SCM\Views\GenericTicketsUiView $uiView = null;
  private ?\SCM\Views\GenericTicketsCardView $cardView = null;
  /** @var array<string,array<string,mixed>> */
  private array $genericFilterOptionsCache = [];

  public function __construct($owner, Database $db)
  {
    $this->owner = $owner;
    $this->db    = $db;
  }

  private function uiView(): \SCM\Views\GenericTicketsUiView
  {
    if ($this->uiView instanceof \SCM\Views\GenericTicketsUiView) {
      return $this->uiView;
    }
    $this->uiView = new \SCM\Views\GenericTicketsUiView(
      [$this, 'parse_unix_ts'],
      [$this, 'format_date'],
      [$this, 'format_history_detail_html'],
      [$this, 'build_history_item_buttons'],
      [$this, 'get_record_field_map']
    );
    return $this->uiView;
  }

  private function cardView(): \SCM\Views\GenericTicketsCardView
  {
    if ($this->cardView instanceof \SCM\Views\GenericTicketsCardView) {
      return $this->cardView;
    }
    $this->cardView = new \SCM\Views\GenericTicketsCardView(
      [$this, 'eval_hito_done'],
      [$this, 'eval_hito_visible'],
      [$this, 'parse_unix_ts'],
      [$this, 'format_date'],
      [$this, 'format_date_time'],
      [$this, 'resolve_hito_link'],
      [$this, 'first_id_value'],
      [$this, 'human_duration_since'],
      [$this, 'estado_badge'],
      [$this, 'render_seguimiento_form'],
      [$this, 'render_historial_block'],
      [$this, 'render_record_section'],
      [$this, 'render_single_record_section'],
      [$this, 'resolve_timeline_hitos_for_row']
    );
    return $this->cardView;
  }

  public function __call(string $name, array $arguments)
  {
    if (is_object($this->owner) && is_callable([$this->owner, $name])) {
      return $this->owner->$name(...$arguments);
    }

    throw new \BadMethodCallException('Metodo no encontrado: ' . $name);
  }

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

  public function parse_params_generic(array $input, string $prefix): array
  {
    $clean = static function ($v): string {
      return sanitize_text_field((string) ($v ?? ''));
    };

    return [
      'fEstado' => $clean($input[$prefix . 'estado'] ?? ''),
      'fEstadoAdmin' => $clean($input[$prefix . 'estado_admin'] ?? ''),
      'fBusqueda' => $clean($input[$prefix . 'busqueda'] ?? ''),
      'fInmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'fContrato' => $clean($input[$prefix . 'contrato'] ?? ''),
      'fEmpleado' => $clean($input[$prefix . 'empleado'] ?? ''),
      'fAsunto' => $clean($input[$prefix . 'asunto'] ?? ''),
      'fArrendatario' => $clean($input[$prefix . 'arrendatario'] ?? ''),
      'fPropietario' => $clean($input[$prefix . 'propietario'] ?? ''),
      'fBarrio' => $clean($input[$prefix . 'barrio'] ?? ''),
      'fAseguradora' => $clean($input[$prefix . 'aseguradora'] ?? ''),
      'fConsultorEntrega' => $clean($input[$prefix . 'consultor_entrega'] ?? ''),
      'fFechaDesde' => $clean($input[$prefix . 'fecha_desde'] ?? ''),
      'fFechaHasta' => $clean($input[$prefix . 'fecha_hasta'] ?? ''),
      'fCotizacion' => $clean($input[$prefix . 'cotizacion'] ?? ''),
      'fRevision' => $clean($input[$prefix . 'revision'] ?? ''),
      'fDanos' => $clean($input[$prefix . 'danos'] ?? ''),
      'fMagnitud' => $clean($input[$prefix . 'magnitud'] ?? ''),
      'fMagnitudCaso' => $clean($input[$prefix . 'magnitud_caso'] ?? ''),
      'fPerturbacion' => $clean($input[$prefix . 'perturbacion'] ?? ''),
      'fAtraso' => $clean($input[$prefix . 'atraso'] ?? ''),
      'fSinActualizar' => $clean($input[$prefix . 'sin_actualizar'] ?? ''),
      'fTuvoSeguimiento' => $clean($input[$prefix . 'tuvo_seguimiento'] ?? ''),
      'fTema' => $clean($input[$prefix . 'tema'] ?? ''),
      'fPage' => max(1, (int) $clean($input[$prefix . 'page'] ?? '1')),
    ];
  }

  public function run_query_generic(array $temas, array $p, array $config = [], bool $includeRows = true): array
  {
    $tabla = $this->db->table('jet_cct_tickets');

    if (!$this->table_exists($tabla) || empty($temas)) {
      return ['rows' => [], 'stats' => ['total' => 0, 'abiertos' => 0, 'cerrados' => 0], 'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'total_pages' => 1]];
    }

    $where = [];
    $args = [];

    $temaPhs = implode(',', array_fill(0, count($temas), '?'));
    $where[] = "CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$temaPhs})";
    foreach ($temas as $t) {
      $args[] = $t;
    }

    if (!empty($p['fTema'])) {
      $where[] = 'CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci';
      $args[] = $p['fTema'];
    }

    $statusBucket = strtolower(trim((string) ($p['_scmStatusBucket'] ?? 'active')));
    if (!in_array($statusBucket, ['active', 'postergados', 'cerrados'], true)) {
      $statusBucket = 'active';
    }

    if ($statusBucket === 'postergados') {
      $col = $this->detect_first_existing_column($tabla, ['estado_administrativo', 'estado_admin_ticket', 'estado_admin']);
      if ($col !== '') {
        $where[] = "LOWER(TRIM(COALESCE(`{$col}`, ''))) = ?";
        $args[] = 'postergado';
        $where[] = "LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN (?, ?, ?)";
        $args[] = 'cerrado';
        $args[] = 'resuelto';
        $args[] = 'finalizado';
      } else {
        $where[] = '1 = 0';
      }
    } elseif ($statusBucket === 'cerrados') {
      $closedValues = ['cerrado', 'resuelto', 'finalizado'];
      $closedWhere = ["LOWER(TRIM(COALESCE(`estado`, ''))) IN (?, ?, ?)"];
      foreach ($closedValues as $value) {
        $args[] = $value;
      }
      $adminCol = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
      if ($adminCol !== '') {
        $closedWhere[] = "LOWER(TRIM(COALESCE(`{$adminCol}`, ''))) IN (?, ?, ?)";
        foreach ($closedValues as $value) {
          $args[] = $value;
        }
      }
      $where[] = '(' . implode(' OR ', $closedWhere) . ')';
    } else {
      $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) IN (?, ?)';
      $args[] = 'nuevo';
      $args[] = 'en proceso';

      if (!empty($p['fEstado'])) {
        $estadoFilter = strtolower(trim((string) $p['fEstado']));
        if (in_array($estadoFilter, ['nuevo', 'en proceso'], true)) {
          $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) = ?';
          $args[] = $estadoFilter;
        }
      }
      if (!empty($p['fEstadoAdmin'])) {
        $col = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
        if ($col !== '') {
          $where[] = "CONVERT(`{$col}` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
          $args[] = $p['fEstadoAdmin'];
        }
      }
    }

    if (!empty($p['fInmueble'])) {
      $term = '%' . $this->db->escapeLike($p['fInmueble']) . '%';
      $parts = [];
      foreach (['id_inmueble', 'inmueble', 'numero_inmueble'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $parts[] = "`{$candidate}` LIKE ?";
          $args[] = $term;
        }
      }
      if (!empty($parts)) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    if (!empty($p['fContrato'])) {
      $col = $this->detect_first_existing_column($tabla, ['id_contrato', 'contrato', 'numero_contrato']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fContrato']) . '%';
      }
    }
    if (!empty($p['fAsunto'])) {
      $col = $this->detect_first_existing_column($tabla, ['asunto', 'descripcion']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fAsunto']) . '%';
      }
    }
    if (!empty($p['fArrendatario'])) {
      $col = $this->detect_first_existing_column($tabla, ['arrendatario', 'nombre_arrendatario', 'nombre']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fArrendatario']) . '%';
      }
    }
    if (!empty($p['fPropietario'])) {
      $col = $this->detect_first_existing_column($tabla, ['propietario', 'nombre_propietario']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fPropietario']) . '%';
      }
    }
    if (!empty($p['fBarrio']) && $this->column_exists($tabla, 'barrio')) {
      $where[] = '`barrio` LIKE ?';
      $args[] = '%' . $this->db->escapeLike($p['fBarrio']) . '%';
    }
    if (!empty($p['fAseguradora'])) {
      $col = $this->detect_first_existing_column($tabla, ['numero_aseguradora', 'aseguradora']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fAseguradora']) . '%';
      }
    }
    if (!empty($p['fConsultorEntrega'])) {
      $col = $this->detect_first_existing_column($tabla, ['consultor_entrega']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fConsultorEntrega']) . '%';
      }
    }

    if (!empty($p['fEmpleado'])) {
      $empCols = [];
      foreach (['id_empleado', 'empleado'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $empCols[] = $candidate;
        }
      }
      if (!empty($empCols)) {
        $empWhere = [];
        foreach ($empCols as $empCol) {
          $empWhere[] = "TRIM(COALESCE(`{$empCol}`, '')) = ?";
          $args[] = $p['fEmpleado'];
        }
        $where[] = '(' . implode(' OR ', $empWhere) . ')';
      }
    }

    if (!empty($p['fFechaDesde'])) {
      $ts = strtotime($p['fFechaDesde']);
      if ($ts !== false && $ts > 0) {
        $where[] = 'fecha >= ?';
        $args[] = $ts;
      }
    }

    if (!empty($p['fFechaHasta'])) {
      $ts = strtotime($p['fFechaHasta'] . ' 23:59:59');
      if ($ts !== false && $ts > 0) {
        $where[] = 'fecha <= ?';
        $args[] = $ts;
      }
    }
    if (!empty($p['fCotizacion'])) {
      $cotCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
      if ($cotCol !== '') {
        if ($p['fCotizacion'] === 'has') {
          $where[] = "TRIM(COALESCE(`{$cotCol}`, '')) <> ''";
        } elseif ($p['fCotizacion'] === 'none') {
          $where[] = "TRIM(COALESCE(`{$cotCol}`, '')) = ''";
        }
      }
    }
    $magnitudCasoFilter = (string) (!empty($p['fMagnitudCaso']) ? $p['fMagnitudCaso'] : ($p['fMagnitud'] ?? ''));

    if ($magnitudCasoFilter !== '' && $this->column_exists($tabla, 'magnitud_caso')) {
      $where[] = "LOWER(TRIM(COALESCE(`magnitud_caso`, ''))) = ?";
      $args[] = strtolower(trim($magnitudCasoFilter));
    }
    if (!empty($p['fPerturbacion'])) {
      $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');
      if ($this->table_exists($cotTabla)) {
        $cotIdCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
        if ($this->column_exists($cotTabla, 'perturbacion')) {
          $ticketIdCol = $this->detect_first_existing_column($tabla, ['id_ticket']);
          $perturbacionFilter = strtolower(trim((string) $p['fPerturbacion']));
          $isConFilter = in_array($perturbacionFilter, ['has', 'si', 'sí', 'con'], true);
          $isSinFilter = in_array($perturbacionFilter, ['none', 'no', 'sin'], true);
          $pertExpr = "LOWER(TRIM(COALESCE(cot_filter.perturbacion, '')))";
          $condExpr = '';
          if ($isConFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '' AND {$pertExpr} NOT IN ('0', 'no', 'false', 'null'))";
          } elseif ($isSinFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) = '' OR {$pertExpr} IN ('0', 'no', 'false', 'null'))";
          }
          if ($condExpr === '') {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '')";
          }
          $existsByCotId = '';
          if ($cotIdCol !== '') {
            $existsByCotId = "EXISTS (
              SELECT 1
              FROM `{$cotTabla}` cot_filter
              WHERE CAST(NULLIF(TRIM(COALESCE(`{$tabla}`.`{$cotIdCol}`, '')), '') AS UNSIGNED) = cot_filter._ID
                AND {$condExpr}
            )";
          }
          $existsByTicket = '';
          if ($ticketIdCol !== '' && $this->column_exists($cotTabla, 'id_ticket')) {
            $existsByTicket = "EXISTS (
              SELECT 1
              FROM `{$cotTabla}` cot_filter
              WHERE TRIM(COALESCE(cot_filter.id_ticket, '')) <> ''
                AND TRIM(COALESCE(cot_filter.id_ticket, '')) = TRIM(COALESCE(`{$tabla}`.`{$ticketIdCol}`, ''))
                AND {$condExpr}
            )";
          }
          if ($existsByCotId !== '' && $existsByTicket !== '') {
            $where[] = "({$existsByCotId} OR {$existsByTicket})";
          } elseif ($existsByCotId !== '') {
            $where[] = $existsByCotId;
          } elseif ($existsByTicket !== '') {
            $where[] = $existsByTicket;
          }
        }
      }
    }
    if (!empty($p['fAtraso']) && is_numeric($p['fAtraso'])) {
      $days = max(0, (int) $p['fAtraso']);
      if ($days > 0) {
        $where[] = '(UNIX_TIMESTAMP() - COALESCE(fecha, 0)) >= ?';
        $args[] = $days * 86400;
      }
    }
    if (!empty($p['fSinActualizar']) && is_numeric($p['fSinActualizar'])) {
      $days = max(0, (int) $p['fSinActualizar']);
      if ($days > 0) {
        $where[] = '(UNIX_TIMESTAMP() - COALESCE(NULLIF(fecha_actualizacion, 0), fecha, 0)) >= ?';
        $args[] = $days * 86400;
      }
    }
    if (!empty($p['fTuvoSeguimiento'])) {
      $seg = strtolower(trim((string) $p['fTuvoSeguimiento']));
      if (in_array($seg, ['si', 'no'], true) && $this->column_exists($tabla, 'tuvo_seguimiento')) {
        $where[] = "LOWER(TRIM(COALESCE(`tuvo_seguimiento`, ''))) = ?";
        $args[] = $seg;
      }
    }
    if (!empty($p['fRevision'])) {
      $revisionFilter = strtolower(trim((string) $p['fRevision']));
      $revisionCols = [];
      foreach (['id_revision_preventiva', 'id_revision_correctiva', 'id_revision_entrega', 'id_revision_recibo'] as $rc) {
        if ($this->column_exists($tabla, $rc)) {
          $revisionCols[] = $rc;
        }
      }
      if (!empty($revisionCols)) {
        $parts = [];
        foreach ($revisionCols as $rc) {
          $parts[] = "TRIM(COALESCE(`{$rc}`, '')) <> ''";
        }
        $revHasExpr = '(' . implode(' OR ', $parts) . ')';
        if ($revisionFilter === 'has') {
          $where[] = $revHasExpr;
        } elseif ($revisionFilter === 'none') {
          $where[] = 'NOT ' . $revHasExpr;
        }
      }
    }
    if (!empty($p['fDanos'])) {
      $damageFilter = strtolower(trim((string) $p['fDanos']));
      $damageCond = '';
      if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
        $damageCond = "LOWER(TRIM(COALESCE(prev_filter.encontro_danos, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
      } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
        $damageCond = "LOWER(TRIM(COALESCE(prev_filter.encontro_danos, ''))) IN ('no', '0', 'false', 'sin danos')";
      }

      if ($damageCond !== '') {
        $prevTabla = $this->db->table('jet_cct_revision_preventiva');
        if (
          $this->table_exists($prevTabla)
          && $this->column_exists($tabla, 'id_revision_preventiva')
          && $this->column_exists($prevTabla, '_ID')
          && $this->column_exists($prevTabla, 'encontro_danos')
        ) {
          $where[] = "EXISTS (
            SELECT 1
            FROM `{$prevTabla}` prev_filter
            WHERE FIND_IN_SET(CAST(prev_filter._ID AS CHAR), REPLACE(COALESCE(`{$tabla}`.`id_revision_preventiva`, ''), ' ', '')) > 0
              AND {$damageCond}
          )";
        } elseif ($this->column_exists($tabla, 'se_encontraron_danos')) {
          if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`se_encontraron_danos`, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
          } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`se_encontraron_danos`, ''))) IN ('no', '0', 'false', 'sin danos')";
          }
        } elseif ($this->column_exists($tabla, 'encontro_danos')) {
          if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`encontro_danos`, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
          } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`encontro_danos`, ''))) IN ('no', '0', 'false', 'sin danos')";
          }
        }
      }
    }

    if (!empty($p['fBusqueda'])) {
      $likeVal = '%' . $this->db->escapeLike($p['fBusqueda']) . '%';
      $searchCols = [];
      foreach (['asunto', 'descripcion', 'direccion', 'barrio', 'nombre', 'correo', 'id_ticket'] as $col) {
        if ($this->column_exists($tabla, $col)) {
          $searchCols[] = "`{$col}` LIKE ?";
          $args[] = $likeVal;
        }
      }
      if (!empty($searchCols)) {
        $where[] = '(' . implode(' OR ', $searchCols) . ')';
      }
    }

    $whereStr = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}";
    $total = (int) $this->db->getVar($countSql, $args);

    $cotCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
    $revCols = [];
    foreach (['id_revision_preventiva', 'id_revision_correctiva', 'id_revision_entrega', 'id_revision_recibo'] as $rc) {
      if ($this->column_exists($tabla, $rc)) {
        $revCols[] = $rc;
      }
    }
    $revExpr = '0';
    if (!empty($revCols)) {
      $parts = [];
      foreach ($revCols as $rc) {
        $parts[] = "TRIM(COALESCE(`{$rc}`, '')) <> ''";
      }
      $revExpr = '(' . implode(' OR ', $parts) . ')';
    }
    $cotExpr = $cotCol !== '' ? "TRIM(COALESCE(`{$cotCol}`, '')) <> ''" : '0';
    $staleExpr = "AVG((UNIX_TIMESTAMP() - COALESCE(NULLIF(`fecha_actualizacion`, 0), `fecha`, UNIX_TIMESTAMP())) / 3600)";
    $firstExpr = "AVG((COALESCE(NULLIF(`fecha_actualizacion`, 0), `fecha`) - COALESCE(`fecha`, 0)) / 3600)";
    $magnitudDanoExpr = $this->column_exists($tabla, 'magnitud_caso')
      ? "LOWER(TRIM(COALESCE(`magnitud_caso`, '')))"
      : "''";
    $prevTabla = $this->db->table('jet_cct_revision_preventiva');
    if (
      $this->table_exists($prevTabla)
      && $this->column_exists($tabla, 'id_revision_preventiva')
      && $this->column_exists($prevTabla, '_ID')
      && $this->column_exists($prevTabla, 'encontro_danos')
    ) {
      $danosValueParts = [];
      if ($this->column_exists($tabla, 'se_encontraron_danos')) {
        $danosValueParts[] = "NULLIF(`se_encontraron_danos`, '')";
      }
      if ($this->column_exists($tabla, 'encontro_danos')) {
        $danosValueParts[] = "NULLIF(`encontro_danos`, '')";
      }
      $danosValueParts[] = "(
          SELECT COALESCE(prev_stats.encontro_danos, '')
          FROM `{$prevTabla}` prev_stats
          WHERE FIND_IN_SET(CAST(prev_stats._ID AS CHAR), REPLACE(COALESCE(`{$tabla}`.`id_revision_preventiva`, ''), ' ', '')) > 0
          ORDER BY prev_stats._ID DESC
          LIMIT 1
        )";
      $danosValueParts[] = "''";
      $danosExpr = "LOWER(TRIM(COALESCE(
        " . implode(",\n        ", $danosValueParts) . "
      )))";
    } else {
      $danosExpr = $this->column_exists($tabla, 'se_encontraron_danos')
        ? "LOWER(TRIM(COALESCE(`se_encontraron_danos`, '')))"
        : ($this->column_exists($tabla, 'encontro_danos') ? "LOWER(TRIM(COALESCE(`encontro_danos`, '')))" : "''");
    }
    $revEntregaExpr = $this->column_exists($tabla, 'id_revision_entrega') ? "TRIM(COALESCE(`id_revision_entrega`, '')) <> ''" : '0';
    $invEntregaExpr = $this->column_exists($tabla, 'id_inventario_entrega') ? "TRIM(COALESCE(`id_inventario_entrega`, '')) <> ''" : '0';
    $revReciboExpr = $this->column_exists($tabla, 'id_revision_recibo') ? "TRIM(COALESCE(`id_revision_recibo`, '')) <> ''" : '0';
    $calActTable = 'calendario_actividades';
    $conCitaExpr = $this->table_exists($calActTable)
      ? "EXISTS (SELECT 1 FROM `{$calActTable}` WHERE `id_ticket` = `{$tabla}`.`_ID`)"
      : '0';
    $aggSql = "SELECT
        SUM(CASE WHEN {$cotExpr} THEN 1 ELSE 0 END) AS con_cotizacion,
        SUM(CASE WHEN {$revExpr} THEN 1 ELSE 0 END) AS con_revision,
        SUM(CASE WHEN {$revEntregaExpr} THEN 1 ELSE 0 END) AS con_revision_entrega,
        SUM(CASE WHEN {$invEntregaExpr} THEN 1 ELSE 0 END) AS con_inventario,
        SUM(CASE WHEN {$revReciboExpr} THEN 1 ELSE 0 END) AS con_revision_recibo,
        SUM(CASE WHEN {$conCitaExpr} THEN 1 ELSE 0 END) AS con_cita,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(`estado`, ''))) = 'nuevo' THEN 1 ELSE 0 END) AS estado_nuevo,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(`estado`, ''))) = 'en proceso' THEN 1 ELSE 0 END) AS estado_en_proceso,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'critico' THEN 1 ELSE 0 END) AS magnitud_critico,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'alto' THEN 1 ELSE 0 END) AS magnitud_alto,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'medio' THEN 1 ELSE 0 END) AS magnitud_medio,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'bajo' THEN 1 ELSE 0 END) AS magnitud_bajo,
        SUM(CASE WHEN {$danosExpr} IN ('si', 'sí', '1', 'true', 'yes') THEN 1 ELSE 0 END) AS danos_si,
        SUM(CASE WHEN {$danosExpr} IN ('no', '0', 'false') THEN 1 ELSE 0 END) AS danos_no,
        {$staleExpr} AS avg_stale_h,
        {$firstExpr} AS avg_first_h
      FROM `{$tabla}` WHERE {$whereStr}";
    $agg = $this->db->getRow($aggSql, $args);

    $perPage = max(5, min(100, (int) ($p['fPerPage'] ?? 20)));
    $page = max(1, (int) ($p['fPage'] ?? 1));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $rows = [];
    if ($includeRows) {
      $sql = "SELECT * FROM `{$tabla}` WHERE {$whereStr} ORDER BY fecha DESC LIMIT ? OFFSET ?";
      $queryArgs = array_merge($args, [$perPage, $offset]);

      $rows = $this->db->getResults($sql, $queryArgs);
      $rows = $this->enrich_ticket_rows_with_related_data($rows);
      $rows = $this->attach_historial_rows($rows);
      $rows = $this->enrich_generic_case_details($rows);
    }

    $conCot = (int) ($agg['con_cotizacion'] ?? 0);
    $conRev = (int) ($agg['con_revision'] ?? 0);
    $conRevEntrega = (int) ($agg['con_revision_entrega'] ?? 0);
    $conInventario = (int) ($agg['con_inventario'] ?? 0);
    $conRevRecibo = (int) ($agg['con_revision_recibo'] ?? 0);
    $conCita = (int) ($agg['con_cita'] ?? 0);
    $estadoNuevo = (int) ($agg['estado_nuevo'] ?? 0);
    $estadoEnProceso = (int) ($agg['estado_en_proceso'] ?? 0);
    $avgStale = isset($agg['avg_stale_h']) ? (float) $agg['avg_stale_h'] : 0.0;
    $avgFirst = isset($agg['avg_first_h']) ? (float) $agg['avg_first_h'] : 0.0;
    $abiertos = $statusBucket === 'cerrados' ? 0 : $total;
    $cerrados = $statusBucket === 'cerrados' ? $total : 0;

    return [
      'rows' => $rows,
      'stats' => [
        'total' => $total,
        'abiertos' => $abiertos,
        'cerrados' => $cerrados,
        'con_cotizacion' => $conCot,
        'sin_cotizacion' => max(0, $total - $conCot),
        'con_revision' => $conRev,
        'sin_revision' => max(0, $total - $conRev),
        'con_revision_entrega' => $conRevEntrega,
        'sin_revision_entrega' => max(0, $total - $conRevEntrega),
        'con_inventario' => $conInventario,
        'sin_inventario' => max(0, $total - $conInventario),
        'con_revision_recibo' => $conRevRecibo,
        'sin_revision_recibo' => max(0, $total - $conRevRecibo),
        'con_cita' => $conCita,
        'sin_cita' => max(0, $total - $conCita),
        'estado_nuevo' => $estadoNuevo,
        'estado_en_proceso' => $estadoEnProceso,
        'avg_first_h' => $avgFirst,
        'avg_stale_h' => $avgStale,
        'magnitud_critico' => (int) ($agg['magnitud_critico'] ?? 0),
        'magnitud_alto' => (int) ($agg['magnitud_alto'] ?? 0),
        'magnitud_medio' => (int) ($agg['magnitud_medio'] ?? 0),
        'magnitud_bajo' => (int) ($agg['magnitud_bajo'] ?? 0),
        'danos_si' => (int) ($agg['danos_si'] ?? 0),
        'danos_no' => (int) ($agg['danos_no'] ?? 0),
      ],
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
    ];
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrich_generic_case_details(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $ticketKeys = [];
    $contractIds = [];
    $propertyIds = [];
    $ownerIds = [];
    $tenantIds = [];
    foreach ($rows as $row) {
      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        $ticketKeys[$key] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['contrato', 'id_contrato'])) as $id) {
        $contractIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_inmueble'])) as $id) {
        $propertyIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_propietario'])) as $id) {
        $ownerIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_arrendatario'])) as $id) {
        $tenantIds[$id] = true;
      }
    }

    $segByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_seguimiento_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified', 'id_coordinador', 'id_empleado', 'evidencia']
    );
    $notesByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_notas_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'id_empleado', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified']
    );
    $contractById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_contratos_arrendamiento'),
      ['_ID', 'contrato'],
      array_keys($contractIds)
    );
    $propertyById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_inmuebles'),
      ['codigo'],
      array_keys($propertyIds)
    );
    $ownerById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_propietarios'),
      ['id_propietario'],
      array_keys($ownerIds)
    );
    $tenantById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_arrendatarios'),
      ['id_arrendatario'],
      array_keys($tenantIds)
    );
    $histByProperty = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_inmueble', 'id_inmueble_data'],
      array_keys($propertyIds),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );
    $histByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );

    foreach ($rows as &$row) {
      $row['_scm_seguimientos_ticket'] = [];
      $row['_scm_notas_ticket'] = [];
      $row['_scm_historial_inmueble'] = [];
      $row['_scm_contrato_data'] = [];
      $row['_scm_inmueble_data'] = [];
      $row['_scm_propietario_data'] = [];
      $row['_scm_arrendatario_data'] = [];

      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        if (!empty($segByTicket[$key])) {
          $row['_scm_seguimientos_ticket'] = array_merge($row['_scm_seguimientos_ticket'], $segByTicket[$key]);
        }
        if (!empty($notesByTicket[$key])) {
          $row['_scm_notas_ticket'] = array_merge($row['_scm_notas_ticket'], $notesByTicket[$key]);
        }
      }

      $contractId = $this->first_id_value($this->first_existing_value($row, ['contrato', 'id_contrato']));
      if ($contractId !== '' && isset($contractById[$contractId])) {
        $row['_scm_contrato_data'] = $contractById[$contractId];
      }

      $propertyId = $this->first_id_value($this->first_existing_value($row, ['id_inmueble']));
      if ($propertyId === '' && !empty($row['_scm_contrato_data']) && is_array($row['_scm_contrato_data'])) {
        $propertyId = $this->first_id_value($this->first_existing_value($row['_scm_contrato_data'], ['id_inmueble']));
      }
      if ($propertyId !== '' && isset($propertyById[$propertyId])) {
        $row['_scm_inmueble_data'] = $propertyById[$propertyId];
      }
      $ownerId = $this->first_id_value($this->first_existing_value($row, ['id_propietario']));
      if ($ownerId !== '' && isset($ownerById[$ownerId])) {
        $row['_scm_propietario_data'] = $ownerById[$ownerId];
        if (trim((string) ($row['indicativo_propietario'] ?? '')) === '') {
          $row['indicativo_propietario'] = trim((string) ($ownerById[$ownerId]['indicativo'] ?? ''));
        }
      }
      $tenantId = $this->first_id_value($this->first_existing_value($row, ['id_arrendatario']));
      if ($tenantId !== '' && isset($tenantById[$tenantId])) {
        $row['_scm_arrendatario_data'] = $tenantById[$tenantId];
        if (trim((string) ($row['indicativo_arrendatario'] ?? '')) === '') {
          $row['indicativo_arrendatario'] = trim((string) ($tenantById[$tenantId]['indicativo'] ?? ''));
        }
      }
      if ($propertyId !== '' && isset($histByProperty[$propertyId])) {
        $row['_scm_historial_inmueble'] = $histByProperty[$propertyId];
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        if (!empty($histByTicket[$key])) {
          $row['_scm_historial_inmueble'] = array_merge($row['_scm_historial_inmueble'], $histByTicket[$key]);
        }
      }
      if (!empty($row['_scm_historial_inmueble'])) {
        $uniqueHist = [];
        foreach ($row['_scm_historial_inmueble'] as $histItem) {
          if (!is_array($histItem)) {
            continue;
          }
          $histKey = trim((string)($histItem['_ID'] ?? ''));
          if ($histKey === '') {
            $histKey = md5(json_encode($histItem));
          }
          $uniqueHist[$histKey] = $histItem;
        }
        $row['_scm_historial_inmueble'] = array_values($uniqueHist);
      }

      $this->sort_rows_by_date_desc($row['_scm_seguimientos_ticket']);
      $this->sort_rows_by_date_desc($row['_scm_notas_ticket']);
      $this->sort_rows_by_date_desc($row['_scm_historial_inmueble']);
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrich_ticket_rows_with_related_data(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $prevIds = [];
    $cotIds = [];
    $calendarIds = [];
    $ticketKeys = [];
    foreach ($rows as $row) {
      foreach ($this->split_id_values($row['id_revision_preventiva'] ?? '') as $id) {
        $prevIds[$id] = true;
      }
      foreach ($this->split_id_values($row['id_cotizacion_mantenimiento'] ?? $row['id_cotizacion'] ?? '') as $id) {
        $cotIds[$id] = true;
      }
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $calendarIds[$ticketPk] = true;
      }
      $logicalTicket = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalTicket !== '') {
        $ticketKeys[$logicalTicket] = true;
      }
      if ($logicalTicket !== '' && ctype_digit($logicalTicket)) {
        $calendarIds[(int) $logicalTicket] = true;
      }
    }

    $prevRows = $this->fetch_related_rows_by_ids(
      $this->db->table('jet_cct_revision_preventiva'),
      array_keys($prevIds),
      ['_ID', 'id_revision_preventiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_preventiva',
        'id_revision',
        'id',
        'estado',
        'estado_rev_preventiva',
        'estado_revision_preventiva',
        'fecha',
        'fecha_revision_preventiva',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'encontro_danos',
        'tiene_cotizacion',
        'se_envio',
      ]
    );

    $cotRows = $this->fetch_related_rows_by_ids(
      $this->db->table('jet_cct_cotizacion_mantenimiento'),
      array_keys($cotIds),
      ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id'],
      [
        '_ID',
        'id_cotizacion_mantenimiento',
        'id_cotizacion',
        'id',
        'id_ticket',
        'estado',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'estado_respuesta',
        'respuesta',
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
        'fecha',
        'fecha_envio',
        'fecha_respuesta',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'inicio_trabajo',
        'final_trabajo',
        'id_acta_satisfaccion',
      ]
    );

    $calendarRows = $this->fetch_calendar_dates(array_keys($calendarIds));
    $cotRowsByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_cotizacion_mantenimiento'),
      ['id_ticket'],
      array_keys($ticketKeys),
      [
        '_ID',
        'id_ticket',
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
      ]
    );

    $entregaRevByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_revision_entrega'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $inventarioByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_inventario'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $actaEntregaByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_actas_de_entrega'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $reciboRevByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_revision_recibo'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $actaReciboByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_actas_de_recibo'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );

    foreach ($rows as &$row) {
      $row['_scm_cita_cal_fecha'] = $row['_scm_cita_cal_fecha'] ?? '';
      $row['_scm_prev_fecha'] = $row['_scm_prev_fecha'] ?? '';
      $row['_scm_prev_encontro_danos'] = $row['_scm_prev_encontro_danos'] ?? '';
      $row['_scm_prev_se_envio'] = $row['_scm_prev_se_envio'] ?? '';
      $row['_scm_cot_fecha_registro'] = $row['_scm_cot_fecha_registro'] ?? '';
      $row['_scm_cot_fecha_envio'] = $row['_scm_cot_fecha_envio'] ?? '';
      $row['_scm_cot_fecha_respuesta'] = $row['_scm_cot_fecha_respuesta'] ?? '';
      $row['_scm_cot_final_trabajo'] = $row['_scm_cot_final_trabajo'] ?? '';
      $row['_scm_cot_id_acta'] = $row['_scm_cot_id_acta'] ?? '';
      $row['_scm_cot_perturbacion'] = $row['_scm_cot_perturbacion'] ?? '';
      $row['_scm_cot_justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'] ?? '';
      $row['_scm_cot_valor_bonificacion'] = $row['_scm_cot_valor_bonificacion'] ?? '';
      $row['_scm_cot_area_afectada'] = $row['_scm_cot_area_afectada'] ?? '';
      $row['cita_fecha_inicio'] = $row['cita_fecha_inicio'] ?? '';
      $row['prev_fecha_registro'] = $row['prev_fecha_registro'] ?? '';
      $row['prev_se_envio'] = $row['prev_se_envio'] ?? '';
      $row['cot_id'] = $row['cot_id'] ?? '';
      $row['cot_fecha_registro'] = $row['cot_fecha_registro'] ?? '';
      $row['cot_fecha_envio'] = $row['cot_fecha_envio'] ?? '';
      $row['cot_fecha_respuesta'] = $row['cot_fecha_respuesta'] ?? '';
      $row['cot_final_trabajo'] = $row['cot_final_trabajo'] ?? '';
      $row['ticket_cct_created'] = $row['ticket_cct_created'] ?? '';
      $row['id_cita_agendada'] = $row['id_cita_agendada'] ?? '';
      $row['cita_evento_id'] = $row['cita_evento_id'] ?? '';
      $row['entrega_rev_fecha'] = $row['entrega_rev_fecha'] ?? '';
      $row['id_revision_entrega'] = $row['id_revision_entrega'] ?? '';
      $row['entrega_inventario_fecha'] = $row['entrega_inventario_fecha'] ?? '';
      $row['id_inventario_entrega'] = $row['id_inventario_entrega'] ?? '';
      $row['entrega_acta_fecha'] = $row['entrega_acta_fecha'] ?? '';
      $row['id_acta_entrega'] = $row['id_acta_entrega'] ?? '';
      $row['recibo_rev_fecha'] = $row['recibo_rev_fecha'] ?? '';
      $row['id_revision_recibo'] = $row['id_revision_recibo'] ?? '';
      $row['recibo_acta_fecha'] = $row['recibo_acta_fecha'] ?? '';
      $row['id_acta_recibo'] = $row['id_acta_recibo'] ?? '';

      $ticketPk = (int) ($row['_ID'] ?? 0);
      $logicalTicket = trim((string) ($row['id_ticket'] ?? ''));

      if (trim((string) ($row['ticket_cct_created'] ?? '')) === '') {
        $ticketCreatedTs = $this->parse_unix_ts((string) ($row['cct_created'] ?? ''));
        if ($ticketCreatedTs > 0) {
          $row['ticket_cct_created'] = (string) $ticketCreatedTs;
        }
      }

      foreach ([$ticketPk, ctype_digit($logicalTicket) ? (int) $logicalTicket : 0] as $calendarId) {
        if ($calendarId > 0 && isset($calendarRows[$calendarId])) {
          $calendarEvent = $calendarRows[$calendarId];
          $calendarTs = $this->parse_unix_ts($calendarEvent['fecha_inicio']);
          $row['_scm_cita_cal_fecha'] = $calendarTs > 0 ? (string) $calendarTs : '';
          $row['cita_fecha_inicio'] = $row['_scm_cita_cal_fecha'];
          $row['id_cita_agendada'] = $calendarEvent['id'];
          $row['cita_evento_id'] = $calendarEvent['id'];
          break;
        }
      }

      $prevRow = $this->first_related_row($prevRows, $row['id_revision_preventiva'] ?? '');
      if (is_array($prevRow)) {
        $prevDate = $this->first_existing_value($prevRow, ['fecha_revision_preventiva', 'fecha', 'cct_created', 'created_at', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $prevTs = $this->parse_unix_ts($prevDate);
        $row['_scm_prev_fecha'] = $prevTs > 0 ? (string) $prevTs : '';
        $row['_scm_prev_encontro_danos'] = $this->first_existing_value($prevRow, ['encontro_danos', 'tiene_cotizacion']);
        $row['_scm_prev_se_envio'] = $this->first_existing_value($prevRow, ['se_envio']);
        $row['prev_fecha_registro'] = $row['_scm_prev_fecha'];
        $row['prev_se_envio'] = $row['_scm_prev_se_envio'];

        $prevEstado = $this->first_existing_value($prevRow, ['estado_rev_preventiva', 'estado_revision_preventiva', 'estado']);
        if ($prevEstado !== '' && trim((string) ($row['estado_rev_preventiva'] ?? '')) === '') {
          $row['estado_rev_preventiva'] = $prevEstado;
        }
        if (trim((string) ($row['se_encontraron_danos'] ?? '')) === '' && $row['_scm_prev_encontro_danos'] !== '') {
          $row['se_encontraron_danos'] = $row['_scm_prev_encontro_danos'];
        }
      }

      $cotRow = $this->first_related_row($cotRows, $row['id_cotizacion_mantenimiento'] ?? $row['id_cotizacion'] ?? '');
      if (!is_array($cotRow)) {
        foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
          if (!empty($cotRowsByTicket[$tk]) && is_array($cotRowsByTicket[$tk][0] ?? null)) {
            $chosen = $cotRowsByTicket[$tk][0];
            foreach ($cotRowsByTicket[$tk] as $candidate) {
              if (!is_array($candidate)) {
                continue;
              }
              $pVal = strtolower(trim((string) ($candidate['perturbacion'] ?? '')));
              if ($pVal !== '' && $pVal !== '0' && $pVal !== 'no' && $pVal !== 'false' && $pVal !== 'null') {
                $chosen = $candidate;
                break;
              }
            }
            $cotRow = $chosen;
            break;
          }
        }
      }
      if (is_array($cotRow)) {
        $cotReg = $this->first_existing_value($cotRow, ['cct_created', 'created_at', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $cotSentFlag = $this->first_existing_value($cotRow, ['fue_enviada_cotizacion_mantenimiento', 'fue_enviada', 'se_envio']);
        $cotSentOk = in_array(strtolower(trim((string) $cotSentFlag)), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $cotSend = $cotSentOk ? $this->first_existing_value($cotRow, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $cotResp = $this->first_existing_value($cotRow, ['fecha_respuesta', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $cotFinal = $this->first_existing_value($cotRow, ['final_trabajo']);

        $cotRegTs = $this->parse_unix_ts($cotReg);
        $cotSendTs = $this->parse_unix_ts($cotSend);
        $cotRespTs = $this->parse_unix_ts($cotResp);
        $cotFinalTs = $this->parse_unix_ts($cotFinal);
        $row['_scm_cot_fecha_registro'] = $cotRegTs > 0 ? (string) $cotRegTs : '';
        $row['_scm_cot_fecha_envio'] = $cotSendTs > 0 ? (string) $cotSendTs : '';
        $row['_scm_cot_fecha_respuesta'] = $cotRespTs > 0 ? (string) $cotRespTs : '';
        $row['_scm_cot_final_trabajo'] = $cotFinalTs > 0 ? (string) $cotFinalTs : '';
        $row['cot_id'] = trim((string) ($cotRow['_ID'] ?? $cotRow['id_cotizacion_mantenimiento'] ?? $cotRow['id_cotizacion'] ?? ''));
        $row['cot_fecha_registro'] = $row['_scm_cot_fecha_registro'];
        $row['cot_fecha_envio'] = $row['_scm_cot_fecha_envio'];
        $row['cot_fecha_respuesta'] = $row['_scm_cot_fecha_respuesta'];
        $row['cot_final_trabajo'] = $row['_scm_cot_final_trabajo'];
        $row['_scm_cot_id_acta'] = trim((string) ($cotRow['id_acta_satisfaccion'] ?? ''));
        $row['_scm_cot_perturbacion'] = trim((string) ($cotRow['perturbacion'] ?? ''));
        $row['_scm_cot_justificacion_perturbacion'] = trim((string) ($cotRow['justificacion_perturbacion'] ?? ''));
        $row['_scm_cot_valor_bonificacion'] = trim((string) ($cotRow['valor_bonificacion'] ?? ''));
        $row['_scm_cot_area_afectada'] = trim((string) ($cotRow['area_afectada'] ?? ''));
        if (trim((string) ($row['perturbacion'] ?? '')) === '' && $row['_scm_cot_perturbacion'] !== '') {
          $row['perturbacion'] = $row['_scm_cot_perturbacion'];
        }
        if (trim((string) ($row['justificacion_perturbacion'] ?? '')) === '' && $row['_scm_cot_justificacion_perturbacion'] !== '') {
          $row['justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'];
        }
        if (trim((string) ($row['valor_bonificacion'] ?? '')) === '' && $row['_scm_cot_valor_bonificacion'] !== '') {
          $row['valor_bonificacion'] = $row['_scm_cot_valor_bonificacion'];
        }
        if (trim((string) ($row['area_afectada'] ?? '')) === '' && $row['_scm_cot_area_afectada'] !== '') {
          $row['area_afectada'] = $row['_scm_cot_area_afectada'];
        }
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }

        $cotEstado = $this->first_existing_value($cotRow, ['estado_cotizacion_mantenimiento', 'estado']);
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }
        $cotRespEstado = $this->first_existing_value($cotRow, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta', 'respuesta']);
        if ($cotRespEstado !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotRespEstado;
        }
      }

      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_rev_fecha'] === '' && !empty($entregaRevByTicket[$tk])) {
          $revRow = $entregaRevByTicket[$tk][0];
          if (is_array($revRow)) {
            $row['id_revision_entrega'] = trim((string) ($revRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($revRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_rev_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_inventario_fecha'] === '' && !empty($inventarioByTicket[$tk])) {
          $invRow = $inventarioByTicket[$tk][0];
          if (is_array($invRow)) {
            $row['id_inventario_entrega'] = trim((string) ($invRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($invRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_inventario_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_acta_fecha'] === '' && !empty($actaEntregaByTicket[$tk])) {
          $actaRow = $actaEntregaByTicket[$tk][0];
          if (is_array($actaRow)) {
            $row['id_acta_entrega'] = trim((string) ($actaRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($actaRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_acta_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['recibo_rev_fecha'] === '' && !empty($reciboRevByTicket[$tk])) {
          $revRow = $reciboRevByTicket[$tk][0];
          if (is_array($revRow)) {
            $row['id_revision_recibo'] = trim((string) ($revRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($revRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['recibo_rev_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['recibo_acta_fecha'] === '' && !empty($actaReciboByTicket[$tk])) {
          $actaRow = $actaReciboByTicket[$tk][0];
          if (is_array($actaRow)) {
            $row['id_acta_recibo'] = trim((string) ($actaRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($actaRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['recibo_acta_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
    }
    unset($row);

    return $rows;
  }

  /** @param array<int,int|string> $ticketIds @return array<int,array<string,string>> */
  private function fetch_calendar_dates(array $ticketIds): array
  {
    $ticketIds = array_values(array_filter(array_unique(array_map('intval', $ticketIds)), static fn(int $id): bool => $id > 0));
    if (empty($ticketIds) || !$this->table_exists('calendario_actividades')) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    $rows = $this->db->getResults(
      "SELECT `id`, `id_ticket`, `fecha_inicio` FROM `calendario_actividades` WHERE `id_ticket` IN ({$ph}) ORDER BY `id` DESC",
      $ticketIds
    );

    $out = [];
    foreach ($rows as $row) {
      $id = (int) ($row['id_ticket'] ?? 0);
      if ($id > 0 && !isset($out[$id])) {
        $out[$id] = [
          'id'          => (string) ($row['id'] ?? ''),
          'fecha_inicio' => (string) ($row['fecha_inicio'] ?? ''),
        ];
      }
    }
    return $out;
  }

  /** @param array<string,mixed> $row @return array<int,string> */
  private function resolve_ticket_lookup_keys(array $row): array
  {
    $keys = [];
    $ticketPk = (int) ($row['_ID'] ?? 0);
    if ($ticketPk > 0) {
      $keys[] = (string) $ticketPk;
    }

    $logicalId = trim((string) ($row['id_ticket'] ?? ''));
    if ($logicalId !== '' && !in_array($logicalId, $keys, true)) {
      $keys[] = $logicalId;
    }

    return $keys;
  }

  /**
   * @param array<int,string> $columnCandidates
   * @param array<int,string> $keys
   * @param array<int,string> $wantedColumns
   * @return array<string,array<int,array<string,mixed>>>
   */
  private function fetch_rows_grouped_by_column_candidates(string $table, array $columnCandidates, array $keys, array $wantedColumns): array
  {
    $keys = array_values(array_filter(array_unique(array_map('strval', $keys)), static fn(string $key): bool => trim($key) !== ''));
    if (empty($keys) || !$this->table_exists($table)) {
      return [];
    }

    $columns = [];
    foreach ($columnCandidates as $column) {
      if ($this->column_exists($table, $column)) {
        $columns[] = $column;
      }
    }
    if (empty($columns)) {
      return [];
    }

    $selectCols = [];
    foreach ($wantedColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $selectCols[] = $column;
      }
    }
    foreach ($columns as $column) {
      if (!in_array($column, $selectCols, true)) {
        $selectCols[] = $column;
      }
    }
    if (empty($selectCols)) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($keys), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($columns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($keys as $key) {
        $args[] = $key;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $selectCols));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $grouped = [];
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $key = trim((string) ($row[$column] ?? ''));
        if ($key !== '') {
          $grouped[$key][] = $row;
        }
      }
    }
    return $grouped;
  }

  /**
   * @param array<int,string> $idColumns
   * @param array<int,string> $ids
   * @return array<string,array<string,mixed>>
   */
  private function fetch_single_rows_by_id_candidates(string $table, array $idColumns, array $ids): array
  {
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn(string $id): bool => trim($id) !== ''));
    if (empty($ids) || !$this->table_exists($table)) {
      return [];
    }

    $columns = [];
    foreach ($idColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $columns[] = $column;
      }
    }
    if (empty($columns)) {
      return [];
    }

    $selectCols = $this->get_existing_columns($table, [
      '_ID',
      'contrato',
      'codigo',
      'id_contrato',
      'id_contrato_arrendamiento',
      'id_inmueble',
      'id_inmueble_data',
      'inmueble',
      'arrendatario',
      'propietario',
      'nombre',
      'correo',
      'celular',
      'indicativo',
      'direccion',
      'barrio',
      'ciudad',
      'tipo_inmueble',
      'tipo_negocio',
      'destinacion',
      'uso',
      'estado',
      'precio_arriendo',
      'precio_venta',
      'precio_admin',
      'area_construida',
      'area_privada',
      'habitaciones',
      'banos',
      'parqueaderos',
      'estrato',
      'copropiedad',
      'matricula_inmobiliaria',
      'valor_canon',
      'valor_administracion',
      'tasa_administracion',
      'porcentaje_incremento_admin',
      'derechos_inmobiliarios',
      'aseguradora',
      'numero_solicitud',
      'id_estudio_aseguradora',
      'ubicacion_google_maps',
      'fecha',
      'cct_created',
      'cct_modified',
    ]);
    foreach ($columns as $column) {
      if (!in_array($column, $selectCols, true)) {
        $selectCols[] = $column;
      }
    }

    $ph = implode(',', array_fill(0, count($ids), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($columns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $selectCols));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $out = [];
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $id = trim((string) ($row[$column] ?? ''));
        if ($id !== '') {
          $out[$id] = $row;
        }
      }
    }
    return $out;
  }

  /** @return array<int,string> */
  private function get_existing_columns(string $table, array $columns): array
  {
    $out = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $out[] = $column;
      }
    }
    return $out;
  }

  /** @param array<int,array<string,mixed>> $rows */
  private function sort_rows_by_date_desc(array &$rows): void
  {
    usort($rows, function (array $a, array $b): int {
      return $this->parse_unix_ts($b['fecha'] ?? $b['cct_created'] ?? $b['cct_modified'] ?? '') <=> $this->parse_unix_ts($a['fecha'] ?? $a['cct_created'] ?? $a['cct_modified'] ?? '');
    });
  }

  /** @return array<string,array<string,mixed>> */
  private function fetch_related_rows_by_ids(string $table, array $ids, array $idColumns, array $selectColumns): array
  {
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn(string $id): bool => trim($id) !== ''));
    if (empty($ids) || !$this->table_exists($table)) {
      return [];
    }

    $existingIdColumns = [];
    foreach ($idColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $existingIdColumns[] = $column;
      }
    }
    if (empty($existingIdColumns)) {
      return [];
    }

    $existingSelectColumns = [];
    foreach ($selectColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $existingSelectColumns[] = $column;
      }
    }
    foreach ($existingIdColumns as $column) {
      if (!in_array($column, $existingSelectColumns, true)) {
        $existingSelectColumns[] = $column;
      }
    }

    $ph = implode(',', array_fill(0, count($ids), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($existingIdColumns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $existingSelectColumns));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $map = [];
    foreach ($rows as $row) {
      foreach ($existingIdColumns as $column) {
        $id = trim((string) ($row[$column] ?? ''));
        if ($id !== '') {
          $map[$id] = $row;
        }
      }
    }
    return $map;
  }

  /** @param array<string,array<string,mixed>> $relatedRows */
  private function first_related_row(array $relatedRows, $rawIds): ?array
  {
    foreach ($this->split_id_values($rawIds) as $id) {
      if (isset($relatedRows[$id])) {
        return $relatedRows[$id];
      }
    }
    return null;
  }

  /** @return array<int,string> */
  private function split_id_values($raw): array
  {
    $parts = preg_split('/[,\s;|]+/', trim((string) $raw));
    if (!is_array($parts)) {
      return [];
    }
    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        $ids[] = $id;
      }
    }
    return array_values(array_unique($ids));
  }

  /** @param array<string,mixed> $row */
  private function first_existing_value(array $row, array $columns): string
  {
    foreach ($columns as $column) {
      $value = trim((string) ($row[$column] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @return array<string,mixed> */
  public function get_generic_filter_options(array $temas): array
  {
    $tabla = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($tabla) || empty($temas)) {
      return ['estado_admin' => [], 'funcionarios' => [], 'barrios' => $this->get_barrios_filter_options()];
    }

    $cacheKey = md5(json_encode(array_values($temas), JSON_UNESCAPED_UNICODE) ?: implode('|', $temas));
    if (isset($this->genericFilterOptionsCache[$cacheKey])) {
      return $this->genericFilterOptionsCache[$cacheKey];
    }

    $where = [];
    $args = [];
    $temaPhs = implode(',', array_fill(0, count($temas), '?'));
    $where[] = "CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$temaPhs})";
    foreach ($temas as $t) {
      $args[] = $t;
    }
    $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) IN (?, ?)';
    $args[] = 'nuevo';
    $args[] = 'en proceso';
    $whereStr = implode(' AND ', $where);

    $estadoAdmin = [];
    $adminCol = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
    if ($adminCol !== '') {
      $sql = "SELECT DISTINCT `{$adminCol}` AS val FROM `{$tabla}` WHERE {$whereStr} AND TRIM(COALESCE(`{$adminCol}`, '')) <> '' ORDER BY val ASC";
      $rows = $this->db->getResults($sql, $args);
      foreach ($rows as $row) {
        $v = trim((string) ($row['val'] ?? ''));
        if ($v !== '') {
          $estadoAdmin[] = $v;
        }
      }
    }

    $funcionarios = [];
    $funcionariosTable = $this->db->table('jet_cct_funcionarios');
    if ($this->table_exists($funcionariosTable) && $this->column_exists($funcionariosTable, 'id_empleado')) {
      $nameCol = $this->detect_first_existing_column($funcionariosTable, ['nombre', 'empleado', 'nombre_empleado']);
      $nameExpr = $nameCol !== ''
        ? "TRIM(COALESCE(`{$nameCol}`, `id_empleado`, 'Sin nombre'))"
        : "TRIM(COALESCE(`id_empleado`, 'Sin nombre'))";

      $activeFilter = $this->column_exists($funcionariosTable, 'activo')
        ? " AND LOWER(TRIM(COALESCE(`activo`, 'si'))) <> 'no'"
        : '';

      $sql = "
        SELECT DISTINCT
          TRIM(COALESCE(`id_empleado`, '')) AS id,
          {$nameExpr} AS label
        FROM `{$funcionariosTable}`
        WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''{$activeFilter}
        ORDER BY label ASC
      ";
      $rows = $this->db->getResults($sql, []);
      foreach ($rows as $row) {
        $fid = trim((string) ($row['id'] ?? ''));
        if ($fid === '') {
          continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
          $label = $fid;
        }
        $funcionarios[] = ['id' => $fid, 'label' => $label . ' (' . $fid . ')'];
      }
    }

    $out = ['estado_admin' => $estadoAdmin, 'funcionarios' => $funcionarios, 'barrios' => $this->get_barrios_filter_options()];
    $this->genericFilterOptionsCache[$cacheKey] = $out;
    return $out;
  }

  /** @return array<int,string> */
  private function get_barrios_filter_options(): array
  {
    $barriosTable = $this->db->table('jet_cct_barrios');
    if ($this->table_exists($barriosTable) && $this->column_exists($barriosTable, 'barrio')) {
      $rows = $this->db->getCol(
        "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio FROM `{$barriosTable}` WHERE TRIM(COALESCE(`barrio`, '')) <> '' ORDER BY barrio ASC LIMIT 500"
      );
      return $this->unique_non_empty_values($rows);
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable) || !$this->column_exists($ticketsTable, 'barrio')) {
      return [];
    }

    $rows = $this->db->getCol(
      "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio FROM `{$ticketsTable}` WHERE TRIM(COALESCE(`barrio`, '')) <> '' ORDER BY barrio ASC LIMIT 500"
    );
    return $this->unique_non_empty_values($rows);
  }

  /** @param array<int,mixed> $items @return array<int,string> */
  private function unique_non_empty_values(array $items): array
  {
    $seen = [];
    $out = [];
    foreach ($items as $item) {
      $value = trim((string) $item);
      if ($value === '') {
        continue;
      }
      $key = strtolower($value);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $value;
    }
    natcasesort($out);
    return array_values($out);
  }

  /** @param array<int,array<string,mixed>> $rows
   *  @return array<int,array<string,mixed>>
   */
  private function attach_historial_rows(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $keys = [];
    foreach ($rows as $row) {
      $pk = (int) ($row['_ID'] ?? 0);
      if ($pk > 0) {
        $keys[(string) $pk] = true;
      }
      $logical = trim((string) ($row['id_ticket'] ?? ''));
      if ($logical !== '') {
        $keys[$logical] = true;
      }
    }
    if (empty($keys)) {
      return $rows;
    }

    $kVals = array_keys($keys);
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    $items = $this->fetch_history_items_by_keys($histTable, $kVals);

    $map = [];
    foreach ($items as $item) {
      $itemKeys = is_array($item['_scm_ticket_keys'] ?? null) ? $item['_scm_ticket_keys'] : [];
      if (empty($itemKeys)) {
        continue;
      }
      foreach ($itemKeys as $k) {
        $map[$k][] = $item;
      }
    }

    foreach ($map as &$list) {
      usort(
        $list,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );
    }
    unset($list);

    foreach ($rows as &$row) {
      $bucket = [];
      $localKeys = [];
      $pk = (int) ($row['_ID'] ?? 0);
      if ($pk > 0) {
        $localKeys[] = (string) $pk;
      }
      $logical = trim((string) ($row['id_ticket'] ?? ''));
      if ($logical !== '') {
        $localKeys[] = $logical;
      }
      $localKeys = array_values(array_unique($localKeys));

      foreach ($localKeys as $k) {
        if (!empty($map[$k])) {
          $bucket = array_merge($bucket, $map[$k]);
        }
      }

      usort(
        $bucket,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $inlineItems = $this->build_ticket_inline_history_items($row);
      foreach ($inlineItems as $inlineItem) {
        $bucket[] = $inlineItem;
      }
      usort(
        $bucket,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $row['_scm_historial_items'] = $bucket;
    }
    unset($row);

    return $rows;
  }

  /** @return array<int,string> */
  private function detect_history_ticket_columns(string $histTable): array
  {
    $cols = [];
    foreach (['id_ticket', 'ticket_id', 'id_tickets', 'tickets_id', 'id_ticket_mantenimiento', 'ticket', 'ticket_pk'] as $candidate) {
      if ($this->column_exists($histTable, $candidate)) {
        $cols[] = $candidate;
      }
    }
    return $cols;
  }

  /**
   * @param array<int,string> $keys
   * @return array<int,array<string,mixed>>
   */
  private function fetch_history_items_by_keys(string $histTable, array $keys): array
  {
    if (empty($keys) || !$this->table_exists($histTable)) {
      return [];
    }

    $ticketCols = $this->detect_history_ticket_columns($histTable);
    if (empty($ticketCols)) {
      return [];
    }

    $wanted = [
      '_ID',
      'fecha',
      'nombre',
      'respuesta',
      'observacion',
      'descripcion',
      'imagen',
      'archivos',
      'id_empleado',
      'cct_author_id',
      'cct_created',
      'cct_modified',
      'id_ticket',
      'id_revision_preventiva',
      'id_revision_correctiva',
      'id_revision_entrega',
      'id_revision_recibo',
      'id_revision_sp',
      'id_revision_servicios_publicos',
      'id_cotizacion_mantenimiento',
      'id_cotizacion_comercial',
      'id_acta_satisfaccion',
      'id_acta_entrega',
      'id_acta_revision',
      'id_acta_revision_notificacion',
      'id_acta_recibo',
      'id_inventario',
      'id_orden',
      'id_contrato',
      'id_hoja_cierre',
      'id_estudio_aseguradora',
      'id_ticket_danos_entrega',
      'id_ticket_danos_recibo',
      'seguimiento_reparaciones',
      'id_inmueble',
    ];
    $selectCols = [];
    foreach ($wanted as $c) {
      if ($this->column_exists($histTable, $c)) {
        $selectCols[] = $c;
      }
    }
    foreach ($ticketCols as $ticketCol) {
      if (!in_array($ticketCol, $selectCols, true)) {
        $selectCols[] = $ticketCol;
      }
    }
    if (empty($selectCols)) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($keys), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $select = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $selectCols));
    $whereParts = [];
    $queryArgs = [];
    foreach ($ticketCols as $ticketCol) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$ticketCol}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($keys as $key) {
        $queryArgs[] = $key;
      }
    }
    $sql = "SELECT {$select} FROM `{$histTable}` WHERE " . implode(' OR ', $whereParts);
    $items = $this->db->getResults($sql, $queryArgs);

    $out = [];
    foreach ($items as $item) {
      $itemKeys = [];
      foreach ($ticketCols as $ticketCol) {
        $key = trim((string) ($item[$ticketCol] ?? ''));
        if ($key !== '') {
          $itemKeys[$key] = true;
        }
      }
      if (empty($itemKeys)) {
        continue;
      }
      $item['_scm_ticket_keys'] = array_keys($itemKeys);
      $item['_scm_ts'] = $this->parse_unix_ts($item['cct_created'] ?? $item['fecha'] ?? $item['cct_modified'] ?? '');
      $out[] = $item;
    }

    return $out;
  }

  /** @param array<string,mixed> $row
   *  @return array<string,mixed>
   */
  /** @return array<int,array<string,mixed>> */
  private function build_ticket_inline_history_items(array $row): array
  {
    $author = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? ''));
    if ($author === '') {
      $author = 'Ticket';
    }

    $ts = $this->parse_unix_ts($row['fecha_actualizacion'] ?? $row['fecha'] ?? '');

    $items = [];
    $add = function (string $text, string $kind) use (&$items, $author, $ts): void {
      $clean = trim($text);
      if ($clean === '') {
        return;
      }
      $items[] = [
        '_ID' => '',
        'nombre' => $author,
        'respuesta' => $clean,
        '_scm_ts' => $ts,
        '_scm_kind' => $kind,
      ];
    };

    $add((string) ($row['respuesta'] ?? $row['observacion'] ?? $row['respuesta_ticket'] ?? $row['detalle_respuesta'] ?? ''), 'respuesta');
    $add((string) ($row['seguimiento_mantenimiento'] ?? ''), 'seguimiento');

    return $items;
  }
  public function render_historial_block($items): string
  {
    return $this->uiView()->renderHistorialBlock($items);
  }

  /**
   * @param array<int,array<string,mixed>> $items
   */
  public function render_record_section(string $title, array $items, string $sectionId = '', array $visibleFields = []): string
  {
    return $this->uiView()->renderRecordSection($title, $items, $sectionId, $visibleFields);
  }

  /**
   * @param array<string,mixed> $item
   * @param array<string,string> $visibleFields
   */
  private function render_record_item_fields(array $item, array $visibleFields): string
  {
    return $this->uiView()->renderRecordItemFields($item, $visibleFields);
  }

  /**
   * @param array<string,mixed> $record
   */
  public function render_single_record_section(string $title, array $record, string $sectionId = ''): string
  {
    return $this->uiView()->renderSingleRecordSection($title, $record, $sectionId);
  }

  /** @return array<string,string> */
  public function get_record_field_map(string $title): array
  {
    $key = strtolower(trim($title));
    if ($key === 'contrato') {
      return [
        'contrato' => 'Contrato',
        'id_contrato' => 'ID contrato',
        'id_inmueble' => 'ID inmueble',
        'estado' => 'Estado',
        'arrendatario' => 'Arrendatario',
        'propietario' => 'Propietario',
        'valor_canon' => 'Canon',
        'valor_administracion' => 'Administracion',
        'tasa_administracion' => 'Tasa administracion',
        'porcentaje_incremento_admin' => 'Porcentaje admin',
        'derechos_inmobiliarios' => 'Derechos inmobiliarios',
        'aseguradora' => 'Aseguradora',
        'numero_solicitud' => 'Numero solicitud',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
      ];
    }
    if ($key === 'inmueble') {
      return [
        'codigo' => 'Codigo',
        'id_inmueble' => 'ID inmueble',
        'direccion' => 'Direccion',
        'barrio' => 'Barrio',
        'ciudad' => 'Ciudad',
        'estado' => 'Estado',
        'tipo_inmueble' => 'Tipo',
        'tipo_negocio' => 'Negocio',
        'destinacion' => 'Destinacion',
        'precio_arriendo' => 'Precio arriendo',
        'precio_venta' => 'Precio venta',
        'precio_admin' => 'Administracion',
        'area_construida' => 'Area construida',
        'area_privada' => 'Area privada',
        'habitaciones' => 'Habitaciones',
        'banos' => 'Banos',
        'parqueaderos' => 'Parqueaderos',
        'estrato' => 'Estrato',
        'copropiedad' => 'Copropiedad',
        'matricula_inmobiliaria' => 'Matricula inmobiliaria',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
      ];
    }
    return [];
  }

  public function format_history_detail_html(string $raw): string
  {
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = wp_kses_post($decoded);
    if (trim($clean) === '') {
      return esc_html($raw);
    }
    return $clean;
  }
  /**
   * @param array<int,array{url:string,label:string}> $buttons
   */
  private function render_case_action_buttons(array $buttons): string
  {
    return $this->uiView()->renderCaseActionButtons($buttons);
  }

  /**
   * @param array<int,array<string,mixed>> $historialItems
   * @return array<int,array{url:string,label:string}>
   */
  private function collect_case_action_buttons(string $ticketUrl, string $prevUrl, string $corrUrl, string $cotzUrl, $historialItems): array
  {
    $buttons = [];
    if ($ticketUrl !== '') {
      $buttons[] = ['url' => $ticketUrl, 'label' => 'Ver ticket'];
    }
    if ($corrUrl !== '') {
      $buttons[] = ['url' => $corrUrl, 'label' => 'Ver revision'];
    } elseif ($prevUrl !== '') {
      $buttons[] = ['url' => $prevUrl, 'label' => 'Ver revision'];
    }
    if ($cotzUrl !== '') {
      $buttons[] = ['url' => $cotzUrl, 'label' => 'Ver cotizacion'];
    }

    $items = is_array($historialItems) ? $historialItems : [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach ($this->extract_history_id_buttons($item) as $btn) {
        $buttons[] = $btn;
      }

      $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
      if ($raw === '') {
        continue;
      }
      foreach ($this->extract_links_from_html($raw) as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));
        if ($label === '' || $url === '' || stripos($label, 'ver ') !== 0) {
          continue;
        }
        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    $unique = [];
    $out = [];
    foreach ($buttons as $btn) {
      $url = trim((string) ($btn['url'] ?? ''));
      $label = trim((string) ($btn['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $item
   * @return array<int,array{url:string,label:string}>
   */
  private function extract_history_id_buttons(array $item): array
  {
    $urlMap = HistoryLinkMap::idButtons();

    $out = [];
    foreach ($urlMap as $field => $meta) {
      $raw = trim((string) ($item[$field] ?? ''));
      if ($raw === '') {
        continue;
      }
      $ids = preg_split('/[,\s;|]+/', $raw);
      if (!is_array($ids)) {
        continue;
      }
      foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '') {
          continue;
        }
        $base = trim((string) ($meta['base'] ?? ''));
        if ($base === '') {
          continue;
        }
        $out[] = [
          'url' => $base . rawurlencode($id),
          'label' => (string) $meta['label'],
        ];
      }
    }

    $estudio = trim((string) ($item['id_estudio_aseguradora'] ?? ''));
    if ($estudio !== '') {
      $ticket = trim((string) ($item['id_ticket'] ?? ''));
      $url = 'https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio=' . rawurlencode($estudio);
      if ($ticket !== '') {
        $url .= '&id_ticket=' . rawurlencode($ticket);
      }
      $out[] = ['url' => $url, 'label' => 'Ver estudio aseguradora'];
    }

    $hojaCierre = trim((string) ($item['id_hoja_cierre'] ?? ''));
    if ($hojaCierre !== '') {
      $url = 'https://sucasainmobiliaria.com.co/hoja-de-cierre/?numero=' . rawurlencode($hojaCierre);
      $extraMap = [
        'id_inmueble' => 'id_inmueble',
        'id_inventario' => 'id_inmueble_data',
        'id_contrato' => 'id_contrato',
        'id_empleado' => 'id_empleado',
      ];
      foreach ($extraMap as $field => $param) {
        $val = trim((string) ($item[$field] ?? ''));
        if ($val !== '') {
          $url .= '&' . $param . '=' . rawurlencode($val);
        }
      }
      $out[] = ['url' => $url, 'label' => 'Ver hoja de cierre'];
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $item
   * @return array<int,array{url:string,label:string}>
   */
  public function build_history_item_buttons(array $item): array
  {
    $buttons = $this->extract_history_id_buttons($item);
    $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
    if ($raw !== '') {
      foreach ($this->extract_links_from_html($raw) as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));
        if ($label === '' || $url === '') {
          continue;
        }
        if (stripos($label, 'ver ') !== 0) {
          $label = 'Ver recurso';
        }
        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    $unique = [];
    $out = [];
    foreach ($buttons as $btn) {
      $url = trim((string) ($btn['url'] ?? ''));
      $label = trim((string) ($btn['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }

    return $out;
  }

  /**
   * @return array<int,array{url:string,label:string}>
   */
  private function extract_links_from_html(string $raw): array
  {
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded === '') {
      return [];
    }

    if (!preg_match_all('/<a\\b[^>]*href=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\\/a>/is', $decoded, $matches, PREG_SET_ORDER)) {
      return [];
    }

    $links = [];
    foreach ($matches as $m) {
      $href = trim((string) ($m[1] ?? ''));
      $labelRaw = trim((string) ($m[2] ?? ''));
      if ($href === '' || $labelRaw === '') {
        continue;
      }
      $label = trim(preg_replace('/\\s+/', ' ', wp_strip_all_tags($labelRaw)));
      if ($label === '') {
        continue;
      }
      $links[] = ['url' => $href, 'label' => $label];
    }

    return $links;
  }

  public function first_id_value(string $raw): string
  {
    $parts = preg_split('/[,\|;]+/', $raw);
    if (!is_array($parts)) {
      return trim($raw);
    }
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        return $id;
      }
    }
    return '';
  }

  public function human_duration_since(int $fromTs): string
  {
    if ($fromTs <= 0) {
      return '-';
    }

    $diff = time() - $fromTs;
    if ($diff < 0) {
      $diff = 0;
    }

    $days = (int) floor($diff / 86400);
    $hours = (int) floor(($diff % 86400) / 3600);
    $mins = (int) floor(($diff % 3600) / 60);

    if ($days > 0) {
      return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
      return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
  }

  public function render_generic_filter_form(string $tabKey, string $prefix, array $p, bool $showTema = false, array $temaOpts = [], array $filterOptions = [], string $baseTabKey = '', string $lockedStatusLabel = ''): string
  {
    return $this->uiView()->renderGenericFilterForm($tabKey, $prefix, $p, $showTema, $temaOpts, $filterOptions, $baseTabKey, $lockedStatusLabel);
  }

  /**
   * @param array<string,int> $pagination
   */
  public function render_generic_pagination(string $tabKey, array $pagination): string
  {
    return $this->uiView()->renderGenericPagination($tabKey, $pagination);
  }

  /**
   * Query directo sobre wp_jet_cct_revision_preventiva.
   * Enriquece con cotizacion via id_ticket → wp_jet_cct_cotizacion_mantenimiento.
   *
   * @param array<string,mixed> $p
   * @return array<string,mixed>
   */
  public function run_query_preventiva(array $p): array
  {
    $tabla    = $this->db->table('jet_cct_revision_preventiva');
    $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');

    $empty = [
      'rows' => [],
      'stats' => ['total' => 0, 'abiertos' => 0, 'cerrados' => 0, 'con_cotizacion' => 0, 'sin_cotizacion' => 0, 'con_revision' => 0, 'sin_revision' => 0],
      'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1],
    ];

    if (!$this->table_exists($tabla)) {
      return $empty;
    }

    $where = [];
    $args  = [];

    if (!empty($p['fBusqueda'])) {
      $likeVal = '%' . $this->db->escapeLike($p['fBusqueda']) . '%';
      $searchParts = [];
      foreach (['inmueble', 'contrato', 'propietario', 'arrendatario', 'direccion', 'id_ticket'] as $col) {
        if ($this->column_exists($tabla, $col)) {
          $searchParts[] = "`{$col}` LIKE ?";
          $args[] = $likeVal;
        }
      }
      if (!empty($searchParts)) {
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
      }
    }

    if (!empty($p['fInmueble'])) {
      $where[] = '`inmueble` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fInmueble']) . '%';
    }
    if (!empty($p['fContrato'])) {
      $where[] = '`contrato` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fContrato']) . '%';
    }
    if (!empty($p['fArrendatario'])) {
      $where[] = '`arrendatario` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fArrendatario']) . '%';
    }
    if (!empty($p['fPropietario'])) {
      $where[] = '`propietario` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fPropietario']) . '%';
    }
    if (!empty($p['fEmpleado'])) {
      $empCols = [];
      foreach (['id_empleado', 'empleado'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $empCols[] = $candidate;
        }
      }
      if (!empty($empCols)) {
        $empWhere = [];
        foreach ($empCols as $empCol) {
          $empWhere[] = "TRIM(COALESCE(`{$empCol}`, '')) = ?";
          $args[]  = $p['fEmpleado'];
        }
        $where[] = '(' . implode(' OR ', $empWhere) . ')';
      }
    }
    if (!empty($p['fEstado'])) {
      $where[] = "LOWER(TRIM(COALESCE(`cct_status`, ''))) = ?";
      $args[]  = strtolower(trim((string) $p['fEstado']));
    }
    if (!empty($p['fFechaDesde'])) {
      $where[] = '`cct_created` >= ?';
      $args[]  = $p['fFechaDesde'] . ' 00:00:00';
    }
    if (!empty($p['fFechaHasta'])) {
      $where[] = '`cct_created` <= ?';
      $args[]  = $p['fFechaHasta'] . ' 23:59:59';
    }

    $whereStr = empty($where) ? '1=1' : implode(' AND ', $where);

    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}", $args);

    $damageVals = ['si', '1', 'true', 'con danos'];
    $damagePhs  = implode(',', array_fill(0, count($damageVals), '?'));
    $conDanosSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}"
      . " AND (LOWER(TRIM(COALESCE(`encontro_danos`,''))) IN ({$damagePhs})"
      . " OR LOWER(TRIM(COALESCE(`tiene_cotizacion`,''))) IN ({$damagePhs}))";
    $conDanosArgs = array_merge($args, $damageVals, $damageVals);
    $conDanos = (int) $this->db->getVar($conDanosSql, $conDanosArgs);

    $enviadaVals = ['si', '1', 'true', 'enviada'];
    $enviadaPhs  = implode(',', array_fill(0, count($enviadaVals), '?'));
    $conEnviadaSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}"
      . " AND LOWER(TRIM(COALESCE(`se_envio`,''))) IN ({$enviadaPhs})";
    $conEnviada = (int) $this->db->getVar($conEnviadaSql, array_merge($args, $enviadaVals));

    $perPage    = max(5, min(100, (int) ($p['fPerPage'] ?? 20)));
    $page       = max(1, (int) ($p['fPage'] ?? 1));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT *,
        `_ID`          AS id_revision_preventiva,
        UNIX_TIMESTAMP(`cct_created`) AS fecha,
        UNIX_TIMESTAMP(`cct_created`) AS fecha_actualizacion,
        `cct_status`   AS estado,
        `cct_status`   AS asunto,
        'Revision Preventiva' AS tema_ayuda,
        CASE
          WHEN LOWER(TRIM(COALESCE(`tiene_cotizacion`,''))) IN ('si','1','true','con danos')
            OR LOWER(TRIM(COALESCE(`encontro_danos`,''))) IN ('si','1','true','con danos')
          THEN 'Si' ELSE COALESCE(`encontro_danos`,'')
        END AS se_encontraron_danos
      FROM `{$tabla}` WHERE {$whereStr}
      ORDER BY `cct_created` DESC LIMIT ? OFFSET ?";
    $rows = $this->db->getResults($sql, array_merge($args, [$perPage, $offset]));

    if (!empty($rows) && $this->table_exists($cotTabla)) {
      $rows = $this->enrich_preventiva_rows_with_cotizacion($rows, $cotTabla);
    } else {
      foreach ($rows as &$row) {
        $row['ticket_cct_created'] = '';
        $row['cita_fecha_inicio'] = '';
        $row['cita_evento_id'] = '';
        $row['id_cita_agendada'] = '';
        $row['prev_id'] = (string) ($row['_ID'] ?? '');
        $row['id_revision_preventiva'] = (string) ($row['_ID'] ?? '');
        $row['prev_fecha_registro'] = '';
        $row['prev_fecha_envio'] = '';
        $row['prev_se_envio'] = '';
        $row['prev_encontro_danos'] = '';
        $row['cot_id'] = '';
        $row['id_cotizacion_mantenimiento'] = '';
        $row['id_cotizacion'] = '';
        $row['cot_fecha_registro'] = '';
        $row['cot_fecha_envio'] = '';
        $row['cot_fecha_respuesta'] = '';
        $row['cot_estado'] = '';
        $row['acta_id'] = '';
        $row['id_acta_satisfaccion'] = '';
        $row['acta_fecha_creada'] = '';
      }
      unset($row);
    }

    $rows = $this->attach_historial_rows($rows);

    return [
      'rows' => $rows,
      'stats' => [
        'total'          => $total,
        'abiertos'       => $total,
        'cerrados'       => 0,
        'con_cotizacion' => $conDanos,
        'sin_cotizacion' => max(0, $total - $conDanos),
        'con_revision'   => $conEnviada,
        'sin_revision'   => max(0, $total - $conEnviada),
      ],
      'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
      ],
    ];
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrich_preventiva_rows_with_cotizacion(array $rows, string $cotTabla): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $calendarTable = 'calendario_actividades';
    $actaTable = $this->db->table('jet_cct_actas_de_satisfaccion');

    foreach ($rows as &$row) {
      $row['ticket_cct_created'] = '';
      $row['cita_fecha_inicio'] = '';
      $row['cita_evento_id'] = '';
      $row['id_cita_agendada'] = '';
      $row['prev_id'] = (string) ($row['_ID'] ?? '');
      $row['id_revision_preventiva'] = (string) ($row['_ID'] ?? '');
      $row['prev_fecha_registro'] = '';
      $row['prev_fecha_envio'] = '';
      $row['prev_se_envio'] = '';
      $row['prev_encontro_danos'] = '';
      $row['cot_id'] = '';
      $row['id_cotizacion_mantenimiento'] = '';
      $row['id_cotizacion'] = '';
      $row['cot_fecha_registro'] = '';
      $row['cot_fecha_envio'] = '';
      $row['cot_fecha_respuesta'] = '';
      $row['cot_estado'] = '';
      $row['acta_id'] = '';
      $row['id_acta_satisfaccion'] = '';
      $row['acta_fecha_creada'] = '';
    }
    unset($row);

    $ticketIds = [];
    foreach ($rows as $row) {
      $tid = trim((string) ($row['id_ticket'] ?? ''));
      if ($tid !== '') {
        $ticketIds[$tid] = true;
      }
    }
    if (empty($ticketIds)) {
      return $rows;
    }

    $ids = array_keys($ticketIds);
    $phs = implode(',', array_fill(0, count($ids), '?'));

    $ticketMap = [];
    if ($this->table_exists($ticketsTable)) {
      $ticketRows = $this->db->getResults(
        "SELECT `_ID`, `cct_created`, `id_empleado`, `empleado`, `nombre_empleado` FROM `{$ticketsTable}` WHERE `_ID` IN ({$phs})",
        $ids
      );
      foreach ($ticketRows as $tRow) {
        $tid = trim((string) ($tRow['_ID'] ?? ''));
        if ($tid !== '') {
          $ticketMap[$tid] = $tRow;
        }
      }
    }

    $calendarMap = [];
    if ($this->table_exists($calendarTable) && $this->column_exists($calendarTable, 'id_ticket')) {
      $calendarRows = $this->db->getResults(
        "SELECT `id`, `id_ticket`, `fecha_inicio` FROM `{$calendarTable}` WHERE `id_ticket` IN ({$phs}) ORDER BY `id` DESC",
        $ids
      );
      foreach ($calendarRows as $calRow) {
        $tid = trim((string) ($calRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($calendarMap[$tid])) {
          $calendarMap[$tid] = $calRow;
        }
      }
    }

    $cotMap = [];
    if ($this->table_exists($cotTabla) && $this->column_exists($cotTabla, 'id_ticket')) {
      $cotRows = $this->db->getResults(
        "SELECT `_ID`, `id_ticket`, `cct_created`, `fecha_envio`, `fecha_respuesta`, `estado`, `se_envio`
         FROM `{$cotTabla}`
         WHERE `id_ticket` IN ({$phs})
         ORDER BY `_ID` DESC",
        $ids
      );
      foreach ($cotRows as $cRow) {
        $tid = trim((string) ($cRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($cotMap[$tid])) {
          $cotMap[$tid] = $cRow;
        }
      }
    }

    $actaMap = [];
    if ($this->table_exists($actaTable) && $this->column_exists($actaTable, 'id_ticket')) {
      $actaRows = $this->db->getResults(
        "SELECT `_ID`, `id_ticket`, `cct_created`
         FROM `{$actaTable}`
         WHERE `id_ticket` IN ({$phs})
         ORDER BY `_ID` DESC",
        $ids
      );
      foreach ($actaRows as $aRow) {
        $tid = trim((string) ($aRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($actaMap[$tid])) {
          $actaMap[$tid] = $aRow;
        }
      }
    }

    foreach ($rows as &$row) {
      $tid = trim((string) ($row['id_ticket'] ?? ''));
      if ($tid === '') {
        continue;
      }

      if (isset($ticketMap[$tid])) {
        $ts = $this->parse_unix_ts((string) ($ticketMap[$tid]['cct_created'] ?? ''));
        if ($ts > 0) {
          $row['ticket_cct_created'] = (string) $ts;
        }

        // Mantener el mismo comportamiento visual de Asignado a que en mantenimiento.
        if (trim((string) ($row['id_empleado'] ?? '')) === '') {
          $row['id_empleado'] = trim((string) ($ticketMap[$tid]['id_empleado'] ?? ''));
        }
        if (trim((string) ($row['empleado'] ?? '')) === '') {
          $row['empleado'] = trim((string) ($ticketMap[$tid]['empleado'] ?? ''));
        }
        if (trim((string) ($row['nombre_empleado'] ?? '')) === '') {
          $row['nombre_empleado'] = trim((string) ($ticketMap[$tid]['nombre_empleado'] ?? ''));
        }
      }

      $prevRegTs = $this->parse_unix_ts((string) ($row['cct_created'] ?? ''));
      if ($prevRegTs > 0) {
        $row['prev_fecha_registro'] = (string) $prevRegTs;
      }

      $prevEnvTs = $this->parse_unix_ts((string) ($row['fecha_envio'] ?? ''));
      if ($prevEnvTs > 0) {
        $row['prev_fecha_envio'] = (string) $prevEnvTs;
      }

      $row['prev_se_envio'] = trim((string) ($row['se_envio'] ?? ''));
      $row['prev_encontro_danos'] = trim((string) ($row['encontro_danos'] ?? ''));

      if (isset($calendarMap[$tid])) {
        $calendar = $calendarMap[$tid];
        $row['cita_evento_id'] = trim((string) ($calendar['id'] ?? ''));
        $row['id_cita_agendada'] = $row['cita_evento_id'];
        $ts = $this->parse_unix_ts((string) ($calendar['fecha_inicio'] ?? ''));
        if ($ts > 0) {
          $row['cita_fecha_inicio'] = (string) $ts;
        }
      }

      if (isset($cotMap[$tid])) {
        $cot = $cotMap[$tid];
        $row['cot_id'] = trim((string) ($cot['_ID'] ?? ''));
        $row['id_cotizacion_mantenimiento'] = $row['cot_id'];
        $row['id_cotizacion'] = $row['cot_id'];
        $row['cot_estado'] = trim((string) ($cot['estado'] ?? ''));

        $ts = $this->parse_unix_ts((string) ($cot['cct_created'] ?? ''));
        if ($ts > 0) {
          $row['cot_fecha_registro'] = (string) $ts;
        }

        $seEnvio = strtolower(trim((string) ($cot['se_envio'] ?? '')));
        if (in_array($seEnvio, ['si', 's�', '1', 'true'], true)) {
          $ts = $this->parse_unix_ts((string) ($cot['fecha_envio'] ?? ''));
          if ($ts > 0) {
            $row['cot_fecha_envio'] = (string) $ts;
          }
        }

        $ts = $this->parse_unix_ts((string) ($cot['fecha_respuesta'] ?? ''));
        if ($ts > 0) {
          $row['cot_fecha_respuesta'] = (string) $ts;
        }
      }

      if (isset($actaMap[$tid])) {
        $acta = $actaMap[$tid];
        $row['acta_id'] = trim((string) ($acta['_ID'] ?? ''));
        $ts = $this->parse_unix_ts((string) ($acta['cct_created'] ?? ''));
        $row['id_acta_satisfaccion'] = $row['acta_id'];
        if ($ts > 0) {
          $row['acta_fecha_creada'] = (string) $ts;
        }
      }
    }
    unset($row);

    return $rows;
  }
}
