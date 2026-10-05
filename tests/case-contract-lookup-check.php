<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Support\CaseContractLookup;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

// The number of one contract equals the internal ID of another.
$contracts = [
  ['_ID' => '820', 'contrato' => '600', 'arrendatario' => 'Contrato A'],
  ['_ID' => '600', 'contrato' => '820', 'arrendatario' => 'Contrato B'],
];
$cases = [
  ['id_contrato' => '820', 'contrato' => '600'],
  ['id_contrato' => '', 'contrato' => '820'],
  ['id_contrato' => '600', 'contrato' => '820'],
  ['contrato' => '600'],
  ['id_contrato' => ' 820 ; 600 ', 'contrato' => '820'],
  ['id_contrato' => '999', 'contrato' => '820'],
  ['id_contrato' => ' ', 'contrato' => ' 820 '],
  [],
];

foreach ([$contracts, array_reverse($contracts)] as $source) {
  $calls = [];
  $records = CaseContractLookup::load($cases, static function (string $column, array $ids) use ($source, &$calls): array {
    $calls[] = $column;
    $out = [];
    foreach ($source as $contract) {
      if (in_array($contract[$column], array_map('strval', $ids), true)) {
        $out[$contract[$column]] = $contract;
      }
    }
    return $out;
  });
  foreach (['820', '600', '600', '820', '820', null, '600', null] as $index => $expectedId) {
    $actual = CaseContractLookup::resolve($cases[$index], $records);
    $check(($actual['_ID'] ?? null) === $expectedId, 'Incorrect contract for case ' . $index);
  }
  $check($calls === ['_ID', 'contrato'], 'Contract references must be queried in separate namespaces.');
}

$check(CaseContractLookup::load([[]], static function (): array {
  throw new RuntimeException('An empty reference must not query contracts.');
}) === [], 'Empty cases must have no contract.');

echo "Case contract lookup checks passed.\n";
