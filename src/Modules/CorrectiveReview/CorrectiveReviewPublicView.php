<?php

declare(strict_types=1);

namespace SCM\Modules\CorrectiveReview;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

final class CorrectiveReviewPublicView
{
  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  /**
   * @return array{title:string,content:string,status:int}
   */
  public function render(int $reviewId): array
  {
    if ($reviewId <= 0) {
      return $this->error('Revisión no disponible', 'El número de revisión no es válido.', 400);
    }

    $review = $this->review($reviewId);
    if (!$review) {
      return $this->error('Revisión no encontrada', 'No encontramos una revisión correctiva con ese número.', 404);
    }

    $ticket = $this->ticket($review);
    $contract = $this->contract($review, $ticket);
    $property = $this->property($review, $ticket, $contract);
    $items = $this->items($review['evaluacion_de_danos'] ?? '');
    $title = 'Revisión correctiva #' . $reviewId;
    $meta = [
      'Caso' => $this->withHash($this->first([$review['id_ticket'] ?? '', $ticket['id_ticket'] ?? '', $ticket['_ID'] ?? ''])),
      'Inmueble' => $this->first([$review['inmueble'] ?? '', $ticket['inmueble'] ?? '', $contract['inmueble'] ?? '', $review['id_inmueble'] ?? '']),
      'Contrato' => $this->withHash($this->first([$review['contrato'] ?? '', $ticket['contrato'] ?? '', $contract['contrato'] ?? '', $review['id_contrato'] ?? ''])),
      'Dirección' => $this->first([$review['direccion'] ?? '', $ticket['direccion'] ?? '', $contract['direccion'] ?? '', $property['direccion'] ?? '', $property['direccion_fisica'] ?? '']),
      'Destinatario' => $this->first([$review['destinatario'] ?? '', $ticket['propietario'] ?? '', $contract['propietario'] ?? '']),
      'Fecha' => $this->dateLabel($this->first([$review['fecha'] ?? '', $review['cct_created'] ?? ''])),
    ];

    $metaHtml = '';
    foreach ($meta as $label => $value) {
      $metaHtml .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></div>';
    }

    $areas = $this->first([$review['area_afectada'] ?? '', $this->combinedAreas($items)]);
    $summary = [
      'Áreas afectadas' => $areas,
      'Total de daños registrados' => (string) count($items),
    ];
    $summaryHtml = '';
    foreach ($summary as $label => $value) {
      $summaryHtml .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></div>';
    }

    $itemsHtml = '';
    foreach ($items as $index => $item) {
      $itemsHtml .= $this->renderItem($item, $index + 1);
    }
    if ($itemsHtml === '') {
      $itemsHtml = '<article class="scm-corrective-public-empty">Esta revisión no tiene daños detallados guardados.</article>';
    }
    $employee = $this->employeeProfile($review);
    $performedBy = $this->first([$employee['name'] ?? '', $review['creador'] ?? '', $review['funcionario'] ?? '', $review['coordinador'] ?? '']);
    $performedCargo = $this->first([$employee['cargo'] ?? '', $review['cargo_creador'] ?? '', $review['cargo'] ?? '']);
    $performedEmail = $this->first([$employee['email'] ?? '', $review['email_creador'] ?? '', $review['email_coordinador'] ?? '']);
    $performedPhone = $this->first([$employee['phone'] ?? '', $review['celular_creador'] ?? '', $review['celular_coordinador'] ?? '']);
    $signatureHtml = '';
    if ($performedBy !== '') {
      $signatureHtml = '<section class="scm-corrective-public-card scm-corrective-public-signature">'
        . '<div class="scm-corrective-public-signature-label">Atentamente,</div>'
        . '<div class="scm-corrective-public-signature-name">' . $this->h($performedBy) . '</div>'
        . ($performedCargo !== '' ? '<div class="scm-corrective-public-signature-role">' . $this->h($performedCargo) . '</div>' : '')
        . '<p>Revisión correctiva realizada desde SKC SuCasa Inmobiliaria.</p>'
        . ($performedEmail !== '' || $performedPhone !== '' ? '<div class="scm-corrective-public-signature-contact">' . $this->h(implode(' · ', array_filter([$performedEmail, $performedPhone]))) . '</div>' : '')
        . '</section>';
    }

    $content = '<article class="scm-corrective-public-card scm-corrective-public-hero">'
      . '<div class="scm-corrective-public-title">'
      . '<p>Informe público</p>'
      . '<h1>' . $this->h($title) . '</h1>'
      . '<span>Consulta nativa del panel de servicios inmobiliarios.</span>'
      . '</div>'
      . '<div class="scm-corrective-public-actions">'
      . '<button type="button" onclick="window.print()">Imprimir</button>'
      . '</div>'
      . '</article>'
      . '<section class="scm-corrective-public-card"><h2>Datos de la revisión</h2><div class="scm-corrective-public-grid">' . $metaHtml . '</div></section>'
      . '<section class="scm-corrective-public-card"><h2>Resumen</h2><div class="scm-corrective-public-grid scm-corrective-public-grid--summary">' . $summaryHtml . '</div></section>'
      . '<section class="scm-corrective-public-card"><h2>Daños encontrados</h2><div class="scm-corrective-public-items">' . $itemsHtml . '</div></section>'
      . $signatureHtml;

    return [
      'title' => $title,
      'content' => $content,
      'status' => 200,
    ];
  }

