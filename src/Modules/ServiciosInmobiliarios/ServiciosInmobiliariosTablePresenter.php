<?php


namespace SCM\Modules\ServiciosInmobiliarios;

use SCM\Support\DateFormatter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimestampParser;
use SCM\Timeline\Definitions\ServiciosInmobiliariosTimelineDefinition;
use SCM\Timeline\TimelineEngine;

final class ServiciosInmobiliariosTablePresenter
{
  private DateFormatter $dateFormatter;
  private TimestampParser $parser;
  private TimelineEngine $timelineEngine;
  /** @var callable */
  private $seguimientoFormRenderer;
  private int $historySeq = 0;

  public function __construct(DateFormatter $dateFormatter, TimestampParser $parser, TimelineEngine $timelineEngine, callable $seguimientoFormRenderer)
  {
    $this->dateFormatter = $dateFormatter;
    $this->parser = $parser;
    $this->timelineEngine = $timelineEngine;
    $this->seguimientoFormRenderer = $seguimientoFormRenderer;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @param array<string,string> $config
   */
  public function renderTbody(array $rows, array $config, bool $canSeguimiento, string $statusBucket = ''): string
  {
    if (empty($rows)) {
      return '<div class="scm-empty scm-empty-cards">No hay tickets con los filtros actuales.</div>';
    }

    $html = '';
    $definitions = ServiciosInmobiliariosTimelineDefinition::definitions();
    $ticketBaseUrl = trim((string) ($config['ticket_url'] ?? ''));
    $preventivaBaseUrl = trim((string) ($config['preventiva_url'] ?? ''));
    $correctivaBaseUrl = trim((string) ($config['correctiva_url'] ?? ''));
    $cotizacionBaseUrl = trim((string) ($config['cotizacion_url'] ?? ''));
    $actaBaseUrl = trim((string) ($config['acta_url'] ?? ''));
    $statusBucket = in_array($statusBucket, ['postergados', 'cerrados'], true) ? $statusBucket : '';

    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      $idTicketRaw = trim((string) ($row['id_ticket'] ?? ''));
      $idTicket = $idTicketRaw !== '' ? $idTicketRaw : (string) $ticketPk;
      $asunto = trim((string) ($row['asunto'] ?? ''));
      $descripcionRaw = trim((string) ($row['descripcion'] ?? ''));
      $tema = trim((string) ($row['tema_ayuda'] ?? ''));
      $estado = trim((string) ($row['estado'] ?? ''));
      $estadoAdmin = trim((string) ($row['estado_administrativo'] ?? $row['estado_admin'] ?? ''));
      $prioridad = trim((string) ($row['prioridad'] ?? ''));
      $magnitudCaso = trim((string) ($row['magnitud_caso'] ?? ''));
      $perturbacionRaw = trim((string) ($row['perturbacion'] ?? $row['_scm_cot_perturbacion'] ?? ''));
      $justificacionPerturbacionRaw = trim((string) ($row['justificacion_perturbacion'] ?? $row['_scm_cot_justificacion_perturbacion'] ?? ''));
      $valorBonificacionRaw = trim((string) ($row['valor_bonificacion'] ?? $row['_scm_cot_valor_bonificacion'] ?? ''));
      $areaAfectadaRaw = trim((string) ($row['area_afectada'] ?? $row['_scm_cot_area_afectada'] ?? ''));
      $resumenCalculoPerturbacionRaw = trim((string) ($row['resumen_calculo_perturbacion'] ?? $row['_scm_cot_resumen_calculo_perturbacion'] ?? ''));
      $cotEstadoRaw = trim((string) ($row['_scm_cot_estado'] ?? $row['estado_cotizacion_mantenimiento'] ?? ''));
      $areaAfectadaLabel = $areaAfectadaRaw !== '' ? $areaAfectadaRaw : '-';
      if ($areaAfectadaRaw !== '' && !preg_match('/\bm2\b/i', $areaAfectadaRaw)) {
        $areaAfectadaLabel = $areaAfectadaRaw . ' m2';
      }
      $contrato = trim((string) ($row['contrato'] ?? ''));
      $inmueble = trim((string) ($row['inmueble'] ?? ''));
      $barrio = trim((string) ($row['barrio'] ?? ''));
      $direccion = trim((string) ($row['direccion'] ?? ''));
      $propietario = trim((string) ($row['propietario'] ?? ''));
      $correoPropietario = trim((string) ($row['correo_propietario'] ?? ''));
      $celularPropietario = trim((string) ($row['celular_propietario'] ?? ''));
      $indicativoPropietario = trim((string) ($row['indicativo_propietario'] ?? $row['indicativo'] ?? ''));
      $arrendatario = trim((string) ($row['arrendatario'] ?? ''));
      $correoArrendatario = trim((string) ($row['correo_arrendatario'] ?? ''));
      $celularArrendatario = trim((string) ($row['celular_arrendatario'] ?? ''));
      $indicativoArrendatario = trim((string) ($row['indicativo_arrendatario'] ?? $row['indicativo'] ?? ''));

      $rawCreated = ($row['cct_created'] ?? '') !== '' ? $row['cct_created'] : ($row['fecha'] ?? null);
      $createdTs = $this->parser->parse($rawCreated);
      $updatedTs = $this->parser->parse($row['fecha_actualizacion'] ?? null);

      $idCotz = trim((string) ($row['id_cotizacion_mantenimiento'] ?? ''));
      $tieneCotz = $idCotz !== '';

      $idPrev = trim((string) ($row['id_revision_preventiva'] ?? ''));
      $tienePrev = $idPrev !== '';

      $idCorr = trim((string) ($row['id_revision_correctiva'] ?? ''));
      $tieneCorr = $idCorr !== '';

      $steps = $this->timelineEngine->build($row, $definitions);

      $ticketIdUrl = $this->firstIdValue($idTicket);
      $ticketUrl = ($ticketBaseUrl !== '' && $ticketIdUrl !== '') ? esc_url($ticketBaseUrl . rawurlencode($ticketIdUrl)) : '';

      $idPrevUrl = $this->firstIdValue($idPrev);
      $idCorrUrl = $this->firstIdValue($idCorr);
      $idCotzUrl = $this->firstIdValue($idCotz);
      $idActaUrl = $this->firstIdValue(trim((string) ($row['_scm_cot_id_acta'] ?? '')));
      $calendarEventId = $this->firstIdValue(trim((string) ($row['_scm_cita_cal_id'] ?? '')));

      $prevUrl = ($tienePrev && $preventivaBaseUrl !== '' && $idPrevUrl !== '') ? esc_url($preventivaBaseUrl . rawurlencode($idPrevUrl)) : '';
      $corrUrl = ($tieneCorr && $correctivaBaseUrl !== '' && $idCorrUrl !== '') ? esc_url($correctivaBaseUrl . rawurlencode($idCorrUrl)) : '';
      $actaUrl = ($actaBaseUrl !== '' && $idActaUrl !== '') ? esc_url($actaBaseUrl . rawurlencode($idActaUrl)) : '';
      $cotzUrl = ($tieneCotz && $cotizacionBaseUrl !== '' && $idCotzUrl !== '') ? esc_url($cotizacionBaseUrl . rawurlencode($idCotzUrl)) : '';
      $calendarUrl = $calendarEventId !== '' ? esc_url('https://calendar-skc.netlify.app/evento/' . rawurlencode($calendarEventId)) : '';
      $stepLinks = [
        'queja_registrada' => $ticketUrl,
        'cita_agendada' => $calendarUrl !== '' ? $calendarUrl : $ticketUrl,
        'revision_correctiva_registrada' => $corrUrl !== '' ? $corrUrl : ($prevUrl !== '' ? $prevUrl : $ticketUrl),
        'cotizacion_registrada' => $cotzUrl !== '' ? $cotzUrl : $ticketUrl,
        'cotizacion_enviada' => $cotzUrl !== '' ? $cotzUrl : $ticketUrl,
        'respuesta_cotizacion' => $cotzUrl !== '' ? $cotzUrl : $ticketUrl,
        'trabajo_finalizado' => $actaUrl !== '' ? $actaUrl : ($cotzUrl !== '' ? $cotzUrl : $ticketUrl),
      ];
      $timeline = $this->renderTimeline($steps, $stepLinks);

      $employee = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? ''));
      $historialItems = $this->normalizeHistorialItems($row['_scm_historial_items'] ?? []);
      $seguimientosItems = $this->normalizeHistorialItems($row['_scm_seguimientos_ticket'] ?? []);
      $notasItems = $this->normalizeHistorialItems($row['_scm_notas_ticket'] ?? []);
      $historialInmuebleItems = $this->normalizeHistorialItems($row['_scm_historial_inmueble'] ?? []);
      $contratoData = is_array($row['_scm_contrato_data'] ?? null) ? $row['_scm_contrato_data'] : [];
      $inmuebleData = is_array($row['_scm_inmueble_data'] ?? null) ? $row['_scm_inmueble_data'] : [];
      $idInmuebleWeb = trim((string) ($row['id_inmueble'] ?? ($inmuebleData['codigo'] ?? '')));
      $propertyDataId = trim((string) ($inmuebleData['_ID'] ?? $inmuebleData['id_inmueble_data'] ?? ''));
      $propertyGoogleMaps = trim((string) ($inmuebleData['ubicacion_google_maps'] ?? ''));
      $seguimientoFields = [
        'cct_status' => 'Estado',
        'id_ticket' => 'Ticket',
        'id_coordinador' => 'Coordinador',
        'id_empleado' => 'Empleado',
        'cct_author_id' => 'Autor ID',
        'fecha' => 'Fecha',
        'cct_created' => 'Creado',
        'cct_modified' => 'Modificado',
        'evidencia' => 'Evidencia',
      ];
      $notasFields = [
        'cct_status' => 'Estado',
        'id_ticket' => 'Ticket',
        'id_empleado' => 'Empleado',
        'cct_author_id' => 'Autor ID',
        'fecha' => 'Fecha',
        'cct_created' => 'Creado',
        'cct_modified' => 'Modificado',
      ];
      $asuntoLabel = $asunto !== '' ? $asunto : '-';
      $temaLabel = $tema !== '' ? $tema : '-';
      $contractLabel = $contrato !== '' ? ('#' . $contrato) : '-';
      $inmuebleLabel = $inmueble !== '' ? $inmueble : '-';
      $barrioLabel = $barrio !== '' ? $barrio : '-';
      $creado = $createdTs > 0 ? $this->dateFormatter->formatDateTime($createdTs) : '-';

