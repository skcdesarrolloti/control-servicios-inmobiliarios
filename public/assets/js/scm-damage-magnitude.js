(function () {
  'use strict';

  const roots = document.querySelectorAll('.scm-damage-tab');
  if (!roots.length) return;

  roots.forEach(initDamageTab);

  function initDamageTab(root) {

  const appRoot = root.closest('#scm-app.scm-wrap[data-scm-runtime]');
  let runtime = {};
  if (appRoot) {
    try {
      runtime = JSON.parse(appRoot.getAttribute('data-scm-runtime') || '{}') || {};
    } catch (err) {
      console.error('SCM damage runtime parse error:', err);
      runtime = {};
    }
  }

  const apiUrl = runtime.ajaxUrl || root.dataset.apiUrl || 'api.php';
  const action = (runtime.actions && runtime.actions.damage_magnitude) || 'damage_magnitude_tickets';
  const nonce = runtime.nonce || '';
  const revisionType = root.dataset.revisionType || 'correctiva';
  const revisionLabel = root.dataset.revisionLabel || (revisionType === 'preventiva' ? 'preventiva' : 'correctiva');

  const grid = root.querySelector('#scmDamageGrid');
  const summary = root.querySelector('#scmDamageSummary');
  const state = root.querySelector('#scmDamageState');
  const searchInput = root.querySelector('#scmDamageSearch');
  const ticketInput = root.querySelector('#scmDamageTicket');
  const contratoInput = root.querySelector('#scmDamageContrato');
  const inmuebleInput = root.querySelector('#scmDamageInmueble');
  const cotizacionSelect = root.querySelector('#scmDamageCotizacion');
  const magnitudeSelect = root.querySelector('#scmDamageMagnitude');
  const limitSelect = root.querySelector('#scmDamageLimit');
  const refreshBtn = root.querySelector('#scmDamageRefresh');
  const ticketBaseUrl = (runtime.config && runtime.config.ticket_url) || 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=';

  if (!grid || !summary || !state) return;

  let debounceTimer = null;
  let currentTickets = [];
  const modal = createModal();

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildFormData() {
    const fd = new FormData();
    fd.set('action', action);
    fd.set('nonce', nonce);
    fd.set('q', searchInput ? searchInput.value || '' : '');
    fd.set('ticket', ticketInput ? ticketInput.value || '' : '');
    fd.set('contrato', contratoInput ? contratoInput.value || '' : '');
    fd.set('inmueble', inmuebleInput ? inmuebleInput.value || '' : '');
    fd.set('cotizacion', cotizacionSelect ? cotizacionSelect.value || '' : '');
    fd.set('magnitud', magnitudeSelect ? magnitudeSelect.value || '' : '');
    fd.set('revision_type', revisionType);
    fd.set('limit', limitSelect ? limitSelect.value || '150' : '150');
    fd.set('offset', '0');
    return fd;
  }

  function setLoading() {
    state.textContent = 'Cargando tickets...';
    state.className = 'scm-damage-state';
    grid.innerHTML = '';
    summary.innerHTML = '<div class="scm-skeleton"></div><div class="scm-skeleton"></div><div class="scm-skeleton"></div><div class="scm-skeleton"></div>';
  }

  function renderSummary(data) {
    const s = data.summary || {};
    const cards = [
      ['critico', 'Críticos', s.critico || 0],
      ['alto', 'Altos', s.alto || 0],
      ['medio', 'Medios', s.medio || 0],
      ['bajo', 'Bajos', s.bajo || 0],
    ];

    summary.innerHTML = cards.map(function (card) {
      const key = card[0];
      const label = card[1];
      const value = card[2];
      return '<article class="scm-summary-card scm-' + escapeHtml(key) + '">' +
        '<span>' + escapeHtml(label) + '</span>' +
        '<strong>' + escapeHtml(value) + '</strong>' +
        '</article>';
    }).join('');
  }

  function renderMatrix(matrix) {
    if (!Array.isArray(matrix) || !matrix.length) return '';
    return '<div class="scm-ticket-matrix">' + matrix.map(function (row) {
      return '<div class="scm-ticket-matrix-row">' +
        '<span>' + escapeHtml(row.factor) + '</span>' +
        '<strong>' + escapeHtml(row.nivel) + '</strong>' +
        '<small>' + escapeHtml(row.criterio) + '</small>' +
        '</div>';
    }).join('') + '</div>';
  }

  function renderDamageItems(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<p class="scm-muted scm-compact">No se detectaron ítems detallados en la evaluación.</p>';
    }

    return '<ul class="scm-damage-items">' + items.map(function (item) {
      const fields = item.fields || {};
      const photos = Array.isArray(item.photos) ? item.photos : [];
      const areas = Array.isArray(item.areas) && item.areas.length
        ? item.areas.join(', ')
        : [fields.area_afectada_1, fields.area_afectada_2, fields.area_afectada_3, fields.area_afectada_4].filter(Boolean).join(', ');
      const photoHtml = photos.length
        ? '<div class="scm-damage-photo-grid">' + photos.map(function (photo) {
          return '<a href="' + escapeHtml(photo.url) + '" target="_blank" rel="noopener noreferrer">' +
            '<img src="' + escapeHtml(photo.url) + '" alt="Registro fotografico ' + escapeHtml(photo.id || '') + '" loading="lazy">' +
            '</a>';
        }).join('') + '</div>'
        : '';

      if (Object.keys(fields).length) {
        return '<li class="scm-damage-item-rich">' +
          '<p><b>Indice:</b> ' + escapeHtml(fields.indice || item.label || 'Dano registrado') + '</p>' +
          (areas ? '<p><b>Area afectada:</b> ' + escapeHtml(areas) + '</p>' : '') +
          (fields.a_quien_corresponde ? '<p><b>A quien corresponde este dano:</b> ' + escapeHtml(fields.a_quien_corresponde) + '</p>' : '') +
          (photoHtml ? '<p><b>Registro fotografico:</b></p>' + photoHtml : '') +
          (fields.registro_foto_dano && !photos.length ? '<span><b>Registro fotografico:</b> ' + escapeHtml(fields.registro_foto_dano) + '</span>' : '') +
          (fields.descripcion_dano ? '<p><b>Descripcion de dano:</b><br>' + escapeHtml(fields.descripcion_dano) + '</p>' : '') +
          (fields.consecuencia ? '<p><b>Consecuencia:</b><br>' + escapeHtml(fields.consecuencia) + '</p>' : '') +
          ((fields.nivel_dano || fields.tiempo_atencion) ? '<div class="scm-damage-item-duo">' +
            (fields.nivel_dano ? '<p><b>Nivel del dano:</b> ' + escapeHtml(fields.nivel_dano) + '</p>' : '') +
            (fields.tiempo_atencion ? '<p><b>Tiempo de atencion:</b> ' + escapeHtml(fields.tiempo_atencion) + '</p>' : '') +
            '</div>' : '') +
          '</li>';
      }

      return '<li><strong>' + escapeHtml(item.label) + '</strong><span>' + escapeHtml(item.value) + '</span></li>';
    }).join('') + '</ul>';
  }

  function renderTicketCard(ticket, index) {
    const m = ticket.magnitud || {};
    const ticketId = ticket.id_ticket || ticket.ticket_row_id || '';
    const ticketUrl = ticket.ticket_url || (ticketId ? ticketBaseUrl + encodeURIComponent(String(ticketId)) : '');
    const indicators = Array.isArray(m.indicators) && m.indicators.length
      ? m.indicators.map(function (i) { return '<span>' + escapeHtml(i) + '</span>'; }).join('')
      : '<span>Sin indicadores explícitos</span>';

    return '<article class="scm-damage-card scm-card-' + escapeHtml(m.key || 'sin_datos') + '">' +
      '<div class="scm-card-topline">' +
      '<span class="scm-ticket-id">#' + escapeHtml(ticketId) + '</span>' +
      '<span class="scm-badge scm-badge-' + escapeHtml(m.key || 'sin_datos') + '">' + escapeHtml(m.label || 'Sin datos') + '</span>' +
      '</div>' +
      '<h3>' + escapeHtml(ticket.asunto) + '</h3>' +
      '<div class="scm-card-meta">' +
      '<span><b>Estado:</b> ' + escapeHtml(ticket.estado) + '</span>' +
      '<span><b>Prioridad:</b> ' + escapeHtml(ticket.prioridad || 'No definida') + '</span>' +
      '<span><b>Fecha:</b> ' + escapeHtml(ticket.fecha || 'Sin fecha') + '</span>' +
      '<span><b>Revisión:</b> ' + escapeHtml(ticket.revision_id || '') + '</span>' +
      '<span><b>Contrato:</b> ' + escapeHtml(ticket.contrato || 'N/D') + '</span>' +
      '<span><b>Inmueble:</b> ' + escapeHtml(ticket.inmueble || 'N/D') + '</span>' +
      '</div>' +
      '<p class="scm-address">' + escapeHtml(ticket.direccion || ticket.inmueble || 'Sin dirección registrada') + '</p>' +
      '<div class="scm-score-box">' +
      '<div><span>Score</span><strong>' + escapeHtml(m.score || 0) + '</strong></div>' +
      '<div><span>Hallazgos</span><strong>' + escapeHtml(m.items || 0) + '</strong></div>' +
      '<div><span>Cotización</span><strong>' + escapeHtml(ticket.tiene_cotizacion || 'N/D') + '</strong></div>' +
      '</div>' +
      '<div class="scm-indicators">' + indicators + '</div>' +
      '<div class="scm-damage-actions">' +
      (ticketUrl ? '<a class="scm-damage-ticket-btn" href="' + escapeHtml(ticketUrl) + '" target="_blank" rel="noopener noreferrer">Ver ticket</a>' : '') +
      '<button class="scm-damage-ticket-btn scm-damage-detail-btn" type="button" data-damage-index="' + escapeHtml(index) + '">Ver matriz y da&ntilde;os</button>' +
      '</div>' +
      '</article>';
  }

  function createModal() {
    const el = document.createElement('div');
    el.className = 'scm-damage-modal';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<div class="scm-damage-modal-backdrop" data-close-damage-modal="1"></div>' +
      '<div class="scm-damage-modal-dialog" role="dialog" aria-modal="true">' +
      '<button type="button" class="scm-damage-modal-close" data-close-damage-modal="1" aria-label="Cerrar">x</button>' +
      '<div class="scm-damage-modal-body"></div>' +
      '</div>';
    root.appendChild(el);
    el.addEventListener('click', function (event) {
      if (event.target && event.target.getAttribute('data-close-damage-modal') === '1') {
        closeModal();
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && el.getAttribute('aria-hidden') === 'false') {
        closeModal();
      }
    });
    return el;
  }

  function openModal(ticket) {
    if (!ticket) return;
    const m = ticket.magnitud || {};
    const body = modal.querySelector('.scm-damage-modal-body');
    body.innerHTML = '<div class="scm-damage-modal-head">' +
      '<div><span class="scm-ticket-id">#' + escapeHtml(ticket.id_ticket || ticket.ticket_row_id || '') + '</span>' +
      '<h3>' + escapeHtml(ticket.asunto || 'Detalle de danos') + '</h3></div>' +
      '<span class="scm-badge scm-badge-' + escapeHtml(m.key || 'medio') + '">' + escapeHtml(m.label || '') + '</span>' +
      '</div>' +
      '<div class="scm-score-explain">' +
      '<div><span>Score</span><strong>' + escapeHtml(m.score || 0) + '</strong></div>' +
      '<div><span>Hallazgos</span><strong>' + escapeHtml(m.items || 0) + '</strong></div>' +
      '<div><span>Indicadores criticos</span><strong>' + escapeHtml(m.critical_hits || 0) + '</strong></div>' +
      '<div><span>Indicadores altos</span><strong>' + escapeHtml(m.high_hits || 0) + '</strong></div>' +
      '</div>' +
      '<div class="scm-score-guide scm-score-guide-compact">' +
      '<div><h4>Formula</h4><p>(Criticos x 6) + (Altos x 4) + (Medios x 2) + (Bajos x 1). Si la prioridad es urgente suma +3.</p></div>' +
      '<div><h4>Ajustes</h4><ul><li>3 o mas hallazgos suman +1.</li><li>6 o mas hallazgos suman +3.</li><li>Los niveles escritos en la revision pesan mas que una palabra suelta.</li></ul></div>' +
      '<div><h4>Lectura</h4><ul><li>Critico: indicador critico o score 18+.</li><li>Alto: indicador alto o score 11+.</li><li>Medio: indicador medio o score 5+.</li></ul></div>' +
      '</div>' +
      '<h4>Matriz de interpretacion</h4>' +
      renderMatrix(ticket.matriz) +
      '<h4>Danos detectados</h4>' +
      renderDamageItems(ticket.danos_detectados) +
      '<h4>Recomendacion</h4>' +
      '<p>' + escapeHtml(m.recommendation || '') + '</p>';
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.setAttribute('aria-hidden', 'true');
  }

  async function loadTickets() {
    setLoading();
    try {
      const response = await fetch(apiUrl, {
        method: 'POST',
        body: buildFormData(),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });

      const text = await response.text();
      let json = null;
      try {
        json = JSON.parse(text);
      } catch (parseError) {
        throw new Error('La API no devolvió JSON válido: ' + text.slice(0, 180));
      }

      const ok = !!(json && (json.success || json.ok));
      if (!ok) {
        const msg = (json && json.data && json.data.message) || json.message || 'Error consultando la API';
        throw new Error(msg);
      }

      const data = json.data || {};
      renderSummary(data);

      const tickets = data.tickets || [];
      currentTickets = tickets;
      if (!tickets.length) {
        state.textContent = 'No hay tickets con revisión ' + revisionLabel + ' para los filtros seleccionados.';
        grid.innerHTML = '';
        return;
      }

      state.textContent = tickets.length + ' ticket(s) encontrados.';
      grid.innerHTML = tickets.map(renderTicketCard).join('');
    } catch (error) {
      console.error(error);
      state.textContent = 'No se pudo cargar la magnitud de daños: ' + (error && error.message ? error.message : 'revisa la API.');
      state.className = 'scm-damage-state scm-error';
      grid.innerHTML = '';
      summary.innerHTML = '';
    }
  }

  function debouncedLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadTickets, 350);
  }

  if (searchInput) searchInput.addEventListener('input', debouncedLoad);
  if (ticketInput) ticketInput.addEventListener('input', debouncedLoad);
  if (contratoInput) contratoInput.addEventListener('input', debouncedLoad);
  if (inmuebleInput) inmuebleInput.addEventListener('input', debouncedLoad);
  if (cotizacionSelect) cotizacionSelect.addEventListener('change', loadTickets);
  if (magnitudeSelect) magnitudeSelect.addEventListener('change', loadTickets);
  if (limitSelect) limitSelect.addEventListener('change', loadTickets);
  if (refreshBtn) refreshBtn.addEventListener('click', loadTickets);
  grid.addEventListener('click', function (event) {
    const btn = event.target && event.target.closest ? event.target.closest('.scm-damage-detail-btn') : null;
    if (!btn) return;
    openModal(currentTickets[Number(btn.getAttribute('data-damage-index'))]);
  });

  loadTickets();
  }
})();

