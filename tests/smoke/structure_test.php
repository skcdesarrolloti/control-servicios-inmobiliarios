<?php

$files = [
    __DIR__ . '/../../index.php',
    __DIR__ . '/../../api.php',
    __DIR__ . '/../../src/App/SuCasaControlServiciosInmobiliarios.php',
    __DIR__ . '/../../src/Modules/GenericTickets/GenericTicketsModule.php',
    __DIR__ . '/../../src/Modules/ServiciosInmobiliarios/SeguimientoService.php',
];

$ok = true;
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false || trim($content) === '') {
        fwrite(STDERR, "[FAIL] Archivo vacio o no legible: {$file}\n");
        $ok = false;
    }
}

$pluginContent = file_get_contents(__DIR__ . '/../../src/App/SuCasaControlServiciosInmobiliarios.php') ?: '';
$checks = [
    'ajax_handler' => 'public function ajax_handler()',
    'ajax_handler_generic' => 'public function ajax_handler_generic(): void',
    'ajax_handler_seguimiento' => 'public function ajax_handler_seguimiento()',
    'seguimiento_service_getter' => 'private function get_seguimiento_service()',
];

foreach ($checks as $name => $needle) {
    if (strpos($pluginContent, $needle) === false) {
        fwrite(STDERR, "[FAIL] No se encontro {$name} en plugin principal.\n");
        $ok = false;
    }
}

$serviceContent = file_get_contents(__DIR__ . '/../../src/Modules/ServiciosInmobiliarios/SeguimientoService.php') ?: '';
if (strpos($serviceContent, 'final class SeguimientoService') === false) {
    fwrite(STDERR, "[FAIL] SeguimientoService no esta definido correctamente.\n");
    $ok = false;
}

if (!$ok) {
    exit(1);
}

echo "[OK] smoke/structure_test.php\n";

