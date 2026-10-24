<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\Tools\CleaningCalculator;
use SCM\Modules\Tools\CleaningQuotePdf;
use SCM\Modules\Tools\CleaningSettings;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
};

// Configuración guardada hoy por el plugin (wp_jet_cct_confi_sistema + wp_options).
$config = [
  'salario' => 1750905.0, 'auxilio_transporte' => 249095.0, 'prestaciones_sociales' => 0.4, 'factor_prestacional' => 1.52, 'valor_pasaje_trans' => 4000.0,
  'iva_servicios' => 0.19, 'margen_inmobiliaria' => 0.15, 'margen_contratista' => 0.1,
  'tramos' => [['desde' => 0.0, 'hasta' => 40.0, 'horas' => 2.0], ['desde' => 41.0, 'hasta' => 80.0, 'horas' => 3.5], ['desde' => 81.0, 'hasta' => 120.0, 'horas' => 4.5], ['desde' => 121.0, 'hasta' => 200.0, 'horas' => 6.0]],
  'transporte' => [['rango' => '0', 'valor' => 10000.0], ['rango' => '1-5', 'valor' => 30000.0], ['rango' => '10-19', 'valor' => 60000.0]],
  'insumos' => [['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => 8000.0, 'consumo' => 0.005], ['nombre' => 'Desengrasante', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.003], ['nombre' => 'Desinfectante', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.004], ['nombre' => 'Limpiavidrios', 'unidad' => 'ml', 'costo' => 12000.0, 'consumo' => 0.002], ['nombre' => 'Bolsas', 'unidad' => 'und', 'costo' => 1000.0, 'consumo' => 0.004]],
];

// Mismos valores que la fórmula del plugin (comprobada contra su JS en 5.880 combinaciones).
$f = CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'funcionario']);
$assert($f['horas'] === 3.5 && $f['mano_obra'] === 40000.0 && $f['transporte'] === 60000.0 && $f['insumos'] === 10000.0, 'funcionario: 3,5 h, MO 40.000, transporte 30.000 × 2, insumos 152 × 60 m² al mil');
$assert($f['base'] === 143000.0 && $f['iva'] === 27170.0 && $f['total'] === 170170.0, 'base redondeada a mil, IVA sobre la base');
$assert(abs($f['real_mano_obra'] - 40000 / 1.52) < 1e-9 && $f['real_transporte'] === 4000.0, 'costo real del funcionario: MO / factor y 50 % de dos pasajes');
$c = CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'contratista']);
$assert($c['transporte'] === 4000.0 && $c['real_mano_obra'] === $c['mano_obra'], 'contratista: transporte = auxilio, costo real = costo cobrado');

// Corrección: entre dos tramos (40,5 m²) el plugin daba 0 horas y $0 de mano de obra.
$gap = CleaningCalculator::calculate($config, ['m2' => '40,5', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '2', 'trabajador' => 'funcionario']);
$assert($gap['horas'] === 3.5 && $gap['mano_obra'] > 0, '40,5 m² usa el tramo 41–80');
$assert(CleaningCalculator::hours($config['tramos'], 250) === 7.0, 'por encima del último tramo: +1 hora cada 50 m²');

// Corrección: lo que ve el cliente suma el subtotal.
foreach ([$f, $c, $gap] as $result) {
  $lines = $result['cliente'];
  $assert($lines['servicio'] + $lines['transporte'] + $lines['insumos'] === $result['base'], 'las líneas del cliente suman el subtotal');
}
$assert(CleaningCalculator::calculate($config, ['m2' => '60', 'jornada' => 'Jornada Diurna', 'distancia' => '1-5', 'dificultad' => '4', 'trabajador' => 'contratista'])['protocolo'] === true, 'dificultad 4 o más activa el protocolo de visita');

foreach ([
  [['m2' => '0'], 'metros'],
  [['m2' => '60', 'jornada' => 'Otra'], 'jornada'],
  [['m2' => '60', 'jornada' => 'Jornada Diurna', 'dificultad' => '7'], 'dificultad'],
  [['m2' => '60', 'jornada' => 'Jornada Diurna', 'dificultad' => '2', 'trabajador' => 'contratista', 'distancia' => '99'], 'distancia'],
] as [$input, $word]) {
  $error = '';
  try {
    CleaningCalculator::calculate($config, $input);
  } catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
  }
  $assert(stripos($error, $word) !== false, 'valida ' . $word);
}

