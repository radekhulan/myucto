import assert from 'node:assert/strict';
import test from 'node:test';

import { OTHER_ITEM_TOOLS } from '../src/other-item-tools.mjs';
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

const writes = (client) => client.calls.filter(({ method }) => method !== 'GET');

const draft = (overrides = {}) => ({
  id: 12,
  status: 'draft',
  side: 'payable',
  kind: 'loan',
  title: 'Syntetický úvěr na stroj',
  partner_id: '7',
  partner_name: null,
  issued_on: '2094-01-15',
  accounting_on: '2094-01-15',
  due_on: '2094-12-15',
  currency: 'CZK',
  amount: '12000.00',
  amount_czk: '12000.00',
  paid_amount: '0.00',
  variable_symbol: '2094001',
  account_code: '461',
  counter_account_code: '221',
  posting_lines: [{ account_code: '221', amount: 12000 }],
  note: 'Syntetická poznámka',
  ...overrides,
});

test('katalog registruje nástroje ostatních položek a nenabízí účtování', () => {
  assert.equal(new Set(TOOLS.map(({ name }) => name)).size, TOOLS.length);
  for (const entry of OTHER_ITEM_TOOLS) {
    assert.ok(TOOLS_BY_NAME.has(entry.name), entry.name);
    assert.equal(entry.inputSchema.additionalProperties, false, entry.name);
    for (const required of entry.inputSchema.required) {
      assert.ok(Object.hasOwn(entry.inputSchema.properties, required), `${entry.name}.${required}`);
    }
    assert.ok(!Object.hasOwn(entry.inputSchema.properties, 'auto_post'), entry.name);
  }
  for (const name of ['delete_other_item', 'clear_other_item_installments']) {
    assert.equal(tool(name).destructive, true, name);
    assert.ok(Object.hasOwn(tool(name).inputSchema.properties, 'confirm'), name);
  }
  for (const forbidden of [
    'post_other_item', 'reverse_other_item', 'repost_other_item', 'allocate_other_item_payment',
    'unallocate_other_item_payment', 'generate_other_item_schedule',
  ]) {
    assert.equal(TOOLS_BY_NAME.has(forbidden), false, forbidden);
  }
  for (const entry of OTHER_ITEM_TOOLS.filter(({ write }) => write)) {
    assert.match(entry.description, /nezaúčtuje|neúčt|nic nezapisuj|nezapíná/i, entry.name);
  }
});

test('čtecí nástroje volají konkrétní cesty a mapují hledání na q', async () => {
  const routes = [
    ['list_other_items', { side: 'payable', status: 'open', query: 'nájem', page: 2 }, '/accounting/other-items',
      { side: 'payable', status: 'open', page: 2, q: 'nájem' }],
    ['list_other_items', { kind_id: 5, sort_by: 'document_no' }, '/accounting/other-items', { kind_id: 5, sort_by: 'document_no' }],
    ['get_other_item', { id: 12 }, '/accounting/other-items/12', null],
    ['list_other_item_allocations', { id: 12 }, '/accounting/other-items/12/allocations', null],
    ['other_item_payment_candidates', { id: 12, query: 'nájem', limit: 10 }, '/accounting/other-items/12/payment-candidates',
      { limit: 10, q: 'nájem' }],
    ['list_other_item_kinds', {}, '/accounting/other-items/kinds', null],
    ['list_other_item_schedules', {}, '/accounting/other-items/schedules', null],
    ['get_other_item_schedule', { id: 3 }, '/accounting/other-items/schedules/3', null],
    ['get_other_item_installments', { item_id: 12 }, '/accounting/other-items/12/installments', null],
  ];
  for (const [name, args, path, query] of routes) {
    const client = new FakeClient();
    await tool(name).run(client, args, name);
    assert.deepEqual(client.calls, [{ method: 'GET', path, query, tool: name }], name);
    assert.equal(tool(name).write, false, name);
  }
});

