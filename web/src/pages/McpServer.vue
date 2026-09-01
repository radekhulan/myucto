<script setup lang="ts">
/**
 * MCP server — návod na zprovoznění, příklady dotazů a log volání.
 */
import { ref, computed, onMounted } from 'vue'
import { tokensApi, type ApiToken, type ApiLogEntry } from '@/api/tokens'
import { useToast } from '@/composables/useToast'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'

const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()

// V demu je stránka dostupná jako ukázka — čte se všechno, ale token vydat nelze
// (mutace zastaví DemoReadOnlyMiddleware). Bez téhle informace by uživatel
// zbytečně bloudil na stránce tokenů a narazil na obecnou chybu.
const isDemo = computed(() => auth.isDemo)

// API má stejný původ jako aplikace — návod tak ukazuje adresu, na kterou se
// uživatel právě dívá, a ne natvrdo zadanou produkční doménu.
const apiBase = `${window.location.origin}/api/v1`

// Server jde provozovat dvěma způsoby a liší se jen cestou, kterou dostane
// asistent. Přepínač mění cestu ve VŠECH konfiguracích najednou, ať ji uživatel
// nemusí přepisovat ručně — překlep v cestě je nejčastější důvod, proč server
// nenaběhne.
type Variant = 'bundle' | 'source'
const variant = ref<Variant>('bundle')

const PATHS: Record<Variant, { unix: string; win: string }> = {
  bundle: {
    unix: '/cesta/k/myucto.cz/MCP/dist/myucto-mcp.mjs',
    win: 'C:\\cesta\\k\\myucto.cz\\MCP\\dist\\myucto-mcp.mjs',
  },
  source: {
    unix: '/cesta/k/myucto.cz/MCP/src/index.mjs',
    win: 'C:\\cesta\\k\\myucto.cz\\MCP\\src\\index.mjs',
  },
}

const unixPath = computed(() => PATHS[variant.value].unix)
const winPathJson = computed(() => PATHS[variant.value].win.replace(/\\/g, '\\\\'))

// ── Podporovaní asistenti ────────────────────────────────────────────────────
// Server je lokální stdio proces, takže funguje všude, kde klient umí spustit
// příkaz. Webové rozhraní ChatGPT to neumí (potřebuje vzdálený MCP server přes
// HTTP) — místo mlžení to říkáme rovnou a nabízíme Codex CLI, který stdio umí.
type ClientKind = 'shell' | 'json' | 'toml'
interface McpClient {
  key: string
  label: string
  vendor: string
  kind: ClientKind
  path: string
  intro: string
  config: string
  note?: string
  warn?: string
}

const CLIENTS = computed<McpClient[]>(() => [
  {
    key: 'claude-code',
    label: 'Claude Code',
    vendor: 'Anthropic',
    kind: 'shell',
    path: 'CLI i desktopová aplikace — sdílejí konfiguraci',
    intro: 'Spusťte v terminálu jediný příkaz:',
    config: `claude mcp add myucto \\\n`
      + `  --env MYUCTO_API_URL=${apiBase} \\\n`
      + `  --env MYUCTO_API_TOKEN=mi_pat_vas_token \\\n`
      + `  -- node ${unixPath.value}`,
    note: 'Na Windows použijte cestu ve tvaru '
      + `${PATHS[variant.value].win} a příkaz napište na jeden řádek `
      + '(zpětná lomítka na konci řádku jsou syntaxe unixového shellu).',
  },
  {
    key: 'claude-desktop',
    label: 'Claude Desktop',
    vendor: 'Anthropic',
    kind: 'json',
    path: 'Settings → Developer → Edit Config (claude_desktop_config.json)',
    intro: 'Do konfiguračního souboru přidejte:',
    config: `{
  "mcpServers": {
    "myucto": {
      "command": "node",
      "args": ["${winPathJson.value}"],
      "env": {
        "MYUCTO_API_URL": "${apiBase}",
        "MYUCTO_API_TOKEN": "mi_pat_vas_token"
      }
    }
  }
}`,
    note: 'Umístění souboru: Windows %APPDATA%\\Claude\\claude_desktop_config.json, '
      + 'macOS ~/Library/Application Support/Claude/claude_desktop_config.json. '
      + 'Po uložení aplikaci restartujte.',
  },
  {
    key: 'codex',
    label: 'ChatGPT (Codex CLI)',
    vendor: 'OpenAI',
    kind: 'toml',
    path: '~/.codex/config.toml',
    intro: 'Do konfigurace Codexu přidejte sekci:',
    config: `[mcp_servers.myucto]
command = "node"
args = ["${winPathJson.value}"]

[mcp_servers.myucto.env]
MYUCTO_API_URL = "${apiBase}"
MYUCTO_API_TOKEN = "mi_pat_vas_token"`,
    warn: 'Webový a desktopový ChatGPT umí připojit jen VZDÁLENÉ MCP servery přes HTTP — '
      + 'tenhle server je lokální proces, takže se do nich napojit nedá. '
      + 'Pro práci s daty MyÚčta z prostředí OpenAI použijte Codex CLI, '
      + 'nebo si server vystavte vlastním HTTP mostem.',
  },
  {
    key: 'gemini',
    label: 'Gemini CLI',
    vendor: 'Google',
    kind: 'json',
    path: '~/.gemini/settings.json',
    intro: 'Do nastavení Gemini CLI přidejte:',
    config: `{
  "mcpServers": {
    "myucto": {
      "command": "node",
      "args": ["${winPathJson.value}"],
      "env": {
        "MYUCTO_API_URL": "${apiBase}",
        "MYUCTO_API_TOKEN": "mi_pat_vas_token"
      }
    }
  }
}`,
    note: 'Konfigurace jde uložit i per-projekt do .gemini/settings.json. '
      + 'Po změně Gemini CLI restartujte a ověřte příkazem /mcp.',
  },
  {
    key: 'vscode',
    label: 'VS Code (Copilot)',
    vendor: 'Microsoft',
    kind: 'json',
    path: '.vscode/mcp.json v projektu',
    intro: 'Vytvořte v projektu soubor .vscode/mcp.json:',
    config: `{
  "servers": {
    "myucto": {
      "type": "stdio",
      "command": "node",
      "args": ["${winPathJson.value}"],
      "env": {
        "MYUCTO_API_URL": "${apiBase}",
        "MYUCTO_API_TOKEN": "mi_pat_vas_token"
      }
    }
  }
}`,
    note: 'Nástroje se objeví v režimu Agent po kliknutí na ikonu nástrojů. '
      + 'Token nedávejte do souboru, který commitujete do gitu.',
  },
  {
    key: 'cursor',
    label: 'Cursor',
    vendor: 'Anysphere',
    kind: 'json',
    path: '.cursor/mcp.json (projekt) nebo ~/.cursor/mcp.json (globálně)',
    intro: 'Do konfigurace Cursoru přidejte:',
    config: `{
  "mcpServers": {
    "myucto": {
      "command": "node",
      "args": ["${winPathJson.value}"],
      "env": {
        "MYUCTO_API_URL": "${apiBase}",
        "MYUCTO_API_TOKEN": "mi_pat_vas_token"
      }
    }
  }
}`,
    note: 'Stav připojení uvidíte v Settings → MCP.',
  },
])

