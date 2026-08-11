(function() {
            var app = document.getElementById('public-ticket-app');
            if (!app) return;

            var runtime = {};
            try {
              runtime = JSON.parse(app.getAttribute('data-runtime') || '{}');
            } catch (e) {
              runtime = {};
            }

            var lookupForm = document.getElementById('lookup-form');
            var createForm = document.getElementById('create-form');
            var lookupInput = document.getElementById('lookup_value');
            var lookupStatus = document.getElementById('lookup-status');
            var createStatus = document.getElementById('create-status');
            var head = document.getElementById('lookup-head');
            var body = document.getElementById('lookup-body');
            var selectionHint = document.getElementById('selection-hint');
            var step1Card = document.getElementById('step-1');
            var step2Card = document.getElementById('step-2');
            var step3Card = document.getElementById('step-3');
            var requestStatus = document.getElementById('request-status');
            var requestResults = document.getElementById('request-results');
            var backStep1Btn = document.getElementById('back-step-1');
            var backStep2Btn = document.getElementById('back-step-2');
            var defaultSelectionHint = selectionHint ? selectionHint.textContent : '';

            var selectedId = '';
            var selectedRow = null;
            var rows = [];
            var lookupMessage = '';

            function setStatus(el, kind, message) {
              if (!el) return;
              el.className = 'status';
              if (!message) {
                el.style.display = 'none';
                el.innerHTML = '';
                return;
              }
              el.style.display = 'block';
              el.classList.add(kind === 'ok' ? 'ok' : 'error');
              el.innerHTML = message;
            }

            function esc(value) {
              return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            }

            function post(action, data) {
              var fd = new FormData();
              fd.append('action', action);
              fd.append('actor', runtime.actor || '');
              fd.append('nonce', runtime.nonce || '');
              Object.keys(data || {}).forEach(function(key) {
                fd.append(key, data[key] == null ? '' : String(data[key]));
              });
              return fetch(runtime.apiUrl || '', {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                });
            }

            function setStepVisible(stepEl, visible) {
              if (!stepEl) return;
              stepEl.classList.toggle('is-hidden', !visible);
            }

            function showStepFlow(step2Visible, step3Visible) {
              var activeStep = step3Visible ? 3 : (step2Visible ? 2 : 1);
              setStepVisible(step1Card, activeStep === 1);
              setStepVisible(step2Card, activeStep === 2);
              setStepVisible(step3Card, activeStep === 3);
            }

            function renderLookupPlaceholder(message) {
              if (!head || !body) return;
              head.innerHTML = '';
              body.innerHTML = '<tr><td style="padding:16px;color:#5a6f86;">' + esc(message) + '</td></tr>';
            }

            function renderRequestPlaceholder(message) {
              if (!requestResults) return;
              requestResults.innerHTML = '<div class="hint">' + esc(message) + '</div>';
            }

            function renderRequestResults(items) {
              if (!requestResults) return;
              if (!Array.isArray(items) || !items.length) {
                renderRequestPlaceholder('No encontramos solicitudes registradas para esta seleccion.');
                return;
              }

              var html = '';
              items.forEach(function(item) {
                html += '<article class="request-item">';
                html += '<div class="request-item-head">';
                html += '<strong>Solicitud #' + esc(item.ticket_id || '-') + '</strong>';
                html += '<span class="request-badge">' + esc(item.estado || 'Sin estado') + '</span>';
                html += '</div>';
                html += '<div class="request-meta">';
                html += '<div><strong>Asunto:</strong> ' + esc(item.asunto || '-') + '</div>';
                html += '<div><strong>Tema:</strong> ' + esc(item.tema_ayuda || '-') + '</div>';
                html += '<div><strong>Fecha:</strong> ' + esc(item.fecha || '-') + '</div>';
                html += '<div><strong>Departamento:</strong> ' + esc(item.departamento || '-') + '</div>';
                if (item.contrato) {
                  html += '<div><strong>Contrato:</strong> ' + esc(item.contrato) + '</div>';
                }
                if (item.inmueble) {
                  html += '<div><strong>Inmueble:</strong> ' + esc(item.inmueble) + '</div>';
                }
                if (item.direccion) {
                  html += '<div><strong>Direccion:</strong> ' + esc(item.direccion) + '</div>';
                }
                html += '</div>';
                if (item.ticket_url) {
                  html += '<div class="request-actions"><a class="btn btn-secondary btn-link" href="' + esc(item.ticket_url) + '" target="_blank" rel="noopener noreferrer">Ver solicitud</a></div>';
                }
                html += '</article>';
              });

              requestResults.innerHTML = html;
            }

            function fetchRequestResults() {
              if (!selectedId || !selectedRow) {
                renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
                setStatus(requestStatus, 'ok', '');
                return;
              }

              renderRequestPlaceholder('Consultando solicitudes...');
              setStatus(requestStatus, 'ok', 'Consultando solicitudes...');
              post('public_ticket_requests', {
                  selected_id: selectedId,
                  lookup_value: (lookupInput.value || '').trim()
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudieron consultar las solicitudes.');
                  }

                  var items = Array.isArray(json.data.rows) ? json.data.rows : [];
                  renderRequestResults(items);
                  if (!items.length) {
                    setStatus(requestStatus, 'ok', 'No tienes solicitudes registradas para esta seleccion.');
                  } else {
                    setStatus(requestStatus, 'ok', 'Se encontraron ' + items.length + ' solicitud(es) registradas.');
                  }
                })
                .catch(function(err) {
                  renderRequestPlaceholder('No fue posible consultar tus solicitudes en este momento.');
                  setStatus(requestStatus, 'error', err.message || 'Error consultando solicitudes.');
                });
            }

            function renderLookupTable() {
              if (!head || !body) return;
              var isClient = runtime.actor === 'cliente';

              if (!rows.length) {
                renderLookupPlaceholder(lookupMessage || 'No se encontraron resultados para la consulta.');
                selectedId = '';
                selectedRow = null;
                showStepFlow(false, false);
                if (selectionHint) {
                  selectionHint.textContent = defaultSelectionHint;
                }
                return;
              }

              showStepFlow(true, false);

              if (isClient) {
                head.innerHTML = '<tr><th></th><th>Cliente</th><th>ID cliente</th><th>Correo</th><th>Celular</th><th>Tipo</th></tr>';
              } else {
                head.innerHTML = '<tr><th></th><th>Contrato</th><th>Inmueble</th><th>Direccion</th><th>Propietario</th><th>Arrendatario</th><th>Responsable</th></tr>';
              }

              var html = '';
              rows.forEach(function(row) {
                var checked = String(selectedId) === String(row.id) ? ' checked' : '';
                if (isClient) {
                  html += '<tr data-row-id="' + esc(row.id) + '">';
                  html += '<td><input type="radio" name="selected_row" value="' + esc(row.id) + '"' + checked + '></td>';
                  html += '<td>' + esc(row.nombre || row.display || '') + '</td>';
                  html += '<td>' + esc(row.cliente_id || '') + '</td>';
                  html += '<td>' + esc(row.correo || '') + '</td>';
                  html += '<td>' + esc(row.celular || '') + '</td>';
                  html += '<td>' + esc(row.tipo_cliente || '') + '</td>';
                  html += '</tr>';
                } else {
                  html += '<tr data-row-id="' + esc(row.id) + '">';
                  html += '<td><input type="radio" name="selected_row" value="' + esc(row.id) + '"' + checked + '></td>';
                  html += '<td>' + esc(row.contrato || row.id || '') + '</td>';
                  html += '<td>' + esc(row.inmueble || '') + '</td>';
                  html += '<td>' + esc(row.direccion || '') + '</td>';
                  html += '<td>' + esc(row.propietario || '') + '</td>';
                  html += '<td>' + esc(row.arrendatario || '') + '</td>';
                  html += '<td>' + esc(row.responsable_nombre || row.responsable_id || 'Sin asignar') + '</td>';
                  html += '</tr>';
                }
              });
              body.innerHTML = html;

              body.querySelectorAll('input[name="selected_row"]').forEach(function(input) {
                input.addEventListener('change', function() {
                  selectRow(this.value);
                });
              });

              body.querySelectorAll('tr[data-row-id]').forEach(function(tr) {
                tr.addEventListener('click', function(e) {
                  if (e.target && e.target.tagName === 'INPUT') return;
                  var rowId = this.getAttribute('data-row-id') || '';
                  var radio = this.querySelector('input[name="selected_row"]');
                  if (radio) {
                    radio.checked = true;
                  }
                  selectRow(rowId);
                });
              });
            }

            function selectRow(id) {
              selectedId = String(id || '');
              selectedRow = rows.find(function(item) {
                return String(item.id) === selectedId;
              }) || null;

              body.querySelectorAll('tr[data-row-id]').forEach(function(tr) {
                tr.classList.toggle('active', String(tr.getAttribute('data-row-id') || '') === selectedId);
              });

              if (!selectedRow) {
                if (selectionHint) {
                  selectionHint.textContent = defaultSelectionHint;
                }
                renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
                setStatus(requestStatus, 'ok', '');
                showStepFlow(true, false);
                return;
              }

              var name = selectedRow.contact_name || selectedRow.nombre || '';
              var email = selectedRow.contact_email || selectedRow.correo || '';
              var phone = selectedRow.contact_phone || selectedRow.celular || '';
              var indicativo = selectedRow.contact_indicativo || selectedRow.indicativo || '+57';
              var responsable = selectedRow.responsable_nombre || selectedRow.responsable_id || 'Sin asignar';

              document.getElementById('solicitante').value = name;
              document.getElementById('correo_solicitante').value = email;
              document.getElementById('celular_solicitante').value = phone;
              document.getElementById('indicativo').value = indicativo;

              if (selectionHint) {
                selectionHint.textContent = 'Seleccionado: ' + (selectedRow.display || selectedRow.contrato || selectedRow.nombre || selectedRow.id) + ' | Responsable: ' + responsable;
              }
              showStepFlow(true, true);
              fetchRequestResults();
            }

            function resetLookup() {
              rows = [];
              lookupMessage = '';
              selectedRow = null;
              selectedId = '';
              lookupInput.value = '';
              renderLookupPlaceholder('Realiza primero la consulta para ver resultados.');
              setStatus(lookupStatus, 'ok', '');
              setStatus(createStatus, 'ok', '');
              if (selectionHint) {
                selectionHint.textContent = defaultSelectionHint;
              }
              renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
              setStatus(requestStatus, 'ok', '');
              showStepFlow(false, false);
              resetCreateForm();
            }

            function resetCreateForm() {
              createForm.reset();
              document.getElementById('indicativo').value = '+57';
              setStatus(createStatus, 'ok', '');
            }

            lookupForm.addEventListener('submit', function(e) {
              e.preventDefault();
              var lookupValue = (lookupInput.value || '').trim();
              if (!lookupValue) {
                setStatus(lookupStatus, 'error', 'Debes ingresar un valor para consultar.');
                return;
              }

              rows = [];
              selectedRow = null;
              selectedId = '';
              showStepFlow(false, false);
              renderLookupPlaceholder('Consultando...');
              setStatus(lookupStatus, 'ok', 'Consultando...');
              post('public_ticket_lookup', {
                  lookup_value: lookupValue
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo consultar.');
                  }

                  rows = Array.isArray(json.data.rows) ? json.data.rows : [];
                  lookupMessage = (json.data && json.data.message) ? String(json.data.message) : '';
                  selectedId = '';
                  renderLookupTable();

                  if (!rows.length) {
                    setStatus(lookupStatus, 'error', lookupMessage || 'No se encontraron resultados.');
                  } else {
                    setStatus(lookupStatus, 'ok', 'Se encontraron ' + rows.length + ' resultado(s).');
                    if (selectionHint) {
                      selectionHint.textContent = defaultSelectionHint;
                    }
                  }
                })
                .catch(function(err) {
                  setStatus(lookupStatus, 'error', err.message || 'Error consultando datos.');
                  renderLookupPlaceholder('No fue posible completar la consulta.');
                  showStepFlow(false, false);
                });
            });

            createForm.addEventListener('submit', function(e) {
              e.preventDefault();

              if (!selectedRow || !selectedId) {
                setStatus(createStatus, 'error', 'Debes seleccionar un registro antes de crear el PQR.');
                return;
              }

              var asunto = (document.getElementById('asunto').value || '').trim();
              var descripcion = (document.getElementById('descripcion').value || '').trim();
              var correo = (document.getElementById('correo_solicitante').value || '').trim();
              var celular = (document.getElementById('celular_solicitante').value || '').trim();
              var solicitante = (document.getElementById('solicitante').value || '').trim();
              if (!asunto || !descripcion || !correo || !celular || !solicitante) {
                setStatus(createStatus, 'error', 'No pudimos radicar tu solicitud. Por favor revisa los campos obligatorios e intenta nuevamente.');
                return;
              }

              var payload = {
                selected_id: selectedId,
                actor_id: selectedRow.actor_id || '',
                lookup_value: (lookupInput.value || '').trim(),
                solicitante: solicitante,
                correo_solicitante: correo,
                celular_solicitante: celular,
                indicativo: (document.getElementById('indicativo').value || '').trim(),
                asunto: asunto,
                tema_ayuda: document.getElementById('tema_ayuda').value || '',
                descripcion: descripcion
              };

              setStatus(createStatus, 'ok', 'Creando PQR...');
              post('public_ticket_create', payload)
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo crear el PQR.');
                  }

                  var data = json.data || {};
                  var redirectUrl = new URL(runtime.portalUrl || window.location.pathname || '/', window.location.origin);
                  redirectUrl.searchParams.set('radicado', '1');
                  if (data.ticket_id) {
                    redirectUrl.searchParams.set('ref', String(data.ticket_id).trim());
                  }
                  window.location.href = redirectUrl.toString();
                })
                .catch(function(err) {
                  setStatus(createStatus, 'error', 'No pudimos radicar tu solicitud. Por favor revisa los campos obligatorios e intenta nuevamente.');
                });
            });

            document.getElementById('lookup-clear').addEventListener('click', resetLookup);
            if (backStep1Btn) {
              backStep1Btn.addEventListener('click', function() {
                showStepFlow(false, false);
                setStatus(createStatus, 'ok', '');
              });
            }
            if (backStep2Btn) {
              backStep2Btn.addEventListener('click', function() {
                showStepFlow(true, false);
                setStatus(createStatus, 'ok', '');
              });
            }
            document.getElementById('create-clear').addEventListener('click', function() {
              if (!window.confirm('¿Estás seguro de que deseas borrar los datos del formulario?')) return;
              resetCreateForm();
            });

            // ── Dynamic topic helper ──────────────────────────────────────
            var TOPIC_DATA = {
              'Avaluo': {
                title: 'Avaluo',
                help: 'Indica el inmueble, la finalidad del avaluo y si cuentas con documentos como certificado de tradicion, impuesto predial o escritura.',
                asunto: 'Solicitud de avaluo',
                placeholder: 'Indica el inmueble, finalidad del avaluo, fecha requerida y documentos disponibles.'
              },
              'Actualizacion': {
                title: 'Actualizacion',
                help: 'Usa esta opcion para actualizar precio, disponibilidad, documentos, fotos, datos del propietario o condiciones comerciales del inmueble.',
                asunto: 'Actualizacion de informacion del inmueble',
                placeholder: 'Describe que informacion debe actualizarse y sobre que inmueble.'
              },
              'Captacion': {
                title: 'Captacion',
                help: 'Registra un inmueble nuevo para ofrecerlo en arriendo, venta o administracion.',
                asunto: 'Captacion de inmueble',
                placeholder: 'Indica direccion, tipo de inmueble, valor esperado, disponibilidad y datos relevantes.'
              },
              'Recaptacion': {
                title: 'Recaptacion',
                help: 'Reactivar un inmueble que ya estuvo vinculado anteriormente con la empresa.',
                asunto: 'Recaptacion de inmueble',
                placeholder: 'Indica cual inmueble deseas reactivar y si cambiaron precio, condiciones o disponibilidad.'
              },
              'Arriendo': {
                title: 'Arriendo',
                help: 'Solicitudes relacionadas con inmuebles en arriendo, canones, disponibilidad o proceso de arrendamiento.',
                asunto: 'Solicitud relacionada con arriendo',
                placeholder: 'Describe tu solicitud sobre el proceso de arriendo.'
              },
              'Venta': {
                title: 'Venta',
                help: 'Solicitudes relacionadas con venta de inmuebles, precio, promocion, negociacion o proceso comercial.',
                asunto: 'Solicitud relacionada con venta',
                placeholder: 'Describe tu solicitud sobre el proceso de venta.'
              },
              'Arriendo o venta': {
                title: 'Arriendo o venta',
                help: 'Para inmuebles que puedan ofrecerse tanto en arriendo como en venta.',
                asunto: 'Solicitud de arriendo o venta',
                placeholder: 'Indica si deseas publicar, cambiar condiciones o recibir asesoria sobre arriendo y venta.'
              },
              'Entrega de inmuebles': {
                title: 'Entrega de inmuebles',
                help: 'Coordinar o reportar la entrega de un inmueble al finalizar contrato, negociacion u ocupacion.',
                asunto: 'Entrega de inmuebles',
                placeholder: 'Indica fecha estimada de entrega, inmueble relacionado y observaciones.'
              },
              'Revision preventiva': {
                title: 'Revision preventiva',
                help: 'Solicita una revision anticipada del estado fisico, juridico o documental del inmueble.',
                asunto: 'Revision preventiva del inmueble',
                placeholder: 'Describe que deseas revisar y el motivo de la revision.'
              },
              'Recibo de inmuebles': {
                title: 'Recibo de inmuebles',
                help: 'Coordinar el recibo formal de un inmueble por parte de la empresa o propietario.',
                asunto: 'Recibo de inmuebles',
                placeholder: 'Indica fecha, inmueble, estado general y observaciones para el recibo.'
              },
              'Reparaciones necesarias': {
                title: 'Reparaciones necesarias',
                help: 'Reporta daños o reparaciones indispensables para conservar el inmueble o permitir su uso adecuado.',
                asunto: 'Reporte de reparacion necesaria',
                placeholder: 'Describe el daño, ubicacion dentro del inmueble, fecha en que se detecto y adjunta fotos si es posible.'
              },
              'Reparaciones locativas': {
                title: 'Reparaciones locativas',
                help: 'Reparaciones menores asociadas al uso ordinario, mantenimiento o desgaste normal.',
                asunto: 'Reporte de reparacion locativa',
                placeholder: 'Describe la reparacion menor requerida y el lugar exacto dentro del inmueble.'
              },
              'Mejoras utiles': {
                title: 'Mejoras utiles',
                help: 'Mejoras que aumentan la funcionalidad, valor o aprovechamiento del inmueble.',
                asunto: 'Solicitud de mejora util',
                placeholder: 'Describe la mejora propuesta, su finalidad y si requiere autorizacion previa.'
              },
              'Reparaciones voluntarias': {
                title: 'Reparaciones voluntarias',
                help: 'Intervenciones no indispensables, normalmente esteticas o de conveniencia.',
                asunto: 'Solicitud de reparacion voluntaria',
                placeholder: 'Describe la reparacion voluntaria, motivo y quien asumiria el costo.'
              },
              'Contable y tributaria': {
                title: 'Contable y tributaria',
                help: 'Consulta pagos, estados de cuenta, impuestos, retenciones, facturacion o informacion contable.',
                asunto: 'Consulta contable o tributaria',
                placeholder: 'Indica el periodo, contrato, inmueble o documento sobre el cual necesitas informacion.'
              },
              'Certificaciones tributarias': {
                title: 'Certificaciones tributarias',
                help: 'Solicita certificados de retencion, pagos, ingresos u otros soportes tributarios.',
                asunto: 'Solicitud de certificacion tributaria',
                placeholder: 'Indica el certificado requerido, año gravable y datos del propietario.'
              },
              'Procesos juridicos': {
                title: 'Procesos juridicos',
                help: 'Cobros, incumplimientos, restituciones, reclamaciones o procesos legales.',
                asunto: 'Solicitud relacionada con proceso juridico',
                placeholder: 'Describe el caso, contrato relacionado, fechas importantes y documentos de soporte.'
              },
              'Solicitud contractual': {
                title: 'Solicitud contractual',
                help: 'Contratos, otrosies, prorrogas, terminaciones, cesiones o modificaciones contractuales.',
                asunto: 'Solicitud contractual',
                placeholder: 'Indica que documento o modificacion necesitas y el contrato relacionado.'
              },
              'Solicitud de servicios publicos': {
                title: 'Solicitud de servicios publicos',
                help: 'Gestiones sobre energia, agua, gas, internet u otros servicios publicos.',
                asunto: 'Solicitud sobre servicios publicos',
                placeholder: 'Indica el servicio, numero de cuenta o factura, inmueble y situacion presentada.'
              },
              'Reparaciones antes de la entrega': {
                title: 'Reparaciones antes de la entrega',
                help: 'Reparaciones que deben realizarse antes de entregar el inmueble.',
                asunto: 'Reparaciones antes de la entrega',
                placeholder: 'Describe las reparaciones pendientes antes de la entrega del inmueble.'
              },
              'Reparaciones antes del recibo': {
                title: 'Reparaciones antes del recibo',
                help: 'Reparaciones o pendientes que deben revisarse antes de recibir formalmente el inmueble.',
                asunto: 'Reparaciones antes del recibo',
                placeholder: 'Describe los pendientes que deben resolverse antes del recibo formal.'
              }
            };

            var tipoSelect = document.getElementById('tema_ayuda');
            var helpBox = document.getElementById('tema-help-box');
            var asuntoInput = document.getElementById('asunto');
            var descTextarea = document.getElementById('descripcion');

            function applyTopicData(val) {
              var td = val ? TOPIC_DATA[val] : null;
              if (!td) {
                if (helpBox) {
                  helpBox.className = 'tema-help-box is-placeholder';
                  helpBox.innerHTML = 'Selecciona un tema para ver una guía sobre cómo diligenciar tu solicitud.';
                }
                return;
              }
              if (helpBox) {
                helpBox.className = 'tema-help-box';
                helpBox.innerHTML = '<strong>' + td.title + '</strong>' + td.help;
              }
              if (asuntoInput && asuntoInput.value.trim() === '') {
                asuntoInput.value = td.asunto;
              }
              if (descTextarea && !descTextarea.value.trim()) {
                descTextarea.placeholder = td.placeholder;
              }
            }

            if (tipoSelect) {
              tipoSelect.addEventListener('change', function() {
                applyTopicData(this.value);
              });
              if (tipoSelect.value) {
                applyTopicData(tipoSelect.value);
              } else if (helpBox) {
                helpBox.className = 'tema-help-box is-placeholder';
              }
            }

            showStepFlow(false, false);
            renderLookupPlaceholder('Realiza primero la consulta para ver resultados.');
          })();
