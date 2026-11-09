<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

use SCM\Core\Database;
use SCM\Support\EmailTemplate;
use SCM\Support\PanelCaseNotifications;
use SCM\Support\StoredFileService;

/**
 * Comunicaciones a terceros, siempre dentro de un caso. Lo enviado es inmutable: tras el envío solo se actualizan los
 * campos de estado de cada destinatario, que hoy llegan hasta "aceptado por el servidor" porque SMTP y la cola no informan entrega.
 */
final class LegalCommunicationService
{
  public const WHATSAPP_TEMPLATE = 'scm_comunicacion_caso_v1';
  public const VARIABLES = ['nombre_destinatario', 'numero_contrato', 'direccion_inmueble', 'fecha', 'codigo_caso', 'funcionario'];
  public const STATUS_LABELS = ['en_cola' => 'En cola', 'enviado' => 'Aceptado por el servidor', 'fallido' => 'Fallido', 'cancelado' => 'Cancelado'];
  public const CHANNEL_LABELS = ['email' => 'Correo', 'whatsapp' => 'WhatsApp'];
  public const PER_PAGE = 25;
  private const LINK_SECONDS = 180 * 86400;
  private const MAX_RECIPIENTS = 50;
  private const MAX_ATTACHMENT_BYTES = 20 * 1048576;
  private const QUEUE_STATUS = ['pending' => 'en_cola', 'processing' => 'en_cola', 'sent' => 'enviado', 'failed' => 'fallido', 'cancelled' => 'cancelado'];
  private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

  public function __construct(private Database $db, private LegalCaseService $cases, private StoredFileService $files, private LegalDeliveryGateway $gateway) {}

  private function table(string $name): string
  {
    return $this->cases->table($name);
  }

  // --- Plantillas

