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
