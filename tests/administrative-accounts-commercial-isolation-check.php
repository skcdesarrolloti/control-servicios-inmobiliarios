<?php

declare(strict_types=1);

// Las cuentas comerciales (CCO-) comparten wp_jet_cct_cuentas_cobro: este panel no debe listarlas, abrirlas ni pagarlas.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
  if (str_starts_with($class, 'SCM\\')) {
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require_once $file;
  }
});

use SCM\Core\Database;
use SCM\Modules\AdministrativeReports\AdministrativeReportsService as Service;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'fixture');
$pdo->exec("ATTACH ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
$pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
foreach ([
  'wp_jet_cct_cuentas_cobro' => '_ID INTEGER PRIMARY KEY, numero_cuenta TEXT, id_empleado TEXT, fecha_creacion TEXT, total_general TEXT, reportes_incluidos TEXT, fue_pagada TEXT',
  'wp_jet_cct_reportes_administrativos' => '_ID INTEGER PRIMARY KEY, fue_pagado TEXT',
] as $table => $schema) {
  $pdo->exec("CREATE TABLE `{$table}` ({$schema})");
  $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?,?)')->execute(['fixture', $table]);
  foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $pdo->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)')->execute(['fixture', $table, $column['name']]);
  }
}
$pdo->exec("INSERT INTO wp_jet_cct_reportes_administrativos VALUES (1,'No'),(2,'No')");
$pdo->exec("INSERT INTO wp_jet_cct_cuentas_cobro VALUES (10,'CC-000010','7','2026-10-01','100','[\"1\"]','No pagada'),(11,'CCO-000011','7','2026-10-01','200','[\"2\"]','No pagada'),(12,NULL,'7','2026-10-01','300','[]','No pagada')");
$service = new Service(new Database($pdo));

$listed = array_column($service->accounts([])['items'], 'id');
$paid = $service->markAccountsPaid([10, 11]);
$status = static fn(string $sql): string => (string) $pdo->query($sql)->fetchColumn();
$checks = [
  'lists administrative accounts, including legacy ones without number' => $listed === [12, 10],
  'does not open a commercial account' => $service->account(11) === null && $service->account(10) !== null,
  'pays only the administrative account' => $paid['accounts'] === 1 && $paid['reports'] === 1,
  'commercial account stays unpaid' => $status('SELECT fue_pagada FROM wp_jet_cct_cuentas_cobro WHERE _ID = 11') === 'No pagada',
  'report sharing a commercial number stays unpaid' => $status('SELECT fue_pagado FROM wp_jet_cct_reportes_administrativos WHERE _ID = 2') === 'No',
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
  fwrite(STDERR, "Commercial account isolation checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}
echo 'Commercial account isolation checks passed: ' . count($checks) . PHP_EOL;
