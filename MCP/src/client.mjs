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
 */

const DEFAULTS = {
  maxRps: 8,
  maxConcurrent: 3,
  timeoutMs: 30_000,
  maxRetries: 3,
};

const READ_POST_PATHS = new Set([
  '/catalog/products/batch',
  '/catalog/prices/batch',
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
      `Nástroj "${tool}" zapisuje data, ale server běží v režimu jen pro čtení `
      + '(MYUCTO_READ_ONLY=1). Zápis povolíte odebráním té proměnné — token musí mít scope "čtení a zápis".',
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
    this.timeoutMs = cfg.timeoutMs ?? DEFAULTS.timeoutMs;
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

  async request(method, path, { query, body, tool, retryRead = false } = {}) {
    const url = new URL(this.baseUrl + path);
    appendQuery(url.searchParams, query);

    const headers = {
      Authorization: `Bearer ${this.token}`,
      Accept: 'application/json',
      'X-MyUcto-Client': 'mcp',
      'X-MyUcto-Client-Version': this.version,
    };
    if (tool) headers['X-MyUcto-Tool'] = tool;
    if (this.supplierId) headers['X-Supplier-Id'] = String(this.supplierId);
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    return this.throttle.run(() => this.#send(method, url, headers, body, retryRead));
  }

  async #send(method, url, headers, body, retryRead) {
    let lastError;
    const canRetry = method === 'GET' || retryRead;

    for (let attempt = 0; attempt <= DEFAULTS.maxRetries; attempt += 1) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);

      let response;
      try {
        response = await fetch(url, {
          method,
          headers,
          body: body === undefined ? undefined : JSON.stringify(body),
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
      clearTimeout(timer);

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

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
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
