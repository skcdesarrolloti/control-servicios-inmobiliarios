<?php

declare(strict_types=1);

namespace SCM\Modules\Calendar;

use SCM\Core\Database;

/**
 * API propia del calendario del panel (reemplaza calendario-actividades). Atiende las acciones que usa el panel con la
 * misma respuesta {success,message,data} para que el motor JS del calendario no cambie. La sesión y el nonce se exigen
 * en public/calendar-api.php; los procesos del servidor (p. ej. Revisión crítica) la llaman directamente sin sesión.
 *
 * Permisos con sesión ($team no nulo):
 * - Con "Calendario equipo" o cargo administrador ($team = true): leer y modificar todo.
 * - Sin él: lo propio (asignado a la persona o creado por ella). De otros funcionarios solo se entrega ocupado/libre,
 *   y solo se agenda a otro funcionario desde su caso (cita de un caso abierto).
 * Sin sesión ($team = null, procesos del servidor): acceso completo y sin reglas de horario.
 * No hay sincronización con Google Calendar.
 */
final class CalendarApi
{
  private const MAX_BULK_EVENTS = 400;
  /** Margen para guardar algo que acaba de terminar (la pantalla valida contra la hora del navegador). */
  private const PAST_GRACE_MINUTES = 10;
  /** Franja de agenda del panel; la pantalla aplica la misma. */
  private const DAY_START = '08:00';
  private const DAY_END = '21:00';
  private const CLOSED_TICKET_STATES = ['cerrado', 'cerrada', 'resuelto', 'resuelta', 'finalizado', 'finalizada', 'anulado', 'anulada'];

  private CalendarStore $store;
  private CalendarMailer $mailer;
  private ?bool $team;
  private ?string $me = null;

