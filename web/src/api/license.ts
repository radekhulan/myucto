import { api } from './client'

export type LicenseStateKind = 'trial' | 'trial_expired' | 'active' | 'overage' | 'degraded'

/** Kompaktní stav licence z /auth/me — pro bannery v layoutu. */
export interface LicenseSummary {
  state: LicenseStateKind
  tier: string | null
  trial_ends_at: number | null
  valid_until: number | null
  overage_deadline: number | null
  /** Doživotní licence — neomezená platnost (valid_until je jen TTL tokenu). */
  perpetual: boolean
  commercial_features: boolean
  /**
   * Odemyká placené moduly TARIF?
   *
   * ⚠️ Není to totéž co `commercial_features`. Klíč se vydává i na bezplatný
   * tarif — je to jediný kanál, kterým se instalace dozví o zaplacené kvótě
   * a stavu předplatného. Bez tohohle pole obrazovka nerozliší „licence
   * propadla, zaplaťte" od „tenhle tarif to nikdy neměl" a nabízí zaplatit
   * něco, co je zaplacené.
   */
  tier_commercial: boolean
  payroll_features?: boolean
  payroll_enabled?: boolean
  payroll_tier?: string | null
  payroll_max_employees?: number | null
  payroll_users_licensed?: number
  payroll_employees_active?: number
  payroll_users_active?: number
  /**
   * Proč nejde přidat dalšího uživatele na licencované místo:
   * `no_license` / `seat_limit` / `null`. Obrazovka správy uživatelů podle
   * toho varuje dřív, než admin vyplní celý formulář.
   */
  new_user_blocked: 'no_license' | 'seat_limit' | null
  /**
   * Stav předplatného na licenčním serveru, ne stav licence.
   *
   * Licence může být pořád platná, a přitom je zákazník po splatnosti: token
   * doběhne až na konci zaplaceného období. Bez toho by se výzva k úhradě
   * objevila teprve ve chvíli, kdy se komerční moduly zavřou.
   *
   * `null` = server stav neposlal (starší instalace, nebo se instalace ještě
   * nedovolala) — není to „zaplaceno".
   */
  subscription_state: string | null
  /** Počty pro overage banner — kolik aktivních vs. licencováno. */
  users_active: number
  users_licensed: number
  companies_active: number
  max_companies: number | null
}

/**
 * Stav předplatného za licencí (z licenčního serveru). `null` = licence se
 * automaticky neprodlužuje (trial, doživotní licence) nebo to server nehlásí.
 * Časy jsou unix timestampy.
 */
export interface SubscriptionInfo {
  state: 'active' | 'past_due' | 'cancelled' | 'expired'
  period: 'month' | 'year'
  /** Chystá se další stržení? */
  auto_renew: boolean
  next_charge_at: number | null
  cancelled_at: number | null
  valid_until: number | null
  /**
   * Dá se zrušené předplatné obnovit rovnou z aplikace?
   *
   * ⚠️ Rozhoduje o tom, jestli se tlačítko vůbec nabídne. Jakmile je hostovaná
   * instance vypnutá, je obnova ruční operace v retenční lhůtě a tlačítko by
   * vedlo jen na hlášku „ozvěte se podpoře". Starší server pole neposílá —
   * `undefined` proto znamená „nenabízet".
   */
  resumable?: boolean
  /** Přidělené předplatné za 0 Kč, fakturované mimo systém. Starší server pole neposílá. */
  comped?: boolean
}

/**
 * Obsazení místa spravované instalace.
 *
 * ⚠️ `null` znamená „nevím", NIKDY nulu:
 *  - `measured === false` → spotřeba se ještě neměřila; `usage_bytes` je null
 *    a obrazovka musí říct „zatím neměřeno", ne „0 %, vše v pořádku".
 *  - `quota_bytes === null` → neznáme zaplacený objem; pak se NEKRESLÍ pruh
 *    ani procenta, jen absolutní obsazení. Dělit něčím, co neznáme, znamená
 *    vymyslet si číslo.
 */
