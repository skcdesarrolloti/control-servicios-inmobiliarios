<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetService;
use SCM\Modules\OwnerCredits\OwnerCreditPromissoryService;
use SCM\Support\SchemaInspector;

/**
 * Fase 3 de copropietarios: completa los datos que ya existían antes de registrar el % de participación.
 *
 * 1. Mandatos con `propietarios > 1`: registra en Propietarios a los propietarios 2..N sin ficha (por documento
 *    normalizado, como el plugin `skc_copropietarios`) y llena `id_propietario_nuevo_N`.
 * 2. Contratos de arrendamiento de esos mandatos: llena `propietarios`, `id_propietario_2..6` y `porcentaje_1..6` desde
 *    el mandato. Solo columnas vacías: un valor distinto queda como conflicto, no se pisa.
 * 3. Lista los contratos con copropietarios sin % válido, para cargarlo en el mandato (plugin o hoja de cierre).
 *
 * Sin `$apply` solo calcula y devuelve lo que haría (bin/sync-co-owners.php).
 */
final class CoOwnersBackfill
{
  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /**
   * @param string $author id_empleado real del funcionario que ejecuta (cct_author_id de las fichas nuevas)
   * @return array{profiles:list<array<string,string>>,mandates:list<array<string,mixed>>,contracts:list<array<string,mixed>>,conflicts:list<array<string,string>>,missing_shares:list<array<string,string>>}
   */
  public function run(bool $apply, string $author = ''): array
  {
    $out = ['profiles' => [], 'mandates' => [], 'contracts' => [], 'conflicts' => [], 'missing_shares' => []];
    $mandateTable = $this->db->table('jet_cct_contrato_mandato');
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($mandateTable) || !$this->schema->columnExists($mandateTable, 'propietarios')) {
      return $out;
    }
    if ($apply && trim($author) === '') {
      throw new \InvalidArgumentException('Falta el id_empleado del funcionario que ejecuta (--funcionario=<id_empleado>).');
    }
    $now = date('Y-m-d H:i:s');
    $index = $this->profileIndex();

    $mandates = [];
    foreach ($this->db->getResults("SELECT * FROM `{$mandateTable}` WHERE CAST(COALESCE(NULLIF(TRIM(`propietarios`), ''), '1') AS UNSIGNED) > 1 ORDER BY `_ID` ASC") as $mandate) {
      $update = [];
      foreach (ContractCoOwners::mandateOwners($mandate) as $owner) {
        $i = $owner['index'];
        if ($i === 1 || ($owner['owner_id'] !== '' && isset($index['ids'][$owner['owner_id']]))) {
          continue;
        }
        $document = OwnerCreditPromissoryService::normalizeDocument($owner['document']);
        $id = strlen($document) >= 4 ? ($index['documents'][$document] ?? '') : '';
        // En modo de prueba, quien ya se iba a registrar en un mandato anterior se muestra como la misma ficha nueva.
        $action = str_starts_with($id, 'nueva:') ? 'repetido' : 'vincular';
        if ($id === '') {
          if ($owner['name'] === '') {
            continue;
          }
          $action = 'registrar';
          $id = $apply ? $this->registerProfile($mandate, $owner, $author, $now) : '';
          if ($apply) {
            $index['ids'][$id] = true;
          }
          if (strlen($document) >= 4) {
            $index['documents'][$document] = $apply ? $id : 'nueva:' . $mandate['_ID'];
          }
        }
        if ($action === 'repetido') {
          $out['profiles'][] = ['mandato' => (string) $mandate['_ID'], 'indice' => (string) $i, 'nombre' => $owner['name'], 'documento' => $owner['document'], 'accion' => $action, 'id' => substr($id, 6)];
          continue;
        }
        $out['profiles'][] = ['mandato' => (string) $mandate['_ID'], 'indice' => (string) $i, 'nombre' => $owner['name'], 'documento' => $owner['document'], 'accion' => $action, 'id' => $id];
        if ($id !== '' && $this->schema->columnExists($mandateTable, 'id_propietario_nuevo_' . $i)) {
          $update['id_propietario_nuevo_' . $i] = $id;
          $mandate['id_propietario_nuevo_' . $i] = $id;
        }
      }
      if ($update !== []) {
        $out['mandates'][] = ['mandato' => (string) $mandate['_ID'], 'columnas' => $update];
        if ($apply) {
          $this->db->update($mandateTable, $update + $this->schema->filterTableData($mandateTable, ['cct_modified' => $now]), ['_ID' => (int) $mandate['_ID']]);
        }
      }
      $mandates[(string) $mandate['_ID']] = $mandate;
    }

