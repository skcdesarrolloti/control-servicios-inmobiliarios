<?php

namespace SCM\Views;

final class GenericTicketsUiView
{
  /** @var callable */
  private $parseTs;
  /** @var callable */
  private $formatDate;
  /** @var callable */
  private $formatDetailHtml;
  /** @var callable */
  private $buildHistoryItemButtons;
  /** @var callable */
  private $getRecordFieldMap;

  public function __construct(
    callable $parseTs,
    callable $formatDate,
    callable $formatDetailHtml,
    callable $buildHistoryItemButtons,
    callable $getRecordFieldMap
  ) {
    $this->parseTs = $parseTs;
    $this->formatDate = $formatDate;
    $this->formatDetailHtml = $formatDetailHtml;
    $this->buildHistoryItemButtons = $buildHistoryItemButtons;
    $this->getRecordFieldMap = $getRecordFieldMap;
  }

  /** @param array<int,array{url:string,label:string}> $buttons */
  public function renderCaseActionButtons(array $buttons): string
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

  /** @param array<int,array<string,mixed>>|mixed $items */
  public function renderHistorialBlock($items): string
  {
    static $historySeq = 0;
    $historySeq++;
    $historyListId = 'scm-history-list-generic-' . $historySeq;
    $list = is_array($items) ? $items : [];
    $html = '<section class="scm-case-history"><h4>Historial del caso</h4>';
    if (empty($list)) {
      return $html . '<p class="scm-case-history-empty">Sin historial registrado.</p></section>';
    }

    $perPage = 10;
    $totalItems = count($list);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));

    $html .= '<div class="scm-case-history-list" id="' . esc_attr($historyListId) . '" data-current-page="1">';
    $index = 0;
    foreach ($list as $item) {
      if (!is_array($item)) {
        continue;
      }
      $index++;
      $page = (int) ceil($index / $perPage);
      $itemStyle = $page === 1 ? '' : ' style="display:none;"';

      $ts = (int) call_user_func($this->parseTs, $item['_scm_ts'] ?? $item['cct_created'] ?? $item['fecha'] ?? '');
      $date = $this->formatSpanishRecordDate($ts);
      $author = $this->recordAuthor($item);
      $detail = $this->recordDetail($item);
      if ($detail === '') {
        $detail = 'Sin detalle';
      }
      $detailHtml = (string) call_user_func($this->formatDetailHtml, $detail);
      $itemButtons = (array) call_user_func($this->buildHistoryItemButtons, $item);

      $html .= '<article class="scm-case-history-item scm-case-record-card" data-page="' . esc_attr((string) $page) . '"' . $itemStyle . '>';
      $html .= '<div class="scm-case-record-head"><div class="scm-case-record-title"><span class="scm-case-record-user-icon" aria-hidden="true"></span><strong>' . esc_html($author) . '</strong></div>';
      if (!empty($itemButtons)) {
        $html .= $this->renderCaseActionButtons($itemButtons);
      }
      $html .= '</div>';
      $html .= '<div class="scm-case-history-detail scm-case-record-detail"><strong>' . $detailHtml . '</strong></div>';
      $html .= $this->renderHistoryImages($item['imagen'] ?? '');
      $html .= $this->renderHistoryDocuments($item['archivos'] ?? '');
      $html .= '<div class="scm-case-record-date"><span class="scm-case-record-date-icon" aria-hidden="true"></span><strong>' . esc_html($date) . '</strong></div>';
      $html .= '</article>';
    }
    $html .= '</div>';

    if ($totalPages > 1) {
      $firstEnd = min($perPage, $totalItems);
      $html .= '<div class="scm-history-pagination">';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($historyListId) . '" data-dir="prev" disabled>&lsaquo; Anterior</button>';
      $html .= '<span class="scm-history-page-status" data-target="' . esc_attr($historyListId) . '" data-total="' . esc_attr((string) $totalItems) . '" data-per-page="' . esc_attr((string) $perPage) . '">Mostrando 1-' . esc_html((string) $firstEnd) . ' de ' . esc_html((string) $totalItems) . ' | Pagina 1 de ' . esc_html((string) $totalPages) . '</span>';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($historyListId) . '" data-dir="next">Siguiente &rsaquo;</button>';
      $html .= '</div>';
    }

    return $html . '</section>';
  }

  /** @param array<int,array<string,mixed>> $items */
  public function renderRecordSection(string $title, array $items, string $sectionId = '', array $visibleFields = []): string
  {
    static $recordSectionSeq = 0;
    $recordSectionSeq++;
    $listId = 'scm-record-list-' . $recordSectionSeq;
    $sectionAttr = $sectionId !== '' ? ' id="' . esc_attr($sectionId) . '"' : '';
    $showButtons = $this->recordSectionShowsButtons($title);
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . esc_html($title) . '</h4>';
    if (empty($items)) {
      return $html . '<p class="scm-case-history-empty">Sin registros.</p></section>';
    }

    $perPage = 10;
    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));

    $html .= '<div class="scm-case-history-list" id="' . esc_attr($listId) . '" data-current-page="1">';
    $index = 0;
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $index++;
      $page = (int) ceil($index / $perPage);
      $itemStyle = $page === 1 ? '' : ' style="display:none;"';
      $ts = (int) call_user_func($this->parseTs, $item['fecha'] ?? $item['cct_created'] ?? '');
      $date = $this->formatSpanishRecordDate($ts);
      $author = $this->recordAuthor($item);
      $detail = $this->recordDetail($item);
      if ($detail === '') {
        $detail = 'Sin detalle';
      }
      $itemButtons = $showButtons ? (array) call_user_func($this->buildHistoryItemButtons, $item) : [];
      $html .= '<article class="scm-case-history-item scm-case-record-card" data-page="' . esc_attr((string) $page) . '"' . $itemStyle . '>';
      $html .= '<div class="scm-case-record-head"><div class="scm-case-record-title"><span class="scm-case-record-user-icon" aria-hidden="true"></span><strong>' . esc_html($author) . '</strong></div>';
      if (!empty($itemButtons)) {
        $html .= $this->renderCaseActionButtons($itemButtons);
      }
      $html .= '</div>';
      $html .= '<div class="scm-case-history-detail scm-case-record-detail"><strong>' . (string) call_user_func($this->formatDetailHtml, $detail) . '</strong></div>';
      $html .= $this->renderRecordItemFields($item, $visibleFields);
      $html .= $this->renderHistoryImages($item['evidencia'] ?? $item['imagen'] ?? '');
      $html .= $this->renderHistoryDocuments($item['archivos'] ?? '');
      $html .= '<div class="scm-case-record-date"><span class="scm-case-record-date-icon" aria-hidden="true"></span><strong>' . esc_html($date) . '</strong></div>';
      $html .= '</article>';
    }
    $html .= '</div>';

    if ($totalPages > 1) {
      $firstEnd = min($perPage, $totalItems);
      $html .= '<div class="scm-history-pagination">';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($listId) . '" data-dir="prev" disabled>&lsaquo; Anterior</button>';
      $html .= '<span class="scm-history-page-status" data-target="' . esc_attr($listId) . '" data-total="' . esc_attr((string) $totalItems) . '" data-per-page="' . esc_attr((string) $perPage) . '">Mostrando 1-' . esc_html((string) $firstEnd) . ' de ' . esc_html((string) $totalItems) . ' | Pagina 1 de ' . esc_html((string) $totalPages) . '</span>';
      $html .= '<button type="button" class="scm-history-page-btn" data-target="' . esc_attr($listId) . '" data-dir="next">Siguiente &rsaquo;</button>';
      $html .= '</div>';
    }

    return $html . '</section>';
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
      $url = $this->normalizeHistoryAttachmentUrl(trim((string) ($doc['archivo'] ?? '')));
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

  /** @return array<int,string> */
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
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['archivo'] ?? '')) : trim((string) $item);
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

  /** @return array<int,array{nombre_archivo:string,archivo:string}> */
  private function extractHistoryDocuments($raw): array
  {
    $value = trim((string) $raw);
    if ($value === '') {
      return [];
    }
    $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
    if (!is_array($decoded)) {
      $url = $this->normalizeHistoryAttachmentUrl($value);
      return filter_var($url, FILTER_VALIDATE_URL) ? [['nombre_archivo' => '', 'archivo' => $url]] : [];
    }

    $out = [];
    foreach ($decoded as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $url = $this->normalizeHistoryAttachmentUrl(trim((string) ($doc['archivo'] ?? '')));
      if ($url === '') {
        continue;
      }
      $out[] = [
        'nombre_archivo' => trim((string) ($doc['nombre_archivo'] ?? '')),
        'archivo' => $url,
      ];
    }
    return $out;
  }

  /** @param array<string,mixed> $item @param array<string,string> $visibleFields */
  public function renderRecordItemFields(array $item, array $visibleFields): string
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
        $ts = (int) call_user_func($this->parseTs, $text);
        if ($ts > 0) {
          $text = (string) call_user_func($this->formatDate, $ts);
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
    return $lines === '' ? '' : '<div class="scm-case-history-detail">' . $lines . '</div>';
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

  /** @param array<string,mixed> $record */
  public function renderSingleRecordSection(string $title, array $record, string $sectionId = ''): string
  {
    $sectionAttr = $sectionId !== '' ? ' id="' . esc_attr($sectionId) . '"' : '';
    $html = '<section class="scm-case-history"' . $sectionAttr . '><h4>' . esc_html($title) . '</h4>';
    if (empty($record)) {
      return $html . '<p class="scm-case-history-empty">Sin datos.</p></section>';
    }
    $fieldMap = (array) call_user_func($this->getRecordFieldMap, $title);
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
        if ((string) $key === '_ID' || strpos((string) $key, '_scm_') === 0 || isset($printed[(string) $key]) || is_array($value)) {
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
    $itemButtons = (array) call_user_func($this->buildHistoryItemButtons, $record);
    if (!empty($itemButtons)) {
      $html .= $this->renderCaseActionButtons($itemButtons);
    }
    return $html . '</article></section>';
  }

  public function renderGenericFilterForm(string $tabKey, string $prefix, array $p, bool $showTema = false, array $temaOpts = [], array $filterOptions = [], string $baseTabKey = '', string $lockedStatusLabel = ''): string
  {
    $baseTabKey = $baseTabKey !== '' ? $baseTabKey : $tabKey;
    $lockedStatusLabel = trim($lockedStatusLabel);
    $svgSearch = '<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3a6 6 0 100 12A6 6 0 009 3zM1 9a8 8 0 1114.32 4.906l3.387 3.387a1 1 0 01-1.414 1.414l-3.387-3.387A8 8 0 011 9z" clip-rule="evenodd"/></svg>';
    $svgClear = '<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';
    ob_start();
?>
    <div class="scm-filter-card card">
      <h3>Filtros</h3>
      <form id="scm-form-<?php echo esc_attr($tabKey); ?>" autocomplete="off">
        <input type="hidden" id="<?php echo esc_attr($prefix); ?>page" name="<?php echo esc_attr($prefix); ?>page" value="<?php echo esc_attr((string) ($p['fPage'] ?? 1)); ?>">
        <div class="scm-grid">
          <?php if ($showTema && !empty($temaOpts)): ?>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>tema">Categoria</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>tema" name="<?php echo esc_attr($prefix); ?>tema">
                <option value="">Todas</option><?php foreach ($temaOpts as $t): ?><option value="<?php echo esc_attr($t); ?>" <?php selected(strtolower((string) ($p['fTema'] ?? '')), strtolower($t)); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if ($lockedStatusLabel !== ''): ?>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>estado_bloqueado">Vista</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>estado_bloqueado" type="text" value="<?php echo esc_attr($lockedStatusLabel); ?>" readonly></div>
          <?php else: ?>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>estado">Estado</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>estado" name="<?php echo esc_attr($prefix); ?>estado">
                <option value="">Todos</option><?php foreach (['Nuevo', 'En proceso'] as $estOpt): ?><option value="<?php echo esc_attr($estOpt); ?>" <?php selected(strtolower((string) ($p['fEstado'] ?? '')), strtolower($estOpt)); ?>><?php echo esc_html($estOpt); ?></option><?php endforeach; ?>
              </select></div>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>estado_admin">Estado administrativo</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>estado_admin" name="<?php echo esc_attr($prefix); ?>estado_admin">
                <option value="">Todos</option><?php foreach (($filterOptions['estado_admin'] ?? []) as $opt): ?><option value="<?php echo esc_attr((string) $opt); ?>" <?php selected((string) ($p['fEstadoAdmin'] ?? ''), (string) $opt); ?>><?php echo esc_html((string) $opt); ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>origen">Origen</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>origen" name="<?php echo esc_attr($prefix); ?>origen">
              <option value="">Todos</option>
              <option value="web" <?php selected((string) ($p['fOrigen'] ?? ''), 'web'); ?>>Guardian</option>
              <option value="interno" <?php selected((string) ($p['fOrigen'] ?? ''), 'interno'); ?>>No Guardian</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>empleado">Funcionario</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>empleado" name="<?php echo esc_attr($prefix); ?>empleado">
              <option value="">Todos</option><?php foreach (($filterOptions['funcionarios'] ?? []) as $func): $fId = trim((string) ($func['id'] ?? ''));
                                                if ($fId === '') continue; ?><option value="<?php echo esc_attr($fId); ?>" <?php selected((string) ($p['fEmpleado'] ?? ''), $fId); ?>><?php echo esc_html((string) ($func['label'] ?? $fId)); ?></option><?php endforeach; ?>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>inmueble">Inmueble SIMI</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>inmueble" name="<?php echo esc_attr($prefix); ?>inmueble" type="text" value="<?php echo esc_attr((string) ($p['fInmueble'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>contrato">Contrato</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>contrato" name="<?php echo esc_attr($prefix); ?>contrato" type="text" value="<?php echo esc_attr((string) ($p['fContrato'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>asunto">Asunto</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>asunto" name="<?php echo esc_attr($prefix); ?>asunto" type="text" value="<?php echo esc_attr((string) ($p['fAsunto'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>arrendatario">Arrendatario</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>arrendatario" name="<?php echo esc_attr($prefix); ?>arrendatario" type="text" value="<?php echo esc_attr((string) ($p['fArrendatario'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>propietario">Propietario</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>propietario" name="<?php echo esc_attr($prefix); ?>propietario" type="text" value="<?php echo esc_attr((string) ($p['fPropietario'] ?? '')); ?>"></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>barrio">Barrio</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>barrio" name="<?php echo esc_attr($prefix); ?>barrio">
              <option value="">Todos</option><?php foreach (($filterOptions['barrios'] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string) $barrioOpt); ?>" <?php selected((string) ($p['fBarrio'] ?? ''), (string) $barrioOpt); ?>><?php echo esc_html((string) $barrioOpt); ?></option><?php endforeach; ?>
            </select></div>
          <?php if ($baseTabKey === 'entrega'): ?><div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>aseguradora">Numero aseguradora</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>aseguradora" name="<?php echo esc_attr($prefix); ?>aseguradora" type="text" value="<?php echo esc_attr((string) ($p['fAseguradora'] ?? '')); ?>"></div><?php endif; ?>
          <?php if ($baseTabKey === 'entrega'): ?><div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>consultor_entrega">Consultor de entrega</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>consultor_entrega" name="<?php echo esc_attr($prefix); ?>consultor_entrega" type="text" value="<?php echo esc_attr((string) ($p['fConsultorEntrega'] ?? '')); ?>"></div><?php endif; ?>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>fecha">Fecha</label><input class="input input-bordered input-sm scm-input" id="<?php echo esc_attr($prefix); ?>fecha" name="<?php echo esc_attr($prefix); ?>fecha" type="date" value="<?php echo esc_attr((string) ($p['fFecha'] ?? '')); ?>"></div>
          <?php if ($baseTabKey === 'preventiva'): ?><div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>cotizacion">Cotizacion</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>cotizacion" name="<?php echo esc_attr($prefix); ?>cotizacion">
                <option value="">Todas</option>
                <option value="has" <?php selected((string) ($p['fCotizacion'] ?? ''), 'has'); ?>>Con cotizacion</option>
                <option value="none" <?php selected((string) ($p['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizacion</option>
              </select></div>
            <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_estado">Estado cotizacion</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>cotizacion_estado" name="<?php echo esc_attr($prefix); ?>cotizacion_estado">
                <option value="">Todos</option><?php foreach (($filterOptions['cotizacion_estado'] ?? []) as $cotEstadoOpt): ?><option value="<?php echo esc_attr((string) $cotEstadoOpt); ?>" <?php selected((string) ($p['fCotizacionEstado'] ?? ''), (string) $cotEstadoOpt); ?>><?php echo esc_html((string) $cotEstadoOpt); ?></option><?php endforeach; ?>
              </select></div>
            <div class="scm-field scm-cotizacion-dependent" data-cotizacion-dependent-for="<?php echo esc_attr($prefix); ?>cotizacion"><label for="<?php echo esc_attr($prefix); ?>cotizacion_enviada">Fue enviada</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>cotizacion_enviada" name="<?php echo esc_attr($prefix); ?>cotizacion_enviada">
                <option value="">Todas</option>
                <option value="si" <?php selected((string) ($p['fCotizacionEnviada'] ?? ''), 'si'); ?>>Si</option>
                <option value="no" <?php selected((string) ($p['fCotizacionEnviada'] ?? ''), 'no'); ?>>No</option>
              </select></div>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>perturbacion">Perturbacion</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>perturbacion" name="<?php echo esc_attr($prefix); ?>perturbacion">
                <option value="">Todas</option>
                <option value="has" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'has'); ?>>Con perturbacion</option>
                <option value="none" <?php selected(strtolower((string) ($p['fPerturbacion'] ?? '')), 'none'); ?>>Sin perturbacion</option>
              </select></div>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>revision">Revision</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>revision" name="<?php echo esc_attr($prefix); ?>revision">
                <option value="">Todas</option>
                <option value="has" <?php selected((string) ($p['fRevision'] ?? ''), 'has'); ?>>Con revision</option>
                <option value="none" <?php selected((string) ($p['fRevision'] ?? ''), 'none'); ?>>Sin revision</option>
              </select></div>
            <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>danos">Da&ntilde;os</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>danos" name="<?php echo esc_attr($prefix); ?>danos">
                <option value="">Todos</option>
                <option value="has" <?php selected((string) ($p['fDanos'] ?? ''), 'has'); ?>>Con da&ntilde;os</option>
                <option value="none" <?php selected((string) ($p['fDanos'] ?? ''), 'none'); ?>>Sin da&ntilde;os</option>
              </select></div><?php endif; ?>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>atraso">Atraso desde creacion</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>atraso" name="<?php echo esc_attr($prefix); ?>atraso">
              <option value="">Todos</option>
              <option value="3" <?php selected((string) ($p['fAtraso'] ?? ''), '3'); ?>>+3 dias</option>
              <option value="5" <?php selected((string) ($p['fAtraso'] ?? ''), '5'); ?>>+5 dias</option>
              <option value="10" <?php selected((string) ($p['fAtraso'] ?? ''), '10'); ?>>+10 dias</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>sin_actualizar">Sin actualizar desde gestion</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>sin_actualizar" name="<?php echo esc_attr($prefix); ?>sin_actualizar">
              <option value="">Todos</option>
              <option value="1" <?php selected((string) ($p['fSinActualizar'] ?? ''), '1'); ?>>+1 dia</option>
              <option value="3" <?php selected((string) ($p['fSinActualizar'] ?? ''), '3'); ?>>+3 dias</option>
              <option value="7" <?php selected((string) ($p['fSinActualizar'] ?? ''), '7'); ?>>+7 dias</option>
            </select></div>
          <div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>tuvo_seguimiento">Tuvo seguimiento</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>tuvo_seguimiento" name="<?php echo esc_attr($prefix); ?>tuvo_seguimiento">
              <option value="">Todos</option>
              <option value="Si" <?php selected((string) ($p['fTuvoSeguimiento'] ?? ''), 'Si'); ?>>Si</option>
              <option value="No" <?php selected((string) ($p['fTuvoSeguimiento'] ?? ''), 'No'); ?>>No</option>
            </select></div>
          <?php if ($baseTabKey === 'preventiva'): ?><div class="scm-field"><label for="<?php echo esc_attr($prefix); ?>magnitud_caso">Magnitud caso</label><select class="select select-bordered select-sm scm-select" id="<?php echo esc_attr($prefix); ?>magnitud_caso" name="<?php echo esc_attr($prefix); ?>magnitud_caso">
                <option value="">Todas las magnitudes</option>
                <option value="critico" <?php selected((string) ($p['fMagnitudCaso'] ?? ''), 'critico'); ?>>Critico</option>
                <option value="alto" <?php selected((string) ($p['fMagnitudCaso'] ?? ''), 'alto'); ?>>Alto</option>
                <option value="medio" <?php selected((string) ($p['fMagnitudCaso'] ?? ''), 'medio'); ?>>Medio</option>
                <option value="bajo" <?php selected((string) ($p['fMagnitudCaso'] ?? ''), 'bajo'); ?>>Bajo</option>
              </select></div><?php endif; ?>
        </div>
        <div class="scm-actions">
          <button class="scm-btn-primary btn btn-primary" type="submit"><?php echo $svgSearch; ?> Filtrar</button>
          <?php if ($baseTabKey === 'preventiva'): ?><button class="scm-btn-secondary btn btn-outline scm-classify-magnitude" type="button" data-tab-key="<?php echo esc_attr($tabKey); ?>" data-revision-type="preventiva">Calcular magnitud da&ntilde;o</button><?php endif; ?>
          <button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear-<?php echo esc_attr($tabKey); ?>"><?php echo $svgClear; ?> Limpiar</button>
          <span class="scm-spinner" id="scm-spinner-<?php echo esc_attr($tabKey); ?>"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,int> $pagination */
  public function renderGenericPagination(string $tabKey, array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0) {
      return '';
    }
    $html = '<div class="scm-pagination-card card">';
    $html .= '<div class="scm-pagination-summary">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div>';
    $html .= '<div class="scm-pagination-controls">';
    $html .= $this->renderPageBtn($tabKey, 1, '&laquo;', $page <= 1);
    $html .= $this->renderPageBtn($tabKey, $page - 1, '&lsaquo;', $page <= 1);
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
      $html .= $this->renderPageBtn($tabKey, $i, (string) $i, false, $i === $page);
    }
    $html .= $this->renderPageBtn($tabKey, $page + 1, '&rsaquo;', $page >= $totalPages);
    $html .= $this->renderPageBtn($tabKey, $totalPages, '&raquo;', $page >= $totalPages);
    return $html . '</div></div>';
  }

  private function renderPageBtn(string $tabKey, int $page, string $label, bool $disabled = false, bool $active = false): string
  {
    $classes = ['scm-page-btn', 'btn', 'btn-sm', 'scm-page-btn-generic'];
    if ($active) {
      $classes[] = 'btn-primary';
      $classes[] = 'is-active';
    } else {
      $classes[] = 'btn-outline';
    }
    $attrs = ' type="button" class="' . esc_attr(implode(' ', $classes)) . '"';
    $attrs .= ' data-tab="' . esc_attr($tabKey) . '"';
    $attrs .= ' data-page="' . esc_attr((string) max(1, $page)) . '"';
    if ($disabled) {
      $attrs .= ' disabled';
    }
    return '<button' . $attrs . '>' . wp_kses_post($label) . '</button>';
  }
}
