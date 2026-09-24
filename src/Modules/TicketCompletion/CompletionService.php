<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\EmailTemplate;
use SCM\Support\StoredFileService;

final class CompletionService
{
  private \Closure $enqueue;

  public function __construct(public readonly CompletionRepository $repo, private string $secret, private string $baseUrl, ?\Closure $enqueue = null)
  {
    if (strlen($secret) < 32) {
      throw new \RuntimeException('No hay una clave segura para las actas.');
    }
    $this->enqueue = $enqueue ?? static function (string $to, string $subject, string $html, array $options) use ($repo): int {
      return CompletionDelivery::enqueue($repo->db, $to, $subject, $html, $options);
    };
  }

  public function context(int $ticketId, array $sourceFlow = []): array
  {
    $this->repo->requireSchema();
    $ticket = $this->repo->ticket($ticketId);
    $contacts = [];
    foreach (CompletionPolicy::ROLES as $role => $label) {
      $contacts[$role] = [
        'name' => trim((string) ($ticket[$role] ?? '')),
        'email' => trim((string) ($ticket['correo_' . $role] ?? '')),
        'phone' => trim((string) ($ticket['celular_' . $role] ?? '')),
        'label' => $label,
      ];
      if ($role === 'copropiedad' && (int) ($ticket['id_copropiedad'] ?? 0) > 0) {
        $table = $this->repo->db->table('jet_cct_copropiedades');
        if ($this->repo->schema->tableExists($table)) {
          $community = $this->repo->db->getRow("SELECT * FROM `{$table}` WHERE _ID = ?", [(int) $ticket['id_copropiedad']]);
          if ($community) {
            $contacts[$role]['name'] = trim((string) ($community['administrador'] ?? '')) ?: $contacts[$role]['name'];
            $contacts[$role]['email'] = $contacts[$role]['email'] ?: trim((string) ($community['correo'] ?? ''));
            $contacts[$role]['phone'] = $contacts[$role]['phone'] ?: trim((string) ($community['contacto'] ?? ''));
          }
        }
      }
      $contacts[$role]['phone'] = CompletionPolicy::phone($contacts[$role]['phone'], (string) ($ticket['indicativo_' . $role] ?? ''));
      $contacts[$role]['available'] = $contacts[$role]['name'] !== '' && $this->verificationChannels($contacts[$role]) !== [];
    }
    $config = [];
    $table = $this->repo->db->table('jet_cct_confi_sistema');
    if ($this->repo->schema->tableExists($table)) {
      foreach ($this->repo->db->getResults("SELECT funcion, valor FROM `{$table}` WHERE funcion IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv','valor_transporte') ORDER BY _ID") as $row) {
        $config[(string) $row['funcion']] = $row['valor'];
      }
    }
    return [
      'ticket' => $ticket,
      'contacts' => $contacts,
      'fee' => CompletionPolicy::fee($config),
      'fee_config' => $config,
      'transport_base' => CompletionPolicy::transportBase($config),
      'transport_max' => CompletionPolicy::transportMaximum($config),
      'acts' => $this->repo->history($ticketId),
      'branch_contact' => $this->branchContact($ticket),
      'source_flow' => $sourceFlow,
      'suggested_items' => $this->suggestedItemsForTicket($ticket),
    ];
  }

  /** @return array<int,array{damage:string,solution:string,damage_photos?:array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}>}> */
  private function suggestedItemsForTicket(array $ticket): array
  {
    $items = $this->suggestedItemsFromCorrectiveReview((string) ($ticket['id_revision_correctiva'] ?? ''));
    if ($items !== []) {
      return $items;
    }

    $items = $this->suggestedItemsFromPreventiveReview((string) ($ticket['id_revision_preventiva'] ?? ''), $ticket);
    if ($items !== []) {
      return $items;
    }

    return [];
  }

  /** @return array<int,array{damage:string,solution:string,damage_photos?:array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}>}> */
  private function suggestedItemsFromCorrectiveReview(string $rawIds): array
  {
    $rows = $this->linkedRowsByIds('jet_cct_revision_correctiva', $rawIds);
    $items = [];
    foreach ($rows as $row) {
      $stored = $this->decodeStoredItems($row['evaluacion_de_danos'] ?? null);
      foreach ($stored as $item) {
        if (!is_array($item)) {
          continue;
        }
        $damage = $this->correctiveDamageText($item);
        if ($damage !== '') {
          $items[] = [
            'damage' => $damage,
            'solution' => '',
            'damage_photos' => $this->actaPhotoDescriptorsFromRefs($this->storedImageRefsFromValue($item['registro_foto_dano'] ?? '')),
          ];
        }
      }
      if ($stored === []) {
        $damage = $this->linkedRecordDamageText($row, [
          'descripcion_dano',
          'descripcion_daño',
          'dano_encontrado',
          'daño_encontrado',
          'descripcion',
          'area_afectada',
          'observacion',
          'observaciones',
        ]);
        if ($damage !== '') {
          $items[] = [
            'damage' => $damage,
            'solution' => '',
            'damage_photos' => $this->actaPhotoDescriptorsFromRefs($this->rowEvidenceRefs($row)),
          ];
        }
      }
    }

    return $this->uniqueSuggestedItems($items);
  }

  /** @return array<int,array{damage:string,solution:string,damage_photos?:array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}>}> */
  private function suggestedItemsFromPreventiveReview(string $rawIds, array $ticket): array
  {
    $rows = $this->linkedRowsByIds('jet_cct_revision_preventiva', $rawIds);
    $items = [];
    foreach ($rows as $row) {
      $damageFlag = $this->firstTextFromRow($row, ['encontro_danos', 'tiene_cotizacion', 'se_encontraron_danos']);
      if ($damageFlag !== '' && !$this->isPositiveDamageFlag($damageFlag)) {
        continue;
      }
      $damage = $this->linkedRecordDamageText($row, [
        'descripcion_dano',
        'descripcion_daño',
        'dano_encontrado',
        'daño_encontrado',
        'danos_encontrados',
        'daños_encontrados',
        'hallazgos',
        'novedad',
        'observacion',
        'observaciones',
        'descripcion',
        'area_afectada',
      ]);
      if ($damage !== '') {
        $items[] = [
          'damage' => $damage,
          'solution' => '',
          'damage_photos' => $this->actaPhotoDescriptorsFromRefs($this->rowEvidenceRefs($row)),
        ];
      }
    }

    if ($items === [] && $this->isPositiveDamageFlag((string) ($ticket['se_encontraron_danos'] ?? $ticket['_scm_prev_encontro_danos'] ?? ''))) {
      $damage = $this->linkedRecordDamageText($ticket, [
        'descripcion',
        'asunto',
        'area_afectada',
        'observacion',
        'observaciones',
      ]);
      if ($damage !== '') {
        $items[] = [
          'damage' => $damage,
          'solution' => '',
          'damage_photos' => $this->actaPhotoDescriptorsFromRefs($this->rowEvidenceRefs($ticket)),
        ];
      }
    }

    return $this->uniqueSuggestedItems($items);
  }

  /** @param array<string,mixed> $row @return array<int,string> */
  private function rowEvidenceRefs(array $row): array
  {
    $refs = [];
    foreach (['registro_foto_dano', 'registro_foto_daño', 'evidencia', 'evidencias', 'imagen', 'imagenes', 'foto', 'fotos'] as $column) {
      foreach ($this->storedImageRefsFromValue($row[$column] ?? '') as $ref) {
        $refs[$ref] = $ref;
      }
    }
    return array_values($refs);
  }

