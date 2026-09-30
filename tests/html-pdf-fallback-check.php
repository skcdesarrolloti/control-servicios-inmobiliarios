<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) require_once $vendor;

$directory = sys_get_temp_dir() . '/scm-pdf-fallback-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) throw new RuntimeException('No se pudo crear el directorio de prueba.');
define('SCM_UPLOAD_PATH', $directory);
define('SCM_BASE_URL', 'https://example.test/control/public');
define('SCM_APP_SECRET', str_repeat('a', 32));
define('SCM_UPLOAD_MAX_BYTES', 10485760);

$chunk = static function (string $type, string $data): string {
  return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
};
$name = str_repeat('a', 24) . '_1.png';
$png = "\x89PNG\r\n\x1a\n"
  . $chunk('IHDR', pack('NNC5', 2, 1, 8, 6, 0, 0, 0))
  . $chunk('IDAT', gzcompress("\0\xff\x00\x00\xff\x00\x00\xff\x80"))
  . $chunk('IEND', '');
file_put_contents($directory . '/' . $name, $png);

try {
  $html = '<article><header><h2>Cotización #570</h2></header><section><h3>Datos de la cotización</h3>'
    . '<table><tr><th>Contrato</th><td>2000</td><th>Inmueble simi</th><td>204578</td></tr></table>'
    . '<p>Caso #10841 · Código inmueble web 84016</p></section><section><h3>Soportes de materiales</h3>'
    . '<img src="https://example.test/file.php?n=' . $name . '" alt="Oferta de materiales"></section></article>';
  $pdf = \SCM\Support\HtmlPdfFallback::render($html, 'Cotización de mantenimiento #570');
  if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-') || !str_contains($pdf, '/Subtype /Image') || !str_contains($pdf, '/FlateDecode')) {
    throw new RuntimeException('El PDF local no incluyó la cotización y su imagen PNG.');
  }
  if (class_exists(\Smalot\PdfParser\Parser::class)) {
    $text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
    foreach (['Cotización', 'Contrato', 'Inmueble simi', '204578', '84016'] as $required) {
      if (!str_contains($text, $required)) throw new RuntimeException('Falta texto en el PDF: ' . $required);
    }
  }
  echo "HTML PDF fallback checks passed.\n";
} finally {
  @unlink($directory . '/' . $name);
  @rmdir($directory);
}
