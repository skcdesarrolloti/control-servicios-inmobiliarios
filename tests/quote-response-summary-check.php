<?php

declare(strict_types=1);

// La vista de la cotización (panel, página pública y PDF) muestra lo que respondió el destinatario.
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
$base = ['_ID' => '700', 'id_ticket' => '10600', 'total' => '1000000'];

$pending = $view->invoke($app, $base + ['estado' => 'Esperando respuesta'], []);
$check(!str_contains($pending, 'scm-cotizacion-native-response'), 'a quote without an answer has no response section');

$approved = $view->invoke($app, $base + [
  'estado' => 'Aprobada',
  'fecha_respuesta' => (string) mktime(12, 0, 0, 11, 20, 2026),
  'financiacion' => 'No',
  'observacion_respuesta' => "Aprobada cotización para pago diferido.\n\nRespondido por: Constanza Echeverry Gomez",
], []);
$check(str_contains($approved, 'Respuesta a la cotizaci'), 'an answered quote shows the response section');
$check(str_contains($approved, '<strong>Aprobada</strong>') && str_contains($approved, '20-11-2026'), 'it shows the state and the answer date');
$check(str_contains($approved, '¿Requiere financiación?') && !str_contains($approved, '<span>Motivo</span>'), 'an approval shows financing and no reason');
$check(str_contains($approved, 'Respondido por: Constanza Echeverry Gomez') && str_contains($approved, '<br'), 'the answer observation keeps its line breaks');

$rejected = $view->invoke($app, $base + ['estado' => 'Desaprobada', 'motivo' => 'Por costo', 'financiacion' => ''], []);
$check(str_contains($rejected, '<span>Motivo</span><strong>Por costo</strong>') && !str_contains($rejected, 'financiación?'), 'a rejection shows the reason and no financing');

$noFinancing = $view->invoke($app, $base + ['estado' => 'Aprobada', 'financiacion' => ''], []);
$check(str_contains($noFinancing, '<strong>Sin respuesta</strong>'), 'an approval without a financing answer says so');

echo "Quote response summary checks passed.\n";
