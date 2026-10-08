<?php
declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FuncionarioOptions;
use SCM\Support\SchemaInspector;

final class ContractReceiptSettings
{
  public const KEY = 'contract_receipt_automation';

  public static function read(Database $db): array
  {
    $value = (new Settings($db, true))->get(self::KEY, []);
    return array_replace(['enabled' => false, 'contract_id' => 525, 'employee_id' => '', 'coordinator_id' => ''], is_array($value) ? $value : []);
  }

  public static function validate(Database $db, array $value): array
  {
    $id = trim((string) ($value['contract_id'] ?? ''));
    if (!preg_match('/^[1-9][0-9]*$/', $id) || strlen($id) > 9) throw new \InvalidArgumentException('Indica el _ID interno de un contrato. El cron siempre se limita a ese contrato.');
    if (!$db->getRow("SELECT `_ID` FROM `{$db->table('jet_cct_contratos_arrendamiento')}` WHERE `_ID` = ?", [(int) $id])) throw new \InvalidArgumentException('El contrato indicado no existe.');
    $active = array_map('strval', array_column(FuncionarioOptions::activeFuncionarios($db, new SchemaInspector($db), 'employee', true), 'id'));
    if (isset($value['enabled']) && !is_bool($value['enabled'])) throw new \InvalidArgumentException('La activación debe ser verdadera o falsa.');
    $enabled = !empty($value['enabled']);
    $employee = trim((string) ($value['employee_id'] ?? ''));
    $coordinator = trim((string) ($value['coordinator_id'] ?? ''));
    foreach ([$employee, $coordinator] as $person) if ($person !== '' && !in_array($person, $active, true)) throw new \InvalidArgumentException('Selecciona funcionarios activos.');
    if ($enabled && $employee === '') throw new \InvalidArgumentException('Selecciona el funcionario que recibirá los casos automáticos.');
    return ['enabled' => $enabled, 'contract_id' => (int) $id, 'employee_id' => $employee, 'coordinator_id' => $coordinator];
  }
}
