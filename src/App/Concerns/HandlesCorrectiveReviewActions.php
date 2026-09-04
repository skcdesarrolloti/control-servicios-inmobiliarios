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
        $this->jsonFail('No tienes permiso para crear la revisión correctiva de este caso.');
      }
      $operation = trim((string) ($_POST['operation'] ?? 'read'));
      if ($operation === 'create') {
        $result = $this->correctiveReviewCreate($ticketId);
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
      $photoTotal = 0;
      foreach ($items as $index => &$item) {
        $field = 'corrective_review_photos_' . $index;
        $names = $_FILES[$field]['name'] ?? [];
        $names = is_array($names) ? array_values(array_filter($names, static fn($name): bool => trim((string) $name) !== '')) : [];
        if (count($names) > 10) {
          throw new \DomainException('Cada daño admite máximo 10 fotos.');
        }
        $photoTotal += count($names);
        if ($photoTotal > 30) {
          throw new \DomainException('La revisión admite máximo 30 fotos en total.');
        }
        if (!$names) {
          continue;
        }
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
        $urls = array_map(static fn(array $photo): string => (string) $photo['url'], $photos);
        $item['registro_foto_dano'] = implode(',', array_values(array_filter($urls)));
      }
      unset($item);

      $actor = $this->ticketCompletionActor();
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
      $property = is_array($context['property'] ?? null) ? $context['property'] : [];
      $ownerEmail = $this->correctiveReviewFirstText([$ticket['correo_propietario'] ?? '', $contract['correo_propietario'] ?? '']);
      $tenantEmail = $this->correctiveReviewFirstText([$ticket['correo_arrendatario'] ?? '', $contract['correo_arrendatario'] ?? '']);
      $ownerPhone = $this->correctiveReviewFirstText([$ticket['celular_propietario'] ?? '', $contract['celular_propietario'] ?? '']);
      $tenantPhone = $this->correctiveReviewFirstText([$ticket['celular_arrendatario'] ?? '', $contract['celular_arrendatario'] ?? '']);
      $reviewData = [
        'cct_status' => 'publish',
        'direccion' => $this->correctiveReviewFirstText([$ticket['direccion'] ?? '', $contract['direccion'] ?? '', $property['direccion'] ?? '', $property['direccion_fisica'] ?? '']),
        'tip_inm' => $this->correctiveReviewFirstText([$ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '', $property['tipo_inmueble'] ?? '']),
        'evaluacion_de_danos' => serialize($items),
        'cct_author_id' => Auth::userId(),
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'creador' => $actor['name'] ?? Auth::user(),
        'fecha' => $now,
        'id_ticket' => $ticketId,
        'id_inmueble' => $this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '', $property['_ID'] ?? '']),
        'tiene_cotizacion' => 'No',
        'destinatario' => $this->correctiveReviewFirstText([$contract['propietario'] ?? '', $ticket['propietario'] ?? '', $contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']),
        'celular_destinatario' => $this->correctiveReviewFirstText([$ownerPhone, $tenantPhone]),
        'email_destinatario' => $this->correctiveReviewFirstText([$ownerEmail, $tenantEmail]),
        'contrato' => ltrim($this->correctiveReviewFirstText([$ticket['contrato'] ?? '', $ticket['id_contrato'] ?? '', $contract['contrato'] ?? '', $contract['_ID'] ?? '']), '#'),
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
        'tipo_negocio' => $this->correctiveReviewFirstText([$property['tipo_negocio'] ?? '', $contract['gestion_inmueble'] ?? '']),
        'destinacion' => $this->correctiveReviewFirstText([$property['destinacion'] ?? '', $contract['destinacion_inmueble'] ?? '']),
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
          'respuesta' => 'Se ha elaborado la revisión correctiva del inmueble. La cotización se podrá crear posteriormente sin generar reporte administrativo desde esta revisión.',
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

      return [
        'message' => 'Revisión correctiva #' . $reviewId . ' guardada. No se generó reporte administrativo.',
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
      $area = $this->correctiveReviewText($raw['area_afectada'] ?? '');
      $descripcion = $this->correctiveReviewHtml($raw['descripcion_dano'] ?? '');
      $consecuencia = $this->correctiveReviewHtml($raw['consecuencia'] ?? '');
      $nivel = $this->correctiveReviewText($raw['nivel_dano'] ?? '');
      $tiempo = $this->correctiveReviewText($raw['tiempo_atencion'] ?? '');
      $corresponde = $this->correctiveReviewText($raw['a_quien_corresponde'] ?? '');
      if ($indice === '' && $area === '' && $descripcion === '' && $consecuencia === '' && $nivel === '' && $tiempo === '' && $corresponde === '') {
        continue;
      }
      foreach ([
        'indice' => $indice,
        'area afectada' => $area,
        'descripción del daño' => $descripcion,
        'consecuencia' => $consecuencia,
        'nivel del daño' => $nivel,
        'tiempo de atención' => $tiempo,
      ] as $label => $value) {
        if ($value === '') {
          throw new \DomainException('Completa ' . $label . ' en todos los daños.');
        }
      }
      $areaKey = 'area_afectada_1';
      if (stripos($indice, 'estructurales') !== false) {
        $areaKey = 'area_afectada_2';
      } elseif (stripos($indice, 'otros inconvenientes') !== false) {
        $areaKey = 'area_afectada_3';
      } elseif (stripos($indice, 'servicios publicos') !== false || stripos($indice, 'servicios públicos') !== false) {
        $areaKey = 'area_afectada_4';
      }
      $item = [
        'indice' => $indice,
        'area_afectada_1' => '',
        'area_afectada_2' => '',
        'area_afectada_3' => '',
        'area_afectada_4' => '',
        'registro_foto_dano' => '',
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
  private function correctiveReviewContext(int $ticketId): array
  {
    $ticket = $this->correctiveReviewTicket($ticketId);
    if (!$ticket) {
      throw new \DomainException('No se encontró el caso seleccionado.');
    }
    $contract = $this->correctiveReviewContract($ticket);
    $property = $this->correctiveReviewProperty($ticket, $contract);
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
    $idContrato = preg_replace('/\D+/', '', (string) ($ticket['id_contrato'] ?? '')) ?: '';
    if ($idContrato !== '') {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? OR `contrato` = ? LIMIT 1", [$idContrato, $idContrato]);
      if ($row) {
        return $row;
      }
    }
    $contrato = preg_replace('/\D+/', '', (string) ($ticket['contrato'] ?? '')) ?: '';
    if ($contrato !== '') {
      return $this->db->getRow("SELECT * FROM `{$table}` WHERE `contrato` = ? OR `_ID` = ? LIMIT 1", [$contrato, $contrato]) ?: [];
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
    foreach ([$ticket['id_inmueble_data'] ?? '', $contract['id_inmueble_data'] ?? '', $ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? ''] as $candidate) {
      $id = preg_replace('/\D+/', '', (string) $candidate) ?: '';
      if ($id === '') {
        continue;
      }
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? OR `id_ticket` = ? LIMIT 1", [$id, $id]);
      if ($row) {
        return $row;
      }
    }
    return [];
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
  private function renderCorrectiveReviewPanel(array $context): string
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
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review">
      <p class="scm-acta-notice">Esta revisión correctiva registra los daños encontrados y deja el caso como <strong>Inspeccionado</strong>. No genera reporte administrativo; el cobro se manejará cuando se cree la primera cotización.</p>
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
              <strong>Revisión #<?= $h($id) ?></strong>
              <span><?= $h($this->correctiveReviewDateLabel($review['fecha'] ?? $review['cct_created'] ?? '')) ?></span>
              <?php if ($id !== ''): ?><a class="scm-acta-button scm-acta-secondary" href="<?= $h(self::DEFAULT_CORRECTIVA_URL . rawurlencode($id)) ?>" target="_blank" rel="noopener">Ver informe</a><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
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
    </div>
    <?php
    return (string) ob_get_clean();
  }

  private function renderCorrectiveReviewItem(int $index): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
    <fieldset class="scm-acta-item scm-corrective-item" data-corrective-item>
      <legend>Daño #<?= $h((string) ($index + 1)) ?></legend>
      <div class="scm-acta-grid">
        <label>Índice *
          <select name="items[<?= $h((string) $index) ?>][indice]" required>
            <option value="">Seleccionar índice</option>
            <option value="Evaluacion de los daños en elementos arquitectonicos">Elementos arquitectónicos</option>
            <option value="Evaluacion de los daños en elementos estructurales">Elementos estructurales</option>
            <option value="Otros inconvenientes al inmueble accesos y usos conexos">Otros inconvenientes / accesos y usos conexos</option>
            <option value="Servicios publicos">Servicios públicos</option>
          </select>
        </label>
        <label>Área afectada *
          <input type="text" name="items[<?= $h((string) $index) ?>][area_afectada]" required placeholder="Ej. Cocina, baño, sala, medidor...">
        </label>
      </div>
      <div class="scm-acta-grid">
        <label>Descripción del daño *
          <textarea name="items[<?= $h((string) $index) ?>][descripcion_dano]" rows="4" required></textarea>
        </label>
        <label>Consecuencia *
          <textarea name="items[<?= $h((string) $index) ?>][consecuencia]" rows="4" required></textarea>
        </label>
      </div>
      <div class="scm-acta-grid">
        <label>Nivel del daño *
          <select name="items[<?= $h((string) $index) ?>][nivel_dano]" required>
            <option value="">Seleccionar nivel</option>
            <option value="Leve">Leve</option>
            <option value="Moderado">Moderado</option>
            <option value="Grave">Grave</option>
          </select>
        </label>
        <label>Tiempo de atención *
          <select name="items[<?= $h((string) $index) ?>][tiempo_atencion]" required>
            <option value="">Seleccionar tiempo</option>
            <option value="De inmediato">De inmediato</option>
            <option value="1 a 3 días">1 a 3 días</option>
            <option value="4 a 8 días">4 a 8 días</option>
            <option value="Programable">Programable</option>
          </select>
        </label>
      </div>
      <label>¿A quién corresponde el daño?
        <select name="items[<?= $h((string) $index) ?>][a_quien_corresponde]">
          <option value="">Por definir</option>
          <option value="Propietario">Propietario</option>
          <option value="Arrendatario">Arrendatario</option>
          <option value="Inmobiliaria">Inmobiliaria</option>
          <option value="Copropiedad">Copropiedad</option>
        </select>
      </label>
      <div class="scm-acta-photo-field">
        <label>Registro fotográfico
          <input type="file" name="corrective_review_photos_<?= $h((string) $index) ?>[]" accept="image/jpeg,image/png,image/webp" multiple data-corrective-photos>
        </label>
        <small>Máximo 10 fotos por daño y 30 por revisión. Se comprimen automáticamente antes de guardarlas.</small>
        <div class="scm-acta-photo-preview" data-corrective-photo-preview></div>
      </div>
      <button type="button" class="scm-acta-remove" data-corrective-remove-item>Quitar este daño</button>
    </fieldset>
    <?php
    return (string) ob_get_clean();
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
