<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_tickets (_ID INTEGER PRIMARY KEY, inicio_trabajo TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_tickets (_ID, inicio_trabajo) VALUES (1, NULL), (2, '0'), (3, '1700000000'), (4, '')");
$db = new \SCM\Core\Database($pdo);
$assert = static function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; };
$value = static fn(int $id) => $db->getVar('SELECT inicio_trabajo FROM wp_jet_cct_tickets WHERE _ID = ?', [$id]);

$assert(\SCM\Support\CaseWorkStart::markIfEmpty($db, 1, 1790000000) && (int) $value(1) === 1790000000, 'first execution sets the work start on an empty case');
$assert(\SCM\Support\CaseWorkStart::markIfEmpty($db, 2, 1790000000) && \SCM\Support\CaseWorkStart::markIfEmpty($db, 4, 1790000000), 'zero and blank count as empty');
$assert(!\SCM\Support\CaseWorkStart::markIfEmpty($db, 3, 1790000000) && (int) $value(3) === 1700000000, 'an existing work start is never overwritten');
$assert(!\SCM\Support\CaseWorkStart::markIfEmpty($db, 1, 1800000000) && (int) $value(1) === 1790000000, 'later execution steps keep the first start date');
$assert(!\SCM\Support\CaseWorkStart::markIfEmpty($db, 99, 1790000000) && !\SCM\Support\CaseWorkStart::markIfEmpty($db, 0, 1790000000), 'missing cases are ignored');

$service = (new ReflectionClass(\SCM\Modules\TicketCompletion\CompletionService::class))->newInstanceWithoutConstructor();
$restore = new ReflectionMethod($service, 'previousExecutorUpdate');
$assert($restore->invoke($service, ['previous' => ['ejecutado' => 'Propietario', 'inicio_trabajo' => '']]) === ['ejecutado' => 'Propietario', 'inicio_trabajo' => ''], 'cancelling an act restores the previous work start');
$assert($restore->invoke($service, ['previous' => ['estado_administrativo' => 'Cotizado']]) === [], 'older acts without the snapshot leave the work start untouched');
