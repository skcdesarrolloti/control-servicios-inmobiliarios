<?php

declare(strict_types=1);

/**
 * Layout Maestro - Header Global
 * SKC SuCasa Inmobiliaria — Control de Servicios Inmobiliarios
 *
 * Variables esperadas (opcionales con valores predeterminados):
 * @var string|null $page_title
 * @var string|null $current_page   ('metricas'|'contratos'|'tickets'|'liquidacion'|'administrativas'|'vencimientos')
 * @var string|null $user_name
 * @var string|null $user_role
 * @var string|null $user_avatar
 * @var string|null $base_url
 * @var bool|null   $standalone_function
 */

if (!defined('SCM_BASE_URL')) {
  $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  define('SCM_BASE_URL', $protocol . $host);
}

$baseUrl = isset($base_url) && $base_url !== '' ? rtrim((string)$base_url, '/') : rtrim((string)SCM_BASE_URL, '/');

// Resolver información de usuario y cargo
$authUserName = class_exists('\SCM\Core\Auth') ? \SCM\Core\Auth::user() : '';
$authUserCargo = class_exists('\SCM\Core\Auth') ? \SCM\Core\Auth::userCargo() : '';
$authUserCargoName = class_exists('\SCM\Core\Auth') ? \SCM\Core\Auth::userCargoName() : '';

$userName = !empty($user_name) ? (string)$user_name : (!empty($authUserName) ? $authUserName : 'Royner Guardo');
$userRole = !empty($user_role) ? (string)$user_role : (!empty($authUserCargoName) ? $authUserCargoName : (!empty($authUserCargo) ? $authUserCargo : 'Administrador Operativo'));
$pageTitle = !empty($page_title) ? (string)$page_title : 'SKC SuCasa Inmobiliaria — Control Operativo';

// Logo corporativo y favicon/isologo desde wp_jet_cct_confi_sistema
$logoUrl = function_exists('system_image')
  ? \system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : '')
  : (defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : 'https://sucasainmobiliaria.com.co/wp-content/uploads/2023/07/SUCASA_PNG_CALIDAD-NORMAL_5.png');

$faviconUrl = function_exists('system_image')
  ? \system_image('portal_favicon_url', defined('SCM_DEFAULT_PORTAL_FAVICON_URL') ? SCM_DEFAULT_PORTAL_FAVICON_URL : '')
  : (defined('SCM_DEFAULT_PORTAL_FAVICON_URL') ? SCM_DEFAULT_PORTAL_FAVICON_URL : 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/cropped-ISOLOGO-WEB.png');

$isologoUrl = function_exists('system_image')
  ? \system_image('portal_isologo_url', $faviconUrl)
  : $faviconUrl;

// Avatar oficial: usar isologo corporativo (eliminando la foto stock genérica)
$defaultAvatar = $isologoUrl !== '' ? $isologoUrl : ($baseUrl . '/assets/img/cropped-ISOLOGO-WEB.png');
$userAvatar = !empty($user_avatar) ? (string)$user_avatar : $defaultAvatar;

// Resolver notificaciones operativas recientes (casos nuevos, pendientes del área de servicios inmobiliarios)
$operationalNotifications = [];
$unreadNotifCount = 0;
if (class_exists('\SCM\Core\App')) {
  try {
    $notifDb = \SCM\Core\App::db();
    $ticketsTable = $notifDb->table('jet_cct_tickets');
    $currentEmployeeId = class_exists('\SCM\Core\Auth') ? \SCM\Core\Auth::employeeId() : '';

    $whereOperational = "LOWER(TRIM(COALESCE(`estado`, ''))) IN ('nuevo', 'en proceso')
      AND LOWER(TRIM(COALESCE(`estado_administrativo`, ''))) NOT IN ('cerrado', 'resuelto', 'finalizado', 'desistido')
      AND LOWER(TRIM(COALESCE(`departamento`, ''))) != 'servicio al cliente'
      AND (
        `departamento` IN ('Servicio al arrendatario', 'Servicio al propietario')
        OR LOWER(TRIM(COALESCE(`departamento`, ''))) = 'mantenimiento'
        OR LOWER(COALESCE(`tema_ayuda`, '')) LIKE '%reparacion%'
        OR LOWER(COALESCE(`tema_ayuda`, '')) LIKE '%mantenimiento%'
        OR LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) IN (
          'entrega de inmuebles',
          'recibo de inmuebles',
          'revision preventiva',
          'revisiones preventiva',
          'revisiones preventivas',
          'contable y tributaria',
          'certificaciones tributarias',
          'solicitud contractual',
          'solicitud de servicios publicos',
          'solicitud de servicios públicos',
          'procesos juridicos',
          'procesos jurídicos',
          'retencion de contrato',
          'retención de contrato',
          'otros servicios'
        )
      )
      AND LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) NOT IN (
        'arriendo', 'captacion', 'venta', 'recaptacion', 'ruta', 'actualizacion', 'arriendo o venta', 'retoque'
      )";

    $countRes = $notifDb->getResults("SELECT COUNT(*) AS total FROM `{$ticketsTable}` WHERE {$whereOperational}");
    if (!empty($countRes[0]['total'])) {
      $unreadNotifCount = (int) $countRes[0]['total'];
    }

    $recentTickets = $notifDb->getResults(
      "SELECT `_ID`, `id_ticket`, `asunto`, `departamento`, `tema_ayuda`, `estado`, `estado_administrativo`, 
              `nombre_empleado`, `id_empleado`, `cct_created`, `cct_modified`
         FROM `{$ticketsTable}`
        WHERE {$whereOperational}
        ORDER BY `_ID` DESC
        LIMIT 8"
    );

    if (is_array($recentTickets)) {
      foreach ($recentTickets as $tRow) {
        $pk = (int) ($tRow['_ID'] ?? 0);
        $logical = trim((string) ($tRow['id_ticket'] ?? '')) ?: (string) $pk;
        $subject = trim((string) ($tRow['asunto'] ?? '')) ?: ('Caso #' . $logical);
        $st = trim((string) ($tRow['estado'] ?? 'Abierto'));
        $stAdmin = trim((string) ($tRow['estado_administrativo'] ?? ''));
        $emp = trim((string) ($tRow['nombre_empleado'] ?? ''));
        $empId = trim((string) ($tRow['id_empleado'] ?? ''));
        $created = trim((string) ($tRow['cct_created'] ?? ''));
        $tema = trim((string) ($tRow['tema_ayuda'] ?? ''));
        $depto = trim((string) ($tRow['departamento'] ?? ''));

        $isMine = ($currentEmployeeId !== '' && $empId === $currentEmployeeId);
        $isUnassigned = ($empId === '' || $empId === '0');

        $timeAgo = 'Reciente';
        if ($created !== '') {
          $ts = strtotime($created);
          if ($ts > 0) {
            $diff = time() - $ts;
            if ($diff < 3600) {
              $m = max(1, (int) round($diff / 60));
              $timeAgo = "Hace {$m} min";
            } elseif ($diff < 86400) {
              $h = (int) floor($diff / 3600);
              $timeAgo = "Hace {$h}h";
            } else {
              $d = (int) floor($diff / 86400);
              $timeAgo = "Hace {$d}d";
            }
          }
        }

        $topicKey = mb_strtolower($tema, 'UTF-8');
        $deptoKey = mb_strtolower($depto, 'UTF-8');
        $subtab = 'mant';
        $icon = 'build';
        $areaLabel = 'Mantenimiento';

        if (str_contains($topicKey, 'reparacion') || str_contains($topicKey, 'mejora') || str_contains($topicKey, 'mantenimiento') || $deptoKey === 'mantenimiento') {
          $subtab = 'mant';
          $icon = 'build';
          $areaLabel = 'Mantenimiento';
        } elseif (str_contains($topicKey, 'preventiva')) {
          $subtab = 'preventiva';
          $icon = 'verified';
          $areaLabel = 'Preventiva';
        } elseif (str_contains($topicKey, 'entrega')) {
          $subtab = 'entrega';
          $icon = 'key';
          $areaLabel = 'Entrega';
        } elseif (str_contains($topicKey, 'recibo')) {
          $subtab = 'recibo';
          $icon = 'home_pin';
          $areaLabel = 'Recibo';
        } elseif (str_contains($topicKey, 'certificacion')) {
          $subtab = 'certificaciones';
          $icon = 'verified_user';
          $areaLabel = 'Certificaciones';
        } elseif (str_contains($topicKey, 'contable')) {
          $subtab = 'contable';
          $icon = 'receipt_long';
          $areaLabel = 'Contable';
        } elseif (str_contains($topicKey, 'juridic') || str_contains($topicKey, 'contractual') || str_contains($topicKey, 'servicios public') || str_contains($topicKey, 'retencion')) {
          $subtab = 'contractual';
          $icon = 'gavel';
          $areaLabel = 'Contractual';
        }

        $typeLabel = 'Nuevo';
        $badgeClass = 'bg-blue-50 text-blue-700 border-blue-200';
        if ($isUnassigned) {
          $typeLabel = 'Sin asignar';
          $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
        } elseif ($isMine) {
          $typeLabel = 'Tu caso';
          $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
        } elseif ($stAdmin !== '') {
          $typeLabel = $stAdmin;
        }

        $operationalNotifications[] = [
          'pk' => $pk,
          'id' => $logical,
          'asunto' => $subject,
          'tema' => $tema,
          'departamento' => $depto,
          'subtab' => $subtab,
          'icon' => $icon,
          'area_label' => $areaLabel,
          'estado' => $st,
          'estado_admin' => $stAdmin,
          'empleado' => $emp,
          'is_mine' => $isMine,
          'is_unassigned' => $isUnassigned,
          'time_ago' => $timeAgo,
          'type_label' => $typeLabel,
          'badge_class' => $badgeClass,
        ];
      }
    }
  } catch (\Throwable $e) {
    $operationalNotifications = [];
  }
}
if ($unreadNotifCount === 0 && !empty($operationalNotifications)) {
  $unreadNotifCount = count($operationalNotifications);
}

