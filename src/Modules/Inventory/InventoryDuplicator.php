<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

/**
 * Copia un inventario hacia un caso con otro contrato (antes plugin "SKC Mapeador de Inventario"). Conserva los elementos
 * del origen y toma del contrato destino las partes, el inmueble, los servicios y los linderos.
 */
final class InventoryDuplicator
{
  private const FROM_CONTRACT = ['id_inmueble', 'id_estudio_aseguradora', 'numero_solicitud', 'contrato', 'inmueble', 'registro_fotografico', 'id_propietario', 'propietario', 'correo_propietario', 'celular_propietario', 'id_arrendatario', 'arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'tiene_delegado', 'delegado', 'correo_delegado', 'celular_delegado', 'direccion', 'sucursal', 'luz', 'agua', 'gas', 'medidor_luz', 'medidor_agua', 'medidor_gas', 'servicios_publicos', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'acuerdos_precontractuales', 'acuerdos_precontractuales_pro', 'acuerdos_precontractuales_arre', 'acuerdos_precontractuales_co', 'acuerdos_precontractuales_inm', 'acuerdos_precontractuales_ext', 'fecha_linderos', 'conjunto_lin', 'torre_lin', 'inmueble_lin', 'parqueadero_lin', 'deposito_lin'];

  /**
   * @param array<string,mixed> $source @param array<string,mixed> $contract @param array<string,mixed> $ticket
   * @param array{name:string,email:string,phone:string,employee_id:string} $actor
   * @return array<string,mixed>
   */
  public static function build(array $source, array $contract, array $ticket, array $actor, int $now): array
  {
    $row = $source;
    unset($row['_ID']);
    foreach (self::FROM_CONTRACT as $field) {
      $row[$field] = $contract[$field] ?? '';
    }
    $nowSql = date('Y-m-d H:i:s', $now);
    return array_merge($row, [
      'id_contrato' => (string) ($contract['_ID'] ?? ''),
      'id_ticket' => (string) ($ticket['_ID'] ?? ''),
      'cedula_arrendatario' => $contract['documento_arrendatario'] ?? '',
      'cedula_delegado' => $contract['documento_delegado'] ?? '',
      'cct_status' => 'publish',
      'cct_author_id' => $actor['employee_id'],
      'id_empleado' => $actor['employee_id'],
      'creador' => $actor['name'],
      'email_creador' => $actor['email'],
      'celular_creador' => $actor['phone'],
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'fecha' => $now,
      'fecha_entrega' => $now,
      'tipo_inventario' => InventorySchema::NEW_TYPE,
      'se_envio' => 'Si',
      'incluir_anexos' => 'Si',
      'id_reporte_administrativo' => '',
      'reporte_realizado' => '',
    ]);
  }

  /** Filas registradas en el inventario origen. @param array<string,mixed> $source */
  public static function elementCount(array $source): int
  {
    $total = 0;
    foreach (InventorySchema::sections() as $key => $section) {
      $total += count(InventorySchema::rowsFromStored($source[$key] ?? '', $section['suffix']));
      $total += count(InventorySchema::rowsFromStored($source[$section['furniture']] ?? '', $section['furniture_suffix']));
    }
    return $total;
  }
}
