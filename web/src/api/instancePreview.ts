/**
 * Náhled stavů spravované instalace — jak obrazovka vypadá, když se něco pokazí.
 *
 * Vzniklo z prosté potřeby: ukázat „licence vyprší", „místo došlo" a „vyzvi
 * k platbě", aniž by kvůli tomu někdo musel doopravdy přestat platit. Bez toho
 * se ty stavy dají ověřit jedině tak, že se počká, až nastanou.
 *
 * ── ⚠️ Čtyři hranice, které se tu NESMÍ překročit ─────────────────────────
 *
 *  1. **Mění POUZE zobrazení.** Náhled nikam nic neposílá, nic neukládá
 *     a nesahá na `auth.license` — tedy na to, podle čeho se rozhoduje
 *     přístup. Zavřený modul se náhledem NEOTEVŘE a otevřený se nezavře;
 *     mění se jen text a barva na obrazovce.
 *  2. **Nikdy se nezapne sám.** Zapíná ho výhradně superadmin výslovným
 *     úkonem (`?nahled=…` nebo přepínač). Žádné „když je to divné, ukaž
 *     náhled".
 *  3. **Je nepřehlédnutelně označený.** Dokud běží, drží se nad obsahem pruh
 *     „NÁHLED — data nejsou skutečná". Náhled, který se dá splést se
 *     skutečností, je horší než žádný.
 *  4. **Nepřežije odchod ze stránky.** Žádné localStorage, žádná session —
 *     zapomenutý náhled by z falešného varování udělal trvalý stav.
 *
 * Scénáře jsou schválně postavené jako ČISTÁ funkce nad syntetickým základem:
 * nepotřebují běžící server ani konkrétní stav instalace, takže ukážou i to,
 * co na téhle instalaci zrovna nastat nemůže.
 */

import { computed, ref } from 'vue'
import type {
  LicenseStatus,
  ManagedBillingInfo,
  ManagedStorageInfo,
  StorageQuote,
  UpgradeQuote,
} from './license'

/** Scénáře, které jde ukázat. Hodnota je zároveň to, co se píše do `?nahled=`. */
export const PREVIEW_SCENARIOS = [
  'trial_expired',
  'degraded',
  'overage',
  'past_due',
  'suspended',
  'expired',
  'cancelled',
  'storage_80',
  'storage_95',
  'storage_100',
  'provisioning',
  // Licence: chybí úplně / je zadaná ručně (ne ze zřízení).
  'no_license',
  'manual_key',
  // Nákupní toky — nabídka, výsledek i chybová cesta. Bez nich se dá ověřit
  // jen ta polovina, kde všechno vyjde.
  'users_offer',
  'users_done',
  'storage_offer',
  'storage_done',
  'card_declined',
  'result_unknown',
] as const

export type PreviewScenario = (typeof PREVIEW_SCENARIOS)[number]

export function isPreviewScenario(value: unknown): value is PreviewScenario {
  return typeof value === 'string' && (PREVIEW_SCENARIOS as readonly string[]).includes(value)
}

const GB = 1024 * 1024 * 1024
const DAY = 86400

const activeScenario = ref<PreviewScenario | null>(null)

/**
 * Zapnout náhled.
 *
 * ⚠️ Volající MUSÍ ověřit, že jde o superadmina — tady se to zjistit nedá
 * (modul o autentizaci nic neví) a schovat kontrolu sem by znamenala tichou
 * závislost, na kterou se zapomene.
 */
export function startPreview(scenario: PreviewScenario): void {
  activeScenario.value = scenario
}

export function stopPreview(): void {
  activeScenario.value = null
}

export const instancePreview = {
  scenario: computed(() => activeScenario.value),
  isActive: computed(() => activeScenario.value !== null),
}


/**
 * Stav NÁKUPNÍHO FORMULÁŘE pro náhled — kalkulace, výsledek, chyba.
 *
 * Proč zvlášť od {@link buildPreviewStatus}: tohle nejsou data instalace, ale
 * to, co má obrazovka zrovna rozepsané. Bez toho by šlo ukázat jen stavy, do
 * kterých se zákazník dostane sám od sebe — ne to, jak vypadá odmítnutá karta
 * nebo ztracená odpověď, tedy přesně ty dvě situace, kde se nejsnáz udělá
 * škoda.
 *
 * ⚠️ Je to POŘÁD jen zobrazení: nic z toho se neodesílá a tlačítka zůstávají
 * v náhledu zamčená.
 */
export interface PreviewUiState {
  storageQuote?: StorageQuote
  userQuote?: UpgradeQuote
  /** Hotovo — hláška o úspěchu (`done`) nebo o zavádění (`pending`). */
  outcome?: 'storage_done' | 'storage_pending' | 'users_done'
  /** Chybová hláška ze serveru, jak by přišla. */
  error?: string
  /** Odkaz na doplacení jinou kartou — chodí s odmítnutou platbou. */
  payUrl?: string
  /** Nabídka se po nejistém výsledku zavírá — „zkusit znovu" se NENABÍZÍ. */
  offerClosed?: boolean
}

