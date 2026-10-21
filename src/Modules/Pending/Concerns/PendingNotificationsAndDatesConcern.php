<?php

declare(strict_types=1);

namespace SCM\Modules\Pending\Concerns;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;

trait PendingNotificationsAndDatesConcern
{
  private function queueCreationEmails(int $ticketId, array $ticketPayload, array $notifyRecipients, string $mode, array $generatedPdfs = []): int
  {
    $notifyRecipients = $this->normalizeCreationNotifyRecipients($notifyRecipients, $mode, (string) ($ticketPayload['solicitante_tipo'] ?? ''));
    $notifyRecipients = array_values(array_unique(array_filter(array_map('strval', $notifyRecipients))));
    if (empty($notifyRecipients)) {
      $notifyRecipients = strtolower(trim($mode)) === 'preventiva'
        ? ['empleado', 'arrendatario', 'admin']
        : ['empleado', 'solicitante', 'admin'];
    }
    if (in_array('none', $notifyRecipients, true)) {
      return 0;
    }

    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode((string) $ticketId);
    $isRetention = in_array(mb_strtolower(trim((string) ($ticketPayload['tema_ayuda'] ?? '')), 'UTF-8'), ['retencion de contrato', 'retención de contrato'], true);
    $subject = $mode === 'preventiva'
      ? 'Aviso de revision preventiva del contrato ' . (string) ($ticketPayload['contrato'] ?? '')
      : 'Caso administrativo #' . $ticketId . ' creado';
    $content = '<p style="font-weight:600;margin:0 0 12px;">Se ha creado un caso administrativo.</p>';
    $content .= '<p style="margin:4px 0;"><b>Caso:</b> #' . EmailTemplate::e((string) $ticketId) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Tema:</b> ' . EmailTemplate::e((string) ($ticketPayload['tema_ayuda'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e((string) ($ticketPayload['asunto'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e((string) ($ticketPayload['contrato'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Inmueble:</b> ' . EmailTemplate::e((string) ($ticketPayload['inmueble'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Responsable:</b> ' . EmailTemplate::e((string) ($ticketPayload['nombre_empleado'] ?? '')) . '</p>';
    $descripcion = trim(html_entity_decode(strip_tags((string) ($ticketPayload['descripcion'] ?? '')), ENT_QUOTES, 'UTF-8'));
    if ($descripcion !== '') {
      $content .= '<div style="margin:14px 0 0;padding:14px 16px;border:1px solid #334155;background:#1f2937;border-radius:10px;">';
      $content .= '<p style="margin:0 0 6px;color:#f8fafc;font-weight:700;">Descripcion</p>';
      $content .= '<p style="margin:0;color:#cbd5e1;line-height:1.55;">' . nl2br(EmailTemplate::e($descripcion)) . '</p>';
      $content .= '</div>';
    }
    if ($isRetention) {
      $subject = \SCM\Modules\Contracts\ContractRetentionEmail::subject($ticketId);
      $content = \SCM\Modules\Contracts\ContractRetentionEmail::content($ticketId, $ticketPayload);
    }

    $jobs = [];
    $adminAction = '';
    $addJob = static function (array &$jobs, string $email, string $target, array $pdfs): void {
      $email = trim($email);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
      }
      $key = strtolower($email);
      if (!isset($jobs[$key])) {
        $jobs[$key] = ['email' => $email, 'targets' => [], 'pdfs' => []];
      }
      $jobs[$key]['targets'][] = $target;
      foreach ($pdfs as $pdfKey => $pdf) {
        $url = trim((string) ($pdf['url'] ?? ''));
        if ($url !== '') {
          $jobs[$key]['pdfs'][$pdfKey] = $pdf;
        }
      }
    };

    foreach ($notifyRecipients as $target) {
      if ($target === 'empleado') {
        $addJob($jobs, (string) ($ticketPayload['correo_empleado'] ?? ''), $target, $generatedPdfs);
      } elseif ($target === 'solicitante') {
        $addJob($jobs, (string) ($ticketPayload['correo_solicitante'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, (string) ($ticketPayload['solicitante_tipo'] ?? ''), (string) ($ticketPayload['arrendatario'] ?? '')));
      } elseif ($target === 'arrendatario') {
        $addJob($jobs, (string) ($ticketPayload['correo_arrendatario'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, 'arrendatario', 'arrendatario'));
      } elseif ($target === 'propietario') {
        $addJob($jobs, (string) ($ticketPayload['correo_propietario'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, 'propietario', 'arrendatario'));
      } elseif ($target === 'admin') {
        $adminAction = trim((string) ($ticketPayload['internal_notification_action'] ?? ''));
        if ($adminAction === '') {
          $adminAction = $mode === 'preventiva' ? 'crear_ticket_preventiva' : 'ticket_administrativo_creado';
        }
        if ($mode === 'preventiva' && $generatedPdfs !== []) {
          foreach (InternalNotificationRecipients::emailsForAction($this->repo->getDb(), 'acta_preventiva') as $actEmail) $addJob($jobs, $actEmail, $target, $generatedPdfs);
        }
        foreach (InternalNotificationRecipients::emailsForAction($this->repo->getDb(), $adminAction) as $adminEmail) {
          $addJob($jobs, $adminEmail, $target, $generatedPdfs);
        }
      }
    }
    if (empty($jobs)) {
      return 0;
    }

    $queue = new EmailQueue($this->repo->getDb());
    $copies = $adminAction !== '' ? InternalNotificationRecipients::emailsForAction($this->repo->getDb(),$adminAction) : [];
    if ($mode === 'preventiva' && $generatedPdfs !== [] && in_array('admin',$notifyRecipients,true)) $copies = \SCM\Support\InternalEmailCopies::emails(array_merge($copies,InternalNotificationRecipients::emailsForAction($this->repo->getDb(),'acta_preventiva')));
    $primaryEmails = \SCM\Support\InternalEmailCopies::plan(array_column($jobs,'email'),$copies)['to'];
    foreach ($jobs as $job) {
      if (!in_array(strtolower($job['email']),$primaryEmails,true)) continue;
      $pdfButtons = [];
      $documents = [];
      foreach ((array) ($job['pdfs'] ?? []) as $pdf) {
        $url = trim((string) ($pdf['url'] ?? ''));
        if ($url === '') {
          continue;
        }
        $title = (string) ($pdf['title'] ?? 'Documento PDF');
        $label = $this->documentButtonLabel($mode, $title);
        $pdfButtons[] = ['url' => $url, 'label' => $label];
        $documents[] = [
          'title' => $title,
          'url' => $url,
        ];
      }
      $extraContent = $content;
      if (!empty($pdfButtons)) {
        $extraContent .= '<div style="margin:16px 0;padding:14px 16px;border:1px solid #fde2bd;background:#fff8ef;border-radius:10px;">';
        $extraContent .= '<p style="margin:0 0 6px;color:#061d49;font-weight:700;">' . (isset($generatedPdfs['acta_desocupacion']) ? 'Notificación previa para entrega del inmueble' : 'Ofrecimiento de revisión preventiva') . '</p>';
        $extraContent .= '<p style="margin:0;color:#475569;">Consulta el documento correspondiente desde el botón de acceso.</p>';
        $extraContent .= '</div>';
      }
      $jobSubject = $subject;
      $jobCopies = $copies;
      // El arrendatario recibe su propio aviso de recibo (sin datos internos ni enlace al caso); los funcionarios, el correo interno.
      $tenantEmail = strtolower(trim((string) ($ticketPayload['correo_arrendatario'] ?? '')));
      if (isset($generatedPdfs['acta_desocupacion']) && $tenantEmail !== '' && strtolower((string) $job['email']) === $tenantEmail) {
        $jobSubject = 'Aviso previo de entrega del inmueble - Contrato ' . trim((string) ($ticketPayload['contrato'] ?? ''));
        $html = EmailTemplate::render('Aviso previo de entrega del inmueble', $this->receiptTenantEmailContent($ticketPayload), [
          'buttons' => $pdfButtons,
        ]);
        if (count($primaryEmails) > 1) $jobCopies = [];
      } else {
        $html = EmailTemplate::render($subject, $extraContent, [
          'ticket_url' => $ticketUrl,
          'buttons' => $pdfButtons,
        ]);
      }
      $queuedCount = $queue->enqueue((string) $job['email'], $jobSubject, $html, [
        'source' => 'scm_ticket_administrativo_nativo',
        'internal_action'=>$adminAction, 'internal_cc'=>$jobCopies,
        'dedupe_key' => 'ticket_admin_created_' . $ticketId,
        'meta' => [
          'documents' => $documents,
          'internal_events' => $mode === 'preventiva' && $generatedPdfs !== [] ? [$adminAction, 'acta_preventiva'] : [$adminAction],
          'targets' => array_values(array_unique((array) ($job['targets'] ?? []))),
        ],
      ]);
      if (!empty($ticketPayload['require_receipt_letter']) && $queuedCount < 1) throw new \RuntimeException('No se pudo encolar la carta para todos los destinatarios.');
    }
    return count($primaryEmails);
  }

  /** Correo para el arrendatario con la carta de aviso previo de entrega (caso de recibo). */
  private function receiptTenantEmailContent(array $ticketPayload): string
  {
    $name = trim((string) ($ticketPayload['arrendatario'] ?? ''));
    $address = trim((string) ($ticketPayload['direccion'] ?? ''));
    $contract = trim((string) ($ticketPayload['contrato'] ?? ''));
    $html = '<p style="margin:0 0 12px;">' . ($name !== '' ? 'Señor(a) <b>' . EmailTemplate::e($name) . '</b>,' : 'Cordial saludo,') . '</p>';
    $html .= '<p style="margin:0 0 12px;line-height:1.55;">Le escribimos de SKC SuCasa Inmobiliaria porque su contrato de arrendamiento'
      . ($contract !== '' ? ' <b>' . EmailTemplate::e($contract) . '</b>' : '')
      . ($address !== '' ? ' del inmueble ubicado en <b>' . EmailTemplate::e($address) . '</b>' : '')
      . ' se encuentra próximo a finalizar.</p>';
    $html .= '<p style="margin:0 0 12px;line-height:1.55;">En la notificación adjunta encontrará las condiciones de entrega: el inmueble desocupado, con sus llaves y demás elementos recibidos, las reparaciones que sean de su responsabilidad y las obligaciones al día (canon, servicios públicos y administración cuando aplique).</p>';
    $contact = array_filter([
      trim((string) ($ticketPayload['nombre_empleado'] ?? '')),
      trim((string) ($ticketPayload['celular_empleado'] ?? '')),
      trim((string) ($ticketPayload['correo_empleado'] ?? '')),
    ]);
    $html .= '<p style="margin:0;line-height:1.55;">Para coordinar la revisión y el recibo del inmueble'
      . ($contact !== [] ? ' comuníquese con <b>' . EmailTemplate::e(implode(' · ', $contact)) . '</b>.' : ' comuníquese con nuestro equipo.') . '</p>';
    return $html;
  }

  /** @param array<int,string> $notifyRecipients @return array<int,string> */
  private function normalizeCreationNotifyRecipients(array $notifyRecipients, string $mode, string $solicitanteTipo): array
  {
    $mode = strtolower(trim($mode));
    $solicitanteTipo = strtolower(trim($solicitanteTipo));
    if ($mode !== 'preventiva') {
      return $notifyRecipients;
    }

    $out = [];
    foreach ($notifyRecipients as $target) {
      $target = strtolower(trim((string) $target));
      if ($target === 'solicitante') {
        $target = $solicitanteTipo === 'propietario' ? 'propietario' : 'arrendatario';
      }
      if ($target !== '') {
        $out[] = $target;
      }
    }
    return $out;
  }

  /**
   * @param array<string,array<string,string>> $pdfs
   * @return array<string,array<string,string>>
   */
  private function pdfsForTarget(array $pdfs, string $target, string $arrendatarioName): array
  {
    if (isset($pdfs['acta_desocupacion'])) {
      return ['acta_desocupacion' => $pdfs['acta_desocupacion']];
    }
    $target = strtolower(trim($target));
    $arrendatarioName = strtolower(trim($arrendatarioName));
    if ($target === 'arrendatario' || ($arrendatarioName !== '' && $target === $arrendatarioName)) {
      return isset($pdfs['acta_revision_preventiva_arrendatario'])
        ? ['acta_revision_preventiva_arrendatario' => $pdfs['acta_revision_preventiva_arrendatario']]
        : [];
    }
    if ($target === 'propietario') {
      return isset($pdfs['acta_revision_preventiva_propietario'])
        ? ['acta_revision_preventiva_propietario' => $pdfs['acta_revision_preventiva_propietario']]
        : [];
    }
    return $pdfs;
  }

  private function documentButtonLabel(string $mode, string $title): string
  {
    if (strtolower(trim($mode)) !== 'preventiva') {
      return 'Ver ' . $title;
    }

    $titleNorm = strtolower(trim($title));
    if (str_contains($titleNorm, 'propietario')) {
      return 'Ver ofrecimiento de revision preventiva - Propietario';
    }
    if (str_contains($titleNorm, 'arrendatario')) {
      return 'Ver ofrecimiento de revision preventiva - Arrendatario';
    }
    return 'Ver ofrecimiento de revision preventiva';
  }

  private function parseTs($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function addMonths(int $ts, int $months): int
  {
    if ($ts <= 0 || $months <= 0) {
      return 0;
    }
    try {
      $dt = new \DateTime('@' . $ts);
      $dt->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
      $dt->modify('+' . $months . ' months');
      return (int) $dt->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  private function replaceMonthPreservingDate(int $ts, int $month, bool $ensureFuture = false, ?int $forcedYear = null): int
  {
    if ($ts <= 0 || $month < 1 || $month > 12) {
      return 0;
    }

    try {
      $tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
      $source = new \DateTime('@' . $ts);
      $source->setTimezone($tz);

      $targetYear = $forcedYear ?? (int) $source->format('Y');
      $targetDay = (int) $source->format('j');
      $targetHour = (int) $source->format('G');
      $targetMinute = (int) $source->format('i');
      $targetSecond = (int) $source->format('s');

      $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $targetYear);
      $candidate = clone $source;
      $candidate->setDate($targetYear, $month, min($targetDay, $maxDay));
      $candidate->setTime($targetHour, $targetMinute, $targetSecond);

      if ($ensureFuture && $candidate->getTimestamp() <= $ts) {
        $targetYear++;
        $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $targetYear);
        $candidate->setDate($targetYear, $month, min($targetDay, $maxDay));
        $candidate->setTime($targetHour, $targetMinute, $targetSecond);
      }

      return (int) $candidate->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /** @param array<int,mixed> $values */
  private function firstPositiveTs(array $values): int
  {
    foreach ($values as $value) {
      $ts = $this->parseTs($value);
      if ($ts > 0) {
        return $ts;
      }
    }
    return 0;
  }
}
