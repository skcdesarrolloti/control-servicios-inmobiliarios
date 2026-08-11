<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait RendersDashboard
{
  public function renderPanel(array $overrideConfig = []): string
  {
    $config = array_merge([
      'ticket_url'     => self::DEFAULT_TICKET_URL,
      'preventiva_url' => self::DEFAULT_PREVENTIVA_URL,
      'correctiva_url' => self::DEFAULT_CORRECTIVA_URL,
      'cotizacion_url' => self::DEFAULT_COTIZACION_URL,
      'acta_url'       => self::DEFAULT_ACTA_URL,
    ], $overrideConfig);

    $tabla = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($tabla)) {
      return '<div class="scm-error">No existe la tabla ' . self::h($tabla) . '.</div>';
    }

    $module = $this->get_servicios_inmobiliarios_module();
    $filterOptions = $module->getFilterOptions();
    $params = $module->parseParams($_GET);
    $result = $module->run($params, $config);
    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $tbodyHtml = (string)($result['tbody'] ?? '');
    $paginationHtml = (string)($result['pagination_html'] ?? '');

    $genericTabDefs = $this->get_generic_tab_definitions();
    $tabRaw = trim((string) ($_GET['scm_tab'] ?? ($_GET['tab'] ?? '')));
    $tabKey = mb_strtolower($tabRaw, 'UTF-8');
    $openTopicAliases = [
      'mant' => 'mant',
      'mantenimiento' => 'mant',
      'scm-panel-mant' => 'mant',
      'revision_preventiva' => 'preventiva',
      'revisión preventiva' => 'preventiva',
      'revision preventiva' => 'preventiva',
      'preventiva' => 'preventiva',
      'scm-panel-preventiva' => 'preventiva',
      'entrega' => 'entrega',
      'scm-panel-entrega' => 'entrega',
      'recibo' => 'recibo',
      'scm-panel-recibo' => 'recibo',
      'contractual' => 'contractual',
      'scm-panel-contractual' => 'contractual',
      'contable' => 'contable',
      'scm-panel-contable' => 'contable',
      'certificaciones' => 'certificaciones',
      'scm-panel-certificaciones' => 'certificaciones',
    ];
    $initialOpenTopic = $openTopicAliases[$tabKey] ?? '';

    $genericResults = [];
    foreach ($genericTabDefs as $gTabKey => $gTabDef) {
      $gParams = $this->parse_params_generic($_GET, $gTabDef['prefix']);
      $hydrateGenericRows = $initialOpenTopic !== '' && $initialOpenTopic === $gTabKey;
      $gResult = $this->run_query_generic($gTabDef['temas'], $gParams, $config, $hydrateGenericRows);
      $genericResults[$gTabKey] = ['params' => $gParams, 'result' => $gResult, 'def' => $gTabDef, 'loaded' => $hydrateGenericRows];
    }

    $statusBucketDefs = $this->get_status_bucket_definitions();
    $statusTopicDefs = $this->get_status_topic_definitions();
    $statusResults = $this->build_status_bucket_results($statusBucketDefs, $statusTopicDefs, $config, $filterOptions, false);
    $openTopicDefs = [
      'mant' => ['label' => 'Mantenimiento'],
      'preventiva' => ['label' => 'Preventiva'],
      'entrega' => ['label' => 'Entrega'],
      'recibo' => ['label' => 'Recibo'],
      'contractual' => ['label' => 'Contractual'],
      'contable' => ['label' => 'Contable'],
      'certificaciones' => ['label' => 'Certificaciones'],
    ];

    $pendingController = $this->get_pending_controller();
    $preventivasPendientesHtml = $pendingController->renderPreventivasTab($_GET);
    $serviciosPublicosPendientesHtml = $pendingController->renderServiciosPublicosTab($_GET);
    $reportesAdministrativosPendientesHtml = $pendingController->renderReportesAdministrativosTab();
    $contratosArrendamientoHtml = $pendingController->renderContratosArrendamientoTab();
    $publicPqrEmployeeFilter = trim((string) ($_GET['id_empleado'] ?? ''));
    $publicPqrEmployeeFilter = preg_replace('/[^A-Za-z0-9_-]/', '', $publicPqrEmployeeFilter);
    if (!is_string($publicPqrEmployeeFilter)) {
      $publicPqrEmployeeFilter = '';
    }
    $publicPqrFilters = $this->get_public_pqr_filters_from_request();

    $tabMap = [
      'abiertos' => 'scm-panel-abiertos',
      'abierto' => 'scm-panel-abiertos',
      'scm-panel-abiertos' => 'scm-panel-abiertos',
      'pqr_publico' => 'scm-panel-pqr-publico',
      'pqr-publico' => 'scm-panel-pqr-publico',
      'solicitudes_web' => 'scm-panel-pqr-publico',
      'solicitudes-web' => 'scm-panel-pqr-publico',
      'scm-panel-pqr-publico' => 'scm-panel-pqr-publico',
      'postergados' => 'scm-panel-postergados',
      'scm-panel-postergados' => 'scm-panel-postergados',
      'cerrados' => 'scm-panel-cerrados',
      'scm-panel-cerrados' => 'scm-panel-cerrados',
      'contratos_arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
      'contratos' => 'scm-panel-contratos-arrendamiento',
      'scm-panel-contratos-arrendamiento' => 'scm-panel-contratos-arrendamiento',
    ];
    $initialTab = $tabMap[$tabKey] ?? '';
    if ($initialTab === '' && $initialOpenTopic !== '') {
      $initialTab = 'scm-panel-abiertos';
    }
    if ($initialTab === '' && ($publicPqrEmployeeFilter !== '' || $publicPqrFilters['estado'] !== '' || $publicPqrFilters['empleado'] !== '')) {
      $initialTab = 'scm-panel-pqr-publico';
    }
    $iframeModeRaw = mb_strtolower(trim((string) ($_GET['scm_iframe'] ?? ($_GET['iframe'] ?? ''))), 'UTF-8');
    $iframeMode = in_array($iframeModeRaw, ['1', 'true', 'yes', 'si'], true);

    $publicPqrRows = $this->fetch_public_pqr_rows(180, $publicPqrEmployeeFilter, $publicPqrFilters);
    $publicPqrHtml = $this->render_public_pqr_tab(
      $publicPqrRows,
      (array) ($filterOptions['funcionarios'] ?? []),
      (string) ($config['ticket_url'] ?? self::DEFAULT_TICKET_URL),
      $publicPqrEmployeeFilter,
      false,
      [],
      $publicPqrFilters
    );

    $categoryMetrics = [
      'Mantenimiento' => (int)($stats['total'] ?? 0),
      'Entrega' => (int)($genericResults['entrega']['result']['stats']['total'] ?? 0),
      'Preventiva' => (int)($genericResults['preventiva']['result']['stats']['total'] ?? 0),
      'Recibo' => (int)($genericResults['recibo']['result']['stats']['total'] ?? 0),
      'Contable' => (int)($genericResults['contable']['result']['stats']['total'] ?? 0),
      'Certificaciones' => (int)($genericResults['certificaciones']['result']['stats']['total'] ?? 0),
      'Contractual' => (int)($genericResults['contractual']['result']['stats']['total'] ?? 0),
    ];

    $nonce = \SCM\Core\App::csrf()->token(self::NONCE_KEY);
    $apiUrl = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/api.php') : '/api.php';
    $runtimeData = [
      'ajaxUrl'      => $apiUrl,
      'nonce'        => $nonce,
      'config'       => $config,
      'indicativos'  => $this->getIndicativoOptions(),
      'funcionarios' => $filterOptions['funcionarios'] ?? [],
      'initialTab' => $initialTab,
      'initialOpenTopic' => $initialOpenTopic,
      'iframeMode' => $iframeMode,
      'publicPqrEmployeeFilter' => $publicPqrEmployeeFilter,
      'guide'   => [
        'enabled' => true,
        'title'   => 'Guia de uso',
        'html'    => '<ul><li>Usa filtros para acotar resultados.</li><li>Atraso ticket = tiempo desde creacion.</li><li>Sin actualizar = tiempo desde ultima actualizacion.</li></ul>',
      ],
      'actions' => [
        'mant'            => self::AJAX_ACTION,
        'seg'             => self::AJAX_SEGUIMIENTO,
        'nota'            => self::AJAX_NOTA,
        'ticket_response' => self::AJAX_TICKET_RESPONSE,
        'postpone_ticket' => self::AJAX_POSTPONE_TICKET,
        'status_tickets'  => self::AJAX_STATUS_TICKETS,
        'activate_ticket' => self::AJAX_ACTIVATE_TICKET,
        'cotizacion_response' => self::AJAX_COTIZACION_RESPONSE,
        'close_ticket'    => self::AJAX_CLOSE_TICKET,
        'contacts_update' => self::AJAX_CONTACTS_UPDATE,
        'entrega'         => self::AJAX_ENTREGA,
        'preventiva'      => self::AJAX_PREVENTIVA,
        'recibo'          => self::AJAX_RECIBO,
        'contable'        => self::AJAX_CONTABLE,
        'certificaciones' => self::AJAX_CERTIFICACIONES,
        'contractual'     => self::AJAX_CONTRACTUAL,
        'preventivas_pendientes' => self::AJAX_PREVENTIVAS_PENDIENTES,
        'servicios_publicos_pendientes' => self::AJAX_SERVICIOS_PUBLICOS_PENDIENTES,
        'reportes_administrativos_pendientes' => self::AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES,
        'contratos_arrendamiento' => self::AJAX_CONTRATOS_ARRENDAMIENTO,
        'crear_ticket_administrativo' => self::AJAX_CREAR_TICKET_ADMINISTRATIVO,
        'aprobar_reporte_administrativo' => self::AJAX_APROBAR_REPORTE_ADMINISTRATIVO,
        'desaprobar_reporte_administrativo' => self::AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO,
        'damage_magnitude' => self::AJAX_DAMAGE_MAGNITUDE,
        'classify_magnitude' => self::AJAX_CLASSIFY_MAGNITUDE,
        'save_case_magnitude' => self::AJAX_SAVE_CASE_MAGNITUDE,
        'save_property_location' => self::AJAX_SAVE_PROPERTY_LOCATION,
        'trasladar_caso' => self::AJAX_TRASLADAR_CASO,
        'asignar_pqr_publico' => self::AJAX_ASIGNAR_PQR_PUBLICO,
        'filtrar_pqr_publico' => self::AJAX_FILTER_PQR_PUBLICO,
        'guardar_corresponsable_pqr_publico' => self::AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO,
        'notif_responsable_pqr' => self::AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR,
        // Guía
        'guide_gcd_read' => self::AJAX_GUIDE_GCD_READ,
        'guide_gcd_save' => self::AJAX_GUIDE_GCD_SAVE,
        'guide_gcd_del'  => self::AJAX_GUIDE_GCD_DEL,
        'guide_grt_read' => self::AJAX_GUIDE_GRT_READ,
        'guide_grt_save' => self::AJAX_GUIDE_GRT_SAVE,
        'guide_grt_del'  => self::AJAX_GUIDE_GRT_DEL,
        'guide_gac_read' => self::AJAX_GUIDE_GAC_READ,
        'guide_gac_save' => self::AJAX_GUIDE_GAC_SAVE,
        'guide_gac_del'  => self::AJAX_GUIDE_GAC_DEL,
        'guide_gac_cats' => self::AJAX_GUIDE_GAC_CATS,
      ],
    ];

    $metricsData = [
      'total'          => (int)($stats['total'] ?? 0),
      'abiertos'       => (int)($stats['abiertos'] ?? 0),
      'cerrados'       => (int)($stats['cerrados'] ?? 0),
      'sla_vencido'    => (int)($stats['sla_vencido'] ?? 0),
      'sla_riesgo'     => (int)($stats['sla_riesgo'] ?? 0),
      'con_cotizacion' => (int)($stats['con_cotizacion'] ?? 0),
      'sin_cotizacion' => (int)($stats['sin_cotizacion'] ?? 0),
      'con_revision'   => (int)($stats['con_revision'] ?? 0),
      'sin_revision'   => (int)($stats['sin_revision'] ?? 0),
      'avg_first_h'    => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? (float)$stats['avg_first_h'] : null,
      'avg_close_h'    => isset($stats['avg_close_h']) && is_numeric($stats['avg_close_h']) ? (float)$stats['avg_close_h'] : null,
      'avg_stale_h'    => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? (float)$stats['avg_stale_h'] : null,
      'mes_actualizados' => (int)($stats['mes_actualizados'] ?? 0),
      'mes_cerrados' => (int)($stats['mes_cerrados'] ?? 0),
      'mes_seguimientos' => (int)($stats['mes_seguimientos'] ?? 0),
      'por_categoria' => $categoryMetrics,
      'detalle_por_categoria' => [
        'mantenimiento' => [
          'label' => 'Mantenimiento',
          'total' => (int)($stats['total'] ?? 0),
          'abiertos' => (int)($stats['abiertos'] ?? 0),
          'cerrados' => (int)($stats['cerrados'] ?? 0),
          'sla_vencido' => (int)($stats['sla_vencido'] ?? 0),
          'sla_riesgo' => (int)($stats['sla_riesgo'] ?? 0),
          'con_cotizacion' => (int)($stats['con_cotizacion'] ?? 0),
          'sin_cotizacion' => (int)($stats['sin_cotizacion'] ?? 0),
          'con_revision' => (int)($stats['con_revision'] ?? 0),
          'sin_revision' => (int)($stats['sin_revision'] ?? 0),
          'avg_first_h' => isset($stats['avg_first_h']) && is_numeric($stats['avg_first_h']) ? (float)$stats['avg_first_h'] : 0.0,
          'avg_close_h' => isset($stats['avg_close_h']) && is_numeric($stats['avg_close_h']) ? (float)$stats['avg_close_h'] : 0.0,
          'avg_stale_h' => isset($stats['avg_stale_h']) && is_numeric($stats['avg_stale_h']) ? (float)$stats['avg_stale_h'] : 0.0,
          'mes_actualizados' => (int)($stats['mes_actualizados'] ?? 0),
          'mes_cerrados' => (int)($stats['mes_cerrados'] ?? 0),
          'mes_seguimientos' => (int)($stats['mes_seguimientos'] ?? 0),
          'seg_por_funcionario' => is_array($stats['seg_por_funcionario'] ?? null) ? $stats['seg_por_funcionario'] : [],
          'abiertos_por_funcionario' => is_array($stats['abiertos_por_funcionario'] ?? null) ? $stats['abiertos_por_funcionario'] : [],
          'actualizados_por_funcionario' => is_array($stats['actualizados_por_funcionario'] ?? null) ? $stats['actualizados_por_funcionario'] : [],
        ],
        'entrega' => ['label' => 'Entrega'] + (array)($genericResults['entrega']['result']['stats'] ?? []),
        'preventiva' => ['label' => 'Preventiva'] + (array)($genericResults['preventiva']['result']['stats'] ?? []),
        'recibo' => ['label' => 'Recibo'] + (array)($genericResults['recibo']['result']['stats'] ?? []),
        'contable' => ['label' => 'Contable'] + (array)($genericResults['contable']['result']['stats'] ?? []),
        'certificaciones' => ['label' => 'Certificaciones'] + (array)($genericResults['certificaciones']['result']['stats'] ?? []),
        'contractual' => ['label' => 'Contractual'] + (array)($genericResults['contractual']['result']['stats'] ?? []),
      ],
    ];

    $runtimeJson   = json_encode($runtimeData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $metricsJson   = json_encode($metricsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $assetBaseUrl  = defined('SCM_BASE_URL') ? (SCM_BASE_URL . '/') : '/';
    $assetBasePath = defined('SCM_BASE_PATH') ? (SCM_BASE_PATH . DIRECTORY_SEPARATOR) : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

    $cssRel = 'assets/css/scm-admin.css';
    $jsRel  = 'assets/js/scm-admin.js';
    $dashboardRuntimeJsRel = 'assets/js/admin-dashboard-runtime.js';
    $guideJsRel = 'assets/js/admin-guide.js';
    $damageCssRel = 'assets/css/scm-damage-magnitude.css';
    $damageJsRel  = 'assets/js/scm-damage-magnitude.js';
    $cssPath = $assetBasePath . $cssRel;
    $jsPath  = $assetBasePath . $jsRel;
    $dashboardRuntimeJsPath = $assetBasePath . $dashboardRuntimeJsRel;
    $guideJsPath = $assetBasePath . $guideJsRel;
    $damageCssPath = $assetBasePath . $damageCssRel;
    $damageJsPath  = $assetBasePath . $damageJsRel;
    $cssVer = file_exists($cssPath) ? (string)filemtime($cssPath) : SCM_VERSION;
    $jsVer  = file_exists($jsPath)  ? (string)filemtime($jsPath)  : SCM_VERSION;
    $dashboardRuntimeJsVer = file_exists($dashboardRuntimeJsPath) ? (string)filemtime($dashboardRuntimeJsPath) : SCM_VERSION;
    $guideJsVer = file_exists($guideJsPath) ? (string)filemtime($guideJsPath) : SCM_VERSION;
    $damageCssVer = file_exists($damageCssPath) ? (string)filemtime($damageCssPath) : SCM_VERSION;
    $damageJsVer  = file_exists($damageJsPath)  ? (string)filemtime($damageJsPath)  : SCM_VERSION;
    $cssUrl = self::h($assetBaseUrl . $cssRel . '?v=' . rawurlencode($cssVer));
    $jsUrl  = self::h($assetBaseUrl . $jsRel  . '?v=' . rawurlencode($jsVer));
    $dashboardRuntimeJsUrl = self::h($assetBaseUrl . $dashboardRuntimeJsRel . '?v=' . rawurlencode($dashboardRuntimeJsVer));
    $guideJsUrl = self::h($assetBaseUrl . $guideJsRel . '?v=' . rawurlencode($guideJsVer));
    $damageCssUrl = self::h($assetBaseUrl . $damageCssRel . '?v=' . rawurlencode($damageCssVer));
    $damageJsUrl  = self::h($assetBaseUrl . $damageJsRel  . '?v=' . rawurlencode($damageJsVer));

    ob_start();
  ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" media="all">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" media="all">
    <link rel="stylesheet" href="<?php echo $cssUrl; ?>" media="all">
    <link rel="stylesheet" href="<?php echo $damageCssUrl; ?>" media="all">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy" data-scm-runtime="<?php echo self::h((string)$runtimeJson); ?>">
      <div class="scm-guide-bar">
        <button class="scm-guide-btn" type="button" id="scm-open-guide"><i class="fas fa-book-open"></i> Ver gu&iacute;as</button>
      </div>
      <div class="scm-tabs scm-main-tabs">
        <button class="scm-tab active" data-tab="scm-panel-abiertos" type="button">Abiertos</button>
        <button class="scm-tab" data-tab="scm-panel-postergados" type="button">Postergados</button>
        <button class="scm-tab" data-tab="scm-panel-cerrados" type="button">Cerrados</button>
        <button class="scm-tab" data-tab="scm-panel-preventivas-pendientes" type="button">Preventivas Pendientes</button>
        <button class="scm-tab" data-tab="scm-panel-contratos-arrendamiento" type="button">Contratos de arrendamiento</button>
        <button class="scm-tab" data-tab="scm-panel-servicios-publicos-pendientes" type="button">Servicios P&uacute;blicos Pendientes</button>
        <button class="scm-tab" data-tab="scm-panel-reportes-administrativos-pendientes" type="button">Reportes Administrativos</button>
        <button class="scm-tab" data-tab="scm-panel-pqr-publico" type="button">Solicitudes Web</button>
        <button class="scm-tab" data-tab="scm-panel-metricas" type="button">Metricas</button>
      </div>

      <?php $activeOpenTopic = isset($openTopicDefs[$initialOpenTopic]) ? $initialOpenTopic : 'mant'; ?>
      <div class="scm-tab-panel active" id="scm-panel-abiertos">
        <div class="scm-status-bucket scm-open-bucket" data-open-bucket="abiertos">
          <div class="scm-status-subtabs scm-open-subtabs" role="tablist" aria-label="Tickets abiertos">
            <?php foreach ($openTopicDefs as $openTopicKey => $openTopicDef): ?>
              <button class="scm-status-topic-tab scm-open-topic-tab<?php echo $activeOpenTopic === $openTopicKey ? ' active' : ''; ?>" type="button" data-open-target="<?php echo esc_attr($openTopicKey); ?>"><?php echo esc_html((string)($openTopicDef['label'] ?? $openTopicKey)); ?></button>
            <?php endforeach; ?>
          </div>

      <div class="scm-open-topic-panel<?php echo $activeOpenTopic === 'mant' ? ' active' : ''; ?>" id="scm-panel-mant" data-open-topic="mant" data-scm-loaded="1">
        <div class="scm-kpis scm-kpis-daisy">
          <div class="scm-kpi">
            <div class="scm-kpi-label">Total</div>
            <div class="scm-kpi-value" id="scm-kpi-total"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Con cotizaci&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-con-cotz"><?php echo esc_html((string)($stats['con_cotizacion'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Sin cotizaci&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-sin-cotz"><?php echo esc_html((string)($stats['sin_cotizacion'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Con revisi&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-con-prev"><?php echo esc_html((string)($stats['con_revision'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi">
            <div class="scm-kpi-label">Sin revisi&oacute;n</div>
            <div class="scm-kpi-value" id="scm-kpi-sin-prev"><?php echo esc_html((string)($stats['sin_revision'] ?? 0)); ?></div>
          </div>

          <div class="scm-kpi scm-kpi-magnitud scm-kpi-critico">
            <div class="scm-kpi-label">Cr&iacute;ticos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-critico"><?php echo esc_html((string)($stats['magnitud_critico'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-alto">
            <div class="scm-kpi-label">Altos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-alto"><?php echo esc_html((string)($stats['magnitud_alto'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-medio">
            <div class="scm-kpi-label">Medios</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-medio"><?php echo esc_html((string)($stats['magnitud_medio'] ?? 0)); ?></div>
          </div>
          <div class="scm-kpi scm-kpi-magnitud scm-kpi-bajo">
            <div class="scm-kpi-label">Bajos</div>
            <div class="scm-kpi-value" id="scm-kpi-magnitud-bajo"><?php echo esc_html((string)($stats['magnitud_bajo'] ?? 0)); ?></div>
          </div>
        </div>

        <div class="scm-filter-card card">
          <h3>Filtros</h3>
          <form id="scm-form" autocomplete="off">
            <input type="hidden" id="scm_page" name="scm_page" value="<?php echo esc_attr((string)($params['fPage'] ?? 1)); ?>">
            <div class="scm-grid">
              <div class="scm-field"><label for="scm_estado">Estado</label><select id="scm_estado" name="scm_estado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions['estado'] ?? []) as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected(($params['fEstado'] ?? ''), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_estado_admin">Estado administrativo</label><select id="scm_estado_admin" name="scm_estado_admin" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["estado_admin"] ?? []) as $opt): ?><option value="<?php echo esc_attr($opt); ?>" <?php selected(($params["fEstadoAdmin"] ?? ""), $opt); ?>><?php echo esc_html($opt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_id_empleado">Funcionario</label><select id="scm_id_empleado" name="scm_id_empleado" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["funcionarios"] ?? []) as $func): $fId = trim((string)($func["id"] ?? ""));
                                                    if ($fId === "") continue; ?>
                    <option value="<?php echo esc_attr($fId); ?>" <?php selected(($params["fEmpleado"] ?? ""), $fId); ?>><?php echo esc_html((string)($func["label"] ?? $fId)); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_busqueda">B&uacute;squeda</label><input id="scm_busqueda" name="scm_busqueda" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params['fBusqueda'] ?? '')); ?>"></div>
              <div class="scm-field"><label for="scm_inmueble">Inmueble</label><input id="scm_inmueble" name="scm_inmueble" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params['fInmueble'] ?? '')); ?>"></div>
              <div class="scm-field"><label for="scm_contrato">Contrato</label><input id="scm_contrato" name="scm_contrato" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fContrato"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_asunto">Asunto</label><input id="scm_asunto" name="scm_asunto" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fAsunto"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_arrendatario">Arrendatario</label><input id="scm_arrendatario" name="scm_arrendatario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fArrendatario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_propietario">Propietario</label><input id="scm_propietario" name="scm_propietario" class="input input-bordered input-sm scm-input" type="text" value="<?php echo esc_attr((string)($params["fPropietario"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_barrio">Barrio</label><select id="scm_barrio" name="scm_barrio" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option><?php foreach (($filterOptions["barrios"] ?? []) as $barrioOpt): ?><option value="<?php echo esc_attr((string)$barrioOpt); ?>" <?php selected((string)($params["fBarrio"] ?? ""), (string)$barrioOpt); ?>><?php echo esc_html((string)$barrioOpt); ?></option><?php endforeach; ?>
                </select></div>
              <div class="scm-field"><label for="scm_fecha_desde">Fecha desde</label><input id="scm_fecha_desde" name="scm_fecha_desde" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($params["fFechaDesde"] ?? "")); ?>"></div>
              <div class="scm-field"><label for="scm_fecha_hasta">Fecha hasta</label><input id="scm_fecha_hasta" name="scm_fecha_hasta" class="input input-bordered input-sm scm-input" type="date" value="<?php echo esc_attr((string)($params["fFechaHasta"] ?? "")); ?>"></div>

              <div class="scm-field"><label for="scm_cotizacion">Cotizaci&oacute;n</label><select id="scm_cotizacion" name="scm_cotizacion" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(($params['fCotizacion'] ?? ''), 'has'); ?>>Con cotizaci&oacute;n</option>
                  <option value="none" <?php selected(($params['fCotizacion'] ?? ''), 'none'); ?>>Sin cotizaci&oacute;n</option>
                </select></div>
              <div class="scm-field"><label for="scm_perturbacion">Perturbaci&oacute;n</label><select id="scm_perturbacion" name="scm_perturbacion" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(strtolower((string) ($params['fPerturbacion'] ?? '')), 'has'); ?>>Con perturbaci&oacute;n</option>
                  <option value="none" <?php selected(strtolower((string) ($params['fPerturbacion'] ?? '')), 'none'); ?>>Sin perturbaci&oacute;n</option>
                </select></div>
              <div class="scm-field"><label for="scm_revision">Revisi&oacute;n</label><select id="scm_revision" name="scm_revision" class="select select-bordered select-sm scm-select">
                  <option value="">Todas</option>
                  <option value="has" <?php selected(($params['fRevision'] ?? ''), 'has'); ?>>Con revisi&oacute;n</option>
                  <option value="none" <?php selected(($params['fRevision'] ?? ''), 'none'); ?>>Sin revisi&oacute;n</option>
                </select></div>
              <div class="scm-field"><label for="scm_atraso">Atraso ticket</label><select id="scm_atraso" name="scm_atraso" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="3" <?php selected(($params['fAtraso'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
                  <option value="5" <?php selected(($params['fAtraso'] ?? ''), '5'); ?>>+5 d&iacute;as</option>
                  <option value="10" <?php selected(($params['fAtraso'] ?? ''), '10'); ?>>+10 d&iacute;as</option>
                </select></div>
              <div class="scm-field"><label for="scm_sin_actualizar">Sin actualizar</label><select id="scm_sin_actualizar" name="scm_sin_actualizar" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="1" <?php selected(($params['fSinActualizar'] ?? ''), '1'); ?>>+1 d&iacute;a</option>
                  <option value="3" <?php selected(($params['fSinActualizar'] ?? ''), '3'); ?>>+3 d&iacute;as</option>
                  <option value="7" <?php selected(($params['fSinActualizar'] ?? ''), '7'); ?>>+7 d&iacute;as</option>
                </select></div>
              <div class="scm-field"><label for="scm_tuvo_seguimiento">Tuvo seguimiento</label><select id="scm_tuvo_seguimiento" name="scm_tuvo_seguimiento" class="select select-bordered select-sm scm-select">
                  <option value="">Todos</option>
                  <option value="Si" <?php selected(($params['fTuvoSeguimiento'] ?? ''), 'Si'); ?>>Si</option>
                  <option value="No" <?php selected(($params['fTuvoSeguimiento'] ?? ''), 'No'); ?>>No</option>
                </select></div>
              <div class="scm-field"><label for="scm_magnitud_caso">Magnitud caso</label><select id="scm_magnitud_caso" name="scm_magnitud_caso" class="select select-bordered select-sm scm-select">
                  <option value="">Todas las magnitudes</option>
                  <option value="critico" <?php selected(($params['fMagnitudCaso'] ?? ''), 'critico'); ?>>Cr&iacute;tico</option>
                  <option value="alto" <?php selected(($params['fMagnitudCaso'] ?? ''), 'alto'); ?>>Alto</option>
                  <option value="medio" <?php selected(($params['fMagnitudCaso'] ?? ''), 'medio'); ?>>Medio</option>
                  <option value="bajo" <?php selected(($params['fMagnitudCaso'] ?? ''), 'bajo'); ?>>Bajo</option>
                </select></div>
            </div>
            <div class="scm-actions">
              <button class="scm-btn-primary btn btn-primary" type="submit">Filtrar</button>
              <button class="scm-btn-secondary btn btn-outline scm-classify-magnitude" type="button" data-revision-type="correctiva">Calcular magnitud da&ntilde;o</button>
              <button class="scm-btn-secondary btn btn-outline" type="button" id="scm-clear">Limpiar</button>
              <span class="scm-spinner" id="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
            </div>
          </form>
        </div>

        <div class="scm-cards-wrap">
          <div class="scm-ticket-cards" id="scm-tbody"><?php echo $tbodyHtml; ?></div>
        </div>
        <div class="scm-pagination" id="scm-pagination"><?php echo $paginationHtml; ?></div>
      </div>

      <?php foreach (['preventiva', 'entrega', 'recibo', 'contractual', 'contable', 'certificaciones'] as $gTabKey): $gTabData = $genericResults[$gTabKey];
        $gParams = $gTabData['params'];
        $gRows = $gTabData['result']['rows'];
        $gStats = $gTabData['result']['stats'];
        $gLoaded = !empty($gTabData['loaded']);
        $gPagination = is_array($gTabData['result']['pagination'] ?? null) ? $gTabData['result']['pagination'] : ['page' => 1, 'total_pages' => 1, 'total' => 0];
        $gCardsHtml = $gLoaded ? $this->render_generic_cards($gRows, $config, $gTabKey) : $this->render_lazy_tickets_placeholder();
        $showTema = false;
        $gFilterOptions = $this->get_generic_tickets_module()->get_generic_filter_options($gTabData['def']['temas']);
        $gFormHtml = $this->render_generic_filter_form($gTabKey, $gTabData['def']['prefix'], $gParams, $showTema, ['Procesos juridicos', 'Solicitud contractual', 'Solicitud de servicios publicos', 'Retencion de contrato', 'Otros servicios'], $gFilterOptions);
        $gPaginationHtml = $gLoaded ? $this->get_generic_tickets_module()->render_generic_pagination($gTabKey, $gPagination) : ''; ?>
        <div class="scm-open-topic-panel<?php echo $activeOpenTopic === $gTabKey ? ' active' : ''; ?>" id="scm-panel-<?php echo esc_attr($gTabKey); ?>" data-open-topic="<?php echo esc_attr($gTabKey); ?>" data-scm-loaded="<?php echo $gLoaded ? '1' : '0'; ?>">
          <span id="scm-<?php echo esc_attr($gTabKey); ?>-count" style="display:none;"><?php echo esc_html((string)($gStats['total'] ?? 0)); ?></span>
          <div class="scm-kpis scm-kpis-daisy">
            <div class="scm-kpi">
              <div class="scm-kpi-label">Total</div>
              <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-total"><?php echo esc_html((string)($gStats['total'] ?? 0)); ?></div>
            </div>
            <?php if ($gTabKey === 'entrega'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n de entrega</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-con-rev-entrega"><?php echo esc_html((string)($gStats['con_revision_entrega'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n de entrega</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-sin-rev-entrega"><?php echo esc_html((string)($gStats['sin_revision_entrega'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con inventario</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-con-inventario"><?php echo esc_html((string)($gStats['con_inventario'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin inventario</div>
                <div class="scm-kpi-value" id="scm-entrega-kpi-sin-inventario"><?php echo esc_html((string)($gStats['sin_inventario'] ?? 0)); ?></div>
              </div>
            <?php elseif (in_array($gTabKey, ['contractual', 'contable', 'certificaciones'], true)): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Nuevo</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-nuevo"><?php echo esc_html((string)($gStats['estado_nuevo'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">En proceso</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-en-proceso"><?php echo esc_html((string)($gStats['estado_en_proceso'] ?? 0)); ?></div>
              </div>
            <?php elseif ($gTabKey === 'recibo'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con cita</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-con-cita"><?php echo esc_html((string)($gStats['con_cita'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin cita</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-sin-cita"><?php echo esc_html((string)($gStats['sin_cita'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n de recibo</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-con-rev-recibo"><?php echo esc_html((string)($gStats['con_revision_recibo'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n de recibo</div>
                <div class="scm-kpi-value" id="scm-recibo-kpi-sin-rev-recibo"><?php echo esc_html((string)($gStats['sin_revision_recibo'] ?? 0)); ?></div>
              </div>
            <?php else: ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con cotizaci&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-con-cotz"><?php echo esc_html((string)($gStats['con_cotizacion'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin cotizaci&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-sin-cotz"><?php echo esc_html((string)($gStats['sin_cotizacion'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con revisi&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-con-prev"><?php echo esc_html((string)($gStats['con_revision'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin revisi&oacute;n</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-sin-prev"><?php echo esc_html((string)($gStats['sin_revision'] ?? 0)); ?></div>
              </div>
            <?php endif; ?>

            <?php if ($gTabKey === 'preventiva'): ?>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Con da&ntilde;os</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-danos-si"><?php echo esc_html((string)($gStats['danos_si'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi">
                <div class="scm-kpi-label">Sin da&ntilde;os</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-danos-no"><?php echo esc_html((string)($gStats['danos_no'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-critico">
                <div class="scm-kpi-label">Cr&iacute;ticos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-critico"><?php echo esc_html((string)($gStats['magnitud_critico'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-alto">
                <div class="scm-kpi-label">Altos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-alto"><?php echo esc_html((string)($gStats['magnitud_alto'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-medio">
                <div class="scm-kpi-label">Medios</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-medio"><?php echo esc_html((string)($gStats['magnitud_medio'] ?? 0)); ?></div>
              </div>
              <div class="scm-kpi scm-kpi-magnitud scm-kpi-bajo">
                <div class="scm-kpi-label">Bajos</div>
                <div class="scm-kpi-value" id="scm-<?php echo esc_attr($gTabKey); ?>-kpi-magnitud-bajo"><?php echo esc_html((string)($gStats['magnitud_bajo'] ?? 0)); ?></div>
              </div>
            <?php endif; ?>
          </div>
          <?php echo $gFormHtml; ?>
          <div class="scm-cards-wrap">
            <div class="scm-ticket-cards" id="scm-cards-<?php echo esc_attr($gTabKey); ?>"><?php echo $gCardsHtml; ?></div>
          </div>
          <div class="scm-pagination" id="scm-pagination-<?php echo esc_attr($gTabKey); ?>"><?php echo $gPaginationHtml; ?></div>
        </div>
      <?php endforeach; ?>
        </div>
      </div>

      <div class="scm-tab-panel" id="scm-panel-preventivas-pendientes">
        <?php echo $preventivasPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-contratos-arrendamiento">
        <?php echo $contratosArrendamientoHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-servicios-publicos-pendientes">
        <?php echo $serviciosPublicosPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-reportes-administrativos-pendientes">
        <?php echo $reportesAdministrativosPendientesHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-pqr-publico">
        <?php echo $publicPqrHtml; ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-postergados">
        <?php echo $this->render_status_bucket_panel('postergados', $statusBucketDefs['postergados'], $statusTopicDefs, $statusResults['postergados'] ?? []); ?>
      </div>

      <div class="scm-tab-panel" id="scm-panel-cerrados">
        <?php echo $this->render_status_bucket_panel('cerrados', $statusBucketDefs['cerrados'], $statusTopicDefs, $statusResults['cerrados'] ?? []); ?>
      </div>

      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/admin-dashboard-inline.js?v=' . SCM_VERSION); ?>" defer></script>

      <div class="scm-tab-panel" id="scm-panel-metricas" data-scm-metrics="<?php echo self::h((string)$metricsJson); ?>">
        <div class="scm-header scm-header-metricas">
          <div>
            <h2>Metricas Operativas</h2>
            <p>Visualizacion consolidada del Control de Servicios Inmobiliarios.</p>
          </div>
          <div class="scm-header-counter-wrap"><span class="scm-header-counter" id="scm-metrics-total"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></span></div>
        </div>
        <div class="scm-tabs scm-metric-tabs" id="scm-metric-tabs">
          <button class="scm-tab active" type="button" data-scm-metric-cat="mantenimiento">Mantenimiento</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="entrega">Entrega</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="preventiva">Preventiva</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="recibo">Recibo</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contable">Contable</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="certificaciones">Certificaciones</button>
          <button class="scm-tab" type="button" data-scm-metric-cat="contractual">Contractual</button>
        </div>

        <div class="scm-metrics-grid">
          <section class="scm-metrics-card">
            <h3>Estado general</h3>
            <div class="scm-donut-wrap">
              <div class="scm-donut" id="scm-chart-estado-ring"><span id="scm-chart-estado-center"><?php echo esc_html((string)($stats['total'] ?? 0)); ?></span></div>
              <div class="scm-metrics-legend">
                <div class="scm-metrics-legend-item"><span class="dot dot-open"></span>Abiertos <strong id="scm-metric-abiertos"><?php echo esc_html((string)($stats['abiertos'] ?? 0)); ?></strong></div>
                <div class="scm-metrics-legend-item"><span class="dot dot-closed"></span>Cerrados <strong id="scm-metric-cerrados"><?php echo esc_html((string)($stats['cerrados'] ?? 0)); ?></strong></div>
              </div>
            </div>
          </section>

          <section class="scm-metrics-card">
            <h3>Salud SLA</h3>
            <div class="scm-bars" id="scm-chart-sla"></div>
          </section>

          <section class="scm-metrics-card">
            <h3>Flujo operativo</h3>
            <div class="scm-bars" id="scm-chart-flujo"></div>
          </section>

          <section class="scm-metrics-card">
            <h3>Tiempos promedio</h3>
            <div class="scm-bars" id="scm-chart-tiempos"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Produccion mensual</h3>
            <div class="scm-bars" id="scm-chart-produccion"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Categorias</h3>
            <div class="scm-bars" id="scm-chart-categorias"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Seguimientos del mes por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-seg-funcionario"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Actualizados del mes por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-actualizados-funcionario"></div>
          </section>
          <section class="scm-metrics-card">
            <h3>Tickets abiertos por Funcionario</h3>
            <div class="scm-bars" id="scm-chart-abiertos-funcionario"></div>
          </section>
        </div>
      </div>

      <?php echo \SCM\Views\GuideModalView::render(); ?>

      <div class="scm-case-modal" id="scm-case-modal" aria-hidden="true">
        <div class="scm-case-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-case-title">
          <button class="scm-case-close" type="button" aria-label="Cerrar vista del caso" onclick="scmCloseCase(this)">&times;</button>
          <div class="scm-case-head">
            <div>
              <h3 id="scm-case-title">Vista del caso</h3>
              <p id="scm-case-subtitle">Ticket de servicios inmobiliarios</p>
            </div>
            <div class="scm-case-head-actions" id="scm-case-head-actions"></div>
            <div class="scm-case-head-meta" id="scm-case-meta"></div>
          </div>
          <div class="scm-case-body" id="scm-case-body"></div>
        </div>
      </div>
    </div>
    <script src="<?php echo $jsUrl; ?>" defer></script>
    <script src="<?php echo $dashboardRuntimeJsUrl; ?>" defer></script>
    <script src="<?php echo $guideJsUrl; ?>" defer></script>
    <script src="<?php echo $damageJsUrl; ?>" defer></script>
<?php
    return (string)ob_get_clean();
  }
}
