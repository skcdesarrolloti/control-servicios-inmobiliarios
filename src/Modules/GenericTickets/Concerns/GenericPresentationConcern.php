<?php

declare(strict_types=1);

namespace SCM\Modules\GenericTickets\Concerns;

use SCM\Core\Auth;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

trait GenericPresentationConcern
{
  public function render_historial_block($items): string
  {
    return $this->uiView()->renderHistorialBlock($items);
  }

  /**
   * @param array<int,array<string,mixed>> $items
   */
  public function render_record_section(string $title, array $items, string $sectionId = '', array $visibleFields = []): string
  {
    return $this->uiView()->renderRecordSection($title, $items, $sectionId, $visibleFields);
  }

  /**
   * @param array<string,mixed> $item
   * @param array<string,string> $visibleFields
   */
  private function render_record_item_fields(array $item, array $visibleFields): string
  {
    return $this->uiView()->renderRecordItemFields($item, $visibleFields);
  }

  /**
   * @param array<string,mixed> $record
   */
  public function render_single_record_section(string $title, array $record, string $sectionId = ''): string
  {
    return $this->uiView()->renderSingleRecordSection($title, $record, $sectionId);
  }

  /** @return array<string,string> */
  public function get_record_field_map(string $title): array
  {
    $key = strtolower(trim($title));
    if ($key === 'contrato') {
      return [
        'contrato' => 'Contrato',
        'id_contrato' => 'ID contrato',
        'id_inmueble' => 'ID inmueble',
        'estado' => 'Estado',
        'arrendatario' => 'Arrendatario',
        'propietario' => 'Propietario',
        'valor_canon' => 'Canon',
        'valor_administracion' => 'Administracion',
        'tasa_administracion' => 'Tasa administracion',
        'porcentaje_incremento_admin' => 'Porcentaje admin',
        'derechos_inmobiliarios' => 'Derechos inmobiliarios',
        'aseguradora' => 'Aseguradora',
        'numero_solicitud' => 'Numero solicitud',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
      ];
    }
    if ($key === 'inmueble') {
      return [
        'codigo' => 'Codigo',
        'id_inmueble' => 'ID inmueble',
        'direccion' => 'Direccion',
        'barrio' => 'Barrio',
        'ciudad' => 'Ciudad',
        'estado' => 'Estado',
        'tipo_inmueble' => 'Tipo',
        'tipo_negocio' => 'Negocio',
        'destinacion' => 'Destinacion',
        'precio_arriendo' => 'Precio arriendo',
        'precio_venta' => 'Precio venta',
        'precio_admin' => 'Administracion',
        'area_construida' => 'Area construida',
        'area_privada' => 'Area privada',
        'habitaciones' => 'Habitaciones',
        'banos' => 'Banos',
        'parqueaderos' => 'Parqueaderos',
        'estrato' => 'Estrato',
        'copropiedad' => 'Copropiedad',
        'matricula_inmobiliaria' => 'Matricula inmobiliaria',
        'id_estudio_aseguradora' => 'Estudio aseguradora',
      ];
    }
    return [];
  }

  public function format_history_detail_html(string $raw): string
  {
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = wp_kses_post($decoded);
    if (trim($clean) === '') {
      return esc_html($raw);
    }
    return $clean;
  }
  /**
   * @param array<int,array{url:string,label:string}> $buttons
   */
  private function render_case_action_buttons(array $buttons): string
  {
    return $this->uiView()->renderCaseActionButtons($buttons);
  }