// PDF: el del cliente no muestra costos internos; el interno sí.
$client = CleaningQuotePdf::html('cliente', $f, $config, ['cliente' => 'Cliente QA'], 'Funcionario QA');
$internal = CleaningQuotePdf::html('inmobiliaria', $f, $config, [], 'Funcionario QA');
$assert(str_contains($client, 'Cliente QA') && str_contains($client, '$170.170') && !str_contains($client, 'Utilidad') && !str_contains($client, 'Precio contratista'), 'el PDF del cliente no expone márgenes');
$assert(str_contains($internal, 'Utilidad inmobiliaria') && str_contains($internal, 'Realizado por') && str_contains($internal, 'SKC SuCasa Inmobiliaria'), 'el PDF interno lleva utilidades y el cierre "Realizado por"');

// Configuración: mismas ubicaciones del plugin; porcentajes en %, tramos ordenados, cct_author_id = id_empleado.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, funcion TEXT UNIQUE, valor TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_confi_sistema(funcion, valor) VALUES ('salario', '1750905'), ('iva_servicios', '0.19')");
$pdo->exec('CREATE TABLE wp_options(option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
$pdo->exec("INSERT INTO wp_options(option_name, option_value, autoload) VALUES ('aseo_transporte', '" . serialize([['rango' => '0', 'valor' => '10000']]) . "', 'yes')");
$settings = new CleaningSettings(new \SCM\Core\Database($pdo, 'wp_'));
$read = $settings->read();
$assert($read['salario'] === 1750905.0 && $read['margen_inmobiliaria'] === 0.15 && $read['transporte'] === [['rango' => '0', 'valor' => 10000.0]] && count($read['tramos']) === 4, 'lee lo guardado y completa con los valores del plugin');
$saved = $settings->save([
  'salario' => '1423500', 'auxilio_transporte' => '200000', 'prestaciones_sociales' => '40', 'factor_prestacional' => '1,52', 'valor_pasaje_trans' => '4000',
  'iva_servicios' => '19', 'margen_inmobiliaria' => '15', 'margen_contratista' => '10,5',
  'tramos' => [['desde' => '41', 'hasta' => '80', 'horas' => '3,5'], ['desde' => '0', 'hasta' => '40', 'horas' => '2'], ['desde' => '', 'hasta' => '', 'horas' => '']],
  'transporte' => [['rango' => '0', 'valor' => '12000'], ['rango' => '1-5', 'valor' => '32000']],
  'insumos' => [['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => '8000', 'consumo' => '0,005']],
], '1077');
$assert($saved['salario'] === 1423500.0 && $saved['margen_contratista'] === 0.105 && $saved['iva_servicios'] === 0.19 && $saved['factor_prestacional'] === 1.52, 'los porcentajes se guardan como fracción, como el plugin');
$assert($saved['tramos'][0]['hasta'] === 40.0 && count($saved['tramos']) === 2, 'tramos ordenados y filas vacías descartadas');
$row = $pdo->query("SELECT valor, cct_author_id FROM wp_jet_cct_confi_sistema WHERE funcion = 'margen_contratista'")->fetch(PDO::FETCH_ASSOC);
$assert($row['valor'] === '0.105' && (int) $row['cct_author_id'] === 1077, 'variable nueva con cct_author_id del funcionario');
$option = unserialize((string) $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'aseo_insumos'")->fetchColumn());
$assert($option === [['nombre' => 'Detergente', 'unidad' => 'ml', 'costo' => '8000', 'consumo' => '0.005']], 'tablas serializadas como las guarda WordPress');
foreach ([['margen_inmobiliaria' => '100'], ['salario' => '0'], ['tramos' => [['desde' => '50', 'hasta' => '10', 'horas' => '2']]], ['transporte' => [['rango' => '1', 'valor' => '1'], ['rango' => '1', 'valor' => '2']]]] as $bad) {
  $error = '';
  try {
    $settings->save($bad + $saved + ['prestaciones_sociales' => '40', 'iva_servicios' => '19', 'margen_inmobiliaria' => '15', 'margen_contratista' => '10'], '1077');
  } catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
  }
  $assert($error !== '', 'rechaza configuración inválida: ' . json_encode($bad));
}

echo "OK tools cleaning calculator\n";
