/**
 * HTTP klient nad veřejným REST API MyÚčta (`/api/v1`).
 *
 * Tři věci, které tenhle klient řeší a proto nestačí holý fetch:
 *
 *  1) ŠETRNOST K SERVERU. API běží nad ostrou instancí a sdílí s ní PHP procesy —
 *     agent, který vystřelí 200 dotazů naráz, zpomalí i běžné uživatele. Držíme
 *     proto strop požadavků za sekundu i souběžných volání; přebytek čeká ve frontě.
 *
 *  2) IDENTIFIKACE V LOGU. Každý požadavek nese X-MyUcto-Client / -Client-Version /
 *     -Tool, takže je v aplikaci (API tokeny → Log volání) vidět, KTERÝ nástroj
 *     volání vyvolal, ne jen holá cesta.
 *
 *  3) ZÁMĚRNÉ OMEZENÍ ZÁPISŮ. Token se scope `read` odmítne zápis až server; my ho
 *     zastavíme dřív (MYUCTO_READ_ONLY), ať agent nedostane 403 uprostřed úlohy.
 *
 *  4) SOUBORY. Běžná volání jsou JSON. Stažení souboru (`download`) čte binární
 *     tělo se stropem velikosti a vrací base64, nahrání (`upload`) skládá
 *     multipart/form-data z base64 v paměti, nic se nezapisuje na disk.
 */

import { randomBytes } from 'node:crypto';

const DEFAULTS = {
  maxRps: 8,
  maxConcurrent: 3,
  timeoutMs: 30_000,
  maxRetries: 3,
  maxFileBytes: 10 * 1024 * 1024,
};

/**
 * Strop souboru v hostovaném MCP. Soubor tam putuje jako base64 v JSON zprávách
 * mezi PHP a Node, proto je nižší než u lokálního serveru. Musí odpovídat
 * `McpFileLimits::MAX_FILE_BYTES` v PHP (hlídá test).
 */
export const HOSTED_MAX_FILE_BYTES = 5 * 1024 * 1024;

/** Strop jedné JSON zprávy mostu, která nese base64 tělo. Zrcadlí `McpFileLimits::MAX_ENVELOPE_BYTES`. */
export const HOSTED_MAX_ENVELOPE_BYTES = 12 * 1024 * 1024;

const READ_POST_PATHS = new Set([
  '/catalog/products/batch',
  '/catalog/prices/batch',
  '/stock/items/quote',
  '/stock/intrastat/preview',
]);

export class ApiError extends Error {
  constructor(status, code, message, detail) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.detail = detail;
  }
}

export class ReadOnlyError extends Error {
  constructor(tool) {
    super(
      `Nástroj "${tool}" vyžaduje přístup pro čtení a zápis, ale server běží v režimu jen pro čtení. `
      + 'Pro lokální připojení vypněte MYUCTO_READ_ONLY a použijte token pro čtení a zápis.',
    );
    this.name = 'ReadOnlyError';
  }
}

/**
 * Token bucket na RPS + semafor na souběh. Obojí je nutné zvlášť: samotný strop
 * souběhu nezabrání dávce krátkých dotazů zahltit server, samotné RPS zase
 * nezabrání tomu, aby se nakupily dlouhé dotazy.
 */
class Throttle {
  constructor(maxRps, maxConcurrent) {
    this.minIntervalMs = maxRps > 0 ? 1000 / maxRps : 0;
    this.maxConcurrent = Math.max(1, maxConcurrent);
    this.active = 0;
    this.lastStart = 0;
    this.queue = [];
  }

  async run(fn) {
    await new Promise((resolve) => {
      this.queue.push(resolve);
      this.#pump();
    });
    try {
      return await fn();
    } finally {
      this.active -= 1;
      this.#pump();
    }
  }

  #pump() {
    if (this.active >= this.maxConcurrent || this.queue.length === 0) return;

    const wait = Math.max(0, this.lastStart + this.minIntervalMs - Date.now());
    if (wait > 0) {
      if (!this.pending) {
        this.pending = true;
        setTimeout(() => {
          this.pending = false;
          this.#pump();
        }, wait);
      }
      return;
    }

