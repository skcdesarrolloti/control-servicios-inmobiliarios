<?php

declare(strict_types=1);

namespace SCM\Modules\Calendar;

use SCM\Core\Auth;
use SCM\Core\Database;

/**
 * Datos del calendario de actividades. Lógica traída de calendario-actividades/models (Evento, Recordatorio, Categoria,
 * Ticket e HistorialTicket) sobre las mismas tablas, que también usa el panel comercial: no se duplican agendas.
 */
final class CalendarStore
{
  public const EVENTS = 'calendario_actividades';
  public const CATEGORIES = 'categorias_calendario';
  public const REMINDERS = 'calendario_recordatorios';
  public const BLOCKS = 'registro_bloqueos';
  public const ORIGIN = 'control-servicios-inmobiliarios';
  public const TIMEZONE = 'America/Bogota';

  /** Días que un evento puede seguir sin cerrar antes de bloquear la creación de otros. */
  private const BLOCK_GRACE_DAYS = 2;
  /** Tope de filas de una consulta: el mes de todo el equipo no debe quedar cortado. */
  public const MAX_ROWS = 5000;
  /** Casos que se pueden vincular a un evento desde el calendario. */
  private const OPEN_TICKET_STATES = ['Nuevo', 'En proceso', 'Postergado'];
  /** Categorías que siempre exigen reporte comercial (mismas de calendario-actividades). */
  private const COMMERCIAL_REPORT_CATEGORIES = [3, 26, 40];