export interface ManagedStorageInfo {
  measured: boolean
  measured_at: string | null
  usage_bytes: number | null
  /** ZAPLACENÝ objem (ne disková kvóta hostingu, ta obsahuje rezervu na dumpy). */
  quota_bytes: number | null
  percent: number | null
  warn_percent: number
  read_only_percent: number
  /** Skutečný stav vynucení — instalace je právě teď jen pro čtení. */
  blocks_writes: boolean
  /**
   * Zaplacené rozšíření, které provozovatel ještě nezavedl.
   *
   * ⚠️ Dokud platí, obrazovka NESMÍ nabízet dokoupení znovu — zákazník už
   * zaplatil a druhé kliknutí by strhlo podruhé.
   */
  change_pending: boolean
  /** Objednaný objem v GB (může být větší než ten, proti kterému se dnes měří). */
  quota_gb_ordered: number | null
  /** Odkud se ví, kolik má zaplaceno. `none` = nevíme. */
  quota_source: 'license' | 'config' | 'none'
}

/**
 * Stav spravované instalace (SaaS). Přítomné POUZE ve spravovaném režimu —
 * na self-hosted instalaci klíč v odpovědi vůbec není a obrazovka aktivace
 * zůstává beze změny.
 */
export interface ManagedInstanceInfo {
  managed: true
  /** Kód tarifu provozu; null = neuvedeno. */
  plan: string | null
  /** Datum zřízení (ISO); null = neuvedeno. */
  managed_since: string | null
  /** Správa předplatného na webu; null = adresa není nakonfigurovaná → kontakt. */
  subscription_url: string | null
  storage: ManagedStorageInfo
  billing: ManagedBillingInfo
  links: ManagedLinks
  /** Chybí = starší licenční server; obrazovka sekci nekreslí. */
  performance?: ManagedPerformanceInfo
}

/** Objem dat celé instance, podle kterého se výkonnostní instance doporučí. */
export interface PerformanceUsage {
  invoices: number
  journal_entries: number
  recommended: boolean
  thresholds: { invoices: number; journal_entries: number }
}

export type PerformanceVendorState = 'off' | 'enable_requested' | 'on' | 'disable_requested'

/** Výkonnostní instance — stav od licenčního serveru a objem dat instance. */
export interface ManagedPerformanceInfo {
  enabled: boolean
  /** Vypnutí je naplánované ke konci zaplaceného období. */
  pending_disable: boolean
  /** Co už provedl poskytovatel hostingu. */
  vendor_state: PerformanceVendorState
  price_month_czk: number | null
  ram_mb: number | null
  php_processes: number | null
  base_ram_mb: number | null
  base_php_processes: number | null
  usage: PerformanceUsage
}

/**
 * Dunning stav — co dlužím, dokdy to jde zaplatit a kde.
 *
 * ⚠️ Jediná část licenčního API, kterou vidí i BĚŽNÝ admin (GET
 * `/api/license/billing`). Rozsah je proto úmyslně krátký: nikdy licenční
 * klíč, fakturační údaje ani počty míst. Rozšiřovat se dá jen vědomě
 * a na obou stranách (`BillingSnapshot::DUNNING_KEYS`).
 */
export interface BillingDunningInfo {
  /** Komerční moduly jsou zavřené / server hlásí nezaplacené předplatné. */
  unpaid: boolean
  license_state: LicenseStateKind
  /** Stav předplatného ze serveru; null = nehlásí ho (trial, doživotní). */
  subscription_state: SubscriptionInfo['state'] | null

  /**
   * ── V jaké fázi jsme a co se stane dál ──────────────────────────────────
   * Všechno počítá licenční server; instalace to jen podává dál.
   *
   * ⚠️ Nic z toho se NEDOPOČÍTÁVÁ. Termín, který si aplikace vymyslí, je slib,
   * který nikdo nedodrží — když server údaj neposlal, zůstane `null`
   * a obrazovka o termínu MLČÍ (viz `resolveBillingNarrative`).
   */
  phase: 'active' | 'past_due' | 'suspended' | 'expired' | 'cancelled' | string | null
  /** Kolikátý pokus o stržení karty selhal. */
  attempt: number | null
  max_attempts: number | null
  /** Kdy se karta zkusí znovu (unix). */
  next_attempt_at: number | null
  /** Kdy se pozastaví provoz instance (unix). */
  suspend_at: number | null
  /** Dokdy fungují placené funkce (unix). */
  access_until: number | null
  /** Dokdy po pozastavení držíme data (unix). */
  data_until: number | null