    this.active += 1;
    this.lastStart = Date.now();
    this.queue.shift()();
    this.#pump();
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Chyby ověření certifikátu. Typicky u lokální / testovací instance s certifikátem
 * od firemní autority — Node má vlastní seznam kořenových autorit a systémový
 * úložiště certifikátů ve výchozím stavu NEČTE, takže adresa, která v prohlížeči
 * funguje, tady spadne.
 */
const TLS_ERROR_CODES = new Set([
  'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
  'SELF_SIGNED_CERT_IN_CHAIN',
  'DEPTH_ZERO_SELF_SIGNED_CERT',
  'UNABLE_TO_GET_ISSUER_CERT',
  'UNABLE_TO_GET_ISSUER_CERT_LOCALLY',
  'CERT_HAS_EXPIRED',
  'ERR_TLS_CERT_ALTNAME_INVALID',
]);

function tlsHint(origin, detail) {
  return `Node nedůvěřuje HTTPS certifikátu serveru ${origin}: ${detail}\n`
    + 'Server se při startu pokusil načíst certifikační autority z operačního '
    + 'systému (diagnostika je na jeho chybovém výstupu za „TLS:"), takže root '
    + 'nainstalovaný v systému by měl stačit. Když to i tak selhává:\n'
    + '  1. ověřte, že certifikát serveru je vydaný autoritou nainstalovanou '
    + 'v systémovém úložišti — a že řetěz posílá i mezilehlé certifikáty '
    + '(„unable to verify the first certificate" typicky znamená chybějící mezičlánek);\n'
    + '  2. na Node starším než 22.15 přidejte NODE_OPTIONS=--use-system-ca, '
    + 'nebo NODE_EXTRA_CA_CERTS=/cesta/k/ca.pem;\n'
    + '  3. jen pro vývojovou instanci: MYUCTO_INSECURE_TLS=1 ověřování vypne. '
    + 'Na produkci to nepoužívejte.';
}

export class MyUctoClient {
  /**
   * @param {{baseUrl: string, token: string, supplierId?: string|number|null,
   *          readOnly?: boolean, maxRps?: number, maxConcurrent?: number,
   *          timeoutMs?: number, version: string}} cfg
   */
  constructor(cfg) {
    this.baseUrl = String(cfg.baseUrl).replace(/\/+$/, '');
    this.token = cfg.token;
    this.supplierId = cfg.supplierId ?? null;
    this.readOnly = Boolean(cfg.readOnly);
    this.version = cfg.version;
    this.fetcher = cfg.fetcher ?? fetch;
    this.timeoutMs = cfg.timeoutMs ?? DEFAULTS.timeoutMs;
    this.maxFileBytes = cfg.maxFileBytes ?? DEFAULTS.maxFileBytes;
    this.throttle = new Throttle(
      cfg.maxRps ?? DEFAULTS.maxRps,
      cfg.maxConcurrent ?? DEFAULTS.maxConcurrent,
    );
  }

  get(path, query, tool) {
    return this.request('GET', path, { query, tool });
  }

  post(path, body, tool) {
    return this.request('POST', path, { body, tool });
  }

  /**
   * Čtecí POST pro dávkové dotazy. Endpoint nic nemění, proto se může po
   * přechodném výpadku zopakovat stejně jako GET. Běžný `post` zůstává bez
   * retry, protože u zápisu není jisté, zda server požadavek už přijal.
   */
  postRead(path, body, tool) {
    if (!READ_POST_PATHS.has(path)) {
      throw new Error(`Čtecí POST není pro cestu "${path}" povolen.`);
    }
    return this.request('POST', path, { body, tool, retryRead: true });
  }

  put(path, body, tool) {
    return this.request('PUT', path, { body, tool });
  }