const selected = ref<string>("claude-code")
const client = computed(() => CLIENTS.value.find((c) => c.key === selected.value) ?? CLIENTS.value[0])

const KIND_LABEL: Record<ClientKind, string> = {
  shell: 'příkaz v terminálu',
  json: 'soubor JSON',
  toml: 'soubor TOML',
}

// ── Log volání ───────────────────────────────────────────────────────────────
const tokens = ref<ApiToken[]>([])
const entries = ref<ApiLogEntry[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref('')

const PER_PAGE = 40
const page = ref(1)
const filter = ref({ token_id: 0, method: '', route: '', client: '', only_errors: false })

const pages = computed(() => Math.max(1, Math.ceil(total.value / PER_PAGE)))
const hasFilter = computed(() =>
  filter.value.token_id > 0
  || filter.value.method !== ''
  || filter.value.route !== ''
  || filter.value.client !== ''
  || filter.value.only_errors,
)

const activeTokens = computed(() => tokens.value.filter((t) => !t.is_revoked && !t.is_expired))
const hasMcpTraffic = computed(() => entries.value.some((e) => e.client === 'mcp'))

async function loadLog() {
  loading.value = true
  error.value = ''
  try {
    const res = await tokensApi.log({
      token_id: filter.value.token_id || undefined,
      method: filter.value.method || undefined,
      route: filter.value.route || undefined,
      client: filter.value.client || undefined,
      only_errors: filter.value.only_errors || undefined,
      limit: PER_PAGE,
      offset: (page.value - 1) * PER_PAGE,
    })
    entries.value = res.entries
    total.value = res.total
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || 'Log se nepodařilo načíst.'
  } finally {
    loading.value = false
  }
}

async function loadTokens() {
  try {
    tokens.value = await tokensApi.list()
  } catch {
    /* výpis tokenů je jen pro filtr — bez něj stránka pořád funguje */
  }
}

function applyFilter() {
  page.value = 1
  loadLog()
}

function resetFilter() {
  filter.value = { token_id: 0, method: '', route: '', client: '', only_errors: false }
  applyFilter()
}

function goTo(n: number) {
  if (n < 1 || n > pages.value || n === page.value) return
  page.value = n
  loadLog()
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Zkopírováno do schránky.')
  } catch {
    /* uživatel může označit a zkopírovat ručně */
  }
}

function statusClass(status: number): string {
  if (status >= 500) return 'bg-danger-50 text-danger-500'
  if (status >= 400) return 'bg-warning-50 text-warning-600'
  return 'bg-success-50 text-success-600'
}

function fmtTime(ts: string): string {
  return ts.replace('T', ' ').slice(0, 19)
}

