import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { TOOLS } from '../src/tools.mjs';
import { UPDATE_TOOL } from '../src/update.mjs';
import {
  apiManifest, matchesApiPath, NOT_IN_CLIENT_CATALOG, routeManifest, routeTemplate, serializeManifest, serializeRouteManifest,
} from '../src/api-contracts.mjs';
import { recordToolCalls } from '../tool-recording.mjs';

const all = [...TOOLS, UPDATE_TOOL];

test('verzovaný manifest odpovídá deklaracím celého katalogu', () => {
  assert.equal(readFileSync(new URL('../src/tool-api.generated.json', import.meta.url), 'utf8'), serializeManifest(all));
});

test('neúplná deklarace ani zápis označený jako čtení neprojdou', () => {
  const base = { name: 'synthetic', write: false, api: [['GET', '/api/invoices/{id}']] };
  assert.throws(() => apiManifest([{ ...base, api: undefined }]), /chybí deklarace/);
  assert.throws(() => apiManifest([{ ...base, api: [] }]), /chybí deklarace/);
  assert.throws(() => apiManifest([{ ...base, api: false }]), /zdůvodnění/);
  assert.deepEqual(apiManifest([{ ...base, api: false, apiReason: 'Lokální nástroj.' }]), {});
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
    assert.throws(() => apiManifest([{ ...base, api: [[method, '/api/invoices']] }]), /write: true/);
  }
  for (const path of ['/api/invoices/1', '/api/invoices/*', '/api/invoices/{id}/../settings', '/api/invoices?x=1']) {
    assert.throws(() => apiManifest([{ ...base, api: [['GET', path]] }]), /šablona/);
  }
  assert.equal(matchesApiPath('/api/invoices/{id}', '/api/invoices/73'), true);
  assert.equal(matchesApiPath('/api/invoices/{id}', '/api/invoices/73/payments'), false);
});

test('verzovaný manifest rout pokrývá celý katalog včetně nástrojů mimo klientský', async () => {
  const current = readFileSync(new URL('../src/tool-routes.generated.json', import.meta.url), 'utf8');
  assert.equal(current, await serializeRouteManifest(all, (tool) => recordToolCalls(tool, { tolerant: true })));
  const routes = JSON.parse(current);
  assert.deepEqual(Object.keys(routes).toSorted(), all.map((tool) => tool.name).toSorted());
  // Incident: aging_* nejsou v klientském katalogu, ale filtr podle role jejich routu potřebuje.
  assert.deepEqual(routes.aging_receivables.api, [['GET', '/api/crm/aging-receivables']]);
  assert.deepEqual(routes.aging_payables.api, [['GET', '/api/crm/aging-payables']]);
  for (const tool of all) {
    if (tool.api !== false) assert.deepEqual(routes[tool.name].api, tool.api, tool.name);
    else if (tool.apiReason === NOT_IN_CLIENT_CATALOG) assert.ok(routes[tool.name].api.length > 0, tool.name);
  }
});

test('šablona zaznamenané cesty skryje ID i hodnoty z argumentů', () => {
  assert.equal(routeTemplate('/api/invoices/7/payments/8'), '/api/invoices/{id}/payments/{id}');
  assert.equal(routeTemplate('/api/reports/vat-return?period=2026-01'), '/api/reports/vat-return');
  assert.equal(routeTemplate('/api/codebooks/cnb-rate/EUR'), '/api/codebooks/cnb-rate/{value}');
});

test('manifest rout odmítne nástroj mimo katalog, jehož scénář nedošel k API', async () => {
  const base = { name: 'synthetic', write: false, api: false };
  await assert.rejects(routeManifest([{ ...base, apiReason: NOT_IN_CLIENT_CATALOG }], async () => []), /nedošel k API/);
  assert.deepEqual(await routeManifest([{ ...base, apiReason: 'Lokální nástroj.' }], async () => []),
    { synthetic: { write: false, api: [] } });
  assert.deepEqual(await routeManifest([{ ...base, apiReason: NOT_IN_CLIENT_CATALOG }],
    async () => [['GET', '/api/crm/aging-receivables'], ['GET', '/api/invoices/7'], ['GET', '/api/invoices/8']]),
  { synthetic: { write: false, api: [['GET', '/api/crm/aging-receivables'], ['GET', '/api/invoices/{id}']] } });
  await assert.rejects(routeManifest([{ ...base, apiReason: NOT_IN_CLIENT_CATALOG }],
    async () => [['GET', '/settings']]), /šablona/);
});

for (const tool of all) {
  // Vyloučení i jeho zdůvodnění už ověřila kontrola celého katalogu výše.
  if (tool.api === false) continue;
  test(`${tool.name}: skutečná API volání odpovídají deklaraci`, async () => {
    const recorded = await recordToolCalls(tool);
    for (const [method, path] of recorded) {
      assert.ok(tool.api.some(([declaredMethod, template]) => method === declaredMethod && matchesApiPath(template, path)),
        `${tool.name}: chybí deklarace ${method} ${path}`);
    }
    // Zelená nesmí vzniknout ukončením run před podmíněnou operací či před zápisem.
    for (const [method, template] of tool.api) {
      assert.ok(recorded.some(([actual, path]) => actual === method && matchesApiPath(template, path)),
        `${tool.name}: scénáře nedosáhly ${method} ${template}`);
    }
  });
}