  /**
   * Částečná úprava — pošle jen předaná pole. Na rozdíl od `put` (celý objekt)
   * nemá vynechaný klíč význam „vynuluj", takže agent může změnit jednu hodnotu,
   * aniž by si musel načíst a poslat zpátky zbytek záznamu.
   */
  patch(path, body, tool) {
    return this.request('PATCH', path, { body, tool });
  }

  /**
   * `delete` je v JS rezervované slovo, takže metoda je `del`.
   *
   * Tělo se záměrně neposílá: mazací endpointy API ho nečtou a prázdný
   * `Content-Type: application/json` bez těla některé proxy odmítají.
   */
  del(path, tool, query) {
    return this.request('DELETE', path, { query, tool });
  }

  /**
   * Stáhne soubor. Vrací metadata a obsah v base64; nad `maxFileBytes`
   * skončí chybou `file_too_large` dřív, než se celé tělo načte do paměti.
   *
   * @returns {Promise<{filename: string|null, content_type: string, size: number, content_base64: string}>}
   */
  async download(path, query, tool) {
    const url = apiUrl(this.baseUrl, path);
    appendQuery(url.searchParams, query);
    const headers = this.#headers(tool, '*/*');
    return this.throttle.run(() => this.#send('GET', url, headers, undefined, false, 'binary'));
  }

  /**
   * Nahraje soubor jako multipart/form-data. Obsah přijde v base64 a do těla
   * požadavku se složí v paměti. Upload je zápis, takže se v režimu jen pro
   * čtení odmítne a po výpadku se neopakuje.
   *
   * @param {{content_base64: string, filename: string, content_type: string, field?: string}} file
   * @param {{fields?: Record<string, string|number|boolean|null|undefined>, method?: string}} [options]
   */
  async upload(path, file, tool, { fields = {}, method = 'POST' } = {}) {
    if (this.readOnly) throw new ReadOnlyError(tool);
    const bytes = decodeBase64(file.content_base64, this.maxFileBytes);
    const multipart = buildMultipart(fields, {
      field: file.field ?? 'file',
      filename: sanitizeFilename(file.filename),
      contentType: normalizeContentType(file.content_type),
      bytes,
    });
    const url = apiUrl(this.baseUrl, path);
    const headers = this.#headers(tool);
    headers['Content-Type'] = multipart.contentType;
    return this.throttle.run(() => this.#send(method, url, headers, multipart.body, false));
  }

  async request(method, path, { query, body, tool, retryRead = false } = {}) {
    const url = apiUrl(this.baseUrl, path);
    appendQuery(url.searchParams, query);

    const headers = this.#headers(tool);
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    return this.throttle.run(() => this.#send(method, url, headers, body, retryRead));
  }

  #headers(tool, accept = 'application/json') {
    const headers = {
      Authorization: `Bearer ${this.token}`,
      Accept: accept,
      'X-MyUcto-Client': 'mcp',
      'X-MyUcto-Client-Version': this.version,
    };
    if (tool) headers['X-MyUcto-Tool'] = tool;
    if (this.supplierId) headers['X-Supplier-Id'] = String(this.supplierId);
    return headers;
  }

  async #send(method, url, headers, body, retryRead, mode = 'json') {
    let lastError;
    const canRetry = method === 'GET' || retryRead;

    for (let attempt = 0; attempt <= DEFAULTS.maxRetries; attempt += 1) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);

      let response;
      try {
        response = await this.fetcher(url, {
          method,
          headers,
          body: body === undefined || body instanceof Uint8Array ? body : JSON.stringify(body),
          signal: controller.signal,
        });
      } catch (e) {
        clearTimeout(timer);

        // `fetch` zabaluje skutečnou příčinu do `cause`; samotné "fetch failed"
        // je bezcenné — u nedůvěryhodného certifikátu i u vypnutého serveru
        // vypadá stejně, takže se ladí naslepo.
        const cause = e.cause ?? {};
        const code = cause.code ?? e.code ?? '';
        const detail = cause.message ?? e.message;

        // Chyba certifikátu se opakováním nespraví — je to konfigurace, ne výpadek.
        if (TLS_ERROR_CODES.has(code) || /certificate|self.signed/i.test(detail)) {
          throw new ApiError(0, 'tls_error', tlsHint(url.origin, detail));
        }

        // Síťová chyba / timeout — zkusit znovu má smysl jen u čtení; opakovaný
        // POST by mohl vytvořit doklad dvakrát, protože nevíme, jestli server
        // požadavek přijal, nebo ne.
        lastError = new ApiError(
          0,
          'network_error',
          `Spojení s ${url.origin} selhalo: ${detail}${code ? ` (${code})` : ''}`,
        );
        if (!canRetry || attempt === DEFAULTS.maxRetries) throw lastError;
        await sleep(backoffMs(attempt));
        continue;
      }
      // Binární tělo se čte pod stejným časovým limitem jako hlavičky: velký
      // soubor po pomalé lince nesmí nástroj zablokovat bez konce.
      if (mode !== 'binary' || !response.ok) clearTimeout(timer);

      // 429 / 5xx = přechodné. Retry-After posílá server u rate limitu.
      if (response.status === 429 || response.status >= 500) {
        if (attempt < DEFAULTS.maxRetries && canRetry) {
          const retryAfter = Number(response.headers.get('Retry-After'));
          await sleep(Number.isFinite(retryAfter) && retryAfter > 0
            ? retryAfter * 1000
            : backoffMs(attempt));
          continue;
        }
      }

      if (mode === 'binary' && response.ok) {
        try {
          return await readFile(response, this.maxFileBytes);
        } catch (e) {
          if (e instanceof ApiError) throw e;
          throw new ApiError(0, 'network_error', `Stažení souboru z ${url.origin} selhalo: ${e.message}`);
        } finally {
          clearTimeout(timer);
        }
      }

      const text = await response.text();
      const payload = text ? safeJson(text) : null;

      if (!response.ok) {
        const err = payload?.error ?? {};
        throw new ApiError(
          response.status,
          err.code ?? String(response.status),
          err.message ?? `HTTP ${response.status}`,
          err.details ?? null,
        );
      }

      return payload;
    }

