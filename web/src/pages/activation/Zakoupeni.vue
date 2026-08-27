<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { licenseApi, type LicenseStatus, type LicenseStateKind, type TierQuote, type UpgradeQuote } from '@/api/license'
import { formatQuotaBytes } from '@/api/storageQuota'
import { ensureInstanceDunning, instanceStatus } from '@/api/instanceStatus'
import { resolveBillingNarrative } from '@/api/instanceHealth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t, te, tm, rt } = useI18n()
const auth = useAuthStore()

const status = ref<LicenseStatus | null>(null)
const loading = ref(true)
const errorMsg = ref<string | null>(null)

const keyInput = ref('')
const activating = ref(false)
const deactivating = ref(false)
const activateError = ref<string | null>(null)
// Klíč obsazený na jiné instalaci — nabídneme přenos (takeover).
const alreadyBound = ref(false)
const transfersRemaining = ref<number | null>(null)

const isAdmin = computed(() => auth.isSuperadmin)

// ─────────────────────────────────────────────────────────────────────────────
//  Spravovaná instalace (SaaS)
//
//  Přepínač je PŘÍTOMNOST bloku `instance` v odpovědi, ne příznak z /me:
//  backend ho posílá jen když `app.managed` platí, takže self-hosted instalace
//  se na tuhle větev nedostane ani ve chvíli, kdy setup-status ještě nedorazil.
//  Self-hosted obrazovka je hlavní cesta k nákupu licence a nesmí se změnit.
// ─────────────────────────────────────────────────────────────────────────────
const managedInstance = computed(() => status.value?.instance ?? null)
const isManaged = computed(() => managedInstance.value !== null)
const storage = computed(() => managedInstance.value?.storage ?? null)

/**
 * ⚠️ Tři stavy místa, které se nesmí slít do jednoho:
 *  - `unmeasured` — spotřeba se ještě neměřila. NENÍ to nula: prázdná
 *    a nezměřená instalace vypadají v datech stejně a znamenají opak.
 *  - `unknown_quota` — víme, kolik je obsazeno, ale ne z kolika. Procenta ani
 *    pruh se nekreslí; vydělit něčím, co neznáme, znamená vymyslet si číslo.
 *  - `known` — obojí známe, teprve tady má smysl poměr.
 */
const storageMode = computed<'unmeasured' | 'unknown_quota' | 'known'>(() => {
  const s = storage.value
  if (!s || !s.measured || s.usage_bytes === null) return 'unmeasured'
  if (s.quota_bytes === null || s.percent === null) return 'unknown_quota'
  return 'known'
})

/** Úroveň pro barvu a výzvu. Skutečné vynucení (blocks_writes) přebíjí poměr. */
const storageLevel = computed<'none' | 'ok' | 'warning' | 'exhausted'>(() => {
  const s = storage.value
  if (!s) return 'none'
  if (s.blocks_writes) return 'exhausted'
  if (storageMode.value !== 'known' || s.percent === null) return 'none'
  if (s.percent >= s.read_only_percent) return 'exhausted'
  if (s.percent >= s.warn_percent) return 'warning'
  return 'ok'
})

const STORAGE_STYLE: Record<'none' | 'ok' | 'warning' | 'exhausted', { card: string; bar: string }> = {
  none:      { card: 'border-neutral-200 bg-surface',            bar: 'bg-neutral-300' },
  ok:        { card: 'border-neutral-200 bg-surface',            bar: 'bg-success-500' },
  warning:   { card: 'border-warning-300 bg-warning-50/40',      bar: 'bg-warning-500' },
  exhausted: { card: 'border-danger-300 bg-danger-50/40',        bar: 'bg-danger-500' },
}
const storageStyle = computed(() => STORAGE_STYLE[storageLevel.value])

/** Šířka pruhu. Přes 100 % se pruh nepřetáčí, poměr v textu zůstává pravdivý. */
const storageBarWidth = computed(() => {
  const p = storage.value?.percent
  if (storageMode.value !== 'known' || p === null || p === undefined) return 0
  return Math.max(0, Math.min(100, p))
})

const usedLabel = computed(() => formatQuotaBytes(storage.value?.usage_bytes ?? null))
const quotaLabel = computed(() => formatQuotaBytes(storage.value?.quota_bytes ?? null))

function fmtPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} %`
}

/** Tarif provozu. Neznámý kód se ukáže tak, jak přišel — nevymýšlíme mu název. */
const planLabel = computed(() => {
  const plan = managedInstance.value?.plan
  if (!plan) return t('license.managed_plan_unknown')
  const key = `license.managed_plan_${plan}`
  return te(key) ? t(key) : plan
})

/** Správa předplatného na webu; null = adresa není nakonfigurovaná → kontakt. */
const subscriptionUrl = computed(() => managedInstance.value?.subscription_url ?? null)

const managedSinceLabel = computed(() => {
  const raw = managedInstance.value?.managed_since
  if (!raw) return null
  const parsed = new Date(raw)
  return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleDateString()
})

/** Co provoz zahrnuje / co v něm není — pole překladů přes tm() + rt(). */
const managedIncluded = computed(() => (tm('license.managed_included') as unknown[]).map(item => rt(item as string)))
const managedExcluded = computed(() => (tm('license.managed_excluded') as unknown[]).map(item => rt(item as string)))

/** Veřejný portál podpory — fallback, když identita přes licenční server nevyjde. */
const SUPPORT_PORTAL_URL = 'https://myucto.cz/support'

// In-place navýšení počtu uživatelů (poměrný doplatek z uložené karty).
const upgradeUsers = ref<number>(1)
const quoting = ref(false)
const upgrading = ref(false)
const quote = ref<UpgradeQuote | null>(null)
const upgradeError = ref<string | null>(null)
const upgradeSuccess = ref<string | null>(null)
const targetTier = ref('single')
const tierQuote = ref<TierQuote | null>(null)
const tierBusy = ref(false)
const tierError = ref<string | null>(null)
const tierSuccess = ref<string | null>(null)

// Automatické prodlužování předplatného (vypnutí = licence doběhne do valid_until).
const cancellingRenewal = ref(false)
const resumingRenewal = ref(false)
const renewalSuccess = ref<string | null>(null)
const renewalError = ref<string | null>(null)
const purchasing = ref(false)
const purchaseSuccess = ref<string | null>(null)
const purchaseError = ref<string | null>(null)

/**
 * Fragment se odstraní synchronně při vytvoření komponenty, ještě před prvním
 * await nebo síťovým voláním. Token se tak nedostane do historie, logů ani
 * případné diagnostiky stránky.
 */
const purchaseReturn = (() => {
  const raw = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : ''
  const params = new URLSearchParams(raw)
  if (!params.has('purchase') && !params.has('state')) return null
  const purchase = params.get('purchase') ?? ''
  const state = params.get('state') ?? ''
  window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}`)
  return { purchase, state }
})()

