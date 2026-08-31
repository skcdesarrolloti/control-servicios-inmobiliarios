<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

/** All calls are serialized using the same ticket lock as signing. */
final class CompletionVerification
{
  public function __construct(private CompletionRepository $repo, private string $secret) {}

  public function request(array $act, string $channel, callable $send): array
  {
    $state = $this->state($act);
    $now = time();
    if ((int) ($state['last_request'] ?? 0) > $now - 60) { throw new \DomainException('Espera un minuto antes de solicitar otro código.'); }
    if ((int) ($state['requests'] ?? 0) >= 5 || (int) ($state['failures'] ?? 0) >= 5) { throw new \DomainException('Alcanzaste el límite de verificación. Intenta nuevamente en una hora.'); }
    $code = (string) random_int(100000, 999999);
    $nonce = bin2hex(random_bytes(16));
    $state = array_merge($state, [
      'last_request' => $now, 'requests' => (int) ($state['requests'] ?? 0) + 1,
      'channel' => $channel, 'nonce' => $nonce, 'expires_at' => $now + 600,
      'hash' => $this->hash($act, $nonce, $code), 'queued' => false,
    ]);
    $this->save((int) $act['id'], $state); // Persist throttling even when queueing fails.
    $state['queued'] = (bool) $send($code, $nonce);
    $this->save((int) $act['id'], $state);
    return ['queued' => $state['queued'], 'message' => $state['queued']
      ? 'Código solicitado. Revisa el contacto seleccionado. Vence en 10 minutos; usa solo el último código. En cola no significa entregado.'
      : 'No se pudo encolar el código. El ticket sigue abierto. Espera un minuto y reintenta o elige otro canal disponible.'];
  }

  /** Persist failed attempts outside the signing transaction; rollback must not reset the budget. */
  public function verify(array $act, mixed $code): array
  {
    $state = $this->state($act);
    if ((int) ($state['failures'] ?? 0) >= 5) { throw new \DomainException('Alcanzaste el límite de intentos. Intenta nuevamente en una hora.'); }
    if (empty($state['queued']) || (int) ($state['expires_at'] ?? 0) <= time()) { throw new \DomainException('Solicita un código de verificación vigente antes de firmar.'); }
    if (!is_string($code) || !preg_match('/^\d{6}$/D', $code) || !hash_equals((string) $state['hash'], $this->hash($act, (string) $state['nonce'], $code))) {
      $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
      $this->save((int) $act['id'], $state);
      throw new \DomainException('Código incorrecto. Revisa el último recibido; después de 5 intentos se bloquea la verificación durante una hora.');
    }
    return ['channel' => $state['channel'], 'challenge' => $state['nonce'], 'requested_at' => $state['last_request'], 'verified_at' => time()];
  }

  private function state(array $act): array
  {
    $state = json_decode((string) ($act['otp_json'] ?? ''), true) ?: [];
    return (int) ($state['window_start'] ?? 0) <= time() - 3600 ? ['window_start' => time(), 'requests' => 0, 'failures' => 0] : $state;
  }

  private function hash(array $act, string $nonce, string $code): string
  {
    return hash_hmac('sha256', $act['id'] . '|' . $act['token_nonce'] . '|' . $act['payload_hash'] . '|' . $nonce . '|' . $code, $this->secret);
  }

  private function save(int $id, array $state): void
  {
    $this->repo->db->update($this->repo->table(), ['otp_json' => json_encode($state, JSON_THROW_ON_ERROR)], ['id' => $id]);
  }
}
