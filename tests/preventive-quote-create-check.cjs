const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const helpers = source.slice(source.indexOf('  function isPreventivaCase('), source.indexOf('  function syncPreventivaNoAccessBox('));
const start = source.indexOf('        if (\n          !isPublicPqr &&\n          canUseDashboardAction("quote_create")');
assert(start >= 0, 'Quote creation action was not found');
const action = source.slice(start, source.indexOf('        if (isPublicPqr && ticketUrl)', start));

function render(dataset, {allowed = true, publicPqr = false} = {}) {
  const context = {
    btn: {dataset: {ticket: '10519', ...dataset}},
    calendarTicketPk: '10519',
    cotizacionId: dataset.cotizacionId || '',
    cotizacionUrl: '',
    quoteActionButtons: [],
    isPublicPqr: publicPqr,
    canUseDashboardAction: key => key === 'quote_create' && allowed,
    escHtml: value => String(value).replace(/"/g, '&quot;'),
  };
  vm.runInNewContext(helpers + action, context);
  return context.quoteActionButtons.join('');
}

const preventive = {tabKey: 'preventiva', asunto: 'REVISION PREVENTIVA', idRevisionPreventiva: '257', prevEncontroDanos: 'Si', cotizacionId: '549'};
const html = render(preventive);
assert.match(html, /A&ntilde;adir nueva cotizaci&oacute;n/, 'Preventive cases with a quote need an additional quote action');
assert.match(html, /data-scm-create-cotizacion/, 'The action must open the native quote form');
assert.match(html, /data-scm-clear-cotizacion-create-draft="1"/, 'The additional quote must start with a clean draft');
assert.match(html, /data-cotizacion-mode="create"/, 'The action must create a quote instead of editing the existing one');
assert.match(html, /data-ticket-pk="10519"/, 'The additional quote must belong to the current case');
assert.match(render({...preventive, cotizacionId: ''}), /A&ntilde;adir cotizaci&oacute;n/, 'A first preventive quote must also be available');
assert.match(render({...preventive, tabKey: 'mis_tickets'}), /data-scm-create-cotizacion/, 'Preventive cases must work from other case tabs');
assert.equal(render(preventive, {allowed: false}), '', 'The creation permission must still apply');
assert.equal(render(preventive, {publicPqr: true}), '', 'Public PQR must not expose maintenance quote creation');
assert.equal(render({...preventive, prevEncontroDanos: 'No'}), '', 'Preventive reviews without damage must not enable a quote');
assert.equal(render({...preventive, idRevisionPreventiva: ''}), '', 'A preventive review is required');
assert.match(render({tabKey: 'mantenimiento', idRevisionCorrectiva: '30'}), /data-scm-create-cotizacion/, 'Corrective maintenance quote creation must remain available');
assert.equal(render({tabKey: 'contable', idRevisionCorrectiva: '30'}), '', 'Unrelated case tabs must not enable maintenance quotes');
console.log('Preventive quote creation checks passed.');
