<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

use SCM\Support\CaseUpdateStatus;

$check = static function (string $name, mixed $expected, mixed $actual): void {
  if ($expected !== $actual) {
    fwrite(STDERR, "FAIL {$name}: expected " . json_encode($expected) . ', got ' . json_encode($actual) . "\n");
    exit(1);
  }
};

$limits = ['mantenimiento' => 7, 'preventiva' => 5];
$now = '2026-11-05 10:00:00';
$case = static fn(string $topic, string $modified): array => ['tema_ayuda' => $topic, 'cct_modified' => $modified, 'cct_created' => '2026-01-01 08:00:00'];

// Del 3 al 5 de noviembre son dos días completos.
$recent = CaseUpdateStatus::evaluate($case('Reparaciones necesarias', '2026-11-03 10:00:00'), $limits, $now);
$check('label', '2 días hábiles', $recent['label']);
$check('mantenimiento al día', 'al_dia', $recent['status']);

// Domingos y el festivo del 2/11 no cuentan.
$check('preventiva atrasada', 'atrasado', CaseUpdateStatus::evaluate($case('Revisión preventiva', '2026-10-20 10:00:00'), $limits, $now)['status']);
$check('al límite sigue al día', 'al_dia', CaseUpdateStatus::evaluate($case('Mejoras útiles', '2026-10-27 10:00:00'), $limits, $now)['status']);

$other = CaseUpdateStatus::evaluate($case('Arriendo', '2026-10-01 10:00:00'), $limits, $now);
$check('otro tema sin estado', '', $other['status']);
$check('otro tema muestra días', true, $other['label'] !== '');

$fallback = CaseUpdateStatus::evaluate(['tema_ayuda' => 'Arriendo', 'cct_modified' => '0000-00-00 00:00:00', 'cct_created' => '2026-11-04 10:00:00'], $limits, $now);
$check('usa la creación', '1 día hábil', $fallback['label']);
$check('sin fechas', ['label' => '', 'status' => ''], CaseUpdateStatus::evaluate(['tema_ayuda' => 'Arriendo'], $limits, $now));

$pills = CaseUpdateStatus::pills(['label' => '2 días hábiles', 'status' => 'atrasado']);
$check('pill atrasado', true, str_contains($pills, 'Atrasado') && str_contains($pills, 'Sin act: 2 días hábiles'));

echo "Case update status checks passed\n";
