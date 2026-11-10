<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Core\Database;
use SCM\Modules\DeliveryReview\DeliveryReviewForm;

/** Documento nativo del inventario (reemplaza la página "inventario-inmueble" de WordPress). */
final class InventoryPublicView
{
  private const CLAUSE = 'Que el inmueble ha sido entregado a la persona delegada por los arrendatarios a recibir conforme al presente inventario. Acorde con el contrato de arrendamiento, los arrendatarios se comprometen a conservar y mantener el inmueble y su correspondiente dotación, en el mismo estado que lo reciben, salvo los deterioros naturales originados en el uso decente del mismo, así como a arreglar daños resultantes del mal trato o descuido en el lapso de la tenencia. Si estos arreglos no se hicieren, queda el arrendador autorizado para hacerlos por su cuenta y para cobrar ejecutivamente las sumas correspondientes a los arrendatarios: Para este efecto convienen las partes en que las facturas de reparación de daños o de reposición de faltantes junto con el contrato de arrendamiento prestarán mérito ejecutivo suficiente.';

  public function __construct(private Database $db)
  {
  }

  public function headerHtml(): string
  {
    $logo = function_exists('system_image') ? system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? (string) SCM_DEFAULT_PORTAL_LOGO_URL : '') : '';
    return '<header class="scm-corrective-public-head">'
      . '<span class="scm-corrective-public-logo">' . ($logo !== '' ? '<img src="' . $this->h($logo) . '" alt="SKC SuCasa Inmobiliaria">' : '') . '</span>'
      . '<div><p>SKC SuCasa Inmobiliaria</p><strong>Inventario del inmueble</strong></div>'
      . '</header>';
  }

  /** @return array{title:string,content:string,status:int} */
  public function render(int $inventoryId): array
  {
    if ($inventoryId <= 0) {
      return $this->error('Inventario no disponible', 'El número de inventario no es válido.', 400);
    }
    $table = $this->db->table('jet_cct_inventario');
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? AND COALESCE(`cct_status`, 'publish') <> 'trash' LIMIT 1", [$inventoryId]);
    if (!$row) {
      return $this->error('Inventario no encontrado', 'No encontramos un inventario con ese número.', 404);
    }
    $type = $this->propertyType($row);
    $profile = InventorySchema::profile($type);
    $title = 'Inventario #' . $inventoryId;
    $meta = [
      'Fecha' => $this->date($row['fecha'] ?? '', $row['fecha_entrega'] ?? ''),
      'Propietario' => $this->text($row['propietario'] ?? ''),
      'Dirección' => $this->text($row['direccion'] ?? ''),
      'Contrato' => $this->hash($this->text($row['contrato'] ?? '')),
      'Arrendatario' => $this->text($row['arrendatario'] ?? ''),
      'Inmueble' => $this->text($row['inmueble'] ?? ''),
      'Tipo de inmueble' => $type,
      'Categoría' => $this->text($row['categoria_inventario'] ?? ''),
      'Tipo de inventario' => $this->text($row['tipo_inventario'] ?? ''),
    ];
    $content = '<article class="scm-corrective-public-card scm-corrective-public-hero">'
      . '<div class="scm-corrective-public-title"><h1>' . $this->h($title) . '</h1></div>'
      . '<div class="scm-corrective-public-actions"><button type="button" data-scm-print>Imprimir</button></div>'
      . '</article>'
      . '<section class="scm-corrective-public-card"><h2>Información general</h2><div class="scm-corrective-public-grid">' . $this->grid($meta) . '</div>' . $this->propertyFacts($row) . $this->photos($row) . '</section>'
      . $this->boundaries($row)
      . $this->elements($row, $profile)
      . $this->furniture($row, $profile)
      . $this->observations($row)
      . $this->services($row)
      . $this->note($row)
      . $this->closing($row);
    return ['title' => $title, 'content' => $content, 'status' => 200];
  }

  /** @param array<string,mixed> $row */
  private function propertyFacts(array $row): string
  {
    $facts = [];
    if (InventorySchema::floors($row['cantidad_pisos'] ?? 1) > 1) {
      $facts['Pisos'] = (string) InventorySchema::floors($row['cantidad_pisos'] ?? 1);
    }
    foreach (['Habitaciones' => 'cantidad_habitaciones', 'Baños' => 'cantidad_banos'] as $label => $column) {
      if ($this->text($row[$column] ?? '') !== '') {
        $facts[$label] = $this->text($row[$column]);
      }
    }
    if (strcasecmp($this->text($row['tiene_parqueadero'] ?? ''), 'Si') === 0) {
      $facts['Parqueadero'] = implode(' · ', array_filter([$this->text($row['tipo_parqueadero'] ?? ''), $this->text($row['numero_parqueadero'] ?? '') !== '' ? '# ' . $this->text($row['numero_parqueadero']) : ''])) ?: 'Sí';
    }
    if (strcasecmp($this->text($row['tiene_deposito'] ?? ''), 'Si') === 0) {
      $facts['Depósitos'] = $this->text($row['depositos'] ?? '') ?: 'Sí';
    }
    return $facts === [] ? '' : '<div class="scm-corrective-public-grid scm-corrective-public-grid--summary scm-inv-facts">' . $this->grid($facts) . '</div>';
  }

  /** @param array<string,mixed> $row */
  private function photos(array $row): string
  {
    $photo = trim((string) ($row['registro_fotografico'] ?? ''));
    if (!preg_match('#^https?://#i', $photo)) {
      return '';
    }
    $folder = preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $photo, $match) === 1 ? $match[1] : '';
    return '<div class="scm-delivery-public-photos">'
      . ($folder !== ''
        ? '<button type="button" data-delivery-photos="' . $this->h('https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder) . '#grid') . '">Ver registro fotográfico</button>'
        : '<a href="' . $this->h($photo) . '" rel="noopener">Ver registro fotográfico</a>')
      . '<span class="scm-delivery-public-print-link">Registro fotográfico: ' . $this->h($photo) . '</span>'
      . '<div class="scm-delivery-public-frame" data-delivery-photos-frame hidden></div></div>';
  }

  /** @param array<string,mixed> $row */
  private function boundaries(array $row): string
  {
    $labels = ['norte' => 'Norte', 'sur' => 'Sur', 'oriente' => 'Oriente', 'occidente' => 'Occidente', 'cenit' => 'Cenit', 'nadir' => 'Nadir'];
    $cards = '';
    foreach (InventorySchema::boundaries() as $column => $group) {
      foreach (DeliveryReviewForm::rows($row[$column] ?? '') as $index => $item) {
        $list = '';
        $filled = false;
        foreach ($group['sides'] as $side) {
          $value = $this->text($item[$side . '_' . $group['suffix']] ?? '');
          $filled = $filled || $value !== '';
          $list .= '<li><span>' . $labels[$side] . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></li>';
        }
        if (!$filled) {
          continue;
        }
        $number = $this->text($item['num_parqueadero'] ?? '');
        $name = $group['multiple'] ? $group['label'] . ' ' . ($number !== '' ? '#' . $number : (string) ($index + 1)) : $group['label'];
        $cards .= '<div class="scm-delivery-public-card"><h3>' . $this->h($name) . '</h3><ul class="scm-delivery-public-list">' . $list . '</ul></div>';
      }
    }
    if ($cards === '') {
      return '';
    }
    $date = $this->date($row['fecha_linderos'] ?? '', '');
    return '<section class="scm-corrective-public-card scm-delivery-public-section"><h2>Linderos</h2>'
      . ($date !== '' ? '<p class="scm-inv-note">Fecha de toma de linderos: ' . $this->h($date) . '</p>' : '')
      . '<div class="scm-delivery-public-cards">' . $cards . '</div></section>';
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $profile */
  private function elements(array $row, array $profile): string
  {
    $html = '';
    foreach (InventorySchema::sections() as $key => $section) {
      $rows = InventorySchema::rowsFromStored($row[$key] ?? '', $section['suffix']);
      if ($rows === []) {
        continue;
      }
      $head = '';
      $floor = $section['floor'] !== '' ? $this->text($row[$section['floor']] ?? '') : '';
      $details = $section['details'] !== '' ? $this->text($row[$section['details']] ?? '') : '';
      if ($floor !== '' || $details !== '') {
        $head = '<p class="scm-inv-note">' . $this->h(implode(' · ', array_filter([$floor !== '' ? 'Piso: ' . $floor : '', $details]))) . '</p>';
      }
      $html .= '<div class="scm-inv-block"><h3>' . $this->h(InventorySchema::sectionLabel($key, $profile)) . '</h3>' . $head . $this->table($rows, true) . '</div>';
    }
    return $html === '' ? '' : '<section class="scm-corrective-public-card scm-inv-section"><h2>Inventario del inmueble</h2>' . $html . '</section>';
  }

  /** @param array<string,mixed> $row @param array<string,mixed> $profile */
  private function furniture(array $row, array $profile): string
  {
    $html = '';
    foreach (InventorySchema::sections() as $key => $section) {
      $rows = InventorySchema::rowsFromStored($row[$section['furniture']] ?? '', $section['furniture_suffix']);
      if ($rows !== []) {
        $html .= '<div class="scm-inv-block"><h3>' . $this->h(InventorySchema::sectionLabel($key, $profile)) . '</h3>' . $this->table($rows, false) . '</div>';
      }
    }
    return $html === '' ? '' : '<section class="scm-corrective-public-card scm-inv-section"><h2>Muebles y enseres</h2>' . $html . '</section>';
  }

  /** @param list<array<string,string>> $rows */
  private function table(array $rows, bool $material): string
  {
    $head = '<tr><th>Descripción</th><th>Cantidad</th>' . ($material ? '<th>Tipo de material</th>' : '') . '<th>Estado</th><th>Observaciones</th></tr>';
    $body = '';
    foreach ($rows as $item) {
      $body .= '<tr><td>' . $this->h($item['d']) . '</td><td>' . $this->h($item['c']) . '</td>'
        . ($material ? '<td>' . $this->h($item['m']) . '</td>' : '')
        . '<td>' . $this->h($item['e']) . '</td><td>' . $this->h($item['o']) . '</td></tr>';
    }
    return '<div class="scm-inv-table-wrap"><table class="scm-inv-table"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>';
  }

  /** @param array<string,mixed> $row */
  private function observations(array $row): string
  {
    $text = $this->text($row['observaciones'] ?? '');
    return $text === '' ? '' : '<section class="scm-corrective-public-card"><h2>Observaciones</h2><p class="scm-inv-text">' . nl2br($this->h($text)) . '</p></section>';
  }

  /** @param array<string,mixed> $row */
  private function services(array $row): string
  {
    $active = DeliveryReviewForm::values($row['servicios_publicos'] ?? '');
    $cards = '';
    foreach ([['Energia', 'Luz', 'luz', 'medidor_luz'], ['Agua', 'Agua', 'agua', 'medidor_agua'], ['Gas', 'Gas', 'gas', 'medidor_gas']] as [$service, $label, $column, $meter]) {
      $value = $this->text($row[$column] ?? '');
      $meterValue = $this->text($row[$meter] ?? '');
      if ($value === '' && $meterValue === '' && !in_array($service, $active, true)) {
        continue;
      }
      $cards .= '<div class="scm-delivery-public-card"><h3>' . $label . '</h3><ul class="scm-delivery-public-list">'
        . '<li><span>Póliza o cuenta</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></li>'
        . ($meterValue !== '' ? '<li><span>Medidor</span><strong>' . $this->h($meterValue) . '</strong></li>' : '')
        . '</ul></div>';
    }
    return $cards === '' ? '' : '<section class="scm-corrective-public-card scm-delivery-public-section"><h2>Servicios</h2><div class="scm-delivery-public-cards">' . $cards . '</div></section>';
  }

  /** @param array<string,mixed> $row */
  private function note(array $row): string
  {
    $agreements = '';
    foreach (InventorySchema::agreements() as $label => $column) {
      $text = $this->text($row[$column] ?? '');
      if ($text !== '') {
        $agreements .= '<p class="scm-inv-text"><strong>' . $label . ':</strong> ' . nl2br($this->h($text)) . '</p>';
      }
    }
    return '<section class="scm-corrective-public-card scm-inv-note-card"><h2>Nota</h2>'
      . ($agreements !== '' ? '<h3>Acuerdos precontractuales</h3>' . $agreements : '')
      . '<h3>Declaramos expresamente las partes</h3><p class="scm-inv-text">' . $this->h(self::CLAUSE) . '</p></section>';
  }

  /** @param array<string,mixed> $row */
  private function closing(array $row): string
  {
    $date = $this->date($row['fecha'] ?? '', $row['fecha_entrega'] ?? '');
    $delegated = strcasecmp($this->text($row['tiene_delegado'] ?? ''), 'Si') === 0 && $this->text($row['delegado'] ?? '') !== '';
    $verifier = $this->party('Verificador de inmuebles', $this->first([$row['coordinador'] ?? '', $row['creador'] ?? '']), $this->first([$row['email_coordinador'] ?? '', $row['email_creador'] ?? '']), $this->first([$row['celular_coordinador'] ?? '', $row['celular_creador'] ?? '']));
    $receiver = $delegated
      ? $this->party('Quien recibe en representación del arrendatario', $this->text($row['delegado']), $this->text($row['correo_delegado'] ?? ''), $this->text($row['celular_delegado'] ?? ''))
      : $this->party('Quien recibe', $this->text($row['arrendatario'] ?? ''), $this->text($row['correo_arrendatario'] ?? ''), $this->text($row['celular_arrendatario'] ?? ''));
    return '<section class="scm-corrective-public-card scm-inv-closing">'
      . '<p class="scm-inv-text">Para constancia de nuestra conformidad firmamos el presente documento' . ($date !== '' ? ', el día ' . $this->h($date) : '') . '.</p>'
      . '<div class="scm-inv-signatures">' . $verifier . $receiver . '</div>'
      . '<p class="scm-inv-performed">Inventario realizado por SKC SuCasa Inmobiliaria.</p></section>';
  }

  private function party(string $role, string $name, string $email, string $phone): string
  {
    return '<div class="scm-inv-signature"><span class="scm-inv-signature-line"></span><strong>' . $this->h($name !== '' ? $name : '-') . '</strong>'
      . '<small>' . $this->h($role) . '</small>'
      . ($email !== '' ? '<small>' . $this->h($email) . '</small>' : '')
      . ($phone !== '' ? '<small>Cel. ' . $this->h($phone) . '</small>' : '')
      . '</div>';
  }

  /** @param array<string,mixed> $row */
  private function propertyType(array $row): string
  {
    // Algunos registros antiguos guardaron el tipo serializado (a:1:{i:0;i:106;}); no es un dato legible.
    $type = $this->text($row['tipo_de_inmueble'] ?? '');
    return str_starts_with($type, 'a:') ? '' : $type;
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

  private function date(mixed $primary, mixed $fallback): string
  {
    foreach ([$primary, $fallback] as $raw) {
      $raw = trim((string) $raw);
      $ts = $raw === '' ? 0 : (ctype_digit($raw) ? (int) $raw : (strtotime($raw) ?: 0));
      if ($ts > 0) {
        return date('d/m/Y', $ts);
      }
    }
    return '';
  }

  private function text(mixed $value): string
  {
    if (is_array($value)) {
      return '';
    }
    return trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  }

  /** @param list<mixed> $values */
  private function first(array $values): string
  {
    foreach ($values as $value) {
      $text = $this->text($value);
      if ($text !== '') {
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
