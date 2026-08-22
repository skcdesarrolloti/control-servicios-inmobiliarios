<?php

namespace SCM\Modules\Pending;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class PendingService
{
  use \SCM\Modules\Pending\Concerns\PendingQueriesConcern;
  use \SCM\Modules\Pending\Concerns\AdministrativeTicketCreationConcern;
  use \SCM\Modules\Pending\Concerns\PendingNotificationsAndDatesConcern;

  private PendingRepository $repo;

  public function __construct(PendingRepository $repo)
  {
    $this->repo = $repo;
  }

  /** @return array<int,array{id:string,label:string}> */
  public function funcionarios(): array
  {
    return $this->repo->getFuncionarios();
  }

  /** @return array<string,mixed> */
  public function markContratoRecibido(int $contractId, string $fechaRecibo): array
  {
    if ($contractId <= 0) {
      return ['ok' => false, 'message' => 'ID de contrato invalido.'];
    }

    $row = $this->repo->getContratoArrendamientoById((string) $contractId);
    if (!is_array($row)) {
      return ['ok' => false, 'message' => 'Contrato no encontrado.'];
    }

    $fechaTs = $this->normalizeContractReceivedDate($fechaRecibo);
    if ($fechaTs <= 0) {
      return ['ok' => false, 'message' => 'La fecha de recibo es obligatoria.'];
    }

    $this->repo->updateContratoArrendamiento($contractId, [
      'estado' => 'Recibido',
      'tipo' => 'Ex',
      'fecha_recibo' => $fechaTs,
      'cct_modified' => date('Y-m-d H:i:s'),
    ]);

    return [
      'ok' => true,
      'message' => 'Contrato marcado como recibido.',
      'estado' => 'Recibido',
      'tipo' => 'Ex',
      'fecha_recibo' => $fechaTs,
      'fecha_recibo_date' => $this->formatContractReceivedDate($fechaTs),
    ];
  }

  private function normalizeContractReceivedDate(string $value): int
  {
    $value = trim($value);
    if ($value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      return $ts > 0 ? $ts : 0;
    }

    $tz = new \DateTimeZone('America/Bogota');
    foreach (['Y-m-d', 'd/m/Y', 'Y-m-d H:i:s'] as $format) {
      $dt = \DateTimeImmutable::createFromFormat($format, $value, $tz);
      if ($dt instanceof \DateTimeImmutable) {
        return $dt->setTime(0, 0)->getTimestamp();
      }
    }

    $ts = strtotime($value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function formatContractReceivedDate(int $timestamp): string
  {
    if ($timestamp <= 0) {
      return '';
    }
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('America/Bogota'))
      ->format('Y-m-d');
  }

}
