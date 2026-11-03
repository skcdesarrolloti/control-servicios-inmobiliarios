<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Propietarios de un contrato de arrendamiento con su contacto, para avisarles a todos (cotizaciones de reparaciones,
 * etc.). Carga el mandato y, sin mandato, las fichas de `id_propietario_2..6`; la lectura es `ContractCoOwners::resolve`.
 */
final class ContractCoOwnerContacts
{
  /**
   * @param array<string,mixed> $contract fila de jet_cct_contratos_arrendamiento
   * @return list<array{key:string,index:int,name:string,document:string,email:string,phone:string,owner_id:string,share:float,share_source:string,share_detail:string}>
   */
  public static function forContract(Database $db, array $contract): array
  {
    if ($contract === []) return [];
    $schema = new SchemaInspector($db);
    $mandateTable = $db->table('jet_cct_contrato_mandato');
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $mandate = $mandateId > 0 && $schema->tableExists($mandateTable) ? $db->getRow("SELECT * FROM `{$mandateTable}` WHERE `_ID` = ? LIMIT 1", [$mandateId]) : null;
    if (is_array($mandate)) return ContractCoOwners::resolve($contract, $mandate);

    $ids = [];
    for ($i = 2; $i <= ContractCoOwners::MAX; $i++) {
      $id = trim((string) ($contract['id_propietario_' . $i] ?? ''));
      if ($id !== '' && ctype_digit($id)) $ids[] = $id;
    }
    $profiles = [];
    $table = $db->table('jet_cct_propietarios');
    if ($ids !== [] && $schema->tableExists($table)) {
      foreach ($db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) $profiles[(string) $row['_ID']] = $row;
    }
    return ContractCoOwners::resolve($contract, null, $profiles);
  }
}
