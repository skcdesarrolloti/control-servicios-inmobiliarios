<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait NotificationDeliveryConcern
{
  private function notifyTicketResponse(array $ticket, string $logicalTicket, string $respuesta, string $userName, string $estado, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = $estado === 'Cerrado' ? 'Ticket #' . $logicalTicket . ' cerrado' : 'Ticket #' . $logicalTicket . ' con nueva respuesta';
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets) as $recipient) {
      $html = EmailTemplate::renderNamed('respuesta_ticket', [
        'asunto_correo' => EmailTemplate::e($subject),
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'mensaje_intro' => $estado === 'Cerrado'
          ? 'Te informamos que el ticket ha sido cerrado.'
          : 'Te informamos que el ticket ha tenido una nueva respuesta.',
        'respuesta' => wp_kses_post($respuesta),
        'usuario' => EmailTemplate::e($userName),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver ticket']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html);
    }
    return $sent;
  }

  private function notifyCotizacionResponse(array $ticket, array $cotizacion, string $estado, string $observacion, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $cotId = $this->firstNonEmpty([$cotizacion['_ID'] ?? '', $ticket['id_cotizacion_mantenimiento'] ?? '']);
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $cotUrl = $cotId !== '' ? 'https://sucasainmobiliaria.com.co/cotizacion-de-mantenimiento/?numero=' . rawurlencode($cotId) : '';
    $subject = 'Nueva respuesta de cotizacion de mantenimiento #' . $cotId;
    $motivo = trim((string)($cotizacion['motivo'] ?? ''));
    $recipients = $this->emailRecipientsForTargets($ticket, $notifyTargets);
    if (!in_array('none', $this->normalizeTargets($notifyTargets), true)) {
      foreach (
        [
          (string)($cotizacion['email_destinatario'] ?? ''),
          (string)($cotizacion['email_creador'] ?? ''),
          (string)($cotizacion['email_coordinador'] ?? ''),
        ] as $extraEmail
      ) {
        $extraEmail = trim($extraEmail);
        if ($extraEmail !== '') {
          $recipients[] = ['email' => $extraEmail, 'name' => 'cliente', 'role' => 'cotizacion'];
        }
      }
    }

    $sent = 0;
    foreach ($this->uniqueEmailRecipients($recipients) as $recipient) {
      $html = EmailTemplate::renderNamed('respuesta_cotizacion', [
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
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
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html, [
        'cc' => [],
      ]);
    }

    return $sent;
  }

  private function notifySeguimiento(array $ticket, string $observacion, string $userName, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Nuevo seguimiento del ticket #' . $logicalTicket;
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets) as $recipient) {
      $html = EmailTemplate::renderNamed('nuevo_seguimiento', [
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'id_ticket' => EmailTemplate::e($logicalTicket),
        'observacion' => wp_kses_post($observacion),
        'usuario' => EmailTemplate::e($userName),
        'fecha' => EmailTemplate::e(date('Y-m-d H:i')),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver ticket']]),
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
    $emailSent = 0;
    $whatsappSent = 0;
    $tenantEmail = trim((string)($ticket['correo_arrendatario'] ?? $ticket['correo_solicitante'] ?? ''));
    $tenantName = $this->resolveTicketTenantDisplayName($ticket);
    $noticeUrl = trim((string)($notice['url'] ?? ''));
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Acta de revision preventiva del ticket #' . $logicalTicket;

    $content = '<p style="font-weight:500;margin:10px 0;">Apreciado(a) ' . EmailTemplate::e($tenantName) . ',</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Se ha generado el acta/comunicación preventiva para dejar constancia de la gestión realizada frente a la revisión preventiva del inmueble.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Esta comunicación corresponde al intento No. ' . EmailTemplate::e((string)$attempt) . ' y queda anexada al historial del caso.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Puedes consultarla desde el siguiente botón. No adjuntamos el archivo para evitar bloqueos o marcaciones de spam.</p>'
      . '<p style="line-height:1.65;margin:10px 0;">Cordialmente,<br><b>' . EmailTemplate::e($userName) . '</b></p>';

    if ($this->queue instanceof EmailQueue && $tenantEmail !== '' && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::render('Acta de revision preventiva', $content, [
        'buttons' => [
          ['url' => $noticeUrl, 'label' => 'Ver acta preventiva'],
          ['url' => $ticketUrl, 'label' => 'Ver ticket'],
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
        $message .= "Se generó la comunicación preventiva No. {$attempt} del ticket #{$logicalTicket}, relacionada con la revisión preventiva del inmueble.\n\n";
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
  private function emailRecipientsForTargets(array $ticket, array $targets = [], array $exclude = []): array
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
      foreach ($this->adminNotificationEmails() as $adminEmail) {
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
  private function adminNotificationEmails(): array
  {
    return $this->normalizeEmails([
      'sucasacorreos@gmail.com',
      'gcorrearivera@gmail.com',
      'sucasa.inmobiliaria@hotmail.com',
    ]);
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
      $this->queue->enqueue($allEmails, $subject, $html);
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
