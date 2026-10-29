<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Core\Database;
use SCM\Modules\TicketCompletion\CompletionDelivery;
use SCM\Modules\TicketCompletion\CompletionPolicy;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\HtmlPdfRenderer;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

/**
 * Pagaré en blanco + carta de instrucciones de cada persona que recibe créditos. Se firma una sola vez por número de
 * documento y respalda todos sus créditos. Se pide al aprobar un crédito; la respuesta al propietario (carta de
 * aprobación, pagaré firmado y estado de cuenta) sale cuando cada firmante elegido lo tiene firmado.
 *
 * Firma como el acta de satisfacción: enlace personal con token HMAC y vencimiento, nombre y documento escritos,
 * código de 6 dígitos por correo o WhatsApp y aceptación expresa. La evidencia (IP, navegador, huella del documento)
 * y el PDF firmado se guardan con su HMAC; un pagaré firmado solo se anula con nota y queda en el historial.
 */
final class OwnerCreditPromissoryService
{
  public const STATUSES = ['pendiente' => 'Pendiente de firma', 'firmado' => 'Firmado', 'anulado' => 'Anulado'];
  private const LINK_DAYS = 30;
  /** @var array<string,bool> */
  private static array $schemaReady = [];
  private SchemaInspector $schema;
  /** @var \Closure(string,string,array<string,mixed>):int */
  private \Closure $enqueue;
  /** @var \Closure(array<string,mixed>,array<string,mixed>):string */
  private \Closure $renderPdf;
  /** @var (\Closure(array<string,mixed>):bool)|null tras firmar; true = la respuesta del crédito ya le lleva su copia */
  private ?\Closure $afterSign = null;

  public function __construct(private Database $db, private string $secret, private string $baseUrl, ?\Closure $enqueue = null, ?\Closure $renderPdf = null, ?SchemaInspector $schema = null)
  {
    $this->schema = $schema ?: new SchemaInspector($db);
    $this->enqueue = $enqueue ?? fn(string $channel, string $to, array $message): int => $this->deliver($channel, $to, $message);
    $this->renderPdf = $renderPdf ?? static fn(array $row, array $payload): string => self::pdfBytes($row, $payload);
  }

  public function table(): string
  {
    return $this->db->table('scm_owner_credit_promissory');
  }

  /** Lo que sigue al firmar (la respuesta de los créditos aprobados que esperaban la firma). */
  public function afterSign(\Closure $callback): void
  {
    $this->afterSign = $callback;
  }

