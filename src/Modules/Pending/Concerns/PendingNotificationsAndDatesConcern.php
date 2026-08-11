<?php

declare(strict_types=1);

namespace SCM\Modules\Pending\Concerns;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait PendingNotificationsAndDatesConcern
{
  private function queueCreationEmails(int $ticketId, array $ticketPayload, array $notifyRecipients, string $mode, array $generatedPdfs = []): int
  {
    $notifyRecipients = array_values(array_unique(array_filter(array_map('strval', $notifyRecipients))));
    if (empty($notifyRecipients)) {
      $notifyRecipients = ['empleado', 'solicitante', 'admin'];
    }
    if (in_array('none', $notifyRecipients, true)) {
      return 0;
    }

    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode((string) $ticketId);
    $subject = $mode === 'preventiva'
      ? 'Aviso de revision preventiva del contrato ' . (string) ($ticketPayload['contrato'] ?? '')
      : 'Ticket administrativo #' . $ticketId . ' creado';
    $content = '<p style="font-weight:600;margin:0 0 12px;">Se ha creado un ticket administrativo.</p>';
    $content .= '<p style="margin:4px 0;"><b>Ticket:</b> #' . EmailTemplate::e((string) $ticketId) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Tema:</b> ' . EmailTemplate::e((string) ($ticketPayload['tema_ayuda'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e((string) ($ticketPayload['asunto'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e((string) ($ticketPayload['contrato'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Inmueble:</b> ' . EmailTemplate::e((string) ($ticketPayload['inmueble'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Responsable:</b> ' . EmailTemplate::e((string) ($ticketPayload['nombre_empleado'] ?? '')) . '</p>';

    $jobs = [];
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
        $addJob($jobs, 'sucasacorreos@gmail.com', $target, $generatedPdfs);
        $addJob($jobs, $mode === 'preventiva' ? 'mantenimientotickets@hotmail.com' : 'gcorrearivera@gmail.com', $target, $generatedPdfs);
      }
    }
    if (empty($jobs)) {
      return 0;
    }

    $queue = new EmailQueue($this->repo->getDb());
    foreach ($jobs as $job) {
      $pdfButtons = [];
      $attachments = [];
      foreach ((array) ($job['pdfs'] ?? []) as $pdf) {
        $url = trim((string) ($pdf['url'] ?? ''));
        if ($url === '') {
          continue;
        }
        $title = (string) ($pdf['title'] ?? 'Documento PDF');
        $pdfButtons[] = ['url' => $url, 'label' => $title];
        $attachments[] = [
          'title' => $title,
          'url' => $url,
          'path' => (string) ($pdf['path'] ?? ''),
        ];
      }
      $extraContent = $content;
      if (!empty($pdfButtons)) {
        $extraContent .= '<p style="margin:12px 0 4px;"><b>Documentos generados:</b></p>';
        foreach ($pdfButtons as $button) {
          $extraContent .= '<p style="margin:4px 0;">' . EmailTemplate::e((string) $button['label']) . '</p>';
        }
      }
      $html = EmailTemplate::render($subject, $extraContent, [
        'ticket_url' => $ticketUrl,
        'buttons' => $pdfButtons,
      ]);
      $queue->enqueue((string) $job['email'], $subject, $html, [
        'source' => 'scm_ticket_administrativo_nativo',
        'dedupe_key' => 'ticket_admin_created_' . $ticketId,
        'attachments' => $attachments,
        'meta' => [
          'attachments' => $attachments,
          'targets' => array_values(array_unique((array) ($job['targets'] ?? []))),
        ],
      ]);
    }
    return count($jobs);
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
