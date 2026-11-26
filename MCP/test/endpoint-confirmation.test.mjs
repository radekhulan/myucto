import assert from 'node:assert/strict';
import test from 'node:test';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

test('Přesun dokumentu do koše vrátí detail před potvrzením', async () => {
  const tool = TOOLS_BY_NAME.get('trash_document');
  const record = { id: 12, title: 'Syntetický dokument' };
  const calls = [];
  const client = {
    get: (path) => { calls.push(['GET', path]); return record; },
    del: (path) => calls.push(['DELETE', path]),
  };
  const preview = await tool.run(client, { id: 12, confirm: false }, tool.name);
  assert.equal(preview.confirmed, false);
  assert.deepEqual(preview.preview, record);
  assert.deepEqual(calls, [['GET', '/documents/12']]);
  await tool.run(client, { id: 12, confirm: true }, tool.name);
  assert.deepEqual(calls, [['GET', '/documents/12'], ['DELETE', '/documents/12']]);
});

test('Náhled hromadné kopie vrátí počet a seznam zdrojových faktur', async () => {
  const tool = TOOLS_BY_NAME.get('clone_invoice_drafts_bulk');
  const calls = [];
  const preview = await tool.run({
    get: (path) => {
      calls.push(path);
      return { id: Number(path.split('/').at(-1)), invoice_number: 'FA-TEST' };
    },
  }, { invoice_ids: [12, 34, 12] }, tool.name);
  assert.deepEqual(calls, ['/invoices/12', '/invoices/34']);
  assert.deepEqual(preview.preview, {
    count: 2, items: [{ id: 12, invoice_number: 'FA-TEST' }, { id: 34, invoice_number: 'FA-TEST' }],
  });
});

test('Náhled přepočtu tachometru prochází stránky a respektuje pořadí jízd', async () => {
  const tool = TOOLS_BY_NAME.get('recalculate_logbook_trip_odometers');
  const anchor = { id: 10, car_id: 2, trip_date: '2026-03-01', time_start: '08:00' };
  const later = { ...anchor, id: 8, time_start: '12:00' };
  const earlier = { ...anchor, id: 11, time_start: null };
  const nextDay = { ...anchor, id: 9, trip_date: '2026-03-02' };
  const pages = [];
  const preview = await tool.run({
    get: (path, query) => {
      if (path === '/logbook/trips/10') return anchor;
      assert.equal(path, '/logbook/trips');
      assert.equal(query.car_id, 2);
      assert.equal(query.date_from, '2026-03-01');
      pages.push(query.page);
      return { data: query.page === 1 ? [nextDay] : [later, earlier, anchor], meta: { pages: 2 } };
    },
  }, { after_trip_id: 10, car_id: 7 }, tool.name);
  assert.deepEqual(pages, [1, 2]);
  assert.equal(preview.preview.count, 2);
  assert.deepEqual(preview.preview.items.map(({ id }) => id), [9, 8]);
});