const EXAMPLE_GROUPS = computed(() => [
  {
    title: 'Fakturace a pohledávky',
    items: [
      { q: 'Které faktury jsou po splatnosti a kdo nám dluží nejvíc?', tool: 'list_unpaid_invoices' },
      { q: 'Najdi fakturu pro ACME z června a ukaž, jestli je zaplacená.', tool: 'search + get_invoice' },
      { q: 'Jak staré jsou naše pohledávky? Rozpad do pásem po splatnosti.', tool: 'aging_receivables' },
      { q: 'Vystav fakturu firmě ACME na 10 hodin konzultací po 1 500 Kč.', tool: 'create_invoice (token čtení a zápis)' },
    ],
  },
  {
    title: 'Odběratelé',
    items: [
      { q: 'Založ klienta podle IČO 45274649.', tool: 'create_client (údaje z ARES)' },
      { q: 'Najdi v ARES firmu s IČO 12345678 a ukaž mi její adresu.', tool: 'lookup_company_in_ares' },
      { q: 'Uprav Prazdroji telefon na +420 123 456 789.', tool: 'update_client' },
      { q: 'Přenačti údaje ACME z ARES, přestěhovali se.', tool: 'update_client (refresh_from_ares)' },
    ],
  },
  {
    title: 'Výkazy práce a materiálu',
    items: [
      { q: 'Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.', tool: 'add_work_report_entry' },
      { q: 'Kolik hodin je zatím ve výkazu na téhle faktuře?', tool: 'get_work_report' },
      { q: 'Přidej do výkazu 5 metrů kabeláže po 120 Kč.', tool: 'add_work_report_material' },
      { q: 'Smaž poslední řádek z výkazu, zadal jsem ho omylem.', tool: 'remove_work_report_entry' },
      { q: 'Jakou máme hodinovou sazbu na zakázce pro Prazdroj?', tool: 'list_projects' },
    ],
  },
  {
    title: t('mcp_server_page.examples.operations.title'),
    items: [
      { q: t('mcp_server_page.examples.operations.create_project'), tool: 'save_project' },
      { q: t('mcp_server_page.examples.operations.profitability'), tool: 'project_profitability' },
      { q: t('mcp_server_page.examples.operations.search_documents'), tool: 'search_documents + get_document' },
      { q: t('mcp_server_page.examples.operations.link_document'), tool: 'link_document' },
      { q: t('mcp_server_page.examples.operations.add_trip'), tool: 'list_logbook_trip_categories + save_logbook_trip' },
      { q: t('mcp_server_page.examples.operations.add_fueling'), tool: 'save_logbook_fueling' },
    ],
  },
  {
    title: 'Daně',
    items: [
      { q: 'Kolik letos v červenci zaplatíme na DPH?', tool: 'vat_return_preview' },
      { q: 'Jak vychází DPH za tenhle kvartál a co se ještě změní z konceptů?', tool: 'vat_return_preview + vat_drafts_prediction' },
      { q: 'Ukaž vývoj odvedeného DPH za posledních 12 měsíců.', tool: 'vat_trend' },
      { q: 'Z jakých dokladů se skládá DPH za červen?', tool: 'vat_ledger' },
      { q: 'Kolik letos odvedeme na dani z příjmů a jak jsme na tom se zálohami?', tool: 'income_tax_analysis' },
      { q: 'Jaké daňové termíny mě čekají?', tool: 'tax_calendar' },
    ],
  },
  {
    title: 'Účetnictví (jen čtení)',
    items: [
      { q: 'Ukaž obratovou předvahu za letošní období.', tool: 'list_accounting_periods + trial_balance' },
      { q: 'Jaký je hospodářský výsledek podle výsledovky?', tool: 'income_statement' },
      { q: 'Jak se zaúčtovala faktura číslo 2026001?', tool: 'list_journal_entries' },
      { q: 'Co visí v saldu — komu jsme nespárovali platby?', tool: 'saldo' },
      { q: 'Z čeho se skládá zůstatek účtu 311?', tool: 'chart_of_accounts + account_statement' },
    ],
  },
  {
    title: 'Statistika a byznys',
    items: [
      { q: 'Ukaž trend obratu a zisku po měsících za poslední rok.', tool: 'revenue_monthly' },
      { q: 'Kde nám utíkají peníze — rozpad nákladů podle kategorií.', tool: 'expense_breakdown' },
      { q: 'Jak moc jsme závislí na největších zákaznících?', tool: 'client_concentration' },
      { q: 'Jaký je výhled cash flow na příští týdny?', tool: 'cash_flow_forecast' },
      { q: 'Kdo platí nejhůř a má smysl posílat upomínky?', tool: 'payment_punctuality + reminder_effectiveness' },
      { q: 'Kteří zákazníci nám přestali objednávat?', tool: 'churn_risk' },
      { q: 'Co bych měl dneska řešit?', tool: 'action_items' },
    ],
  },
  {
    title: 'E-shop a sklad',
    items: [
      { q: 'Které zboží je pod minimální zásobou a mělo by se doobjednat?', tool: 'stock_levels' },
      { q: 'Najdi zboží podle kódu a ukaž jeho cenu a dostupnost.', tool: 'search_products + stock_availability' },
      { q: 'Kolik máme uloženo ve skladu k dnešnímu dni?', tool: 'stock_valuation' },
    ],
  },
  {
    title: t('mcp_server_page.examples.payroll.title'),
    items: [
      { q: t('mcp_server_page.examples.payroll.agreed_salary'), tool: 'list_payroll_people + get_payroll_person' },
      { q: t('mcp_server_page.examples.payroll.net_salary'), tool: 'get_payroll_salary_result' },
      { q: t('mcp_server_page.examples.payroll.change_salary'), tool: 'get_payroll_person + change_payroll_salary' },
      { q: t('mcp_server_page.examples.payroll.sickness'), tool: 'list_payroll_average_earnings + create_payroll_absence' },
      { q: t('mcp_server_page.examples.payroll.overtime'), tool: 'get_payroll_time_month + save_payroll_time_entry' },
      { q: t('mcp_server_page.examples.payroll.bonus'), tool: 'list_payroll_components + create_payroll_input' },
    ],
  },
])

