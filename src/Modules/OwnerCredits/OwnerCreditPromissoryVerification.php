<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Core\Database;

/**
 * Código de 6 dígitos para firmar el pagaré, con las reglas del acta de satisfacción (CompletionVerification):
 * vence en 10 minutos, un minuto entre solicitudes, máximo 5 solicitudes y 5 intentos fallidos por hora. Solo se
 * guarda el HMAC del código, ligado al enlace (token_nonce) y a la huella del documento.
 */
final class OwnerCreditPromissoryVerification
{
  public function __construct(private Database $db, private string $table, private string $secret) {}

  /** @param callable(string,string):bool $send */
  public function request(array $row, string $channel, callable $send): array
  {
    $state = $this->state($row);
    $now = time();
    if ((int) ($state['last_request'] ?? 0) > $now - 60) throw new \DomainException('Espera un minuto antes de solicitar otro código.');
    if ((int) ($state['requests'] ?? 0) >= 5 || (int) ($state['failures'] ?? 0) >= 5) throw new \DomainException('Alcanzaste el límite de verificación. Intenta nuevamente en una hora.');
    $code = (string) random_int(100000, 999999);
    $nonce = bin2hex(random_bytes(16));
    $state = array_merge($state, [
      'last_request' => $now, 'requests' => (int) ($state['requests'] ?? 0) + 1,
      'channel' => $channel, 'nonce' => $nonce, 'expires_at' => $now + 600, 'hash' => $this->hash($row, $nonce, $code), 'queued' => false,
    ]);
    $this->save((int) $row['id'], $state);
    $state['queued'] = $send($code, $nonce);
    $this->save((int) $row['id'], $state);
    return ['queued' => $state['queued'], 'message' => $state['queued']
      ? 'Código enviado. Revisa el contacto elegido: vence en 10 minutos y solo sirve el último que pidas.'
      : 'No se pudo enviar el código. Espera un minuto y vuelve a intentarlo o elige otro canal.'];
  }

  /** Los intentos fallidos se guardan de inmediato (fuera de la transacción de la firma). */
  public function verify(array $row, mixed $code): array
  {
    $state = $this->state($row);
    if ((int) ($state['failures'] ?? 0) >= 5) throw new \DomainException('Alcanzaste el límite de intentos. Intenta nuevamente en una hora.');
    if (empty($state['queued']) || (int) ($state['expires_at'] ?? 0) <= time()) throw new \DomainException('Solicita un código de verificación vigente antes de firmar.');
    if (!is_string($code) || !preg_match('/^\d{6}$/D', $code) || !hash_equals((string) $state['hash'], $this->hash($row, (string) $state['nonce'], $code))) {
      $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
      $this->save((int) $row['id'], $state);
      throw new \DomainException('Código incorrecto. Revisa el último recibido; después de 5 intentos se bloquea la verificación durante una hora.');
    }
    return ['channel' => $state['channel'], 'challenge' => $state['nonce'], 'requested_at' => $state['last_request'], 'verified_at' => time()];
  }

  private function state(array $row): array
  {
    $state = json_decode((string) ($row['otp_json'] ?? ''), true) ?: [];
    return (int) ($state['window_start'] ?? 0) <= time() - 3600 ? ['window_start' => time(), 'requests' => 0, 'failures' => 0] : $state;
  }

  private function hash(array $row, string $nonce, string $code): string
  {
    return hash_hmac('sha256', 'pagare|' . $row['id'] . '|' . $row['token_nonce'] . '|' . $row['payload_hash'] . '|' . $nonce . '|' . $code, $this->secret);
  }

  private function save(int $id, array $state): void
  {
    $this->db->update($this->table, ['otp_json' => json_encode($state, JSON_THROW_ON_ERROR)], ['id' => $id]);
  }
}
