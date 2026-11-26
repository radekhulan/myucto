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
  constructor(name, {
    status = 'draft', projectRate = 1200, clientRate = 1100, previousRate = 1000, pdfPath = null,
    currency = 'CZK', invoiceType = 'invoice',
  } = {}) {
    this.name = name;
    this.calls = [];
    this.status = status;
    this.projectRate = projectRate;
    this.clientRate = clientRate;
    this.previousRate = previousRate;
    this.pdfPath = pdfPath;
    this.currency = currency;
    this.invoiceType = invoiceType;
  }

  async call(method, path) {
    this.calls.push([method, `/api${path}`]);
    const item = { id: 7, description: 'Syntetická položka', quantity: 2, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 7 };
    const row = { id: 7, name: 'Syntetický projekt', company_name: 'Syntetický klient', status: this.status,
      invoice_type: this.invoiceType, document_kind: 'invoice', client_id: 7, vendor_id: 7, project_id: 7, issue_date: '2026-01-01',
      items: [item, { ...item, id: 8 }], next_run_date: '2026-02-01', currency: this.currency, currency_id: 7,
      ic: '00000000', hourly_rate: this.projectRate, pdf_path: this.pdfPath };
    if (method !== 'GET') return row;
    if (path.startsWith('/stock/documents/')) return { ...row, lines: [] };
    if (path === '/document-folders') return { folders: [{ id: 7, name: 'Syntetická složka', parent_id: null }] };
    if (path === '/documents') return { documents: [] };
    if (path === '/logbook/trips') return { data: [], meta: { total: 0, pages: 0 } };
    if (path.startsWith('/logbook/trips/')) return { ...row, car_id: 7, trip_date: '2026-01-01' };
    if (path.endsWith('/tracking-units')) return { data: [{ id: 1 }], meta: { pages: 1 } };
    if (/^\/(eshop\/pricing\/matrix|eshop\/jobs)\/\d+\/items$/.test(path)) {
      return { job: row, items: [], pagination: { total: 0 } };
    }
    if (['/logbook/odometer-gaps', '/logbook/odometer-readings', '/logbook/trip-templates',
      '/logbook/trip-categories', '/stock/item-templates'].includes(path)) return [row];
    if (path.startsWith('/payroll/people/')) return { employments: [{ id: 7, row_version: 1 }] };
    if (path === '/stock/purchase-orders/7/receipt') return { lines: [{ purchase_order_line_id: 7, remaining_qty: 2 }] };
    if (path === '/accounting/other-items/7') {
      return { ...row, side: 'receivable', issued_on: '2026-01-01', due_on: '2026-03-01', amount: 100 };
    }
    if (path === '/accounting/other-items/7/installments') return { items: [{ due_on: '2026-02-01', amount: 50 }] };
    if (this.name === 'unlink_purchase_advance') return { ...row, linked_advance: { id: 8, total_with_vat: 100, currency: 'CZK' } };
    if (/^\/purchase-invoices\/\d+$/.test(path)) {
      return { ...row, vendor_invoice_number: 'SYN-7', due_date: '2026-02-01' };
    }
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
    if (/billing-preview$/.test(path)) return { items: [], fingerprint: 'a'.repeat(64) };
    if (path.includes('/journal/for-document/')) return { items: [] };
    if (this.name === 'unlink_invoice_advance') return { ...row, parent_invoice_id: 8, advance_paid_amount: 100 };
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
  const type = Array.isArray(schema.type) ? schema.type.find((value) => value !== 'null') : schema.type;
  if (type === 'object') return Object.fromEntries((schema.required ?? []).map((key) => [key, sample(schema.properties[key])]));
  if (type === 'array') {
    const entry = sample(schema.items);
    const count = schema.minItems ?? Math.min(1, schema.maxItems ?? 1);
    return Array.from({ length: count }, (_, index) => schema.uniqueItems && typeof entry === 'number' ? entry + index : entry);
  }
  if (type === 'integer' || type === 'number') {
    return Math.min(schema.maximum ?? Infinity, Math.max(schema.minimum ?? 1, (schema.exclusiveMinimum ?? 0) + 1));
  }
  if (type === 'boolean') return true;
  if (type === 'null') return null;
  if (schema.format === 'date') return '2026-01-01';
  return STRING_SAMPLES.find((value) => fitsString(schema, value)) ?? 'Syntetický údaj';
}