  /** @return array<int,string> */
  private function storedImageRefsFromValue(mixed $value): array
  {
    $refs = [];
    $collect = function (mixed $input) use (&$collect, &$refs): void {
      if (is_array($input)) {
        foreach ($input as $key => $nested) {
          if (is_string($key) && in_array($key, ['url', 'archivo', 'media_archivo', 'imagen', 'imagenes', 'evidencia', 'registro_foto_dano', 'registro_foto_daño'], true)) {
            $collect($nested);
            continue;
          }
          if (is_array($nested) || is_scalar($nested)) {
            $collect($nested);
          }
        }
        return;
      }
      $text = html_entity_decode(trim((string) $input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($text === '') {
        return;
      }
      $decoded = @unserialize($text, ['allowed_classes' => false]);
      if (is_array($decoded)) {
        $collect($decoded);
        return;
      }
      $json = json_decode($text, true);
      if (is_array($json)) {
        $collect($json);
        return;
      }
      foreach (preg_split('/[\s,]+/', $text) ?: [] as $part) {
        $ref = trim(strip_tags((string) $part));
        if ($ref === '' || strlen($ref) > 2048 || preg_match('/[\x00<>"\']/', $ref)) {
          continue;
        }
        if (ctype_digit($ref) || preg_match('/file\.php\?/i', $ref) || preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', basename($ref))) {
          $refs[$ref] = $ref;
        }
      }
    };
    $collect($value);
    return array_values($refs);
  }

  /** @param array<int,string> $refs @return array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}> */
  private function actaPhotoDescriptorsFromRefs(array $refs): array
  {
    if ($refs === []) {
      return [];
    }
    $storedFiles = StoredFileService::fromRuntime();
    $photos = [];
    $attachmentIds = [];
    foreach ($refs as $ref) {
      $ref = trim((string) $ref);
      if (ctype_digit($ref)) {
        $attachmentIds[(int) $ref] = (int) $ref;
        continue;
      }
      $name = $this->storedImageNameFromRef((string) $ref);
      if ($name === '' || isset($photos[$name])) {
        continue;
      }
      $path = $storedFiles->pathFor($name);
      if ($path === null) {
        continue;
      }
      $info = @getimagesize($path);
      $hash = @hash_file('sha256', $path);
      $bytes = @filesize($path);
      if (!is_array($info) || !is_string($hash) || !is_int($bytes) || ($info['mime'] ?? '') !== 'image/jpeg') {
        continue;
      }
      if ((int) $info[0] < 1 || (int) $info[0] > 1600 || (int) $info[1] < 1 || (int) $info[1] > 1600 || $bytes < 100 || $bytes > 1500000) {
        continue;
      }
      $photos[$name] = [
        'name' => $name,
        'mime' => 'image/jpeg',
        'width' => (int) $info[0],
        'height' => (int) $info[1],
        'bytes' => $bytes,
        'sha256' => $hash,
      ];
      if (count($photos) >= 4) {
        break;
      }
    }
    if (count($photos) < 4 && $attachmentIds !== []) {
      foreach ($this->attachmentPhotoDescriptorsFromIds(array_values($attachmentIds), 4 - count($photos)) as $photo) {
        $photos[$photo['name']] = $photo;
        if (count($photos) >= 4) {
          break;
        }
      }
    }
    return array_values($photos);
  }

  /** @param int[] $ids @return array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}> */
  private function attachmentPhotoDescriptorsFromIds(array $ids, int $limit): array
  {
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0));
    if ($ids === [] || $limit < 1) {
      return [];
    }
    $posts = $this->repo->db->table('posts');
    $postmeta = $this->repo->db->table('postmeta');
    if (!$this->repo->schema->tableExists($posts) || !$this->repo->schema->tableExists($postmeta)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->repo->db->getResults(
      "SELECT p.ID, p.guid, p.post_mime_type, pm.meta_value AS attached_file
       FROM `{$posts}` p
       LEFT JOIN `{$postmeta}` pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
       WHERE p.ID IN ({$placeholders})",
      $ids
    );
    $byId = [];
    foreach ($rows as $row) {
      $byId[(int) ($row['ID'] ?? 0)] = $row;
    }
    $storedFiles = StoredFileService::fromRuntime();
    $photos = [];
    foreach ($ids as $id) {
      $path = isset($byId[$id]) ? $this->wordpressAttachmentPath($byId[$id]) : null;
      if ($path === null) {
        continue;
      }
      $stored = $storedFiles->storeExistingImagePath($path);
      if ($stored === null || ($stored['mime'] ?? '') !== 'image/jpeg') {
        continue;
      }
      if ((int) $stored['width'] < 1 || (int) $stored['width'] > 1600 || (int) $stored['height'] < 1 || (int) $stored['height'] > 1600 || (int) $stored['bytes'] < 100 || (int) $stored['bytes'] > 1500000) {
        continue;
      }
      $photos[] = [
        'name' => (string) $stored['name'],
        'mime' => 'image/jpeg',
        'width' => (int) $stored['width'],
        'height' => (int) $stored['height'],
        'bytes' => (int) $stored['bytes'],
        'sha256' => (string) $stored['sha256'],
      ];
      if (count($photos) >= $limit) {
        break;
      }
    }
    return $photos;
  }

  /** @param array<string,mixed> $row */
  private function wordpressAttachmentPath(array $row): ?string
  {
    $candidates = [];
    $attachedFile = str_replace('\\', '/', trim((string) ($row['attached_file'] ?? '')));
    if ($attachedFile !== '') {
      if (preg_match('/^(?:[A-Za-z]:)?\//', $attachedFile)) {
        $candidates[] = $attachedFile;
      }
      $relative = ltrim($attachedFile, '/');
      $this->appendWordPressUploadCandidates($candidates, $relative);
    }
    $urlPath = (string) parse_url(trim((string) ($row['guid'] ?? '')), PHP_URL_PATH);
    if ($urlPath !== '') {
      $normalizedPath = str_replace('\\', '/', $urlPath);
      if (preg_match('~/wp-content/uploads/(.+)$~i', $normalizedPath, $matches)) {
        $this->appendWordPressUploadCandidates($candidates, (string) $matches[1]);
      }
      if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') . '/' . ltrim($normalizedPath, '/');
      }
    }
    foreach (array_values(array_unique($candidates)) as $candidate) {
      $path = realpath($candidate);
      if (is_string($path) && is_file($path) && is_readable($path)) {
        return $path;
      }
    }
    return null;
  }

