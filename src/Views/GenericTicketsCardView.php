<?php

namespace SCM\Views;

use SCM\Core\Auth;

final class GenericTicketsCardView
{
  /** @var callable */
  private $evalDone;
  /** @var callable */
  private $evalVisible;
  /** @var callable */
  private $parseTs;
  /** @var callable */
  private $formatDate;
  /** @var callable */
  private $formatDateTime;
  /** @var callable */
  private $resolveHitoLink;
  /** @var callable */
  private $firstIdValue;
  /** @var callable */
  private $humanDurationSince;
  /** @var callable */
  private $estadoBadge;
  /** @var callable */
  private $renderSeguimientoForm;
  /** @var callable */
  private $renderHistorialBlock;
  /** @var callable */
  private $renderRecordSection;
  /** @var callable */
  private $renderSingleRecordSection;
  /** @var callable */
  private $resolveTimelineHitosForRow;

  public function __construct(
    callable $evalDone,
    callable $evalVisible,
    callable $parseTs,
    callable $formatDate,
    callable $formatDateTime,
    callable $resolveHitoLink,
    callable $firstIdValue,
    callable $humanDurationSince,
    callable $estadoBadge,
    callable $renderSeguimientoForm,
    callable $renderHistorialBlock,
    callable $renderRecordSection,
    callable $renderSingleRecordSection,
    callable $resolveTimelineHitosForRow
  ) {
    $this->evalDone = $evalDone;
    $this->evalVisible = $evalVisible;
    $this->parseTs = $parseTs;
    $this->formatDate = $formatDate;
    $this->formatDateTime = $formatDateTime;
    $this->resolveHitoLink = $resolveHitoLink;
    $this->firstIdValue = $firstIdValue;
    $this->humanDurationSince = $humanDurationSince;
    $this->estadoBadge = $estadoBadge;
    $this->renderSeguimientoForm = $renderSeguimientoForm;
    $this->renderHistorialBlock = $renderHistorialBlock;
    $this->renderRecordSection = $renderRecordSection;
    $this->renderSingleRecordSection = $renderSingleRecordSection;
    $this->resolveTimelineHitosForRow = $resolveTimelineHitosForRow;
  }