  /**
   * Dlužná částka. `null` = server ji neposlal a obrazovka o ní MLČÍ —
   * vymyšlené číslo u tlačítka „Zaplatit" je horší než žádné.
   */
  amount_due: number | null
  /** Měna dlužné částky; bez ní se částka needituje na koruny. */
  currency: string | null
  /**
   * Kam vede „Zaplatit". Podepsaný odkaz z licenčního serveru; když ho
   * neposlal, backend sem dosadí správu předplatného, takže tlačítko má
   * vždycky kam vést. `null` jen tehdy, když není nakonfigurovaná ani ta.
   */
  pay_url: string | null
}

/** Plný stav (ne)uhrazení pro obrazovku Hostingu — jen pro superadmina. */
export interface ManagedBillingInfo extends BillingDunningInfo {
  valid_until: number | null
  /** Kdy se instalace naposledy ptala serveru — bez toho „neuhrazeno" nelze číst. */
  last_check_at: string | null
  last_check_ok: boolean
}

/**
 * Adresy na myucto.cz z konfigurace (`license.server_url`). `null` = adresa
 * není nastavená a obrazovka odkaz NEKRESLÍ — mrtvý odkaz je horší než žádný.
 */
export interface ManagedLinks {
  subscription: string | null
  expand_storage: string | null
  support: string | null
  terms: string | null
  privacy: string | null
}

/** Plný stav licence z /api/license/status (admin). */
export interface LicenseStatus {
  state: LicenseStateKind
  instance_id: string
  /** Serverové prostředí. Debugovací UI smí být vidět pouze při hodnotě true. */
  development: boolean
  tier: string | null
  max_companies: number | null
  users_licensed: number
  users_active: number
  companies_active: number
  valid_until: number | null
  trial_ends_at: number | null
  overage_deadline: number | null
  /** Doživotní licence — neomezená platnost (valid_until je jen TTL tokenu). */
  perpetual: boolean
  commercial_features: boolean
  /** Odemyká placené moduly TARIF? Viz LicenseSummary.tier_commercial. */
  tier_commercial: boolean
  payroll_features: boolean
  payroll_enabled: boolean
  payroll_tier: string | null
  payroll_max_employees: number | null
  payroll_users_licensed: number
  payroll_employees_active: number
  payroll_users_active: number
  license_key_masked: string | null
  last_check_at: string | null
  last_check_ok: boolean
  buy_url: string
  /** Automatické prodlužování a datum dalšího stržení; null = neprodlužuje se. */
  subscription: SubscriptionInfo | null
  /** Fakturační údaje aktuální firmy — předvyplnění webového checkoutu (nevynucené). */
  company: LicenseCompany
  /** Jen spravovaná instalace; na self-hosted klíč v odpovědi není. */
  instance?: ManagedInstanceInfo
}

export interface LicenseCompany {
  name: string
  ic: string
  dic: string
  street: string
  city: string
  zip: string
  email: string
}

export interface DeactivateResult {
  transfers_remaining: number | null
  state: LicenseStatus
}

/** Výsledek vypnutí automatického prodlužování — licence běží dál do valid_until. */
export interface CancelRenewalResult {
  /** Předplatné už zrušené bylo (opakované volání) — pořád úspěch. */
  already_cancelled: boolean
  /** Konec zaplaceného období, do kterého licence poběží. */
  valid_until: number | null
  state: LicenseStatus
}

/**
 * Výsledek spuštěné obnovy zrušeného předplatného.
 *
 * ⚠️ Předplatné je v tenhle okamžik pořád zrušené — rozeběhne se, až zákazník
 * na `pay_url` zaplatí. Zrušením se zneplatnil mandát u brány, takže bez nové
 * karty by se příští obnova jen znovu nestrhla.
 */
