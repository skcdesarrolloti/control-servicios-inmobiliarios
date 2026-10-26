<?php

declare(strict_types=1);

// % de administración sugerido: mismos valores que Simulacion_Honorarios_SKC.xlsx. Sin base de datos.
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
require_once dirname(__DIR__) . '/src/Core/Helpers.php';

use SCM\Modules\QuoteFee\QuoteFeeSettings;
use SCM\Modules\QuoteFee\QuoteFeeView;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
  echo 'PASS: ' . $message . PHP_EOL;
};
$near = static fn(float $a, float $b): bool => abs($a - $b) < 0.01;

// Parámetros del Excel: p 10 %, F 30.000 (C6 = 30.000 / 1.795.007 SMLMV), T 10.000.000, k 0,8.
$config = QuoteFeeSettings::fromInput(['salario' => '1.795.007', 'tarifa' => '10', 'f' => '30000', 'transicion' => '10000000', 'k' => '0,8', 'curva' => 'elipse']);
$assert($near($config['f'], 30000.0) && abs($config['f_smlmv'] - 30000 / 1795007) < 1e-12, 'F se guarda como fracción del SMLMV (celda C6)');

// Columna G (honorario elipse) y H (% elipse) del Excel.
foreach ([100000 => 39210.91833198661, 200000 => 48442.93823704077, 500000 => 76255.76957218423, 1000000 => 122951.94528130758, 2000000 => 217371.88186310435, 5000000 => 506261.3645756624, 10000000 => 1000000.0, 150000 => 43824.35706998764] as $repair => $fee) {
  $assert($near(QuoteFeeSettings::fee($config, (float) $repair), $fee), 'elipse ' . $repair . ' → ' . round($fee, 2));
}
$assert(QuoteFeeSettings::suggestedPercent($config, 1000000.0) === 12.3, '% sugerido para 1.000.000 = 12,3 % (H del Excel: 12,295 %)');
$assert(QuoteFeeSettings::suggestedPercent($config, 25000000.0) === 10.0, 'desde T solo aplica la tarifa proporcional');
$assert(QuoteFeeSettings::suggestedPercent($config, 0.0) === null, 'sin subtotal no hay sugerencia');

// Columna D (parábola).
$parabola = array_merge($config, ['curva' => 'parabola']);
foreach ([100000 => 39403.0, 500000 => 77075.0, 2000000 => 219200.0, 150000 => 44106.75] as $repair => $fee) {
  $assert($near(QuoteFeeSettings::fee($parabola, (float) $repair), $fee), 'parábola ' . $repair . ' → ' . $fee);
}

// Al año siguiente F sube con el salario: 2.000.000 × C6 = 33.426,05 (celda C4).
$nextYear = QuoteFeeSettings::fromInput(['salario' => '2000000', 'tarifa' => '10', 'f' => (string) (2000000 * $config['f_smlmv']), 'transicion' => '10000000', 'k' => '0.8', 'curva' => 'elipse']);
$assert($near($nextYear['f'], 33426.05349171341), 'F con salario 2.000.000 = 33.426,05');

// Celda D8: con los parámetros del Excel ambas curvas son crecientes; con F alto deja de serlo.
$assert(QuoteFeeSettings::isIncreasing($config) && QuoteFeeSettings::isIncreasing($parabola), 'honorario creciente con los parámetros del Excel');
$assert(!QuoteFeeSettings::isIncreasing(array_merge($config, ['f' => 800000.0])), 'avisa cuando el honorario puede bajar');

foreach ([['k' => '0'], ['k' => '1,5'], ['transicion' => '0'], ['tarifa' => '100'], ['curva' => 'recta'], ['f' => 'abc']] as $bad) {
  try {
    QuoteFeeSettings::fromInput(array_merge(['salario' => '1750905', 'tarifa' => '10', 'f' => '30000', 'transicion' => '10000000', 'k' => '0.8', 'curva' => 'elipse'], $bad));
    $assert(false, 'rechaza ' . json_encode($bad));
  } catch (\InvalidArgumentException) {
    $assert(true, 'rechaza ' . json_encode($bad));
  }
}

$html = QuoteFeeView::form($config);
$assert(str_contains($html, 'data-quote-fee-form') && str_contains($html, 'name="f" value="30000"') && str_contains($html, '12,3 %'), 'el formulario muestra F en pesos y la tabla de ejemplos');

// Cableado: ruta, permiso, menú Configuración y modal de envío.
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert(str_contains($read('src/Http/Api/AuthenticatedActionRouter.php'), "'scm_quote_fee_settings' => 'ajax_handler_quote_fee_settings'"), 'ruta AJAX registrada');
$assert(str_contains($read('includes/header.php'), 'data-quote-fee-settings') && str_contains($read('public/assets/js/admin-dashboard-runtime.js'), 'quote_fee_configure: "[data-quote-fee-settings]"'), 'opción en Configuración, oculta sin quote_fee_configure');
$assert(str_contains($read('src/App/Concerns/RendersDashboard.php'), 'data-porcentaje-sugerido') && str_contains($read('public/assets/js/admin-dashboard-runtime.js'), 'swal-send-cot-usar-sugerido'), 'el envío de la cotización propone el % sugerido');