  public function renderGenericTimeline(array $row, array $hitos): string
  {
    if (empty($hitos)) {
      return '';
    }
    $visibleHitos = [];
    foreach ($hitos as $hito) {
      if (call_user_func($this->evalVisible, $hito, $row)) {
        $visibleHitos[] = $hito;
      }
    }
    if (empty($visibleHitos)) {
      return '';
    }

    $items = '';
    $count = count($visibleHitos);
    $timelineItems = [];
    foreach ($visibleHitos as $hito) {
      $done = (bool) call_user_func($this->evalDone, $hito, $row);
      $ts = $done ? $this->resolveHitoTimestamp($hito, $row) : 0;
      $timelineItems[] = [
        'hito' => $hito,
        'done' => $done,
        'ts' => $ts,
      ];
    }

    foreach ($timelineItems as $i => $entry) {
      $hito = (array) ($entry['hito'] ?? []);
      $done = (bool) ($entry['done'] ?? false);
      $ts = (int) ($entry['ts'] ?? 0);
      $dateStr = ($done && $ts > 0) ? (string) call_user_func($this->formatDateTime, $ts) : '';
      $statusClass = $done ? 'scm-tl-done' : 'scm-tl-pending';
      $icon = esc_html((string) ($hito['icon'] ?? ''));
      $labelRaw = trim((string) ($hito['label'] ?? ''));
      $label = esc_html($labelRaw);

      $subParts = [];
      if ($dateStr !== '') {
        $subParts[] = '<span class="scm-tl-date">' . esc_html($dateStr) . '</span>';
      } else {
        $emptyText = trim((string) ($hito['empty_text'] ?? 'Pendiente'));
        if ($emptyText !== '') {
          $subParts[] = '<span class="scm-tl-date scm-tl-empty">' . esc_html($emptyText) . '</span>';
        }
      }

      $prevDoneTs = 0;
      $prevDoneLabel = '';
      for ($j = $i - 1; $j >= 0; $j--) {
        $prevDone = (bool) ($timelineItems[$j]['done'] ?? false);
        $prevTs = (int) ($timelineItems[$j]['ts'] ?? 0);
        if ($prevDone && $prevTs > 0) {
          $prevDoneTs = $prevTs;
          $prevDoneLabel = trim((string) (($timelineItems[$j]['hito']['label'] ?? '')));
          break;
        }
      }

      $fromTs = 0;
      $fromLabel = '';
      $fromFields = [];
      if (!empty($hito['elapsed_from_fields']) && is_array($hito['elapsed_from_fields'])) {
        $fromFields = $hito['elapsed_from_fields'];
      } elseif (!empty($hito['elapsed_from_field'])) {
        $fromFields = [(string) $hito['elapsed_from_field']];
      }
      if (!empty($fromFields)) {
        $fromTs = $this->resolveTimestampByFields($row, $fromFields);
        $fromLabel = trim((string) ($hito['elapsed_from_label'] ?? ''));
      } else {
        $fromTs = $prevDoneTs;
        $fromLabel = $prevDoneLabel;
      }

      if ($fromTs > 0) {
        if ($done && $ts > 0 && $ts >= $fromTs) {
          $elapsed = $this->humanDurationBetween($fromTs, $ts);
          if ($elapsed !== '') {
            $from = $fromLabel !== '' ? $fromLabel : 'hito anterior';
            $subParts[] = '<small class="scm-tl-sub">Tardo ' . esc_html($elapsed) . ' desde ' . esc_html($from) . '</small>';
          }
        } elseif (!$done) {
          $pending = (string) call_user_func($this->humanDurationSince, $fromTs);
          if ($pending !== '') {
            $from = $fromLabel !== '' ? $fromLabel : 'hito anterior';
            $subParts[] = '<small class="scm-tl-sub">Lleva ' . esc_html($pending) . ' sin hacerse desde ' . esc_html($from) . '</small>';
          }
        }
      }

      $sub = implode('', $subParts);
      $stepLink = (string) call_user_func($this->resolveHitoLink, (string) ($hito['link_url'] ?? ''), $row);
      $linkTitle = trim((string)($hito['link_label'] ?? 'Abrir'));
      if ($linkTitle === '') {
        $linkTitle = 'Abrir';
      }
      $iconHtml = $icon;
      if ($stepLink !== '') {
        $iconHtml = '<a class="scm-tl-icon-link" href="' . esc_url($stepLink) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($linkTitle) . '">' . $icon . '</a>';
      }

      $items .= '<div class="scm-tl-item ' . $statusClass . '"><div class="scm-tl-icon">' . $iconHtml . '</div><div class="scm-tl-label">' . $label . $sub . '</div></div>';
      if ($i < $count - 1) {
        $lineClass = $done ? 'scm-tl-line scm-tl-line-done' : 'scm-tl-line';
        $items .= '<div class="' . $lineClass . '"></div>';
      }
    }
    return '<div class="scm-tl-wrap"><div class="scm-timeline">' . $items . '</div></div>';
  }

  private function resolveHitoTimestamp(array $hito, array $row): int
  {
    $fields = [];
    if (!empty($hito['ts_fields']) && is_array($hito['ts_fields'])) {
      $fields = $hito['ts_fields'];
    } elseif (!empty($hito['ts_field'])) {
      $fields = [(string) $hito['ts_field']];
    }

    foreach ($fields as $field) {
      $field = (string) $field;
      if ($field === '') {
        continue;
      }
      $ts = (int) call_user_func($this->parseTs, $row[$field] ?? '');
      if ($ts > 0) {
        return $ts;
      }
    }
    return 0;
  }

  /**
   * @param array<int,string> $fields
   */
  private function resolveTimestampByFields(array $row, array $fields): int
  {
    foreach ($fields as $field) {
      $name = trim((string) $field);
      if ($name === '') {
        continue;
      }
      $ts = (int) call_user_func($this->parseTs, $row[$name] ?? '');
      if ($ts > 0) {
        return $ts;
      }
    }

    return 0;
  }

