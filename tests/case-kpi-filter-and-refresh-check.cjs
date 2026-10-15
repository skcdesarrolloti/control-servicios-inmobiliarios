const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const runtime = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const admin = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const slice = (text, from, to) => {
  const start = text.indexOf(from);
  const end = text.indexOf(to, start);
  assert(start >= 0 && end > start, 'Code was not found: ' + from);
  return text.slice(start, end);
};
const kpis = slice(runtime, '    // KPIs como botones', '    var form = root.querySelector("#scm-form");');
const toggleKpi = slice(runtime, '    function toggleKpiVisibility(', '    function applyRevisionKpiVisibility(');
const dueAttrs = slice(runtime, '    function dashboardDueCaseAttrMap()', '    function openDashboardRelatedTickets()');
const reopen = slice(runtime, '    function cssAttrValue(', '    function refreshCaseAfterSave(');
const findRoot = slice(admin, '  function findRootFromNode(', '  function getCaseModal(');

const kpi = (key, label) => '<div class="scm-kpi" data-kpi="' + key + '"><span class="scm-kpi-label">' + label + '</span><div class="scm-kpi-value-row"><span class="scm-kpi-value" id="scm-kpi-' + key + '">1</span></div></div>';
const select = (name, values) => '<select class="scm-select" name="' + name + '" id="' + name + '"><option value="">Todos</option>' + values.map(v => '<option value="' + v + '">' + v + '</option>').join('') + '</select>';

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.setContent('<div id="scm-app" class="scm-wrap" data-scm-runtime="{}">' +
      '<div class="scm-tab-panel active" id="scm-panel-abiertos">' +
      '<div class="scm-open-topic-panel active" data-open-topic="mant" id="scm-panel-mant"><div class="scm-kpis">' +
      kpi('total', 'Total') + kpi('con-cotz', 'Con cotiz') + kpi('sin-cotz', 'Sin cotiz') + kpi('con-prev', 'Con rev') + kpi('critico', 'Criticos') +
      '</div><form id="scm-form">' + select('scm_cotizacion', ['has', 'none']) + select('scm_revision', ['has', 'none']) + select('scm_magnitud_caso', ['critico', 'alto']) + '</form>' +
      '<div id="scm-tbody"><article class="scm-ticket-card"><button class="scm-btn-case" data-ticket-pk="77">Caso</button></article></div></div>' +
      '<div class="scm-open-topic-panel" data-open-topic="entrega"><div class="scm-kpis">' +
      '<div class="scm-kpi"><div class="scm-kpi-label">Con inventario</div><div class="scm-kpi-value" id="scm-entrega-kpi-con-inventario">3</div></div>' +
      '<div class="scm-kpi"><div class="scm-kpi-label">Daños</div><div class="scm-kpi-value" id="scm-entrega-kpi-danos-si">3</div></div>' +
      '</div><form id="scm-form-entrega">' + select('scm_entrega_atraso', ['3']) + '</form></div>' +
      '<div class="scm-open-topic-panel" data-open-topic="preventiva"><div class="scm-kpis">' +
      '<div class="scm-kpi"><div class="scm-kpi-label">Con daños</div><div class="scm-kpi-value" id="scm-preventiva-kpi-danos-si">2</div></div>' +
      '</div><form id="scm-form-preventiva">' + select('scm_prev_danos', ['has', 'none']) + '</form></div>' +
      '</div><div id="scm-case-modal" class="open"></div></div>');
    await page.addScriptTag({content: `
      var root = document.getElementById('scm-app');
      window.changes = [];
      root.addEventListener('change', function (e) { window.changes.push(e.target.name + '=' + e.target.value); });
      ${toggleKpi}
      ${kpis}
      ${dueAttrs}
      window.dashboardDueCaseAttrMap = dashboardDueCaseAttrMap;
      window.dashboardApplyDueCaseData = dashboardApplyDueCaseData;
      window.opened = []; window.fetched = []; window.closedCount = 0; window.fetchFails = false;
      window.scmOpenCase = function (btn) { window.opened.push(btn.dataset.ticketPk); };
      function closeCaseModal() { window.closedCount++; }
      function showToast() {}
      function openPanelCaseById(id, propagate, keepLayer) {
        window.fetched.push(id + ':' + keepLayer);
        return window.fetchFails ? Promise.reject(new Error('sin permiso')) : Promise.resolve(true);
      }
      ${reopen}
      window.reopenCaseFromUpdatedCard = reopenCaseFromUpdatedCard;
    `});
    await page.addScriptTag({content: `(function(){ ${findRoot} window.findRootFromNode = findRootFromNode; })();`});

    const state = () => page.evaluate(() => ({
      cot: document.getElementById('scm_cotizacion').value,
      rev: document.getElementById('scm_revision').value,
      mag: document.getElementById('scm_magnitud_caso').value,
      active: Array.from(document.querySelectorAll('#scm-panel-mant .scm-kpi-active')).map(k => k.dataset.kpi),
    }));

    assert.deepEqual((await state()).active, ['total'], 'Total starts active when no KPI filter is applied');
    await page.click('[data-kpi="con-cotz"]');
    assert.deepEqual(await state(), {cot: 'has', rev: '', mag: '', active: ['con-cotz']});
    await page.click('[data-kpi="con-cotz"]');
    assert.deepEqual(await state(), {cot: '', rev: '', mag: '', active: ['total']}, 'Second click clears the KPI filter');
    await page.click('[data-kpi="critico"]');
    await page.focus('[data-kpi="con-prev"]');
    await page.keyboard.press('Enter');
    assert.deepEqual(await state(), {cot: '', rev: 'has', mag: 'critico', active: ['con-prev', 'critico']});
    await page.click('[data-kpi="total"]');
    assert.deepEqual(await state(), {cot: '', rev: '', mag: '', active: ['total']}, 'Total clears every KPI filter');
    assert.ok((await page.evaluate(() => window.changes)).includes('scm_cotizacion=has'), 'KPI dispatches the filter change that triggers the fetch');

    // A filter chosen in the form highlights its KPI too.
    await page.selectOption('#scm_cotizacion', 'none');
    assert.deepEqual((await state()).active, ['sin-cotz']);

    const generic = await page.evaluate(() => ({
      inventory: document.querySelector('#scm-entrega-kpi-con-inventario').closest('.scm-kpi').classList.contains('scm-kpi-clickable'),
      entregaDamage: document.querySelector('#scm-entrega-kpi-danos-si').closest('.scm-kpi').classList.contains('scm-kpi-clickable'),
    }));
    assert.deepEqual(generic, {inventory: false, entregaDamage: false}, 'KPIs without a matching filter stay passive');
    await page.evaluate(() => document.querySelector('#scm-preventiva-kpi-danos-si').closest('.scm-kpi').click());
    assert.equal(await page.evaluate(() => document.getElementById('scm_prev_danos').value), 'has', 'Generic tab KPI filters its own form');

    // Hiding a KPI hides the whole card, not only its value row.
    await page.evaluate(() => toggleKpiVisibility('scm-kpi-sin-cotz', false));
    assert.equal(await page.evaluate(() => document.querySelector('[data-kpi="sin-cotz"]').style.display), 'none');

    // Case popup refresh: list cards reopen from the refreshed list, proxies reload from the server.
    const refresh = await page.evaluate(async () => {
      var modal = document.getElementById('scm-case-modal');
      var listCard = document.createElement('article'); listCard.className = 'scm-ticket-card';
      var listBtn = document.createElement('button'); listBtn.dataset.ticketPk = '77'; listCard.appendChild(listBtn);
      modal._scmCurrentCaseButton = listBtn; // replaced (detached) after the list refresh
      reopenCaseFromUpdatedCard('77');
      var proxy = document.createElement('article'); proxy.className = 'scm-ticket-card'; proxy.hidden = true;
      var proxyBtn = document.createElement('button'); proxy.appendChild(proxyBtn);
      modal._scmCurrentCaseButton = proxyBtn;
      reopenCaseFromUpdatedCard('77');
      modal._scmCurrentCaseButton = listBtn;
      reopenCaseFromUpdatedCard('88');
      window.fetchFails = true;
      reopenCaseFromUpdatedCard('99');
      await new Promise(r => setTimeout(r, 20));
      return {opened: window.opened, fetched: window.fetched, closed: window.closedCount};
    });
    assert.deepEqual(refresh, {opened: ['77'], fetched: ['77:true', '88:true', '99:true'], closed: 1});

    const due = await page.evaluate(() => {
      var card = document.createElement('article'); card.className = 'scm-ticket-card';
      var btn = document.createElement('button'); card.appendChild(btn);
      dashboardApplyDueCaseData(btn, {ticket_pk: '5', contract_pk: '9', etapa: 'Ejecución', etapa_tiempo: '2d', medio: 'Panel', total: '3d'});
      return ['data-ticket-pk', 'data-contract-id', 'data-etapa', 'data-etapa-tiempo', 'data-medio', 'data-total'].map(a => btn.getAttribute(a));
    });
    assert.deepEqual(due, ['5', '9', 'Ejecución', '2d', 'Panel', '3d'], 'Server-loaded cases keep every card attribute');

    const detachedRoot = await page.evaluate(() => {
      var btn = document.createElement('button');
      var app = document.getElementById('scm-app');
      return findRootFromNode(btn) === app && findRootFromNode(document.body) === null;
    });
    assert.equal(detachedRoot, true, 'Detached case buttons still resolve the panel root');

    assert.deepEqual(errors, []);
    console.log('PASS: KPI filters (toggle, total, keyboard, generic tabs, passive KPIs, card hiding), case popup refresh from list/proxy/server, due-case attributes, detached root.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exit(1);
});
