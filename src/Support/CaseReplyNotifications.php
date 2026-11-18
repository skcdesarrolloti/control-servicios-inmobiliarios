<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/**
 * Avisos de respuesta de un caso en los dos sentidos:
 *  - Un actor (propietario, arrendatario, copropiedad o solicitante) responde desde la vista pública → funcionario asignado
 *    y copias del evento interno `respuesta_caso_actor`, por correo y WhatsApp, con enlace de autologin.
 *  - Un funcionario responde desde el panel → WhatsApp a los actores marcados (el correo lo envía el flujo de respuesta).
 * Cada mensaje es individual: los enlaces con autologin nunca viajan en correos con copias.
 */
final class CaseReplyNotifications
{
  public const INTERNAL_ACTION = 'respuesta_caso_actor';
  public const AUDIENCES = ['publico' => 'Solicitante', 'propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'copropiedad' => 'Copropiedad'];
  /** Rol de destinatario del panel → audiencia del enlace público y columnas de contacto en el caso. */
  private const PANEL_ROLES = [
    'solicitante' => ['audience' => 'publico', 'name' => 'solicitante', 'phone' => 'celular_solicitante'],
    'propietario' => ['audience' => 'propietario', 'name' => 'propietario', 'phone' => 'celular_propietario'],
    'arrendatario' => ['audience' => 'arrendatario', 'name' => 'arrendatario', 'phone' => 'celular_arrendatario'],
  ];

  public function __construct(private Database $db) {}

  public static function audienceLabel(string $audience): string
  {
    return self::AUDIENCES[$audience] ?? 'Solicitante';
  }

  /** Nombre del actor según el enlace firmado con el que abrió el caso; nunca lo escribe el visitante. */
  public static function actorName(array $ticket, string $audience): string
  {
    $column = ['publico' => 'solicitante', 'propietario' => 'propietario', 'arrendatario' => 'arrendatario', 'copropiedad' => 'copropiedad'][$audience] ?? 'solicitante';
    foreach ([$ticket['nombre_' . $column] ?? '', $ticket[$column] ?? ''] as $value) {
      $name = trim(strip_tags((string) $value));
      if ($name !== '' && !preg_match('/^\d+$/', $name)) return mb_substr($name, 0, 120);
    }
    return self::audienceLabel($audience);
  }

  /**
   * Respuesta desde la vista pública: avisa al funcionario asignado y a las copias configuradas.
   * @return array{email:int,whatsapp:int}
   */
  public function notifyActorReply(array $ticket, int $id, string $audience, string $actorName, string $text, int $attachments, string $dedupe): array
  {
    $sent = ['email' => 0, 'whatsapp' => 0];
    $logical = self::logicalTicket($ticket, $id);
    $actorLabel = self::audienceLabel($audience) . ' · ' . $actorName;
    $staff = [];
    $assigneeId = trim((string) ($ticket['id_empleado'] ?? ''));
    $assignee = $this->employee($assigneeId);
    $staff[] = [
      'employee_id' => $assigneeId,
      'name' => self::first([$ticket['nombre_empleado'] ?? '', $assignee['nombre'] ?? '', 'Funcionario asignado']),
      'email' => self::first([$ticket['correo_empleado'] ?? '', $assignee['correo'] ?? '']),
      'phone' => self::first([$ticket['celular_empleado'] ?? '', $assignee['celular'] ?? '']),
    ];
    foreach (InternalNotificationRecipients::contactsForAction($this->db, self::INTERNAL_ACTION) as $contact) {
      $staff[] = ['employee_id' => (string) ($contact['employee_id'] ?? ''), 'name' => (string) ($contact['name'] ?? ''), 'email' => (string) ($contact['email'] ?? ''), 'phone' => (string) ($contact['phone'] ?? '')];
    }

    $subject = 'Caso #' . $logical . ': nueva respuesta de ' . self::audienceLabel($audience);
    $emails = [];
    $phones = [];
    $config = PanelCaseNotifications::config();
    $queue = $config['reply_enabled'] ? (new SharedNotificationsBridge($this->db))->queue() : null;
    foreach ($staff as $person) {
      $name = trim($person['name']) !== '' ? trim($person['name']) : 'Funcionario';
      $email = strtolower(trim($person['email']));
      if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($emails[$email])) {
        $emails[$email] = true;
        $content = '<p style="margin:0 0 12px;">Hola ' . EmailTemplate::e($name) . ', el caso <b>#' . EmailTemplate::e($logical) . '</b> recibió una respuesta desde la vista pública.</p>'
          . '<p style="margin:4px 0;"><b>Respondió:</b> ' . EmailTemplate::e($actorLabel) . '</p>'
          . '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e(self::first([$ticket['asunto'] ?? '', 'Sin asunto'])) . '</p>'
          . ($attachments > 0 ? '<p style="margin:4px 0;"><b>Adjuntos:</b> ' . $attachments . '</p>' : '')
          . '<div style="margin:14px 0;padding:12px 14px;border-left:4px solid #f59120;background:#f8fafc;white-space:pre-wrap;">' . EmailTemplate::e($text) . '</div>'
          . '<p style="margin:16px 0 0;">Abre el caso con el botón <b>Responder en el panel</b> para contestarle.</p>';
        try {
          // Correo individual sin copias: el enlace inicia la sesión de quien lo recibe.
          $sent['email'] += (new EmailQueue($this->db))->enqueue($email, $subject, EmailTemplate::render('Nueva respuesta en el caso', $content, [
            'ticket_url' => PublicCaseAccess::staffUrl($id, $person['employee_id']),
            'ticket_label' => 'Responder en el panel',
          ]), [
            'source_module' => self::INTERNAL_ACTION, 'internal_cc' => [], 'destination_name' => $name,
            'dedupe_key' => $dedupe . ':email:' . $email,
            'meta' => ['event' => self::INTERNAL_ACTION, 'ticket_id' => $id, 'id_ticket' => $logical, 'recipient_role' => 'funcionario', 'actor' => $audience],
          ]);
        } catch (\Throwable $error) {
          error_log('[respuesta_caso_actor] Caso #' . $id . ': no se pudo encolar correo: ' . $error->getMessage());
        }
      }
      $phone = PanelCaseNotifications::phone($person['phone']);
      if (!$queue || $phone === '' || isset($phones[$phone])) continue;
      $phones[$phone] = true;
      $sent['whatsapp'] += $this->enqueueTemplate($queue, $config, $phone, $name, [
        $name, $logical, $actorLabel, $ticket['asunto'] ?? '', $ticket['estado'] ?? '', $text,
      ], PublicCaseAccess::staffReference($id, $person['employee_id']), self::INTERNAL_ACTION, $dedupe . ':whatsapp:' . $phone, ['ticket_id' => $id, 'recipient_role' => 'funcionario', 'actor' => $audience]);
    }
    return $sent;
  }

