/**
 * Zaznamenávající klient a syntetické scénáře pro nástroje MCP.
 *
 * Sdílí je test shody deklarací (`test/api-contracts.test.mjs`) a generátor
 * manifestu rout (`generate-api-manifest.mjs`), který z nich u nástrojů bez
 * ručně deklarovaného `api` odvozuje skutečně volané operace.
 */
import { validateToolArguments } from './src/tool-args.mjs';

/** Jen syntetické odpovědi; každé volání se zaznamená i tehdy, když run zachytí jeho chybu. */
export class RecordingClient {
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
  return STRING_SAMPLES.find((value) => fitsString(schema, value)) ?? 'Syntetický údaj';
}

/** První vzorek, který projde omezeními schématu; obecný text má přednost. */
const STRING_SAMPLES = ['Syntetický údaj', '2026-01-01', '2026-01', '2026-01-01T00:00:00Z', '1', 'CZK', 'cs', '311', 'A1', '9001010000'];

function fitsString(schema, value) {
  return (!Number.isInteger(schema.minLength) || value.length >= schema.minLength)
    && (!Number.isInteger(schema.maxLength) || value.length <= schema.maxLength)
    && (typeof schema.pattern !== 'string' || new RegExp(schema.pattern).test(value));
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

export function scenarios(tool) {
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
  // Nástroje mimo klientský katalog: jen tolik, aby scénář došel k API (manifest rout).
  const minimal = {
    set_purchase_invoice_payment_account: { account_number: '1000000005', bank_code: '0100' },
    update_other_item: { title: 'Syntetický doklad', amount: 100 },
    delete_attribute_option: { option_id: 7 },
    delete_product_media: { media_id: 7 },
    allocate_other_item_payment: { bank_transaction_id: 7 },
    get_payroll_salary_result: { period: '2026-01' },
    create_analytic_account: { account_code: '378.800' },
  }[tool.name];
  if (minimal) cases.push({ args: { ...args, ...minimal } });
  if (tool.name === 'delete_purchase_invoice_pdf') cases.push({ args, options: { pdfPath: 'synthetic.pdf' } });
  if (tool.name === 'uncancel_invoice') cases.push({ args, options: { status: 'cancelled' } });
  return cases;
}

/**
 * Projde všechny scénáře nástroje nad zaznamenávajícím klientem a vrátí volání.
 * `tolerant` zachová volání i z běhu, který skončil chybou (nástroje mimo klientský
 * katalog nemají scénáře doladěné na všechny větve).
 */
export async function recordToolCalls(tool, { tolerant = false } = {}) {
  const recorded = [];
  for (const entry of scenarios(tool)) {
    const status = tool.name === 'cancel_invoice' ? 'issued' : tool.name === 'unmark_invoice_paid' ? 'paid' : 'draft';
    const client = new RecordingClient(tool.name, { status, ...entry.options });
    try {
      await tool.run(client, validateToolArguments(tool, entry.args), tool.name);
    } catch (error) {
      if (!tolerant) throw error;
    }
    if (!tolerant && client.calls.length === 0) throw new Error(`${tool.name}: scénář musí dojít k API`);
    recorded.push(...client.calls);
  }
  return recorded;
}
