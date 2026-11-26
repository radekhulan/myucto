import assert from 'node:assert/strict';
import test from 'node:test';
import { isFileResult } from '../../src/tool-result.mjs';
import { validateToolArguments } from '../../src/tool-args.mjs';
import { TOOLS_BY_NAME } from '../../src/tools.mjs';

export function testEndpoints(cases) {
  const response = { id: 71, filename: 'synteticky.pdf', content_type: 'application/pdf', size: 3, content_base64: 'UERG' };
  for (const [name, args, method, path, payload] of cases) {
    test(`${name}: ověří argumenty a volá správnou operaci API`, async () => {
      const tool = TOOLS_BY_NAME.get(name);
      assert.ok(tool, name);
      assert.equal(tool.write, !['GET', 'DOWNLOAD'].includes(method));
      assert.throws(() => validateToolArguments(tool, { ...args, neznama_volba: true }), /neznámý parametr/);
      for (const required of tool.inputSchema.required) {
        const { [required]: omitted, ...incomplete } = args;
        assert.throws(() => validateToolArguments(tool, incomplete), /chybí povinný parametr/);
      }
      const calls = [];
      const call = (verb, requestPath, data, toolName) => {
        calls.push([verb, requestPath, data, toolName]);
        return response;
      };
      const client = {
        get: (...values) => call('GET', ...values),
        post: (...values) => call('POST', ...values),
        put: (...values) => call('PUT', ...values),
        patch: (...values) => call('PATCH', ...values),
        del: (requestPath, toolName, query) => call('DELETE', requestPath, query, toolName),
        download: (...values) => call('DOWNLOAD', ...values),
      };
      if (args.confirm) {
        assert.equal(tool.destructive, true);
        const { confirm: unused, ...unconfirmed } = args;
        const previewClient = {
          ...client,
          get: (requestPath, query, toolName) => {
            call('GET', requestPath, query, toolName);
            if (requestPath === '/document-folders') return { folders: [{ id: 1, name: 'Složka' }] };
            if (requestPath === '/documents') return { documents: [] };
            if (requestPath === '/logbook/trips') return { data: [], meta: { pages: 0 } };
            if (requestPath.startsWith('/logbook/trips/')) return { id: 1, car_id: 1, trip_date: '2026-01-01' };
            if (requestPath.endsWith('/tracking-units')) return { data: [{ id: 1 }], meta: { pages: 1 } };
            if (requestPath.endsWith('/items')) return { items: [], pagination: { total: 0 } };
            if (['/logbook/odometer-gaps', '/logbook/odometer-readings', '/logbook/trip-templates',
              '/logbook/trip-categories', '/stock/item-templates'].includes(requestPath)) return [{ id: 1, reading_id: 1 }];
            return response;
          },
        };
        const preview = await tool.run(previewClient, validateToolArguments(tool, unconfirmed), name);
        assert.equal(preview.confirmed, false);
        assert.match(preview.message, /confirm: true/);
        assert.deepEqual(Object.fromEntries(preview.parameters.map(({ name, value }) => [name, value])), unconfirmed);
        assert.ok(calls.every(([verb, route, data]) => verb === 'GET'
          || (verb === 'POST' && route === '/logbook/fuelings/estimate-odometers' && data.dry_run === true)));
        calls.length = 0;
      }
      const result = await tool.run(client, validateToolArguments(tool, args), name);
      assert.deepEqual(calls, [[method, path, payload, name]]);
      if (method === 'DOWNLOAD') {
        assert.ok(isFileResult(result));
        assert.equal(result.meta.filename, response.filename);
      } else {
        assert.equal(result, response);
      }
    });
  }
}