test('založení konceptu pošle jen zadaná pole a hlídá součet kontace', async () => {
  const client = new FakeClient();
  const args = {
    side: 'payable', kind: 'rent', title: 'Syntetický nájem 10/2094', issued_on: '2094-10-01',
    due_on: '2094-10-15', amount: 15000, posting_lines: [
      { account_code: '518', amount: 12000 }, { account_code: '548', amount: 3000 },
    ],
  };
  await tool('create_other_item').run(client, args, 'create_other_item');
  assert.deepEqual(client.calls, [{ method: 'POST', path: '/accounting/other-items', body: args, tool: 'create_other_item' }]);

  const bad = new FakeClient();
  await assert.rejects(
    tool('create_other_item').run(bad, { ...args, posting_lines: [{ account_code: '518', amount: 14999.99 }] }, 'x'),
    /součet protiřádků 14999\.99 Kč neodpovídá částce dokladu 15000\.00 Kč/,
  );
  await assert.rejects(
    tool('create_other_item').run(bad, { ...args, counter_account_code: '518' }, 'x'),
    /buď `counter_account_code`/,
  );
  await assert.rejects(tool('create_other_item').run(bad, { ...args, amount: 10.005 }, 'x'), /na haléře/);
  assert.equal(bad.calls.length, 0);
});

test('založení konceptu s document_id ověří dokument předem a připojí ho', async () => {
  const client = new FakeClient({
    'POST /accounting/other-items': { id: 77, status: 'draft' },
    'POST /documents/5/links': { links: [{ entity_type: 'other_item', entity_id: 77, label: 'Syntetická zápůjčka' }] },
  });
  const args = {
    side: 'receivable', kind: 'loan', title: 'Syntetická zápůjčka', issued_on: '2094-01-15',
    due_on: '2094-12-31', amount: 100000, account_code: '378.800', counter_account_code: '221', document_id: 5,
  };
  const result = await tool('create_other_item').run(client, args, 'create_other_item');
  const { document_id: _omit, ...itemBody } = args;
  assert.deepEqual(client.calls, [
    { method: 'GET', path: '/documents/5', query: null, tool: 'create_other_item' },
    { method: 'POST', path: '/accounting/other-items', body: itemBody, tool: 'create_other_item' },
    { method: 'POST', path: '/documents/5/links', body: { entity_type: 'other_item', entity_id: 77 }, tool: 'create_other_item' },
  ]);
  assert.equal(result.id, 77);
  assert.equal(result.document_links[0].entity_id, 77);

  const missing = new FakeClient();
  missing.get = async () => { throw new Error('404 Dokument nenalezen.'); };
  await assert.rejects(tool('create_other_item').run(missing, args, 'x'), /Dokument nenalezen/);
  assert.equal(missing.calls.length, 0);
});

test('dokument jde připojit i vyhledat u ostatní položky', () => {
  for (const name of ['link_document', 'unlink_document', 'list_entity_documents', 'upload_document']) {
    assert.ok(tool(name).inputSchema.properties.entity_type.enum.includes('other_item'), name);
  }
  assert.ok(tool('get_document_dimensions').inputSchema.properties.document_type.enum.includes('other-items'));
});

test('dimenze konceptu se pošlou jako celá hlavička jen ke konceptu', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft() });
  await tool('set_other_item_dimensions').run(client, {
    id: 12, dimensions: [{ type_id: 3, value_id: 31 }, { type_id: 4, value_id: 40 }],
  }, 'set_other_item_dimensions');
  assert.deepEqual(writes(client), [{
    method: 'PUT', path: '/accounting/dimensions/documents/other-items/12',
    body: { header: { 3: 31, 4: 40 } }, tool: 'set_other_item_dimensions',
  }]);

  await assert.rejects(tool('set_other_item_dimensions').run(client, {
    id: 12, dimensions: [{ type_id: 3, value_id: 31 }, { type_id: 3, value_id: 32 }],
  }, 's'), /víckrát/);

  const posted = new FakeClient({ 'GET /accounting/other-items/12': draft({ status: 'posted' }) });
  await assert.rejects(tool('set_other_item_dimensions').run(posted, {
    id: 12, dimensions: [{ type_id: 3, value_id: 31 }],
  }, 's'), /jen koncept/);
  assert.equal(writes(posted).length, 0);
});