$isStandalone = !empty($standalone_function);

// Determinar pestaña activa si no fue provista explícitamente
if (empty($current_page) || $current_page === 'tickets') {
  $tabParam = mb_strtolower(trim((string)($_GET['scm_tab'] ?? ($_GET['tab'] ?? ''))), 'UTF-8');
  $subtabParam = mb_strtolower(trim((string)($_GET['scm_subtab'] ?? ($_GET['subtab'] ?? ''))), 'UTF-8');

  if (in_array($tabParam, ['abiertos', 'scm-panel-abiertos'], true)) {
    $current_page = 'abiertos';
  } elseif (in_array($tabParam, ['mis_tickets', 'mis-tickets', 'scm-panel-mis-tickets'], true)) {
    $current_page = 'mis_tickets';
  } elseif (in_array($tabParam, ['postergados', 'scm-panel-postergados'], true)) {
    $current_page = 'postergados';
  } elseif (in_array($tabParam, ['cerrados', 'scm-panel-cerrados'], true)) {
    $current_page = 'cerrados';
  } elseif (in_array($tabParam, ['tickets', 'ticket', 'casos'], true)) {
    $current_page = 'abiertos';
  } elseif (in_array($tabParam, ['vencimientos', 'due', 'due_calendar'], true) || ($tabParam === 'inicio' && $subtabParam === 'due')) {
    $current_page = 'due';
  } elseif ($tabParam === 'inicio' && $subtabParam === 'team') {
    $current_page = 'team';
  } elseif (in_array($tabParam, ['historial', 'historial_inmueble'], true) || ($tabParam === 'inicio' && in_array($subtabParam, ['property_history', 'property-history'], true))) {
    $current_page = 'property_history';
  } elseif (in_array($tabParam, ['terminacion_contrato', 'terminacion'], true) || ($tabParam === 'inicio' && in_array($subtabParam, ['contract_terminations', 'contract-termination'], true))) {
    $current_page = 'contract_termination';
  } elseif ($tabParam === 'inicio' && $subtabParam === 'mine') {
    $current_page = 'mine';
  } elseif (in_array($tabParam, ['inicio', 'home', 'resumen', 'scm-panel-inicio'], true)) {
    $current_page = 'inicio';
  } elseif (in_array($tabParam, ['notificaciones', 'scm-panel-admin-notificaciones'], true)) {
    $current_page = 'notificaciones';
  } elseif (in_array($tabParam, ['gestiones_cobro', 'scm-panel-gestiones-cobro'], true)) {
    $current_page = 'gestiones_cobro';
  } elseif (in_array($tabParam, ['cotizaciones_mantenimiento', 'cotizaciones', 'scm-panel-cotizaciones-mantenimiento'], true)) {
    $current_page = 'cotizaciones_mantenimiento';
  } elseif (in_array($tabParam, ['actas_satisfaccion', 'actas', 'scm-panel-actas-satisfaccion'], true)) {
    $current_page = 'actas_satisfaccion';
  } elseif (in_array($tabParam, ['preventivas_pendientes', 'scm-panel-preventivas-pendientes'], true)) {
    $current_page = 'preventivas_pendientes';
  } elseif (in_array($tabParam, ['servicios_publicos_pendientes', 'scm-panel-servicios-publicos-pendientes'], true)) {
    $current_page = 'servicios_publicos_pendientes';
  } elseif (in_array($tabParam, ['reportes_administrativos_pendientes', 'reportes', 'scm-panel-reportes-administrativos-pendientes'], true)) {
    $current_page = 'reportes_administrativos_pendientes';
  } elseif (in_array($tabParam, ['auditoria_canon_aseguradoras', 'auditoria', 'scm-panel-auditoria-canon-aseguradoras'], true)) {
    $current_page = 'auditoria_canon_aseguradoras';
  } elseif (in_array($tabParam, ['cartas_aumento', 'scm-panel-cartas-aumento'], true)) {
    $current_page = 'cartas_aumento';
  } elseif (in_array($tabParam, ['liquidacion', 'liquidador', 'liquidador_servicios_publicos', 'scm-panel-liquidador-servicios-publicos'], true)) {
    $current_page = 'liquidador_servicios_publicos';
  } elseif (in_array($tabParam, ['contratos', 'contratos-arrendamiento', 'contratos_arrendamiento', 'scm-panel-contratos-arrendamiento'], true)) {
    $current_page = 'contratos';
  } elseif (in_array($tabParam, ['administrativas', 'actividades_administrativas', 'scm-panel-actividades-administrativas'], true)) {
    $current_page = 'notificaciones';
  } elseif (in_array($tabParam, ['metricas', 'dashboard', 'scm-panel-metricas'], true)) {
    $current_page = 'dashboard';
  } else {
    $current_page = empty($tabParam) ? 'inicio' : 'abiertos';
  }
}