    throw lastError ?? new ApiError(0, 'unknown_error', 'Požadavek se nepodařilo dokončit.');
  }
}

function backoffMs(attempt) {
  // 400 / 800 / 1600 ms + jitter, ať se souběžné retry nesrovnají do špičky
  return 400 * 2 ** attempt + Math.floor(Math.random() * 200);
}

const megabytes = (bytes) => `${Math.round((bytes / (1024 * 1024)) * 10) / 10} MB`;

function fileTooLarge(maxBytes) {
  return new ApiError(
    413,
    'file_too_large',
    `Soubor je větší než ${megabytes(maxBytes)}, což je strop pro přenos souboru přes MCP. `
      + 'Stáhněte nebo nahrajte ho přímo v aplikaci.',
  );
}

/** Přípona → typ obsahu. Slouží, když server pošle obecný `application/octet-stream`. */
const EXTENSION_TYPES = {
  pdf: 'application/pdf',
  isdoc: 'application/xml',
  xml: 'application/xml',
  isdocx: 'application/zip',
  zip: 'application/zip',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  gif: 'image/gif',
  webp: 'image/webp',
  heic: 'image/heic',
  heif: 'image/heif',
  txt: 'text/plain',
  csv: 'text/csv',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ppt: 'application/vnd.ms-powerpoint',
  pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  odt: 'application/vnd.oasis.opendocument.text',
  ods: 'application/vnd.oasis.opendocument.spreadsheet',
  odp: 'application/vnd.oasis.opendocument.presentation',
};

export const extensionOf = (filename) => {
  const match = /\.([A-Za-z0-9]{1,10})$/.exec(String(filename ?? ''));
  return match ? match[1].toLowerCase() : '';
};

export const contentTypeForFilename = (filename) => EXTENSION_TYPES[extensionOf(filename)] ?? null;

/**
 * Načte binární tělo se stropem. Deklarovaná délka se kontroluje předem,
 * skutečná průběžně — hlavička může chybět nebo lhát.
 */
