<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use SCM\Modules\Contracts\ContractRetentionDescription;
$samples = [];
$features = ContractRetentionDescription::propertyContext(
  ['valor_canon' => '1.250.000', 'destinacion_inmueble' => 'Vivienda'],
  ['tipo_inmueble' => 'Apartamento', 'destinacion' => 'Comercial', 'precio_arriendo' => '9000000', 'habitaciones' => '3', 'banos' => '2', 'area_construida' => '85', 'barrio' => 'El Recreo']
);
if ($features['monthly_rent'] !== '$1.250.000 COP' || $features['property_use'] !== 'Vivienda') throw new RuntimeException('Use current contractual rent and use, not advertised values.');
$missing = ContractRetentionDescription::propertyContext([], ['precio_arriendo' => '9000000', 'habitaciones' => '0']);
if ($missing['monthly_rent'] !== '' || $missing['bedrooms'] !== '0') throw new RuntimeException('Do not invent rent; preserve registered zero values.');
foreach (['contrato por terminar', 'terminación de contrato', 'no prórroga'] as $source) {
  $html = ContractRetentionDescription::build(array_merge($features, [
    'source' => $source, 'source_ticket' => $source === 'contrato por terminar' ? '' : '10863',
    'contract' => '2000', 'property' => '204578', 'tenant' => 'Arrendatario de prueba',
    'address' => 'Urbanización Simón Bolívar manzana 20 lote 7', 'end_date' => '09/01/2027',
    'status' => $source === 'contrato por terminar' ? '' : 'dentro de término',
    'document_url' => 'https://example.invalid/file.php?n=test.pdf&s=synthetic', 'document_title' => 'Respuesta contractual',
    'response' => 'CARTA COMPLETA QUE NO DEBE COPIARSE ' . str_repeat('texto ', 500),
  ]));
  foreach (['Apartamento', 'Vivienda', '$1.250.000 COP', 'Habitaciones:</strong> 3', 'Baños:</strong> 2', 'Área construida (m²):</strong> 85', 'El Recreo', 'retener al cliente en SKC SuCasa Inmobiliaria', 'opciones de inmuebles similares'] as $expected) {
    if (!str_contains($html, $expected)) throw new RuntimeException('Missing customer retention detail: ' . $expected);
  }
  if (!str_contains($html, '<ul>') || !str_contains($html, $source) || !str_contains($html, '09/01/2027') || str_contains($html, 'CARTA COMPLETA') || mb_strlen(strip_tags($html)) > 1400) throw new RuntimeException('Commercial handoff must be brief and structured for ' . $source);
  if ($source !== 'contrato por terminar' && !str_contains($html, 'Ticket #10863')) throw new RuntimeException('Original case must remain referenced.');
  $samples[] = '<h2>' . htmlspecialchars($source) . '</h2><article>' . $html . '</article>';
}
$unsafe = ContractRetentionDescription::build(['source' => '<script>alert(1)</script>', 'tenant' => '<img src=x onerror=alert(1)>', 'property_type' => '<script>bad</script>', 'document_url' => 'javascript:alert(1)']);
if (str_contains($unsafe, '<script>') || str_contains($unsafe, '<img ') || str_contains($unsafe, 'href=')) throw new RuntimeException('Unsafe content must be escaped.');
if (!str_contains(ContractRetentionDescription::build($missing), 'Sin registrar') || !str_contains(ContractRetentionDescription::build($missing), 'Habitaciones:</strong> 0')) throw new RuntimeException('Missing characteristics must be explicit.');
$path = sys_get_temp_dir() . '/scm-retention-description-qa.html';
file_put_contents($path, '<!doctype html><meta charset="utf-8"><style>body{font:16px Arial;color:#0f1e36;background:#f1f5f9;margin:24px}article{background:white;border:1px solid #dbe4f0;border-radius:12px;padding:24px;line-height:1.6}li{margin:4px 0}a{color:#2563eb}</style>' . implode('', $samples));
echo "PASS: all three retention sources, short HTML description, original case and document references, escaping. QA: {$path}\n";
