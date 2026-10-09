<?php

declare(strict_types=1);

namespace SCM\Support;

final class PanelCaseAttachments
{
  private array $counts = ['imagenes' => 0, 'archivos' => 0];

  public function validate(array $input): void
  {
    $choice = (string) ($input['has_attachments'] ?? '');
    if (!in_array($choice, ['Si', 'No'], true)) throw new \InvalidArgumentException('Indica si el caso tiene adjuntos.');
    $totalBytes = 0;
    foreach ($this->counts as $field => $_count) {
      $files = $_FILES[$field] ?? [];
      if ($files !== [] && (!is_array($files['name'] ?? null) || !is_array($files['error'] ?? null))) {
        throw new \InvalidArgumentException('Formato de adjuntos inválido.');
      }
      foreach ((array) ($files['name'] ?? []) as $index => $name) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('No se pudo cargar ' . basename((string) $name) . '. Revisa el tamaño e inténtalo nuevamente.');
        $size = (int) ($files['size'][$index] ?? 0);
        $tmp = (string) ($files['tmp_name'][$index] ?? '');
        if ($size < 1 || $size > min((int) SCM_UPLOAD_MAX_BYTES, 10 * 1024 * 1024) || !is_uploaded_file($tmp)) {
          throw new \InvalidArgumentException('Cada adjunto debe pesar máximo 10 MB (o el límite del servidor).');
        }
        if ($field === 'imagenes') {
          $image = @getimagesize($tmp);
          if (!$image || !in_array($image['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $image[0] * $image[1] > 16000000) {
            throw new \InvalidArgumentException('Usa imágenes JPG, PNG o WebP de máximo 16 megapíxeles.');
          }
        } else {
          $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
          $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
          if ($extension !== 'pdf' || $mime !== 'application/pdf') throw new \InvalidArgumentException('Los documentos deben estar en formato PDF.');
        }
        $this->counts[$field]++;
        $totalBytes += $size;
      }
    }
    $count = array_sum($this->counts);
    if ($count > 10 || $totalBytes > 25 * 1024 * 1024) throw new \InvalidArgumentException('Carga máximo 10 adjuntos y 25 MB en total.');
    if (($choice === 'Si' && $count === 0) || ($choice === 'No' && $count > 0)) throw new \InvalidArgumentException('La selección de adjuntos no coincide con los archivos cargados.');
  }

  public function store(): array
  {
    $service = StoredFileService::fromRuntime();
    $stored = ['images' => [], 'documents' => []];
    try {
      $stored['images'] = $service->storeImagesDetailed('imagenes', 10);
      $titles = array_map(static fn($name): string => basename((string) $name), (array) ($_FILES['archivos']['name'] ?? []));
      $stored['documents'] = $service->storeDocuments('archivos', $titles, 10);
      if (count($stored['images']) !== $this->counts['imagenes'] || count($stored['documents']) !== $this->counts['archivos']) {
        throw new \RuntimeException('No se pudieron guardar todos los adjuntos. Intenta nuevamente.');
      }
      return $stored;
    } catch (\Throwable $e) {
      $this->cleanup($stored);
      throw $e;
    }
  }

  public function cleanup(array $stored): void
  {
    $service = StoredFileService::fromRuntime();
    foreach (array_merge($stored['images'] ?? [], $stored['documents'] ?? []) as $file) {
      parse_str((string) parse_url((string) ($file['url'] ?? $file['archivo'] ?? ''), PHP_URL_QUERY), $query);
      $path = $service->pathFor((string) ($query['n'] ?? ''));
      if ($path !== null) @unlink($path);
    }
  }
}