const TOOL_GROUPS = computed(() => [
  {
    title: 'Fakturace a pohledávky',
    tools: 'list_invoices, list_unpaid_invoices, get_invoice, list_invoice_payments, '
      + 'list_purchase_invoices, get_purchase_invoice, create_invoice, issue_invoice, '
      + 'send_invoice, mark_invoice_paid, send_invoice_reminder',
  },
  {
    title: 'Výkazy práce a materiálu',
    tools: 'get_work_report, add_work_report_entry, add_work_report_material, '
      + 'remove_work_report_entry, list_projects',
  },
  {
    title: t('mcp_server_page.tool_groups.projects'),
    tools: 'list_projects, get_project, project_stats, project_profitability, '
      + 'save_project, archive_project, delete_project',
  },
  {
    title: t('mcp_server_page.tool_groups.documents'),
    tools: 'list_documents, search_documents, get_document, list_entity_documents, '
      + 'update_document, link_document, unlink_document',
  },
  {
    title: t('mcp_server_page.tool_groups.logbook'),
    tools: 'list_logbook_cars, save_logbook_car, delete_logbook_car, '
      + 'list_logbook_trip_categories, list_logbook_trips, save_logbook_trip, '
      + 'delete_logbook_trip, list_logbook_fuelings, save_logbook_fueling, '
      + 'delete_logbook_fueling, logbook_summary',
  },
  {
    title: 'Daně — jen čtení',
    tools: 'vat_return_preview, vat_trend, vat_drafts_prediction, vat_control_statement_preview, '
      + 'vat_ledger, vat_summary_report_preview, income_tax_analysis, list_tax_submissions, tax_calendar',
  },
  {
    title: 'Účetnictví — jen čtení',
    tools: 'list_accounting_periods, trial_balance, balance_sheet, income_statement, general_ledger, '
      + 'account_statement, saldo, chart_of_accounts, list_journal_entries, get_journal_entry, cash_journal',
  },
  {
    title: 'Statistika a přehledy',
    tools: 'dashboard_summary, purchase_summary, revenue_overview, revenue_monthly, revenue_yearly, '
      + 'revenue_breakdown, expense_breakdown, top_clients, top_vendors, aging_receivables, aging_payables, '
      + 'cash_flow_forecast, payment_punctuality, payment_time_histogram, dso_dpo, client_concentration, '
      + 'churn_risk, late_payment_risk, reminder_effectiveness, action_items',
  },
  {
    title: 'E-shop a sklad',
    tools: 'search_products, list_products, get_product, get_product_prices, set_product_prices, '
      + 'list_categories, list_manufacturers, stock_levels, stock_availability, stock_valuation, list_warehouses',
  },
  {
    title: t('mcp_server_page.tool_groups.payroll'),
    tools: 'list_payroll_people, get_payroll_person, change_payroll_salary, list_payroll_components, '
      + 'list_payroll_inputs, create_payroll_input, update_payroll_input, list_payroll_runs, '
      + 'get_payroll_salary_result, get_payroll_time_month, save_payroll_time_entry, '
      + 'list_payroll_absences, list_payroll_average_earnings, create_payroll_absence',
  },
  {
    title: 'Odběratelé a ARES',
    tools: 'search_clients, get_client, create_client, update_client, lookup_company_in_ares',
  },
  {
    title: 'Hledání a číselníky',
    tools: 'search, list_vat_rates, list_suppliers, whoami',
  },
])