export interface ResumeRenewalResult {
  /** Adresa platební brány, kde zákazník zadá kartu. */
  pay_url: string
  /** Konec dosud zaplaceného období. */
  valid_until: number | null
}

/** Kalkulace poměrného doplatku za in-place navýšení počtu uživatelů. */
export interface UpgradeQuote {
  current_users: number | null
  new_users: number
  amount: number | null
  currency: string | null
  period_end: number | string | null
  quote_token: string
  expires_at: number | string | null
  scheduled: boolean
  effective_at: number | string | null
}

export interface UpgradeResult {
  new_users: number
  amount_charged: number | null
  state: LicenseStatus
  scheduled: boolean
  effective_at: number | string | null
  pending: boolean
  order_id: string | null
}

/**
 * Kalkulace rozšíření úložiště. Nic se nestrhává — jen se počítá.
 *
 * ⚠️ Dvě čísla, a obě se musí ukázat: `amount` je JEDNORÁZOVÝ poměrný doplatek
 * do konce období, `recurring_delta` je o kolik se natrvalo zvedne pravidelná
 * platba. Ukázat jen jedno z nich znamená zamlčet polovinu ceny.
 */
export interface StorageQuote {
  current_quota_gb: number | null
  new_quota_gb: number
  amount: number | null
  recurring_delta: number | null
  currency: string | null
  period_end: number | string | null
  quote_token: string
  expires_at: number | string | null
  scheduled: boolean
  effective_at: number | string | null
}

/**
 * Výsledek rozšíření úložiště.
 *
 * ⚠️ `provisioning_pending: true` NENÍ chyba: zaplaceno, jen se kvóta
 * u provozovatele ještě nezvedla. Obrazovka v tu chvíli nesmí nabídnout nákup
 * znovu — druhé kliknutí by strhlo podruhé.
 */
export interface StorageUpgradeResult {
  new_quota_gb: number
  amount_charged: number | null
  provisioning_pending: boolean
  state: LicenseStatus
  scheduled: boolean
  effective_at: number | string | null
  pending: boolean
  order_id: string | null
}

/**
 * Kalkulace zapnutí/vypnutí výkonnostní instance. Zapnutí strhne `amount`
 * hned (poměrně do `period_end`), vypnutí platí od `effective_at` bez vratky.
 * `cancel_scheduled` = zapnutí jen ruší naplánované vypnutí, zdarma.
 */
export interface PerformanceQuote {
  current_enabled: boolean
  new_enabled: boolean
  pending_disable: boolean
  cancel_scheduled: boolean
  amount: number | null
  recurring_delta: number | null
  monthly_price: number | null
  currency: string | null
  period_end: number | string | null
  scheduled: boolean
  effective_at: number | string | null
  ram_mb: number | null
  php_processes: number | null
  base_ram_mb: number | null
  base_php_processes: number | null
  quote_token: string
  expires_at: number | string | null
  usage: PerformanceUsage
}

export interface PerformanceChangeResult {
  enabled: boolean
  pending_disable: boolean
  amount_charged: number | null
  scheduled: boolean
  effective_at: number | string | null
  pending: boolean
  order_id: string | null
  state: LicenseStatus
}

export interface TierQuote {
  current_tier: string
  new_tier: string
  amount: number | null
  recurring_delta: number | null
  currency: string | null
  period_end: number | string | null
  quote_token: string
  expires_at: number | string | null
  scheduled: boolean
  effective_at: number | string | null
  pending_target?: string | null
}

/**
 * Nabídka přechodu z měsíčního předplatného na roční.
 *
 * `amount` je CELÁ roční cena (účtuje se deset měsíců místo dvanácti), ne
 * doplatek: roční období se přilepí na konec už zaplaceného měsíce, takže se
 * zaplacené dny neztratí. `new_period_end` je platnost, kterou tím licence
 * dostane.
 */
