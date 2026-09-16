<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

trait HandlesPropertyHistoryActions
{
  public function ajax_handler_property_history_report(): void
  {
    $this->verifyCsrf();
    if (!$this->property_history_can_access()) {
      $this->jsonFail('No tienes permiso para consultar historial de inmuebles.');
    }

    [$contractNumber, $propertyCode] = $this->property_history_request_filters();
    if ($contractNumber === '' && $propertyCode === '') {
      $this->jsonFail('Escribe un contrato o un codigo web/inmueble para consultar.');
    }

    $this->jsonOk($this->property_history_payload($contractNumber, $propertyCode));
  }

  public function ajax_handler_property_history_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->property_history_can_access()) {
      $this->jsonFail('No tienes permiso para generar este PDF.');
    }

    [$contractNumber, $propertyCode] = $this->property_history_request_filters();
    if ($contractNumber === '' && $propertyCode === '') {
      $this->jsonFail('Escribe un contrato o un codigo web/inmueble para generar el PDF.');
    }

    $payload = $this->property_history_payload($contractNumber, $propertyCode);
    $pdf = $this->property_history_build_pdf($payload);
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $dir = (string) SCM_STORAGE_PATH . '/tmp';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
      $this->jsonFail('No se pudo preparar el PDF.');
    }

    $path = $dir . '/' . $basename;
    $pdf->save($path);
    $filenameToken = preg_replace('/[^0-9A-Za-z_-]+/', '-', (string) ($payload['property']['codigo'] ?? $propertyCode ?: $contractNumber)) ?: 'inmueble';

    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="historial-inmueble-' . $filenameToken . '.pdf"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    @unlink($path);
    exit;
  }

  private function property_history_can_access(): bool
  {
    return $this->canAccessDashboardTab('calendario_actividades')
      || $this->canAccessDashboardTab('abiertos')
      || $this->canAccessDashboardTab('mis_tickets')
      || $this->canAccessDashboardTab('metricas');
  }

  private function property_history_clean_query($value): string
  {
    $value = trim(sanitize_text_field(wp_unslash((string) ($value ?? ''))));
    return preg_replace('/[^0-9A-Za-z_-]+/', '', $value) ?: '';
  }

  /** @return array{0:string,1:string} */
  private function property_history_request_filters(): array
  {
    $contractNumber = $this->property_history_clean_query($_POST['contract_number'] ?? '');
    $propertyCode = $this->property_history_clean_query($_POST['property_code'] ?? '');
    $legacyQuery = $this->property_history_clean_query($_POST['query'] ?? '');
    if ($contractNumber === '' && $propertyCode === '' && $legacyQuery !== '') {
      $propertyCode = $legacyQuery;
    }
    return [$contractNumber, $propertyCode];
  }

  /** @return array<string,mixed> */
  private function property_history_payload(string $contractNumber, string $propertyCode): array
  {
    $target = $this->property_history_resolve_target($contractNumber, $propertyCode);
    $property = is_array($target['property'] ?? null) ? $target['property'] : [];
    $contracts = is_array($target['contracts'] ?? null) ? $target['contracts'] : [];
    $targetContractTokens = array_values(array_filter(array_map('strval', (array) ($target['contract_tokens'] ?? [])), static fn(string $value): bool => trim($value) !== ''));
    if ($contractNumber !== '' && $targetContractTokens === []) {
      $this->jsonFail('No se encontro el contrato ' . $contractNumber . '. Revisa el numero e intenta nuevamente.');
    }
    if ($contractNumber !== '' && $propertyCode !== '' && $property !== []) {
      $resolvedPropertyTokens = $this->property_history_tokens_from_row($property);
      if ($resolvedPropertyTokens !== [] && !in_array($propertyCode, $resolvedPropertyTokens, true)) {
        $resolvedCode = trim((string) ($property['codigo'] ?? $property['_ID'] ?? $property['id_inmueble'] ?? ''));
        $this->jsonFail('El contrato ' . $contractNumber . ' corresponde al codigo/inmueble ' . ($resolvedCode !== '' ? $resolvedCode : 'registrado') . ', no al codigo ' . $propertyCode . '. Revisa los filtros.');
      }
    }
    $tokens = array_values(array_unique(array_filter(array_map('strval', array_merge(
      [$propertyCode],
      (array) ($target['property_tokens'] ?? []),
      [
        $property['_ID'] ?? '',
        $property['codigo'] ?? '',
        $property['id_inmueble'] ?? '',
        $property['id_inmueble_data'] ?? '',
      ]
    )), static fn(string $value): bool => trim($value) !== '')));
    $contractTokens = array_values(array_unique(array_filter(array_map('strval', array_merge(
      $targetContractTokens,
      [$contractNumber]
    )), static fn(string $value): bool => trim($value) !== '')));

    $sources = $this->property_history_source_labels();
    $sections = [];
    $counts = [];
    $contractHistory = [];
    foreach ($sources as $key => $source) {
      $rows = $this->property_history_rows(
        (string) $source['table'],
        $tokens,
        (array) ($source['contract_columns'] ?? []),
        $contractTokens,
        (array) ($source['order'] ?? [])
      );
      if (in_array((string) $key, ['contratos_arrendamiento', 'contratos_mandato'], true)) {
        foreach ($rows as $row) {
          $contractHistory[] = $this->property_history_contract_summary_row((string) $key, $row);
        }
      }
      $items = array_map(fn(array $row): array => $this->property_history_summarize_row($key, $row), $rows);
      $counts[$key] = count($items);
      $sections[] = [
        'key' => $key,
        'label' => (string) $source['label'],
        'description' => (string) $source['description'],
        'count' => count($items),
        'items' => array_slice($items, 0, 80),
      ];
    }

    $timeline = [];
    foreach ($sections as $section) {
      foreach ((array) ($section['items'] ?? []) as $item) {
        $timeline[] = [
          'source' => (string) ($section['label'] ?? ''),
          'date' => (string) ($item['date'] ?? ''),
          'date_ts' => (int) ($item['date_ts'] ?? 0),
          'title' => (string) ($item['title'] ?? ''),
          'detail' => (string) ($item['detail'] ?? ''),
          'reference' => (string) ($item['reference'] ?? ''),
        ];
      }
    }
    usort($timeline, static fn(array $a, array $b): int => (int) ($b['date_ts'] ?? 0) <=> (int) ($a['date_ts'] ?? 0));
    usort($contractHistory, static fn(array $a, array $b): int => (int) ($b['date_ts'] ?? 0) <=> (int) ($a['date_ts'] ?? 0));
    $totalMatches = array_sum(array_map(static fn($value): int => (int) $value, $counts));
    if ($totalMatches <= 0 && $property === [] && $contracts === []) {
      if ($contractNumber !== '' && $propertyCode !== '') {
        $this->jsonFail('No se encontro informacion para el contrato ' . $contractNumber . ' ni para el codigo web/inmueble ' . $propertyCode . '. Revisa los filtros.');
      }
      if ($contractNumber !== '') {
        $this->jsonFail('No se encontro el contrato ' . $contractNumber . '. Revisa el numero e intenta nuevamente.');
      }
      $this->jsonFail('No se encontro el codigo web/inmueble ' . $propertyCode . '. Revisa el codigo e intenta nuevamente.');
    }

    $propertyInfo = $this->property_history_property_info($propertyCode !== '' ? $propertyCode : $contractNumber, $property, $contracts, $contractNumber);
    $sourceRows = [];
    foreach ($sections as $section) {
      $sourceRows[] = [
        'key' => (string) ($section['key'] ?? ''),
        'label' => (string) ($section['label'] ?? ''),
        'description' => (string) ($section['description'] ?? ''),
        'count' => (int) ($section['count'] ?? 0),
      ];
    }

    return [
      'query' => $this->property_history_join([
        $contractNumber !== '' ? 'Contrato ' . $contractNumber : '',
        $propertyCode !== '' ? 'Codigo ' . $propertyCode : '',
      ], ' / '),
      'contract_number' => $contractNumber,
      'property_code' => $propertyCode,
      'generated_at' => date('d/m/Y H:i'),
      'resolved_by' => (string) ($target['resolved_by'] ?? 'consulta'),
      'property' => $propertyInfo,
      'contracts' => array_slice($contractHistory, 0, 120),
      'counts' => $counts,
      'sources' => $sourceRows,
      'sections' => $sections,
      'timeline' => array_slice($timeline, 0, 300),
    ];
  }

  /** @return array<string,mixed> */
  private function property_history_resolve_target(string $contractNumber, string $propertyCode): array
  {
    $propertyTable = $this->db->table('jet_cct_inmuebles');
    $property = [];
    $propertyTokens = $propertyCode !== '' ? [$propertyCode] : [];
    $contractTokens = [];
    $contracts = [];
    $resolvedBy = 'codigo';

    if ($propertyCode !== '' && $this->table_exists($propertyTable)) {
      $where = [];
      $args = [];
      foreach (['_ID', 'codigo', 'id_inmueble', 'id_inmueble_data'] as $column) {
        if (!$this->column_exists($propertyTable, $column)) {
          continue;
        }
        $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) = ?";
        $args[] = $propertyCode;
      }
      if ($where !== []) {
        $row = $this->db->getRow("SELECT * FROM `{$propertyTable}` WHERE " . implode(' OR ', $where) . " LIMIT 1", $args);
        if (is_array($row)) {
          $property = $row;
          $propertyTokens = $this->property_history_tokens_from_row($row);
        }
      }
    }

    if ($contractNumber !== '') {
      foreach (['jet_cct_contratos_arrendamiento', 'jet_cct_contrato_mandato'] as $suffix) {
        $table = $this->db->table($suffix);
        if (!$this->table_exists($table)) {
          continue;
        }
        $where = [];
        $args = [];
        foreach (['contrato', 'id_contrato'] as $column) {
          if (!$this->column_exists($table, $column)) {
            continue;
          }
          $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) = ?";
          $args[] = $contractNumber;
        }
        if ($where === []) {
          continue;
        }
        $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " ORDER BY `_ID` DESC LIMIT 5", $args);
        foreach ($rows as $row) {
          $contracts[] = $row;
          $contractTokens = array_values(array_unique(array_merge($contractTokens, $this->property_history_contract_tokens_from_row($row))));
          $rowTokens = $this->property_history_tokens_from_row($row);
          if ($rowTokens !== []) {
            $propertyTokens = array_values(array_unique(array_merge($propertyTokens, $rowTokens)));
            $resolvedBy = 'contrato';
            $foundProperty = $this->property_history_find_property($rowTokens);
            if ($foundProperty !== []) {
              $property = $foundProperty;
              $propertyTokens = array_values(array_unique(array_merge($propertyTokens, $this->property_history_tokens_from_row($foundProperty))));
            }
          }
        }
      }
    }

    if ($property === [] || ($contractNumber !== '' && $contractTokens === [])) {
      $ticketTable = $this->db->table('jet_cct_tickets');
      if ($this->table_exists($ticketTable)) {
        $where = [];
        $args = [];
        $ticketFilters = $contractNumber !== ''
          ? ['contrato', 'id_contrato']
          : ['id_inmueble', 'id_inmueble_data', 'inmueble'];
        $lookupValue = $contractNumber !== '' ? $contractNumber : $propertyCode;
        foreach ($ticketFilters as $column) {
          if (!$this->column_exists($ticketTable, $column)) {
            continue;
          }
          $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) = ?";
          $args[] = $lookupValue;
        }
        if ($where !== []) {
          $row = $this->db->getRow("SELECT * FROM `{$ticketTable}` WHERE " . implode(' OR ', $where) . " ORDER BY `_ID` DESC LIMIT 1", $args);
          if (is_array($row)) {
            $propertyTokens = array_values(array_unique(array_merge($propertyTokens, $this->property_history_tokens_from_row($row))));
            $contractTokens = array_values(array_unique(array_merge($contractTokens, $this->property_history_contract_tokens_from_row($row))));
            $foundProperty = $this->property_history_find_property($propertyTokens);
            if ($foundProperty !== []) {
              $property = $foundProperty;
            }
            $resolvedBy = $contractNumber !== '' ? 'ticket/contrato' : 'ticket/inmueble';
          }
        }
      }
    }

    if ($property !== []) {
      $propertyTokens = array_values(array_unique(array_merge($propertyTokens, $this->property_history_tokens_from_row($property))));
    }

    return [
      'resolved_by' => $resolvedBy,
      'property' => $property,
      'property_tokens' => $propertyTokens,
      'contract_tokens' => $contractTokens,
      'contracts' => $contracts,
    ];
  }

  /** @param string[] $tokens @return array<string,mixed> */
  private function property_history_find_property(array $tokens): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->table_exists($table)) {
      return [];
    }
    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens), static fn(string $value): bool => trim($value) !== '')));
    if ($tokens === []) {
      return [];
    }
    $where = [];
    $args = [];
    foreach (['_ID', 'codigo', 'id_inmueble', 'id_inmueble_data'] as $column) {
      if (!$this->column_exists($table, $column)) {
        continue;
      }
      $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) IN (" . implode(',', array_fill(0, count($tokens), '?')) . ")";
      array_push($args, ...$tokens);
    }
    if ($where === []) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " LIMIT 1", $args);
    return is_array($row) ? $row : [];
  }

  /** @return array<string,array<string,mixed>> */
  private function property_history_source_labels(): array
  {
    return [
      'historial' => ['table' => 'jet_cct_historial_del_inmueble', 'label' => 'Historial del inmueble', 'description' => 'Linea de tiempo general registrada para el inmueble.', 'order' => ['fecha', 'cct_created', '_ID']],
      'gestiones_cobro' => ['table' => 'jet_cct_gestiones_cobro', 'label' => 'Gestiones de cobro', 'description' => 'Mensajes, recordatorios y acciones de cartera.', 'order' => ['fecha', 'cct_created', '_ID']],
      'tickets' => ['table' => 'jet_cct_tickets', 'label' => 'Tickets', 'description' => 'Casos administrativos y solicitudes relacionadas.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'contratos_arrendamiento' => ['table' => 'jet_cct_contratos_arrendamiento', 'label' => 'Contratos de arrendamiento', 'description' => 'Contratos vinculados al inmueble.', 'contract_columns' => ['contrato', 'id_contrato', '_ID'], 'order' => ['fecha', 'cct_created', '_ID']],
      'contratos_mandato' => ['table' => 'jet_cct_contrato_mandato', 'label' => 'Contratos de administracion', 'description' => 'Mandatos o administracion del inmueble.', 'contract_columns' => ['contrato', 'id_contrato', '_ID'], 'order' => ['fecha', 'cct_created', '_ID']],
      'cierres' => ['table' => 'jet_cct_cierres', 'label' => 'Cierres', 'description' => 'Cierres comerciales o documentales.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'revisiones_servicios' => ['table' => 'jet_cct_revisiones_servicios', 'label' => 'Servicios publicos', 'description' => 'Revisiones de servicios publicos registradas.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'revision_preventiva' => ['table' => 'jet_cct_revision_preventiva', 'label' => 'Revisiones preventivas', 'description' => 'Inspecciones preventivas y hallazgos.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'fecha_revision', 'cct_created', '_ID']],
      'revision_correctiva' => ['table' => 'jet_cct_revision_correctiva', 'label' => 'Revisiones correctivas', 'description' => 'Revisiones por danos o reparaciones.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'cotizaciones_mantenimiento' => ['table' => 'jet_cct_cotizacion_mantenimiento', 'label' => 'Cotizaciones de mantenimiento', 'description' => 'Cotizaciones y presupuestos de reparacion.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'fecha_envio', 'cct_created', '_ID']],
      'estudios_aseguradoras' => ['table' => 'jet_cct_estudios_aseguradoras', 'label' => 'Estudios de aseguradora', 'description' => 'Solicitudes y respuestas de aseguradoras.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'actualizaciones' => ['table' => 'jet_cct_actualizaciones', 'label' => 'Actualizaciones', 'description' => 'Cambios de informacion del inmueble.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
      'llamadas' => ['table' => 'jet_cct_cct_llamadas', 'label' => 'Llamadas o interesados', 'description' => 'Contactos e interesados relacionados.', 'order' => ['fecha', 'cct_created', '_ID']],
      'recaptaciones' => ['table' => 'jet_cct_recaptaciones', 'label' => 'Recaptaciones', 'description' => 'Recaptacion o reactivacion comercial.', 'contract_columns' => ['contrato', 'id_contrato'], 'order' => ['fecha', 'cct_created', '_ID']],
    ];
  }

  /** @param string[] $tokens @param string[] $contractColumns @param string[] $contractTokens @param string[] $orderColumns @return array<int,array<string,mixed>> */
  private function property_history_rows(string $suffix, array $tokens, array $contractColumns, array $contractTokens, array $orderColumns): array
  {
    $table = $this->db->table($suffix);
    if (!$this->table_exists($table)) {
      return [];
    }
    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens), static fn(string $value): bool => trim($value) !== '')));
    $contractTokens = array_values(array_unique(array_filter(array_map('strval', $contractTokens), static fn(string $value): bool => trim($value) !== '')));
    $where = [];
    $args = [];
    foreach (['id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo', 'codigo_inmueble', 'id_inmueble_web'] as $column) {
      if ($tokens === [] || !$this->column_exists($table, $column)) {
        continue;
      }
      $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) IN (" . implode(',', array_fill(0, count($tokens), '?')) . ")";
      array_push($args, ...$tokens);
    }
    foreach ($contractColumns as $column) {
      $column = trim((string) $column);
      if ($contractTokens === [] || $column === '' || !$this->column_exists($table, $column)) {
        continue;
      }
      $where[] = "CAST(TRIM(COALESCE(`{$column}`, '')) AS CHAR) IN (" . implode(',', array_fill(0, count($contractTokens), '?')) . ")";
      array_push($args, ...$contractTokens);
    }
    if ($where === []) {
      return [];
    }
    $order = $this->property_history_order_clause($table, $orderColumns);
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where) . " {$order} LIMIT 600", $args);
  }

  /** @param string[] $columns */
  private function property_history_order_clause(string $table, array $columns): string
  {
    foreach ($columns as $column) {
      $column = trim((string) $column);
      if ($column !== '' && $this->column_exists($table, $column)) {
        return "ORDER BY `{$column}` DESC";
      }
    }
    return $this->column_exists($table, '_ID') ? 'ORDER BY `_ID` DESC' : '';
  }

  /** @return string[] */
  private function property_history_tokens_from_row(array $row): array
  {
    $tokens = [];
    foreach (['_ID', 'codigo', 'id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo_inmueble', 'id_inmueble_web'] as $field) {
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        $tokens[] = $value;
      }
    }
    return array_values(array_unique($tokens));
  }

  /** @return string[] */
  private function property_history_contract_tokens_from_row(array $row): array
  {
    $tokens = [];
    foreach (['contrato', 'id_contrato', 'contrato_arrendamiento'] as $field) {
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        $tokens[] = $value;
      }
    }
    return array_values(array_unique($tokens));
  }

  /** @return array<string,string> */
  private function property_history_property_info(string $query, array $property, array $contracts, string $contractNumber = ''): array
  {
    $lastContract = $contracts[0] ?? [];
    $codigo = trim((string) ($property['codigo'] ?? $property['_ID'] ?? $property['id_inmueble'] ?? $query));
    $direccion = trim((string) ($property['direccion'] ?? $property['direccion_fisica'] ?? $lastContract['direccion'] ?? ''));
    return [
      'codigo' => $codigo,
      'id_interno' => trim((string) ($property['_ID'] ?? '')),
      'direccion' => $direccion,
      'barrio' => trim((string) ($property['barrio'] ?? $lastContract['barrio'] ?? '')),
      'ciudad' => trim((string) ($property['ciudad'] ?? $lastContract['ciudad'] ?? '')),
      'propietario' => trim((string) ($property['propietario'] ?? $lastContract['propietario'] ?? '')),
      'tipo' => $this->property_history_join([$property['tipo_inmueble'] ?? '', $property['tipo_negocio'] ?? '', $property['destinacion'] ?? ''], ' / '),
      'matricula' => trim((string) ($property['matricula_inmobiliaria'] ?? $property['matricula'] ?? '')),
      'referencia_catastral' => trim((string) ($property['referencia_catastral'] ?? $property['referencia'] ?? '')),
    ];
  }

  /** @return array<string,mixed> */
  private function property_history_contract_summary_row(string $key, array $row): array
  {
    $number = $this->property_history_first($row, ['contrato', 'id_contrato', '_ID']);
    $dateRaw = $this->property_history_first($row, ['fecha_inicio', 'fecha', 'fecha_contrato', 'cct_created', 'cct_modified']);
    $endDate = $this->property_history_first($row, ['fecha_fin', 'fecha_finalizacion', 'fecha_terminacion', 'fecha_cierre']);
    $tenant = $this->property_history_first($row, ['arrendatario', 'inquilino', 'cliente']);
    $status = $this->property_history_first($row, ['estado', 'estado_contrato', 'estado_administrativo']);
    $canonRaw = $this->property_history_first($row, ['valor_canon', 'canon', 'canon_arrendamiento', 'precio_arriendo']);
    $type = $key === 'contratos_mandato' ? 'Administracion' : 'Arrendamiento';
    $relation = $this->property_history_join([
      $tenant !== '' ? 'Arrendatario: ' . $tenant : '',
      $this->property_history_first($row, ['propietario']) !== '' ? 'Propietario: ' . $this->property_history_first($row, ['propietario']) : '',
      $endDate !== '' ? 'Finaliza: ' . $this->property_history_date_label($endDate) : '',
    ], ' · ');

    return [
      'type' => $type,
      'number' => $number,
      'title' => 'Contrato ' . strtolower($type) . ' #' . ($number !== '' ? $number : '-'),
      'date' => $this->property_history_date_label($dateRaw),
      'date_ts' => $this->property_history_timestamp($dateRaw),
      'end_date' => $endDate !== '' ? $this->property_history_date_label($endDate) : '',
      'status' => $status,
      'tenant' => $tenant,
      'canon' => $this->format_cop_currency($canonRaw),
      'reference' => $relation,
    ];
  }

  /** @return array<string,mixed> */
  private function property_history_summarize_row(string $key, array $row): array
  {
    $id = trim((string) ($row['_ID'] ?? ''));
    $dateRaw = $this->property_history_first($row, ['fecha', 'fecha_revision', 'fecha_envio', 'fecha_satisfaccion', 'cct_created', 'cct_modified']);
    $ticket = $this->property_history_first($row, ['id_ticket', 'ticket', 'ticket_pk']);
    $contract = $this->property_history_first($row, ['contrato', 'id_contrato', 'contrato_arrendamiento']);
    $property = $this->property_history_first($row, ['id_inmueble', 'id_inmueble_data', 'inmueble', 'codigo']);
    $status = $this->property_history_first($row, ['estado', 'estado_administrativo', 'estatus']);
    $title = $this->property_history_title($key, $row, $id);
    $detail = $this->property_history_first($row, ['observacion', 'descripcion', 'reportes', 'respuesta', 'seguimiento_mantenimiento', 'recomendaciones', 'asunto', 'tema_ayuda', 'detalle']);
    $reference = $this->property_history_join([
      $ticket !== '' ? 'Caso #' . $ticket : '',
      $contract !== '' ? 'Contrato #' . $contract : '',
      $property !== '' ? 'Inmueble ' . $property : '',
      $status !== '' ? 'Estado: ' . $status : '',
    ], ' · ');

    return [
      'id' => $id,
      'date' => $this->property_history_date_label($dateRaw),
      'date_ts' => $this->property_history_timestamp($dateRaw),
      'title' => $this->metrics_execution_clean_text($title),
      'detail' => $this->metrics_execution_clean_text($detail),
      'reference' => $reference,
      'ticket' => $ticket,
      'contract' => $contract,
      'property' => $property,
      'status' => $status,
    ];
  }

  private function property_history_title(string $key, array $row, string $id): string
  {
    if ($key === 'tickets') {
      $ticket = $this->property_history_first($row, ['id_ticket', '_ID']);
      return 'Ticket #' . ($ticket !== '' ? $ticket : $id) . ' - ' . $this->property_history_first($row, ['asunto', 'tema_ayuda', 'categoria']);
    }
    if ($key === 'historial') {
      return $this->property_history_first($row, ['tipo_reporte', 'tipo', 'titulo']) ?: 'Movimiento de historial #' . $id;
    }
    if ($key === 'contratos_arrendamiento' || $key === 'contratos_mandato') {
      return 'Contrato #' . ($this->property_history_first($row, ['contrato', 'id_contrato', '_ID']) ?: $id);
    }
    if ($key === 'cotizaciones_mantenimiento') {
      return 'Cotizacion #' . ($id !== '' ? $id : $this->property_history_first($row, ['id_cotizacion']));
    }
    if ($key === 'revision_preventiva') {
      return 'Revision preventiva #' . ($id !== '' ? $id : '-');
    }
    if ($key === 'revision_correctiva') {
      return 'Revision correctiva #' . ($id !== '' ? $id : '-');
    }
    if ($key === 'revisiones_servicios') {
      return 'Revision servicios publicos #' . ($id !== '' ? $id : '-');
    }
    return ($id !== '' ? '#' . $id . ' ' : '') . ucfirst(str_replace('_', ' ', $key));
  }

  /** @param string[] $fields */
  private function property_history_first(array $row, array $fields): string
  {
    foreach ($fields as $field) {
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @param array<int,mixed> $items */
  private function property_history_join(array $items, string $separator): string
  {
    $parts = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $items), static fn(string $value): bool => $value !== '' && $value !== '-'));
    return implode($separator, $parts);
  }

  private function property_history_timestamp(string $value): int
  {
    $value = trim($value);
    if ($value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $number = (int) $value;
      return $number > 1000000000 ? $number : 0;
    }
    $time = strtotime($value);
    return $time ? $time : 0;
  }

  private function property_history_date_label(string $value): string
  {
    $time = $this->property_history_timestamp($value);
    return $time > 0 ? date('d/m/Y H:i', $time) : ($value !== '' ? $value : '-');
  }

  /** @param array<string,mixed> $payload */
  private function property_history_build_pdf(array $payload): \SCM\Support\SimplePdf
  {
    $pdf = new \SCM\Support\SimplePdf();
    $pdf->backgroundImage(dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg');
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Reporte de inmueble');
    $pdf->layout(58, 168, 118);
    $property = is_array($payload['property'] ?? null) ? $payload['property'] : [];
    $propertyLabel = (string) ($property['codigo'] ?? ($payload['property_code'] ?? ''));
    $pdf->actaHeader(
      'Reporte de inmueble',
      'Generado: ' . (string) ($payload['generated_at'] ?? date('d/m/Y H:i')) . ' | Inmueble ' . ($propertyLabel !== '' ? $propertyLabel : '-'),
      'Informe consolidado de actividad del inmueble'
    );
    $pdf->paragraph('Informe consolidado de actividades, reportes, gestiones, tickets, contratos, revisiones, cotizaciones y registros relacionados con el inmueble consultado.', 8);
    $pdf->heading('Identificacion del inmueble');
    $pdf->detailGrid([
      ['Codigo / inmueble', (string) ($property['codigo'] ?? '-')],
      ['ID interno', (string) ($property['id_interno'] ?? '-')],
      ['Direccion', (string) ($property['direccion'] ?? '-')],
      ['Barrio / ciudad', $this->property_history_join([$property['barrio'] ?? '', $property['ciudad'] ?? ''], ' / ') ?: '-'],
      ['Propietario', (string) ($property['propietario'] ?? '-')],
      ['Tipo', (string) ($property['tipo'] ?? '-')],
      ['Matricula inmobiliaria', (string) ($property['matricula'] ?? '-')],
      ['Referencia catastral', (string) ($property['referencia_catastral'] ?? '-')],
    ]);

    $contractRows = [];
    foreach ((array) ($payload['contracts'] ?? []) as $contract) {
      $contractRows[] = [
        (string) ($contract['type'] ?? ''),
        (string) ($contract['number'] ?? ''),
        (string) ($contract['status'] ?? ''),
        (string) (($contract['tenant'] ?? '') ?: ($contract['reference'] ?? '')),
        (string) ($contract['canon'] ?? ''),
        (string) ($contract['date'] ?? '-'),
      ];
    }
    $pdf->heading('Contratos vinculados al inmueble');
    if ($contractRows !== []) {
      $pdf->table(['Tipo', 'Contrato', 'Estado', 'Arrendatario / relacion', 'Canon', 'Fecha'], $contractRows, [0.16, 0.14, 0.16, 0.28, 0.13, 0.13], 7);
    } else {
      $pdf->paragraph('No se encontraron contratos vinculados para este inmueble en las fuentes consultadas.', 8);
    }

    $sourceRows = [];
    foreach ((array) ($payload['sources'] ?? []) as $source) {
      $sourceRows[] = [
        (string) ($source['label'] ?? ''),
        (string) ($source['description'] ?? ''),
        (string) ($source['count'] ?? 0),
      ];
    }
    $pdf->heading('Fuentes consultadas');
    $pdf->table(['Grupo', 'Contenido', 'Registros'], $sourceRows, [0.28, 0.56, 0.16], 7, [2]);

    $timelineRows = [];
    foreach (array_slice((array) ($payload['timeline'] ?? []), 0, 120) as $item) {
      $timelineRows[] = [
        (string) ($item['date'] ?? '-'),
        (string) ($item['source'] ?? ''),
        (string) ($item['title'] ?? ''),
        (string) ($item['reference'] ?? ''),
      ];
    }
    $pdf->heading('Cronologia consolidada');
    $pdf->table(['Fecha', 'Fuente', 'Actividad', 'Referencia'], $timelineRows, [0.18, 0.22, 0.38, 0.22], 7);

    foreach ((array) ($payload['sections'] ?? []) as $section) {
      $items = (array) ($section['items'] ?? []);
      if ($items === []) {
        continue;
      }
      $rows = [];
      foreach (array_slice($items, 0, 60) as $item) {
        $rows[] = [
          (string) ($item['date'] ?? '-'),
          (string) ($item['title'] ?? ''),
          (string) ($item['reference'] ?? ''),
          (string) ($item['detail'] ?? ''),
        ];
      }
      $pdf->heading((string) ($section['label'] ?? 'Detalle'));
      $pdf->table(['Fecha', 'Registro', 'Referencia', 'Detalle'], $rows, [0.18, 0.28, 0.22, 0.32], 7);
    }

    $actor = $this->property_history_current_employee_signature();
    $pdf->spacer(8);
    $pdf->signatureBlock('Informe generado por', $actor['name'], $actor['details']);
    $pdf->signatureBlock('Empresa', 'SKC SuCasa Inmobiliaria', 'NIT 900623242-4 | Cartagena de Indias - Colombia');

    return $pdf;
  }

  /** @return array{name:string,details:string} */
  private function property_history_current_employee_signature(): array
  {
    $name = trim((string) \SCM\Core\Auth::user());
    $details = 'Control Servicios Inmobiliarios';
    $userId = \SCM\Core\Auth::userId();
    $table = $this->db->table('jet_cct_funcionarios');
    if ($userId <= 0 || !$this->table_exists($table)) {
      return ['name' => $name !== '' ? $name : 'Control Servicios Inmobiliarios', 'details' => $details];
    }
    $emailExpr = $this->property_history_first_column_expr($table, 'f', ['correo', 'correo_empleado', 'email']);
    $phoneExpr = $this->property_history_first_column_expr($table, 'f', ['celular', 'celular_empleado', 'telefono', 'whatsapp']);
    $cargoTable = $this->db->table('jet_cct_cargos');
    $hasCargo = $this->table_exists($cargoTable) && $this->column_exists($cargoTable, 'nombre_cargo');
    $cargoSelect = $hasCargo ? "TRIM(COALESCE(c.`nombre_cargo`, '')) AS nombre_cargo" : "'' AS nombre_cargo";
    $cargoJoin = $hasCargo ? " LEFT JOIN `{$cargoTable}` c ON CAST(c.`_ID` AS CHAR) = TRIM(COALESCE(f.`id_cargo`, ''))" : '';
    $row = $this->db->getRow(
      "SELECT TRIM(COALESCE(f.`nombre`, '')) AS nombre,
              TRIM(COALESCE(f.`rol`, '')) AS rol,
              {$emailExpr} AS correo,
              {$phoneExpr} AS telefono,
              {$cargoSelect}
       FROM `{$table}` f
       {$cargoJoin}
       WHERE f.`_ID` = ?
       LIMIT 1",
      [$userId]
    );
    if (is_array($row)) {
      $name = trim((string) ($row['nombre'] ?? $name));
      $parts = array_values(array_filter([
        trim((string) ($row['nombre_cargo'] ?? '')) ?: trim((string) ($row['rol'] ?? '')),
        trim((string) ($row['correo'] ?? '')) !== '' ? 'Email: ' . trim((string) ($row['correo'] ?? '')) : '',
        trim((string) ($row['telefono'] ?? '')) !== '' ? 'Cel. ' . trim((string) ($row['telefono'] ?? '')) : '',
      ], static fn(string $value): bool => $value !== ''));
      $details = $parts !== [] ? implode(' | ', $parts) : $details;
    }
    return ['name' => $name !== '' ? $name : 'Control Servicios Inmobiliarios', 'details' => $details];
  }

  /** @param string[] $columns */
  private function property_history_first_column_expr(string $table, string $alias, array $columns): string
  {
    $parts = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $parts[] = "{$alias}.`{$column}`";
      }
    }
    return $parts !== []
      ? 'TRIM(COALESCE(' . implode(', ', $parts) . ", ''))"
      : "''";
  }
}
