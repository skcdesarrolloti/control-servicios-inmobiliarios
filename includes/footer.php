<?php

declare(strict_types=1);

/**
 * Layout Maestro - Footer Global
 * SKC SuCasa Inmobiliaria — Control de Servicios Inmobiliarios
 */

$scmVersion = defined('SCM_VERSION') ? SCM_VERSION : '2.0.0';
?>
  </main>

  <!-- ========================================== -->
  <!-- FOOTER MAESTRO INSTITUCIONAL                -->
  <!-- ========================================== -->
  <footer class="mt-auto border-t border-slate-200/80 bg-white/70 backdrop-blur-xs py-5">
    <div class="w-full px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
      
      <!-- Marca y Derechos -->
      <div class="flex items-center gap-2">
        <span class="font-semibold text-slate-700">SKC SuCasa Inmobiliaria</span>
        <span>•</span>
        <span>Sistema de Control y Operaciones Inmobiliarias</span>
        <span>•</span>
        <span>&copy; <?php echo date('Y'); ?> Todos los derechos reservados.</span>
      </div>

      <!-- Enlaces y Versión -->
      <div class="flex items-center gap-4 text-[11px]">
        <button type="button" onclick="window.dispatchEvent(new CustomEvent('scm:open-guia'))" class="hover:text-slate-900 transition-colors flex items-center gap-1">
          <span class="material-symbols-outlined text-[14px]">help_outline</span>
          <span>Guía de uso</span>
        </button>
        <span class="text-slate-300">|</span>
        <span class="px-2 py-0.5 rounded-md bg-slate-100 font-mono text-[10px] text-slate-600 border border-slate-200/60">
          v<?php echo htmlspecialchars($scmVersion, ENT_QUOTES, 'UTF-8'); ?>
        </span>
      </div>

    </div>
  </footer>

  <!-- ========================================== -->
  <!-- CONTROLADOR JAVASCRIPT DEL APP SHELL        -->
  <!-- ========================================== -->
  <script>
    (function () {
      'use strict';

      // 1. Menú desplegable de perfil de usuario, configuración y notificaciones operativas
      const profileBtn = document.getElementById('user-profile-button');
      const dropdownMenu = document.getElementById('user-dropdown-menu');
      const configBtn = document.getElementById('btn-global-configuracion');
      const configDropdown = document.getElementById('global-config-dropdown-menu');
      const notifBtn = document.getElementById('btn-global-notificaciones');
      const notifDropdown = document.getElementById('global-notifications-dropdown-menu');
      const appBaseUrl = '<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, "UTF-8"); ?>';

      if (profileBtn && dropdownMenu) {
        profileBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (configDropdown) configDropdown.classList.add('hidden');
          if (notifDropdown) notifDropdown.classList.add('hidden');
          const isHidden = dropdownMenu.classList.contains('hidden');
          dropdownMenu.classList.toggle('hidden', !isHidden);
          profileBtn.setAttribute('aria-expanded', String(isHidden));
        });
      }

      if (configBtn && configDropdown) {
        configBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (dropdownMenu) dropdownMenu.classList.add('hidden');
          if (notifDropdown) notifDropdown.classList.add('hidden');
          const isHidden = configDropdown.classList.contains('hidden');
          configDropdown.classList.toggle('hidden', !isHidden);
          configBtn.setAttribute('aria-expanded', String(isHidden));
        });

        configDropdown.addEventListener('click', function (e) {
          const actionBtn = e.target.closest('[data-scm-config-action]');
          if (!actionBtn) return;
          const action = actionBtn.getAttribute('data-scm-config-action');
          configDropdown.classList.add('hidden');
          configBtn.setAttribute('aria-expanded', 'false');

          if (action === 'permissions') {
            const el = document.getElementById('scm-open-permissions');
            if (el) el.click();
            else window.dispatchEvent(new CustomEvent('scm:open-configuracion'));
          } else if (action === 'due-settings') {
            const el = document.querySelector('[data-scm-open-due-settings]');
            if (el) el.click();
          } else if (action === 'notifications') {
            const el = document.getElementById('scm-open-internal-notifications') || document.getElementById('scm-open-pqr-settings');
            if (el) el.click();
            else window.dispatchEvent(new CustomEvent('scm:open-notificaciones'));
          } else if (action === 'guardian-settings') {
            const el = document.getElementById('scm-open-pqr-settings');
            if (el) el.click();
            else window.dispatchEvent(new CustomEvent('scm:open-configuracion-guardian'));
          } else if (action === 'actas-guide') {
            const el = document.getElementById('scm-open-actas-guide');
            if (el) el.click();
          } else if (action === 'guide') {
            const el = document.getElementById('scm-open-guide');
            if (el) el.click();
            else window.dispatchEvent(new CustomEvent('scm:open-guia'));
          }
        });
      }

      if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (dropdownMenu) dropdownMenu.classList.add('hidden');
          if (configDropdown) configDropdown.classList.add('hidden');
          const isHidden = notifDropdown.classList.contains('hidden');
          notifDropdown.classList.toggle('hidden', !isHidden);
          notifBtn.setAttribute('aria-expanded', String(isHidden));
        });

        notifDropdown.addEventListener('click', function (e) {
          const item = e.target.closest('[data-scm-notif-ticket]');
          if (!item) return;
          const ticketPk = item.getAttribute('data-scm-notif-ticket');
          const logicalId = item.getAttribute('data-scm-notif-logical');
          notifDropdown.classList.add('hidden');
          notifBtn.setAttribute('aria-expanded', 'false');

          // Buscar botón de caso en la vista activa
          const selectors = [
            '.scm-btn-case[data-ticket-pk="' + ticketPk + '"]',
            '.scm-btn-case[data-ticket="' + logicalId + '"]',
            '.scm-btn-case[data-ticket="' + ticketPk + '"]',
            '[data-scm-open-linked-ticket-case][data-ticket-pk="' + ticketPk + '"]',
            '[data-ticket-id="' + ticketPk + '"]'
          ];
          let foundBtn = null;
          for (let i = 0; i < selectors.length; i++) {
            foundBtn = document.querySelector(selectors[i]);
            if (foundBtn) break;
          }
          if (foundBtn && typeof window.scmOpenCase === 'function') {
            window.scmOpenCase(foundBtn);
            return;
          }

          // Si no está en el DOM actual, navegar a la pestaña adecuada
          const isMine = item.getAttribute('data-scm-notif-is-mine') === '1';
          const subtab = item.getAttribute('data-scm-notif-subtab') || '';
          let targetUrl = appBaseUrl + '/index.php?';
          if (isMine) {
            targetUrl += 'tab=mis_tickets&ticket=' + encodeURIComponent(logicalId);
          } else {
            targetUrl += 'tab=abiertos' + (subtab ? '&scm_tab=' + encodeURIComponent(subtab) : '') + '&ticket=' + encodeURIComponent(logicalId);
          }
          window.location.href = targetUrl;
        });
      }

      // 1b. Menús desplegables del navbar (Inicio, Gestión de Casos, Actividades Administrativas)
      const navDropdownContainers = document.querySelectorAll('[data-scm-nav-dropdown]');
      navDropdownContainers.forEach(function (container) {
        const trigger = container.querySelector('.nav-tab-dropdown-btn');
        const menu = container.querySelector('[data-scm-dropdown-menu]');
        if (!trigger || !menu) return;

        function openNavDropdown() {
          if (container.getAttribute('data-closed-by-click') === 'true') return;
          navDropdownContainers.forEach(function (other) {
            if (other !== container) {
              const otherMenu = other.querySelector('[data-scm-dropdown-menu]');
              if (otherMenu) otherMenu.classList.add('hidden');
              other.classList.remove('open');
              const otherBtn = other.querySelector('.nav-tab-dropdown-btn');
              if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
            }
          });
          if (dropdownMenu) dropdownMenu.classList.add('hidden');
          if (configDropdown) configDropdown.classList.add('hidden');
          if (notifDropdown) notifDropdown.classList.add('hidden');
          menu.classList.remove('hidden');
          container.classList.add('open');
          trigger.setAttribute('aria-expanded', 'true');
        }

        function closeNavDropdown() {
          menu.classList.add('hidden');
          container.classList.remove('open');
          trigger.setAttribute('aria-expanded', 'false');
          container.removeAttribute('data-closed-by-click');
        }

        container.addEventListener('mouseenter', openNavDropdown);
        container.addEventListener('mouseleave', closeNavDropdown);

        trigger.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          container.removeAttribute('data-closed-by-click');
          const isCurrentlyOpen = container.classList.contains('open') && !menu.classList.contains('hidden');
          if (isCurrentlyOpen) {
            closeNavDropdown();
          } else {
            openNavDropdown();
          }
        });
      });

      document.addEventListener('click', function (e) {
        if (dropdownMenu && !dropdownMenu.contains(e.target) && profileBtn && !profileBtn.contains(e.target)) {
          dropdownMenu.classList.add('hidden');
          if (profileBtn) profileBtn.setAttribute('aria-expanded', 'false');
        }
        if (configDropdown && !configDropdown.contains(e.target) && configBtn && !configBtn.contains(e.target)) {
          configDropdown.classList.add('hidden');
          if (configBtn) configBtn.setAttribute('aria-expanded', 'false');
        }
        if (notifDropdown && !notifDropdown.contains(e.target) && notifBtn && !notifBtn.contains(e.target)) {
          notifDropdown.classList.add('hidden');
          if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
        }
        navDropdownContainers.forEach(function (container) {
          if (!container.contains(e.target)) {
            const menu = container.querySelector('[data-scm-dropdown-menu]');
            if (menu) menu.classList.add('hidden');
            container.classList.remove('open');
            container.removeAttribute('data-closed-by-click');
            const trigger = container.querySelector('.nav-tab-dropdown-btn');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
          }
        });
      });

      // 2. Atajo global de búsqueda: Cmd+K o Ctrl+K, y cierre con Escape
      const searchInput = document.getElementById('global-search-input');
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          if (dropdownMenu) {
            dropdownMenu.classList.add('hidden');
            if (profileBtn) profileBtn.setAttribute('aria-expanded', 'false');
          }
          if (configDropdown) {
            configDropdown.classList.add('hidden');
            if (configBtn) configBtn.setAttribute('aria-expanded', 'false');
          }
          if (notifDropdown) {
            notifDropdown.classList.add('hidden');
            if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
          }
          navDropdownContainers.forEach(function (container) {
            const menu = container.querySelector('[data-scm-dropdown-menu]');
            if (menu) menu.classList.add('hidden');
            container.classList.remove('open');
            container.removeAttribute('data-closed-by-click');
            const trigger = container.querySelector('.nav-tab-dropdown-btn');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
          });
        }
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
          e.preventDefault();
          if (searchInput) {
            searchInput.focus();
            searchInput.select();
          }
        }
      });

      // 3. Puente de sincronización entre píldoras del Header y las pestañas nativas del panel SCM
      const navPills = document.querySelectorAll('.nav-tab-pill');
      navPills.forEach(function (pill) {
        pill.addEventListener('click', function (e) {
          const panelTarget = this.getAttribute('data-panel-target');
          const subtabTarget = this.getAttribute('data-subtab-target');
          let adminSubTarget = this.getAttribute('data-admin-sub-target') || null;

          // Si el elemento clicado pertenece a un dropdown, cerrarlo de inmediato
          if (this.classList.contains('nav-tab-dropdown-item')) {
            const container = this.closest('[data-scm-nav-dropdown]');
            if (container) {
              const menu = container.querySelector('[data-scm-dropdown-menu]');
              if (menu) menu.classList.add('hidden');
              container.classList.remove('open');
              container.setAttribute('data-closed-by-click', 'true');
              const trigger = container.querySelector('.nav-tab-dropdown-btn');
              if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
                trigger.blur();
              }
            }
            this.blur();
            if (document.activeElement) document.activeElement.blur();
          }

          // Si el panel principal #scm-app existe en esta página, realizamos cambio reactivo sin recarga
          const scmApp = document.getElementById('scm-app');
          if (scmApp && panelTarget) {
            const selectedTabKey = this.getAttribute('data-tab-key') || '';
            let nativeTabBtn = scmApp.querySelector('.scm-main-tabs .scm-tab[data-tab="' + panelTarget + '"]');
            if (!adminSubTarget && !nativeTabBtn) {
              const subBtn = scmApp.querySelector('[data-admin-activity-target="' + panelTarget + '"]');
              if (subBtn) {
                nativeTabBtn = scmApp.querySelector('.scm-main-tabs .scm-tab[data-tab="scm-panel-actividades-administrativas"]');
                adminSubTarget = panelTarget;
              } else if (panelTarget === 'scm-panel-liquidador-servicios-publicos') {
                nativeTabBtn = scmApp.querySelector('.scm-main-tabs .scm-tab[data-tab="scm-panel-actividades-administrativas"]');
                adminSubTarget = 'scm-panel-liquidador-servicios-publicos';
              }
            }

            if (nativeTabBtn) {
              e.preventDefault();
              nativeTabBtn.click();
              window.scrollTo({ top: 0, behavior: 'instant' });
              if (panelTarget === 'scm-panel-inicio' && selectedTabKey === 'inicio') {
                setTimeout(function () {
                  scmApp.dispatchEvent(new CustomEvent('scm:home-summary-selected', { bubbles: true }));
                }, 180);
              }

              if (adminSubTarget) {
                setTimeout(function () {
                  const parentAdminPanel = scmApp.querySelector('#scm-panel-actividades-administrativas');
                  if (parentAdminPanel) {
                    parentAdminPanel.querySelectorAll('.scm-admin-activity-panel').forEach(function (panel) {
                      panel.classList.toggle('active', panel.id === adminSubTarget);
                    });
                    parentAdminPanel.querySelectorAll('.scm-admin-activity-tab').forEach(function (btn) {
                      const tTarget = btn.getAttribute('data-admin-activity-target');
                      btn.classList.toggle('active', tTarget === adminSubTarget);
                    });
                  }
                  const subBtn = document.querySelector('[data-admin-activity-target="' + adminSubTarget + '"]');
                  if (subBtn) subBtn.click();
                }, 120);
              }

              // Si tiene subtab especificado (ej. vencimientos dentro de inicio)
              if (subtabTarget) {
                setTimeout(function () {
                  const subtabBtn = document.querySelector('[data-calendar-section-target="scm-home-calendar-section-' + subtabTarget + '"]');
                  if (subtabBtn) subtabBtn.click();
                }, 120);
              }

              // Actualizar estilo visual activo de las píldoras
              const parentDropdown = this.closest('[data-scm-nav-dropdown]');
              navPills.forEach(function (p) {
                p.classList.remove('bg-[#0f1e36]', 'text-white', 'font-semibold', 'shadow-xs', 'bg-slate-100');
                if (p.classList.contains('nav-tab-dropdown-item')) {
                  p.classList.add('text-slate-700', 'font-medium');
                } else {
                  p.classList.add('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100', 'font-medium');
                }
                const icon = p.querySelector('.material-symbols-outlined');
                if (icon) {
                  icon.classList.remove('text-white');
                  icon.classList.add('text-slate-500');
                }
                p.removeAttribute('aria-current');
              });

              if (parentDropdown) {
                const dropBtn = parentDropdown.querySelector('.nav-tab-dropdown-btn');
                if (dropBtn) {
                  dropBtn.classList.add('bg-[#0f1e36]', 'text-white', 'font-semibold', 'shadow-xs');
                  dropBtn.classList.remove('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100', 'font-medium');
                  const dropBtnIcon = dropBtn.querySelector('.material-symbols-outlined');
                  if (dropBtnIcon) {
                    dropBtnIcon.classList.add('text-white');
                    dropBtnIcon.classList.remove('text-slate-500');
                  }
                }
                this.classList.add('bg-[#0f1e36]', 'text-white', 'font-semibold');
                this.classList.remove('text-slate-700');
              } else {
                this.classList.add('bg-[#0f1e36]', 'text-white', 'font-semibold', 'shadow-xs');
                this.classList.remove('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100', 'font-medium');
              }

              const activeIcon = this.querySelector('.material-symbols-outlined');
              if (activeIcon) {
                activeIcon.classList.add('text-white');
                activeIcon.classList.remove('text-slate-500');
              }
              this.setAttribute('aria-current', 'page');

              // Actualizar la URL de forma limpia
              if (window.history && window.history.pushState) {
                const url = new URL(window.location.href);
                const tabKey = this.getAttribute('data-tab-key') || '';
                url.searchParams.set('tab', tabKey);
                window.history.pushState({}, '', url.toString());
              }
            }
          }
        });
      });

      // 3b. Puente de sincronización para botones de estado de tickets (.scm-status-nav-pill)
      const ticketStatusPills = document.querySelectorAll('[data-ticket-status-target]');
      ticketStatusPills.forEach(function (pill) {
        pill.addEventListener('click', function (e) {
          e.preventDefault();
          const target = this.getAttribute('data-ticket-status-target');
          const scmApp = document.getElementById('scm-app');
          if (scmApp && target) {
            const nativeTabBtn = scmApp.querySelector('.scm-main-tabs .scm-tab[data-tab="' + target + '"]');
            if (nativeTabBtn) {
              nativeTabBtn.click();
            }
          }

          // Encontrar píldora correspondiente en el dropdown del Header y activar
          const headerChild = document.querySelector('.nav-tab-dropdown-item[data-panel-target="' + target + '"]');
          if (headerChild) {
            navPills.forEach(function (p) {
              p.classList.remove('bg-[#0f1e36]', 'text-white', 'font-semibold', 'shadow-xs', 'bg-slate-100');
              if (p.classList.contains('nav-tab-dropdown-item')) {
                p.classList.add('text-slate-700', 'font-medium');
              } else {
                p.classList.add('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100', 'font-medium');
              }
              const icon = p.querySelector('.material-symbols-outlined');
              if (icon) {
                icon.classList.remove('text-white');
                icon.classList.add('text-slate-500');
              }
              p.removeAttribute('aria-current');
            });

            const parentDropdown = headerChild.closest('[data-scm-nav-dropdown]');
            if (parentDropdown) {
              const dropBtn = parentDropdown.querySelector('.nav-tab-dropdown-btn');
              if (dropBtn) {
                dropBtn.classList.add('bg-[#0f1e36]', 'text-white', 'font-semibold', 'shadow-xs');
                dropBtn.classList.remove('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100', 'font-medium');
                const dropBtnIcon = dropBtn.querySelector('.material-symbols-outlined');
                if (dropBtnIcon) {
                  dropBtnIcon.classList.add('text-white');
                  dropBtnIcon.classList.remove('text-slate-500');
                }
              }
              headerChild.classList.add('bg-[#0f1e36]', 'text-white', 'font-semibold');
              headerChild.classList.remove('text-slate-700');
            }
            const activeIcon = headerChild.querySelector('.material-symbols-outlined');
            if (activeIcon) {
              activeIcon.classList.add('text-white');
              activeIcon.classList.remove('text-slate-500');
            }
            headerChild.setAttribute('aria-current', 'page');
          }

          if (window.history && window.history.pushState && target) {
            const url = new URL(window.location.href);
            const tabKey = target.replace('scm-panel-', '');
            url.searchParams.set('tab', tabKey);
            window.history.pushState({}, '', url.toString());
          }
        });
      });

      // 4. Conexión de eventos rápidos del Header con los disparadores nativos existentes
      window.addEventListener('scm:open-nuevo-ticket', function () {
        // Disparar modal de creación de ticket o abrir formulario administrativo
        const createTicketBtn = document.querySelector('[data-scm-crear-ticket], #scm-open-crear-ticket, .scm-btn-nuevo-ticket');
        if (createTicketBtn) {
          createTicketBtn.click();
        } else {
          // Si no está el botón directo, abrir pestaña de tickets y alertar
          const ticketsPill = document.querySelector('.nav-tab-pill[data-tab-key="tickets"]');
          if (ticketsPill) ticketsPill.click();
        }
      });

      window.addEventListener('scm:open-configuracion', function () {
        const permBtn = document.getElementById('scm-open-permissions');
        if (permBtn) {
          permBtn.click();
        } else {
          const dueSettingsBtn = document.querySelector('[data-scm-open-due-settings]');
          if (dueSettingsBtn) dueSettingsBtn.click();
        }
      });

      window.addEventListener('scm:open-notificaciones', function () {
        const notifBtn = document.getElementById('scm-open-internal-notifications') || document.getElementById('scm-open-pqr-settings');
        if (notifBtn) {
          notifBtn.click();
        } else {
          const adminPill = document.querySelector('.nav-tab-pill[data-tab-key="administrativas"]');
          if (adminPill) adminPill.click();
        }
      });

      window.addEventListener('scm:open-configuracion-guardian', function () {
        const guardianBtn = document.getElementById('scm-open-pqr-settings');
        if (guardianBtn) {
          guardianBtn.click();
        } else {
          const ticketsPill = document.querySelector('.nav-tab-pill[data-tab-key="tickets"]');
          if (ticketsPill) ticketsPill.click();
        }
      });

      window.addEventListener('scm:open-guia', function () {
        const guideBtn = document.getElementById('scm-open-guide');
        if (guideBtn) guideBtn.click();
      });

      // 5. Búsqueda rápida global vinculada con filtros de tablas activas
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          const query = this.value.trim().toLowerCase();
          // Despachar evento para que cualquier vista activa pueda auto-filtrar en tiempo real
          window.dispatchEvent(new CustomEvent('scm:global-search', { detail: { query: query } }));
        });
      }

      // 6. Colapsar / Expandir panel de filtros avanzados
      const filterCollapseBtn = document.getElementById('scm-filter-collapse-toggle');
      const filterGrid = document.getElementById('scm-filter-grid');
      if (filterCollapseBtn && filterGrid) {
        filterCollapseBtn.addEventListener('click', function () {
          const isCollapsed = filterGrid.classList.contains('hidden');
          filterGrid.classList.toggle('hidden', !isCollapsed);
          const icon = filterCollapseBtn.querySelector('.material-symbols-outlined');
          const text = filterCollapseBtn.querySelector('.scm-filter-collapse-text');
          if (icon) {
            icon.textContent = isCollapsed ? 'expand_less' : 'expand_more';
          }
          if (text) {
            text.textContent = isCollapsed ? 'Colapsar panel' : 'Mostrar panel';
          }
        });
      }

      // 7. Auto-abrir caso si viene indicado en los parámetros de la URL (?ticket=...)
      try {
        const urlParams = new URLSearchParams(window.location.search);
        const ticketParam = urlParams.get('ticket');
        if (ticketParam) {
          setTimeout(function () {
            const selectors = [
              '.scm-btn-case[data-ticket-pk="' + ticketParam + '"]',
              '.scm-btn-case[data-ticket="' + ticketParam + '"]',
              '[data-scm-open-linked-ticket-case][data-ticket-pk="' + ticketParam + '"]',
              '[data-ticket-id="' + ticketParam + '"]'
            ];
            for (let i = 0; i < selectors.length; i++) {
              const btn = document.querySelector(selectors[i]);
              if (btn && typeof window.scmOpenCase === 'function') {
                window.scmOpenCase(btn);
                break;
              }
            }
          }, 350);
        }
      } catch (err) {}

    })();
  </script>
</body>
</html>