export interface AnnualSwitchQuote {
  current_period: 'month'
  new_period: 'year'
  amount: number
  monthly_amount: number
  months_charged: number
  /** Kolik se rokem ušetří proti dvanácti měsíčním platbám. */
  saving: number
  currency: string | null
  period_end: number | string | null
  new_period_end: number | string | null
  quote_token: string
  expires_at: number | string | null
}

export interface AnnualSwitchResult {
  new_period: 'year'
  amount_charged: number | null
  valid_until: number | string | null
  pending: boolean
  order_id: string | null
  state: LicenseStatus
}

export interface TierChangeResult {
  new_tier: string
  amount_charged: number | null
  scheduled: boolean
  effective_at: number | string | null
  pending: boolean
  order_id: string | null
  state: LicenseStatus
}

export interface PayrollQuote {
  current_enabled: boolean
  new_enabled: boolean
  current_tier: string | null
  new_tier: string | null
  current_max_employees: number | null
  new_max_employees: number | null
  amount: number | null
  recurring_delta: number | null
  currency: string | null
  period_end: number | string | null
  quote_token: string
  expires_at: number | string | null
  scheduled: boolean
  effective_at: number | string | null
}

export interface PayrollChangeResult {
  enabled: boolean
  amount_charged: number | null
  scheduled: boolean
  effective_at: number | string | null
  pending: boolean
  order_id: string | null
  state: LicenseStatus
}

export interface ChangeStatusResult {
  order_id: string
  state: 'pending' | 'paid' | 'failed' | string
  applied: boolean
  license?: LicenseStatus
}

export interface PurchaseStartResult {
  buy_url: string
  expires_in: number
}

const PENDING_CHANGE_STORAGE_KEY = 'myucto.license.pending_change_orders'
const CHECKOUT_WAIT_MS = 10 * 60_000

interface PendingChangeOrder { id: string; checkoutUntil?: number }

function pendingOrders(): PendingChangeOrder[] {
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(PENDING_CHANGE_STORAGE_KEY) ?? '[]')
    if (!Array.isArray(parsed)) return []
    const orders: PendingChangeOrder[] = []
    for (const item of parsed) {
      if (typeof item === 'string' && item !== '') orders.push({ id: item })
      else if (Number.isSafeInteger(item) && item > 0) orders.push({ id: String(item) })
      else if (typeof item?.id === 'string' && item.id !== '' && Number.isFinite(item.checkoutUntil) && item.checkoutUntil > Date.now()) {
        orders.push({ id: item.id, checkoutUntil: item.checkoutUntil })
      }
    }
    return orders
  } catch {
    return []
  }
}

function rememberPendingOrder(order: PendingChangeOrder): void {
  try {
    const orders = pendingOrders().filter(item => item.id !== order.id)
    orders.push(order)
    window.localStorage.setItem(PENDING_CHANGE_STORAGE_KEY, JSON.stringify(orders.map(item => item.checkoutUntil ? item : item.id)))
  } catch { /* Polling v otevřené stránce funguje i bez localStorage. */ }
}

function forgetPendingOrder(orderId: string): void {
  try {
    window.localStorage.setItem(
      PENDING_CHANGE_STORAGE_KEY,
      JSON.stringify(pendingOrders().filter(item => item.id !== orderId).map(item => item.checkoutUntil ? item : item.id)),
    )
  } catch { /* Bezpečně ignorovat nedostupné perzistentní úložiště. */ }
}

const changeChecks = new Map<string, Promise<ChangeStatusResult>>()

function waitForOrder(order: PendingChangeOrder, attempts = 30): Promise<ChangeStatusResult> {
  const existing = changeChecks.get(order.id)
  if (existing) return existing
  rememberPendingOrder(order)
  const check = (async () => {
    let last: ChangeStatusResult = { order_id: order.id, state: 'pending', applied: false }
    const interval = order.checkoutUntil ? 5_000 : 2_000
    for (let i = 0; i < attempts; i += 1) {
      if (i > 0) await new Promise(resolve => window.setTimeout(resolve, interval))
      if (order.checkoutUntil && Date.now() >= order.checkoutUntil) {
        forgetPendingOrder(order.id)
        break
      }
      try {
        last = await licenseApi.changeStatus(order.id)
      } catch (error: unknown) {
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status === undefined || status === 429 || status >= 500) continue
        throw error
      }
      if (last.applied || (last.state === 'failed' && !order.checkoutUntil)) {
        forgetPendingOrder(order.id)
        break
      }
    }
    return last
  })().finally(() => changeChecks.delete(order.id))
  changeChecks.set(order.id, check)
  return check
}