    if ($mandates === [] || !$this->schema->tableExists($contractTable) || !$this->schema->columnExists($contractTable, 'id_contrato_mandato')) {
      return $out;
    }
    foreach (array_chunk(array_keys($mandates), 500) as $chunk) {
      $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
      foreach ($this->db->getResults("SELECT * FROM `{$contractTable}` WHERE TRIM(COALESCE(`id_contrato_mandato`, '')) IN ({$placeholders}) ORDER BY `_ID` ASC", array_map('strval', $chunk)) as $contract) {
        $mandate = $mandates[trim((string) $contract['id_contrato_mandato'])];
        [$fill, $conflicts] = $this->contractColumns($contract, $mandate);
        foreach ($conflicts as $column => [$current, $wanted]) {
          $out['conflicts'][] = ['contrato' => (string) ($contract['contrato'] ?? ''), 'id' => (string) $contract['_ID'], 'mandato' => (string) $mandate['_ID'], 'columna' => $column, 'contrato_valor' => $current, 'mandato_valor' => $wanted];
        }
        $fill = $this->schema->filterTableData($contractTable, $fill);
        if ($fill !== []) {
          $out['contracts'][] = ['contrato' => (string) ($contract['contrato'] ?? ''), 'id' => (string) $contract['_ID'], 'mandato' => (string) $mandate['_ID'], 'columnas' => $fill];
          if ($apply) {
            $this->db->update($contractTable, $fill, ['_ID' => (int) $contract['_ID']]);
          }
          $contract = $fill + $contract;
        }
        $owners = ContractCoOwners::resolve($contract, $mandate);
        if (count($owners) > 1 && $owners[0]['share_source'] === 'partes_iguales') {
          $out['missing_shares'][] = [
            'contrato' => (string) ($contract['contrato'] ?? ''), 'id' => (string) $contract['_ID'], 'estado' => (string) ($contract['estado'] ?? ''),
            'mandato' => (string) $mandate['_ID'], 'propietarios' => implode(', ', array_column($owners, 'name')), 'motivo' => (string) $owners[0]['share_detail'],
          ];
        }
      }
    }
    return $out;
  }

  /**
   * Columnas del contrato que se llenan desde el mandato y las que tienen otro valor (no se tocan).
   * @return array{0:array<string,string>,1:array<string,array{0:string,1:string}>}
   */
  private function contractColumns(array $contract, array $mandate): array
  {
    $owners = ContractCoOwners::mandateOwners($mandate);
    $wanted = ['propietarios' => (string) count($owners)];
    foreach ($owners as $owner) {
      if ($owner['index'] > 1 && $owner['owner_id'] !== '') {
        $wanted['id_propietario_' . $owner['index']] = $owner['owner_id'];
      }
      if ($owner['percent'] !== '') {
        $wanted['porcentaje_' . $owner['index']] = $owner['percent'];
      }
    }
    // "1" en un contrato sin ningún copropietario es el valor de un único dueño, no un dato distinto.
    $hasCoOwners = false;
    for ($i = 2; $i <= ContractCoOwners::CONTRACT_MAX; $i++) {
      $hasCoOwners = $hasCoOwners || trim((string) ($contract['id_propietario_' . $i] ?? '')) !== '';
    }
    $fill = [];
    $conflicts = [];
    foreach ($wanted as $column => $value) {
      $current = trim((string) ($contract[$column] ?? ''));
      if ($current === '' || ($column === 'propietarios' && $current === '1' && !$hasCoOwners)) {
        if ($current !== $value) {
          $fill[$column] = $value;
        }
        continue;
      }
      $same = str_starts_with($column, 'porcentaje_') ? ContractCoOwners::percentValue($current) === $value : $current === $value;
      if (!$same) {
        $conflicts[$column] = [$current, $value];
      }
    }
    // Nombre, documento y contacto de los copropietarios: solo se llenan si están vacíos (no son conflicto).
    foreach ($owners as $owner) {
      if ($owner['index'] === 1) continue;
      foreach (ContractCoOwners::contactColumns($owner['index'], $owner) as $column => $value) {
        if ($value !== '' && trim((string) ($contract[$column] ?? '')) === '') $fill[$column] = $value;
      }
    }
    return [$fill, $conflicts];
  }

  /** @return array{ids:array<string,bool>,documents:array<string,string>} fichas de Propietarios por _ID y por documento normalizado */
  private function profileIndex(): array
  {
    $index = ['ids' => [], 'documents' => []];
    $table = $this->db->table('jet_cct_propietarios');
    if (!$this->schema->tableExists($table)) {
      return $index;
    }
    foreach ($this->db->getResults("SELECT `_ID`, `documento` FROM `{$table}` ORDER BY `_ID` ASC") as $row) {
      $id = (string) $row['_ID'];
      $index['ids'][$id] = true;
      $document = OwnerCreditPromissoryService::normalizeDocument((string) ($row['documento'] ?? ''));
      if (strlen($document) >= 4 && !isset($index['documents'][$document])) {
        $index['documents'][$document] = $id;
      }
    }
    return $index;
  }

  /** Ficha nueva en Propietarios con los datos del mandato (campos del plugin `skc_coprop_ensure_profile`, tipo de persona como la hoja de cierre). */
  private function registerProfile(array $mandate, array $owner, string $author, string $now): string
  {
    $table = $this->db->table('jet_cct_propietarios');
    $i = (int) $owner['index'];
    $value = static fn(string $column): string => trim((string) ($mandate[$column . '_' . $i] ?? ''));
    $type = ClosingSheetService::personType($value('tipo'));
    // En el mandato, empresa_N / nit_N son el representante legal de una persona jurídica.
    $company = $type === 'Juridica';
    $row = $this->schema->filterTableData($table, [
      'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now,
      'nombre' => $owner['name'], 'documento' => $owner['document'], 'tipo_persona' => $type, 'tipo_documento' => $value('tipo_documento'),
      'expedicion' => $value('lugar'), 'nombre_juridico' => $company ? $value('empresa') : '', 'documento_juridico' => $company ? $value('nit') : '',
      'correo' => $value('correo'), 'celular' => $value('celular'), 'indicativo' => $value('indicativo'), 'direccion' => $value('direccion'),
    ]);
    if ($row === [] || !$this->db->insert($table, $row)) {
      throw new \RuntimeException('No se pudo registrar a ' . $owner['name'] . ' (mandato #' . $mandate['_ID'] . ') en Propietarios.');
    }
    return (string) $this->db->lastInsertId();
  }
}