// Configuración y verificación de permisos para pestañas
$allowedTabsList = isset($allowed_tabs) && is_array($allowed_tabs) ? $allowed_tabs : null;

$checkTabPerm = static function (array $perms) use ($allowedTabsList): bool {
  if ($allowedTabsList === null || empty($perms)) {
    return true;
  }
  foreach ($perms as $p) {
    if (in_array($p, $allowedTabsList, true)) {
      return true;
    }
  }
  return false;
};

// Orden solicitado:
// 1. Inicio (Dropdown: Mi Calendario, Calendario Equipo, Vencimientos, Historial Inmueble, Solicitudes Terminación)
// 2. Gestión de Casos & Tickets (Dropdown: Tickets Abiertos, Mis Tickets, Tickets Postergados, Tickets Cerrados)
// 3. Actividades Administrativas (Dropdown: Notificaciones, Gestiones de Cobro, Cotizaciones, Actas, Preventivas, Servicios Públicos, Liquidación, Reportes, Auditoría, Cartas Aumento)
// 4. Métricas y Dashboard
$rawNavItems = [
  'inicio' => [
    'type' => 'dropdown',
    'key' => 'inicio',
    'label' => 'Inicio',
    'icon' => 'home',
    'children' => [
      'inicio' => [
        'key' => 'inicio',
        'label' => 'Inicio (Resumen general)',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'mine',
        'url' => $baseUrl . '/index.php?tab=inicio',
        'icon' => 'dashboard',
        'perms' => ['calendario_actividades', 'abiertos'],
      ],
      'mine' => [
        'key' => 'mine',
        'label' => 'Mi calendario',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'mine',
        'url' => $baseUrl . '/index.php?tab=inicio&subtab=mine',
        'icon' => 'calendar_today',
        'perms' => ['calendario_actividades', 'abiertos'],
      ],
      'team' => [
        'key' => 'team',
        'label' => 'Calendario equipo',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'team',
        'url' => $baseUrl . '/index.php?tab=inicio&subtab=team',
        'icon' => 'groups',
        'perms' => ['calendario_actividades', 'abiertos'],
      ],
      'due' => [
        'key' => 'due',
        'label' => 'Vencimientos',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'due',
        'url' => $baseUrl . '/index.php?tab=vencimientos',
        'icon' => 'calendar_month',
        'perms' => ['calendario_actividades', 'reportes_administrativos_pendientes', 'abiertos'],
      ],
      'property_history' => [
        'key' => 'property_history',
        'label' => 'Historial inmueble',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'property-history',
        'url' => $baseUrl . '/index.php?tab=historial_inmueble',
        'icon' => 'history',
        'perms' => ['calendario_actividades', 'abiertos'],
      ],
      'contract_termination' => [
        'key' => 'contract_termination',
        'label' => 'Solicitudes de terminación de contrato',
        'panel_id' => 'scm-panel-inicio',
        'subtab' => 'contract-termination',
        'url' => $baseUrl . '/index.php?tab=terminacion_contrato',
        'icon' => 'assignment_late',
        'perms' => ['calendario_actividades', 'abiertos'],
      ],
    ],
  ],
  'tickets' => [
    'type' => 'dropdown',
    'key' => 'tickets',
    'label' => 'Gestión de Casos',
    'icon' => 'confirmation_number',
    'children' => [
      'abiertos' => [
        'key' => 'abiertos',
        'label' => 'Casos Abiertos',
        'panel_id' => 'scm-panel-abiertos',
        'url' => $baseUrl . '/index.php?tab=abiertos',
        'icon' => 'inbox',
        'perms' => ['abiertos'],
      ],
      'mis_tickets' => [
        'key' => 'mis_tickets',
        'label' => 'Mis Casos',
        'panel_id' => 'scm-panel-mis-tickets',
        'url' => $baseUrl . '/index.php?tab=mis_tickets',
        'icon' => 'assignment_ind',
        'perms' => ['mis_tickets'],
      ],
      'postergados' => [
        'key' => 'postergados',
        'label' => 'Casos Postergados',
        'panel_id' => 'scm-panel-postergados',
        'url' => $baseUrl . '/index.php?tab=postergados',
        'icon' => 'hourglass_empty',
        'perms' => ['postergados'],
      ],
      'cerrados' => [
        'key' => 'cerrados',
        'label' => 'Casos Cerrados',
        'panel_id' => 'scm-panel-cerrados',
        'url' => $baseUrl . '/index.php?tab=cerrados',
        'icon' => 'task_alt',
        'perms' => ['cerrados'],
      ],
    ],
  ],
  'administrativas' => [
    'type' => 'dropdown',
    'key' => 'administrativas',
    'label' => 'Actividades Administrativas',
    'icon' => 'folder_shared',
    'children' => [
      'notificaciones' => [
        'key' => 'notificaciones',
        'label' => 'Notificaciones',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-admin-notificaciones',
        'admin_activity_key' => 'notificaciones',
        'url' => $baseUrl . '/index.php?tab=notificaciones',
        'icon' => 'notifications',
        'perms' => ['notificaciones'],
      ],
      'gestiones_cobro' => [
        'key' => 'gestiones_cobro',
        'label' => 'Gestiones de Cobro',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-gestiones-cobro',
        'admin_activity_key' => 'gestiones_cobro',
        'url' => $baseUrl . '/index.php?tab=gestiones_cobro',
        'icon' => 'payments',
        'perms' => ['gestiones_cobro'],
      ],
      'cotizaciones_mantenimiento' => [
        'key' => 'cotizaciones_mantenimiento',
        'label' => 'Cotizaciones de Mantenimiento',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-cotizaciones-mantenimiento',
        'admin_activity_key' => 'cotizaciones_mantenimiento',
        'url' => $baseUrl . '/index.php?tab=cotizaciones_mantenimiento',
        'icon' => 'request_quote',
        'perms' => ['cotizaciones_mantenimiento'],
      ],
      'actas_satisfaccion' => [
        'key' => 'actas_satisfaccion',
        'label' => 'Actas de Satisfacción',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-actas-satisfaccion',
        'admin_activity_key' => 'actas_satisfaccion',
        'url' => $baseUrl . '/index.php?tab=actas_satisfaccion',
        'icon' => 'assignment_turned_in',
        'perms' => ['actas_satisfaccion'],
      ],
      'preventivas_pendientes' => [
        'key' => 'preventivas_pendientes',
        'label' => 'Preventivas Pendientes',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-preventivas-pendientes',
        'admin_activity_key' => 'preventivas_pendientes',
        'url' => $baseUrl . '/index.php?tab=preventivas_pendientes',
        'icon' => 'pending_actions',
        'perms' => ['preventivas_pendientes'],
      ],
      'servicios_publicos_pendientes' => [
        'key' => 'servicios_publicos_pendientes',
        'label' => 'Servicios Públicos Pendientes',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-servicios-publicos-pendientes',
        'admin_activity_key' => 'servicios_publicos_pendientes',
        'url' => $baseUrl . '/index.php?tab=servicios_publicos_pendientes',
        'icon' => 'receipt_long',
        'perms' => ['servicios_publicos_pendientes'],
      ],
      'liquidador_servicios_publicos' => [
        'key' => 'liquidador_servicios_publicos',
        'label' => 'Liquidación de Servicios',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-liquidador-servicios-publicos',
        'admin_activity_key' => 'liquidador_servicios_publicos',
        'url' => $baseUrl . '/index.php?tab=liquidador_servicios_publicos',
        'icon' => 'calculate',
        'perms' => ['liquidador_servicios_publicos', 'servicios_publicos_pendientes'],
      ],
      'reportes_administrativos_pendientes' => [
        'key' => 'reportes_administrativos_pendientes',
        'label' => 'Reportes Administrativos',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-reportes-administrativos-pendientes',
        'admin_activity_key' => 'reportes_administrativos_pendientes',
        'url' => $baseUrl . '/index.php?tab=reportes_administrativos_pendientes',
        'icon' => 'summarize',
        'perms' => ['reportes_administrativos_pendientes'],
      ],
      'auditoria_canon_aseguradoras' => [
        'key' => 'auditoria_canon_aseguradoras',
        'label' => 'Auditoría Canon y Aseguradoras',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-auditoria-canon-aseguradoras',
        'admin_activity_key' => 'auditoria_canon_aseguradoras',
        'url' => $baseUrl . '/index.php?tab=auditoria_canon_aseguradoras',
        'icon' => 'verified_user',
        'perms' => ['auditoria_canon_aseguradoras'],
      ],
      'cartas_aumento' => [
        'key' => 'cartas_aumento',
        'label' => 'Cartas de Aumento',
        'panel_id' => 'scm-panel-actividades-administrativas',
        'admin_sub_target' => 'scm-panel-cartas-aumento',
        'admin_activity_key' => 'cartas_aumento',
        'url' => $baseUrl . '/index.php?tab=cartas_aumento',
        'icon' => 'mail',
        'perms' => ['cartas_aumento'],
      ],
    ],
  ],
  'dashboard' => [
    'type' => 'link',
    'key' => 'dashboard',
    'label' => 'Métricas y Dashboard',
    'panel_id' => 'scm-panel-metricas',
    'url' => $baseUrl . '/index.php?tab=metricas',
    'icon' => 'query_stats',
    'perms' => ['metricas'],
  ],
];

