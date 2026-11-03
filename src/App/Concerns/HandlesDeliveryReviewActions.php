<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\DeliveryReview\DeliveryReviewForm;
use SCM\Support\SchemaInspector;

/**
 * Revisión antes de la ocupación (revisión de entrega) nativa. Reemplaza el formulario JetForm
 * "Añadir revisión de entrega": datos del inmueble, linderos, registro fotográfico por espacio y servicios
 * públicos, con las mismas actualizaciones de caso, contrato, inmueble e historiales.
 */
trait HandlesDeliveryReviewActions
{
  public function canAccessDeliveryReview(int $ticketId): bool
  {
    return $this->canAccessCorrectiveReview($ticketId) || (Auth::isLoggedIn() && $ticketId > 0 && $this->canAccessDashboardTab('entrega'));
  }

  public function ajax_handler_delivery_review(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('delivery_review_manage')) {
      $this->jsonFail('No tienes permiso para gestionar revisiones antes de la ocupación.');
    }
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    $operation = trim((string) ($_POST['operation'] ?? 'read'));
    try {
      if (!$this->canAccessDeliveryReview($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar la revisión antes de la ocupación de este caso.');
      }
      $reviewId = (int) ($_POST['review_id'] ?? 0);
      $result = [];
      $editId = 0;
      if ($operation === 'create') {
        $result = $this->deliveryReviewCreate($ticketId);
      } elseif ($operation === 'edit') {
        $this->deliveryReviewTargetRow($this->deliveryReviewContext($ticketId), $reviewId);
        $editId = $reviewId;
      } elseif ($operation === 'update') {
        $result = $this->deliveryReviewUpdate($ticketId, $reviewId);
      } elseif ($operation === 'delete') {
        $result = $this->deliveryReviewDelete($ticketId, $reviewId);
      } elseif ($operation !== 'read') {
        throw new \DomainException('Operación de revisión antes de la ocupación no válida.');
      }
      $this->jsonOk($result + [
        'html' => $this->renderDeliveryReviewPanel($this->deliveryReviewContext($ticketId), $editId),
      ]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  /** @return array<string,mixed> */
  private function deliveryReviewContext(int $ticketId): array
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
      'reviews' => $this->deliveryReviewRows($ticketId, $ticket),
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function deliveryReviewRows(int $ticketId, array $ticket): array
  {
    $table = $this->db->table('jet_cct_revision_entrega');
    if (!$this->table_exists($table)) {
      return [];
    }
    $byId = [];
    $ids = $this->correctiveReviewSplitIds((string) ($ticket['id_revision_entrega'] ?? ''));
    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      foreach ($this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN ({$placeholders})", $ids) as $row) {
        $byId[(string) ($row['_ID'] ?? '')] = $row;
      }
    }
    foreach ($this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? ORDER BY `_ID` DESC LIMIT 10", [(string) $ticketId]) as $row) {
      $byId[(string) ($row['_ID'] ?? '')] = $row;
    }
    krsort($byId, SORT_NUMERIC);
    return array_values($byId);
  }

  /** @param array<string,mixed> $context @return array<string,mixed> */
  private function deliveryReviewTargetRow(array $context, int $reviewId): array
  {
    if ($reviewId <= 0) {
      throw new \DomainException('Selecciona una revisión antes de la ocupación válida.');
    }
    foreach ((array) ($context['reviews'] ?? []) as $review) {
      if ((int) ($review['_ID'] ?? 0) === $reviewId) {
        return $review;
      }
    }
    throw new \DomainException('No se encontró la revisión antes de la ocupación de este caso.');
  }

  /**
   * Lee y valida el formulario. Devuelve las columnas de revision_entrega y los servicios activos.
   *
   * @return array{data:array<string,mixed>,services:list<string>}
   */
  private function deliveryReviewInput(): array
  {
    $text = fn($value, int $max = 200): string => mb_substr($this->correctiveReviewText($value), 0, $max);
    $choice = static fn(string $value, array $options): string => in_array($value, $options, true) ? $value : '';
    $count = function ($raw, string $label): string {
      $raw = trim(is_scalar($raw) ? (string) $raw : '');
      if ($raw === '' || !ctype_digit($raw) || (int) $raw > 30) {
        throw new \DomainException('Indica la cantidad de ' . $label . ' (0 a 30).');
      }
      return (string) (int) $raw;
    };
    $yesNo = function (string $key, string $question): string {
      $value = $this->correctiveReviewText($_POST[$key] ?? '');
      if (!in_array($value, ['Si', 'No'], true)) {
        throw new \DomainException('Responde: ' . $question);
      }
      return $value;
    };

    // "piso" es la cantidad de pisos del inmueble; habitaciones y baños se cuentan por piso.
    $floorsRaw = trim((string) ($_POST['piso'] ?? ''));
    if (!ctype_digit($floorsRaw) || (int) $floorsRaw < 1 || (int) $floorsRaw > DeliveryReviewForm::MAX_FLOORS) {
      throw new \DomainException('Indica la cantidad de pisos del inmueble (1 a ' . DeliveryReviewForm::MAX_FLOORS . ').');
    }
    $floors = (int) $floorsRaw;
    $rooms = 0;
    $baths = 0;
    for ($floor = 1; $floor <= $floors; $floor++) {
      $suffix = $floors > 1 ? ' del piso ' . $floor : '';
      $rooms += (int) $count($_POST['hab_piso'][$floor] ?? '', 'habitaciones' . $suffix);
      $baths += (int) $count($_POST['ban_piso'][$floor] ?? '', 'baños' . $suffix);
    }
    $data = [
      'habitaciones' => (string) $rooms,
      'banos' => (string) $baths,
      'piso' => (string) $floors,
      'tiene_parqueadero' => $yesNo('tiene_parqueadero', '¿Tiene parqueadero?'),
      'tiene_deposito' => $yesNo('tiene_deposito', '¿Tiene depósito?'),
    ];
    $data += ['tipo_parqueadero' => '', 'parqueaderos' => '', 'numero_parqueadero' => '', 'depositos' => ''];
    if ($data['tiene_parqueadero'] === 'Si') {
      $data['tipo_parqueadero'] = $choice($text($_POST['tipo_parqueadero'] ?? ''), DeliveryReviewForm::glossary($this->db, 1011));
      $data['parqueaderos'] = $this->correctiveReviewDigits($_POST['parqueaderos'] ?? '');
      $data['numero_parqueadero'] = $text($_POST['numero_parqueadero'] ?? '', 120);
      if ($data['tipo_parqueadero'] === '' || (int) $data['parqueaderos'] <= 0 || $data['numero_parqueadero'] === '') {
        throw new \DomainException('Completa el tipo, la cantidad y el número de parqueadero.');
      }
    }
    if ($data['tiene_deposito'] === 'Si') {
      $data['depositos'] = $this->correctiveReviewDigits($_POST['depositos'] ?? '');
      if ((int) $data['depositos'] <= 0) {
        throw new \DomainException('Indica la cantidad de depósitos.');
      }
    }
    $photoLink = trim((string) ($_POST['registro_fotografico'] ?? ''));
    if (!preg_match('#^https?://#i', $photoLink) || filter_var($photoLink, FILTER_VALIDATE_URL) === false) {
      throw new \DomainException('Pega el enlace del registro fotográfico (carpeta de Drive).');
    }
    $data['registro_fotografico'] = mb_substr($photoLink, 0, 500);
    $boundaryDate = $this->deliveryReviewDate($_POST['fech_linderos'] ?? '');
    if ($boundaryDate === '') {
      throw new \DomainException('Indica la fecha de toma de linderos.');
    }
    $data['fech_linderos'] = (int) strtotime($boundaryDate . ' 00:00:00');

    // Linderos (repeaters de JetEngine con el sufijo de cada grupo: norte_conjunto, cenit_inmueble…).
    $rawBoundaries = is_array($_POST['linderos'] ?? null) ? $_POST['linderos'] : [];
    foreach (DeliveryReviewForm::boundaries() as $key => $group) {
      $rows = [];
      if ($group['when'] === '' || $data[$group['when']] === 'Si') {
        foreach (is_array($rawBoundaries[$key] ?? null) ? $rawBoundaries[$key] : [] as $input) {
          if (!is_array($input)) {
            continue;
          }
          $row = [];
          if ($key === 'parqueadero') {
            $row['num_parqueadero'] = $text($input['num'] ?? '', 40);
          }
          foreach ($group['sides'] as $side) {
            $row[$side . '_' . $group['suffix']] = $text($input[$side] ?? '', 300);
          }
          if (implode('', $row) !== '') {
            $rows[] = $row;
          }
          if (!$group['multiple'] && $rows) {
            break;
          }
        }
      }
      if ($group['required'] && !$rows) {
        throw new \DomainException('Registra los linderos del ' . mb_strtolower($group['label'], 'UTF-8') . '.');
      }
      $data[$key] = DeliveryReviewForm::storeRows(array_slice($rows, 0, 20));
    }

    // Registro fotográfico por espacio: qué contiene y observación.
    $rawSpaces = is_array($_POST['espacios'] ?? null) ? $_POST['espacios'] : [];
    $data += $this->deliveryReviewInputFloorSpaces($rawSpaces, $floors);
    foreach (DeliveryReviewForm::GENERAL_SPACES as $key) {
      $space = DeliveryReviewForm::spaces()[$key];
      $rows = [];
      foreach (is_array($rawSpaces[$key] ?? null) ? $rawSpaces[$key] : [] as $input) {
        if (!is_array($input)) {
          continue;
        }
        // Opciones del glosario o las que ya traía una revisión antigua (se conservan al editar).
        $contains = array_slice(array_values(array_unique(array_filter(array_map(fn($value): string => $text($value, 120), (array) ($input['contiene'] ?? [])), static fn(string $value): bool => $value !== ''))), 0, 40);
        $observation = mb_substr($this->preventiveReviewMultiline($input['observacion'] ?? ''), 0, 2000);
        if ($contains === [] && $observation === '') {
          continue;
        }
        $row = [$space['contains'] => $contains];
        $row[$space['observation']] = $observation;
        if (strcasecmp($observation, 'Ninguna') === 0) {
          $row[$space['none']] = ['Ninguna'];
        }
        $rows[] = $row;
      }
      $data[$key] = DeliveryReviewForm::storeRows(array_slice($rows, 0, 30));
    }

    // Servicios públicos: solo se validan y guardan los que tiene activos el inmueble.
    $serviceDefs = DeliveryReviewForm::services();
    $activeOptions = array_values(array_intersect(DeliveryReviewForm::glossary($this->db, 1003), array_map('strval', (array) ($_POST['servicios_publicos'] ?? []))));
    $data['servicios_publicos'] = serialize($activeOptions);
    $rawServices = is_array($_POST['servicios'] ?? null) ? $_POST['servicios'] : [];
    $active = [];
    foreach ($serviceDefs as $serviceKey => $service) {
      $isActive = in_array($service['option'], $activeOptions, true);
      if ($isActive) {
        $active[] = $serviceKey;
      }
      foreach ($service['items'] as $item) {
        foreach ($item['fields'] as $field) {
          $value = $isActive ? $this->deliveryReviewFieldValue($field, $rawServices[$field['name']] ?? '') : '';
          if ($isActive && $field['required'] && $value === '') {
            throw new \DomainException('Completa "' . $field['label'] . '" del servicio de ' . mb_strtolower($service['label'], 'UTF-8') . '.');
          }
          $data[$field['name']] = $value;
        }
        $observation = $isActive ? mb_substr($this->preventiveReviewMultiline($rawServices[$item['observation']] ?? ''), 0, 1000) : '';
        $data[$item['observation']] = $isActive && $observation === '' ? 'Ninguna' : $observation;
      }
    }
    $extras = DeliveryReviewForm::glossary($this->db, 577);
    $data['servicios_adicionales'] = serialize(array_values(array_intersect($extras, array_map('strval', (array) ($_POST['servicios_adicionales'] ?? [])))));

    return ['data' => $data, 'services' => $active];
  }

  /**
   * Espacios interiores por piso (espacios[cocina_p2][0][...]). Habitaciones y baños de tipo "De servicio" se guardan
   * en hab_ser / bano_ser, como el formulario original; cada fila lleva su piso (piso_cocina, piso_habitacion…).
   *
   * @param array<mixed> $rawSpaces
   * @return array<string,string>
   */
  private function deliveryReviewInputFloorSpaces(array $rawSpaces, int $floors): array
  {
    $spaces = DeliveryReviewForm::spaces();
    $out = [];
    foreach (DeliveryReviewForm::floorSpaces() as $base) {
      $serviceKey = DeliveryReviewForm::MERGED[$base] ?? '';
      $stored = $serviceKey !== '' ? [$base => [], $serviceKey => []] : [$base => []];
      for ($floor = 1; $floor <= $floors; $floor++) {
        foreach (is_array($rawSpaces[$base . '_p' . $floor] ?? null) ? $rawSpaces[$base . '_p' . $floor] : [] as $input) {
          if (!is_array($input)) {
            continue;
          }
          $type = $serviceKey !== '' ? DeliveryReviewForm::normalizeType($base, mb_substr($this->correctiveReviewText($input['tipo'] ?? ''), 0, 120)) : '';
          $contains = array_slice(array_values(array_unique(array_filter(array_map(fn($value): string => mb_substr($this->correctiveReviewText($value), 0, 120), (array) ($input['contiene'] ?? [])), static fn(string $value): bool => $value !== ''))), 0, 40);
          $observation = mb_substr($this->preventiveReviewMultiline($input['observacion'] ?? ''), 0, 2000);
          if ($type === '' && $contains === [] && $observation === '') {
            continue;
          }
          $key = $serviceKey !== '' && $type === DeliveryReviewForm::SERVICE_TYPE ? $serviceKey : $base;
          $space = $spaces[$key];
          $row = $space['type'] !== '' ? [$space['type'] => $type] : [];
          $row[DeliveryReviewForm::floorKey($key)] = (string) $floor;
          $row[$space['contains']] = $contains;
          $row[$space['observation']] = $observation;
          if (strcasecmp($observation, 'Ninguna') === 0) {
            $row[$space['none']] = ['Ninguna'];
          }
          $stored[$key][] = $row;
        }
      }
      foreach ($stored as $key => $rows) {
        $out[$key] = DeliveryReviewForm::storeRows(array_slice($rows, 0, 60));
      }
    }
    return $out;
  }

  /** @param array{name:string,type:string,label:string,glossary:int,required:bool,contract:string} $field */
  private function deliveryReviewFieldValue(array $field, $raw): string
  {
    $value = $this->correctiveReviewText($raw);
    if ($value === '') {
      return '';
    }
    switch ($field['type']) {
      case 'money':
        return $this->correctiveReviewDigits($value);
      case 'date':
        $date = $this->deliveryReviewDate($value);
        if ($date === '') {
          throw new \DomainException('Fecha no válida en "' . $field['label'] . '".');
        }
        return $date;
      case 'select':
        if (!in_array($value, DeliveryReviewForm::glossary($this->db, $field['glossary']), true)) {
          throw new \DomainException('Opción no válida en "' . $field['label'] . '".');
        }
        return $value;
      default:
        return mb_substr($value, 0, 200);
    }
  }

  private function deliveryReviewDate($value): string
  {
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
      return '';
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
  }

  /** @return array<string,mixed> */
  private function deliveryReviewCreate(int $ticketId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_entrega');
    $ticketTable = $this->db->table('jet_cct_tickets');
    foreach ([$reviewTable, $ticketTable] as $table) {
      if (!$schema->tableExists($table)) {
        throw new \DomainException('No está disponible la tabla requerida para guardar la revisión antes de la ocupación.');
      }
    }
    $context = $this->deliveryReviewContext($ticketId);
    if (!empty($context['reviews'])) {
      throw new \DomainException('Este caso ya tiene revisión antes de la ocupación. Usa Editar para modificarla.');
    }
    $ticket = $context['ticket'];
    $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
    $property = is_array($context['property'] ?? null) ? $context['property'] : [];
    $this->correctiveReviewValidateContext($ticket, $contract, $property);
    $input = $this->deliveryReviewInput();

    try {
      $actor = $this->ticketCompletionActor();
      $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $branch = $this->preventiveReviewBranch($ticket, $contract);
      $propertyPostId = $this->correctiveReviewDigits($this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']));
      $data = [
        'cct_status' => 'publish',
        'fecha' => $now,
        'id_empleado' => $actorEmployeeId,
        'cct_author_id' => $actorEmployeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'creador' => $actor['name'] ?? Auth::user(),
        'email_creador' => $actor['email'] ?? '',
        'celular_creador' => $actor['phone'] ?? '',
        'coordinador' => $branch['nombre_contractual'] ?? '',
        'email_coordinador' => $branch['correo_contractual'] ?? '',
        'celular_coordinador' => $branch['celular_contractual'] ?? '',
        'id_ticket' => (string) $ticketId,
        'id_inmueble' => $propertyPostId,
        'id_contrato' => $this->correctiveReviewFirstText([$ticket['id_contrato'] ?? '', $contract['_ID'] ?? '']),
        'contrato' => ltrim($this->correctiveReviewFirstText([$contract['contrato'] ?? '', $ticket['contrato'] ?? '']), '#'),
        'inmueble' => $this->correctiveReviewFirstText([$contract['inmueble'] ?? '', $ticket['inmueble'] ?? '']),
        'direccion' => $this->correctiveReviewFirstText([$contract['direccion'] ?? '', $ticket['direccion'] ?? '', $property['direccion'] ?? '']),
        'tip_inm' => $this->correctiveReviewFirstText([$ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '', $property['tipo_inmueble'] ?? '']),
        'sucursal' => $this->correctiveReviewFirstText([$ticket['id_sucursal'] ?? '', $contract['sucursal'] ?? '', $property['sucursal'] ?? '']),
        'id_propietario' => $this->correctiveReviewFirstText([$ticket['id_propietario'] ?? '', $contract['id_propietario'] ?? '']),
        'id_arrendatario' => $this->correctiveReviewFirstText([$ticket['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '']),
        'destinatario' => $this->correctiveReviewFirstText([$contract['propietario'] ?? '', $ticket['propietario'] ?? '']),
        'email_destinatario' => $this->correctiveReviewFirstText([$contract['correo_propietario'] ?? '', $ticket['correo_propietario'] ?? '']),
        'celular_destinatario' => $this->correctiveReviewFirstText([$contract['celular_propietario'] ?? '', $ticket['celular_propietario'] ?? '']),
        'id_estudio_aseguradora' => $this->correctiveReviewFirstText([$ticket['id_estudio_aseguradora'] ?? '', $contract['id_estudio_aseguradora'] ?? '']),
        'numero_solicitud' => $this->correctiveReviewFirstText([$ticket['numero_solicitud'] ?? '', $contract['numero_solicitud'] ?? '']),
        'tipo_negocio' => $this->correctiveReviewFirstText([$ticket['tipo_negocio'] ?? '', $property['tipo_negocio'] ?? '', $contract['gestion_inmueble'] ?? '']),
        'destinacion' => $this->correctiveReviewFirstText([$ticket['destinacion'] ?? '', $property['destinacion'] ?? '', $contract['destinacion_inmueble'] ?? '']),
        'cesion' => $propertyPostId !== '' ? (string) ($this->deliveryReviewPostMeta((int) $propertyPostId, ['sera-cesion'])['sera-cesion'] ?? '') : '',
      ] + $input['data'];
      $insert = $schema->filterTableData($reviewTable, $data);
      if (!$this->db->insert($reviewTable, $insert)) {
        throw new \DomainException('No fue posible guardar la revisión antes de la ocupación.');
      }
      $reviewId = (int) $this->db->lastInsertId();
      if ($reviewId <= 0) {
        throw new \DomainException('La revisión antes de la ocupación se guardó sin identificador válido.');
      }

      $this->deliveryReviewHistory($schema, $ticketId, $reviewId, $actor, $actorEmployeeId, 'Se ha elaborado la revisión antes de la ocupación de acuerdo al proceso de entrega. Procederemos a realizar el inventario inicial del inmueble.', $propertyPostId, 'Se ha realizado una revisión de entrega a su inmueble.');

      // Mismos estados que dejaba el formulario de WordPress al registrar la revisión.
      $ticketUpdate = $schema->filterTableData($ticketTable, [
        'id_revision_entrega' => (string) $reviewId,
        'estado_rev_entrega' => 'Si',
        'estado_rev_correctiva' => 'Si',
        'estado' => 'En proceso',
        'estado_administrativo' => 'Inspeccionado',
        'fecha_actualizacion' => $now,
      ]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
      }
      $this->deliveryReviewSyncRecords($schema, $reviewId, $insert, $input['services'], $contract, $property);

      try {
        $queued = $this->deliveryReviewEnqueueNotifications('revision_entrega_creada', $insert, $ticket, $actor, $reviewId);
      } catch (\Throwable $notifyError) {
        $queued = 0;
        error_log('[revision-entrega] notificaciones #' . $reviewId . ': ' . $notifyError->getMessage());
      }

      return [
        'message' => 'Revisión antes de la ocupación #' . $reviewId . ' guardada.' . ($queued > 0 ? ' Correos en cola: ' . $queued . '.' : ''),
        'review_id' => (string) $reviewId,
        'review_url' => self::deliveryReviewPublicUrl($reviewId),
      ];
    } catch (\Throwable $error) {
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[revision-entrega] create ticket #' . $ticketId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible guardar la revisión antes de la ocupación.');
    }
  }

  /** @return array<string,mixed> */
  private function deliveryReviewUpdate(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_entrega');
    $context = $this->deliveryReviewContext($ticketId);
    $review = $this->deliveryReviewTargetRow($context, $reviewId);
    $input = $this->deliveryReviewInput();
    try {
      $actor = $this->ticketCompletionActor();
      $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
      $update = $schema->filterTableData($reviewTable, ['cct_modified' => date('Y-m-d H:i:s')] + $input['data']);
      if (!$update || $this->db->update($reviewTable, $update, ['_ID' => $reviewId]) < 0) {
        throw new \DomainException('No fue posible actualizar la revisión antes de la ocupación.');
      }
      $ticketTable = $this->db->table('jet_cct_tickets');
      $ticketUpdate = $schema->filterTableData($ticketTable, ['fecha_actualizacion' => time()]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
      }
      $saved = array_merge($review, $update);
      $this->deliveryReviewSyncRecords($schema, $reviewId, $saved, $input['services'], (array) ($context['contract'] ?? []), (array) ($context['property'] ?? []));
      $this->deliveryReviewHistory($schema, $ticketId, $reviewId, $actor, $actorEmployeeId, 'Se actualizó la revisión antes de la ocupación #' . $reviewId . '.', (string) ($review['id_inmueble'] ?? ''), 'Se actualizó la revisión de entrega #' . $reviewId . ' del inmueble.');
      try {
        $queued = $this->deliveryReviewEnqueueNotifications('revision_entrega_actualizada', $saved, (array) $context['ticket'], $actor, $reviewId);
      } catch (\Throwable $notifyError) {
        $queued = 0;
        error_log('[revision-entrega] notificaciones edición #' . $reviewId . ': ' . $notifyError->getMessage());
      }
      return [
        'message' => 'Revisión antes de la ocupación #' . $reviewId . ' actualizada.' . ($queued > 0 ? ' Correos en cola: ' . $queued . '.' : ''),
        'review_id' => (string) $reviewId,
        'review_url' => self::deliveryReviewPublicUrl($reviewId),
      ];
    } catch (\Throwable $error) {
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[revision-entrega] update #' . $reviewId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible actualizar la revisión antes de la ocupación.');
    }
  }

  /** @return array<string,mixed> */
  private function deliveryReviewDelete(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $context = $this->deliveryReviewContext($ticketId);
    $review = $this->deliveryReviewTargetRow($context, $reviewId);
    if ($this->db->delete($this->db->table('jet_cct_revision_entrega'), ['_ID' => $reviewId]) !== 1) {
      throw new \DomainException('No fue posible eliminar la revisión antes de la ocupación.');
    }
    $ticket = (array) $context['ticket'];
    $remaining = array_values(array_diff($this->correctiveReviewSplitIds((string) ($ticket['id_revision_entrega'] ?? '')), [(string) $reviewId]));
    $ticketTable = $this->db->table('jet_cct_tickets');
    $ticketUpdate = $schema->filterTableData($ticketTable, [
      'id_revision_entrega' => implode(',', $remaining),
      'estado_rev_entrega' => $remaining ? 'Si' : 'No',
      'fecha_actualizacion' => time(),
    ]);
    if ($ticketUpdate) {
      $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
    }
    $contract = (array) ($context['contract'] ?? []);
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if ((string) ($contract['id_revision_entrega'] ?? '') === (string) $reviewId && $schema->tableExists($contractTable)) {
      $contractUpdate = $schema->filterTableData($contractTable, ['id_revision_entrega' => (string) (end($remaining) ?: '')]);
      if ($contractUpdate) {
        $this->db->update($contractTable, $contractUpdate, ['_ID' => (int) $contract['_ID']]);
      }
    }
    $postId = (int) $this->correctiveReviewDigits($review['id_inmueble'] ?? '');
    if ($postId > 0 && (string) ($this->deliveryReviewPostMeta($postId, ['id_revision_entrega'])['id_revision_entrega'] ?? '') === (string) $reviewId) {
      $this->deliveryReviewSetPostMeta($postId, 'id_revision_entrega', (string) (end($remaining) ?: ''));
    }
    $actor = $this->ticketCompletionActor();
    $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $this->deliveryReviewHistory($schema, $ticketId, 0, $actor, $actorEmployeeId, 'Se eliminó la revisión antes de la ocupación #' . $reviewId . '.', '', '');
    return [
      'message' => 'Revisión antes de la ocupación #' . $reviewId . ' eliminada.',
      'review_id' => (string) $reviewId,
    ];
  }

  /**
   * Lo que el formulario de WordPress copiaba al contrato, al inmueble (CCT) y a la ficha del inmueble en WordPress.
   * Los servicios inactivos no se borran del contrato ni del inmueble.
   *
   * @param array<string,mixed> $review @param list<string> $services @param array<string,mixed> $contract @param array<string,mixed> $property
   */
  private function deliveryReviewSyncRecords(SchemaInspector $schema, int $reviewId, array $review, array $services, array $contract, array $property): void
  {
    $value = static fn(string $key): string => trim((string) ($review[$key] ?? ''));
    $serviceList = DeliveryReviewForm::values($review['servicios_publicos'] ?? '');
    $readings = [];
    $serviceColumns = [];
    foreach (DeliveryReviewForm::services() as $serviceKey => $service) {
      if (!in_array($serviceKey, $services, true)) {
        continue;
      }
      foreach ($service['items'] as $item) {
        foreach ($item['fields'] as $field) {
          if ($field['contract'] !== '') {
            $serviceColumns[$field['contract']] = $value($field['name']);
          }
          if (isset(DeliveryReviewForm::CONTRACT_READINGS[$field['name']])) {
            $readings[DeliveryReviewForm::CONTRACT_READINGS[$field['name']]] = $value($field['name']);
          }
        }
      }
    }

    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractId = (int) ($contract['_ID'] ?? 0);
    if ($contractId > 0 && $schema->tableExists($contractTable)) {
      $contractUpdate = $schema->filterTableData($contractTable, [
        'id_revision_entrega' => (string) $reviewId,
        'registro_fotografico' => $value('registro_fotografico'),
        'servicios_publicos' => (string) ($review['servicios_publicos'] ?? ''),
        'tiene_parqueadero' => $value('tiene_parqueadero'),
        'tipo_parqueadero' => $value('tipo_parqueadero'),
        'parqueaderos' => $value('parqueaderos'),
        'numero_parqueadero' => $value('numero_parqueadero'),
        'tiene_deposito' => $value('tiene_deposito'),
        'depositos' => $value('depositos'),
        'fecha_linderos' => $value('fech_linderos'),
        'conjunto_lin' => (string) ($review['conjunto'] ?? ''),
        'torre_lin' => (string) ($review['torre'] ?? ''),
        'inmueble_lin' => (string) ($review['inmueble_rep'] ?? ''),
        'parqueadero_lin' => (string) ($review['parqueadero'] ?? ''),
        'deposito_lin' => (string) ($review['deposito'] ?? ''),
      ] + $serviceColumns + $readings);
      if ($contractUpdate) {
        $this->db->update($contractTable, $contractUpdate, ['_ID' => $contractId]);
      }
    }

    $propertyTable = $this->db->table('jet_cct_inmuebles');
    $propertyId = (int) ($property['_ID'] ?? 0);
    if ($propertyId > 0 && $schema->tableExists($propertyTable)) {
      $propertyUpdate = $schema->filterTableData($propertyTable, [
        'parqueadero' => $value('tiene_parqueadero'),
        'tipo_parqueadero' => $value('tipo_parqueadero'),
        'parqueaderos' => $value('parqueaderos'),
        'numero_parqueadero' => $value('numero_parqueadero'),
        'deposito' => $value('tiene_deposito'),
        'depositos' => $value('depositos'),
        'servicios_publicos' => (string) ($review['servicios_publicos'] ?? ''),
        'habitaciones' => $value('habitaciones'),
        'banos' => $value('banos'),
      ] + array_intersect_key($serviceColumns, array_flip(['agua', 'gas', 'luz'])));
      if ($propertyUpdate) {
        $this->db->update($propertyTable, $propertyUpdate, ['_ID' => $propertyId]);
      }
    }

    $postId = (int) $this->correctiveReviewDigits($review['id_inmueble'] ?? '');
    if ($postId > 0) {
      $meta = [
        'id_revision_entrega' => (string) $reviewId,
        'servicios-publicos' => $serviceList,
        'tiene-parqueadero' => [$value('tiene_parqueadero')],
        'tipo-parqueadero' => $value('tipo_parqueadero'),
        'parqueaderos' => $value('parqueaderos'),
        'parqueadero' => $value('numero_parqueadero'),
        'tiene-deposito' => [$value('tiene_deposito')],
        'depositos' => $value('depositos'),
        'habitaciones' => $value('habitaciones'),
        'banos' => $value('banos'),
      ] + array_intersect_key($serviceColumns, array_flip(['agua', 'gas', 'luz']));
      foreach ($meta as $key => $metaValue) {
        $this->deliveryReviewSetPostMeta($postId, $key, $metaValue);
      }
    }
  }

  /** @param list<string> $keys @return array<string,string> */
  private function deliveryReviewPostMeta(int $postId, array $keys): array
  {
    $table = $this->db->table('postmeta');
    if ($postId <= 0 || $keys === [] || !$this->table_exists($table)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $out = [];
    foreach ($this->db->getResults("SELECT `meta_key`, `meta_value` FROM `{$table}` WHERE `post_id` = ? AND `meta_key` IN ({$placeholders}) ORDER BY `meta_id`", array_merge([$postId], $keys)) as $row) {
      $out[(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
    }
    return $out;
  }

  /**
   * Escribe un meta del inmueble en WordPress. Un arreglo se guarda serializado (checkbox de JetEngine) solo si
   * el meta ya existe así o es una lista; un valor vacío no reemplaza datos existentes.
   *
   * @param string|list<string> $value
   */
  private function deliveryReviewSetPostMeta(int $postId, string $key, $value): void
  {
    $table = $this->db->table('postmeta');
    if ($postId <= 0 || !$this->table_exists($table)) {
      return;
    }
    try {
      $current = $this->db->getRow("SELECT `meta_id`, `meta_value` FROM `{$table}` WHERE `post_id` = ? AND `meta_key` = ? ORDER BY `meta_id` LIMIT 1", [$postId, $key]);
      if (is_array($value)) {
        $value = array_values(array_filter($value, static fn($item): bool => trim((string) $item) !== ''));
        $storedAsArray = $key === 'servicios-publicos' || ($current && is_array(@unserialize((string) $current['meta_value'], ['allowed_classes' => false])));
        if ($value === [] && $key !== 'servicios-publicos') {
          return;
        }
        $value = $storedAsArray ? serialize($value) : (string) ($value[0] ?? '');
      } elseif ($value === '' && $key !== 'id_revision_entrega') {
        return;
      }
      if ($current) {
        if ((string) $current['meta_value'] !== $value) {
          $this->db->update($table, ['meta_value' => $value], ['meta_id' => (int) $current['meta_id']]);
        }
      } elseif ($value !== '') {
        $postsTable = $this->db->table('posts');
        if ((int) $this->db->getVar("SELECT COUNT(*) FROM `{$postsTable}` WHERE `ID` = ?", [$postId]) > 0) {
          $this->db->insert($table, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
        }
      }
    } catch (\Throwable $error) {
      error_log('[revision-entrega] meta ' . $key . ' inmueble #' . $postId . ': ' . $error->getMessage());
    }
  }

  /** @param array<string,mixed> $actor */
  private function deliveryReviewHistory(SchemaInspector $schema, int $ticketId, int $reviewId, array $actor, string $employeeId, string $ticketMessage, string $propertyId, string $propertyMessage): void
  {
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($historyTable)) {
      $history = $schema->filterTableData($historyTable, [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_ticket' => $ticketId,
        'fecha' => $now,
        'nombre' => $actor['name'] ?? Auth::user(),
        'correo' => $actor['email'] ?? '',
        'celular' => $actor['phone'] ?? '',
        'respuesta' => '<p>' . htmlspecialchars($ticketMessage, ENT_QUOTES, 'UTF-8') . '</p>',
        'id_revision_entrega' => $reviewId > 0 ? (string) $reviewId : '',
        'id_empleado' => $employeeId,
      ]);
      if ($history) {
        $this->db->insert($historyTable, $history);
      }
    }
    $propertyHistoryTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($propertyMessage !== '' && $propertyId !== '' && $schema->tableExists($propertyHistoryTable)) {
      $history = $schema->filterTableData($propertyHistoryTable, [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $propertyId,
        'fecha' => $now,
        'tipo_reporte' => 'Revision de entrega',
        'observacion' => $propertyMessage,
        'funcionario' => $actor['name'] ?? Auth::user(),
        'id_ticket' => (string) $ticketId,
      ]);
      if ($history) {
        $this->db->insert($propertyHistoryTable, $history);
      }
    }
  }

  /**
   * Correos de Notificaciones internas → Entregas. Al crear se avisa también a quien la registró;
   * al editar solo a los funcionarios configurados para "Revisión antes de la ocupación editada".
   *
   * @param array<string,mixed> $review @param array<string,mixed> $ticket @param array<string,mixed> $actor
   */
  private function deliveryReviewEnqueueNotifications(string $event, array $review, array $ticket, array $actor, int $reviewId): int
  {
    $created = $event === 'revision_entrega_creada';
    $configured = $this->correctiveReviewInternalEmailRecipients($event);
    $recipients = $created ? [['name' => trim((string) ($actor['name'] ?? '')), 'email' => trim((string) ($actor['email'] ?? '')), 'role' => 'creador']] : [];
    foreach ($configured as $recipient) {
      $recipients[] = ['name' => $recipient['name'], 'email' => $recipient['email'], 'role' => 'funcionario_configurado'];
    }
    $recipients = $this->correctiveReviewUniqueEmailRecipients($recipients);
    $copies = array_column($configured, 'email');
    $recipients = \SCM\Support\InternalEmailCopies::recipients($recipients, $copies);
    if ($recipients === []) {
      return 0;
    }
    $reviewUrl = self::signedDeliveryReviewPublicUrl($reviewId);
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) ($ticket['_ID'] ?? '');
    $contract = trim((string) ($review['contrato'] ?? ''));
    $address = trim((string) ($review['direccion'] ?? ''));
    $subject = 'Revisión antes de la ocupación ' . ($created ? 'registrada' : 'editada') . ($contract !== '' ? ' del contrato #' . $contract : '') . ($ticketLabel !== '' ? ' - caso #' . $ticketLabel : '');
    $actorName = trim((string) ($actor['name'] ?? '')) ?: 'Un funcionario';
    $services = DeliveryReviewForm::values($review['servicios_publicos'] ?? '');
    $e = static fn(string $value): string => \SCM\Support\EmailTemplate::e($value);
    $details = '<p style="margin:0;line-height:1.65;"><b>Habitaciones:</b> ' . $e((string) ($review['habitaciones'] ?? '-')) . ' · <b>Baños:</b> ' . $e((string) ($review['banos'] ?? '-'))
      . (trim((string) ($review['piso'] ?? '')) !== '' ? ' · <b>Piso:</b> ' . $e((string) $review['piso']) : '')
      . '<br><b>Servicios públicos activos:</b> ' . $e($services ? implode(', ', $services) : 'Ninguno') . '</p>';
    $queue = new \SCM\Support\EmailQueue($this->db);
    $queued = 0;
    foreach ($recipients as $recipient) {
      $name = trim((string) ($recipient['name'] ?? '')) ?: 'Usuario';
      $role = (string) ($recipient['role'] ?? 'destinatario');
      $intro = $role === 'creador'
        ? 'Registraste la revisión antes de la ocupación #' . $reviewId . '. El siguiente paso del proceso de entrega es el inventario inicial del inmueble.'
        : '<b>' . $e($actorName) . '</b> ' . ($created ? 'registró' : 'editó') . ' la revisión antes de la ocupación #' . $reviewId
          . ($contract !== '' ? ' del contrato <b>#' . $e($contract) . '</b>' : '') . ($address !== '' ? ', inmueble ubicado en <b>' . $e($address) . '</b>' : '') . '.';
      $content = '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . $e($name) . ':</p>'
        . '<p style="margin:0 0 14px;line-height:1.65;">' . $intro . '</p>' . $details;
      $html = \SCM\Support\EmailTemplate::render($subject, $content, ['buttons' => [['url' => $reviewUrl, 'label' => 'Ver revisión antes de la ocupación']]]);
      $queued += $queue->enqueue((string) $recipient['email'], $subject, $html, [
        'source_module' => 'revision_entrega',
        'internal_action' => $event,
        'internal_cc' => $copies,
        'destination_name' => $name,
        'dedupe_key' => $event . ':' . $reviewId . ':' . ($created ? '' : (string) ($review['cct_modified'] ?? time()) . ':') . $role . ':' . strtolower((string) $recipient['email']),
        'payload' => ['review_url' => $reviewUrl, 'reply_to' => (string) ($actor['email'] ?? '')],
        'meta' => [
          'event' => $event,
          'role' => $role,
          'id_revision_entrega' => $reviewId,
          'id_ticket' => $ticketLabel,
          'contrato' => $contract,
          'actor' => $actorName,
        ],
      ]);
    }
    return $queued;
  }

  /**
   * Valores con los que arranca el formulario al crear: lo que ya tiene el contrato, el inmueble y su ficha en WordPress.
   *
   * @param array<string,mixed> $ticket @param array<string,mixed> $contract @param array<string,mixed> $property
   * @return array<string,mixed>
   */
  private function deliveryReviewDefaults(array $ticket, array $contract, array $property): array
  {
    $postId = (int) $this->correctiveReviewDigits($this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']));
    $meta = $this->deliveryReviewPostMeta($postId, ['habitaciones', 'banos']);
    $first = fn(array $values): string => $this->correctiveReviewFirstText($values);
    $defaults = [
      'habitaciones' => $this->correctiveReviewDigits($first([$meta['habitaciones'] ?? '', $property['habitaciones'] ?? ''])),
      'banos' => $this->correctiveReviewDigits($first([$meta['banos'] ?? '', $property['banos'] ?? ''])),
      // El meta "piso" de WordPress puede ser el piso donde queda el apartamento: no se usa como cantidad de pisos.
      'piso' => '1',
      'tiene_parqueadero' => $first([$contract['tiene_parqueadero'] ?? '', $property['parqueadero'] ?? '']),
      'tipo_parqueadero' => $first([$contract['tipo_parqueadero'] ?? '', $property['tipo_parqueadero'] ?? '']),
      'parqueaderos' => $first([$contract['parqueaderos'] ?? '', $property['parqueaderos'] ?? '']),
      'numero_parqueadero' => $first([$contract['numero_parqueadero'] ?? '', $property['numero_parqueadero'] ?? '']),
      'tiene_deposito' => $first([$contract['tiene_deposito'] ?? '', $property['deposito'] ?? '']),
      'depositos' => $first([$contract['depositos'] ?? '', $property['depositos'] ?? '']),
      'registro_fotografico' => $first([$contract['registro_fotografico'] ?? '', $ticket['registro_fotografico'] ?? '']),
      'fech_linderos' => $first([$contract['fecha_linderos'] ?? '']),
      'conjunto' => $contract['conjunto_lin'] ?? '',
      'torre' => $contract['torre_lin'] ?? '',
      'inmueble_rep' => $contract['inmueble_lin'] ?? '',
      'parqueadero' => $contract['parqueadero_lin'] ?? '',
      'deposito' => $contract['deposito_lin'] ?? '',
      'servicios_publicos' => $first([$contract['servicios_publicos'] ?? '', $property['servicios_publicos'] ?? '']),
    ];
    foreach (DeliveryReviewForm::services() as $service) {
      foreach ($service['items'] as $item) {
        foreach ($item['fields'] as $field) {
          if ($field['contract'] !== '') {
            $defaults[$field['name']] = $first([$contract[$field['contract']] ?? '', $property[$field['contract']] ?? '']);
          }
        }
      }
    }
    return $defaults;
  }

  /** @param array<string,mixed> $context */
  private function renderDeliveryReviewPanel(array $context, int $editReviewId = 0): string
  {
    $ticket = (array) ($context['ticket'] ?? []);
    $contract = (array) ($context['contract'] ?? []);
    $reviews = (array) ($context['reviews'] ?? []);
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $editReview = [];
    foreach ($reviews as $review) {
      if ($editReviewId > 0 && (int) ($review['_ID'] ?? 0) === $editReviewId) {
        $editReview = $review;
      }
    }
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review scm-preventive-review scm-delivery-review">
      <?php if ($reviews && !$editReview): ?>
        <section class="scm-corrective-existing">
          <h3>Revisión antes de la ocupación registrada</h3>
          <?php foreach ($reviews as $review): $id = (int) ($review['_ID'] ?? 0); $services = DeliveryReviewForm::values($review['servicios_publicos'] ?? ''); ?>
            <article>
              <div class="scm-corrective-existing-info">
                <strong>Revisión #<?= $h((string) $id) ?></strong>
                <span><?= $h($this->correctiveReviewDateLabel($this->correctiveReviewDisplayDateRaw($review))) ?> · <?= $h((string) ($review['creador'] ?? '')) ?></span>
                <span>Habitaciones: <?= $h(trim((string) ($review['habitaciones'] ?? '')) ?: '-') ?> · Baños: <?= $h(trim((string) ($review['banos'] ?? '')) ?: '-') ?> · Piso: <?= $h(trim((string) ($review['piso'] ?? '')) ?: '-') ?></span>
                <span>Servicios públicos: <?= $h($services ? implode(', ', $services) : 'ninguno activo') ?></span>
              </div>
              <div class="scm-corrective-existing-actions">
                <button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-url="<?= $h(self::deliveryReviewPublicUrl($id)) ?>" data-iframe-title="Revisión antes de la ocupación #<?= $h((string) $id) ?>">Ver informe</button>
                <button type="button" class="scm-acta-button scm-acta-secondary" data-delivery-edit-review="<?= $h((string) $id) ?>">Editar</button>
                <button type="button" class="scm-acta-button scm-acta-danger" data-delivery-delete-review="<?= $h((string) $id) ?>">Eliminar</button>
              </div>
            </article>
          <?php endforeach; ?>
          <span data-corrective-review-message aria-live="polite"></span>
        </section>
      <?php else: ?>
        <?= $this->renderDeliveryReviewForm($ticket, $contract, (array) ($context['property'] ?? []), $editReview) ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /**
   * Asistente por pasos: Inmueble → Linderos → Espacios → Servicios → Resumen. Autoguardado en el navegador.
   *
   * @param array<string,mixed> $ticket @param array<string,mixed> $contract @param array<string,mixed> $property @param array<string,mixed> $review
   */
  private function renderDeliveryReviewForm(array $ticket, array $contract, array $property, array $review): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $editing = $review !== [];
    $reviewId = (int) ($review['_ID'] ?? 0);
    $ticketId = (int) ($ticket['_ID'] ?? 0);
    $values = $editing ? $review : $this->deliveryReviewDefaults($ticket, $contract, $property);
    $v = static fn(string $key): string => trim((string) ($values[$key] ?? ''));
    $boundaryTs = $values['fech_linderos'] ?? '';
    $boundaryTs = is_numeric($boundaryTs) ? (int) $boundaryTs : (strtotime((string) $boundaryTs) ?: 0);
    $boundaryDate = $boundaryTs > 0 ? date('Y-m-d', $boundaryTs) : date('Y-m-d');
    $activeServices = DeliveryReviewForm::values($values['servicios_publicos'] ?? '');
    $extraServices = DeliveryReviewForm::values($values['servicios_adicionales'] ?? '');
    $select = function (string $name, int $glossary, string $selected, string $placeholder, bool $required = false, string $attrs = '') use ($h): string {
      $html = '<select name="' . $h($name) . '"' . ($required ? ' required' : '') . $attrs . '><option value="">' . $h($placeholder) . '</option>';
      $options = DeliveryReviewForm::glossary($this->db, $glossary);
      if ($selected !== '' && !in_array($selected, $options, true)) {
        $options[] = $selected;
      }
      foreach ($options as $option) {
        $html .= '<option value="' . $h($option) . '"' . ($option === $selected ? ' selected' : '') . '>' . $h($option) . '</option>';
      }
      return $html . '</select>';
    };
    $yesNo = static function (string $name, string $selected, string $label) use ($h): string {
      $html = '<div class="scm-prev-choice" role="radiogroup" aria-label="' . $h($label) . '">';
      foreach (['Si' => 'Sí', 'No' => 'No'] as $value => $text) {
        $html .= '<label><input type="radio" name="' . $h($name) . '" value="' . $value . '" required' . ($selected === $value ? ' checked' : '') . '><span>' . $text . '</span></label>';
      }
      return $html . '</div>';
    };
    $summary = [
      'Caso' => '#' . (trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketId),
      'Contrato' => ltrim($this->correctiveReviewFirstText([$contract['contrato'] ?? '', $ticket['contrato'] ?? '']), '#') ?: '-',
      'Inmueble simi' => $this->correctiveReviewFirstText([$contract['inmueble'] ?? '', $ticket['inmueble'] ?? '']) ?: '-',
      'Tipo de inmueble' => $this->correctiveReviewFirstText([$review['tip_inm'] ?? '', $ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '']) ?: '-',
      'Dirección' => $this->correctiveReviewFirstText([$review['direccion'] ?? '', $contract['direccion'] ?? '', $ticket['direccion'] ?? '']) ?: '-',
      'Propietario' => $this->correctiveReviewFirstText([$contract['propietario'] ?? '', $ticket['propietario'] ?? '']) ?: '-',
      'Arrendatario' => $this->correctiveReviewFirstText([$contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']) ?: '-',
    ];
    $steps = ['inmueble' => 'Inmueble', 'linderos' => 'Linderos', 'espacios' => 'Espacios', 'servicios' => 'Servicios', 'resumen' => 'Resumen'];
    $spaceRows = [];
    foreach (DeliveryReviewForm::GENERAL_SPACES as $key) {
      $rows = DeliveryReviewForm::rows($values[$key] ?? '');
      if (!$editing && $rows === []) {
        $rows = in_array($key, DeliveryReviewForm::DEFAULT_SPACES, true) ? [[]] : [];
      }
      $spaceRows[$key] = $rows;
    }
    // Espacios interiores por piso: al crear, habitaciones y baños salen de lo registrado en el inmueble, en el piso 1.
    $floorRooms = $editing ? DeliveryReviewForm::spacesByFloor($review) : [];
    if (!$editing) {
      foreach (array_intersect(DeliveryReviewForm::DEFAULT_SPACES, DeliveryReviewForm::floorSpaces()) as $key) {
        $floorRooms[1][$key] = [['tipo' => '', 'contiene' => [], 'observacion' => '']];
      }
    }
    $floors = max(1, min(DeliveryReviewForm::MAX_FLOORS, max((int) preg_replace('/\D+/', '', $v('piso')), $floorRooms ? max(array_keys($floorRooms)) : 1)));
    $floorCounts = [];
    for ($floor = 1; $floor <= $floors; $floor++) {
      $floorCounts[$floor] = ['habitacion' => count($floorRooms[$floor]['habitacion'] ?? []), 'bano' => count($floorRooms[$floor]['bano'] ?? [])];
    }
    foreach (['habitacion' => 'habitaciones', 'bano' => 'banos'] as $base => $column) {
      $others = 0;
      for ($floor = 2; $floor <= $floors; $floor++) {
        $others += $floorCounts[$floor][$base];
      }
      $floorCounts[1][$base] = min(30, max($floorCounts[1][$base], (int) $v($column) - $others));
      if (!$editing) {
        $floorRooms[1][$base] = [];
        for ($i = 0; $i < $floorCounts[1][$base]; $i++) {
          $floorRooms[1][$base][] = ['tipo' => DeliveryReviewForm::typeOptions($base)[$i === 0 ? 0 : 1], 'contiene' => [], 'observacion' => '', 'auto' => true];
        }
      }
    }
    ob_start();
    ?>
    <form class="scm-prev-wizard scm-delivery-wizard" data-delivery-review-form<?= $editing ? ' data-delivery-review-edit' : '' ?> data-delivery-review-version="<?= $h($editing ? hash('sha256', (string) ($review['cct_modified'] ?? '') . $reviewId) : 'new') ?>" autocomplete="off" novalidate>
      <input type="hidden" name="ticket_pk" value="<?= $h((string) $ticketId) ?>">
      <?php if ($editing): ?><input type="hidden" name="review_id" value="<?= $h((string) $reviewId) ?>"><?php endif; ?>
      <ol class="scm-prev-steps" data-prev-steps>
        <?php $n = 0; foreach ($steps as $stepKey => $stepLabel): $n++; ?>
          <li><button type="button" data-prev-goto="<?= $h($stepKey) ?>"<?= $n === 1 ? ' aria-current="step"' : '' ?>><span><?= $n ?></span><?= $h($stepLabel) ?></button></li>
        <?php endforeach; ?>
      </ol>
      <div class="scm-prev-progress" aria-hidden="true"><span data-prev-progress></span></div>
      <p class="scm-delivery-autosave"><span class="material-symbols-outlined" aria-hidden="true">cloud_done</span>Lo que escribas se guarda solo en este equipo mientras terminas; puedes cerrar y continuar después.</p>

      <section class="scm-prev-step" data-prev-step="inmueble">
        <header><h3><?= $editing ? 'Editando revisión antes de la ocupación #' . $h((string) $reviewId) : 'Nueva revisión antes de la ocupación' ?></h3><p>Confirma los datos del inmueble. Lo que ya estaba en el contrato aparece diligenciado.</p></header>
        <div class="scm-prev-summary">
          <?php foreach ($summary as $label => $value): ?><div><span><?= $h($label) ?></span><strong><?= $h($value) ?></strong></div><?php endforeach; ?>
        </div>
        <div class="scm-delivery-grid scm-delivery-grid--3">
          <label class="scm-delivery-field"><span>Cantidad de pisos *</span><input type="number" name="piso" min="1" max="<?= DeliveryReviewForm::MAX_FLOORS ?>" step="1" inputmode="numeric" required value="<?= $h((string) $floors) ?>" data-delivery-floors-input></label>
        </div>
        <div class="scm-delivery-floor-counts<?= $floors === 1 ? ' is-single' : '' ?>" data-delivery-floor-counts>
          <?php for ($floor = 1; $floor <= $floors; $floor++): ?><?= $this->renderDeliveryFloorCount((string) $floor, $floorCounts[$floor]['habitacion'], $floorCounts[$floor]['bano']) ?><?php endfor; ?>
        </div>
        <template data-delivery-floor-count-template><?= $this->renderDeliveryFloorCount('__FLOOR__', 0, 0) ?></template>
        <p class="scm-prev-muted"><?= $editing ? '' : 'Según el inmueble registrado: ' . $h($v('habitaciones') !== '' ? $v('habitaciones') : 'sin dato') . ' habitación(es) y ' . $h($v('banos') !== '' ? $v('banos') : 'sin dato') . ' baño(s). ' ?>Si tiene varios pisos, reparte las habitaciones y los baños en cada piso; en el paso Espacios sale una ficha por cada uno.</p>
        <div class="scm-delivery-grid">
          <fieldset class="scm-prev-block">
            <legend>¿Tiene parqueadero? *</legend>
            <?= $yesNo('tiene_parqueadero', $v('tiene_parqueadero'), '¿Tiene parqueadero?') ?>
            <div class="scm-delivery-grid scm-delivery-grid--3" data-delivery-when="tiene_parqueadero=Si"<?= $v('tiene_parqueadero') === 'Si' ? '' : ' hidden' ?>>
              <label class="scm-delivery-field"><span>Tipo *</span><?= $select('tipo_parqueadero', 1011, $v('tipo_parqueadero'), 'Selecciona', true) ?></label>
              <label class="scm-delivery-field"><span>Cantidad *</span><input type="number" name="parqueaderos" min="1" max="20" inputmode="numeric" required value="<?= $h($v('parqueaderos')) ?>"></label>
              <label class="scm-delivery-field"><span>Número(s) *</span><input type="text" name="numero_parqueadero" maxlength="120" required value="<?= $h($v('numero_parqueadero')) ?>" placeholder="Ej: 12, 13"></label>
            </div>
          </fieldset>
          <fieldset class="scm-prev-block">
            <legend>¿Tiene depósito? *</legend>
            <?= $yesNo('tiene_deposito', $v('tiene_deposito'), '¿Tiene depósito?') ?>
            <div data-delivery-when="tiene_deposito=Si"<?= $v('tiene_deposito') === 'Si' ? '' : ' hidden' ?>>
              <label class="scm-delivery-field"><span>Cantidad *</span><input type="number" name="depositos" min="1" max="20" inputmode="numeric" required value="<?= $h($v('depositos')) ?>"></label>
            </div>
          </fieldset>
        </div>
        <label class="scm-delivery-field"><span>Enlace del registro fotográfico *</span><input type="url" name="registro_fotografico" required maxlength="500" value="<?= $h($v('registro_fotografico')) ?>" placeholder="https://drive.google.com/drive/folders/…"></label>
        <p class="scm-prev-muted">Sube las fotos a la carpeta del inmueble en Drive y pega aquí el enlace compartido. <a href="https://drive.google.com/drive/u/1/folders/1gqJf1QJcd2zxF4yZF46ZeU-I8JbKCwBi" target="_blank" rel="noopener">Abrir Drive de registros fotográficos</a></p>
      </section>

      <section class="scm-prev-step" data-prev-step="linderos" hidden>
        <header><h3>Linderos</h3><p>Escribe con qué colinda cada lado. Si el contrato ya tenía linderos, aparecen para que solo los confirmes.</p></header>
        <label class="scm-delivery-field scm-delivery-field--date"><span>Fecha de toma de linderos *</span><input type="date" name="fech_linderos" required value="<?= $h($boundaryDate) ?>"></label>
        <?php foreach (DeliveryReviewForm::boundaries() as $key => $group): $rows = DeliveryReviewForm::rows($values[$key] ?? '') ?: [[]]; ?>
          <div class="scm-prev-group scm-delivery-repeater"<?= $group['when'] !== '' ? ' data-delivery-when="' . $h($group['when']) . '=Si"' . ($v($group['when']) === 'Si' ? '' : ' hidden') : '' ?>>
            <header>
              <div><h3><?= $h($group['label']) ?><?= $group['required'] ? ' *' : '' ?></h3><p><?= $h($group['hint']) ?></p></div>
              <?php if ($group['multiple']): ?><button type="button" class="scm-prev-quick" data-delivery-add="lin-<?= $h($key) ?>">Agregar <?= $h(mb_strtolower(rtrim($group['label'], 's'), 'UTF-8')) ?></button><?php endif; ?>
            </header>
            <div data-delivery-list="lin-<?= $h($key) ?>">
              <?php foreach ($rows as $index => $row): ?><?= $this->renderDeliveryBoundaryRow($key, $group, (string) $index, $row) ?><?php endforeach; ?>
            </div>
            <?php if ($group['multiple']): ?><template data-delivery-template="lin-<?= $h($key) ?>"><?= $this->renderDeliveryBoundaryRow($key, $group, '__INDEX__', []) ?></template><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="scm-prev-step" data-prev-step="espacios" hidden>
        <header><h3>Registro fotográfico por espacio</h3><p>Por cada espacio marca qué quedó en las fotos y anota observaciones. Usa "Sin novedad" si todo está bien.</p></header>
        <h4 class="scm-delivery-section-title">Exteriores del inmueble</h4>
        <?php foreach ($spaceRows as $key => $rows): $space = DeliveryReviewForm::spaces()[$key]; $options = DeliveryReviewForm::glossary($this->db, $space['glossary']); ?>
          <div class="scm-prev-group scm-delivery-repeater" data-delivery-space="<?= $h($key) ?>">
            <header>
              <div><h3><span class="material-symbols-outlined" aria-hidden="true"><?= $h($space['icon']) ?></span> <?= $h($space['label']) ?></h3><p data-delivery-space-count><?= count($rows) ?> registrado(s)</p></div>
              <button type="button" class="scm-prev-quick" data-delivery-add="esp-<?= $h($key) ?>">Agregar <?= $h(mb_strtolower($space['item'], 'UTF-8')) ?></button>
            </header>
            <div data-delivery-list="esp-<?= $h($key) ?>">
              <?php foreach ($rows as $index => $row): ?><?= $this->renderDeliverySpaceRow($key, $space, $options, (string) $index, $row) ?><?php endforeach; ?>
            </div>
            <template data-delivery-template="esp-<?= $h($key) ?>"><?= $this->renderDeliverySpaceRow($key, $space, $options, '__INDEX__', []) ?></template>
          </div>
        <?php endforeach; ?>
        <h4 class="scm-delivery-section-title">Espacios por piso</h4>
        <div data-delivery-floors>
          <?php for ($floor = 1; $floor <= $floors; $floor++): ?><?= $this->renderDeliveryFloorBlock((string) $floor, $floorRooms[$floor] ?? [], $floors) ?><?php endfor; ?>
        </div>
        <template data-delivery-floor-template><?= $this->renderDeliveryFloorBlock('__FLOOR__', [], 2) ?></template>
        <?php foreach (DeliveryReviewForm::floorSpaces() as $base): ?>
          <template data-delivery-template="esp-<?= $h($base) ?>"><?= $this->renderDeliveryFloorRow($base, '__FLOOR__', '__INDEX__', []) ?></template>
        <?php endforeach; ?>
      </section>

      <section class="scm-prev-step" data-prev-step="servicios" hidden>
        <header><h3>Servicios públicos</h3><p>Marca los servicios activos del inmueble y diligencia sus datos. Las observaciones vacías se guardan como "Ninguna".</p></header>
        <div class="scm-delivery-chips scm-delivery-chips--lg" role="group" aria-label="Servicios públicos activos">
          <?php foreach (DeliveryReviewForm::services() as $serviceKey => $service): ?>
            <label><input type="checkbox" name="servicios_publicos[]" value="<?= $h($service['option']) ?>" data-delivery-service-toggle="<?= $h($serviceKey) ?>" data-label="<?= $h($service['label']) ?>"<?= in_array($service['option'], $activeServices, true) ? ' checked' : '' ?>><span><span class="material-symbols-outlined" aria-hidden="true"><?= $h($service['icon']) ?></span><?= $h($service['label']) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php foreach (DeliveryReviewForm::services() as $serviceKey => $service): ?>
          <div class="scm-prev-group scm-delivery-service" data-delivery-service="<?= $h($serviceKey) ?>"<?= in_array($service['option'], $activeServices, true) ? '' : ' hidden' ?>>
            <header><div><h3><span class="material-symbols-outlined" aria-hidden="true"><?= $h($service['icon']) ?></span> Servicio de <?= $h(mb_strtolower($service['label'], 'UTF-8')) ?></h3></div></header>
            <?php foreach ($service['items'] as $item): $observation = $v($item['observation']); ?>
              <fieldset class="scm-prev-block scm-delivery-item">
                <legend><?= $h($item['label']) ?></legend>
                <div class="scm-delivery-grid scm-delivery-grid--3">
                  <?php foreach ($item['fields'] as $field): $name = 'servicios[' . $field['name'] . ']'; $value = $v($field['name']); ?>
                    <label class="scm-delivery-field"><span><?= $h($field['label']) ?><?= $field['required'] ? ' *' : '' ?></span>
                      <?php if ($field['type'] === 'select'): ?>
                        <?= $select($name, $field['glossary'], $value, 'Selecciona', $field['required']) ?>
                      <?php elseif ($field['type'] === 'date'): ?>
                        <input type="date" name="<?= $h($name) ?>" value="<?= $h($this->deliveryReviewDate($value)) ?>"<?= $field['required'] ? ' required' : '' ?>>
                      <?php elseif ($field['type'] === 'money'): ?>
                        <input type="text" name="<?= $h($name) ?>" inputmode="numeric" value="<?= $h($value) ?>" placeholder="$ 0"<?= $field['required'] ? ' required' : '' ?>>
                      <?php else: ?>
                        <input type="text" name="<?= $h($name) ?>" maxlength="200" value="<?= $h($value) ?>"<?= $field['required'] ? ' required' : '' ?>>
                      <?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                  <label class="scm-delivery-field scm-delivery-field--obs"><span>Observaciones</span><input type="text" name="servicios[<?= $h($item['observation']) ?>]" maxlength="1000" value="<?= $h(DeliveryReviewForm::isNone($observation) ? '' : $observation) ?>" placeholder="Ninguna"></label>
                </div>
              </fieldset>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <fieldset class="scm-prev-block">
          <legend>Servicios adicionales</legend>
          <div class="scm-delivery-chips" role="group" aria-label="Servicios adicionales">
            <?php foreach (DeliveryReviewForm::glossary($this->db, 577) as $option): ?>
              <label><input type="checkbox" name="servicios_adicionales[]" value="<?= $h($option) ?>"<?= in_array($option, $extraServices, true) ? ' checked' : '' ?>><span><?= $h(DeliveryReviewForm::EXTRA_SERVICE_LABELS[$option] ?? $option) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      </section>

      <section class="scm-prev-step" data-prev-step="resumen" hidden>
        <header><h3>Revisa y guarda</h3><p>Al guardar se actualizan el caso, el contrato y la ficha del inmueble, y se registra en el historial.</p></header>
        <div class="scm-prev-review" data-delivery-summary></div>
      </section>

      <footer class="scm-prev-nav">
        <button type="button" class="scm-acta-button scm-acta-secondary" data-prev-back hidden>Anterior</button>
        <?php if ($editing): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-delivery-cancel-edit>Cancelar edición</button><?php endif; ?>
        <span data-corrective-review-message aria-live="polite"></span>
        <button type="button" class="scm-acta-button" data-prev-next>Siguiente</button>
        <button type="submit" class="scm-acta-button" data-prev-submit hidden><?= $editing ? 'Actualizar revisión' : 'Guardar revisión' ?></button>
      </footer>
    </form>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $group @param array<string,mixed> $row */
  private function renderDeliveryBoundaryRow(string $key, array $group, string $index, array $row): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $prefix = 'linderos[' . $key . '][' . $index . ']';
    $labels = ['norte' => 'Norte', 'sur' => 'Sur', 'oriente' => 'Oriente', 'occidente' => 'Occidente', 'cenit' => 'Cenit (arriba)', 'nadir' => 'Nadir (abajo)'];
    $html = '<div class="scm-delivery-row" data-delivery-row>';
    if ($group['multiple']) {
      $html .= '<div class="scm-delivery-row-head"><strong data-delivery-row-title>' . $h(rtrim($group['label'], 's')) . '</strong><button type="button" class="scm-delivery-remove" data-delivery-remove aria-label="Quitar fila">Quitar</button></div>';
    }
    $html .= '<div class="scm-delivery-grid scm-delivery-grid--' . (count($group['sides']) > 4 ? '3' : '2') . '">';
    if ($key === 'parqueadero') {
      $html .= '<label class="scm-delivery-field"><span># Parqueadero</span><input type="text" name="' . $h($prefix . '[num]') . '" maxlength="40" value="' . $h($row['num_parqueadero'] ?? '') . '"></label>';
    }
    foreach ($group['sides'] as $side) {
      $html .= '<label class="scm-delivery-field"><span>' . $h($labels[$side]) . '</span><input type="text" name="' . $h($prefix . '[' . $side . ']') . '" maxlength="300" value="' . $h($row[$side . '_' . $group['suffix']] ?? '') . '"></label>';
    }
    return $html . '</div></div>';
  }

  /** @param array<string,mixed> $space @param list<string> $options @param array<string,mixed> $row */
  private function renderDeliverySpaceRow(string $key, array $space, array $options, string $index, array $row): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $prefix = 'espacios[' . $key . '][' . $index . ']';
    $contains = DeliveryReviewForm::values($row[$space['contains']] ?? []);
    $observation = trim((string) ($row[$space['observation']] ?? ''));
    $html = '<div class="scm-delivery-row scm-delivery-space-row" data-delivery-row>'
      . '<div class="scm-delivery-row-head"><strong data-delivery-row-title>' . $h($space['item']) . '</strong>'
      . '<button type="button" class="scm-delivery-link" data-delivery-all>Marcar todo</button>'
      . '<button type="button" class="scm-delivery-remove" data-delivery-remove aria-label="Quitar ' . $h(mb_strtolower($space['item'], 'UTF-8')) . '">Quitar</button></div>';
    return $html . $this->renderDeliveryContents($prefix, $options, $contains, $observation) . '</div>';
  }

  /** Chips de "qué contiene" (glosario + opciones antiguas) y observación con "Sin novedad". @param list<string> $options @param list<string> $contains */
  private function renderDeliveryContents(string $prefix, array $options, array $contains, string $observation): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $html = '<div class="scm-delivery-chips" role="group" aria-label="Qué contiene el registro">';
    foreach (array_merge($options, array_values(array_diff($contains, $options))) as $option) {
      $html .= '<label><input type="checkbox" name="' . $h($prefix . '[contiene][]') . '" value="' . $h($option) . '"' . (in_array($option, $contains, true) ? ' checked' : '') . '><span>' . $h($option) . '</span></label>';
    }
    return $html . '</div><div class="scm-delivery-obs"><label class="scm-prev-observation"><span>Observación</span><textarea name="' . $h($prefix . '[observacion]') . '" rows="2" maxlength="2000" placeholder="Describe detalles o daños visibles">' . $h($observation) . '</textarea></label>'
      . '<button type="button" class="scm-prev-quick" data-delivery-none>Sin novedad</button></div>';
  }

  /** Cantidad de habitaciones y baños de un piso (paso Inmueble). */
  private function renderDeliveryFloorCount(string $floor, int $rooms, int $baths): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    return '<div class="scm-delivery-floor-count" data-delivery-floor-count="' . $h($floor) . '">'
      . '<strong class="scm-delivery-floor-label">Piso ' . $h($floor) . '</strong>'
      . '<label class="scm-delivery-field"><span>Habitaciones *</span><input type="number" name="hab_piso[' . $h($floor) . ']" min="0" max="30" step="1" inputmode="numeric" required value="' . $rooms . '" data-delivery-count="habitacion_p' . $h($floor) . '"></label>'
      . '<label class="scm-delivery-field"><span>Baños *</span><input type="number" name="ban_piso[' . $h($floor) . ']" min="0" max="30" step="1" inputmode="numeric" required value="' . $baths . '" data-delivery-count="bano_p' . $h($floor) . '"></label>'
      . '</div>';
  }

  /**
   * Habitaciones y baños de un piso (paso Espacios).
   *
   * @param array<string,list<array<string,mixed>>> $rooms
   */
  private function renderDeliveryFloorBlock(string $floor, array $rooms, int $floors): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $spaces = DeliveryReviewForm::spaces();
    $html = '<div class="scm-prev-group scm-delivery-floor" data-delivery-floor="' . $h($floor) . '">'
      . '<header><div><h3><span class="material-symbols-outlined" aria-hidden="true">stairs</span> <span data-delivery-floor-title>' . ($floors > 1 ? 'Piso ' . $h($floor) : 'Espacios interiores') . '</span></h3>'
      . '<p>Todos los espacios de este piso. La habitación y el baño de servicio se eligen en el tipo.</p></div></header>';
    foreach (DeliveryReviewForm::floorSpaces() as $base) {
      $rows = $rooms[$base] ?? [];
      $list = 'esp-' . $base . '_p' . $floor;
      $html .= '<div class="scm-delivery-repeater scm-delivery-floor-space" data-delivery-space="' . $h($base) . '">'
        . '<div class="scm-delivery-subhead"><strong><span class="material-symbols-outlined" aria-hidden="true">' . $h($spaces[$base]['icon']) . '</span> ' . $h($spaces[$base]['label']) . '</strong>'
        . '<span data-delivery-space-count>' . ($rows ? count($rows) . ' registrado(s)' : 'No aplica: agrégalo si el piso lo tiene') . '</span>'
        . '<button type="button" class="scm-prev-quick" data-delivery-add="' . $h($list) . '">Agregar ' . $h(mb_strtolower($spaces[$base]['item'], 'UTF-8')) . '</button></div>'
        . '<div data-delivery-list="' . $h($list) . '">';
      foreach ($rows as $index => $row) {
        $html .= $this->renderDeliveryFloorRow($base, $floor, (string) $index, $row);
      }
      $html .= '</div></div>';
    }
    return $html . '</div>';
  }

  /** @param array<string,mixed> $row tipo, contiene, observacion y "auto" si el tipo lo puso el formulario */
  private function renderDeliveryFloorRow(string $base, string $floor, string $index, array $row): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $space = DeliveryReviewForm::spaces()[$base];
    $prefix = 'espacios[' . $base . '_p' . $floor . '][' . $index . ']';
    $typeField = '';
    if (isset(DeliveryReviewForm::MERGED[$base])) {
      $typeField = $this->renderDeliveryTypeSelect($base, $prefix, $row);
    }
    return '<div class="scm-delivery-row scm-delivery-space-row" data-delivery-row>'
      . '<div class="scm-delivery-row-head"><strong data-delivery-row-title>' . $h($space['item']) . '</strong>'
      . '<button type="button" class="scm-delivery-link" data-delivery-all>Marcar todo</button>'
      . '<button type="button" class="scm-delivery-remove" data-delivery-remove aria-label="Quitar ' . $h(mb_strtolower($space['item'], 'UTF-8')) . '">Quitar</button></div>'
      . $typeField
      . $this->renderDeliveryContents($prefix, DeliveryReviewForm::glossary($this->db, $space['glossary']), array_values((array) ($row['contiene'] ?? [])), (string) ($row['observacion'] ?? ''))
      . '</div>';
  }

  /** Lista de tipo de habitación o baño. @param array<string,mixed> $row */
  private function renderDeliveryTypeSelect(string $base, string $prefix, array $row): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $type = (string) ($row['tipo'] ?? '');
    $options = DeliveryReviewForm::typeOptions($base);
    if ($type !== '' && !in_array($type, $options, true)) {
      $options[] = $type;
    }
    $select = '<select name="' . $h($prefix . '[tipo]') . '" required data-delivery-type' . (!empty($row['auto']) ? ' data-auto="1"' : '') . '><option value="">Selecciona</option>';
    foreach ($options as $option) {
      $select .= '<option value="' . $h($option) . '"' . ($option === $type ? ' selected' : '') . '>' . $h($option) . '</option>';
    }
    $select .= '</select>';
    return '<label class="scm-delivery-field scm-delivery-field--type"><span>Tipo de ' . ($base === 'bano' ? 'baño' : 'habitación') . ' *</span>' . $select . '</label>';
  }
}
