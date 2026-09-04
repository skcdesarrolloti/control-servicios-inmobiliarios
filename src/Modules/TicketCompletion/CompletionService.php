<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\EmailTemplate;

final class CompletionService
{
  private \Closure $enqueue;

  public function __construct(public readonly CompletionRepository $repo, private string $secret, private string $baseUrl, ?\Closure $enqueue = null)
  {
    if (strlen($secret) < 32) {
      throw new \RuntimeException('No hay una clave segura para las actas.');
    }
    $this->enqueue = $enqueue ?? static function (string $to, string $subject, string $html, array $options) use ($repo): int {
      return CompletionDelivery::enqueue($repo->db, $to, $subject, $html, $options);
    };
  }

  public function context(int $ticketId): array
  {
    $this->repo->requireSchema();
    $ticket = $this->repo->ticket($ticketId);
    $contacts = [];
    foreach (CompletionPolicy::ROLES as $role => $label) {
      $contacts[$role] = [
        'name' => trim((string) ($ticket[$role] ?? '')),
        'email' => trim((string) ($ticket['correo_' . $role] ?? '')),
        'phone' => trim((string) ($ticket['celular_' . $role] ?? '')),
        'label' => $label,
      ];
      if ($role === 'copropiedad' && (int) ($ticket['id_copropiedad'] ?? 0) > 0) {
        $table = $this->repo->db->table('jet_cct_copropiedades');
        if ($this->repo->schema->tableExists($table)) {
          $community = $this->repo->db->getRow("SELECT * FROM `{$table}` WHERE _ID = ?", [(int) $ticket['id_copropiedad']]);
          if ($community) {
            $contacts[$role]['name'] = trim((string) ($community['administrador'] ?? '')) ?: $contacts[$role]['name'];
            $contacts[$role]['email'] = $contacts[$role]['email'] ?: trim((string) ($community['correo'] ?? ''));
            $contacts[$role]['phone'] = $contacts[$role]['phone'] ?: trim((string) ($community['contacto'] ?? ''));
          }
        }
      }
      $contacts[$role]['phone'] = CompletionPolicy::phone($contacts[$role]['phone'], (string) ($ticket['indicativo_' . $role] ?? ''));
      $contacts[$role]['available'] = $contacts[$role]['name'] !== '' && $this->verificationChannels($contacts[$role]) !== [];
    }
    $config = [];
    $table = $this->repo->db->table('jet_cct_confi_sistema');
    if ($this->repo->schema->tableExists($table)) {
      foreach ($this->repo->db->getResults("SELECT funcion, valor FROM `{$table}` WHERE funcion IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv','valor_transporte') ORDER BY _ID") as $row) {
        $config[(string) $row['funcion']] = $row['valor'];
      }
    }
    return [
      'ticket' => $ticket,
      'contacts' => $contacts,
      'fee' => CompletionPolicy::fee($config),
      'fee_config' => $config,
      'transport_base' => CompletionPolicy::transportBase($config),
      'transport_max' => CompletionPolicy::transportMaximum($config),
      'acts' => $this->repo->history($ticketId),
    ];
  }

