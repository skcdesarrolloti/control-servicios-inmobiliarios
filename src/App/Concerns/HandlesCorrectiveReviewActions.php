<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Support\SchemaInspector;

trait HandlesCorrectiveReviewActions
{
  public function canAccessCorrectiveReview(int $ticketId): bool
  {
    if (!Auth::isLoggedIn() || $ticketId <= 0) {
      return false;
    }
    if ($this->canAccessDashboardTab('abiertos') || $this->canAccessDashboardTab('postergados')) {
      return true;
    }
    if (!$this->canAccessDashboardTab('mis_tickets')) {
      return false;
    }
    $ticket = $this->correctiveReviewTicket($ticketId);
    if (!$ticket) {
      return false;
    }
    $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
    return $employee
      && trim((string) ($employee['id_empleado'] ?? '')) !== ''
      && trim((string) ($ticket['id_empleado'] ?? '')) === trim((string) ($employee['id_empleado'] ?? ''));
  }

  public function ajax_handler_corrective_review(): void
  {
    $this->verifyCsrf();
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    try {
      if (!$this->canAccessCorrectiveReview($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar la revisión correctiva de este caso.');
      }
      $operation = trim((string) ($_POST['operation'] ?? 'read'));
      if ($operation === 'create') {
        $result = $this->correctiveReviewCreate($ticketId);
        $this->jsonOk($result + [
          'html' => $this->renderCorrectiveReviewPanel($this->correctiveReviewContext($ticketId)),
        ]);
      }
      if ($operation === 'edit') {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $this->correctiveReviewTargetRow($ticketId, $reviewId);
        $this->jsonOk([
          'html' => $this->renderCorrectiveReviewPanel($this->correctiveReviewContext($ticketId), $reviewId),
        ]);
      }
      if ($operation === 'update') {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $result = $this->correctiveReviewUpdate($ticketId, $reviewId);
        $this->jsonOk($result + [
          'html' => $this->renderCorrectiveReviewPanel($this->correctiveReviewContext($ticketId)),
        ]);
      }
      if ($operation === 'delete') {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $result = $this->correctiveReviewDelete($ticketId, $reviewId);
        $this->jsonOk($result + [
          'html' => $this->renderCorrectiveReviewPanel($this->correctiveReviewContext($ticketId)),
        ]);
      }
      if ($operation !== 'read') {
        throw new \DomainException('Operación de revisión correctiva no válida.');
      }
      $this->jsonOk([
        'html' => $this->renderCorrectiveReviewPanel($this->correctiveReviewContext($ticketId)),
      ]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  /** @return array<string,mixed> */
  private function correctiveReviewCreate(int $ticketId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_correctiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    $propertyHistoryTable = $this->db->table('jet_cct_historial_del_inmueble');
    foreach ([$reviewTable, $ticketTable] as $table) {
      if (!$schema->tableExists($table)) {
        throw new \DomainException('No está disponible la tabla requerida para guardar la revisión correctiva.');
      }
    }

    $context = $this->correctiveReviewContext($ticketId);
    $ticket = $context['ticket'];
    if (!is_array($ticket) || empty($ticket)) {
      throw new \DomainException('No se encontró el caso seleccionado.');
    }

    $items = $this->correctiveReviewInputItems();
    if (empty($items)) {
      throw new \DomainException('Agrega al menos un daño para guardar la revisión correctiva.');
    }

    $storedPhotos = [];
    try {
      $items = $this->correctiveReviewAttachUploadedPhotos($items, $storedPhotos);

      $actor = $this->ticketCompletionActor();
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $areaAfectada = $this->correctiveReviewCombinedAreas($items);
      $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
      $property = is_array($context['property'] ?? null) ? $context['property'] : [];
      $this->correctiveReviewValidateContext($ticket, $contract, $property);
      $ownerEmail = $this->correctiveReviewFirstText([$ticket['correo_propietario'] ?? '', $contract['correo_propietario'] ?? '']);
      $tenantEmail = $this->correctiveReviewFirstText([$ticket['correo_arrendatario'] ?? '', $contract['correo_arrendatario'] ?? '']);
      $ownerPhone = $this->correctiveReviewFirstText([$ticket['celular_propietario'] ?? '', $contract['celular_propietario'] ?? '']);
      $tenantPhone = $this->correctiveReviewFirstText([$ticket['celular_arrendatario'] ?? '', $contract['celular_arrendatario'] ?? '']);
      $reviewData = [
        'cct_status' => 'publish',
        'direccion' => $this->correctiveReviewFirstText([$ticket['direccion'] ?? '', $contract['direccion'] ?? '', $property['direccion'] ?? '', $property['direccion_fisica'] ?? '']),
        'tip_inm' => $this->correctiveReviewFirstText([$ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '', $property['tipo_inmueble'] ?? '']),
        'area_afectada' => $areaAfectada,
        'evaluacion_de_danos' => serialize($items),
        'cct_author_id' => Auth::userId(),
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'creador' => $actor['name'] ?? Auth::user(),
        'fecha' => $now,
        'id_ticket' => $ticketId,
        'id_inmueble' => $this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '', $property['_ID'] ?? '']),
        'tiene_cotizacion' => 'No',
        'destinatario' => $this->correctiveReviewFirstText([$ticket['propietario'] ?? '', $contract['propietario'] ?? '', $ticket['arrendatario'] ?? '', $contract['arrendatario'] ?? '']),
        'celular_destinatario' => $this->correctiveReviewFirstText([$ownerPhone, $tenantPhone]),
        'email_destinatario' => $this->correctiveReviewFirstText([$ownerEmail, $tenantEmail]),
        'contrato' => ltrim($this->correctiveReviewFirstText([$ticket['contrato'] ?? '', $contract['contrato'] ?? '', $ticket['id_contrato'] ?? '', $contract['_ID'] ?? '']), '#'),
        'inmueble' => $this->correctiveReviewFirstText([$ticket['inmueble'] ?? '', $contract['inmueble'] ?? '']),
        'id_empleado' => $this->correctiveReviewFirstText([$ticket['id_empleado'] ?? '', $actor['employee_id'] ?? '']),
        'id_propietario' => $this->correctiveReviewFirstText([$ticket['id_propietario'] ?? '', $contract['id_propietario'] ?? '', $property['id_propietario'] ?? '']),
        'id_arrendatario' => $this->correctiveReviewFirstText([$ticket['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '', $property['id_arrendatario'] ?? '']),
        'sucursal' => $this->correctiveReviewFirstText([$ticket['id_sucursal'] ?? '', $contract['sucursal'] ?? '', $property['sucursal'] ?? '']),
        'id_contrato' => $this->correctiveReviewFirstText([$ticket['id_contrato'] ?? '', $contract['_ID'] ?? '', $contract['contrato'] ?? '']),
        'email_creador' => $actor['email'] ?? '',
        'celular_creador' => $actor['phone'] ?? '',
        'coordinador' => $actor['name'] ?? '',
        'email_coordinador' => $actor['email'] ?? '',
        'celular_coordinador' => $actor['phone'] ?? '',
        'tipo_negocio' => $this->correctiveReviewFirstText([$ticket['tipo_negocio'] ?? '', $property['tipo_negocio'] ?? '', $contract['gestion_inmueble'] ?? '']),
        'destinacion' => $this->correctiveReviewFirstText([$ticket['destinacion'] ?? '', $property['destinacion'] ?? '', $contract['destinacion_inmueble'] ?? '']),
      ];
      $reviewData = $schema->filterTableData($reviewTable, $reviewData);
      if (!$this->db->insert($reviewTable, $reviewData)) {
        throw new \DomainException('No fue posible guardar la revisión correctiva.');
      }
      $reviewId = (int) $this->db->lastInsertId();
      if ($reviewId <= 0) {
        throw new \DomainException('La revisión correctiva se guardó sin identificador válido.');
      }

      if ($schema->tableExists($historyTable)) {
        $history = $schema->filterTableData($historyTable, [
          'cct_status' => 'publish',
          'cct_author_id' => Auth::userId(),
          'cct_created' => $nowSql,
          'cct_modified' => $nowSql,
          'id_ticket' => $ticketId,
          'fecha' => $now,
          'nombre' => $actor['name'] ?? Auth::user(),
          'correo' => $actor['email'] ?? '',
          'celular' => $actor['phone'] ?? '',
          'respuesta' => 'Se ha elaborado la revisión correctiva del inmueble. La cotización y el cobro se gestionarán posteriormente desde el flujo de cotizaciones.',
          'id_revision_correctiva' => $reviewId,
          'id_empleado' => $actor['employee_id'] ?? '',
        ]);
        if ($history) {
          $this->db->insert($historyTable, $history);
        }
      }

      if ($schema->tableExists($propertyHistoryTable)) {
        $propertyHistory = $schema->filterTableData($propertyHistoryTable, [
          'cct_status' => 'publish',
          'cct_author_id' => Auth::userId(),
          'cct_created' => $nowSql,
          'cct_modified' => $nowSql,
          'id_empleado' => $actor['employee_id'] ?? '',
          'id_inmueble' => $reviewData['id_inmueble'] ?? '',
          'fecha' => $now,
          'tipo_reporte' => 'Revision correctiva',
          'observacion' => 'Se ha realizado una revisión correctiva al inmueble desde el panel de servicios.',
          'funcionario' => $actor['name'] ?? Auth::user(),
          'id_ticket' => $ticketId,
          'id_inmueble_data' => $property['_ID'] ?? '',
        ]);
        if ($propertyHistory) {
          $this->db->insert($propertyHistoryTable, $propertyHistory);
        }
      }

      $existingIds = $this->correctiveReviewSplitIds((string) ($ticket['id_revision_correctiva'] ?? ''));
      $existingIds[] = (string) $reviewId;
      $ticketUpdate = $schema->filterTableData($ticketTable, [
        'estado' => 'En proceso',
        'estado_administrativo' => 'Inspeccionado',
        'estado_rev_correctiva' => 'Si',
        'estado_acta_cotizacion_mantenimiento' => 'No',
        'estado_acta_satisfaccion' => 'No',
        'estado_cotizacion_mantenimiento' => 'No',
        'se_encontraron_danos' => 'Si',
        'fecha_actualizacion' => $now,
        'id_revision_correctiva' => implode(',', array_values(array_unique(array_filter($existingIds)))),
      ]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
      }

      $queuedEmails = $this->correctiveReviewEnqueueCreatedNotifications($reviewData, $ticket, $contract, $actor, $reviewId);

      return [
        'message' => 'Revisión correctiva #' . $reviewId . ' guardada. ' . ($queuedEmails > 0 ? 'Correos en cola: ' . $queuedEmails . '.' : 'No se encolaron correos porque no hay destinatarios válidos.'),
        'review_id' => (string) $reviewId,
        'review_url' => self::DEFAULT_CORRECTIVA_URL . rawurlencode((string) $reviewId),
      ];
    } catch (\Throwable $error) {
      if ($storedPhotos) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      throw new \DomainException('No fue posible guardar la revisión correctiva.');
    }
  }

  /** @return array<string,mixed> */
  private function correctiveReviewUpdate(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_correctiva');
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    $propertyHistoryTable = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$schema->tableExists($reviewTable)) {
      throw new \DomainException('No está disponible la tabla requerida para actualizar la revisión correctiva.');
    }
    $context = $this->correctiveReviewContext($ticketId);
    $review = $this->correctiveReviewTargetRowFromContext($context, $reviewId);
    $items = $this->correctiveReviewInputItems();
    if (empty($items)) {
      throw new \DomainException('Agrega al menos un daño para actualizar la revisión correctiva.');
    }

    $storedPhotos = [];
    try {
      $items = $this->correctiveReviewAttachUploadedPhotos($items, $storedPhotos);
      $areaAfectada = $this->correctiveReviewCombinedAreas($items);
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $actor = $this->ticketCompletionActor();
      $update = $schema->filterTableData($reviewTable, [
        'evaluacion_de_danos' => serialize($items),
        'area_afectada' => $areaAfectada,
        'cct_modified' => $nowSql,
        'cct_author_id' => Auth::userId(),
      ]);
      if (!$update || $this->db->update($reviewTable, $update, ['_ID' => $reviewId]) < 0) {
        throw new \DomainException('No fue posible actualizar la revisión correctiva.');
      }

      if ($schema->tableExists($historyTable)) {
        $history = $schema->filterTableData($historyTable, [
          'cct_status' => 'publish',
          'cct_author_id' => Auth::userId(),
          'cct_created' => $nowSql,
          'cct_modified' => $nowSql,
          'id_ticket' => $ticketId,
          'fecha' => $now,
          'nombre' => $actor['name'] ?? Auth::user(),
          'correo' => $actor['email'] ?? '',
          'celular' => $actor['phone'] ?? '',
          'respuesta' => 'Se actualizó la revisión correctiva #' . $reviewId . ' del inmueble.',
          'id_revision_correctiva' => $reviewId,
          'id_empleado' => $actor['employee_id'] ?? '',
        ]);
        if ($history) {
          $this->db->insert($historyTable, $history);
        }
      }

      if ($schema->tableExists($propertyHistoryTable)) {
        $property = is_array($context['property'] ?? null) ? $context['property'] : [];
        $history = $schema->filterTableData($propertyHistoryTable, [
          'cct_status' => 'publish',
          'cct_author_id' => Auth::userId(),
          'cct_created' => $nowSql,
          'cct_modified' => $nowSql,
          'id_empleado' => $actor['employee_id'] ?? '',
          'id_inmueble' => $this->correctiveReviewFirstText([$review['id_inmueble'] ?? '', $property['_ID'] ?? '']),
          'fecha' => $now,
          'tipo_reporte' => 'Revision correctiva',
          'observacion' => 'Se actualizó la revisión correctiva #' . $reviewId . ' desde el panel de servicios.',
          'funcionario' => $actor['name'] ?? Auth::user(),
          'id_ticket' => $ticketId,
          'id_inmueble_data' => $property['_ID'] ?? '',
        ]);
        if ($history) {
          $this->db->insert($propertyHistoryTable, $history);
        }
      }

      return [
        'message' => 'Revisión correctiva #' . $reviewId . ' actualizada.',
        'review_id' => (string) $reviewId,
        'review_url' => self::DEFAULT_CORRECTIVA_URL . rawurlencode((string) $reviewId),
      ];
    } catch (\Throwable $error) {
      if ($storedPhotos) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      throw new \DomainException('No fue posible actualizar la revisión correctiva.');
    }
  }

  /** @return array<string,mixed> */
  private function correctiveReviewDelete(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_correctiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    if (!$schema->tableExists($reviewTable) || !$schema->tableExists($ticketTable)) {
      throw new \DomainException('No está disponible la tabla requerida para eliminar la revisión correctiva.');
    }
    $context = $this->correctiveReviewContext($ticketId);
    $this->correctiveReviewTargetRowFromContext($context, $reviewId);
    $ticket = is_array($context['ticket'] ?? null) ? $context['ticket'] : [];
    $deleted = $this->db->delete($reviewTable, ['_ID' => $reviewId]);
    if ($deleted !== 1) {
      throw new \DomainException('No fue posible eliminar la revisión correctiva.');
    }

    $remainingIds = array_values(array_diff($this->correctiveReviewSplitIds((string) ($ticket['id_revision_correctiva'] ?? '')), [(string) $reviewId]));
    $ticketUpdate = $schema->filterTableData($ticketTable, [
      'id_revision_correctiva' => implode(',', $remainingIds),
      'estado_rev_correctiva' => $remainingIds ? 'Si' : 'No',
      'fecha_actualizacion' => time(),
    ]);
    if ($ticketUpdate) {
      $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
    }

    if ($schema->tableExists($historyTable)) {
      $now = time();
      $actor = $this->ticketCompletionActor();
      $history = $schema->filterTableData($historyTable, [
        'cct_status' => 'publish',
        'cct_author_id' => Auth::userId(),
        'cct_created' => date('Y-m-d H:i:s', $now),
        'cct_modified' => date('Y-m-d H:i:s', $now),
        'id_ticket' => $ticketId,
        'fecha' => $now,
        'nombre' => $actor['name'] ?? Auth::user(),
        'correo' => $actor['email'] ?? '',
        'celular' => $actor['phone'] ?? '',
        'respuesta' => 'Se eliminó la revisión correctiva #' . $reviewId . '.',
        'id_empleado' => $actor['employee_id'] ?? '',
      ]);
      if ($history) {
        $this->db->insert($historyTable, $history);
      }
    }

    return [
      'message' => 'Revisión correctiva #' . $reviewId . ' eliminada.',
      'review_id' => (string) $reviewId,
    ];
  }

  /** @param array<int,array<string,string>> $items @param array<int,array{name:string,url:string,mime:string,width:int,height:int,bytes:int,sha256:string}> $storedPhotos @return array<int,array<string,string>> */
  private function correctiveReviewAttachUploadedPhotos(array $items, array &$storedPhotos): array
  {
    $photoTotal = 0;
    foreach ($items as $index => &$item) {
      $existingPhotos = $this->correctiveReviewSplitPhotoRefs((string) ($item['registro_foto_dano'] ?? ''));
      $field = 'corrective_review_photos_' . $index;
      $names = $_FILES[$field]['name'] ?? [];
      $names = is_array($names) ? array_values(array_filter($names, static fn($name): bool => trim((string) $name) !== '')) : [];
      if ((count($existingPhotos) + count($names)) > 10) {
        throw new \DomainException('Cada daño admite máximo 10 fotos.');
      }
      $photoTotal += count($existingPhotos) + count($names);
      if ($photoTotal > 30) {
        throw new \DomainException('La revisión admite máximo 30 fotos en total.');
      }
      if ($names) {
        $photos = $this->handleImageUploadsDetailed($field, 10);
        if (count($photos) !== count($names)) {
          throw new \DomainException('No se pudieron procesar todas las fotos. Usa imágenes JPG, PNG o WebP de máximo ' . (int) floor(SCM_UPLOAD_MAX_BYTES / 1048576) . ' MB cada una.');
        }
        foreach ($photos as $photo) {
          if ($photo['mime'] !== 'image/jpeg' || $photo['width'] > 1600 || $photo['height'] > 1600 || $photo['bytes'] > 1500000) {
            throw new \DomainException('Una foto no pudo comprimirse por debajo de 1,5 MB y 1600 px. Prueba con otra imagen.');
          }
        }
        $storedPhotos = array_merge($storedPhotos, $photos);
        $existingPhotos = array_merge($existingPhotos, array_map(static fn(array $photo): string => (string) $photo['url'], $photos));
      }
      $item['registro_foto_dano'] = implode(',', array_values(array_unique(array_filter($existingPhotos))));
    }
    unset($item);
    return $items;
  }

  /** @return array<int,array<string,string>> */
  private function correctiveReviewInputItems(): array
  {
    $rawItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $out = [];
    foreach ($rawItems as $sourceIndex => $raw) {
      if (!is_array($raw)) {
        continue;
      }
      if (!preg_match('/^\d+$/D', (string) $sourceIndex)) {
        continue;
      }
      $indice = $this->correctiveReviewText($raw['indice'] ?? '');
      $areas = [
        'area_afectada_1' => $this->correctiveReviewText($raw['area_afectada_1'] ?? ''),
        'area_afectada_2' => $this->correctiveReviewText($raw['area_afectada_2'] ?? ''),
        'area_afectada_3' => $this->correctiveReviewText($raw['area_afectada_3'] ?? ''),
        'area_afectada_4' => $this->correctiveReviewText($raw['area_afectada_4'] ?? ''),
      ];
      $legacyArea = $this->correctiveReviewText($raw['area_afectada'] ?? '');
      $areaKey = $this->correctiveReviewAreaKeyForIndice($indice);
      $area = $areas[$areaKey] ?? '';
      if ($area === '') {
        $area = $legacyArea !== '' ? $legacyArea : $this->correctiveReviewFirstText(array_values($areas));
      }
      $descripcion = $this->correctiveReviewHtml($raw['descripcion_dano'] ?? '');
      $consecuencia = $this->correctiveReviewHtml($raw['consecuencia'] ?? '');
      $nivel = $this->correctiveReviewText($raw['nivel_dano'] ?? '');
      $tiempo = $this->correctiveReviewText($raw['tiempo_atencion'] ?? '');
      $corresponde = $this->correctiveReviewText($raw['a_quien_corresponde'] ?? '');
      $existingPhotos = $this->correctiveReviewSafePhotoRefs($raw['existing_fotos'] ?? '');
      if ($indice === '' && $area === '' && $descripcion === '' && $consecuencia === '' && $nivel === '' && $tiempo === '' && $corresponde === '' && !$existingPhotos) {
        continue;
      }
      foreach ([
        'indice' => $indice,
        'área afectada' => $area,
        'descripción del daño' => $descripcion,
        'consecuencia' => $consecuencia,
        'nivel del daño' => $nivel,
        'tiempo de atención' => $tiempo,
      ] as $label => $value) {
        if ($value === '') {
          throw new \DomainException('Completa ' . $label . ' en todos los daños.');
        }
      }
      $item = [
        'indice' => $indice,
        'area_afectada_1' => '',
        'area_afectada_2' => '',
        'area_afectada_3' => '',
        'area_afectada_4' => '',
        'registro_foto_dano' => implode(',', $existingPhotos),
        'descripcion_dano' => $descripcion,
        'consecuencia' => $consecuencia,
        'nivel_dano' => $nivel,
        'tiempo_atencion' => $tiempo,
        'a_quien_corresponde' => $corresponde,
      ];
      $item[$areaKey] = $area;
      $out[(int) $sourceIndex] = $item;
      if (count($out) >= 30) {
        break;
      }
    }
    return $out;
  }

  /** @return array<string,mixed> */
  private function correctiveReviewTargetRow(int $ticketId, int $reviewId): array
  {
    return $this->correctiveReviewTargetRowFromContext($this->correctiveReviewContext($ticketId), $reviewId);
  }

  /** @param array<string,mixed> $context @return array<string,mixed> */
  private function correctiveReviewTargetRowFromContext(array $context, int $reviewId): array
  {
    if ($reviewId <= 0) {
      throw new \DomainException('Selecciona una revisión correctiva válida.');
    }
    $reviews = is_array($context['reviews'] ?? null) ? $context['reviews'] : [];
    foreach ($reviews as $review) {
      if ((int) ($review['_ID'] ?? 0) === $reviewId) {
        return $review;
      }
    }
    throw new \DomainException('No se encontró la revisión correctiva de este caso.');
  }

  /** @return array<string,mixed> */
  private function correctiveReviewContext(int $ticketId): array
  {
    $ticket = $this->correctiveReviewTicket($ticketId);
    if (!$ticket) {
      throw new \DomainException('No se encontró el caso seleccionado.');
    }
    $contract = $this->correctiveReviewContract($ticket);
    $property = $this->correctiveReviewProperty($ticket, $contract);
    $this->correctiveReviewValidateContext($ticket, $contract, $property);
    return [
      'ticket' => $ticket,
      'contract' => $contract,
      'property' => $property,
      'reviews' => $this->correctiveReviewRows($ticketId, $ticket),
    ];
  }

  /** @return array<string,mixed> */
  private function correctiveReviewTicket(int $ticketId): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$ticketId]) ?: [];
  }

  /** @return array<string,mixed> */
  private function correctiveReviewContract(array $ticket): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->table_exists($table)) {
      return [];
    }
    $idContrato = $this->correctiveReviewDigits($ticket['id_contrato'] ?? '');
    if ($idContrato !== '') {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$idContrato]);
      if ($row) {
        return $row;
      }
    }
    $contrato = $this->correctiveReviewDigits($ticket['contrato'] ?? '');
    if ($contrato !== '') {
      return $this->db->getRow("SELECT * FROM `{$table}` WHERE `contrato` = ? LIMIT 1", [$contrato]) ?: [];
    }
    return [];
  }

  /** @return array<string,mixed> */
  private function correctiveReviewProperty(array $ticket, array $contract): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->table_exists($table)) {
      return [];
    }
    foreach ([$contract['id_inmueble_data'] ?? '', $ticket['id_inmueble_data'] ?? ''] as $candidate) {
      $id = $this->correctiveReviewDigits($candidate);
      $row = $id !== '' ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) : null;
      if ($row) {
        return $row;
      }
    }
    $contractId = $this->correctiveReviewDigits($contract['_ID'] ?? ($ticket['id_contrato'] ?? ''));
    if ($contractId !== '') {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_contrato_arrendamiento` = ? LIMIT 1", [$contractId]);
      if ($row) {
        return $row;
      }
    }
    foreach ([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '', $ticket['inmueble'] ?? '', $contract['inmueble'] ?? ''] as $candidate) {
      $id = $this->correctiveReviewDigits($candidate);
      if ($id === '') {
        continue;
      }
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `codigo` = ? OR `id_ticket` = ? LIMIT 1", [$id, $id]);
      if ($row) {
        return $row;
      }
    }
    return [];
  }

  /**
   * Evita guardar revisiones con datos mezclados de otro contrato/inmueble.
   *
   * @param array<string,mixed> $ticket
   * @param array<string,mixed> $contract
   * @param array<string,mixed> $property
   */
  private function correctiveReviewValidateContext(array $ticket, array $contract, array $property): void
  {
    if ($contract) {
      $ticketContractId = $this->correctiveReviewDigits($ticket['id_contrato'] ?? '');
      $contractId = $this->correctiveReviewDigits($contract['_ID'] ?? '');
      if ($ticketContractId !== '' && $contractId !== '' && $ticketContractId !== $contractId) {
        throw new \DomainException('El contrato encontrado no coincide con el contrato interno del caso. Recarga el caso antes de crear la revisión.');
      }

      $ticketContract = $this->correctiveReviewDigits($ticket['contrato'] ?? '');
      $contractNumber = $this->correctiveReviewDigits($contract['contrato'] ?? '');
      if ($ticketContract !== '' && $contractNumber !== '' && $ticketContract !== $contractNumber) {
        throw new \DomainException('El número de contrato encontrado no coincide con el número de contrato del caso. Recarga el caso antes de crear la revisión.');
      }

      $ticketPropertyId = $this->correctiveReviewDigits($ticket['id_inmueble'] ?? '');
      $contractPropertyId = $this->correctiveReviewDigits($contract['id_inmueble'] ?? '');
      if ($ticketPropertyId !== '' && $contractPropertyId !== '' && $ticketPropertyId !== $contractPropertyId) {
        throw new \DomainException('El inmueble encontrado no coincide con el inmueble del caso. Recarga el caso antes de crear la revisión.');
      }

      $ticketPropertyCode = $this->correctiveReviewDigits($ticket['inmueble'] ?? '');
      $contractPropertyCode = $this->correctiveReviewDigits($contract['inmueble'] ?? '');
      if ($ticketPropertyCode !== '' && $contractPropertyCode !== '' && $ticketPropertyCode !== $contractPropertyCode) {
        throw new \DomainException('El código de inmueble encontrado no coincide con el código del caso. Recarga el caso antes de crear la revisión.');
      }
    }

    if (!$property) {
      return;
    }

    $propertyId = $this->correctiveReviewDigits($property['_ID'] ?? '');
    foreach ([$contract['id_inmueble_data'] ?? '', $ticket['id_inmueble_data'] ?? ''] as $candidate) {
      $expectedId = $this->correctiveReviewDigits($candidate);
      if ($expectedId !== '' && $propertyId !== '' && $expectedId !== $propertyId) {
        throw new \DomainException('El inmueble de datos encontrado no corresponde al caso. Recarga el caso antes de crear la revisión.');
      }
    }

    $contractId = $this->correctiveReviewDigits($contract['_ID'] ?? ($ticket['id_contrato'] ?? ''));
    $propertyContractId = $this->correctiveReviewDigits($property['id_contrato_arrendamiento'] ?? '');
    if ($contractId !== '' && $propertyContractId !== '' && $contractId !== $propertyContractId) {
      throw new \DomainException('El inmueble encontrado pertenece a otro contrato. Recarga el caso antes de crear la revisión.');
    }
  }

  /**
   * @param array<string,mixed> $review
   * @param array<string,mixed> $ticket
   * @param array<string,mixed> $contract
   * @param array<string,mixed> $actor
   */
  private function correctiveReviewEnqueueCreatedNotifications(array $review, array $ticket, array $contract, array $actor, int $reviewId): int
  {
    $reviewUrl = self::DEFAULT_CORRECTIVA_URL . rawurlencode((string) $reviewId);
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) ($ticket['_ID'] ?? '');
    $propertyCode = $this->correctiveReviewFirstText([$review['inmueble'] ?? '', $ticket['inmueble'] ?? '', $contract['inmueble'] ?? '', $review['id_inmueble'] ?? '']);
    $address = $this->correctiveReviewFirstText([$review['direccion'] ?? '', $ticket['direccion'] ?? '', $contract['direccion'] ?? '']);
    $creatorName = trim((string) ($actor['name'] ?? $review['creador'] ?? Auth::user()));
    $creatorEmail = trim((string) ($actor['email'] ?? $review['email_creador'] ?? ''));
    $ownerName = $this->correctiveReviewFirstText([$review['destinatario'] ?? '', $ticket['propietario'] ?? '', $contract['propietario'] ?? 'Propietario']);
    $ownerEmail = $this->correctiveReviewFirstText([$review['email_destinatario'] ?? '', $ticket['correo_propietario'] ?? '', $contract['correo_propietario'] ?? '']);

    $recipients = [
      ['name' => $creatorName, 'email' => $creatorEmail, 'role' => 'creador'],
      ['name' => $ownerName, 'email' => $ownerEmail, 'role' => 'propietario'],
    ];
    foreach ($this->correctiveReviewInternalEmailRecipients('revision_correctiva_creada') as $recipient) {
      $recipients[] = [
        'name' => (string) ($recipient['name'] ?? 'Administración SuCasa'),
        'email' => (string) ($recipient['email'] ?? ''),
        'role' => 'funcionario_configurado',
      ];
    }
    $recipients = $this->correctiveReviewUniqueEmailRecipients($recipients);
    if ($recipients === []) {
      return 0;
    }

    $subject = 'Revisión correctiva agregada' . ($propertyCode !== '' ? ' del inmueble #' . $propertyCode : '') . ($ticketLabel !== '' ? ' - caso #' . $ticketLabel : '');
    $queued = 0;
    $queue = new \SCM\Support\EmailQueue($this->db);

    foreach ($recipients as $recipient) {
      $name = trim((string) ($recipient['name'] ?? 'Usuario')) ?: 'Usuario';
      $role = trim((string) ($recipient['role'] ?? 'destinatario'));
      $content = '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . \SCM\Support\EmailTemplate::e($name) . ':</p>';
      $content .= '<p style="margin:0 0 14px;line-height:1.65;">Se registró una revisión correctiva del inmueble' . ($propertyCode !== '' ? ' <b>#' . \SCM\Support\EmailTemplate::e($propertyCode) . '</b>' : '') . ($address !== '' ? ', ubicado en ' . \SCM\Support\EmailTemplate::e($address) : '') . '.</p>';
      if ($ticketLabel !== '') {
        $content .= '<p style="margin:0 0 14px;line-height:1.65;"><b>Caso:</b> #' . \SCM\Support\EmailTemplate::e($ticketLabel) . '.</p>';
      }
      $content .= '<p style="margin:0;line-height:1.65;">Puedes consultar el informe para revisar los daños registrados y continuar el proceso desde la cotización cuando corresponda.</p>';
      $html = \SCM\Support\EmailTemplate::render($subject, $content, [
        'buttons' => [
          ['url' => $reviewUrl, 'label' => 'Consultar informe de inspección'],
        ],
      ]);

      $queued += $queue->enqueue((string) $recipient['email'], $subject, $html, [
        'source_module' => 'revision_correctiva',
        'destination_name' => $name,
        'dedupe_key' => 'revision-correctiva-creada:' . $reviewId . ':' . $role,
        'payload' => [
          'review_url' => $reviewUrl,
          'reply_to' => $creatorEmail,
        ],
        'meta' => [
          'event' => 'revision_correctiva_creada',
          'role' => $role,
          'id_revision_correctiva' => $reviewId,
          'id_ticket' => $ticketLabel,
          'id_contrato' => trim((string) ($review['id_contrato'] ?? $ticket['id_contrato'] ?? $contract['_ID'] ?? '')),
          'contrato' => trim((string) ($review['contrato'] ?? $ticket['contrato'] ?? $contract['contrato'] ?? '')),
          'inmueble' => $propertyCode,
          'actor' => $creatorName,
        ],
      ]);
    }

    return $queued;
  }

  /** @return array<int,array{name:string,email:string}> */
  private function correctiveReviewInternalEmailRecipients(string $action): array
  {
    $selectedIds = array_map('strval', $this->internalNotificationRecipientsForAction($action));
    if ($selectedIds === []) {
      return [];
    }

    $selected = array_fill_keys($selectedIds, true);
    $recipients = [];
    foreach ($this->internalNotificationFuncionarioOptions() as $funcionario) {
      $id = trim((string) ($funcionario['id'] ?? ''));
      $email = trim((string) ($funcionario['email'] ?? ''));
      if ($id === '' || !isset($selected[$id]) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $recipients[] = [
        'name' => trim((string) ($funcionario['name'] ?? '')),
        'email' => $email,
      ];
    }

    return $this->correctiveReviewUniqueEmailRecipients($recipients);
  }

  /** @param array<int,array<string,mixed>> $recipients @return array<int,array{name:string,email:string,role:string}> */
  private function correctiveReviewUniqueEmailRecipients(array $recipients): array
  {
    $seen = [];
    $out = [];
    foreach ($recipients as $recipient) {
      $email = strtolower(trim((string) ($recipient['email'] ?? '')));
      if ($email === '' || isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $seen[$email] = true;
      $out[] = [
        'name' => trim((string) ($recipient['name'] ?? '')),
        'email' => $email,
        'role' => trim((string) ($recipient['role'] ?? 'destinatario')),
      ];
    }
    return $out;
  }

  /** @return array<int,array<string,mixed>> */
  private function correctiveReviewRows(int $ticketId, array $ticket): array
  {
    $table = $this->db->table('jet_cct_revision_correctiva');
    if (!$this->table_exists($table)) {
      return [];
    }
    $ids = $this->correctiveReviewSplitIds((string) ($ticket['id_revision_correctiva'] ?? ''));
    $rows = [];
    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN ({$placeholders}) ORDER BY `_ID` DESC", $ids);
    }
    $byId = [];
    foreach ($rows as $row) {
      $byId[(string) ($row['_ID'] ?? '')] = $row;
    }
    $linked = $this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? ORDER BY `_ID` DESC LIMIT 10", [$ticketId]);
    foreach ($linked as $row) {
      $byId[(string) ($row['_ID'] ?? '')] = $row;
    }
    return array_values($byId);
  }

  /** @param array<string,mixed> $context */
  private function renderCorrectiveReviewPanel(array $context, int $editReviewId = 0): string
  {
    $ticket = is_array($context['ticket'] ?? null) ? $context['ticket'] : [];
    $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
    $property = is_array($context['property'] ?? null) ? $context['property'] : [];
    $reviews = is_array($context['reviews'] ?? null) ? $context['reviews'] : [];
    $ticketId = (int) ($ticket['_ID'] ?? 0);
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketId;
    $idInmueble = $this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '', $property['_ID'] ?? '']);
    $contrato = ltrim($this->correctiveReviewFirstText([$ticket['contrato'] ?? '', $ticket['id_contrato'] ?? '', $contract['contrato'] ?? '']), '#');
    $inmueble = $this->correctiveReviewFirstText([$ticket['inmueble'] ?? '', $contract['inmueble'] ?? '']);
    $direccion = $this->correctiveReviewFirstText([$ticket['direccion'] ?? '', $contract['direccion'] ?? '', $property['direccion'] ?? '', $property['direccion_fisica'] ?? '']);
    $editReview = [];
    if ($editReviewId > 0) {
      foreach ($reviews as $review) {
        if ((int) ($review['_ID'] ?? 0) === $editReviewId) {
          $editReview = $review;
          break;
        }
      }
    }
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review">
      <p class="scm-acta-notice">Esta revisión correctiva registra los daños encontrados y deja el caso como <strong>Inspeccionado</strong>. La cotización y el cobro se gestionarán después, desde el flujo de cotizaciones.</p>
      <div class="scm-acta-meta">
        <strong>Caso #<?= $h($ticketLabel) ?></strong>
        <span>Inmueble <?= $h($idInmueble !== '' ? $idInmueble : '-') ?> · Contrato <?= $h($contrato !== '' ? $contrato : '-') ?></span>
      </div>
      <div class="scm-corrective-summary">
        <div><span>Inmueble interno</span><strong><?= $h($inmueble !== '' ? $inmueble : '-') ?></strong></div>
        <div><span>Dirección</span><strong><?= $h($direccion !== '' ? $direccion : '-') ?></strong></div>
        <div><span>Propietario</span><strong><?= $h($this->correctiveReviewFirstText([$ticket['propietario'] ?? '', $contract['propietario'] ?? '']) ?: '-') ?></strong></div>
        <div><span>Arrendatario</span><strong><?= $h($this->correctiveReviewFirstText([$ticket['arrendatario'] ?? '', $contract['arrendatario'] ?? '']) ?: '-') ?></strong></div>
      </div>
      <?php if ($reviews): ?>
        <section class="scm-corrective-existing">
          <h3>Revisiones correctivas registradas</h3>
          <?php foreach ($reviews as $review): $id = trim((string) ($review['_ID'] ?? '')); ?>
            <article>
              <div class="scm-corrective-existing-info">
                <strong>Revisión #<?= $h($id) ?></strong>
                <span><?= $h($this->correctiveReviewDateLabel($review['fecha'] ?? $review['cct_created'] ?? '')) ?></span>
              </div>
              <div class="scm-corrective-existing-actions">
                <?php if ($id !== ''): ?><a class="scm-acta-button scm-acta-secondary" href="<?= $h(self::DEFAULT_CORRECTIVA_URL . rawurlencode($id)) ?>" target="_blank" rel="noopener">Ver informe</a><?php endif; ?>
                <?php if ($id !== ''): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-corrective-edit-review="<?= $h($id) ?>">Editar</button><?php endif; ?>
                <?php if ($id !== ''): ?><button type="button" class="scm-acta-button scm-acta-danger" data-corrective-delete-review="<?= $h($id) ?>">Eliminar</button><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
      <?php if ($editReview): ?>
        <?php $editItems = $this->correctiveReviewStoredItems($editReview['evaluacion_de_danos'] ?? []); ?>
        <form data-corrective-review-edit autocomplete="off" enctype="multipart/form-data">
          <input type="hidden" name="ticket_pk" value="<?= $h((string) $ticketId) ?>">
          <input type="hidden" name="review_id" value="<?= $h((string) $editReviewId) ?>">
          <section>
            <h3>Editando revisión #<?= $h((string) $editReviewId) ?></h3>
            <p class="scm-acta-help">Al guardar se reemplazan los datos de la revisión. Las fotos que dejes marcadas se conservan; las nuevas se comprimen antes de subir.</p>
            <div data-corrective-review-items>
              <?php foreach (($editItems ?: [[]]) as $index => $item): ?>
                <?= $this->renderCorrectiveReviewItem((int) $index, is_array($item) ? $item : []) ?>
              <?php endforeach; ?>
            </div>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-corrective-add-item>Agregar otro daño</button>
          </section>
          <div class="scm-acta-actions">
            <button type="submit" class="scm-acta-button">Actualizar revisión correctiva</button>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-corrective-cancel-edit>Cancelar edición</button>
            <span data-corrective-review-message aria-live="polite"></span>
          </div>
        </form>
      <?php elseif (!$reviews): ?>
        <form data-corrective-review-create autocomplete="off" enctype="multipart/form-data">
          <input type="hidden" name="ticket_pk" value="<?= $h((string) $ticketId) ?>">
          <section>
            <h3>Nueva revisión correctiva</h3>
            <div data-corrective-review-items>
              <?= $this->renderCorrectiveReviewItem(0) ?>
            </div>
            <button type="button" class="scm-acta-button scm-acta-secondary" data-corrective-add-item>Agregar otro daño</button>
          </section>
          <div class="scm-acta-actions">
            <button type="submit" class="scm-acta-button">Guardar revisión correctiva</button>
            <span data-corrective-review-message aria-live="polite"></span>
          </div>
        </form>
      <?php else: ?>
        <div class="scm-acta-empty">
          <strong>Este caso ya tiene revisión correctiva.</strong>
          <span>Para modificarla usa Editar; si fue una prueba, usa Eliminar y luego podrás crear una nueva revisión.</span>
          <span data-corrective-review-message aria-live="polite"></span>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $item */
  private function renderCorrectiveReviewItem(int $index, array $item = []): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $indice = $this->correctiveReviewText($item['indice'] ?? '');
    $activeAreaKey = $this->correctiveReviewAreaKeyForIndice($indice);
    $indiceOptions = $this->correctiveReviewGlossaryOptions(585, [
      'Evaluacion de los daños en elementos arquitectonicos' => 'Elementos arquitectónicos',
      'Evaluacion de los daños en elementos estructurales' => 'Elementos estructurales',
      'Otros inconvenientes al inmueble accesos y usos conexos' => 'Otros inconvenientes / accesos y usos conexos',
      'Servicios publicos' => 'Servicios públicos',
    ]);
    $areaArquitectonicaOptions = $this->correctiveReviewGlossaryOptions(581, [
      'Muros de fachada o antepechos' => 'Muros de fachada o antepechos',
      'Muros divisorios' => 'Muros divisorios (Mamposteria, estucos, pinturas)',
      'Cielos rasos y luminarias' => 'Cielos rasos y luminarias',
      'Cubiertas' => 'Cubiertas (Fibrocemento, placa, termo acustic, metalicas. etc) canales y Bajantes',
      'Escaleras' => 'Escaleras',
      'Instalaciones' => 'Instalaciones (Acueducto, Alcantarillado, Energia y Gas)',
      'Tanques elevados' => 'Tanques elevados',
      'Muebles' => 'Muebles (Cocinas, baños, closets, otros muebles instalados en el inmueble)',
      'Puertas y Ventanas' => 'Puertas y Ventanas (Madera, metalicas, alumininos y otras)',
      'Equipos y/o electrodomesticos instalados' => 'Equipos y/o electrodomesticos instalados',
      'Pisos (Ceramica,Porcelanatos,Marmol, Estado de Juntas, Maderas, Laminados)' => 'Pisos (Ceramica,Porcelanatos,Marmol, Estado de Juntas, Maderas, Laminados)',
    ]);
    $areaEstructuralOptions = $this->correctiveReviewGlossaryOptions(582, [
      'Vigas, columnas' => 'Vigas, columnas y muros estructurales en (Concreto reforzado, Madera o Acero)',
      'Mamposteria' => 'Mampostería',
      'Muros' => 'Muros',
      'Entrepisos' => 'Entrepisos (incluye placa de cubierta)',
    ]);
    $nivelOptions = $this->correctiveReviewGlossaryOptions(583, [
      'Muy leve' => 'Muy leve',
      'Leve' => 'Leve',
      'Moderado' => 'Moderado',
      'Fuerte' => 'Fuerte',
      'Severo' => 'Severo',
    ]);
    $tiempoOptions = $this->correctiveReviewGlossaryOptions(584, [
      'De inmediato' => 'De inmediato',
      '2 dias' => '2 dias',
      '8 dias' => '8 dias',
    ]);
    $correspondeOptions = $this->correctiveReviewGlossaryOptions(619, [
      'Propietario' => 'Propietario',
      'Arrendatario' => 'Arrendatario',
      'Administracion' => 'Administracion',
      'Fabricante' => 'Fabricante',
      'Constructor' => 'Constructor',
    ]);
    $photos = $this->correctiveReviewSplitPhotoRefs((string) ($item['registro_foto_dano'] ?? ''));
    ob_start();
    ?>
    <fieldset class="scm-acta-item scm-corrective-item" data-corrective-item>
      <legend>Daño #<?= $h((string) ($index + 1)) ?></legend>
      <div class="scm-acta-grid">
        <label>Índice *
          <select name="items[<?= $h((string) $index) ?>][indice]" required data-corrective-indice>
            <?= $this->correctiveReviewRenderOptions($indiceOptions, $indice, 'Seleccionar índice') ?>
          </select>
        </label>
        <div class="scm-corrective-area-field">
          <span>Área afectada *</span>
          <div data-corrective-area-group data-corrective-area-for="area_afectada_1"<?= $activeAreaKey === 'area_afectada_1' ? '' : ' hidden' ?>>
          <?php if ($areaArquitectonicaOptions): ?>
            <select name="items[<?= $h((string) $index) ?>][area_afectada_1]" data-corrective-area-field<?= $activeAreaKey === 'area_afectada_1' ? ' required' : ' disabled' ?>>
              <?= $this->correctiveReviewRenderOptions($areaArquitectonicaOptions, $item['area_afectada_1'] ?? $item['area_afectada'] ?? '', 'Elige un área') ?>
            </select>
          <?php else: ?>
            <input type="text" name="items[<?= $h((string) $index) ?>][area_afectada_1]" data-corrective-area-field placeholder="Escribe un área" value="<?= $h($item['area_afectada_1'] ?? $item['area_afectada'] ?? '') ?>"<?= $activeAreaKey === 'area_afectada_1' ? ' required' : ' disabled' ?>>
          <?php endif; ?>
          </div>
          <div data-corrective-area-group data-corrective-area-for="area_afectada_2"<?= $activeAreaKey === 'area_afectada_2' ? '' : ' hidden' ?>>
          <?php if ($areaEstructuralOptions): ?>
            <select name="items[<?= $h((string) $index) ?>][area_afectada_2]" data-corrective-area-field<?= $activeAreaKey === 'area_afectada_2' ? ' required' : ' disabled' ?>>
              <?= $this->correctiveReviewRenderOptions($areaEstructuralOptions, $item['area_afectada_2'] ?? $item['area_afectada'] ?? '', 'Elige un área') ?>
            </select>
          <?php else: ?>
            <input type="text" name="items[<?= $h((string) $index) ?>][area_afectada_2]" data-corrective-area-field placeholder="Escribe un área" value="<?= $h($item['area_afectada_2'] ?? $item['area_afectada'] ?? '') ?>"<?= $activeAreaKey === 'area_afectada_2' ? ' required' : ' disabled' ?>>
          <?php endif; ?>
          </div>
          <div data-corrective-area-group data-corrective-area-for="area_afectada_3"<?= $activeAreaKey === 'area_afectada_3' ? '' : ' hidden' ?>>
          <input type="text" name="items[<?= $h((string) $index) ?>][area_afectada_3]" data-corrective-area-field placeholder="Escribe un área" value="<?= $h($item['area_afectada_3'] ?? $item['area_afectada'] ?? '') ?>"<?= $activeAreaKey === 'area_afectada_3' ? ' required' : ' disabled' ?>>
          </div>
          <div data-corrective-area-group data-corrective-area-for="area_afectada_4"<?= $activeAreaKey === 'area_afectada_4' ? '' : ' hidden' ?>>
          <input type="text" name="items[<?= $h((string) $index) ?>][area_afectada_4]" data-corrective-area-field placeholder="Escribe un área" value="<?= $h($item['area_afectada_4'] ?? $item['area_afectada'] ?? '') ?>"<?= $activeAreaKey === 'area_afectada_4' ? ' required' : ' disabled' ?>>
          </div>
        </div>
      </div>
      <div class="scm-acta-grid">
        <label>Descripción del daño *
          <textarea name="items[<?= $h((string) $index) ?>][descripcion_dano]" rows="4" required><?= $h($this->correctiveReviewText($item['descripcion_dano'] ?? '')) ?></textarea>
        </label>
        <label>Consecuencia *
          <textarea name="items[<?= $h((string) $index) ?>][consecuencia]" rows="4" required><?= $h($this->correctiveReviewText($item['consecuencia'] ?? '')) ?></textarea>
        </label>
      </div>
      <div class="scm-acta-grid">
        <label>Nivel del daño *
          <select name="items[<?= $h((string) $index) ?>][nivel_dano]" required>
            <?= $this->correctiveReviewRenderOptions($nivelOptions, $item['nivel_dano'] ?? '', 'Seleccionar nivel') ?>
          </select>
        </label>
        <label>Tiempo de atención *
          <select name="items[<?= $h((string) $index) ?>][tiempo_atencion]" required>
            <?= $this->correctiveReviewRenderOptions($tiempoOptions, $item['tiempo_atencion'] ?? '', 'Seleccionar tiempo') ?>
          </select>
        </label>
      </div>
      <label>¿A quién corresponde el daño?
        <select name="items[<?= $h((string) $index) ?>][a_quien_corresponde]">
          <?= $this->correctiveReviewRenderOptions($correspondeOptions, $item['a_quien_corresponde'] ?? '', 'Por definir') ?>
        </select>
      </label>
      <div class="scm-acta-photo-field">
        <label>Registro fotográfico
          <input type="file" name="corrective_review_photos_<?= $h((string) $index) ?>[]" accept="image/jpeg,image/png,image/webp" multiple data-corrective-photos>
        </label>
        <small>Máximo 10 fotos por daño y 30 por revisión. Se comprimen automáticamente antes de guardarlas.</small>
        <div class="scm-acta-photo-preview" data-corrective-photo-preview>
          <?php foreach ($photos as $photoIndex => $photo): ?>
            <figure data-corrective-existing-photo>
              <img src="<?= $h($photo) ?>" alt="Foto guardada <?= $h((string) ($photoIndex + 1)) ?>">
              <figcaption>Foto guardada</figcaption>
              <input type="hidden" name="items[<?= $h((string) $index) ?>][existing_fotos][]" value="<?= $h($photo) ?>">
              <button type="button" class="scm-acta-photo-remove" data-corrective-remove-existing-photo aria-label="Quitar foto guardada">×</button>
            </figure>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="button" class="scm-acta-remove" data-corrective-remove-item>Quitar este daño</button>
    </fieldset>
    <?php
    return (string) ob_get_clean();
  }

  private function correctiveReviewAreaKeyForIndice(string $indice): string
  {
    $normalized = $this->correctiveReviewNormalizeKey($indice);
    if (str_contains($normalized, 'estructurales')) {
      return 'area_afectada_2';
    }
    if (str_contains($normalized, 'otros inconvenientes')) {
      return 'area_afectada_3';
    }
    if (str_contains($normalized, 'servicios publicos')) {
      return 'area_afectada_4';
    }
    return 'area_afectada_1';
  }

  /** @param array<string,string> $fallback @return array<string,string> */
  private function correctiveReviewGlossaryOptions(int $glossaryId, array $fallback = []): array
  {
    static $cache = [];
    if (array_key_exists($glossaryId, $cache)) {
      return $cache[$glossaryId] ?: $fallback;
    }
    $options = [];
    $table = $this->db->table('options');
    if ($this->table_exists($table)) {
      $raw = (string) ($this->db->getVar("SELECT `option_value` FROM `{$table}` WHERE `option_name` = ? LIMIT 1", ['jet_engine_glossaries']) ?? '');
      $decoded = $this->correctiveReviewDecodeStorage($raw);
      if (is_array($decoded)) {
        $node = $this->correctiveReviewFindGlossaryNode($decoded, (string) $glossaryId);
        if ($node) {
          $options = $this->correctiveReviewExtractGlossaryOptions($node);
        }
      }
    }
    $cache[$glossaryId] = $options;
    return $options ?: $fallback;
  }

  /** @param array<string,string> $options */
  private function correctiveReviewRenderOptions(array $options, $selectedValue, string $placeholder): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $selected = trim((string) $selectedValue);
    $html = '<option value="">' . $h($placeholder) . '</option>';
    $hasSelected = $selected === '';
    foreach ($options as $value => $label) {
      $value = trim((string) $value);
      $label = trim((string) $label);
      if ($value === '' && $label === '') {
        continue;
      }
      if ($value === '') {
        $value = $label;
      }
      if ($label === '') {
        $label = $value;
      }
      $isSelected = $selected !== '' && $selected === $value;
      if ($isSelected) {
        $hasSelected = true;
      }
      $html .= '<option value="' . $h($value) . '"' . ($isSelected ? ' selected' : '') . '>' . $h($label) . '</option>';
    }
    if (!$hasSelected && $selected !== '') {
      $html .= '<option value="' . $h($selected) . '" selected>' . $h($selected) . '</option>';
    }
    return $html;
  }

  private function correctiveReviewDecodeStorage(string $raw)
  {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }
    $unserialized = @unserialize($raw);
    if (is_array($unserialized)) {
      return $unserialized;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  /** @param mixed $node @return array<string,mixed> */
  private function correctiveReviewFindGlossaryNode($node, string $glossaryId): array
  {
    if (!is_array($node)) {
      return [];
    }
    foreach (['id', 'ID', '_id', '_ID', 'glossary_id', 'glossaryId'] as $key) {
      if (isset($node[$key]) && (string) $node[$key] === $glossaryId) {
        return $node;
      }
    }
    if (isset($node[$glossaryId]) && is_array($node[$glossaryId])) {
      return $node[$glossaryId];
    }
    foreach ($node as $child) {
      $found = $this->correctiveReviewFindGlossaryNode($child, $glossaryId);
      if ($found) {
        return $found;
      }
    }
    return [];
  }

  /** @param mixed $node @return array<string,string> */
  private function correctiveReviewExtractGlossaryOptions($node): array
  {
    $out = [];
    $this->correctiveReviewCollectGlossaryOptions($node, $out, false);
    return $out;
  }

  /** @param mixed $node @param array<string,string> $out */
  private function correctiveReviewCollectGlossaryOptions($node, array &$out, bool $insideOptions): void
  {
    if (!is_array($node)) {
      return;
    }
    if (isset($node['value']) || isset($node['val'])) {
      $value = $this->correctiveReviewText($node['value'] ?? $node['val'] ?? '');
      $label = $this->correctiveReviewText($node['label'] ?? $node['title'] ?? $node['name'] ?? $node['text'] ?? $value);
      if ($value !== '' && $label !== '' && !$this->correctiveReviewLooksLikeGlossaryMeta($value, $label)) {
        $out[$value] = $label;
      }
    }
    foreach ($node as $key => $child) {
      if (is_string($key) && in_array($key, ['id', 'ID', '_id', '_ID', 'glossary_id', 'glossaryId', 'name', 'slug', 'type'], true)) {
        continue;
      }
      $nextInsideOptions = $insideOptions || (is_string($key) && in_array($key, ['options', 'fields', 'items', 'choices', 'data'], true));
      if ($insideOptions && is_string($key) && is_scalar($child) && trim($key) !== '' && trim((string) $child) !== '') {
        $value = $this->correctiveReviewText($child);
        $label = $this->correctiveReviewText($key);
        if ($value !== '' && $label !== '' && !$this->correctiveReviewLooksLikeGlossaryMeta($value, $label)) {
          $out[$value] = $label;
        }
        continue;
      }
      $this->correctiveReviewCollectGlossaryOptions($child, $out, $nextInsideOptions);
    }
  }

  private function correctiveReviewLooksLikeGlossaryMeta(string $value, string $label): bool
  {
    $valueKey = $this->correctiveReviewNormalizeKey($value);
    $labelKey = $this->correctiveReviewNormalizeKey($label);
    if ($valueKey === $labelKey && in_array($valueKey, ['manual', 'custom', 'select', 'checkbox', 'radio'], true)) {
      return true;
    }
    return in_array($labelKey, ['id', 'name', 'slug', 'type', 'source', 'options', 'fields'], true);
  }

  private function correctiveReviewNormalizeKey(string $value): string
  {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'];
    $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'u', 'n'];
    $value = str_replace($from, $to, $value);
    $value = strtolower($value);
    $value = preg_replace('/\s+/', ' ', $value) ?: '';
    return trim($value);
  }

  /** @return array<int,array<string,mixed>> */
  private function correctiveReviewStoredItems($raw): array
  {
    $items = [];
    if (is_string($raw) && trim($raw) !== '') {
      $unserialized = @unserialize($raw);
      if (is_array($unserialized)) {
        $items = $unserialized;
      } else {
        $json = json_decode($raw, true);
        $items = is_array($json) ? $json : [];
      }
    } elseif (is_array($raw)) {
      $items = $raw;
    }
    $out = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $out[] = $item;
      }
      if (count($out) >= 30) {
        break;
      }
    }
    return $out;
  }

  /** @return array<int,string> */
  private function correctiveReviewSafePhotoRefs($value): array
  {
    $refs = [];
    if (is_array($value)) {
      foreach ($value as $entry) {
        foreach ($this->correctiveReviewSafePhotoRefs($entry) as $ref) {
          $refs[$ref] = $ref;
        }
      }
      return array_values($refs);
    }
    foreach (preg_split('/[,\r\n]+/', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $part) {
      $ref = trim(strip_tags((string) $part));
      if ($ref === '' || strlen($ref) > 2048 || preg_match('/[\x00<>"\']/', $ref)) {
        continue;
      }
      if (preg_match('#^https?://#i', $ref) || str_starts_with($ref, '/') || str_starts_with($ref, 'file.php?')) {
        $refs[$ref] = $ref;
      }
    }
    return array_values($refs);
  }

  /** @return array<int,string> */
  private function correctiveReviewSplitPhotoRefs(string $raw): array
  {
    return $this->correctiveReviewSafePhotoRefs($raw);
  }

  /** @param array<int,array<string,mixed>> $items */
  private function correctiveReviewCombinedAreas(array $items): string
  {
    $areas = [];
    foreach ($items as $item) {
      foreach (['area_afectada', 'area_afectada_1', 'area_afectada_2', 'area_afectada_3', 'area_afectada_4'] as $key) {
        $area = $this->correctiveReviewText($item[$key] ?? '');
        if ($area !== '') {
          $areas[$area] = $area;
        }
      }
    }
    return implode(', ', array_values($areas));
  }

  private function correctiveReviewText($value): string
  {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text) ?: '';
    return trim($text);
  }

  private function correctiveReviewHtml($value): string
  {
    $text = trim((string) $value);
    if ($text === '') {
      return '';
    }
    $text = strip_tags($text);
    $text = trim($text);
    return $text !== '' ? '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>' : '';
  }

  /** @param array<int,mixed> $values */
  private function correctiveReviewFirstText(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string) $value);
      if ($text !== '' && $text !== '-') {
        return $text;
      }
    }
    return '';
  }

  private function correctiveReviewDigits($value): string
  {
    return preg_replace('/\D+/', '', (string) $value) ?: '';
  }

  /** @return array<int,string> */
  private function correctiveReviewSplitIds(string $raw): array
  {
    $parts = preg_split('/[,\s]+/', trim($raw)) ?: [];
    $ids = [];
    foreach ($parts as $part) {
      $id = preg_replace('/\D+/', '', $part) ?: '';
      if ($id !== '') {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

  private function correctiveReviewDateLabel($raw): string
  {
    if (is_numeric($raw)) {
      $ts = (int) $raw;
    } else {
      $ts = strtotime((string) $raw) ?: 0;
    }
    return $ts > 0 ? date('d/m/Y H:i', $ts) : '-';
  }
}