  /** @param bool|null $team null = proceso del servidor (sin sesión); true/false = sesión con o sin "Calendario equipo". */
  public function __construct(Database $db, ?CalendarMailer $mailer = null, ?bool $team = null)
  {
    $this->store = new CalendarStore($db);
    $this->mailer = $mailer ?? new CalendarMailer($this->store);
    $this->team = $team;
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
      'crear_recordatorios' => 'createReminders',
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
    } catch (\DomainException $exception) {
      return self::fail($exception->getMessage(), [], 403);
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
    [$filters, $masked] = $this->readScope($input);
    if ($filters === null) {
      return self::ok('Items de calendario', []);
    }
    $items = [];
    if (in_array('evento', $types, true)) {
      $items = $this->store->events($filters)['rows'];
    }
    if (in_array('recordatorio', $types, true) && !$masked) {
      $items = array_merge($items, $this->store->reminders($filters));
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['fecha_inicio'] ?? ''), (string) ($b['fecha_inicio'] ?? '')));
    $limit = isset($input['limite']) ? max(1, min(CalendarStore::MAX_ROWS, (int) $input['limite'])) : CalendarStore::MAX_ROWS;
    $items = array_slice($items, max(0, (int) ($input['offset'] ?? 0)), $limit);
    return self::ok('Items de calendario', $masked ? array_map([self::class, 'mask'], $items) : $items);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function filterEventsAdmin(array $input): array
  {
    [$filters, $masked] = $this->readScope($input);
    if ($filters === null) {
      return self::ok('Eventos filtrados', ['data' => [], 'total' => 0]);
    }
    $page = max(1, (int) ($input['pagina'] ?? 1));
    $limit = max(1, min(CalendarStore::MAX_ROWS, (int) ($input['limite'] ?? 50)));
    $result = $this->store->events($filters, $limit, ($page - 1) * $limit);
    return self::ok('Eventos filtrados', ['data' => $masked ? array_map([self::class, 'mask'], $result['rows']) : $result['rows'], 'total' => $result['total']]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function listOverdue(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de empleado requerido.');
    }
    return self::ok('Eventos pendientes vencidos', $this->canSeeEmployee($employee) ? $this->store->overdueEvents($employee) : []);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function employeeTickets(array $input): array
  {
    $employee = trim((string) ($input['id_empleado'] ?? ''));
    if ($employee === '') {
      return self::fail('ID de funcionario requerido');
    }
    return self::ok('Tickets encontrados', $this->canSeeEmployee($employee) ? $this->store->openTicketsForEmployee($employee) : []);
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
    $this->assertSchedule($start, $end, true);
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $ticket = (int) ($input['id_ticket'] ?? 0);
    if ($ticket > 0) {
      $this->assertOpenTicket($ticket);
    }
    $this->assertCanAssign([$employee], $ticket);
    $this->assertCategory((int) $input['id_categoria']);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $data = $this->eventData($input, $employee, $start, $end);
    $id = $this->store->insertEvent($data);
    $this->mailer->newEvent($data + ['notificar_funcionarios' => $input['notificar_funcionarios'] ?? []], $id);
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
    $this->assertCanAssign($employees, 0);
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
      $this->assertSchedule($start, $end, true);
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
    $pdo = $this->store->database()->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($rows as [$event, $employee, $start, $end]) {
        $data = $this->eventData($event, $employee, $start, $end);
        $data['id_ticket'] = 0;
        $id = $this->store->insertEvent($data);
        $byEmployee[$employee][] = ['id' => $id, 'notificar_funcionarios' => $event['notificar_funcionarios'] ?? ($input['notificar_funcionarios'] ?? [])] + $data;
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
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
    $this->assertSchedule($start, $end, true);
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $this->assertCanAssign([$employee], 0);
    $this->assertCategory((int) $input['id_categoria']);
    $blocked = $this->blockResponse($employee, $input);
    if ($blocked !== null) {
      return $blocked;
    }
    $data = $this->eventData($input, $employee, $start, $end);
    $data['id_ticket'] = 0;
    $id = $this->store->insertEvent($data);
    $this->mailer->newEvent($data + ['notificar_funcionarios' => $input['notificar_funcionarios'] ?? []], $id);
    return self::ok('Evento creado.', ['item' => $this->store->event($id), 'recordatorio' => null]);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function createReminder(array $input): array
  {
    $data = $this->reminderData($input);
    $id = $this->store->insertReminder($data);
    return self::ok('Recordatorio creado.', $this->scheduleReminder($id));
  }

  /**
   * Varios recordatorios de una vez (varias fechas o funcionarios): se validan todos y se guardan juntos, así un error
   * no deja la mitad creada ni se duplican al reintentar.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  private function createReminders(array $input): array
  {
    $items = is_array($input['recordatorios'] ?? null) ? array_values($input['recordatorios']) : [];
    if ($items === []) {
      return self::fail('Indica los recordatorios a crear.');
    }
    if (count($items) > self::MAX_BULK_EVENTS) {
      return self::fail('Son demasiadas fechas a la vez. El máximo es ' . self::MAX_BULK_EVENTS . ' recordatorios.');
    }
    $rows = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        return self::fail('Formato incorrecto en los recordatorios.');
      }
      $rows[] = $this->reminderData($item);
    }
    $ids = [];
    $pdo = $this->store->database()->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($rows as $row) {
        $ids[] = $this->store->insertReminder($row);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    }
    $created = array_map([$this, 'scheduleReminder'], $ids);
    $scheduled = count(array_filter($created, static fn(array $row): bool => !empty($row['aviso_programado'])));
    return self::ok('Se crearon ' . count($ids) . ' recordatorios.', ['creados' => count($ids), 'aviso_programado' => $scheduled === count($ids), 'items' => $created]);
  }

  // ── Modificar ──────────────────────────────────────

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function updateEvent(array $input): array
  {
    $id = (int) ($input['id'] ?? 0);
    $event = $this->editableEvent($id);
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
    $current = $this->editableEvent($id);
    // Trasladar lo reabría (estado 'No') y luego podía bloquear al funcionario como vencido.
    if (strcasecmp(trim((string) ($current['estado'] ?? '')), 'Si') === 0) {
      return self::fail('Este evento ya fue realizado; no se puede trasladar. Crea uno nuevo si necesitas otra cita.');
    }
    $this->assertSchedule($start, $end, true);
    $this->store->rescheduleEvent($id, $start, $end, $observation);
    $event = (array) $this->store->event($id);
    $this->mailer->eventMoved($event, $id);
    $ticket = (int) ($event['id_ticket'] ?? 0);
    if ($ticket > 0) {
      try {
        $this->store->touchTicket($ticket);
        if (strtolower(trim((string) ($input['es_cita'] ?? 'no'))) === 'si') {
          // Como en calendario-actividades: el historial del caso guarda el mensaje para el caso/cliente, o el motivo si no hay.
          $this->store->addTicketHistory($ticket, (string) $event['id_empleado'], $id, trim((string) ($input['descripcion'] ?? '')) ?: $observation, $this->me());
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
    $this->editableEvent($id);
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
    $current = $this->editableReminder($id);
    if (!in_array($current['estado'], ['pendiente', 'programado'], true)) {
      return self::fail('Solo se pueden editar recordatorios pendientes.');
    }
    $title = trim((string) ($input['titulo'] ?? ''));
    $at = self::parseDate((string) ($input['recordatorio_at'] ?? ''));
    if ($title === '' || $at === null) {
      return self::fail('El título y la fecha del recordatorio son obligatorios.');
    }
    $this->assertSchedule($at, $at, false);
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
    $this->editableReminder($id);
    $this->store->setReminderStatus($id, $status);
    $reminder = (array) $this->store->reminder($id);
    $this->mailer->cancelReminder($reminder, $status === 'enviado' ? 'Cancelado porque el recordatorio fue marcado como enviado manualmente.' : 'Cancelado manualmente desde calendario.');
    return self::ok($status === 'enviado' ? 'Recordatorio marcado como enviado.' : 'Recordatorio cancelado.', $reminder);
  }

  // ── Permisos ───────────────────────────────────────

  /** Proceso del servidor (sin sesión): acceso completo. */
  private function isSystem(): bool
  {
    return $this->team === null;
  }

  private function canTeam(): bool
  {
    return $this->team !== false;
  }

  /** Quien tiene la sesión; vacío en procesos del servidor. */
  private function me(): string
  {
    return $this->me ??= $this->store->currentEmployeeId();
  }

  private function canSeeEmployee(string $employee): bool
  {
    return $this->canTeam() || ($this->me() !== '' && $employee === $this->me());
  }

  /**
   * Qué agenda puede leer la persona: con el equipo, cualquiera; sin él, la suya completa y la de otro solo como
   * ocupado/libre. Sin funcionario en el filtro y sin equipo, la suya.
   * @param array<string,mixed> $input
   * @return array{0:array<string,string>|null,1:bool} [filtros o null si no puede ver nada, enmascarar]
   */
  private function readScope(array $input): array
  {
    $filters = self::filters($input);
    if ($this->canTeam()) {
      return [$filters, false];
    }
    $me = $this->me();
    $requested = $filters['id_empleado'] ?? '';
    if ($requested === '' || $requested === $me) {
      if ($me === '') {
        return [null, false];
      }
      $filters['id_empleado'] = $me;
      return [$filters, false];
    }
    // De otro funcionario solo fechas: nada de búsqueda, casos ni categorías que revelen el contenido.
    return [array_intersect_key($filters, array_flip(['id_empleado', 'fecha_inicio', 'fecha_fin', 'estado'])), true];
  }

  /**
   * A sí mismo siempre. A otros, con "Calendario equipo", o una sola persona desde su caso (cita del caso).
   * @param array<int,string> $employees
   */
  private function assertCanAssign(array $employees, int $ticket): void
  {
    if ($this->canTeam()) {
      return;
    }
    foreach ($employees as $employee) {
      if ($employee === $this->me()) {
        continue;
      }
      if (!($ticket > 0 && count($employees) === 1)) {
        throw new \DomainException('No tienes permiso para agendar en el calendario de otro funcionario.');
      }
    }
  }

  /** @param array<string,mixed> $row */
  private function canChange(array $row): bool
  {
    if ($this->canTeam()) {
      return true;
    }
    $me = $this->me();
    return $me !== '' && ((string) ($row['id_empleado'] ?? '') === $me || (string) ($row['creado_por'] ?? '') === $me);
  }

  /** @return array<string,mixed> */
  private function editableEvent(int $id): array
  {
    $event = $this->store->event($id);
    if ($event === null) {
      throw new \InvalidArgumentException('Evento no encontrado.');
    }
    if (!$this->canChange($event)) {
      throw new \DomainException('No tienes permiso para modificar este evento.');
    }
    return $event;
  }

  /** @return array<string,mixed> */
  private function editableReminder(int $id): array
  {
    $reminder = $this->store->reminder($id);
    if ($reminder === null) {
      throw new \InvalidArgumentException('Recordatorio no encontrado.');
    }
    if (!$this->canChange($reminder)) {
      throw new \DomainException('No tienes permiso para modificar este recordatorio.');
    }
    return $reminder;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private static function mask(array $row): array
  {
    return [
      'id' => $row['id'] ?? '',
      'tipo_item' => $row['tipo_item'] ?? 'evento',
      'titulo' => 'Ocupado',
      'categoria' => 'Ocupado',
      'fecha_inicio' => $row['fecha_inicio'] ?? '',
      'fecha_fin' => $row['fecha_fin'] ?? '',
      'id_empleado' => $row['id_empleado'] ?? '',
      'funcionario' => $row['funcionario'] ?? '',
      'estado' => $row['estado'] ?? '',
      'color' => '#94a3b8',
      'privado' => true,
    ];
  }

  // ── Reglas ─────────────────────────────────────────

  /**
   * Igual que la pantalla: con sesión no se agenda en el pasado y los eventos van de 8:00 a. m. a 9:00 p. m. Los
   * procesos del servidor (vencimientos a cualquier hora) no tienen estas reglas.
   */
  private function assertSchedule(string $start, string $end, bool $isEvent): void
  {
    if ($this->isSystem()) {
      return;
    }
    $limit = (new \DateTimeImmutable($this->store->now(), new \DateTimeZone(CalendarStore::TIMEZONE)))
      ->modify('-' . self::PAST_GRACE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    if ($end < $limit) {
      throw new \InvalidArgumentException('No puedes agendar en una fecha u hora que ya pasó.');
    }
    if ($isEvent && (substr($start, 0, 10) !== substr($end, 0, 10) || substr($start, 11, 5) < self::DAY_START || substr($end, 11, 5) > self::DAY_END)) {
      throw new \InvalidArgumentException('El evento debe ser el mismo día, entre las 8:00 a. m. y las 9:00 p. m.');
    }
  }

  private function assertOpenTicket(int $ticket): void
  {
    $row = $this->store->ticket($ticket);
    if ($row === null) {
      throw new \InvalidArgumentException('El caso vinculado no existe.');
    }
    foreach (['estado', 'estado_administrativo'] as $field) {
      if (in_array(mb_strtolower(trim((string) ($row[$field] ?? ''))), self::CLOSED_TICKET_STATES, true)) {
        throw new \InvalidArgumentException('El caso #' . $ticket . ' está cerrado; no se le pueden agendar citas.');
      }
    }
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

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function reminderData(array $input): array
  {
    foreach (['titulo', 'id_empleado'] as $field) {
      if (empty($input[$field])) {
        throw new \InvalidArgumentException("El campo '{$field}' es obligatorio para recordatorios.");
      }
    }
    $at = self::parseDate((string) (($input['recordatorio_at'] ?? '') ?: ($input['fecha_inicio'] ?? '')));
    if ($at === null) {
      throw new \InvalidArgumentException('La fecha del recordatorio es obligatoria.');
    }
    $this->assertSchedule($at, $at, false);
    $employee = $this->assertEmployee((string) $input['id_empleado']);
    $this->assertCanAssign([$employee], 0);
    $categoryId = (int) ($input['id_categoria'] ?? 0);
    if ($categoryId > 0) {
      $this->assertCategory($categoryId);
    }
    $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
    unset($meta['google_calendar_requested']);
    $meta['aviso_minutos_antes'] = self::noticeMinutes($input['aviso_minutos_antes'] ?? ($meta['aviso_minutos_antes'] ?? 30));
    return [
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
    ];
  }

  /** Programa el aviso del recordatorio ya guardado y lo devuelve. @return array<string,mixed> */
  private function scheduleReminder(int $id): array
  {
    $queueId = $this->mailer->scheduleReminder((array) $this->store->reminder($id));
    if ($queueId !== null) {
      $this->store->setReminderQueue($id, $queueId, 'programado');
    }
    $reminder = (array) $this->store->reminder($id);
    $reminder['aviso_programado'] = $queueId !== null;
    return $reminder;
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

  /** @param array<string,mixed> $input @return array<string,mixed>|null */
  private function blockResponse(string $employee, array $input): ?array
  {
    $block = $this->store->blockStatus($employee);
    if (!$block['bloqueado']) {
      return null;
    }
    $value = $input['permitir_vencidos'] ?? '';
    $override = $value === true || in_array(strtolower(trim((string) $value)), ['1', 'si', 'true', 'yes'], true);
    if ($override) {
      return null;
    }
    // Se registra una sola vez, en el intento bloqueado; el "crear de todas formas" que sigue no lo duplica.
    $this->store->logBlock($employee, $block['cantidad']);
    // El bloqueo es del funcionario asignado (no de quien crea) y solo por eventos que terminaron hace más de 2 días:
    // se nombran para que se sepa cuál cerrar o trasladar.
    $overdue = $this->store->overdueEvents($employee);
    $list = array_map(static function (array $row): string {
      $ts = strtotime((string) ($row['fecha_inicio'] ?? ''));
      return '«' . trim((string) ($row['titulo'] ?? 'Evento')) . '» #' . (int) ($row['id'] ?? 0) . ' del ' . ($ts ? date('d/m/Y h:i A', $ts) : (string) ($row['fecha_inicio'] ?? ''));
    }, array_slice($overdue, 0, 3));
    $name = trim((string) ($this->store->employee($employee)['nombre'] ?? ''));
    $who = $employee === $this->me() || $name === '' ? 'Tienes' : $name . ' tiene';
    $message = $who . ' ' . $block['cantidad'] . ' evento/s vencidos sin realizar (terminaron hace más de 2 días): ' . implode('; ', $list)
      . ($block['cantidad'] > count($list) ? ' y otros' : '') . '. Márcalos como realizados o trasládalos antes de crear otro.';
    return self::fail($message, [
      'bloqueado_vencidos' => true,
      'cantidad' => $block['cantidad'],
      'id_empleado' => $employee,
      'eventos' => array_map(static fn(array $row): array => ['id' => $row['id'] ?? null, 'titulo' => $row['titulo'] ?? '', 'fecha_inicio' => $row['fecha_inicio'] ?? '', 'fecha_fin' => $row['fecha_fin'] ?? ''], $overdue),
    ]);
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

  /** @param array<string,mixed> $input @return array<string,string> */
  private static function filters(array $input): array
  {
    $filters = [];
    foreach (['id_empleado', 'id_categoria', 'id_ticket', 'estado', 'fue_trasladado', 'origen_app', 'external_ref', 'fecha_inicio', 'fecha_fin', 'buscar'] as $key) {
      if (isset($input[$key]) && !is_array($input[$key]) && trim((string) $input[$key]) !== '') {
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
