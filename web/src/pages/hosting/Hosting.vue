<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { licenseApi, type StorageQuote, type TierQuote, type UpgradeQuote } from '@/api/license'
import { formatQuotaBytes } from '@/api/storageQuota'
import { instanceStatus, publishInstanceStatus } from '@/api/instanceStatus'
import {
  PREVIEW_SCENARIOS,
  instancePreview,
  isPreviewScenario,
  previewUiState,
  startPreview,
  stopPreview,
} from '@/api/instancePreview'
import {
  currentQuotaGb,
  resolveBillingNarrative,
  resolveStorageLevel,
  resolveStorageMode,
  storageUpgradeOptionsGb,
} from '@/api/instanceHealth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Předplatné a provoz — co zákazník má, co mu dochází a co s tím (H-31).
 *
 * Obrazovka má na jedno otevření odpovědět na tři otázky, a v tomhle pořadí:
 *
 *   1. **Co mám?** tarif licence, uživatelé, účetní jednotky, tarif provozu,
 *      místo, stav platby a dokdy je zaplaceno.
 *   2. **Co mi dochází?** co je blízko stropu nebo přes něj.
 *   3. **Co s tím?** u každé položky konkrétní akce, ne odkaz „na web".
 *
 * ── ⚠️ Pravidla, na kterých to stojí ──────────────────────────────────────
 *
 *  1. **Řádek, který je v pořádku, NEKŘIČÍ.** Barvu, odznak a tlačítko dostane
 *     jen to, co se má řešit. Kdyby svítilo všechno, nesvítí nic.
 *  2. **„Nevím" se nikdy nekreslí jako nula.** Nezměřené místo, neznámá kvóta
 *     ani chybějící termín se nedopočítávají — viz `@/api/instanceHealth`.
 *  3. **Dvojklik nesmí strhnout dvakrát.** Objednávka je zamčená po celou dobu
 *     volání a po (i nejistém) výsledku se nabídka schová.
 *  4. **Co se zavádí, se nenabízí znovu.** `change_pending` = zaplaceno,
 *     jen se to ještě neprojevilo.
 *
 * Náhled stavů (`?nahled=…`) je jen pro superadmina a mění POUZE zobrazení —
 * viz `@/api/instancePreview`.
 */
const { t, te, tm, rt } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const loading = ref(true)
const errorMsg = ref<string | null>(null)

const status = instanceStatus.status
const instance = instanceStatus.instance
const storage = instanceStatus.storage
const billing = instanceStatus.billing
const links = computed(() => instance.value?.links ?? null)

/** Self-hosted: blok `instance` v odpovědi vůbec není. */
const isManaged = computed(() => instance.value !== null)

// ─── Náhled stavů ──────────────────────────────────────────────────────────
//
// ⚠️ Zapíná ho VÝHRADNĚ superadmin a VÝHRADNĚ výslovným úkonem (`?nahled=`).
// Nikdy se nezapne sám a nepřežije odchod ze stránky — zapomenutý náhled by
// z falešného varování udělal trvalý stav.
const previewing = instancePreview.isActive
const previewScenario = instancePreview.scenario
const previewScenarios = PREVIEW_SCENARIOS

function syncPreviewFromRoute(): void {
  const raw = route.query.nahled
  const value = Array.isArray(raw) ? raw[0] : raw

  if (!auth.isSuperadmin || !isPreviewScenario(value)) {
    stopPreview()
    return
  }
  startPreview(value)
}

watch(() => route.query.nahled, syncPreviewFromRoute, { immediate: true })

// ⚠️ Náhled se ZÁMĚRNĚ neruší při odchodu ze stránky: hostingové stavy se
// promítají i do menu a do „Akcí pro tebe" na dashboardu, a právě tam je
// potřeba je proklikat. Aby z toho nevznikl zapomenutý falešný stav, drží se
// nad celou aplikací pruh „NÁHLED" (viz InstancePreviewBar) a náhled žije jen
// v paměti — obnovení stránky ho zahodí. Návrat na /hosting bez `?nahled=`
// ho vypne taky (viz syncPreviewFromRoute).

// ─── Načtení ───────────────────────────────────────────────────────────────

async function load(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  try {
    publishInstanceStatus(await licenseApi.status())
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? t('hosting.load_failed')
  } finally {
    loading.value = false
  }
}

/**
 * Ruční stažení rozsahu z licenčního serveru.
 *
 * Zaplacené navýšení míst i rozšíření úložiště se do instalace propíše až
 * novým licenčním tokenem, a ten se běžně obnovuje jednou denně. Po platbě,
 * která proběhla jinde než tady — odkaz z e-mailu, ruční potvrzení obsluhou —
 * koukal zákazník do té doby na staré počty a nechápal, za co zaplatil.
 */
const refreshing = ref(false)

async function refreshEntitlement(): Promise<void> {
  refreshing.value = true
  errorMsg.value = null
  try {
    // ⚠️ Stav se čte AŽ POTOM běžnou cestou. Odpověď obnovy ho nenese —
    // payload `/license/status` je bohatší a jeho druhá, chudší podoba by
    // obrazovku shodila do „tahle instalace u nás neběží".
    await licenseApi.refresh()
    await load()
    await auth.refresh()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? t('hosting.refresh_failed')
  } finally {
    refreshing.value = false
  }
}

onMounted(async () => {
  await load()
  if (!auth.isSuperadmin) return
  const settled = await licenseApi.resumePendingChanges()
  if (settled.some(change => change.applied)) {
    await load()
    await auth.refresh()
  }
})

// ─── Formátování ───────────────────────────────────────────────────────────

