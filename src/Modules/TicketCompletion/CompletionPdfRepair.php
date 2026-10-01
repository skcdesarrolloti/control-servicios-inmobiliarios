<?php
declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

/** Explicit presentation repair; signing data and business state are immutable. */
final class CompletionPdfRepair
{
  public function __construct(private CompletionService $service, private string $secret) {}

  public function table(): string { return $this->service->repo->db->table('scm_ticket_completion_pdf_repairs'); }

  public function schemaSql(): string
  {
    return 'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      act_id BIGINT UNSIGNED NOT NULL,
      original_pdf MEDIUMBLOB NOT NULL,
      original_hash CHAR(64) NOT NULL,
      original_hmac CHAR(64) NOT NULL,
      replacement_hash CHAR(64) NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      signature_hash CHAR(64) NOT NULL,
      reason VARCHAR(1000) NOT NULL,
      renderer_version VARCHAR(40) NOT NULL,
      repaired_at BIGINT NOT NULL,
      INDEX act_repairs (act_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  }

  public function prepare(int $id, string $expectedHash): array
  {
    if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) throw new \DomainException('Indica el SHA-256 exacto del PDF defectuoso.');
    $act = $this->service->repo->act($id);
    $payload = $this->service->payload($act);
    $original = $this->service->pdf($act); // Validates the existing PDF hash/HMAC and signature.
    if (!hash_equals($expectedHash, hash('sha256', $original))) throw new \DomainException('El PDF actual no coincide con el archivo que se autorizó reparar.');
    if (empty($act['signed_pdf'])) throw new \DomainException('La reparación requiere un PDF original almacenado.');
    $pdf = (new CompletionPdf())->render($act, $payload);
    return ['act_id' => $id, 'original_hash' => $expectedHash, 'payload_hash' => $act['payload_hash'],
      'signature_hash' => hash('sha256', $act['signed_json']), 'pdf_hash' => hash('sha256', $pdf), 'pdf' => $pdf];
  }

  public function apply(array $candidate, string $reason): void
  {
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 1000) throw new \DomainException('Registra el motivo de la reparación (máximo 1000 caracteres).');
    if (!str_starts_with((string) $candidate['pdf'], '%PDF-') || !hash_equals((string) $candidate['pdf_hash'], hash('sha256', $candidate['pdf']))) throw new \DomainException('La copia de reparación cambió.');
    $db = $this->service->repo->db;
    if ($db->pdo()->inTransaction()) throw new \LogicException('La reparación requiere su propia transacción.');
    $db->pdo()->beginTransaction();
    try {
      $act = $db->getRow('SELECT * FROM `' . $this->service->repo->table() . '` WHERE id = ? FOR UPDATE', [(int) $candidate['act_id']]);
      if (!$act) throw new \DomainException('Acta no disponible.');
      $this->service->pdf($act);
      if (!hash_equals((string) $candidate['original_hash'], (string) $act['pdf_hash'])
        || !hash_equals((string) $candidate['payload_hash'], (string) $act['payload_hash'])
        || !hash_equals((string) $candidate['signature_hash'], hash('sha256', $act['signed_json']))) throw new \DomainException('El acta cambió después de preparar la reparación.');
      // Archive the exact original before replacing presentation bytes. No invitation,
      // OTP, signature timestamp, ticket state or administrative charge is changed.
      $db->insert($this->table(), ['act_id' => (int) $act['id'], 'original_pdf' => $act['signed_pdf'],
        'original_hash' => $act['pdf_hash'], 'original_hmac' => $act['pdf_hmac'],
        'replacement_hash' => $candidate['pdf_hash'], 'payload_hash' => $act['payload_hash'],
        'signature_hash' => $candidate['signature_hash'], 'reason' => $reason,
        'renderer_version' => defined('SCM_VERSION') ? SCM_VERSION : 'unknown', 'repaired_at' => time()]);
      $db->update($this->service->repo->table(), ['signed_pdf' => $candidate['pdf'], 'pdf_hash' => $candidate['pdf_hash'],
        'pdf_hmac' => hash_hmac('sha256', $act['id'] . '|' . $act['payload_hash'] . '|' . $candidate['pdf_hash'], $this->secret)], ['id' => (int) $act['id']]);
      $db->pdo()->commit();
    } catch (\Throwable $error) {
      $db->pdo()->rollBack();
      throw $error;
    }
  }
}
