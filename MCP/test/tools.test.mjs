import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS, TOOLS_BY_NAME } from '../src/tools.mjs';

class FakeClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    return this.responses[`${method} ${path}`] ?? { ok: true };
  }

  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return this.response('GET', path);
  }

  async post(path, body, tool) {
    this.calls.push({ method: 'POST', path, body, tool });
    return this.response('POST', path);
  }

  async put(path, body, tool) {
    this.calls.push({ method: 'PUT', path, body, tool });
    return this.response('PUT', path);
  }

  async patch(path, body, tool) {
    this.calls.push({ method: 'PATCH', path, body, tool });
    return this.response('PATCH', path);
  }

  async del(path, tool, query) {
    this.calls.push({ method: 'DELETE', path, query, tool });
    return this.response('DELETE', path);
  }
}

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

test('katalog má unikátní názvy a nové domény', () => {
  assert.equal(new Set(TOOLS.map(({ name }) => name)).size, TOOLS.length);
  for (const name of [
    'save_project', 'project_profitability', 'get_document', 'link_document',
    'save_logbook_car', 'save_logbook_trip', 'save_logbook_fueling', 'logbook_summary',
    'list_payroll_people', 'get_payroll_person', 'change_payroll_salary',
    'list_payroll_components', 'create_payroll_input', 'get_payroll_salary_result',
    'save_payroll_time_entry', 'create_payroll_absence',
  ]) {
    assert.ok(TOOLS_BY_NAME.has(name), name);
  }
  assert.equal(tool('project_profitability').write, false);
  assert.equal(tool('logbook_summary').write, false);
  assert.equal(tool('delete_logbook_trip').destructive, true);
  assert.equal(tool('get_payroll_salary_result').write, false);
  assert.equal(tool('change_payroll_salary').write, true);
  for (const forbidden of [
    'calculate_payroll_run', 'approve_payroll_run', 'post_payroll_run',
    'prepare_payroll_payments', 'close_payroll_run', 'send_payroll_submission',
    'decide_payroll_absence', 'cancel_payroll_absence',
  ]) {
    assert.equal(TOOLS_BY_NAME.has(forbidden), false, forbidden);
  }
});

test('změna mzdy od data posílá jen povolená pole do nové verze', async () => {
  const client = new FakeClient({
    'GET /payroll/people/7': {
      person: {
        id: 7,
        employments: [{
          id: 11,
          row_version: 4,
          monthly_gross_minor: 5000000,
          terms: [{
            id: 20,
            effective_from: '2026-01-01',
            effective_to: null,
            planned_start_on: '2026-01-01',
            weekly_hours: '40.00',
            workload_basis_points: 10000,
            social_insurance_participation: 'automatic',
            health_insurance_participation: 'automatic',
            tax_regime: 'advance',
            tax_declaration_signed: true,
            is_primary: true,
          }],
        }],
      },
    },
  });

  await tool('change_payroll_salary').run(client, {
    employee_id: 7,
    employment_id: 11,
    change_kind: 'new_terms',
    effective_from: '2026-09-01',
    monthly_gross_minor: 5500000,
    reason: 'Navýšení sjednané mzdy',
  }, 'change_payroll_salary');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/payroll/people/7'],
    ['PUT', '/payroll/employments/11/terms'],
  ]);
  assert.deepEqual(client.calls[1].body, {
    change_reason: 'Navýšení sjednané mzdy',
    row_version: 4,
    monthly_gross_minor: 5500000,
    effective_from: '2026-09-01',
  });
});

test('oprava mzdy používá aktuální verzi bez data účinnosti', async () => {
  const client = new FakeClient({
    'GET /payroll/people/7': {
      person: { id: 7, employments: [{ id: 11, row_version: 4 }] },
    },
  });

  await tool('change_payroll_salary').run(client, {
    employee_id: 7,
    employment_id: 11,
    change_kind: 'correction',
    monthly_gross_minor: 5500000,
    reason: 'Oprava chybně zadané mzdy',
  }, 'change_payroll_salary');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/payroll/people/7'],
    ['PATCH', '/payroll/employments/11/terms/current'],
  ]);
  assert.deepEqual(client.calls[1].body, {
    change_reason: 'Oprava chybně zadané mzdy',
    row_version: 4,
    monthly_gross_minor: 5500000,
  });
});

