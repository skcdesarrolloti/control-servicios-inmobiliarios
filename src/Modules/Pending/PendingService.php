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
  use \SCM\Modules\Pending\Concerns\PublicServicesReviewConcern;

  private PendingRepository $repo;

  public function __construct(PendingRepository $repo)
  {
    $this->repo = $repo;
  }

  /** @return array<string,mixed> */
  public function markContratoRecibido(int $contractId, string $fechaRecibo): array
  {
    if ($contractId <= 0) {
      return ['ok' => false, 'message' => 'ID de contrato invalido.'];
    }

    $row = $this->repo->getPublicServicesContract($contractId);
    if (!is_array($row)) {
      return ['ok' => false, 'message' => 'Contrato no encontrado.'];
    }

    $fechaTs = $this->normalizeContractReceivedDate($fechaRecibo);
    if ($fechaTs <= 0) {
      return ['ok' => false, 'message' => 'La fecha de recibo es obligatoria.'];
    }

    $employee = $this->repo->getFuncionarioByUserId(Auth::userId());
    if (!$employee || empty($employee['id_empleado'])) return ['ok' => false, 'message' => 'No se pudo identificar al funcionario autenticado.'];
    $pdo = $this->repo->getDb()->pdo();
    if ($pdo->inTransaction()) return ['ok' => false, 'message' => 'Ya existe una operación en curso.'];
    try {
      $pdo->beginTransaction();
      $row = $this->repo->getPublicServicesContract($contractId, true);
      if (!$row) throw new \DomainException('Contrato no encontrado.');
      if (strtolower(trim((string) ($row['estado'] ?? ''))) === 'recibido') {
        $pdo->commit();
        return ['ok' => true, 'message' => 'El contrato ya estaba recibido; no se modificó nuevamente.', 'estado' => 'Recibido', 'tipo' => $row['tipo'] ?? 'Ex', 'fecha_recibo' => $row['fecha_recibo'] ?? 0];
      }
      $now = date('Y-m-d H:i:s');
      if ($this->repo->updateContratoArrendamiento($contractId, [
        'estado' => 'Recibido', 'tipo' => 'Ex', 'fecha_recibo' => $fechaTs, 'cct_modified' => $now,
        'cct_author_id' => (string) $employee['id_empleado'], 'id_empleado' => (string) $employee['id_empleado'], 'realizado_por' => $employee['nombre'],
      ]) !== 1) throw new \RuntimeException('No fue posible actualizar el contrato.');
      if (!$this->repo->insertHistorialInmueble([
        'cct_status' => 'publish', 'cct_author_id' => (string) $employee['id_empleado'], 'cct_created' => $now, 'cct_modified' => $now,
        'id_empleado' => (string) $employee['id_empleado'], 'funcionario' => $employee['nombre'], 'id_inmueble' => $row['id_inmueble'] ?? '',
        'fecha' => time(), 'tipo_reporte' => 'Contractual',
        'observacion' => 'Contrato #' . ($row['contrato'] ?? $contractId) . ' marcado como recibido. Estado anterior: ' . ($row['estado'] ?? '')
          . '; tipo anterior: ' . ($row['tipo'] ?? '') . '; fecha de recibo anterior: ' . ($row['fecha_recibo'] ?? '') . '; nueva fecha de recibo: ' . $this->formatContractReceivedDate($fechaTs) . '.',
      ])) throw new \RuntimeException('No fue posible guardar la trazabilidad del recibo.');
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return ['ok' => false, 'message' => $error->getMessage()];
    }

    return [
      'ok' => true,
      'message' => 'Contrato marcado como recibido.',
      'estado' => 'Recibido',
      'tipo' => 'Ex',
      'fecha_recibo' => $fechaTs,
      'fecha_recibo_date' => $this->formatContractReceivedDate($fechaTs),
    ];
  }

  /** @return array<string,mixed> */
  public function postponePreventivaToNextYear(int $contractId, string $fechaUltimaPreventiva): array
  {
    if ($contractId <= 0) {
      return ['ok' => false, 'message' => 'ID de contrato invalido.'];
    }

    $row = $this->repo->getContratoArrendamientoById((string) $contractId);
    if (!is_array($row)) {
      return ['ok' => false, 'message' => 'Contrato no encontrado.'];
    }

    $fechaTs = $this->normalizeContractReceivedDate($fechaUltimaPreventiva);
    if ($fechaTs <= 0) {
      return ['ok' => false, 'message' => 'La fecha de última preventiva es obligatoria.'];
    }

    $this->repo->updateContratoArrendamiento($contractId, [
      'ultima_revision_preventiva' => $fechaTs,
      'cct_modified' => date('Y-m-d H:i:s'),
    ]);

    $nextTs = $this->addMonths($fechaTs, 12);
    return [
      'ok' => true,
      'message' => 'Última preventiva actualizada. El contrato quedará para revisión el próximo año.',
      'ultima_revision_preventiva' => $fechaTs,
      'ultima_revision_preventiva_date' => $this->formatContractReceivedDate($fechaTs),
      'siguiente_revision_preventiva' => $nextTs,
      'siguiente_revision_preventiva_date' => $this->formatContractReceivedDate($nextTs),
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
      $errors = \DateTimeImmutable::getLastErrors();
      if ($dt instanceof \DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $dt->setTime(0, 0)->getTimestamp();
      }
    }

    return 0;
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