$filteredNavItems = [];
foreach ($rawNavItems as $k => $item) {
  if ($item['type'] === 'link') {
    if ($checkTabPerm($item['perms'])) {
      $filteredNavItems[$k] = $item;
    }
  } elseif ($item['type'] === 'dropdown') {
    $allowedChildren = [];
    foreach ($item['children'] as $ck => $child) {
      if ($checkTabPerm($child['perms'])) {
        $allowedChildren[$ck] = $child;
      }
    }
    if (!empty($allowedChildren)) {
      $item['children'] = $allowedChildren;
      $filteredNavItems[$k] = $item;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-[#f8f9ff]">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8'); ?>" sizes="32x32">

  <!-- Fuentes e Iconos -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

  <!-- Tailwind CSS CDN con Tokens de Diseño Stitch UI -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            "primary": "#000412",
            "primary-container": "#0f1e36",
            "primary-hover": "#162846",
            "on-primary": "#ffffff",
            "surface": "#f8f9ff",
            "surface-dim": "#cbdbf5",
            "surface-container-lowest": "#ffffff",
            "surface-container-low": "#eff4ff",
            "surface-container": "#e5eeff",
            "surface-container-high": "#dce9ff",
            "surface-container-highest": "#d3e4fe",
            "on-surface": "#0b1c30",
            "on-surface-variant": "#44474d",
            "secondary": "#904d00",
            "secondary-container": "#fe932c",
            "secondary-fixed": "#ffdcc3",
            "on-secondary-fixed": "#2f1500",
            "tertiary": "#000703",
            "tertiary-container": "#002416",
            "tertiary-fixed": "#85f8c4",
            "on-tertiary-container": "#0e996b",
            "error": "#ba1a1a",
            "error-container": "#ffdad6",
            "on-error-container": "#93000a",
            "outline": "#75777e",
            "outline-variant": "#c5c6ce",
            "brand-navy": "#0f1e36",
            "brand-blue": "#1e3a8a",
            "brand-sky": "#38bdf8",
          },
          fontFamily: {
            sans: ["'Poppins'", "sans-serif"],
          },
          borderRadius: {
            "DEFAULT": "0.5rem",
            "lg": "0.75rem",
            "xl": "1rem",
            "2xl": "1.5rem",
            "full": "9999px"
          },
          boxShadow: {
            "subtle": "0 1px 3px 0 rgba(15, 30, 54, 0.04), 0 1px 2px -1px rgba(15, 30, 54, 0.02)",
            "elevated": "0 4px 6px -1px rgba(15, 30, 54, 0.06), 0 2px 4px -2px rgba(15, 30, 54, 0.03)",
            "popover": "0 10px 15px -3px rgba(15, 30, 54, 0.08), 0 4px 6px -4px rgba(15, 30, 54, 0.04)",
          }
        }
      }
    };
  </script>

  <style>
    @font-face {
      font-family: 'Material Symbols Outlined';
      font-style: normal;
      font-weight: 100 700;
      src: url(https://fonts.gstatic.com/s/materialsymbolsoutlined/v374/kJF1BvYX7BgnkSrUwT8OhrdQw4oELdPIeeII9v6oDMzByHX9rA6RzaxHMPdY43zj-jCxv3fzvRNU22ZXGJpEpjC_1v-p_4MrImHCIJIZrDCvHOej.woff2) format('woff2');
      font-display: swap;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background-color: #f8f9ff;
      color: #0b1c30;
    }

    .material-symbols-outlined,
    [class*="material-symbols-"],
    #scm-app .material-symbols-outlined,
    #scm-app [class*="material-symbols-"] {
      font-family: 'Material Symbols Outlined' !important;
      font-weight: normal !important;
      font-style: normal !important;
      font-size: 20px;
      line-height: 1;
      letter-spacing: normal;
      text-transform: none;
      display: inline-block;
      white-space: nowrap;
      word-wrap: normal;
      direction: ltr;
      -webkit-font-feature-settings: 'liga' 1 !important;
      font-feature-settings: 'liga' 1 !important;
      -webkit-font-smoothing: antialiased;
      vertical-align: middle;
    }

    /* Loader moderno inmediato para evitar círculos negros */
    .scm-panel-loader {
      position: fixed !important;
      inset: 0 !important;
      z-index: 999999 !important;
      display: grid !important;
      place-items: center !important;
      min-height: 100vh !important;
      background: rgba(15, 30, 54, 0.5) !important;
      -webkit-backdrop-filter: blur(4px) !important;
      backdrop-filter: blur(4px) !important;
      transition: opacity 0.2s ease !important;
    }
    .scm-panel-loader[hidden] {
      display: none !important;
    }
    .scm-panel-loader-card {
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      width: min(90vw, 360px) !important;
      padding: 28px 24px !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 1.25rem !important;
      background: #ffffff !important;
      color: #0f172a !important;
      text-align: center !important;
      box-shadow: 0 20px 40px -10px rgba(15, 30, 54, 0.2) !important;
    }
    .scm-panel-loader-icon {
      display: grid !important;
      place-items: center !important;
      width: 52px !important;
      height: 52px !important;
      margin-bottom: 14px !important;
      border-radius: 1rem !important;
      background: #eff4ff !important;
      color: #0f1e36 !important;
    }
    .scm-panel-loader-icon svg {
      width: 32px !important;
      height: 32px !important;
      fill: none !important;
      stroke: currentColor !important;
      stroke-linecap: round !important;
      stroke-width: 2.5 !important;
      animation: scm-spin 0.8s linear infinite !important;
    }
    .scm-panel-loader-icon circle {
      opacity: 0.2 !important;
      fill: none !important;
      stroke: currentColor !important;
    }
    .scm-panel-loader-icon path {
      fill: none !important;
      stroke: currentColor !important;
    }
    @keyframes scm-spin {
      to { transform: rotate(360deg); }
    }

    /* Estilo sutil de scrollbars para mantener diseño limpio */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }
    ::-webkit-scrollbar-track {
      background: #f1f5f9;
    }
    ::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 9999px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }
  </style>
  <?php
  $modernUiCssPath = dirname(__DIR__) . '/public/assets/css/modern-ui.css';
  $modernUiCssVer = (defined('SCM_VERSION') ? SCM_VERSION : '2.0.0') . '-' . (file_exists($modernUiCssPath) ? (string) filemtime($modernUiCssPath) : '0');
  ?>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl . '/assets/css/modern-ui.css?v=' . $modernUiCssVer, ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body class="min-h-full flex flex-col bg-[#f8f9ff] text-on-surface antialiased <?php echo $isStandalone ? 'scm-standalone-mode' : ''; ?>">

  <!-- ========================================== -->
  <!-- HEADER MAESTRO FIJO (APP SHELL)             -->
  <!-- ========================================== -->
  <header class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200/90 shadow-[0_1px_8px_rgba(15,30,54,0.04)]">
    <div class="w-full px-4 sm:px-6 lg:px-8">

      <!-- Fila 1: Marca, Búsqueda Global y Perfil (Altura 64px) -->
      <div class="h-16 flex items-center justify-between gap-4">

        <!-- Logotipo Oficial SuCasa & Branding Corporativo (Alto Contraste Stitch UI) -->
        <div class="flex items-center gap-3 shrink-0">
          <a href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-2.5 group focus:outline-none focus:ring-2 focus:ring-[#0f1e36] rounded-xl p-1 transition-all" title="SKC SuCasa Inmobiliaria — Control Operativo">
            <div class="w-9 h-9 rounded-xl bg-[#0f1e36] flex items-center justify-center shrink-0 shadow-sm overflow-hidden p-1 group-hover:bg-[#162846] transition-colors">
              <img
                src="<?php echo htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8'); ?>"
                alt="SKC SuCasa Inmobiliaria"
                class="w-7 h-7 object-contain"
                onerror="this.onerror=null; this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='block';"
              >
              <svg class="w-5 h-5 hidden" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 3L2 12H6V20H10V14H14V20H18V12H22L12 3Z" fill="#38bdf8"/>
                <path d="M12 7L7 11.5V18H9V13H15V18H17V11.5L12 7Z" fill="#ffffff"/>
              </svg>
            </div>
            <div class="flex flex-col">
              <span class="text-[17px] font-extrabold tracking-tight text-[#0f172a] leading-none">SuCasa</span>
              <span class="text-[9px] font-bold uppercase tracking-wider text-slate-500 leading-none mt-1">Control Inmobiliario</span>
            </div>
          </a>
        </div>

        <!-- Barra de Búsqueda Global con atajo visual ⌘K -->
        <div class="flex-1 max-w-md mx-2">
          <div class="relative flex items-center w-full">
            <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[20px] pointer-events-none select-none">search</span>
            <input
              id="global-search-input"
              type="text"
              placeholder="Buscar contratos, propiedades, cédulas o tickets..."
              class="w-full pl-10 pr-14 py-2 rounded-xl bg-slate-100/80 text-slate-900 text-xs sm:text-sm placeholder-slate-400 border border-transparent focus:border-slate-300 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]/10 transition-all shadow-inner"
              autocomplete="off"
            >
            <kbd class="absolute right-3 px-1.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-500 font-mono text-[10px] font-semibold shadow-2xs select-none">⌘K</kbd>
          </div>
        </div>

        <!-- Acciones Rápidas & Perfil de Funcionario -->
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">

          <!-- Botón de Acción Principal: + Nuevo Caso -->
          <button
            type="button"
            id="btn-global-nuevo-ticket"
            onclick="window.dispatchEvent(new CustomEvent('scm:open-nuevo-ticket'))"
            class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl bg-[#0f1e36] text-white hover:bg-[#162846] font-semibold text-xs sm:text-sm shadow-sm hover:shadow transition-all active:scale-[0.98]"
          >
            <span class="material-symbols-outlined text-[18px]">add</span>
            <span class="hidden md:inline">Nuevo Caso</span>
          </button>

          <!-- Menú Desplegable Configuración Operativa -->
          <div class="relative" id="global-config-menu-container">
            <button
              type="button"
              id="btn-global-configuracion"
              title="Configuración operativa"
              class="p-2 sm:px-3 sm:py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent hover:border-slate-200 transition-colors flex items-center gap-1 text-xs sm:text-sm font-medium focus:outline-none"
              aria-haspopup="true"
              aria-expanded="false"
            >
              <span class="material-symbols-outlined text-[20px]">settings</span>
              <span class="hidden xl:inline">Configuración</span>
              <span class="material-symbols-outlined text-[16px] text-slate-400">expand_more</span>
            </button>
            <div
              id="global-config-dropdown-menu"
              class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-slate-200/90 py-1.5 z-50 drop-shadow-xl"
              role="menu"
            >
              <div class="px-3.5 py-1.5 border-b border-slate-100">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Configuración Operativa</p>
              </div>
              <div class="p-1 flex flex-col gap-0.5">
                <button
                  type="button"
                  data-scm-config-action="permissions"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">lock</span>
                  <span>Permisos del Panel</span>
                </button>
                <button
                  type="button"
                  data-scm-config-action="due-settings"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">calendar_month</span>
                  <span>Configurar Vencimientos</span>
                </button>
                <button
                  type="button"
                  data-scm-config-action="notifications"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">notifications</span>
                  <span>Notificaciones Internas</span>
                </button>
                <button
                  type="button"
                  data-scm-config-action="guardian-settings"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">shield_person</span>
                  <span>Configuración de Guardian</span>
                </button>
                <button
                  type="button"
                  data-scm-config-action="actas-guide"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">description</span>
                  <span>Tipos de Actas</span>
                </button>
                <button
                  type="button"
                  data-scm-config-action="guide"
                  class="flex items-center gap-2.5 w-full text-left px-3 py-2 rounded-lg text-xs text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[18px] text-slate-500">menu_book</span>
                  <span>Guías del Sistema</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Notificaciones con Indicador Activo & Menú Desplegable -->
          <div class="relative" id="global-notifications-menu-container">
            <button
              type="button"
              id="btn-global-notificaciones"
              title="Notificaciones operativas"
              class="relative p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent hover:border-slate-200 transition-colors focus:outline-none"
              aria-haspopup="true"
              aria-expanded="false"
            >
              <span class="material-symbols-outlined text-[22px]">notifications</span>
              <?php if ($unreadNotifCount > 0): ?>
                <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-amber-500 ring-2 ring-white animate-pulse"></span>
              <?php endif; ?>
            </button>

            <!-- Menú Flotante de Notificaciones Operativas -->
            <div
              id="global-notifications-dropdown-menu"
              class="hidden absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-xl border border-slate-200/90 py-0 z-50 drop-shadow-xl overflow-hidden"
              role="menu"
            >
              <div class="px-4 py-3 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[18px] text-slate-600">notifications_active</span>
                  <p class="text-xs font-bold text-slate-800 tracking-tight">Notificaciones Operativas</p>
                </div>
                <?php if ($unreadNotifCount > 0): ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">
                    <?php echo $unreadNotifCount; ?> casos activos
                  </span>
                <?php endif; ?>
              </div>

              <div class="max-h-[380px] overflow-y-auto divide-y divide-slate-100/80">
                <?php if (!empty($operationalNotifications)): ?>
                  <?php foreach ($operationalNotifications as $notif): ?>
                    <button
                      type="button"
                      data-scm-notif-ticket="<?php echo htmlspecialchars((string)$notif['pk'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-scm-notif-logical="<?php echo htmlspecialchars((string)$notif['id'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-scm-notif-is-mine="<?php echo $notif['is_mine'] ? '1' : '0'; ?>"
                      data-scm-notif-subtab="<?php echo htmlspecialchars((string)$notif['subtab'], ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full text-left p-3 hover:bg-slate-50 transition-colors flex items-start gap-3 group focus:outline-none focus:bg-slate-50"
                      role="menuitem"
                    >
                      <div class="w-8 h-8 rounded-xl bg-slate-100 border border-slate-200 flex items-center justify-center shrink-0 mt-0.5 group-hover:border-blue-300 group-hover:bg-blue-50 transition-colors">
                        <span class="material-symbols-outlined text-[18px] text-slate-500 group-hover:text-blue-600"><?php echo htmlspecialchars($notif['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-1.5 mb-1">
                          <div class="flex items-center gap-1.5 min-w-0">
                            <span class="text-[11px] font-bold text-slate-900 shrink-0">
                              Caso #<?php echo htmlspecialchars($notif['id'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="text-[10px] font-medium text-slate-400 truncate">
                              • <?php echo htmlspecialchars($notif['area_label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                          </div>
                          <span class="shrink-0 text-[10px] font-semibold px-1.5 py-0.5 rounded border <?php echo $notif['badge_class']; ?>">
                            <?php echo htmlspecialchars($notif['type_label'], ENT_QUOTES, 'UTF-8'); ?>
                          </span>
                        </div>
                        <p class="text-xs text-slate-600 line-clamp-2 leading-relaxed mb-1.5 font-medium">
                          <?php echo htmlspecialchars($notif['asunto'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <div class="flex items-center justify-between text-[10px] text-slate-400">
                          <span class="truncate max-w-[150px]">
                            <?php echo htmlspecialchars($notif['empleado'] !== '' ? $notif['empleado'] : 'Sin asignar', ENT_QUOTES, 'UTF-8'); ?>
                          </span>
                          <span class="font-medium shrink-0"><?php echo htmlspecialchars($notif['time_ago'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                      </div>
                    </button>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="p-6 text-center">
                    <span class="material-symbols-outlined text-[36px] text-slate-300 mb-2">done_all</span>
                    <p class="text-xs font-semibold text-slate-700">Sin notificaciones operativas</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Todos los casos están al día o gestionados.</p>
                  </div>
                <?php endif; ?>
              </div>

              <div class="p-2 bg-slate-50/80 border-t border-slate-100 flex items-center justify-between">
                <a
                  href="<?php echo htmlspecialchars($baseUrl . '/index.php?tab=abiertos', ENT_QUOTES, 'UTF-8'); ?>"
                  data-panel-target="scm-panel-abiertos"
                  data-tab-key="abiertos"
                  class="nav-tab-pill w-full text-center py-1.5 px-3 rounded-xl text-xs font-bold text-[#0f1e36] hover:bg-white hover:shadow-xs border border-transparent hover:border-slate-200 transition-all"
                >
                  Ver todos los casos abiertos →
                </a>
              </div>
            </div>
          </div>

          <div class="h-6 w-px bg-slate-200 hidden sm:block"></div>

          <!-- Perfil de Usuario & Menú Desplegable -->
          <div class="relative" id="user-profile-menu-container">
            <button
              type="button"
              id="user-profile-button"
              class="flex items-center gap-2 p-1 pl-1.5 rounded-xl hover:bg-slate-100 transition-colors focus:outline-none"
              aria-haspopup="true"
              aria-expanded="false"
            >
              <div class="w-8 h-8 rounded-full bg-slate-100 ring-1 ring-slate-200 flex items-center justify-center p-1 shrink-0 shadow-2xs overflow-hidden">
                <img
                  src="<?php echo htmlspecialchars($userAvatar, ENT_QUOTES, 'UTF-8'); ?>"
                  alt="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-6 h-6 object-contain"
                  onerror="this.onerror=null; this.src='https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/cropped-ISOLOGO-WEB.png';"
                >
              </div>
              <div class="hidden lg:flex flex-col text-left">
                <span class="text-xs font-semibold text-slate-900 leading-tight"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="text-[10px] font-medium text-slate-500 leading-tight"><?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <span class="material-symbols-outlined text-[18px] text-slate-400 hidden lg:inline">expand_more</span>
            </button>

            <!-- Menú Flotante de Usuario (Cerrar sesión) -->
            <div
              id="user-dropdown-menu"
              class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-200 py-1.5 z-50"
              role="menu"
            >
              <div class="px-4 py-2 border-b border-slate-100 lg:hidden">
                <p class="text-xs font-semibold text-slate-900"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-[10px] text-slate-500"><?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
              <a
                href="<?php echo htmlspecialchars($baseUrl . '/index.php?tab=mis_tickets', ENT_QUOTES, 'UTF-8'); ?>"
                data-panel-target="scm-panel-mis-tickets"
                data-tab-key="mis_tickets"
                class="nav-tab-pill flex items-center gap-2 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition-colors"
                role="menuitem"
              >
                <span class="material-symbols-outlined text-[16px] text-slate-500">assignment_ind</span>
                Mis Casos Asignados
              </a>
              <form method="post" action="<?php echo htmlspecialchars($baseUrl . '/logout.php', ENT_QUOTES, 'UTF-8'); ?>" class="m-0 p-0 border-t border-slate-100">
                <?php
                if (class_exists('\SCM\Core\App') && method_exists('\SCM\Core\App', 'csrf')) {
                  echo \SCM\Core\App::csrf()->field('logout');
                }
                ?>
                <button
                  type="submit"
                  class="w-full text-left flex items-center gap-2 px-4 py-2 text-xs text-rose-700 hover:bg-rose-50 transition-colors font-medium"
                  role="menuitem"
                >
                  <span class="material-symbols-outlined text-[16px] text-rose-500">logout</span>
                  Cerrar sesión
                </button>
              </form>
            </div>
          </div>

        </div>
      </div>

      <!-- Fila 2: Subnavegación Horizontal por Píldoras (Altura 48px) -->
      <div class="h-12 flex items-center overflow-x-visible no-scrollbar border-t border-slate-100">
        <nav class="flex items-center gap-1.5 py-1" aria-label="Pestañas principales del sistema">
          <?php foreach ($filteredNavItems as $tabKey => $tab): ?>
            <?php if ($tab['type'] === 'dropdown'): ?>
              <?php
              $isChildActive = in_array($current_page, array_keys($tab['children']), true);
              $parentClass = $isChildActive
                ? 'bg-[#0f1e36] text-white font-semibold shadow-xs'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium';
              ?>
              <div class="relative scm-nav-dropdown" data-scm-nav-dropdown>
                <button
                  type="button"
                  class="nav-tab-pill nav-tab-dropdown-btn flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs transition-all whitespace-nowrap <?php echo $parentClass; ?>"
                  aria-haspopup="true"
                  aria-expanded="<?php echo $isChildActive ? 'true' : 'false'; ?>"
                >
                  <span class="material-symbols-outlined text-[16px] <?php echo $isChildActive ? 'text-white' : 'text-slate-500'; ?>"><?php echo htmlspecialchars($tab['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="material-symbols-outlined text-[15px] <?php echo $isChildActive ? 'text-white' : 'text-slate-400'; ?> transition-transform scm-dropdown-chevron">expand_more</span>
                </button>
                <div class="scm-dropdown-menu absolute left-0 top-full pt-1.5 hidden z-50 min-w-[240px] drop-shadow-xl" data-scm-dropdown-menu>
                  <div class="bg-white rounded-xl shadow-lg border border-slate-200/90 py-1.5 px-1.5 flex flex-col gap-0.5">
                    <?php foreach ($tab['children'] as $childKey => $child): ?>
                      <?php
                      $isSubActive = ($current_page === $childKey);
                      $subClass = $isSubActive
                        ? 'bg-[#0f1e36] text-white font-semibold'
                        : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 font-medium';
                      ?>
                      <a
                        href="<?php echo htmlspecialchars($child['url'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-tab-key="<?php echo htmlspecialchars($childKey, ENT_QUOTES, 'UTF-8'); ?>"
                        data-panel-target="<?php echo htmlspecialchars($child['panel_id'], ENT_QUOTES, 'UTF-8'); ?>"
                        <?php if (!empty($child['subtab'])): ?>data-subtab-target="<?php echo htmlspecialchars($child['subtab'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                        <?php if (!empty($child['admin_sub_target'])): ?>data-admin-sub-target="<?php echo htmlspecialchars($child['admin_sub_target'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                        <?php if (!empty($child['admin_activity_key'])): ?>data-admin-activity-key="<?php echo htmlspecialchars($child['admin_activity_key'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                        class="nav-tab-pill nav-tab-dropdown-item flex items-center gap-2 px-3 py-2 rounded-lg text-xs transition-all whitespace-nowrap <?php echo $subClass; ?>"
                        <?php if ($isSubActive): ?>aria-current="page"<?php endif; ?>
                      >
                        <span class="material-symbols-outlined text-[16px] <?php echo $isSubActive ? 'text-white' : 'text-slate-500'; ?>"><?php echo htmlspecialchars($child['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <?php
              $isActive = ($current_page === $tabKey);
              $activeClass = $isActive
                ? 'bg-[#0f1e36] text-white font-semibold shadow-xs'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium';
              ?>
              <a
                href="<?php echo htmlspecialchars($tab['url'], ENT_QUOTES, 'UTF-8'); ?>"
                data-tab-key="<?php echo htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8'); ?>"
                data-panel-target="<?php echo htmlspecialchars($tab['panel_id'], ENT_QUOTES, 'UTF-8'); ?>"
                <?php if (isset($tab['subtab'])): ?>data-subtab-target="<?php echo htmlspecialchars($tab['subtab'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                class="nav-tab-pill flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs transition-all whitespace-nowrap <?php echo $activeClass; ?>"
                <?php if ($isActive): ?>aria-current="page"<?php endif; ?>
              >
                <span class="material-symbols-outlined text-[16px] <?php echo $isActive ? 'text-white' : 'text-slate-500'; ?>"><?php echo htmlspecialchars($tab['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </div>

    </div>
  </header>

  <!-- ========================================== -->
  <!-- CONTENEDOR PRINCIPAL DEL LAYOUT             -->
  <!-- ========================================== -->
  <main class="flex-1 w-full px-4 sm:px-6 lg:px-8 pt-32 pb-12 transition-all">

    <?php if ($isStandalone): ?>
      <div class="mb-4 p-3.5 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-xs flex items-center justify-between" role="status">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-blue-600 text-[18px]">verified_user</span>
          <span><strong>Función protegida activa:</strong> Vista ejecutada con credenciales verificadas.</span>
        </div>
        <a href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>" class="font-semibold text-blue-700 hover:underline">Ir al panel completo →</a>
      </div>
    <?php endif; ?>