      $estadoBadge = $this->estadoBadge($estado !== '' ? $estado : '-');
      $estadoAdminBadge = $this->estadoBadge($estadoAdmin !== '' ? $estadoAdmin : '-');
      $magnitudCasoBadge = $this->renderMagnitudeBadge($magnitudCaso);
      $perturbacionKey = strtolower($perturbacionRaw);
      $perturbacionNum = is_numeric($perturbacionRaw) ? (float) $perturbacionRaw : 0.0;
      $perturbacionSi = ($perturbacionRaw !== '' && $perturbacionKey !== 'no' && $perturbacionKey !== 'false' && $perturbacionKey !== 'null' && $perturbacionKey !== '0')
        || $perturbacionNum > 0;
      $perturbacionBadge = $perturbacionSi
        ? '<span class="scm-magnitude-badge" style="background:#dc2626;">Con perturbaci&oacute;n</span>'
        : '<span class="scm-magnitude-badge scm-magnitude-empty">Sin perturbaci&oacute;n</span>';
      $tiempoEjecucion = $this->humanDurationSince($createdTs);
      $baseUpdateTs = $updatedTs > 0 ? $updatedTs : $createdTs;
      $tiempoSinActualizar = $this->humanDurationSince($baseUpdateTs);

      $caseSource = '';
      if ($descripcionRaw !== '') {
        $caseSource .= '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">' . $descripcionRaw . '</div></div>';
      }
      $caseSource .= '<div class="scm-modal-timeline-only">';
      $caseSource .= $timeline;
      $caseSource .= '</div>';
      $caseSource .= '<div class="scm-seg-wrap">';
      $caseSource .= (string) call_user_func($this->seguimientoFormRenderer, $ticketPk, $canSeguimiento, $tieneCotz);
      $caseSource .= '</div>';
      $caseSource .= $this->renderHistorialBlock($historialItems);
      $caseSource .= $this->renderRecordSection('Seguimientos realizados', $seguimientosItems, '', ['evidencia' => 'Evidencia']);
      $caseSource .= $this->renderRecordSection('Notas del ticket', $notasItems);
      $caseSource .= '<div class="scm-case-action-buttons">';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
      $caseSource .= '</div>';
      $caseSource .= '<div class="scm-case-hidden-sections" style="display:none;">';
      $caseSource .= $this->renderSingleRecordSection('Contrato', $contratoData, 'scm-sec-contrato');
      $caseSource .= $this->renderSingleRecordSection('Inmueble', $inmuebleData, 'scm-sec-inmueble');
      $caseSource .= $this->renderRecordSection('Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
      $caseSource .= '</div>';

      $html .= '<article class="scm-ticket-card card" data-pk="' . esc_attr((string) $ticketPk) . '">';
      $html .= '<header class="scm-ticket-card-head">';
      $html .= '<span class="scm-ticket-badge badge badge-primary">#' . esc_html($idTicket) . '</span>';
      $html .= '<h3>' . esc_html($temaLabel) . '</h3>';
      $html .= '</header>';
      $html .= '<p class="scm-ticket-card-asunto"><span>Asunto</span><strong>' . esc_html($asuntoLabel) . '</strong></p>';
      $html .= '<p class="scm-ticket-card-contract">Contrato <strong>' . esc_html($contractLabel) . '</strong></p>';
      $html .= '<p class="scm-ticket-card-property">Inmueble <strong>' . esc_html($inmuebleLabel) . '</strong></p>';
      $html .= '<p class="scm-ticket-card-barrio">Barrio <strong>' . esc_html($barrioLabel) . '</strong></p>';
      $html .= '<p class="scm-ticket-card-address">Dirección <strong>' . esc_html($direccion !== '' ? $direccion : '-') . '</strong></p>';
      $html .= '<p class="scm-ticket-card-employee">Asignado a <strong>' . esc_html($employee !== '' ? $employee : '-') . '</strong></p>';
      if ($statusBucket !== 'cerrados') {
        $html .= '<p class="scm-ticket-card-execution">En ejecución <strong>' . esc_html($tiempoEjecucion) . '</strong></p>';
        $html .= '<p class="scm-ticket-card-stale">Sin actualizar <strong>' . esc_html($tiempoSinActualizar) . '</strong></p>';
      }
      $html .= '<div class="scm-ticket-card-states">';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado</span>' . $estadoBadge . '</div>';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado administrativo</span>' . $estadoAdminBadge . '</div>';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Magnitud caso</span>' . $magnitudCasoBadge . '</div>';
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Perturbaci&oacute;n</span>' . $perturbacionBadge . '</div>';
      $html .= '</div>';
      $html .= '<p class="scm-ticket-card-barrio">Area afectada <strong>' . esc_html($areaAfectadaLabel) . '</strong></p>';
      $html .= '<div class="scm-ticket-card-footer">';
      if ($statusBucket !== '') {
        $html .= '<button class="btn btn-outline btn-sm scm-activate-ticket-btn" type="button" data-scm-activate-ticket>Activar ticket</button>';
      }
      $html .= '<button class="scm-btn-case btn btn-primary btn-sm"'
        . ' data-ticket="' . esc_attr($idTicket) . '"'
        . ' data-ticket-pk="' . esc_attr((string) $ticketPk) . '"'
        . ' data-asunto="' . esc_attr($asuntoLabel) . '"'
        . ' data-estado="' . esc_attr($estado !== '' ? $estado : '-') . '"'
        . ' data-admin="' . esc_attr($estadoAdmin !== '' ? $estadoAdmin : '-') . '"'
        . ' data-prioridad="' . esc_attr($prioridad !== '' ? $prioridad : '-') . '"'
        . ' data-magnitud-caso="' . esc_attr($magnitudCaso !== '' ? ucfirst($magnitudCaso) : '-') . '"'
        . ' data-perturbacion="' . esc_attr($perturbacionRaw) . '"'
        . ' data-justificacion-perturbacion="' . esc_attr($justificacionPerturbacionRaw) . '"'
        . ' data-valor-bonificacion="' . esc_attr($valorBonificacionRaw) . '"'
        . ' data-area-afectada="' . esc_attr($areaAfectadaRaw) . '"'
        . ' data-resumen-calculo-perturbacion="' . esc_attr($resumenCalculoPerturbacionRaw) . '"'
        . ' data-ejecucion="' . esc_attr($tiempoEjecucion) . '"'
        . ' data-sin-actualizar="' . esc_attr($tiempoSinActualizar) . '"'
        . ' data-contrato="' . esc_attr($contractLabel) . '"'
        . ' data-inmueble="' . esc_attr($inmuebleLabel) . '"'
        . ' data-id-inmueble-web="' . esc_attr($idInmuebleWeb !== '' ? $idInmuebleWeb : '-') . '"'
        . ' data-id-inmueble-data="' . esc_attr($propertyDataId) . '"'
        . ' data-ubicacion-google-maps="' . esc_attr($propertyGoogleMaps) . '"'
        . ' data-barrio="' . esc_attr($barrioLabel) . '"'
        . ' data-direccion="' . esc_attr($direccion !== '' ? $direccion : '-') . '"'
        . ' data-creado="' . esc_attr($creado) . '"'
        . ' data-empleado="' . esc_attr($employee !== '' ? $employee : '-') . '"'
        . ' data-propietario="' . esc_attr($propietario) . '"'
        . ' data-correo-propietario="' . esc_attr($correoPropietario) . '"'
        . ' data-celular-propietario="' . esc_attr($celularPropietario) . '"'
        . ' data-indicativo-propietario="' . esc_attr($indicativoPropietario) . '"'
        . ' data-arrendatario="' . esc_attr($arrendatario) . '"'
        . ' data-correo-arrendatario="' . esc_attr($correoArrendatario) . '"'
        . ' data-celular-arrendatario="' . esc_attr($celularArrendatario) . '"'
        . ' data-indicativo-arrendatario="' . esc_attr($indicativoArrendatario) . '"'
        . ' data-ticket-url="' . esc_attr($ticketUrl) . '"'
        . ' data-cotizacion-url="' . esc_attr($cotzUrl) . '"'
        . ' data-cot-estado="' . esc_attr($cotEstadoRaw) . '"'
        . ' data-id-revision-correctiva="' . esc_attr($idCorrUrl) . '"'
        . ' data-id-revision-preventiva="' . esc_attr($idPrevUrl) . '"'
        . ($statusBucket !== '' ? ' data-status-bucket="' . esc_attr($statusBucket) . '"' : '')
        . ' onclick="scmOpenCase(this)" type="button">Ver caso</button>';
      $html .= '</div>';
      $html .= '<div class="scm-case-source" aria-hidden="true" style="display:none;">' . $caseSource . '</div>';
      $html .= '</article>';
    }

