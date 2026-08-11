<?php

declare(strict_types=1);

// Compatibilidad de solo lectura para adjuntos creados antes de los enlaces firmados.
$name = basename((string) ($_GET['n'] ?? ''));
$extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
$allowedExtensions = [
  'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
  'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff',
];

if ($name === ''
  || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $name) !== 1
  || !in_array($extension, $allowedExtensions, true)) {
  http_response_code(404);
  exit('Archivo no disponible.');
}

$storageRoot = dirname(__DIR__) . '/storage/uploads';
$path = $storageRoot . '/' . $name;
if (!is_file($path)) {
  http_response_code(404);
  exit('Archivo no disponible.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($path);
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=3600');
readfile($path);