const STRING_SAMPLES = [
  'Syntetický údaj', '2026-01-01', '2026-01', '2026-01-01T00:00:00Z', '1', 'CZK', 'CZ',
  'cs', '311', 'A1', '9001010000', 'manufacturer', '00000000-0000-4000-8000-000000000001', 'a'.repeat(64),
];

function fitsString(schema, value) {
  return (!Number.isInteger(schema.minLength) || value.length >= schema.minLength)
    && (!Number.isInteger(schema.maxLength) || value.length <= schema.maxLength)
    && (typeof schema.pattern !== 'string' || new RegExp(schema.pattern).test(value));
}

function basicArgs(tool) {
  const common = { id: 7, vat_id: 'CZ00000000', invoice_id: 7, item_id: 7, payment_id: 7, attachment_id: 7, row: 1, confirm: true,
    description: 'Syntetická práce', name: 'Syntetický projekt', company_name: 'Syntetický klient',
    street: 'Syntetická 1', city: 'Praha', zip: '11000', currency: 'CZK', hours: 2, rate: 1500,
    quantity: 2, unit_price_without_vat: 100, unit_price: 100, vat_rate_id: 7, client_id: 7,
    payment_due_days: 14, registration: 'SYNTHETIC', car_id: 7, trip_date: '2026-01-01', category_id: 7,
    distance_km: 10, fueled_date: '2026-01-01', amount_with_vat: 100,
    filename: 'synthetic.pdf', content_base64: Buffer.from('%PDF-synthetic').toString('base64') };
  const overrides = Object.entries(common).filter(([key, value]) => {
    const property = tool.inputSchema.properties[key];
    return property && !(typeof value === 'number' && property.type === 'string');
  });
  return { ...sample(tool.inputSchema), ...Object.fromEntries(overrides) };
}

