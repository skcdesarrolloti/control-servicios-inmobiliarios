<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use SCM\Modules\Contracts\ContractRetentionDescription;
$samples = [];
foreach (['contrato por terminar', 'terminación de contrato', 'no prórroga'] as $source) {
  $html = ContractRetentionDescription::build([
    'source' => $source, 'source_ticket' => $source === 'contrato por terminar' ? '' : '10863',
    'contract' => '2000', 'property' => '204578', 'tenant' => 'Arrendatario de prueba',
    'address' => 'Urbanización Simón Bolívar manzana 20 lote 7', 'end_date' => '09/01/2027',
    'status' => $source === 'contrato por terminar' ? '' : 'dentro de término',
    'document_url' => 'https://example.invalid/file.php?n=test.pdf&s=synthetic', 'document_title' => 'Respuesta contractual',
    'response' => 'CARTA COMPLETA QUE NO DEBE COPIARSE ' . str_repeat('texto ', 500),
  ]);
  if (!str_contains($html, '<ul>') || !str_contains($html, $source) || !str_contains($html, '09/01/2027') || str_contains($html, 'CARTA COMPLETA') || mb_strlen(strip_tags($html)) > 700) throw new RuntimeException('Commercial handoff must be brief and structured for ' . $source);
  if ($source !== 'contrato por terminar' && !str_contains($html, 'Ticket #10863')) throw new RuntimeException('Original case must remain referenced.');
  $samples[] = '<h2>' . htmlspecialchars($source) . '</h2><article>' . $html . '</article>';
}
$unsafe = ContractRetentionDescription::build(['source' => '<script>alert(1)</script>', 'tenant' => '<img src=x onerror=alert(1)>', 'document_url' => 'javascript:alert(1)']);
if (str_contains($unsafe, '<script>') || str_contains($unsafe, '<img ') || str_contains($unsafe, 'href=')) throw new RuntimeException('Unsafe content must be escaped.');
$path = sys_get_temp_dir() . '/scm-retention-description-qa.html';
file_put_contents($path, '<!doctype html><meta charset="utf-8"><style>body{font:16px Arial;color:#0f1e36;background:#f1f5f9;margin:24px}article{background:white;border:1px solid #dbe4f0;border-radius:12px;padding:24px;line-height:1.6}li{margin:4px 0}a{color:#2563eb}</style>' . implode('', $samples));
echo "PASS: all three retention sources, short HTML description, original case and document references, escaping. QA: {$path}\n";
