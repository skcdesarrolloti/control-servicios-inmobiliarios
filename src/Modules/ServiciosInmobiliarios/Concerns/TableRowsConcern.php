<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Support\DateFormatter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimestampParser;
use SCM\Timeline\Definitions\ServiciosInmobiliariosTimelineDefinition;
use SCM\Timeline\TimelineEngine;

trait TableRowsConcern
{
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
      $cotRespuestaEstadoRaw = trim((string) ($row['_scm_cot_respuesta_estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? $row['estado_respuesta'] ?? ''));
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
      $origenLabel = $this->ticketOriginIsGuardian($row) ? 'Guardian' : 'No Guardian';
      $origenBadge = $origenLabel === 'Guardian'
        ? '<span class="scm-origin-badge scm-origin-badge--guardian">Guardian</span>'
        : '<span class="scm-origin-badge scm-origin-badge--internal">No Guardian</span>';

      $rawCreated = ($row['cct_created'] ?? '') !== '' ? $row['cct_created'] : ($row['fecha'] ?? null);
      $createdTs = $this->parser->parse($rawCreated);
      $updatedTs = $this->parser->parse($row['fecha_actualizacion'] ?? null);

      $idCotz = trim((string) ($row['id_cotizacion_mantenimiento'] ?? ''));
      $tieneCotz = $idCotz !== '';
      $cotEstadoParaRespuesta = $cotRespuestaEstadoRaw !== '' ? $cotRespuestaEstadoRaw : $cotEstadoRaw;
      $cotizacionPendienteRespuesta = $tieneCotz && in_array(strtolower($cotEstadoParaRespuesta), ['', 'esperando respuesta'], true);

      $idPrev = trim((string) ($row['id_revision_preventiva'] ?? ''));
      $tienePrev = $idPrev !== '';
      $isPreventivaTicket = $tienePrev || stripos($tema . ' ' . $asunto . ' ' . $descripcionRaw, 'preventiva') !== false;

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
      $employeeId = trim((string) ($row['id_empleado'] ?? ''));
      $historialItems = $this->normalizeHistorialItems($row['_scm_historial_items'] ?? []);
      $seguimientosItems = $this->normalizeHistorialItems($row['_scm_seguimientos_ticket'] ?? []);
      $notasItems = $this->normalizeHistorialItems($row['_scm_notas_ticket'] ?? []);
      $historialInmuebleItems = $this->normalizeHistorialItems($row['_scm_historial_inmueble'] ?? []);
      $preventivaNoAccessCount = $isPreventivaTicket ? $this->countPreventivaNoAccessNotices($row, $historialItems) : 0;
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
      if ($isPreventivaTicket) {
        $caseSource .= $this->renderPreventivaNoAccessSummary($preventivaNoAccessCount);
      }
      $ticketDocumentsHtml = $this->renderTicketRootDocumentsSection($row['archivos'] ?? '', 'scm-sec-documentos');
      $caseSource .= '<div class="scm-modal-timeline-only">';
      $caseSource .= $timeline;
      $caseSource .= '</div>';
      $caseSource .= '<div class="scm-seg-wrap">';
      $caseSource .= (string) call_user_func($this->seguimientoFormRenderer, $ticketPk, $canSeguimiento, $cotizacionPendienteRespuesta);
      $caseSource .= '</div>';
      $caseSource .= $this->renderHistorialBlock($historialItems);
      $caseSource .= $this->renderRecordSection('Seguimientos realizados', $seguimientosItems, '', ['evidencia' => 'Evidencia']);
      $caseSource .= $this->renderRecordSection('Notas del ticket', $notasItems);
      $caseSource .= '<div class="scm-case-action-buttons">';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button>';
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
      if ($ticketDocumentsHtml !== '') {
        $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-documentos">Ver documentos</button>';
      }
      $caseSource .= '</div>';
      $caseSource .= '<div class="scm-case-hidden-sections" style="display:none;">';
      $caseSource .= $this->renderSingleRecordSection('Contrato', $contratoData, 'scm-sec-contrato');
      $caseSource .= $this->renderSingleRecordSection('Inmueble', $inmuebleData, 'scm-sec-inmueble');
      $caseSource .= $this->renderRecordSection('Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
      $caseSource .= $ticketDocumentsHtml;
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
      if ($propietario !== '') {
        $html .= '<p class="scm-ticket-card-owner">Propietario <strong>' . esc_html($propietario) . '</strong></p>';
      }
      if ($arrendatario !== '') {
        $html .= '<p class="scm-ticket-card-tenant">Arrendatario <strong>' . esc_html($arrendatario) . '</strong></p>';
      }
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
      $html .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Origen</span>' . $origenBadge . '</div>';
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
        . ' data-origen="' . esc_attr($origenLabel) . '"'
        . ' data-contrato="' . esc_attr($contractLabel) . '"'
        . ' data-inmueble="' . esc_attr($inmuebleLabel) . '"'
        . ' data-id-inmueble-web="' . esc_attr($idInmuebleWeb !== '' ? $idInmuebleWeb : '-') . '"'
        . ' data-id-inmueble-data="' . esc_attr($propertyDataId) . '"'
        . ' data-ubicacion-google-maps="' . esc_attr($propertyGoogleMaps) . '"'
        . ' data-barrio="' . esc_attr($barrioLabel) . '"'
        . ' data-direccion="' . esc_attr($direccion !== '' ? $direccion : '-') . '"'
        . ' data-creado="' . esc_attr($creado) . '"'
        . ' data-empleado="' . esc_attr($employee !== '' ? $employee : '-') . '"'
        . ' data-empleado-id="' . esc_attr($employeeId) . '"'
        . ' data-propietario="' . esc_attr($propietario) . '"'
        . ' data-correo-propietario="' . esc_attr($correoPropietario) . '"'
        . ' data-celular-propietario="' . esc_attr($celularPropietario) . '"'
        . ' data-indicativo-propietario="' . esc_attr($indicativoPropietario) . '"'
        . ' data-arrendatario="' . esc_attr($arrendatario) . '"'
        . ' data-correo-arrendatario="' . esc_attr($correoArrendatario) . '"'
        . ' data-celular-arrendatario="' . esc_attr($celularArrendatario) . '"'
        . ' data-indicativo-arrendatario="' . esc_attr($indicativoArrendatario) . '"'
        . ' data-ticket-url="' . esc_attr($ticketUrl) . '"'
        . ' data-cotizacion-id="' . esc_attr($idCotzUrl) . '"'
        . ' data-cotizacion-url="' . esc_attr($cotzUrl) . '"'
        . ' data-cot-estado="' . esc_attr($cotEstadoRaw) . '"'
        . ' data-id-revision-correctiva="' . esc_attr($idCorrUrl) . '"'
        . ' data-id-revision-preventiva="' . esc_attr($idPrevUrl) . '"'
        . ' data-preventiva-no-access-count="' . esc_attr((string) $preventivaNoAccessCount) . '"'
        . ($statusBucket !== '' ? ' data-status-bucket="' . esc_attr($statusBucket) . '"' : '')
        . ' onclick="scmOpenCase(this)" type="button">Ver caso</button>';
      $html .= '</div>';
      $html .= '<div class="scm-case-source" aria-hidden="true" style="display:none;">' . $caseSource . '</div>';
      $html .= '</article>';
    }

    return $html;
  }

  private function ticketOriginIsGuardian(array $row): bool
  {
    $creatorBy = strtolower(trim((string) ($row['creador_por'] ?? '')));
    return $creatorBy !== '' && $creatorBy !== 'funcionario';
  }

  /** @param array<int,array<string,mixed>> $historialItems */
  private function countPreventivaNoAccessNotices(array $row, array $historialItems = []): int
  {
    $documents = [];
    $collectDocuments = function ($raw) use (&$documents): void {
      foreach ($this->extractTicketRootDocuments($raw) as $doc) {
        $label = $this->normalizePreventivaNoAccessText((string) ($doc['nombre_archivo'] ?? ''));
        $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
        $urlKey = strtolower($url);
        if ($url !== '' && $this->looksLikePreventivaNoAccessNotice($label . ' ' . $urlKey)) {
          $documents[$urlKey !== '' ? $urlKey : md5($label)] = true;
          $documents['attempt:' . $this->preventivaNoAccessAttemptNumber($label)] = true;
        }
      }
    };

    $collectDocuments($row['archivos'] ?? '');
    foreach ($historialItems as $item) {
      if (is_array($item)) {
        $collectDocuments($item['archivos'] ?? '');
      }
    }

    foreach (['acta_no_acceso_preventiva', 'pdf_no_acceso_preventiva'] as $column) {
      $url = trim((string) ($row[$column] ?? ''));
      if ($url !== '') {
        $documents[strtolower($url)] = true;
      }
    }

    $historyCount = 0;
    foreach ($historialItems as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = $this->normalizePreventivaNoAccessText(implode(' ', [
        (string) ($item['respuesta'] ?? ''),
        (string) ($item['observacion'] ?? ''),
        (string) ($item['descripcion'] ?? ''),
        (string) ($item['nombre'] ?? ''),
        (string) ($item['estado_administrativo'] ?? ''),
        (string) ($item['estado_admin_ticket'] ?? ''),
        (string) ($item['estado_admin'] ?? ''),
      ]));
      if ($this->looksLikePreventivaNoAccessNotice($text) || $this->looksLikePreventivaNoAccessLegacyAttempt($text)) {
        $historyCount++;
        $historyCount = max($historyCount, $this->preventivaNoAccessAttemptNumber($text));
      }
    }

    $documentCount = count(array_filter(array_keys($documents), static fn(string $key): bool => strpos($key, 'attempt:') !== 0));
    foreach (array_keys($documents) as $key) {
      if (strpos($key, 'attempt:') === 0) {
        $documentCount = max($documentCount, (int) substr($key, 8));
      }
    }
    return max($documentCount, $historyCount);
  }

  private function looksLikePreventivaNoAccessNotice(string $text): bool
  {
    return strpos($text, 'preventiva') !== false
      && (
        strpos($text, 'no autorizacion') !== false
        || strpos($text, 'no permitir acceso') !== false
        || strpos($text, 'no acceso') !== false
      );
  }

  private function looksLikePreventivaNoAccessLegacyAttempt(string $text): bool
  {
    $hasWaitingState = strpos($text, 'en espera de respuesta') !== false;
    $hasAccessSignal = strpos($text, 'autorizacion') !== false
      || strpos($text, 'autoriza') !== false
      || strpos($text, 'acceso') !== false
      || strpos($text, 'coordinar') !== false
      || strpos($text, 'visita') !== false
      || strpos($text, 'no responde') !== false
      || strpos($text, 'sin respuesta') !== false;

    return $hasWaitingState || $hasAccessSignal;
  }

  private function preventivaNoAccessAttemptNumber(string $text): int
  {
    if (preg_match('/(?:#|no\\.?|numero)\\s*(\\d{1,3})/i', $text, $match)) {
      $number = max(0, (int) $match[1]);
      return $number <= 50 ? $number : 0;
    }
    return 0;
  }

  private function normalizePreventivaNoAccessText(string $text): string
  {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strtr($text, [
      'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
      'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    return strtolower($text);
  }

  private function renderPreventivaNoAccessSummary(int $count): string
  {
    $next = $count + 1;
    $label = $count === 1 ? '1 comunicaci&oacute;n registrada' : $count . ' comunicaciones registradas';
    return '<section class="scm-preventiva-no-access-summary" data-scm-preventiva-no-access-summary>'
      . '<div class="scm-preventiva-no-access-icon" aria-hidden="true">!</div>'
      . '<div class="scm-preventiva-no-access-copy"><span>Constancias preventivas por no autorizaci&oacute;n</span>'
      . '<strong data-scm-preventiva-no-access-count-label>' . esc_html($label) . '</strong>'
      . '<p>Este contador ayuda a dejar trazabilidad cuando el arrendatario no responde o no permite coordinar la revisi&oacute;n preventiva.</p></div>'
      . '<div class="scm-preventiva-no-access-next"><small>Pr&oacute;xima</small><b>#' . esc_html((string) $next) . '</b></div>'
      . '</section>';
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

  private function renderTicketRootDocumentsSection($raw, string $sectionId): string
  {
    $docs = $this->extractTicketRootDocuments($raw);
    if (empty($docs)) {
      return '';
    }

    $html = '<section class="scm-case-history scm-case-documents-section" id="' . esc_attr($sectionId) . '">';
    $html .= '<h4>Documentos del caso</h4>';
    $html .= '<div class="scm-case-document-grid">';
    foreach ($docs as $doc) {
      $label = trim((string) ($doc['nombre_archivo'] ?? ''));
      $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      if ($label === '') {
        $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
      }
      $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="scm-case-action-btn scm-case-document-link">' . esc_html($label) . '</a>';
    }
    $html .= '</div></section>';
    return $html;
  }

  /** @return array<int,array{nombre_archivo:string,archivo:string,media_archivo:string}> */
  private function extractTicketRootDocuments($raw): array
  {
    if (is_array($raw)) {
      $decoded = $raw;
    } else {
      $value = trim((string) $raw);
      if ($value === '') {
        return [];
      }
      $decoded = preg_match('/^[aObis]:/', $value) ? @unserialize($value, ['allowed_classes' => false]) : null;
      if (!is_array($decoded)) {
        $json = json_decode($value, true);
        $decoded = is_array($json) ? $json : [['nombre_archivo' => '', 'archivo' => $value]];
      }
    }

    $out = [];
    foreach ($decoded as $doc) {
      $label = '';
      $url = '';
      if (is_array($doc)) {
        $label = trim((string) ($doc['nombre_archivo'] ?? $doc['title'] ?? $doc['label'] ?? ''));
        $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? $doc['url'] ?? ''));
      } else {
        $url = trim((string) $doc);
      }
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $out[] = ['nombre_archivo' => $label, 'media_archivo' => $url, 'archivo' => $url];
    }
    return $out;
  }
}