/** Stav předplatného z licenčního serveru; null = licence se neprodlužuje. */
const subscription = computed(() => status.value?.subscription ?? null)
const canStartPurchase = computed(() => subscription.value === null || subscription.value.state === 'expired')
/** Konec zaplaceného období — do něj licence poběží i po zrušení obnovy. */
const paidUntil = computed(() => subscription.value?.valid_until ?? status.value?.valid_until ?? null)
const periodLabel = computed(() =>
  subscription.value?.period === 'year' ? t('license.renewal_period_year') : t('license.renewal_period_month'),
)

/** Navýšení má smysl jen u aktivního placeného předplatného (aktivní klíč). */
const canUpgrade = computed(() => {
  const s = status.value
  return !!s && !!s.license_key_masked && (s.state === 'active' || s.state === 'overage')
})

/** Přečerpání rozsahu licence — víc aktivních uživatelů / firem, než licencuje klíč. */
const usersOverage = computed(() => {
  const s = status.value
  return !!s && s.users_licensed > 0 && s.users_active > s.users_licensed
})
const companiesOverage = computed(() => {
  const s = status.value
  return !!s && s.max_companies !== null && s.companies_active > s.max_companies
})
const hasOverage = computed(() => usersOverage.value || companiesOverage.value)

/** Barevný akcent karty stavu dle stavu licence. */
const STATE_STYLE: Record<LicenseStateKind, { card: string; badge: string }> = {
  active:        { card: 'border-success-300 bg-success-50/40', badge: 'bg-success-100 text-success-700' },
  trial:         { card: 'border-primary-300 bg-primary-50/40',  badge: 'bg-primary-100 text-primary-700' },
  overage:       { card: 'border-warning-300 bg-warning-50/40',  badge: 'bg-warning-100 text-warning-800' },
  trial_expired: { card: 'border-danger-300 bg-danger-50/40',    badge: 'bg-danger-100 text-danger-700' },
  degraded:      { card: 'border-danger-300 bg-danger-50/40',    badge: 'bg-danger-100 text-danger-700' },
}

const stateStyle = computed(() => status.value ? STATE_STYLE[status.value.state] : STATE_STYLE.trial)

const tierLabel = computed(() => {
  const tier = status.value?.tier
  if (!tier) return '—'
  const map: Record<string, string> = {
    single: t('license.tier_single'),
    multi10: t('license.tier_multi10'),
    unlimited: t('license.tier_unlimited'),
  }
  return map[tier] ?? tier
})

/** Doplní jen pohodlné předvyplnění. Vazbu na instanci nese serverový handoff. */
function checkoutUrl(base: string): string {
  const s = status.value
  if (!s) return base
  const c = s.company
  const raw: Record<string, string> = {
    users: String(s.users_active),
    companies: String(s.companies_active),
    company: c?.name ?? '',
    ico: c?.ic ?? '',
    dic: c?.dic ?? '',
    street: c?.street ?? '',
    city: c?.city ?? '',
    zip: c?.zip ?? '',
    email: c?.email ?? '',
  }
  const params = new URLSearchParams()
  for (const [k, v] of Object.entries(raw)) {
    if (v !== '') params.set(k, v)
  }
  const query = params.toString()
  return query === '' ? base : `${base}${base.includes('?') ? '&' : '?'}${query}`
}

async function startPurchase(): Promise<void> {
  if (purchasing.value || !canStartPurchase.value) return
  purchasing.value = true
  purchaseError.value = null
  purchaseSuccess.value = null
  try {
    const result = await licenseApi.startPurchase()
    window.location.assign(checkoutUrl(result.buy_url))
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } }
    purchaseError.value = err.response?.data?.error?.message ?? t('license.purchase_start_failed')
    purchasing.value = false
  }
}

async function completePurchase(): Promise<void> {
  if (!purchaseReturn) return
  if (!/^[a-f0-9]{32,64}$/i.test(purchaseReturn.purchase)
    || !/^[A-Za-z0-9_-]{43}$/.test(purchaseReturn.state)) {
    purchaseError.value = t('license.purchase_return_invalid')
    return
  }
  try {
    status.value = await licenseApi.completePurchase(purchaseReturn.purchase, purchaseReturn.state)
    purchaseSuccess.value = t('license.purchase_auto_success')
    await auth.refresh()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } }
    purchaseError.value = err.response?.data?.error?.message ?? t('license.purchase_auto_failed')
  }
}

function fmtDate(ts: number | null): string {
  if (!ts) return '—'
  return new Date(ts * 1000).toLocaleDateString()
}
function fmtDateTime(s: string | null): string {
  if (!s) return '—'
  try { return new Date(s).toLocaleString() } catch { return s }
}