  /** @param array<int,string> $candidates */
  private function appendWordPressUploadCandidates(array &$candidates, string $relative): void
  {
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') {
      return;
    }
    if (defined('WP_CONTENT_DIR')) {
      $candidates[] = rtrim((string) WP_CONTENT_DIR, '/\\') . '/uploads/' . $relative;
    }
    if (defined('ABSPATH')) {
      $candidates[] = rtrim((string) ABSPATH, '/\\') . '/wp-content/uploads/' . $relative;
    }
    if (defined('SCM_ROOT')) {
      $candidates[] = rtrim(dirname((string) SCM_ROOT), '/\\') . '/wp-content/uploads/' . $relative;
      $candidates[] = rtrim((string) SCM_ROOT, '/\\') . '/../wp-content/uploads/' . $relative;
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-content/uploads/' . $relative;
    }
  }

  private function storedImageNameFromRef(string $ref): string
  {
    $ref = html_entity_decode(trim($ref), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($ref === '') {
      return '';
    }
    $basename = basename((string) parse_url($ref, PHP_URL_PATH));
    if (preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', $basename)) {
      return $basename;
    }
    $query = (string) parse_url($ref, PHP_URL_QUERY);
    if ($query !== '') {
      parse_str($query, $params);
      $name = basename((string) ($params['n'] ?? ''));
      if (preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', $name)) {
        return $name;
      }
    }
    if (preg_match('/[?&]n=([^&]+)/', $ref, $matches)) {
      $name = basename(rawurldecode((string) ($matches[1] ?? '')));
      if (preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', $name)) {
        return $name;
      }
    }
    return '';
  }

  /** @return array<int,array<string,mixed>> */
  private function linkedRowsByIds(string $tableName, string $rawIds): array
  {
    $ids = $this->linkedNumericIds($rawIds);
    if ($ids === []) {
      return [];
    }
    $table = $this->repo->db->table($tableName);
    if (!$this->repo->schema->tableExists($table) || !$this->repo->schema->columnExists($table, '_ID')) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return $this->repo->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN ({$placeholders}) ORDER BY `_ID` DESC", $ids);
  }

  /** @return int[] */
  private function linkedNumericIds(string $raw): array
  {
    preg_match_all('/\d+/', $raw, $matches);
    $ids = [];
    foreach ($matches[0] ?? [] as $id) {
      $num = (int) $id;
      if ($num > 0) {
        $ids[$num] = $num;
      }
    }
    return array_values($ids);
  }

  /** @return array<int,mixed> */
  private function decodeStoredItems(mixed $raw): array
  {
    if (is_array($raw)) {
      return array_values($raw);
    }
    $text = trim((string) $raw);
    if ($text === '') {
      return [];
    }
    $decoded = @unserialize($text, ['allowed_classes' => false]);
    if (is_array($decoded)) {
      return array_values($decoded);
    }
    $json = json_decode($text, true);
    return is_array($json) ? array_values($json) : [];
  }

  private function correctiveDamageText(array $item): string
  {
    $area = $this->firstTextFromRow($item, ['area_afectada', 'area_afectada_1', 'area_afectada_2', 'area_afectada_3', 'area_afectada_4']);
    $parts = [
      'Área afectada' => $area,
      'Descripción del daño' => $this->firstTextFromRow($item, ['descripcion_dano', 'descripcion_daño', 'descripcion']),
      'Consecuencia' => $this->firstTextFromRow($item, ['consecuencia']),
      'Nivel del daño' => $this->firstTextFromRow($item, ['nivel_dano', 'nivel_daño']),
      'Tiempo de atención' => $this->firstTextFromRow($item, ['tiempo_atencion']),
    ];
    return $this->labelledText($parts);
  }

  /** @param string[] $columns */
  private function linkedRecordDamageText(array $row, array $columns): string
  {
    $parts = [];
    foreach ($columns as $column) {
      $value = $this->cleanSuggestionText($row[$column] ?? '');
      if ($value === '') {
        continue;
      }
      $label = ucfirst(str_replace('_', ' ', str_replace(['dano', 'danos'], ['daño', 'daños'], $column)));
      $parts[$label] = $value;
    }
    return $this->labelledText($parts);
  }

  /** @param string[] $columns */
  private function firstTextFromRow(array $row, array $columns): string
  {
    foreach ($columns as $column) {
      $value = $this->cleanSuggestionText($row[$column] ?? '');
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @param array<string,string> $parts */
  private function labelledText(array $parts): string
  {
    $lines = [];
    foreach ($parts as $label => $value) {
      $value = $this->cleanSuggestionText($value);
      if ($value !== '') {
        $lines[] = $label . ': ' . $value;
      }
    }
    return implode("\n", array_values(array_unique($lines)));
  }

  private function cleanSuggestionText(mixed $value): string
  {
    if (is_array($value) || is_object($value)) {
      return '';
    }
    $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\R\s*/u', "\n", $text) ?? '';
    return trim($text);
  }

  private function isPositiveDamageFlag(string $value): bool
  {
    $value = strtolower(strtr(trim($value), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));
    return in_array($value, ['si', '1', 'true', 'yes', 'con dano', 'con danos', 'con daño', 'con daños'], true);
  }

  /** @param array<int,array{damage:string,solution:string,damage_photos?:array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}>}> $items @return array<int,array{damage:string,solution:string,damage_photos?:array<int,array{name:string,mime:string,width:int,height:int,bytes:int,sha256:string}>}> */
  private function uniqueSuggestedItems(array $items): array
  {
    $out = [];
    $seen = [];
    $photoCount = 0;
    foreach ($items as $item) {
      $damage = $this->cleanSuggestionText($item['damage'] ?? '');
      if ($damage === '') {
        continue;
      }
      $key = md5(mb_strtolower($damage, 'UTF-8'));
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $photos = [];
      foreach ((array) ($item['damage_photos'] ?? []) as $photo) {
        if (!is_array($photo) || $photoCount >= 12 || count($photos) >= 4) {
          continue;
        }
        $photos[] = $photo;
        $photoCount++;
      }
      $suggestion = ['damage' => $damage, 'solution' => ''];
      if ($photos !== []) {
        $suggestion['damage_photos'] = $photos;
      }
      $out[] = $suggestion;
      if (count($out) >= 30) {
        break;
      }
    }
    return $out;
  }

  public function payload(array $act): array
  {
    if (!hash_equals((string) $act['payload_hash'], hash('sha256', (string) $act['payload_json']))) {
      throw new \DomainException('El contenido del acta cambió. No se puede firmar; solicita una nueva acta.');
    }
    if (($act['status'] ?? '') === 'signed') {
      $evidence = json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR);
      $hmac = (string) ($evidence['evidence_hmac'] ?? '');
      unset($evidence['evidence_hmac']);
      if (!hash_equals(hash_hmac('sha256', json_encode($evidence, JSON_THROW_ON_ERROR), $this->secret), $hmac)
        || !hash_equals((string) $act['payload_hash'], (string) ($evidence['document_hash'] ?? ''))
        || (int) ($evidence['signed_at'] ?? 0) !== (int) $act['signed_at']) {
        throw new \DomainException('La evidencia de firma no coincide con el acta. Solicita una revisión al administrador.');
      }
    }
    return json_decode((string) $act['payload_json'], true, 32, JSON_THROW_ON_ERROR);
  }

  public function viewUrl(int $id): string
  {
    return rtrim($this->baseUrl, '/') . '/ticket-acta.php?id=' . $id;
  }

  public function dashboardUrlForTicket(array $ticket, string $status = 'pending'): string
  {
    $case = trim((string) ($ticket['id_ticket'] ?? '')) ?: trim((string) ($ticket['_ID'] ?? ''));
    $query = [
      'tab' => 'actas_satisfaccion',
      'sacta_estado' => $status,
    ];
    if ($case !== '') {
      $query['sacta_caso'] = $case;
    }
    return rtrim($this->baseUrl, '/') . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  public function editUrl(array $act): string
  {
    return rtrim($this->baseUrl, '/') . '/crear-acta.php?' . http_build_query([
      'ticket_pk' => (int) $act['ticket_pk'],
      'act_id' => (int) $act['id'],
    ], '', '&', PHP_QUERY_RFC3986);
  }

  public function token(array $act): string
  {
    return hash_hmac('sha256', 'ticket-completion|' . $act['id'] . '|' . $act['token_nonce'], $this->secret);
  }

  public function publicAct(int $id, string $token): array
  {
    $this->repo->requireSchema();
    $act = $this->repo->act($id);
    $this->assertPublicAccess($act, $token);
    $this->payload($act);
    return $act;
  }

  private function assertPublicAccess(array $act, string $token): void
  {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($this->token($act), $token)
      || (int) $act['expires_at'] < time() || !in_array($act['status'], ['pending', 'signed'], true)) {
      throw new \DomainException('El enlace no es válido, fue anulado o venció. Solicita un nuevo enlace a la inmobiliaria.');
    }
  }

  public function create(int $ticketId, array $input, array $actor): array
  {
    $this->repo->requireSchema();
    $act = $this->repo->transaction($ticketId, function (array $ticket) use ($ticketId, $input, $actor): array {
      if ($this->repo->active($ticketId)) {
        throw new \DomainException('Este ticket ya tiene un acta activa. Consúltala o anúlala antes de generar otra.');
      }
      if (in_array(mb_strtolower(trim((string) $ticket['estado'])), ['cerrado', 'finalizado', 'resuelto'], true)) {
        throw new \DomainException('No se puede crear un acta en un caso cerrado.');
      }
      $now = time();
      $context = $this->context($ticketId);
      $payload = $this->payloadFromInput($ticketId, $ticket, $context, $input, $actor, null, $now);
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $this->repo->db->insert($this->repo->table(), [
        'ticket_pk' => $ticketId, 'payload_json' => $json,
        'payload_hash' => hash('sha256', $json), 'token_nonce' => bin2hex(random_bytes(32)),
        'created_at' => $now, 'expires_at' => $now + 30 * 86400,
      ]);
      $id = (int) $this->repo->db->lastInsertId();
      // Legacy timelines treat any act row as completion; publish the CCT record only on signature.
      $this->repo->updateTicket($ticketId, ['estado' => 'En proceso', 'estado_administrativo' => $payload['pending_admin_state']]);
      $sourceText = (($payload['source']['flow'] ?? '') === 'approved_quote' && trim((string) ($payload['source']['quote_id'] ?? '')) !== '')
        ? ' Asociada a la cotización aprobada #' . trim((string) $payload['source']['quote_id']) . '.'
        : '';
      $this->repo->audit($ticketId, 'Acta de satisfacción #' . $id . ' generada.' . $sourceText . ' Solución por ' . CompletionPolicy::EXECUTORS[$payload['executor']] . '. Pendiente de firma de ' . htmlspecialchars($payload['signer']['name'], ENT_QUOTES, 'UTF-8') . '. <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta</a>. El caso permanece abierto en “' . $payload['pending_admin_state'] . '” y el reporte aún no se cobra.', $actor['name'], $actor['employee_id']);
      return $this->repo->act($id);
    });
    try { return $this->notify($act); }
    catch (\Throwable) { return ['act_id' => (int) $act['id'], 'queued' => false, 'message' => 'Acta guardada. No se pudo confirmar el envío; recarga y usa Reenviar invitación. El ticket sigue abierto.']; }
  }

  public function update(int $id, int $ticketId, array $input, array $actor): array
  {
    $this->repo->requireSchema();
    $act = $this->repo->transaction($ticketId, function (array $ticket) use ($id, $ticketId, $input, $actor): array {
      $active = $this->repo->active($ticketId);
      if (!$active || (int) $active['id'] !== $id || $active['status'] !== 'pending') {
        throw new \DomainException('Solo se puede editar el acta pendiente activa de este caso.');
      }
      if (in_array(mb_strtolower(trim((string) $ticket['estado'])), ['cerrado', 'finalizado', 'resuelto'], true)) {
        throw new \DomainException('No se puede editar un acta en un caso cerrado.');
      }
      $oldPayload = $this->payload($active);
      $now = time();
      $payload = $this->payloadFromInput(
        $ticketId,
        $ticket,
        $this->context($ticketId),
        $input,
        $actor,
        is_array($oldPayload['previous'] ?? null) ? $oldPayload['previous'] : null,
        (int) ($oldPayload['created_at'] ?? $now)
      );
      $payload['updated_at'] = $now;
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $this->repo->db->update($this->repo->table(), [
        'payload_json' => $json,
        'payload_hash' => hash('sha256', $json),
        'token_nonce' => bin2hex(random_bytes(32)),
        'expires_at' => $now + 30 * 86400,
        'otp_json' => null,
        'delivery_json' => null,
        'invitation_queued_at' => null,
      ], ['id' => $id]);
      $this->repo->updateTicket($ticketId, ['estado' => 'En proceso', 'estado_administrativo' => $payload['pending_admin_state']]);
      $this->repo->audit($ticketId, 'Acta de satisfacción #' . $id . ' editada. Se invalidaron códigos anteriores y se solicitó nueva firma de ' . htmlspecialchars($payload['signer']['name'], ENT_QUOTES, 'UTF-8') . '. El caso permanece abierto en “' . $payload['pending_admin_state'] . '”.', $actor['name'], $actor['employee_id']);
      return $this->repo->act($id);
    });
    try { return $this->notify($act, false, true); }
    catch (\Throwable) { return ['act_id' => (int) $act['id'], 'queued' => false, 'message' => 'Acta actualizada. No se pudo confirmar el envío; usa Reenviar invitación desde Actas de satisfacción.']; }
  }

  private function payloadFromInput(int $ticketId, array $ticket, array $context, array $input, array $actor, ?array $previous, int $createdAt): array
  {
    $role = CompletionPolicy::text($input['signer_role'] ?? '', 'quién firma', 25);
    $executor = CompletionPolicy::text($input['executor'] ?? '', 'quién realizó la solución', 25);
    if (!isset(CompletionPolicy::ROLES[$role])) {
      throw new \DomainException('Selecciona propietario, arrendatario o copropiedad.');
    }
    if (!isset(CompletionPolicy::EXECUTORS[$executor])) {
      throw new \DomainException('Selecciona quién realizó la solución.');
    }
    $pendingAdminState = CompletionPolicy::executionState($executor);
    $contact = $context['contacts'][$role];
    if (!$contact['available']) {
      throw new \DomainException('El firmante necesita nombre y un contacto válido para verificación: correo o WhatsApp con plantilla de autenticación configurada.');
    }
    $channels = $input['channels'] ?? ['email'];
    if ($channels === ['both']) { $channels = ['email', 'whatsapp']; }
    if (!is_array($channels) || !$channels || array_diff($channels, ['email', 'whatsapp'])) { throw new \DomainException('Selecciona correo, WhatsApp o ambos.'); }
    $channels = array_values(array_unique($channels));
    foreach ($channels as $channel) {
      if (($channel === 'email' && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) || ($channel === 'whatsapp' && $contact['phone'] === '')) {
        throw new \DomainException('Falta el contacto registrado para el canal seleccionado: ' . $channel . '.');
      }
    }
    $signerName = CompletionPolicy::text($input['signer_name'] ?? '', 'nombre de quien firma', 160);
    $items = CompletionPolicy::items($input['items'] ?? null);
    $observations = CompletionPolicy::text($input['observations'] ?? '', 'observaciones');
    $source = $this->sourceFromInput($input, $ticket, $context);
    if ($source['flow'] === 'approved_quote' && !$this->repo->isApprovedMaintenanceQuoteForTicket($ticket, $source['quote_id'])) {
      throw new \DomainException('Solo se puede crear esta acta desde una cotización aprobada asociada al caso.');
    }
    $hasAdministrativeReport = false;
    $transportMax = $context['transport_max'];
    $transport = $hasAdministrativeReport ? ($transportMax ?? 0) : 0;
    $fee = $hasAdministrativeReport ? $context['fee'] : 0;
    if ($hasAdministrativeReport && $fee === null) {
      $fee = (int) round(CompletionPolicy::number($input['service_fee'] ?? ''));
    }
    if ($hasAdministrativeReport && $fee <= 0) {
      throw new \DomainException('No hay una tarifa de servicio válida. Revisa la configuración o ingresa el valor administrativo.');
    }
    if ($hasAdministrativeReport && $fee + $transport > 999999999) {
      throw new \DomainException('El total administrativo supera el máximo permitido.');
    }
    if (($input['confirm'] ?? '') !== '1') {
      throw new \DomainException('Confirma que revisaste el acta, la solución y el firmante.');
    }
    $propertyMeta = $this->propertyMeta($ticket);
    $branchContact = is_array($context['branch_contact'] ?? null) ? $context['branch_contact'] : [];

    return [
      'version' => 2, 'channels' => $channels, 'ticket_pk' => $ticketId, 'ticket_number' => (string) ($ticket['id_ticket'] ?: $ticketId),
      'property' => (string) ($ticket['inmueble'] ?? ''), 'address' => (string) ($ticket['direccion'] ?? ''),
      'contract' => (string) ($ticket['contrato'] ?? ''), 'executor' => $executor, 'pending_admin_state' => $pendingAdminState,
      'items' => $items, 'observations' => $observations, 'created_at' => $createdAt,
      'signer' => ['role' => $role, 'name' => $signerName, 'contact_name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']],
      'actor' => $actor,
      'source' => $source,
      'property_meta' => $propertyMeta,
      'coordinator' => [
        'name' => trim((string) ($branchContact['name'] ?? '')),
        'email' => trim((string) ($branchContact['email'] ?? '')),
        'phone' => trim((string) ($branchContact['phone'] ?? '')),
      ],
      'owner_id' => (string) ($ticket['id_propietario'] ?? ''), 'tenant_id' => (string) ($ticket['id_arrendatario'] ?? ''),
      'previous' => $previous ?: ['estado_administrativo' => (string) ($ticket['estado_administrativo'] ?? ''), 'id_acta_satisfaccion' => (string) ($ticket['id_acta_satisfaccion'] ?? ''), 'estado_acta_satisfaccion' => (string) ($ticket['estado_acta_satisfaccion'] ?? 'No')],
      'report' => [
        'service_fee' => $fee, 'transport' => $transport, 'total' => $fee + $transport,
        'transport_base' => $context['transport_base'], 'transport_max' => $transportMax,
        'applies' => $hasAdministrativeReport,
        'fee_source' => !$hasAdministrativeReport ? 'cotizacion_previa' : ($context['fee'] === null ? 'manual' : 'configuracion'), 'fee_config' => $context['fee_config'],
        'id_inmueble' => (string) ($ticket['id_inmueble'] ?? ''), 'id_contrato' => (string) ($ticket['id_contrato'] ?? ''),
        'sucursal' => (string) ($ticket['sucursal'] ?? '1'), 'arrendatario' => (string) ($ticket['arrendatario'] ?? ''),
        'fecha_ticket' => (string) ($ticket['fecha'] ?? ''),
      ],
    ];
  }

  /** @return array{flow:string,quote_id:string} */
  private function sourceFromInput(array $input, array $ticket, array $context): array
  {
    $configured = is_array($context['source_flow'] ?? null) ? $context['source_flow'] : [];
    $flow = trim((string) ($input['source_flow'] ?? $configured['flow'] ?? 'ticket_solution'));
    $quoteId = trim((string) ($input['source_cotizacion_id'] ?? $input['id_cotizacion'] ?? $configured['quote_id'] ?? ''));
    $flow = in_array($flow, ['approved_quote', 'ticket_solution'], true) ? $flow : '';
    if ($flow === '') {
      $flow = 'ticket_solution';
    }
    if ($quoteId === '') {
      $quoteId = trim((string) ($ticket['id_cotizacion_mantenimiento'] ?? $ticket['id_cotizacion'] ?? ''));
    }
    return ['flow' => $flow, 'quote_id' => $quoteId];
  }

  /** @return array{tipo_inmueble:string,tipo_negocio:string,destinacion:string} */
  private function propertyMeta(array $ticket): array
  {
    return [
      'tipo_inmueble' => self::firstText($ticket, ['tipo_inmueble', 'tipo_de_inmueble', 'tipo_de_inmueble_texto']),
      'tipo_negocio' => self::firstText($ticket, ['tipo_negocio']),
      'destinacion' => self::firstText($ticket, ['destinacion']),
    ];
  }

  /** @return array{name:string,email:string,phone:string} */
  private function branchContact(array $ticket): array
  {
    $out = ['name' => '', 'email' => '', 'phone' => ''];
    $branchId = trim((string) ($ticket['sucursal'] ?? $ticket['id_sucursal'] ?? ''));
    if ($branchId === '') {
      return $out;
    }
    $table = $this->repo->db->table('jet_cct_sucursales');
    if (!$this->repo->schema->tableExists($table)) {
      return $out;
    }
    $row = $this->repo->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$branchId]);
    if (!is_array($row)) {
      return $out;
    }
    return [
      'name' => self::firstText($row, ['nombre_contractual', 'coordinador', 'nombre', 'nombre_sucursal']),
      'email' => self::firstText($row, ['correo_contractual', 'email_coordinador', 'correo', 'email']),
      'phone' => self::firstText($row, ['celular_contractual', 'celular_coordinador', 'celular', 'telefono']),
    ];
  }

  /** @param string[] $keys */
  private static function firstText(array $row, array $keys): string
  {
    foreach ($keys as $key) {
      $value = trim((string) ($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  public function resend(int $id): array
  {
    $act = $this->repo->act($id);
    $act = $this->repo->transaction((int) $act['ticket_pk'], function () use ($id): array {
      $act = $this->repo->act($id);
      if (!in_array($act['status'], ['pending', 'signed'], true)) {
        throw new \DomainException('No se puede reenviar un acta anulada.');
      }
      if ((int) $act['invitation_queued_at'] > time() - 60) {
        throw new \DomainException('Espera un minuto antes de reenviar el acta.');
      }
      // Rotate only expired links, so a delayed email or a retry does not invalidate a valid invitation.
      if ((int) $act['expires_at'] < time()) {
        $this->repo->db->update($this->repo->table(), ['token_nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 30 * 86400, 'invitation_queued_at' => null, 'otp_json' => null], ['id' => $id]);
      }
      return $this->repo->act($id);
    });
    return $this->notify($act, $act['status'] === 'signed', true);
  }

  private function notify(array $act, bool $receipt = false, bool $resend = false): array
  {
    return CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($act, $receipt, $resend): array {
    $act = $this->repo->act((int) $act['id']);
    if ($act['status'] !== ($receipt ? 'signed' : 'pending')) { return ['queued' => false, 'message' => 'El estado del acta cambió. Recarga el caso.']; }
    $payload = $this->payload($act);
    $url = $this->viewUrl((int) $act['id']) . '&token=' . $this->token($act);
    $title = ($receipt ? 'Acta firmada' : 'Acta de satisfacción') . ' del caso #' . $payload['ticket_number'];
    $description = $receipt ? 'Tu firma quedó registrada y el caso se cerró. Puedes consultar y descargar tu PDF firmado.' : 'Revisa los daños, soluciones, observaciones y evidencias. Solo firma si estás conforme: tu firma cerrará el caso. Necesitarás un código enviado a tu contacto registrado.';
    $linkLabel = $receipt ? 'Descargar PDF firmado' : 'Revisar y firmar acta';
    $target = $receipt ? $url . '&format=pdf' : $url;
    $body = '<p>Hola ' . EmailTemplate::e($payload['signer']['name']) . '.</p><p>' . $description . '</p><p><a href="' . EmailTemplate::e($target) . '">' . $linkLabel . '</a></p><p>Enlace personal válido hasta ' . date('d/m/Y', (int) $act['expires_at']) . '. No lo compartas.</p>';
    $delivery = json_decode((string) ($act['delivery_json'] ?? ''), true) ?: [];
    $event = $receipt ? 'signed_receipt' : 'signature_invitation';
    $channels = $payload['channels'] ?? ['email'];
    $previousComplete = true;
    foreach ($channels as $channel) { $previousComplete = $previousComplete && !empty($delivery[$event][$channel]['queued']); }
    $freshSend = $resend && ($previousComplete || ($delivery[$event]['token_nonce'] ?? '') !== $act['token_nonce']);
    $generation = (int) ($delivery[$event]['generation'] ?? 0) + ($freshSend ? 1 : 0);
    if ($freshSend) { $delivery[$event] = []; }
    $delivery[$event]['generation'] = $generation;
    $delivery[$event]['token_nonce'] = $act['token_nonce'];
    $results = [];
    foreach ($channels as $channel) {
      if (!empty($delivery[$event][$channel]['queued'])) { $results[$channel] = true; continue; }
      $results[$channel] = $this->send($act, $payload, $channel, $event, $generation . ':' . $act['token_nonce'], $title, $body, $description . ' ' . $linkLabel . ': ' . $target, '', $target);
      $delivery[$event][$channel] = ['queued' => $results[$channel], 'attempted_at' => time()];
      // Save each channel independently; a retry cannot duplicate a successful sibling channel.
      $this->repo->db->update($this->repo->table(), ['delivery_json' => json_encode($delivery, JSON_THROW_ON_ERROR)], ['id' => (int) $act['id']]);
    }
    $this->repo->db->update($this->repo->table(), ['invitation_queued_at' => time()], ['id' => (int) $act['id']]);
    $all = !in_array(false, $results, true);
    return ['act_id' => (int) $act['id'], 'queued' => $all, 'channels' => $results, 'message' => ($receipt ? 'Acta firmada y caso cerrado. ' : 'Acta guardada; el caso sigue abierto hasta la firma. ') . ($all ? 'Mensajes en cola (no confirma entrega).' : 'No se pudieron encolar todos los canales. Revisa el detalle y reintenta.')];
    });
  }

  public function verificationChannels(array $signer): array
  {
    $out = [];
    if (filter_var($signer['email'] ?? '', FILTER_VALIDATE_EMAIL)) { $out['email'] = 'Correo: ' . preg_replace('/^(.).+(@.*)$/u', '$1***$2', $signer['email']); }
    if (CompletionDelivery::otpTemplate() !== '' && CompletionPolicy::phone((string) ($signer['phone'] ?? '')) !== '') { $out['whatsapp'] = 'WhatsApp: ***' . substr($signer['phone'], -4); }
    return $out;
  }

  public function requestCode(int $id, string $token, string $channel): array
  {
    $act = $this->publicAct($id, $token);
    return CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($id, $token, $channel): array {
      $act = $this->publicAct($id, $token);
      if ($act['status'] !== 'pending') { throw new \DomainException('Esta acta ya no está pendiente de firma.'); }
      $payload = $this->payload($act);
      if (!isset($this->verificationChannels($payload['signer'])[$channel])) { throw new \DomainException('Canal de verificación no disponible. Usa el correo registrado o contacta a la inmobiliaria.'); }
      return (new CompletionVerification($this->repo, $this->secret))->request($act, $channel, function (string $code, string $nonce) use ($act, $payload, $channel): bool {
        $text = 'Tu código para firmar el acta #' . $act['id'] . ' del caso #' . $payload['ticket_number'] . ' es ' . $code . '. Vence en 10 minutos. No lo compartas. Si no lo solicitaste, ignora este mensaje.';
        return $this->send($act, $payload, $channel, 'signature_otp', $nonce, 'Código de firma del acta #' . $act['id'], '<p>' . EmailTemplate::e($text) . '</p>', $text, $code);
      });
    });
  }

  private function send(array $act, array $payload, string $channel, string $event, string $key, string $subject, string $body, string $text, string $code = '', string $actUrl = ''): bool
  {
    try {
      return ($this->enqueue)($channel === 'email' ? $payload['signer']['email'] : $payload['signer']['phone'], $subject, EmailTemplate::render($subject, $body), [
        'channel' => $channel, 'source_module' => 'ticket-completion', 'provider' => $channel === 'email' ? 'email_smtp' : 'whatsapp_official',
        'destination_name' => $payload['signer']['name'], 'message_text' => $text, 'otp_code' => $code,
        'ticket_number' => $payload['ticket_number'], 'act_url' => $actUrl,
        'priority' => $event === 'signature_otp' ? 200 : 100,
        'dedupe_key' => 'ticket-acta:' . $act['id'] . ':' . $event . ':' . $channel . ':' . $key,
        'meta' => ['ticket_pk' => (int) $act['ticket_pk'], 'act_id' => (int) $act['id'], 'event' => $event],
      ]) > 0;
    } catch (\Throwable) {
      error_log('[ticket-completion] No se pudo encolar ' . $event . ' por ' . $channel . ' del acta #' . $act['id']);
      return false;
    }
  }

  public function cancel(int $id, string $reason, array $actor): void
  {
    $this->retirePending($id, $reason, $actor, 'cancelled', 'anulación', 'anulada');
  }

  public function archive(int $id, string $reason, array $actor): void
  {
    $this->retirePending($id, $reason, $actor, 'archived', 'archivo', 'archivada');
  }

  public function deleteRetired(int $id, array $actor, bool $allowAny = false): void
  {
    $photos = [];
    $act = $this->repo->act($id);
    $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $actor, $allowAny, &$photos): void {
      $act = $this->repo->act($id);
      $status = (string) $act['status'];
      $allowedStatuses = $allowAny ? ['pending', 'signed', 'archived', 'cancelled'] : ['archived', 'cancelled'];
      if (!in_array($status, $allowedStatuses, true)) {
        throw new \DomainException($status === 'pending'
          ? 'Solo un cargo administrativo puede eliminar actas pendientes. También puedes archivarla para sacarla de pendientes sin borrarla.'
          : 'Solo se pueden eliminar actas archivadas o anuladas. Las actas firmadas se conservan como soporte de cierre y cobro.');
      }
      try {
        $payload = $this->payload($act);
        foreach ($payload['items'] ?? [] as $item) {
          foreach (($item['damage_photos'] ?? []) as $photo) {
            if (is_array($photo)) {
              $photos[] = $photo;
            }
          }
          foreach (($item['photos'] ?? []) as $photo) {
            if (is_array($photo)) {
              $photos[] = $photo;
            }
          }
        }
      } catch (\Throwable) {
        $photos = [];
      }
      $legacyId = (int) ($act['legacy_act_id'] ?? 0);
      $reportId = (int) ($act['report_id'] ?? 0);
      if ($legacyId > 0) {
        $this->repo->db->delete($this->repo->db->table('jet_cct_actas_de_satisfaccion'), ['_ID' => $legacyId]);
      }
      if ($reportId > 0) {
        $this->repo->db->delete($this->repo->db->table('jet_cct_reportes_administrativos'), ['_ID' => $reportId]);
      }
      if ($this->repo->db->delete($this->repo->table(), ['id' => $id]) !== 1) {
        throw new \RuntimeException('No se pudo eliminar el acta.');
      }
      if ($status === 'pending' || $status === 'signed') {
        $previousAdmin = trim((string) ($payload['previous']['estado_administrativo'] ?? ''));
        $ticketUpdate = [
          'estado' => 'En proceso',
          'estado_administrativo' => $previousAdmin,
          'id_acta_satisfaccion' => trim((string) ($payload['previous']['id_acta_satisfaccion'] ?? '')),
          'estado_acta_satisfaccion' => trim((string) ($payload['previous']['estado_acta_satisfaccion'] ?? 'No')) ?: 'No',
          'final_trabajo' => '',
        ];
        $this->repo->updateTicket((int) $ticket['_ID'], $ticketUpdate);
        $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' eliminada permanentemente por cargo administrativo. El ticket volvió al estado anterior; se retiraron los soportes internos asociados y no queda cobro de esta acta.', $actor['name'], $actor['employee_id']);
      } else {
        $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' eliminada permanentemente del tablero de actas. No cerró el ticket ni generó cobro.', $actor['name'], $actor['employee_id']);
      }
    });
    if ($photos !== []) {
      \SCM\Support\StoredFileService::fromRuntime()->deleteStoredImages($photos);
    }
  }

  private function retirePending(int $id, string $reason, array $actor, string $status, string $actionName, string $statusLabel): void
  {
    $reason = CompletionPolicy::text($reason, 'motivo de ' . $actionName, 1000);
    $act = $this->repo->act($id);
    $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $reason, $actor, $status, $statusLabel): void {
      $act = $this->repo->act($id);
      if ($act['status'] !== 'pending') {
        throw new \DomainException('Solo se puede archivar o anular un acta pendiente de firma.');
      }
      $payload = $this->payload($act);
      $this->repo->db->update($this->repo->table(), ['status' => $status, 'active_slot' => null, 'cancelled_at' => time(), 'cancellation_reason' => $reason], ['id' => $id]);
      $this->repo->updateTicket((int) $ticket['_ID'], ['estado' => 'En proceso', 'estado_administrativo' => $payload['previous']['estado_administrativo']]);
      $this->repo->audit((int) $ticket['_ID'], 'Acta #' . $id . ' ' . $statusLabel . ' sin cerrar el ticket ni generar cobro. Motivo: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'), $actor['name'], $actor['employee_id']);
    });
  }

  public function sign(int $id, string $token, array $input, string $ip, string $userAgent): array
  {
    $act = $this->publicAct($id, $token);
    $signed = CompletionRepository::locked($this->repo->db, (int) $act['ticket_pk'], function () use ($id, $token, $input, $ip, $userAgent): array {
      $act = $this->publicAct($id, $token);
      if ($act['status'] === 'signed') { return $act; }
      $payload = $this->payload($act);
      CompletionPolicy::signature($input, $payload['signer']['name']);
      if (($input['consent_version'] ?? '') !== '3') { throw new \DomainException('Recarga y acepta la versión actual del formulario de firma.'); }
      $verification = (new CompletionVerification($this->repo, $this->secret))->verify($act, $input['otp_code'] ?? '');
      return $this->repo->transaction((int) $act['ticket_pk'], function (array $ticket) use ($id, $token, $input, $ip, $userAgent, $verification): array {
      $act = $this->repo->act($id);
      $this->assertPublicAccess($act, $token);
      $payload = $this->payload($act);
      if ($act['status'] === 'signed') {
        return $act; // Repeated submits cannot duplicate the report or audit trail.
      }
      // Acts created before execution states were adopted remain signable while
      // their ticket still carries the former waiting value.
      $expectedAdminState = (string) ($payload['pending_admin_state'] ?? CompletionPolicy::WAITING);
      if ($act['status'] !== 'pending' || (int) $act['active_slot'] !== 1 || ($ticket['estado_administrativo'] ?? '') !== $expectedAdminState || strcasecmp((string) $ticket['estado'], 'Cerrado') === 0) {
        throw new \DomainException('El estado del caso cambió. Contacta a la inmobiliaria antes de firmar.');
      }
      if (!hash_equals((string) $act['payload_hash'], (string) ($input['document_hash'] ?? ''))) {
        throw new \DomainException('La versión del acta no coincide. Recarga y revisa nuevamente el documento.');
      }
      $signature = CompletionPolicy::signature($input, $payload['signer']['name']);
      $signature['verification'] = $verification;
      $signature['signed_at'] = time();
      $signature['ip'] = substr($ip, 0, 45);
      $signature['user_agent'] = substr($userAgent, 0, 512);
      $signature['document_hash'] = $act['payload_hash'];
      $signature['token_fingerprint'] = hash('sha256', $token);
      $signature['evidence_hmac'] = hash_hmac('sha256', json_encode($signature, JSON_THROW_ON_ERROR), $this->secret);
      $report = $payload['report'];
      $now = $signature['signed_at'];
      $isApprovedQuoteFlow = ($payload['source']['flow'] ?? '') === 'approved_quote';
      $reportId = null;
      $legacyPhotoUrls = self::legacyPhotoUrls($payload);
      $legacyId = $this->repo->insertLegacy('jet_cct_actas_de_satisfaccion', [
        'cct_status' => 'publish', 'id_ticket' => (int) $act['ticket_pk'], 'fecha' => $payload['created_at'],
        'id_empleado' => $payload['actor']['employee_id'], 'cct_author_id' => $payload['actor']['employee_id'], 'creador' => $payload['actor']['name'],
        'email_creador' => $payload['actor']['email'] ?? '', 'celular_creador' => $payload['actor']['phone'] ?? '',
        'cct_created' => date('Y-m-d H:i:s'), 'direccion' => $payload['address'],
        'id_inmueble' => $report['id_inmueble'], 'inmueble' => $payload['property'],
        'id_contrato' => $report['id_contrato'], 'contrato' => $payload['contract'], 'sucursal' => $report['sucursal'],
        'id_propietario' => $payload['owner_id'], 'id_arrendatario' => $payload['tenant_id'],
        'id_cotizacion' => $payload['source']['quote_id'] ?? '',
        'tipo_inmueble' => $payload['property_meta']['tipo_inmueble'] ?? '',
        'tipo_de_inmueble' => $payload['property_meta']['tipo_inmueble'] ?? '',
        'tipo_negocio' => $payload['property_meta']['tipo_negocio'] ?? '',
        'destinacion' => $payload['property_meta']['destinacion'] ?? '',
        'coordinador' => $payload['coordinator']['name'] ?? '',
        'email_coordinador' => $payload['coordinator']['email'] ?? '',
        'celular_coordinador' => $payload['coordinator']['phone'] ?? '',
        'quien_firma' => CompletionPolicy::ROLES[$payload['signer']['role']], 'destinatario' => $payload['signer']['name'],
        'email_destinatario' => $payload['signer']['email'], 'celular_destinatario' => $payload['signer']['phone'],
        'destinatario_email' => $payload['signer']['email'], 'destinatario_celular' => $payload['signer']['phone'],
        'registro_fotografico' => $legacyPhotoUrls !== [] ? implode("\n", $legacyPhotoUrls) : '',
        'fecha_satisfaccion' => $now, 'cct_modified' => date('Y-m-d H:i:s'),
        'observaciones' => self::legacyText($payload) . "\n\nActa firmada por " . $signature['name'] . ' el ' . date('d/m/Y H:i:s') . '. Registro verificable: ' . $this->viewUrl($id),
      ]);
      $this->repo->db->update($this->repo->table(), [
        'status' => 'signed', 'signed_at' => $now, 'signed_json' => json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'report_id' => $reportId, 'legacy_act_id' => $legacyId, 'otp_json' => null, 'expires_at' => $now + 30 * 86400,
      ], ['id' => $id]);
      $pdf = (new CompletionPdf())->render($this->repo->act($id), $payload);
      $pdfHash = hash('sha256', $pdf);
      $this->repo->db->update($this->repo->table(), ['signed_pdf' => $pdf, 'pdf_hash' => $pdfHash, 'pdf_hmac' => hash_hmac('sha256', $id . '|' . $act['payload_hash'] . '|' . $pdfHash, $this->secret)], ['id' => $id]);
      $quoteIds = $isApprovedQuoteFlow
        ? $this->repo->finalizeApprovedMaintenanceQuotes($ticket, (string) ($payload['source']['quote_id'] ?? ''), $legacyId, $now)
        : $this->repo->disapproveMaintenanceQuotes($ticket, $id, $now);
      $ticketUpdate = [
        'estado' => 'Cerrado', 'estado_administrativo' => 'Finalizado', 'estado_acta_satisfaccion' => 'Si',
        'id_acta_satisfaccion' => $legacyId, 'final_trabajo' => $now,
      ];
      if (!$isApprovedQuoteFlow && $quoteIds !== []) {
        $ticketUpdate['estado_cotizacion_mantenimiento'] = 'Desaprobada';
        $ticketUpdate['estado_respuesta_cotizacion_mantenimiento'] = 'Desaprobada';
        $ticketUpdate['fecha_respuesta_cotizacion_mantenimiento'] = $now;
      }
      $this->repo->updateTicket((int) $act['ticket_pk'], $ticketUpdate);
      $this->insertSignedActPropertyHistory($act, $ticket, $payload, $signature, $reportId, $legacyId, $quoteIds, $isApprovedQuoteFlow, $now);
      $quoteAudit = $quoteIds === []
        ? ''
        : ($isApprovedQuoteFlow
          ? ' Cotizacion(es) de mantenimiento #' . implode(', #', $quoteIds) . ' marcadas como trabajo finalizado con esta acta.'
          : ' Cotizacion(es) de mantenimiento #' . implode(', #', $quoteIds) . ' marcadas como Desaprobada por ejecucion sin aprobacion.');
      $reportAudit = $reportId
        ? ' Reporte administrativo #' . $reportId . ' registrado (no pagado, no exportado).'
        : ' Sin reporte administrativo nuevo; el cobro se gestiona desde la cotización de mantenimiento cuando aplique.';
      $this->repo->audit((int) $act['ticket_pk'], 'Acta #' . $id . ' firmada por ' . htmlspecialchars($signature['name'], ENT_QUOTES, 'UTF-8') . '. Caso cerrado.' . $quoteAudit . $reportAudit . ' <a href="' . htmlspecialchars($this->viewUrl($id), ENT_QUOTES, 'UTF-8') . '">Ver acta firmada</a>.', $payload['actor']['name'], $payload['actor']['employee_id']);
      return $this->repo->act($id);
      });
    });
    // Delivery failure cannot undo a valid signature. Staff can retry the receipt without a second charge.
    try { $signed['receipt'] = $this->notify($signed, true); }
    catch (\Throwable) { $signed['receipt'] = ['queued' => false, 'message' => 'Acta firmada y caso cerrado; no se pudo encolar la copia. Solicita su reenvío a la inmobiliaria.']; }
    return $signed;
  }

  /**
   * @param array<string,mixed> $act
   * @param array<string,mixed> $ticket
   * @param array<string,mixed> $payload
   * @param array<string,mixed> $signature
   * @param string[] $quoteIds
   */
  private function insertSignedActPropertyHistory(array $act, array $ticket, array $payload, array $signature, ?int $reportId, int $legacyId, array $quoteIds, bool $isApprovedQuoteFlow, int $now): void
  {
    $table = $this->repo->db->table('jet_cct_historial_del_inmueble');
    if (!$this->repo->schema->tableExists($table)) {
      return;
    }

    $report = is_array($payload['report'] ?? null) ? $payload['report'] : [];
    $firstNonEmpty = static function (array $values): string {
      foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text !== '') {
          return $text;
        }
      }
      return '';
    };
    $propertyId = $firstNonEmpty([$report['id_inmueble'] ?? '', $payload['property'] ?? '', $ticket['inmueble'] ?? '', $ticket['id_inmueble'] ?? '']);
    $propertyDataId = $firstNonEmpty([$ticket['id_inmueble_data'] ?? '', $report['id_inmueble'] ?? '', $propertyId]);
    if ($propertyId === '' && $propertyDataId === '') {
      return;
    }

    $ticketRef = $firstNonEmpty([$payload['ticket_number'] ?? '', $ticket['id_ticket'] ?? '', $act['ticket_pk'] ?? '']);
    $actorName = trim((string) ($payload['actor']['name'] ?? ''));
    $actorEmployeeId = trim((string) ($payload['actor']['employee_id'] ?? ''));
    $signerName = trim((string) ($signature['name'] ?? $payload['signer']['name'] ?? ''));
    $sourceQuoteId = trim((string) ($payload['source']['quote_id'] ?? ''));
    $historyQuoteIds = $quoteIds !== [] ? $quoteIds : ($sourceQuoteId !== '' ? [$sourceQuoteId] : []);
    $quoteText = $quoteIds !== []
      ? ' Cotización(es) relacionada(s): #' . implode(', #', $quoteIds) . '.'
      : ($sourceQuoteId !== '' ? ' Cotización relacionada: #' . $sourceQuoteId . '.' : '');
    $flowText = $isApprovedQuoteFlow
      ? 'El acta corresponde a la satisfacción del trabajo cotizado y aprobado.'
      : 'El acta corresponde al cierre directo del caso sin cotización aprobada.';
    $detail = 'Acta de satisfacción #' . (int) $act['id'] . ' firmada por ' . ($signerName !== '' ? $signerName : 'destinatario') . '. Caso cerrado. ' . $flowText . $quoteText
      . ($legacyId > 0 ? ' Registro de acta #' . $legacyId . '.' : '')
      . ($reportId ? ' Reporte administrativo #' . $reportId . ' registrado.' : ' Sin reporte administrativo nuevo por estar asociado a cotización aprobada.')
      . ' Ver acta: ' . $this->viewUrl((int) $act['id']);
    $nowMysql = date('Y-m-d H:i:s', $now);
    $payloadRow = [
      'cct_status' => 'publish',
      'cct_author_id' => $actorEmployeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_ticket' => $ticketRef,
      'id_inmueble' => $propertyId,
      'id_inmueble_data' => $propertyDataId,
      'id_empleado' => $actorEmployeeId,
      'fecha' => $now,
      'tipo_reporte' => 'Acta de satisfacción',
      'tipo_de_reporte_his' => 'Acta de satisfacción',
      'observacion' => $detail,
      'observacion_his' => $detail,
      'funcionario' => $actorName,
      'reporte_realizado_por_his' => $actorName,
      'id_acta_satisfaccion' => (string) $legacyId,
      'id_cotizacion_mantenimiento' => implode(',', $historyQuoteIds),
    ];
    $payloadRow = $this->repo->schema->filterTableData($table, $payloadRow);
    if ($payloadRow !== []) {
      $this->repo->db->insert($table, $payloadRow);
    }
  }

  public function pdf(array $act, bool $staff = false): string
  {
    $payload = $this->payload($act);
    if ($act['status'] !== 'signed') {
      throw new \DomainException('El PDF solo se puede descargar cuando el acta esté firmada.');
    }
    if ($act['status'] === 'signed' && empty($act['signed_pdf']) && in_array((string) (json_decode($act['signed_json'], true)['consent_version'] ?? ''), ['2', '3'], true)) {
      throw new \DomainException('No se encuentra el PDF original firmado. Solicita revisión al administrador.');
    }
    if ($act['status'] === 'signed' && !empty($act['signed_pdf'])) {
      $hash = hash('sha256', $act['signed_pdf']);
      if (!hash_equals((string) $act['pdf_hash'], $hash) || !hash_equals((string) $act['pdf_hmac'], hash_hmac('sha256', $act['id'] . '|' . $act['payload_hash'] . '|' . $hash, $this->secret))) { throw new \DomainException('El PDF no coincide con su registro. Solicita revisión al administrador.'); }
      if (!$staff) { return $act['signed_pdf']; }
    }
    return (new CompletionPdf())->render($act, $payload, $staff);
  }

  /** @return array{filters:array<string,string|int>,items:array<int,array<string,mixed>>,stats:array<string,int>,count:int,pagination:array<string,int>} */
  public function dashboardList(array $input): array
  {
    $this->repo->requireSchema();
    $clean = static fn(mixed $value): string => trim(strip_tags((string) (is_scalar($value) ? $value : '')));
    $status = strtolower($clean($input['sacta_estado'] ?? 'pending'));
    $statusMap = ['pending' => 'pending', 'pendiente' => 'pending', 'sin_firmar' => 'pending', 'firmada' => 'signed', 'signed' => 'signed', 'archivada' => 'archived', 'archived' => 'archived', 'anulada' => 'cancelled', 'cancelled' => 'cancelled', 'todos' => 'all', 'all' => 'all'];
    $status = $statusMap[$status] ?? 'pending';
    $page = max(1, (int) ($input['sacta_page'] ?? 1));
    $perPage = min(100, max(10, (int) ($input['sacta_per_page'] ?? 30)));
    $filters = [
      'estado' => $status,
      'caso' => $clean($input['sacta_caso'] ?? ''),
      'inmueble' => $clean($input['sacta_inmueble'] ?? ''),
      'contrato' => $clean($input['sacta_contrato'] ?? ''),
      'firmante' => $clean($input['sacta_firmante'] ?? ''),
      'page' => $page,
      'per_page' => $perPage,
    ];

    $where = [];
    $args = [];
    if ($status !== 'all') {
      $where[] = 'a.status = ?';
      $args[] = $status;
    }
    $likeJson = static fn(string $path): string => "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '{$path}')), '')))";
    foreach ([
      'caso' => ["LOWER(TRIM(COALESCE(t.id_ticket, '')))", $likeJson('$.ticket_number'), "CAST(a.ticket_pk AS CHAR)"],
      'inmueble' => ["LOWER(TRIM(COALESCE(t.inmueble, '')))", $likeJson('$.property')],
      'contrato' => ["LOWER(TRIM(COALESCE(t.contrato, '')))", $likeJson('$.contract')],
      'firmante' => [$likeJson('$.signer.name'), $likeJson('$.signer.email'), $likeJson('$.signer.phone')],
    ] as $key => $columns) {
      if ($filters[$key] === '') {
        continue;
      }
      $needle = '%' . mb_strtolower($this->repo->db->escapeLike((string) $filters[$key]), 'UTF-8') . '%';
      $where[] = '(' . implode(' OR ', array_map(static fn(string $expr): string => $expr . ' LIKE ?', $columns)) . ')';
      foreach ($columns as $_) {
        $args[] = $needle;
      }
    }

    $table = $this->repo->table();
    $tickets = $this->repo->db->table('jet_cct_tickets');
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = (int) $this->repo->db->getVar("SELECT COUNT(*) FROM `{$table}` a LEFT JOIN `{$tickets}` t ON t._ID = a.ticket_pk{$whereSql}", $args);
    $totalPages = max(1, (int) ceil($count / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = $this->repo->db->getResults(
      "SELECT a.*, t.id_ticket AS ticket_display, t.inmueble AS ticket_inmueble, t.contrato AS ticket_contrato, t.direccion AS ticket_address, t.estado AS ticket_estado, t.estado_administrativo AS ticket_estado_admin
       FROM `{$table}` a
       LEFT JOIN `{$tickets}` t ON t._ID = a.ticket_pk{$whereSql}
       ORDER BY CASE a.status WHEN 'pending' THEN 0 WHEN 'signed' THEN 1 WHEN 'archived' THEN 2 ELSE 3 END, COALESCE(a.signed_at, a.created_at) DESC, a.id DESC
       LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );
    $items = [];
    foreach ($rows as $row) {
      try {
        $row['_payload'] = $this->payload($row);
        $items[] = $row;
      } catch (\Throwable) {
        $row['_payload'] = [];
        $row['_invalid_payload'] = true;
        $items[] = $row;
      }
    }
    $statsRows = $this->repo->db->getResults("SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status");
    $stats = ['pending' => 0, 'signed' => 0, 'archived' => 0, 'cancelled' => 0, 'all' => 0];
    foreach ($statsRows as $row) {
      $key = (string) ($row['status'] ?? '');
      if (isset($stats[$key])) {
        $stats[$key] = (int) ($row['total'] ?? 0);
        $stats['all'] += $stats[$key];
      }
    }

    $filters['page'] = $page;
    return ['filters' => $filters, 'items' => $items, 'stats' => $stats, 'count' => $count, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $count, 'total_pages' => $totalPages]];
  }

  private static function legacyText(array $payload): string
  {
    $lines = ['Solución realizada por: ' . CompletionPolicy::EXECUTORS[$payload['executor']]];
    foreach ($payload['items'] as $index => $item) {
      $lines[] = ($index + 1) . '. Daño: ' . $item['damage'] . "\nSolución: " . $item['solution'];
    }
    $lines[] = 'Observaciones: ' . $payload['observations'];
    return implode("\n\n", $lines);
  }

  /** @return string[] */
  private static function legacyPhotoUrls(array $payload): array
  {
    $urls = [];
    $storage = \SCM\Support\StoredFileService::fromRuntime();
    foreach ((array) ($payload['items'] ?? []) as $item) {
      foreach (['damage_photos', 'photos'] as $photoGroup) {
        foreach ((array) ($item[$photoGroup] ?? []) as $photo) {
          if (!is_array($photo) || trim((string) ($photo['name'] ?? '')) === '') {
            continue;
          }
          $urls[] = $storage->urlFor((string) $photo['name']);
        }
      }
    }
    return array_values(array_unique($urls));
  }
}
