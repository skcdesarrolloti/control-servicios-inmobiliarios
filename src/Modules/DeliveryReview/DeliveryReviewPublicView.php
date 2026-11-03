<?php

declare(strict_types=1);

namespace SCM\Modules\DeliveryReview;

use SCM\Core\Database;
use SCM\Modules\CorrectiveReview\CorrectiveReviewPublicView;
use SCM\Support\SchemaInspector;

/** Informe nativo de la revisión antes de la ocupación (reemplaza la página "revision-de-entrega" de WordPress). */
final class DeliveryReviewPublicView
{
  private Database $db;
  private CorrectiveReviewPublicView $shared;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->shared = new CorrectiveReviewPublicView($db);
  }

  public function headerHtml(): string
  {
    $logo = function_exists('system_image') ? system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? (string) SCM_DEFAULT_PORTAL_LOGO_URL : '') : '';
    return '<header class="scm-corrective-public-head">'
      . '<span class="scm-corrective-public-logo">' . ($logo !== '' ? '<img src="' . $this->h($logo) . '" alt="SKC SuCasa Inmobiliaria">' : '') . '</span>'
      . '<div><p>SKC SuCasa Inmobiliaria</p><strong>Revisión antes de la ocupación</strong></div>'
      . '</header>';
  }

  /** @return array{title:string,content:string,status:int} */
  public function render(int $reviewId): array
  {
    if ($reviewId <= 0) {
      return $this->error('Revisión no disponible', 'El número de revisión no es válido.', 400);
    }
    $table = $this->db->table('jet_cct_revision_entrega');
    $review = (new SchemaInspector($this->db))->tableExists($table)
      ? ($this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$reviewId]) ?: [])
      : [];
    if (!$review) {
      return $this->error('Revisión no encontrada', 'No encontramos una revisión antes de la ocupación con ese número.', 404);
    }
    [$ticket, $contract] = $this->shared->relatedRecords($review);
    $title = 'Revisión antes de la ocupación #' . $reviewId;
    $yes = static fn(string $value): bool => strcasecmp($value, 'Si') === 0;
    $parking = $this->first([$review['tiene_parqueadero'] ?? '']);
    $storage = $this->first([$review['tiene_deposito'] ?? '']);
    $meta = [
      '# Caso' => $this->hash($this->first([$ticket['id_ticket'] ?? '', $review['id_ticket'] ?? ''])),
      'Contrato' => $this->hash($this->first([$review['contrato'] ?? '', $contract['contrato'] ?? ''])),
      'Inmueble simi' => $this->first([$review['inmueble'] ?? '', $contract['inmueble'] ?? '']),
      'Tipo de inmueble' => $this->first([$review['tip_inm'] ?? '']),
      'Dirección' => $this->first([$review['direccion'] ?? '', $contract['direccion'] ?? '']),
      'Propietario' => $this->first([$review['destinatario'] ?? '', $contract['propietario'] ?? '']),
      'Arrendatario' => $this->first([$contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']),
      'Fecha' => $this->date($review['fecha'] ?? '', $review['cct_created'] ?? '', 'd/m/Y H:i'),
    ];
    $property = [
      'Habitaciones' => $this->first([$review['habitaciones'] ?? '']),
      'Baños' => $this->first([$review['banos'] ?? '']),
      'Pisos' => $this->first([$review['piso'] ?? '']),
      'Parqueadero' => $yes($parking) ? trim(implode(' · ', array_filter([$this->first([$review['tipo_parqueadero'] ?? '']), $this->first([$review['parqueaderos'] ?? '']) !== '' ? $review['parqueaderos'] . ' celda(s)' : '', $this->first([$review['numero_parqueadero'] ?? '']) !== '' ? '# ' . $review['numero_parqueadero'] : '']))) ?: 'Sí' : ($parking ?: '-'),
      'Depósito' => $yes($storage) ? ($this->first([$review['depositos'] ?? '']) !== '' ? $review['depositos'] . ' depósito(s)' : 'Sí') : ($storage ?: '-'),
      'Toma de linderos' => $this->date($review['fech_linderos'] ?? '', '', 'd/m/Y'),
    ];

    $content = '<article class="scm-corrective-public-card scm-corrective-public-hero">'
      . '<div class="scm-corrective-public-title"><h1>' . $this->h($title) . '</h1></div>'
      . '<div class="scm-corrective-public-actions"><button type="button" data-scm-print>Imprimir</button></div>'
      . '</article>'
      . '<section class="scm-corrective-public-card"><h2>Datos de la revisión</h2><div class="scm-corrective-public-grid">' . $this->grid($meta) . '</div></section>'
      . '<section class="scm-corrective-public-card"><h2>Inmueble</h2><div class="scm-corrective-public-grid scm-corrective-public-grid--summary">' . $this->grid($property) . '</div></section>';

    $content .= $this->boundariesHtml($review);
    $content .= $this->spacesHtml($review);
    $content .= $this->servicesHtml($review);
    $content .= $this->shared->signatureHtml($review, 'Revisión antes de la ocupación realizada por SKC SuCasa Inmobiliaria.');

    return ['title' => $title, 'content' => $content, 'status' => 200];
  }

  /** @param array<string,mixed> $review */
  private function boundariesHtml(array $review): string
  {
    $labels = ['norte' => 'Norte', 'sur' => 'Sur', 'oriente' => 'Oriente', 'occidente' => 'Occidente', 'cenit' => 'Cenit', 'nadir' => 'Nadir'];
    $cards = '';
    foreach (DeliveryReviewForm::boundaries() as $key => $group) {
      foreach (DeliveryReviewForm::rows($review[$key] ?? '') as $index => $row) {
        $items = [];
        foreach ($group['sides'] as $side) {
          $items[$labels[$side]] = $this->first([$row[$side . '_' . $group['suffix']] ?? '']);
        }
        if (implode('', $items) === '') {
          continue;
        }
        $name = $group['multiple'] ? rtrim($group['label'], 's') . ' ' . ($this->first([$row['num_parqueadero'] ?? '']) !== '' ? '#' . $this->first([$row['num_parqueadero'] ?? '']) : (string) ($index + 1)) : $group['label'];
        $list = '';
        foreach ($items as $label => $value) {
          $list .= '<li><span>' . $this->h($label) . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></li>';
        }
        $cards .= '<div class="scm-delivery-public-card"><h3>' . $this->h($name) . '</h3><ul class="scm-delivery-public-list">' . $list . '</ul></div>';
      }
    }
    return $cards === '' ? '' : '<section class="scm-corrective-public-card scm-delivery-public-section"><h2>Linderos</h2><div class="scm-delivery-public-cards">' . $cards . '</div></section>';
  }

  /** @param array<string,mixed> $review */
  private function spacesHtml(array $review): string
  {
    // Exteriores primero y luego cada piso con todos sus espacios, como se recorre el inmueble.
    $spaces = DeliveryReviewForm::spaces();
    $groups = [];
    foreach (DeliveryReviewForm::GENERAL_SPACES as $key) {
      $rows = DeliveryReviewForm::rows($review[$key] ?? '');
      foreach ($rows as $index => $row) {
        $name = $spaces[$key]['item'] . (count($rows) > 1 ? ' ' . ($index + 1) : '');
        $groups['Exteriores'][] = $this->spaceCard($name, DeliveryReviewForm::values($row[$spaces[$key]['contains']] ?? []), trim((string) ($row[$spaces[$key]['observation']] ?? '')));
      }
    }
    $byFloor = DeliveryReviewForm::spacesByFloor($review);
    $multiFloor = count($byFloor) > 1 || (int) ($review['piso'] ?? 1) > 1;
    foreach ($byFloor as $floor => $floorSpaces) {
      $label = $multiFloor ? 'Piso ' . $floor : 'Espacios interiores';
      foreach ($floorSpaces as $base => $rows) {
        foreach ($rows as $index => $row) {
          $name = $spaces[$base]['item'] . (count($rows) > 1 ? ' ' . ($index + 1) : '') . ($row['tipo'] !== '' ? ' · ' . $row['tipo'] : '');
          $groups[$label][] = $this->spaceCard($name, $row['contiene'], $row['observacion']);
        }
      }
    }
    $cards = '';
    foreach ($groups as $label => $items) {
      $cards .= '<h3 class="scm-delivery-public-subtitle">' . $this->h($label) . '</h3><div class="scm-delivery-public-cards">' . implode('', $items) . '</div>';
    }
    $photo = trim((string) ($review['registro_fotografico'] ?? ''));
    $photoHtml = '';
    if (preg_match('#^https?://#i', $photo)) {
      $folder = $this->driveFolderId($photo);
      $photoHtml = '<div class="scm-delivery-public-photos">'
        . ($folder !== ''
          ? '<button type="button" data-delivery-photos="' . $this->h('https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder) . '#grid') . '">Ver registro fotográfico</button>'
          : '<a href="' . $this->h($photo) . '" rel="noopener">Ver registro fotográfico</a>')
        . '<span class="scm-delivery-public-print-link">Registro fotográfico: ' . $this->h($photo) . '</span>'
        . '<div class="scm-delivery-public-frame" data-delivery-photos-frame hidden></div></div>';
    }
    if ($cards === '' && $photoHtml === '') {
      return '';
    }
    return '<section class="scm-corrective-public-card scm-delivery-public-section"><h2>Registro fotográfico por espacio</h2>' . $photoHtml
      . $cards . '</section>';
  }

  /** @param list<string> $contains */
  private function spaceCard(string $name, array $contains, string $observation): string
  {
    $chips = '';
    foreach ($contains as $item) {
      $chips .= '<span>' . $this->h($item) . '</span>';
    }
    $hasNote = !DeliveryReviewForm::isNone($observation);
    return '<div class="scm-delivery-public-card' . ($hasNote ? ' has-note' : '') . '"><h3>' . $this->h($name) . '</h3>'
      . ($chips !== '' ? '<div class="scm-delivery-public-chips">' . $chips . '</div>' : '')
      . '<p class="scm-delivery-public-note">' . ($hasNote ? '<b>Observación:</b> ' . nl2br($this->h($observation)) : 'Sin observaciones.') . '</p></div>';
  }

  /** @param array<string,mixed> $review */
  private function servicesHtml(array $review): string
  {
    $active = DeliveryReviewForm::values($review['servicios_publicos'] ?? '');
    $html = '';
    foreach (DeliveryReviewForm::services() as $service) {
      if (!in_array($service['option'], $active, true)) {
        continue;
      }
      $rows = '';
      foreach ($service['items'] as $item) {
        $parts = [];
        foreach ($item['fields'] as $field) {
          $value = $this->first([$review[$field['name']] ?? '']);
          if ($value === '') {
            continue;
          }
          if ($field['type'] === 'money' && ctype_digit($value)) {
            $value = '$' . number_format((int) $value, 0, ',', '.');
          } elseif ($field['type'] === 'date') {
            $value = $this->date($value, '', 'd/m/Y');
          }
          $parts[] = (count($item['fields']) > 1 ? $field['label'] . ': ' : '') . $value;
        }
        $observation = $this->first([$review[$item['observation']] ?? '']);
        if ($parts === [] && DeliveryReviewForm::isNone($observation)) {
          continue;
        }
        $rows .= '<tr><th scope="row">' . $this->h($item['label']) . '</th><td>' . $this->h($parts ? implode(' · ', $parts) : '-') . '</td><td>' . $this->h(DeliveryReviewForm::isNone($observation) ? 'Ninguna' : $observation) . '</td></tr>';
      }
      if ($rows !== '') {
        $html .= '<div class="scm-delivery-public-service"><h3>' . $this->h($service['label']) . '</h3><table class="scm-delivery-public-table"><thead><tr><th>Ítem</th><th>Dato</th><th>Observación</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
      }
    }
    $extras = array_map(static fn(string $value): string => DeliveryReviewForm::EXTRA_SERVICE_LABELS[$value] ?? $value, DeliveryReviewForm::values($review['servicios_adicionales'] ?? ''));
    $html .= '<p class="scm-prev-public-text"><b>Servicios adicionales:</b> ' . $this->h($extras ? implode(', ', $extras) : 'Ninguno') . '</p>';
    return '<section class="scm-corrective-public-card scm-delivery-public-section"><h2>Servicios públicos</h2>'
      . ($active === [] ? '<p class="scm-prev-public-text">El inmueble no tenía servicios públicos activos al momento de la revisión.</p>' : '')
      . $html . '</section>';
  }

  private function driveFolderId(string $url): string
  {
    if (!preg_match('#^https://drive\.google\.com/#i', $url)) {
      return '';
    }
    if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $url, $match) === 1 || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $url, $match) === 1) {
      return $match[1];
    }
    return '';
  }

  /** @param array<string,string> $items */
  private function grid(array $items): string
  {
    $html = '';
    foreach ($items as $label => $value) {
      $html .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></div>';
    }
    return $html;
  }

  /** @param mixed $primary @param mixed $fallback */
  private function date($primary, $fallback, string $format): string
  {
    foreach ([$primary, $fallback] as $raw) {
      $raw = trim((string) $raw);
      $ts = $raw === '' ? 0 : (ctype_digit($raw) ? (int) $raw : (strtotime($raw) ?: 0));
      if ($ts > 0) {
        return date($format, $ts);
      }
    }
    return '-';
  }

  /** @param array<int,mixed> $values */
  private function first(array $values): string
  {
    foreach ($values as $value) {
      $text = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
      if ($text !== '' && $text !== '-') {
        return $text;
      }
    }
    return '';
  }

  private function hash(string $value): string
  {
    return $value === '' ? '' : '#' . ltrim($value, '#');
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
