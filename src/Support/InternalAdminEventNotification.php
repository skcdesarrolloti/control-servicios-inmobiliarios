<?php
declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/** Internal-only events never expose notes or operational details to external actors. */
final class InternalAdminEventNotification
{
  public static function enqueue(Database $db, string $action, array $ticket, string $title, string $details, string $reference, array $meta = []): int
  {
    try { return self::queue($db, $action, $ticket, $title, $details, $reference, $meta); }
    catch (\Throwable $error) { error_log('[internal-admin-event:' . $action . '] ' . $error->getMessage()); return 0; }
  }

  private static function queue(Database $db, string $action, array $ticket, string $title, string $details, string $reference, array $meta): int
  {
    $copies = InternalNotificationRecipients::emailsForAction($db, $action);
    if ($copies === []) return 0;
    $number = trim((string) ($ticket['id_ticket'] ?? $ticket['_ID'] ?? ''));
    $base = defined('SCM_BASE_URL') ? SCM_BASE_URL : '';
    $url = $base . '/index.php?tab=mis_tickets' . ($number !== '' ? '&scm_case=' . rawurlencode($number) : '');
    $body = '<p><strong>' . EmailTemplate::e($title) . '</strong></p>'
      . ($number !== '' ? '<p>Caso #' . EmailTemplate::e($number) . '</p>' : '')
      . '<p>' . nl2br(EmailTemplate::e(trim(strip_tags($details)))) . '</p>';
    return (new EmailQueue($db))->enqueue($copies, $title . ($number !== '' ? ' · Caso #' . $number : ''), EmailTemplate::render($title, $body, ['buttons' => [['url' => $url, 'label' => 'Abrir panel']]]), [
      'source_module' => $action, 'internal_action' => $action, 'internal_cc' => $copies,
      'dedupe_key' => 'internal-admin:' . $action . ':' . $reference,
      'meta' => $meta + ['event' => $action, 'ticket_pk' => (string) ($ticket['_ID'] ?? ''), 'id_ticket' => $number],
    ]);
  }
}
