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

export interface TierChangeResult {
  new_tier: string
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

function pendingOrderIds(): string[] {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(PENDING_CHANGE_STORAGE_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string' && id !== '') : []
  } catch {
    return []
  }
}

function rememberPendingOrder(orderId: string): void {
  try {
    const ids = new Set(pendingOrderIds())
    ids.add(orderId)
    window.localStorage.setItem(PENDING_CHANGE_STORAGE_KEY, JSON.stringify([...ids]))
  } catch { /* Polling v otevřené stránce funguje i bez localStorage. */ }
}

function forgetPendingOrder(orderId: string): void {
  try {
    window.localStorage.setItem(
      PENDING_CHANGE_STORAGE_KEY,
      JSON.stringify(pendingOrderIds().filter(id => id !== orderId)),
    )
  } catch { /* Bezpečně ignorovat nedostupné perzistentní úložiště. */ }
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
  status: () => api.get<LicenseStatus>('/license/status').then((r) => r.data),

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
  refresh: () => api.post<LicenseStatus>('/license/refresh').then((r) => r.data),

  /** Admin — vypnutí automatického prodlužování. NENÍ deaktivace: licence běží
   *  do konce zaplaceného období, jen se nestrhne další platba. Idempotentní. */
  cancelRenewal: () =>
    api.post<CancelRenewalResult>('/license/cancel-renewal').then((r) => r.data),

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

  changeStatus: (order_id: string) =>
    api.post<ChangeStatusResult>('/license/change-status', { order_id }).then((r) => r.data),

  /** Aktivní krátký polling po asynchronní platbě; po `applied` backend
   * vynutí renew tokenu, takže vrácená licence už obsahuje nový rozsah. */
  waitForChange: async (order_id: string, attempts = 30): Promise<ChangeStatusResult> => {
    rememberPendingOrder(order_id)
    let last: ChangeStatusResult = { order_id, state: 'pending', applied: false }
    for (let i = 0; i < attempts; i += 1) {
      if (i > 0) await new Promise(resolve => window.setTimeout(resolve, 2000))
      last = await licenseApi.changeStatus(order_id)
      if (last.applied || last.state === 'failed') {
        forgetPendingOrder(order_id)
        return last
      }
    }
    return last
  },

  /** Naváže na polling i po reloadu/zavření stránky. Ukládá se jen
   * neškodné ID objednávky, nikdy licenční klíč ani platební údaje. */
  resumePendingChanges: async (): Promise<ChangeStatusResult[]> => {
    const results: ChangeStatusResult[] = []
    for (const orderId of pendingOrderIds()) {
      results.push(await licenseApi.waitForChange(orderId))
    }
    return results
  },

  /** Admin — přihlášený přechod na portál podpory (jednorázový token v URL). */
  supportLink: () => api.post<SupportLink>('/license/support-link').then((r) => r.data),
}