const PREVIEW_STORAGE_QUOTE: StorageQuote = {
  current_quota_gb: 7,
  new_quota_gb: 22,
  amount: 249,
  recurring_delta: 120,
  currency: 'CZK',
  period_end: null,
  quote_token: 'preview',
  expires_at: null,
  scheduled: false,
  effective_at: null,
}

const PREVIEW_USER_QUOTE: UpgradeQuote = {
  current_users: 3,
  new_users: 5,
  amount: 430,
  currency: 'CZK',
  period_end: null,
  quote_token: 'preview',
  expires_at: null,
  scheduled: false,
  effective_at: null,
}

/** Co má mít formulář rozepsané. `null` = nic, obrazovka je ve výchozím stavu. */
export function previewUiState(scenario: PreviewScenario): PreviewUiState | null {
  switch (scenario) {
    case 'storage_offer':
      return { storageQuote: PREVIEW_STORAGE_QUOTE }
    case 'storage_done':
      return { outcome: 'storage_done', offerClosed: true }
    case 'users_offer':
      return { userQuote: PREVIEW_USER_QUOTE }
    case 'users_done':
      return { outcome: 'users_done' }
    case 'card_declined':
      // ⚠️ Se stejnou kartou nemá opakování smysl — proto se nabízí doplacení
      // jinou kartou. Odkaz vede na TUTÉŽ objednávku, nic se nezdvojí.
      return {
        storageQuote: PREVIEW_STORAGE_QUOTE,
        error: 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
          + 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
        payUrl: PREVIEW_DUE.pay_url,
      }
    case 'result_unknown':
      // ⚠️ Peníze MOHLY odejít. Nabídka se zavírá a nikde se nepobízí k opakování.
      return {
        offerClosed: true,
        error: 'Nevíme, jak platba dopadla. Nezkoušejte to prosím znovu — '
          + 'za chvíli obnovte stránku, a pokud se nic nezmění, ozvěte se podpoře.',
      }
    default:
      return null
  }
}

/** Co by u dluhu poslal licenční server — částka a podepsaný odkaz na úhradu. */
const PREVIEW_DUE = {
  amount_due: 890,
  currency: 'CZK',
  pay_url: 'https://myucto.cz/platba/nahled',
} as const

/** Syntetický základ — náhled nesmí záviset na tom, co zrovna vrací server. */
function baseStatus(now: number): LicenseStatus {
  return {
    state: 'active',
    instance_id: 'preview',
    tier: 'multi10',
    max_companies: 10,
    users_licensed: 3,
    users_active: 3,
    companies_active: 4,
    valid_until: now + 200 * DAY,
    trial_ends_at: null,
    overage_deadline: null,
    perpetual: false,
    commercial_features: true,
    tier_commercial: true,
    license_key_masked: 'MYU-••••-••••-7C2A',
    last_check_at: new Date(now * 1000).toISOString(),
    last_check_ok: true,
    buy_url: '',
    subscription: {
      state: 'active',
      period: 'month',
      auto_renew: true,
      next_charge_at: now + 12 * DAY,
      cancelled_at: null,
      valid_until: now + 200 * DAY,
    },
    company: { name: '', ic: '', dic: '', street: '', city: '', zip: '', email: '' },
    instance: {
      managed: true,
      plan: 'standard',
      managed_since: '2025-03-01',
      subscription_url: null,
      links: { subscription: null, expand_storage: null, support: null, terms: null, privacy: null },
      billing: {
        unpaid: false,
        license_state: 'active',
        subscription_state: 'active',
        valid_until: now + 200 * DAY,
        last_check_at: new Date(now * 1000).toISOString(),
        last_check_ok: true,
        phase: 'active',
        attempt: null,
        max_attempts: null,
        next_attempt_at: null,
        suspend_at: null,
        access_until: null,
        data_until: null,
        amount_due: null,
        currency: null,
        // Zdravá instalace nic nedluží — odkaz je jen správa předplatného,
        // stejně jako ho v tu chvíli dosadí backend.
        pay_url: null,
      },
      storage: {
        measured: true,
        measured_at: new Date(now * 1000).toISOString(),
        usage_bytes: Math.round(0.42 * 7 * GB),
        quota_bytes: 7 * GB,
        percent: 42,
        warn_percent: 90,
        read_only_percent: 100,
        blocks_writes: false,
        change_pending: false,
        quota_gb_ordered: 7,
        quota_source: 'license',
      },
    },
  }
}

function withStorage(status: LicenseStatus, percent: number, over: Partial<ManagedStorageInfo> = {}): LicenseStatus {
  const storage = status.instance!.storage
  const next = { ...storage, ...over }
  // ⚠️ Z kvóty PO přepisu: scénář „rozšířeno na 22 GB" by jinak spočítal
  // obsazení ze sedmi a sám by si odporoval.
  const quota = next.quota_bytes ?? 7 * GB

  return {
    ...status,
    instance: {
      ...status.instance!,
      storage: {
        ...next,
        percent,
        usage_bytes: Math.round((percent / 100) * quota),
        blocks_writes: percent >= next.read_only_percent,
      },
    },
  }
}

