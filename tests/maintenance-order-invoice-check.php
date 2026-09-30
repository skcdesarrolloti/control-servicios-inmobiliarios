<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Support\MaintenanceOrderInvoice;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$quote = [
  'total_mano_obra' => '500000',
  'total_materiales' => '300000',
  'total_maquinarias' => '1270000',
  'total_otros_costos' => '100000',
  'total_admon' => '217000',
  'iva_admon' => '41230',
  'total' => '2428230',
];
$rows = MaintenanceOrderInvoice::rows(['categoria' => 'Maquinaria', 'valor' => '600000'], $quote);
$check(count($rows) === 6, 'Expected four categories plus administration and VAT.');
$check($rows[2]['label'] === 'Maquinarias' && $rows[2]['current'] === true, 'The order category was not normalized.');
$check($rows[2]['quote'] === '1270000' && $rows[2]['order'] === '600000', 'Order and quotation amounts were mixed.');
$check($rows[0]['order'] === 0 && $rows[4]['quote'] === '217000', 'Breakdown contains an incorrect amount.');
$check(MaintenanceOrderInvoice::quoteTotal($quote) === '2428230', 'Quotation total is incorrect.');

$withoutQuote = MaintenanceOrderInvoice::rows(['categoria' => 'Otros costos', 'valor' => '15000'], null);
$check($withoutQuote[3]['order'] === '15000' && $withoutQuote[3]['quote'] === null, 'Missing quotation must not be shown as zero.');
$check(MaintenanceOrderInvoice::quoteTotal(null) === null, 'Missing quotation total must remain unknown.');

$unclassified = MaintenanceOrderInvoice::rows(['categoria' => 'Transporte especial', 'valor' => '9000'], null);
$check($unclassified[4]['label'] === 'Transporte especial' && $unclassified[4]['order'] === '9000', 'Unclassified order amount was omitted.');

echo "Maintenance order invoice checks passed.\n";
