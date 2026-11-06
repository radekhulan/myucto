import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { TOOLS } from '../src/tools.mjs';
import { UPDATE_TOOL } from '../src/update.mjs';
import { apiManifest, matchesApiPath, serializeManifest } from '../src/api-contracts.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

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

/** Jen syntetické odpovědi; každé volání se zaznamená i tehdy, když run zachytí jeho chybu. */
class RecordingClient {
  constructor(name, { status = 'draft', projectRate = 1200, clientRate = 1100, previousRate = 1000, pdfPath = null } = {}) {
    this.name = name;
    this.calls = [];
    this.status = status;
    this.projectRate = projectRate;
    this.clientRate = clientRate;
    this.previousRate = previousRate;
    this.pdfPath = pdfPath;
  }

  async call(method, path) {
    this.calls.push([method, `/api${path}`]);
    const item = { id: 7, description: 'Syntetická položka', quantity: 2, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 7 };
    const row = { id: 7, name: 'Syntetický projekt', company_name: 'Syntetický klient', status: this.status,
      invoice_type: 'invoice', document_kind: 'invoice', client_id: 7, vendor_id: 7, project_id: 7, issue_date: '2026-01-01',
      items: [item, { ...item, id: 8 }], next_run_date: '2026-02-01', currency: 'CZK', currency_id: 7,
      ic: '00000000', hourly_rate: this.projectRate, pdf_path: this.pdfPath };
    if (method !== 'GET') return row;
    if (path === '/clients') return { data: [{ ...row, ic: '', dic: '' }] };
    if (path === '/projects') return { data: [row] };
    if (path === '/invoices') return { data: [row] };
    if (/^\/clients\/\d+$/.test(path)) return { ...row, hourly_rate: this.clientRate };
    if (/work-report$/.test(path)) return { title: 'Syntetický výkaz', project_id: 7, vat_rate_id: 7,
      material_vat_rate_id: 7, items: [{ description: 'Původní práce', hours: 1, rate: this.previousRate }],
      materials: [{ description: 'Původní materiál', quantity: 1, unit: 'ks', unit_price: 10 }] };
    if (/\/payments$/.test(path)) return { payments: [{ id: 7, amount: 100, paid_on: '2026-01-01' }] };
    if (path === '/codebooks/currencies') return [{ id: 7, code: 'CZK' }, { id: 8, code: 'EUR' }];
    if (path === '/codebooks/cnb-rate') return { rate: 25, rate_date: '2026-01-01' };
    if (path === '/settings/supplier') return { default_hourly_rate: 900 };
    if (this.name === 'unlink_invoice_advance') return { ...row, parent_invoice_id: 8, advance_paid_amount: 100 };
    if (this.name === 'unlink_purchase_advance') return { ...row, linked_advance: { id: 8, total_with_vat: 100, currency: 'CZK' } };
    return row;
  }

  get(path) { return this.call('GET', path); }
  post(path) { return this.call('POST', path); }
  put(path) { return this.call('PUT', path); }
  patch(path) { return this.call('PATCH', path); }
  del(path) { return this.call('DELETE', path); }
  postRead(path) { return this.call('POST', path); }
  upload(path) { return this.call('POST', path); }
  async download(path) {
    await this.call('GET', path);
    return { filename: 'synthetic.pdf', content_type: 'application/pdf', size: 3, content_base64: 'UERG' };
  }
}

function sample(schema) {
  if (schema.enum) return schema.enum[0];
  if (schema.type === 'object') return Object.fromEntries((schema.required ?? []).map((key) => [key, sample(schema.properties[key])]));
  if (schema.type === 'array') return Array.from({ length: schema.minItems ?? 1 }, () => sample(schema.items));
  if (schema.type === 'integer' || schema.type === 'number') return Math.max(1, schema.minimum ?? 1);
  if (schema.type === 'boolean') return true;
  if (schema.format === 'date') return '2026-01-01';
  return 'Syntetický údaj';
}