test('úprava konceptu složí celý doklad a jediný protiřádek přepočte', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft() });
  await tool('update_other_item').run(client, { id: 12, amount: 13500.5, issued_on: '2094-02-01' }, 'update_other_item');
  assert.deepEqual(writes(client), [{
    method: 'PUT',
    path: '/accounting/other-items/12',
    tool: 'update_other_item',
    body: {
      side: 'payable', kind: 'loan', title: 'Syntetický úvěr na stroj', partner_id: 7, partner_name: null,
      issued_on: '2094-02-01', accounting_on: '2094-02-01', due_on: '2094-12-15', amount: 13500.5,
      variable_symbol: '2094001', account_code: '461', note: 'Syntetická poznámka',
      posting_lines: [{ account_code: '221', amount: 13500.5 }],
    },
  }]);
});

test('úprava konceptu s vlastním druhem ho drží, vestavěný druh ho zruší', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft({ kind: 'other', kind_id: '5' }) });
  await tool('update_other_item').run(client, { id: 12, title: 'Syntetický nájem klubovny' }, 'update_other_item');
  assert.equal(writes(client)[0].body.kind_id, 5);

  await tool('update_other_item').run(client, { id: 12, kind: 'other' }, 'update_other_item');
  assert.equal(writes(client)[1].body.kind_id, 5);

  await tool('update_other_item').run(client, { id: 12, kind: 'rent' }, 'update_other_item');
  assert.equal(writes(client)[2].body.kind, 'rent');
  assert.equal(writes(client)[2].body.kind_id, null);
});

test('úprava s více protiřádky změnu částky bez nové kontace odmítne', async () => {
  const lines = [{ account_code: '518', amount: 7000 }, { account_code: '548', amount: 5000 }];
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft({ posting_lines: lines, counter_account_code: null }) });
  await assert.rejects(
    tool('update_other_item').run(client, { id: 12, amount: 13000 }, 'update_other_item'),
    /2 protiřádků kontace/,
  );
  assert.equal(writes(client).length, 0);

  await tool('update_other_item').run(client, { id: 12, title: 'Nový syntetický popis' }, 'update_other_item');
  assert.deepEqual(writes(client)[0].body.posting_lines, lines);
  assert.equal(writes(client)[0].body.title, 'Nový syntetický popis');
});

test('úprava a smazání nekonceptu se odmítne ještě před zápisem', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft({ status: 'posted' }) });
  await assert.rejects(tool('update_other_item').run(client, { id: 12, title: 'X' }, 'u'), /upravit lze jen koncept/);
  await assert.rejects(tool('delete_other_item').run(client, { id: 12, confirm: true }, 'd'), /smazat lze jen koncept/);
  assert.equal(writes(client).length, 0);
});

test('smazání konceptu bez potvrzení jen vypíše doklad', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft() });
  await assert.rejects(
    tool('delete_other_item').run(client, { id: 12 }, 'delete_other_item'),
    /NEPROVEDENO — chybí potvrzení\. Smazat se má koncept: Syntetický úvěr na stroj \(závazek 12000\.00 Kč/,
  );
  assert.equal(writes(client).length, 0);
  const result = await tool('delete_other_item').run(client, { id: 12, confirm: true }, 'delete_other_item');
  assert.deepEqual(writes(client), [{ method: 'DELETE', path: '/accounting/other-items/12', query: undefined, tool: 'delete_other_item' }]);
  assert.equal(result.deleted.id, 12);
});

test('splátkový kalendář z dokumentu se ověří proti částce dokladu a pošle celý', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft({ status: 'posted' }) });
  const installments = [
    { due_on: '2094-02-15', amount: 4000 },
    { due_on: '2094-03-15', amount: 4000 },
    { due_on: '2094-04-15', amount: 4000 },
  ];
  await tool('set_other_item_installments').run(client, { item_id: 12, installments }, 'set_other_item_installments');
  assert.deepEqual(writes(client), [{
    method: 'PUT', path: '/accounting/other-items/12/installments', body: { items: installments },
    tool: 'set_other_item_installments',
  }]);
});