/**
 * Odkaz na portál podpory na myucto.cz. U placené licence nese jednorázový
 * token, kterým se zákazník na portálu rovnou identifikuje; jinak je to prostý
 * veřejný odkaz.
 */
export interface SupportLink {
  url: string
}

export const licenseApi = {
  /** Admin — kompletní stav licence + počty. */
  status: (signal?: AbortSignal) => api.get<LicenseStatus>('/license/status', { signal }).then((r) => r.data),

  /**
   * Dunning stav — dostupný i BĚŽNÉMU adminovi, ne jen superadminovi.
   *
   * `null` = self-hosted instalace (nic se tu neplatí). Klientské účty dostanou
   * 403 a volající to musí umlčet stejně jako každé jiné „nevíme".
   */
  dunning: () =>
    api.get<{ billing: BillingDunningInfo | null }>('/license/billing').then((r) => r.data.billing),

  /** Admin — aktivace licenčním klíčem. `takeover` vynutí přenos vazby z jiné instalace
   *  (po chybě already_bound); počítá se do limitu přenosů 2/30 dní. */
  activate: (license_key: string, takeover = false) =>
    api.post<LicenseStatus>('/license/activate', { license_key, takeover }).then((r) => r.data),

  /** Admin — deaktivace (uvolní vazbu, smaže klíč lokálně). */
  deactivate: () => api.post<DeactivateResult>('/license/deactivate').then((r) => r.data),

  /**
   * Admin — okamžité stažení rozsahu z licenčního serveru.
   *
   * Zaplacené navýšení se do instalace propíše až novým tokenem, který se
   * běžně obnovuje jednou denně. Po platbě, která proběhla jinde než tady
   * (odkaz z e-mailu, ruční potvrzení obsluhou), by zákazník do té doby koukal
   * na staré počty.
   */
  refresh: (signal?: AbortSignal) => api.post<{ refreshed: boolean }>('/license/refresh', undefined, { signal }).then((r) => r.data),

  /** Admin — vypnutí automatického prodlužování. NENÍ deaktivace: licence běží
   *  do konce zaplaceného období, jen se nestrhne další platba. Idempotentní. */
  cancelRenewal: () =>
    api.post<CancelRenewalResult>('/license/cancel-renewal').then((r) => r.data),

  /**
   * Admin — obnova zrušeného předplatného.
   *
   * ⚠️ Neobnoví ho rovnou: vrací `pay_url`. Zrušení zneplatnilo mandát
   * u platební brány, takže se předplatné rozeběhne až po zaplacení novou
   * kartou. Obrazovka na tu adresu přesměruje.
   */
  resumeRenewal: () =>
    api.post<ResumeRenewalResult>('/license/resume-renewal').then((r) => r.data),

  /** Založí serverově svázaný PKCE checkout. Instance ani tajemství nejdou v URL. */
  startPurchase: () =>
    api.post<PurchaseStartResult>('/license/purchase/start').then((r) => r.data),

  /** Po návratu z platby převezme licenci server-to-server a vrátí jen běžný stav. */
  completePurchase: (purchase: string, state: string) =>
    api.post<LicenseStatus>('/license/purchase/complete', { purchase, state }).then((r) => r.data),

  /** Admin — kalkulace poměrného doplatku za navýšení na `users` (nic se nestrhává). */
  upgradeQuote: (users: number) =>
    api.post<UpgradeQuote>('/license/upgrade/quote', { users }).then((r) => r.data),

  /** Admin — in-place navýšení na `users` (strhne doplatek z uložené karty). */
  upgrade: (users: number, quote_token: string) =>
    api.post<UpgradeResult>('/license/upgrade', { users, quote_token }).then((r) => r.data),

  /**
   * Admin — kolik by stálo rozšíření úložiště na `quota_gb`. Nic nestrhává.
   *
   * ⚠️ `quota_gb` je CÍLOVÁ velikost z výčtu {@link STORAGE_SIZES_GB}, ne
   * přírůstek: „+5 GB" nad dvěma se posílá jako `7`.
   */
  storageQuote: (quota_gb: number) =>
    api.post<StorageQuote>('/license/quota/quote', { quota_gb }).then((r) => r.data),

  /**
   * Admin — rozšíření úložiště na `quota_gb` (strhne doplatek z uložené karty).
   *
   * ⚠️ Routa je `/license/quota`, NE `/license/storage`: IIS má segment
   * `storage` mezi skrytými kvůli datovému adresáři a požadavek by skončil
   * na 404.8 dřív, než se dostane k routeru.
   */
  storageUpgrade: (quota_gb: number, quote_token: string) =>
    api.post<StorageUpgradeResult>('/license/quota', { quota_gb, quote_token }).then((r) => r.data),

  tierQuote: (tier: string) =>
    api.post<TierQuote>('/license/tier/quote', { tier }).then((r) => r.data),

  changeTier: (tier: string, quote_token: string) =>
    api.post<TierChangeResult>('/license/tier', { tier, quote_token }).then((r) => r.data),

  /**
   * Přechod na roční předplatné. Jde jen z měsíčního; už zaplacenou roční
   * licenci server prodloužit dopředu nenechá (`already_annual`).
   */
  annualSwitchQuote: () =>
    api.post<AnnualSwitchQuote>('/license/period/quote', {}).then((r) => r.data),

  switchToAnnual: (quote_token: string) =>
    api.post<AnnualSwitchResult>('/license/period', { quote_token }).then((r) => r.data),

  payrollQuote: (enabled: boolean, payroll_employees_target: number, payroll_users_target: number) =>
    api.post<PayrollQuote>('/license/payroll/quote', {
      enabled,
      payroll_employees_target,
      payroll_users_target,
    }).then((r) => r.data),

  changePayroll: (enabled: boolean, quote_token: string, payroll_employees_target: number, payroll_users_target: number) =>
    api.post<PayrollChangeResult>('/license/payroll', {
      enabled,
      quote_token,
      payroll_employees_target,
      payroll_users_target,
    }).then((r) => r.data),

  performanceQuote: (enabled: boolean) =>
    api.post<PerformanceQuote>('/license/performance/quote', { enabled }).then((r) => r.data),

  changePerformance: (enabled: boolean, quote_token: string) =>
    api.post<PerformanceChangeResult>('/license/performance', { enabled, quote_token }).then((r) => r.data),

  changeStatus: (order_id: string) =>
    api.post<ChangeStatusResult>('/license/change-status', { order_id }).then((r) => r.data),

  /** Aktivní krátký polling po asynchronní platbě; po `applied` backend
   * vynutí renew tokenu, takže vrácená licence už obsahuje nový rozsah. */
  waitForChange: (order_id: string, attempts = 30) => waitForOrder({ id: String(order_id) }, attempts),

  waitForCheckout: (orderId: string) => waitForOrder({ id: orderId, checkoutUntil: Date.now() + CHECKOUT_WAIT_MS }, Infinity),

  /** Naváže na polling i po reloadu/zavření stránky. Ukládá se jen
   * neškodné ID objednávky, nikdy licenční klíč ani platební údaje. */
  resumePendingChanges: async (onApplied?: () => void | Promise<void>): Promise<ChangeStatusResult[]> => {
    const results = await Promise.allSettled(pendingOrders().map(async order => {
      const result = await waitForOrder(order, order.checkoutUntil ? Infinity : 30)
      if (result.applied) await onApplied?.()
      return result
    }))
    return results.flatMap(result => result.status === 'fulfilled' ? [result.value] : [])
  },

  /** Admin — přihlášený přechod na portál podpory (jednorázový token v URL). */
  supportLink: () => api.post<SupportLink>('/license/support-link').then((r) => r.data),
}