function basicArgs(tool) {
  const common = { id: 7, invoice_id: 7, item_id: 7, payment_id: 7, attachment_id: 7, row: 1, confirm: true,
    description: 'Syntetická práce', name: 'Syntetický projekt', company_name: 'Syntetický klient',
    street: 'Syntetická 1', city: 'Praha', zip: '11000', currency: 'CZK', hours: 2, rate: 1500,
    quantity: 2, unit_price_without_vat: 100, unit_price: 100, vat_rate_id: 7, client_id: 7,
    payment_due_days: 14, registration: 'SYNTHETIC', car_id: 7, trip_date: '2026-01-01', category_id: 7,
    distance_km: 10, fueled_date: '2026-01-01', amount_with_vat: 100,
    filename: 'synthetic.pdf', content_base64: Buffer.from('%PDF-synthetic').toString('base64') };
  return { ...sample(tool.inputSchema), ...Object.fromEntries(Object.entries(common).filter(([key]) => key in tool.inputSchema.properties)) };
}

function scenarios(tool) {
  const args = basicArgs(tool);
  const cases = [{ args }];
  // Dohledání konceptu musí být otestované v obou větvích i u starších fakturačních nástrojů.
  if ('project' in tool.inputSchema.properties && 'invoice_id' in tool.inputSchema.properties) {
    for (const lookup of ['project', 'client']) {
      const { invoice_id: unused, ...rest } = args;
      cases.push({ args: { ...rest, [lookup]: lookup === 'project' ? 'Syntetický projekt' : 'Syntetický klient' } });
    }
  }
  if (/^save_(project|logbook_)/.test(tool.name)) {
    const { id: unused, ...rest } = args;
    cases.push({ args: rest });
  }
  if (['list_logbook_cars', 'list_logbook_trips', 'list_logbook_fuelings', 'project_profitability'].includes(tool.name)) {
    const { id: unused, ...rest } = args;
    cases.push({ args: rest });
  }
  if (tool.name === 'create_client') cases.push({ args: { ...args, ic: '00000000', dic: 'CZ00000000' } });
  if (tool.name === 'update_client') cases.push({ args: { ...args, refresh_from_ares: true } });
  if (tool.name === 'create_purchase_invoice') cases.push({ args: { ...args, currency: 'EUR' } });
  if (tool.name === 'update_purchase_invoice') {
    cases.push({ args: { id: args.id, note: 'Syntetická poznámka' } });
    cases.push({ args: { id: args.id, currency_id: 8 } });
    cases.push({ args: { id: args.id, vendor_id: 9 } });
  }
  if (['update_purchase_invoice_item', 'remove_purchase_invoice_item'].includes(tool.name)) {
    const { item_id: unused, ...rest } = args;
    cases.push({ args: rest });
  }
  if (tool.name === 'upload_purchase_invoice_pdf') cases.push({ args, options: { pdfPath: 'synthetic.pdf' } });
  if (tool.name === 'import_purchase_invoice_file') cases.push({ args: {
    filename: 'synthetic.isdoc', content_base64: Buffer.from('<Invoice/>').toString('base64'),
  } });
  if (tool.name === 'add_work_report_entry') {
    const { rate: unused, ...rest } = args;
    for (const options of [{}, { previousRate: 0 }, { previousRate: 0, projectRate: 0 }, { previousRate: 0, projectRate: 0, clientRate: 0 }]) {
      cases.push({ args: rest, options });
    }
  }
  return cases;
}

for (const tool of all) {
  // Vyloučení i jeho zdůvodnění už ověřila kontrola celého katalogu výše.
  if (tool.api === false) continue;
  test(`${tool.name}: skutečná API volání odpovídají deklaraci`, async () => {
    const recorded = [];
    for (const entry of scenarios(tool)) {
      const status = tool.name === 'cancel_invoice' ? 'issued' : tool.name === 'unmark_invoice_paid' ? 'paid' : 'draft';
      const client = new RecordingClient(tool.name, { status, ...entry.options });
      const args = validateToolArguments(tool, entry.args);
      await tool.run(client, args, tool.name);
      assert.ok(client.calls.length > 0, `${tool.name}: scénář musí dojít k API`);
      recorded.push(...client.calls);
    }
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
