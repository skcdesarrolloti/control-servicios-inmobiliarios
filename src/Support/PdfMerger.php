<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Une varios PDF en uno con Gotenberg (el mismo servicio que los genera). Los archivos originales no cambian: el
 * resultado es una copia nueva. Si no se puede unir devuelve null y quien llama decide qué hacer.
 */
final class PdfMerger
{
  /** @param list<string> $pdfs contenido de cada PDF, en el orden en que deben quedar */
  public static function merge(array $pdfs): ?string
  {
    if ($pdfs === []) return null;
    if (count($pdfs) === 1) return $pdfs[0];
    $baseUrl = trim((string) getenv('SCM_GOTENBERG_URL'));
    if (!str_starts_with($baseUrl, 'https://') || !function_exists('curl_init')) return null;
    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/scm-merge-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) return null;
    $paths = [];
    try {
      $fields = [];
      foreach (array_values($pdfs) as $index => $bytes) {
        // Gotenberg une por orden alfabético del nombre del archivo.
        $name = sprintf('%03d.pdf', $index + 1);
        $path = $directory . '/' . $name;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) return null;
        $paths[] = $path;
        $fields['files[' . $index . ']'] = new \CURLFile($path, 'application/pdf', $name);
      }
      $curl = curl_init(rtrim($baseUrl, '/') . '/forms/pdfengines/merge');
      if ($curl === false) return null;
      $options = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
      ];
      $username = trim((string) getenv('SCM_GOTENBERG_USERNAME'));
      $password = (string) getenv('SCM_GOTENBERG_PASSWORD');
      if ($username !== '' && $password !== '') {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = $username . ':' . $password;
      }
      curl_setopt_array($curl, $options);
      $bytes = curl_exec($curl);
      $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);
      if ($status === 200 && is_string($bytes) && str_starts_with($bytes, '%PDF-')) return $bytes;
      error_log('[pdf-merger] Gotenberg respondió ' . $status . ' al unir ' . count($pdfs) . ' PDF.');
      return null;
    } finally {
      foreach ($paths as $path) @unlink($path);
      @rmdir($directory);
    }
  }
}
