/**
 * Jediné místo, které ví, jak spolu souvisí agendy: ze zapisujícího API požadavku
 * odvodí dotčená data a pro adresu okna pozná, zda je změna zajímá a jak se smí obnovit.
 *
 * Mapa je záměrně hrubá (agendy, ne konkrétní záznamy). Faktura se váže na proformu,
 * dobropis nebo úhradu jiného dokladu, takže shoda podle id by obnovu občas vynechala.
 * Skrytá okna se obnovují až při zobrazení, takže hrubost nestojí skoro nic.
 */
export type DataArea =
  | 'sales' | 'purchases' | 'recurring' | 'clients' | 'projects' | 'crm'
  | 'bank' | 'cash' | 'eet' | 'ledger' | 'assets' | 'other-items' | 'tax'
  | 'stock' | 'eshop' | 'documents' | 'payroll' | 'property' | 'logbook' | 'settings'

export type AreaSet = readonly DataArea[] | 'all'

/**
 * `auto` = stránka jen zobrazuje data, smí se znovu načíst sama.
 * `manual` = formulář, průvodce nebo výsledek akce drží stav, který nejde z adresy obnovit.
 * Okno dostane jen značku a o obnově rozhodne uživatel.
 */
export type WindowRefreshMode = 'auto' | 'manual'

export interface WindowDataProfile {
  reads: AreaSet
  mode: WindowRefreshMode
}

/** Co se kromě zapsané agendy změní. Jen jedna úroveň: tranzitivně by souviselo všechno se vším. */
const EFFECTS: Record<DataArea, readonly DataArea[]> = {
  sales: ['clients', 'ledger', 'bank', 'cash', 'stock', 'projects', 'recurring', 'documents', 'tax', 'eet'],
  purchases: ['clients', 'ledger', 'bank', 'cash', 'stock', 'projects', 'documents', 'tax'],
  recurring: ['sales', 'clients'],
  clients: ['sales', 'purchases', 'recurring', 'crm', 'projects', 'bank'],
  projects: ['sales', 'purchases', 'ledger'],
  crm: ['clients'],
  bank: ['sales', 'purchases', 'clients', 'cash', 'ledger', 'other-items', 'payroll', 'property', 'tax'],
  cash: ['sales', 'purchases', 'clients', 'ledger', 'eet', 'tax'],
  eet: ['sales', 'cash'],
  ledger: ['sales', 'purchases', 'bank', 'cash', 'assets', 'other-items', 'payroll', 'stock', 'property', 'tax'],
  assets: ['ledger', 'tax'],
  'other-items': ['ledger', 'bank', 'tax'],
  tax: [],
  stock: ['sales', 'purchases', 'ledger', 'eshop'],
  eshop: ['stock', 'sales'],
  documents: ['sales', 'purchases', 'other-items'],
  payroll: ['ledger', 'bank', 'tax'],
  property: ['ledger', 'bank', 'sales', 'tax'],
  logbook: ['tax'],
  settings: [],
}

/** Zapisující metodou se volají i výpočty, které nic nemění. */
const NON_WRITE = /(?:^|[/-])(?:preview|dry-run|quote|test|lookup|estimate)(?:[/-]|$)|\/exports?$/

