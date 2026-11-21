<?php

declare(strict_types=1);

namespace SCM\Modules\Calendar;

use SCM\Core\Database;

/**
 * API propia del calendario del panel (reemplaza calendario-actividades). Atiende las acciones que usa el panel con la
 * misma respuesta {success,message,data} para que el motor JS del calendario no cambie. La sesión y el nonce se exigen
 * en public/calendar-api.php; los procesos del servidor (p. ej. Revisión crítica) la llaman directamente sin sesión.
 * No hay sincronización con Google Calendar.
 */
final class CalendarApi
{
  private const MAX_BULK_EVENTS = 400;

  private CalendarStore $store;
  private CalendarMailer $mailer;
  private ?string $me = null;

  public function __construct(Database $db, ?CalendarMailer $mailer = null)
  {
    $this->store = new CalendarStore($db);
    $this->mailer = $mailer ?? new CalendarMailer($this->store);
  }

  /**
   * @param array<string,mixed> $input
   * @return array{success:bool,message:string,data:mixed,status?:int}
   */
  public function handle(string $action, array $input): array
  {
    $handlers = [
      'listar_categorias' => 'listCategories',
      'listar_funcionarios' => 'listEmployees',
      'listar_items_calendario' => 'listItems',
      'filtrar_eventos_admin' => 'filterEventsAdmin',
      'listar_pendientes_vencidos' => 'listOverdue',
      'obtener_tickets_funcionario' => 'employeeTickets',
      'crear_evento' => 'createEvent',
      'crear_eventos' => 'createEvents',
      'crear_item_calendario' => 'createItem',
      'crear_recordatorio' => 'createReminder',
      'actualizar_evento' => 'updateEvent',
      'trasladar_evento' => 'moveEvent',
      'cambiar_estado' => 'completeEvent',
      'actualizar_recordatorio' => 'updateReminder',
      'actualizar_recordatorio_estado' => 'updateReminderStatus',
    ];
    if (in_array($action, ['crear_tarea', 'actualizar_tarea_estado'], true)) {
      return self::fail('Las tareas fueron retiradas del calendario. Solo se permiten eventos y recordatorios.', [], 410);
    }
    if (!isset($handlers[$action])) {
      return self::fail('Acción no válida.', [], 400);
    }
    try {
      return $this->{$handlers[$action]}($input);
    } catch (\InvalidArgumentException $exception) {
      return self::fail($exception->getMessage());
    }
  }

