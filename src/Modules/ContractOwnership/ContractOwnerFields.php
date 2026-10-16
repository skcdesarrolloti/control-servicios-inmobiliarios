<?php

declare(strict_types=1);

namespace SCM\Modules\ContractOwnership;

/**
 * Campos que identifican al funcionario responsable de un contrato de arrendamiento.
 *
 * Solo el módulo "Funcionario de contratos" puede cambiarlos. Las demás acciones
 * (revisiones, gestiones de cobro, recibo, ajustes) dejan su trazabilidad en
 * historiales y nunca deben sobrescribir al responsable del contrato.
 */
final class ContractOwnerFields
{
  public const PROTECTED = ['id_empleado', 'realizado_por', 'cct_author_id'];

  /**
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  public static function strip(array $payload): array
  {
    foreach (self::PROTECTED as $field) {
      unset($payload[$field]);
    }
    return $payload;
  }
}