/** První shoda vyhrává, konkrétnější cesty proto stojí před obecnými. */
const MUTATION_RULES: readonly (readonly [RegExp, AreaSet])[] = [
  [/^\/(?:auth|user|license|mcp|ai|instance-admin)(?:\/|$)/, []],
  [/^\/accounting\/cash-/, ['cash']],
  [/^\/accounting\/(?:small-)?assets(?:\/|$)/, ['assets']],
  [/^\/accounting\/other-items(?:\/|$)/, ['other-items']],
  [/^\/accounting\/payroll(?:\/|$)/, ['payroll']],
  [/^\/accounting\/(?:bank-|gopay)/, ['bank', 'ledger']],
  [/^\/(?:accounting|tax-evidence)(?:\/|$)/, ['ledger']],
  [/^\/invoices(?:\/|$)/, ['sales']],
  [/^\/recurring(?:\/|$)/, ['recurring']],
  [/^\/(?:portal\/)?purchase-invoice(?:s|-submissions|-approvals)(?:\/|$)/, ['purchases']],
  [/^\/clients(?:\/|$)/, ['clients']],
  [/^\/projects(?:\/|$)/, ['projects']],
  [/^\/work-report-billing-templates(?:\/|$)/, ['projects']],
  [/^\/crm(?:\/|$)/, ['crm']],
  [/^\/(?:bank|bank-statements|bank-transactions|bank-match-suggestions|payment-cards|credit-cards)(?:\/|$)/, ['bank']],
  [/^\/cash(?:\/|$)/, ['cash']],
  [/^\/eet(?:\/|$)/, ['eet']],
  [/^\/(?:stock|price-list-items)(?:\/|$)/, ['stock']],
  [/^\/(?:eshop|catalog)(?:\/|$)/, ['eshop']],
  [/^\/(?:portal\/)?(?:documents|document-folders|document-requests|scan-attach|attachment-checks)(?:\/|$)/, ['documents']],
  [/^\/payroll(?:\/|$)/, ['payroll']],
  [/^\/property(?:\/|$)/, ['property']],
  [/^\/logbook(?:\/|$)/, ['logbook']],
  [/^\/(?:tax|tax-return|reports)(?:\/|$)/, ['tax']],
  [/^\/(?:settings|suppliers|admin|codebooks|vat-classifications|revenue-categories|expense-categories)(?:\/|$)/, ['settings']],
]

const LEDGER_READ: readonly DataArea[] = ['ledger']
const REPORT_READ: readonly DataArea[] = ['sales', 'purchases', 'ledger', 'tax']
const NOTHING: readonly DataArea[] = []

type RouteRule = readonly [RegExp, AreaSet, WindowRefreshMode?]

/** Cesty bez úvodního lomítka a dotazu. První shoda vyhrává. */
const ROUTE_RULES: readonly RouteRule[] = [
  // Nový záznam ještě nečetl data žádného záznamu, načtené číselníky obnoví až nová stránka.
  [/(?:^|\/)new$/, NOTHING, 'manual'],
  [/^$/, 'all'],
  [/^invoices\/(?:export|import|ai-import)$/, NOTHING, 'manual'],
  [/^invoices\/\d+\/edit$/, ['sales', 'clients'], 'manual'],
  [/^invoices(?:\/\d+)?$/, ['sales']],
  [/^purchase-invoices\/(?:export|import|ai-import|renumber|payment-orders)$/, ['purchases'], 'manual'],
  [/^purchase-invoices\/\d+\/edit$/, ['purchases', 'clients'], 'manual'],
  [/^(?:purchase-invoices(?:\/\d+|\/incoming)?|purchase-approvals)$/, ['purchases']],
  [/^recurring\/\d+\/edit$/, ['recurring', 'clients'], 'manual'],
  [/^recurring(?:\/\d+)?$/, ['recurring']],
  [/^clients\/\d+\/edit$/, ['clients'], 'manual'],
  [/^clients(?:\/\d+)?$/, ['clients']],
  [/^projects\/\d+\/edit$/, ['projects'], 'manual'],
  [/^projects(?:\/\d+)?$/, ['projects']],
  [/^other-items\/\d+\/edit$/, ['other-items'], 'manual'],
  [/^other-items(?:\/\d+)?$/, ['other-items']],
  [/^documents\/scan-attach$/, ['documents'], 'manual'],
  [/^(?:documents(?:\/\d+)?|document-requests)$/, ['documents']],
  [/^crm$/, ['crm', 'clients']],
  [/^(?:bank|gopay|payment-cards|credit-cards)(?:\/\d+)?$/, ['bank']],
  [/^eet\/settings$/, ['settings'], 'manual'],
  [/^eet\/records/, ['eet']],
  [/^accounting\/cash(?:\/|$)/, ['cash']],
  [/^accounting\/(?:small-)?assets(?:\/|$)/, ['assets']],
  [/^accounting\/(?:journal|account-statement|general-ledger|trial-balance|balance-sheet|balance-inventory|income-statement|income-statement-by-function|saldo|offsets|monthly-report|monthly-check|document-completeness|manual-posting-queue|transition-report|dimension-profit|reports|archive|internal-documents)(?:\/|$)/, LEDGER_READ],
  [/^accounting(?:\/|$)/, LEDGER_READ, 'manual'],
  [/^tax-evidence\/(?:cash-journal|receivables-payables|tax-estimate)$/, LEDGER_READ],
  [/^tax-evidence(?:\/|$)/, ['settings'], 'manual'],
  [/^(?:stats|purchase-stats|group-stats|dimension-stats)$/, REPORT_READ],
  [/^reports\/(?:dph-book|cnb-rate-audit|invoice-series-completeness)$/, REPORT_READ],
  [/^reports(?:\/|$)/, REPORT_READ, 'manual'],
  [/^tax$/, REPORT_READ],
  [/^stock\/(?:documents|items|sales-orders|purchase-orders|takes|reports)$/, ['stock']],
  [/^stock(?:\/|$)/, ['stock'], 'manual'],
  [/^eshop(?:\/|$)/, ['eshop', 'stock'], 'manual'],
  [/^payroll(?:\/|$)/, ['payroll'], 'manual'],
  [/^property(?:\/|$)/, ['property'], 'manual'],
  [/^logbook$/, ['logbook'], 'manual'],
  [/^admin\/databox$/, 'all'],
  [/^admin\/approvals$/, ['purchases']],
  [/^admin\/work-report-billing-templates$/, ['projects'], 'manual'],
  [/^(?:admin|company)(?:\/|$)/, ['settings'], 'manual'],
  [/^portfolio$/, 'all'],
  [/^automation$/, 'all', 'manual'],
  [/^portal\/settings$/, ['settings'], 'manual'],
  [/^portal(?:\/|$)/, 'all', 'manual'],
  // Vlastní profil, licence a nástroje nečtou data dokladů.
  [/^(?:profile|activation|hosting|utilities|imports|exchange|templates|isds-gateway)(?:\/|$)/, NOTHING, 'manual'],
  // Nová záložka čeká na hledání; rozepsaný dotaz se nesmí přepsat obnovou.
  [/^new-tab$/, NOTHING, 'manual'],
]