async function readFile(response, maxBytes) {
  const declared = Number(response.headers.get('Content-Length'));
  if (Number.isFinite(declared) && declared > maxBytes) {
    await response.body?.cancel().catch(() => {});
    throw fileTooLarge(maxBytes);
  }

  const chunks = [];
  let size = 0;
  if (response.body) {
    const reader = response.body.getReader();
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > maxBytes) {
        await reader.cancel().catch(() => {});
        throw fileTooLarge(maxBytes);
      }
      chunks.push(value);
    }
  }
  const bytes = Buffer.concat(chunks.map((chunk) => Buffer.from(chunk.buffer, chunk.byteOffset, chunk.byteLength)), size);

  const filename = filenameFromDisposition(response.headers.get('Content-Disposition'));
  let contentType = normalizeHeaderType(response.headers.get('Content-Type'));
  if (contentType === '' || contentType === 'application/octet-stream') {
    contentType = contentTypeForFilename(filename) ?? 'application/octet-stream';
  }

  return {
    filename,
    content_type: contentType,
    size,
    content_base64: bytes.toString('base64'),
  };
}

const normalizeHeaderType = (raw) => String(raw ?? '').split(';')[0].trim().toLowerCase();

/**
 * Název souboru z Content-Disposition. Aplikace posílá `filename="…"` se syrovým
 * UTF-8, které fetch přečte jako latin1 — bez zpětného převodu by se z
 * „Faktura č. 1.pdf" stal nečitelný název.
 */
export function filenameFromDisposition(header) {
  if (!header) return null;
  const extended = /filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/.exec(header);
  let name = null;
  if (extended) {
    try {
      name = decodeURIComponent(extended[1].trim());
    } catch {
      name = null;
    }
  }
  if (name === null) {
    const plain = /filename\s*=\s*(?:"([^"]*)"|([^;]+))/i.exec(header);
    if (!plain) return null;
    name = (plain[1] ?? plain[2] ?? '').trim();
    if (/[\u0080-ÿ]/.test(name) && !/[^\u0000-ÿ]/.test(name)) {
      const utf8 = Buffer.from(name, 'latin1').toString('utf8');
      if (!utf8.includes('�')) name = utf8;
    }
  }
  try {
    return sanitizeFilename(name);
  } catch {
    return null;
  }
}

/**
 * Název souboru bez cesty a bez znaků, které by rozbily hlavičku multipartu
 * nebo název na disku. Z „../../faktura.pdf" zůstane „faktura.pdf".
 */