  private function humanDurationBetween(int $fromTs, int $toTs): string
  {
    if ($fromTs <= 0 || $toTs <= 0 || $toTs < $fromTs) {
      return '';
    }

    $diff = $toTs - $fromTs;
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

  public function renderGenericCard(array $row, array $config, array $hitos, string $tabKey = '', string $statusBucket = ''): string
  {
    $ticketPk = (int) ($row['_ID'] ?? 0);
    $idTicketRaw = trim((string) ($row['id_ticket'] ?? ''));
    $ticketLabel = $idTicketRaw !== '' ? $idTicketRaw : (string) $ticketPk;
    $asuntoRaw = trim((string) ($row['asunto'] ?? $row['descripcion'] ?? ''));
    $descripcionRaw = trim((string) ($row['descripcion'] ?? ''));
    $temaRaw = trim((string) ($row['tema_ayuda'] ?? ''));
    $estadoRaw = trim((string) ($row['estado'] ?? ''));
    $estadoAdmRaw = trim((string) ($row['estado_admin_ticket'] ?? $row['estado_administrativo'] ?? $row['estado_admin'] ?? ''));
    $prioridadRaw = trim((string) ($row['prioridad'] ?? ''));
    $magnitudCasoRaw = trim((string) ($row['magnitud_caso'] ?? ''));
    $perturbacionRaw = trim((string) ($row['perturbacion'] ?? $row['_scm_cot_perturbacion'] ?? ''));
    $justificacionPerturbacionRaw = trim((string) ($row['justificacion_perturbacion'] ?? $row['_scm_cot_justificacion_perturbacion'] ?? ''));
    $valorBonificacionRaw = trim((string) ($row['valor_bonificacion'] ?? $row['_scm_cot_valor_bonificacion'] ?? ''));
    $areaAfectadaRaw = trim((string) ($row['area_afectada'] ?? $row['_scm_cot_area_afectada'] ?? ''));
    $resumenCalculoPerturbacionRaw = trim((string) ($row['resumen_calculo_perturbacion'] ?? $row['_scm_cot_resumen_calculo_perturbacion'] ?? ''));
    $areaAfectadaLabel = $areaAfectadaRaw !== '' ? $areaAfectadaRaw : '-';
    if ($areaAfectadaRaw !== '' && !preg_match('/\bm2\b/i', $areaAfectadaRaw)) {
      $areaAfectadaLabel = $areaAfectadaRaw . ' m2';
    }
    $inmuebleRaw = trim((string) ($row['inmueble'] ?? $row['numero_inmueble'] ?? ''));
    $contratoRaw = trim((string) ($row['contrato'] ?? $row['id_contrato'] ?? ''));
    $barrioRaw = trim((string) ($row['barrio'] ?? ''));
    $direccionRaw = trim((string) ($row['direccion'] ?? ''));
    $empleadoRaw   = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? $row['id_empleado'] ?? ''));
    $empleadoIdRaw = trim((string) ($row['id_empleado'] ?? ''));
    $propietarioRaw = trim((string) ($row['propietario'] ?? ''));
    $solicitanteRaw = trim((string) ($row['solicitante'] ?? ''));
    $numeroSolicitudRaw = trim((string) ($row['numero_solicitud'] ?? ''));
    $correoPropietarioRaw = trim((string) ($row['correo_propietario'] ?? ''));
    $celularPropietarioRaw = trim((string) ($row['celular_propietario'] ?? ''));
    $indicativoPropietarioRaw = trim((string) ($row['indicativo_propietario'] ?? $row['indicativo'] ?? ''));
    $arrendatarioRaw = trim((string) ($row['arrendatario'] ?? ''));
    $correoArrendatarioRaw = trim((string) ($row['correo_arrendatario'] ?? ''));
    $celularArrendatarioRaw = trim((string) ($row['celular_arrendatario'] ?? ''));
    $indicativoArrendatarioRaw = trim((string) ($row['indicativo_arrendatario'] ?? $row['indicativo'] ?? ''));
    $origenLabel = $this->ticketOriginIsGuardian($row) ? 'Guardian' : 'No Guardian';
    $origenBadge = $origenLabel === 'Guardian'
      ? '<span class="scm-origin-badge scm-origin-badge--guardian">Guardian</span>'
      : '<span class="scm-origin-badge scm-origin-badge--internal">No Guardian</span>';
    $idPrev = trim((string) ($row['id_revision_preventiva'] ?? ''));
    $idCorr = trim((string) ($row['id_revision_correctiva'] ?? ''));
    $idCotz = trim((string) ($row['id_cotizacion_mantenimiento'] ?? ''));
    $cotEstadoRaw = trim((string) ($row['_scm_cot_estado'] ?? $row['estado_cotizacion_mantenimiento'] ?? ''));
    $cotRespuestaEstadoRaw = trim((string) ($row['_scm_cot_respuesta_estado'] ?? $row['estado_respuesta_cotizacion_mantenimiento'] ?? $row['estado_respuesta'] ?? ''));
    $idEstudioAseguradoraRaw    = trim((string) ($row['id_estudio_aseguradora'] ?? ''));
    $anexosEntregaRaw           = trim((string) ($row['anexos_entrega'] ?? ''));
    $consultorEntregaRaw        = trim((string) ($row['consultor_entrega'] ?? ''));
    $consultorEntregaCelularRaw = trim((string) ($row['consultor_entrega_celular'] ?? ''));
    $consultorEntregaCorreoRaw  = trim((string) ($row['consultor_entrega_correo'] ?? ''));
    $ubicacionLlavesRaw         = trim((string) ($row['ubicacion_llaves'] ?? ''));
    $personaLlavesRaw           = trim((string) ($row['persona_llaves'] ?? ''));
    $contactoLlavesRaw          = trim((string) ($row['contacto_llaves'] ?? ''));

