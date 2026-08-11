<?php

namespace SCM\Views;

final class DashboardView
{
  public function render(string $baseUrl, string $user, string $panelHtml): void
  {
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Panel de Control — Servicios Inmobiliarios</title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <style>
        *,
        *::before,
        *::after {
          box-sizing: border-box;
          margin: 0;
          padding: 0;
        }

        body {
          font-family: 'Poppins', sans-serif;
          background:
            radial-gradient(circle at 8% 0%, rgba(255, 122, 0, .10), transparent 26%),
            radial-gradient(circle at 95% 0%, rgba(47, 111, 167, .10), transparent 32%),
            #f3f7fc;
          color: #1c3048;
        }

        .top-bar {
          background: linear-gradient(135deg, #1f4c78, #2d6ea7);
          color: #fff;
          padding: .85rem 1.5rem;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 1rem;
          flex-wrap: wrap;
          border-bottom: 1px solid rgba(255, 255, 255, .2);
          box-shadow: 0 8px 24px rgba(16, 41, 68, .25);
        }

        .top-bar .brand {
          display: inline-flex;
          align-items: center;
          gap: .7rem;
          font-weight: 700;
          font-size: 1rem;
          color: #ffd2ab;
          text-decoration: none;
        }

        .top-bar .brand-logo {
          width: 142px;
          min-height: 40px;
          border-radius: 8px;
          background: #fff;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: .35rem .55rem;
          box-shadow: 0 8px 18px rgba(13, 30, 47, .18);
          flex: 0 0 auto;
        }

        .top-bar .brand-logo img {
          display: block;
          width: auto;
          max-width: 100%;
          max-height: 30px;
          object-fit: contain;
        }

        .top-bar .nav-links a {
          color: #dbe8f7;
          font-size: .82rem;
          text-decoration: none;
          margin-left: 1.2rem;
          transition: color .2s;
        }

        .top-bar .nav-links a:hover {
          color: #fff;
          text-decoration: underline;
          text-decoration-thickness: 1.5px;
        }

        .top-bar .user-info {
          font-size: .82rem;
          color: #e8f1fb;
        }

        .page-body {
          padding: 1.25rem;
        }
      </style>
    </head>

    <body>
      <div class="top-bar">
        <a class="brand" href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>">
          <span class="brand-logo">
            <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
          </span>
          <span>Control Servicios Inmobiliarios</span>
        </a>
        <div class="nav-links">

          <a href="<?php echo htmlspecialchars($baseUrl . '/logout.php', ENT_QUOTES, 'UTF-8'); ?>">Cerrar sesión</a>
        </div>
        <span class="user-info"><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>

      <div class="page-body">
        <?php echo $panelHtml; ?>
      </div>
    </body>

    </html>
<?php
  }
}