onMounted(() => {
  loadTokens()
  loadLog()
})
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">MCP server</h1>
      <p class="text-sm text-neutral-500 mt-0.5">
        {{ t('mcp_server_page.subtitle') }}
      </p>
    </div>

    <!-- Co to je -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm text-neutral-700">
      <p class="mb-2">
        <strong>MCP (Model Context Protocol)</strong> je otevřený standard, kterým se AI asistentovi
        zpřístupní data aplikace. Podporuje ho Claude, Gemini, Copilot i Codex. Po zprovoznění se
        ptáte běžnou češtinou — „kolik zaplatíme na DPH“, „kdo nám dluží“, „jaký byl loni zisk“ —
        a asistent si sám vybere správné nástroje a zavolá je přes veřejné API.
      </p>
      <p class="mb-2">
        Server pokrývá <strong>fakturaci včetně vystavování</strong>, <strong>správu
        odběratelů s napojením na ARES</strong>, <strong>výkazy práce a materiálu</strong>,
        <strong>přehled zaplacených a nezaplacených dokladů</strong>, <strong>daně</strong>,
        <strong>{{ t('mcp_server_page.capabilities.projects') }}</strong>,
        <strong>{{ t('mcp_server_page.capabilities.documents') }}</strong>,
        <strong>{{ t('mcp_server_page.capabilities.logbook') }}</strong>,
        <strong>účetnictví</strong>, <strong>statistiku</strong>, <strong>e-shop se skladem</strong>
        a <strong>{{ t('mcp_server_page.capabilities.payroll') }}</strong>.
      </p>
      <p class="mb-2">
        Řekneš třeba <em>„přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru“</em> —
        asistent dohledá koncept faktury dané zakázky, doplní hodinovou sazbu
        (zakázka → odběratel → výchozí sazba firmy) a řádek přidá, aniž by sáhl
        na ty stávající.
      </p>
      <p class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-warning-700">
        <strong>Účetnictví a daně jsou dostupné pouze ke čtení.</strong> Asistent si přečte odhad DPH,
        obratovku, rozvahu i saldo, ale zaúčtovat doklad, uzavřít období, zaevidovat opravu podle
        § 46 / § 74b ani odeslat podání na EPO nemůže — je to agenda s daňovou odpovědností, kde
        chyba znamená opravné podání. Zápis do účetnictví dělá člověk v aplikaci. Zákaz vynucuje
        server, ne jen tenhle nástroj: i token s právem zápisu dostane na takovou operaci odmítnutí.
        <br><strong>{{ t('mcp_server_page.payroll_boundary.title') }}</strong>
        {{ t('mcp_server_page.payroll_boundary.description') }}
      </p>
    </div>

    <div v-if="isDemo"
      class="rounded-lg border border-primary-600/40 bg-primary-50 px-4 py-3 mb-4 text-sm text-primary-700">
      <strong>Ukázkový režim.</strong> Stránka je tu celá k prohlédnutí — návod, přehled
      nástrojů i log volání. <strong>API token si v demu vydat nelze</strong>, protože
      demo nic nezapisuje; MCP server tedy nezprovozníte. Ve vlastní instalaci je postup
      přesně takový, jaký je popsaný níže.
    </div>

    <!-- Adresa API -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
      <div class="flex flex-wrap items-center gap-3">
        <div class="text-sm">
          <span class="text-neutral-500">Adresa API této instance:</span>
          <code class="ml-2 px-2 py-1 bg-neutral-100 rounded font-mono text-sm">{{ apiBase }}</code>
        </div>
        <button @click="copy(apiBase)"
          class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50 whitespace-nowrap">
          Kopírovat
        </button>
        <a href="/api/docs" target="_blank"
          class="h-9 px-3 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md whitespace-nowrap">
          Dokumentace API (Swagger) →
        </a>
      </div>
    </div>

    <!-- Instalace -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-3">Zprovoznění</h2>

      <ol class="space-y-4 text-sm text-neutral-700">
        <li class="flex gap-3">
          <span class="shrink-0 w-7 h-7 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">1</span>
          <div class="min-w-0">
            <strong>Vygenerujte API token.</strong>
            <template v-if="isDemo">
              Ve vlastní instalaci ho vydáte v <em>Firma → API tokeny</em> tlačítkem
              <em>Nový token</em>. Token se zobrazí jen jednou — hned si ho zkopírujete.
              <span class="text-neutral-500">V demu tenhle krok nejde dokončit.</span>
            </template>
            <template v-else>
            V <RouterLink to="/profile/api-tokens" class="text-primary-600 hover:underline">API tokenech</RouterLink>
            klikněte na <em>Nový token</em>. Token se zobrazí jen jednou — hned si ho zkopírujte.</template>
            Pro zkoušení volte rozsah <strong>čtení</strong>; <strong>čtení a zápis</strong>
            až tehdy, když má asistent opravdu vystavovat doklady nebo měnit ceny.
            <div class="mt-1 text-neutral-500">
              Doporučeně token rovnou omezte na svou IP adresu (sloupec <em>IP omezení</em> u tokenu).
            </div>
          </div>
        </li>

        <li class="flex gap-3">
          <span class="shrink-0 w-7 h-7 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">2</span>
          <div class="min-w-0">
            <strong>{{ t('mcp_server_page.install.prepare_title') }}</strong>
            {{ t('mcp_server_page.install.prebuilt_intro') }}
            <strong>{{ t('mcp_server_page.install.node_requirement') }}</strong>{{ t('mcp_server_page.install.node_reason') }}
            <div class="mt-1 text-neutral-500">
              {{ t('mcp_server_page.install.node_missing') }} Windows: <code class="px-1 bg-neutral-100 rounded font-mono">winget install --id OpenJS.NodeJS.LTS --exact</code>
              · macOS: <code class="px-1 bg-neutral-100 rounded font-mono">brew install node</code>
            </div>
            {{ t('mcp_server_page.install.choose_variant') }}

            <div class="mt-2 flex flex-wrap gap-1.5">
              <button @click="variant = 'bundle'"
                class="cursor-pointer px-3 py-1.5 rounded-md border text-sm font-medium whitespace-nowrap"
                :class="variant === 'bundle'
                  ? 'border-primary-600 bg-primary-600 text-white'
                  : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
                {{ t('mcp_server_page.install.bundle') }}
              </button>
              <button @click="variant = 'source'"
                class="cursor-pointer px-3 py-1.5 rounded-md border text-sm font-medium whitespace-nowrap"
                :class="variant === 'source'
                  ? 'border-primary-600 bg-primary-600 text-white'
                  : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
                {{ t('mcp_server_page.install.source') }}
              </button>
            </div>

            <div v-if="variant === 'bundle'" class="mt-2">
              <p>{{ t('mcp_server_page.install.bundle_intro') }}</p>
              <pre class="mt-1 bg-neutral-900 text-neutral-100 rounded-md p-3 overflow-x-auto text-xs font-mono">MCP/dist/myucto-mcp.mjs</pre>
              <p class="mt-1 text-neutral-500">
                {{ t('mcp_server_page.install.bundle_description') }}
              </p>
            </div>

            <div v-else class="mt-2">
              <p>Nainstalujte závislosti a spouštějte přímo ze složky projektu:</p>
              <pre class="mt-1 bg-neutral-900 text-neutral-100 rounded-md p-3 overflow-x-auto text-xs font-mono">cd MCP
