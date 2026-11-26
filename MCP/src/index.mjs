#!/usr/bin/env node
/**
 * MCP server pro MyÚčto.cz — stdio.
 *
 * Zpřístupní AI klientovi (Claude Code, Claude Desktop, IDE rozšíření) fakturaci,
 * zakázky, dokumenty, knihu jízd, e-shop se skladem a statistiku přes veřejné
 * REST API `/api/v1`. Účetní a daňová vrstva je dostupná jen ke čtení — viz
 * komentář v `tools.mjs`.
 *
 * Konfigurace přes proměnné prostředí:
 *   MYUCTO_API_URL         povinné, např. https://ucto.firma.cz/api/v1
 *   MYUCTO_API_TOKEN       povinné, token `mi_pat_…` z aplikace (API tokeny)
 *   MYUCTO_SUPPLIER_ID     volitelné, firma pro tokeny nevázané na jednu firmu
 *   MYUCTO_READ_ONLY       volitelné, "1" = zakázat všechny zápisové nástroje
 *   MYUCTO_MAX_RPS         volitelné, strop požadavků za sekundu (výchozí 8)
 *   MYUCTO_MAX_CONCURRENT  volitelné, souběžná volání (výchozí 3)
 *   MYUCTO_TIMEOUT_MS      volitelné, timeout požadavku (výchozí 30000)
 *   MYUCTO_MAX_FILE_MB     volitelné, strop staženého i nahraného souboru v MB (výchozí 10, nejvýš 50)
 *   MYUCTO_INSECURE_TLS    volitelné, "1" = nekontrolovat HTTPS certifikát (JEN vývoj)
 */

import tls from 'node:tls';

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import { MyUctoClient } from './client.mjs';
import { callLocalTool } from './local-call.mjs';
import { TOOLS, TOOLS_BY_NAME } from './tools.mjs';
import { UPDATE_TOOL, checkUpdate } from './update.mjs';
import { VERSION } from './version.mjs';

function readConfig(env) {
  const apiUrl = (env.MYUCTO_API_URL ?? '').trim();
  const token = (env.MYUCTO_API_TOKEN ?? '').trim();
  const problems = [];

  if (!apiUrl) {
    problems.push('MYUCTO_API_URL není nastavená (např. https://ucto.firma.cz/api/v1).');
  } else if (!/^https?:\/\//i.test(apiUrl)) {
    problems.push(`MYUCTO_API_URL musí začínat http:// nebo https:// — dostal jsem "${apiUrl}".`);
  } else if (!/\/api\/v1\/?$/.test(apiUrl)) {
    // Nejčastější chyba při zprovozňování: zadaná adresa aplikace místo API.
    // Server by pak volal neexistující cesty a vracel HTML místo JSON.
    problems.push(`MYUCTO_API_URL má končit "/api/v1" — dostal jsem "${apiUrl}".`);
  }

  if (!token) {
    problems.push('MYUCTO_API_TOKEN není nastavený (token vygenerujete v aplikaci: Nastavení firmy → API tokeny).');
  } else if (!token.startsWith('mi_pat_')) {
    problems.push('MYUCTO_API_TOKEN nevypadá jako token MyÚčta — má začínat "mi_pat_".');
  }

  if (problems.length > 0) {
    throw new Error(`Chybná konfigurace MCP serveru:\n  - ${problems.join('\n  - ')}`);
  }

  return {
    baseUrl: apiUrl,
    token,
    supplierId: (env.MYUCTO_SUPPLIER_ID ?? '').trim() || null,
    readOnly: isTrue(env.MYUCTO_READ_ONLY),
    maxRps: positiveNumber(env.MYUCTO_MAX_RPS, 8),
    maxConcurrent: positiveNumber(env.MYUCTO_MAX_CONCURRENT, 3),
    timeoutMs: positiveNumber(env.MYUCTO_TIMEOUT_MS, 30_000),
    maxFileBytes: Math.round(Math.min(positiveNumber(env.MYUCTO_MAX_FILE_MB, 10), 50) * 1024 * 1024),
    version: VERSION,
  };
}

function positiveNumber(raw, fallback) {
  const value = Number(raw);
  return Number.isFinite(value) && value > 0 ? value : fallback;
}

const isTrue = (raw) => ['1', 'true', 'yes'].includes(String(raw ?? '').toLowerCase());
const isFalse = (raw) => ['0', 'false', 'no'].includes(String(raw ?? '').toLowerCase());

