import assert from 'node:assert/strict';
import test from 'node:test';

import { ApiError, MyUctoClient } from '../src/client.mjs';

const client = () => new MyUctoClient({
  baseUrl: 'https://example.test/api/v1',
  token: 'test-token',
  maxRps: 0,
  maxConcurrent: 1,
  timeoutMs: 1000,
  version: 'test',
});

test('čtecí POST opakuje přechodnou chybu serveru', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return calls === 1
      ? new Response('', { status: 503 })
      : new Response(JSON.stringify({ items: [] }), { status: 200 });
  };

  try {
    const result = await client().postRead('/catalog/products/batch', { ids: [1] }, 'batch');
    assert.deepEqual(result, { items: [] });
    assert.equal(calls, 2);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('čtecí POST odmítne jinou než výslovně povolenou cestu', () => {
  assert.throws(
    () => client().postRead('/stock/items', { name: 'Test' }, 'batch'),
    /není pro cestu.*povolen/i,
  );
});

test('běžný POST neopakuje ani rate limit', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return new Response(JSON.stringify({ error: { code: 'temporary', message: 'Dočasná chyba' } }), {
      status: 429,
      headers: { 'Content-Type': 'application/json' },
    });
  };

  try {
    await assert.rejects(
      client().post('/stock/items', { name: 'Test' }, 'write'),
      (error) => error instanceof ApiError && error.status === 429,
    );
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
