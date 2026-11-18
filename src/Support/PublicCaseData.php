<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/** Explicit public allowlist: never use the administrative presenter or its actions; history goes through history(). */
final class PublicCaseData
{
  public static function fromTicket(array $ticket): array
  {
    $data = [];
    foreach (['_ID', 'asunto', 'tema_ayuda', 'estado', 'contrato', 'inmueble', 'direccion'] as $field) $data[$field] = trim(strip_tags((string) ($ticket[$field] ?? '')));
    $description = preg_replace('/<\/(p|div|li)>|<br\s*\/?\s*>/i', "\n", (string) ($ticket['descripcion'] ?? ''));
    $data['descripcion'] = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $description) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $rawDate = (string) ($ticket['fecha'] ?? '');
    $date = ctype_digit($rawDate) ? (int) $rawDate : (strtotime((string) ($ticket['cct_created'] ?? '')) ?: 0);
    $data['fecha'] = $date > 0 ? date('d/m/Y H:i', $date) . ' (Colombia)' : '';
    $data['responsable'] = trim(strip_tags((string) ($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '')));
    $data['images'] = [];
    foreach (explode(',', (string) ($ticket['imagenes'] ?? '')) as $url) {
      $url = self::safeAttachment(trim($url), ['jpg', 'jpeg', 'png', 'webp']);
      if ($url !== '') $data['images'][] = $url;
    }
    $data['documents'] = [];
    $documents = is_string($ticket['archivos'] ?? null) ? @unserialize($ticket['archivos'], ['allowed_classes' => false]) : [];
    if (is_array($documents)) foreach ($documents as $document) {
      if (!is_array($document)) continue;
      $url = self::safeAttachment((string) ($document['media_archivo'] ?? $document['archivo'] ?? ''), ['pdf']);
      if ($url !== '') $data['documents'][] = ['url' => $url, 'name' => strip_tags((string) ($document['nombre_archivo'] ?? 'Documento PDF'))];
    }
    $data['images'] = array_slice(array_values(array_unique($data['images'])), 0, 10);
    $data['documents'] = array_slice($data['documents'], 0, 10);
    return $data;
  }

  /**
   * Historial de respuestas del caso para la vista pública: autor, fecha, texto y adjuntos permitidos.
   * Nunca publica correo, celular ni identificadores internos de la fila.
   * @return list<array{author:string,date:string,text:string,external:bool,images:list<string>,documents:list<array{url:string,name:string}>}>
   */
  public static function history(Database $db, array $ticket, int $limit = 100): array
  {
    $table = $db->table('jet_cct_historial_del_ticket');
    $keys = array_values(array_unique(array_filter([trim((string) ($ticket['_ID'] ?? '')), trim((string) ($ticket['id_ticket'] ?? ''))], static fn(string $key): bool => $key !== '')));
    if ($keys === []) return [];
    $rows = $db->getResults(
      'SELECT `nombre`, `fecha`, `cct_created`, `respuesta`, `imagen`, `archivos` FROM `' . $table . '` WHERE `id_ticket` IN (' . implode(',', array_fill(0, count($keys), '?')) . ") AND (`cct_status` IS NULL OR `cct_status` <> 'trash') ORDER BY `_ID` DESC LIMIT " . max(1, $limit),
      $keys
    );
    return self::historyFromRows(array_reverse($rows));
  }

  /** @param array<int,array<string,mixed>> $rows En orden cronológico. */
  public static function historyFromRows(array $rows): array
  {
    $out = [];
    foreach ($rows as $row) {
      $text = preg_replace('/<\/(p|div|li|h[1-6])>|<br\s*\/?\s*>/i', "\n", (string) ($row['respuesta'] ?? '')) ?? '';
      $text = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
      $images = [];
      $rawImages = (string) ($row['imagen'] ?? '');
      $list = str_starts_with($rawImages, 'a:') ? @unserialize($rawImages, ['allowed_classes' => false]) : explode(',', $rawImages);
      foreach (is_array($list) ? $list : [] as $url) {
        $url = self::safeAttachment(trim((string) $url), ['jpg', 'jpeg', 'png', 'webp']);
        if ($url !== '') $images[] = $url;
      }
      $documents = [];
      $rawDocuments = is_string($row['archivos'] ?? null) ? @unserialize($row['archivos'], ['allowed_classes' => false]) : [];
      foreach (is_array($rawDocuments) ? $rawDocuments : [] as $document) {
        if (!is_array($document)) continue;
        $url = self::safeAttachment((string) ($document['media_archivo'] ?? $document['archivo'] ?? ''), ['pdf']);
        if ($url !== '') $documents[] = ['url' => $url, 'name' => trim(strip_tags((string) ($document['nombre_archivo'] ?? ''))) ?: 'Documento PDF'];
      }
      if ($text === '' && $images === [] && $documents === []) continue;
      $rawDate = (string) ($row['fecha'] ?? '');
      $date = ctype_digit($rawDate) ? (int) $rawDate : (strtotime((string) ($row['cct_created'] ?? '')) ?: 0);
      $author = trim(strip_tags((string) ($row['nombre'] ?? '')));
      $out[] = [
        'author' => $author !== '' ? $author : 'SKC SuCasa Inmobiliaria',
        'date' => $date > 0 ? date('d/m/Y h:i a', $date) : '',
        'text' => $text,
        // Respuestas de actores desde la vista pública: "Nombre (Propietario)".
        'external' => (bool) preg_match('/\((Propietario|Arrendatario|Copropiedad|Solicitante)\)$/u', $author),
        'images' => array_slice(array_values(array_unique($images)), 0, 10),
        'documents' => array_slice($documents, 0, 10),
      ];
    }
    return $out;
  }

  private static function safeAttachment(string $url, array $extensions): string
  {
    $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) return '';
    // New panel uploads are already signed by StoredFileService. Never sign arbitrary stored URLs here.
    $base = rtrim((string) SCM_BASE_URL, '/');
    if (str_starts_with($url, $base . '/file.php?')) {
      parse_str($parts['query'] ?? '', $query);
      if (!is_string($query['n'] ?? null) || !is_string($query['s'] ?? null)) return '';
      if (!in_array(strtolower(pathinfo($query['n'], PATHINFO_EXTENSION)), $extensions, true)) return '';
      return StoredFileService::fromRuntime()->isValidSignature($query['n'], $query['s']) ? $url : '';
    }
    // Legacy WordPress media is public already; allow only corporate uploads, never arbitrary origins.
    $host = strtolower((string) ($parts['host'] ?? ''));
    return $parts['scheme'] === 'https' && in_array($host, ['sucasainmobiliaria.com.co', 'www.sucasainmobiliaria.com.co'], true)
      && str_starts_with($parts['path'] ?? '', '/wp-content/uploads/')
      && in_array(strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION)), $extensions, true) ? $url : '';
  }
}