async function load() {
  loading.value = true
  errorMsg.value = null
  try {
    // ⚠️ Bez superadmin práv je `/license/status` zavřený. Dřív se tím obrazovka
    // vyčerpala („nemáte oprávnění") a admin, který instalaci spravuje, neměl
    // z aplikace jak doplatit. Dunning stav je proto vlastní, užší dotaz.
    if (!isAdmin.value) {
      await ensureInstanceDunning({ managed: auth.isManagedInstallation })
      return
    }
    status.value = await licenseApi.status()
    // Výchozí cílový počet = aktuální aktivní počet uživatelů.
    upgradeUsers.value = Math.max(status.value.users_active, 1)
    targetTier.value = status.value.tier ?? 'single'
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Nepodařilo se načíst stav licence.'
  } finally {
    loading.value = false
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Neuhrazená platba — rekapitulace a přímá cesta zaplatit
//
//  „Z aplikace musí jít doplatit." Dřív odsud vedl jediný odkaz na správu
//  předplatného na webu, bez částky a bez termínů — tři skoky, než se zákazník
//  dostal k platbě. Tahle sekce říká, co se stalo, kolik se dluží a dokdy,
//  a dává jedno primární tlačítko, které jde rovnou na úhradu.
// ─────────────────────────────────────────────────────────────────────────────

/** Dunning stav. Plný z `/license/status`, jinak užší z `/license/billing`. */
const dunning = computed(() => status.value?.instance?.billing ?? instanceStatus.dunning.value)

/** Co se stalo a co bude. `null` = není co hlásit → sekce se nekreslí. */
const narrative = computed(() => resolveBillingNarrative(dunning.value))

const dunningMilestones = computed(() => narrative.value?.milestones ?? [])

/** Věta „co se stalo" (+ kolikátý pokus, když to server poslal). */
const happenedText = computed(() => {
  const n = narrative.value
  if (!n) return null
  const base = t(n.happenedKey)
  if (n.attempt === null || n.maxAttempts === null) return base

  return `${base} ${t('hosting.phase.attempt_of', { attempt: n.attempt, max: n.maxAttempts })}`
})

/** Věta „co bude a kdy". Bez termínu varianta, která žádný neslibuje. */
const nextText = computed(() => {
  const n = narrative.value

  return n ? t(n.nextKey, { date: fmtDate(n.nextAt) }) : null
})

/** Dlužná částka. `null` = server ji neposlal a obrazovka o ní MLČÍ. */
const amountDueText = computed(() => {
  const amount = dunning.value?.amount_due
  if (amount === null || amount === undefined) return null

  return fmtAmount(amount, dunning.value?.currency ?? null)
})

/**
 * Kam vede „Zaplatit". Podepsaný odkaz z licenčního serveru; backend za něj
 * v nejhorším dosadí správu předplatného, takže tlačítko má kam vést vždycky.
 */
const payUrl = computed(() => dunning.value?.pay_url ?? subscriptionUrl.value ?? null)

/** Sekundárně „Změnit kartu" — jen když se liší od hlavního cíle. */
const changeCardUrl = computed(() => {
  const url = subscriptionUrl.value
  return url !== null && url !== payUrl.value ? url : null
})

const dunningCritical = computed(() => narrative.value?.severity === 'critical')

function fmtAmount(amount: number | null, currency: string | null): string {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'CZK' }).format(amount)
  } catch {
    return `${amount} ${currency ?? ''}`.trim()
  }
}

function upgradeErrMsg(e: unknown): string {
  const err = e as { response?: { data?: { error?: { message?: string } } } }
  return err.response?.data?.error?.message ?? t('license.upgrade_failed')
}

async function calcQuote() {
  if (quoting.value) return
  const n = Math.floor(Number(upgradeUsers.value))
  if (!n || n < 1) return
  quoting.value = true
  upgradeError.value = null
  upgradeSuccess.value = null
  quote.value = null
  try {
    quote.value = await licenseApi.upgradeQuote(n)
  } catch (e: unknown) {
    upgradeError.value = upgradeErrMsg(e)
  } finally {
    quoting.value = false
  }
}

async function doUpgrade() {
  if (upgrading.value || !quote.value) return
  const n = quote.value.new_users
  if (!confirm(t(quote.value.scheduled ? 'license.capacity_schedule_confirm' : 'license.upgrade_confirm', { n }))) return
  upgrading.value = true
  upgradeError.value = null
  upgradeSuccess.value = null
  try {
    let res = await licenseApi.upgrade(n, quote.value.quote_token)
    if (res.pending && res.order_id) {
      const settled = await licenseApi.waitForChange(res.order_id)
      if (settled.applied && settled.license) res = { ...res, pending: false, state: settled.license }
    }
    status.value = res.state
    quote.value = null
    upgradeSuccess.value = res.scheduled
      ? t('license.change_scheduled', { date: fmtEffective(res.effective_at) })
      : res.pending
        ? t('license.change_pending')
        : t('license.upgrade_success', { n: res.new_users })
    upgradeUsers.value = Math.max(res.state.users_active, res.new_users)
    // Obnov /me, ať zmizí overage banner a projeví se nový limit.
    await auth.refresh()
  } catch (e: unknown) {
    upgradeError.value = upgradeErrMsg(e)
  } finally {
    upgrading.value = false
  }
}

function fmtEffective(value: number | string | null): string {
  if (!value) return '—'
  const date = new Date(typeof value === 'number' ? value * 1000 : value)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString()
}

async function calcTierQuote(): Promise<void> {
  if (tierBusy.value || targetTier.value === status.value?.tier) return
  tierBusy.value = true
  tierError.value = null
  tierSuccess.value = null
  try {
    tierQuote.value = await licenseApi.tierQuote(targetTier.value)
  } catch (e: unknown) {
    tierError.value = upgradeErrMsg(e)
  } finally {
    tierBusy.value = false
  }
}

async function applyTierChange(): Promise<void> {
  const quote = tierQuote.value
  if (!quote || tierBusy.value) return
  if (!confirm(t(quote.scheduled ? 'license.tier_schedule_confirm' : 'license.tier_change_confirm'))) return
  tierBusy.value = true
  tierError.value = null
  try {
    let result = await licenseApi.changeTier(quote.new_tier, quote.quote_token)
    if (result.pending && result.order_id) {
      const settled = await licenseApi.waitForChange(result.order_id)
      if (settled.applied && settled.license) result = { ...result, pending: false, state: settled.license }
    }
    status.value = result.state
    targetTier.value = result.state.tier ?? quote.new_tier
    tierQuote.value = null
    tierSuccess.value = result.scheduled
      ? t('license.change_scheduled', { date: fmtEffective(result.effective_at) })
      : result.pending ? t('license.change_pending') : t('license.tier_change_success')
    await auth.refresh()
  } catch (e: unknown) {
    tierError.value = upgradeErrMsg(e)
  } finally {
    tierBusy.value = false
  }
}

/**
 * Vypnutí automatického prodlužování. Není to deaktivace — licence běží dál do
 * konce zaplaceného období, jen se nestrhne další platba.
 */
async function cancelRenewal() {
  if (cancellingRenewal.value) return
  const confirmKey = isManaged.value
    ? 'license.renewal_cancel_confirm_managed'
    : 'license.renewal_cancel_confirm'
  if (!confirm(t(confirmKey, { date: fmtDate(paidUntil.value) }))) return
  cancellingRenewal.value = true
  renewalError.value = null
  renewalSuccess.value = null
  try {
    const res = await licenseApi.cancelRenewal()
    status.value = res.state
    const date = fmtDate(res.valid_until ?? paidUntil.value)
    renewalSuccess.value = res.already_cancelled
      ? t('license.renewal_cancel_already', { date })
      : t('license.renewal_cancel_success', { date })
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } }
    renewalError.value = err.response?.data?.error?.message ?? t('license.renewal_cancel_failed')
  } finally {
    cancellingRenewal.value = false
  }
}

