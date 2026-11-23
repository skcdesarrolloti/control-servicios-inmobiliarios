<?php

declare(strict_types=1);

// Los saldos de la cotización son control interno: solo en la vista del panel, nunca en la página pública ni en el
// PDF que recibe el destinatario.
require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

$app = (new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
$view = new ReflectionMethod($app, 'render_native_cotizacion_mantenimiento_view');
$row = ['_ID' => '608', 'id_ticket' => '10508', 'estado' => 'Aprobada', 'total' => '4555922', 'total_materiales' => '2229900', 'total_mano_obra' => '1700000', 'total_maquinarias' => '0', 'total_otros_costos' => '230000', 'saldo_materiales' => '215700', 'saldo_obra' => '1700000', 'saldo_maquinarias' => '0', 'saldo_otros_costo' => '230000', 'porcentaje_admon' => '8', 'total_admon' => '332792', 'iva' => '19', 'iva_admon' => '63230'];

$recipient = $view->invoke($app, $row, []);
$check(!str_contains($recipient, 'Saldos de la cotizaci') && !str_contains($recipient, 'scm-cotizacion-native-balances') && !str_contains($recipient, '$215.700'), 'the recipient view and PDF do not show the quote balances');
$check(str_contains($recipient, 'Total cotización'), 'the recipient still sees the quote totals');
$internal = $view->invoke($app, $row, [], true);
$check(str_contains($internal, 'Saldos de la cotizaci') && str_contains($internal, '$215.700'), 'the panel view shows the quote balances to staff');

$source = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
$check(str_contains($source, "\$nativeCotizacionHtml = \$this->render_native_cotizacion_mantenimiento_view(\$row, \$orders, true);"), 'the panel card renders the internal view');
$check(str_contains($source, "\$responseGuide . \$this->render_native_cotizacion_mantenimiento_view(\$row, \$orders) . \$responsePanel"), 'the public page renders the recipient view');
$pdf = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/HandlesMaintenanceActions.php');
$check(str_contains($pdf, "\$html = \$this->render_native_cotizacion_mantenimiento_view(\$row, \$orders);"), 'the quote PDF uses the recipient view');

echo "Quote balances visibility checks passed.\n";
