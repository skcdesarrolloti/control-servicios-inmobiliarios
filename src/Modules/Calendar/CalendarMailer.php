<?php

declare(strict_types=1);

namespace SCM\Modules\Calendar;

use SCM\Support\EmailTemplate;
use SCM\Support\PublicCaseAccess;
use SCM\Support\SharedNotificationsBridge;

/**
 * Correos y avisos de recordatorio del calendario, siempre encolados en shared-notifications. Lógica traída de
 * calendario-actividades/helpers/email.php: conserva el proyecto y las `dedupe_key` para poder cancelar lo que esa app
 * dejó programado. Los destinatarios salen de los datos (responsable, quien creó, "roles a notificar" de la categoría y
 * los funcionarios elegidos en el formulario), nunca de correos fijos.
 */
class CalendarMailer
{
  private const PROJECT = 'calendario-actividades';

  private CalendarStore $store;
  private ?SharedNotificationsBridge $bridge;

  public function __construct(CalendarStore $store, ?SharedNotificationsBridge $bridge = null)
  {
    $this->store = $store;
    $this->bridge = $bridge;
  }

  /** @param array<string,mixed> $event */
  public function newEvent(array $event, int $eventId): void
  {
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail($this->recipients($event), 'Nuevo evento #' . $eventId . ' de ' . $this->categoryName($event) . ' agregado', 'Nuevo evento agendado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, se agendó el siguiente evento:') . $this->eventDetails($event),
      'nuevo-evento-email', 'calendario:nuevo-evento:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado'], 'creado_por' => $event['creado_por'] ?? null, 'id_categoria' => $event['id_categoria'] ?? null]);
  }

  /** @param array<int,array<string,mixed>> $events Eventos de un mismo funcionario. */
  public function bulkEvents(string $employeeId, array $events): void
  {
    $employee = $this->store->employee($employeeId);
    if ($employee === null || $events === []) {
      return;
    }
    usort($events, static fn(array $a, array $b): int => strcmp((string) $a['fecha_inicio'], (string) $b['fecha_inicio']));
    $list = '<ul style="padding-left:20px;color:#404041;">';
    foreach ($events as $event) {
      $list .= '<li style="margin-bottom:5px;"><strong>' . self::e(self::humanDate((string) $event['fecha_inicio'])) . '</strong> a ' . self::e(self::humanTime((string) $event['fecha_fin'])) . '</li>';
    }
    $list .= '</ul>';
    $ids = array_values(array_filter(array_column($events, 'id')));
    $this->sendEmail($this->recipients($events[0]), 'Se han creado ' . count($events) . ' eventos de ' . $this->categoryName($events[0]), 'Eventos agendados',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, se agendaron ' . count($events) . ' fechas de <strong>' . self::e((string) $events[0]['titulo']) . '</strong>:') . $list,
      'eventos-masivos-email', 'calendario:eventos-masivos:' . $employeeId . ':' . md5(implode(',', $ids)), ['id_empleado' => $employeeId, 'id_eventos' => $ids, 'cantidad' => count($events)]);
  }

  /** @param array<string,mixed> $event */
  public function eventCompleted(array $event, int $eventId): void
  {
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail($this->recipients($event), 'Evento #' . $eventId . ' de ' . $this->categoryName($event) . ' finalizado', 'Evento finalizado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, el evento <strong>#' . $eventId . '</strong> quedó marcado como realizado.') . $this->eventDetails($event),
      'evento-realizado-email', 'calendario:evento-realizado:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado'], 'creado_por' => $event['creado_por'] ?? null]);
  }

  /** @param array<string,mixed> $event */
  public function eventMoved(array $event, int $eventId): void
  {
    $employee = $this->store->employee((string) $event['id_empleado']);
    if ($employee === null) {
      return;
    }
    $this->sendEmail($this->recipients($event), 'Evento #' . $eventId . ' de ' . $this->categoryName($event) . ' trasladado', 'Evento trasladado',
      $this->paragraph('Hola <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong>, el evento quedó con esta nueva fecha:') . $this->eventDetails($event),
      'evento-trasladado-email', 'calendario:evento-trasladado:' . $eventId, ['id_evento' => $eventId, 'id_empleado' => $event['id_empleado']]);
  }

  /** Aviso al solicitante del caso cuando se le agenda o reprograma una cita. @param array<string,mixed> $event */
  public function requesterAppointment(array $event, bool $moved): void
  {
    $pk = (int) ($event['id_ticket'] ?? 0);
    $ticket = $pk > 0 ? $this->store->ticket($pk) : null;
    if ($ticket === null || trim((string) ($ticket['correo_solicitante'] ?? '')) === '') {
      return;
    }
    $employee = $this->store->employee((string) $event['id_empleado']) ?? [];
    $intro = 'Apreciado/a <strong>' . self::e((string) ($ticket['solicitante'] ?? '')) . '</strong>, nuestro funcionario <strong>' . self::e((string) ($employee['nombre'] ?? '')) . '</strong> '
      . ($moved ? 'reprogramó su cita.' : 'le ha agendado una cita.');
    $this->sendEmail([(string) $ticket['correo_solicitante']], ($moved ? 'Evento reprogramado de ' : 'Tienes evento programado de ') . $this->categoryName($event), $moved ? 'Cita reprogramada' : 'Cita agendada',
      $this->paragraph($intro) . $this->eventDetails($event),
      $moved ? 'cita-trasladada-solicitante-email' : 'cita-solicitante-email',
      'calendario:' . ($moved ? 'cita-trasladada-solicitante:' : 'cita-solicitante:') . $pk . ':' . (string) $event['fecha_inicio'], ['id_ticket' => $pk, 'id_empleado' => $event['id_empleado']],
      [['url' => PublicCaseAccess::url($pk), 'label' => 'Ver el caso']]);
  }

  /** Programa el aviso del recordatorio en la cola; devuelve el id o null si no se pudo. @param array<string,mixed> $item */
  public function scheduleReminder(array $item): ?int
  {
    $at = trim((string) ($item['recordatorio_at'] ?? ''));
    $employeeId = trim((string) ($item['id_empleado'] ?? ''));
    $id = trim((string) ($item['id'] ?? ''));
    $queue = $this->queue();
    $employee = $this->store->employee($employeeId);
    if ($at === '' || $id === '' || $queue === null || $employee === null) {
      return null;
    }
    $channel = CalendarStore::reminderChannel((string) ($item['recordatorio_canal'] ?? 'email'));
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $minutes = max(0, min(1440, (int) ($meta['aviso_minutos_antes'] ?? 0)));
    $zone = new \DateTimeZone('America/Bogota');
    $scheduled = (new \DateTimeImmutable($at, $zone))->modify('-' . $minutes . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    $title = trim((string) ($item['titulo'] ?? 'Recordatorio'));
    $when = self::humanDate($at);
    $location = trim((string) ($item['ubicacion'] ?? ''));
    $text = 'Recordatorio: ' . $title . "\nFecha: " . $when . ($location !== '' ? "\nUbicación: " . $location : '');
    $dedupe = self::reminderKey($item, $channel);
    $options = [
      'project_code' => self::PROJECT, 'source_module' => 'recordatorio-calendario-' . $channel, 'created_by' => CalendarStore::ORIGIN, 'scheduled_at' => $scheduled, 'dedupe_key' => $dedupe, 'priority' => 150,
      'meta' => ['item_id' => $id, 'tipo_item' => 'recordatorio', 'id_empleado' => $employeeId, 'origen_app' => $item['origen_app'] ?? CalendarStore::ORIGIN, 'external_ref' => $item['external_ref'] ?? null, 'source_table' => CalendarStore::REMINDERS, 'aviso_minutos_antes' => $minutes],
    ];
    try {
      $queue->cancelByDedupeKey($dedupe, 'Cancelado por un recordatorio de calendario mas reciente.');
      if ($channel === 'email') {
        if (!filter_var(trim((string) ($employee['correo'] ?? '')), FILTER_VALIDATE_EMAIL)) {
          return null;
        }
        $ids = $queue->enqueueEmail([trim((string) $employee['correo'])], 'Recordatorio de calendario: ' . $title, EmailTemplate::render('Recordatorio', $this->paragraph(nl2br(self::e($text)))), $options + ['provider' => 'email_smtp']);
        return isset($ids[0]) ? (int) $ids[0] : null;
      }
      $phone = trim((string) ($employee['celular'] ?? ''));
      if ($phone === '') {
        return null;
      }
      if ($channel === 'sms') {
        return (int) $queue->enqueueSms($phone, $text, $options + ['provider' => 'sms_onurix']) ?: null;
      }
      $template = trim((string) getenv('CALENDARIO_WHATSAPP_REMINDER_TEMPLATE')) ?: 'recordatorio_calendario';
      $language = trim((string) getenv('CALENDARIO_WHATSAPP_REMINDER_TEMPLATE_LANG')) ?: 'es_CO';
      return (int) $queue->enqueueWhatsAppOfficialTemplate($phone, $template, [[
        'type' => 'body',
        'parameters' => [
          ['type' => 'text', 'text' => (string) ($employee['nombre'] ?? 'Funcionario')],
          ['type' => 'text', 'text' => $title . ' • recordatorio' . ($location !== '' ? ' • ' . $location : '')],
          ['type' => 'text', 'text' => $when],
        ],
      ]], $options + ['provider' => 'whatsapp_official', 'template_language' => $language, 'payload' => ['text_fallback' => $text]]) ?: null;
    } catch (\Throwable $exception) {
      error_log('[calendar] recordatorio ' . $id . ': ' . $exception->getMessage());
      return null;
    }
  }

  /** @param array<string,mixed> $item */
  public function cancelReminder(array $item, string $reason): void
  {
    $queue = $this->queue();
    if ($queue === null) {
      return;
    }
    try {
      $queue->cancelByDedupeKey(self::reminderKey($item, CalendarStore::reminderChannel((string) ($item['recordatorio_canal'] ?? ($item['canal'] ?? 'email')))), $reason);
    } catch (\Throwable $exception) {
      error_log('[calendar] cancelar recordatorio: ' . $exception->getMessage());
    }
  }

  /**
   * @param array<int,string> $destinations
   * @param array<string,mixed> $meta
   * @param array<int,array{url:string,label:string}> $buttons
   */
  protected function sendEmail(array $destinations, string $subject, string $title, string $body, string $module, string $dedupe, array $meta, array $buttons = []): void
  {
    ['to' => $to, 'cc' => $cc] = self::addressing($destinations);
    $queue = $this->queue();
    if ($to === null || $queue === null) {
      return;
    }
    try {
      $queue->cancelByDedupeKey($dedupe, 'Cancelado por una notificacion mas reciente del calendario.');
      $queue->enqueueEmail([$to], $subject, EmailTemplate::render($title, $body, $buttons !== [] ? ['buttons' => $buttons] : []), [
        'project_code' => self::PROJECT, 'source_module' => $module, 'created_by' => CalendarStore::ORIGIN, 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'meta' => $meta + ['cc' => $cc],
        'payload' => ['cc' => $cc],
      ]);
    } catch (\Throwable $exception) {
      error_log('[calendar] correo ' . $module . ': ' . $exception->getMessage());
    }
  }

  /**
   * Un solo correo por aviso: el primero (el responsable del evento) como destinatario y los demás en copia (CC),
   * sin repetir ni correos inválidos.
   * @param array<int,string> $destinations
   * @return array{to:?string,cc:array<int,string>}
   */
  public static function addressing(array $destinations): array
  {
    $unique = [];
    foreach ($destinations as $mail) {
      $mail = trim((string) $mail);
      if (filter_var($mail, FILTER_VALIDATE_EMAIL) && !isset($unique[strtolower($mail)])) {
        $unique[strtolower($mail)] = $mail;
      }
    }
    $list = array_values($unique);
    return ['to' => $list[0] ?? null, 'cc' => array_slice($list, 1)];
  }

  private function queue(): ?\SharedNotifications\NotificationQueue
  {
    $this->bridge ??= new SharedNotificationsBridge($this->store->database());
    return $this->bridge->queue();
  }

  /** @param array<string,mixed> $item */
  private static function reminderKey(array $item, string $channel): string
  {
    return 'calendario:' . CalendarStore::REMINDERS . ':' . trim((string) ($item['id'] ?? '')) . ':recordatorio:' . $channel;
  }

  /**
   * Responsable, quien creó el evento, "roles a notificar" de la categoría y funcionarios elegidos en el formulario.
   * @param array<string,mixed> $event
   * @return array<int,string>
   */
  private function recipients(array $event): array
  {
    $ids = [(string) ($event['id_empleado'] ?? ''), (string) ($event['creado_por'] ?? '')];
    $category = $this->store->category((int) ($event['id_categoria'] ?? 0));
    $ids = array_merge($ids, self::idList($category['roles_notificar'] ?? ''), self::idList($event['notificar_funcionarios'] ?? []));
    $emails = [];
    foreach (array_unique(array_filter(array_map('trim', $ids))) as $id) {
      $emails[] = (string) ($this->store->employee($id)['correo'] ?? '');
    }
    return $emails;
  }

  /** @param mixed $value @return array<int,string> */
  private static function idList($value): array
  {
    $list = is_array($value) ? $value : explode(',', (string) $value);
    return array_values(array_filter(array_map(static fn($id): string => trim((string) $id), $list), static fn(string $id): bool => $id !== ''));
  }

  /** @param array<string,mixed> $event */
  private function categoryName(array $event): string
  {
    return (string) ($this->store->category((int) ($event['id_categoria'] ?? 0))['nombre'] ?? 'calendario');
  }

  /** @param array<string,mixed> $event */
  private function eventDetails(array $event): string
  {
    $location = trim((string) ($event['ubicacion'] ?? ''));
    return $this->row('Evento', (string) ($event['titulo'] ?? ''))
      . $this->row('Inicio', self::humanDate((string) ($event['fecha_inicio'] ?? '')))
      . $this->row('Fin', self::humanDate((string) ($event['fecha_fin'] ?? '')))
      . ($location !== '' ? $this->row('Lugar', $location) : '');
  }

  private function row(string $label, string $value): string
  {
    return '<p style="margin:10px 0;"><strong>' . self::e($label) . ':</strong> ' . self::e($value) . '</p>';
  }

  private function paragraph(string $html): string
  {
    return '<p style="margin:10px 0;">' . $html . '</p>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  private static function humanDate(string $date): string
  {
    $ts = strtotime($date);
    return $ts > 0 ? str_replace(['am', 'pm'], ['a. m.', 'p. m.'], date('d/m/Y g:i a', $ts)) : $date;
  }

  private static function humanTime(string $date): string
  {
    $ts = strtotime($date);
    return $ts > 0 ? str_replace(['am', 'pm'], ['a. m.', 'p. m.'], date('g:i a', $ts)) : $date;
  }
}
