<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\EmailQueue;
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
      return (new EmailQueue($repo->db))->enqueue($to, $subject, $html, $options);
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
      $contacts[$role]['available'] = $contacts[$role]['name'] !== '' && (bool) filter_var($contacts[$role]['email'], FILTER_VALIDATE_EMAIL);
    }
    $config = [];
    $table = $this->repo->db->table('jet_cct_confi_sistema');
    if ($this->repo->schema->tableExists($table)) {
      foreach ($this->repo->db->getResults("SELECT funcion, valor FROM `{$table}` WHERE funcion IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv') ORDER BY _ID") as $row) {
        $config[(string) $row['funcion']] = $row['valor'];
      }
    }
    return ['ticket' => $ticket, 'contacts' => $contacts, 'fee' => CompletionPolicy::fee($config), 'fee_config' => $config, 'acts' => $this->repo->history($ticketId)];
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
      || (int) $act['expires_at'] < time() || $act['status'] === 'cancelled') {
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
        throw new \DomainException('No se puede crear un acta en un ticket cerrado.');
      }
      $context = $this->context($ticketId);
      $role = CompletionPolicy::text($input['signer_role'] ?? '', 'quién firma', 25);
      $executor = CompletionPolicy::text($input['executor'] ?? '', 'quién realizó la solución', 25);
      if (!isset(CompletionPolicy::ROLES[$role], CompletionPolicy::ROLES[$executor])) {
        throw new \DomainException('Selecciona propietario, arrendatario o copropiedad.');
      }
      $contact = $context['contacts'][$role];
      if (!$contact['available']) {
        throw new \DomainException('El firmante no tiene nombre y correo válidos en el ticket. Actualiza sus contactos primero.');
      }
      $signerName = CompletionPolicy::text($input['signer_name'] ?? '', 'nombre de quien firma', 160);
      $items = CompletionPolicy::items($input['items'] ?? null);
      $observations = CompletionPolicy::text($input['observations'] ?? '', 'observaciones');
      $transport = (int) round(CompletionPolicy::number($input['transport'] ?? '0'));
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
        'version' => 1, 'ticket_pk' => $ticketId, 'ticket_number' => (string) ($ticket['id_ticket'] ?: $ticketId),
        'property' => (string) ($ticket['inmueble'] ?? ''), 'address' => (string) ($ticket['direccion'] ?? ''),
        'contract' => (string) ($ticket['contrato'] ?? ''), 'executor' => $executor,
        'items' => $items, 'observations' => $observations, 'created_at' => $now,
        'signer' => ['role' => $role, 'name' => $signerName, 'contact_name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']],
        'actor' => $actor,
        'owner_id' => (string) ($ticket['id_propietario'] ?? ''), 'tenant_id' => (string) ($ticket['id_arrendatario'] ?? ''),
        'previous' => ['estado_administrativo' => (string) ($ticket['estado_administrativo'] ?? ''), 'id_acta_satisfaccion' => (string) ($ticket['id_acta_satisfaccion'] ?? ''), 'estado_acta_satisfaccion' => (string) ($ticket['estado_acta_satisfaccion'] ?? 'No')],
        'report' => [
          'service_fee' => $fee, 'transport' => $transport, 'total' => $fee + $transport,
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
      $this->repo->updateTicket($ticketId, ['estado' => 'En proceso', 'estado_administrativo' => CompletionPolicy::WAITING]);
      $this->repo->audit($ticketId, 'Acta de satisfacción #' . $id . ' generada. Solución por ' . CompletionPolicy::ROLES[$executor] . '. Pendiente de firma de ' . htmlspecialchars($signerName, ENT_QUOTES, 'UTF-8') . '. <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta</a>. El ticket permanece abierto y el reporte aún no se cobra.', $actor['name'], $actor['employee_id']);
      return $this->repo->act($id);
    });
    return $this->notify($act);
  }

  public function resend(int $id): array
  {
    $act = $this->repo->act($id);
    $act = $this->repo->transaction((int) $act['ticket_pk'], function () use ($id): array {
      $act = $this->repo->act($id);
      if ($act['status'] !== 'pending') {
        throw new \DomainException('Solo se pueden reenviar actas pendientes de firma.');
      }
      if ((int) $act['invitation_queued_at'] > time() - 60) {
        throw new \DomainException('El correo ya se encoló. Espera un minuto antes de reenviarlo.');
      }
      // Rotate only expired links, so a delayed email or a retry does not invalidate a valid invitation.
      if ((int) $act['expires_at'] < time()) {
        $this->repo->db->update($this->repo->table(), ['token_nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 30 * 86400, 'invitation_queued_at' => null], ['id' => $id]);
      }
      return $this->repo->act($id);
    });
    return $this->notify($act);
  }

  private function notify(array $act): array
  {
    $payload = $this->payload($act);
    $url = $this->viewUrl((int) $act['id']) . '&token=' . $this->token($act);
    $title = 'Acta de satisfacción del ticket #' . $payload['ticket_number'];
    $body = '<p>Hola ' . EmailTemplate::e($payload['signer']['name']) . '.</p><p>La solución realizada por ' . EmailTemplate::e(CompletionPolicy::ROLES[$payload['executor']]) . ' está documentada. Revisa los daños, las soluciones y las observaciones antes de firmar.</p><p>El ticket solo se cerrará si aceptas y firmas el acta. Si no estás conforme, no firmes y contacta a la inmobiliaria.</p><p><a href="' . EmailTemplate::e($url) . '">Revisar y firmar acta</a></p><p>Este enlace personal vence el ' . date('d/m/Y', (int) $act['expires_at']) . '. No lo compartas.</p>';
    $queued = 0;
    try {
      $queued = ($this->enqueue)($payload['signer']['email'], $title, EmailTemplate::render($title, $body), [
        'source_module' => 'ticket-completion', 'provider' => 'email_smtp',
        'destination_name' => $payload['signer']['name'],
        'dedupe_key' => 'ticket-acta:' . $act['id'] . ':' . $act['token_nonce'] . ':' . ((int) ($act['invitation_queued_at'] ?? 0)),
        'meta' => ['ticket_pk' => (int) $act['ticket_pk'], 'act_id' => (int) $act['id'], 'event' => 'signature_invitation'],
      ]);
    } catch (\Throwable $error) {
      error_log('[ticket-completion] No se pudo encolar invitación del acta #' . $act['id']);
    }
    if ($queued > 0) {
      $this->repo->db->update($this->repo->table(), ['invitation_queued_at' => time()], ['id' => (int) $act['id']]);
    }
    return ['act_id' => (int) $act['id'], 'queued' => $queued > 0, 'message' => $queued > 0
      ? 'Acta guardada. Correo de firma en cola; el ticket queda abierto hasta la firma.'
      : 'Acta guardada, pero NO se pudo encolar el correo. Usa Reenviar invitación cuando la cola esté disponible. El ticket sigue abierto.'];
  }

  public function cancel(int $id, string $reason, array $actor): void
  {
    $reason = CompletionPolicy::text($reason, 'motivo de anulación', 1000);
    $act = $this->repo->act($id);
    $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $reason, $actor): void {
      $act = $this->repo->act($id);
      if ($act['status'] !== 'pending') {
        throw new \DomainException('No se puede anular un acta firmada o ya anulada.');
      }
      $payload = $this->payload($act);
      $this->repo->db->update($this->repo->table(), ['status' => 'cancelled', 'active_slot' => null, 'cancelled_at' => time(), 'cancellation_reason' => $reason], ['id' => $id]);
      $this->repo->updateTicket((int) $ticket['_ID'], ['estado' => 'En proceso', 'estado_administrativo' => $payload['previous']['estado_administrativo']]);
      $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' anulada sin cerrar el ticket ni generar cobro. Motivo: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'), $actor['name'], $actor['employee_id']);
    });
  }

  public function sign(int $id, string $token, array $input, string $ip, string $userAgent): array
  {
    $act = $this->publicAct($id, $token);
    return $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $token, $input, $ip, $userAgent): array {
      $act = $this->repo->act($id);
      $this->assertPublicAccess($act, $token);
      $payload = $this->payload($act);
      if ($act['status'] === 'signed') {
        return $act; // Repeated submits cannot duplicate the report or audit trail.
      }
      if ($act['status'] !== 'pending' || (int) $act['active_slot'] !== 1 || ($ticket['estado_administrativo'] ?? '') !== CompletionPolicy::WAITING || strcasecmp((string) $ticket['estado'], 'Cerrado') === 0) {
        throw new \DomainException('El estado del ticket cambió. Contacta a la inmobiliaria antes de firmar.');
      }
      if (!hash_equals((string) $act['payload_hash'], (string) ($input['document_hash'] ?? ''))) {
        throw new \DomainException('La versión del acta no coincide. Recarga y revisa nuevamente el documento.');
      }
      $signature = CompletionPolicy::signature($input, $payload['signer']['name']);
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
        'descripcion' => 'Acta de satisfacción #' . $id . ' firmada del ticket #' . htmlspecialchars($payload['ticket_number'], ENT_QUOTES, 'UTF-8') . '. Solución por ' . CompletionPolicy::ROLES[$payload['executor']] . '. <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta y detalle de daños/soluciones</a>.',
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
        'status' => 'signed', 'signed_at' => $now, 'signed_json' => json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'report_id' => $reportId, 'legacy_act_id' => $legacyId,
      ], ['id' => $id]);
      $this->repo->updateTicket((int) $act['ticket_pk'], [
        'estado' => 'Cerrado', 'estado_administrativo' => 'Finalizado', 'estado_acta_satisfaccion' => 'Si',
        'id_acta_satisfaccion' => $legacyId, 'final_trabajo' => $now,
      ]);
      $this->repo->audit((int) $act['ticket_pk'], 'Acta #' . $id . ' firmada por ' . htmlspecialchars($signature['name'], ENT_QUOTES, 'UTF-8') . '. Ticket cerrado. Reporte administrativo #' . $reportId . ' registrado (no pagado, no exportado). <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta firmada</a>.', $payload['actor']['name'], $payload['actor']['employee_id']);
      return $this->repo->act($id);
    });
  }

  private static function legacyText(array $payload): string
  {
    $lines = ['Solución realizada por: ' . CompletionPolicy::ROLES[$payload['executor']]];
    foreach ($payload['items'] as $index => $item) {
      $lines[] = ($index + 1) . '. Daño: ' . $item['damage'] . "\nSolución: " . $item['solution'];
    }
    $lines[] = 'Observaciones: ' . $payload['observations'];
    return implode("\n\n", $lines);
  }
}