    $creadoTs = (int) call_user_func($this->parseTs, $row['cct_created'] ?? $row['fecha'] ?? '');
    if ($creadoTs <= 0) {
      $creadoTs = (int) call_user_func($this->parseTs, $row['fecha'] ?? '');
    }
    $creado = $creadoTs > 0 ? (string) call_user_func($this->formatDateTime, $creadoTs) : '-';
    $updatedTs = (int) call_user_func($this->parseTs, $row['fecha_actualizacion'] ?? '');
    $tiempoEjecucion = (string) call_user_func($this->humanDurationSince, $creadoTs);
    $tiempoSinActualizar = (string) call_user_func($this->humanDurationSince, $updatedTs > 0 ? $updatedTs : $creadoTs);
    $timelineHtml = $this->renderGenericTimeline($row, $hitos);
    $temaLabel = $temaRaw !== '' ? $temaRaw : '-';
    $magnitudCasoBadge = $this->renderMagnitudeBadge($magnitudCasoRaw);
    $perturbacionKey = strtolower($perturbacionRaw);
    $perturbacionNum = is_numeric($perturbacionRaw) ? (float) $perturbacionRaw : 0.0;
    $perturbacionSi = ($perturbacionRaw !== '' && $perturbacionKey !== 'no' && $perturbacionKey !== 'false' && $perturbacionKey !== 'null' && $perturbacionKey !== '0')
      || $perturbacionNum > 0;
    $perturbacionBadge = $perturbacionSi
      ? '<span class="scm-magnitude-badge" style="background:#dc2626;">Con perturbaci&oacute;n</span>'
      : '<span class="scm-magnitude-badge scm-magnitude-empty">Sin perturbaci&oacute;n</span>';
    $ticketBaseUrl = trim((string) ($config['ticket_url'] ?? ''));
    $preventivaBaseUrl = trim((string) ($config['preventiva_url'] ?? ''));
    $correctivaBaseUrl = trim((string) ($config['correctiva_url'] ?? ''));
    $cotizacionBaseUrl = trim((string) ($config['cotizacion_url'] ?? ''));
    $ticketIdUrl = $idTicketRaw !== '' ? $idTicketRaw : (string) $ticketPk;
    $ticketUrl = ($ticketBaseUrl !== '' && $ticketIdUrl !== '') ? esc_url($ticketBaseUrl . rawurlencode($ticketIdUrl)) : '';
    $prevFirstId = (string) call_user_func($this->firstIdValue, $idPrev);
    $corrFirstId = (string) call_user_func($this->firstIdValue, $idCorr);
    $cotzFirstId = (string) call_user_func($this->firstIdValue, $idCotz);
    $prevUrl = ($preventivaBaseUrl !== '' && $prevFirstId !== '') ? esc_url($preventivaBaseUrl . rawurlencode($prevFirstId)) : '';
    $corrUrl = ($correctivaBaseUrl !== '' && $corrFirstId !== '') ? esc_url($correctivaBaseUrl . rawurlencode($corrFirstId)) : '';
    $cotzUrl = ($cotizacionBaseUrl !== '' && $cotzFirstId !== '') ? esc_url($cotizacionBaseUrl . rawurlencode($cotzFirstId)) : '';
    $cotEstadoParaRespuesta = $cotRespuestaEstadoRaw !== '' ? $cotRespuestaEstadoRaw : $cotEstadoRaw;
    $cotizacionPendienteRespuesta = $idCotz !== '' && in_array(strtolower($cotEstadoParaRespuesta), ['', 'esperando respuesta'], true);
    $isPreventivaTicket = $tabKey === 'preventiva' || $idPrev !== '' || stripos($temaRaw . ' ' . $asuntoRaw . ' ' . $descripcionRaw, 'preventiva') !== false;