  /**
   * Respuesta desde el panel: WhatsApp a los actores marcados y al funcionario asignado cuando responde otra persona.
   * Las copias internas (admin) y los correos adicionales siguen solo por correo.
   * @param string[] $targets
   */
  public function notifyPanelResponse(array $ticket, int $id, string $respuesta, string $userName, string $estado, array $targets, string $responderEmployeeId, string $dedupe): int
  {
    $config = PanelCaseNotifications::config();
    if (!$config['reply_enabled'] || in_array('none', $targets, true)) return 0;
    $queue = (new SharedNotificationsBridge($this->db))->queue();
    if (!$queue) return 0;
    $selected = $targets === [] ? ['solicitante', 'arrendatario', 'propietario', 'empleado'] : $targets;
    $logical = self::logicalTicket($ticket, $id);
    $text = self::plain($respuesta);
    if ($estado === 'Cerrado') $text = 'Caso cerrado. ' . $text;
    $people = [];
    foreach (self::PANEL_ROLES as $role => $columns) {
      if (!in_array($role, $selected, true)) continue;
      $people[] = ['role' => $role, 'name' => self::actorName($ticket, $columns['audience']), 'phone' => (string) ($ticket[$columns['phone']] ?? ''), 'button' => PublicCaseAccess::reference($id, null, $columns['audience'])];
    }
    $assigneeId = trim((string) ($ticket['id_empleado'] ?? ''));
    if (in_array('empleado', $selected, true) && $assigneeId !== '' && $assigneeId !== trim($responderEmployeeId)) {
      $assignee = $this->employee($assigneeId);
      $people[] = [
        'role' => 'funcionario',
        'name' => self::first([$ticket['nombre_empleado'] ?? '', $assignee['nombre'] ?? '', 'Funcionario asignado']),
        'phone' => self::first([$ticket['celular_empleado'] ?? '', $assignee['celular'] ?? '']),
        'button' => PublicCaseAccess::staffReference($id, $assigneeId),
      ];
    }
    $phones = [];
    $count = 0;
    foreach ($people as $person) {
      $phone = PanelCaseNotifications::phone($person['phone']);
      if ($phone === '' || isset($phones[$phone])) continue;
      $phones[$phone] = true;
      $count += $this->enqueueTemplate($queue, $config, $phone, $person['name'], [
        $person['name'], $logical, self::first([$userName, 'SKC SuCasa Inmobiliaria']), $ticket['asunto'] ?? '', $estado, $text,
      ], $person['button'], 'respuesta_ticket', $dedupe . ':whatsapp:' . $phone, ['ticket_id' => $id, 'id_ticket' => $logical, 'recipient_role' => $person['role']]);
    }
    return $count;
  }