  /** @return array<string,mixed> */
  private function review(int $reviewId): array
  {
    $table = $this->db->table('jet_cct_revision_correctiva');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$reviewId]) ?: [];
  }

  /** @param array<string,mixed> $review @return array<string,mixed> */
  private function ticket(array $review): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $ticketId = $this->digits($review['id_ticket'] ?? '');
    if ($ticketId === '') {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? OR `id_ticket` = ? LIMIT 1", [$ticketId, $ticketId]) ?: [];
  }

  /** @param array<string,mixed> $review @param array<string,mixed> $ticket @return array<string,mixed> */
  private function contract(array $review, array $ticket): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $idContrato = $this->digits($this->first([$review['id_contrato'] ?? '', $ticket['id_contrato'] ?? '']));
    if ($idContrato !== '') {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$idContrato]);
      if ($row) {
        return $row;
      }
    }
    $contrato = $this->digits($this->first([$review['contrato'] ?? '', $ticket['contrato'] ?? '']));
    if ($contrato !== '') {
      return $this->db->getRow("SELECT * FROM `{$table}` WHERE `contrato` = ? LIMIT 1", [$contrato]) ?: [];
    }
    return [];
  }

  /** @param array<string,mixed> $review @param array<string,mixed> $ticket @param array<string,mixed> $contract @return array<string,mixed> */
  private function property(array $review, array $ticket, array $contract): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    foreach ([$contract['id_inmueble_data'] ?? '', $ticket['id_inmueble_data'] ?? '', $review['id_inmueble'] ?? ''] as $candidate) {
      $id = $this->digits($candidate);
      if ($id === '') {
        continue;
      }
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]);
      if ($row) {
        return $row;
      }
    }
    $contractId = $this->digits($this->first([$review['id_contrato'] ?? '', $contract['_ID'] ?? '', $ticket['id_contrato'] ?? '']));
    if ($contractId !== '') {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_contrato_arrendamiento` = ? LIMIT 1", [$contractId]);
      if ($row) {
        return $row;
      }
    }
    $code = $this->digits($this->first([$review['inmueble'] ?? '', $ticket['inmueble'] ?? '', $contract['inmueble'] ?? '']));
    if ($code !== '') {
      return $this->db->getRow("SELECT * FROM `{$table}` WHERE `codigo` = ? OR `id_ticket` = ? LIMIT 1", [$code, $code]) ?: [];
    }
    return [];
  }

  /** @param array<string,mixed> $review @return array{name:string,cargo:string,email:string,phone:string} */
  private function employeeProfile(array $review): array
  {
    $profile = ['name' => '', 'cargo' => '', 'email' => '', 'phone' => ''];
    $employeeId = $this->first([$review['id_empleado'] ?? '', $review['cct_author_id'] ?? '']);
    if ($employeeId === '') {
      return $profile;
    }

    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return $profile;
    }
    $cargoTable = $this->db->table('jet_cct_cargos');
    $hasCargoNames = $this->schema->tableExists($cargoTable)
      && $this->schema->columnExists($cargoTable, '_ID')
      && $this->schema->columnExists($cargoTable, 'nombre_cargo');
    $cargoSelect = $hasCargoNames ? "TRIM(COALESCE(c.`nombre_cargo`, ''))" : "''";
    $join = $hasCargoNames ? " LEFT JOIN `{$cargoTable}` c ON TRIM(COALESCE(f.`id_cargo`, '')) = CAST(c.`_ID` AS CHAR)" : '';
    $row = $this->db->getRow(
      "SELECT
          TRIM(COALESCE(f.`nombre`, '')) AS nombre,
          TRIM(COALESCE(f.`correo`, '')) AS correo,
          TRIM(COALESCE(f.`celular`, '')) AS celular,
          TRIM(COALESCE(f.`rol`, '')) AS rol,
          TRIM(COALESCE(f.`gestion`, '')) AS gestion,
          TRIM(COALESCE(f.`id_cargo`, '')) AS id_cargo,
          {$cargoSelect} AS nombre_cargo
        FROM `{$table}` f
        {$join}
        WHERE TRIM(COALESCE(f.`id_empleado`, '')) = ?
        LIMIT 1",
      [$employeeId]
    );
    if (!$row) {
      return $profile;
    }

    return [
      'name' => $this->first([$row['nombre'] ?? '']),
      'cargo' => $this->first([$row['nombre_cargo'] ?? '', $row['rol'] ?? '', $row['gestion'] ?? '', $row['id_cargo'] ?? '']),
      'email' => $this->first([$row['correo'] ?? '']),
      'phone' => $this->first([$row['celular'] ?? '']),
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function items(mixed $raw): array
  {
    $items = [];
    if (is_string($raw) && trim($raw) !== '') {
      $unserialized = @unserialize($raw);
      if (is_array($unserialized)) {
        $items = $unserialized;
      } else {
        $json = json_decode($raw, true);
        $items = is_array($json) ? $json : [];
      }
    } elseif (is_array($raw)) {
      $items = $raw;
    }

    $out = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $out[] = $item;
      }
      if (count($out) >= 30) {
        break;
      }
    }
    return $out;
  }

  /** @param array<string,mixed> $item */
  private function renderItem(array $item, int $number): string
  {
    $indice = $this->text($item['indice'] ?? '');
    $area = $this->itemArea($item);
    $descripcion = $this->text($item['descripcion_dano'] ?? '');
    $consecuencia = $this->text($item['consecuencia'] ?? '');
    $nivel = $this->text($item['nivel_dano'] ?? '');
    $tiempo = $this->text($item['tiempo_atencion'] ?? '');
    $corresponde = $this->text($item['a_quien_corresponde'] ?? '');
    $photos = $this->photoRefs($item['registro_foto_dano'] ?? '');

    $details = [
      'Índice' => $indice,
      'Área afectada' => $area,
      'Nivel del daño' => $nivel,
      'Tiempo de atención' => $tiempo,
      'A quien corresponde' => $corresponde,
    ];
    $detailsHtml = '';
    foreach ($details as $label => $value) {
      if ($value === '') {
        continue;
      }
      $detailsHtml .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value) . '</strong></div>';
    }
    $photosHtml = '';
    foreach ($photos as $photo) {
      $url = $this->photoUrl($photo);
      if ($url === '') {
        continue;
      }
      $photosHtml .= '<a href="' . $this->h($url) . '" target="_blank" rel="noopener"><img src="' . $this->h($url) . '" alt="Evidencia del daño #' . $number . '" loading="lazy"></a>';
    }

    return '<article class="scm-corrective-public-item">'
      . '<header><span>Daño #' . $number . '</span>' . ($area !== '' ? '<strong>' . $this->h($area) . '</strong>' : '') . '</header>'
      . ($detailsHtml !== '' ? '<div class="scm-corrective-public-grid scm-corrective-public-grid--item">' . $detailsHtml . '</div>' : '')
      . ($descripcion !== '' ? '<section><h3>Descripción del daño</h3><p>' . $this->h($descripcion) . '</p></section>' : '')
      . ($consecuencia !== '' ? '<section><h3>Consecuencia</h3><p>' . $this->h($consecuencia) . '</p></section>' : '')
      . ($photosHtml !== '' ? '<section><h3>Evidencias</h3><div class="scm-corrective-public-photos">' . $photosHtml . '</div></section>' : '')
      . '</article>';
  }

  /** @param array<string,mixed> $item */
  private function itemArea(array $item): string
  {
    $indice = $this->normalize($this->text($item['indice'] ?? ''));
    $preferred = 'area_afectada_1';
    if (str_contains($indice, 'estruct')) {
      $preferred = 'area_afectada_2';
    } elseif (str_contains($indice, 'redes') || str_contains($indice, 'instalaciones')) {
      $preferred = 'area_afectada_3';
    } elseif (str_contains($indice, 'humedad') || str_contains($indice, 'humedades')) {
      $preferred = 'area_afectada_4';
    }

    return $this->first([
      $item[$preferred] ?? '',
      $item['area_afectada'] ?? '',
      $item['area_afectada_1'] ?? '',
      $item['area_afectada_2'] ?? '',
      $item['area_afectada_3'] ?? '',
      $item['area_afectada_4'] ?? '',
    ]);
  }

  /** @param array<int,array<string,mixed>> $items */
  private function combinedAreas(array $items): string
  {
    $areas = [];
    foreach ($items as $item) {
      $area = $this->itemArea($item);
      if ($area !== '') {
        $areas[$area] = $area;
      }
    }
    return implode(', ', array_values($areas));
  }

  /** @return array<int,string> */
  private function photoRefs(mixed $value): array
  {
    $refs = [];
    if (is_array($value)) {
      foreach ($value as $entry) {
        foreach ($this->photoRefs($entry) as $ref) {
          $refs[$ref] = $ref;
        }
      }
      return array_values($refs);
    }
    foreach (preg_split('/[,\r\n]+/', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $part) {
      $ref = trim(strip_tags((string) $part));
      if ($ref === '' || strlen($ref) > 2048 || preg_match('/[\x00<>"\']/', $ref)) {
        continue;
      }
      if (preg_match('#^https?://#i', $ref) || str_starts_with($ref, '/') || str_starts_with($ref, 'file.php?')) {
        $refs[$ref] = $ref;
      }
    }
    return array_values($refs);
  }

  private function photoUrl(string $url): string
  {
    $url = trim($url);
    if ($url === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $url)) {
      return $url;
    }
    if (str_starts_with($url, 'file.php?') && defined('SCM_BASE_URL')) {
      return rtrim((string) SCM_BASE_URL, '/') . '/' . $url;
    }
    return $url;
  }

  /** @param array<int,mixed> $values */
  private function first(array $values): string
  {
    foreach ($values as $value) {
      $text = $this->text($value);
      if ($text !== '' && $text !== '-') {
        return $text;
      }
    }
    return '';
  }

  private function text(mixed $value): string
  {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
    $text = strip_tags($text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?: '';
    $text = preg_replace('/\R{3,}/', "\n\n", $text) ?: '';
    return trim($text);
  }

  private function normalize(string $value): string
  {
    $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'];
    $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'u', 'n'];
    $value = strtolower(str_replace($from, $to, $value));
    return preg_replace('/\s+/', ' ', $value) ?: '';
  }

  private function digits(mixed $value): string
  {
    return preg_replace('/\D+/', '', (string) $value) ?: '';
  }

  private function withHash(string $value): string
  {
    if ($value === '') {
      return '';
    }
    return str_starts_with($value, '#') ? $value : '#' . $value;
  }

  private function dateLabel(mixed $value): string
  {
    if ($value === '') {
      return '-';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    return $ts > 0 ? date('d/m/Y H:i', $ts) : '-';
  }

  /** @return array{title:string,content:string,status:int} */
  private function error(string $title, string $message, int $status): array
  {
    return [
      'title' => $title,
      'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>' . $this->h($title) . '</h1><p role="alert">' . $this->h($message) . '</p></article>',
      'status' => $status,
    ];
  }

  private function h(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
