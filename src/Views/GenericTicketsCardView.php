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
    $departamentoRaw = trim((string) ($row['departamento'] ?? ''));
    $effectiveTabKey = $this->inferTabKeyForRow($tabKey, $row);
    $effectiveStatusBucket = $this->inferStatusBucketForRow($statusBucket, $row);
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
    $cotzUrl = $cotzFirstId !== '' ? esc_url(\SCM\App\SuCasaControlServiciosInmobiliarios::signedMaintenanceQuotePublicUrl((int) $cotzFirstId)) : '';
    $cotzOrderUrl = $cotzFirstId !== ''
      ? 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-orden-de-mantenimiento/?id_cotizacion=' . rawurlencode($cotzFirstId) . '&id_inmueble=' . rawurlencode($idInmuebleWebRaw !== '' ? $idInmuebleWebRaw : $inmuebleRaw)
      : '';
    $actaBaseUrl = rtrim((string) (defined('SCM_BASE_URL') ? SCM_BASE_URL : ''), '/');
    $cotzActaUrl = $cotzFirstId !== '' && $actaBaseUrl !== ''
      ? $actaBaseUrl . '/crear-acta.php?' . http_build_query([
        'ticket_pk' => (string) $ticketPk,
        'id_cotizacion' => $cotzFirstId,
        'source_flow' => 'approved_quote',
      ], '', '&', PHP_QUERY_RFC3986)
      : '';
    $cotEstadoParaRespuesta = $cotRespuestaEstadoRaw !== '' ? $cotRespuestaEstadoRaw : $cotEstadoRaw;
    $cotizacionPendienteRespuesta = $idCotz !== '' && in_array(strtolower($cotEstadoParaRespuesta), ['', 'esperando respuesta'], true);
    $cotFechaEnvioTs = (int) call_user_func($this->parseTs, $row['_scm_cot_fecha_envio'] ?? $row['cot_fecha_envio'] ?? $row['fecha_envio_cotizacion_mantenimiento'] ?? $row['fecha_envio'] ?? '');
    $cotSentFlag = strtolower(trim((string) ($row['_scm_cot_se_envio'] ?? $row['fue_enviada_cotizacion_mantenimiento'] ?? $row['fue_enviada'] ?? $row['se_envio'] ?? '')));
    $cotEnviada = $cotFechaEnvioTs > 0 || in_array($cotSentFlag, ['si', 'sí', '1', 'true', 'enviada', 'enviado', 'fue enviada'], true);
    $cotDiasCalendario = 0;
    if ($cotFechaEnvioTs > 0) {
      $sentDay = strtotime(date('Y-m-d', $cotFechaEnvioTs)) ?: $cotFechaEnvioTs;
      $todayDay = strtotime(date('Y-m-d')) ?: time();
      $cotDiasCalendario = max(0, (int) floor(($todayDay - $sentDay) / 86400));
    }
    $cotSeguimientoReparacionesDisponible = $cotizacionPendienteRespuesta && $cotEnviada && $cotFechaEnvioTs > 0 && $cotDiasCalendario > 10;
    $isPreventivaTicket = $effectiveTabKey === 'preventiva' || $idPrev !== '' || stripos($temaRaw . ' ' . $asuntoRaw . ' ' . $descripcionRaw, 'preventiva') !== false;

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

    $ticketDocumentsHtml = $this->renderTicketAttachmentsSection([$row['imagenes'] ?? '', $row['imagen'] ?? '', $row['evidencia'] ?? ''], $row['archivos'] ?? '', 'scm-sec-documentos');

    $caseSource  = '';
    if ($descripcionRaw !== '') {
      $caseSource .= '<div class="scm-case-description bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs mb-4">'
        . '<div class="flex items-center justify-between mb-2 pb-2 border-b border-slate-100">'
        . '<strong class="text-sm font-semibold text-slate-900 flex items-center gap-1.5"><span class="material-symbols-outlined text-slate-400 text-[18px]">subject</span><span>Descripción del Requerimiento</span></strong>'
        . '</div>'
        . '<div class="scm-case-description-content p-4 rounded-xl bg-slate-50 border border-slate-200/80 text-slate-800 text-sm leading-relaxed font-normal">' . $descripcionRaw . '</div>'
        . '</div>';
    }
    if ($isPreventivaTicket) {
      $caseSource .= $this->renderPreventivaNoAccessSummary($preventivaNoAccessCount);
    }
    $caseSource .= '<div class="scm-modal-timeline-only">' . $timelineHtml . '</div>';
    $caseSource .= '<div class="scm-seg-wrap">' . (string) call_user_func($this->renderSeguimientoForm, $ticketPk, Auth::isLoggedIn(), $cotizacionPendienteRespuesta) . '</div>';
    $caseSource .= (string) call_user_func($this->renderHistorialBlock, $historialItems);
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Seguimientos realizados', $seguimientosItems, '', ['evidencia' => 'Evidencia']);
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Notas del ticket', $notasItems);
    $caseSource .= '<div class="scm-case-action-buttons">';
    if ($ticketDocumentsHtml !== '') {
      $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-documentos">Adjuntos del caso</button>';
    }
    $caseSource .= '<button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-contrato">Ver contrato</button><button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-inmueble">Ver inmueble</button><button type="button" class="btn btn-primary btn-sm" data-scm-open-section="scm-sec-hist-inmueble">Ver historial del inmueble</button>';
    $caseSource .= '</div>';
    $caseSource .= '<div class="scm-case-hidden-sections" style="display:none;">';
    if ($ticketDocumentsHtml !== '') {
      $caseSource .= $ticketDocumentsHtml;
    }
    $caseSource .= (string) call_user_func($this->renderSingleRecordSection, 'Contrato', $contratoData, 'scm-sec-contrato');
    $caseSource .= (string) call_user_func($this->renderSingleRecordSection, 'Inmueble', $inmuebleData, 'scm-sec-inmueble');
    $caseSource .= (string) call_user_func($this->renderRecordSection, 'Historial del inmueble', $historialInmuebleItems, 'scm-sec-hist-inmueble');
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
    $contratoClean = ltrim($contratoRaw, '#');
    $contratoLabel = $contratoClean !== '' ? ('#' . $contratoClean) : '-';
    $dataAttrs .= ' data-contrato="' . esc_attr($contratoLabel) . '"';
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
    $dataAttrs .= ' data-cotizacion-order-url="' . esc_attr($cotzOrderUrl) . '"';
    $dataAttrs .= ' data-cotizacion-acta-url="' . esc_attr($cotzActaUrl) . '"';
    $dataAttrs .= ' data-cot-estado="' . esc_attr($cotEstadoRaw) . '"';
    $dataAttrs .= ' data-cot-fecha-envio="' . esc_attr((string) $cotFechaEnvioTs) . '"';
    $dataAttrs .= ' data-cot-dias-calendario="' . esc_attr((string) $cotDiasCalendario) . '"';
    $dataAttrs .= ' data-cot-seguimiento-reparaciones-disponible="' . esc_attr($cotSeguimientoReparacionesDisponible ? '1' : '0') . '"';
    $dataAttrs .= ' data-id-revision-correctiva="' . esc_attr($corrFirstId) . '"';
    $dataAttrs .= ' data-id-revision-preventiva="' . esc_attr($prevFirstId) . '"';
    $dataAttrs .= ' data-prev-encontro-danos="' . esc_attr((string) ($row['_scm_prev_encontro_danos'] ?? $row['se_encontraron_danos'] ?? '')) . '"';
    $dataAttrs .= ' data-preventiva-no-access-count="' . esc_attr((string) $preventivaNoAccessCount) . '"';
    $dataAttrs .= ' data-ejecucion="' . esc_attr($tiempoEjecucion) . '"';
    $dataAttrs .= ' data-sin-actualizar="' . esc_attr($tiempoSinActualizar) . '"';
    $dataAttrs .= ' data-origen="' . esc_attr($origenLabel) . '"';
    $dataAttrs .= ' data-tab-key="' . esc_attr($effectiveTabKey) . '"';
    $dataAttrs .= ' data-tema="' . esc_attr($temaRaw) . '"';
    $dataAttrs .= ' data-departamento="' . esc_attr($departamentoRaw) . '"';
    if ($effectiveStatusBucket !== '') {
      $dataAttrs .= ' data-status-bucket="' . esc_attr($effectiveStatusBucket) . '"';
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

    $timeAgo = 'Reciente';
    $rawCreated = $row['cct_created'] ?? $row['fecha'] ?? '';
    $createdTs = (int) call_user_func($this->parseTs, $rawCreated);
    if ($createdTs > 0) {
      $diff = time() - $createdTs;
      if ($diff < 3600) {
        $timeAgo = 'Hace ' . max(1, (int) floor($diff / 60)) . ' min';
      } elseif ($diff < 86400) {
        $timeAgo = 'Hace ' . (int) floor($diff / 3600) . ' horas';
      } elseif ($diff < 172800) {
        $timeAgo = 'Ayer';
      } else {
        $timeAgo = 'Hace ' . (int) floor($diff / 86400) . ' días';
      }
    }

    $urgencyNorm = strtolower(trim($prioridadRaw !== '' ? $prioridadRaw : $magnitudCasoRaw));
    if (str_contains($urgencyNorm, 'crit') || str_contains($urgencyNorm, 'urgente')) {
      $urgencyPill = '<span class="scm-card-urgency scm-urgency-critico"><span class="material-symbols-outlined text-[13px]">local_fire_department</span> Crítico</span>';
    } elseif (str_contains($urgencyNorm, 'alt')) {
      $urgencyPill = '<span class="scm-card-urgency scm-urgency-alto"><span class="material-symbols-outlined text-[13px]">warning</span> Alto</span>';
    } elseif (str_contains($urgencyNorm, 'med')) {
      $urgencyPill = '<span class="scm-card-urgency scm-urgency-medio"><span class="material-symbols-outlined text-[13px]">schedule</span> Medio</span>';
    } else {
      $urgencyPill = '<span class="scm-card-urgency scm-urgency-bajo"><span class="material-symbols-outlined text-[13px]">check</span> Bajo</span>';
    }

    $assigneeName = $empleadoRaw !== '' ? $empleadoRaw : 'Sin asignar';
    $initials = 'SA';
    if ($assigneeName !== 'Sin asignar') {
      $nameParts = preg_split('/\s+/', trim($assigneeName));
      $initials = strtoupper(substr($nameParts[0] ?? '', 0, 1) . substr($nameParts[1] ?? '', 0, 1));
    }

    $timingPills = '<div class="scm-card-timings">';
    if ($tiempoEjecucion !== '' && $tiempoEjecucion !== '-') {
      $timingPills .= '<span class="scm-timing-badge scm-timing-ejecucion" title="Tiempo en ejecución"><span class="material-symbols-outlined text-[13px]">hourglass_top</span> En ejec: ' . esc_html($tiempoEjecucion) . '</span>';
    }
    if ($tiempoSinActualizar !== '' && $tiempoSinActualizar !== '-') {
      $timingPills .= '<span class="scm-timing-badge scm-timing-inactivo" title="Tiempo sin actualizar"><span class="material-symbols-outlined text-[13px]">history</span> Sin act: ' . esc_html($tiempoSinActualizar) . '</span>';
    } else {
      $timingPills .= '<span class="scm-timing-badge scm-timing-ok"><span class="material-symbols-outlined text-[13px]">verified</span> Al día</span>';
    }
    $timingPills .= '</div>';

    $cotChip = ($idCotz !== '')
      ? '<span class="scm-card-chip scm-chip-success">Con Cotización</span>'
      : '<span class="scm-card-chip scm-chip-muted">Sin Cotización</span>';

    $thirdPartyLabel = 'Inquilino';
    $thirdPartyValue = $arrendatarioRaw !== '' ? $arrendatarioRaw : ($ownerLabel !== '' ? $ownerLabel : '');
    if ($arrendatarioRaw === '' && $ownerLabel !== '') {
      $thirdPartyLabel = 'Propietario';
    }

    $c  = '<article class="scm-ticket-card card" data-pk="' . esc_attr((string) $ticketPk) . '">';
    $c .= '<div class="scm-ticket-card-top">';
    $c .= '<div class="scm-ticket-top-badges">';
    $c .= '<span class="scm-ticket-badge badge badge-primary">#' . esc_html($ticketLabel) . '</span>';
    $c .= $urgencyPill;
    $c .= '</div>';
    $c .= '<span class="scm-ticket-time-ago">' . esc_html($timeAgo) . '</span>';
    $c .= '</div>';

    $c .= '<div class="scm-ticket-kicker">' . esc_html(mb_strtoupper($temaLabel, 'UTF-8')) . '</div>';
    $c .= '<h3 class="scm-ticket-title">' . esc_html($asuntoRaw !== '' ? $asuntoRaw : '-') . '</h3>';

    $c .= '<div class="scm-ticket-meta-list">';
    $c .= '<div class="scm-ticket-meta-row"><span class="scm-ticket-meta-label"><span class="material-symbols-outlined text-[15px]">description</span> Contrato:</span><strong class="scm-ticket-meta-value">' . esc_html($contratoLabel) . '</strong></div>';
    $c .= '<div class="scm-ticket-meta-row"><span class="scm-ticket-meta-label"><span class="material-symbols-outlined text-[15px]">domain</span> Inmueble simi:</span><strong class="scm-ticket-meta-value">' . esc_html($inmuebleRaw !== '' ? $inmuebleRaw : '-') . '</strong></div>';
    if ($thirdPartyValue !== '') {
      $c .= '<div class="scm-ticket-meta-row"><span class="scm-ticket-meta-label"><span class="material-symbols-outlined text-[15px]">person</span> ' . esc_html($thirdPartyLabel) . ':</span><strong class="scm-ticket-meta-value">' . esc_html($thirdPartyValue) . '</strong></div>';
    }
    $c .= '</div>';

    $c .= '<div class="scm-ticket-stakeholder-row">';
    $c .= '<div class="scm-ticket-assignee">';
    $c .= '<div class="scm-ticket-avatar">' . esc_html($initials) . '</div>';
    $c .= '<div class="scm-ticket-assignee-info">';
    $c .= '<strong class="scm-ticket-assignee-name">' . esc_html($assigneeName) . '</strong>';
    $c .= '<span class="scm-ticket-assignee-role">Asignado</span>';
    $c .= '</div>';
    $c .= '</div>';
    $c .= '<div class="scm-ticket-sla-wrap">' . $timingPills . '</div>';
    $c .= '</div>';

    $c .= '<div class="scm-ticket-chips-row">';
    $c .= '<div class="scm-ticket-status-label"><span>Estado:</span> ' . (string) call_user_func($this->estadoBadge, $estadoRaw !== '' ? $estadoRaw : '-') . '</div>';
    $c .= '<div class="scm-ticket-extra-chips">' . $cotChip . '</div>';
    $c .= '</div>';

    $c .= '<div class="scm-ticket-card-footer">';
    if ($effectiveTabKey === 'entrega') {
      $c .= '<button class="btn btn-outline btn-sm" type="button" data-scm-open-card-consultor>Consultor/a de entrega</button>';
      $c .= '<button class="btn btn-outline btn-sm" type="button" data-scm-open-card-llaves>Llaves</button>';
    }
    if (in_array($effectiveStatusBucket, ['postergados', 'cerrados'], true)) {
      $c .= '<button class="btn btn-outline btn-sm scm-activate-ticket-btn" type="button" data-scm-activate-ticket>Activar ticket</button>';
    }
    $c .= '<button class="scm-btn-case btn btn-primary btn-sm scm-btn-ver-detalle" type="button" onclick="scmOpenCase(this)" ' . $dataAttrs . '><span>Ver Detalle</span><span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>';
    $c .= '<button class="btn btn-outline btn-sm scm-btn-card-calendar" type="button" title="Agendar o ver detalle" onclick="scmOpenCase(this)" ' . $dataAttrs . '><span class="material-symbols-outlined text-[18px]">event_available</span></button>';
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
        $url = $this->normalizeTicketAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
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
    $label = $count === 1 ? '1 comunicación registrada' : $count . ' comunicaciones registradas';
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
      $effectiveTabKey = $this->inferTabKeyForRow($tabKey, $row);
      $hitos = (array) call_user_func($this->resolveTimelineHitosForRow, $effectiveTabKey, $row);
      $html .= $this->renderGenericCard($row, $config, $hitos, $effectiveTabKey, $statusBucket);
    }
    return $html;
  }

  private function inferTabKeyForRow(string $tabKey, array $row): string
  {
    if ($tabKey !== 'mis_tickets') {
      return $tabKey;
    }

    $tema = trim((string) ($row['tema_ayuda'] ?? ''));
    $temaKey = mb_strtolower($tema, 'UTF-8');
    $departamentoKey = mb_strtolower(trim((string) ($row['departamento'] ?? '')), 'UTF-8');
    $textKey = mb_strtolower(trim($tema . ' ' . (string) ($row['asunto'] ?? '') . ' ' . (string) ($row['descripcion'] ?? '')), 'UTF-8');

    if (trim((string) ($row['id_revision_preventiva'] ?? '')) !== '' || strpos($textKey, 'preventiva') !== false) {
      return 'preventiva';
    }
    if ($temaKey === 'entrega de inmuebles') {
      return 'entrega';
    }
    if ($temaKey === 'recibo de inmuebles') {
      return 'recibo';
    }
    if ($temaKey === 'contable y tributaria') {
      return 'contable';
    }
    if ($temaKey === 'certificaciones tributarias') {
      return 'certificaciones';
    }
    if (in_array($tema, ['Procesos juridicos', 'Solicitud contractual', 'Solicitud de servicios publicos', 'Retencion de contrato', 'Otros servicios'], true)) {
      return 'contractual';
    }

    $maintenanceTopics = array_map(static fn(string $topic): string => mb_strtolower($topic, 'UTF-8'), \SCM\Repositories\TicketsRepository::MAINTENANCE_TOPICS);
    if ($departamentoKey === 'mantenimiento' || in_array($temaKey, $maintenanceTopics, true) || strpos($textKey, 'reparacion') !== false || strpos($textKey, 'mantenimiento') !== false) {
      return 'mantenimiento';
    }

    return '';
  }

  private function inferStatusBucketForRow(string $statusBucket, array $row): string
  {
    if ($statusBucket !== 'all') {
      return $statusBucket;
    }

    $estado = mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8');
    $estadoAdmin = mb_strtolower(trim((string) ($row['estado_admin_ticket'] ?? $row['estado_administrativo'] ?? $row['estado_admin'] ?? '')), 'UTF-8');
    if (in_array($estado, ['cerrado', 'resuelto', 'finalizado'], true) || in_array($estadoAdmin, ['cerrado', 'resuelto', 'finalizado'], true)) {
      return 'cerrados';
    }
    if ($estadoAdmin === 'postergado') {
      return 'postergados';
    }

    return '';
  }

  private function renderTicketAttachmentsSection($imageRaw, $documentRaw, string $sectionId): string
  {
    $images = $this->extractTicketAttachmentUrls($imageRaw);
    $docs = $this->extractTicketDocuments($documentRaw);
    $fileDocs = [];
    foreach ($docs as $doc) {
      $url = trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? ''));
      if ($url === '') {
        continue;
      }
      if ($this->isImageAttachmentUrl($url)) {
        $images[] = $url;
      } else {
        $fileDocs[] = $doc;
      }
    }
    $images = array_values(array_unique($images));
    if (empty($images) && empty($fileDocs)) {
      return '';
    }

    $html = '<section class="scm-case-history scm-case-documents-section bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs mb-4" id="' . esc_attr($sectionId) . '">';
    $html .= '<h4 class="text-sm font-semibold text-slate-900 mb-3 flex items-center justify-between">'
      . '<span class="flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-[18px]">photo_library</span><span>Evidencias Fotográficas Adjuntas (' . count($images) . ' fotos)</span></span>'
      . '<span class="text-xs text-slate-400 font-normal">Clic para ampliar</span>'
      . '</h4>';
    if (!empty($images)) {
      $html .= '<div class="grid grid-cols-1 sm:grid-cols-3 gap-3">';
      foreach ($images as $idx => $url) {
        $html .= '<div class="group relative rounded-xl overflow-hidden bg-slate-100 border border-slate-200 aspect-video cursor-pointer shadow-2xs">'
          . '<img src="' . esc_url($url) . '" alt="Evidencia ' . ($idx + 1) . '" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">'
          . '<button type="button" class="scm-case-attachment-image-btn absolute inset-0 bg-[#0f1e36]/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="Evidencia #' . ($idx + 1) . '">'
          . '<span class="material-symbols-outlined text-[24px]">zoom_in</span>'
          . '</button>'
          . '<span class="absolute bottom-1.5 left-2 bg-[#0f1e36]/80 backdrop-blur-xs text-white px-2 py-0.5 rounded text-[10px] font-semibold">Evidencia #' . ($idx + 1) . '</span>'
          . '</div>';
      }
      $html .= '</div>';
    }
    if (!empty($fileDocs)) {
      $html .= '<div class="scm-case-document-grid">';
      foreach ($fileDocs as $doc) {
        $label = trim((string) ($doc['nombre_archivo'] ?? ''));
        $url = $this->normalizeTicketAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? '')));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
          continue;
        }
        if ($label === '') {
          $label = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Ver documento';
        }
        $html .= '<button type="button" class="scm-case-action-btn scm-case-document-link" data-scm-open-iframe data-iframe-url="' . esc_url($url) . '" data-iframe-title="' . esc_attr($label) . '">' . esc_html($label) . '</button>';
      }
      $html .= '</div>';
    }
    $html .= '</section>';
    return $html;
  }

  /** @return array<int,string> */
  private function extractTicketAttachmentUrls($raw): array
  {
    if (is_array($raw)) {
      $items = [];
      foreach ($raw as $entry) {
        if (is_array($entry)) {
          $items[] = $entry;
          continue;
        }
        $value = trim((string) $entry);
        if ($value === '') {
          continue;
        }
        if (preg_match('/^[aObis]:/', $value)) {
          $decoded = @unserialize($value, ['allowed_classes' => false]);
          if (is_array($decoded)) {
            $items = array_merge($items, $decoded);
            continue;
          }
        } else {
          $decoded = json_decode($value, true);
          if (is_array($decoded)) {
            $items = array_merge($items, $decoded);
            continue;
          }
        }
        $items[] = $value;
      }
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
      } else {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
          $items = $decoded;
        }
      }
    }

    $out = [];
    foreach ($items as $item) {
      $url = is_array($item)
        ? trim((string) ($item['url'] ?? $item['imagenes'] ?? $item['imagen'] ?? $item['evidencia'] ?? $item['archivo'] ?? $item['media_archivo'] ?? ''))
        : trim((string) $item);
      $url = $this->normalizeTicketAttachmentUrl($url);
      if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $out[] = $url;
      }
    }
    return array_values(array_unique($out));
  }

  private function isImageAttachmentUrl(string $url): bool
  {
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    $query = (string) parse_url($url, PHP_URL_QUERY);
    if ($query !== '') {
      parse_str($query, $parts);
      if (!empty($parts['n'])) {
        $name = basename((string) $parts['n']);
      }
    }

    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff'], true);
  }

  private function normalizeTicketAttachmentUrl(string $url): string
  {
    $url = trim($url);
    if ($url === '') {
      return '';
    }

    if (preg_match('/^\d+$/', $url) && function_exists('wp_get_attachment_url')) {
      $attachmentUrl = (string) wp_get_attachment_url((int) $url);
      if ($attachmentUrl !== '' && filter_var($attachmentUrl, FILTER_VALIDATE_URL)) {
        return $attachmentUrl;
      }
    }
    if (preg_match('/^\d+$/', $url)) {
      $attachmentUrl = $this->resolveTicketAttachmentUrlFromId((int) $url);
      if ($attachmentUrl !== '') {
        return $attachmentUrl;
      }
    }

    if (filter_var($url, FILTER_VALIDATE_URL)) {
      return $url;
    }

    if (strpos($url, 'file.php?') === 0 || strpos($url, 'legacy-file.php?') === 0) {
      return rtrim((string) SCM_BASE_URL, '/') . '/' . $url;
    }
    if (strpos($url, '/file.php?') === 0 || strpos($url, '/legacy-file.php?') === 0) {
      return rtrim((string) SCM_BASE_URL, '/') . $url;
    }

    $fileName = basename((string) parse_url($url, PHP_URL_PATH));
    if ($this->isSafeLegacyAttachmentName($fileName)) {
      return rtrim((string) SCM_BASE_URL, '/') . '/legacy-file.php?n=' . rawurlencode($fileName);
    }

    return $url;
  }

  private function resolveTicketAttachmentUrlFromId(int $attachmentId): string
  {
    if ($attachmentId <= 0) {
      return '';
    }

    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
      return '';
    }

    try {
      $postsTable = (string) ($wpdb->posts ?? (($wpdb->prefix ?? 'wp_') . 'posts'));
      $url = trim((string) $wpdb->get_var($wpdb->prepare(
        "SELECT `guid` FROM `{$postsTable}` WHERE `ID` = %d AND TRIM(COALESCE(`guid`, '')) <> '' LIMIT 1",
        $attachmentId
      )));
      return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    } catch (\Throwable $e) {
      return '';
    }
  }

  private function isSafeLegacyAttachmentName(string $fileName): bool
  {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName)) {
      return false;
    }

    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'bmp', 'heic', 'heif', 'tif', 'tiff'], true);
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
        $url = $this->normalizeTicketAttachmentUrl(trim((string) ($doc['archivo'] ?? $doc['media_archivo'] ?? $doc['url'] ?? '')));
      } else {
        $url = $this->normalizeTicketAttachmentUrl(trim((string) $doc));
      }
      if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $out[] = ['nombre_archivo' => $label, 'media_archivo' => $url, 'archivo' => $url];
    }
    return $out;
  }
}