/**
 * Obnova zrušeného předplatného.
 *
 * ⚠️ Nejde jen přepnout příznak zpátky. Zrušením se u platební brány zneplatnil
 * mandát, takže bez nové karty by se příští obnova jen znovu nestrhla — a
 * zákazník by se to dozvěděl až tím, že mu přestane fungovat instalace. Server
 * proto vrací adresu platby a předplatné se rozeběhne, teprve až projde.
 *
 * ⚠️ Bez potvrzovacího dialogu schválně: potvrzením je sama platební brána,
 * ze které se dá odejít. Dvě potvrzení nad jednou akcí se dřív nebo později
 * rozejdou v tom, co slibují.
 */
async function resumeRenewal() {
  if (resumingRenewal.value) return
  resumingRenewal.value = true
  renewalError.value = null
  renewalSuccess.value = null
  try {
    const res = await licenseApi.resumeRenewal()
    if (!res.pay_url) throw new Error('missing pay_url')
    window.location.href = res.pay_url
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } }
    renewalError.value = err.response?.data?.error?.message ?? t('license.renewal_resume_failed')
    // ⚠️ Odemknout JEN při chybě. Po úspěchu se odchází na bránu a odemčené
    // tlačítko by svádělo k druhému kliknutí, tedy k druhé platbě.
    resumingRenewal.value = false
  }
}

async function activate(takeover = false) {
  const key = keyInput.value.trim()
  if (!key || activating.value) return
  // Přenos vazby z jiné instalace potvrdíme (počítá se do limitu 2/30 dní).
  if (takeover && !confirm(t('license.takeover_confirm'))) return
  // ⚠️ Licence se NESČÍTAJÍ. Instalace nese vždy jeden klíč; aktivací dalšího
  // ten původní přestane platit. Kdo si koupil druhou licenci v domnění, že tím
  // získá dalšího uživatele, by jinak o tu první tiše přišel — a zjistil by to
  // až podle toho, že má míst pořád stejně.
  if (!takeover && status.value?.license_key_masked && !confirm(t('license.replace_confirm'))) return
  activating.value = true
  activateError.value = null
  try {
    status.value = await licenseApi.activate(key, takeover)
    keyInput.value = ''
    alreadyBound.value = false
    transfersRemaining.value = null
    // Obnov /me, ať zmizí bannery a zpřístupní se komerční moduly.
    await auth.refresh()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { code?: string; message?: string; transfers_remaining?: number } } } }
    const errObj = err.response?.data?.error
    const code = errObj?.code
    if (code === 'already_bound') {
      // Klíč je aktivní jinde → nabídneme tlačítko „přenést" (takeover).
      alreadyBound.value = true
      transfersRemaining.value = typeof errObj?.transfers_remaining === 'number' ? errObj.transfers_remaining : null
      activateError.value = null
    } else {
      alreadyBound.value = false
      activateError.value = errObj?.message ?? t('license.activate_failed')
    }
  } finally {
    activating.value = false
  }
}

async function deactivate() {
  if (deactivating.value) return
  if (!confirm(t('license.deactivate_confirm'))) return
  deactivating.value = true
  errorMsg.value = null
  try {
    const res = await licenseApi.deactivate()
    status.value = res.state
    await auth.refresh()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? t('license.deactivate_failed')
  } finally {
    deactivating.value = false
  }
}

/**
 * Přechod na portál podpory. Okno se otevírá synchronně (jinak ho blokátor
 * vyskakovacích oken zahodí) a cíl se doplní až po odpovědi serveru, který
 * u placené licence vrátí odkaz s jednorázovým přihlašovacím tokenem.
 */
const supportLinkBusy = ref(false)
async function openSupportPortal() {
  if (supportLinkBusy.value) return
  const tab = window.open('', '_blank')
  if (tab) tab.opener = null

  supportLinkBusy.value = true
  let url = SUPPORT_PORTAL_URL
  try {
    url = (await licenseApi.supportLink()).url || SUPPORT_PORTAL_URL
  } catch {
    url = SUPPORT_PORTAL_URL
  } finally {
    supportLinkBusy.value = false
  }

  if (tab) tab.location.replace(url)
  else window.open(url, '_blank', 'noopener')
}