test('splátkový kalendář odmítne špatný součet, pořadí, datum i počet', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/12': draft() });
  const run = (installments) => tool('set_other_item_installments').run(client, { item_id: 12, installments }, 's');
  await assert.rejects(run([{ due_on: '2094-02-15', amount: 6000 }, { due_on: '2094-03-15', amount: 5999.99 }]),
    /součet splátek 11999\.99 Kč neodpovídá částce dokladu 12000\.00 Kč \(rozdíl 0\.01 Kč\)/);
  await assert.rejects(run([{ due_on: '2094-03-15', amount: 6000 }, { due_on: '2094-02-15', amount: 6000 }]),
    /vzestupné/);
  await assert.rejects(run([{ due_on: '2094-01-01', amount: 6000 }, { due_on: '2094-02-15', amount: 6000 }]),
    /před datem vzniku/);
  await assert.rejects(run([{ due_on: '2094-02-30', amount: 6000 }, { due_on: '2094-03-15', amount: 6000 }]),
    /neplatné datum/);
  await assert.rejects(run([{ due_on: '2094-02-15', amount: 12000 }]), /2 až 120 splátek/);
  await assert.rejects(run([]), /clear_other_item_installments/);

  const paid = new FakeClient({ 'GET /accounting/other-items/12': draft({ status: 'confirmed', paid_amount: '100.00' }) });
  await assert.rejects(tool('set_other_item_installments').run(paid, {
    item_id: 12, installments: [{ due_on: '2094-02-15', amount: 6000 }, { due_on: '2094-03-15', amount: 6000 }],
  }, 's'), /spárovanou úhradu/);
  assert.equal(writes(client).length + writes(paid).length, 0);
});

test('zrušení splátkového kalendáře vyžaduje potvrzení a pošle prázdný seznam', async () => {
  const client = new FakeClient({
    'GET /accounting/other-items/12': draft(),
    'GET /accounting/other-items/12/installments': { items: [{ id: 1 }, { id: 2 }] },
  });
  await assert.rejects(
    tool('clear_other_item_installments').run(client, { item_id: 12 }, 'c'),
    /Zrušit se má splátkový kalendář \(2 splátek\)/,
  );
  assert.equal(writes(client).length, 0);
  await tool('clear_other_item_installments').run(client, { item_id: 12, confirm: true }, 'c');
  assert.deepEqual(writes(client), [{ method: 'PUT', path: '/accounting/other-items/12/installments', body: { items: [] }, tool: 'c' }]);
});

test('opakování se zakládá vždy bez automatického účtování', async () => {
  const client = new FakeClient();
  await tool('create_other_item_schedule').run(client, {
    item_id: 12, frequency: 'monthly', ends_on: '2094-12-31', auto_post: true,
  }, 'create_other_item_schedule');
  assert.deepEqual(client.calls, [{
    method: 'POST', path: '/accounting/other-items/12/schedule',
    body: { frequency: 'monthly', ends_on: '2094-12-31', auto_post: false }, tool: 'create_other_item_schedule',
  }]);
});

test('obnovení opakování s automatikou odmítne, pozastavení projde', async () => {
  const client = new FakeClient({ 'GET /accounting/other-items/schedules/3': { id: 3, auto_post: true, status: 'paused' } });
  await assert.rejects(
    tool('set_other_item_schedule_status').run(client, { id: 3, status: 'active' }, 's'),
    /automatické účtování/,
  );
  assert.equal(writes(client).length, 0);
  await tool('set_other_item_schedule_status').run(client, { id: 3, status: 'paused', auto_post: true }, 's');
  assert.deepEqual(writes(client), [{ method: 'PUT', path: '/accounting/other-items/schedules/3/status', body: { status: 'paused' }, tool: 's' }]);

  const manual = new FakeClient({ 'GET /accounting/other-items/schedules/4': { id: 4, auto_post: false } });
  await tool('set_other_item_schedule_status').run(manual, { id: 4, status: 'active' }, 's');
  assert.deepEqual(writes(manual)[0].body, { status: 'active' });
});
