<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

$name = basename((string) ($_GET['n'] ?? ''));
$signature = (string) ($_GET['s'] ?? '');
$files = \SCM\Support\StoredFileService::fromRuntime();
$path = $files->pathFor($name);
if ($path === null || !$files->isValidSignature($name, $signature)) {
  http_response_code(404);
  exit('Archivo no disponible.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($path);
// Los .pdf guardados ya se validaron al subirlos; algunos traen saltos de línea antes de la cabecera y finfo no los reconoce.
if (str_ends_with($name, '.pdf')) {
  $mime = 'application/pdf';
}
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');
readfile($path);