export function sanitizeFilename(raw) {
  const base = String(raw ?? '').normalize('NFC').split(/[\\/]/).pop() ?? '';
  let name = base
    .replace(/[\u0000-\u001f\u007f"<>|*?:]/g, '_')
    .trim()
    .replace(/^[. _]+|[. _]+$/g, '');
  if (name === '') {
    throw new Error('Chybí platný název souboru (filename), například "faktura.pdf".');
  }
  if (Buffer.byteLength(name, 'utf8') > 200) {
    const ext = extensionOf(name);
    const stem = ext ? name.slice(0, -(ext.length + 1)) : name;
    let cut = stem;
    while (Buffer.byteLength(cut, 'utf8') > 190) cut = cut.slice(0, -1);
    name = ext ? `${cut}.${ext}` : cut;
  }
  return name;
}

/** Typ obsahu bez parametrů; cokoli s CR/LF nebo mimo tvar `typ/podtyp` se odmítne. */
export function normalizeContentType(raw) {
  const value = normalizeHeaderType(raw);
  if (!/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/.test(value)) {
    throw new Error(`Neplatný content_type "${String(raw ?? '')}".`);
  }
  return value;
}

/**
 * Přísné base64: jiný než standardní abeceda nebo špatné zarovnání se odmítne,
 * místo aby Buffer tiše zahodil neplatné znaky a nahrál poškozený soubor.
 * Prefix `data:…;base64,` se toleruje. Velikost se kontroluje před dekódováním.
 */
export function decodeBase64(input, maxBytes) {
  if (typeof input !== 'string') {
    throw new Error('content_base64 musí být text v base64.');
  }
  const clean = input.trim().replace(/^data:[^,]*;base64,/i, '').replace(/\s+/g, '');
  if (clean === '') {
    throw new Error('content_base64 je prázdný, soubor nemá žádný obsah.');
  }
  if (clean.length % 4 !== 0 || !/^[A-Za-z0-9+/]+={0,2}$/.test(clean)) {
    throw new Error('content_base64 není platné base64 (standardní abeceda, délka dělitelná čtyřmi).');
  }
  const padding = clean.endsWith('==') ? 2 : clean.endsWith('=') ? 1 : 0;
  if ((clean.length / 4) * 3 - padding > maxBytes) {
    throw fileTooLarge(maxBytes);
  }
  return Buffer.from(clean, 'base64');
}

/**
 * Tělo multipart/form-data. Hranice je náhodná a ověřuje se, že se v obsahu
 * nevyskytuje, takže ji soubor nemůže předčasně ukončit.
 */
export function buildMultipart(fields, { field, filename, contentType, bytes }) {
  if (!/^[A-Za-z0-9_[\]-]+$/.test(field)) {
    throw new Error(`Neplatné pole formuláře "${field}".`);
  }
  let boundary;
  do {
    boundary = `----MyUctoMcp${randomBytes(16).toString('hex')}`;
  } while (bytes.includes(boundary));

  const parts = [];
  for (const [name, value] of Object.entries(fields ?? {})) {
    if (value === undefined || value === null) continue;
    if (!/^[A-Za-z0-9_[\]-]+$/.test(name)) {
      throw new Error(`Neplatné pole formuláře "${name}".`);
    }
    const text = String(value);
    if (text.includes(boundary)) {
      throw new Error(`Hodnota pole "${name}" je neplatná.`);
    }
    parts.push(Buffer.from(
      `--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${text}\r\n`,
      'utf8',
    ));
  }
  parts.push(Buffer.from(
    `--${boundary}\r\nContent-Disposition: form-data; name="${field}"; filename="${filename}"\r\n`
      + `Content-Type: ${contentType}\r\n\r\n`,
    'utf8',
  ));
  parts.push(bytes);
  parts.push(Buffer.from(`\r\n--${boundary}--\r\n`, 'utf8'));

  return {
    body: Buffer.concat(parts),
    contentType: `multipart/form-data; boundary=${boundary}`,
  };
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
}

const DOT_SEGMENT = /^(?:\.|%2e){1,2}$/i;

/**
 * Adresa požadavku. Tečkové segmenty (i zakódované `%2e`) by `new URL()`
 * vyhodnotil a požadavek by mířil na jiný endpoint, než který nástroj sestavil;
 * takovou cestu proto odmítne dřív, než cokoli odejde.
 */
export function apiUrl(baseUrl, path) {
  const pathname = String(path).split(/[?#]/, 1)[0];
  if (pathname.split('/').some((part) => DOT_SEGMENT.test(part))) {
    throw new Error(`Neplatná cesta požadavku "${pathname}".`);
  }
  return new URL(baseUrl + path);
}

/**
 * Serializace query parametrů. Filtry seznamu faktur chodí v hranatých závorkách
 * (`filter[status]`), takže vnořený objekt rozbalíme do `klíč[podklíč]`.
 * Prázdné hodnoty se vynechávají — jinak by `filter[client_id]=` shodil validaci.
 */
function appendQuery(params, query, prefix) {
  if (!query) return;

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue;

    const name = prefix ? `${prefix}[${key}]` : key;

    if (Array.isArray(value)) {
      if (value.length > 0) params.append(name, value.join(','));
    } else if (typeof value === 'object') {
      appendQuery(params, value, name);
    } else if (typeof value === 'boolean') {
      if (value) params.append(name, '1');
    } else {
      params.append(name, String(value));
    }
  }
}
