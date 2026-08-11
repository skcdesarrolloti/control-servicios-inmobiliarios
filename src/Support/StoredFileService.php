<?php

declare(strict_types=1);

namespace SCM\Support;

final class StoredFileService
{
  private string $directory;
  private string $baseUrl;
  private string $secret;
  private int $maxBytes;

  public function __construct(string $directory, string $baseUrl, string $secret, int $maxBytes)
  {
    $this->directory = rtrim($directory, '/\\');
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->secret = $secret;
    $this->maxBytes = max(1024, $maxBytes);
  }

  public static function fromRuntime(): self
  {
    return new self(
      (string) SCM_UPLOAD_PATH,
      (string) SCM_BASE_URL,
      (string) SCM_APP_SECRET,
      (int) SCM_UPLOAD_MAX_BYTES
    );
  }

  /** @return string[] */
  public function storeImages(string $fieldName, int $maximumFiles = 10): array
  {
    $stored = [];
    foreach ($this->normalizeUploadedFiles($fieldName) as $file) {
      if (count($stored) >= max(1, $maximumFiles)) {
        break;
      }
      $name = $this->storeImage($file);
      if ($name !== '') {
        $stored[] = $this->urlFor($name);
      }
    }
    return $stored;
  }

  /** @param string[] $titles @return array<int,array{nombre_archivo:string,archivo:string}> */
  public function storeDocuments(string $fieldName, array $titles = [], int $maximumFiles = 10): array
  {
    $stored = [];
    foreach ($this->normalizeUploadedFiles($fieldName) as $index => $file) {
      if (count($stored) >= max(1, $maximumFiles)) {
        break;
      }
      $name = $this->storeDocument($file);
      if ($name === '') {
        continue;
      }
      $stored[] = [
        'nombre_archivo' => trim(strip_tags(stripslashes((string) ($titles[$index] ?? '')))),
        'archivo' => $this->urlFor($name),
      ];
    }
    return $stored;
  }

  public function urlFor(string $name): string
  {
    $name = basename($name);
    return $this->baseUrl . '/file.php?n=' . rawurlencode($name)
      . '&s=' . rawurlencode($this->signature($name));
  }

  public function isValidSignature(string $name, string $signature): bool
  {
    $name = basename($name);
    return $name !== '' && $signature !== '' && hash_equals($this->signature($name), $signature);
  }

  public function pathFor(string $name): ?string
  {
    $name = basename($name);
    if ($name === '' || preg_match('/^[a-f0-9]{24}_[0-9]+\.[a-z0-9]{1,8}$/', $name) !== 1) {
      return null;
    }
    $path = $this->directory . '/' . $name;
    return is_file($path) ? $path : null;
  }

  /** @param array<string,mixed> $file */
  private function storeImage(array $file): string
  {
    if (!$this->isAcceptableUpload($file)) {
      return '';
    }

    $mime = $this->detectMime((string) $file['tmp_name']);
    $extensionMap = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'image/bmp' => 'bmp',
      'image/x-ms-bmp' => 'bmp',
      'image/heic' => 'heic',
      'image/heif' => 'heif',
      'image/tiff' => 'tiff',
    ];
    if (!isset($extensionMap[$mime]) || !$this->ensureDirectory()) {
      return '';
    }

    $tmpName = (string) $file['tmp_name'];
    if ($mime === 'image/gif' || !extension_loaded('gd')) {
      return $this->moveUpload($tmpName, $extensionMap[$mime]);
    }

    $source = match ($mime) {
      'image/jpeg' => @imagecreatefromjpeg($tmpName),
      'image/png' => @imagecreatefrompng($tmpName),
      'image/webp' => @imagecreatefromwebp($tmpName),
      default => false,
    };
    if ($source === false) {
      return $this->moveUpload($tmpName, $extensionMap[$mime]);
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width > 1600 || $height > 1600) {
      $ratio = min(1600 / $width, 1600 / $height);
      $canvas = imagecreatetruecolor((int) round($width * $ratio), (int) round($height * $ratio));
      if ($canvas !== false) {
        imagecopyresampled(
          $canvas,
          $source,
          0,
          0,
          0,
          0,
          imagesx($canvas),
          imagesy($canvas),
          $width,
          $height
        );
        imagedestroy($source);
        $source = $canvas;
      }
    }

    $name = $this->newName('jpg');
    $saved = imagejpeg($source, $this->directory . '/' . $name, 78);
    imagedestroy($source);
    return $saved ? $name : '';
  }

  /** @param array<string,mixed> $file */
  private function storeDocument(array $file): string
  {
    if (!$this->isAcceptableUpload($file) || !$this->ensureDirectory()) {
      return '';
    }

    $mime = $this->detectMime((string) $file['tmp_name']);
    $originalExtension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = [
      'pdf' => ['application/pdf'],
      'doc' => ['application/msword'],
      'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
      'xls' => ['application/vnd.ms-excel'],
      'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
      'txt' => ['text/plain'],
      'csv' => ['text/csv', 'text/plain', 'application/csv'],
      'jpg' => ['image/jpeg'],
      'jpeg' => ['image/jpeg'],
      'png' => ['image/png'],
      'gif' => ['image/gif'],
      'webp' => ['image/webp'],
    ];
    if (!isset($allowed[$originalExtension]) || !in_array($mime, $allowed[$originalExtension], true)) {
      return '';
    }

    return $this->moveUpload((string) $file['tmp_name'], $originalExtension === 'jpeg' ? 'jpg' : $originalExtension);
  }

  /** @param array<string,mixed> $file */
  private function isAcceptableUpload(array $file): bool
  {
    return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
      && (string) ($file['tmp_name'] ?? '') !== ''
      && (int) ($file['size'] ?? 0) > 0
      && (int) ($file['size'] ?? 0) <= $this->maxBytes
      && is_uploaded_file((string) $file['tmp_name']);
  }

  private function moveUpload(string $tmpName, string $extension): string
  {
    $name = $this->newName($extension);
    return move_uploaded_file($tmpName, $this->directory . '/' . $name) ? $name : '';
  }

  private function newName(string $extension): string
  {
    return bin2hex(random_bytes(12)) . '_' . time() . '.' . $extension;
  }

  private function detectMime(string $path): string
  {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    return (string) $finfo->file($path);
  }

  private function ensureDirectory(): bool
  {
    return is_dir($this->directory)
      || (mkdir($this->directory, 0750, true) && is_dir($this->directory));
  }

  private function signature(string $name): string
  {
    return hash_hmac('sha256', 'stored-file|' . $name, $this->secret);
  }

  /** @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> */
  private function normalizeUploadedFiles(string $fieldName): array
  {
    $file = $_FILES[$fieldName] ?? null;
    if (!is_array($file) || !isset($file['name'])) {
      return [];
    }
    if (!is_array($file['name'])) {
      return [[
        'name' => (string) $file['name'],
        'type' => (string) ($file['type'] ?? ''),
        'tmp_name' => (string) ($file['tmp_name'] ?? ''),
        'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($file['size'] ?? 0),
      ]];
    }

    $normalized = [];
    foreach ($file['name'] as $index => $name) {
      $normalized[] = [
        'name' => (string) $name,
        'type' => (string) ($file['type'][$index] ?? ''),
        'tmp_name' => (string) ($file['tmp_name'][$index] ?? ''),
        'error' => (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($file['size'][$index] ?? 0),
      ];
    }
    return $normalized;
  }
}
