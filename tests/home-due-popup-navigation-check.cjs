const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const runtime = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const footer = fs.readFileSync(path.join(__dirname, '../includes/footer.php'), 'utf8');
const slice = (text, from, to) => {
  const start = text.indexOf(from);
  const end = text.indexOf(to, start);
  assert(start >= 0 && end > start, 'Navigation code was not found');
  return text.slice(start, end);
};
const guard = slice(runtime, '    function isDashboardHomePanelActive()', '    function markStandaloneFunctionReady()');
const popup = slice(runtime, '    function maybeShowDashboardDuePopup(', '    root.addEventListener("scm:case-modal-closed"');
const calendarTabs = slice(runtime, '    root.querySelectorAll(".scm-calendar-section-tab").forEach', '    root.querySelectorAll(".scm-open-topic-tab").forEach');
const mainTabs = slice(runtime, '    root.querySelectorAll(".scm-tab[data-tab]").forEach', '    root.addEventListener("click", function (event) {');
const navbar = slice(footer, "      const navPills = document.querySelectorAll('.nav-tab-pill');", '      // 3b.');
const sections = ['mine', 'team', 'due', 'property-history', 'contracts-ending', 'contract-termination', 'contract-non-renewal'];
const panelFor = section => section.startsWith('contract') ? 'scm-panel-actividades-contractuales' : 'scm-panel-inicio';
const sectionHtml = section => '<button class="scm-calendar-section-tab" data-calendar-section-target="scm-home-calendar-section-' + section + '">' + section + '</button><section class="scm-calendar-section-panel" id="scm-home-calendar-section-' + section + '"></section>';

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage();
    await page.route('http://scm.test/**', route => route.fulfill({contentType: 'text/html', body: '<main></main>'}));
    await page.goto('http://scm.test/index.php?tab=ini&keep=value#context');
    await page.setContent('<nav><button class="nav-tab-pill" id="summary" data-panel-target="scm-panel-inicio" data-tab-key="inicio" data-subtab-target="mine">Inicio</button>' + sections.map(section => '<button class="nav-tab-pill" id="nav-' + section + '" data-panel-target="' + panelFor(section) + '" data-tab-key="' + section.replace(/-/g, '_') + '" data-subtab-target="' + section + '">' + section + '</button>').join('') + '</nav><div id="scm-app"><div class="scm-main-tabs"><button class="scm-tab" data-tab="scm-panel-inicio"></button><button class="scm-tab" data-tab="scm-panel-actividades-contractuales"></button></div>' + ['scm-panel-inicio', 'scm-panel-actividades-contractuales'].map(panel => '<section id="' + panel + '" class="scm-tab-panel ' + (panel === 'scm-panel-inicio' ? 'active' : '') + '"><div data-calendar-sections>' + sections.filter(section => panelFor(section) === panel).map(sectionHtml).join('') + '</div></section>').join('') + '</div>');
    await page.addScriptTag({content: `
      var root = document.getElementById('scm-app');
      var funcionarioBridgeMode = false, duePopupConfig = {enabled:true}, dashboardDuePopupShown = {};
      var ajaxUrl = '/api.php', actionAdminDueCalendar = 'due', popupCount = 0;
      function loadDashboardDuePopupRows() { return window.pendingDue || Promise.resolve({rows:[{}]}); }
      function dashboardDueEntryPopupHtml() { return ''; }
      function initCalendarPanel() {}
      function loadContractTerminationRequests() {}
      function loadContractNonRenewalRequests() {}
      function loadContractsEnding() {}
      function loadActiveLazyPanelWithFeedback() {}
      function refreshDashboardDueNavBadge() {}
      window.Swal = {fire:function(){popupCount++;return Promise.resolve();}};
      ${guard}
      ${popup}
      ${calendarTabs}
      ${navbar}
      root.querySelectorAll('.scm-main-tabs .scm-tab').forEach(function(button) {
        button.addEventListener('click', function() {
          root.querySelectorAll('.scm-tab-panel').forEach(function(panel) {panel.classList.toggle('active', panel.id === button.dataset.tab);});
        });
      });
      ${mainTabs}
      root.addEventListener('scm:home-summary-selected', function(){maybeShowDashboardDuePopup('home-manual');});
    `});
    for (const section of sections) {
      await page.locator('#nav-' + section).click();
      await page.waitForFunction(key => new URL(location.href).searchParams.get('tab') === key.replace(/-/g, '_') && document.getElementById('scm-home-calendar-section-' + key).classList.contains('active'), section);
      const state = await page.evaluate(() => ({tab:new URL(location.href).searchParams.get('tab'), keep:new URL(location.href).searchParams.get('keep'), hash:location.hash, count:popupCount, show:shouldAutoShowDashboardDuePopup('case-return')}));
      assert.deepEqual(state, {tab:section.replace(/-/g, '_'), keep:'value', hash:'#context', count:0, show:false});
    }
    await page.locator('#summary').click();
    await page.waitForFunction(() => !new URL(location.href).searchParams.has('subtab') && document.getElementById('scm-home-calendar-section-mine').classList.contains('active'));
    assert.equal(await page.evaluate(() => shouldAutoShowDashboardDuePopup('login')), true);
    await page.waitForFunction(() => popupCount > 0);
    await page.locator('[data-calendar-section-target="scm-home-calendar-section-team"]').click();
    assert.equal(new URL(page.url()).searchParams.get('tab'), 'team');
    assert.equal(new URL(page.url()).searchParams.has('subtab'), false);
    assert.equal(await page.evaluate(() => shouldAutoShowDashboardDuePopup('home-manual')), false);

    // A slow request must not open its popup after navigation has changed.
    await page.evaluate(() => {
      history.replaceState({}, '', '?tab=ini');
      dashboardDuePopupShown = {};
      window.pendingDue = new Promise(resolve => {window.resolveDue = resolve;});
      window.popupRequest = maybeShowDashboardDuePopup('login');
    });
    await page.locator('[data-calendar-section-target="scm-home-calendar-section-due"]').click();
    const before = await page.evaluate(() => popupCount);
    await page.evaluate(async () => {resolveDue({rows:[{}]});await popupRequest;});
    assert.equal(await page.evaluate(() => popupCount), before);
    for (const query of ['?tab=ini&subtab=mine', '?tab=ini&scm_subtab=team', '?tab=inicio', '?scm_tab=ini', '?tab=abiertos', '?tab=vencimientos', '?']) {
      assert.equal(await page.evaluate(query => {history.replaceState({}, '', query);return shouldAutoShowDashboardDuePopup('home-tab');}, query), false, query);
    }
    // Native contract buttons also write their own tab route.
    await page.locator('.scm-main-tabs [data-tab="scm-panel-actividades-contractuales"]').click();
    for (const section of sections.filter(section => section.startsWith('contract'))) {
      await page.locator('[data-calendar-section-target="scm-home-calendar-section-' + section + '"]').click();
      assert.equal(new URL(page.url()).searchParams.get('tab'), section.replace(/-/g, '_'));
      assert.equal(new URL(page.url()).searchParams.has('subtab'), false);
    }
    console.log('Home and contract tab navigation checks passed.');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode = 1;});