function withBilling(status: LicenseStatus, over: Partial<ManagedBillingInfo>, state?: LicenseStatus['state']): LicenseStatus {
  const billing = { ...status.instance!.billing, ...over }

  return {
    ...status,
    // ⚠️ `state` na kořeni je jen ZOBRAZENÍ na téhle obrazovce; přístup
    // k modulům se řídí `auth.license`, na které náhled nesahá.
    state: state ?? status.state,
    // I tarif musí moduly odemykat — bezplatný „Fakturace a DPH" má platnou
    // licenci, ale účetnictví si nezaplatil. Bez téhle podmínky náhled
    // bezplatného tarifu ukazoval moduly jako dostupné.
    commercial_features: status.tier_commercial !== false
      && billing.license_state !== 'degraded'
      && billing.license_state !== 'trial_expired',
    instance: { ...status.instance!, billing },
  }
}

/**
 * Sestaví stav podle scénáře. Čistá funkce — `now` se předává, aby se dala
 * otestovat bez závislosti na hodinách.
 */
export function buildPreviewStatus(
  scenario: PreviewScenario,
  now: number = Math.floor(Date.now() / 1000),
): LicenseStatus {
  const base = baseStatus(now)

  switch (scenario) {
    case 'trial_expired':
      return withBilling(base, {
        unpaid: true,
        license_state: 'trial_expired',
        subscription_state: null,
        phase: null,
        valid_until: now - 2 * DAY,
      }, 'trial_expired')

    case 'degraded':
      return withBilling(base, {
        unpaid: true,
        license_state: 'degraded',
        subscription_state: 'expired',
        phase: 'expired',
        valid_until: now - 5 * DAY,
        access_until: now - 5 * DAY,
        data_until: now + 30 * DAY,
        ...PREVIEW_DUE,
      }, 'degraded')

    case 'overage':
      return {
        ...withBilling(base, {}, 'overage'),
        users_active: 6,
        users_licensed: 3,
        companies_active: 12,
        max_companies: 10,
        overage_deadline: now + 21 * DAY,
      }

    case 'past_due':
      return withBilling(base, {
        unpaid: true,
        license_state: 'active',
        subscription_state: 'past_due',
        phase: 'past_due',
        attempt: 2,
        max_attempts: 4,
        next_attempt_at: now + 3 * DAY,
        suspend_at: now + 14 * DAY,
        access_until: now + 14 * DAY,
        ...PREVIEW_DUE,
      })

    case 'suspended':
      return withBilling(base, {
        unpaid: true,
        license_state: 'degraded',
        subscription_state: 'past_due',
        phase: 'suspended',
        attempt: 4,
        max_attempts: 4,
        access_until: now - 1 * DAY,
        data_until: now + 30 * DAY,
        ...PREVIEW_DUE,
      }, 'degraded')

    case 'expired':
      return withBilling(base, {
        unpaid: true,
        license_state: 'degraded',
        subscription_state: 'expired',
        phase: 'expired',
        valid_until: now - 9 * DAY,
        access_until: now - 9 * DAY,
        data_until: now + 30 * DAY,
        ...PREVIEW_DUE,
      }, 'degraded')

    // Zrušená obnova — zaplaceno je, ale doběhne to. Záměrně NENÍ „unpaid".
    case 'cancelled':
      return withBilling(base, {
        unpaid: false,
        subscription_state: 'cancelled',
        phase: 'cancelled',
        access_until: now + 46 * DAY,
        data_until: now + 76 * DAY,
      })

    case 'storage_80':
      return withStorage(base, 82)

    case 'storage_95':
      return withStorage(base, 95)

    case 'storage_100':
      return withStorage(base, 100)

    // Zaplacené rozšíření, které se právě zavádí — nabídka nákupu musí zmizet.
    case 'provisioning':
      return withStorage(base, 94, { change_pending: true, quota_gb_ordered: 22 })

    // Klíč chybí — zřízení se nepovedlo. Nákup nemá o co opřít, jediná cesta
    // ven je klíč zadat ručně.
    case 'no_license':
      return { ...base, license_key_masked: null }

    case 'manual_key':
      return { ...base, license_key_masked: 'MYU-••••-••••-3F91' }

    // Nákupní toky mění jen to, co má obrazovka rozepsané (viz previewUiState);
    // stav instalace zůstává zdravý, aby bylo vidět samotný formulář.
    case 'users_offer':
    case 'users_done':
      return { ...base, users_active: 3, users_licensed: 3 }

    case 'storage_offer':
    case 'card_declined':
    case 'result_unknown':
      return withStorage(base, 84)

    case 'storage_done':
      return withStorage(base, 27, { quota_bytes: 22 * GB, quota_gb_ordered: 22 })
  }
}
