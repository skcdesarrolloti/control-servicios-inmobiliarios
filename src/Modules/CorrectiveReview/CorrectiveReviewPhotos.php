<?php

declare(strict_types=1);

namespace SCM\Modules\CorrectiveReview;

use SCM\Core\Database;
use SCM\Support\StoredFileService;

final class CorrectiveReviewPhotos
{
  /** @return array<int,string> */
  public static function refs(mixed $value): array
  {
    $refs = [];
    $collect = static function (mixed $entry) use (&$collect, &$refs): void {
      if (is_array($entry)) {
        foreach ($entry as $key => $child) {
          if (is_int($key) || in_array($key, ['url', 'src', 'value', 'id', 'ID', 'attachment_id', 'media_id', 'archivo', 'media_archivo', 'imagen', 'imagenes', 'evidencia', 'registro_foto_dano'], true)) {
            $collect($child);
          }
        }
        return;
      }
      if (!is_scalar($entry)) { return; }
      $raw = html_entity_decode(trim((string) $entry), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($raw === '') { return; }
      $decoded = @unserialize($raw, ['allowed_classes' => false]);
      if (is_array($decoded)) { $collect($decoded); return; }
      $json = json_decode($raw, true);
      if (is_array($json)) { $collect($json); return; }
      foreach (preg_split('/[,\r\n]+/', $raw) ?: [] as $part) {
        $ref = trim(strip_tags($part));
        if ($ref === '' || strlen($ref) > 2048 || preg_match('/[\x00<>"\']/', $ref)) { continue; }
        if (ctype_digit($ref) || preg_match('#^https?://#i', $ref) || str_starts_with($ref, '/') || str_starts_with($ref, 'file.php?') || preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', $ref)) {
          $refs[$ref] = $ref;
        }
      }
    };
    $collect($value);
    return array_values($refs);
  }

  public static function url(Database $db, string $ref): string
  {
    $ref = trim($ref);
    if ($ref === '') { return ''; }
    if (ctype_digit($ref)) {
      $posts = $db->table('posts');
      try {
        $row = $db->getRow("SELECT `guid`, `post_mime_type` FROM `{$posts}` WHERE `ID` = ? AND `post_type` = 'attachment' LIMIT 1", [(int) $ref]);
      } catch (\Throwable) {
        return '';
      }
      $url = trim((string) ($row['guid'] ?? ''));
      $image = str_starts_with((string) ($row['post_mime_type'] ?? ''), 'image/') || preg_match('/\.(?:jpe?g|png|webp|gif)(?:\?|$)/i', $url);
      return $image && preg_match('#^https?://#i', $url) ? $url : '';
    }
    $path = (string) parse_url($ref, PHP_URL_PATH);
    $query = [];
    parse_str((string) parse_url($ref, PHP_URL_QUERY), $query);
    $name = basename((string) ($query['n'] ?? $path));
    if (preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', $name) && (str_contains($ref, 'file.php?') || $ref === $name)) {
      $files = StoredFileService::fromRuntime();
      if ($files->pathFor($name) !== null) { return $files->urlFor($name); }
      // A retained database reference does not mean the file still exists.
      return '';
    }
    if (preg_match('#^https?://#i', $ref)) { return $ref; }
    if (str_starts_with($ref, 'file.php?')) { return rtrim((string) SCM_BASE_URL, '/') . '/' . $ref; }
    return str_starts_with($ref, '/') ? $ref : '';
  }
}