/**
 * Přidá k důvěryhodným autoritám ty ze systémového úložiště.
 *
 * Node má vlastní zabudovaný seznam kořenových autorit a úložiště operačního
 * systému ve výchozím stavu NEČTE. Firemní nebo vlastnoručně vydaný certifikát
 * proto v prohlížeči projde (ten systému věří), ale tomuhle serveru spadne na
 * "unable to verify the first certificate" — a chyba přijde jako obyčejná
 * síťová, takže vypadá jako výpadek serveru.
 *
 * Node 22.15+ umí systémové autority načíst za běhu, takže to řešíme tady
 * a uživatel nemusí nic nastavovat. Na starším Nodu zbývá spustit proces
 * s `--use-system-ca`, případně `NODE_EXTRA_CA_CERTS`.
 *
 * @returns {string} popis výsledku pro diagnostický řádek na stderr
 */
function trustSystemCertificates(env) {
  if (isFalse(env.MYUCTO_SYSTEM_CA)) {
    return 'systémové certifikáty vypnuty (MYUCTO_SYSTEM_CA=0)';
  }
  if (typeof tls.getCACertificates !== 'function' || typeof tls.setDefaultCACertificates !== 'function') {
    return `systémové certifikáty nepodporuje ${process.version} (nutný Node 22.15+ nebo --use-system-ca)`;
  }

  try {
    const system = tls.getCACertificates('system');
    if (system.length === 0) {
      return 'systémové úložiště certifikátů je prázdné';
    }
    // Sjednocení, ne náhrada: zabudované autority musí zůstat, jinak by přestala
    // fungovat běžná veřejná HTTPS instance.
    const merged = new Set([...tls.getCACertificates('default'), ...system]);
    tls.setDefaultCACertificates([...merged]);
    return `+${system.length} systémových certifikátů`;
  } catch (e) {
    return `systémové certifikáty se nepodařilo načíst: ${e.message}`;
  }
}

async function main() {
  // Musí proběhnout PŘED prvním requestem, ne až při chybě — jinak by první
  // volání spadlo na certifikát a agent to ohlásil jako nedostupný server.
  const caStatus = trustSystemCertificates(process.env);

  if (isTrue(process.env.MYUCTO_INSECURE_TLS)) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
    process.stderr.write(
      'VAROVÁNÍ: MYUCTO_INSECURE_TLS=1 — ověřování HTTPS certifikátu je vypnuté. '
      + 'Používejte jen proti vývojové instanci.\n',
    );
  }

  let config;
  try {
    config = readConfig(process.env);
  } catch (e) {
    // Konfigurační chyba musí jít na stderr — stdout patří MCP protokolu.
    process.stderr.write(`${e.message}\n`);
    process.exit(1);
    return;
  }

  const client = new MyUctoClient(config);

  const server = new Server(
    { name: 'myucto', version: VERSION },
    { capabilities: { tools: {} } },
  );

  // V režimu jen pro čtení zápisové nástroje vůbec nenabízíme — model si tak
  // nenaplánuje postup, který stejně nedokáže dokončit.
  const allTools = [...TOOLS, UPDATE_TOOL];
  const toolsByName = new Map([...TOOLS_BY_NAME, [UPDATE_TOOL.name, UPDATE_TOOL]]);
  const exposed = config.readOnly ? allTools.filter((t) => !t.write) : allTools;

  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: exposed.map((t) => ({
      name: t.name,
      title: t.title,
      description: t.write && t.mutatesData !== false
        ? `${t.description}\n\n⚠️ Tento nástroj MĚNÍ DATA v ostré instanci.`
          + (t.destructive
            ? ' Jde o NEVRATNOU operaci — bez `confirm: true` se neprovede a jen se '
              + 'vypíše, čeho by se týkala.'
            : '')
        : t.description,
      inputSchema: t.inputSchema,
      annotations: {
        title: t.title,
        readOnlyHint: !t.write || t.mutatesData === false,
        // Mazání a storna se od běžného zápisu liší tím, že se nedají vzít
        // zpět — klient na ně umí upozornit jinak než na obyčejnou změnu.
        destructiveHint: Boolean(t.destructive),
        idempotentHint: !t.write || t.mutatesData === false,
        openWorldHint: true,
      },
    })),
  }));

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;
    return callLocalTool({ toolsByName, readOnly: config.readOnly, client }, name, args);
  });

  await server.connect(new StdioServerTransport());
  process.stderr.write(
    `MyÚčto MCP v${VERSION} připojen — ${exposed.length} nástrojů, API ${config.baseUrl}`
    + `${config.readOnly ? ' (jen pro čtení)' : ''}; TLS: ${caStatus}\n`,
  );
  void checkUpdate()
    .then((update) => {
      if (update.updateAvailable) {
        process.stderr.write(
          `Je dostupná aktualizace MyÚčto MCP v${update.current} -> v${update.latest}. `
          + `Stáhněte ${update.downloadUrl} a restartujte MCP server.\n`,
        );
      }
    })
    .catch(() => {});
}

main().catch((e) => {
  process.stderr.write(`MyÚčto MCP selhal: ${e?.stack ?? e}\n`);
  process.exit(1);
});
