<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait RendersPublicPqr
{
  /** @return array<int,string> */
  private function get_public_pqr_themes(): array
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
  }

  private function get_public_pqr_department_for_theme(string $theme): string
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getDepartmentForTheme($theme);
  }

  /** @return array<int,string> */
  private function get_public_pqr_department_options(): array
  {
    return [
      'Servicio al propietario',
      'Servicio al arrendatario',
      'Servicio a la copropiedad',
      'Servicio al cliente',
    ];
  }

  /** @return array<string,array<int,string>> */
  private function get_public_pqr_corresponsables(): array
  {
    return \SCM\Modules\PublicTickets\PublicTicketsService::getCorresponsableAssignments();
  }

  /** @return array<int,string> */
  private function flatten_public_pqr_corresponsable_ids(array $value): array
  {
    $ids = [];
    foreach ($value as $entry) {
      if (is_array($entry)) {
        foreach ($this->flatten_public_pqr_corresponsable_ids($entry) as $nestedId) {
          if ($nestedId !== '' && !in_array($nestedId, $ids, true)) {
            $ids[] = $nestedId;
          }
        }
        continue;
      }

      $id = trim((string) $entry);
      if ($id !== '' && !in_array($id, $ids, true)) {
        $ids[] = $id;
      }
    }

    return $ids;
  }

  /** @return array<int,string> */
  private function public_pqr_corresponsable_scope_ids(array $value, string $scope): array
  {
    $scope = mb_strtolower(trim($scope), 'UTF-8');
    if ($scope === '') {
      return [];
    }

    $keys = array_keys($value);
    $isList = $value === [] || $keys === range(0, count($value) - 1);
    if ($isList) {
      return $scope === 'default' ? $this->flatten_public_pqr_corresponsable_ids($value) : [];
    }

    $scopeValue = $value[$scope] ?? [];
    if (!is_array($scopeValue)) {
      $scopeValue = [$scopeValue];
    }

    return $this->flatten_public_pqr_corresponsable_ids($scopeValue);
  }

  /** @return array<int,array{id:string,label:string}> */
  private function get_public_pqr_corresponsable_candidates(): array
  {
    $funcTable = $this->db->table('jet_cct_funcionarios');
    if (!$this->table_exists($funcTable) || !$this->column_exists($funcTable, 'id_empleado')) {
      return [];
    }

    $nameCol = $this->column_exists($funcTable, 'nombre') ? 'nombre' : ($this->column_exists($funcTable, 'empleado') ? 'empleado' : 'id_empleado');
    $activeFilter = $this->column_exists($funcTable, 'activo')
      ? " AND LOWER(TRIM(COALESCE(`activo`, 'si'))) <> 'no'"
      : '';

    $sql = "SELECT
              TRIM(COALESCE(`id_empleado`, '')) AS id,
              TRIM(COALESCE(`{$nameCol}`, `id_empleado`, 'Sin nombre')) AS nombre,
              TRIM(COALESCE(`id_cargo`, '')) AS id_cargo
            FROM `{$funcTable}`
            WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''
              {$activeFilter}
            ORDER BY nombre ASC";

    $rows = $this->db->getResults($sql, []);
    $out = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $nombre = trim((string) ($row['nombre'] ?? ''));
      if ($nombre === '') {
        $nombre = $id;
      }
      $out[] = [
        'id' => $id,
        'label' => $nombre . ' (' . $id . ')',
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $input @return array{estado:string,empleado:string} */
  private function get_public_pqr_filters_from_input(array $input): array
  {
    $estado = trim((string) ($input['public_pqr_estado'] ?? ''));
    $empleado = trim((string) ($input['public_pqr_empleado'] ?? ''));

    $estado = sanitize_text_field($estado);
    $empleado = preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field($empleado));
    if (!is_string($empleado)) {
      $empleado = '';
    }

    return [
      'estado' => $estado,
      'empleado' => $empleado,
    ];
  }

  /** @return array{estado:string,empleado:string} */
  private function get_public_pqr_filters_from_request(): array
  {
    return $this->get_public_pqr_filters_from_input($_GET);
  }

  /** @return array<int,array<string,mixed>> */
  private function fetch_public_pqr_rows(int $limit = 160, string $employeeIdFilter = '', array $filters = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable)) {
      return [];
    }

    $limit = max(20, min(500, $limit));
    $creatorLabels = ['propietario', 'arrendatario', 'copropiedad', 'cliente'];
    $medioLabels = ['portal propietario', 'portal arrendatario', 'portal copropiedad', 'whatsapp cliente'];

    $selectCreadoPor = $this->column_exists($ticketsTable, 'creado_por')
      ? "TRIM(COALESCE(`creado_por`, '')) AS creado_por"
      : "'' AS creado_por";
    $selectCreadorPor = $this->column_exists($ticketsTable, 'creador_por')
      ? "TRIM(COALESCE(`creador_por`, '')) AS creador_por"
      : "'' AS creador_por";
    $hasTemaAyuda = $this->column_exists($ticketsTable, 'tema_ayuda');
    $hasTipoPqrs = $this->column_exists($ticketsTable, 'tipo_pqrs');
    if ($hasTemaAyuda && $hasTipoPqrs) {
      $selectTipoPqrs = "TRIM(COALESCE(`tema_ayuda`, `tipo_pqrs`, '')) AS tipo_pqrs";
    } elseif ($hasTemaAyuda) {
      $selectTipoPqrs = "TRIM(COALESCE(`tema_ayuda`, '')) AS tipo_pqrs";
    } else {
      $selectTipoPqrs = "TRIM(COALESCE(`tipo_pqrs`, '')) AS tipo_pqrs";
    }

    $wherePublic = [];
    $args = [];
    $creatorPh = implode(',', array_fill(0, count($creatorLabels), '?'));
    if ($this->column_exists($ticketsTable, 'creado_por')) {
      $wherePublic[] = "LOWER(TRIM(COALESCE(`creado_por`, ''))) IN ({$creatorPh})";
      $args = array_merge($args, $creatorLabels);
    }
    if ($this->column_exists($ticketsTable, 'creador_por')) {
      $wherePublic[] = "LOWER(TRIM(COALESCE(`creador_por`, ''))) IN ({$creatorPh})";
      $args = array_merge($args, $creatorLabels);
    }
    $medioPh = implode(',', array_fill(0, count($medioLabels), '?'));
    $wherePublic[] = "LOWER(TRIM(COALESCE(`medio`, ''))) IN ({$medioPh})";
    $args = array_merge($args, $medioLabels);
    $wherePublic[] = "LOWER(TRIM(COALESCE(`departamento`, ''))) = 'servicio al cliente'";

    $whereSql = '(' . implode(' OR ', $wherePublic) . ')';
    if ($this->column_exists($ticketsTable, 'creador_por')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`creador_por`, ''))) <> 'funcionario'";
    }
    if ($this->column_exists($ticketsTable, 'estado')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) <> 'cerrado'";
    }
    $employeeIdFilter = trim($employeeIdFilter);
    if ($employeeIdFilter !== '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $employeeIdFilter;
    }
    $estadoFilter = strtolower(trim((string) ($filters['estado'] ?? '')));
    if ($estadoFilter !== '' && $this->column_exists($ticketsTable, 'estado')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) = ?";
      $args[] = $estadoFilter;
    }
    $empleadoFilter = trim((string) ($filters['empleado'] ?? ''));
    if ($empleadoFilter !== '' && $employeeIdFilter === '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $empleadoFilter;
    }
    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`asunto`, '')) AS asunto,
              TRIM(COALESCE(`solicitante`, '')) AS solicitante,
              TRIM(COALESCE(`correo_solicitante`, '')) AS correo_solicitante,
              TRIM(COALESCE(`celular_solicitante`, '')) AS celular_solicitante,
              {$selectTipoPqrs},
              TRIM(COALESCE(`departamento`, '')) AS departamento,
              TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              TRIM(COALESCE(`empleado`, '')) AS empleado,
              TRIM(COALESCE(`estado`, '')) AS estado,
              TRIM(COALESCE(`medio`, '')) AS medio,
              COALESCE(`fecha_actualizacion`, `fecha`, 0) AS fecha_ref,
              {$selectCreadoPor},
              {$selectCreadorPor}
            FROM `{$ticketsTable}`
            WHERE {$whereSql}
            ORDER BY `_ID` DESC
            LIMIT {$limit}";

    return $this->db->getResults($sql, $args);
  }

  /** @param array<int,array<string,mixed>> $rows @param array<int,mixed> $funcionarios */
  private function render_public_pqr_tab(array $rows, array $funcionarios, string $ticketUrl = '', string $employeeIdFilter = '', bool $readOnly = false, array $publicAccess = [], array $filters = []): string
  {
    $themes = $readOnly ? [] : $this->get_public_pqr_themes();
    $corresponsables = $readOnly ? [] : $this->get_public_pqr_corresponsables();
    $corresponsableCandidates = $readOnly ? [] : $this->get_public_pqr_corresponsable_candidates();
    $publicToken = trim((string) ($publicAccess['token'] ?? ''));
    $publicEmployeeId = trim((string) ($publicAccess['id_empleado'] ?? ''));
    $publicExp = trim((string) ($publicAccess['exp'] ?? ''));
    $publicSig = trim((string) ($publicAccess['sig'] ?? ''));
    $publicScope = mb_strtolower(trim((string) ($publicAccess['scope'] ?? 'assigned')), 'UTF-8');
    if ($publicScope !== 'all') {
      $publicScope = 'assigned';
    }
    $isSignedPublicAccess = $publicEmployeeId !== '' && ($publicToken !== '' || ($publicExp !== '' && $publicSig !== ''));
    $isGlobalSignedAccess = $isSignedPublicAccess && $publicScope === 'all';

    $allowedCorrespCargos = ['11', '12', '13', '14'];
    $isAdmin = !$readOnly && !$isSignedPublicAccess && in_array(Auth::userCargo(), $allowedCorrespCargos, true);

    // ── Build settings modal HTML (only for allowed cargos) ──────────────────
    $settingsModalHtml = '';
    if ($isAdmin) {
      $currentNotifIds = \SCM\Modules\PublicTickets\PublicTicketsService::getNotifResponsables();
      $selectedNotifMap = [];
      foreach ($currentNotifIds as $notifId) {
        $notifId = trim((string) $notifId);
        if ($notifId !== '') {
          $selectedNotifMap[$notifId] = true;
        }
      }
      $notifOptions = '';
      foreach ($corresponsableCandidates as $func) {
        $id = trim((string) ($func['id'] ?? ''));
        if ($id === '') {
          continue;
        }
        $label = trim((string) ($func['label'] ?? $id));
        $sel = isset($selectedNotifMap[$id]) ? ' selected' : '';
        $notifOptions .= '<option value="' . self::h($id) . '"' . $sel . '>' . self::h($label) . '</option>';
      }

      $correspGridHtml = '';
      foreach ($themes as $theme) {
        $assignmentValue = is_array($corresponsables[$theme] ?? null) ? $corresponsables[$theme] : [];

        $buildCorrespOptions = function (array $selectedIds) use ($corresponsableCandidates): string {
          $selectedCorresponsables = [];
          foreach ($selectedIds as $idSaved) {
            $idSaved = trim((string) $idSaved);
            if ($idSaved !== '') {
              $selectedCorresponsables[$idSaved] = true;
            }
          }

          $options = '';
          foreach ($corresponsableCandidates as $func) {
            $id = trim((string) ($func['id'] ?? ''));
            if ($id === '') {
              continue;
            }
            $label = trim((string) ($func['label'] ?? $id));
            $selected = isset($selectedCorresponsables[$id]) ? ' selected' : '';
            $options .= '<option value="' . self::h($id) . '"' . $selected . '>' . self::h($label) . '</option>';
          }

          return $options;
        };

        $generalOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'default'));
        $ownerOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'propietario'));
        $tenantOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'arrendatario'));
        $coproOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'copropiedad'));
        $clientOptions = $buildCorrespOptions($this->public_pqr_corresponsable_scope_ids($assignmentValue, 'cliente'));

        $correspGridHtml .= '<form class="scm-public-pqr-corresponsable-form scm-pqr-config-form" method="post" autocomplete="off" style="display:flex;flex-direction:column;gap:6px;background:#f8fbff;border:1px solid #e5edf7;border-radius:8px;padding:8px;">';
        $correspGridHtml .= '<input type="hidden" name="tema_ayuda" value="' . self::h((string) $theme) . '">';
        $correspGridHtml .= '<label style="font-size:12px;font-weight:600;color:#33465d;">' . self::h((string) $theme) . '</label>';
        $correspGridHtml .= '<small style="color:#5d728a;">General aplica cuando no hay responsable especifico por actor.</small>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;">General</label>';
        $correspGridHtml .= '<select name="corresponsable_ids[]" class="select select-bordered select-sm scm-select" multiple size="4">' . $generalOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Propietario</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[propietario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $ownerOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Arrendatario</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[arrendatario][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $tenantOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Copropiedad</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[copropiedad][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $coproOptions . '</select>';
        $correspGridHtml .= '<label style="font-size:11px;font-weight:600;color:#4c6077;margin-top:4px;">Cliente</label>';
        $correspGridHtml .= '<select name="corresponsable_actor[cliente][]" class="select select-bordered select-sm scm-select" multiple size="4">' . $clientOptions . '</select>';
        $correspGridHtml .= '<small style="color:#5d728a;">Ctrl/Command + click para seleccionar varios o quitar seleccion.</small>';
        $correspGridHtml .= '<div style="display:flex;align-items:center;gap:8px;">';
        $correspGridHtml .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button>';
        $correspGridHtml .= '<small class="scm-public-pqr-corresponsable-msg" aria-live="polite"></small>';
        $correspGridHtml .= '</div>';
        $correspGridHtml .= '</form>';
      }

      if (empty($corresponsableCandidates)) {
        $correspGridHtml = '<p style="margin:0 0 8px;color:#a95b26;font-size:12px;">No hay funcionarios disponibles para configurar.</p>';
      }

      $settingsModalHtml  = '<div id="scm-pqr-settings-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);overflow-y:auto;padding:18px 10px;align-items:flex-start;justify-content:center;" onclick="if(event.target===this)this.style.display=\'none\'">';
      $settingsModalHtml .= '<div style="background:#fff;border-radius:14px;max-width:760px;width:95%;max-height:calc(100vh - 36px);overflow:auto;margin:0;padding:24px 20px 20px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18);">';
      $settingsModalHtml .= '<button type="button" onclick="document.getElementById(\'scm-pqr-settings-modal\').style.display=\'none\'" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:#4c6077;" aria-label="Cerrar">&times;</button>';
      $settingsModalHtml .= '<h3 style="margin:0 0 18px;font-size:16px;font-weight:700;color:#1a2d42;">Configuracion de Solicitudes Web</h3>';
      // Notif responsable
      $settingsModalHtml .= '<div style="border:1px solid #dbe6f1;border-radius:10px;padding:14px;margin:0 0 16px;">';
      $settingsModalHtml .= '<h4 style="margin:0 0 6px;font-size:13px;font-weight:700;">Funcionario que recibe notificaciones de nuevas Solicitudes</h4>';
      $settingsModalHtml .= '<p style="margin:0 0 8px;color:#5d728a;font-size:12px;">Recibira WhatsApp y correo cada vez que se cree una solicitud desde el portal. Puedes seleccionar varios funcionarios.</p>';
      $settingsModalHtml .= '<form class="scm-notif-responsable-form scm-pqr-config-form" method="post" autocomplete="off" style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">';
      $settingsModalHtml .= '<div style="flex:1 1 320px;min-width:260px;">';
      $settingsModalHtml .= '<select name="notif_responsable_ids[]" class="select select-bordered select-sm scm-select" style="min-width:220px;" multiple size="6">' . $notifOptions . '</select>';
      $settingsModalHtml .= '<small style="display:block;margin-top:4px;color:#5d728a;">Ctrl/Command + click para seleccionar o quitar varios funcionarios.</small>';
      $settingsModalHtml .= '</div>';
      $settingsModalHtml .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Guardar</button>';
      $settingsModalHtml .= '<small class="scm-notif-responsable-msg" aria-live="polite"></small>';
      $settingsModalHtml .= '</form>';
      $settingsModalHtml .= '</div>';
      // Corresponsables por tipo
      $settingsModalHtml .= '<div style="border:1px solid #dbe6f1;border-radius:10px;padding:14px;">';
      $settingsModalHtml .= '<h4 style="margin:0 0 4px;font-size:13px;font-weight:700;">Corresponsable por tipo de Solicitud/Peticion</h4>';
      $settingsModalHtml .= '<p style="margin:0 0 10px;color:#5d728a;font-size:12px;">Puedes seleccionar varios corresponsables por tipo.</p>';
      $settingsModalHtml .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;max-height:60vh;overflow:auto;padding-right:4px;">' . $correspGridHtml . '</div>';
      $settingsModalHtml .= '</div>';
      $settingsModalHtml .= '</div></div>';
    }

    $html = '<div class="scm-filter-card card">';
    // Header row with title + settings button
    $html .= '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">';
    $html .= '<h3 style="margin:0;">Solicitudes Web</h3>';
    if ($isAdmin) {
      $html .= '<button type="button" onclick="document.getElementById(\'scm-pqr-settings-modal\').style.display=\'flex\'" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:5px;"><svg xmlns=\'http://www.w3.org/2000/svg\' width=\'14\' height=\'14\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><circle cx=\'12\' cy=\'12\' r=\'3\'></circle><path d=\'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z\'></path></svg> Configuracion</button>';
    }
    $html .= '</div>';
    $html .= '<p style="margin:0 0 10px;color:#4c6077;">' . ($readOnly ? 'Consulta publica de solicitudes creadas desde los portales publicos.' : ($isGlobalSignedAccess ? 'Este acceso corporativo puede revisar y trasladar todas las solicitudes web.' : ($isSignedPublicAccess ? 'Este acceso corporativo puede revisar y trasladar las solicitudes asignadas a tu ID.' : 'Gestiona las solicitudes creadas por Propietario, Arrendatario, Copropiedad y Cliente.'))) . '</p>';
    $kpiTotal = count($rows);
    $kpiNuevo = 0;
    $kpiEnProceso = 0;
    $filterFuncionarios = [];
    foreach ($rows as $kpiRow) {
      $estadoKpi = mb_strtolower(trim((string) ($kpiRow['estado'] ?? '')), 'UTF-8');
      if ($estadoKpi === 'nuevo') {
        $kpiNuevo++;
      } elseif ($estadoKpi === 'en proceso') {
        $kpiEnProceso++;
      }
      $filterEmpleadoId = trim((string) ($kpiRow['id_empleado'] ?? ''));
      if ($filterEmpleadoId !== '') {
        $filterEmpleadoLabel = trim((string) ($kpiRow['empleado'] ?? ''));
        if ($filterEmpleadoLabel === '') {
          $filterEmpleadoLabel = 'ID ' . $filterEmpleadoId;
        }
        $filterFuncionarios[$filterEmpleadoId] = [
          'id' => $filterEmpleadoId,
          'label' => $filterEmpleadoLabel . ' (' . $filterEmpleadoId . ')',
        ];
      }
    }
    if (!empty($filterFuncionarios)) {
      uasort($filterFuncionarios, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
      });
    }
    $html .= '<div class="scm-kpis scm-kpis-daisy scm-public-pqr-kpis">';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">Total</div><div class="scm-kpi-value">' . self::h((string) $kpiTotal) . '</div></div>';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">Nuevo</div><div class="scm-kpi-value">' . self::h((string) $kpiNuevo) . '</div></div>';
    $html .= '<div class="scm-kpi"><div class="scm-kpi-label">En proceso</div><div class="scm-kpi-value">' . self::h((string) $kpiEnProceso) . '</div></div>';
    $html .= '</div>';
    $currentEstado = trim((string) ($filters['estado'] ?? ''));
    $currentEmpleado = trim((string) ($filters['empleado'] ?? ''));
    $showEmployeeFilter = !$readOnly || $isGlobalSignedAccess;
    if ($employeeIdFilter !== '' && ($readOnly || $isSignedPublicAccess)) {
      $showEmployeeFilter = false;
    }
    $clearQuery = ['scm_tab' => 'scm-panel-pqr-publico'];
    if ($isSignedPublicAccess && $publicToken !== '') {
      $clearQuery['k'] = $publicToken;
    } elseif ($isSignedPublicAccess) {
      $clearQuery['id_empleado'] = $publicEmployeeId;
      $clearQuery['scope'] = $publicScope;
      $clearQuery['exp'] = $publicExp;
      $clearQuery['sig'] = $publicSig;
    } elseif ($employeeIdFilter !== '') {
      $clearQuery['id_empleado'] = $employeeIdFilter;
    }
    $clearUrl = remove_query_arg(['public_pqr_estado', 'public_pqr_empleado', 'scm_tab', 'tab', 'k', 'scope', 'exp', 'sig', 'id_empleado']);
    $clearUrl = add_query_arg($clearQuery, $clearUrl);
    $html .= '<form method="get" autocomplete="off" class="scm-public-pqr-filters scm-public-pqr-filter-form">';
    if ($isSignedPublicAccess && $publicToken !== '') {
      $html .= '<input type="hidden" name="k" value="' . self::h($publicToken) . '">';
    } elseif ($isSignedPublicAccess) {
      $html .= '<input type="hidden" name="id_empleado" value="' . self::h($publicEmployeeId) . '">';
      $html .= '<input type="hidden" name="scope" value="' . self::h($publicScope) . '">';
      $html .= '<input type="hidden" name="exp" value="' . self::h($publicExp) . '">';
      $html .= '<input type="hidden" name="sig" value="' . self::h($publicSig) . '">';
    } elseif ($employeeIdFilter !== '') {
      $html .= '<input type="hidden" name="id_empleado" value="' . self::h($employeeIdFilter) . '">';
    }
    $html .= '<input type="hidden" name="scm_tab" value="scm-panel-pqr-publico">';
    $html .= '<div class="scm-public-pqr-filter-field"><label>Estado</label>';
    $html .= '<select name="public_pqr_estado" class="select select-bordered select-sm scm-select">';
    $html .= '<option value="">Todos</option>';
    foreach (['Nuevo', 'En proceso'] as $estadoOption) {
      $selected = strtolower($currentEstado) === strtolower($estadoOption) ? ' selected' : '';
      $html .= '<option value="' . self::h($estadoOption) . '"' . $selected . '>' . self::h($estadoOption) . '</option>';
    }
    $html .= '</select></div>';
    if ($showEmployeeFilter) {
      $html .= '<div class="scm-public-pqr-filter-field scm-public-pqr-filter-field-wide"><label>Funcionario</label>';
      $html .= '<select name="public_pqr_empleado" class="select select-bordered select-sm scm-select">';
      $html .= '<option value="">Todos</option>';
      foreach ($filterFuncionarios as $func) {
        $fId = trim((string) ($func['id'] ?? ''));
        if ($fId === '') {
          continue;
        }
        $selected = $currentEmpleado === $fId ? ' selected' : '';
        $html .= '<option value="' . self::h($fId) . '"' . $selected . '>' . self::h((string) ($func['label'] ?? $fId)) . '</option>';
      }
      $html .= '</select></div>';
    }
    $html .= '<div class="scm-public-pqr-filter-actions">';
    $html .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Filtrar</button>';
    $html .= '<a href="' . self::h($clearUrl) . '" class="btn btn-sm">Limpiar</a>';
    $html .= '</div>';
    $html .= '</form>';
    if (empty($rows)) {
      $html .= '<p style="margin:0;color:#4c6077;">' . ($readOnly ? 'No hay solicitudes web disponibles para este acceso.' : 'No hay solicitudes creadas desde portales publicos para gestionar.') . '</p>';
      $html .= '</div>';
      return $html . $settingsModalHtml;
    }

    $html .= '<div class="result-wrap"><table class="scm-go-table"><thead><tr>';
    $html .= '<th>ID</th><th>Fecha</th><th>Creado por</th><th>Solicitante</th><th>Asunto</th><th>Tipo Solicitud/Peticion</th><th>Estado</th><th>Departamento</th><th>Funcionario</th><th>' . ($readOnly ? 'Detalle' : 'Accion') . '</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk <= 0) {
        continue;
      }
      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId === '') {
        $logicalId = (string) $ticketPk;
      }
      $tipoActual = trim((string) ($row['tema_ayuda'] ?? $row['tipo_pqrs'] ?? ''));
      $deptoActual = trim((string) ($row['departamento'] ?? ''));
      $empActual = trim((string) ($row['empleado'] ?? ''));
      $empIdActual = trim((string) ($row['id_empleado'] ?? ''));
      if ($empActual === '' && $empIdActual !== '') {
        $empActual = 'ID ' . $empIdActual;
      }

      $creadoPor = trim((string) ($row['creado_por'] ?? ''));
      if ($creadoPor === '') {
        $creadoPor = trim((string) ($row['creador_por'] ?? ''));
      }
      if ($creadoPor === '') {
        $medio = mb_strtolower(trim((string) ($row['medio'] ?? '')), 'UTF-8');
        if (strpos($medio, 'propietario') !== false) {
          $creadoPor = 'Propietario';
        } elseif (strpos($medio, 'arrendatario') !== false) {
          $creadoPor = 'Arrendatario';
        } elseif (strpos($medio, 'copropiedad') !== false) {
          $creadoPor = 'Copropiedad';
        } elseif (strpos($medio, 'cliente') !== false) {
          $creadoPor = 'Cliente';
        }
      }

      $fechaRef = $this->parse_unix_ts($row['fecha_ref'] ?? 0);
      $fechaTxt = $this->format_date_time($fechaRef);
      if ($fechaTxt === '') {
        $fechaTxt = '-';
      }
      $estadoActual = trim((string) ($row['estado'] ?? ''));

      $tipoOptsRow = '';
      $deptoOptsRow = '';
      $empOptsRow = '';
      if (!$readOnly) {
        foreach ($themes as $theme) {
          $selected = mb_strtolower($theme, 'UTF-8') === mb_strtolower($tipoActual, 'UTF-8') ? ' selected' : '';
          $tipoOptsRow .= '<option value="' . self::h((string) $theme) . '"' . $selected . '>' . self::h((string) $theme) . '</option>';
        }

        foreach ($this->get_public_pqr_department_options() as $departmentOption) {
          $selectedDept = mb_strtolower($departmentOption, 'UTF-8') === mb_strtolower($deptoActual, 'UTF-8') ? ' selected' : '';
          $deptoOptsRow .= '<option value="' . self::h((string) $departmentOption) . '"' . $selectedDept . '>' . self::h((string) $departmentOption) . '</option>';
        }

        $empOptsRow = '<option value="">Sin cambio de responsable</option>';
        foreach ($funcionarios as $func) {
          $id = trim((string) ($func['id'] ?? ''));
          if ($id === '') {
            continue;
          }
          $label = trim((string) ($func['label'] ?? $id));
          $selected = $id === $empIdActual ? ' selected' : '';
          $empOptsRow .= '<option value="' . self::h($id) . '"' . $selected . '>' . self::h($label) . '</option>';
        }
      }

      $html .= '<tr data-pqr-row="' . self::h((string) $ticketPk) . '">';
      $html .= '<td>#' . self::h($logicalId) . '</td>';
      $html .= '<td>' . self::h($fechaTxt) . '</td>';
      $html .= '<td>' . self::h($creadoPor !== '' ? $creadoPor : '-') . '</td>';
      $html .= '<td>' . self::h(trim((string) ($row['solicitante'] ?? ''))) . '<br><small>' . self::h(trim((string) ($row['celular_solicitante'] ?? ''))) . '</small></td>';
      $html .= '<td>' . self::h(trim((string) ($row['asunto'] ?? ''))) . '</td>';
      $html .= '<td><span class="scm-public-pqr-current-topic">' . self::h($tipoActual !== '' ? $tipoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-state">' . $this->estado_badge($estadoActual !== '' ? $estadoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-department">' . self::h($deptoActual !== '' ? $deptoActual : '-') . '</span></td>';
      $html .= '<td><span class="scm-public-pqr-current-employee">' . self::h($empActual !== '' ? $empActual : '-') . '</span></td>';
      $html .= '<td class="scm-public-pqr-action-cell">';
      $html .= '<div class="scm-public-pqr-actions">';
      if ($ticketUrl !== '' && $logicalId !== '') {
        if ($readOnly) {
          $html .= '<a href="' . self::h($ticketUrl . rawurlencode($logicalId)) . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm scm-public-pqr-view-btn">Ver solicitud</a>';
        } else {
          $html .= '<button type="button" data-scm-open-iframe data-no-emp-check data-iframe-url="' . self::h($ticketUrl . rawurlencode($logicalId)) . '" data-iframe-title="Solicitud #' . self::h($logicalId) . '" class="btn btn-sm scm-public-pqr-view-btn">Ver solicitud</button>';
        }
      }
      if (!$readOnly) {
        $html .= '<button type="button" class="scm-btn-primary btn btn-primary btn-sm scm-public-pqr-transfer-btn" data-scm-open-pqr-transfer data-ticket-pk="' . self::h((string) $ticketPk) . '" data-ticket-logical="' . self::h($logicalId) . '">Trasladar</button>';
        $html .= '<small class="scm-public-pqr-row-msg" aria-live="polite"></small>';
      }
      $html .= '</div>';
      if (!$readOnly) {
        $formClass = $isSignedPublicAccess ? 'scm-public-pqr-public-form' : 'scm-public-pqr-form scm-public-pqr-form-inline';
        $html .= '<form class="' . self::h($formClass) . '" method="post" autocomplete="off" data-ticket-pk="' . self::h((string) $ticketPk) . '" style="display:none;">';
        if ($isSignedPublicAccess) {
          $html .= '<input type="hidden" name="public_action" value="asignar_pqr_publico">';
          if ($publicToken !== '') {
            $html .= '<input type="hidden" name="access_token" value="' . self::h($publicToken) . '">';
          }
          $html .= '<input type="hidden" name="id_empleado" value="' . self::h($publicEmployeeId) . '">';
          $html .= '<input type="hidden" name="scope" value="' . self::h($publicScope) . '">';
          $html .= '<input type="hidden" name="exp" value="' . self::h($publicExp) . '">';
          $html .= '<input type="hidden" name="sig" value="' . self::h($publicSig) . '">';
        }
        $html .= '<input type="hidden" name="ticket_pk" value="' . self::h((string) $ticketPk) . '">';
        $html .= '<label style="display:block;margin:2px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Tipo de solicitud</label>';
        $html .= '<select name="tema_ayuda" class="select select-bordered select-sm scm-select" required>' . $tipoOptsRow . '</select>';
        $html .= '<label style="display:block;margin:8px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Departamento</label>';
        $html .= '<select name="departamento" class="select select-bordered select-sm scm-select" required>' . $deptoOptsRow . '</select>';
        $html .= '<label style="display:block;margin:8px 0 4px;font-size:12px;font-weight:600;color:#2b3f58;">Funcionario responsable</label>';
        $html .= '<select name="new_empleado_id" class="select select-bordered select-sm scm-select">' . $empOptsRow . '</select>';
        $html .= '<div style="display:flex;align-items:center;gap:8px;margin-top:6px;">';
        $html .= '<button type="submit" class="scm-btn-primary btn btn-primary btn-sm">Trasladar</button>';
        if (!$isSignedPublicAccess) {
          $html .= '<small class="scm-public-pqr-msg" aria-live="polite"></small>';
        }
        $html .= '</div>';
        $html .= '</form>';
      }
      $html .= '</td></tr>';
    }

    $html .= '</tbody></table></div></div>';
    return $html . $settingsModalHtml;
  }

  public function renderPublicPqrReadonlyPage(string $employeeIdFilter = '', array $overrideConfig = []): string
  {
    $config = array_merge([
      'ticket_url' => self::DEFAULT_TICKET_URL,
    ], $overrideConfig);

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeIdFilter));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }

    $filters = $this->get_public_pqr_filters_from_request();
    $rows = $this->fetch_public_pqr_rows(180, $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $rows,
      [],
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $employeeIdFilter,
      true,
      [],
      $filters
    );

    $assetBaseUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    $cssRel = 'assets/css/scm-admin.css';
    $cssPath = $assetBasePath . $cssRel;
    $cssVer = file_exists($cssPath) ? (string) filemtime($cssPath) : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));

    ob_start();
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Solicitudes Web</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
      <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-pqr-readonly.css?v=' . SCM_VERSION); ?>">
    </head>

    <body>
      <main class="scm-public-shell">
        <header class="scm-public-header">
          <span class="scm-public-brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span class="scm-public-kicker">Acceso publico</span>
          <h1>Solicitudes Web</h1>
          <p>Consulta de solo lectura para revisar solicitudes creadas desde los portales publicos.</p>
        </header>
        <?php echo $contentHtml; ?>
      </main>
    </body>

    </html>
  <?php
    return (string) ob_get_clean();
  }

  public function renderPublicPqrSignedAccessPage(string $employeeIdFilter = '', string $expiresAt = '', string $signature = '', array $overrideConfig = [], string $flashMessage = '', string $flashType = 'success', string $accessScope = 'assigned', string $accessToken = ''): string
  {
    $config = array_merge([
      'ticket_url' => self::DEFAULT_TICKET_URL,
    ], $overrideConfig);

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeIdFilter));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }
    $accessScope = mb_strtolower(trim($accessScope), 'UTF-8');
    if ($accessScope !== 'all') {
      $accessScope = 'assigned';
    }

    $filters = $this->get_public_pqr_filters_from_request();
    $rows = $this->fetch_public_pqr_rows(180, $accessScope === 'all' ? '' : $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $rows,
      $this->get_public_pqr_corresponsable_candidates(),
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $accessScope === 'all' ? '' : $employeeIdFilter,
      false,
      [
        'token' => $accessToken,
        'id_empleado' => $employeeIdFilter,
        'scope' => $accessScope,
        'exp' => $expiresAt,
        'sig' => $signature,
      ],
      $filters
    );

    $assetBaseUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    $cssRel = 'assets/css/scm-admin.css';
    $cssPath = $assetBasePath . $cssRel;
    $cssVer = file_exists($cssPath) ? (string) filemtime($cssPath) : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $flashType = $flashType === 'error' ? 'error' : 'success';

    ob_start();
  ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Solicitudes Web</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
      <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-pqr-signed.css?v=' . SCM_VERSION); ?>">
    </head>

    <body>
      <main class="scm-public-shell">
        <header class="scm-public-header">
          <span class="scm-public-brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span class="scm-public-kicker">Acceso corporativo</span>
          <h1>Solicitudes Web</h1>
          <p><?php echo $accessScope === 'all' ? 'Desde este enlace puedes revisar y trasladar todas las solicitudes web.' : 'Desde este enlace puedes revisar y trasladar las solicitudes asignadas a tu ID.'; ?></p>
        </header>
        <?php if ($flashMessage !== ''): ?>
          <div class="scm-public-alert scm-public-alert-<?php echo self::h($flashType); ?>"><?php echo self::h($flashMessage); ?></div>
        <?php endif; ?>
        <?php echo $contentHtml; ?>
      </main>
      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/public-pqr-signed.js?v=' . SCM_VERSION); ?>" defer></script>
    </body>

    </html>
  <?php
    return (string) ob_get_clean();
  }

  /** Panel principal - antes de render_shortcode(). Llamar desde index.php. */
}
