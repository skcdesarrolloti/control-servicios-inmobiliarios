<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Support\DateFormatter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimestampParser;
use SCM\Timeline\Definitions\ServiciosInmobiliariosTimelineDefinition;
use SCM\Timeline\TimelineEngine;

trait HistoryPresentationConcern
{
  private function renderHistorialBlock(array $items): string
  {
    $this->historySeq++;
    $historyListId = 'scm-history-list-mant-' . $this->historySeq;
    $html = '<section class="scm-case-history">';
    $html .= '<h4>Historial del caso</h4>';

    if (empty($items)) {
      $html .= '<p class="scm-case-history-empty">Sin historial registrado.</p>';
      $html .= '</section>';
      return $html;
    }

    $perPage = 10;
    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));

    $html .= '<div class="scm-case-history-list" id="' . esc_attr($historyListId) . '" data-current-page="1">';
    $index = 0;
    foreach ($items as $item) {
      $index++;
      $page = (int) ceil($index / $perPage);
      $itemStyle = $page === 1 ? '' : ' style="display:none;"';

      $ts = $this->parser->parse($item['_scm_ts'] ?? $item['cct_created'] ?? $item['fecha'] ?? '');
      $date = $ts > 0 ? $this->dateFormatter->formatDateTime($ts) : '-';
      $author = trim((string) ($item['nombre'] ?? ''));
      if ($author === '') {
        $authorId = trim((string) ($item['id_empleado'] ?? $item['cct_author_id'] ?? ''));
        $author = $authorId !== '' ? ('Usuario #' . $authorId) : 'Sin autor';
      }
      $detail = trim((string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? ''));
      if ($detail === '') {
        $detail = 'Sin detalle';
      }
      $detailHtml = $this->formatHistoryDetailHtml($detail);
      $itemButtons = $this->buildHistoryItemButtons($item);

      $html .= '<article class="scm-case-history-item" data-page="' . esc_attr((string) $page) . '"' . $itemStyle . '>';
      $html .= '<div class="scm-case-history-meta"><strong>' . esc_html($author) . '</strong><span>' . esc_html($date) . '</span></div>';
      $html .= '<div class="scm-case-history-detail">' . $detailHtml . '</div>';
      $html .= $this->renderHistoryImages($item['imagen'] ?? '');
      $html .= $this->renderHistoryDocuments($item['archivos'] ?? '');
      if (!empty($itemButtons)) {
        $html .= $this->renderCaseActionButtons($itemButtons);
      }
      $html .= '</article>';
    }
    $html .= '</div>';

    if ($totalPages > 1) {
      $html .= '<div class="scm-history-pagination">';
      $firstEnd = min($perPage, $totalItems);
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($historyListId) . '" data-dir="prev" disabled>&lsaquo; Anterior</button>';
      $html .= '<span class="scm-history-page-status" data-target="' . esc_attr($historyListId) . '" data-total="' . esc_attr((string) $totalItems) . '" data-per-page="' . esc_attr((string) $perPage) . '">Mostrando 1-' . esc_html((string) $firstEnd) . ' de ' . esc_html((string) $totalItems) . ' | Pagina 1 de ' . esc_html((string) $totalPages) . '</span>';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($historyListId) . '" data-dir="next">Siguiente &rsaquo;</button>';
      $html .= '</div>';
    }

    $html .= '</section>';

    return $html;
  }

  /**
   * @param array<int,array<string,mixed>> $items
   * @param array<string,string> $visibleFields
   */
  private function renderRecordSection(string $title, array $items, string $sectionId = '', array $visibleFields = []): string
  {
    $sectionAttr = $sectionId !== '' ? ' id="' . esc_attr($sectionId) . '"' : '';
    $showButtons = $this->recordSectionShowsButtons($title);
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . esc_html($title) . '</h4>';
    if (empty($items)) {
      $html .= '<p class="scm-case-history-empty">Sin registros.</p></section>';
      return $html;
    }

    $html .= '<div class="scm-case-history-list">';
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $ts = $this->parser->parse($item['fecha'] ?? $item['cct_created'] ?? '');
      $date = $this->formatSpanishRecordDate($ts);
      $author = $this->recordAuthor($item);
      $detail = $this->recordDetail($item);
      if ($detail === '') {
        $detail = 'Sin detalle';
      }
      $itemButtons = $showButtons ? $this->buildHistoryItemButtons($item) : [];
      $html .= '<article class="scm-case-history-item scm-case-record-card">';
      $html .= '<div class="scm-case-record-head"><div class="scm-case-record-title"><span class="scm-case-record-user-icon" aria-hidden="true"></span><strong>' . esc_html($author) . '</strong></div>';
      if (!empty($itemButtons)) {
        $html .= $this->renderCaseActionButtons($itemButtons);
      }
      $html .= '</div>';
      $html .= '<div class="scm-case-history-detail scm-case-record-detail">' . $this->formatHistoryDetailHtml($detail) . '</div>';
      $html .= $this->renderRecordItemFields($item, $visibleFields);
      $html .= $this->renderHistoryImages($item['evidencia'] ?? $item['imagen'] ?? '');
      $html .= $this->renderHistoryDocuments($item['archivos'] ?? '');
      $html .= '<div class="scm-case-record-date"><span class="scm-case-record-date-icon" aria-hidden="true"></span><strong>' . esc_html($date) . '</strong></div>';
      $html .= '</article>';
    }
    $html .= '</div></section>';
    return $html;
  }

  private function recordSectionShowsButtons(string $title): bool
  {
    return false;
  }

  private function renderHistoryImages($raw): string
  {
    $urls = $this->extractAttachmentUrls($raw);
    if (empty($urls)) {
      return '';
    }

    $html = '<div class="scm-case-history-img">';
    foreach ($urls as $url) {
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . esc_url($url) . '" alt="Imagen adjunta" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:4px;margin-top:6px;">'
        . '</a>';
    }
    return $html . '</div>';
  }

  private function renderHistoryDocuments($raw): string
  {
    $docs = $this->extractHistoryDocuments($raw);
    if (empty($docs)) {
      return '';
    }

    $html = '<div class="scm-case-history-docs">';
    foreach ($docs as $doc) {
      $label = trim((string) ($doc['nombre_archivo'] ?? ''));
      $url = $this->normalizeHistoryAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      if ($label === '') {
        $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
      }
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="scm-case-action-btn">' . esc_html($label) . '</a>';
    }
    return $html . '</div>';
  }

  /**
   * @return array<int,string>
   */
  private function extractAttachmentUrls($raw): array
  {
    if (is_array($raw)) {
      $items = $raw;
    } else {
      $value = trim((string) $raw);
      if ($value === '') {
        return [];
      }
      $items = [$value];
      if (preg_match('/^[aObis]:/', $value)) {
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($decoded)) {
          $items = $decoded;
        }
      }
    }

    $out = [];
    foreach ($items as $item) {
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['archivo'] ?? $item['media_archivo'] ?? '')) : trim((string) $item);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = $this->normalizeHistoryAttachmentUrl($url);
      }
    }
    return array_values(array_unique($out));
  }

  private function normalizeHistoryAttachmentUrl(string $url): string
  {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
      return $url;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '' || stripos($path, '/uploads/') === false) {
      return $url;
    }

    $fileName = basename($path);
    if (!$this->isSafeLegacyAttachmentName($fileName)) {
      return $url;
    }

    return rtrim((string) SCM_BASE_URL, '/') . '/legacy-file.php?n=' . rawurlencode($fileName);
  }

  private function isSafeLegacyAttachmentName(string $fileName): bool
  {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName)) {
      return false;
    }

    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], true);
  }

  /**
   * @return array<int,array{nombre_archivo:string,archivo:string,media_archivo:string}>
   */
  private function extractHistoryDocuments($raw): array
  {
    $value = trim((string) $raw);
    if ($value === '') {
      return [];
    }
    $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
    if (!is_array($decoded)) {
      $url = $this->normalizeHistoryAttachmentUrl($value);
      return filter_var($url, FILTER_VALIDATE_URL) ? [['nombre_archivo' => '', 'media_archivo' => $url, 'archivo' => $url]] : [];
    }

    $out = [];
    foreach ($decoded as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $url = $this->normalizeHistoryAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
      if ($url === '') {
        continue;
      }
      $out[] = [
        'nombre_archivo' => trim((string) ($doc['nombre_archivo'] ?? '')),
        'media_archivo' => $url,
        'archivo' => $url,
      ];
    }
    return $out;
  }

  /**
   * @param array<string,mixed> $record
   */
  private function renderSingleRecordSection(string $title, array $record, string $sectionId = ''): string
  {
    $sectionAttr = $sectionId !== '' ? ' id="' . esc_attr($sectionId) . '"' : '';
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . esc_html($title) . '</h4>';
    if (empty($record)) {
      $html .= '<p class="scm-case-history-empty">Sin datos.</p></section>';
      return $html;
    }
    $fieldMap = $this->getRecordFieldMap($title);
    $details = '';
    $printed = [];

    foreach ($fieldMap as $key => $label) {
      if (!array_key_exists($key, $record)) {
        continue;
      }
      $raw = $record[$key];
      if (is_array($raw)) {
        continue;
      }
      $text = trim((string) $raw);
      if ($text === '') {
        continue;
      }
      $printed[$key] = true;
      $details .= '<p><strong>' . esc_html((string) $label) . ':</strong> ' . esc_html($text) . '</p>';
    }

    if ($details === '') {
      foreach ($record as $key => $value) {
        if ((string) $key === '_ID' || strpos((string) $key, '_scm_') === 0) {
          continue;
        }
        if (isset($printed[(string) $key]) || is_array($value)) {
          continue;
        }
        $text = trim((string) $value);
        if ($text === '') {
          continue;
        }
        $details .= '<p><strong>' . esc_html((string) $key) . ':</strong> ' . esc_html($text) . '</p>';
      }
    }

    $html .= '<article class="scm-case-history-item"><div class="scm-case-history-detail">' . $details . '</div>';
    $baseButtons = $this->buildHistoryItemButtons($record);
    if (in_array(strtolower(trim($title)), ['contrato', 'inmueble'], true)) {
      $baseButtons = $this->withoutInmuebleWebButtons($baseButtons);
    }
    $extraButtons = $this->singleRecordExtraButtons($title, $record);
    $itemButtons = strtolower(trim($title)) === 'inmueble'
      ? $this->mergeCaseActionButtons($extraButtons, $baseButtons)
      : $this->mergeCaseActionButtons($baseButtons, $extraButtons);
    if (!empty($itemButtons)) {
      $html .= $this->renderCaseActionButtons($itemButtons);
    }
    $html .= '</article></section>';
    return $html;
  }

  /**
   * @param array<string,mixed> $record
   * @return array<int,array{url:string,label:string}>
   */
  private function singleRecordExtraButtons(string $title, array $record): array
  {
    $key = strtolower(trim($title));
    $buttons = [];

    if (in_array($key, ['contrato', 'inmueble'], true)) {
      $webId = $this->firstNonEmptyRecordValue($record, ['codigo_inmueble_web', 'codigo', 'id_inmueble']);
      if ($webId !== '') {
        $buttons[] = [
          'url' => 'https://sucasainmobiliaria.com.co/inmuebles/inmueble/' . rawurlencode($webId),
          'label' => 'Ver inmueble en web',
        ];
      }
    }

    return $buttons;
  }

  /**
   * @param array<string,mixed> $record
   * @param array<int,string> $keys
   */
  private function firstNonEmptyRecordValue(array $record, array $keys): string
  {
    foreach ($keys as $key) {
      $value = trim((string) ($record[$key] ?? ''));
      if ($value !== '' && $value !== '-' && $value !== '0') {
        return $value;
      }
    }
    return '';
  }

  /** @return array<string,string> */
  private function getRecordFieldMap(string $title): array
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
        'id_contrato_mandato' => 'Contrato de mandato',
        'id_revision_preventiva' => 'Revision preventiva',
        'id_revision_entrega' => 'Revision de entrega',
        'id_revision_recibo' => 'Revision de recibo',
        'id_revision_sp' => 'Revision de servicios publicos',
        'id_revision_servicios_publicos' => 'Revision de servicios publicos',
        'id_cierre' => 'Hoja de cierre',
        'id_hoja_cierre' => 'Hoja de cierre',
        'registro_fotografico' => 'Registro fotografico',
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
        'codigo_inmueble_web' => 'Codigo inmueble web',
        'id_contrato_mandato' => 'Contrato de mandato',
        'id_hoja_cierre' => 'Hoja de cierre',
        'id_contrato_arrendamiento' => 'Contrato de arrendamiento',
        'ubicacion_google_maps' => 'Google Maps',
        'ubicacion_openstreetmap' => 'OpenStreetMap',
      ];
    }
    return [];
  }

  /**
   * @param array<string,mixed> $item
   * @param array<string,string> $visibleFields
   */
  private function renderRecordItemFields(array $item, array $visibleFields): string
  {
    if (empty($visibleFields)) {
      return '';
    }

    $lines = '';
    foreach ($visibleFields as $field => $label) {
      $raw = $item[$field] ?? '';
      if (is_array($raw)) {
        continue;
      }

      $text = trim((string) $raw);
      if ($text === '') {
        continue;
      }

      if ($field === 'fecha' || $field === 'cct_created' || $field === 'cct_modified') {
        $ts = $this->parser->parse($text);
        if ($ts > 0) {
          $text = $this->dateFormatter->formatDateTime($ts);
        }
      }

      if ($field === 'evidencia' || $field === 'imagen') {
        $url = $this->normalizeHistoryAttachmentUrl($text);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
          $lines .= '<p><strong>' . esc_html((string) $label) . ':</strong></p>'
            . '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
            . '<img src="' . esc_url($url) . '" alt="' . esc_attr((string) $label) . '" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:4px;margin-top:4px;">'
            . '</a></p>';
          continue;
        }
      }

      $lines .= '<p><strong>' . esc_html((string) $label) . ':</strong> ' . esc_html($text) . '</p>';
    }

    if ($lines === '') {
      return '';
    }

    return '<div class="scm-case-history-detail">' . $lines . '</div>';
  }

  /** @param array<string,mixed> $item */
  private function recordAuthor(array $item): string
  {
    $author = trim((string) ($item['nombre'] ?? $item['funcionario'] ?? ''));
    if ($author !== '') {
      return $author;
    }
    $authorId = trim((string) ($item['id_empleado'] ?? $item['cct_author_id'] ?? ''));
    return $authorId !== '' ? ('Usuario #' . $authorId) : 'Registro';
  }

  /** @param array<string,mixed> $item */
  private function recordDetail(array $item): string
  {
    $type = trim((string) ($item['tipo_reporte'] ?? ''));
    $detail = trim((string) ($item['observacion'] ?? $item['respuesta'] ?? $item['descripcion'] ?? ''));
    if ($type !== '' && stripos($detail, $type) === false) {
      return '<strong>' . esc_html($type) . ':</strong> ' . esc_html($detail !== '' ? $detail : 'Sin detalle');
    }
    return $detail;
  }

  private function formatSpanishRecordDate(int $ts): string
  {
    if ($ts <= 0) {
      return '-';
    }
    $days = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $days[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . '-' . $months[(int) date('n', $ts)] . '-' . date('Y', $ts);
  }

  /** @return array<int,array<string,mixed>> */
  private function normalizeHistorialItems($raw): array
  {
    if (!is_array($raw)) {
      return [];
    }

    $items = [];
    foreach ($raw as $item) {
      if (is_array($item)) {
        $items[] = $item;
      }
    }

    return $items;
  }

  private function formatHistoryDetailHtml(string $raw): string
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
  private function renderCaseActionButtons(array $buttons): string
  {
    if (empty($buttons)) {
      return '';
    }

    $html = '<div class="scm-case-actions">';
    foreach ($buttons as $btn) {
      $html .= '<a class="scm-case-action-btn" href="' . esc_url((string) $btn['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) $btn['label']) . '</a>';
    }
    $html .= '</div>';
    return $html;
  }

  /**
   * @param array<int,array<string,mixed>> $buttons
   * @return array<int,array<string,mixed>>
   */
  private function withoutInmuebleWebButtons(array $buttons): array
  {
    return array_values(array_filter($buttons, static function ($button): bool {
      $label = strtolower(trim((string) ($button['label'] ?? '')));
      $url = strtolower(trim((string) ($button['url'] ?? '')));
      if (in_array($label, ['ver inmueble', 'ver inmueble en web'], true)) {
        return false;
      }
      return strpos($url, 'sucasainmobiliaria.com.co/inmueble/') === false
        && strpos($url, 'sucasainmobiliaria.com.co/inmuebles/inmueble/') === false;
    }));
  }

  /**
   * @param array<int,array<string,mixed>> $primary
   * @param array<int,array<string,mixed>> $extra
   * @return array<int,array{url:string,label:string}>
   */
  private function mergeCaseActionButtons(array $primary, array $extra): array
  {
    $out = [];
    $seenUrls = [];
    $seenPairs = [];
    foreach (array_merge($primary, $extra) as $button) {
      $url = trim((string) ($button['url'] ?? ''));
      $label = trim((string) ($button['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      $urlKey = strtolower($url);
      $pairKey = strtolower($label . '|' . $url);
      if (isset($seenUrls[$urlKey]) || isset($seenPairs[$pairKey])) {
        continue;
      }
      $seenUrls[$urlKey] = true;
      $seenPairs[$pairKey] = true;
      $out[] = ['url' => esc_url_raw($url), 'label' => $label];
    }
    return $out;
  }

  /**
   * @param array<int,array<string,mixed>> $historialItems
   * @return array<int,array{url:string,label:string}>
   */
  private function collectCaseActionButtons(string $ticketUrl, string $prevUrl, string $corrUrl, string $cotzUrl, array $historialItems): array
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

    foreach ($historialItems as $item) {
      if (is_array($item)) {
        foreach ($this->extractHistoryIdButtons($item) as $btnFromId) {
          $buttons[] = $btnFromId;
        }
      }
      $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
      if ($raw === '') {
        continue;
      }
      foreach ($this->extractLinksFromHtml($raw) as $link) {
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
  private function extractHistoryIdButtons(array $item): array
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
  private function buildHistoryItemButtons(array $item): array
  {
    $buttons = $this->extractHistoryIdButtons($item);
    $raw = (string) ($item['respuesta'] ?? $item['observacion'] ?? $item['descripcion'] ?? '');
    if ($raw !== '') {
      foreach ($this->extractLinksFromHtml($raw) as $link) {
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
  private function extractLinksFromHtml(string $raw): array
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
}