  private Database $db;
  private string $employees;
  private string $tickets;
  private string $history;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->employees = $db->table('jet_cct_funcionarios');
    $this->tickets = $db->table('jet_cct_tickets');
    $this->history = $db->table('jet_cct_historial_del_ticket');
  }

  public function database(): Database
  {
    return $this->db;
  }

  // ── Categorías ─────────────────────────────────────

  /** @return array<int,array<string,mixed>> */
  public function categories(): array
  {
    return $this->db->getResults('SELECT `id`, `nombre`, `color`, `descripcion`, `roles_notificar` FROM `' . self::CATEGORIES . '` ORDER BY `nombre` ASC');
  }

  /** @return array<string,mixed>|null */
  public function category(int $id): ?array
  {
    return $id > 0 ? $this->db->getRow('SELECT `id`, `nombre`, `color`, `descripcion`, `roles_notificar` FROM `' . self::CATEGORIES . '` WHERE `id` = ?', [$id]) : null;
  }

  // ── Eventos ────────────────────────────────────────

  /**
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,total:int}
   */
  public function events(array $filters, int $limit = self::MAX_ROWS, int $offset = 0): array
  {
    $where = ['1=1'];
    $args = [];
    foreach (['id_empleado', 'id_categoria', 'id_ticket', 'estado', 'fue_trasladado', 'origen_app', 'external_ref'] as $column) {
      $value = $filters[$column] ?? null;
      if ($value !== null && !is_array($value) && trim((string) $value) !== '') {
        $where[] = "e.`{$column}` = ?";
        $args[] = trim((string) $value);
      }
    }
    if (!empty($filters['fecha_inicio']) && !empty($filters['fecha_fin'])) {
      // Por traslape: también entra un evento de varios días que empezó antes del rango.
      $where[] = 'e.`fecha_inicio` <= ? AND e.`fecha_fin` >= ?';
      $args[] = substr((string) $filters['fecha_fin'], 0, 10) . ' 23:59:59';
      $args[] = substr((string) $filters['fecha_inicio'], 0, 10) . ' 00:00:00';
    }
    $search = trim((string) ($filters['buscar'] ?? ''));
    if ($search !== '') {
      $where[] = '(e.`titulo` LIKE ? OR e.`descripcion` LIKE ? OR e.`ubicacion` LIKE ?)';
      $like = '%' . $this->db->escapeLike($search) . '%';
      array_push($args, $like, $like, $like);
    }
    $from = ' FROM `' . self::EVENTS . '` e
              JOIN `' . self::CATEGORIES . '` c ON e.`id_categoria` = c.`id`
              JOIN `' . $this->employees . '` f ON e.`id_empleado` = f.`id_empleado`
             WHERE ' . implode(' AND ', $where);
    $limit = max(1, min(self::MAX_ROWS, $limit));
    $rows = $this->db->getResults(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario' . $from
        . ' ORDER BY e.`fecha_inicio` ASC, e.`id` ASC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset),
      $args
    );
    return [
      'rows' => array_map([$this, 'mapEvent'], $rows),
      'total' => (int) $this->db->getVar('SELECT COUNT(*)' . $from, $args),
    ];
  }

  /** @return array<string,mixed>|null */
  public function event(int $id): ?array
  {
    if ($id <= 0) {
      return null;
    }
    $row = $this->db->getRow(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS nombre, f.`nombre` AS funcionario
         FROM `' . self::EVENTS . '` e
         JOIN `' . self::CATEGORIES . '` c ON e.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON e.`id_empleado` = f.`id_empleado`
        WHERE e.`id` = ?',
      [$id]
    );
    return $row === null ? null : $this->mapEvent($row);
  }

  /** @param array<string,mixed> $data */
  public function insertEvent(array $data): int
  {
    $this->db->insert(self::EVENTS, [
      'titulo' => (string) $data['titulo'],
      'descripcion' => (string) ($data['descripcion'] ?? ''),
      'ubicacion' => self::optional($data['ubicacion'] ?? null),
      'fecha_inicio' => (string) $data['fecha_inicio'],
      'fecha_fin' => (string) $data['fecha_fin'],
      'id_empleado' => (string) $data['id_empleado'],
      'creado_por' => self::optional($data['creado_por'] ?? null),
      'id_categoria' => (int) $data['id_categoria'],
      'id_ticket' => (int) ($data['id_ticket'] ?? 0) > 0 ? (int) $data['id_ticket'] : null,
      'origen_app' => self::optional($data['origen_app'] ?? self::ORIGIN),
      'external_ref' => self::optional($data['external_ref'] ?? null),
      'meta_json' => self::metaJson($data['meta'] ?? null),
    ]);
    return (int) $this->db->lastInsertId();
  }

  public function updateEvent(int $id, string $title, string $description, string $location, int $categoryId): void
  {
    $this->db->update(self::EVENTS, ['titulo' => $title, 'descripcion' => $description, 'ubicacion' => $location, 'id_categoria' => $categoryId], ['id' => $id]);
  }

  public function completeEvent(int $id, string $observation): void
  {
    $this->db->update(self::EVENTS, ['estado' => 'Si', 'observacion' => $observation], ['id' => $id]);
  }

  public function rescheduleEvent(int $id, string $start, string $end, string $observation): void
  {
    $this->db->update(self::EVENTS, ['fecha_inicio' => $start, 'fecha_fin' => $end, 'observacion' => $observation, 'estado' => 'No', 'fue_trasladado' => 'Si'], ['id' => $id]);
  }

  /**
   * Igual que calendario-actividades/models/ReporteComercial: el evento de un cargo habilitado en
   * `calendario_reportes_comerciales_cargos` y categoría comercial solo se cierra con su reporte (en el panel comercial).
   */
  public function requiresCommercialReport(int $eventId): bool
  {
    $event = $this->db->getRow(
      'SELECT e.`id_categoria`, c.`nombre` AS categoria, c.`roles_notificar`, f.`id_cargo`
         FROM `' . self::EVENTS . '` e
         JOIN `' . self::CATEGORIES . '` c ON c.`id` = e.`id_categoria`
         JOIN `' . $this->employees . '` f ON f.`id_empleado` = e.`id_empleado`
        WHERE e.`id` = ?',
      [$eventId]
    );
    if ($event === null) {
      return false;
    }
    $raw = $this->db->getVar(
      'SELECT `valor` FROM `' . $this->db->table('jet_cct_confi_sistema') . "` WHERE `funcion` = 'calendario_reportes_comerciales_cargos' AND `cct_status` = 'publish' ORDER BY `_ID` DESC LIMIT 1"
    );
    $cargos = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cargos) || !in_array((int) ($event['id_cargo'] ?? 0), array_map('intval', $cargos), true) || (int) ($event['id_cargo'] ?? 0) <= 0) {
      return false;
    }
    if (in_array((int) $event['id_categoria'], self::COMMERCIAL_REPORT_CATEGORIES, true)) {
      return true;
    }
    $text = (string) ($event['roles_notificar'] ?? '') . ' ' . (string) ($event['categoria'] ?? '');
    $text = strtolower((string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text));
    foreach (['comercial', 'promocion', 'captacion', 'avaluo', 'precaptador'] as $word) {
      if (strpos($text, $word) !== false) {
        return true;
      }
    }
    return false;
  }

  // ── Bloqueo por eventos vencidos ───────────────────

  /** @return array{bloqueado:bool,cantidad:int} */
  public function blockStatus(string $employeeId): array
  {
    $total = (int) $this->db->getVar(
      'SELECT COUNT(*) FROM `' . self::EVENTS . "` WHERE `id_empleado` = ? AND `estado` = 'No' AND `fecha_fin` < ?",
      [$employeeId, $this->blockLimit()]
    );
    return ['bloqueado' => $total > 0, 'cantidad' => $total];
  }

  public function logBlock(string $employeeId, int $pending): void
  {
    $this->db->insert(self::BLOCKS, ['id_empleado' => $employeeId, 'cantidad_pendientes' => $pending, 'fecha_registro' => $this->now()]);
  }

  /** @return array<int,array<string,mixed>> */
  public function overdueEvents(string $employeeId): array
  {
    return $this->db->getResults(
      'SELECT e.*, c.`nombre` AS categoria, c.`color`
         FROM `' . self::EVENTS . '` e
         LEFT JOIN `' . self::CATEGORIES . "` c ON e.`id_categoria` = c.`id`
        WHERE e.`id_empleado` = ? AND e.`estado` = 'No' AND e.`fecha_fin` < ?
        ORDER BY e.`fecha_inicio` ASC",
      [$employeeId, $this->blockLimit()]
    );
  }

  // ── Recordatorios ──────────────────────────────────

  /**
   * @param array<string,mixed> $filters
   * @return array<int,array<string,mixed>>
   */
  public function reminders(array $filters): array
  {
    $where = ['1=1'];
    $args = [];
    $employee = trim((string) ($filters['id_empleado'] ?? ''));
    if ($employee !== '') {
      $where[] = 'r.`id_empleado` = ?';
      $args[] = $employee;
    }
    $status = trim((string) ($filters['estado'] ?? ''));
    if ($status === 'No') {
      $where[] = "r.`estado` IN ('pendiente', 'programado')";
    } elseif ($status === 'Si') {
      $where[] = "r.`estado` IN ('finalizado', 'enviado')";
    } elseif ($status !== '') {
      $where[] = 'r.`estado` = ?';
      $args[] = self::reminderStatus($status);
    }
    foreach (['id_ticket', 'origen_app', 'external_ref'] as $column) {
      $value = $filters[$column] ?? null;
      if ($value !== null && !is_array($value) && trim((string) $value) !== '') {
        $where[] = "r.`{$column}` = ?";
        $args[] = trim((string) $value);
      }
    }
    if (!empty($filters['fecha_inicio']) && !empty($filters['fecha_fin'])) {
      $where[] = 'r.`recordatorio_at` BETWEEN ? AND ?';
      $args[] = substr((string) $filters['fecha_inicio'], 0, 10) . ' 00:00:00';
      $args[] = substr((string) $filters['fecha_fin'], 0, 10) . ' 23:59:59';
    }
    $rows = $this->db->getResults(
      'SELECT r.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario
         FROM `' . self::REMINDERS . '` r
         LEFT JOIN `' . self::CATEGORIES . '` c ON r.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON r.`id_empleado` = f.`id_empleado`
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY r.`recordatorio_at` ASC, r.`id` ASC LIMIT ' . self::MAX_ROWS,
      $args
    );
    return array_map([$this, 'mapReminder'], $rows);
  }

  /** @return array<string,mixed>|null */
  public function reminder(int $id): ?array
  {
    if ($id <= 0) {
      return null;
    }
    $row = $this->db->getRow(
      'SELECT r.*, c.`nombre` AS categoria, c.`color`, f.`nombre` AS funcionario
         FROM `' . self::REMINDERS . '` r
         LEFT JOIN `' . self::CATEGORIES . '` c ON r.`id_categoria` = c.`id`
         JOIN `' . $this->employees . '` f ON r.`id_empleado` = f.`id_empleado`
        WHERE r.`id` = ?',
      [$id]
    );
    return $row === null ? null : $this->mapReminder($row);
  }

  /** @param array<string,mixed> $data */
  public function insertReminder(array $data): int
  {
    $this->db->insert(self::REMINDERS, [
      'titulo' => trim((string) ($data['titulo'] ?? 'Recordatorio')),
      'mensaje' => (string) ($data['mensaje'] ?? ($data['descripcion'] ?? '')),
      'recordatorio_at' => (string) $data['recordatorio_at'],
      'canal' => self::reminderChannel((string) ($data['recordatorio_canal'] ?? ($data['canal'] ?? 'email'))),
      'estado' => 'pendiente',
      'id_empleado' => trim((string) $data['id_empleado']),
      'creado_por' => self::optional($data['creado_por'] ?? null),
      'id_evento' => (int) ($data['id_evento'] ?? 0) > 0 ? (int) $data['id_evento'] : null,
      'id_ticket' => (int) ($data['id_ticket'] ?? 0) > 0 ? (int) $data['id_ticket'] : null,
      'id_categoria' => (int) ($data['id_categoria'] ?? 0) > 0 ? (int) $data['id_categoria'] : null,
      'origen_app' => self::optional($data['origen_app'] ?? self::ORIGIN),
      'external_ref' => self::optional($data['external_ref'] ?? null),
      'meta_json' => self::metaJson($data['meta'] ?? null),
    ]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $data */
  public function updateReminder(int $id, array $data): void
  {
    $this->db->pdo()->prepare(
      'UPDATE `' . self::REMINDERS . "` SET `titulo` = ?, `mensaje` = ?, `recordatorio_at` = ?, `meta_json` = ?
        WHERE `id` = ? AND `estado` IN ('pendiente', 'programado')"
    )->execute([trim((string) $data['titulo']), (string) ($data['mensaje'] ?? ''), (string) $data['recordatorio_at'], self::metaJson($data['meta'] ?? []), $id]);
  }

  public function setReminderQueue(int $id, ?int $queueId, string $status): void
  {
    $this->db->update(self::REMINDERS, ['notification_queue_id' => $queueId ?: null, 'estado' => self::reminderStatus($status)], ['id' => $id]);
  }

  public function setReminderStatus(int $id, string $status): void
  {
    $this->db->update(self::REMINDERS, ['estado' => self::reminderStatus($status)], ['id' => $id]);
  }

  /** Pasa a finalizado lo que ya llegó a su hora. */
  public function finalizeExpiredReminders(): void
  {
    $this->db->pdo()->prepare(
      'UPDATE `' . self::REMINDERS . "` SET `estado` = 'finalizado' WHERE `estado` IN ('pendiente', 'programado') AND `recordatorio_at` <= ?"
    )->execute([$this->now()]);
  }

  // ── Casos vinculados ───────────────────────────────

  /** @return array<string,mixed>|null */
  public function ticket(int $pk): ?array
  {
    return $pk > 0 ? $this->db->getRow('SELECT * FROM `' . $this->tickets . '` WHERE `_ID` = ?', [$pk]) : null;
  }

  /** Casos abiertos del funcionario que se pueden vincular a un evento. @return array<int,array<string,mixed>> */
  public function openTicketsForEmployee(string $employeeId): array
  {
    return $this->db->getResults(
      'SELECT `_ID`, `indicativo`, `solicitante`, `id_empleado`, `estado`,
              `estado_comercial`, `estado_administrativo`, `departamento`, `contrato`, `inmueble`, `direccion`
         FROM `' . $this->tickets . '`
        WHERE `id_empleado` = ? AND `estado` IN (' . implode(',', array_fill(0, count(self::OPEN_TICKET_STATES), '?')) . ')',
      array_merge([$employeeId], self::OPEN_TICKET_STATES)
    );
  }

  /**
   * Marca el caso en proceso al agendarle un evento. Los estados comercial y administrativo solo cambian cuando el
   * formulario los envía (la cita del caso pasa a "Por inspeccionar"); nunca se vacían.
   */
  public function touchTicketForEvent(int $pk, string $commercialStatus, string $administrativeStatus): void
  {
    if ($this->ticket($pk) === null) {
      return;
    }
    $data = ['fecha_actualizacion' => time(), 'cct_modified' => $this->now(), 'estado' => 'En proceso'];
    if (trim($commercialStatus) !== '') {
      $data['estado_comercial'] = trim($commercialStatus);
    }
    if (trim($administrativeStatus) !== '') {
      $data['estado_administrativo'] = trim($administrativeStatus);
    }
    $this->db->update($this->tickets, $data, ['_ID' => $pk]);
  }

  public function touchTicket(int $pk): void
  {
    $this->db->update($this->tickets, ['fecha_actualizacion' => time(), 'cct_modified' => $this->now()], ['_ID' => $pk]);
  }

  /** Deja constancia de la cita en el historial del caso, a nombre del funcionario asignado. */
  public function addTicketHistory(int $pk, string $employeeId, int $eventId, string $message, string $authorId): void
  {
    $employee = $this->employee($employeeId) ?? [];
    $now = $this->now();
    $this->db->insert($this->history, [
      'cct_status' => 'publish',
      'cct_author_id' => trim($authorId) !== '' ? trim($authorId) : $employeeId,
      'cct_created' => $now,
      'cct_modified' => $now,
      'id_ticket' => $pk,
      'fecha' => time(),
      'nombre' => (string) ($employee['nombre'] ?? ''),
      'correo' => (string) ($employee['correo'] ?? ''),
      'celular' => (string) ($employee['celular'] ?? ''),
      'respuesta' => $message,
      'id_empleado' => $employeeId,
      'id_evento' => $eventId,
      'fue_editada' => 'Si',
    ]);
  }

  // ── Funcionarios ───────────────────────────────────

  /** @return array<int,array<string,mixed>> */
  public function employees(): array
  {
    return $this->db->getResults('SELECT `_ID`, `nombre`, `rol`, `id_cargo`, `id_empleado`, `activo` FROM `' . $this->employees . '` ORDER BY `nombre` ASC');
  }

  /** @return array<string,mixed>|null Fila completa: es de uso interno y nunca se envía tal cual al navegador. */
  public function employee(string $employeeId): ?array
  {
    return trim($employeeId) === '' ? null : $this->db->getRow('SELECT * FROM `' . $this->employees . '` WHERE `id_empleado` = ? LIMIT 1', [trim($employeeId)]);
  }

  /** El `id_empleado` real de quien tiene la sesión; vacío si no hay sesión (procesos del servidor). */
  public function currentEmployeeId(): string
  {
    $id = trim(Auth::employeeId());
    if ($id !== '' || Auth::userId() <= 0) {
      return $id;
    }
    return trim((string) $this->db->getVar('SELECT `id_empleado` FROM `' . $this->employees . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]));
  }

  public function now(): string
  {
    return (new \DateTimeImmutable('now', new \DateTimeZone(CalendarStore::TIMEZONE)))->format('Y-m-d H:i:s');
  }

  private function blockLimit(): string
  {
    return (new \DateTimeImmutable('now', new \DateTimeZone(CalendarStore::TIMEZONE)))->modify('-' . self::BLOCK_GRACE_DAYS . ' days')->format('Y-m-d H:i:s');
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function mapEvent(array $row): array
  {
    $row['tipo_item'] = 'evento';
    $row['source_table'] = self::EVENTS;
    $row['meta'] = self::decodeMeta($row['meta_json'] ?? null);
    unset($row['meta_json']);
    return $row;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function mapReminder(array $row): array
  {
    $row['tipo_item'] = 'recordatorio';
    $row['source_table'] = self::REMINDERS;
    $row['fecha_inicio'] = $row['recordatorio_at'];
    $row['fecha_fin'] = $row['recordatorio_at'];
    $row['descripcion'] = $row['mensaje'] ?? '';
    $row['recordatorio_canal'] = $row['canal'] ?? 'email';
    $row['recordatorio_queue_id'] = $row['notification_queue_id'] ?? null;
    $row['recordatorio_estado'] = $row['estado'] ?? 'pendiente';
    $row['meta'] = self::decodeMeta($row['meta_json'] ?? null);
    unset($row['meta_json']);
    return $row;
  }

  /** @param mixed $value */
  private static function optional($value): ?string
  {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : null;
  }

  /** @param mixed $value */
  private static function metaJson($value): ?string
  {
    if (is_array($value)) {
      return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
      return null;
    }
    json_decode($text, true);
    return json_last_error() === JSON_ERROR_NONE ? $text : json_encode(['raw' => $text], JSON_UNESCAPED_UNICODE);
  }

  /** @param mixed $value @return array<string,mixed> */
  private static function decodeMeta($value): array
  {
    $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
    return is_array($decoded) ? $decoded : [];
  }

  public static function reminderChannel(string $channel): string
  {
    $channel = strtolower(trim($channel));
    return in_array($channel, ['email', 'whatsapp', 'sms'], true) ? $channel : 'email';
  }

  private static function reminderStatus(string $status): string
  {
    $status = strtolower(trim($status));
    return in_array($status, ['pendiente', 'programado', 'finalizado', 'enviado', 'cancelado'], true) ? $status : 'pendiente';
  }
}
