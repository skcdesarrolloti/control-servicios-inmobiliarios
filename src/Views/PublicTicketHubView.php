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
      <style>
        * {
          box-sizing: border-box;
        }

        body {
          margin: 0;
          font-family: 'Poppins', sans-serif;
          color: #1b2d43;
          background:
            radial-gradient(circle at 0% 0%, rgba(245, 145, 1, .14), transparent 36%),
            radial-gradient(circle at 100% 0%, rgba(47, 111, 167, .16), transparent 30%),
            #eef4fa;
        }

        .shell {
          max-width: 1160px;
          margin: 0 auto;
          padding: 28px 20px 44px;
        }

        .hero {
          background: linear-gradient(140deg, #183f68, #2f6fa7);
          color: #fff;
          border-radius: 18px;
          border: 1px solid rgba(255, 255, 255, .2);
          box-shadow: 0 20px 45px rgba(17, 43, 71, .28);
          padding: 24px 24px 20px;
          display: grid;
          grid-template-columns: 1.2fr .8fr;
          gap: 18px;
          align-items: stretch;
        }

        .hero h1 {
          margin: 0 0 10px;
          font-size: 30px;
          line-height: 1.1;
        }

        .hero p {
          margin: 0 0 12px;
          color: #d9e8f7;
          font-size: 14px;
          line-height: 1.55;
        }

        .hero-badge {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          font-size: 12px;
          padding: 6px 10px;
          border-radius: 999px;
          background: rgba(255, 255, 255, .16);
          border: 1px solid rgba(255, 255, 255, .2);
        }

        .hero-brand {
          display: flex;
          align-items: center;
          gap: 12px;
          flex-wrap: wrap;
          margin-bottom: 12px;
        }

        .brand-logo {
          width: 154px;
          min-height: 48px;
          border-radius: 8px;
          background: #fff;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 7px 10px;
          box-shadow: 0 12px 26px rgba(10, 30, 50, .20);
        }

        .brand-logo img {
          display: block;
          width: auto;
          max-width: 100%;
          max-height: 34px;
          object-fit: contain;
        }

        .hero-card {
          background: rgba(8, 26, 46, .28);
          border: 1px solid rgba(255, 255, 255, .18);
          border-radius: 14px;
          display: grid;
          grid-template-columns: 132px 1fr;
          gap: 16px;
          padding: 14px;
          align-items: center;
        }

        .hero-castor {
          width: 132px;
          height: 132px;
          border-radius: 12px;
          object-fit: cover;
          background: rgba(255, 255, 255, .12);
          border: 1px solid rgba(255, 255, 255, .2);
        }

        .hero-role-title {
          margin: 0 0 4px;
          font-size: 18px;
          line-height: 1.2;
        }

        .hero-role-text {
          margin: 0;
          font-size: 13px;
          color: #dce8f7;
          line-height: 1.5;
        }

        .selector {
          margin-top: 18px;
          background: #fff;
          border: 1px solid #d8e3ef;
          border-radius: 14px;
          box-shadow: 0 10px 26px rgba(19, 42, 66, .08);
          padding: 22px;
        }

        .selector.hidden,
        .viewer.hidden {
          display: none;
        }

        .selector h2 {
          margin: 0 0 8px;
          font-size: 18px;
        }

        .selector p {
          margin: 0 0 14px;
          color: #5d728a;
          font-size: 13px;
        }

        .role-grid {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 16px;
        }

        .role-btn {
          appearance: none;
          border: 1px solid #cfd9e6;
          background: #f7fbff;
          color: #1f3a57;
          border-radius: 14px;
          padding: 16px;
          cursor: pointer;
          text-align: left;
          transition: transform .15s ease, border-color .15s ease, background .15s ease;
          display: grid;
          grid-template-columns: 72px 1fr;
          gap: 14px;
          align-items: center;
          min-height: 112px;
        }

        .role-btn:hover {
          transform: translateY(-1px);
          border-color: #9fbedf;
          background: #ecf5ff;
        }

        .role-btn.active {
          border-color: #f18f01;
          background: #fff6e9;
          box-shadow: 0 10px 20px rgba(241, 145, 1, .18);
        }

        .role-thumb {
          width: 72px;
          height: 72px;
          border-radius: 12px;
          object-fit: cover;
          border: 1px solid #d4e1ef;
          background: #fff;
        }

        .role-btn strong {
          display: block;
          font-size: 24px;
          line-height: 1.1;
          margin-bottom: 4px;
        }

        .role-btn span {
          display: block;
          font-size: 14px;
          line-height: 1.35;
          color: #60758c;
        }

        .viewer {
          margin-top: 18px;
          background: #fff;
          border: 1px solid #d8e3ef;
          border-radius: 14px;
          box-shadow: 0 10px 26px rgba(19, 42, 66, .08);
          overflow: hidden;
        }

        .viewer-head {
          padding: 16px 20px;
          border-bottom: 1px solid #e6edf5;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          flex-wrap: wrap;
        }

        .viewer-head h3 {
          margin: 0;
          font-size: 16px;
        }

        .viewer-head p {
          margin: 4px 0 0;
          font-size: 12px;
          color: #5d728a;
        }

        .head-actions {
          display: flex;
          gap: 8px;
          flex-wrap: wrap;
        }

        .btn-link {
          text-decoration: none;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          border-radius: 10px;
          border: 1px solid #d2dfec;
          background: #edf3f9;
          color: #22496f;
          font-size: 13px;
          padding: 8px 12px;
          font-weight: 600;
          font-family: inherit;
          cursor: pointer;
        }

        .btn-link.primary {
          border: 0;
          background: linear-gradient(120deg, #f18f01, #f5a73b);
          color: #fff;
          box-shadow: 0 10px 20px rgba(241, 145, 1, .26);
        }

        .viewer-body {
          position: relative;
          height: min(980px, 78vh);
          min-height: 560px;
          background: #f2f7fd;
        }

        .portal-frame {
          width: 100%;
          height: 100%;
          border: 0;
          display: block;
          background: #f2f7fd;
        }

        .loading {
          position: absolute;
          inset: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 10px;
          color: #4d6782;
          font-size: 14px;
          background: rgba(242, 247, 253, .78);
          opacity: 0;
          pointer-events: none;
          transition: opacity .18s ease;
        }

        .loading.show {
          opacity: 1;
          pointer-events: auto;
        }

        .dot {
          width: 9px;
          height: 9px;
          border-radius: 999px;
          background: #f18f01;
          animation: pop .82s ease infinite alternate;
        }

        .dot:nth-child(2) {
          animation-delay: .12s;
        }

        .dot:nth-child(3) {
          animation-delay: .24s;
        }

        @keyframes pop {
          from {
            transform: translateY(0);
            opacity: .55;
          }

          to {
            transform: translateY(-4px);
            opacity: 1;
          }
        }

        @media (max-width: 1040px) {
          .role-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }

          .hero {
            grid-template-columns: 1fr;
          }
        }

        @media (max-width: 640px) {
          .shell {
            padding: 20px 12px 30px;
          }

          .hero {
            padding: 18px 14px;
          }

          .selector,
          .viewer-head {
            padding: 14px;
          }

          .viewer-body {
            height: 76vh;
            min-height: 480px;
          }

          .role-grid {
            grid-template-columns: 1fr;
          }

          .role-btn {
            grid-template-columns: 56px 1fr;
            gap: 10px;
            min-height: 96px;
            padding: 12px;
          }

          .role-thumb {
            width: 56px;
            height: 56px;
          }

          .role-btn strong {
            font-size: 19px;
          }

          .role-btn span {
            font-size: 13px;
          }

          .hero h1 {
            font-size: 24px;
          }

          .hero-card {
            grid-template-columns: 86px 1fr;
          }

          .hero-castor {
            width: 86px;
            height: 86px;
          }
        }
      </style>
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

      <script>
        (function() {
          var root = document.getElementById('ticket-hub');
          if (!root) return;

          var runtime = {};
          try {
            runtime = JSON.parse(root.getAttribute('data-runtime') || '{}');
          } catch (e) {
            runtime = {};
          }

          var roles = runtime.roles || {};
          var activeRole = runtime.activeRole || '';
          var grid = document.getElementById('role-grid');
          var selectorSection = document.getElementById('role-selector');
          var viewerSection = document.getElementById('portal-viewer');
          var frame = document.getElementById('portal-frame');
          var loading = document.getElementById('viewer-loading');
          var viewerTitle = document.getElementById('viewer-title');
          var viewerSubtitle = document.getElementById('viewer-subtitle');
          var heroTitle = document.getElementById('hero-role-title');
          var heroText = document.getElementById('hero-role-text');
          var heroCastor = document.getElementById('hero-castor');
          var changeRoleBtn = document.getElementById('change-role');

          function esc(value) {
            return String(value == null ? '' : value)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;');
          }

          function buildRoleCard(roleId, data) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'role-btn';
            btn.setAttribute('data-role', roleId);
            btn.innerHTML =
              '<img class="role-thumb" src="' + esc(data.mascot || '') + '" alt="Castor ' + esc(data.label || roleId) + '" loading="lazy" onerror="this.style.display=\'none\'">' +
              '<div><strong>' + esc(data.label || roleId) + '</strong><span>' + esc(data.tagline || 'Portal especializado') + '</span></div>';
            btn.addEventListener('click', function() {
              selectRole(roleId, true);
            });
            return btn;
          }

          function getRoleUrl(role) {
            var data = roles[role] || {};
            var url = String(data.url || '');
            if (!url) return '';
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'embedded=1';
            return url;
          }

          function showLoading(show) {
            if (!loading) return;
            loading.classList.toggle('show', !!show);
          }

          function setStep(step) {
            var isPortal = step === 'portal';
            if (selectorSection) selectorSection.classList.toggle('hidden', isPortal);
            if (viewerSection) viewerSection.classList.toggle('hidden', !isPortal);
          }

          function resetRoleSelection(pushHistory) {
            activeRole = '';
            root.querySelectorAll('.role-btn[data-role]').forEach(function(btn) {
              btn.classList.remove('active');
            });

            if (viewerTitle) viewerTitle.textContent = 'Portal';
            if (viewerSubtitle) viewerSubtitle.textContent = 'Carga din\\u00E1mica por perfil';
            if (heroTitle) heroTitle.textContent = 'Perfil';
            if (heroText) heroText.textContent = 'Selecciona un perfil para iniciar.';
            if (heroCastor) {
              heroCastor.src = '';
              heroCastor.style.display = 'none';
            }
            if (frame) frame.removeAttribute('src');
            showLoading(false);
            setStep('select');

            if (pushHistory) {
              var resetUrl = new URL(window.location.href);
              resetUrl.searchParams.delete('rol');
              window.history.pushState({
                role: ''
              }, '', resetUrl.toString());
            }

            if (selectorSection) {
              selectorSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
              });
            }
          }

          function selectRole(role, pushHistory) {
            if (!roles[role]) return;
            activeRole = role;
            var data = roles[role];

            root.querySelectorAll('.role-btn[data-role]').forEach(function(btn) {
              btn.classList.toggle('active', btn.getAttribute('data-role') === role);
            });

            if (viewerTitle) viewerTitle.textContent = 'Portal ' + (data.label || role);
            if (viewerSubtitle) viewerSubtitle.textContent = data.description || 'Formulario de solicitudes';
            if (heroTitle) heroTitle.textContent = data.label || role;
            if (heroText) heroText.textContent = data.welcome || 'Gestiona tus solicitudes con nuestro equipo.';
            if (heroCastor) {
              heroCastor.style.display = '';
              heroCastor.src = data.mascot || '';
            }

            if (frame) {
              var nextUrl = getRoleUrl(role);
              if (frame.getAttribute('src') !== nextUrl) {
                showLoading(true);
                frame.src = nextUrl;
              }
            }
            setStep('portal');

            if (pushHistory) {
              var targetUrl = new URL(window.location.href);
              targetUrl.searchParams.set('rol', role);
              window.history.pushState({
                role: role
              }, '', targetUrl.toString());
            }

            if (viewerSection) {
              viewerSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
              });
            }
          }

          Object.keys(roles).forEach(function(roleId) {
            if (!grid) return;
            grid.appendChild(buildRoleCard(roleId, roles[roleId] || {}));
          });

          if (heroCastor) {
            heroCastor.style.display = 'none';
          }

          var roleFromQuery = new URL(window.location.href).searchParams.get('rol') || '';
          if (roleFromQuery && roles[roleFromQuery]) {
            selectRole(roleFromQuery, false);
          } else {
            setStep('select');
          }

          if (frame) {
            frame.addEventListener('load', function() {
              showLoading(false);
            });
          }

          if (changeRoleBtn) {
            changeRoleBtn.addEventListener('click', function() {
              resetRoleSelection(true);
            });
          }

          window.addEventListener('popstate', function(event) {
            var role = event && event.state && event.state.role ? String(event.state.role) : '';
            if (!role) {
              var roleFromQuery = new URL(window.location.href).searchParams.get('rol') || '';
              role = roleFromQuery;
            }
            if (role && roles[role]) {
              selectRole(role, false);
            } else {
              resetRoleSelection(false);
            }
          });
        })();
      </script>
    </body>

    </html>
<?php
  }
}


