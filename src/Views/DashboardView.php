<?php

namespace SCM\Views;

final class DashboardView
{
  public function render(string $baseUrl, string $user, string $panelHtml, bool $standaloneFunction = false): void
  {
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?php echo $standaloneFunction ? 'Función protegida' : 'Panel de Control'; ?> — Servicios Inmobiliarios</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/dashboard-shell.css?v=' . SCM_VERSION); ?>">
    </head>

    <body<?php echo $standaloneFunction ? ' class="scm-standalone-function"' : ''; ?>>
      <div class="top-bar">
        <a class="brand" href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>">
          <span class="brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span>Control Servicios Inmobiliarios</span>
        </a>
        <div class="nav-links">
          <form method="post" action="<?php echo htmlspecialchars($baseUrl . '/logout.php', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo \SCM\Core\App::csrf()->field('logout'); ?>
            <button type="submit">Cerrar sesión</button>
          </form>
        </div>
        <span class="user-info"><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>

      <div class="page-body">
        <?php if ($standaloneFunction): ?>
          <div class="scm-standalone-function-status" role="status" aria-live="polite">
            <span>Abriendo función protegida…</span>
            <small>Esta pantalla viene desde WordPress y no muestra el panel completo.</small>
          </div>
        <?php endif; ?>
        <?php echo $panelHtml; ?>
      </div>
    </body>

    </html>
<?php
  }
}