  // ── Consultas ──────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listCategories(array $input): array
  {
    return self::ok('Categorías obtenidas', $this->store->categories());
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listEmployees(array $input): array
  {
    return self::ok('Funcionarios obtenidos', $this->store->employees());
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listItems(array $input): array
  {
    $this->store->finalizeExpiredReminders();
    $types = ['evento', 'recordatorio'];
    if (trim((string) ($input['tipo_item'] ?? '')) !== '') {
      $types = [self::itemType((string) $input['tipo_item'])];
    } elseif (is_array($input['tipos_item'] ?? null) && $input['tipos_item'] !== []) {
      $types = array_values(array_unique(array_map(static fn($type): string => self::itemType((string) $type), $input['tipos_item'])));
    }
    $filters = self::filters($input);
    $items = [];
    if (in_array('evento', $types, true)) {
      $items = $this->store->events($filters)['rows'];
    }
    if (in_array('recordatorio', $types, true)) {
      $items = array_merge($items, $this->store->reminders($filters));
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['fecha_inicio'] ?? ''), (string) ($b['fecha_inicio'] ?? '')));
    $limit = isset($input['limite']) ? max(1, min(CalendarStore::MAX_ROWS, (int) $input['limite'])) : CalendarStore::MAX_ROWS;
    return self::ok('Items de calendario', array_slice($items, max(0, (int) ($input['offset'] ?? 0)), $limit));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function filterEventsAdmin(array $input): array
  {
    $page = max(1, (int) ($input['pagina'] ?? 1));
    $limit = max(1, min(CalendarStore::MAX_ROWS, (int) ($input['limite'] ?? 50)));
    $result = $this->store->events(self::filters($input), $limit, ($page - 1) * $limit);
    return self::ok('Eventos filtrados', ['data' => $result['rows'], 'total' => $result['total']]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listOverdue(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de empleado requerido.');
    }
    return self::ok('Eventos pendientes vencidos', $this->store->overdueEvents($employee));
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function employeeTickets(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de funcionario requerido');
    }
    return self::ok('Tickets encontrados', $this->store->openTicketsForEmployee($employee));
  }

  // ── Crear ──────────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createEvent(array $input): array
  {
    foreach (['titulo', 'fecha_inicio', 'fecha_fin', 'id_empleado', 'id_categoria'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio.");
      }
    }
    [$start, $end] = self::dateRange((string) $input['fecha_inicio'], (string) $input['fecha_fin']);
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $this->assertCategory((int) $input['id_categoria']);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $data = $this->eventData($input, $employee, $start, $end);
    $id = $this->store->insertEvent($data);
    $this->mailer->newEvent($data + ['notificar_funcionarios' => $input['notificar_funcionarios'] ?? []], $id);
    $ticket = (int) ($input['id_ticket'] ?? 0);
    if ($ticket > 0) {
      $this->linkTicket($ticket, $id, $employee, $input, $start, $end);
    }
    return self::ok('Evento #' . $id . ' creado exitosamente.', ['id' => $id]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createEvents(array $input): array
  {
    $events = is_array($input['eventos'] ?? null) ? $input['eventos'] : null;
    $employees = is_array($input['empleados'] ?? null) ? array_values(array_unique(array_map(static fn($id): string => trim((string) $id), $input['empleados']))) : null;
    if ($events === null || $employees === null || $events === [] || $employees === []) {
      return self::fail('Formato incorrecto: se esperaba un array de eventos y empleados');
    }
    if (count($events) * count($employees) > self::MAX_BULK_EVENTS) {
      return self::fail('Son demasiadas fechas a la vez. El máximo es ' . self::MAX_BULK_EVENTS . ' eventos.');
    }
    foreach ($employees as $employee) {
      $this->assertEmployee($employee);
    }
    $rows = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        return self::fail('Formato incorrecto en los eventos.');
      }
      foreach (['titulo', 'fecha_inicio', 'fecha_fin', 'id_categoria'] as $field) {
        if (empty($event[$field])) {
          return self::fail("El campo '{$field}' es obligatorio en los eventos.");
        }
      }
      [$start, $end] = self::dateRange((string) $event['fecha_inicio'], (string) $event['fecha_fin']);
      $this->assertCategory((int) $event['id_categoria']);
      foreach ($employees as $employee) {
        $rows[] = [$event + ['creado_por' => $input['creado_por'] ?? null, 'meta' => $input['meta'] ?? null], $employee, $start, $end];
      }
    }
    foreach ($employees as $employee) {
      $blocked = $this->blockResponse($employee, $input);
      if ($blocked !== null) {
        return $blocked;
      }
    }
    $byEmployee = [];
    foreach ($rows as [$event, $employee, $start, $end]) {
      $data = $this->eventData($event, $employee, $start, $end);
      $id = $this->store->insertEvent($data);
      $byEmployee[$employee][] = ['id' => $id, 'notificar_funcionarios' => $event['notificar_funcionarios'] ?? ($input['notificar_funcionarios'] ?? [])] + $data;
    }
    foreach ($byEmployee as $employee => $list) {
      $this->mailer->bulkEvents((string) $employee, $list);
    }
    return self::ok('Se registraron ' . count($rows) . ' eventos exitosamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createItem(array $input): array
  {
    if (self::itemType((string) ($input['tipo_item'] ?? ($input['tipo'] ?? 'evento'))) === 'recordatorio') {
      return $this->createReminder($input);
    }
    foreach (['titulo', 'fecha_inicio', 'id_empleado', 'id_categoria'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio para eventos.");
      }
    }
    $start = self::parseDate((string) $input['fecha_inicio']);
    if ($start === null) {
      return self::fail('Fecha inválida.');
    }
    $end = !empty($input['fecha_fin']) ? self::parseDate((string) $input['fecha_fin']) : null;
    if ($end === null || strtotime($end) <= strtotime($start)) {
      $end = self::plusMinutes($start, (int) ($input['duracion_minutos'] ?? 30));
    }
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $this->assertCategory((int) $input['id_categoria']);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $data = $this->eventData($input, $employee, $start, $end);
    $id = $this->store->insertEvent($data);
    $this->mailer->newEvent($data + ['notificar_funcionarios' => $input['notificar_funcionarios'] ?? []], $id);
    return self::ok('Evento creado.', ['item' => $this->store->event($id), 'recordatorio' => null]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createReminder(array $input): array
  {
    foreach (['titulo', 'id_empleado'] as $field) {
      if (empty($input[$field])) {
        return self::fail("El campo '{$field}' es obligatorio para recordatorios.");
      }
    }
    $at = self::parseDate((string) (($input['recordatorio_at'] ?? '') ?: ($input['fecha_inicio'] ?? '')));
    if ($at === null) {
      return self::fail('La fecha del recordatorio es obligatoria.');
    }
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $categoryId = (int) ($input['id_categoria'] ?? 0);
    if ($categoryId > 0) {
      $this->assertCategory($categoryId);
    }
    $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
    unset($meta['google_calendar_requested']);
    $meta['aviso_minutos_antes'] = self::noticeMinutes($input['aviso_minutos_antes'] ?? ($meta['aviso_minutos_antes'] ?? 30));
    $id = $this->store->insertReminder([
      'titulo' => (string) $input['titulo'],
      'mensaje' => (string) (($input['mensaje'] ?? '') ?: ($input['descripcion'] ?? '')),
      'recordatorio_at' => $at,
      'recordatorio_canal' => (string) ($input['recordatorio_canal'] ?? 'email'),
      'id_empleado' => $employee,
      'creado_por' => $this->creator($input),
      'id_evento' => (int) ($input['id_evento'] ?? 0),
      'id_ticket' => (int) ($input['id_ticket'] ?? 0),
      'id_categoria' => $categoryId,
      'origen_app' => $input['origen_app'] ?? CalendarStore::ORIGIN,
      'external_ref' => $input['external_ref'] ?? null,
      'meta' => $meta,
    ]);
    $reminder = (array) $this->store->reminder($id);
    $queueId = $this->mailer->scheduleReminder($reminder);
    if ($queueId !== null) {
      $this->store->setReminderQueue($id, $queueId, 'programado');
    }
    $reminder = (array) $this->store->reminder($id);
    $reminder['aviso_programado'] = $queueId !== null;
    return self::ok('Recordatorio creado.', $reminder);
  }

  // ── Modificar ──────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateEvent(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    $event = $this->existingEvent($id);
    $title = trim((string) ($input['titulo'] ?? ''));
    $category = (int) ($input['id_categoria'] ?? 0);
    if ($title === '' || $category <= 0) {
      return self::fail('El título y la categoría son obligatorios.');
    }
    if ($category !== (int) $event['id_categoria']) {
      $this->assertCategory($category);
    }
    $this->store->updateEvent($id, $title, (string) ($input['descripcion'] ?? ''), trim((string) ($input['ubicacion'] ?? '')), $category);
    return self::ok('Evento actualizado correctamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function moveEvent(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    $observation = trim((string) ($input['observacion'] ?? ''));
    if ($id <= 0 || empty($input['fecha_inicio']) || empty($input['fecha_fin']) || $observation === '') {
      return self::fail('Datos incompletos para trasladar el evento.');
    }
    [$start, $end] = self::dateRange((string) $input['fecha_inicio'], (string) $input['fecha_fin']);
    $this->existingEvent($id);
    $this->store->rescheduleEvent($id, $start, $end, $observation);
    $event = (array) $this->store->event($id);
    $this->mailer->eventMoved($event, $id);
    $ticket = (int) ($event['id_ticket'] ?? 0);
    if ($ticket > 0) {
      try {
        $this->store->touchTicket($ticket);
        if (strtolower(trim((string) ($input['es_cita'] ?? 'no'))) === 'si') {
          $this->store->addTicketHistory($ticket, (string) $event['id_empleado'], $id, $observation, $this->me());
          $this->mailer->requesterAppointment($event, true);
        }
      } catch (\Throwable $exception) {
        error_log('[calendar] caso del traslado ' . $id . ': ' . $exception->getMessage());
      }
    }
    return self::ok('Evento trasladado correctamente');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function completeEvent(array $input): array
  {
    $id = (int) ($input['id_evento'] ?? 0);
    $observation = trim((string) ($input['observacion'] ?? ''));
    if ($id <= 0 || $observation === '') {
      return self::fail('El evento y la observación son obligatorios.');
    }
    $this->existingEvent($id);
    if ($this->store->requiresCommercialReport($id)) {
      return self::fail('Debes completar el reporte comercial antes de finalizar el evento. Ciérralo desde el panel comercial.', ['requiere_reporte' => true]);
    }
    $this->store->completeEvent($id, $observation);
    $this->mailer->eventCompleted((array) $this->store->event($id), $id);
    return self::ok('Evento marcado como realizado');
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateReminder(array $input): array
  {
    $this->store->finalizeExpiredReminders();
    $id = (int) ($input['id_recordatorio'] ?? ($input['id'] ?? 0));
    $current = $this->existingReminder($id);
    if (!in_array($current['estado'], ['pendiente', 'programado'], true)) {
      return self::fail('Solo se pueden editar recordatorios pendientes.');
    }
    $title = trim((string) ($input['titulo'] ?? ''));
    $at = self::parseDate((string) ($input['recordatorio_at'] ?? ''));
    if ($title === '' || $at === null) {
      return self::fail('El título y la fecha del recordatorio son obligatorios.');
    }
    $meta = array_merge(is_array($current['meta'] ?? null) ? $current['meta'] : [], is_array($input['meta'] ?? null) ? $input['meta'] : []);
    unset($meta['google_calendar_requested']);
    $meta['aviso_minutos_antes'] = self::noticeMinutes($input['aviso_minutos_antes'] ?? ($meta['aviso_minutos_antes'] ?? 0));
    $this->store->updateReminder($id, ['titulo' => $title, 'mensaje' => (string) (($input['descripcion'] ?? '') ?: ($input['mensaje'] ?? '')), 'recordatorio_at' => $at, 'meta' => $meta]);
    $reminder = (array) $this->store->reminder($id);
    $queueId = $this->mailer->scheduleReminder($reminder);
    $this->store->setReminderQueue($id, $queueId, $queueId !== null ? 'programado' : 'pendiente');
    $reminder = (array) $this->store->reminder($id);
    return self::ok('Recordatorio actualizado.', ['item' => $reminder, 'aviso_programado' => $queueId !== null]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateReminderStatus(array $input): array
  {
    $this->store->finalizeExpiredReminders();
    $id = (int) ($input['id_recordatorio'] ?? ($input['id'] ?? 0));
    $status = strtolower(trim((string) ($input['estado'] ?? '')));
    if ($id <= 0) {
      return self::fail('El recordatorio es obligatorio.');
    }
    if (!in_array($status, ['enviado', 'cancelado'], true)) {
      return self::fail('Estado de recordatorio no válido.');
    }
    $this->existingReminder($id);
    $this->store->setReminderStatus($id, $status);
    $reminder = (array) $this->store->reminder($id);
    $this->mailer->cancelReminder($reminder, $status === 'enviado' ? 'Cancelado porque el recordatorio fue marcado como enviado manualmente.' : 'Cancelado manualmente desde calendario.');
    return self::ok($status === 'enviado' ? 'Recordatorio marcado como enviado.' : 'Recordatorio cancelado.', $reminder);
  }

  // ── Apoyo ──────────────────────────────────────────

  /** Quien tiene la sesión; vacío en procesos del servidor. */
  private function me(): string
  {
    return $this->me ??= $this->store->currentEmployeeId();
  }

  /**
   * `creado_por` es el `id_empleado` real de quien tiene la sesión; sin sesión (procesos del servidor) se usa el que
   * envía el proceso.
   * @param array<string,mixed> $input
   */
  private function creator(array $input): string
  {
    $me = $this->me();
    return $me !== '' ? $me : trim((string) ($input['creado_por'] ?? ''));
  }

  private function assertEmployee(string $employeeId): string
  {
    $employeeId = trim($employeeId);
    if ($employeeId === '' || $this->store->employee($employeeId) === null) {
      throw new \InvalidArgumentException('El funcionario seleccionado no existe.');
    }
    return $employeeId;
  }

  private function assertCategory(int $id): void
  {
    if ($this->store->category($id) === null) {
      throw new \InvalidArgumentException('La categoría seleccionada no existe.');
    }
  }

  /** @return array<string,mixed> */
  private function existingEvent(int $id): array
  {
    $event = $this->store->event($id);
    if ($event === null) {
      throw new \InvalidArgumentException('Evento no encontrado.');
    }
    return $event;
  }

  /** @return array<string,mixed> */
  private function existingReminder(int $id): array
  {
    $reminder = $this->store->reminder($id);
    if ($reminder === null) {
      throw new \InvalidArgumentException('Recordatorio no encontrado.');
    }
    return $reminder;
  }

  /** @param array<string,mixed> $input @return array<string,mixed>|null */
  private function blockResponse(string $employee, array $input): ?array
  {
    $block = $this->store->blockStatus($employee);
    if (!$block['bloqueado']) {
      return null;
    }
    $this->store->logBlock($employee, $block['cantidad']);
    $value = $input['permitir_vencidos'] ?? '';
    if ($value === true || in_array(strtolower(trim((string) $value)), ['1', 'si', 'true', 'yes'], true)) {
      return null;
    }
    return self::fail('Tienes ' . $block['cantidad'] . ' evento/s vencidos sin realizar. Finalízalos antes de crear otro.', ['bloqueado_vencidos' => true, 'cantidad' => $block['cantidad']]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function eventData(array $input, string $employee, string $start, string $end): array
  {
    $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
    unset($meta['google_calendar_requested']);
    return [
      'titulo' => (string) $input['titulo'],
      'descripcion' => (string) ($input['descripcion'] ?? ''),
      'ubicacion' => $input['ubicacion'] ?? null,
      'fecha_inicio' => $start,
      'fecha_fin' => $end,
      'id_empleado' => $employee,
      'creado_por' => $this->creator($input),
      'id_categoria' => (int) $input['id_categoria'],
      'id_ticket' => (int) ($input['id_ticket'] ?? 0),
      'origen_app' => $input['origen_app'] ?? CalendarStore::ORIGIN,
      'external_ref' => $input['external_ref'] ?? null,
      'meta' => $meta,
    ];
  }

  /**
   * Actualiza el caso vinculado. Si falla, el evento ya existe: se registra el error y no se responde como fallo para
   * que nadie lo vuelva a crear.
   * @param array<string,mixed> $input
   */
  private function linkTicket(int $ticket, int $eventId, string $employee, array $input, string $start, string $end): void
  {
    try {
      $this->store->touchTicketForEvent($ticket, (string) ($input['estado_comercial'] ?? ''), (string) ($input['estado_administrativo'] ?? ''));
      if (strtolower(trim((string) ($input['es_cita'] ?? ''))) === 'si') {
        $this->store->addTicketHistory($ticket, $employee, $eventId, trim((string) ($input['descripcion'] ?? '')) ?: 'Cita agendada: ' . (string) $input['titulo'], $this->me());
        $this->mailer->requesterAppointment(['id_ticket' => $ticket, 'id_empleado' => $employee, 'titulo' => (string) $input['titulo'], 'fecha_inicio' => $start, 'fecha_fin' => $end, 'ubicacion' => $input['ubicacion'] ?? '', 'id_categoria' => (int) $input['id_categoria']], false);
      }
    } catch (\Throwable $exception) {
      error_log('[calendar] caso del evento ' . $eventId . ': ' . $exception->getMessage());
    }
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private static function filters(array $input): array
  {
    $filters = [];
    foreach (['id_empleado', 'id_categoria', 'id_ticket', 'estado', 'fue_trasladado', 'origen_app', 'external_ref', 'fecha_inicio', 'fecha_fin', 'buscar'] as $key) {
      if (isset($input[$key]) && !is_array($input[$key])) {
        $filters[$key] = trim((string) $input[$key]);
      }
    }
    return $filters;
  }

  private static function itemType(string $type): string
  {
    $type = strtolower(trim($type));
    if (in_array($type, ['tarea', 'tareas', 'task', 'tasks'], true)) {
      throw new \InvalidArgumentException('Las tareas fueron retiradas del calendario. Solo se permiten eventos y recordatorios.');
    }
    return $type === 'recordatorio' ? 'recordatorio' : 'evento';
  }

  /** @param mixed $value */
  private static function noticeMinutes($value): int
  {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 1440) {
      throw new \InvalidArgumentException('El aviso debe programarse entre 0 y 1440 minutos antes.');
    }
    return (int) $value;
  }

  private static function parseDate(string $value): ?string
  {
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') {
      return null;
    }
    $zone = new \DateTimeZone(CalendarStore::TIMEZONE);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
      if ($date !== false && $date->format($format) === $value) {
        return $date->format('Y-m-d H:i:s');
      }
    }
    return null;
  }

  /** @return array{0:string,1:string} */
  private static function dateRange(string $start, string $end): array
  {
    $from = self::parseDate($start);
    $to = self::parseDate($end);
    if ($from === null || $to === null) {
      throw new \InvalidArgumentException('Fecha inválida.');
    }
    if (strtotime($to) <= strtotime($from)) {
      throw new \InvalidArgumentException('La fecha de finalización debe ser posterior a la de inicio.');
    }
    return [$from, $to];
  }

  private static function plusMinutes(string $date, int $minutes): string
  {
    return (new \DateTimeImmutable($date, new \DateTimeZone(CalendarStore::TIMEZONE)))->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
  }

  /** @param mixed $data @return array{success:bool,message:string,data:mixed} */
  private static function ok(string $message, $data = []): array
  {
    return ['success' => true, 'message' => $message, 'data' => $data];
  }

  /** @param mixed $data @return array{success:bool,message:string,data:mixed,status:int} */
  private static function fail(string $message, $data = [], int $status = 200): array
  {
    return ['success' => false, 'message' => $message, 'data' => $data, 'status' => $status];
  }
}
