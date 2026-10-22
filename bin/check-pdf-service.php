<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Verifica que el panel genere PDF con el servicio configurado en SCM_GOTENBERG_URL (VPS).
$url = trim((string) getenv('SCM_GOTENBERG_URL'));
if ($url === '') {
  fwrite(STDERR, "Falta SCM_GOTENBERG_URL en el .env.\n");
  exit(1);
}
echo "Servicio configurado: {$url}\n";
$started = microtime(true);
$bytes = \SCM\Support\HtmlPdfRenderer::render('<h1>Prueba de PDF</h1><p>SKC SuCasa Inmobiliaria · ' . date('d/m/Y H:i') . '</p>', 'Prueba de PDF', ['allow_fallback' => false]);
$seconds = number_format(microtime(true) - $started, 2);
if ($bytes === null) {
  fwrite(STDERR, "FALLÓ: no se generó el PDF ({$seconds} s). Revisa URL, usuario, contraseña y que el VPS responda por HTTPS.\n");
  exit(1);
}
echo 'OK: PDF de ' . number_format(strlen($bytes)) . " bytes en {$seconds} s.\n";
