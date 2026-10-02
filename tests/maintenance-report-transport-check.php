<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$columns = ['_ID','id_ticket','id_revision_correctiva','id_revision_preventiva','valor','transporte','valor_revision','valor_mantenimiento','id_cotizacion_mantenimiento','fue_pagado','exportado'];
$pdo->exec('CREATE TABLE wp_jet_cct_reportes_administrativos (_ID INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', array_map(static fn(string $name): string => $name . ' TEXT', array_slice($columns, 1))) . ')');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, funcion TEXT, valor TEXT)');
$insert = $pdo->prepare('INSERT INTO wp_jet_cct_confi_sistema (funcion,valor) VALUES (?,?)');
foreach (['salario'=>'1750905','dias_trabajo'=>'30','porcentaje_smlmv_co_pre'=>'53','valor_transporte'=>'4000'] as $name=>$value) $insert->execute([$name,$value]);
$db = new \SCM\Core\Database($pdo);
$schema = new \SCM\Support\SchemaInspector($db);
// SQLite fixtures use known schemas instead of MySQL information_schema.
foreach (['tableExistsCache'=>['wp_jet_cct_reportes_administrativos'=>true,'wp_jet_cct_confi_sistema'=>true], 'tableColumnsCache'=>['wp_jet_cct_reportes_administrativos'=>$columns]] as $property=>$value) {
  (new ReflectionProperty($schema, $property))->setValue($schema, $value);
}
$subject = new class($db, $schema) {
  use \SCM\App\Concerns\HandlesMaintenanceActions;
  public function __construct(private \SCM\Core\Database $db, private \SCM\Support\SchemaInspector $schema) {}
  public function report(int $ticket, string $type = 'Correctiva'): int {
    return $this->maintenance_quote_ensure_admin_report($this->schema,570,['id_ticket'=>(string)$ticket,'id_revision'=>'55','tipo_mantenimiento'=>$type],[],[],['name'=>'Funcionario de prueba'],'99',1700000000,'2026-10-02 12:00:00');
  }
};
$id = $subject->report(1);
$row = $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE _ID=?',[$id]);
if ((int)$row['valor']!==38933 || (int)$row['transporte']!==8000 || (int)$row['valor_revision']!==30933 || (int)$row['valor_mantenimiento']!==30933) throw new RuntimeException('El reporte no separó tarifa y transporte de ida y vuelta.');
if ($subject->report(1)!==$id || (int)$db->getVar('SELECT COUNT(*) FROM wp_jet_cct_reportes_administrativos')!==1) throw new RuntimeException('Se duplicó el reporte.');
$pdo->exec("UPDATE wp_jet_cct_confi_sistema SET valor='5000' WHERE funcion='valor_transporte'");
$id = $subject->report(2,'Preventiva');
$row = $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE _ID=?',[$id]);
if ((int)$row['valor']!==40933 || (int)$row['transporte']!==10000 || $row['id_revision_preventiva']!=='55') throw new RuntimeException('La preventiva no tomó la configuración vigente.');
$pdo->exec("UPDATE wp_jet_cct_confi_sistema SET valor='invalido' WHERE funcion='valor_transporte'");
$id = $subject->report(3);
$row = $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE _ID=?',[$id]);
if ((int)$row['valor']!==30933 || (int)$row['transporte']!==0) throw new RuntimeException('El transporte inválido alteró la tarifa.');
echo "Maintenance report transport checks passed: totals, breakdown, deduplication, preventive, current configuration, invalid transport.\n";
