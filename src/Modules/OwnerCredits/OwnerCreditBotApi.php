<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * API de créditos para el bot de WhatsApp (public/owner-credit-bot.php): el propietario o copropietario identificado
 * en el bot consulta su cupo, radica una solicitud y consulta sus solicitudes. La lógica es la del panel
 * (OwnerCreditService): mismas validaciones de plazo, avisos internos y confirmación al propietario.
 *
 * Seguridad: cuerpo JSON firmado con HMAC-SHA256 del secreto propio de esta integración (OWNER_CREDIT_BOT_SECRET, el
 * mismo en el .env del panel y del bot) en `X-SCM-Signature`, y `ts` a menos de 5 minutos. Sin secreto no responde. Solo opera sobre los contratos donde el documento
 * enviado es de un propietario.
 */
final class OwnerCreditBotApi
{
  private const MAX_SKEW = 300;
  private const STATUS_LABELS = [
    'pendiente' => 'Pendiente de estudio', 'aprobado' => 'Aprobado', 'rechazado' => 'No aprobado', 'pagado' => 'Pagado', 'anulado' => 'Anulado',
  ];

  public function __construct(private OwnerCreditService $service, private string $secret)
  {
  }

  public static function signature(string $body, string $secret): string
  {
    return hash_hmac('sha256', $body, $secret);
  }

  /** @return array{status:int,body:array<string,mixed>} */
  public function handle(string $body, string $signature, ?int $now = null): array
  {
    $now ??= time();
    if (strlen($this->secret) < 16) return self::fail('La integración con el bot no está configurada.', 503);
    if ($signature === '' || !hash_equals(self::signature($body, $this->secret), strtolower(trim($signature)))) return self::fail('Firma no válida.', 401);
    $input = json_decode($body, true);
    if (!is_array($input)) return self::fail('Solicitud no válida.', 400);
    if (abs($now - (int) ($input['ts'] ?? 0)) > self::MAX_SKEW) return self::fail('La solicitud venció; intenta de nuevo.', 401);
    $document = trim((string) ($input['document'] ?? ''));
    try {
      return match ((string) ($input['action'] ?? '')) {
        'preapproval' => self::ok($this->preapproval($document)),
        'create' => self::ok($this->create($document, $input)),
        'list' => self::ok(['credits' => $this->service->creditsForDocument($document)]),
        default => self::fail('Acción no válida.', 400),
      };
    } catch (\InvalidArgumentException | \DomainException $error) {
      return self::fail($error->getMessage(), 422);
    } catch (\Throwable $error) {
      error_log('[owner-credit-bot] ' . $error->getMessage());
      return self::fail('No se pudo procesar la solicitud de crédito. Intenta más tarde.', 500);
    }
  }

  /** @return array<string,mixed> */
  private function preapproval(string $document): array
  {
    $request = $this->service->requestPreapproval($document);
    $keep = static fn(array $p): array => array_intersect_key($p, array_flip(['amount', 'outstanding', 'available', 'max_term', 'fallback_term', 'term_basis']));
    return [
      'contracts' => array_map(static fn(array $row): array => [
        'id' => (int) $row['id'], 'contrato' => (string) $row['contrato'], 'inmueble' => (string) $row['inmueble'], 'direccion' => (string) $row['direccion'],
        'estado' => (string) $row['estado'], 'share' => (float) $row['share'],
        'owner' => ['key' => (string) ($row['owner']['key'] ?? ''), 'name' => (string) ($row['owner']['name'] ?? '')],
        'repair' => $keep((array) $row['repair']),
      ], $request['contracts']),
      'preapproval' => $keep($request['preapproval']),
      'purposes' => OwnerCreditSettings::PURPOSES,
      'repair_purpose' => OwnerCreditSettings::REPAIR_PURPOSE,
      'disclaimer' => OwnerCreditService::DISCLAIMER,
    ];
  }

  /** @param array<string,mixed> $input @return array{credit_id:int,message:string} */
  private function create(string $document, array $input): array
  {
    $contractId = (int) ($input['contract_id'] ?? 0);
    $amount = OwnerCreditService::money($input['amount'] ?? 0);
    $term = (int) ($input['term'] ?? 0);
    $purpose = trim((string) ($input['purpose'] ?? ''));
    if (!in_array($purpose, OwnerCreditSettings::PURPOSES, true)) throw new \DomainException('Elige para qué es el crédito.');
    // El contrato debe ser uno donde ese documento es propietario; el beneficiario es esa persona.
    $row = null;
    foreach ($this->service->ownerPortfolio($document) as $candidate) if ((int) $candidate['id'] === $contractId) $row = $candidate;
    if ($row === null) throw new \DomainException('Elige uno de tus contratos para la solicitud.');
    $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? '')) ?? '';
    $actor = ['employee_id' => '', 'name' => 'Guardian (WhatsApp)' . ($phone !== '' ? ' · ' . $phone : '')];
    $id = $this->service->createManual($contractId, $amount, $term, $purpose, $actor, '', (string) ($row['owner']['key'] ?? ''));
    return ['credit_id' => $id, 'message' => 'Tu solicitud de crédito #' . $id . ' quedó radicada para el contrato #' . $row['contrato'] . '. Queda sujeta a estudio, verificación y aprobación.'];
  }

  /** @param array<string,mixed> $data @return array{status:int,body:array<string,mixed>} */
  private static function ok(array $data): array
  {
    return ['status' => 200, 'body' => ['ok' => true, 'data' => $data]];
  }

  /** @return array{status:int,body:array<string,mixed>} */
  private static function fail(string $message, int $status): array
  {
    return ['status' => $status, 'body' => ['ok' => false, 'message' => $message]];
  }

  /** Estado para mostrar al propietario. */
  public static function statusLabel(string $status): string
  {
    return self::STATUS_LABELS[$status] ?? ucfirst($status);
  }
}