  /**
   * @param array<int,array<string,mixed>> $historialItems
   * @return array<int,array{url:string,label:string}>
   */
  private function collect_case_action_buttons(string $ticketUrl, string $prevUrl, string $corrUrl, string $cotzUrl, $historialItems): array
  {
    $buttons = [];
    if ($ticketUrl !== '') {
      $buttons[] = ['url' => $ticketUrl, 'label' => 'Ver ticket'];
    }
    if ($corrUrl !== '') {
      $buttons[] = ['url' => $corrUrl, 'label' => 'Ver revision'];
    } elseif ($prevUrl !== '') {
      $buttons[] = ['url' => $prevUrl, 'label' => 'Ver revision'];
    }
    if ($cotzUrl !== '') {
      $buttons[] = ['url' => $cotzUrl, 'label' => 'Ver cotizacion'];
    }

    $items = is_array($historialItems) ? $historialItems : [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach ($this->extract_history_id_buttons($item) as $btn) {
        $buttons[] = $btn;
      }

      $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
      if ($raw === '') {
        continue;
      }
      foreach ($this->extract_links_from_html($raw) as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));
        if ($label === '' || $url === '' || stripos($label, 'ver ') !== 0) {
          continue;
        }
        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    $unique = [];
    $out = [];
    foreach ($buttons as $btn) {
      $url = trim((string) ($btn['url'] ?? ''));
      $label = trim((string) ($btn['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $item
   * @return array<int,array{url:string,label:string}>
   */
  private function extract_history_id_buttons(array $item): array
  {
    $urlMap = HistoryLinkMap::idButtons();

    $out = [];
    foreach ($urlMap as $field => $meta) {
      $raw = trim((string) ($item[$field] ?? ''));
      if ($raw === '') {
        continue;
      }
      $ids = preg_split('/[,\s;|]+/', $raw);
      if (!is_array($ids)) {
        continue;
      }
      foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '') {
          continue;
        }
        $base = trim((string) ($meta['base'] ?? ''));
        if ($base === '') {
          continue;
        }
        $out[] = [
          'url' => $base . rawurlencode($id),
          'label' => (string) $meta['label'],
        ];
      }
    }

    $estudio = trim((string) ($item['id_estudio_aseguradora'] ?? ''));
    if ($estudio !== '') {
      $ticket = trim((string) ($item['id_ticket'] ?? ''));
      $url = 'https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio=' . rawurlencode($estudio);
      if ($ticket !== '') {
        $url .= '&id_ticket=' . rawurlencode($ticket);
      }
      $out[] = ['url' => $url, 'label' => 'Ver estudio aseguradora'];
    }

    $hojaCierre = trim((string) ($item['id_hoja_cierre'] ?? ''));
    if ($hojaCierre !== '') {
      $url = 'https://sucasainmobiliaria.com.co/hoja-de-cierre/?numero=' . rawurlencode($hojaCierre);
      $extraMap = [
        'id_inmueble' => 'id_inmueble',
        'id_inventario' => 'id_inmueble_data',
        'id_contrato' => 'id_contrato',
        'id_empleado' => 'id_empleado',
      ];
      foreach ($extraMap as $field => $param) {
        $val = trim((string) ($item[$field] ?? ''));
        if ($val !== '') {
          $url .= '&' . $param . '=' . rawurlencode($val);
        }
      }
      $out[] = ['url' => $url, 'label' => 'Ver hoja de cierre'];
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $item
   * @return array<int,array{url:string,label:string}>
   */
  public function build_history_item_buttons(array $item): array
  {
    $buttons = $this->extract_history_id_buttons($item);
    $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
    if ($raw !== '') {
      foreach ($this->extract_links_from_html($raw) as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $url = trim((string) ($link['url'] ?? ''));
        if ($label === '' || $url === '') {
          continue;
        }
        if (stripos($label, 'ver ') !== 0) {
          $label = 'Ver recurso';
        }
        $buttons[] = ['url' => $url, 'label' => $label];
      }
    }

    $unique = [];
    $out = [];
    foreach ($buttons as $btn) {
      $url = trim((string) ($btn['url'] ?? ''));
      $label = trim((string) ($btn['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $key = strtolower($label . '|' . $url);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }

    return $out;
  }

  /**
   * @return array<int,array{url:string,label:string}>
   */
  private function extract_links_from_html(string $raw): array
  {
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded === '') {
      return [];
    }

    if (!preg_match_all('/<a\\b[^>]*href=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\\/a>/is', $decoded, $matches, PREG_SET_ORDER)) {
      return [];
    }

    $links = [];
    foreach ($matches as $m) {
      $href = trim((string) ($m[1] ?? ''));
      $labelRaw = trim((string) ($m[2] ?? ''));
      if ($href === '' || $labelRaw === '') {
        continue;
      }
      $label = trim(preg_replace('/\\s+/', ' ', wp_strip_all_tags($labelRaw)));
      if ($label === '') {
        continue;
      }
      $links[] = ['url' => $href, 'label' => $label];
    }

    return $links;
  }

  public function first_id_value(string $raw): string
  {
    $parts = preg_split('/[,\|;]+/', $raw);
    if (!is_array($parts)) {
      return trim($raw);
    }
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        return $id;
      }
    }
    return '';
  }

  public function human_duration_since(int $fromTs): string
  {
    if ($fromTs <= 0) {
      return '-';
    }

    $diff = time() - $fromTs;
    if ($diff < 0) {
      $diff = 0;
    }

    $days = (int) floor($diff / 86400);
    $hours = (int) floor(($diff % 86400) / 3600);
    $mins = (int) floor(($diff % 3600) / 60);

    if ($days > 0) {
      return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
      return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
  }

  public function render_generic_filter_form(string $tabKey, string $prefix, array $p, bool $showTema = false, array $temaOpts = [], array $filterOptions = [], string $baseTabKey = '', string $lockedStatusLabel = ''): string
  {
    return $this->uiView()->renderGenericFilterForm($tabKey, $prefix, $p, $showTema, $temaOpts, $filterOptions, $baseTabKey, $lockedStatusLabel);
  }

  /**
   * @param array<string,int> $pagination
   */
  public function render_generic_pagination(string $tabKey, array $pagination): string
  {
    return $this->uiView()->renderGenericPagination($tabKey, $pagination);
  }

  /**
   * Query directo sobre wp_jet_cct_revision_preventiva.
   * Enriquece con cotizacion via id_ticket → wp_jet_cct_cotizacion_mantenimiento.
   *
   * @param array<string,mixed> $p
   * @return array<string,mixed>
   */
}