    $historialItems = is_array($row['_scm_historial_items'] ?? null) ? $row['_scm_historial_items'] : [];
    $seguimientosItems = is_array($row['_scm_seguimientos_ticket'] ?? null) ? $row['_scm_seguimientos_ticket'] : [];
    $notasItems = is_array($row['_scm_notas_ticket'] ?? null) ? $row['_scm_notas_ticket'] : [];
    $seguimientoFields = ['_ID' => 'ID', 'cct_status' => 'Estado', 'id_ticket' => 'Ticket', 'id_coordinador' => 'Coordinador', 'id_empleado' => 'Empleado', 'cct_author_id' => 'Autor ID', 'fecha' => 'Fecha', 'cct_created' => 'Creado', 'cct_modified' => 'Modificado', 'evidencia' => 'Evidencia'];
    $notasFields = ['_ID' => 'ID', 'cct_status' => 'Estado', 'id_ticket' => 'Ticket', 'id_empleado' => 'Empleado', 'cct_author_id' => 'Autor ID', 'fecha' => 'Fecha', 'cct_created' => 'Creado', 'cct_modified' => 'Modificado'];
    $historialInmuebleItems = is_array($row['_scm_historial_inmueble'] ?? null) ? $row['_scm_historial_inmueble'] : [];
    $contratoData = is_array($row['_scm_contrato_data'] ?? null) ? $row['_scm_contrato_data'] : [];
    $inmuebleData = is_array($row['_scm_inmueble_data'] ?? null) ? $row['_scm_inmueble_data'] : [];
    $idInmuebleWebRaw = trim((string) ($row['id_inmueble'] ?? ($inmuebleData['codigo'] ?? '')));
    $propertyDataId = trim((string) ($inmuebleData['_ID'] ?? $inmuebleData['id_inmueble_data'] ?? ''));
    $propertyGoogleMaps = trim((string) ($inmuebleData['ubicacion_google_maps'] ?? ''));
    $preventivaNoAccessCount = $isPreventivaTicket ? $this->countPreventivaNoAccessNotices($row, $historialItems) : 0;

    $caseSource  = '';
    if ($descripcionRaw !== '') {
      $caseSource .= '<div class="scm-case-description"><strong>Descripci&oacute;n del caso:</strong><div class="scm-case-description-content">' . $descripcionRaw . '</div></div>';
    }
    if ($isPreventivaTicket) {
      $caseSource .= $this->renderPreventivaNoAccessSummary($preventivaNoAccessCount);
    }
    $ticketDocumentsHtml = $this->renderTicketDocumentsSection($row['archivos'] ?? '', 'scm-sec-documentos');
    $caseSource .= '<div class="scm-modal-timeline-only">' . $timelineHtml . '</div>';
    $caseSource .= '<div class="scm-seg-wrap">' . (string) call_user_func($this->renderSeguimientoForm, $ticketPk, Auth::isLoggedIn(), $cotizacionPendienteRespuesta) . '</div>';
    $caseSource .= (string) call_user_func($this->renderHistorialBlock, $historialItems);
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Seguimientos realizados', $seguimientosItems, '', ['evidencia' => 'Evidencia']);
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Notas del ticket', $notasItems);
    $caseSource .= '<div class="scm-case-action-buttons"><button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button><button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button><button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
    if ($ticketDocumentsHtml !== '') {
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-documentos">Ver documentos</button>';
    }
    $caseSource .= '</div>';
    $caseSource .= '<div class="scm-case-hidden-sections" style="display:none;">';
    $caseSource .= (string) call_user_func($this->renderSingleRecordSection, 'Contrato', $contratoData, 'scm-sec-contrato');
    $caseSource .= (string) call_user_func($this->renderSingleRecordSection, 'Inmueble', $inmuebleData, 'scm-sec-inmueble');
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
    $caseSource .= $ticketDocumentsHtml;
    $caseSource .= '</div>';