test('výsledek mzdy se dohledá přes nejnovější revizi měsíce', async () => {
  const client = new FakeClient({
    'GET /payroll/runs': { runs: [{ id: 3, revision_id: 19, period_start: '2026-08-01' }] },
    'GET /payroll/revisions/19/net-results/7': { net_result: { net_pay_minor: 4123400 } },
  });

  const result = await tool('get_payroll_salary_result').run(client, {
    employee_id: 7,
    period: '2026-08',
  }, 'get_payroll_salary_result');

  assert.equal(result.net_result.net_pay_minor, 4123400);
  assert.deepEqual(client.calls[0].query, { period: '2026-08', limit: 200, offset: 0 });
  assert.equal(client.calls[1].path, '/payroll/revisions/19/net-results/7');
});

test('úprava zakázky zachová nezadaná pole úplného PUT payloadu', async () => {
  const current = {
    client_id: 8,
    name: 'Původní zakázka',
    status: 'active',
    payment_due_days: 14,
    billing_emails: [{ email: 'billing@example.test', position: 1 }],
    billing_emails_mode: 'replace',
  };
  const client = new FakeClient({ 'GET /projects/42': current });

  await tool('save_project').run(client, { id: 42, name: 'Nový název' }, 'save_project');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/projects/42'],
    ['PUT', '/projects/42'],
  ]);
  assert.deepEqual(client.calls[1].body, { ...current, name: 'Nový název' });
});

test('nová zakázka vyžaduje klienta, název a splatnost', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_project').run(client, { name: 'Neúplná' }, 'save_project'),
    /client_id, payment_due_days/,
  );
  assert.equal(client.calls.length, 0);
});

test('detail dokumentu načte vytěžený text jen na výslovné vyžádání', async () => {
  const client = new FakeClient({
    'GET /documents/7': { id: 7, title: 'Smlouva' },
    'GET /documents/7/text': { content: 'Vytěžený text', has_more: false },
  });

  const result = await tool('get_document').run(client, {
    id: 7,
    include_text: true,
    text_offset: 100,
    text_max_chars: 5000,
  }, 'get_document');

  assert.equal(result.extracted_text.content, 'Vytěžený text');
  assert.deepEqual(client.calls[1], {
    method: 'GET',
    path: '/documents/7/text',
    query: { offset: 100, max_chars: 5000 },
    tool: 'get_document',
  });
});

test('odpojení dokumentu vyžaduje potvrzení a posílá vazbu v query', async () => {
  const preview = { id: 7, title: 'Smlouva' };
  const client = new FakeClient({ 'GET /documents/7': preview });
  const args = { id: 7, entity_type: 'project', entity_id: 42 };

  await assert.rejects(tool('unlink_document').run(client, args, 'unlink_document'), /NEPROVEDENO/);
  assert.equal(client.calls.some(({ method }) => method === 'DELETE'), false);

  await tool('unlink_document').run(client, { ...args, confirm: true }, 'unlink_document');
  assert.deepEqual(client.calls.at(-1), {
    method: 'DELETE',
    path: '/documents/7/links',
    query: { entity_type: 'project', entity_id: 42 },
    tool: 'unlink_document',
  });
});

test('AI nesmí založit jízdu bez výslovně vybrané kategorie', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_logbook_trip').run(client, {
      car_id: 3,
      trip_date: '2026-08-24',
      distance_km: 25,
    }, 'save_logbook_trip'),
    /category_id/,
  );
  assert.equal(client.calls.length, 0);

  await tool('save_logbook_trip').run(client, {
    car_id: 3,
    trip_date: '2026-08-24',
    category_id: 2,
    distance_km: 25,
    origin: 'Praha',
    destination: 'Kolín',
  }, 'save_logbook_trip');
  assert.equal(client.calls[0].path, '/logbook/trips');
});

test('úprava tankování zachová původní hodnoty', async () => {
  const current = {
    car_id: 3,
    fueled_date: '2026-08-20',
    fuel_type: 'diesel',
    quantity: 40,
    unit: 'l',
    unit_price: 38,
    amount_with_vat: 1520,
    currency: 'CZK',
    station: 'Původní stanice',
  };
  const client = new FakeClient({ 'GET /logbook/fuelings/9': current });

  await tool('save_logbook_fueling').run(client, { id: 9, station: 'Nová stanice' }, 'save_logbook_fueling');
  assert.deepEqual(client.calls[1].body, { ...current, station: 'Nová stanice' });
});

test('filtry dodavatele a nepřiřazeného vozidla patří k tankování', async () => {
  const client = new FakeClient();
  await tool('list_logbook_fuelings').run(client, {
    vendor_id: 18,
    unassigned: true,
  }, 'list_logbook_fuelings');

  assert.equal(client.calls[0].path, '/logbook/fuelings');
  assert.equal(client.calls[0].query.vendor_id, 18);
  assert.equal(client.calls[0].query.unassigned, 1);
  assert.equal(tool('list_logbook_trips').inputSchema.properties.vendor_id, undefined);
});