function fmtDate(ts: number | null): string {
  return ts ? new Date(ts * 1000).toLocaleDateString() : '—'
}
function fmtDateTime(value: string | null): string {
  if (!value) return '—'
  try { return new Date(value).toLocaleString() } catch { return value }
}
function fmtPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} %`
}
function fmtAmount(amount: number | null | undefined, currency: string | null): string {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'CZK' }).format(amount)
  } catch {
    return `${amount} ${currency ?? ''}`.trim()
  }
}
/** Konec období z kalkulace — server ho posílá jako unix i jako řetězec. */
function fmtPeriodEnd(value: number | string | null): string | null {
  if (value === null || value === undefined || value === '') return null
  const parsed = typeof value === 'number' ? new Date(value * 1000) : new Date(value)

  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString()
}

/** Chybová hláška ze serveru. ⚠️ Zobrazuje se TA — nevymýšlíme si vlastní. */
function apiError(e: unknown, fallbackKey: string): { code: string | null; message: string } {
  const err = e as { response?: { data?: { error?: { code?: string; message?: string } } } }

  return {
    code: err.response?.data?.error?.code ?? null,
    message: err.response?.data?.error?.message ?? t(fallbackKey),
  }
}

// ─── Co mám ────────────────────────────────────────────────────────────────

/** Odstín řádku. `ok` je záměrně bez barvy — nekřičí, co se nemá řešit. */
type Tone = 'ok' | 'notice' | 'warning' | 'critical'

const TONE_ROW: Record<Tone, string> = {
  ok:       'border-neutral-200 bg-surface',
  notice:   'border-primary-300 bg-primary-50/40',
  warning:  'border-warning-500/40 bg-warning-50/40',
  critical: 'border-danger-500/40 bg-danger-50/50',
}
const TONE_BADGE: Record<Tone, string> = {
  ok:       '',
  notice:   'bg-primary-100 text-primary-800',
  warning:  'bg-warning-500/20 text-warning-600',
  critical: 'bg-danger-500/15 text-danger-600',
}

/** Tarif licence. Neznámý kód se ukáže tak, jak přišel — nevymýšlíme mu název. */
const tierLabel = computed(() => {
  const tier = status.value?.tier
  if (!tier) return t('license.managed_plan_unknown')
  const key = `license.tier_${tier}`

  return te(key) ? t(key) : tier
})

/** Tarif provozu. Totéž — kód, který neumíme pojmenovat, se neschovává. */
const planLabel = computed(() => {
  const plan = instance.value?.plan
  if (!plan) return t('license.managed_plan_unknown')
  const key = `license.managed_plan_${plan}`

  return te(key) ? t(key) : plan
})

const managedSinceLabel = computed(() => {
  const raw = instance.value?.managed_since
  if (!raw) return null
  const parsed = new Date(raw)

  return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleDateString()
})

const subscription = computed(() => status.value?.subscription ?? null)
const paidUntil = computed(() => subscription.value?.valid_until ?? status.value?.valid_until ?? null)

/** Uživatelé: 0 licencovaných = neomezeno, ne „přes limit". */
const usersTone = computed<Tone>(() => {
  const s = status.value
  if (!s || s.users_licensed <= 0) return 'ok'
  if (s.users_active > s.users_licensed) return 'critical'
  if (s.users_active === s.users_licensed) return 'notice'

  return 'ok'
})

/** Účetní jednotky: `max_companies === null` = neomezeně. */
const companiesTone = computed<Tone>(() => {
  const s = status.value
  if (!s || s.max_companies === null) return 'ok'
  if (s.companies_active > s.max_companies) return 'critical'
  if (s.companies_active === s.max_companies) return 'notice'

  return 'ok'
})

/** Chybějící klíč je blokující provozní stav hostované instalace. */
const licenseKeyTone = computed<Tone>(() => (status.value?.license_key_masked ? 'ok' : 'critical'))

const storageMode = computed(() => resolveStorageMode(storage.value))
const storageLevel = computed(() => resolveStorageLevel(storage.value))

const storageTone = computed<Tone>(() => {
  switch (storageLevel.value) {
    case 'exhausted': return 'critical'
    case 'warning': return 'warning'
    case 'notice': return 'notice'
    default: return 'ok'
  }
})

/** Přes 100 % se pruh nepřetáčí; poměr v textu zůstává pravdivý. */
const storageBarWidth = computed(() => {
  const p = storage.value?.percent
  if (storageMode.value !== 'known' || p === null || p === undefined) return 0

  return Math.max(0, Math.min(100, p))
})

const usedLabel = computed(() => formatQuotaBytes(storage.value?.usage_bytes ?? null))
const quotaLabel = computed(() => formatQuotaBytes(storage.value?.quota_bytes ?? null))

// ─── Stav platby a fáze neuhrazení ─────────────────────────────────────────

const narrative = computed(() => resolveBillingNarrative(billing.value))

const billingTone = computed<Tone>(() => {
  const n = narrative.value
  if (!n) return 'ok'

  return n.severity === 'critical' ? 'critical' : 'warning'
})

/**
 * Krátký popis stavu platby do přehledu.
 *
 * ⚠️ Stav LICENCE tu nestačí. Po první neúspěšné platbě licence pořád běží,
 * takže by v řádku „Stav platby" stálo „Aktivní" — přesně to, co zákazník
 * potřebuje NEvidět, když se mu právě nepodařilo strhnout kartu. Vede fáze;
 * na stav licence se spadne, jen když fázi neznáme.
 */
const billingLabel = computed(() => {
  const n = narrative.value
  if (!n) return t('hosting.billing_ok')
  if (n.phase !== null) return t(`hosting.phase.label_${n.phase}`)

  return t(`license.state_${billing.value?.license_state ?? 'active'}`)
})

/** Věta „co se stalo" (+ kolikátý pokus, když to server poslal). */
const happenedText = computed(() => {
  const n = narrative.value
  if (!n) return null
  const base = t(n.happenedKey)
  if (n.attempt === null || n.maxAttempts === null) return base

  return `${base} ${t('hosting.phase.attempt_of', { attempt: n.attempt, max: n.maxAttempts })}`
})

/**
 * Věta „co bude a kdy".
 *
 * ⚠️ Když server termín neposlal, `nextKey` končí na `_nodate` a žádné datum
 * se nikde neobjeví. Vymyšlený termín je horší než žádný.
 */
const nextText = computed(() => {
  const n = narrative.value
  if (!n) return null

  return t(n.nextKey, { date: n.nextAt === null ? '' : fmtDate(n.nextAt) })
})

/** Časová osa — jen milníky, které server opravdu poslal. */
const milestones = computed(() => narrative.value?.milestones ?? [])

/** Placené moduly jsou zavřené → uživatel musí vidět, co konkrétně nejde. */
const featuresLocked = computed(() => narrative.value?.featuresLocked === true)
const lockedFeatures = computed(() => (tm('license.locked_features') as unknown[]).map(i => rt(i as string)))
const openFeatures = computed(() => (tm('license.open_features') as unknown[]).map(i => rt(i as string)))

// ─── Co mi dochází ─────────────────────────────────────────────────────────

/**
 * Souhrn nahoře. Prázdný seznam = není co řešit a nic se nekreslí.
 *
 * ⚠️ VYČERPANÁ KAPACITA SEM NEPATŘÍ. Kdo má 1 uživatele z 1 zaplaceného,
 * využívá přesně to, co si koupil — je to správný stav, ne nález. Dokud tady
 * byl, přivítala čerstvě zřízená instalace zákazníka při prvním přihlášení
 * výstrahou „Vyžaduje pozornost", protože 1 z 1 se rovná stropu. Že na víc
 * místa není, říká chip „Na stropu" na kartě a tlačítka Dokoupit / Změnit
 * tarif — ta informace se tím neztrácí, jen přestává vypadat jako porucha.
 *
 * Docházející MÍSTO je něco jiného a v souhrnu zůstává i jako `notice`: 80 %
 * je trajektorie k problému, ne ustálený stav.
 */
const attention = computed(() => {
  const items: Array<{ key: string; anchor: string; tone: Tone }> = []
  if (billingTone.value !== 'ok') items.push({ key: 'hosting.attention_billing', anchor: '#platba', tone: billingTone.value })
  if (licenseKeyTone.value !== 'ok') items.push({ key: 'hosting.attention_key', anchor: '#klic', tone: licenseKeyTone.value })
  if (usersTone.value === 'critical') items.push({ key: 'hosting.attention_users', anchor: '#uzivatele', tone: usersTone.value })
  if (companiesTone.value === 'critical') items.push({ key: 'hosting.attention_companies', anchor: '#tarif', tone: companiesTone.value })
  if (storageTone.value !== 'ok') items.push({ key: 'hosting.attention_storage', anchor: '#misto', tone: storageTone.value })

  return items
})

// ─── Dokoupení uživatelů (existující tok licence) ──────────────────────────

const upgradeUsers = ref(1)
const quotingUsers = ref(false)
const upgradingUsers = ref(false)
const userQuote = ref<UpgradeQuote | null>(null)
const userError = ref<string | null>(null)
const userDone = ref<string | null>(null)

const targetTier = ref('single')
const tierQuote = ref<TierQuote | null>(null)
const quotingTier = ref(false)
const changingTier = ref(false)
const tierMessage = ref<string | null>(null)
const tierError = ref<string | null>(null)

watch(status, (s) => {
  if (s?.tier && !tierQuote.value) targetTier.value = s.tier
}, { immediate: true })

async function calcTierQuote(): Promise<void> {
  if (quotingTier.value || previewing.value || targetTier.value === status.value?.tier) return
  quotingTier.value = true
  tierError.value = null
  tierMessage.value = null
  try {
    tierQuote.value = await licenseApi.tierQuote(targetTier.value)
  } catch (e: unknown) {
    tierError.value = apiError(e, 'license.tier_change_failed').message
  } finally {
    quotingTier.value = false
  }
}

async function applyTierChange(): Promise<void> {
  const quote = tierQuote.value
  if (!quote || changingTier.value || previewing.value) return
  if (!confirm(t(quote.scheduled ? 'license.tier_schedule_confirm' : 'license.tier_change_confirm'))) return
  changingTier.value = true
  tierError.value = null
  try {
    let result = await licenseApi.changeTier(quote.new_tier, quote.quote_token)
    if (result.pending && result.order_id) {
      const settled = await licenseApi.waitForChange(result.order_id)
      if (settled.applied && settled.license) result = { ...result, pending: false, state: settled.license }
    }
    tierQuote.value = null
    tierMessage.value = result.scheduled
      ? t('license.change_scheduled', { date: fmtPeriodEnd(result.effective_at) ?? '—' })
      : result.pending ? t('license.change_pending') : t('license.tier_change_success')
    publishInstanceStatus(result.state)
    await load()
    await auth.refresh()
  } catch (e: unknown) {
    tierError.value = apiError(e, 'license.tier_change_failed').message
  } finally {
    changingTier.value = false
  }
}

/**
 * Navýšení má smysl jen u aktivního placeného předplatného s klíčem.
 *
 * ⚠️ Bez klíče se nabídka NEKRESLÍ — server by nákup odmítl a jediné, co
 * uživateli pomůže, je klíč zadat (řádek „Licenční klíč" níž).
 */
const canBuyUsers = computed(() => {
  const s = status.value
  if (!s || !s.license_key_masked) return false

  return s.state === 'active' || s.state === 'overage'
})

watch(status, (s) => {
  if (s && !userQuote.value) upgradeUsers.value = Math.max(s.users_active, s.users_licensed, 1)
}, { immediate: true })

async function calcUserQuote(): Promise<void> {
  if (quotingUsers.value || previewing.value) return
  const n = Math.floor(Number(upgradeUsers.value))
  if (!n || n < 1) return

  quotingUsers.value = true
  userError.value = null
  userDone.value = null
  userQuote.value = null
  try {
    userQuote.value = await licenseApi.upgradeQuote(n)
  } catch (e: unknown) {
    userError.value = apiError(e, 'license.upgrade_failed').message
  } finally {
    quotingUsers.value = false
  }
}

async function buyUsers(): Promise<void> {
  // ⚠️ Zámek jako první příkaz — dvojklik nesmí odeslat druhou platbu.
  if (upgradingUsers.value || previewing.value || !userQuote.value) return
  const n = userQuote.value.new_users
  if (!confirm(t(userQuote.value.scheduled ? 'license.capacity_schedule_confirm' : 'license.upgrade_confirm', { n }))) return

  upgradingUsers.value = true
  userError.value = null
  userDone.value = null
  try {
    let res = await licenseApi.upgrade(n, userQuote.value.quote_token)
    if (res.pending && res.order_id) {
      const settled = await licenseApi.waitForChange(res.order_id)
      if (settled.applied && settled.license) res = { ...res, pending: false, state: settled.license }
    }
    publishInstanceStatus(res.state)
    userQuote.value = null
    userDone.value = res.scheduled
      ? t('license.change_scheduled', { date: fmtPeriodEnd(res.effective_at) ?? '—' })
      : res.pending
        ? t('license.change_pending')
        : t('license.upgrade_success', { n: res.new_users })
    await auth.refresh()
  } catch (e: unknown) {
    userError.value = apiError(e, 'license.upgrade_failed').message
  } finally {
    upgradingUsers.value = false
  }
}

// ─── Rozšíření místa ───────────────────────────────────────────────────────

const currentGb = computed(() => currentQuotaGb(storage.value))
const sizeOptions = computed(() => storageUpgradeOptionsGb(currentGb.value))

const selectedGb = ref<number | null>(null)
const storageQuote = ref<StorageQuote | null>(null)
const quotingStorage = ref(false)
const buyingStorage = ref(false)
const storageError = ref<string | null>(null)
const storageDone = ref<string | null>(null)

/**
 * Nabídka se po objednávce zavře natvrdo.
 *
 * ⚠️ Platí i pro `result_unknown` — „nevíme, jak platba dopadla" znamená, že
 * peníze MOHLY odejít. Nabídnout v tu chvíli „zkusit znovu" je nejrychlejší
 * cesta ke dvojímu stržení.
 */
const offerClosed = ref(false)

/** Zaplacené rozšíření, které se právě zavádí — nabízet znovu se NESMÍ. */
const changePending = computed(() => storage.value?.change_pending === true)

const offerVisible = computed(() =>
  isManaged.value && !changePending.value && !offerClosed.value && sizeOptions.value.length > 0,
)

async function pickSize(gb: number): Promise<void> {
  if (quotingStorage.value || buyingStorage.value || previewing.value) return

  selectedGb.value = gb
  storageQuote.value = null
  storageError.value = null
  storageDone.value = null
  quotingStorage.value = true
  try {
    storageQuote.value = await licenseApi.storageQuote(gb)
  } catch (e: unknown) {
    storageError.value = apiError(e, 'hosting.storage_order_failed').message
  } finally {
    quotingStorage.value = false
  }
}

async function buyStorage(): Promise<void> {
  // ⚠️ Zámek jako první příkaz — viz `offerClosed`.
  if (buyingStorage.value || previewing.value) return
  const quote = storageQuote.value
  if (!quote) return

  const confirmed = confirm(t(quote.scheduled ? 'license.capacity_schedule_confirm' : 'hosting.storage_confirm', {
    gb: quote.new_quota_gb,
    amount: fmtAmount(quote.amount, quote.currency),
  }))
  if (!confirmed) return

  buyingStorage.value = true
  storageError.value = null
  storageDone.value = null
  try {
    let res = await licenseApi.storageUpgrade(quote.new_quota_gb, quote.quote_token)
    if (res.pending && res.order_id) {
      const settled = await licenseApi.waitForChange(res.order_id)
      if (settled.applied && settled.license) res = { ...res, pending: false, state: settled.license }
    }
    // Zaplaceno — ať už se kvóta zvedla, nebo se teprve zavádí. Konec nabídky.
    offerClosed.value = true
    storageQuote.value = null
    selectedGb.value = null
    storageDone.value = res.scheduled
      ? t('license.change_scheduled', { date: fmtPeriodEnd(res.effective_at) ?? '—' })
      : res.pending
        ? t('license.change_pending')
        : res.provisioning_pending
      ? t('hosting.storage_pending', { gb: res.new_quota_gb })
      : t('hosting.storage_done', { gb: res.new_quota_gb })
    publishInstanceStatus(res.state)
    // Backend si po nákupu sám vynutí obnovu licence, takže nová kvóta přijde
    // hned; bez tohohle by zákazník po zaplacení viděl starý objem.
    await load()
    await auth.refresh()
  } catch (e: unknown) {
    const { code, message } = apiError(e, 'hosting.storage_order_failed')
    storageError.value = message
    if (code === 'result_unknown') offerClosed.value = true
  } finally {
    buyingStorage.value = false
  }
}

// ─── Co provoz zahrnuje ────────────────────────────────────────────────────

const included = computed(() => (tm('license.managed_included') as unknown[]).map(i => rt(i as string)))
const excluded = computed(() => (tm('license.managed_excluded') as unknown[]).map(i => rt(i as string)))

// ─── Náhled nákupních toků ─────────────────────────────────────────────────
//
// Scénáře jako „odmítnutá karta" nebo „nevíme, jak platba dopadla" se jinak
// ukázat nedají — musely by se doopravdy stát. Náhled proto umí předepsat i to,
// co má formulář rozepsané.
//
// ⚠️ Pořád jen zobrazení: tlačítka zůstávají v náhledu zamčená (`previewing`),
// takže se odsud nedá nic objednat.
watch(previewScenario, (scenario) => {
  // Odchod z náhledu vrátí formuláře do výchozího stavu — jinak by na obrazovce
  // zůstala viset kalkulace, kterou nikdo nevyžádal.
  storageQuote.value = null
  userQuote.value = null
  storageDone.value = null
  userDone.value = null
  storageError.value = null
  userError.value = null
  tierQuote.value = null
  tierMessage.value = null
  tierError.value = null
  offerClosed.value = false
  selectedGb.value = null
  if (scenario === null) return

  const ui = previewUiState(scenario)
  if (!ui) return

  if (ui.storageQuote) {
    storageQuote.value = ui.storageQuote
    selectedGb.value = ui.storageQuote.new_quota_gb
  }
  if (ui.userQuote) userQuote.value = ui.userQuote
  if (ui.outcome === 'storage_done') storageDone.value = t('hosting.storage_done', { gb: 22 })
  if (ui.outcome === 'storage_pending') storageDone.value = t('hosting.storage_pending', { gb: 22 })
  if (ui.outcome === 'users_done') userDone.value = t('license.upgrade_success', { n: 5 })
  if (ui.error) storageError.value = ui.error
  if (ui.offerClosed) offerClosed.value = true
// ⚠️ `immediate`: scénář z `?nahled=` je nastavený UŽ při setupu (viz
// `syncPreviewFromRoute` výš), takže se nikdy „nezmění" a bez tohohle by se
// formulář nenaplnil — odkaz na odmítnutou kartu by ukázal prázdnou obrazovku.
}, { immediate: true })
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <!-- ⚠️ Náhled musí být nepřehlédnutelný. Náhled, který se dá splést se
         skutečností, je horší než žádný. -->
    <div
      v-if="previewing"
      class="mb-5 rounded-lg border-2 border-dashed border-accent-500 bg-accent-50/70 p-4"
      data-hosting-preview
    >
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="rounded bg-accent-600 px-2 py-0.5 text-xs font-bold uppercase tracking-wider text-white">
          {{ t('hosting.preview_badge') }}
        </span>
        <span class="text-sm font-medium text-neutral-800">{{ t('hosting.preview_warning') }}</span>
        <RouterLink :to="{ path: '/hosting' }" :class="[btnOutline('neutral'), 'ml-auto']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('hosting.preview_stop') }}
        </RouterLink>
      </div>
      <p class="mt-2 text-xs text-neutral-800/80">
        {{ t('hosting.preview_scenario', { name: t(`hosting.preview_name_${previewScenario}`) }) }}
      </p>
    </div>

    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('hosting.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('hosting.subtitle') }}</p>
    </header>

    <div v-if="loading && !status" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <!-- Self-hosted — stránka nemá co ukázat a nesmí nic slibovat. -->
    <div
      v-else-if="status && !isManaged"
      class="rounded-lg border border-neutral-200 bg-surface p-5 text-sm text-neutral-700"
      data-hosting-selfhosted
    >
      <p class="font-medium text-neutral-900">{{ t('hosting.self_hosted_title') }}</p>
      <p class="mt-1">{{ t('hosting.self_hosted_desc') }}</p>
      <RouterLink to="/activation/license" class="mt-3 inline-block text-primary-600 hover:text-primary-800 hover:underline">
        {{ t('nav.license') }} →
      </RouterLink>
    </div>

    <div v-else-if="status && instance" class="space-y-6" data-hosting-managed>
      <!-- ── FÁZE NEUHRAZENÍ ────────────────────────────────────────────────
           Dvě věty: co se stalo a co bude. Termíny počítá licenční server;
           co neposlal, se tu neobjeví ani náhodou. -->
      <section
        v-if="narrative"
        id="platba"
        class="rounded-lg border p-5"
        :class="TONE_ROW[billingTone]"
        data-hosting-phase
      >
        <div class="flex items-start gap-3">
          <svg
            class="w-5 h-5 mt-0.5 shrink-0"
            :class="billingTone === 'critical' ? 'text-danger-600' : 'text-warning-600'"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"
          ><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
          <div class="min-w-0">
            <h2 class="text-lg font-semibold text-neutral-900">{{ happenedText }}</h2>
            <p class="mt-1 text-sm" :class="billingTone === 'critical' ? 'text-danger-600' : 'text-warning-600'">
              {{ nextText }}
            </p>
          </div>
        </div>

        <!-- Časová osa. Chybějící milník se prostě nevykreslí — nedoplňuje se. -->
        <ol v-if="milestones.length" class="mt-4 space-y-1.5 text-sm" data-hosting-milestones>
          <li v-for="m in milestones" :key="m.kind" class="flex flex-wrap items-baseline gap-x-2">
            <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-neutral-400" aria-hidden="true"></span>
            <span class="text-neutral-700">{{ t(`hosting.phase.milestone_${m.kind}`) }}</span>
            <span class="font-medium text-neutral-900 whitespace-nowrap">{{ fmtDate(m.at) }}</span>
          </li>
        </ol>

        <!-- Zavřené placené moduly: uživatel musí vidět, CO konkrétně nejde
             a co dál funguje. „Komerční funkce nedostupné" nikomu neřekne nic. -->
        <div v-if="featuresLocked" class="mt-4 grid gap-4 sm:grid-cols-2" data-hosting-locked>
          <div class="rounded-md border border-danger-500/40 bg-surface p-3">
            <h3 class="text-xs uppercase tracking-wider text-danger-600">{{ t('hosting.locked_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-700">
              <li v-for="(item, i) in lockedFeatures" :key="'lf' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
          <div class="rounded-md border border-success-500/40 bg-surface p-3">
            <h3 class="text-xs uppercase tracking-wider text-success-700">{{ t('hosting.open_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-700">
              <li v-for="(item, i) in openFeatures" :key="'of' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <RouterLink to="/activation/purchase" :class="btnFilled(billingTone === 'critical' ? 'danger' : 'warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ t('hosting.unpaid_cta') }}
          </RouterLink>
          <a v-if="links?.subscription" :href="links.subscription" target="_blank" rel="noopener" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('license.managed_subscription_cta') }}
          </a>
        </div>

        <p class="mt-3 text-xs text-neutral-600">
          {{ t('hosting.unpaid_source', {
            state: t('license.state_' + (billing?.license_state ?? 'active')),
            checked: fmtDateTime(billing?.last_check_at ?? null),
          }) }}
        </p>
      </section>

      <!-- ── CO MI DOCHÁZÍ ──────────────────────────────────────────────────
           Kreslí se jen tehdy, když je co řešit. -->
      <section
        v-if="attention.length"
        class="rounded-lg border border-warning-500/40 bg-warning-50/40 px-4 py-3"
        data-hosting-attention
      >
        <p class="text-sm font-medium text-warning-600">{{ t('hosting.attention_title') }}</p>
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <li v-for="item in attention" :key="item.key">
            <a :href="item.anchor" class="text-primary-700 hover:text-primary-900 hover:underline">
              {{ t(item.key) }} →
            </a>
          </li>
        </ul>
      </section>

      <!-- ── CO MÁM ─────────────────────────────────────────────────────────
           Řádek v pořádku je šedý a bez tlačítka. Křičí jen to, co se má řešit. -->
      <section>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.overview_title') }}</h2>
            <p class="mt-0.5 text-sm text-neutral-600">{{ t('hosting.overview_desc') }}</p>
          </div>
          <!-- ⚠️ Rozsah se do instalace propíše až novým licenčním tokenem, a ten
               se obnovuje jednou denně. Po platbě, která proběhla jinde než tady,
               by zákazník do zítřka koukal na staré počty. -->
          <button
            v-if="auth.isSuperadmin"
            type="button"
            :class="btnOutline('primary')"
            :disabled="refreshing"
            data-hosting-refresh
            @click="refreshEntitlement"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ refreshing ? t('hosting.refresh_busy') : t('hosting.refresh_cta') }}
          </button>
        </div>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
          <!-- Tarif licence -->
          <li id="tarif" class="rounded-lg border p-4" :class="TONE_ROW[companiesTone]" data-hosting-row="tier">
            <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_tier') }}</p>
            <p class="mt-0.5 text-base font-semibold text-neutral-900">{{ tierLabel }}</p>
            <p class="mt-1 text-sm text-neutral-600">
              {{ t('hosting.row_companies_value', {
                used: status.companies_active,
                max: status.max_companies === null ? '∞' : status.max_companies,
              }) }}
            </p>
            <div v-if="companiesTone !== 'ok'" class="mt-3 flex flex-wrap items-center gap-2">
              <span class="rounded px-2 py-0.5 text-xs font-medium" :class="TONE_BADGE[companiesTone]">
                {{ t(companiesTone === 'critical' ? 'hosting.badge_over' : 'hosting.badge_at_limit') }}
              </span>
              <!-- ⚠️ Jediná akce, která vede ven: mění se PRODUKT, ne rozsah.
                   Uživatele i místo si zákazník dokoupí tady v aplikaci. -->
              <a v-if="links?.subscription" :href="links.subscription" target="_blank" rel="noopener" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
                {{ t('hosting.change_tier_cta') }}
              </a>
              <RouterLink v-else to="/activation/purchase" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
                {{ t('hosting.change_tier_cta') }}
              </RouterLink>
            </div>
          </li>

          <!-- Uživatelé -->
          <li class="rounded-lg border p-4" :class="TONE_ROW[usersTone]" data-hosting-row="users">
            <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_users') }}</p>
            <p class="mt-0.5 text-base font-semibold text-neutral-900">
              {{ status.users_active }} / {{ status.users_licensed > 0 ? status.users_licensed : '∞' }}
            </p>
            <p class="mt-1 text-sm text-neutral-600">{{ t('hosting.row_users_hint') }}</p>
            <div v-if="usersTone !== 'ok'" class="mt-3 flex flex-wrap items-center gap-2">
              <span class="rounded px-2 py-0.5 text-xs font-medium" :class="TONE_BADGE[usersTone]">
                {{ t(usersTone === 'critical' ? 'hosting.badge_over' : 'hosting.badge_at_limit') }}
              </span>
              <a href="#uzivatele" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" /></svg>
                {{ t('hosting.buy_users_cta') }}
              </a>
            </div>
          </li>

          <!-- Licenční klíč -->
          <li id="klic" class="rounded-lg border p-4" :class="TONE_ROW[licenseKeyTone]" data-hosting-row="key">
            <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_key') }}</p>
            <p class="mt-0.5 text-base font-semibold text-neutral-900 font-mono">
              {{ status.license_key_masked ?? '—' }}
            </p>
            <p class="mt-1 text-sm text-neutral-600">{{ t('hosting.row_key_hint') }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <span v-if="licenseKeyTone !== 'ok'" class="rounded px-2 py-0.5 text-xs font-medium" :class="TONE_BADGE[licenseKeyTone]">
                {{ t('hosting.badge_no_key') }}
              </span>
              <RouterLink to="/activation/purchase#activate" :class="licenseKeyTone === 'ok' ? btnOutline('neutral') : btnFilled('danger')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                {{ status.license_key_masked ? t('hosting.change_key_cta') : t('license.activate') }}
              </RouterLink>
            </div>
          </li>

          <!-- Tarif provozu -->
          <li class="rounded-lg border border-neutral-200 bg-surface p-4" data-hosting-row="plan">
            <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_plan') }}</p>
            <p class="mt-0.5 text-base font-semibold text-neutral-900">{{ planLabel }}</p>
            <p v-if="managedSinceLabel" class="mt-1 text-sm text-neutral-600">
              {{ t('license.managed_since') }}: {{ managedSinceLabel }}
            </p>
          </li>

          <!-- Stav platby -->
          <li class="rounded-lg border p-4" :class="TONE_ROW[billingTone]" data-hosting-row="billing">
            <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_billing') }}</p>
            <p class="mt-0.5 text-base font-semibold text-neutral-900">
              <template v-if="status.state === 'trial'">{{ t('license.state_trial') }}</template>
              <template v-else>{{ billingLabel }}</template>
            </p>
            <p class="mt-1 text-sm text-neutral-600">
              <template v-if="status.state === 'trial'">
                {{ t('license.trial_ends') }}: {{ fmtDate(status.trial_ends_at) }}
              </template>
              <template v-else-if="status.perpetual">{{ t('license.perpetual_validity') }}</template>
              <template v-else>{{ t('hosting.paid_until', { date: fmtDate(paidUntil) }) }}</template>
            </p>
            <div v-if="subscription" class="mt-1 text-sm" :class="subscription.auto_renew ? 'text-neutral-600' : 'text-warning-600'">
              {{ subscription.auto_renew ? t('license.renewal_on') : t('license.renewal_off') }}
            </div>
          </li>

          <!-- Místo -->
          <li id="misto" class="rounded-lg border p-4 sm:col-span-2" :class="TONE_ROW[storageTone]" data-hosting-row="storage">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.row_storage') }}</p>

                <p v-if="storageMode === 'unmeasured'" class="mt-1 text-sm text-neutral-600">
                  {{ t('license.managed_storage_unmeasured') }}
                </p>

                <template v-else-if="storageMode === 'unknown_quota'">
                  <p class="mt-0.5 text-base font-semibold text-neutral-900">{{ usedLabel }}</p>
                  <p class="mt-1 text-sm text-neutral-600">{{ t('license.managed_storage_quota_unknown') }}</p>
                </template>

                <template v-else>
                  <p class="mt-0.5 text-base font-semibold text-neutral-900">
                    {{ usedLabel }}
                    <span class="font-normal text-neutral-500"> / {{ quotaLabel }}</span>
                    <span class="ml-2 text-sm font-medium">{{ fmtPercent(storage?.percent) }}</span>
                  </p>
                </template>
              </div>

              <div v-if="storageTone !== 'ok'" class="flex shrink-0 flex-wrap items-center gap-2">
                <span class="rounded px-2 py-0.5 text-xs font-medium" :class="TONE_BADGE[storageTone]">
                  {{ t(storageLevel === 'exhausted' ? 'hosting.badge_exhausted' : 'hosting.badge_running_out') }}
                </span>
              </div>
            </div>

            <div
              v-if="storageMode === 'known'"
              class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-neutral-200"
              role="progressbar" :aria-valuenow="Math.round(storageBarWidth)" aria-valuemin="0" aria-valuemax="100"
            >
              <div
                class="h-full rounded-full transition-all"
                :class="storageTone === 'critical' ? 'bg-danger-500' : storageTone === 'warning' ? 'bg-warning-500' : storageTone === 'notice' ? 'bg-primary-500' : 'bg-success-500'"
                :style="{ width: storageBarWidth + '%' }"
              ></div>
            </div>

            <p v-if="storage?.measured_at" class="mt-2 text-xs text-neutral-500">
              {{ t('license.managed_storage_measured_at', { at: fmtDateTime(storage?.measured_at ?? null) }) }}
            </p>

            <p v-if="storageLevel === 'exhausted'" class="mt-3 text-sm text-danger-600">
              {{ t('license.managed_storage_exhausted_desc') }}
            </p>
            <p v-else-if="storageLevel === 'warning'" class="mt-3 text-sm text-warning-600">
              {{ t('license.managed_storage_warning_desc', { percent: storage?.read_only_percent ?? 100 }) }}
            </p>
            <p v-else-if="storageLevel === 'notice'" class="mt-3 text-sm text-primary-800">
              {{ t('hosting.storage_notice_desc') }}
            </p>
          </li>
        </ul>
      </section>

      <!-- ── ROZŠÍŘENÍ MÍSTA ────────────────────────────────────────────────
           ⚠️ Když se rozšíření zavádí, nabídka se NEKRESLÍ. Zákazník už
           zaplatil a druhé kliknutí by strhlo podruhé. -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5" data-hosting-storage-order>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.storage_order_title') }}</h2>

        <div
          v-if="changePending"
          class="mt-3 rounded-md border border-primary-300 bg-primary-50/60 p-3 text-sm text-primary-800"
          data-hosting-storage-pending
        >
          <p class="font-medium">{{ t('hosting.storage_provisioning_title') }}</p>
          <p class="mt-1">
            {{ storage?.quota_gb_ordered
              ? t('hosting.storage_provisioning_desc', { gb: storage.quota_gb_ordered })
              : t('hosting.storage_provisioning_desc_nogb') }}
          </p>
        </div>

        <template v-else>
          <p class="mt-1 text-sm text-neutral-600">{{ t('hosting.storage_order_desc') }}</p>

          <template v-if="offerVisible">
            <div class="mt-4 flex flex-wrap gap-2" data-hosting-storage-sizes>
              <button
                v-for="gb in sizeOptions" :key="gb" type="button"
                :disabled="quotingStorage || buyingStorage || previewing"
                :class="selectedGb === gb ? btnFilled('primary') : btnOutline('primary')"
                @click="pickSize(gb)"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.box" /></svg>
                {{ t('hosting.storage_size_option', { gb }) }}
              </button>
            </div>

            <p v-if="quotingStorage" class="mt-3 text-sm text-neutral-500">{{ t('hosting.storage_quoting') }}</p>

            <!-- ⚠️ Obě čísla, ne jen jedno: jednorázový doplatek TEĎ a o kolik
                 se natrvalo zvedne pravidelná platba. -->
            <div v-if="storageQuote" class="mt-4 rounded-md border border-primary-300 bg-primary-50/40 p-4 text-sm" data-hosting-storage-quote>
              <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                  <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.storage_amount_now') }}</dt>
                  <dd class="mt-0.5 text-lg font-semibold text-neutral-900">{{ fmtAmount(storageQuote.amount, storageQuote.currency) }}</dd>
                </div>
                <div>
                  <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('hosting.storage_recurring_delta') }}</dt>
                  <dd class="mt-0.5 text-lg font-semibold text-neutral-900">
                    {{ storageQuote.recurring_delta === null ? '—' : `+ ${fmtAmount(storageQuote.recurring_delta, storageQuote.currency)}` }}
                  </dd>
                </div>
              </dl>
              <p class="mt-3 text-neutral-700">
                {{ t('hosting.storage_from_to', {
                  from: storageQuote.current_quota_gb ?? '—',
                  to: storageQuote.new_quota_gb,
                }) }}
              </p>
              <p v-if="fmtPeriodEnd(storageQuote.period_end)" class="mt-1 text-xs text-neutral-500">
                {{ t('hosting.storage_period_end', { date: fmtPeriodEnd(storageQuote.period_end) }) }}
              </p>

              <!-- Musí být jasné, odkud peníze odejdou. -->
              <p class="mt-3 font-medium text-neutral-900">{{ t('hosting.storage_card_notice') }}</p>

              <button
                type="button" :disabled="buyingStorage || previewing"
                :class="[btnFilled('success'), 'mt-3']"
                @click="buyStorage"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
                {{ buyingStorage ? t('hosting.storage_ordering') : t(storageQuote.scheduled ? 'license.schedule_change_cta' : 'hosting.storage_order_cta') }}
              </button>
              <p v-if="previewing" class="mt-2 text-xs text-neutral-800">{{ t('hosting.preview_no_orders') }}</p>
            </div>
          </template>

          <p v-else-if="!offerClosed && sizeOptions.length === 0" class="mt-3 text-sm text-neutral-600">
            {{ t('hosting.storage_largest') }}
          </p>
        </template>

        <div v-if="storageDone" class="mt-4 rounded-md border border-success-500/40 bg-success-50 p-3 text-sm text-success-700" data-hosting-storage-done>
          {{ storageDone }}
        </div>
        <div v-if="storageError" class="mt-4 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600" data-hosting-storage-error>
          {{ storageError }}
        </div>

        <p v-if="!links?.subscription && !links?.expand_storage" class="mt-3 text-xs text-neutral-500">
          {{ t('license.managed_subscription_contact') }}
        </p>
      </section>

      <!-- ── DOKOUPENÍ UŽIVATELŮ ────────────────────────────────────────────
           Tentýž tok jako v Aktivaci; tady proto, že přehled má na otázku
           „co s tím" odpovědět akcí, ne odkazem jinam. -->
      <section v-if="canBuyUsers" id="tarif" class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.tier_change_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('license.tier_change_desc') }}</p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
          <label class="block">
            <span class="mb-1 block text-xs uppercase tracking-wider text-neutral-500">{{ t('license.tier_target') }}</span>
            <select v-model="targetTier" class="h-9 rounded-md border border-neutral-300 px-2 text-sm" @change="tierQuote = null">
              <option value="single">{{ t('license.tier_single') }}</option>
              <option value="multi10">{{ t('license.tier_multi10') }}</option>
              <option value="unlimited">{{ t('license.tier_unlimited') }}</option>
            </select>
          </label>
          <button type="button" :disabled="quotingTier || previewing || targetTier === status?.tier" :class="btnOutline('primary')" data-hosting-tier-quote @click="calcTierQuote">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ quotingTier ? t('license.upgrade_quoting') : t('license.upgrade_quote_cta') }}
          </button>
        </div>
        <div v-if="tierQuote" class="mt-4 rounded-md border border-primary-300 bg-primary-50/40 p-4 text-sm">
          <p class="font-medium text-neutral-900">{{ tierQuote.scheduled ? t('license.tier_decrease_next_period') : t('license.upgrade_amount', { amount: fmtAmount(tierQuote.amount, tierQuote.currency) }) }}</p>
          <p v-if="tierQuote.effective_at" class="mt-1 text-neutral-600">{{ t('license.effective_at', { date: fmtPeriodEnd(tierQuote.effective_at) }) }}</p>
          <button type="button" :disabled="changingTier || previewing" :class="[btnFilled(tierQuote.scheduled ? 'warning' : 'success'), 'mt-3']" data-hosting-tier-apply @click="applyTierChange">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ tierQuote.scheduled ? t('license.schedule_change_cta') : t('license.upgrade_pay_cta') }}
          </button>
        </div>
        <p v-if="tierMessage" class="mt-3 text-sm text-success-700">{{ tierMessage }}</p>
        <p v-if="tierError" class="mt-3 text-sm text-danger-600">{{ tierError }}</p>
        <p v-if="previewing" class="mt-2 text-xs text-neutral-800">{{ t('hosting.preview_no_orders') }}</p>
      </section>

      <section v-if="canBuyUsers" id="uzivatele" class="rounded-lg border border-neutral-200 bg-surface p-5" data-hosting-users-order>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.upgrade_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('license.upgrade_desc') }}</p>

        <div class="mt-4 flex flex-wrap items-end gap-3">
          <label class="block">
            <span class="block text-xs uppercase tracking-wider text-neutral-500 mb-1">{{ t('license.upgrade_users_label') }}</span>
            <input
              v-model.number="upgradeUsers" type="number" min="1" step="1"
              class="w-28 h-9 rounded-md border border-neutral-300 px-2 text-sm"
            />
          </label>
          <button
            type="button" :disabled="quotingUsers || previewing || !upgradeUsers || upgradeUsers < 1"
            :class="btnOutline('primary')"
            @click="calcUserQuote"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ quotingUsers ? t('license.upgrade_quoting') : t('license.upgrade_quote_cta') }}
          </button>
        </div>

        <div v-if="userQuote" class="mt-4 rounded-md border border-primary-300 bg-primary-50/40 p-4 text-sm">
          <p class="text-lg font-semibold text-neutral-900">
            {{ t('license.upgrade_amount', { amount: fmtAmount(userQuote.amount, userQuote.currency) }) }}
          </p>
          <p class="mt-1 text-neutral-700">
            {{ t('license.upgrade_from_to', { from: userQuote.current_users ?? '—', to: userQuote.new_users }) }}
          </p>
          <p class="mt-3 font-medium text-neutral-900">{{ t('hosting.storage_card_notice') }}</p>
          <button
            type="button" :disabled="upgradingUsers || previewing"
            :class="[btnFilled('success'), 'mt-3']"
            @click="buyUsers"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ upgradingUsers ? t('license.upgrading') : t(userQuote.scheduled ? 'license.schedule_change_cta' : 'license.upgrade_pay_cta') }}
          </button>
          <p v-if="previewing" class="mt-2 text-xs text-neutral-800">{{ t('hosting.preview_no_orders') }}</p>
        </div>

        <div v-if="userDone" class="mt-4 rounded-md border border-success-500/40 bg-success-50 p-3 text-sm text-success-700">{{ userDone }}</div>
        <div v-if="userError" class="mt-4 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">{{ userError }}</div>
      </section>

      <!-- Co provoz zahrnuje a co ne -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.scope_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('license.managed_intro') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_included_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-700">
              <li v-for="(item, i) in included" :key="'inc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_excluded_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-600">
              <li v-for="(item, i) in excluded" :key="'exc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
        <p class="mt-4 text-xs text-neutral-500">{{ t('hosting.restore_note') }}</p>
      </section>

      <!-- Kam se obrátit. Odkaz kreslíme JEN když adresu známe — mrtvé tlačítko
           je horší než žádné. -->
      <section v-if="links?.subscription || links?.support" class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('hosting.links_title') }}</h2>
        <div class="mt-4 flex flex-wrap gap-2">
          <a v-if="links?.subscription" :href="links.subscription" target="_blank" rel="noopener" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('license.managed_subscription_cta') }}
          </a>
          <a v-if="links?.support" :href="links.support" target="_blank" rel="noopener" :class="btnOutline('warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
            {{ t('hosting.report_outage_cta') }}
          </a>
        </div>
      </section>

      <p class="text-xs text-neutral-500">
        <RouterLink to="/activation/purchase" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.purchase_subscription') }}</RouterLink>
        ·
        <RouterLink to="/activation/license" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.license') }}</RouterLink>
        ·
        <RouterLink to="/activation/terms" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.terms') }}</RouterLink>
      </p>

      <!-- Rozcestník náhledu — jen superadmin, jen jako poslední věc na stránce.
           ⚠️ Nikdy se nezapíná sám; každý stav je jeden odkaz. -->
      <section v-if="auth.isSuperadmin" class="rounded-lg border border-dashed border-neutral-300 p-4" data-hosting-preview-switch>
        <h2 class="text-sm font-semibold text-neutral-700">{{ t('hosting.preview_title') }}</h2>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('hosting.preview_desc') }}</p>
        <div class="mt-3 flex flex-wrap gap-1.5">
          <RouterLink
            v-for="scenario in previewScenarios" :key="scenario"
            :to="{ path: '/hosting', query: { nahled: scenario } }"
            class="rounded-md border px-2.5 py-1 text-xs font-medium"
            :class="previewScenario === scenario
              ? 'border-accent-500 bg-accent-600 text-white'
              : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'"
          >
            {{ t(`hosting.preview_name_${scenario}`) }}
          </RouterLink>
          <RouterLink
            v-if="previewing" :to="{ path: '/hosting' }"
            class="rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50"
          >
            {{ t('hosting.preview_stop') }}
          </RouterLink>
        </div>
      </section>
    </div>

    <div v-if="errorMsg" class="mt-4 rounded-md bg-danger-50 border border-danger-500/40 p-4 text-sm text-danger-600">
      {{ errorMsg }}
    </div>
  </div>
</template>