function normalize(path: string): string {
  return path.split(/[?#]/, 1)[0]!.replace(/^\/api(?=\/)/, '').replace(/\/+$/, '')
}

/**
 * Agendy, které zápis na API cestě změnil, včetně jejich přímých vazeb.
 * Neznámá cesta změnila cokoli: obnovit víc je levnější než ukazovat stará data.
 */
export function changedAreas(apiPath: string): AreaSet {
  const path = normalize(apiPath)
  if (NON_WRITE.test(path)) return []
  const rule = MUTATION_RULES.find(([pattern]) => pattern.test(path))
  if (!rule) return 'all'
  const areas = rule[1]
  if (areas === 'all') return 'all'
  return [...new Set(areas.flatMap(area => [area, ...EFFECTS[area]]))]
}

/** Neznámá adresa čte cokoli a nesmí se načíst sama, protože může držet rozepsanou práci. */
export function windowDataProfile(fullPath: string): WindowDataProfile {
  const path = normalize(fullPath).replace(/^\//, '')
  const rule = ROUTE_RULES.find(([pattern]) => pattern.test(path))
  return rule ? { reads: rule[1], mode: rule[2] ?? 'auto' } : { reads: 'all', mode: 'manual' }
}

/** Zda je route pokrytá pravidlem. Slouží testu, který hlídá nové routy bez zařazení. */
export function hasWindowDataRule(fullPath: string): boolean {
  const path = normalize(fullPath).replace(/^\//, '')
  return ROUTE_RULES.some(([pattern]) => pattern.test(path))
}

export function isWindowAffected(fullPath: string, changed: AreaSet): boolean {
  const { reads } = windowDataProfile(fullPath)
  if (reads !== 'all' && reads.length === 0) return false
  if (changed === 'all') return true
  if (changed.length === 0) return false
  return reads === 'all' || reads.some(area => changed.includes(area))
}