    return $html;
  }

  private function renderMagnitudeBadge(string $magnitud): string
  {
    $key = strtolower(trim($magnitud));
    if ($key === 'crítico') {
      $key = 'critico';
    }
    $labels = [
      'critico' => 'Cr&iacute;tico',
      'alto' => 'Alto',
      'medio' => 'Medio',
      'bajo' => 'Bajo',
    ];
    if (!isset($labels[$key])) {
      return '<span class="scm-magnitude-badge scm-magnitude-empty">Sin clasificar</span>';
    }
    return '<span class="scm-magnitude-badge scm-magnitude-' . esc_attr($key) . '">' . $labels[$key] . '</span>';
  }
  /**
   * @param array<int,array<string,mixed>> $items
   */
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
      $url = trim((string) ($doc['archivo'] ?? ''));
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
      $url = is_array($item) ? trim((string) ($item['url'] ?? $item['archivo'] ?? '')) : trim((string) $item);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = $url;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * @return array<int,array{nombre_archivo:string,archivo:string}>
   */
  private function extractHistoryDocuments($raw): array
  {
    $value = trim((string) $raw);
    if ($value === '') {
      return [];
    }
    $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
    if (!is_array($decoded)) {
      return filter_var($value, FILTER_VALIDATE_URL) ? [['nombre_archivo' => '', 'archivo' => $value]] : [];
    }

    $out = [];
    foreach ($decoded as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $url = trim((string) ($doc['archivo'] ?? ''));
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
    $itemButtons = $this->buildHistoryItemButtons($record);
    if (!empty($itemButtons)) {
      $html .= $this->renderCaseActionButtons($itemButtons);
    }
    $html .= '</article></section>';
    return $html;
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
        if (filter_var($text, FILTER_VALIDATE_URL)) {
          $lines .= '<p><strong>' . esc_html((string) $label) . ':</strong></p>'
            . '<p><a href="' . esc_url($text) . '" target="_blank" rel="noopener noreferrer">'
            . '<img src="' . esc_url($text) . '" alt="' . esc_attr((string) $label) . '" class="scm-record-img" loading="lazy" style="max-width:100%;max-height:220px;border-radius:4px;margin-top:4px;">'
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
  public function renderPagination(array $pagination, int $rowsOnPage): string
  {
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0) {
      return '';
    }

    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));

    if ($page > $totalPages) {
      $page = $totalPages;
    }

    $html = '<div class="scm-pagination-card card">';
    $html .= '<div class="scm-pagination-summary">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div>';
    $html .= '<div class="scm-pagination-controls">';

    $html .= $this->renderPageButton(1, '&laquo;', $page <= 1);
    $html .= $this->renderPageButton($page - 1, '&lsaquo;', $page <= 1);

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
      $html .= $this->renderPageButton($i, (string) $i, false, $page === $i);
    }

    $html .= $this->renderPageButton($page + 1, '&rsaquo;', $page >= $totalPages);
    $html .= $this->renderPageButton($totalPages, '&raquo;', $page >= $totalPages);
    $html .= '</div>';
    $html .= '</div>';

    return $html;
  }

  private function renderTimeline(array $steps, array $stepLinks = []): string
  {
    $html = '<div class="scm-timeline">';

    $last = count($steps) - 1;
    foreach ($steps as $index => $step) {
      $status = (string) ($step['status'] ?? 'none');
      $ts = (int) ($step['ts'] ?? 0);
      $emptyText = trim((string) ($step['empty_text'] ?? 'Sin dato'));

      if ($status === 'done') {
        $dateHtml = $ts > 0
          ? '<small>' . esc_html($this->dateFormatter->formatDateTime($ts)) . '</small>'
          : '<small>' . esc_html($emptyText) . '</small>';
      } elseif ($status === 'pending') {
        $dateHtml = '<small class="scm-tl-pend">Pendiente</small>';
      } else {
        $dateHtml = '<small class="scm-tl-nohecho">' . esc_html($emptyText) . '</small>';
      }

      $subParts = [];
      $sub = trim((string) ($step['sub'] ?? ''));
      if ($sub !== '') {
        $subParts[] = $sub;
      }

      $elapsedLabel = trim((string) ($step['elapsed_label'] ?? ''));
      if ($elapsedLabel !== '') {
        $from = trim((string) ($step['elapsed_from'] ?? ''));
        if ($from !== '') {
          $subParts[] = 'Tardo ' . $elapsedLabel . ' desde ' . $from;
        } else {
          $subParts[] = 'Tardo ' . $elapsedLabel;
        }
      }

      $pendingLabel = trim((string) ($step['pending_label'] ?? ''));
      if ($pendingLabel !== '' && $status === 'pending') {
        $from = trim((string) ($step['pending_from'] ?? ''));
        if ($from !== '') {
          $subParts[] = 'Lleva ' . $pendingLabel . ' sin hacerse desde ' . $from;
        } else {
          $subParts[] = 'Lleva ' . $pendingLabel . ' sin hacerse';
        }
      }

      $subHtml = '';
      foreach ($subParts as $subPart) {
        $subHtml .= '<small class="scm-tl-sub">' . esc_html($subPart) . '</small>';
      }

      $label = trim((string) ($step['label'] ?? ''));
      $icon = trim((string) ($step['icon'] ?? ''));
      $source = trim((string) ($step['source'] ?? ''));
      $stepKey = trim((string) ($step['key'] ?? ''));
      $stepLink = trim((string) ($stepLinks[$stepKey] ?? ''));
      $iconHtml = '<span class="scm-tl-icon">' . esc_html($icon) . '</span>';
      if ($stepLink !== '') {
        $iconHtml = '<a class="scm-tl-icon scm-tl-icon-link" href="' . esc_url($stepLink) . '" target="_blank" rel="noopener noreferrer" title="Abrir registro relacionado">' . esc_html($icon) . '</a>';
      }

      $html .= '<div class="scm-tl-item scm-tl-' . esc_attr($status) . '" data-source="' . esc_attr($source) . '">';
      $html .= $iconHtml;
      $html .= '<span class="scm-tl-label">' . esc_html($label) . $dateHtml . $subHtml . '</span>';
      $html .= '</div>';

      if ($index < $last) {
        $html .= '<div class="scm-tl-line"></div>';
      }
    }

    $html .= '</div>';
    return $html;
  }

  private function renderPageButton(int $page, string $label, bool $disabled, bool $active = false): string
  {
    $classes = ['scm-page-btn', 'btn', 'btn-sm'];
    if ($active) {
      $classes[] = 'btn-primary';
      $classes[] = 'is-active';
    } else {
      $classes[] = 'btn-outline';
    }

    $attrs = ' type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-page="' . esc_attr((string) max(1, $page)) . '"';
    if ($disabled) {
      $attrs .= ' disabled';
    }

    return '<button' . $attrs . '>' . wp_kses_post($label) . '</button>';
  }

  private function prioridadBadge(string $prioridad): string
  {
    $value = strtolower(trim($prioridad));
    $map = [
      'alta' => ['#dc2626', '#fef2f2', '#fecaca'],
      'media' => ['#b45309', '#fffbeb', '#fde68a'],
      'baja' => ['#15803d', '#f0fdf4', '#bbf7d0'],
    ];

    $style = $map[$value] ?? ['#475569', '#f8fafc', '#e2e8f0'];
    $label = $prioridad !== '' ? $prioridad : '-';

    return '<span style="background:' . $style[1] . ';color:' . $style[0] . ';border:1px solid ' . $style[2] . ';border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap;">' . esc_html($label) . '</span>';
  }

  private function slaBadge(string $slaStatus, ?int $daysOpen): string
  {
    $labelDays = $daysOpen !== null ? ' (' . $daysOpen . 'd)' : '';

    if ($slaStatus === 'vencido') {
      return '<span style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">Vencido' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'en_riesgo') {
      return '<span style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">En riesgo' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'cerrado') {
      return '<span style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">Cerrado' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'en_tiempo') {
      return '<span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">En tiempo' . esc_html($labelDays) . '</span>';
    }

    if ($daysOpen !== null) {
      return '<span style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">' . esc_html((string) $daysOpen) . 'd</span>';
    }

    return '<span style="color:#94a3b8;">-</span>';
  }

  private function estadoBadge(string $estado): string
  {
    $value = strtolower(trim($estado));
    $map = [
      'abierto' => 'badge-warning',
      'pendiente' => 'badge-warning',
      'en proceso' => 'badge-info',
      'en_proceso' => 'badge-info',
      'cerrado' => 'badge-success',
      'resuelto' => 'badge-success',
      'cancelado' => 'badge-neutral',
      'aprobado' => 'badge-accent',
      'rechazado' => 'badge-error',
    ];

    $class = $map[$value] ?? 'badge-ghost';
    $label = $estado !== '' ? $estado : '-';

    return '<span class="badge badge-sm ' . esc_attr($class) . '">'
      . esc_html($label)
      . '</span>';
  }

  private function stageBadge(string $label, string $status, int $stageSeconds, bool $isCompleted): string
  {
    $label = trim($label);
    if ($label === '') {
      $label = '-';
    }

    $statusKey = strtolower(trim($status));
    $class = 'scm-stage-none';

    if ($isCompleted || $statusKey === 'done') {
      $class = 'scm-stage-done';
    } elseif ($statusKey === 'pending') {
      if ($stageSeconds >= 72 * 3600) {
        $class = 'scm-stage-critical';
      } elseif ($stageSeconds >= 24 * 3600) {
        $class = 'scm-stage-risk';
      } else {
        $class = 'scm-stage-pending';
      }
    } elseif ($statusKey === 'none') {
      $class = 'scm-stage-none';
    }

    return '<span class="scm-stage-pill ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
  }

  private function timeBadge(string $label, int $seconds, string $context, bool $isCompleted): string
  {
    $label = trim($label);
    if ($label === '') {
      $label = '-';
    }

    $class = 'scm-time-muted';

    if ($label === '-' || $label === '0s') {
      $class = 'scm-time-muted';
    } elseif (stripos($label, 'completado') !== false) {
      $class = 'scm-time-done';
    } elseif ($context === 'stage') {
      if ($isCompleted) {
        $class = 'scm-time-done';
      } elseif ($seconds >= 72 * 3600) {
        $class = 'scm-time-critical';
      } elseif ($seconds >= 24 * 3600) {
        $class = 'scm-time-risk';
      } else {
        $class = 'scm-time-normal';
      }
    } else {
      if ($isCompleted) {
        $class = 'scm-time-done';
      } elseif ($seconds >= 120 * 3600) {
        $class = 'scm-time-critical';
      } elseif ($seconds >= 72 * 3600) {
        $class = 'scm-time-risk';
      } else {
        $class = 'scm-time-normal';
      }
    }

    return '<span class="scm-time-pill ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
  }

  private function firstIdValue($raw): string
  {
    $ids = $this->splitIds($raw);
    if (!empty($ids)) {
      return trim((string) $ids[0]);
    }

    return trim((string) $raw);
  }

  /** @return array<int,string> */
  private function splitIds($raw): array
  {
    $text = trim((string) $raw);
    if ($text === '') {
      return [];
    }

    $parts = preg_split('/[,\s;|]+/', $text);
    if (!is_array($parts)) {
      return [];
    }

    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id === '') {
        continue;
      }
      $ids[$id] = true;
    }

    return array_keys($ids);
  }

  private function humanDurationSince(int $fromTs): string
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
}