export function scenarios(tool) {
  const args = basicArgs(tool);
  const cases = [{ args }];
  if (tool.api === false && args.confirm === true) cases.push({ args: { ...args, confirm: false } });
  if (tool.name === 'change_payroll_salary') {
    cases.push({ args: { ...args, employment_id: 7, effective_from: '2026-01-01' } });
    cases.push({ args: { ...args, employment_id: 7, change_kind: 'correction' } });
  }
  if (tool.name === 'set_other_item_installments') {
    cases.push({ args: { item_id: 7, installments: [
      { due_on: '2026-02-01', amount: 50 },
      { due_on: '2026-03-01', amount: 50 },
    ] } });
  }
  if (tool.name === 'get_payroll_run_results') cases.push({ args: { ...args, employee_id: 7 } });
  if (tool.name === 'migration_data_checks') cases.push({ args: { ...args, check: 'bank_balances' } });
  if (tool.name === 'vat_filing_checks') {
    cases.push({ args: { check: 'related_parties', from: '2026-01-01', to: '2026-03-31' } });
    cases.push({ args: { check: 'vat_coefficient', year: 2026 } });
  }
  if (tool.name === 'small_assets_report') {
    cases.push({ args: { kind: 'movements', from: '2026-01-01', to: '2026-03-31' } });
  }
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
  if (tool.name === 'update_stock_document') cases.push({ args: { ...args, lines: [{ stock_item_id: 7, qty: 1 }] } });
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
    match_bank_transaction: { invoice_ids: [7] },
    match_bank_payment_to_document: { invoice_id: 7 },
    match_bank_payments_batch: { invoice_id: 7, transaction_ids: [7, 8], confirm_version: 'a'.repeat(64) },
    create_document_request: { description: 'Syntetický doklad' },
    find_bank_payments_for_document: { invoice_id: 7 },
    create_analytic_account: { account_code: '378.800' },
    set_purchase_invoice_expense_kinds: { items: [{ item_id: 7, expense_kind: 'service', expense_account_code: '518' }] },
  }[tool.name];
  if (minimal) cases.push({ args: { ...args, ...minimal } });
  // Revize mzdy zadaná přímo: druhá větev nástroje (net-results bez dohledání běhu).
  if (tool.name === 'get_payroll_salary_result') cases.push({ args: { ...args, revision_id: 7 } });
  if (tool.name === 'find_bank_payments_for_document') cases.push({ args: { ...args, purchase_invoice_id: 7 } });
  if (tool.name === 'create_document_request') cases.push({ args: { ...args, bank_transaction_id: 7 } });
  if (tool.name === 'update_document') cases.push({ args: { id: 7, folder_id: 7 } });
  if (tool.api === false) cases.push(...branchCases(tool, args));
  // Nepovinné `id` přepíná seznam a detail (např. list_small_assets).
  if (tool.api === false && 'id' in args && !(tool.inputSchema.required ?? []).includes('id')) {
    const { id: unused, ...rest } = args;
    cases.push({ args: rest });
  }
  if (tool.name === 'delete_purchase_invoice_pdf') cases.push({ args, options: { pdfPath: 'synthetic.pdf' } });
  if (tool.name === 'uncancel_invoice') cases.push({ args, options: { status: 'cancelled' } });
  if (tool.name === 'set_purchase_invoice_exchange_rate') cases.push({ args, options: { currency: 'EUR' } });
  if (tool.name === 'set_invoice_payment_schedule') {
    cases.push({ args: { ...args, payment_schedule: [{ due_on: '2026-02-01', total_amount: 100 }] }, options: { invoiceType: 'rent_calendar' } });
  }
  if (tool.name === 'create_invoice') {
    cases.push({ args: { ...args, invoice_type: 'credit_note', parent_invoice_id: 7 }, options: { status: 'issued' } });
  }
  if (['set_invoice_dimensions', 'set_purchase_invoice_dimensions'].includes(tool.name)) {
    cases.push({ args: { ...args, header: [{ type_id: 7, value_id: 7 }], items: [{ row: 1, dimensions: [{ type_id: 7, value_id: null }] }] } });
  }
  if (tool.name === 'list_vat_classifications') cases.push({ args: { ...args, include_return_lines: true } });
  if (tool.name === 'get_invoice_posting') cases.push({ args: { ...args, document_type: 'purchase-invoices' } });
  if (tool.name === 'create_invoice_refund_order') cases.push({ args: { ...args, account_number: '1000000005', bank_code: '0100' } });
  if (['set_invoice_credit_note_offset', 'set_purchase_credit_note_offset'].includes(tool.name)) {
    cases.push({ args: { ...args, action: 'revert' } });
  }
  if (tool.name === 'set_purchase_invoice_vat_treatment') cases.push({ args: { ...args, vat_deduction: 'none' } });
  return cases;
}

/**
 * Nástroje mimo klientský katalog (`api: false`) se do manifestu rout zapisují jen
 * z toho, co scénář skutečně zavolal. Parametr s výčtem nebo přepínač často mění
 * cestu (`entity_type`, `type: fo|po`, `mode`, `decision`), takže výchozí vzorek by
 * zachytil jen první větev a filtr nabídky podle role by o ostatních nevěděl.
 * Projde proto každou hodnotu výčtu a obě hodnoty přepínače, každou zvlášť.
 */
function branchCases(tool, args) {
  const cases = [];
  for (const [key, property] of Object.entries(tool.inputSchema.properties ?? {})) {
    if (key === 'confirm') continue;
    const values = Array.isArray(property.enum) ? property.enum
      : property.type === 'boolean' ? [true, false] : [];
    for (const value of values) {
      if (args[key] !== value) cases.push({ args: { ...args, [key]: value } });
    }
  }
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
