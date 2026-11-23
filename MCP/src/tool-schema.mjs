/**
 * Stavební kameny schémat a čtecích nástrojů pro moduly mimo hlavní katalog
 * ({@see ./bank-tools.mjs}, {@see ./client-tools.mjs}, {@see ./accounting-read-tools.mjs}).
 *
 * Nástroje odsud mají `api: false` (staff katalog). Operace, které skutečně volají,
 * zaznamená generátor manifestu rout ze syntetických scénářů; parametr s výčtem,
 * který mění cestu, projde scénář pro každou hodnotu (`tool-recording.mjs`).
 */
import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';

export const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
export const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
export const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
export const bool = (description) => ({ type: 'boolean', description });
export const date = (description) => str(description, { format: 'date' });
export const id = (description) => int(description, { minimum: 1 });
export const ids = (description, extra = {}) => ({
  type: 'array', description, items: id('ID.'), minItems: 1, uniqueItems: true, ...extra,
});
export const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});
export const YEAR = int('Rok.', { minimum: 2000, maximum: 2100 });
export const MONTH = int('Měsíc 1 až 12.', { minimum: 1, maximum: 12 });
export const PAGING = {
  page: int('Stránka od 1.', { minimum: 1 }),
  per_page: int('Záznamů na stránce (1 až 100).', { minimum: 1, maximum: 100 }),
};

/** Jen zadané klíče; `aliases` přejmenuje parametr nástroje na parametr API. */
export const pick = (a, keys, aliases = {}) => Object.fromEntries(
  keys.filter((key) => a[key] !== undefined).map((key) => [aliases[key] ?? key, a[key]]),
);

const base = (name, title, description, inputSchema, write) => ({
  api: false, apiReason: NOT_IN_CLIENT_CATALOG, name, title, description, inputSchema, write,
});

/**
 * Čtecí nástroj nad jedním GET. `path` je text nebo funkce argumentů, `query` jsou
 * klíče předané jako query parametry.
 */
export const read = (name, title, description, properties, path, { query = [], required = [], aliases = {} } = {}) => ({
  ...base(name, title, description, schema(properties, required), false),
  run: (c, a, tool) => c.get(typeof path === 'function' ? path(a) : path, query.length ? pick(a, query, aliases) : null, tool),
});

/** Nástroj s vlastním během (víc volání, větvení podle parametru). */
export const custom = (name, title, description, properties, required, write, run, extra = {}) => ({
  ...base(name, title, description, schema(properties, required), write), ...extra, run,
});