  /** @return list<array{id:int,name:string,subject:string,body_html:string,active:bool}> */
  public function templates(bool $onlyActive = false): array
  {
    $rows = $this->db->getResults("SELECT `id`, `name`, `subject`, `body_html`, `active` FROM `{$this->table('templates')}`" . ($onlyActive ? ' WHERE `active` = 1' : '') . ' ORDER BY `name`');
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'subject' => (string) $row['subject'], 'body_html' => (string) $row['body_html'], 'active' => (int) $row['active'] === 1], $rows);
  }

  /** @param array<string,mixed> $input @param array<string,string> $actor */
  public function saveTemplate(int $id, array $input, array $actor): int
  {
    $name = trim((string) ($input['name'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $body = LegalHtmlSanitizer::clean((string) ($input['body'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) throw new \DomainException('Escribe el nombre de la plantilla (máximo 120 caracteres).');
    if (mb_strlen($subject) > 200) throw new \DomainException('El asunto admite máximo 200 caracteres.');
    if (LegalHtmlSanitizer::isEmpty($body)) throw new \DomainException('Escribe el contenido de la plantilla.');
    $this->assertKnownVariables($subject . ' ' . $body);
    $data = ['name' => $name, 'subject' => $subject, 'body_html' => $body, 'active' => !empty($input['active']) ? 1 : 0, 'updated_by_name' => $actor['name'], 'updated_at' => time()];
    if ($id > 0) {
      if ($this->db->getVar("SELECT `id` FROM `{$this->table('templates')}` WHERE `id` = ? LIMIT 1", [$id]) === null) throw new \DomainException('La plantilla no existe.');
      $this->db->update($this->table('templates'), $data, ['id' => $id]);
      return $id;
    }
    $this->db->insert($this->table('templates'), $data + ['created_at' => time()]);
    return (int) $this->db->lastInsertId();
  }

  private function assertKnownVariables(string $text): void
  {
    preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $text, $matches);
    $unknown = array_values(array_unique(array_diff($matches[1], self::VARIABLES)));
    if ($unknown !== []) throw new \DomainException('Variable no reconocida: {{' . $unknown[0] . '}}.');
  }

  // --- Envío

  /**
   * Valida todo y devuelve lo que se enviaría, sin guardar nada.
   * @param array<string,mixed> $input @param array<string,string> $actor @return array<string,mixed>
   */
  public function preview(int $caseId, array $input, array $actor): array
  {
    $plan = $this->plan($caseId, $input, $actor);
    $first = $plan['recipients'][0];
    $vars = $this->variables($plan['detail'], $first['name'], $actor);
    return [
      'subject' => $this->render($plan['subject'], $vars, false),
      'html' => $this->letter($this->render($plan['body'], $vars, true), $actor),
      'addressee' => $first['name'],
      'email_count' => count(array_filter($plan['recipients'], static fn(array $to): bool => $to['email'] !== '')),
      'whatsapp_count' => count(array_filter($plan['recipients'], static fn(array $to): bool => $to['phone'] !== '')),
      'recipients' => array_map(static fn(array $to): array => ['name' => $to['name'], 'email' => $to['email'], 'phone' => $to['phone']], $plan['recipients']),
      'cc' => array_column($plan['cc'], 'email'),
      'reply_to' => $plan['reply_to'],
    ];
  }

  /**
   * Todo o nada: la cola comparte conexión, así que si un destinatario no se puede encolar se revierte el envío completo.
   * @param array<string,mixed> $input @param list<array<string,mixed>> $uploads @param array<string,string> $actor
   * @return array{communication_id:int,email:int,whatsapp:int}
   */
  public function send(int $caseId, array $input, array $uploads, array $actor): array
  {
    $plan = $this->plan($caseId, $input, $actor);
    $attachments = $this->attachments($plan['detail'], $input, $uploads, in_array('email', $plan['channels'], true));
    if (!$this->gateway->ready()) throw new \DomainException('La cola de envíos no está disponible. Intenta nuevamente en unos minutos.');

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $result = $this->store($caseId, $plan, $attachments, $uploads, $input, $actor);
      $pdo->commit();
      return $result;
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }

  /**
   * @param array<string,mixed> $plan @param list<array<string,mixed>> $attachments @param list<array<string,mixed>> $uploads
   * @param array<string,mixed> $input @param array<string,string> $actor
   * @return array{communication_id:int,email:int,whatsapp:int}
   */
  private function store(int $caseId, array $plan, array $attachments, array $uploads, array $input, array $actor): array
  {
    $now = time();
    $usesWhatsApp = in_array('whatsapp', $plan['channels'], true);
    $this->db->insert($this->table('communications'), [
      'case_id' => $caseId, 'channels' => implode(',', $plan['channels']), 'subject' => $plan['subject'], 'body_html' => $plan['body'], 'reply_to' => $plan['reply_to'],
      'whatsapp_template' => $usesWhatsApp ? self::WHATSAPP_TEMPLATE : '', 'template_id' => (int) ($input['template_id'] ?? 0),
      'attachments_json' => (string) json_encode($attachments, JSON_UNESCAPED_UNICODE), 'sender_id' => $actor['id'], 'sender_name' => $actor['name'], 'created_at' => $now,
    ]);
    $communicationId = (int) $this->db->lastInsertId();
    $eventId = $this->cases->event($caseId, 'comunicacion_enviada', $actor, ['communication_id' => $communicationId, 'asunto' => $plan['subject'], 'canales' => array_map(static fn(string $channel): string => self::CHANNEL_LABELS[$channel], $plan['channels']), 'destinatarios' => array_column($plan['recipients'], 'name'), 'copias' => array_column($plan['cc'], 'email'), 'adjuntos' => array_column($attachments, 'name')]);
    foreach ($uploads as $upload) $this->cases->insertDocument($caseId, $eventId, $upload, $actor);

    $counts = ['communication_id' => $communicationId, 'email' => 0, 'whatsapp' => 0];
    $emailAttachments = array_map(fn(array $item): array => ['path' => (string) $this->files->pathFor($item['stored_name']), 'name' => $item['name']], $attachments);
    $copies = $plan['cc'];
    foreach ($plan['recipients'] as $index => $to) {
      $vars = $this->variables($plan['detail'], $to['name'], $actor);
      $subject = $this->render($plan['subject'], $vars, false);
      $letter = $this->letter($this->render($plan['body'], $vars, true), $actor);
      if ($to['email'] !== '') {
        $prefix = "legal-comm:{$communicationId}:{$index}:email";
        $html = EmailTemplate::render($subject, $letter);
        // Las copias van solo en el primer correo: si no, recibirían uno por cada destinatario.
        $this->insertRecipient($communicationId, $to, 'to', 'email', $to['email'], $subject, $letter, $html, $prefix);
        foreach ($copies as $copy) $this->insertRecipient($communicationId, $copy, 'cc', 'email', $copy['email'], $subject, $letter, $html, $prefix);
        $cc = array_column($copies, 'email');
        $copies = [];
        $queued = $this->gateway->email($to['email'], $subject, $html, ['source_module' => 'casos_juridicos', 'destination_name' => $to['name'], 'dedupe_key' => $prefix, 'payload' => ['reply_to' => $plan['reply_to'], 'cc' => $cc, 'attachments' => $emailAttachments], 'meta' => ['event' => 'comunicacion_caso', 'communication_id' => $communicationId]]);
        if (!$queued) throw new \DomainException("No se pudo encolar el correo de {$to['name']}. No se envió nada.");
        $counts['email']++;
      }
      if ($usesWhatsApp && $to['phone'] !== '') {
        $prefix = "legal-comm:{$communicationId}:{$index}:whatsapp";
        $recipientId = $this->insertRecipient($communicationId, $to, 'to', 'whatsapp', $to['phone'], $subject, $letter, '', $prefix);
        $params = [$this->line($to['name'], 60), LegalCaseService::code($caseId), $this->line($subject, 200), $this->signature($actor)];
        $suffix = self::buttonSuffix(self::publicUrl($recipientId));
        $this->db->update($this->table('communication_recipients'), ['whatsapp_params' => (string) json_encode(['template' => self::WHATSAPP_TEMPLATE, 'body' => $params, 'button' => $suffix], JSON_UNESCAPED_UNICODE)], ['id' => $recipientId]);
        $queued = $this->gateway->whatsapp($to['phone'], $to['name'], 'Comunicación del caso ' . $params[1] . ': ' . $params[2], ['source_module' => 'casos_juridicos', 'dedupe_key' => $prefix, 'template_name' => self::WHATSAPP_TEMPLATE, 'template_language' => 'es_CO', 'template_components' => [
          ['type' => 'body', 'parameters' => array_map(static fn(string $value): array => ['type' => 'text', 'text' => $value], $params)],
          ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
        ]]);
        if (!$queued) throw new \DomainException("No se pudo encolar el WhatsApp de {$to['name']}. No se envió nada.");
        $counts['whatsapp']++;
      }
    }
    $this->db->update($this->cases->table(), ['updated_at' => $now], ['id' => $caseId]);
    return $counts;
  }

  /** @param array<string,string> $person */
  private function insertRecipient(int $communicationId, array $person, string $kind, string $channel, string $destination, string $subject, string $letter, string $sentHtml, string $prefix): int
  {
    $this->db->insert($this->table('communication_recipients'), [
      'communication_id' => $communicationId, 'party_id' => (int) ($person['party_id'] ?? 0), 'kind' => $kind, 'channel' => $channel, 'name' => $person['name'], 'destination' => $destination,
      'rendered_subject' => $subject, 'rendered_html' => $letter, 'sent_html' => $sentHtml, 'dedupe_key' => $prefix,
    ]);
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $input @param array<string,string> $actor @return array<string,mixed> */
  private function plan(int $caseId, array $input, array $actor): array
  {
    $detail = $this->cases->detail($caseId);
    $channels = array_values(array_intersect(array_keys(self::CHANNEL_LABELS), (array) ($input['channels'] ?? [])));
    if ($channels === []) throw new \DomainException('Elige al menos un canal.');
    $subject = trim(preg_replace('/\s+/u', ' ', (string) ($input['subject'] ?? '')) ?? '');
    if ($subject === '' || mb_strlen($subject) > 200) throw new \DomainException('Escribe el asunto (máximo 200 caracteres).');
    $body = LegalHtmlSanitizer::clean((string) ($input['body'] ?? ''));
    if (LegalHtmlSanitizer::isEmpty($body)) throw new \DomainException('Escribe el contenido de la comunicación.');
    if (strlen($body) > 200000) throw new \DomainException('El contenido es demasiado largo.');
    $this->assertKnownVariables($subject . ' ' . $body);
    if ($detail['case']['contract_code'] === '' && preg_match('/\{\{\s*numero_contrato\s*\}\}/', $subject . $body) === 1) throw new \DomainException('Este caso no tiene contrato: quita la variable {{numero_contrato}}.');
    if ($detail['case']['address'] === '' && preg_match('/\{\{\s*direccion_inmueble\s*\}\}/', $subject . $body) === 1) throw new \DomainException('Este caso no tiene dirección de inmueble: quita la variable {{direccion_inmueble}}.');
    [$to, $cc] = $this->recipients($detail, (array) ($input['recipients'] ?? []), $channels);
    $replyTo = '';
    if (array_filter($to, static fn(array $person): bool => $person['email'] !== '') !== []) {
      $replyTo = strtolower(trim($actor['email']));
      if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) throw new \DomainException('Tu ficha de funcionario no tiene un correo válido para recibir las respuestas.');
    }
    return ['detail' => $detail, 'channels' => $channels, 'subject' => $subject, 'body' => $body, 'recipients' => $to, 'cc' => $cc, 'reply_to' => $replyTo];
  }

  /**
   * Los datos de las partes salen del servidor; del navegador solo se acepta a quién se elige y los contactos manuales.
   * @param array<string,mixed> $detail @param array<int|string,mixed> $raw @param list<string> $channels
   * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
   */
  private function recipients(array $detail, array $raw, array $channels): array
  {
    if (count($raw) > self::MAX_RECIPIENTS) throw new \DomainException('Máximo ' . self::MAX_RECIPIENTS . ' destinatarios por comunicación.');
    $parties = array_column($detail['parties'], null, 'id');
    $wantsEmail = in_array('email', $channels, true);
    $wantsPhone = in_array('whatsapp', $channels, true);
    $to = [];
    $cc = [];
    $seen = [];
    foreach ($raw as $item) {
      if (!is_array($item)) continue;
      $partyId = (int) ($item['party_id'] ?? 0);
      if ($partyId > 0) {
        if (!isset($parties[$partyId])) throw new \DomainException('Una de las partes elegidas ya no está en el caso.');
        $name = (string) $parties[$partyId]['name'];
        $email = (string) $parties[$partyId]['email'];
        $phone = (string) $parties[$partyId]['phone'];
      } else {
        $email = strtolower(trim((string) ($item['email'] ?? '')));
        $rawPhone = trim((string) ($item['phone'] ?? ''));
        $phone = $rawPhone === '' ? '' : PanelCaseNotifications::phone($rawPhone);
        $name = trim((string) ($item['name'] ?? '')) ?: ($email !== '' ? $email : $rawPhone);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \DomainException("El correo {$email} no es válido.");
        if ($rawPhone !== '' && $phone === '') throw new \DomainException("El celular {$rawPhone} no es válido.");
        if (mb_strlen($name) > 190) throw new \DomainException('El nombre del destinatario es demasiado largo.');
      }
      $person = ['party_id' => $partyId, 'name' => $name, 'email' => $wantsEmail ? $email : '', 'phone' => $wantsPhone ? $phone : ''];
      if ((string) ($item['kind'] ?? 'to') === 'cc') {
        if ($person['email'] === '') throw new \DomainException("{$name} no se puede poner en copia: requiere correo y el canal Correo.");
        $key = 'cc|' . $person['email'];
        if (!isset($seen[$key])) $cc[] = $person;
        $seen[$key] = true;
        continue;
      }
      if ($person['email'] === '' && $person['phone'] === '') throw new \DomainException("{$name} no tiene datos de contacto para los canales elegidos.");
      $key = 'to|' . $person['email'] . '|' . $person['phone'];
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $to[] = $person;
    }
    if ($to === []) throw new \DomainException('Elige al menos un destinatario principal.');
    $toEmails = array_filter(array_column($to, 'email'));
    if ($cc !== [] && $toEmails === []) throw new \DomainException('Las copias requieren un destinatario principal con correo.');
    $cc = array_values(array_filter($cc, static fn(array $copy): bool => !in_array($copy['email'], $toEmails, true)));
    return [$to, $cc];
  }

  /**
   * El proveedor SMTP omite en silencio los adjuntos ilegibles, así que aquí se verifica que existan y sigan intactos.
   * @param array<string,mixed> $detail @param array<string,mixed> $input @param list<array<string,mixed>> $uploads
   * @return list<array{document_id:int,name:string,stored_name:string,mime:string,bytes:int,sha256:string}>
   */
  private function attachments(array $detail, array $input, array $uploads, bool $byEmail): array
  {
    $documents = array_column($detail['documents'], null, 'id');
    $list = [];
    foreach (array_unique(array_map('intval', (array) ($input['document_ids'] ?? []))) as $documentId) {
      if (!isset($documents[$documentId])) throw new \DomainException('Uno de los documentos elegidos no pertenece al caso.');
      $document = $documents[$documentId];
      $list[] = ['document_id' => $documentId, 'name' => $document['name'], 'stored_name' => $document['stored_name'], 'mime' => $document['mime'], 'bytes' => $document['bytes'], 'sha256' => $document['sha256']];
    }
    foreach ($uploads as $upload) {
      $list[] = ['document_id' => 0, 'name' => (string) $upload['original_name'], 'stored_name' => (string) $upload['name'], 'mime' => (string) ($upload['mime'] ?? ''), 'bytes' => (int) ($upload['bytes'] ?? 0), 'sha256' => (string) ($upload['sha256'] ?? '')];
    }
    foreach ($list as $item) {
      $path = $this->files->pathFor($item['stored_name']);
      if ($path === null || !is_readable($path)) throw new \DomainException("No se encontró el archivo «{$item['name']}». No se envió nada.");
      if ($item['sha256'] !== '' && !hash_equals($item['sha256'], (string) hash_file('sha256', $path))) throw new \DomainException("El archivo «{$item['name']}» cambió desde que se cargó. No se envió nada.");
    }
    if ($byEmail && array_sum(array_column($list, 'bytes')) > self::MAX_ATTACHMENT_BYTES) throw new \DomainException('Los adjuntos superan 20 MB, el máximo seguro para un correo.');
    return $list;
  }

  /** @param array<string,mixed> $detail @param array<string,string> $actor @return array<string,string> */
  private function variables(array $detail, string $addressee, array $actor): array
  {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
    return [
      'nombre_destinatario' => $addressee, 'numero_contrato' => (string) $detail['case']['contract_code'], 'direccion_inmueble' => (string) $detail['case']['address'],
      'fecha' => $now->format('j') . ' de ' . self::MONTHS[(int) $now->format('n') - 1] . ' de ' . $now->format('Y'), 'codigo_caso' => (string) $detail['case']['code'], 'funcionario' => $actor['name'],
    ];
  }

  /** @param array<string,string> $vars */
  private function render(string $text, array $vars, bool $html): string
  {
    return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn(array $match): string => $html ? htmlspecialchars($vars[$match[1]] ?? '', ENT_QUOTES, 'UTF-8') : ($vars[$match[1]] ?? ''), $text);
  }

  /** @param array<string,string> $actor */
  private function letter(string $body, array $actor): string
  {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $lines = array_filter(['<strong>' . $e($actor['name']) . '</strong>', $e($actor['cargo'] ?? ''), ($phone = $this->nationalPhone($actor['phone'] ?? '')) !== '' ? 'Cel. ' . $e($phone) : '', 'SKC SuCasa Inmobiliaria']);
    return $body . '<p>Atentamente,<br>' . implode('<br>', $lines) . '</p>';
  }

  private function nationalPhone(string $phone): string
  {
    $digits = (string) preg_replace('/\D+/', '', $phone);
    return strlen($digits) === 12 && str_starts_with($digits, '57') ? substr($digits, 2) : $digits;
  }

  /** @param array<string,string> $actor */
  private function signature(array $actor): string
  {
    $phone = $this->nationalPhone($actor['phone'] ?? '');
    return implode(' - ', array_filter([$this->line($actor['name'], 80), $this->line($actor['cargo'] ?? '', 80), $phone !== '' ? 'Cel. ' . $phone : '']));
  }

  /** Meta rechaza variables con saltos de línea o más de tres espacios seguidos. */
  private function line(string $value, int $max): string
  {
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
  }

  // --- Enlace público

  public static function linkSignature(int $recipientId, int $expires): string
  {
    return hash_hmac('sha256', 'legal-communication|' . $recipientId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  public static function publicUrl(int $recipientId, ?int $expires = null): string
  {
    $expires ??= time() + self::LINK_SECONDS;
    return SCM_BASE_URL . '/comunicacion-caso.php?numero=' . $recipientId . '&expires=' . $expires . '&sig=' . self::linkSignature($recipientId, $expires);
  }

  public static function validLink(int $recipientId, int $expires, string $signature): bool
  {
    return $recipientId > 0 && $expires > time() && hash_equals(self::linkSignature($recipientId, $expires), $signature);
  }

  private static function buttonSuffix(string $url): string
  {
    $parts = parse_url($url);
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    return strtolower((string) ($parts['host'] ?? '')) === 'sucasainmobiliaria.com.co' && $path !== '' ? $path . '?' . (string) ($parts['query'] ?? '') : $url;
  }

  /** @return array<string,mixed>|null */
  public function publicView(int $recipientId): ?array
  {
    $row = $this->db->getRow(
      "SELECT r.`rendered_subject`, r.`rendered_html`, r.`name`, c.`case_id`, c.`attachments_json`, c.`created_at` FROM `{$this->table('communication_recipients')}` r
        JOIN `{$this->table('communications')}` c ON c.`id` = r.`communication_id` WHERE r.`id` = ? LIMIT 1",
      [$recipientId]
    );
    if ($row === null) return null;
    return ['subject' => (string) $row['rendered_subject'], 'html' => (string) $row['rendered_html'], 'name' => (string) $row['name'], 'code' => LegalCaseService::code((int) $row['case_id']), 'created_at' => (int) $row['created_at'], 'attachments' => $this->presentAttachments((string) $row['attachments_json'])];
  }

  /** @return list<array{name:string,url:string,mime:string,bytes:int,sha256:string}> */
  private function presentAttachments(string $json): array
  {
    $items = json_decode($json, true);
    return array_map(fn(array $item): array => ['name' => (string) $item['name'], 'url' => $this->files->urlFor((string) $item['stored_name']), 'mime' => (string) $item['mime'], 'bytes' => (int) $item['bytes'], 'sha256' => (string) $item['sha256']], is_array($items) ? $items : []);
  }

  // --- Consulta y estados

  /** Trae de la cola lo que siga pendiente; solo toca los campos de estado. @param list<int> $communicationIds */
  public function syncStatuses(array $communicationIds): void
  {
    if ($communicationIds === []) return;
    $marks = implode(',', array_fill(0, count($communicationIds), '?'));
    $pending = $this->db->getResults("SELECT `id`, `dedupe_key` FROM `{$this->table('communication_recipients')}` WHERE `communication_id` IN ({$marks}) AND `status` = 'en_cola'", $communicationIds);
    foreach ($pending as $row) {
      $found = $this->gateway->lookup((string) $row['dedupe_key']);
      if ($found === null) continue;
      $status = self::QUEUE_STATUS[$found['status']] ?? 'en_cola';
      $this->db->update($this->table('communication_recipients'), ['status' => $status, 'last_error' => mb_substr($found['error'], 0, 500), 'accepted_at' => $found['sent_at'] > 0 ? $found['sent_at'] : null, 'provider_message_id' => $found['provider_id'], 'checked_at' => time()], ['id' => (int) $row['id']]);
    }
  }

  /** @return array<string,mixed> */
  public function communication(int $id): array
  {
    $row = $this->db->getRow("SELECT * FROM `{$this->table('communications')}` WHERE `id` = ? LIMIT 1", [$id]);
    if ($row === null) throw new \DomainException('La comunicación no existe.');
    $this->syncStatuses([$id]);
    $recipients = $this->db->getResults("SELECT * FROM `{$this->table('communication_recipients')}` WHERE `communication_id` = ? ORDER BY `id`", [$id]);
    return $this->presentCommunication($row) + [
      'body_html' => (string) $row['body_html'],
      'reply_to' => (string) $row['reply_to'],
      'whatsapp_template' => (string) $row['whatsapp_template'],
      'attachments' => $this->presentAttachments((string) $row['attachments_json']),
      'recipients' => array_map(function (array $recipient): array {
        $params = json_decode((string) ($recipient['whatsapp_params'] ?? ''), true);
        return [
          'id' => (int) $recipient['id'], 'kind' => (string) $recipient['kind'], 'channel' => (string) $recipient['channel'], 'channel_label' => self::CHANNEL_LABELS[$recipient['channel']] ?? (string) $recipient['channel'],
          'name' => (string) $recipient['name'], 'destination' => (string) $recipient['destination'], 'subject' => (string) $recipient['rendered_subject'], 'html' => (string) $recipient['rendered_html'],
          'status' => (string) $recipient['status'], 'status_label' => self::STATUS_LABELS[$recipient['status']] ?? (string) $recipient['status'], 'error' => (string) $recipient['last_error'],
          'provider_id' => (string) $recipient['provider_message_id'], 'accepted_at' => (int) ($recipient['accepted_at'] ?? 0), 'whatsapp_body' => is_array($params) ? ($params['body'] ?? []) : [],
        ];
      }, $recipients),
    ];
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function presentCommunication(array $row): array
  {
    return ['id' => (int) $row['id'], 'case_id' => (int) $row['case_id'], 'case_code' => LegalCaseService::code((int) $row['case_id']), 'subject' => (string) $row['subject'], 'channels' => array_map(static fn(string $channel): string => self::CHANNEL_LABELS[$channel] ?? $channel, explode(',', (string) $row['channels'])), 'sender_name' => (string) $row['sender_name'], 'created_at' => (int) $row['created_at']];
  }

  /**
   * Vista global "Comunicaciones enviadas".
   * @param array<string,string> $filters @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
   */
  public function list(array $filters, int $page = 1): array
  {
    $comm = $this->table('communications');
    $recipients = $this->table('communication_recipients');
    $where = [];
    $args = [];
    $recipient = trim((string) ($filters['recipient'] ?? ''));
    if ($recipient !== '') {
      $like = '%' . $this->db->escapeLike($recipient) . '%';
      $where[] = "EXISTS (SELECT 1 FROM `{$recipients}` r WHERE r.`communication_id` = c.`id` AND (r.`name` LIKE ? OR r.`destination` LIKE ?))";
      array_push($args, $like, $like);
    }
    $case = trim((string) ($filters['case'] ?? ''));
    if ($case !== '') {
      $like = '%' . $this->db->escapeLike($case) . '%';
      $clause = "EXISTS (SELECT 1 FROM `{$this->cases->table()}` k WHERE k.`id` = c.`case_id` AND k.`name` LIKE ?)";
      $args[] = $like;
      if (preg_match('/^(?:caso-?)?0*([1-9][0-9]*)$/i', $case, $match) === 1) {
        $clause .= ' OR c.`case_id` = ?';
        $args[] = (int) $match[1];
      }
      $where[] = "({$clause})";
    }
    $channel = (string) ($filters['channel'] ?? '');
    if (isset(self::CHANNEL_LABELS[$channel])) {
      $where[] = "EXISTS (SELECT 1 FROM `{$recipients}` r WHERE r.`communication_id` = c.`id` AND r.`channel` = ?)";
      $args[] = $channel;
    }
    foreach ([['from', '>=', ' 00:00:00'], ['to', '<=', ' 23:59:59']] as [$key, $operator, $time]) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value === '') continue;
      $timestamp = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value . $time, new \DateTimeZone('America/Bogota'));
      if (!$timestamp) throw new \DomainException('La fecha del filtro no es válida.');
      $where[] = "c.`created_at` {$operator} ?";
      $args[] = $timestamp->getTimestamp();
    }
    $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$comm}` c{$sqlWhere}", $args);
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = min(max(1, $page), $pages);
    $rows = $this->db->getResults("SELECT c.* FROM `{$comm}` c{$sqlWhere} ORDER BY c.`id` DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $args);
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $this->syncStatuses($ids);
    $grouped = [];
    if ($ids !== []) {
      $marks = implode(',', array_fill(0, count($ids), '?'));
      foreach ($this->db->getResults("SELECT `communication_id`, `name`, `kind`, `status` FROM `{$recipients}` WHERE `communication_id` IN ({$marks}) ORDER BY `id`", $ids) as $row) $grouped[(int) $row['communication_id']][] = $row;
    }
    return [
      'rows' => array_map(function (array $row) use ($grouped): array {
        $people = $grouped[(int) $row['id']] ?? [];
        $statuses = array_count_values(array_column($people, 'status'));
        $names = array_values(array_unique(array_column(array_filter($people, static fn(array $person): bool => $person['kind'] === 'to'), 'name')));
        return $this->presentCommunication($row) + ['recipients' => $names, 'failed' => (int) ($statuses['fallido'] ?? 0), 'queued' => (int) ($statuses['en_cola'] ?? 0), 'accepted' => (int) ($statuses['enviado'] ?? 0)];
      }, $rows),
      'total' => $total, 'page' => $page, 'pages' => $pages,
    ];
  }
}
