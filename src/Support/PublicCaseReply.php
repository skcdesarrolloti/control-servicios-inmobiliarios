<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/**
 * Respuesta de un actor desde la vista pública del caso (`?scm_case=`).
 * Solo la registra en el historial del caso y avisa al funcionario: no cambia `estado` ni `estado_administrativo`.
 */
final class PublicCaseReply
{
  public const MAX_TEXT = 3000;
  public const MAX_IMAGES = 5;
  public const MAX_DOCUMENTS = 3;
  public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

  public function __construct(private Database $db, private SchemaInspector $schema) {}

  public static function canReply(string $audience, array $ticket): bool
  {
    return isset(CaseReplyNotifications::AUDIENCES[$audience]) && !self::isClosed($ticket);
  }

  public static function isClosed(array $ticket): bool
  {
    return in_array(mb_strtolower(trim((string) ($ticket['estado'] ?? ''))), ['cerrado', 'finalizado', 'anulado'], true);
  }

  /**
   * @param array<string,mixed> $files $_FILES
   * @return array{email:int,whatsapp:int}
   */
  public function submit(array $ticket, string $audience, string $text, array $files): array
  {
    if (!self::canReply($audience, $ticket)) throw new \DomainException(self::isClosed($ticket) ? 'Este caso ya está cerrado y no recibe respuestas.' : 'Este enlace no permite responder el caso.');
    $text = trim(str_replace("\r\n", "\n", $text));
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    if (mb_strlen($text) < 3) throw new \InvalidArgumentException('Escribe tu respuesta antes de enviarla.');
    if (mb_strlen($text) > self::MAX_TEXT) throw new \InvalidArgumentException('La respuesta puede tener máximo ' . self::MAX_TEXT . ' caracteres.');
    $counts = $this->validateFiles($files);

    $id = (int) $ticket['_ID'];
    $table = $this->db->table('jet_cct_historial_del_ticket');
    if (!$this->schema->tableExists($table)) throw new \RuntimeException('No se pudo guardar la respuesta.');
    $storage = StoredFileService::fromRuntime();
    $stored = ['images' => [], 'documents' => []];
    try {
      if ($counts['imagenes'] > 0) $stored['images'] = $storage->storeImagesDetailed('imagenes', self::MAX_IMAGES);
      if ($counts['archivos'] > 0) {
        // Mismo índice que $_FILES: StoredFileService empareja cada título con su archivo.
        $titles = array_map(static fn($name): string => mb_substr(basename((string) $name), 0, 120), array_values((array) ($files['archivos']['name'] ?? [])));
        $stored['documents'] = $storage->storeDocuments('archivos', $titles, self::MAX_DOCUMENTS);
      }
      if (count($stored['images']) !== $counts['imagenes'] || count($stored['documents']) !== $counts['archivos']) {
        throw new \RuntimeException('No se pudieron guardar todos los adjuntos. Intenta nuevamente.');
      }
      $name = CaseReplyNotifications::actorName($ticket, $audience);
      $contact = ['publico' => 'solicitante'][$audience] ?? $audience;
      $now = time();
      $images = array_column($stored['images'], 'url');
      $payload = [
        'cct_status' => 'publish',
        'id_ticket' => $id,
        'fecha' => $now,
        'nombre' => $name . ' (' . CaseReplyNotifications::audienceLabel($audience) . ')',
        'correo' => trim((string) ($ticket['correo_' . $contact] ?? '')),
        'celular' => trim((string) ($ticket['celular_' . $contact] ?? '')),
        'respuesta' => nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false),
        // Respuesta de un actor externo: no hay funcionario autor.
        'cct_author_id' => 0,
        'id_empleado' => '',
        'cct_created' => date('Y-m-d H:i:s', $now),
        'cct_modified' => date('Y-m-d H:i:s', $now),
        'fue_editada' => 'No',
      ];
      if (count($images) === 1) $payload['imagen'] = $images[0];
      elseif (count($images) > 1) $payload['imagen'] = serialize($images);
      if ($stored['documents'] !== []) $payload['archivos'] = serialize($stored['documents']);
      $payload = $this->schema->filterTableData($table, $payload);
      if (empty($payload) || !$this->db->insert($table, $payload)) throw new \RuntimeException('No se pudo guardar la respuesta.');
    } catch (\Throwable $error) {
      (new PanelCaseAttachments())->cleanup($stored);
      throw $error;
    }

    $dedupe = 'respuesta_caso_actor:' . $id . ':' . substr(hash('sha256', $audience . '|' . $now . '|' . $text), 0, 16);
    return (new CaseReplyNotifications($this->db))->notifyActorReply($ticket, $id, $audience, $name, $text, count($images) + count($stored['documents']), $dedupe);
  }

  /** @return array{imagenes:int,archivos:int} */
  private function validateFiles(array $files): array
  {
    $counts = ['imagenes' => 0, 'archivos' => 0];
    $total = 0;
    foreach ($counts as $field => $_) {
      $group = $files[$field] ?? [];
      if ($group === []) continue;
      if (!is_array($group['name'] ?? null) || !is_array($group['error'] ?? null)) throw new \InvalidArgumentException('Formato de adjuntos inválido.');
      foreach ($group['name'] as $index => $name) {
        $error = (int) ($group['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('No se pudo cargar ' . basename((string) $name) . '. Revisa el tamaño e inténtalo nuevamente.');
        $size = (int) ($group['size'][$index] ?? 0);
        $tmp = (string) ($group['tmp_name'][$index] ?? '');
        if ($size < 1 || $size > min((int) SCM_UPLOAD_MAX_BYTES, 10 * 1024 * 1024) || !is_uploaded_file($tmp)) {
          throw new \InvalidArgumentException('Cada adjunto debe pesar máximo 10 MB.');
        }
        if ($field === 'imagenes') {
          $image = @getimagesize($tmp);
          if (!$image || !in_array($image['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $image[0] * $image[1] > 16000000) {
            throw new \InvalidArgumentException('Usa fotos JPG, PNG o WebP.');
          }
        } else {
          $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
          if (strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) !== 'pdf' || $mime !== 'application/pdf') throw new \InvalidArgumentException('Los documentos deben estar en formato PDF.');
        }
        $counts[$field]++;
        $total += $size;
      }
    }
    if ($counts['imagenes'] > self::MAX_IMAGES) throw new \InvalidArgumentException('Adjunta máximo ' . self::MAX_IMAGES . ' fotos.');
    if ($counts['archivos'] > self::MAX_DOCUMENTS) throw new \InvalidArgumentException('Adjunta máximo ' . self::MAX_DOCUMENTS . ' documentos PDF.');
    if ($total > self::MAX_TOTAL_BYTES) throw new \InvalidArgumentException('Los adjuntos pueden sumar máximo 20 MB.');
    return $counts;
  }
}