    $dataAttrs  = 'data-ticket="' . esc_attr($ticketLabel) . '"';
    $dataAttrs .= ' data-ticket-pk="' . esc_attr((string) $ticketPk) . '"';
    $dataAttrs .= ' data-asunto="' . esc_attr($asuntoRaw !== '' ? $asuntoRaw : '-') . '"';
    $dataAttrs .= ' data-estado="' . esc_attr($estadoRaw !== '' ? $estadoRaw : '-') . '"';
    $dataAttrs .= ' data-admin="' . esc_attr($estadoAdmRaw !== '' ? $estadoAdmRaw : '-') . '"';
    $dataAttrs .= ' data-prioridad="' . esc_attr($prioridadRaw !== '' ? $prioridadRaw : '-') . '"';
    $dataAttrs .= ' data-magnitud-caso="' . esc_attr($magnitudCasoRaw !== '' ? ucfirst($magnitudCasoRaw) : '-') . '"';
    $dataAttrs .= ' data-perturbacion="' . esc_attr($perturbacionRaw) . '"';
    $dataAttrs .= ' data-justificacion-perturbacion="' . esc_attr($justificacionPerturbacionRaw) . '"';
    $dataAttrs .= ' data-valor-bonificacion="' . esc_attr($valorBonificacionRaw) . '"';
    $dataAttrs .= ' data-area-afectada="' . esc_attr($areaAfectadaRaw) . '"';
    $dataAttrs .= ' data-resumen-calculo-perturbacion="' . esc_attr($resumenCalculoPerturbacionRaw) . '"';
    $dataAttrs .= ' data-contrato="' . esc_attr($contratoRaw !== '' ? ('#' . $contratoRaw) : '-') . '"';
    $dataAttrs .= ' data-inmueble="' . esc_attr($inmuebleRaw !== '' ? $inmuebleRaw : '-') . '"';
    $dataAttrs .= ' data-id-inmueble-web="' . esc_attr($idInmuebleWebRaw !== '' ? $idInmuebleWebRaw : '-') . '"';
    $dataAttrs .= ' data-id-inmueble-data="' . esc_attr($propertyDataId) . '"';
    $dataAttrs .= ' data-ubicacion-google-maps="' . esc_attr($propertyGoogleMaps) . '"';
    $dataAttrs .= ' data-barrio="' . esc_attr($barrioRaw !== '' ? $barrioRaw : '-') . '"';
    $dataAttrs .= ' data-direccion="' . esc_attr($direccionRaw !== '' ? $direccionRaw : '-') . '"';
    $dataAttrs .= ' data-creado="' . esc_attr($creado) . '"';
    $dataAttrs .= ' data-empleado="' . esc_attr($empleadoRaw !== '' ? $empleadoRaw : '-') . '"';
    $dataAttrs .= ' data-propietario="' . esc_attr($propietarioRaw) . '"';
    $dataAttrs .= ' data-correo-propietario="' . esc_attr($correoPropietarioRaw) . '"';
    $dataAttrs .= ' data-celular-propietario="' . esc_attr($celularPropietarioRaw) . '"';
    $dataAttrs .= ' data-indicativo-propietario="' . esc_attr($indicativoPropietarioRaw) . '"';
    $dataAttrs .= ' data-arrendatario="' . esc_attr($arrendatarioRaw) . '"';
    $dataAttrs .= ' data-correo-arrendatario="' . esc_attr($correoArrendatarioRaw) . '"';
    $dataAttrs .= ' data-celular-arrendatario="' . esc_attr($celularArrendatarioRaw) . '"';
    $dataAttrs .= ' data-indicativo-arrendatario="' . esc_attr($indicativoArrendatarioRaw) . '"';
    $dataAttrs .= ' data-ticket-url="' . esc_attr($ticketUrl) . '"';
    $dataAttrs .= ' data-cotizacion-id="' . esc_attr($cotzFirstId) . '"';
    $dataAttrs .= ' data-cotizacion-url="' . esc_attr($cotzUrl) . '"';
    $dataAttrs .= ' data-cot-estado="' . esc_attr($cotEstadoRaw) . '"';
    $dataAttrs .= ' data-id-revision-correctiva="' . esc_attr($corrFirstId) . '"';
    $dataAttrs .= ' data-id-revision-preventiva="' . esc_attr($prevFirstId) . '"';
    $dataAttrs .= ' data-preventiva-no-access-count="' . esc_attr((string) $preventivaNoAccessCount) . '"';
    $dataAttrs .= ' data-ejecucion="' . esc_attr($tiempoEjecucion) . '"';
    $dataAttrs .= ' data-sin-actualizar="' . esc_attr($tiempoSinActualizar) . '"';
    $dataAttrs .= ' data-origen="' . esc_attr($origenLabel) . '"';
    $dataAttrs .= ' data-tab-key="' . esc_attr($tabKey) . '"';
    if ($statusBucket !== '') {
      $dataAttrs .= ' data-status-bucket="' . esc_attr($statusBucket) . '"';
    }
    $dataAttrs .= ' data-empleado-id="' . esc_attr($empleadoIdRaw) . '"';
    $dataAttrs .= ' data-id-estudio-aseguradora="' . esc_attr($idEstudioAseguradoraRaw) . '"';
    $dataAttrs .= ' data-anexos-entrega="' . esc_attr($anexosEntregaRaw) . '"';
    $dataAttrs .= ' data-consultor-entrega="' . esc_attr($consultorEntregaRaw) . '"';
    $dataAttrs .= ' data-consultor-entrega-celular="' . esc_attr($consultorEntregaCelularRaw) . '"';
    $dataAttrs .= ' data-consultor-entrega-correo="' . esc_attr($consultorEntregaCorreoRaw) . '"';
    $dataAttrs .= ' data-ubicacion-llaves="' . esc_attr($ubicacionLlavesRaw) . '"';
    $dataAttrs .= ' data-persona-llaves="' . esc_attr($personaLlavesRaw) . '"';
    $dataAttrs .= ' data-contacto-llaves="' . esc_attr($contactoLlavesRaw) . '"';

