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

$userName = !empty($user_name) ? (string)$user_name : (!empty($authUserName) ? $authUserName : 'Royner Guardo');
$userRole = !empty($user_role) ? (string)$user_role : (!empty($authUserCargo) ? $authUserCargo : 'Administrador Operativo');
$pageTitle = !empty($page_title) ? (string)$page_title : 'SKC SuCasa Inmobiliaria — Control Operativo';

// Avatar local o fallback corporativo
$defaultAvatar = $baseUrl . '/assets/img/avatar-manager.png';
$userAvatar = !empty($user_avatar) ? (string)$user_avatar : $defaultAvatar;

// Logo corporativo respetando la regla "el logo no lo cambies deja el que esta como es"
$logoUrl = function_exists('system_image')
  ? \system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : '')
  : (defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? SCM_DEFAULT_PORTAL_LOGO_URL : 'https://sucasainmobiliaria.com.co/wp-content/uploads/2023/07/SUCASA_PNG_CALIDAD-NORMAL_5.png');

$faviconUrl = function_exists('system_image')
  ? \system_image('portal_favicon_url', defined('SCM_DEFAULT_PORTAL_FAVICON_URL') ? SCM_DEFAULT_PORTAL_FAVICON_URL : '')
  : (defined('SCM_DEFAULT_PORTAL_FAVICON_URL') ? SCM_DEFAULT_PORTAL_FAVICON_URL : 'https://sucasainmobiliaria.com.co/wp-content/uploads/2023/07/SUCASA_PNG_CALIDAD-NORMAL_5-150x150.png');

$isStandalone = !empty($standalone_function);

// Determinar pestaña activa si no fue provista explícitamente
if (empty($current_page)) {
  $tabParam = mb_strtolower(trim((string)($_GET['scm_tab'] ?? ($_GET['tab'] ?? ''))), 'UTF-8');
  if (in_array($tabParam, ['metricas', 'dashboard', 'scm-panel-metricas'], true)) {
    $current_page = 'metricas';
  } elseif (in_array($tabParam, ['contratos', 'contratos-arrendamiento', 'contratos_arrendamiento', 'scm-panel-contratos-arrendamiento'], true)) {
    $current_page = 'contratos';
  } elseif (in_array($tabParam, ['tickets', 'abiertos', 'mis_tickets', 'cerrados', 'scm-panel-abiertos', 'scm-panel-mis-tickets'], true)) {
    $current_page = 'tickets';
  } elseif (in_array($tabParam, ['liquidacion', 'liquidador', 'liquidador_servicios_publicos', 'scm-panel-liquidador-servicios-publicos'], true)) {
    $current_page = 'liquidacion';
  } elseif (in_array($tabParam, ['administrativas', 'actividades_administrativas', 'scm-panel-actividades-administrativas', 'notificaciones', 'cotizaciones_mantenimiento'], true)) {
    $current_page = 'administrativas';
  } elseif (in_array($tabParam, ['vencimientos', 'due', 'due_calendar'], true)) {
    $current_page = 'vencimientos';
  } else {
    $current_page = 'dashboard';
  }
}

