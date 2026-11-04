/** Zdůvodnění `api: false` u nástrojů, které se zatím na klientské doméně nenabízejí. */
export const NOT_IN_CLIENT_CATALOG = 'Dosud nepřevedeno do klientského katalogu.';

/** Ověří jedinou ručně spravovanou deklaraci operací přímo u nástroje. */
export function apiManifest(tools) {
  const manifest = {};
  const names = new Set();
  for (const tool of tools) {
    if (names.has(tool.name)) throw new Error(`Duplicitní nástroj: ${tool.name}`);
    names.add(tool.name);
    if (tool.api === false) {
      if (!tool.apiReason?.trim()) throw new Error(`${tool.name}: api: false vyžaduje zdůvodnění.`);
      continue;
    }
    if (!Array.isArray(tool.api) || tool.api.length === 0) {
      throw new Error(`${tool.name}: chybí deklarace API operací.`);
    }
    const operations = new Set();
    for (const operation of tool.api) {
      if (!Array.isArray(operation) || operation.length !== 2) throw new Error(`${tool.name}: neplatná API operace.`);
      const [method, path] = operation;
      if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
        || typeof path !== 'string' || !/^\/api\/(?:[a-z][a-z0-9-]*|\{[a-z_]+\})(?:\/(?:[a-z][a-z0-9-]*|\{[a-z_]+\}))*$/.test(path)) {
        throw new Error(`${tool.name}: neplatná metoda nebo šablona API cesty.`);
      }
      if (method !== 'GET' && tool.write !== true) throw new Error(`${tool.name}: ${method} vyžaduje write: true.`);
      const key = `${method} ${path}`;
      if (operations.has(key)) throw new Error(`${tool.name}: duplicitní operace ${key}.`);
      operations.add(key);
    }
    if (typeof tool.write !== 'boolean') throw new Error(`${tool.name}: chybí příznak write.`);
    manifest[tool.name] = { write: tool.write, api: tool.api };
  }
  return Object.fromEntries(Object.entries(manifest).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0));
}

export const serializeManifest = (tools) => `${JSON.stringify(apiManifest(tools), null, 2)}\n`;

/** V testech porovnáváme skutečnou cestu s deklarovanou šablonou, nikoli s ukázkovým ID. */
export function matchesApiPath(template, path) {
  const pattern = template.split(/(\{[a-z_]+\})/).map((part) => /^\{/.test(part)
    ? '[^/?#]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('');
  return new RegExp(`^${pattern}$`).test(path);
}
