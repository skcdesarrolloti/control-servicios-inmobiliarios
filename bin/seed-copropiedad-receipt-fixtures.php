#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;

$db = App::db();
$pdo = $db->pdo();
$copTable = $db->table('jet_cct_copropiedades');
$contractTable = $db->table('jet_cct_contratos_arrendamiento');

/**
 * @return array<string,true>
 */
function scm_columns(PDO $pdo, string $table): array
{
  $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
  $columns = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $columns[(string) $row['Field']] = true;
  }
  return $columns;
}

/**
 * @param array<string,mixed> $data
 * @param array<string,true> $columns
 * @return array<string,mixed>
 */
function scm_filter_columns(array $data, array $columns): array
{
  return array_intersect_key($data, $columns);
}

function scm_next_id(PDO $pdo, string $table): int
{
  $stmt = $pdo->query("SELECT COALESCE(MAX(`_ID`), 0) + 1 FROM `{$table}`");
  return (int) $stmt->fetchColumn();
}

/**
 * @param array<string,mixed> $data
 * @param array<string,mixed> $where
 */
function scm_update_or_insert(\SCM\Core\Database $db, string $table, array $data, array $where): int
{
  $whereSql = implode(' AND ', array_map(static fn(string $key): string => "`{$key}` = ?", array_keys($where)));
  $existing = $db->getRow("SELECT `_ID` FROM `{$table}` WHERE {$whereSql} LIMIT 1", array_values($where));
  if (is_array($existing) && (int) ($existing['_ID'] ?? 0) > 0) {
    $db->update($table, $data, ['_ID' => (int) $existing['_ID']]);
    return (int) $existing['_ID'];
  }

  $data['_ID'] = scm_next_id($db->pdo(), $table);
  $db->insert($table, $data);
  return (int) $data['_ID'];
}

$copColumns = scm_columns($pdo, $copTable);
$contractColumns = scm_columns($pdo, $contractTable);
$now = date('Y-m-d H:i:s');

$fixtures = [
  [
    'name' => 'PRUEBA CODEX COMPROBANTES - MALIBU II',
    'nit' => '9015087552',
    'address' => 'APTO 1102 Y APTO 1002',
    'administrator' => 'Administrador Prueba Codex Malibu',
    'email' => 'prueba.comprobantes.malibu@example.invalid',
    'contracts' => [
      ['contract' => 'PRUEBA-CP-PDF-1102', 'property' => 'APTO 1102', 'address' => 'APTO 1102'],
      ['contract' => 'PRUEBA-CP-PDF-1002', 'property' => 'APTO 1002', 'address' => 'APTO 1002'],
    ],
  ],
  [
    'name' => 'PRUEBA CODEX COMPROBANTES - CALAMARI',
    'nit' => '901133921',
    'address' => 'APTO 302 T10',
    'administrator' => 'Administrador Prueba Codex Calamari',
    'email' => 'prueba.comprobantes.calamari@example.invalid',
    'contracts' => [
      ['contract' => 'PRUEBA-CP-PDF-302-T10', 'property' => 'APTO 302 T10', 'address' => 'APTO 302 T10'],
    ],
  ],
];

$pdo->beginTransaction();
try {
  foreach ($fixtures as $fixture) {
    $copData = scm_filter_columns([
      'cct_status' => 'publish',
      'copropiedad' => $fixture['name'],
      'nit' => $fixture['nit'],
      'direccion' => $fixture['address'],
      'administrador' => $fixture['administrator'],
      'contacto' => '0000000000',
      'correo' => $fixture['email'],
      'indicativo' => '+57',
      'cct_author_id' => 1,
      'cct_modified' => $now,
      'bloqueo_email' => 0,
      'bloqueo_sms' => 0,
      'bloqueo_whatsapp' => 0,
      'permite_notif_email' => 1,
      'permite_notif_sms' => 1,
      'permite_notif_whatsapp' => 1,
      'motivo_bloqueo' => 'Registro falso para probar importacion de comprobantes PDF.',
    ], $copColumns);

    $copId = scm_update_or_insert($db, $copTable, $copData, [
      'copropiedad' => $fixture['name'],
    ]);

    foreach ($fixture['contracts'] as $contractFixture) {
      $contractData = scm_filter_columns([
        'cct_status' => 'publish',
        'tipo' => 'Prueba',
        'estado' => 'Recibido',
        'contrato' => $contractFixture['contract'],
        'contrato_arrendamiento' => $contractFixture['contract'],
        'direccion' => $contractFixture['address'],
        'id_inmueble' => $contractFixture['property'],
        'id_inmueble_data' => $contractFixture['property'],
        'inmueble' => $contractFixture['property'],
        'propiedad_horizontal' => 'Si',
        'copropiedad' => $fixture['name'],
        'nit_copropiedad' => $fixture['nit'],
        'administrador' => $fixture['administrator'],
        'correo_copropiedad' => $fixture['email'],
        'celular_copropiedad' => '0000000000',
        'id_copropiedad' => (string) $copId,
        'valor_canon' => '0',
        'valor_administracion' => '0',
        'cct_author_id' => 1,
        'cct_modified' => $now,
      ], $contractColumns);

      $contractId = scm_update_or_insert($db, $contractTable, $contractData, [
        'contrato' => $contractFixture['contract'],
      ]);

      echo sprintf(
        "OK %s | copropiedad #%d | contrato #%d | NIT %s | estado Recibido\n",
        $fixture['name'],
        $copId,
        $contractId,
        $fixture['nit']
      );
    }
  }
  $pdo->commit();
} catch (Throwable $exception) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
  exit(1);
}
