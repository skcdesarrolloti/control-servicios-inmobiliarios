<?php

namespace SCM\Views;

final class PublicTicketHubView
{
  /**
   * @param array<string,array<string,string>> $roles
   */
  public function render(array $roles, string $baseUrl = '', string $homeUrl = 'https://sucasainmobiliaria.com.co/'): void
  {
    if (empty($roles)) {
      http_response_code(500);
      echo 'No hay perfiles disponibles.';
      return;
    }

    $firstRole = '';
    foreach ($roles as $roleId => $roleData) {
      $firstRole = (string) $roleId;
      break;
    }
    if ($firstRole === '') {
      http_response_code(500);
      echo 'No hay perfiles disponibles.';
      return;
    }

    $queryRole = sanitize_key((string) ($_GET['rol'] ?? ''));
    $activeRole = isset($roles[$queryRole]) ? $queryRole : $firstRole;

    $runtime = [
      'roles' => $roles,
      'activeRole' => $activeRole,
      'baseUrl' => rtrim($baseUrl, '/'),
      'homeUrl' => $homeUrl,
    ];
    $runtimeJson = htmlspecialchars((string) json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Centro de solicitudes - SK&C</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-ticket-hub.css?v=' . SCM_VERSION); ?>">
    </head>

    <body>
      <div class="shell" id="ticket-hub" data-runtime="<?php echo $runtimeJson; ?>">
        <section class="hero">
          <div>
            <div class="hero-brand">
              <span class="brand-logo">
                <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
              </span>
              <span class="hero-badge">Centro digital de solicitudes SK&C</span>
            </div>
            <h1>Bienvenido, cu&eacute;ntanos qui&eacute;n eres</h1>
            <p>Selecciona tu perfil para ver el formulario adecuado. Todo se carga en esta misma pantalla, sin recargar la p&aacute;gina principal.</p>
          </div>
          <div class="hero-card">
            <img id="hero-castor" class="hero-castor" src="" alt="Castor SK&C" loading="lazy" onerror="this.style.display='none'">
            <div>
              <h3 id="hero-role-title" class="hero-role-title">Perfil</h3>
              <p id="hero-role-text" class="hero-role-text">Selecciona un perfil para iniciar.</p>
            </div>
          </div>
        </section>

        <section class="selector" id="role-selector">
          <h2>Usted es...</h2>
          <p>Escoge el rol para continuar con la consulta y radicaci&oacute;n de solicitudes.</p>
          <div class="role-grid" id="role-grid"></div>
        </section>

        <section class="viewer hidden" id="portal-viewer">
          <div class="viewer-head">
            <div>
              <h3 id="viewer-title">Portal</h3>
              <p id="viewer-subtitle">Carga din&aacute;mica por perfil</p>
            </div>
            <div class="head-actions">
              <button class="btn-link" id="change-role" type="button">Cambiar perfil</button>
            </div>
          </div>
          <div class="viewer-body">
            <iframe id="portal-frame" class="portal-frame" title="Portal de solicitudes"></iframe>
            <div id="viewer-loading" class="loading">
              <span>Cargando portal</span>
              <span class="dot"></span>
              <span class="dot"></span>
              <span class="dot"></span>
            </div>
          </div>
        </section>
      </div>

      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/public-ticket-hub.js?v=' . SCM_VERSION); ?>" defer></script>
    </body>

    </html>
<?php
  }
}


