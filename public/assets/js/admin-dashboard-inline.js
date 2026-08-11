(function() {
          function setup(prefix, action, tableId, kpisId) {
            var form = document.getElementById(prefix + 'form');
            if (!form) return;
            var spinner = document.getElementById(prefix + 'spinner');
            var runtimeRaw = document.getElementById('scm-app')?.getAttribute('data-scm-runtime') || '{}';
            var runtime = {};
            try {
              runtime = JSON.parse(runtimeRaw);
            } catch (e) {}
            var ajaxUrl = runtime.ajaxUrl || '/api.php';
            var nonce = runtime.nonce || '';

            function submitForm() {
              var fd = new FormData(form);
              fd.append('action', action);
              fd.append('nonce', nonce);
              if (spinner) spinner.classList.add('active');
              fetch(ajaxUrl, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) return;
                  var d = json.data || {};
                  var t = document.getElementById(tableId);
                  var k = document.getElementById(kpisId);
                  if (t && typeof d.table_html === 'string') t.innerHTML = d.table_html;
                  if (k && typeof d.kpis_html === 'string') k.innerHTML = d.kpis_html;
                  if (prefix === 'spp_') {
                    var headerCount = document.getElementById('spp-kpi-count');
                    if (headerCount && typeof d.count === 'string') headerCount.textContent = d.count;
                  }
                  if (prefix === 'rsp_') {
                    var rspHeaderCount = document.getElementById('rsp-kpi-count');
                    if (rspHeaderCount && typeof d.count === 'string') rspHeaderCount.textContent = d.count;
                  }
                })
                .finally(function() {
                  if (spinner) spinner.classList.remove('active');
                });
            }

            form.addEventListener('submit', function(e) {
              e.preventDefault();
              submitForm();
            });
            var clearBtn = document.querySelector('[data-pending-clear="' + prefix + '"]');
            if (clearBtn) {
              clearBtn.addEventListener('click', function() {
                form.querySelectorAll('input[type="text"],input[type="date"]').forEach(function(i) {
                  i.value = '';
                });
                form.querySelectorAll('select').forEach(function(s) {
                  s.selectedIndex = 0;
                });
                submitForm();
              });
            }
          }
          setup('spp_', 'scm_preventivas_pendientes', 'spp_table', 'spp_kpis');
          setup('rsp_', 'scm_servicios_publicos_pendientes', 'rsp_table', 'rsp_kpis');

          var sraPanel = document.getElementById('sra_panel');
          if (sraPanel) {
            var runtimeRawSra = document.getElementById('scm-app')?.getAttribute('data-scm-runtime') || '{}';
            var runtimeSra = {};
            try {
              runtimeSra = JSON.parse(runtimeRawSra);
            } catch (e) {}
            var ajaxUrlSra = runtimeSra.ajaxUrl || '/api.php';
            var nonceSra = runtimeSra.nonce || '';
            var actionsSra = runtimeSra.actions || {};
            var sraForm = document.getElementById('sra_form');
            var sraSpinner = document.getElementById('sra_spinner');

            function reloadReportesAdministrativos() {
              var fd = sraForm ? new FormData(sraForm) : new FormData();
              fd.append('action', actionsSra.reportes_administrativos_pendientes || 'scm_reportes_administrativos_pendientes');
              fd.append('nonce', nonceSra);
              if (sraSpinner) sraSpinner.classList.add('active');
              fetch(ajaxUrlSra, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) return;
                  var data = json.data || {};
                  var table = document.getElementById('sra_table');
                  var count = document.getElementById('sra-kpi-count');
                  if (table && typeof data.table_html === 'string') table.innerHTML = data.table_html;
                  if (count && typeof data.count === 'string') count.textContent = data.count;
                })
                .finally(function() {
                  if (sraSpinner) sraSpinner.classList.remove('active');
                });
            }

            if (sraForm) {
              sraForm.addEventListener('submit', function(e) {
                e.preventDefault();
                reloadReportesAdministrativos();
              });
            }

            var sraClearBtn = document.querySelector('[data-pending-clear="sra_"]');
            if (sraClearBtn && sraForm) {
              sraClearBtn.addEventListener('click', function() {
                sraForm.querySelectorAll('input[type="text"],input[type="date"]').forEach(function(i) {
                  i.value = '';
                });
                sraForm.querySelectorAll('select').forEach(function(s) {
                  s.selectedIndex = 0;
                });
                reloadReportesAdministrativos();
              });
            }

            sraPanel.addEventListener('click', function(e) {
              var iframeBtn = e.target.closest('[data-scm-open-iframe]');
              if (iframeBtn) {
                if (typeof window.openIframeModal === 'function') {
                  window.openIframeModal(iframeBtn.dataset.iframeUrl || '', iframeBtn.dataset.iframeTitle || '', iframeBtn.hasAttribute('data-scm-compact-iframe'));
                }
                return;
              }

              var actionBtn = e.target.closest('[data-sra-action]');
              if (!actionBtn) return;

              var preId = actionBtn.getAttribute('data-sra-id') || '';
              var kind = actionBtn.getAttribute('data-sra-action') || '';
              var action = kind === 'approve' ? actionsSra.aprobar_reporte_administrativo : actionsSra.desaprobar_reporte_administrativo;
              var question = kind === 'approve' ? '¿Aprobar este reporte administrativo?' : '¿Marcar este reporte como no aprobado?';
              if (!action || !preId || !window.confirm(question)) return;

              actionBtn.disabled = true;
              var fd = new FormData();
              fd.append('action', action);
              fd.append('nonce', nonceSra);
              fd.append('pre_id', preId);

              fetch(ajaxUrlSra, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    window.alert((json && json.data && json.data.message) || 'No se pudo procesar el reporte.');
                    return;
                  }
                  reloadReportesAdministrativos();
                })
                .catch(function() {
                  window.alert('No se pudo procesar el reporte.');
                })
                .finally(function() {
                  actionBtn.disabled = false;
                });
            });
          }

          var pqrPanel = document.getElementById('scm-panel-pqr-publico');
          if (pqrPanel) {
            function initSelect2OnSelect(selectEl) {
              if (!selectEl || !selectEl.classList || !selectEl.classList.contains('scm-select')) return;
              if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) return;
              var $ = window.jQuery;
              var $select = $(selectEl);
              if ($select.data('select2')) return;

              var isMultiple = !!selectEl.multiple;
              var parentModal = selectEl.closest('#scm-pqr-settings-modal, .scm-pqr-transfer-dialog');
              var config = {
                width: '100%',
                closeOnSelect: !isMultiple,
                placeholder: isMultiple ? 'Buscar y seleccionar...' : 'Seleccionar...',
                allowClear: !isMultiple,
                language: {
                  noResults: function() {
                    return 'Sin resultados';
                  },
                  searching: function() {
                    return 'Buscando...';
                  }
                }
              };
              if (parentModal) {
                config.dropdownParent = $(parentModal);
              }
              $select.select2(config);
            }

            function initEnhancedSelects(scope) {
              if (!scope || !scope.querySelectorAll) return;
              scope.querySelectorAll('select.scm-select').forEach(function(selectEl) {
                if (selectEl.closest && selectEl.closest('.scm-public-pqr-form-inline')) {
                  return;
                }
                initSelect2OnSelect(selectEl);
              });
            }

            function setupSettingsObserver() {
              var settingsModal = document.getElementById('scm-pqr-settings-modal');
              if (!(settingsModal && window.MutationObserver) || settingsModal.dataset.scmObserved === '1') {
                return;
              }
              settingsModal.dataset.scmObserved = '1';
              var observer = new MutationObserver(function() {
                if (settingsModal.style.display === 'flex') {
                  initEnhancedSelects(settingsModal);
                }
              });
              observer.observe(settingsModal, {
                attributes: true,
                attributeFilter: ['style']
              });
            }

            function findPqrRowByTicketPk(ticketPk) {
              ticketPk = (ticketPk || '').trim();
              if (ticketPk === '') return null;
              return pqrPanel.querySelector('[data-pqr-row="' + ticketPk.replace(/"/g, '\\"') + '"]');
            }

            function ensureTransferModal() {
              var modal = pqrPanel.querySelector('#scm-pqr-transfer-modal');
              if (modal) return modal;

              modal = document.createElement('div');
              modal.id = 'scm-pqr-transfer-modal';
              modal.className = 'scm-pqr-transfer-overlay';
              modal.setAttribute('aria-hidden', 'true');
              modal.innerHTML =
                '<div class="scm-pqr-transfer-backdrop" data-close-pqr-transfer="1"></div>' +
                '<div class="scm-pqr-transfer-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-pqr-transfer-title">' +
                '<button type="button" class="scm-pqr-transfer-close" data-close-pqr-transfer="1" aria-label="Cerrar">&times;</button>' +
                '<h4 id="scm-pqr-transfer-title" class="scm-pqr-transfer-title">Trasladar Solicitud</h4>' +
                '<div class="scm-pqr-transfer-body"></div>' +
                '</div>';
              pqrPanel.appendChild(modal);

              modal.addEventListener('click', function(ev) {
                var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-pqr-transfer="1"]') : null;
                if (!closeTarget) return;
                closeTransferModal();
              });
              return modal;
            }

            function closeTransferModal() {
              var modal = pqrPanel.querySelector('#scm-pqr-transfer-modal');
              if (!modal) return;
              if (window.jQuery && window.jQuery.fn) {
                window.jQuery(modal).find('select.scm-select').each(function() {
                  var $select = window.jQuery(this);
                  if ($select.data('select2')) {
                    $select.select2('destroy');
                  }
                });
              }
              modal.classList.remove('open');
              modal.setAttribute('aria-hidden', 'true');
              var body = modal.querySelector('.scm-pqr-transfer-body');
              if (body) body.innerHTML = '';
            }

            function openTransferModal(triggerBtn) {
              if (!triggerBtn) return;
              var row = triggerBtn.closest('[data-pqr-row]');
              if (!row) return;
              var sourceForm = row.querySelector('form.scm-public-pqr-form-inline, form.scm-public-pqr-public-form');
              if (!sourceForm) return;

              var modal = ensureTransferModal();
              var body = modal.querySelector('.scm-pqr-transfer-body');
              if (!body) return;
              body.innerHTML = '';

              var clonedForm = sourceForm.cloneNode(true);
              clonedForm.classList.remove('scm-public-pqr-form-inline');
              clonedForm.classList.add('scm-public-pqr-form-modal');
              clonedForm.style.display = 'block';
              body.appendChild(clonedForm);

              var logicalId = (triggerBtn.getAttribute('data-ticket-logical') || '').trim();
              var title = modal.querySelector('.scm-pqr-transfer-title');
              if (title) {
                title.textContent = logicalId ? ('Trasladar Solicitud #' + logicalId) : 'Trasladar Solicitud';
              }

              modal.classList.add('open');
              modal.setAttribute('aria-hidden', 'false');
              initEnhancedSelects(clonedForm);
            }

            document.addEventListener('keydown', function(ev) {
              if (ev.key === 'Escape') {
                closeTransferModal();
              }
            });

            setupSettingsObserver();

            pqrPanel.addEventListener('click', function(e) {
              var statusTab = e.target && e.target.closest ? e.target.closest('[data-public-pqr-bucket]') : null;
              var pageLink = e.target && e.target.closest ? e.target.closest('[data-public-pqr-page]') : null;
              if (statusTab || pageLink) {
                var navigationForm = pqrPanel.querySelector('form.scm-public-pqr-filter-form');
                if (navigationForm) {
                  e.preventDefault();
                  var bucketInput = navigationForm.querySelector('input[name="public_pqr_bucket"]');
                  var pageInput = navigationForm.querySelector('input[name="public_pqr_page"]');
                  if (statusTab && bucketInput) {
                    bucketInput.value = statusTab.getAttribute('data-public-pqr-bucket') || 'abiertos';
                  }
                  if (pageInput) {
                    pageInput.value = pageLink ? (pageLink.getAttribute('data-public-pqr-page') || '1') : '1';
                  }
                  var stateSelect = navigationForm.querySelector('select[name="public_pqr_estado"]');
                  if (statusTab && stateSelect) {
                    stateSelect.value = '';
                  }
                  navigationForm.dispatchEvent(new Event('submit', {
                    bubbles: true,
                    cancelable: true
                  }));
                  return;
                }
              }
              var filterSubmitBtn = e.target && e.target.closest ? e.target.closest('.scm-public-pqr-filter-submit') : null;
              if (filterSubmitBtn) {
                var filterFormForPage = filterSubmitBtn.closest('form.scm-public-pqr-filter-form');
                var pageInputForFilter = filterFormForPage ? filterFormForPage.querySelector('input[name="public_pqr_page"]') : null;
                if (pageInputForFilter) pageInputForFilter.value = '1';
              }
              var clearFilterBtn = e.target && e.target.closest ? e.target.closest('.scm-public-pqr-filter-actions a.btn') : null;
              if (clearFilterBtn) {
                var filterFormForClear = pqrPanel.querySelector('form.scm-public-pqr-filter-form');
                if (filterFormForClear) {
                  e.preventDefault();
                  filterFormForClear.querySelectorAll('select[name="public_pqr_estado"], select[name="public_pqr_empleado"], select[name="public_pqr_categoria"]').forEach(function(selectEl) {
                    selectEl.value = '';
                    if (window.jQuery && window.jQuery.fn && window.jQuery(selectEl).data('select2')) {
                      window.jQuery(selectEl).val('').trigger('change.select2');
                    }
                  });
                  var searchInput = filterFormForClear.querySelector('input[name="public_pqr_busqueda"]');
                  if (searchInput) searchInput.value = '';
                  var clearBucket = filterFormForClear.querySelector('input[name="public_pqr_bucket"]');
                  var clearPage = filterFormForClear.querySelector('input[name="public_pqr_page"]');
                  if (clearBucket) clearBucket.value = 'abiertos';
                  if (clearPage) clearPage.value = '1';
                  filterFormForClear.dispatchEvent(new Event('submit', {
                    bubbles: true,
                    cancelable: true
                  }));
                  return;
                }
              }
              var transferBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-pqr-transfer]') : null;
              if (!transferBtn) return;
              e.preventDefault();
              openTransferModal(transferBtn);
            });

            pqrPanel.addEventListener('submit', function(e) {
              var form = e.target;
              if (!form || !form.classList) return;
              var isFilterForm = form.classList.contains('scm-public-pqr-filter-form');
              var isAssignForm = form.classList.contains('scm-public-pqr-form');
              var isCorresponsableForm = form.classList.contains('scm-public-pqr-corresponsable-form');
              var isNotifForm = form.classList.contains('scm-notif-responsable-form');
              if (!isFilterForm && !isAssignForm && !isCorresponsableForm && !isNotifForm) return;
              e.preventDefault();

              if (isFilterForm) {
                var appFilter = document.getElementById('scm-app');
                var filterRuntimeRaw = appFilter ? (appFilter.getAttribute('data-scm-runtime') || '{}') : '{}';
                var filterRuntime = {};
                try {
                  filterRuntime = JSON.parse(filterRuntimeRaw);
                } catch (ex) {}
                var filterAjaxUrl = filterRuntime.ajaxUrl || '/api.php';
                var filterNonce = filterRuntime.nonce || '';
                var filterAction = (filterRuntime.actions && filterRuntime.actions.filtrar_pqr_publico) ? filterRuntime.actions.filtrar_pqr_publico : 'scm_filtrar_pqr_publico';
                var filterBtn = form.querySelector('button[type="submit"]');
                if (filterBtn) filterBtn.disabled = true;
                pqrPanel.classList.add('is-loading');

                var filterFd = new FormData(form);
                filterFd.append('action', filterAction);
                filterFd.append('nonce', filterNonce);

                fetch(filterAjaxUrl, {
                    method: 'POST',
                    body: filterFd,
                    credentials: 'same-origin'
                  })
                  .then(function(r) {
                    return r.json();
                  })
                  .then(function(json) {
                    if (!json || !json.success) {
                      throw new Error((json && json.data && json.data.message) || 'No se pudo filtrar Solicitudes Web.');
                    }
                    var data = json.data || {};
                    if (typeof data.tab_html === 'string') {
                      pqrPanel.innerHTML = data.tab_html;
                      initEnhancedSelects(pqrPanel);
                      setupSettingsObserver();
                    }
                  })
                  .catch(function(err) {
                    if (window.Swal && typeof window.Swal.fire === 'function') {
                      window.Swal.fire({
                        icon: 'error',
                        title: 'No se pudo filtrar',
                        text: err && err.message ? err.message : 'No se pudo filtrar Solicitudes Web.',
                        confirmButtonColor: '#1f4f99'
                      });
                    }
                  })
                  .finally(function() {
                    pqrPanel.classList.remove('is-loading');
                    if (filterBtn) filterBtn.disabled = false;
                  });
                return;
              }

              var msgClass = isAssignForm ? '.scm-public-pqr-msg' : (isCorresponsableForm ? '.scm-public-pqr-corresponsable-msg' : '.scm-notif-responsable-msg');
              var msg = form.querySelector(msgClass);
              var rowMsg = null;
              if (isAssignForm) {
                var rowForMsg = form.closest('[data-pqr-row]');
                if (!rowForMsg) {
                  var ticketInputForMsg = form.querySelector('input[name="ticket_pk"]');
                  if (ticketInputForMsg) {
                    rowForMsg = findPqrRowByTicketPk(ticketInputForMsg.value || '');
                  }
                }
                if (rowForMsg) {
                  rowMsg = rowForMsg.querySelector('.scm-public-pqr-row-msg');
                }
              }
              var btn = form.querySelector('button[type="submit"]');
              if (btn) btn.disabled = true;
              if (msg) msg.textContent = 'Guardando...';
              if (rowMsg) rowMsg.textContent = 'Guardando...';

              var app = document.getElementById('scm-app');
              var runtimeRaw = app ? (app.getAttribute('data-scm-runtime') || '{}') : '{}';
              var runtime = {};
              try {
                runtime = JSON.parse(runtimeRaw);
              } catch (ex) {}

              var ajaxUrl = runtime.ajaxUrl || '/api.php';
              var nonce = runtime.nonce || '';
              var action = isAssignForm ?
                ((runtime.actions && runtime.actions.asignar_pqr_publico) ? runtime.actions.asignar_pqr_publico : 'scm_asignar_pqr_publico') :
                (isCorresponsableForm ?
                  ((runtime.actions && runtime.actions.guardar_corresponsable_pqr_publico) ? runtime.actions.guardar_corresponsable_pqr_publico : 'scm_guardar_corresponsable_pqr_publico') :
                  ((runtime.actions && runtime.actions.notif_responsable_pqr) ? runtime.actions.notif_responsable_pqr : 'scm_guardar_notif_responsable_pqr')
                );
              var fd = new FormData(form);
              fd.append('action', action);
              fd.append('nonce', nonce);

              fetch(ajaxUrl, {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error(
                      (json && json.data && json.data.message) ||
                      (
                        isAssignForm ?
                        'No se pudo actualizar el PQR.' :
                        (isCorresponsableForm ? 'No se pudo guardar el corresponsable.' : 'No se pudo guardar la notificacion.')
                      )
                    );
                  }
                  var data = json.data || {};
                  if (msg) msg.textContent = data.message || 'Actualizado';
                  if (rowMsg) rowMsg.textContent = data.message || 'Actualizado';

                  if (isAssignForm) {
                    var row = form.closest('[data-pqr-row]');
                    if (!row) {
                      var ticketInput = form.querySelector('input[name="ticket_pk"]');
                      if (ticketInput) {
                        row = findPqrRowByTicketPk(ticketInput.value || '');
                      }
                    }
                    if (row) {
                      var topic = row.querySelector('.scm-public-pqr-current-topic');
                      var dept = row.querySelector('.scm-public-pqr-current-department');
                      var emp = row.querySelector('.scm-public-pqr-current-employee');
                      var tipo = '';
                      if (typeof data.tipo_pqrs === 'string') tipo = data.tipo_pqrs;
                      else if (typeof data.tema_ayuda === 'string') tipo = data.tema_ayuda;
                      if (topic) topic.textContent = tipo || '-';
                      if (dept && typeof data.departamento === 'string') dept.textContent = data.departamento || '-';
                      if (emp) {
                        var empName = (data.empleado || '').trim();
                        var empId = (data.id_empleado || '').trim();
                        if (empName !== '') {
                          emp.textContent = empName;
                        } else if (empId !== '') {
                          emp.textContent = 'ID ' + empId;
                        }
                      }
                      var caseBtn = row.querySelector('[data-case-kind="public-pqr"]');
                      if (caseBtn) {
                        if (tipo) caseBtn.dataset.categoria = tipo;
                        if (typeof data.departamento === 'string') caseBtn.dataset.departamento = data.departamento || '-';
                        if (typeof data.empleado === 'string' && data.empleado.trim() !== '') {
                          caseBtn.dataset.empleado = data.empleado.trim();
                        } else if (typeof data.id_empleado === 'string' && data.id_empleado.trim() !== '') {
                          caseBtn.dataset.empleado = 'ID ' + data.id_empleado.trim();
                        }
                      }
                    }

                    if (form.classList.contains('scm-public-pqr-form-modal')) {
                      setTimeout(function() {
                        closeTransferModal();
                      }, 220);
                    }
                  }
                })
                .catch(function(err) {
                  var fallbackError = isAssignForm ? 'Error actualizando PQR.' : (isCorresponsableForm ? 'Error guardando corresponsable.' : 'Error guardando notificacion.');
                  if (msg) msg.textContent = err && err.message ? err.message : fallbackError;
                  if (rowMsg) rowMsg.textContent = err && err.message ? err.message : fallbackError;
                })
                .finally(function() {
                  if (btn) btn.disabled = false;
                });
            });
          }
        })();