npm install</pre>
              <p class="mt-1 text-neutral-500">
                Server pak běží z <code class="px-1 bg-neutral-100 rounded font-mono">MCP/src/index.mjs</code>
                a potřebuje vedle sebe <code class="px-1 bg-neutral-100 rounded font-mono">node_modules</code>.
                Vhodné při úpravách nástrojů.
              </p>
            </div>
          </div>
        </li>

        <li class="flex gap-3">
          <span class="shrink-0 w-7 h-7 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">3</span>
          <div class="min-w-0 w-full">
            <strong>Zaregistrujte ho u svého asistenta.</strong> Vyberte, kterého používáte:

            <div class="mt-2 flex flex-wrap gap-1.5">
              <button v-for="c in CLIENTS" :key="c.key" @click="selected = c.key"
                class="cursor-pointer px-3 py-1.5 rounded-md border text-sm font-medium whitespace-nowrap"
                :class="selected === c.key
                  ? 'border-primary-600 bg-primary-600 text-white'
                  : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
                {{ c.label }}
              </button>
            </div>

            <div class="mt-3 rounded-md border border-neutral-200 p-3">
              <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 mb-2">
                <span class="font-medium">{{ client.label }}</span>
                <span class="text-xs text-neutral-500">{{ client.vendor }} · {{ KIND_LABEL[client.kind] }}</span>
              </div>
              <div class="text-xs text-neutral-500 mb-2">
                Umístění: <code class="px-1 bg-neutral-100 rounded font-mono">{{ client.path }}</code>
              </div>

              <div v-if="client.warn"
                class="mb-2 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-xs text-warning-700">
                {{ client.warn }}
              </div>

              <p class="mb-1">{{ client.intro }}</p>
              <pre class="bg-neutral-900 text-neutral-100 rounded-md p-3 overflow-x-auto text-xs font-mono">{{ client.config }}</pre>
              <button @click="copy(client.config)"
                class="mt-1 cursor-pointer h-8 px-3 border border-neutral-300 rounded-md text-xs text-neutral-700 hover:bg-neutral-50">
                Kopírovat konfiguraci
              </button>

              <p v-if="client.note" class="mt-2 text-xs text-neutral-500">{{ client.note }}</p>
            </div>

            <div class="mt-2 text-neutral-500 text-xs">
              Adresa musí končit <code class="px-1 bg-neutral-100 rounded font-mono">/api/v1</code>,
              jinak server ohlásí chybnou konfiguraci a nenaběhne. Používáte jiného klienta?
              Server je běžný stdio MCP proces — spustí se příkazem
              <code class="px-1 bg-neutral-100 rounded font-mono">node {{ unixPath }}</code>
              a konfiguraci přebírá z proměnných prostředí (tabulka níže).
            </div>
          </div>
        </li>

        <li class="flex gap-3">
          <span class="shrink-0 w-7 h-7 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">4</span>
          <div class="min-w-0">
            <strong>Ověřte spojení.</strong> Napište asistentovi „ověř připojení k MyÚčtu“ —
            zavolá nástroj <code class="px-1 bg-neutral-100 rounded font-mono">whoami</code>
            a vrátí uživatele, roli a firmu. Volání se objeví níže v logu.
          </div>
        </li>
      </ol>
    </div>

    <!-- Nastavení -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-3">Proměnné prostředí</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-600 text-xs uppercase">
            <tr>
              <th class="text-left px-3 py-2">Proměnná</th>
              <th class="text-left px-3 py-2">Výchozí</th>
              <th class="text-left px-3 py-2">Význam</th>
            </tr>
          </thead>
          <tbody>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_API_URL</td>
              <td class="px-3 py-2 text-neutral-500">—</td>
              <td class="px-3 py-2">Povinné. Adresa API, musí končit <code>/api/v1</code>.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_API_TOKEN</td>
              <td class="px-3 py-2 text-neutral-500">—</td>
              <td class="px-3 py-2">Povinné. Token <code>mi_pat_…</code> z API tokenů.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_SUPPLIER_ID</td>
              <td class="px-3 py-2 text-neutral-500">—</td>
              <td class="px-3 py-2">Firma, se kterou se má pracovat. Jen u tokenů nevázaných na jednu firmu.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_READ_ONLY</td>
              <td class="px-3 py-2 font-mono text-xs">0</td>
              <td class="px-3 py-2">
                <code>1</code> = zápisové nástroje se asistentovi vůbec nenabídnou.
                Užitečná pojistka i u tokenu s právem zápisu.
              </td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_MAX_RPS</td>
              <td class="px-3 py-2 font-mono text-xs">8</td>
              <td class="px-3 py-2">Nejvýš tolik požadavků za sekundu.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_MAX_CONCURRENT</td>
              <td class="px-3 py-2 font-mono text-xs">3</td>
              <td class="px-3 py-2">Nejvýš tolik souběžných volání.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_TIMEOUT_MS</td>
              <td class="px-3 py-2 font-mono text-xs">30000</td>
              <td class="px-3 py-2">Timeout jednoho požadavku.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_SYSTEM_CA</td>
              <td class="px-3 py-2 font-mono text-xs">1</td>
              <td class="px-3 py-2">
                Načíst certifikační autority z operačního systému. <code>0</code> = nenačítat.
              </td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2 font-mono text-xs">MYUCTO_INSECURE_TLS</td>
              <td class="px-3 py-2 font-mono text-xs">0</td>
              <td class="px-3 py-2">
                <code>1</code> = vůbec neověřovat HTTPS certifikát.
                <strong>Jen pro vývojovou instanci</strong>, nikdy na produkci.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-neutral-500 mt-2">
        Stropy počtu požadavků nejsou kosmetika — API sdílí PHP procesy s běžícím webem,
        takže asistent bez omezení zpomalí i běžné uživatele. Přebytečná volání čekají
        ve frontě. Nezávisle na tom platí serverový limit u tokenu.
      </p>
    </div>

    <!-- Řešení problémů -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-3">Když to nefunguje</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-600 text-xs uppercase">
            <tr>
              <th class="text-left px-3 py-2">Projev</th>
              <th class="text-left px-3 py-2">Příčina a náprava</th>
            </tr>
          </thead>
          <tbody>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2">Asistent hlásí, že server neodpovídá</td>
              <td class="px-3 py-2">
                U vlastního nebo firemního certifikátu jde nejčastěji o nedůvěryhodný
                HTTPS certifikát. Server si autority z operačního systému načítá sám,
                ale řetěz musí být kompletní — chybějící mezilehlý certifikát se hlásí
                jako <code>unable to verify the first certificate</code>. Na vývojové
                instanci pomůže <code>MYUCTO_INSECURE_TLS=1</code>.
              </td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2"><code>401 invalid_token</code></td>
              <td class="px-3 py-2">Token je zrušený nebo expirovaný — vygenerujte nový.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2"><code>403 token_ip_forbidden</code></td>
              <td class="px-3 py-2">Token má omezení podle IP a tahle adresa mezi nimi není.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2"><code>403 insufficient_scope</code></td>
              <td class="px-3 py-2">Token má jen rozsah čtení, operace vyžaduje zápis.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2"><code>403 token_write_forbidden</code></td>
              <td class="px-3 py-2">{{ t('mcp_server_page.troubleshooting.write_forbidden') }}</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2"><code>403 stock_disabled</code></td>
              <td class="px-3 py-2">Skladový a e-shopový modul není pro tuhle firmu zapnutý.</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="px-3 py-2">Asistent nástroje nevidí</td>
              <td class="px-3 py-2">
                {{ t('mcp_server_page.troubleshooting.restart_prefix') }}
                <code>MCP/dist/myucto-mcp.mjs</code>.
                {{ t('mcp_server_page.troubleshooting.npm_prefix') }} <code>npm install</code>
                {{ t('mcp_server_page.troubleshooting.npm_suffix') }} <code>MCP/src/index.mjs</code>.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Příklady -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-1">Příklady dotazů</h2>
      <p class="text-sm text-neutral-500 mb-3">
        Ptejte se běžnou češtinou; nástroj v závorce si asistent vybere sám.
      </p>
      <div class="space-y-4">
        <div v-for="g in EXAMPLE_GROUPS" :key="g.title">
          <div class="font-medium text-neutral-700 text-sm mb-1">{{ g.title }}</div>
          <ul class="space-y-1.5 text-sm">
            <li v-for="(ex, i) in g.items" :key="i"
              class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-b border-neutral-200 last:border-0 pb-1.5 last:pb-0">
              <span class="text-neutral-700">„{{ ex.q }}“</span>
              <code class="text-xs text-neutral-500 font-mono">→ {{ ex.tool }}</code>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Nástroje -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="text-lg font-semibold mb-3">Dostupné nástroje</h2>
      <div class="space-y-3 text-sm">
        <div v-for="g in TOOL_GROUPS" :key="g.title">
          <div class="font-medium text-neutral-700">{{ g.title }}</div>
          <div class="text-xs text-neutral-500 font-mono break-words">{{ g.tools }}</div>
        </div>
      </div>
      <p class="text-xs text-neutral-500 mt-3">
        Nástroje respektují rozsah tokenu, jeho omezení podle IP i oprávnění role
        uživatele, pod kterým byl token vydán. Volání mimo veřejné API server odmítne.
      </p>
    </div>

    <!-- Log volání -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="p-5 pb-3">
        <h2 class="text-lg font-semibold">Log volání</h2>
        <p class="text-sm text-neutral-500 mt-0.5">
          Každé volání vašich API tokenů — včetně zamítnutých. U volání z MCP serveru
          je vidět i nástroj, který ho vyvolal.
        </p>
      </div>

      <div class="px-5 pb-3 flex flex-wrap items-end gap-2 text-sm">
        <label class="block">
          <span class="text-xs text-neutral-500">Token</span>
          <select v-model.number="filter.token_id" @change="applyFilter"
            class="mt-0.5 block h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option :value="0">— všechny —</option>
            <option v-for="tk in tokens" :key="tk.id" :value="tk.id">{{ tk.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs text-neutral-500">Metoda</span>
          <select v-model="filter.method" @change="applyFilter"
            class="mt-0.5 block h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">— všechny —</option>
            <option v-for="m in ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']" :key="m" :value="m">{{ m }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs text-neutral-500">Zdroj</span>
          <select v-model="filter.client" @change="applyFilter"
            class="mt-0.5 block h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">— vše —</option>
            <option value="mcp">jen MCP server</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs text-neutral-500">Cesta</span>
          <input v-model="filter.route" @keyup.enter="applyFilter" type="text" placeholder="např. invoices"
            class="mt-0.5 block h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="flex items-center gap-1.5 h-9">
          <input type="checkbox" v-model="filter.only_errors" @change="applyFilter" />
          <span>jen chyby</span>
        </label>
        <button @click="applyFilter"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md whitespace-nowrap">
          Filtrovat
        </button>
        <button v-if="hasFilter" @click="resetFilter"
          class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 whitespace-nowrap">
          Zrušit filtr
        </button>
      </div>

      <div v-if="error" class="mx-5 mb-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-600 text-xs uppercase">
            <tr>
              <th class="text-left px-3 py-2 whitespace-nowrap">Čas</th>
              <th class="text-left px-3 py-2">Token</th>
              <th class="text-left px-3 py-2">Nástroj</th>
              <th class="text-left px-3 py-2">Metoda</th>
              <th class="text-left px-3 py-2">Cesta</th>
              <th class="text-left px-3 py-2">Stav</th>
              <th class="text-right px-3 py-2">ms</th>
              <th class="text-left px-3 py-2">IP</th>
            </tr>
          </thead>
          <tbody>
            <!-- Texty natvrdo česky ze stejného důvodu jako zbytek stránky (viz docblock). -->
            <EmptyState v-if="!loading && entries.length === 0 && hasFilter" dense :colspan="8" variant="filtered"
              title="Žádné záznamy neodpovídají filtru"
              message="Zkuste jiné období, jiný token nebo filtr zrušte."
              cta="Zrušit filtr" @action="resetFilter" />
            <EmptyState v-else-if="!loading && entries.length === 0" dense :colspan="8" accent="neutral" icon="link"
              title="Zatím žádné volání API"
              message="Jakmile se přes MCP server ozve první klient, objeví se tu záznam o každém volání." />
            <tr v-for="e in entries" :key="e.id" class="border-t border-neutral-200 align-top">
              <td class="px-3 py-2 text-neutral-500 whitespace-nowrap font-mono text-xs">{{ fmtTime(e.ts) }}</td>
              <td class="px-3 py-2 text-neutral-600">{{ e.token_name || '—' }}</td>
              <td class="px-3 py-2">
                <code v-if="e.tool" class="text-xs font-mono">{{ e.tool }}</code>
                <span v-else class="text-neutral-400">—</span>
                <span v-if="e.client" class="ml-1 px-1.5 py-0.5 rounded bg-primary-100 text-primary-700 text-[10px] font-medium uppercase">
                  {{ e.client }}
                </span>
              </td>
              <td class="px-3 py-2 font-mono text-xs">{{ e.method }}</td>
              <td class="px-3 py-2">
                <code class="text-xs font-mono break-all">{{ e.route }}</code>
                <span v-if="e.query" class="block text-[11px] text-neutral-400 font-mono break-all">?{{ e.query }}</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusClass(e.status)">{{ e.status }}</span>
                <span v-if="e.error_code" class="block text-[11px] text-neutral-500 mt-0.5">{{ e.error_code }}</span>
              </td>
              <td class="px-3 py-2 text-right text-neutral-500 font-mono text-xs">{{ e.duration_ms }}</td>
              <td class="px-3 py-2 text-neutral-500 font-mono text-xs">{{ e.ip || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="total > 0" class="flex flex-wrap items-center gap-3 px-5 py-3 border-t border-neutral-200 text-xs text-neutral-500">
        <span>Celkem {{ total }} záznamů · strana {{ page }} / {{ pages }}</span>
        <div v-if="pages > 1" class="flex flex-wrap gap-1">
          <button @click="goTo(page - 1)" :disabled="page <= 1"
            class="cursor-pointer h-7 px-2 border border-neutral-300 rounded disabled:opacity-40 hover:bg-neutral-50">‹</button>
          <button @click="goTo(page + 1)" :disabled="page >= pages"
            class="cursor-pointer h-7 px-2 border border-neutral-300 rounded disabled:opacity-40 hover:bg-neutral-50">›</button>
        </div>
        <span v-if="!hasMcpTraffic && activeTokens.length > 0" class="text-neutral-400">
          Zatím žádné volání z MCP serveru — zkontrolujte krok 4 výše.
        </span>
      </div>
    </div>
  </div>
</template>
