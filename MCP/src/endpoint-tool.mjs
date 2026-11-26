import { download } from './file-tools.mjs';
import { custom, pick } from './tool-schema.mjs';
import { CONFIRM, seg } from './tool-shared.mjs';

export const previewRecord = (path, key, query = null) => async (c, a, tool) => {
  const rows = await c.get(path, query, tool);
  return rows.find((row) => Number(row.id) === a[key]) ?? null;
};

export function endpoint(name, title, description, path, {
  params = {}, query = {}, body = {}, required = [], method = 'GET', file = false, confirm = false, arrayBody, preview,
} = {}) {
  const pathKeys = Object.keys(params);
  const queryKeys = Object.keys(query);
  const bodyKeys = Object.keys(body);
  const fields = { ...params, ...query, ...body, ...(confirm ? { confirm: CONFIRM } : {}) };
  const mandatory = [...pathKeys, ...required];
  const url = (template, a) => template.replace(/\{([a-z_]+)\}/g, (_, key) => seg(a[key]));
  const queryFor = (a) => queryKeys.length === 0 ? null : Object.fromEntries(
    Object.entries(pick(a, queryKeys)).map(([key, value]) => [key, typeof value === 'boolean' ? Number(value) : value]),
  );
  if (file) {
    return download(name, title, description, fields, mandatory, (a) => url(path, a), {
      query: queryFor,
      uri: (a) => `myucto://api${url(path, a)}`,
      meta: (a) => pick(a, pathKeys),
    });
  }
  const write = method !== 'GET';
  return custom(name, title, description, fields, mandatory, write, async (c, a, tool) => {
    const target = url(path, a);
    if (confirm && a.confirm !== true) {
      return {
        confirmed: false,
        message: `${title}. Operace čeká na potvrzení. Ukažte náhled uživateli a po jeho souhlasu zopakujte volání s confirm: true.`,
        parameters: Object.entries(pick(a, [...pathKeys, ...queryKeys, ...bodyKeys])).map(([name, value]) => ({
          name, description: fields[name].description, value,
        })),
        ...(preview === undefined ? {} : {
          preview: typeof preview === 'string' ? await c.get(url(preview, a), null, tool) : await preview(c, a, tool),
        }),
      };
    }
    if (!write) return c.get(target, queryFor(a), tool);
    if (method === 'DELETE') return c.del(target, tool, queryFor(a));
    const payload = arrayBody ? a[arrayBody] : pick(a, bodyKeys);
    return c[method.toLowerCase()](target, payload, tool);
  }, confirm ? { destructive: true } : {});
}
