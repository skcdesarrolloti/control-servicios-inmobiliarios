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

  /** @return array<string,array{label:string,themes:array<int,string>}> */
  private function get_public_pqr_topic_definitions(): array
  {
    $generic = $this->get_generic_tab_definitions();
    $maintenanceThemes = array_map(
      static fn(string $theme): string => mb_strtolower(trim($theme), 'UTF-8'),
      \SCM\Repositories\TicketsRepository::MAINTENANCE_TOPICS
    );
    $knownThemes = [];

    $definitions = [
      'mantenimiento' => ['label' => 'Mantenimiento', 'themes' => $maintenanceThemes],
      'preventiva' => ['label' => 'Preventiva', 'themes' => (array) ($generic['preventiva']['temas'] ?? [])],
      'entrega' => ['label' => 'Entrega', 'themes' => (array) ($generic['entrega']['temas'] ?? [])],
      'recibo' => ['label' => 'Recibo', 'themes' => (array) ($generic['recibo']['temas'] ?? [])],
      'contractual' => ['label' => 'Contractual', 'themes' => (array) ($generic['contractual']['temas'] ?? [])],
      'contable' => ['label' => 'Contable', 'themes' => (array) ($generic['contable']['temas'] ?? [])],
      'certificaciones' => ['label' => 'Certificaciones', 'themes' => (array) ($generic['certificaciones']['temas'] ?? [])],
    ];

    foreach ($definitions as $key => $definition) {
      $normalized = [];
      foreach ((array) ($definition['themes'] ?? []) as $theme) {
        $theme = mb_strtolower(trim((string) $theme), 'UTF-8');
        if ($theme !== '' && !in_array($theme, $normalized, true)) {
          $normalized[] = $theme;
          $knownThemes[$theme] = true;
        }
      }
      $definitions[$key]['themes'] = $normalized;
    }

    $otherThemes = [];
    foreach ($this->get_public_pqr_themes() as $theme) {
      $theme = mb_strtolower(trim((string) $theme), 'UTF-8');
      if ($theme !== '' && !isset($knownThemes[$theme])) {
        $otherThemes[] = $theme;
      }
    }
    $definitions['otros'] = ['label' => 'Otros', 'themes' => array_values(array_unique($otherThemes))];

    return $definitions;
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

  /** @param array<string,mixed> $input @return array{estado:string,empleado:string,categoria:string,busqueda:string,bucket:string,topic:string,page:int} */
  private function get_public_pqr_filters_from_input(array $input): array
  {
    $estado = trim((string) ($input['public_pqr_estado'] ?? ''));
    $empleado = trim((string) ($input['public_pqr_empleado'] ?? ''));
    $categoria = trim((string) ($input['public_pqr_categoria'] ?? ''));
    $busqueda = trim((string) ($input['public_pqr_busqueda'] ?? ''));
    $bucket = mb_strtolower(trim((string) ($input['public_pqr_bucket'] ?? 'abiertos')), 'UTF-8');
    $topic = mb_strtolower(trim((string) ($input['public_pqr_topic'] ?? 'mantenimiento')), 'UTF-8');
    $page = (int) ($input['public_pqr_page'] ?? 1);

    $estado = sanitize_text_field($estado);
    $categoria = sanitize_text_field($categoria);
    $busqueda = sanitize_text_field($busqueda);
    $empleado = preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field($empleado));
    if (!is_string($empleado)) {
      $empleado = '';
    }
    if (!in_array($bucket, ['abiertos', 'postergados', 'cerrados'], true)) {
      $bucket = 'abiertos';
    }
    if (!isset($this->get_public_pqr_topic_definitions()[$topic])) {
      $topic = 'mantenimiento';
    }

    return [
      'estado' => $estado,
      'empleado' => $empleado,
      'categoria' => $categoria,
      'busqueda' => $busqueda,
      'bucket' => $bucket,
      'topic' => $topic,
      'page' => max(1, $page),
    ];
  }

  /** @return array{estado:string,empleado:string,categoria:string,busqueda:string,bucket:string,topic:string,page:int} */
  private function get_public_pqr_filters_from_request(): array
  {
    return $this->get_public_pqr_filters_from_input($_GET);
  }

  /**
   * @return array{
   *   rows:array<int,array<string,mixed>>,
   *   counts:array{abiertos:int,postergados:int,cerrados:int},
   *   topic_counts:array<string,int>,
   *   pagination:array{page:int,per_page:int,total:int,total_pages:int},
   *   employees:array<int,array{id:string,label:string}>
   * }
   */
  private function fetch_public_pqr_result(int $perPage = 24, string $employeeIdFilter = '', array $filters = []): array
  {
    $emptyResult = [
      'rows' => [],
      'counts' => ['abiertos' => 0, 'postergados' => 0, 'cerrados' => 0],
      'topic_counts' => [],
      'pagination' => ['page' => 1, 'per_page' => 24, 'total' => 0, 'total_pages' => 1],
      'employees' => [],
    ];
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable)) {
      return $emptyResult;
    }

    $perPage = max(24, min(50, $perPage));
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
      $tipoPqrsExpr = "TRIM(COALESCE(`tema_ayuda`, `tipo_pqrs`, ''))";
    } elseif ($hasTemaAyuda) {
      $tipoPqrsExpr = "TRIM(COALESCE(`tema_ayuda`, ''))";
    } else {
      $tipoPqrsExpr = "TRIM(COALESCE(`tipo_pqrs`, ''))";
    }
    $selectTipoPqrs = $tipoPqrsExpr . ' AS tipo_pqrs';

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
    $employeeIdFilter = trim($employeeIdFilter);
    if ($employeeIdFilter !== '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $employeeIdFilter;
    }

    $empleadoFilter = trim((string) ($filters['empleado'] ?? ''));
    if ($empleadoFilter !== '' && $employeeIdFilter === '') {
      $whereSql .= " AND TRIM(COALESCE(`id_empleado`, '')) = ?";
      $args[] = $empleadoFilter;
    }

    $categoriaFilter = mb_strtolower(trim((string) ($filters['categoria'] ?? '')), 'UTF-8');
    if ($categoriaFilter !== '') {
      $whereSql .= " AND LOWER({$tipoPqrsExpr}) = ?";
      $args[] = $categoriaFilter;
    }

    $busquedaFilter = trim((string) ($filters['busqueda'] ?? ''));
    if ($busquedaFilter !== '') {
      $searchLike = '%' . $this->db->escapeLike($busquedaFilter) . '%';
      $whereSql .= " AND (TRIM(COALESCE(`id_ticket`, '')) LIKE ? OR TRIM(COALESCE(`asunto`, '')) LIKE ? OR TRIM(COALESCE(`solicitante`, '')) LIKE ? OR TRIM(COALESCE(`descripcion`, '')) LIKE ?)";
      $args = array_merge($args, [$searchLike, $searchLike, $searchLike, $searchLike]);
    }

    $closedExprParts = ["LOWER(TRIM(COALESCE(`estado`, ''))) IN ('cerrado', 'resuelto', 'finalizado')"];
    if ($this->column_exists($ticketsTable, 'estado_administrativo')) {
      $closedExprParts[] = "LOWER(TRIM(COALESCE(`estado_administrativo`, ''))) IN ('cerrado', 'resuelto', 'finalizado')";
    }
    $closedExpr = '(' . implode(' OR ', $closedExprParts) . ')';
    $postponedExpr = $this->column_exists($ticketsTable, 'estado_administrativo')
      ? "(LOWER(TRIM(COALESCE(`estado_administrativo`, ''))) = 'postergado' AND NOT {$closedExpr})"
      : '0 = 1';
    $openExpr = "(NOT {$closedExpr} AND NOT {$postponedExpr})";

    $countRow = $this->db->getRow(
      "SELECT
         COALESCE(SUM(CASE WHEN {$openExpr} THEN 1 ELSE 0 END), 0) AS abiertos,
         COALESCE(SUM(CASE WHEN {$postponedExpr} THEN 1 ELSE 0 END), 0) AS postergados,
         COALESCE(SUM(CASE WHEN {$closedExpr} THEN 1 ELSE 0 END), 0) AS cerrados
       FROM `{$ticketsTable}` WHERE {$whereSql}",
      $args
    ) ?? [];
    $counts = [
      'abiertos' => (int) ($countRow['abiertos'] ?? 0),
      'postergados' => (int) ($countRow['postergados'] ?? 0),
      'cerrados' => (int) ($countRow['cerrados'] ?? 0),
    ];

    $bucket = (string) ($filters['bucket'] ?? 'abiertos');
    $bucketExpr = $bucket === 'cerrados' ? $closedExpr : ($bucket === 'postergados' ? $postponedExpr : $openExpr);
    $whereSql .= " AND {$bucketExpr}";

    $estadoFilter = strtolower(trim((string) ($filters['estado'] ?? '')));
    if ($estadoFilter !== '' && $this->column_exists($ticketsTable, 'estado')) {
      $whereSql .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) = ?";
      $args[] = $estadoFilter;
    }

    $topicDefinitions = $this->get_public_pqr_topic_definitions();
    $topicCounts = array_fill_keys(array_keys($topicDefinitions), 0);
    $themeToTopic = [];
    foreach ($topicDefinitions as $topicKey => $topicDefinition) {
      foreach ((array) ($topicDefinition['themes'] ?? []) as $theme) {
        $themeToTopic[mb_strtolower(trim((string) $theme), 'UTF-8')] = $topicKey;
      }
    }
    $topicCountRows = $this->db->getResults(
      "SELECT LOWER({$tipoPqrsExpr}) AS tema_normalizado, COUNT(*) AS total
       FROM `{$ticketsTable}`
       WHERE {$whereSql}
       GROUP BY LOWER({$tipoPqrsExpr})",
      $args
    );
    foreach ($topicCountRows as $topicCountRow) {
      $theme = mb_strtolower(trim((string) ($topicCountRow['tema_normalizado'] ?? '')), 'UTF-8');
      $topicKey = $themeToTopic[$theme] ?? 'otros';
      if (isset($topicCounts[$topicKey])) {
        $topicCounts[$topicKey] += (int) ($topicCountRow['total'] ?? 0);
      }
    }

    $topic = (string) ($filters['topic'] ?? 'mantenimiento');
    if (!isset($topicDefinitions[$topic])) {
      $topic = 'mantenimiento';
    }
    $topicThemes = (array) ($topicDefinitions[$topic]['themes'] ?? []);
    if ($topic === 'otros') {
      $nonOtherThemes = [];
      foreach ($topicDefinitions as $definitionKey => $topicDefinition) {
        if ($definitionKey !== 'otros') {
          $nonOtherThemes = array_merge($nonOtherThemes, (array) ($topicDefinition['themes'] ?? []));
        }
      }
      $nonOtherThemes = array_values(array_unique($nonOtherThemes));
      if ($nonOtherThemes !== []) {
        $knownPlaceholders = implode(',', array_fill(0, count($nonOtherThemes), '?'));
        $whereSql .= " AND LOWER({$tipoPqrsExpr}) NOT IN ({$knownPlaceholders})";
        $args = array_merge($args, $nonOtherThemes);
      }
    } elseif ($topicThemes !== []) {
      $topicPlaceholders = implode(',', array_fill(0, count($topicThemes), '?'));
      $whereSql .= " AND LOWER({$tipoPqrsExpr}) IN ({$topicPlaceholders})";
      $args = array_merge($args, $topicThemes);
    }

    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$ticketsTable}` WHERE {$whereSql}", $args);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) ($filters['page'] ?? 1)), $totalPages);
    $offset = ($page - 1) * $perPage;
    $selectEstadoAdministrativo = $this->column_exists($ticketsTable, 'estado_administrativo')
      ? "TRIM(COALESCE(`estado_administrativo`, '')) AS estado_administrativo"
      : "'' AS estado_administrativo";
    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`asunto`, '')) AS asunto,
              TRIM(COALESCE(`descripcion`, '')) AS descripcion,
              TRIM(COALESCE(`solicitante`, '')) AS solicitante,
              TRIM(COALESCE(`correo_solicitante`, '')) AS correo_solicitante,
              TRIM(COALESCE(`celular_solicitante`, '')) AS celular_solicitante,
              {$selectTipoPqrs},
              TRIM(COALESCE(`departamento`, '')) AS departamento,
              TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              TRIM(COALESCE(`empleado`, '')) AS empleado,
              TRIM(COALESCE(`estado`, '')) AS estado,
              {$selectEstadoAdministrativo},
              TRIM(COALESCE(`medio`, '')) AS medio,
              TRIM(COALESCE(`contrato`, '')) AS contrato,
              TRIM(COALESCE(`inmueble`, '')) AS inmueble,
              TRIM(COALESCE(`barrio`, '')) AS barrio,
              TRIM(COALESCE(`direccion`, '')) AS direccion,
              COALESCE(`fecha_actualizacion`, `fecha`, 0) AS fecha_ref,
              {$selectCreadoPor},
              {$selectCreadorPor}
            FROM `{$ticketsTable}`
            WHERE {$whereSql}
            ORDER BY `_ID` DESC
            LIMIT {$perPage} OFFSET {$offset}";

    return [
      'rows' => $this->db->getResults($sql, $args),
      'counts' => $counts,
      'topic_counts' => $topicCounts,
      'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
      ],
      'employees' => [],
    ];
  }

  /** @param array<string,mixed> $result @param array<int,mixed> $funcionarios */
  private function render_public_pqr_tab(array $result, array $funcionarios, string $ticketUrl = '', string $employeeIdFilter = '', bool $readOnly = false, array $publicAccess = [], array $filters = []): string
  {
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : ['abiertos' => 0, 'postergados' => 0, 'cerrados' => 0];
    $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : ['page' => 1, 'per_page' => 24, 'total' => count($rows), 'total_pages' => 1];
    $filterFuncionarios = is_array($result['employees'] ?? null) ? $result['employees'] : [];
    if (empty($filterFuncionarios)) {
      $filterFuncionarios = $funcionarios;
    }
    $themes = $this->get_public_pqr_themes();
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

    $currentBucket = (string) ($filters['bucket'] ?? 'abiertos');
    $statusLabels = [
      'abiertos' => 'Abiertos',
      'postergados' => 'Postergados',
      'cerrados' => 'Cerrados',
    ];
    $topicDefinitions = $this->get_public_pqr_topic_definitions();
    $topicCounts = is_array($result['topic_counts'] ?? null) ? $result['topic_counts'] : [];
    $currentTopic = (string) ($filters['topic'] ?? 'mantenimiento');
    if (!isset($topicDefinitions[$currentTopic])) {
      $currentTopic = 'mantenimiento';
    }
    $currentTopicLabel = (string) ($topicDefinitions[$currentTopic]['label'] ?? 'Mantenimiento');
    $html = '<div class="scm-status-bucket scm-public-pqr-bucket" data-public-pqr-listing>';
    $html .= '<div class="scm-status-subtabs scm-public-pqr-topic-tabs" role="tablist" aria-label="Categorías de solicitudes web">';
    $topicBaseUrl = remove_query_arg(['public_pqr_categoria', 'public_pqr_topic', 'public_pqr_page', 'scm_tab', 'tab']);
    foreach ($topicDefinitions as $topicKey => $topicDefinition) {
      $active = $topicKey === $currentTopic;
      $topicUrl = add_query_arg([
        'scm_tab' => 'scm-panel-pqr-publico',
        'public_pqr_bucket' => $currentBucket,
        'public_pqr_topic' => $topicKey,
        'public_pqr_page' => 1,
      ], $topicBaseUrl);
      $html .= '<a href="' . self::h($topicUrl) . '" class="scm-status-topic-tab scm-public-pqr-topic-tab' . ($active ? ' active' : '') . '" data-public-pqr-topic="' . self::h($topicKey) . '" aria-selected="' . ($active ? 'true' : 'false') . '">' . self::h((string) ($topicDefinition['label'] ?? $topicKey)) . '</a>';
    }
    $html .= '</div>';
    $html .= '<div class="scm-status-topic-panel scm-public-pqr-topic-panel active" data-public-pqr-active-topic="' . self::h($currentTopic) . '">';
    $html .= '<div class="scm-status-topic-head"><div><h3>' . self::h($currentTopicLabel) . '</h3><p>Solicitudes Web · ' . self::h((string) ($statusLabels[$currentBucket] ?? 'Abiertos')) . '</p></div>';
    $html .= '<div class="scm-public-pqr-heading-actions"><span class="scm-status-count"><strong>' . self::h((string) ($topicCounts[$currentTopic] ?? ($pagination['total'] ?? 0))) . '</strong> tickets</span>';
    if ($isAdmin) {
      $html .= '<button type="button" onclick="document.getElementById(\'scm-pqr-settings-modal\').style.display=\'flex\'" class="btn btn-sm scm-public-pqr-settings-btn" aria-label="Configurar solicitudes web">Configuración</button>';
    }
    $html .= '</div></div>';
    $currentEstado = trim((string) ($filters['estado'] ?? ''));
    $currentEmpleado = trim((string) ($filters['empleado'] ?? ''));
    $currentBusqueda = trim((string) ($filters['busqueda'] ?? ''));
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
    $clearUrl = remove_query_arg(['public_pqr_estado', 'public_pqr_empleado', 'public_pqr_categoria', 'public_pqr_busqueda', 'public_pqr_bucket', 'public_pqr_topic', 'public_pqr_page', 'scm_tab', 'tab', 'k', 'scope', 'exp', 'sig', 'id_empleado']);
    $clearUrl = add_query_arg($clearQuery, $clearUrl);
    $html .= '<div class="scm-filter-card card"><h3>Filtros</h3>';
    $html .= '<form method="get" autocomplete="off" class="scm-public-pqr-filter-form">';
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
    $html .= '<input type="hidden" name="public_pqr_topic" value="' . self::h($currentTopic) . '">';
    $html .= '<input type="hidden" name="public_pqr_page" value="' . self::h((string) ($pagination['page'] ?? 1)) . '">';
    $html .= '<div class="scm-grid">';
    $html .= '<div class="scm-field"><label>Vista</label><select name="public_pqr_bucket" class="select select-bordered select-sm scm-select">';
    foreach ($statusLabels as $bucketKey => $bucketLabel) {
      $selected = $currentBucket === $bucketKey ? ' selected' : '';
      $html .= '<option value="' . self::h($bucketKey) . '"' . $selected . '>' . self::h($bucketLabel) . ' (' . self::h((string) ($counts[$bucketKey] ?? 0)) . ')</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="scm-field"><label>Estado</label>';
    $html .= '<select name="public_pqr_estado" class="select select-bordered select-sm scm-select">';
    $html .= '<option value="">Todos</option>';
    $estadoOptions = $currentBucket === 'cerrados' ? ['Cerrado', 'Resuelto', 'Finalizado'] : ['Nuevo', 'En proceso'];
    foreach ($estadoOptions as $estadoOption) {
      $selected = strtolower($currentEstado) === strtolower($estadoOption) ? ' selected' : '';
      $html .= '<option value="' . self::h($estadoOption) . '"' . $selected . '>' . self::h($estadoOption) . '</option>';
    }
    $html .= '</select></div>';
    if ($showEmployeeFilter) {
      $html .= '<div class="scm-field"><label>Funcionario</label>';
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
    $html .= '<div class="scm-field"><label>Búsqueda</label>';
    $html .= '<input type="search" name="public_pqr_busqueda" class="input input-bordered input-sm scm-input" value="' . self::h($currentBusqueda) . '" placeholder="ID, asunto, solicitante o descripción">';
    $html .= '</div>';
    $html .= '</div><div class="scm-actions">';
    $html .= '<button type="submit" class="scm-btn-primary btn btn-primary scm-public-pqr-filter-submit">Filtrar</button>';
    $html .= '<a href="' . self::h($clearUrl) . '" class="scm-btn-secondary btn btn-outline">Limpiar</a>';
    $html .= '<span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div>';
    if (empty($rows)) {
      $html .= '<div class="scm-empty"><p>' . ($readOnly ? 'No hay solicitudes web disponibles para este acceso.' : 'No hay solicitudes web en esta categoría y vista.') . '</p></div>';
      $html .= '</div></div>';
      return $html . $settingsModalHtml;
    }

    $html .= '<div class="scm-cards-wrap scm-public-pqr-cards-wrap"><div class="scm-ticket-cards">';

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
      $estadoAdminActual = trim((string) ($row['estado_administrativo'] ?? ''));
      $solicitanteActual = trim((string) ($row['solicitante'] ?? ''));
      $correoSolicitante = trim((string) ($row['correo_solicitante'] ?? ''));
      $celularSolicitante = trim((string) ($row['celular_solicitante'] ?? ''));
      $asuntoActual = trim((string) ($row['asunto'] ?? ''));
      $descripcionActual = trim((string) ($row['descripcion'] ?? ''));
      $medioActual = trim((string) ($row['medio'] ?? ''));
      $contratoActual = trim((string) ($row['contrato'] ?? ''));
      $inmuebleActual = trim((string) ($row['inmueble'] ?? ''));
      $barrioActual = trim((string) ($row['barrio'] ?? ''));
      $direccionActual = trim((string) ($row['direccion'] ?? ''));
      $ticketDetailUrl = ($ticketUrl !== '' && $logicalId !== '') ? $ticketUrl . rawurlencode($logicalId) : '';

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

      $caseSource = '<div class="scm-case-action-buttons">';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-public-solicitud">Ver solicitud</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-public-solicitante">Ver solicitante</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-public-inmueble">Ver inmueble</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-public-descripcion">Ver descripcion</button>';
      $caseSource .= '</div>';
      $caseSource .= '<section class="scm-public-pqr-case-detail">';
      $caseSource .= '<h4>Detalle de la solicitud</h4>';
      $caseSource .= '<div class="scm-public-pqr-description"><span>Descripción</span><p>' . ($descripcionActual !== '' ? nl2br(self::h($descripcionActual)) : 'Sin descripción registrada.') . '</p></div>';
      $caseSource .= '<div class="scm-public-pqr-detail-grid">';
      $caseSource .= '<div><span>Ticket</span><strong>#' . self::h($logicalId !== '' ? $logicalId : (string) $ticketPk) . '</strong></div>';
      $caseSource .= '<div><span>Tipo</span><strong>' . self::h($tipoActual !== '' ? $tipoActual : 'Solicitud web') . '</strong></div>';
      $caseSource .= '<div><span>Estado</span><strong>' . self::h($estadoActual !== '' ? $estadoActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Estado administrativo</span><strong>' . self::h($estadoAdminActual !== '' ? $estadoAdminActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Solicitante</span><strong>' . self::h($solicitanteActual !== '' ? $solicitanteActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Celular</span><strong>' . self::h($celularSolicitante !== '' ? $celularSolicitante : '-') . '</strong></div>';
      $caseSource .= '<div><span>Correo</span><strong>' . self::h($correoSolicitante !== '' ? $correoSolicitante : '-') . '</strong></div>';
      $caseSource .= '<div><span>Origen</span><strong>' . self::h($medioActual !== '' ? $medioActual : ($creadoPor !== '' ? $creadoPor : '-')) . '</strong></div>';
      $caseSource .= '<div><span>Contrato</span><strong>' . self::h($contratoActual !== '' ? '#' . $contratoActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Inmueble</span><strong>' . self::h($inmuebleActual !== '' ? $inmuebleActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Barrio</span><strong>' . self::h($barrioActual !== '' ? $barrioActual : '-') . '</strong></div>';
      $caseSource .= '<div><span>Dirección</span><strong>' . self::h($direccionActual !== '' ? $direccionActual : '-') . '</strong></div>';
      $caseSource .= '</div></section>';
      $caseSource .= $this->render_public_pqr_case_section('Gestion de la solicitud', [
        'Acciones disponibles' => $currentBucket === 'cerrados' ? 'Agregar nota, activar solicitud y abrir solicitud original.' : 'Trasladar, agregar nota, postergar, responder, cerrar y abrir solicitud original.',
        'Estado actual' => $estadoActual,
        'Estado administrativo' => $estadoAdminActual,
        'Responsable actual' => $empActual,
      ]);
      $caseSource .= '<div class="scm-case-hidden-sections" style="display:none;">';
      $caseSource .= $this->render_public_pqr_case_section('Solicitud web', [
        'Ticket' => '#' . ($logicalId !== '' ? $logicalId : (string) $ticketPk),
        'Asunto' => $asuntoActual,
        'Tipo de solicitud' => $tipoActual,
        'Departamento' => $deptoActual,
        'Estado' => $estadoActual,
        'Estado administrativo' => $estadoAdminActual,
        'Fecha' => $fechaTxt,
        'Canal' => $medioActual,
        'Creado por' => $creadoPor,
        'Responsable' => $empActual,
      ], 'scm-sec-public-solicitud');
      $caseSource .= $this->render_public_pqr_case_section('Solicitante y contacto', [
        'Solicitante' => $solicitanteActual,
        'Correo' => $correoSolicitante,
        'Celular' => $celularSolicitante,
        'Actor' => $creadoPor,
        'Medio' => $medioActual,
      ], 'scm-sec-public-solicitante');
      $caseSource .= $this->render_public_pqr_case_section('Inmueble y contrato', [
        'Contrato' => $contratoActual !== '' ? '#' . $contratoActual : '',
        'Inmueble' => $inmuebleActual,
        'Barrio' => $barrioActual,
        'Direccion' => $direccionActual,
      ], 'scm-sec-public-inmueble');
      $caseSource .= '<section class="scm-case-history" id="scm-sec-public-descripcion"><h4>Descripcion</h4><article class="scm-case-history-item"><div class="scm-case-history-detail"><p>' . ($descripcionActual !== '' ? nl2br(self::h($descripcionActual)) : 'Sin descripcion registrada.') . '</p></div></article></section>';
      $caseSource .= '</div>';

      $html .= '<article class="scm-ticket-card card scm-public-pqr-card" data-pqr-row="' . self::h((string) $ticketPk) . '" data-pk="' . self::h((string) $ticketPk) . '">';
      $html .= '<header class="scm-ticket-card-head"><span class="scm-ticket-badge badge badge-primary">#' . self::h($logicalId) . '</span><h3><span class="scm-public-pqr-current-topic">' . self::h($tipoActual !== '' ? $tipoActual : 'Solicitud web') . '</span></h3></header>';
      $html .= '<p class="scm-ticket-card-asunto"><span>Asunto</span><strong>' . self::h($asuntoActual !== '' ? $asuntoActual : '-') . '</strong></p>';
      $html .= '<p class="scm-ticket-card-request">Solicitante <strong>' . self::h($solicitanteActual !== '' ? $solicitanteActual : '-') . '</strong></p>';
      $html .= '<p class="scm-ticket-card-request">Creado por <strong>' . self::h($creadoPor !== '' ? $creadoPor : '-') . '</strong></p>';
      $html .= '<p class="scm-ticket-card-request">Fecha <strong>' . self::h($fechaTxt) . '</strong></p>';
      $html .= '<p class="scm-ticket-card-employee">Asignado a <strong class="scm-public-pqr-current-employee">' . self::h($empActual !== '' ? $empActual : '-') . '</strong></p>';
      $html .= '<div class="scm-ticket-card-states">';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado</span><span class="scm-public-pqr-current-state">' . $this->estado_badge($estadoActual !== '' ? $estadoActual : '-') . '</span></div>';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado administrativo</span>' . $this->estado_badge($estadoAdminActual !== '' ? $estadoAdminActual : '-') . '</div>';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Departamento</span><strong class="scm-public-pqr-current-department">' . self::h($deptoActual !== '' ? $deptoActual : '-') . '</strong></div>';
      $html .= '</div>';
      $html .= '<div class="scm-ticket-card-footer">';
      if ($readOnly) {
        if ($ticketDetailUrl !== '') {
          $html .= '<a href="' . self::h($ticketDetailUrl) . '" target="_blank" rel="noopener noreferrer" class="scm-btn-case btn btn-primary btn-sm scm-public-pqr-view-btn">Ver solicitud</a>';
        }
      } elseif ($isSignedPublicAccess) {
        if ($ticketDetailUrl !== '') {
          $html .= '<button type="button" data-scm-open-iframe data-scm-compact-iframe data-no-emp-check data-iframe-url="' . self::h($ticketDetailUrl) . '" data-iframe-title="Solicitud #' . self::h($logicalId) . '" class="scm-btn-case btn btn-primary btn-sm scm-public-pqr-view-btn">Ver solicitud</button>';
        }
      } else {
        $html .= '<button class="scm-btn-case btn btn-primary btn-sm scm-public-pqr-view-btn" type="button" onclick="scmOpenCase(this)" data-case-kind="public-pqr" data-status-bucket="' . self::h($currentBucket) . '" data-ticket="' . self::h($logicalId) . '" data-ticket-pk="' . self::h((string) $ticketPk) . '" data-asunto="' . self::h($asuntoActual !== '' ? $asuntoActual : '-') . '" data-estado="' . self::h($estadoActual !== '' ? $estadoActual : '-') . '" data-admin="' . self::h($estadoAdminActual !== '' ? $estadoAdminActual : '-') . '" data-creado="' . self::h($fechaTxt) . '" data-empleado="' . self::h($empActual !== '' ? $empActual : '-') . '" data-empleado-id="' . self::h($empIdActual) . '" data-categoria="' . self::h($tipoActual !== '' ? $tipoActual : '-') . '" data-departamento="' . self::h($deptoActual !== '' ? $deptoActual : '-') . '" data-creado-por="' . self::h($creadoPor !== '' ? $creadoPor : '-') . '" data-medio="' . self::h($medioActual !== '' ? $medioActual : '-') . '" data-solicitante="' . self::h($solicitanteActual !== '' ? $solicitanteActual : '-') . '" data-correo-solicitante="' . self::h($correoSolicitante) . '" data-celular-solicitante="' . self::h($celularSolicitante) . '" data-descripcion="' . self::h($descripcionActual) . '" data-contrato="' . self::h($contratoActual !== '' ? '#' . $contratoActual : '-') . '" data-inmueble="' . self::h($inmuebleActual !== '' ? $inmuebleActual : '-') . '" data-id-inmueble-web="' . self::h($inmuebleActual) . '" data-id-inmueble-data="" data-ubicacion-google-maps="" data-barrio="' . self::h($barrioActual !== '' ? $barrioActual : '-') . '" data-direccion="' . self::h($direccionActual !== '' ? $direccionActual : '') . '" data-ticket-url="' . self::h($ticketDetailUrl) . '">Ver caso</button>';
      }
      if (!$readOnly && $isSignedPublicAccess) {
        $html .= '<button type="button" class="scm-btn-primary btn btn-primary btn-sm scm-public-pqr-transfer-btn" data-scm-open-pqr-transfer data-ticket-pk="' . self::h((string) $ticketPk) . '" data-ticket-logical="' . self::h($logicalId) . '">Trasladar</button>';
        $html .= '<small class="scm-public-pqr-row-msg" aria-live="polite"></small>';
      }
      $html .= '</div>';
      if (!$readOnly && !$isSignedPublicAccess) {
        $html .= '<button type="button" class="scm-public-pqr-transfer-btn" data-scm-open-pqr-transfer data-ticket-pk="' . self::h((string) $ticketPk) . '" data-ticket-logical="' . self::h($logicalId) . '" hidden tabindex="-1">Trasladar</button>';
        $html .= '<small class="scm-public-pqr-row-msg" aria-live="polite"></small>';
      }
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
      $html .= '<div class="scm-case-source" aria-hidden="true" style="display:none;">' . $caseSource . '</div>';
      $html .= '</article>';
    }

    $html .= '</div></div>';
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $currentPage = min($totalPages, max(1, (int) ($pagination['page'] ?? 1)));
    $totalRows = max(0, (int) ($pagination['total'] ?? count($rows)));
    if ($totalRows > 0) {
      $pageBaseUrl = remove_query_arg(['public_pqr_categoria', 'public_pqr_page', 'scm_tab', 'tab']);
      $pageQuery = [
        'scm_tab' => 'scm-panel-pqr-publico',
        'public_pqr_bucket' => $currentBucket,
        'public_pqr_topic' => $currentTopic,
      ];
      if ($currentEstado !== '') $pageQuery['public_pqr_estado'] = $currentEstado;
      if ($currentEmpleado !== '') $pageQuery['public_pqr_empleado'] = $currentEmpleado;
      if ($currentBusqueda !== '') $pageQuery['public_pqr_busqueda'] = $currentBusqueda;
      $renderPageButton = static function (int $pageNumber, string $label, bool $disabled = false, bool $active = false) use ($pageBaseUrl, $pageQuery): string {
        $classes = 'scm-page-btn btn btn-sm scm-page-btn-public-pqr ' . ($active ? 'btn-primary is-active' : 'btn-outline');
        if ($disabled) {
          return '<span class="' . self::h($classes) . '" aria-disabled="true">' . $label . '</span>';
        }
        $pageUrl = add_query_arg($pageQuery + ['public_pqr_page' => max(1, $pageNumber)], $pageBaseUrl);
        return '<a href="' . self::h($pageUrl) . '" class="' . self::h($classes) . '" data-public-pqr-page="' . self::h((string) max(1, $pageNumber)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
      };
      $html .= '<div class="scm-pagination"><div class="scm-pagination-card card">';
      $html .= '<div class="scm-pagination-summary">Pagina ' . self::h((string) $currentPage) . ' de ' . self::h((string) $totalPages) . ' | Total: ' . self::h((string) $totalRows) . '</div>';
      $html .= '<div class="scm-pagination-controls">';
      $html .= $renderPageButton(1, '&laquo;', $currentPage <= 1);
      $html .= $renderPageButton($currentPage - 1, '&lsaquo;', $currentPage <= 1);
      $startPage = max(1, $currentPage - 2);
      $endPage = min($totalPages, $currentPage + 2);
      for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) {
        $html .= $renderPageButton($pageNumber, self::h((string) $pageNumber), false, $pageNumber === $currentPage);
      }
      $html .= $renderPageButton($currentPage + 1, '&rsaquo;', $currentPage >= $totalPages);
      $html .= $renderPageButton($totalPages, '&raquo;', $currentPage >= $totalPages);
      $html .= '</div></div></div>';
    }
    $html .= '</div></div>';
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
    $result = $this->fetch_public_pqr_result(24, $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $result,
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
        <div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy">
          <?php echo $contentHtml; ?>
        </div>
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
    $result = $this->fetch_public_pqr_result(24, $accessScope === 'all' ? '' : $employeeIdFilter, $filters);
    $contentHtml = $this->render_public_pqr_tab(
      $result,
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
        <div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy">
          <?php echo $contentHtml; ?>
        </div>
      </main>
      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/public-pqr-signed.js?v=' . SCM_VERSION); ?>" defer></script>
    </body>

    </html>
  <?php
    return (string) ob_get_clean();
  }

  /**
   * @param array<string,string> $fields
   */
  private function render_public_pqr_case_section(string $title, array $fields, string $sectionId = ''): string
  {
    $sectionAttr = $sectionId !== '' ? ' id="' . self::h($sectionId) . '"' : '';
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . self::h($title) . '</h4>';
    $html .= '<article class="scm-case-history-item"><div class="scm-case-history-detail">';
    foreach ($fields as $label => $value) {
      $text = trim((string) $value);
      $html .= '<p><strong>' . self::h((string) $label) . ':</strong> ' . self::h($text !== '' ? $text : '-') . '</p>';
    }
    $html .= '</div></article></section>';
    return $html;
  }

  /** Panel principal - antes de render_shortcode(). Llamar desde index.php. */
}