// Configuración de las pestañas principales
$navTabs = [
  'dashboard' => [
    'label' => 'Métricas y Dashboard',
    'panel_id' => 'scm-panel-metricas',
    'url' => $baseUrl . '/index.php?tab=metricas',
    'icon' => 'query_stats',
  ],
  'contratos' => [
    'label' => 'Contratos de Arrendamiento',
    'panel_id' => 'scm-panel-contratos-arrendamiento',
    'url' => $baseUrl . '/index.php?tab=contratos',
    'icon' => 'description',
  ],
  'tickets' => [
    'label' => 'Gestión de Casos & Tickets',
    'panel_id' => 'scm-panel-abiertos',
    'url' => $baseUrl . '/index.php?tab=abiertos',
    'icon' => 'confirmation_number',
  ],
  'liquidacion' => [
    'label' => 'Liquidación de Servicios',
    'panel_id' => 'scm-panel-liquidador-servicios-publicos',
    'url' => $baseUrl . '/index.php?tab=liquidador_servicios_publicos',
    'icon' => 'calculate',
  ],
  'administrativas' => [
    'label' => 'Actividades Administrativas',
    'panel_id' => 'scm-panel-actividades-administrativas',
    'url' => $baseUrl . '/index.php?tab=actividades_administrativas',
    'icon' => 'folder_shared',
  ],
  'vencimientos' => [
    'label' => 'Vencimientos',
    'panel_id' => 'scm-panel-inicio',
    'subtab' => 'vencimientos',
    'url' => $baseUrl . '/index.php?tab=vencimientos',
    'icon' => 'calendar_month',
  ],
];
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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
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
            sans: ["'Plus Jakarta Sans'", "sans-serif"],
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
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: #f8f9ff;
      color: #0b1c30;
    }
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      vertical-align: middle;
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
  <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl . '/assets/css/modern-ui.css?v=' . (defined('SCM_VERSION') ? SCM_VERSION : '2.0.0'), ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body class="min-h-full flex flex-col bg-[#f8f9ff] text-on-surface antialiased <?php echo $isStandalone ? 'scm-standalone-mode' : ''; ?>">

  <!-- ========================================== -->
  <!-- HEADER MAESTRO FIJO (APP SHELL)             -->
  <!-- ========================================== -->
  <header class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200/90 shadow-[0_1px_8px_rgba(15,30,54,0.04)]">
    <div class="w-full max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">

      <!-- Fila 1: Marca, Búsqueda Global y Perfil (Altura 64px) -->
      <div class="h-16 flex items-center justify-between gap-4">

        <!-- Logotipo Oficial SuCasa & Branding Corporativo -->
        <div class="flex items-center gap-3 shrink-0">
          <a href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-3 group focus:outline-none focus:ring-2 focus:ring-[#0f1e36] rounded-lg p-1 transition-all">
            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="SKC SuCasa Inmobiliaria" class="h-9 w-auto object-contain transition-transform group-hover:scale-105">
            <div class="hidden sm:flex flex-col">
              <span class="text-base font-bold tracking-tight text-slate-900 leading-tight">SKC SuCasa</span>
              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 leading-tight">Control Inmobiliario</span>
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

          <!-- Botón de Acción Principal: + Nuevo Ticket -->
          <button
            type="button"
            id="btn-global-nuevo-ticket"
            onclick="window.dispatchEvent(new CustomEvent('scm:open-nuevo-ticket'))"
            class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl bg-[#0f1e36] text-white hover:bg-[#162846] font-semibold text-xs sm:text-sm shadow-sm hover:shadow transition-all active:scale-[0.98]"
          >
            <span class="material-symbols-outlined text-[18px]">add</span>
            <span class="hidden md:inline">Nuevo Ticket</span>
          </button>

          <!-- Botón Configuración / Permisos -->
          <button
            type="button"
            id="btn-global-configuracion"
            onclick="window.dispatchEvent(new CustomEvent('scm:open-configuracion'))"
            title="Configuración operativa"
            class="p-2 sm:px-3 sm:py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent hover:border-slate-200 transition-colors flex items-center gap-1 text-xs sm:text-sm font-medium"
          >
            <span class="material-symbols-outlined text-[20px]">settings</span>
            <span class="hidden xl:inline">Configuración</span>
          </button>

          <!-- Notificaciones con Indicador Activo -->
          <div class="relative">
            <button
              type="button"
              id="btn-global-notificaciones"
              onclick="window.dispatchEvent(new CustomEvent('scm:open-notificaciones'))"
              title="Notificaciones"
              class="relative p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent hover:border-slate-200 transition-colors"
            >
              <span class="material-symbols-outlined text-[22px]">notifications</span>
              <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-amber-500 ring-2 ring-white animate-pulse"></span>
            </button>
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
              <img
                src="<?php echo htmlspecialchars($userAvatar, ENT_QUOTES, 'UTF-8'); ?>"
                alt="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>"
                class="w-8 h-8 rounded-full object-cover ring-1 ring-slate-200 shadow-2xs"
                onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=0f1e36&color=ffffff';"
              >
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
                class="flex items-center gap-2 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition-colors"
                role="menuitem"
              >
                <span class="material-symbols-outlined text-[16px] text-slate-500">assignment_ind</span>
                Mis Tickets Asignados
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
      <div class="h-12 flex items-center overflow-x-auto no-scrollbar border-t border-slate-100">
        <nav class="flex items-center gap-1.5 py-1" aria-label="Pestañas principales del sistema">
          <?php foreach ($navTabs as $tabKey => $tab): ?>
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
          <?php endforeach; ?>
        </nav>
      </div>

    </div>
  </header>

  <!-- ========================================== -->
  <!-- CONTENEDOR PRINCIPAL DEL LAYOUT             -->
  <!-- ========================================== -->
  <main class="flex-1 w-full max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-12 transition-all">

    <?php if ($isStandalone): ?>
      <div class="mb-4 p-3.5 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-xs flex items-center justify-between" role="status">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-blue-600 text-[18px]">verified_user</span>
          <span><strong>Función protegida activa:</strong> Vista ejecutada con credenciales verificadas.</span>
        </div>
        <a href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>" class="font-semibold text-blue-700 hover:underline">Ir al panel completo →</a>
      </div>
    <?php endif; ?>