  public function payload(array $act): array
  {
    if (!hash_equals((string) $act['payload_hash'], hash('sha256', (string) $act['payload_json']))) {
      throw new \DomainException('El contenido del acta cambió. No se puede firmar; solicita una nueva acta.');
    }
    if (($act['status'] ?? '') === 'signed') {
      $evidence = json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR);
      $hmac = (string) ($evidence['evidence_hmac'] ?? '');
      unset($evidence['evidence_hmac']);
      if (!hash_equals(hash_hmac('sha256', json_encode($evidence, JSON_THROW_ON_ERROR), $this->secret), $hmac)
        || !hash_equals((string) $act['payload_hash'], (string) ($evidence['document_hash'] ?? ''))
        || (int) ($evidence['signed_at'] ?? 0) !== (int) $act['signed_at']) {
        throw new \DomainException('La evidencia de firma no coincide con el acta. Solicita una revisión al administrador.');
      }
    }
    return json_decode((string) $act['payload_json'], true, 32, JSON_THROW_ON_ERROR);
  }

  public function viewUrl(int $id): string
  {
    return rtrim($this->baseUrl, '/') . '/ticket-acta.php?id=' . $id;
  }

  public function token(array $act): string
  {
    return hash_hmac('sha256', 'ticket-completion|' . $act['id'] . '|' . $act['token_nonce'], $this->secret);
  }

  public function publicAct(int $id, string $token): array
  {
    $this->repo->requireSchema();
    $act = $this->repo->act($id);
    $this->assertPublicAccess($act, $token);
    $this->payload($act);
    return $act;
  }

  private function assertPublicAccess(array $act, string $token): void
  {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($this->token($act), $token)
      || (int) $act['expires_at'] < time() || !in_array($act['status'], ['pending', 'signed'], true)) {
      throw new \DomainException('El enlace no es válido, fue anulado o venció. Solicita un nuevo enlace a la inmobiliaria.');
    }
  }

  public function create(int $ticketId, array $input, array $actor): array
  {
    $this->repo->requireSchema();
    $act = $this->repo->transaction($ticketId, function (array $ticket) use ($ticketId, $input, $actor): array {
      if ($this->repo->active($ticketId)) {
        throw new \DomainException('Este ticket ya tiene un acta activa. Consúltala o anúlala antes de generar otra.');
      }
      if (in_array(mb_strtolower(trim((string) $ticket['estado'])), ['cerrado', 'finalizado', 'resuelto'], true)) {
        throw new \DomainException('No se puede crear un acta en un caso cerrado.');
      }
      $context = $this->context($ticketId);
      $role = CompletionPolicy::text($input['signer_role'] ?? '', 'quién firma', 25);
      $executor = CompletionPolicy::text($input['executor'] ?? '', 'quién realizó la solución', 25);
      if (!isset(CompletionPolicy::ROLES[$role])) {
        throw new \DomainException('Selecciona propietario, arrendatario o copropiedad.');
      }
      if (!isset(CompletionPolicy::EXECUTORS[$executor])) {
        throw new \DomainException('Selecciona quién realizó la solución.');
      }
      $pendingAdminState = CompletionPolicy::executionState($executor);
      $contact = $context['contacts'][$role];
      if (!$contact['available']) {
        throw new \DomainException('El firmante necesita nombre y un contacto válido para verificación: correo o WhatsApp con plantilla de autenticación configurada.');
      }
      $channels = $input['channels'] ?? ['email'];
      if ($channels === ['both']) { $channels = ['email', 'whatsapp']; }
      if (!is_array($channels) || !$channels || array_diff($channels, ['email', 'whatsapp'])) { throw new \DomainException('Selecciona correo, WhatsApp o ambos.'); }
      $channels = array_values(array_unique($channels));
      foreach ($channels as $channel) {
        if (($channel === 'email' && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) || ($channel === 'whatsapp' && $contact['phone'] === '')) {
          throw new \DomainException('Falta el contacto registrado para el canal seleccionado: ' . $channel . '.');
        }
      }
      $signerName = CompletionPolicy::text($input['signer_name'] ?? '', 'nombre de quien firma', 160);
      $items = CompletionPolicy::items($input['items'] ?? null);
      $observations = CompletionPolicy::text($input['observations'] ?? '', 'observaciones');
      $transportMax = $context['transport_max'];
      $transport = $transportMax ?? 0;
      $fee = $context['fee'];
      if ($fee === null) {
        $fee = (int) round(CompletionPolicy::number($input['service_fee'] ?? ''));
      }
      if ($fee <= 0) {
        throw new \DomainException('No hay una tarifa de servicio válida. Revisa la configuración o ingresa el valor administrativo.');
      }
      if ($fee + $transport > 999999999) {
        throw new \DomainException('El total administrativo supera el máximo permitido.');
      }
      if (($input['confirm'] ?? '') !== '1') {
        throw new \DomainException('Confirma que revisaste el acta y el valor del reporte administrativo.');
      }
      $now = time();
      $payload = [
        'version' => 2, 'channels' => $channels, 'ticket_pk' => $ticketId, 'ticket_number' => (string) ($ticket['id_ticket'] ?: $ticketId),
        'property' => (string) ($ticket['inmueble'] ?? ''), 'address' => (string) ($ticket['direccion'] ?? ''),
        'contract' => (string) ($ticket['contrato'] ?? ''), 'executor' => $executor, 'pending_admin_state' => $pendingAdminState,
        'items' => $items, 'observations' => $observations, 'created_at' => $now,
        'signer' => ['role' => $role, 'name' => $signerName, 'contact_name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']],
        'actor' => $actor,
        'owner_id' => (string) ($ticket['id_propietario'] ?? ''), 'tenant_id' => (string) ($ticket['id_arrendatario'] ?? ''),
        'previous' => ['estado_administrativo' => (string) ($ticket['estado_administrativo'] ?? ''), 'id_acta_satisfaccion' => (string) ($ticket['id_acta_satisfaccion'] ?? ''), 'estado_acta_satisfaccion' => (string) ($ticket['estado_acta_satisfaccion'] ?? 'No')],
        'report' => [
          'service_fee' => $fee, 'transport' => $transport, 'total' => $fee + $transport,
          'transport_base' => $context['transport_base'], 'transport_max' => $transportMax,
          'fee_source' => $context['fee'] === null ? 'manual' : 'configuracion', 'fee_config' => $context['fee_config'],
          'id_inmueble' => (string) ($ticket['id_inmueble'] ?? ''), 'id_contrato' => (string) ($ticket['id_contrato'] ?? ''),
          'sucursal' => (string) ($ticket['sucursal'] ?? '1'), 'arrendatario' => (string) ($ticket['arrendatario'] ?? ''),
          'fecha_ticket' => (string) ($ticket['fecha'] ?? ''),
        ],
      ];
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $this->repo->db->insert($this->repo->table(), [
        'ticket_pk' => $ticketId, 'payload_json' => $json,
        'payload_hash' => hash('sha256', $json), 'token_nonce' => bin2hex(random_bytes(32)),
        'created_at' => $now, 'expires_at' => $now + 30 * 86400,
      ]);
      $id = (int) $this->repo->db->lastInsertId();
      // Legacy timelines treat any act row as completion; publish the CCT record only on signature.
      $this->repo->updateTicket($ticketId, ['estado' => 'En proceso', 'estado_administrativo' => $pendingAdminState]);
      $this->repo->audit($ticketId, 'Acta de satisfacción #' . $id . ' generada. Solución por ' . CompletionPolicy::EXECUTORS[$executor] . '. Pendiente de firma de ' . htmlspecialchars($signerName, ENT_QUOTES, 'UTF-8') . '. <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta</a>. El caso permanece abierto en “' . $pendingAdminState . '” y el reporte aún no se cobra.', $actor['name'], $actor['employee_id']);
      return $this->repo->act($id);
    });
    try { return $this->notify($act); }
    catch (\Throwable) { return ['act_id' => (int) $act['id'], 'queued' => false, 'message' => 'Acta guardada. No se pudo confirmar el envío; recarga y usa Reenviar invitación. El ticket sigue abierto.']; }
  }

  public function resend(int $id): array
  {
    $act = $this->repo->act($id);
    $act = $this->repo->transaction((int) $act['ticket_pk'], function () use ($id): array {
      $act = $this->repo->act($id);
      if (!in_array($act['status'], ['pending', 'signed'], true)) {
        throw new \DomainException('No se puede reenviar un acta anulada.');
      }
      if ((int) $act['invitation_queued_at'] > time() - 60) {
        throw new \DomainException('Espera un minuto antes de reenviar el acta.');
      }
      // Rotate only expired links, so a delayed email or a retry does not invalidate a valid invitation.
      if ((int) $act['expires_at'] < time()) {
        $this->repo->db->update($this->repo->table(), ['token_nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 30 * 86400, 'invitation_queued_at' => null, 'otp_json' => null], ['id' => $id]);
      }
      return $this->repo->act($id);
    });
    return $this->notify($act, $act['status'] === 'signed', true);
  }

  private function notify(array $act, bool $receipt = false, bool $resend = false): array
  {
    return CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($act, $receipt, $resend): array {
    $act = $this->repo->act((int) $act['id']);
    if ($act['status'] !== ($receipt ? 'signed' : 'pending')) { return ['queued' => false, 'message' => 'El estado del acta cambió. Recarga el caso.']; }
    $payload = $this->payload($act);
    $url = $this->viewUrl((int) $act['id']) . '&token=' . $this->token($act);
    $title = ($receipt ? 'Acta firmada' : 'Acta de satisfacción') . ' del caso #' . $payload['ticket_number'];
    $description = $receipt ? 'Tu firma quedó registrada y el caso se cerró. Puedes consultar y descargar tu PDF firmado.' : 'Revisa los daños, soluciones, observaciones y evidencias. Solo firma si estás conforme: tu firma cerrará el caso. Necesitarás un código enviado a tu contacto registrado.';
    $linkLabel = $receipt ? 'Descargar PDF firmado' : 'Revisar y firmar acta';
    $target = $receipt ? $url . '&format=pdf' : $url;
    $body = '<p>Hola ' . EmailTemplate::e($payload['signer']['name']) . '.</p><p>' . $description . '</p><p><a href="' . EmailTemplate::e($target) . '">' . $linkLabel . '</a></p><p>Enlace personal válido hasta ' . date('d/m/Y', (int) $act['expires_at']) . '. No lo compartas.</p>';
    $delivery = json_decode((string) ($act['delivery_json'] ?? ''), true) ?: [];
    $event = $receipt ? 'signed_receipt' : 'signature_invitation';
    $channels = $payload['channels'] ?? ['email'];
    $previousComplete = true;
    foreach ($channels as $channel) { $previousComplete = $previousComplete && !empty($delivery[$event][$channel]['queued']); }
    $freshSend = $resend && ($previousComplete || ($delivery[$event]['token_nonce'] ?? '') !== $act['token_nonce']);
    $generation = (int) ($delivery[$event]['generation'] ?? 0) + ($freshSend ? 1 : 0);
    if ($freshSend) { $delivery[$event] = []; }
    $delivery[$event]['generation'] = $generation;
    $delivery[$event]['token_nonce'] = $act['token_nonce'];
    $results = [];
    foreach ($channels as $channel) {
      if (!empty($delivery[$event][$channel]['queued'])) { $results[$channel] = true; continue; }
      $results[$channel] = $this->send($act, $payload, $channel, $event, $generation . ':' . $act['token_nonce'], $title, $body, $description . ' ' . $linkLabel . ': ' . $target, '', $target);
      $delivery[$event][$channel] = ['queued' => $results[$channel], 'attempted_at' => time()];
      // Save each channel independently; a retry cannot duplicate a successful sibling channel.
      $this->repo->db->update($this->repo->table(), ['delivery_json' => json_encode($delivery, JSON_THROW_ON_ERROR)], ['id' => (int) $act['id']]);
    }
    $this->repo->db->update($this->repo->table(), ['invitation_queued_at' => time()], ['id' => (int) $act['id']]);
    $all = !in_array(false, $results, true);
    return ['act_id' => (int) $act['id'], 'queued' => $all, 'channels' => $results, 'message' => ($receipt ? 'Acta firmada y caso cerrado. ' : 'Acta guardada; el caso sigue abierto hasta la firma. ') . ($all ? 'Mensajes en cola (no confirma entrega).' : 'No se pudieron encolar todos los canales. Revisa el detalle y reintenta.')];
    });
  }

  public function verificationChannels(array $signer): array
  {
    $out = [];
    if (filter_var($signer['email'] ?? '', FILTER_VALIDATE_EMAIL)) { $out['email'] = 'Correo: ' . preg_replace('/^(.).+(@.*)$/u', '$1***$2', $signer['email']); }
    if (CompletionDelivery::otpTemplate() !== '' && CompletionPolicy::phone((string) ($signer['phone'] ?? '')) !== '') { $out['whatsapp'] = 'WhatsApp: ***' . substr($signer['phone'], -4); }
    return $out;
  }

  public function requestCode(int $id, string $token, string $channel): array
  {
    $act = $this->publicAct($id, $token);
    return CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($id, $token, $channel): array {
      $act = $this->publicAct($id, $token);
      if ($act['status'] !== 'pending') { throw new \DomainException('Esta acta ya no está pendiente de firma.'); }
      $payload = $this->payload($act);
      if (!isset($this->verificationChannels($payload['signer'])[$channel])) { throw new \DomainException('Canal de verificación no disponible. Usa el correo registrado o contacta a la inmobiliaria.'); }
      return (new CompletionVerification($this->repo, $this->secret))->request($act, $channel, function (string $code, string $nonce) use ($act, $payload, $channel): bool {
        $text = 'Tu código para firmar el acta #' . $act['id'] . ' del caso #' . $payload['ticket_number'] . ' es ' . $code . '. Vence en 10 minutos. No lo compartas. Si no lo solicitaste, ignora este mensaje.';
        return $this->send($act, $payload, $channel, 'signature_otp', $nonce, 'Código de firma del acta #' . $act['id'], '<p>' . EmailTemplate::e($text) . '</p>', $text, $code);
      });
    });
  }

  private function send(array $act, array $payload, string $channel, string $event, string $key, string $subject, string $body, string $text, string $code = '', string $actUrl = ''): bool
  {
    try {
      return ($this->enqueue)($channel === 'email' ? $payload['signer']['email'] : $payload['signer']['phone'], $subject, EmailTemplate::render($subject, $body), [
        'channel' => $channel, 'source_module' => 'ticket-completion', 'provider' => $channel === 'email' ? 'email_smtp' : 'whatsapp_official',
        'destination_name' => $payload['signer']['name'], 'message_text' => $text, 'otp_code' => $code,
        'ticket_number' => $payload['ticket_number'], 'act_url' => $actUrl,
        'priority' => $event === 'signature_otp' ? 200 : 100,
        'dedupe_key' => 'ticket-acta:' . $act['id'] . ':' . $event . ':' . $channel . ':' . $key,
        'meta' => ['ticket_pk' => (int) $act['ticket_pk'], 'act_id' => (int) $act['id'], 'event' => $event],
      ]) > 0;
    } catch (\Throwable) {
      error_log('[ticket-completion] No se pudo encolar ' . $event . ' por ' . $channel . ' del acta #' . $act['id']);
      return false;
    }
  }

  public function cancel(int $id, string $reason, array $actor): void
  {
    $this->retirePending($id, $reason, $actor, 'cancelled', 'anulación', 'anulada');
  }

  public function archive(int $id, string $reason, array $actor): void
  {
    $this->retirePending($id, $reason, $actor, 'archived', 'archivo', 'archivada');
  }

  public function deleteRetired(int $id, array $actor, bool $allowAny = false): void
  {
    $photos = [];
    $act = $this->repo->act($id);
    $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $actor, $allowAny, &$photos): void {
      $act = $this->repo->act($id);
      $status = (string) $act['status'];
      $allowedStatuses = $allowAny ? ['pending', 'signed', 'archived', 'cancelled'] : ['archived', 'cancelled'];
      if (!in_array($status, $allowedStatuses, true)) {
        throw new \DomainException($status === 'pending'
          ? 'Solo un cargo administrativo puede eliminar actas pendientes. También puedes archivarla para sacarla de pendientes sin borrarla.'
          : 'Solo se pueden eliminar actas archivadas o anuladas. Las actas firmadas se conservan como soporte de cierre y cobro.');
      }
      try {
        $payload = $this->payload($act);
        foreach ($payload['items'] ?? [] as $item) {
          foreach (($item['photos'] ?? []) as $photo) {
            if (is_array($photo)) {
              $photos[] = $photo;
            }
          }
        }
      } catch (\Throwable) {
        $photos = [];
      }
      $legacyId = (int) ($act['legacy_act_id'] ?? 0);
      $reportId = (int) ($act['report_id'] ?? 0);
      if ($legacyId > 0) {
        $this->repo->db->delete($this->repo->db->table('jet_cct_actas_de_satisfaccion'), ['_ID' => $legacyId]);
      }
      if ($reportId > 0) {
        $this->repo->db->delete($this->repo->db->table('jet_cct_reportes_administrativos'), ['_ID' => $reportId]);
      }
      if ($this->repo->db->delete($this->repo->table(), ['id' => $id]) !== 1) {
        throw new \RuntimeException('No se pudo eliminar el acta.');
      }
      if ($status === 'pending' || $status === 'signed') {
        $previousAdmin = trim((string) ($payload['previous']['estado_administrativo'] ?? ''));
        $ticketUpdate = [
          'estado' => 'En proceso',
          'estado_administrativo' => $previousAdmin,
          'id_acta_satisfaccion' => trim((string) ($payload['previous']['id_acta_satisfaccion'] ?? '')),
          'estado_acta_satisfaccion' => trim((string) ($payload['previous']['estado_acta_satisfaccion'] ?? 'No')) ?: 'No',
          'final_trabajo' => '',
        ];
        $this->repo->updateTicket((int) $ticket['_ID'], $ticketUpdate);
        $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' eliminada permanentemente por cargo administrativo. El ticket volvió al estado anterior; se retiraron los soportes internos asociados y no queda cobro de esta acta.', $actor['name'], $actor['employee_id']);
      } else {
        $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' eliminada permanentemente del tablero de actas. No cerró el ticket ni generó cobro.', $actor['name'], $actor['employee_id']);
      }
    });
    if ($photos !== []) {
      \SCM\Support\StoredFileService::fromRuntime()->deleteStoredImages($photos);
    }
  }

  private function retirePending(int $id, string $reason, array $actor, string $status, string $actionName, string $statusLabel): void
  {
    $reason = CompletionPolicy::text($reason, 'motivo de ' . $actionName, 1000);
    $act = $this->repo->act($id);
    $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $reason, $actor, $status, $statusLabel): void {
      $act = $this->repo->act($id);
      if ($act['status'] !== 'pending') {
        throw new \DomainException('Solo se puede archivar o anular un acta pendiente de firma.');
      }
      $payload = $this->payload($act);
      $this->repo->db->update($this->repo->table(), ['status' => $status, 'active_slot' => null, 'cancelled_at' => time(), 'cancellation_reason' => $reason], ['id' => $id]);
      $this->repo->updateTicket((int) $ticket['_ID'], ['estado' => 'En proceso', 'estado_administrativo' => $payload['previous']['estado_administrativo']]);
      $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' ' . $statusLabel . ' sin cerrar el ticket ni generar cobro. Motivo: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'), $actor['name'], $actor['employee_id']);
    });
  }

  public function sign(int $id, string $token, array $input, string $ip, string $userAgent): array
  {
    $act = $this->publicAct($id, $token);
    $signed = CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($id, $token, $input, $ip, $userAgent): array {
      $act = $this->publicAct($id, $token);
      if ($act['status'] === 'signed') { return $act; }
      $payload = $this->payload($act);
      CompletionPolicy::signature($input, $payload['signer']['name']);
      if (($input['consent_version'] ?? '') !== '3') { throw new \DomainException('Recarga y acepta la versión actual del formulario de firma.'); }
      $verification = (new CompletionVerification($this->repo, $this->secret))->verify($act, $input['otp_code'] ?? '');
      return $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $token, $input, $ip, $userAgent, $verification): array {
      $act = $this->repo->act($id);
      $this->assertPublicAccess($act, $token);
      $payload = $this->payload($act);
      if ($act['status'] === 'signed') {
        return $act; // Repeated submits cannot duplicate the report or audit trail.
      }
      // Acts created before execution states were adopted remain signable while
      // their ticket still carries the former waiting value.
      $expectedAdminState = (string) ($payload['pending_admin_state'] ?? CompletionPolicy::WAITING);
      if ($act['status'] !== 'pending' || (int) $act['active_slot'] !== 1 || ($ticket['estado_administrativo'] ?? '') !== $expectedAdminState || strcasecmp((string) $ticket['estado'], 'Cerrado') === 0) {
        throw new \DomainException('El estado del caso cambió. Contacta a la inmobiliaria antes de firmar.');
      }
      if (!hash_equals((string) $act['payload_hash'], (string) ($input['document_hash'] ?? ''))) {
        throw new \DomainException('La versión del acta no coincide. Recarga y revisa nuevamente el documento.');
      }
      $signature = CompletionPolicy::signature($input, $payload['signer']['name']);
      $signature['verification'] = $verification;
      $signature['signed_at'] = time();
      $signature['ip'] = substr($ip, 0, 45);
      $signature['user_agent'] = substr($userAgent, 0, 512);
      $signature['document_hash'] = $act['payload_hash'];
      $signature['token_fingerprint'] = hash('sha256', $token);
      $signature['evidence_hmac'] = hash_hmac('sha256', json_encode($signature, JSON_THROW_ON_ERROR), $this->secret);
      $report = $payload['report'];
      $now = $signature['signed_at'];
      $reportId = $this->repo->insertLegacy('jet_cct_reportes_administrativos', [
        'cct_status' => 'publish', 'cct_author_id' => $payload['actor']['employee_id'],
        'cct_created' => date('Y-m-d H:i:s'), 'cct_modified' => date('Y-m-d H:i:s'),
        'fecha' => $now, 'fecha_revision' => $payload['created_at'], 'fecha_ticket' => $report['fecha_ticket'],
        'id_ticket' => (int) $act['ticket_pk'], 'id_empleado' => $payload['actor']['employee_id'], 'creador' => $payload['actor']['name'],
        'categoria' => 'Acta de satisfaccion',
        'descripcion' => 'Acta de satisfacción #' . $id . ' firmada del caso #' . htmlspecialchars($payload['ticket_number'], ENT_QUOTES, 'UTF-8') . '. Solución por ' . CompletionPolicy::EXECUTORS[$payload['executor']] . '. <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta y detalle de daños/soluciones</a>.',
        'fue_pagado' => 'No', 'exportado' => 'No', 'valor' => $report['total'], 'transporte' => $report['transport'],
        'valor_revision' => $report['service_fee'], 'valor_mantenimiento' => $report['service_fee'],
        'id_inmueble' => $report['id_inmueble'], 'inmueble' => $payload['property'], 'arrendatario' => $report['arrendatario'],
        'id_contrato' => $report['id_contrato'], 'contrato' => $payload['contract'], 'sucursal' => $report['sucursal'],
      ]);
      $legacyId = $this->repo->insertLegacy('jet_cct_actas_de_satisfaccion', [
        'cct_status' => 'publish', 'id_ticket' => (int) $act['ticket_pk'], 'fecha' => $payload['created_at'],
        'id_empleado' => $payload['actor']['employee_id'], 'cct_author_id' => $payload['actor']['employee_id'], 'creador' => $payload['actor']['name'],
        'cct_created' => date('Y-m-d H:i:s'), 'direccion' => $payload['address'],
        'id_inmueble' => $report['id_inmueble'], 'inmueble' => $payload['property'],
        'id_contrato' => $report['id_contrato'], 'contrato' => $payload['contract'], 'sucursal' => $report['sucursal'],
        'id_propietario' => $payload['owner_id'], 'id_arrendatario' => $payload['tenant_id'],
        'quien_firma' => CompletionPolicy::ROLES[$payload['signer']['role']], 'destinatario' => $payload['signer']['name'],
        'email_destinatario' => $payload['signer']['email'], 'celular_destinatario' => $payload['signer']['phone'],
        'fecha_satisfaccion' => $now, 'cct_modified' => date('Y-m-d H:i:s'),
        'observaciones' => self::legacyText($payload) . "\n\nActa firmada por " . $signature['name'] . ' el ' . date('d/m/Y H:i:s') . '. Registro verificable: ' . $this->viewUrl($id),
      ]);
      $this->repo->db->update($this->repo->table(), [
        'status' => 'signed', 'signed_at' => $now, 'signed_json' => json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'report_id' => $reportId, 'legacy_act_id' => $legacyId, 'otp_json' => null, 'expires_at' => $now + 30 * 86400,
      ], ['id' => $id]);
      $pdf = (new CompletionPdf())->render($this->repo->act($id), $payload);
      $pdfHash = hash('sha256', $pdf);
      $this->repo->db->update($this->repo->table(), ['signed_pdf' => $pdf, 'pdf_hash' => $pdfHash, 'pdf_hmac' => hash_hmac('sha256', $id . '|' . $act['payload_hash'] . '|' . $pdfHash, $this->secret)], ['id' => $id]);
      $disapprovedQuoteIds = $this->repo->disapproveMaintenanceQuotes($ticket, $id, $now);
      $ticketUpdate = [
        'estado' => 'Cerrado', 'estado_administrativo' => 'Finalizado', 'estado_acta_satisfaccion' => 'Si',
        'id_acta_satisfaccion' => $legacyId, 'final_trabajo' => $now,
      ];
      if ($disapprovedQuoteIds !== []) {
        $ticketUpdate['estado_cotizacion_mantenimiento'] = 'Desaprobada';
        $ticketUpdate['estado_respuesta_cotizacion_mantenimiento'] = 'Desaprobada';
        $ticketUpdate['fecha_respuesta_cotizacion_mantenimiento'] = $now;
      }
      $this->repo->updateTicket((int) $act['ticket_pk'], $ticketUpdate);
      $quoteAudit = $disapprovedQuoteIds === []
        ? ''
        : ' Cotizacion(es) de mantenimiento #' . implode(', #', $disapprovedQuoteIds) . ' marcadas como Desaprobada por ejecucion sin aprobacion.';
      $this->repo->audit((int) $act['ticket_pk'], 'Acta #' . $id . ' firmada por ' . htmlspecialchars($signature['name'], ENT_QUOTES, 'UTF-8') . '. Caso cerrado.' . $quoteAudit . ' Reporte administrativo #' . $reportId . ' registrado (no pagado, no exportado). <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta firmada</a>.', $payload['actor']['name'], $payload['actor']['employee_id']);
      return $this->repo->act($id);
      });
    });
    // Delivery failure cannot undo a valid signature. Staff can retry the receipt without a second charge.
    try { $signed['receipt'] = $this->notify($signed, true); }
    catch (\Throwable) { $signed['receipt'] = ['queued' => false, 'message' => 'Acta firmada y caso cerrado; no se pudo encolar la copia. Solicita su reenvío a la inmobiliaria.']; }
    return $signed;
  }

  public function pdf(array $act, bool $staff = false): string
  {
    $payload = $this->payload($act);
    if ($act['status'] === 'signed' && empty($act['signed_pdf']) && in_array((string) (json_decode($act['signed_json'], true)['consent_version'] ?? ''), ['2', '3'], true)) {
      throw new \DomainException('No se encuentra el PDF original firmado. Solicita revisión al administrador.');
    }
    if ($act['status'] === 'signed' && !empty($act['signed_pdf'])) {
      $hash = hash('sha256', $act['signed_pdf']);
      if (!hash_equals((string) $act['pdf_hash'], $hash) || !hash_equals((string) $act['pdf_hmac'], hash_hmac('sha256', $act['id'] . '|' . $act['payload_hash'] . '|' . $hash, $this->secret))) { throw new \DomainException('El PDF no coincide con su registro. Solicita revisión al administrador.'); }
      if (!$staff) { return $act['signed_pdf']; }
    }
    return (new CompletionPdf())->render($act, $payload, $staff);
  }

  /** @return array{filters:array<string,string|int>,items:array<int,array<string,mixed>>,stats:array<string,int>,count:int,pagination:array<string,int>} */
  public function dashboardList(array $input): array
  {
    $this->repo->requireSchema();
    $clean = static fn(mixed $value): string => trim(strip_tags((string) (is_scalar($value) ? $value : '')));
    $status = strtolower($clean($input['sacta_estado'] ?? 'pending'));
    $statusMap = ['pending' => 'pending', 'pendiente' => 'pending', 'sin_firmar' => 'pending', 'firmada' => 'signed', 'signed' => 'signed', 'archivada' => 'archived', 'archived' => 'archived', 'anulada' => 'cancelled', 'cancelled' => 'cancelled', 'todos' => 'all', 'all' => 'all'];
    $status = $statusMap[$status] ?? 'pending';
    $page = max(1, (int) ($input['sacta_page'] ?? 1));
    $perPage = min(100, max(10, (int) ($input['sacta_per_page'] ?? 30)));
    $filters = [
      'estado' => $status,
      'caso' => $clean($input['sacta_caso'] ?? ''),
      'inmueble' => $clean($input['sacta_inmueble'] ?? ''),
      'contrato' => $clean($input['sacta_contrato'] ?? ''),
      'firmante' => $clean($input['sacta_firmante'] ?? ''),
      'page' => $page,
      'per_page' => $perPage,
    ];

    $where = [];
    $args = [];
    if ($status !== 'all') {
      $where[] = 'a.status = ?';
      $args[] = $status;
    }
    $likeJson = static fn(string $path): string => "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '{$path}')), '')))";
    foreach ([
      'caso' => ["LOWER(TRIM(COALESCE(t.id_ticket, '')))", $likeJson('$.ticket_number'), "CAST(a.ticket_pk AS CHAR)"],
      'inmueble' => ["LOWER(TRIM(COALESCE(t.inmueble, '')))", $likeJson('$.property')],
      'contrato' => ["LOWER(TRIM(COALESCE(t.contrato, '')))", $likeJson('$.contract')],
      'firmante' => [$likeJson('$.signer.name'), $likeJson('$.signer.email'), $likeJson('$.signer.phone')],
    ] as $key => $columns) {
      if ($filters[$key] === '') {
        continue;
      }
      $needle = '%' . mb_strtolower($this->repo->db->escapeLike((string) $filters[$key]), 'UTF-8') . '%';
      $where[] = '(' . implode(' OR ', array_map(static fn(string $expr): string => $expr . ' LIKE ?', $columns)) . ')';
      foreach ($columns as $_) {
        $args[] = $needle;
      }
    }

    $table = $this->repo->table();
    $tickets = $this->repo->db->table('jet_cct_tickets');
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = (int) $this->repo->db->getVar("SELECT COUNT(*) FROM `{$table}` a LEFT JOIN `{$tickets}` t ON t._ID = a.ticket_pk{$whereSql}", $args);
    $totalPages = max(1, (int) ceil($count / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = $this->repo->db->getResults(
      "SELECT a.*, t.id_ticket AS ticket_display, t.inmueble AS ticket_inmueble, t.contrato AS ticket_contrato, t.direccion AS ticket_address, t.estado AS ticket_estado, t.estado_administrativo AS ticket_estado_admin
       FROM `{$table}` a
       LEFT JOIN `{$tickets}` t ON t._ID = a.ticket_pk{$whereSql}
       ORDER BY CASE a.status WHEN 'pending' THEN 0 WHEN 'signed' THEN 1 WHEN 'archived' THEN 2 ELSE 3 END, COALESCE(a.signed_at, a.created_at) DESC, a.id DESC
       LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );
    $items = [];
    foreach ($rows as $row) {
      try {
        $row['_payload'] = $this->payload($row);
        $items[] = $row;
      } catch (\Throwable) {
        $row['_payload'] = [];
        $row['_invalid_payload'] = true;
        $items[] = $row;
      }
    }
    $statsRows = $this->repo->db->getResults("SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status");
    $stats = ['pending' => 0, 'signed' => 0, 'archived' => 0, 'cancelled' => 0, 'all' => 0];
    foreach ($statsRows as $row) {
      $key = (string) ($row['status'] ?? '');
      if (isset($stats[$key])) {
        $stats[$key] = (int) ($row['total'] ?? 0);
        $stats['all'] += $stats[$key];
      }
    }

    $filters['page'] = $page;
    return ['filters' => $filters, 'items' => $items, 'stats' => $stats, 'count' => $count, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $count, 'total_pages' => $totalPages]];
  }

  private static function legacyText(array $payload): string
  {
    $lines = ['Solución realizada por: ' . CompletionPolicy::EXECUTORS[$payload['executor']]];
    foreach ($payload['items'] as $index => $item) {
      $lines[] = ($index + 1) . '. Daño: ' . $item['damage'] . "\nSolución: " . $item['solution'];
    }
    $lines[] = 'Observaciones: ' . $payload['observations'];
    return implode("\n\n", $lines);
  }
}
