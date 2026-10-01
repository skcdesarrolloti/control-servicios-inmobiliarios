<?php
declare(strict_types=1);

// Offline Chromium regression: fonts must travel in the PDF, even without Arial.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
define('SCM_BASE_URL', 'https://example.invalid');
putenv('SCM_GOTENBERG_URL=');
$root = dirname(__DIR__);
$html = '<article class="scm-acta scm-acta-receipt"><h1>ACTA DE RECIBO A SATISFACCIÓN</h1>'
  . '<p>Dirección: Urbanización Simón Bolívar. José Muñoz Pérez.</p>'
  . '<h2>Firmas Electrónicas</h2><p class="scm-acta-typed-signature">José Muñoz Pérez</p></article>';
$pdf = \SCM\Support\HtmlPdfRenderer::render($html, 'Fuentes del acta', [
  'stylesheets' => [$root . '/public/assets/css/ticket-completion.css', $root . '/public/assets/css/ticket-completion-document.css'],
  'external_fonts' => false, 'allow_fallback' => false, 'body_class' => 'scm-acta-page',
]);
if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) throw new RuntimeException('Se requiere Chromium local para verificar las fuentes del acta.');
foreach (['NotoSans', 'Caveat'] as $font) {
  if (!str_contains($pdf, $font)) throw new RuntimeException('El PDF no incluye la fuente requerida: ' . $font);
}
if (str_contains($pdf, 'Arial')) throw new RuntimeException('El acta todavía depende de Arial instalada en el servidor.');
echo "PDF fonts passed: body and signature embedded; no system Arial dependency.\n";