    $c  = '<article class="scm-ticket-card card" data-pk="' . esc_attr((string) $ticketPk) . '">';
    $c .= '<header class="scm-ticket-card-head"><span class="scm-ticket-badge badge badge-primary">#' . esc_html($ticketLabel) . '</span><h3>' . esc_html($temaLabel) . '</h3></header>';
    $c .= '<p class="scm-ticket-card-asunto"><span>Asunto</span><strong>' . esc_html($asuntoRaw !== '' ? $asuntoRaw : '-') . '</strong></p>';
    $c .= '<p class="scm-ticket-card-contract">Contrato <strong>' . esc_html($contratoRaw !== '' ? ('#' . $contratoRaw) : '-') . '</strong></p>';
    $c .= '<p class="scm-ticket-card-property">Inmueble <strong>' . esc_html($inmuebleRaw !== '' ? $inmuebleRaw : '-') . '</strong></p>';
    $c .= '<p class="scm-ticket-card-barrio">Barrio <strong>' . esc_html($barrioRaw !== '' ? $barrioRaw : '-') . '</strong></p>';
    $c .= '<p class="scm-ticket-card-address">Direccion <strong>' . esc_html($direccionRaw !== '' ? $direccionRaw : '-') . '</strong></p>';
    $ownerLabel = $propietarioRaw !== '' ? $propietarioRaw : (($tabKey === 'entrega' && $solicitanteRaw !== '') ? $solicitanteRaw : '');
    if ($ownerLabel !== '') {
      $c .= '<p class="scm-ticket-card-owner">Propietario <strong>' . esc_html($ownerLabel) . '</strong></p>';
    }
    if ($arrendatarioRaw !== '') {
      $c .= '<p class="scm-ticket-card-tenant">Arrendatario <strong>' . esc_html($arrendatarioRaw) . '</strong></p>';
    }
    if ($tabKey === 'entrega') {
      $c .= '<p class="scm-ticket-card-request">Numero solicitud <strong>' . esc_html($numeroSolicitudRaw !== '' ? $numeroSolicitudRaw : '-') . '</strong></p>';
    }
    $c .= '<p class="scm-ticket-card-employee">Asignado a <strong>' . esc_html($empleadoRaw !== '' ? $empleadoRaw : '-') . '</strong></p>';
    if ($statusBucket !== 'cerrados') {
      $c .= '<p class="scm-ticket-card-execution">En ejecucion <strong>' . esc_html($tiempoEjecucion) . '</strong></p>';
      $c .= '<p class="scm-ticket-card-stale">Sin actualizar <strong>' . esc_html($tiempoSinActualizar) . '</strong></p>';
    }
    $c .= '<div class="scm-ticket-card-states"><div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado</span>' . (string) call_user_func($this->estadoBadge, $estadoRaw !== '' ? $estadoRaw : '-') . '</div><div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Estado administrativo</span>' . (string) call_user_func($this->estadoBadge, $estadoAdmRaw !== '' ? $estadoAdmRaw : '-') . '</div>';
    if ($tabKey === 'preventiva') {
      $c .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Magnitud caso</span>' . $magnitudCasoBadge . '</div>';
      $c .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Perturbaci&oacute;n</span>' . $perturbacionBadge . '</div>';
    }
    $c .= '<div class="scm-ticket-card-state"><span class="scm-ticket-card-state-label">Origen</span>' . $origenBadge . '</div>';
    $c .= '</div>';
    if ($tabKey === 'preventiva') {
      $c .= '<p class="scm-ticket-card-barrio">Area afectada <strong>' . esc_html($areaAfectadaLabel) . '</strong></p>';
    }
    $c .= '<div class="scm-ticket-card-footer">';
    if ($tabKey === 'entrega') {
      $c .= '<button class="btn btn-outline btn-sm" type="button" data-scm-open-card-consultor>Consultor/a de entrega</button>';
      $c .= '<button class="btn btn-outline btn-sm" type="button" data-scm-open-card-llaves>Llaves</button>';
    }
    if (in_array($statusBucket, ['postergados', 'cerrados'], true)) {
      $c .= '<button class="btn btn-outline btn-sm scm-activate-ticket-btn" type="button" data-scm-activate-ticket>Activar ticket</button>';
    }
    $c .= '<button class="scm-btn-case btn btn-primary btn-sm" type="button" onclick="scmOpenCase(this)" ' . $dataAttrs . '>Ver caso</button>';
    $c .= '</div>';
    $c .= '<div class="scm-case-source" aria-hidden="true" style="display:none;">' . $caseSource . '</div></article>';
    return $c;
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
      foreach ($this->extractTicketDocuments($raw) as $doc) {
        $label = $this->normalizePreventivaNoAccessText((string) ($doc['nombre_archivo'] ?? ''));
        $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
        $urlKey = strtolower($url);
        if ($url !== '' && $this->looksLikePreventivaNoAccessNotice($label . ' ' . $urlKey)) {
          $documents[$urlKey !== '' ? $urlKey : md5($label)] = true;
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

    if (!empty($documents)) {
      return count($documents);
    }

    $fallback = 0;
    foreach ($historialItems as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = $this->normalizePreventivaNoAccessText(implode(' ', [
        (string) ($item['respuesta'] ?? ''),
        (string) ($item['observacion'] ?? ''),
        (string) ($item['descripcion'] ?? ''),
        (string) ($item['nombre'] ?? ''),
      ]));
      if ($this->looksLikePreventivaNoAccessNotice($text)) {
        $fallback++;
      }
    }

    return $fallback;
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

  public function renderGenericCards(array $rows, array $config, string $tabKey, string $statusBucket = ''): string
  {
    if (empty($rows)) {
      return '<div class="scm-empty"><p>No se encontraron tickets.</p></div>';
    }
    $html = '';
    foreach ($rows as $row) {
      $hitos = (array) call_user_func($this->resolveTimelineHitosForRow, $tabKey, $row);
      $html .= $this->renderGenericCard($row, $config, $hitos, $tabKey, $statusBucket);
    }
    return $html;
  }

  private function renderTicketDocumentsSection($raw, string $sectionId): string
  {
    $docs = $this->extractTicketDocuments($raw);
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
  private function extractTicketDocuments($raw): array
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
