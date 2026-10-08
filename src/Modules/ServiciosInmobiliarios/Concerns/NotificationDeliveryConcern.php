<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;

trait NotificationDeliveryConcern
{
  private function notifyTicketResponse(array $ticket, string $logicalTicket, string $respuesta, string $userName, string $estado, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = $estado === 'Cerrado' ? 'Caso #' . $logicalTicket . ' cerrado' : 'Caso #' . $logicalTicket . ' con nueva respuesta';
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets, [], 'respuesta_ticket') as $recipient) {
      $html = EmailTemplate::renderNamed('respuesta_ticket', [
        'asunto_correo' => EmailTemplate::e($subject),
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'mensaje_intro' => $estado === 'Cerrado'
          ? 'Te informamos que el caso ha sido cerrado.'
          : 'Te informamos que el caso ha tenido una nueva respuesta.',
        'respuesta' => wp_kses_post($respuesta),
        'usuario' => EmailTemplate::e($userName),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver caso']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html);
    }
    return $sent;
  }

  /** @return array{email:int,whatsapp:int} */
  public function notifyCotizacionResponse(array $ticket, array $cotizacion, string $estado, string $observacion): array
  {
    $queued = ['email' => 0, 'whatsapp' => 0];
    $cotId = trim((string) ($cotizacion['_ID'] ?? ''));
    if ($cotId === '' || (int) $cotId <= 0) return $queued;
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '', $cotizacion['id_ticket'] ?? '']);
    $cotUrl = \SCM\App\SuCasaControlServiciosInmobiliarios::signedMaintenanceQuotePublicUrl((int) $cotId);
    $subject = 'Nueva respuesta de cotizacion de mantenimiento #' . $cotId;
    $motivo = trim((string)($cotizacion['motivo'] ?? ''));
    $recipients = array_merge([[
      'name' => trim((string) ($cotizacion['creador'] ?? '')) ?: 'Creador de la cotización',
      'email' => trim((string) ($cotizacion['email_creador'] ?? '')),
      'phone' => trim((string) ($cotizacion['celular_creador'] ?? '')),
    ]], InternalNotificationRecipients::contactsForAction($this->db, 'respuesta_cotizacion_mantenimiento'));
    $dedupe = 'cotizacion-respuesta:' . $cotId . ':' . $estado . ':' . (string) ($cotizacion['fecha_respuesta'] ?? '');
    $meta = [
      'event' => 'respuesta_cotizacion_mantenimiento',
      'id_cotizacion' => $cotId,
      'id_ticket' => $logicalTicket,
      'estado' => $estado,
      'fecha_respuesta' => $cotizacion['fecha_respuesta'] ?? '',
    ];
    $smsQueue = new \SCM\Support\SmsQueue($this->db);
    $buttonSuffix = $this->whatsappUrlButtonSuffix($cotUrl);
    $details = trim(wp_strip_all_tags($observacion));
    if ($estado === 'Desaprobada' && $motivo !== '') $details .= ' Motivo: ' . $motivo . '.';
    if ($estado === 'Aprobada' && trim((string) ($cotizacion['financiacion'] ?? '')) !== '') {
      $details .= ' Financiación: ' . (string) $cotizacion['financiacion'] . '.';
    }
    $seenEmail = [];
    $seenPhone = [];
    $text = static fn($value): string => mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value)) ?: '') ?: 'No aplica', 0, 600, 'UTF-8');
    foreach ($recipients as $recipient) {
      $name = trim((string) ($recipient['name'] ?? '')) ?: 'Funcionario';
      $email = strtolower(trim((string) ($recipient['email'] ?? '')));
      if ($this->queue instanceof EmailQueue && filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($seenEmail[$email])) {
        $seenEmail[$email] = true;
        $html = EmailTemplate::renderNamed('respuesta_cotizacion', [
          'destinatario' => EmailTemplate::e($name),
          'id_cotizacion' => EmailTemplate::e($cotId),
          'id_ticket' => EmailTemplate::e($logicalTicket),
          'estado' => EmailTemplate::e($estado),
          'observacion' => wp_kses_post($observacion),
          'motivo_bloque' => $estado === 'Desaprobada' && $motivo !== ''
            ? '<p style="font-weight:500;margin:10px 0;"><b>Motivo:</b> ' . EmailTemplate::e($motivo) . '</p>'
            : '',
          'financiacion' => EmailTemplate::e((string)($cotizacion['financiacion'] ?? '')),
          'botones' => EmailTemplate::buttons([['url' => $cotUrl, 'label' => 'Ver cotizacion']]),
        ]);
        try {
          $queued['email'] += $this->queue->enqueue($email, $subject, $html, [
            'source_module' => 'respuesta_cotizacion_mantenimiento',
            'destination_name' => $name,
            'dedupe_key' => $dedupe . ':email',
            'meta' => $meta,
          ]);
        } catch (\Throwable $error) {
          error_log('[respuesta_cotizacion_mantenimiento] Cotización #' . $cotId . ': no se pudo encolar correo: ' . $error->getMessage());
        }
      }
      $phone = trim((string) ($recipient['phone'] ?? ''));
      $digits = preg_replace('/\D+/', '', $phone) ?: '';
      if ($phone !== '' && !str_starts_with($phone, '+') && strlen($digits) <= 10) $digits = '57' . $digits;
      $phone = '+' . $digits;
      if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone) || isset($seenPhone[$phone])) continue;
      $seenPhone[$phone] = true;
      $parameters = array_map(static fn($value): array => ['type' => 'text', 'text' => $text($value)], [
        $name, $cotId, $logicalTicket, $estado, $cotizacion['destinatario'] ?? '',
        $cotizacion['contrato'] ?? '', $cotizacion['inmueble'] ?? '', $details,
      ]);
      $message = 'La cotización #' . $cotId . ' del caso #' . $logicalTicket . ' fue respondida: ' . $estado . '. ' . $details;
      if ($smsQueue->enqueue($phone, $name, $message, array_merge($meta, [
        'source_module' => 'respuesta_cotizacion_mantenimiento',
        'dedupe_key' => $dedupe . ':whatsapp',
        'quote_url' => $cotUrl,
        'button_url_mode' => 'dynamic_suffix',
        'template_name' => 'scm_cotizacion_mantenimiento_respuesta_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          ['type' => 'body', 'parameters' => $parameters],
          ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonSuffix]]],
        ],
      ]))) {
        $queued['whatsapp']++;
      } else {
        error_log('[respuesta_cotizacion_mantenimiento] Cotización #' . $cotId . ': no se pudo encolar WhatsApp: ' . $smsQueue->lastError());
      }
    }

    return $queued;
  }

  private function notifySeguimiento(array $ticket, string $observacion, string $userName, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Nuevo seguimiento del caso #' . $logicalTicket;
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets, [], 'seguimiento_ticket') as $recipient) {
      $html = EmailTemplate::renderNamed('nuevo_seguimiento', [
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'id_ticket' => EmailTemplate::e($logicalTicket),
        'observacion' => wp_kses_post($observacion),
        'usuario' => EmailTemplate::e($userName),
        'fecha' => EmailTemplate::e(date('Y-m-d H:i')),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver caso']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html, [
        'cc' => [],
      ]);
    }
    return $sent;
  }

  /** @param array<string,mixed> $ticket @param array<string,string> $notice @return array{email:int,whatsapp:int} */
  private function notifyPreventivaNoAccessNotice(array $ticket, string $logicalTicket, array $notice, int $attempt, string $userName): array
  {
    $userName = (new \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService($this->db))->senderProfile()['signature_line'];
    $emailSent = 0;
    $whatsappSent = 0;
    $tenantEmail = trim((string)($ticket['correo_arrendatario'] ?? $ticket['correo_solicitante'] ?? ''));
    $tenantName = $this->resolveTicketTenantDisplayName($ticket);
    $noticeUrl = trim((string)($notice['url'] ?? ''));
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Acta de revision preventiva del caso #' . $logicalTicket;

    $content = '<p style="font-weight:500;margin:10px 0;">Apreciado(a) ' . EmailTemplate::e($tenantName) . ',</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Se ha generado el acta/comunicación preventiva para dejar constancia de la gestión realizada frente a la revisión preventiva del inmueble.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Esta comunicación corresponde al intento No. ' . EmailTemplate::e((string)$attempt) . ' y queda anexada al historial del caso.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Puedes consultarla desde el siguiente botón. No adjuntamos el archivo para evitar bloqueos o marcaciones de spam.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Cordialmente,<br><b>' . EmailTemplate::e($userName) . '</b></p>';

    if ($this->queue instanceof EmailQueue && $tenantEmail !== '' && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::render('Acta de revision preventiva', $content, [
        'buttons' => [
          ['url' => $noticeUrl, 'label' => 'Ver acta preventiva'],
          ['url' => $ticketUrl, 'label' => 'Ver caso'],
        ],
      ]);
      $emailSent = $this->sendMailToUnique($tenantEmail, $subject, $html);
    }

    $tenantPhone = $this->firstNonEmpty([
      $ticket['celular_arrendatario'] ?? '',
      $ticket['celular_solicitante'] ?? '',
      $ticket['telefono_arrendatario'] ?? '',
      $ticket['whatsapp_arrendatario'] ?? '',
    ]);
    if ($tenantPhone !== '' && $noticeUrl !== '') {
      try {
        $buttonSuffix = $this->whatsappUrlButtonSuffix($noticeUrl);
        $message = "Buen día, {$tenantName}.\n\n";
        $message .= "Se generó la comunicación preventiva No. {$attempt} del caso #{$logicalTicket}, relacionada con la revisión preventiva del inmueble.\n\n";
        $message .= "Puedes consultar el documento en el botón.\n\n";
        $message .= "Atentamente,\n{$userName}";

        $smsQueue = new \SCM\Support\SmsQueue($this->db);
        $ok = $smsQueue->enqueue($tenantPhone, $tenantName, $message, [
          'source_module' => 'preventiva_no_access_notice',
          'campaign_tag' => 'preventiva_no_access_notice',
          'categoria_mensaje' => 'informacion',
          'id_ticket' => $logicalTicket,
          'attempt' => $attempt,
          'notice_url' => $noticeUrl,
          'dedupe_key' => 'preventiva_no_access_notice:' . $logicalTicket . ':' . $attempt,
          'template_name' => 'scm_preventiva_no_acceso_v1',
          'template_language' => 'es_CO',
          'template_components' => [
            [
              'type' => 'body',
              'parameters' => [
                ['type' => 'text', 'text' => $tenantName],
                ['type' => 'text', 'text' => (string) $attempt],
                ['type' => 'text', 'text' => $logicalTicket],
                ['type' => 'text', 'text' => $userName],
              ],
            ],
            [
              'type' => 'button',
              'sub_type' => 'url',
              'index' => '0',
              'parameters' => [
                ['type' => 'text', 'text' => $buttonSuffix],
              ],
            ],
          ],
        ]);
        if ($ok) {
          $whatsappSent = 1;
        } else {
          $detail = trim($smsQueue->lastError());
          error_log('control-servicios-inmobiliarios: no se pudo encolar WhatsApp de comunicacion preventiva #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : ''));
        }
      } catch (\Throwable $exception) {
        error_log('control-servicios-inmobiliarios: error preparando WhatsApp de comunicacion preventiva #' . $logicalTicket . ': ' . $exception->getMessage());
      }
    }

    return ['email' => $emailSent, 'whatsapp' => $whatsappSent];
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $cotizacion @param array<string,string> $notice @return array{email:int,whatsapp:int} */
  private function notifyRepairFollowupNotice(array $ticket, array $cotizacion, string $logicalTicket, array $notice, int $attempt, int $elapsedDays, string $userName): array
  {
    $userName = (new \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService($this->db))->senderProfile()['signature_line'];
    $emailSent = 0;
    $whatsappSent = 0;
    $quoteId = $this->firstNonEmpty([$cotizacion['_ID'] ?? '', $cotizacion['id_cotizacion_mantenimiento'] ?? '', $cotizacion['id_cotizacion'] ?? '']);
    $recipientName = $this->firstNonEmpty([
      $cotizacion['destinatario'] ?? '',
      $ticket['propietario'] ?? '',
      $ticket['arrendatario'] ?? '',
      $ticket['solicitante'] ?? '',
      'cliente',
    ]);
    $recipientEmail = $this->firstNonEmpty([
      $cotizacion['email_destinatario'] ?? '',
      $cotizacion['correo_destinatario'] ?? '',
      $ticket['correo_propietario'] ?? '',
      $ticket['correo_arrendatario'] ?? '',
      $ticket['correo_solicitante'] ?? '',
    ]);
    $recipientPhone = $this->firstNonEmpty([
      $cotizacion['celular_destinatario'] ?? '',
      $cotizacion['telefono_destinatario'] ?? '',
      $ticket['celular_propietario'] ?? '',
      $ticket['celular_arrendatario'] ?? '',
      $ticket['celular_solicitante'] ?? '',
      $ticket['telefono_propietario'] ?? '',
      $ticket['telefono_arrendatario'] ?? '',
    ]);
    $noticeUrl = trim((string) ($notice['url'] ?? ''));
    $quoteUrl = $quoteId !== '' ? \SCM\App\SuCasaControlServiciosInmobiliarios::signedMaintenanceQuotePublicUrl((int) $quoteId) : '';
    $subject = 'Seguimiento de reparaciones - cotizacion #' . ($quoteId !== '' ? $quoteId : '-') . ' del caso #' . $logicalTicket;

    $content = '<p style="font-weight:500;margin:10px 0;">Apreciado(a) ' . EmailTemplate::e($recipientName) . ',</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Se ha generado la comunicacion de seguimiento de reparaciones para dejar constancia de que la cotizacion de mantenimiento #' . EmailTemplate::e($quoteId !== '' ? $quoteId : '-') . ' continua sin respuesta.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Han transcurrido ' . EmailTemplate::e((string) $elapsedDays) . ' dias calendario desde el envio de la cotizacion. La comunicacion queda anexada al historial del caso #' . EmailTemplate::e($logicalTicket) . '.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Puedes consultarla desde el siguiente boton. No adjuntamos el archivo para evitar bloqueos o marcaciones de spam.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Cordialmente,<br><b>' . EmailTemplate::e($userName) . '</b><br>SKC SuCasa Inmobiliaria</p>';

    if ($this->queue instanceof EmailQueue && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
      $buttons = [['url' => $noticeUrl, 'label' => 'Ver seguimiento']];
      if ($quoteUrl !== '') {
        $buttons[] = ['url' => $quoteUrl, 'label' => 'Ver cotizacion'];
      }
      $html = EmailTemplate::render('Seguimiento de reparaciones', $content, [
        'buttons' => $buttons,
      ]);
      $emailSent = $this->sendMailToUnique($recipientEmail, $subject, $html, [
        'source_module' => 'seguimiento_reparaciones_cotizacion',
        'provider' => 'email_smtp',
        'destination_name' => $recipientName,
        'dedupe_key' => 'seguimiento_reparaciones_cotizacion:' . $logicalTicket . ':' . $quoteId . ':' . $attempt,
        'meta' => [
          'id_ticket' => $logicalTicket,
          'id_cotizacion' => $quoteId,
          'attempt' => $attempt,
          'notice_url' => $noticeUrl,
        ],
      ]);
    }

    if ($recipientPhone !== '' && $noticeUrl !== '') {
      try {
        $buttonSuffix = $this->whatsappUrlButtonSuffix($noticeUrl);
        $message = "Buen dia, {$recipientName}.\n\n";
        $message .= "Generamos la comunicacion de seguimiento de reparaciones No. {$attempt} porque la cotizacion #{$quoteId} del caso #{$logicalTicket} sigue sin respuesta despues de {$elapsedDays} dias.\n\n";
        $message .= "Puedes consultar el documento en el boton.\n\n";
        $message .= "Enlace directo: {$noticeUrl}\n\n";
        $message .= "Atentamente,\n{$userName}";

        $smsQueue = new \SCM\Support\SmsQueue($this->db);
        $ok = $smsQueue->enqueue($recipientPhone, $recipientName, $message, [
          'source_module' => 'seguimiento_reparaciones_cotizacion',
          'campaign_tag' => 'seguimiento_reparaciones_cotizacion',
          'categoria_mensaje' => 'informacion',
          'id_ticket' => $logicalTicket,
          'id_cotizacion' => $quoteId,
          'attempt' => $attempt,
          'notice_url' => $noticeUrl,
          'button_url_mode' => 'dynamic_suffix',
          'dedupe_key' => 'seguimiento_reparaciones_cotizacion:' . $logicalTicket . ':' . $quoteId . ':' . $attempt,
          'template_name' => 'scm_seguimiento_reparaciones_v1',
          'template_language' => 'es_CO',
          'template_components' => [
            [
              'type' => 'body',
              'parameters' => [
                ['type' => 'text', 'text' => $recipientName],
                ['type' => 'text', 'text' => (string) $attempt],
                ['type' => 'text', 'text' => $quoteId !== '' ? $quoteId : '-'],
                ['type' => 'text', 'text' => $logicalTicket],
                ['type' => 'text', 'text' => (string) $elapsedDays],
                ['type' => 'text', 'text' => $userName],
              ],
            ],
            [
              'type' => 'button',
              'sub_type' => 'url',
              'index' => '0',
              'parameters' => [
                ['type' => 'text', 'text' => $buttonSuffix],
              ],
            ],
          ],
        ]);
        if ($ok) {
          $whatsappSent = 1;
        } else {
          $detail = trim($smsQueue->lastError());
          error_log('control-servicios-inmobiliarios: no se pudo encolar WhatsApp de seguimiento reparaciones #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : ''));
        }
      } catch (\Throwable $exception) {
        error_log('control-servicios-inmobiliarios: error preparando WhatsApp de seguimiento reparaciones #' . $logicalTicket . ': ' . $exception->getMessage());
      }
    }

    return ['email' => $emailSent, 'whatsapp' => $whatsappSent];
  }

  private function whatsappUrlButtonSuffix(string $url): string
  {
    $parts = parse_url($url);
    if (!is_array($parts)) {
      return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    $query = trim((string) ($parts['query'] ?? ''));
    if ($host === 'sucasainmobiliaria.com.co' && $path !== '') {
      return $path . ($query !== '' ? '?' . $query : '');
    }
    return $url;
  }

  /** @param array<string,mixed> $ticket @return string[] */
  private function ticketParticipantEmails(array $ticket, bool $includeEmployee = false): array
  {
    $emails = [
      (string)($ticket['correo_solicitante'] ?? ''),
      (string)($ticket['correo_arrendatario'] ?? ''),
      (string)($ticket['correo_propietario'] ?? ''),
    ];
    if ($includeEmployee) {
      $employeeEmail = (string)($ticket['correo_empleado'] ?? '');
      if ($employeeEmail === '') {
        $func = $this->findFuncionarioByEmployeeId($ticket['id_empleado'] ?? '');
        $employeeEmail = (string)($func['correo'] ?? '');
      }
      $emails[] = $employeeEmail;
    }
    return $this->normalizeEmails($emails);
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @param string[] $exclude @return string[] */
  private function emailsForTargets(array $ticket, array $targets = [], array $exclude = []): array
  {
    return array_map(static function (array $recipient): string {
      return $recipient['email'];
    }, $this->emailRecipientsForTargets($ticket, $targets, $exclude));
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @param string[] $exclude @return array<int,array{email:string,name:string,role:string}> */
  private function emailRecipientsForTargets(array $ticket, array $targets = [], array $exclude = [], string $adminAction = 'gestion_caso_admin'): array
  {
    $targets = $this->normalizeTargets($targets);
    if (in_array('none', $targets, true)) {
      return [];
    }
    if (empty($targets)) {
      $targets = ['solicitante', 'arrendatario', 'propietario', 'empleado', 'admin'];
    }

    $excludeMap = array_fill_keys($exclude, true);
    $recipients = [];
    $add = static function (string $role, string $name, string $email) use (&$recipients, $targets, $excludeMap): void {
      if (!in_array($role, $targets, true) || !empty($excludeMap[$role])) {
        return;
      }
      $email = trim($email);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
      }
      $recipients[] = ['email' => $email, 'name' => trim($name), 'role' => $role];
    };

    $add('solicitante', (string)($ticket['solicitante'] ?? ''), (string)($ticket['correo_solicitante'] ?? ''));
    $add('arrendatario', $this->resolveTicketTenantDisplayName($ticket), (string)($ticket['correo_arrendatario'] ?? ''));
    $add('propietario', (string)($ticket['propietario'] ?? ''), (string)($ticket['correo_propietario'] ?? ''));

    $employeeEmail = (string)($ticket['correo_empleado'] ?? '');
    $employeeName = (string)($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '');
    if ($employeeEmail === '') {
      $func = $this->findFuncionarioByEmployeeId($ticket['id_empleado'] ?? '');
      $employeeEmail = (string)($func['correo'] ?? '');
      if ($employeeName === '') {
        $employeeName = (string)($func['nombre'] ?? '');
      }
    }
    $add('empleado', $employeeName, $employeeEmail);

    if (in_array('admin', $targets, true) && empty($excludeMap['admin'])) {
      foreach ($this->adminNotificationEmails($adminAction) as $adminEmail) {
        $recipients[] = ['email' => $adminEmail, 'name' => 'equipo administrativo', 'role' => 'admin'];
      }
    }

    return $this->uniqueEmailRecipients($recipients);
  }

  /** @param array<int,array{email:string,name:string,role:string}> $recipients @return array<int,array{email:string,name:string,role:string}> */
  private function uniqueEmailRecipients(array $recipients): array
  {
    $seen = [];
    $out = [];
    foreach ($recipients as $recipient) {
      $email = strtolower(trim((string)($recipient['email'] ?? '')));
      if ($email === '' || isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $seen[$email] = true;
      $out[] = [
        'email' => $email,
        'name' => trim((string)($recipient['name'] ?? '')),
        'role' => trim((string)($recipient['role'] ?? '')),
      ];
    }
    return $out;
  }

  /** @param string[] $targets */
  private function targetSelected(array $targets, string $target): bool
  {
    $targets = $this->normalizeTargets($targets);
    if (in_array('none', $targets, true)) {
      return false;
    }
    return empty($targets) || in_array($target, $targets, true);
  }

  /** @param string[] $targets @return string[] */
  private function normalizeTargets(array $targets): array
  {
    $allowed = ['solicitante', 'arrendatario', 'propietario', 'empleado', 'admin', 'none'];
    $out = [];
    foreach ($targets as $target) {
      $target = strtolower(trim((string)$target));
      if (in_array($target, $allowed, true)) {
        $out[$target] = true;
      }
    }
    return array_keys($out);
  }

  /** @return string[] */
  private function adminNotificationEmails(string $action = 'gestion_caso_admin'): array
  {
    return $this->normalizeEmails(InternalNotificationRecipients::emailsForAction($this->db, $action));
  }

  /** @param array<int,mixed> $items @return string[] */
  private function normalizeEmails(array $items): array
  {
    $out = [];
    foreach ($items as $item) {
      $email = trim((string)$item);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $out[strtolower($email)] = $email;
    }
    return array_values($out);
  }

  /** @param array<int,mixed>|string $recipients @param array<string,mixed> $options */
  private function sendMailToUnique($recipients, string $subject, string $html, array $options = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $to = is_array($recipients) ? $this->normalizeEmails($recipients) : $this->normalizeEmails([$recipients]);
    if (empty($to)) {
      $cc = isset($options['cc']) && is_array($options['cc']) ? $this->normalizeEmails($options['cc']) : [];
      if (empty($cc)) {
        return 0;
      }
      $to = $cc;
      $options['cc'] = [];
    } elseif (isset($options['cc']) && is_array($options['cc'])) {
      $toKeys = array_fill_keys(array_map('strtolower', $to), true);
      $options['cc'] = array_values(array_filter($this->normalizeEmails($options['cc']), static function (string $email) use ($toKeys): bool {
        return !isset($toKeys[strtolower($email)]);
      }));
    }

    // Modo cola: encolar para procesamiento asíncrono
    if ($this->queue instanceof EmailQueue) {
      $allEmails = array_merge($to, isset($options['cc']) && is_array($options['cc']) ? $options['cc'] : []);
      $this->queue->enqueue($allEmails, $subject, $html, $options);
      return count($allEmails);
    }

    return 0;
  }

  /** @param array<int,mixed> $values */
  private function firstNonEmpty(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string)$value);
      if ($text !== '') {
        return $text;
      }
    }
    return '';
  }

  /** @param array<string,mixed> $ticket */
  private function resolveTicketTenantDisplayName(array $ticket): string
  {
    $directName = $this->firstUsablePersonName([
      $ticket['_scm_arrendatario_nombre_resuelto'] ?? '',
      $ticket['nombre_arrendatario'] ?? '',
      $ticket['arrendatario_nombre'] ?? '',
      $ticket['arrendatario'] ?? '',
    ]);
    if ($directName !== '') {
      return $directName;
    }

    $tenantIds = [];
    foreach ([
      $ticket['id_arrendatario'] ?? '',
      $ticket['arrendatario'] ?? '',
    ] as $value) {
      $id = trim((string) $value);
      if ($id !== '' && preg_match('/^\d+$/', $id)) {
        $tenantIds[$id] = true;
      }
    }
    $tenantName = $this->findTenantNameByIds(array_keys($tenantIds));
    if ($tenantName !== '') {
      return $tenantName;
    }

    $contract = $this->findTicketContractRow($ticket);
    if (!empty($contract)) {
      $contractName = $this->firstUsablePersonName([
        $contract['nombre_arrendatario'] ?? '',
        $contract['arrendatario'] ?? '',
      ]);
      if ($contractName !== '') {
        return $contractName;
      }
      $contractTenantId = trim((string) ($contract['id_arrendatario'] ?? ''));
      $contractTenantName = $this->findTenantNameByIds($contractTenantId !== '' ? [$contractTenantId] : []);
      if ($contractTenantName !== '') {
        return $contractTenantName;
      }
    }

    $solicitante = $this->firstUsablePersonName([$ticket['solicitante'] ?? '']);
    return $solicitante !== '' ? $solicitante : 'arrendatario(a)';
  }

  /** @param array<int,mixed> $values */
  private function firstUsablePersonName(array $values): string
  {
    foreach ($values as $value) {
      $name = trim((string) $value);
      if ($name === '') {
        continue;
      }
      $normalized = strtolower($name);
      if (preg_match('/^\d+$/', $name) || in_array($normalized, ['0', '-', 'no asignado', 'sin asignar'], true)) {
        continue;
      }
      return $name;
    }
    return '';
  }

  /** @param array<string,mixed> $ticket @return array<string,mixed> */
  private function findTicketContractRow(array $ticket): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $where = [];
    $args = [];
    $addLookup = function (string $column, string $value) use ($table, &$where, &$args): void {
      $value = trim($value);
      if ($value === '' || !$this->schema->columnExists($table, $column)) {
        return;
      }
      $where[] = "TRIM(CAST(`{$column}` AS CHAR)) = ?";
      $args[] = ltrim($value, '#');
    };

    foreach (['id_contrato_arrendamiento', 'id_contrato', 'contrato'] as $ticketColumn) {
      $raw = trim((string) ($ticket[$ticketColumn] ?? ''));
      if ($raw === '') {
        continue;
      }
      $lookup = ltrim($raw, '#');
      $addLookup('_ID', $lookup);
      $addLookup('contrato', $lookup);
      $addLookup('contrato_arrendamiento', $lookup);
    }

    if (empty($where)) {
      return [];
    }

    $columns = [];
    foreach (['_ID', 'contrato', 'contrato_arrendamiento', 'arrendatario', 'nombre_arrendatario', 'id_arrendatario'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $columns[] = $column;
      }
    }
    if (empty($columns)) {
      return [];
    }

    $sql = 'SELECT `' . implode('`, `', array_values(array_unique($columns))) . "` FROM `{$table}` WHERE " . implode(' OR ', $where) . ' LIMIT 1';
    $row = $this->db->getRow($sql, $args);
    return is_array($row) ? $row : [];
  }

  /** @param string[] $ids */
  private function findTenantNameByIds(array $ids): string
  {
    $ids = array_values(array_unique(array_filter(array_map(static function ($id): string {
      return trim((string) $id);
    }, $ids), static function (string $id): bool {
      return $id !== '' && preg_match('/^\d+$/', $id) === 1;
    })));
    if (empty($ids)) {
      return '';
    }

    $table = $this->db->table('jet_cct_arrendatarios');
    if (!$this->schema->tableExists($table)) {
      return '';
    }

    $nameColumns = [];
    foreach (['nombre', 'nombre_juridico', 'arrendatario'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $nameColumns[] = $column;
      }
    }
    if (empty($nameColumns)) {
      return '';
    }

    $where = [];
    $args = [];
    foreach (['_ID', 'id_arrendatario'] as $column) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }
      $where[] = "`{$column}` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
      array_push($args, ...$ids);
    }
    if (empty($where)) {
      return '';
    }

    $identityColumns = [];
    if ($this->schema->columnExists($table, '_ID')) {
      $identityColumns[] = '_ID';
    }
    $columns = array_values(array_unique(array_merge($identityColumns, $nameColumns)));
    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', $columns) . "` FROM `{$table}` WHERE " . implode(' OR ', $where) . ' LIMIT 5',
      $args
    );
    foreach ($rows as $row) {
      $name = $this->firstUsablePersonName(array_map(static function (string $column) use ($row) {
        return $row[$column] ?? '';
      }, $nameColumns));
      if ($name !== '') {
        return $name;
      }
    }

    return '';
  }

  /** @return string[] */
  private function splitIds(string $raw): array
  {
    $text = trim($raw);
    if ($text === '') {
      return [];
    }

    $parts = preg_split('/[,\s;|]+/', $text) ?: [];
    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        $ids[$id] = true;
      }
    }

    return array_keys($ids);
  }
}