  /** Enlace de la vista pública para el destinatario de un correo de respuesta o seguimiento (correos con copias: sin autologin). */
  public static function emailCaseUrl(int $id, string $role): string
  {
    $audience = self::PANEL_ROLES[$role]['audience'] ?? (in_array($role, ['empleado', 'admin'], true) ? 'funcionario' : 'publico');
    return PublicCaseAccess::url($id, $audience);
  }

  /** @param array<int,mixed> $values Seis variables de `scm_caso_respuesta_v1`. */
  private function enqueueTemplate(\SharedNotifications\NotificationQueue $queue, array $config, string $phone, string $name, array $values, string $buttonReference, string $source, string $dedupe, array $meta): int
  {
    // Meta rechaza saltos de línea y tabulaciones en las variables; los límites mantienen el mensaje bajo 1024 caracteres.
    $values = array_map(static fn($value, int $limit): string => mb_substr(self::plain((string) $value) ?: 'Sin dato', 0, $limit), $values, [80, 20, 120, 150, 40, 500]);
    try {
      $queued = $queue->enqueueWhatsAppOfficialTemplate($phone, $config['reply_template'], [
        ['type' => 'body', 'parameters' => array_map(static fn(string $value): array => ['type' => 'text', 'text' => $value], $values)],
        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonReference]]],
      ], [
        'project_code' => 'control-servicios-inmobiliarios', 'source_module' => $source,
        'destination_name' => $name, 'template_language' => $config['language'], 'max_attempts' => 5,
        'message_text' => 'Caso #' . $values[1] . ' · Nueva respuesta de ' . $values[2] . ': ' . $values[5],
        'dedupe_key' => $dedupe, 'meta' => $meta,
      ]);
    } catch (\Throwable $error) {
      error_log('[' . $source . '] Caso #' . $values[1] . ': no se pudo encolar WhatsApp: ' . $error->getMessage());
      return 0;
    }
    return $queued > 0 ? 1 : 0;
  }

  /** @return array<string,string> */
  private function employee(string $employeeId): array
  {
    if ($employeeId === '') return [];
    try {
      $row = $this->db->getRow('SELECT `nombre`, `correo`, `celular` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `id_empleado` = ? LIMIT 1', [$employeeId]);
    } catch (\Throwable) {
      return [];
    }
    return is_array($row) ? array_map('strval', $row) : [];
  }

  private static function logicalTicket(array $ticket, int $id): string
  {
    return self::first([$ticket['id_ticket'] ?? '', (string) $id]);
  }

  public static function plain(string $value): string
  {
    $value = preg_replace('/<\/(p|div|li)>|<br\s*\/?\s*>/i', ' ', $value) ?? $value;
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  /** @param array<int,mixed> $values */
  private static function first(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string) $value);
      if ($text !== '') return $text;
    }
    return '';
  }
}
