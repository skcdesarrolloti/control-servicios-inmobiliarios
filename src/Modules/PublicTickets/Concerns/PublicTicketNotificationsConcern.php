<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait PublicTicketNotificationsConcern
{
  private function notifyResponsible(
    int $ticketPk,
    string $logicalTicket,
    string $asunto,
    string $tipoPqrs,
    string $departamento,
    array $contact,
    array $responsable,
    ?array $contract,
    ?array $client,
    string $actor,
    string $actorLabel
  ): array {
    $emailQueued = false;
    $smsQueued = false;
    $clienteOverride = $this->resolveClienteNotificationsOverride($actor);

    $responsableEmail = trim((string) ($responsable['correo'] ?? ''));
    $responsablePhone = trim((string) ($responsable['celular'] ?? ''));
    $responsableName = trim((string) ($responsable['nombre'] ?? ''));
    $responsableId = trim((string) ($responsable['id_empleado'] ?? ''));
    $responsableLabel = $responsableName !== '' ? $responsableName : ($responsableId !== '' ? 'Funcionario #' . $responsableId : 'Sin asignar');
    $solicitanteEmail = trim((string) ($contact['email'] ?? ''));
    $solicitantePhone = trim((string) ($contact['phone'] ?? ''));

    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Nueva Solicitud #' . $logicalTicket . ' asignada';
    $contractLabel = '';
    if (is_array($contract)) {
      $contractLabel = trim((string) ($contract['contrato'] ?? $contract['_ID'] ?? ''));
    }

    $content = '<p style="font-weight:600;margin:0 0 12px;">Se registro una nueva solicitud desde el portal de ' . EmailTemplate::e($actorLabel) . '.</p>';
    $content .= '<p style="margin:4px 0;"><b>Solicitud:</b> #' . EmailTemplate::e($logicalTicket) . '</p>';
    if ($contractLabel !== '') {
      $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e($contractLabel) . '</p>';
    }
    if (is_array($contract)) {
      $inmuebleLabel = trim((string) ($contract['inmueble'] ?? ''));
      $direccionLabel = trim((string) ($contract['direccion'] ?? ''));
      if ($inmuebleLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Inmueble:</b> ' . EmailTemplate::e($inmuebleLabel) . '</p>';
      }
      if ($direccionLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Direccion:</b> ' . EmailTemplate::e($direccionLabel) . '</p>';
      }
    }
    $content .= '<p style="margin:4px 0;"><b>Tipo de solicitud:</b> ' . EmailTemplate::e($tipoPqrs) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Departamento:</b> ' . EmailTemplate::e($departamento) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e($asunto) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Solicitante:</b> ' . EmailTemplate::e((string) $contact['name']) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Correo:</b> ' . EmailTemplate::e((string) $contact['email']) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Celular:</b> ' . EmailTemplate::e((string) $contact['phone']) . '</p>';
    if (is_array($client)) {
      $content .= '<p style="margin:4px 0;"><b>ID cliente:</b> ' . EmailTemplate::e((string) ($client['id_cliente'] ?? $client['_ID'] ?? '')) . '</p>';
    }
    $corresponsableIds = !empty($clienteOverride['enabled']) ? [] : self::getCorresponsableForType($tipoPqrs, $actor);
    $corresponsables = [];
    foreach ($corresponsableIds as $corresponsableId) {
      $corresponsableId = trim((string) $corresponsableId);
      if ($corresponsableId === '' || $corresponsableId === $responsableId) {
        continue;
      }
      $corresponsable = $this->findFuncionarioByEmployeeId($corresponsableId);
      if (empty($corresponsable)) {
        $corresponsable = [
          'id_empleado' => $corresponsableId,
          'nombre' => '',
          'correo' => '',
          'celular' => '',
        ];
      }
      $corresponsables[] = $corresponsable;
    }

    $themeResponsibleLabel = $responsableLabel;
    foreach ($corresponsables as $corresponsable) {
      $themeResponsibleId = trim((string) ($corresponsable['id_empleado'] ?? ''));
      $themeResponsibleName = trim((string) ($corresponsable['nombre'] ?? ''));
      if ($themeResponsibleName !== '') {
        $themeResponsibleLabel = $themeResponsibleName;
        break;
      }
      if ($themeResponsibleId !== '') {
        $themeResponsibleLabel = 'Funcionario #' . $themeResponsibleId;
        break;
      }
    }

    $staffEmailRecipients = [];
    $staffPhones = [];

    if ($responsableEmail !== '' && filter_var($responsableEmail, FILTER_VALIDATE_EMAIL)) {
      $staffEmailRecipients[] = [
        'email' => $responsableEmail,
        'name' => $responsableName,
        'id_empleado' => $responsableId,
        'access_scope' => 'assigned',
      ];
    }
    foreach ($corresponsables as $corresponsable) {
      if (!empty($corresponsable['correo'])) {
        $correspEmail = trim((string) $corresponsable['correo']);
        if ($correspEmail !== '' && filter_var($correspEmail, FILTER_VALIDATE_EMAIL)) {
          $staffEmailRecipients[] = [
            'email' => $correspEmail,
            'name' => trim((string) ($corresponsable['nombre'] ?? '')),
            'id_empleado' => trim((string) ($corresponsable['id_empleado'] ?? '')),
            'access_scope' => 'assigned',
          ];
        }
      }
    }

    if ($responsablePhone !== '') {
      $staffPhones[] = [
        'phone' => $responsablePhone,
        'name' => $responsableName,
        'id_empleado' => $responsableId,
        'access_scope' => 'assigned',
      ];
    }
    foreach ($corresponsables as $corresponsable) {
      if (!empty($corresponsable['celular'])) {
        $staffPhones[] = [
          'phone' => trim((string) $corresponsable['celular']),
          'name' => trim((string) ($corresponsable['nombre'] ?? '')),
          'id_empleado' => trim((string) ($corresponsable['id_empleado'] ?? '')),
          'access_scope' => 'assigned',
        ];
      }
    }

    // ── Funcionario configurado para notificaciones PQR ──────────────────
    $notifResponsableIds = [];
    if (empty($clienteOverride['enabled'])) {
      $notifResponsableIds = self::getNotifResponsables();
      foreach ($notifResponsableIds as $notifResponsableId) {
        if ($notifResponsableId === '') {
          continue;
        }

        $alreadyAdded = false;
        foreach ($staffPhones as &$sp) {
          if (trim((string) ($sp['id_empleado'] ?? '')) === $notifResponsableId) {
            $sp['access_scope'] = 'all';
            $alreadyAdded = true;
            break;
          }
        }
        unset($sp);
        if ($alreadyAdded) {
          // keep processing email below so dedupe can promote this recipient to global access.
        }

        $notifFunc = $this->findFuncionarioByEmployeeId($notifResponsableId);
        $notifPhone = trim((string) ($notifFunc['celular'] ?? ''));
        $notifName  = trim((string) ($notifFunc['nombre'] ?? ''));
        $notifEmail = trim((string) ($notifFunc['correo'] ?? ''));
        if (!$alreadyAdded && $notifPhone !== '') {
          $staffPhones[] = [
            'phone'       => $notifPhone,
            'name'        => $notifName,
            'id_empleado' => $notifResponsableId,
            'access_scope' => 'all',
          ];
        }
        if ($notifEmail !== '' && filter_var($notifEmail, FILTER_VALIDATE_EMAIL)) {
          $staffEmailRecipients[] = [
            'email' => $notifEmail,
            'name' => $notifName,
            'id_empleado' => $notifResponsableId,
            'access_scope' => 'all',
          ];
        }
      }
    }

    if (!empty($clienteOverride['enabled'])) {
      $staffEmailRecipients = [];
      $staffPhones = [];

      $overrideEmail = trim((string) ($clienteOverride['email'] ?? ''));
      $overridePhone = trim((string) ($clienteOverride['phone'] ?? ''));
      $overrideName = trim((string) ($clienteOverride['name'] ?? 'Monitoreo temporal cliente'));
      $overrideEmployeeId = trim((string) ($clienteOverride['id_empleado'] ?? ''));
      if ($overrideEmployeeId === '') {
        $overrideEmployeeId = $this->resolveNotificationEmployeeId($overrideEmail, $overridePhone, $overrideName);
      }

      if ($overrideEmail !== '' && filter_var($overrideEmail, FILTER_VALIDATE_EMAIL)) {
        $staffEmailRecipients[] = [
          'email' => $overrideEmail,
          'name' => $overrideName,
          'id_empleado' => $overrideEmployeeId,
          'access_scope' => 'all',
        ];
      }

      if ($overridePhone !== '') {
        $staffPhones[] = [
          'phone' => $overridePhone,
          'name' => $overrideName,
          'id_empleado' => $overrideEmployeeId,
          'access_scope' => 'all',
        ];
      }
    }

    $uniqueStaffEmails = [];
    foreach ($staffEmailRecipients as $recipient) {
      $mail = trim((string) ($recipient['email'] ?? ''));
      if ($mail === '') {
        continue;
      }
      $key = mb_strtolower($mail, 'UTF-8');
      if (!isset($uniqueStaffEmails[$key])) {
        $uniqueStaffEmails[$key] = [
          'email' => $mail,
          'name' => trim((string) ($recipient['name'] ?? '')),
          'id_empleado' => trim((string) ($recipient['id_empleado'] ?? '')),
          'access_scope' => $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned')),
        ];
      } elseif (
        $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned')) === 'all'
        && ($uniqueStaffEmails[$key]['access_scope'] ?? 'assigned') !== 'all'
      ) {
        $uniqueStaffEmails[$key]['access_scope'] = 'all';
        $uniqueStaffEmails[$key]['id_empleado'] = trim((string) ($recipient['id_empleado'] ?? ''));
      }
    }
    if (!empty($uniqueStaffEmails)) {
      foreach ($uniqueStaffEmails as $recipient) {
        $recipientName = trim((string) ($recipient['name'] ?? ''));
        $recipientLabel = $recipientName !== '' ? $recipientName : 'Destinatario de la notificacion';
        $recipientEmployeeId = trim((string) ($recipient['id_empleado'] ?? ''));
        $recipientScope = $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned'));
        $urlEmployeeId = $recipientEmployeeId !== '' ? $recipientEmployeeId : $responsableId;
        $recipientPublicUrl = $urlEmployeeId !== ''
          ? $this->buildResponsiblePublicPqrUrl($urlEmployeeId, 2592000, $recipientScope)
          : '';
        $emailContent = $content;
        $emailContent .= '<p style="margin:4px 0;"><b>Responsable:</b> ' . EmailTemplate::e($themeResponsibleLabel) . '</p>';
        if ($recipientName !== '' && $recipientName !== $responsableLabel) {
          $emailContent .= '<p style="margin:4px 0;"><b>Recibe esta notificacion:</b> ' . EmailTemplate::e($recipientLabel) . '</p>';
        }
        $buttons = [];
        if ($recipientPublicUrl !== '') {
          $buttons[] = [
            'url' => $recipientPublicUrl,
            'label' => $recipientScope === 'all' ? 'Ver todas las solicitudes web' : 'Ver solicitudes web filtradas',
          ];
        }

        $html = EmailTemplate::render($subject, $emailContent, [
          'ticket_url' => $ticketUrl,
          'buttons' => $buttons,
        ]);

        $this->emailQueue->enqueue(
          (string) ($recipient['email'] ?? ''),
          $subject,
          $html,
          [
            'source' => 'public_pqr_portal',
            'destination_name' => trim((string) ($recipient['name'] ?? '')),
          ]
        );
        $emailQueued = true;
      }
    }

    $uniqueStaffPhones = [];
    foreach ($staffPhones as $dest) {
      $phoneRaw = trim((string) ($dest['phone'] ?? ''));
      if ($phoneRaw === '') {
        continue;
      }
      $phoneKey = $this->normalizePhoneForDedup($phoneRaw);
      if ($phoneKey === '') {
        continue;
      }
      if (!isset($uniqueStaffPhones[$phoneKey])) {
        $uniqueStaffPhones[$phoneKey] = $dest;
      } elseif (
        $this->normalizePublicPqrAccessScope((string) ($dest['access_scope'] ?? 'assigned')) === 'all'
        && $this->normalizePublicPqrAccessScope((string) ($uniqueStaffPhones[$phoneKey]['access_scope'] ?? 'assigned')) !== 'all'
      ) {
        $uniqueStaffPhones[$phoneKey] = $dest;
      }
    }

    foreach ($uniqueStaffPhones as $dest) {
      $phoneRaw = trim((string) ($dest['phone'] ?? ''));
      $destName = trim((string) ($dest['name'] ?? ''));
      $destLabel = $destName !== '' ? $destName : 'Destinatario de la notificacion';
      $destEmployeeId = trim((string) ($dest['id_empleado'] ?? ''));
      $destScope = $this->normalizePublicPqrAccessScope((string) ($dest['access_scope'] ?? 'assigned'));
      $urlEmployeeId = $destEmployeeId !== '' ? $destEmployeeId : $responsableId;
      $publicRequestsUrl = $urlEmployeeId !== ''
        ? $this->buildResponsiblePublicPqrUrl($urlEmployeeId, 2592000, $destScope)
        : '';
      $smsMessage = "📥 *Nueva solicitud web*\n";
      $smsMessage .= '*Solicitud:* #' . $logicalTicket . "\n";
      $smsMessage .= '*Tema:* ' . $tipoPqrs . "\n";
      if ($contractLabel !== '') {
        $smsMessage .= '*Contrato:* ' . $contractLabel . "\n";
      }
      $smsMessage .= '*Responsable:* ' . $themeResponsibleLabel . "\n";
      if ($destName !== '' && $destName !== $responsableLabel) {
        $smsMessage .= '*Notificado:* ' . $destLabel . "\n";
      }
      $smsMessage .= '*Solicitante:* ' . (string) $contact['name'] . "\n\n";
      $smsMessage .= "*Ver solicitud:*\n" . $ticketUrl;
      if ($publicRequestsUrl !== '') {
        $smsMessage .= "\n\n*" . ($destScope === 'all' ? 'Ver todas las solicitudes web:' : 'Ver solicitudes web filtradas:') . "*\n" . $publicRequestsUrl;
      }
      $queued = $this->smsQueue->enqueue($phoneRaw, $destName, $smsMessage, [
        'id_funcionario' => is_numeric((string) ($dest['id_empleado'] ?? '')) ? (int) $dest['id_empleado'] : 0,
        'id_actor' => is_numeric((string) $contact['actor_id']) ? (int) $contact['actor_id'] : 0,
        'tipo_actor' => strtolower($actorLabel),
        'categoria_mensaje' => 'informacion',
        'campaign_tag' => 'pqr_portal_publico',
        'dedupe_key' => 'pqr_nueva_asignada:' . $logicalTicket,
        'template_name' => 'pqr_nueva_asignada_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $destLabel],
              ['type' => 'text', 'text' => $logicalTicket],
              ['type' => 'text', 'text' => $tipoPqrs],
              ['type' => 'text', 'text' => $themeResponsibleLabel],
              ['type' => 'text', 'text' => (string) $contact['name']],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
              ['type' => 'text', 'text' => $logicalTicket],
            ],
          ],
        ],
      ]);
      if ($queued) {
        $smsQueued = true;
      }
    }

    $confirmationSubject = 'Confirmacion PQR #' . $logicalTicket . ' recibido';
    $confirmationContent = '<p style="font-weight:600;margin:0 0 12px;">Hemos recibido tu PQR y ya fue asignado a gestion interna.</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>PQR:</b> #' . EmailTemplate::e($logicalTicket) . '</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>Tipo PQR/Peticion:</b> ' . EmailTemplate::e($tipoPqrs) . '</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e($asunto) . '</p>';
    if ($contractLabel !== '') {
      $confirmationContent .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e($contractLabel) . '</p>';
    }
    $confirmationContent .= '<p style="margin:4px 0;"><b>Solicitante:</b> ' . EmailTemplate::e((string) $contact['name']) . '</p>';
    $confirmationHtml = EmailTemplate::render($confirmationSubject, $confirmationContent, ['ticket_url' => $ticketUrl]);

    if (empty($clienteOverride['enabled']) && $solicitanteEmail !== '' && filter_var($solicitanteEmail, FILTER_VALIDATE_EMAIL)) {
      $dedupKey = mb_strtolower($solicitanteEmail, 'UTF-8');
      if (!isset($uniqueStaffEmails[$dedupKey])) {
        $this->emailQueue->enqueue($solicitanteEmail, $confirmationSubject, $confirmationHtml, ['source' => 'public_pqr_portal']);
        $emailQueued = true;
      }
    }

    return [
      'email_queued' => $emailQueued,
      'sms_queued' => $smsQueued,
      'corresponsable_ids' => $corresponsableIds,
      'notif_responsable_ids' => $notifResponsableIds,
    ];
  }

  /**
   * @param array<string,string> $responsable
   * @param array<string,mixed>|null $contract
   */
  private function insertPropertyHistory(string $logicalTicket, string $asunto, array $responsable, ?array $contract): void
  {
    if (!is_array($contract)) {
      return;
    }

    $histTable = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($histTable)) {
      return;
    }

    $propertyId = trim((string) ($contract['id_inmueble'] ?? ''));
    $propertyDataId = trim((string) ($contract['id_inmueble_data'] ?? ''));
    if ($propertyId === '' && $propertyDataId === '') {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $employeeId = trim((string) ($responsable['id_empleado'] ?? ''));
    $employeeName = trim((string) ($responsable['nombre'] ?? ''));

    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'id_inmueble' => $propertyId,
      'id_inmueble_data' => $propertyDataId,
      'id_ticket' => $logicalTicket,
      'fecha' => $nowTs,
      'tipo_reporte' => 'Ticket',
      'observacion' => $asunto,
      'funcionario' => $employeeName,
    ];

    $payload = $this->schema->filterTableData($histTable, $payload);
    if (empty($payload)) {
      return;
    }

    try {
      $this->db->insert($histTable, $payload);
    } catch (\Throwable $e) {
      // no-op
    }
  }

  /** @return array<int,string> */
}