onMounted(async () => {
  await completePurchase()
  await load()
  if (!isAdmin.value) return
  const settled = await licenseApi.resumePendingChanges()
  const latest = [...settled].reverse().find(change => change.applied && change.license)
  if (latest?.license) status.value = latest.license
  if (latest) await auth.refresh()
})
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">
        {{ isManaged ? t('license.managed_title') : t('license.purchase_title') }}
      </h1>
      <p class="text-sm text-neutral-500 mt-0.5">
        {{ isManaged ? t('license.managed_subtitle') : t('license.purchase_subtitle') }}
      </p>
    </header>

    <!-- ═══ NEUHRAZENÁ PLATBA — rekapitulace a přímá cesta zaplatit ═══
         Vykresluje se PŘED vším ostatním a bez ohledu na práva: dluh je jediná
         věc, kvůli které tahle obrazovka opravdu spěchá, a doplatit ho musí jít
         i adminovi bez superadmin práv. Termíny počítá licenční server; co
         neposlal, se tu neobjeví ani náhodou. -->
    <section
      v-if="narrative"
      class="mb-6 rounded-lg border p-5"
      :class="dunningCritical ? 'border-danger-300 bg-danger-50/40' : 'border-warning-300 bg-warning-50/40'"
      data-purchase-dunning
    >
      <div class="flex items-start gap-3">
        <svg
          class="w-5 h-5 mt-0.5 shrink-0"
          :class="dunningCritical ? 'text-danger-600' : 'text-warning-600'"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"
        ><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-neutral-900">{{ happenedText }}</h2>
          <p class="mt-1 text-sm" :class="dunningCritical ? 'text-danger-600' : 'text-warning-600'">
            {{ nextText }}
          </p>
        </div>
      </div>

      <!-- Kolik se dluží. Bez čísla ze serveru se řádek nekreslí — vymyšlená
           částka u tlačítka „Zaplatit" je horší než žádná. -->
      <p v-if="amountDueText" class="mt-4 text-sm text-neutral-700">
        {{ t('license.dunning_amount_due') }}
        <span class="ml-1 text-2xl font-semibold text-neutral-900">{{ amountDueText }}</span>
      </p>

      <!-- Časová osa: dokdy to jde zaplatit, kdy se instalace pozastaví a kdy
           se smažou data. Chybějící milník se prostě nevykreslí. -->
      <ol v-if="dunningMilestones.length" class="mt-4 space-y-1.5 text-sm" data-purchase-milestones>
        <li v-for="m in dunningMilestones" :key="m.kind" class="flex flex-wrap items-baseline gap-x-2">
          <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-neutral-400" aria-hidden="true"></span>
          <span class="text-neutral-700">{{ t(`hosting.phase.milestone_${m.kind}`) }}</span>
          <span class="font-medium text-neutral-900 whitespace-nowrap">{{ fmtDate(m.at) }}</span>
        </li>
      </ol>

      <div class="mt-5 flex flex-wrap gap-2">
        <a
          v-if="payUrl" :href="payUrl" target="_blank" rel="noopener"
          :class="btnFilled(dunningCritical ? 'danger' : 'warning')"
          data-purchase-pay
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ amountDueText ? t('license.dunning_pay_amount', { amount: amountDueText }) : t('license.dunning_pay') }}
        </a>
        <a
          v-if="changeCardUrl" :href="changeCardUrl" target="_blank" rel="noopener"
          :class="btnOutline('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('license.dunning_change_card') }}
        </a>
      </div>

      <!-- Nenakonfigurovaná adresa → kontakt, ne mrtvé tlačítko. -->
      <p v-if="!payUrl" class="mt-3 text-sm text-neutral-600">
        {{ t('license.managed_subscription_contact') }}
      </p>
    </section>

    <!-- Zbytek obrazovky (klíč, tarif, počty míst) zůstává superadminovi.
         Doplatit ale musí jít i bez toho — sekce výš je nad touhle větví. -->
    <div v-if="!isAdmin" class="rounded-md bg-warning-50 border border-warning-200 p-4 text-sm text-warning-800">
      {{ t('license.no_admin') }}
    </div>

    <div v-else-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <!-- ═══ Spravovaná instalace (SaaS) — stav služby místo nabídky koupit licenci ═══ -->
    <div v-else-if="status && managedInstance" class="space-y-6">
      <!-- Co spravovaný provoz znamená -->
      <section class="rounded-lg border border-primary-200 bg-primary-50/30 p-5">
        <div class="flex flex-wrap items-center gap-2">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 px-3 py-1 text-xs font-medium text-primary-700">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>
            {{ t('license.managed_badge') }}
          </span>
        </div>
        <p class="mt-3 text-sm text-neutral-700">{{ t('license.managed_intro') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_included_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-700">
              <li v-for="(item, i) in managedIncluded" :key="'inc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
          <div>
            <h3 class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_excluded_title') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-neutral-600">
              <li v-for="(item, i) in managedExcluded" :key="'exc' + i" class="flex gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Rozsah služby -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.managed_service_title') }}</h2>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_plan') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ planLabel }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_users') }}</dt>
            <dd class="mt-0.5 font-medium" :class="usersOverage ? 'text-danger-600' : 'text-neutral-900'">
              {{ status.users_active }} / {{ status.users_licensed > 0 ? status.users_licensed : '∞' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_valid_until') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">
              <template v-if="status.state === 'trial'">{{ fmtDate(status.trial_ends_at) }}</template>
              <template v-else-if="status.perpetual">{{ t('license.perpetual_validity') }}</template>
              <template v-else>{{ fmtDate(paidUntil) }}</template>
            </dd>
          </div>
          <div v-if="managedSinceLabel">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.managed_since') }}</dt>
            <dd class="mt-0.5 text-neutral-700">{{ managedSinceLabel }}</dd>
          </div>
          <div v-if="subscription">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.renewal_title') }}</dt>
            <dd class="mt-0.5 font-medium" :class="subscription.auto_renew ? 'text-success-700' : 'text-warning-800'">
              {{ subscription.auto_renew ? t('license.renewal_on') : t('license.renewal_off') }}
            </dd>
          </div>
        </dl>

        <div v-if="usersOverage" class="mt-4 rounded-md border border-warning-300 bg-warning-50/60 p-3 text-sm text-warning-800">
          {{ t('license.managed_users_overage') }}
        </div>
      </section>

      <!-- Místo -->
      <section class="rounded-lg border p-5" :class="storageStyle.card">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.managed_storage_title') }}</h2>

        <!-- ⚠️ Nezměřeno NENÍ nula — žádná procenta, žádný pruh, žádné „vše v pořádku". -->
        <p v-if="storageMode === 'unmeasured'" class="mt-2 text-sm text-neutral-600">
          {{ t('license.managed_storage_unmeasured') }}
        </p>

        <!-- ⚠️ Neznámý zaplacený objem — jen absolutní obsazení, poměr si nevymýšlíme. -->
        <template v-else-if="storageMode === 'unknown_quota'">
          <p class="mt-2 text-2xl font-semibold text-neutral-900">{{ usedLabel }}</p>
          <p class="mt-1 text-sm text-neutral-600">{{ t('license.managed_storage_quota_unknown') }}</p>
        </template>

        <template v-else>
          <p class="mt-2 text-sm text-neutral-700">
            <span class="text-2xl font-semibold text-neutral-900">{{ usedLabel }}</span>
            <span class="text-neutral-500"> / {{ quotaLabel }}</span>
            <span class="ml-2 font-medium">{{ fmtPercent(storage?.percent) }}</span>
          </p>
          <div
            class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-neutral-200"
            role="progressbar" :aria-valuenow="Math.round(storageBarWidth)" aria-valuemin="0" aria-valuemax="100"
          >
            <div class="h-full rounded-full transition-all" :class="storageStyle.bar" :style="{ width: storageBarWidth + '%' }"></div>
          </div>
        </template>

        <p v-if="storage?.measured_at" class="mt-2 text-xs text-neutral-500">
          {{ t('license.managed_storage_measured_at', { at: fmtDateTime(storage?.measured_at ?? null) }) }}
        </p>

        <!-- 100 % — vysvětlení, proč nejde zapisovat a co s tím -->
        <div v-if="storageLevel === 'exhausted'" class="mt-4 rounded-md border border-danger-300 bg-danger-50/60 p-3 text-sm text-danger-700">
          <p class="font-medium">{{ t('license.managed_storage_exhausted_title') }}</p>
          <p class="mt-1 text-danger-600">{{ t('license.managed_storage_exhausted_desc') }}</p>
        </div>

        <!-- 90 % — výzva k rozšíření prostoru, zapisovat se zatím dál smí -->
        <div v-else-if="storageLevel === 'warning'" class="mt-4 rounded-md border border-warning-300 bg-warning-50/60 p-3 text-sm text-warning-800">
          <p class="font-medium">{{ t('license.managed_storage_warning_title') }}</p>
          <p class="mt-1">{{ t('license.managed_storage_warning_desc', { percent: storage?.read_only_percent ?? 100 }) }}</p>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
          <!-- ⚠️ Hlavní akce vede DOVNITŘ aplikace. Rozšířit místo i navýšit
               uživatele jde tady; ven se odchází jen na to, co aplikace neumí
               (změna tarifu, faktury, platební karta), a to je sekundární. -->
          <RouterLink
            to="/hosting#misto"
            :class="btnFilled(storageLevel === 'exhausted' ? 'danger' : 'primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.box" /></svg>
            {{ t('hosting.storage_order_title') }}
          </RouterLink>
          <!-- Odkaz jen když adresu opravdu známe — mrtvé tlačítko je horší než žádné. -->
          <a
            v-if="subscriptionUrl" :href="subscriptionUrl" target="_blank" rel="noopener"
            :class="btnOutline('primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('license.managed_subscription_cta') }}
          </a>
          <button
            type="button" @click="openSupportPortal" :disabled="supportLinkBusy"
            :class="btnOutline('primary')" :title="t('support.help_title')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" /></svg>
            {{ t('support.help_paid') }}
          </button>
        </div>

        <p v-if="!subscriptionUrl" class="mt-3 text-sm text-neutral-600">
          {{ t('license.managed_subscription_contact') }}
        </p>
      </section>

      <section
        v-if="subscription"
        class="rounded-lg border p-5"
        :class="subscription.auto_renew ? 'border-neutral-200 bg-surface' : 'border-warning-200 bg-warning-50/30'"
        data-managed-renewal
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.renewal_title') }}</h2>
            <p class="mt-1 text-sm font-medium" :class="subscription.auto_renew ? 'text-success-700' : 'text-warning-800'">
              {{ subscription.auto_renew ? t('license.renewal_on') : t('license.renewal_off') }}
            </p>
          </div>
          <div v-if="subscription.auto_renew && subscription.next_charge_at" class="text-sm sm:text-right">
            <span class="block text-xs uppercase tracking-wider text-neutral-500">{{ t('license.renewal_next_charge') }}</span>
            <span class="mt-0.5 block font-medium text-neutral-900">{{ fmtDate(subscription.next_charge_at) }}</span>
          </div>
        </div>

        <p class="mt-2 text-sm text-neutral-600">
          {{ subscription.auto_renew
            ? t('license.renewal_on_desc', { period: periodLabel })
            : t('license.renewal_off_desc', { date: fmtDate(paidUntil) }) }}
        </p>

        <div
          v-if="subscription.auto_renew"
          class="mt-4 rounded-md border-2 border-danger-400 bg-danger-50 p-4 text-sm text-danger-800"
          data-managed-cancellation-warning
        >
          <p class="font-semibold">{{ t('license.renewal_managed_warning_title') }}</p>
          <p class="mt-1">{{ t('license.renewal_managed_warning_body', { date: fmtDate(paidUntil) }) }}</p>
          <p class="mt-2">{{ t('license.renewal_managed_retention') }}</p>
          <RouterLink to="/admin/instance-export" :class="[btnFilled('danger'), 'mt-3']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('license.renewal_export_cta') }}
          </RouterLink>
        </div>

        <div v-if="subscription.auto_renew" class="mt-4 flex flex-wrap gap-2">
          <button type="button" @click="cancelRenewal" :disabled="cancellingRenewal" :class="btnOutline('warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ cancellingRenewal ? t('license.renewal_cancelling') : t('license.renewal_cancel_cta') }}
          </button>
        </div>

        <!-- ⚠️ Zrušení nesmí být slepá ulička. Dokud tady nic nebylo, končila
             obrazovka větou „poté je znovu zpřístupní nový nákup předplatného"
             a zákazník neměl KUDY se vrátit — u hostované instalace navíc mezitím
             doběhl naplánovaný konec provozu a instalace zhasla. -->
        <div v-else-if="subscription.state === 'cancelled'" class="mt-4" data-renewal-resume>
          <p class="text-sm text-neutral-700">
            {{ isManaged
              ? t('license.renewal_resume_desc_managed', { date: fmtDate(paidUntil) })
              : t('license.renewal_resume_desc', { date: fmtDate(paidUntil) }) }}
          </p>
          <div v-if="subscription.resumable" class="mt-3 flex flex-wrap gap-2">
            <button type="button" @click="resumeRenewal" :disabled="resumingRenewal" :class="btnFilled('success')" data-renewal-resume-cta>
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ resumingRenewal ? t('license.renewal_resuming') : t('license.renewal_resume_cta') }}
            </button>
          </div>
          <p v-else class="mt-2 text-sm text-warning-800">{{ t('license.renewal_resume_unavailable') }}</p>
        </div>

        <div v-if="renewalSuccess" class="mt-3 rounded-md bg-success-50 border border-success-300 p-3 text-sm text-success-700">
          {{ renewalSuccess }}
        </div>
        <div v-if="renewalError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ renewalError }}
        </div>
      </section>

    </div>

    <div v-else-if="status" class="space-y-6">
      <!-- Karta stavu -->
      <section class="rounded-lg border p-5" :class="stateStyle.card">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium" :class="stateStyle.badge">
            {{ t('license.state_' + status.state) }}
          </span>
          <span class="text-sm text-neutral-500">{{ t('license.tier') }}: <strong class="text-neutral-700">{{ tierLabel }}</strong></span>
        </div>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.users') }}</dt>
            <dd class="mt-0.5 font-medium" :class="usersOverage ? 'text-danger-600' : 'text-neutral-900'">
              {{ status.users_active }} / {{ status.users_licensed > 0 ? status.users_licensed : '∞' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.companies') }}</dt>
            <dd class="mt-0.5 font-medium" :class="companiesOverage ? 'text-danger-600' : 'text-neutral-900'">
              {{ status.companies_active }} / {{ status.max_companies === null ? '∞' : status.max_companies }}
            </dd>
          </div>
          <div v-if="status.state === 'trial'">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.trial_ends') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-medium">{{ fmtDate(status.trial_ends_at) }}</dd>
          </div>
          <div v-else>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.valid_until') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-medium">
              {{ status.perpetual ? t('license.perpetual_validity') : fmtDate(status.valid_until) }}
            </dd>
          </div>
          <div v-if="status.overage_deadline">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.overage_deadline') }}</dt>
            <dd class="mt-0.5 text-warning-800 font-medium">{{ fmtDate(status.overage_deadline) }}</dd>
          </div>
          <div v-if="status.license_key_masked">
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.key') }}</dt>
            <dd class="mt-0.5 text-neutral-900 font-mono text-xs">{{ status.license_key_masked }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-neutral-500">{{ t('license.last_check') }}</dt>
            <dd class="mt-0.5 text-neutral-700">
              {{ fmtDateTime(status.last_check_at) }}
              <span v-if="!status.last_check_ok" class="text-danger-600">({{ t('license.check_failed') }})</span>
            </dd>
          </div>
        </dl>

        <!-- Přečerpání rozsahu — víc aktivních uživatelů / firem, než licence pokrývá. -->
        <div v-if="hasOverage" class="mt-4 rounded-md border border-danger-300 bg-danger-50/60 p-3 text-sm text-danger-700">
          <p class="font-medium">{{ t('license.overage_title') }}</p>
          <p class="mt-1 text-danger-600">{{ t('license.overage_desc') }}</p>
          <a v-if="canUpgrade" :href="companiesOverage ? '#tier-change' : '#upgrade'" :class="[btnFilled('danger'), 'mt-3']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ t('license.overage_cta') }}
          </a>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
          <button
            v-if="canStartPurchase"
            type="button"
            :disabled="purchasing"
            :class="btnFilled('primary')"
            data-license-purchase-start
            @click="startPurchase"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ purchasing ? t('license.purchase_starting') : t('license.buy_cta') }}
          </button>
          <button
            v-if="status.license_key_masked"
            type="button" @click="deactivate" :disabled="deactivating"
            :class="btnOutline('danger')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ deactivating ? '…' : t('license.deactivate') }}
          </button>
          <button
            type="button" @click="openSupportPortal" :disabled="supportLinkBusy"
            :class="btnOutline('primary')" :title="t('support.help_title')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" /></svg>
            {{ t('support.help_paid') }}
          </button>
        </div>
        <p v-if="!canStartPurchase && subscription" class="mt-3 text-sm text-neutral-600">
          {{ t('license.purchase_existing_subscription_hint') }}
        </p>
        <p v-if="purchaseSuccess" class="mt-3 rounded-md border border-success-300 bg-success-50 p-3 text-sm text-success-700" data-license-purchase-success>
          {{ purchaseSuccess }}
        </p>
        <p v-if="purchaseError" class="mt-3 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600" data-license-purchase-error>
          {{ purchaseError }}
        </p>
      </section>

      <!-- Automatické prodlužování předplatného -->
      <section
        v-if="subscription"
        class="rounded-lg border p-5"
        :class="subscription.auto_renew ? 'border-neutral-200 bg-surface' : 'border-warning-200 bg-warning-50/30'"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.renewal_title') }}</h2>
            <p
              class="mt-1 text-sm font-medium"
              :class="subscription.auto_renew ? 'text-success-700' : 'text-warning-800'"
            >
              {{ subscription.auto_renew ? t('license.renewal_on') : t('license.renewal_off') }}
            </p>
          </div>
          <div v-if="subscription.auto_renew && subscription.next_charge_at" class="text-sm sm:text-right">
            <span class="block text-xs uppercase tracking-wider text-neutral-500">{{ t('license.renewal_next_charge') }}</span>
            <span class="mt-0.5 block font-medium text-neutral-900">{{ fmtDate(subscription.next_charge_at) }}</span>
          </div>
          <div v-else-if="subscription.cancelled_at" class="text-sm sm:text-right text-warning-800">
            {{ t('license.renewal_cancelled_at', { date: fmtDate(subscription.cancelled_at) }) }}
          </div>
        </div>

        <p class="mt-2 text-sm text-neutral-600">
          {{ subscription.auto_renew
            ? t('license.renewal_on_desc', { period: periodLabel })
            : t('license.renewal_off_desc', { date: fmtDate(paidUntil) }) }}
        </p>

        <div
          v-if="isManaged && subscription.auto_renew"
          class="mt-4 rounded-md border-2 border-danger-400 bg-danger-50 p-4 text-sm text-danger-800"
          data-managed-cancellation-warning
        >
          <p class="font-semibold">{{ t('license.renewal_managed_warning_title') }}</p>
          <p class="mt-1">{{ t('license.renewal_managed_warning_body', { date: fmtDate(paidUntil) }) }}</p>
          <p class="mt-2">{{ t('license.renewal_managed_retention') }}</p>
          <RouterLink to="/admin/instance-export" :class="[btnFilled('danger'), 'mt-3']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('license.renewal_export_cta') }}
          </RouterLink>
        </div>

        <div v-if="subscription.auto_renew" class="mt-4 flex flex-wrap gap-2">
          <button type="button" @click="cancelRenewal" :disabled="cancellingRenewal" :class="btnOutline('warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ cancellingRenewal ? t('license.renewal_cancelling') : t('license.renewal_cancel_cta') }}
          </button>
        </div>

        <!-- ⚠️ Zrušení nesmí být slepá ulička. Dokud tady nic nebylo, končila
             obrazovka větou „poté je znovu zpřístupní nový nákup předplatného"
             a zákazník neměl KUDY se vrátit — u hostované instalace navíc mezitím
             doběhl naplánovaný konec provozu a instalace zhasla. -->
        <div v-else-if="subscription.state === 'cancelled'" class="mt-4" data-renewal-resume>
          <p class="text-sm text-neutral-700">
            {{ isManaged
              ? t('license.renewal_resume_desc_managed', { date: fmtDate(paidUntil) })
              : t('license.renewal_resume_desc', { date: fmtDate(paidUntil) }) }}
          </p>
          <div v-if="subscription.resumable" class="mt-3 flex flex-wrap gap-2">
            <button type="button" @click="resumeRenewal" :disabled="resumingRenewal" :class="btnFilled('success')" data-renewal-resume-cta>
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ resumingRenewal ? t('license.renewal_resuming') : t('license.renewal_resume_cta') }}
            </button>
          </div>
          <p v-else class="mt-2 text-sm text-warning-800">{{ t('license.renewal_resume_unavailable') }}</p>
        </div>

        <div v-if="renewalSuccess" class="mt-3 rounded-md bg-success-50 border border-success-300 p-3 text-sm text-success-700">
          {{ renewalSuccess }}
        </div>
        <div v-if="renewalError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ renewalError }}
        </div>
      </section>


    </div>

    <!-- ═══ Nákupní akce — pro OBĚ instalace ═══
         ⚠️ Spravovaná instalace znamená „server řešíme my", NE „nemůžete si
         přikoupit". Dokud tyhle sekce visely jen ve self-hosted větvi, dostal
         hostovaný zákazník místo nákupu souhrn „Spravovaná instalace" a neměl
         KUDY navýšit uživatele ani opravit licenční klíč. Klíč navíc hostovaná
         instance dostává při zřízení automaticky — o důvod víc, aby šel opravit
         ručně, když se to nepovede. -->
    <div v-if="status && isAdmin && !loading" class="space-y-6 mt-6">
      <section v-if="canUpgrade" id="tier-change" class="rounded-lg border border-primary-200 bg-primary-50/30 p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.tier_change_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('license.tier_change_desc') }}</p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
          <label class="text-sm">
            <span class="mb-1 block text-xs uppercase tracking-wider text-neutral-500">{{ t('license.tier_target') }}</span>
            <select v-model="targetTier" class="h-9 rounded-md border border-neutral-300 px-3 text-sm" @change="tierQuote = null">
              <option value="single">{{ t('license.tier_single') }}</option>
              <option value="multi10">{{ t('license.tier_multi10') }}</option>
              <option value="unlimited">{{ t('license.tier_unlimited') }}</option>
            </select>
          </label>
          <button type="button" :disabled="tierBusy || targetTier === status.tier" :class="btnOutline('primary')" @click="calcTierQuote">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ tierBusy ? t('license.upgrade_quoting') : t('license.upgrade_quote_cta') }}
          </button>
        </div>
        <div v-if="tierQuote" class="mt-4 rounded-md border border-primary-300 bg-surface p-3 text-sm">
          <p class="font-medium text-neutral-900">{{ tierQuote.scheduled ? t('license.tier_decrease_next_period') : t('license.upgrade_amount', { amount: fmtAmount(tierQuote.amount, tierQuote.currency) }) }}</p>
          <p v-if="tierQuote.effective_at" class="mt-1 text-neutral-500">{{ t('license.effective_at', { date: fmtEffective(tierQuote.effective_at) }) }}</p>
          <button type="button" :disabled="tierBusy" :class="[btnFilled(tierQuote.scheduled ? 'warning' : 'success'), 'mt-3']" @click="applyTierChange">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ tierQuote.scheduled ? t('license.schedule_change_cta') : t('license.upgrade_pay_cta') }}
          </button>
        </div>
        <p v-if="tierSuccess" class="mt-3 rounded-md border border-success-300 bg-success-50 p-3 text-sm text-success-700">{{ tierSuccess }}</p>
        <p v-if="tierError" class="mt-3 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">{{ tierError }}</p>
      </section>

      <!-- In-place navýšení počtu uživatelů -->
      <section v-if="canUpgrade" id="upgrade" class="rounded-lg border border-primary-200 bg-primary-50/30 p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.upgrade_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('license.upgrade_desc') }}</p>

        <div class="mt-3 flex flex-wrap items-end gap-3">
          <label class="text-sm">
            <span class="block text-xs uppercase tracking-wider text-neutral-500 mb-1">{{ t('license.upgrade_users_label') }}</span>
            <input
              v-model.number="upgradeUsers" type="number" min="1" step="1"
              class="w-28 h-9 px-3 text-sm rounded-md border border-neutral-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
            />
          </label>
          <button
            type="button" @click="calcQuote" :disabled="quoting || !upgradeUsers || upgradeUsers < 1"
            :class="btnOutline('primary')"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            {{ quoting ? t('license.upgrade_quoting') : t('license.upgrade_quote_cta') }}
          </button>
        </div>

        <!-- Kalkulace poměrného doplatku -->
        <div v-if="quote" class="mt-4 rounded-md border border-primary-300 bg-surface p-3 text-sm">
          <p class="text-neutral-900 font-medium">
            {{ t('license.upgrade_amount', { amount: fmtAmount(quote.amount, quote.currency) }) }}
          </p>
          <p class="mt-0.5 text-neutral-500">
            {{ t('license.upgrade_from_to', { from: quote.current_users ?? '—', to: quote.new_users }) }}
          </p>
          <button
            type="button" @click="doUpgrade" :disabled="upgrading"
            :class="[btnFilled('success'), 'mt-3']"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ upgrading ? t('license.upgrading') : t(quote.scheduled ? 'license.schedule_change_cta' : 'license.upgrade_pay_cta') }}
          </button>
        </div>

        <div v-if="upgradeSuccess" class="mt-3 rounded-md bg-success-50 border border-success-300 p-3 text-sm text-success-700">
          {{ upgradeSuccess }}
        </div>
        <div v-if="upgradeError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ upgradeError }}
        </div>
      </section>

      <!-- Aktivace klíče -->
      <section id="activate" class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('license.activate_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('license.activate_desc') }}</p>
        <form class="mt-3 flex flex-wrap items-start gap-2" @submit.prevent="activate()">
          <input
            v-model="keyInput" type="text" :placeholder="t('license.key_placeholder')"
            class="flex-1 min-w-[240px] h-9 px-3 text-sm rounded-md border border-neutral-300 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none font-mono"
          />
          <button type="submit" :disabled="activating || !keyInput.trim()" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ activating ? t('license.activating') : t('license.activate') }}
          </button>
        </form>

        <!-- Klíč je aktivní na jiné instalaci → nabídka přenosu (takeover). -->
        <div v-if="alreadyBound" class="mt-3 rounded-md bg-warning-50 border border-warning-300 p-3 text-sm text-warning-800">
          <p class="font-medium">{{ t('license.already_bound_title') }}</p>
          <p class="mt-1">{{ t('license.already_bound_desc') }}</p>
          <p v-if="transfersRemaining !== null" class="mt-1 text-warning-700">
            {{ t('license.transfers_remaining', { n: transfersRemaining }) }}
          </p>
          <button
            type="button" @click="activate(true)" :disabled="activating || !keyInput.trim()"
            :class="[btnFilled('warning'), 'mt-3']"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ activating ? t('license.activating') : t('license.takeover_cta') }}
          </button>
        </div>

        <div v-if="activateError" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
          {{ activateError }}
        </div>
      </section>

      <!-- Rozšíření místa i celý přehled provozu žijí na /hosting; tahle
           obrazovka zůstává u licence a předplatného. -->
      <p class="text-xs text-neutral-500">
        <RouterLink v-if="isManaged" to="/hosting" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.hosting') }}</RouterLink>
        <span v-if="isManaged"> · </span>
        <RouterLink to="/activation/license" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.license') }}</RouterLink>
        ·
        <RouterLink to="/activation/terms" class="text-primary-600 hover:text-primary-800 hover:underline">{{ t('nav.terms') }}</RouterLink>
      </p>
    </div>

    <div v-if="errorMsg" class="mt-4 rounded-md bg-danger-50 border border-danger-500/40 p-4 text-sm text-danger-600">
      {{ errorMsg }}
    </div>
  </div>
</template>