  public function ensureSchema(): void
  {
    if (isset(self::$schemaReady[$this->table()])) return;
    self::$schemaReady[$this->table()] = true;
    if ($this->schema->tableExists($this->table())) return;
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_document VARCHAR(40) NOT NULL, owner_id VARCHAR(60) NOT NULL DEFAULT '', owner_name VARCHAR(190) NOT NULL DEFAULT '',
      owner_email VARCHAR(190) NOT NULL DEFAULT '', owner_phone VARCHAR(40) NOT NULL DEFAULT '',
      credit_id BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      payload_json MEDIUMTEXT NOT NULL, payload_hash CHAR(64) NOT NULL, token_nonce CHAR(64) NOT NULL, expires_at BIGINT NOT NULL,
      channels VARCHAR(40) NOT NULL DEFAULT 'email', otp_json TEXT NULL, delivery_json MEDIUMTEXT NULL,
      signed_at BIGINT NULL, signed_json MEDIUMTEXT NULL, signed_pdf MEDIUMBLOB NULL, pdf_hash CHAR(64) NULL, pdf_hmac CHAR(64) NULL,
      voided_at BIGINT NULL, void_note TEXT NULL, voided_by_name VARCHAR(190) NOT NULL DEFAULT '',
      created_by_id VARCHAR(40) NOT NULL DEFAULT '', created_by_name VARCHAR(190) NOT NULL DEFAULT '',
      created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
      KEY idx_document (owner_document, status), KEY idx_credit (credit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  public static function normalizeDocument(string $value): string
  {
    return strtoupper(preg_replace('/[\s.]+/', '', trim($value)) ?? '');
  }

  /** Pagaré vigente (firmado o pendiente) de la persona; el firmado manda. @return array<string,mixed>|null */
  public function current(string $document): ?array
  {
    $this->ensureSchema();
    $document = self::normalizeDocument($document);
    if ($document === '') return null;
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `owner_document` = ? AND `status` IN ('firmado', 'pendiente') ORDER BY (`status` = 'firmado') DESC, `id` DESC LIMIT 1", [$document]);
    return is_array($row) ? $row : null;
  }

  /**
   * Último pagaré firmado de cada documento, para el listado de créditos.
   * @param list<string> $documents @return array<string,int> documento normalizado => id del pagaré
   */
  public function signedByDocuments(array $documents): array
  {
    $documents = array_values(array_unique(array_filter(array_map([self::class, 'normalizeDocument'], $documents))));
    if ($documents === []) return [];
    $this->ensureSchema();
    $signed = [];
    foreach ($this->db->getResults("SELECT `id`, `owner_document` FROM `{$this->table()}` WHERE `status` = 'firmado' AND `owner_document` IN (" . implode(',', array_fill(0, count($documents), '?')) . ') ORDER BY `id` DESC', $documents) as $row) {
      $signed[(string) $row['owner_document']] ??= (int) $row['id'];
    }
    return $signed;
  }

  /** @return array<string,mixed> */
  public function find(int $id): array
  {
    $this->ensureSchema();
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1", [$id]);
    if (!is_array($row)) throw new \DomainException('El pagaré no existe.');
    return $row;
  }

  /**
   * Estado de cada firmante: firmado (sirve para todos sus créditos), pendiente (enviado) o sin enviar.
   * @param list<array<string,mixed>> $signers dueños elegidos: key, name, document, email, phone, owner_id
   * @return list<array<string,mixed>>
   */
  public function statusFor(array $signers): array
  {
    return array_map(function (array $signer): array {
      $row = $this->current((string) $signer['document']);
      return $signer + [
        'status' => $row['status'] ?? 'sin_enviar',
        'promissory_id' => (int) ($row['id'] ?? 0),
        'number' => $row !== null ? self::number((int) $row['id']) : '',
        'signed_at' => (int) ($row['signed_at'] ?? 0),
        'sent_at' => (int) ($row['updated_at'] ?? 0),
        'expired' => $row !== null && $row['status'] === 'pendiente' && (int) $row['expires_at'] < time(),
        'url' => $row !== null ? $this->staffUrl((int) $row['id']) : '',
      ];
    }, $signers);
  }

  /**
   * Envía (o reenvía) el pagaré a quien aún no lo tiene firmado. Un pendiente se reenvía con enlace renovado; uno
   * firmado no se vuelve a pedir.
   * @param array<string,mixed> $signer @param list<string> $channels email|whatsapp
   * @return array{id:int,status:string,queued:array<string,bool>}
   */
  public function send(array $signer, int $creditId, array $channels, array $actor): array
  {
    $this->ensureSchema();
    $document = self::normalizeDocument((string) ($signer['document'] ?? ''));
    if ($document === '' || strlen($document) < 4) throw new \DomainException('Registra el número de documento de ' . ($signer['name'] ?: 'el propietario') . ' antes de enviarle el pagaré.');
    $channels = array_values(array_intersect(['email', 'whatsapp'], $channels));
    if (in_array('email', $channels, true) && !filter_var((string) $signer['email'], FILTER_VALIDATE_EMAIL)) $channels = array_values(array_diff($channels, ['email']));
    if (in_array('whatsapp', $channels, true) && CompletionPolicy::phone((string) $signer['phone']) === '') $channels = array_values(array_diff($channels, ['whatsapp']));
    if ($channels === []) throw new \DomainException((string) $signer['name'] . ' no tiene correo ni celular válidos para enviarle el pagaré.');
    $current = $this->current($document);
    if ($current !== null && $current['status'] === 'firmado') return ['id' => (int) $current['id'], 'status' => 'firmado', 'queued' => []];
    $now = time();
    if ($current === null) {
      $this->db->insert($this->table(), [
        'owner_document' => $document, 'owner_id' => mb_substr((string) ($signer['owner_id'] ?? ''), 0, 60), 'owner_name' => mb_substr((string) $signer['name'], 0, 190),
        'owner_email' => mb_substr((string) $signer['email'], 0, 190), 'owner_phone' => mb_substr((string) $signer['phone'], 0, 40),
        'credit_id' => $creditId, 'status' => 'pendiente', 'payload_json' => '{}', 'payload_hash' => str_repeat('0', 64), 'token_nonce' => bin2hex(random_bytes(32)),
        'expires_at' => $now + self::LINK_DAYS * 86400, 'channels' => implode(',', $channels),
        'created_by_id' => (string) ($actor['employee_id'] ?? ''), 'created_by_name' => (string) ($actor['name'] ?? ''), 'created_at' => $now, 'updated_at' => $now,
      ]);
      $id = (int) $this->db->lastInsertId();
      // El número del pagaré sale del id: se arma el documento ya con su número y se guarda su huella.
      $payload = $this->payload($id, $signer, $document, $creditId, $actor, $now);
      $this->db->update($this->table(), ['payload_json' => self::json($payload), 'payload_hash' => hash('sha256', self::json($payload))], ['id' => $id]);
    } else {
      $id = (int) $current['id'];
      // Reenvío: enlace nuevo (el anterior deja de servir) y contactos actualizados; el documento no cambia.
      $this->db->update($this->table(), [
        'token_nonce' => bin2hex(random_bytes(32)), 'expires_at' => $now + self::LINK_DAYS * 86400, 'channels' => implode(',', $channels),
        'owner_email' => mb_substr((string) $signer['email'], 0, 190), 'owner_phone' => mb_substr((string) $signer['phone'], 0, 40), 'otp_json' => null, 'updated_at' => $now,
      ], ['id' => $id]);
    }
    $row = $this->find($id);
    $queued = [];
    $url = $this->publicUrl($row);
    foreach ($channels as $channel) {
      $text = 'Hola ' . $row['owner_name'] . ', te enviamos el pagaré No. ' . self::number($id) . ' y su carta de instrucciones para que los revises y firmes electrónicamente. Se firman una sola vez y respaldan tus créditos del Pasaporte SuCasa. Enlace personal: ' . $url;
      $queued[$channel] = ($this->enqueue)($channel, $channel === 'email' ? (string) $row['owner_email'] : (string) $row['owner_phone'], [
        'event' => 'invitation', 'row' => $row, 'url' => $url, 'text' => $text,
        'subject' => 'Firma del pagaré No. ' . self::number($id) . ' · SKC SuCasa Inmobiliaria',
        'html' => '<p>Hola ' . EmailTemplate::e($row['owner_name']) . ',</p>' . ($creditId > 0 ? '<p>Tu solicitud de crédito <b>#' . $creditId . '</b> fue aprobada. Para continuar, firma el pagaré: al quedar firmado te enviaremos la carta de aprobación, el pagaré firmado y el estado de cuenta.</p>' : '')
          . '<p>Te enviamos el <b>pagaré No. ' . self::number($id) . '</b> y su <b>carta de instrucciones</b> para que los revises y firmes electrónicamente. Se firman una sola vez y respaldan los créditos que tomes con el Pasaporte SuCasa.</p><p>Para firmar necesitarás tu nombre, tu número de documento y un código que te enviaremos al abrir el enlace.</p>',
        'button' => ['url' => $url, 'label' => 'Revisar y firmar'],
        'key' => 'invitation:' . $row['token_nonce'],
      ]) > 0;
    }
    $this->mergeDelivery($id, 'invitation', $queued);
    return ['id' => $id, 'status' => 'pendiente', 'queued' => $queued];
  }

  /** @return array<string,mixed> */
  private function payload(int $id, array $signer, string $document, int $creditId, array $actor, int $now): array
  {
    return [
      'version' => OwnerCreditPromissoryDocument::VERSION, 'number' => self::number($id), 'credit_id' => $creditId, 'created_at' => $now,
      'creditor' => OwnerCreditPromissoryDocument::CREDITOR,
      'debtor' => ['name' => (string) $signer['name'], 'document' => $document, 'owner_id' => (string) ($signer['owner_id'] ?? '')],
      'actor' => ['employee_id' => (string) ($actor['employee_id'] ?? ''), 'name' => (string) ($actor['name'] ?? '')],
    ];
  }

  public static function number(int $id): string
  {
    return 'PS-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
  }

  public function token(array $row): string
  {
    return hash_hmac('sha256', 'owner-credit-promissory|' . (int) $row['id'] . '|' . $row['token_nonce'], $this->secret);
  }

  public function publicUrl(array $row): string
  {
    return rtrim($this->baseUrl, '/') . '/credito-pagare.php?id=' . (int) $row['id'] . '&token=' . $this->token($row);
  }

  public function staffUrl(int $id): string
  {
    return rtrim($this->baseUrl, '/') . '/credito-pagare.php?id=' . $id;
  }

  /** Acceso del firmante: token del enlace vigente; un firmado se puede consultar mientras el enlace no venza. */
  public function publicRow(int $id, string $token): array
  {
    $row = $this->find($id);
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($this->token($row), $token)) throw new \DomainException('El enlace no es válido. Pide a la inmobiliaria que te lo envíe de nuevo.');
    if ($row['status'] === 'anulado') throw new \DomainException('Este pagaré fue anulado. Si necesitas firmar uno nuevo, la inmobiliaria te enviará otro enlace.');
    if ((int) $row['expires_at'] < time()) throw new \DomainException('El enlace venció. Pide a la inmobiliaria que te lo envíe de nuevo.');
    return $row;
  }

  /** @return array<string,mixed> */
  public function payloadOf(array $row): array
  {
    $json = (string) $row['payload_json'];
    if (!hash_equals((string) $row['payload_hash'], hash('sha256', $json))) throw new \DomainException('El documento no coincide con su huella; no se puede usar.');
    return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
  }

  /** @return array<string,string> canal => etiqueta enmascarada */
  public function verificationChannels(array $row): array
  {
    $out = [];
    if (filter_var((string) $row['owner_email'], FILTER_VALIDATE_EMAIL)) {
      [$user, $domain] = explode('@', (string) $row['owner_email'], 2);
      $out['email'] = 'Correo: ' . mb_substr($user, 0, 2) . '***@' . $domain;
    }
    if (CompletionDelivery::otpTemplate() !== '' && CompletionPolicy::phone((string) $row['owner_phone']) !== '') $out['whatsapp'] = 'WhatsApp: ***' . substr(preg_replace('/\D/', '', (string) $row['owner_phone']) ?? '', -4);
    return $out;
  }

  public function requestCode(int $id, string $token, string $channel): array
  {
    return $this->locked($id, function () use ($id, $token, $channel): array {
      $row = $this->publicRow($id, $token);
      if ($row['status'] !== 'pendiente') throw new \DomainException('Este pagaré ya no está pendiente de firma.');
      if (!isset($this->verificationChannels($row)[$channel])) throw new \DomainException('Canal de verificación no disponible. Usa el correo registrado o contacta a la inmobiliaria.');
      return (new OwnerCreditPromissoryVerification($this->db, $this->table(), $this->secret))->request($row, $channel, function (string $code, string $nonce) use ($row, $channel): bool {
        $text = 'Tu código para firmar el pagaré No. ' . self::number((int) $row['id']) . ' es ' . $code . '. Vence en 10 minutos. No lo compartas. Si no lo solicitaste, ignora este mensaje.';
        return ($this->enqueue)($channel, $channel === 'email' ? (string) $row['owner_email'] : (string) $row['owner_phone'], [
          'event' => 'otp', 'row' => $row, 'code' => $code, 'text' => $text, 'subject' => 'Código de firma del pagaré No. ' . self::number((int) $row['id']),
          'html' => '<p>' . EmailTemplate::e($text) . '</p>', 'key' => 'otp:' . $nonce,
        ]) > 0;
      });
    });
  }

  /**
   * Firma: nombre y documento como en el pagaré, código vigente, aceptación expresa y la misma huella que se mostró.
   * @param array<string,mixed> $input
   * @return array<string,mixed> fila firmada
   */
  public function sign(int $id, string $token, array $input, string $ip, string $userAgent): array
  {
    // El código se valida fuera de la transacción: un intento fallido debe quedar contado aunque la firma no siga.
    $row = $this->publicRow($id, $token);
    $verification = null;
    if ($row['status'] === 'pendiente') {
      self::signature($input, $this->payloadOf($row)['debtor']);
      $verification = (new OwnerCreditPromissoryVerification($this->db, $this->table(), $this->secret))->verify($row, $input['otp_code'] ?? '');
    }
    $row = $this->locked($id, function () use ($id, $token, $input, $ip, $userAgent, $verification): array {
      $row = $this->publicRow($id, $token);
      if ($row['status'] === 'firmado') return $row;
      if ($row['status'] !== 'pendiente' || $verification === null) throw new \DomainException('Este pagaré ya no está pendiente de firma.');
      $payload = $this->payloadOf($row);
      if (!hash_equals((string) $row['payload_hash'], (string) ($input['document_hash'] ?? ''))) throw new \DomainException('La versión del documento no coincide. Recarga y revísalo nuevamente.');
      $signature = self::signature($input, $payload['debtor']);
      $signature['verification'] = $verification;
      $signature['signed_at'] = time();
      $signature['ip'] = substr($ip, 0, 45);
      $signature['user_agent'] = substr($userAgent, 0, 512);
      $signature['document_hash'] = $row['payload_hash'];
      $signature['token_fingerprint'] = hash('sha256', $token);
      $signature['evidence_hmac'] = hash_hmac('sha256', self::json($signature), $this->secret);
      $this->db->update($this->table(), ['status' => 'firmado', 'signed_at' => $signature['signed_at'], 'signed_json' => self::json($signature), 'otp_json' => null, 'expires_at' => $signature['signed_at'] + self::LINK_DAYS * 86400, 'updated_at' => $signature['signed_at']], ['id' => $id]);
      $signedRow = $this->find($id);
      // El PDF firmado se guarda con su HMAC; si no se puede generar, la firma no se registra.
      $pdf = ($this->renderPdf)($signedRow, $payload);
      $hash = hash('sha256', $pdf);
      $this->db->update($this->table(), ['signed_pdf' => $pdf, 'pdf_hash' => $hash, 'pdf_hmac' => hash_hmac('sha256', $id . '|' . $row['payload_hash'] . '|' . $hash, $this->secret)], ['id' => $id]);
      return $this->find($id);
    });
    if ($verification !== null) {
      $handled = false;
      try {
        $handled = $this->afterSign !== null && ($this->afterSign)($row);
      } catch (\Throwable $error) {
        error_log('[owner-credit-promissory] respuesta tras firmar: ' . $error->getMessage());
      }
      $this->notifySigned($row, !$handled);
    }
    return $row;
  }

  /** @param array<string,mixed> $debtor @return array<string,mixed> */
  public static function signature(array $input, array $debtor): array
  {
    if (($input['accepted'] ?? '') !== '1') throw new \DomainException('Debes leer y aceptar expresamente el pagaré y la carta de instrucciones para firmarlos.');
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['signature_name'] ?? '')) ?? '');
    if ($name === '' || mb_strtolower($name) !== mb_strtolower(trim((string) $debtor['name']))) throw new \DomainException('Escribe tu nombre exactamente como aparece en el pagaré.');
    $document = self::normalizeDocument((string) ($input['document'] ?? ''));
    if ($document === '' || !hash_equals(self::normalizeDocument((string) $debtor['document']), $document)) throw new \DomainException('El número de documento no coincide con el del pagaré.');
    return ['name' => $name, 'document' => $document, 'method' => 'typed-name-document-otp-and-explicit-consent', 'consent_version' => '1', 'consent_text' => OwnerCreditPromissoryDocument::CONSENT];
  }

  /** PDF firmado guardado, verificado contra su HMAC. */
  public function pdf(array $row): string
  {
    if ($row['status'] !== 'firmado' || !is_string($row['signed_pdf']) || $row['signed_pdf'] === '') throw new \DomainException('El PDF solo está disponible cuando el pagaré está firmado.');
    $hash = hash('sha256', $row['signed_pdf']);
    if (!hash_equals((string) $row['pdf_hash'], $hash) || !hash_equals((string) $row['pdf_hmac'], hash_hmac('sha256', (int) $row['id'] . '|' . $row['payload_hash'] . '|' . $hash, $this->secret))) {
      throw new \DomainException('El PDF firmado no coincide con su registro; no se puede entregar.');
    }
    return $row['signed_pdf'];
  }

  /** Anula un pagaré (pendiente o firmado) con nota: la persona tendrá que firmar uno nuevo. */
  public function void(int $id, string $note, array $actor): array
  {
    $note = trim($note);
    if (mb_strlen($note) < 10) throw new \DomainException('Escribe el motivo de la anulación (mínimo 10 caracteres).');
    return $this->locked($id, function () use ($id, $note, $actor): array {
      $row = $this->find($id);
      if ($row['status'] === 'anulado') return $row;
      $this->db->update($this->table(), ['status' => 'anulado', 'voided_at' => time(), 'void_note' => $note, 'voided_by_name' => mb_substr((string) ($actor['name'] ?? ''), 0, 190), 'otp_json' => null, 'updated_at' => time()], ['id' => $id]);
      return $this->find($id);
    });
  }

  public static function pdfBytes(array $row, array $payload): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render(OwnerCreditPromissoryDocument::render($row, $payload, '', true), 'Pagaré No. ' . $payload['number'] . ' y carta de instrucciones', [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css', $root . '/public/assets/css/owner-credit-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF del pagaré. Intenta nuevamente en unos minutos; la firma solo se guarda si se genera el documento.');
    return $bytes;
  }

  /** Copia al firmante (si la respuesta del crédito no se la lleva) y aviso a los funcionarios de "Notificaciones internas". */
  private function notifySigned(array $row, bool $ownerCopy = true): void
  {
    $queued = [];
    $url = $this->publicUrl($row) . '&format=pdf';
    foreach ($ownerCopy ? array_filter(explode(',', (string) $row['channels'])) : [] as $channel) {
      $text = 'Hola ' . $row['owner_name'] . ', quedó firmado tu pagaré No. ' . self::number((int) $row['id']) . ' con su carta de instrucciones. Descarga tu copia: ' . $url;
      $queued[$channel] = ($this->enqueue)($channel, $channel === 'email' ? (string) $row['owner_email'] : (string) $row['owner_phone'], [
        'event' => 'signed', 'row' => $row, 'url' => $url, 'text' => $text, 'subject' => 'Pagaré No. ' . self::number((int) $row['id']) . ' firmado · SKC SuCasa Inmobiliaria',
        'html' => '<p>Hola ' . EmailTemplate::e($row['owner_name']) . ',</p><p>Quedó firmado tu <b>pagaré No. ' . self::number((int) $row['id']) . '</b> con su carta de instrucciones. Respalda los créditos que tomes con el Pasaporte SuCasa: no tendrás que firmarlo de nuevo.</p>',
        'button' => ['url' => $url, 'label' => 'Descargar copia firmada'], 'key' => 'signed',
      ]) > 0;
    }
    $this->mergeDelivery((int) $row['id'], 'signed', $queued);
    try {
      $copies = InternalNotificationRecipients::emailsForAction($this->db, 'credito_propietario_pagare_firmado');
      if ($copies === []) return;
      $title = 'Pagaré No. ' . self::number((int) $row['id']) . ' firmado por ' . $row['owner_name'];
      $details = $row['owner_name'] . ' (documento ' . $row['owner_document'] . ') firmó el pagaré en blanco y la carta de instrucciones' . ((int) $row['credit_id'] > 0 ? ', enviados con el crédito #' . (int) $row['credit_id'] : '') . '. Sus créditos aprobados ya pueden enviar la respuesta con los documentos.';
      (new EmailQueue($this->db))->enqueue($copies, $title, EmailTemplate::render($title, '<p>' . EmailTemplate::e($details) . '</p>', ['buttons' => [['url' => $this->staffUrl((int) $row['id']), 'label' => 'Ver pagaré firmado']]]), [
        'source_module' => 'credito_propietario_pagare_firmado', 'internal_action' => 'credito_propietario_pagare_firmado', 'internal_cc' => $copies,
        'dedupe_key' => 'internal-admin:credito_propietario_pagare_firmado:' . (int) $row['id'], 'meta' => ['event' => 'credito_propietario_pagare_firmado'],
      ]);
    } catch (\Throwable $error) {
      error_log('[owner-credit-promissory] aviso interno: ' . $error->getMessage());
    }
  }

  /**
   * Correo o WhatsApp por la cola compartida, intentando el envío inmediato. Las plantillas de WhatsApp se aprueban
   * en Meta (docs/whatsapp-credito-pagare-*.json) y se pueden cambiar por variable de entorno.
   * @param array<string,mixed> $m
   */
  private function deliver(string $channel, string $to, array $m): int
  {
    $row = $m['row'];
    $key = 'owner-credit-promissory:' . (int) $row['id'] . ':' . $m['event'] . ':' . $channel . ':' . $m['key'];
    try {
      if ($channel === 'email') {
        $vars = isset($m['button']) ? ['buttons' => [$m['button']]] : [];
        $count = (new EmailQueue($this->db))->enqueue($to, (string) $m['subject'], EmailTemplate::render((string) $m['subject'], (string) $m['html'], $vars), [
          'source_module' => 'owner-credit-promissory', 'dedupe_key' => $key, 'priority' => $m['event'] === 'otp' ? 200 : 100, 'meta' => ['event' => 'pagare_' . $m['event'], 'promissory_id' => (int) $row['id']],
        ]);
        $destination = strtolower(trim($to));
      } else {
        $phone = CompletionPolicy::phone($to);
        if ($phone === '') return 0;
        $number = self::number((int) $row['id']);
        [$template, $parameters] = match ($m['event']) {
          'otp' => [CompletionDelivery::otpTemplate(), [(string) $m['code']]],
          'invitation' => [self::template('SCM_PAGARE_WHATSAPP_INVITATION_TEMPLATE', 'scm_credito_pagare_firma_v1'), [(string) $row['owner_name'], $number, (string) $m['url']]],
          default => [self::template('SCM_PAGARE_WHATSAPP_SIGNED_TEMPLATE', 'scm_credito_pagare_firmado_v1'), [(string) $row['owner_name'], $number, (string) $m['url']]],
        };
        if ($template === '') return 0;
        $components = [['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $parameters)]];
        // Las plantillas de autenticación (código) llevan el código también en el botón de copiar.
        if ($m['event'] === 'otp') $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $m['code']]]];
        $count = (new SmsQueue($this->db))->enqueue($phone, (string) $row['owner_name'], (string) $m['text'], [
          'source_module' => 'owner-credit-promissory', 'dedupe_key' => $key, 'template_name' => $template,
          'template_language' => trim((string) getenv($m['event'] === 'otp' ? 'SCM_ACTA_WHATSAPP_OTP_LANGUAGE' : 'SCM_ACTA_WHATSAPP_LANGUAGE')) ?: 'es_CO',
          'template_components' => $components, 'priority' => $m['event'] === 'otp' ? 200 : 100, 'max_attempts' => 3, 'event' => 'pagare_' . $m['event'],
        ]) ? 1 : 0;
        $destination = $phone;
      }
      if ($count > 0) {
        try {
          (new \SCM\Support\SharedNotificationsBridge($this->db))->dispatchNow($key . ':' . $destination);
        } catch (\Throwable) {
          // Queda en la cola compartida para reintento.
        }
      }
      return $count;
    } catch (\Throwable $error) {
      error_log('[owner-credit-promissory] No se pudo encolar ' . $m['event'] . ' por ' . $channel . ' del pagaré #' . (int) $row['id'] . ': ' . $error->getMessage());
      return 0;
    }
  }

  private static function template(string $env, string $default): string
  {
    $name = trim((string) getenv($env));
    return preg_match('/^[a-z0-9_]{1,512}$/D', $name) ? $name : $default;
  }

  /** @param array<string,bool> $queued */
  private function mergeDelivery(int $id, string $event, array $queued): void
  {
    $row = $this->find($id);
    $delivery = json_decode((string) ($row['delivery_json'] ?? ''), true) ?: [];
    $delivery[$event][] = ['at' => time(), 'queued' => $queued];
    $this->db->update($this->table(), ['delivery_json' => self::json($delivery)], ['id' => $id]);
  }

  /** Serializa la operación sobre el pagaré (código, firma, anulación). */
  private function locked(int $id, callable $callback): mixed
  {
    $this->ensureSchema();
    $pdo = $this->db->pdo();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
      $this->db->getRow("SELECT `id` FROM `{$this->table()}` WHERE `id` = ? LIMIT 1 FOR UPDATE", [$id]);
      $result = $callback();
      if ($own) $pdo->commit();
      return $result;
    } catch (\Throwable $error) {
      if ($own && $pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }

  private static function json(array $value): string
  {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }
}
