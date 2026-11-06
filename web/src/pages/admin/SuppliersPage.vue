<script setup lang="ts">
import { isCzechVatGroup } from '@/utils/vatGroup'
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  suppliersApi,
  type SupplierListItem,
  type SupplierCreatePayload,
  type SupplierDirectoryItem,
  type SupplierDirectoryParams,
  type SupplierDirectorySort,
  type SupplierCarryOverPreview,
  type SupplierCarryOverResult,
} from '@/api/suppliers'
import { clientsApi } from '@/api/clients'
import { authApi } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import SupplierUrgency from '@/components/supplier/SupplierUrgency.vue'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const { switchTo } = useSupplierSwitch()

const suppliers = ref<SupplierDirectoryItem[]>([])
const loading = ref(false)
const loadError = ref(false)

// Řazení a hledání žijí v URL, aby šel odkaz na konkrétní pohled sdílet. Plné filtry
// a důvody urgence má Přehled firem (/portfolio), správa firem zůstává seznamem.
const SORTS: SupplierDirectorySort[] = ['urgency', 'name', 'last_invoice', 'last_activity', 'overdue']
const DEFAULT_DIR: Record<SupplierDirectorySort, 'asc' | 'desc'> = {
  urgency: 'desc', name: 'asc', last_invoice: 'desc', last_activity: 'desc', overdue: 'desc',
}

function pick<T extends string>(value: unknown, allowed: readonly T[]): T | undefined {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value) ? value as T : undefined
}

function paramsFromQuery(q: LocationQuery): SupplierDirectoryParams {
  const sort = pick(q.sort, SORTS) ?? 'urgency'
  return {
    sort,
    dir: pick(q.dir, ['asc', 'desc'] as const) ?? DEFAULT_DIR[sort],
    q: typeof q.q === 'string' ? q.q : '',
  }
}

const filters = reactive<SupplierDirectoryParams>(paramsFromQuery(route.query))
const search = ref(filters.q ?? '')

const hasActiveFilters = computed(() => !!filters.q)

function queryFromFilters(): Record<string, string> {
  const sort = filters.sort ?? 'urgency'
  const out: Record<string, string> = {}
  if (sort !== 'urgency') out.sort = sort
  if (filters.dir && filters.dir !== DEFAULT_DIR[sort]) out.dir = filters.dir
  if (filters.q) out.q = filters.q
  return out
}

function applyFilters() {
  void router.replace({ query: queryFromFilters() })
}

function onSortChange() {
  filters.dir = DEFAULT_DIR[filters.sort ?? 'urgency']
  applyFilters()
}

function toggleDir() {
  filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
  applyFilters()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.q = value.trim()
    applyFilters()
  }, 300)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

function clearFilters() {
  clearTimeout(searchTimer)
  search.value = ''
  filters.q = ''
  applyFilters()
}

let loadRun = 0
async function loadSuppliers() {
  const run = ++loadRun
  loading.value = true
  loadError.value = false
  try {
    const params = { ...filters }
    if (!params.q) delete params.q
    const rows = await suppliersApi.directory(params)
    if (run === loadRun) suppliers.value = rows
  } catch {
    if (run === loadRun) loadError.value = true
  } finally {
    if (run === loadRun) loading.value = false
  }
}

watch(() => route.query, (q) => {
  Object.assign(filters, paramsFromQuery(q))
  if ((filters.q ?? '') !== search.value.trim()) search.value = filters.q ?? ''
  void loadSuppliers()
})

onMounted(async () => {
  await loadSuppliers()
  // Onboarding gate (#151): dashboard sem posílá s ?create=supplier → rovnou otevři
  // formulář pro vytvoření prvního dodavatele.
  if (route.query.create === 'supplier') newSupplier()
})

// ─── Suppliers (multi-tenant firmy) ───────────────────────────────────────
const supplierDraft = reactive<SupplierCreatePayload>({
  company_name: '', street: '', city: '', zip: '', email: '',
  country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true, vat_period: 'monthly',
  commercial_register: '',
  default_payment_due_days: 14, default_hourly_rate: 1500,
})
const supplierCreateOpen = ref(false)
const supplierAresLoading = ref(false)
const supplierAresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

// Bankovní účet nového dodavatele (volitelný, lze načíst z registru plátců DPH)
const supplierBank = reactive({ currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
const supplierBankLoading = ref(false)
const supplierBankMessage = ref<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null)
const supplierBankAccounts = ref<import('@/api/clients').CrpDphAccount[]>([])
const supplierBankAccountsRequireSelection = ref(false)

function supplierApplyBank(acc: import('@/api/clients').CrpDphAccount) {
  if (acc.iban) {
    supplierBank.currency = 'EUR'
    supplierBank.iban = acc.iban
  } else {
    supplierBank.currency = 'CZK'
    supplierBank.account_number = acc.prefix ? `${acc.prefix}-${acc.number}` : acc.number
    supplierBank.bank_code = acc.bank_code
  }
}

async function supplierLookupBank() {
  const dic = (supplierDraft.dic || '').replace(/\D/g, '')
  if (!/^\d{8,10}$/.test(dic)) {
    supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_no_dic') }
    return
  }
  supplierBankLoading.value = true
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  supplierBankAccountsRequireSelection.value = false
  try {
    const r = await clientsApi.lookupBank(dic)
    supplierBankAccounts.value = r.accounts
    supplierBankAccountsRequireSelection.value = isCzechVatGroup(dic)
    // O plátcovství rozhoduje registr plátců (stejně jako v prvotním setupu);
    // neúspěšné dohledání ho neshazuje.
    if (r.found === true) supplierDraft.is_vat_payer = true
    if (r.accounts.length === 0) {
      supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_none') }
    } else if (supplierBankAccountsRequireSelection.value) {
      supplierBankMessage.value = { type: 'warning', text: t('supplier.bank_lookup_group') }
    } else {
      supplierApplyBank(r.accounts[0])
      supplierBankMessage.value = r.accounts.length === 1
        ? { type: 'success', text: t('supplier.bank_lookup_one') }
        : { type: 'success', text: t('supplier.bank_lookup_many', { n: r.accounts.length }) }
    }
    if (r.unreliable === true) {
      supplierBankMessage.value = { type: 'warning', text: [
        supplierBankAccountsRequireSelection.value && r.accounts.length > 0 ? t('supplier.bank_lookup_group') : '',
        t('supplier.bank_lookup_unreliable'),
      ].filter(Boolean).join(' ') }
    }
  } catch (e: any) {
    supplierBankMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.bank_lookup_failed') }
  } finally {
    supplierBankLoading.value = false
  }
}

function newSupplier() {
  Object.assign(supplierDraft, {
    company_name: '', street: '', city: '', zip: '', email: '',
    country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true, vat_period: 'monthly',
    commercial_register: '', taxpayer_type: undefined,
    default_payment_due_days: 14, default_hourly_rate: 1500,
  })
  Object.assign(supplierBank, { currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  supplierBankAccountsRequireSelection.value = false
  supplierAresMessage.value = null
  supplierCreateOpen.value = true
  void loadCarryOver()
}

// ─── Převzetí AI klíče a certifikátu ze stávajících firem ──────────────────
// Server nabídne jen to, co je ve všech firmách uživatele stejné. Výchozí volba
// je převzít vše nabídnuté; certifikát chce stejné ověření jako jeho sdílení
// v Elektronických podpisech (passkey, nebo heslo + případně TOTP).
const carryOver = ref<SupplierCarryOverPreview | null>(null)
const carryOverAi = ref(false)
const carryOverCertificateIds = ref<number[]>([])
const carryOverStepPassword = ref('')
const carryOverStepTotp = ref('')
const carryOverStepPasskeyToken = ref('')
const carryOverPasskeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()
let carryOverRun = 0

const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const hasTotp = computed(() => auth.user?.totp_enabled === true)

const carryOverOffered = computed(() =>
  carryOver.value !== null
  && (carryOver.value.ai.available || carryOver.value.ai.reason === 'different' || carryOver.value.certificates.length > 0),
)
const carryOverNeedsStepUp = computed(() => carryOverCertificateIds.value.length > 0)

/** Prázdný řetězec = ověření je kompletní; jinak text, co ještě chybí. */
const carryOverStepUpMissing = computed(() => {
  if (!carryOverNeedsStepUp.value || carryOverStepPasskeyToken.value) return ''
  if (!carryOverStepPassword.value) return t('settings.certificate_vault_step_up_password_missing')
  if (hasTotp.value && !/^\d{6}$/.test(carryOverStepTotp.value.trim())) {
    return t('settings.certificate_vault_step_up_totp_missing')
  }
  return ''
})

function resetCarryOver() {
  carryOver.value = null
  carryOverAi.value = false
  carryOverCertificateIds.value = []
  carryOverStepPassword.value = ''
  carryOverStepTotp.value = ''
  carryOverStepPasskeyToken.value = ''
}

async function loadCarryOver() {
  const run = ++carryOverRun
  resetCarryOver()
  try {
    const preview = await suppliersApi.carryOverPreview()
    if (run !== carryOverRun) return
    carryOver.value = preview
    carryOverAi.value = preview.ai.available
    carryOverCertificateIds.value = preview.certificates.map(c => c.id)
  } catch {
    // Náhled je jen nabídka navíc: bez něj se firma založí jako dřív.
    if (run === carryOverRun) carryOver.value = null
  }
}

function carryOverProviderLabel(provider: string | null): string {
  return provider ? t(`aiGateway.provider.${provider}`) : ''
}

async function verifyCarryOverPasskey() {
  if (!passkeySupported) return
  carryOverPasskeyBusy.value = true
  try {
    const flow = await authApi.passkeyStepUpOptions('epo.certificate')
    const credential = await getCredential(flow.public_key)
    carryOverStepPasskeyToken.value = await authApi.passkeyStepUpVerify(flow.flow_token, 'epo.certificate', credential)
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('settings.certificate_vault_passkey_failed')))
  } finally {
    carryOverPasskeyBusy.value = false
  }
}

function carryOverPayload(): SupplierCreatePayload['carry_over'] {
  if (carryOver.value === null || (!carryOverAi.value && carryOverCertificateIds.value.length === 0)) return undefined
  return {
    ai: carryOverAi.value,
    certificate_ids: [...carryOverCertificateIds.value],
    step_up: carryOverNeedsStepUp.value
      ? {
          password: carryOverStepPassword.value || undefined,
          totp_code: carryOverStepTotp.value.trim() || undefined,
          step_up_token: carryOverStepPasskeyToken.value || undefined,
        }
      : undefined,
  }
}

function reportCarryOver(result: SupplierCarryOverResult | undefined) {
  if (!result) return
  const failures: string[] = []
  let carried = 0
  if (result.ai) {
    if (result.ai.ok) carried++
    else failures.push(t('supplier.carry_over.failed_ai', { reason: t(`supplier.carry_over.error.${result.ai.error ?? 'failed'}`) }))
  }
  for (const row of result.certificates) {
    if (row.ok) {
      carried++
      continue
    }
    const label = carryOver.value?.certificates.find(c => c.id === row.id)?.label ?? `#${row.id}`
    failures.push(t('supplier.carry_over.failed_certificate', {
      label,
      reason: t(`supplier.carry_over.error.${row.error ?? 'failed'}`),
    }))
  }
  if (carried > 0) toast.success(t('supplier.carry_over.done', { n: carried }))
  for (const message of failures) toast.warning(message)
}

async function supplierLookupAres() {
  const ic = (supplierDraft.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    supplierAresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  supplierAresLoading.value = true
  supplierAresMessage.value = null
  try {
    const r = await clientsApi.lookupAres(ic)
    if (!r.found || !r.data) {
      supplierAresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    supplierDraft.company_name = d.company_name || supplierDraft.company_name
    supplierDraft.street       = d.street       || supplierDraft.street
    supplierDraft.city         = d.city         || supplierDraft.city
    supplierDraft.zip          = d.zip          || supplierDraft.zip
    supplierDraft.country_iso2 = d.country_iso2 || supplierDraft.country_iso2 || 'CZ'
    supplierDraft.ic           = d.ic           || ic
    supplierDraft.dic          = d.dic          || supplierDraft.dic
    supplierDraft.is_vat_payer = d.is_vat_payer
    supplierDraft.commercial_register = d.commercial_register || supplierDraft.commercial_register
    if (d.taxpayer_type === 'fo' || d.taxpayer_type === 'po') supplierDraft.taxpayer_type = d.taxpayer_type
    supplierAresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
    // Stejně jako setup: s DIČ rovnou dotáhni registr plátců (plátcovství + účet).
    if (/^\d{8,10}$/.test((supplierDraft.dic || '').replace(/\D/g, ''))) {
      await supplierLookupBank()
    }
  } catch (e: any) {
    supplierAresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    supplierAresLoading.value = false
  }
}

async function saveSupplier() {
  if (!supplierDraft.company_name || !supplierDraft.street || !supplierDraft.city || !supplierDraft.zip || !supplierDraft.email) {
    toast.error(t('common.error'))
    return
  }
  if (carryOverStepUpMissing.value !== '') {
    toast.error(carryOverStepUpMissing.value)
    return
  }
  try {
    const payload: SupplierCreatePayload = { ...supplierDraft }
    if (!payload.is_vat_payer) delete payload.vat_period
    if (supplierBank.account_number || supplierBank.iban) {
      payload.bank_account = {
        currency: supplierBank.currency,
        account_number: supplierBank.account_number || undefined,
        bank_code: supplierBank.bank_code || undefined,
        bank_name: supplierBank.bank_name || undefined,
        iban: supplierBank.iban || undefined,
        bic: supplierBank.bic || undefined,
      }
    }
    const carry = carryOverPayload()
    if (carry) payload.carry_over = carry
    const created = await suppliersApi.create(payload)
    supplierCreateOpen.value = false
    toast.success(t('common.saved'))
    reportCarryOver(created.carry_over_result)
    resetCarryOver()
    await loadSuppliers()
    await auth.refresh()
  } catch (e: any) {
    // Passkey proof je jednorázový; po odeslání se zahazuje i při chybě.
    carryOverStepPasskeyToken.value = ''
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeSupplier(s: SupplierListItem) {
  if (s.clients_count > 0 || s.invoices_count > 0) return
  if (!confirm(t('supplier.delete_confirm'))) return
  try {
    await suppliersApi.delete(s.id)
    toast.success(t('common.deleted'))
    await loadSuppliers()
    await auth.refresh()
    if (supplierStore.currentSupplierId === s.id) {
      const first = suppliers.value[0]
      if (first) await switchTo(first.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function switchSupplier(id: number) {
  if (id === supplierStore.currentSupplierId) return
  await switchTo(id)
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('supplier.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('supplier.list_subtitle') }}</p>
    </div>

    <section>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <input v-model="search" type="search" :placeholder="t('supplier.listing.search_placeholder')" data-testid="suppliers-search"
          class="flex-1 min-w-[12rem] h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
        <select v-model="filters.sort" @change="onSortChange" :title="t('common.sort_by')" data-testid="suppliers-sort"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="urgency">{{ t('supplier.listing.sort_urgency') }}</option>
          <option value="name">{{ t('supplier.listing.sort_name') }}</option>
          <option value="last_invoice">{{ t('supplier.listing.sort_last_invoice') }}</option>
          <option value="last_activity">{{ t('supplier.listing.sort_last_activity') }}</option>
          <option value="overdue">{{ t('supplier.listing.sort_overdue') }}</option>
        </select>
        <button type="button" @click="toggleDir" :class="btnOutline('neutral')" class="whitespace-nowrap"
          :title="filters.dir === 'asc' ? t('supplier.listing.dir_asc') : t('supplier.listing.dir_desc')">
          <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': filters.dir === 'asc' }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
          {{ filters.dir === 'asc' ? t('supplier.listing.dir_asc') : t('supplier.listing.dir_desc') }}
        </button>
        <router-link v-if="auth.canRead('dashboard.portfolio')" to="/portfolio" :class="btnOutline('neutral')" class="whitespace-nowrap" data-testid="suppliers-portfolio-link">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('nav.portfolio') }}
        </router-link>
        <button @click="newSupplier" :class="btnFilled('primary')" class="whitespace-nowrap sm:ml-auto">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('supplier.new') }}
        </button>
      </div>

      <div v-if="loading && !suppliers.length" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="loadError" boxed variant="failed" :message="t('supplier.listing.load_failed')" @action="loadSuppliers" />
      <EmptyState v-else-if="!suppliers.length && hasActiveFilters" boxed variant="filtered"
        :cta="t('common.empty_state.clear_filters')" @action="clearFilters" />

      <template v-else>
      <p class="text-xs text-neutral-500 mb-2">{{ t('supplier.listing.count', { n: suppliers.length }) }}</p>

      <!-- Desktop tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" :class="{ 'opacity-60': loading }">
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-10"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.company_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.ic') }} / {{ t('supplier.dic') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.listing.col_urgency') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('supplier.listing.col_last_invoice') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('supplier.listing.col_last_activity') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.invoices') }}</th>
                <th class="px-3 py-2 w-48"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in suppliers" :key="s.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 text-center">
                  <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base" :title="t('supplier.active_label')">●</span>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium text-neutral-900">{{ s.company_name }}</div>
                  <div v-if="s.display_name && s.display_name !== s.company_name" class="text-xs text-neutral-500">{{ s.display_name }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">
                  <span v-if="s.ic">{{ s.ic }}</span>
                  <span v-if="s.ic && s.dic"> / </span>
                  <span v-if="s.dic">{{ s.dic }}</span>
                  <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2">
                  <SupplierUrgency :urgency="s.urgency" compact />
                </td>
                <td class="px-3 py-2 text-right font-mono text-xs whitespace-nowrap">
                  <span v-if="s.last_invoice_date">{{ formatDate(s.last_invoice_date) }}</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono text-xs whitespace-nowrap">
                  <span v-if="s.last_activity_at">{{ formatDateTime(s.last_activity_at) }}</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ s.clients_count }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ s.invoices_count }}</td>
                <!-- Tlačítka, ne holé odkazy: obojí tu MĚNÍ stav (přepnutí firmy,
                     smazání), takže mají vypadat jako akce — a `whitespace-nowrap`
                     drží dvojici na jednom řádku. -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <div class="flex items-center justify-end gap-1.5">
                    <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
                      :class="btnOutlineSm('primary')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
                      {{ t('supplier.switch') }}
                    </button>
                    <button v-if="auth.isSuperadmin" @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || supplierStore.availableSuppliers.length <= 1"
                      :class="btnOutlineSm('danger')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                      {{ t('common.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="s in suppliers" :key="`m-${s.id}`" class="px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5 min-w-0 truncate">
              <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base shrink-0" :title="t('supplier.active_label')">●</span>
              {{ s.company_name }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">
              <span v-if="s.ic">{{ s.ic }}</span>
              <span v-if="s.ic && s.dic"> / </span>
              <span v-if="s.dic">{{ s.dic }}</span>
              <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
            </span>
            <span class="font-mono">{{ t('supplier.clients') }}: {{ s.clients_count }} · {{ t('supplier.invoices') }}: {{ s.invoices_count }}</span>
          </div>
          <div v-if="s.urgency.level !== 'none'" class="mt-1.5">
            <SupplierUrgency :urgency="s.urgency" compact />
          </div>
          <div v-if="s.last_invoice_date || s.last_activity_at" class="flex flex-wrap gap-x-3 mt-1 text-xs text-neutral-500">
            <span v-if="s.last_invoice_date">{{ t('supplier.listing.col_last_invoice') }}: {{ formatDate(s.last_invoice_date) }}</span>
            <span v-if="s.last_activity_at">{{ t('supplier.listing.col_last_activity') }}: {{ formatDateTime(s.last_activity_at) }}</span>
          </div>
          <div class="flex flex-wrap gap-2 mt-2">
            <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
              :class="btnOutlineSm('primary')" class="whitespace-nowrap">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
              {{ t('supplier.switch') }}
            </button>
            <button v-if="auth.isSuperadmin" @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || supplierStore.availableSuppliers.length <= 1"
              :class="btnOutlineSm('danger')" class="whitespace-nowrap ml-auto">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
      </template>
    </section>

    <!-- Supplier create modal (multi-tenant firma) -->
    <div v-if="supplierCreateOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-1">{{ t('supplier.create_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-4">{{ t('supplier.create_hint') }}</p>
        <form @submit.prevent="saveSupplier">
          <div class="space-y-3">
            <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
              <div class="flex gap-2">
                <input v-model="supplierDraft.ic" type="text" placeholder="12345678" maxlength="8"
                  @keydown.enter.prevent="supplierLookupAres"
                  class="min-w-0 flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <button type="button" @click="supplierLookupAres" :disabled="supplierAresLoading"
                  :class="[btnFilled('primary'), 'shrink-0']">
                  <svg v-if="!supplierAresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  <span v-else>…</span>
                  {{ supplierAresLoading ? t('common.loading') : t('supplier.ares_load') }}
                </button>
              </div>
              <div v-if="supplierAresMessage" class="mt-2 text-xs px-2 py-1 rounded"
                :class="supplierAresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ supplierAresMessage.text }}
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.company_name') }} *</label>
              <input v-model="supplierDraft.company_name" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.dic') }}</label>
              <input v-model="supplierDraft.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.taxpayer_type') }}</label>
              <select v-model="supplierDraft.taxpayer_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="undefined">{{ t('supplier.taxpayer_auto') }}</option>
                <option value="fo">{{ t('settings.taxpayer_fo') }}</option>
                <option value="po">{{ t('settings.taxpayer_po') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('supplier.taxpayer_hint') }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
              <label class="flex items-center gap-2 h-10 cursor-pointer">
                <input v-model="supplierDraft.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span class="text-sm text-neutral-800">{{ t('supplier.is_vat_payer') }}</span>
              </label>
              <div v-if="supplierDraft.is_vat_payer">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_period') }}</label>
                <select v-model="supplierDraft.vat_period" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option value="monthly">{{ t('settings.vat_monthly') }}</option>
                  <option value="quarterly">{{ t('settings.vat_quarterly') }}</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.street') }} *</label>
              <input v-model="supplierDraft.street" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.zip') }} *</label>
                <input v-model="supplierDraft.zip" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div class="col-span-2">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.city') }} *</label>
                <input v-model="supplierDraft.city" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.email') }} *</label>
              <input v-model="supplierDraft.email" type="email" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
              <input v-model="supplierDraft.commercial_register" type="text" :placeholder="t('settings.commercial_register_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>

            <div class="border-t border-neutral-200 pt-3">
              <div class="flex items-center justify-between mb-2 gap-2">
                <label class="block text-xs font-medium text-neutral-700">{{ t('settings.account_cz') }} / {{ t('settings.iban') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></label>
                <button type="button" @click="supplierLookupBank" :disabled="supplierBankLoading"
                  :class="[btnOutline('primary'), 'shrink-0']">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  {{ supplierBankLoading ? t('common.loading') : t('supplier.bank_lookup') }}
                </button>
              </div>
              <div v-if="supplierBankMessage" class="mb-2 text-xs px-2 py-1 rounded"
                :class="{
                  'bg-success-50 text-success-600': supplierBankMessage.type === 'success',
                  'bg-danger-50 text-danger-500': supplierBankMessage.type === 'error',
                  'bg-warning-50 text-warning-600': supplierBankMessage.type === 'warning',
                }">
                {{ supplierBankMessage.text }}
              </div>
              <div v-if="supplierBankAccounts.length > 1 || (supplierBankAccountsRequireSelection && supplierBankAccounts.length > 0)" class="mb-2 flex flex-wrap gap-1.5">
                <button v-for="(acc, i) in supplierBankAccounts" :key="i" type="button" @click="supplierApplyBank(acc)"
                  class="cursor-pointer px-2 py-1 text-xs font-mono border border-neutral-300 rounded hover:bg-primary-50 hover:border-primary-300">
                  {{ acc.display }}
                </button>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
                  <select v-model="supplierBank.currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                    <option value="CZK">CZK</option>
                    <option value="EUR">EUR</option>
                  </select>
                </div>
                <template v-if="supplierBank.currency === 'CZK'">
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
                    <input v-model="supplierBank.account_number" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
                    <input v-model="supplierBank.bank_code" maxlength="4" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
                <template v-else>
                  <div class="col-span-2">
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
                    <input v-model="supplierBank.iban" placeholder="CZ65 0800 0000 1920 0014 5399" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
              </div>
            </div>

            <div v-if="carryOver && carryOverOffered" class="border-t border-neutral-200 pt-3" data-testid="supplier-carry-over">
              <h4 class="text-xs font-medium text-neutral-700">{{ t('supplier.carry_over.title') }}</h4>
              <p class="text-xs text-neutral-500 mt-0.5 mb-2">{{ t('supplier.carry_over.hint') }}</p>
              <div class="space-y-2">
                <label v-if="carryOver.ai.available" class="flex items-start gap-2 text-sm cursor-pointer">
                  <input v-model="carryOverAi" type="checkbox" data-testid="carry-over-ai"
                    class="mt-0.5 h-4 w-4 accent-primary-600" />
                  <span>
                    <span class="block text-neutral-800">{{ t('supplier.carry_over.ai', {
                      provider: carryOverProviderLabel(carryOver.ai.provider), n: carryOver.ai.companies_count,
                    }, carryOver.ai.companies_count) }}</span>
                    <span class="block text-xs text-neutral-500">{{ carryOver.ai.companies.join(', ') }}</span>
                  </span>
                </label>
                <p v-else-if="carryOver.ai.reason === 'different'" class="text-xs text-neutral-500" data-testid="carry-over-ai-different">
                  {{ t('supplier.carry_over.ai_different') }}
                </p>
                <label v-for="certificate in carryOver.certificates" :key="certificate.id"
                  class="flex items-start gap-2 text-sm cursor-pointer">
                  <input v-model="carryOverCertificateIds" type="checkbox" :value="certificate.id"
                    :data-testid="`carry-over-certificate-${certificate.id}`"
                    class="mt-0.5 h-4 w-4 accent-primary-600" />
                  <span>
                    <span class="block text-neutral-800">{{ t('supplier.carry_over.certificate', {
                      label: certificate.label, date: formatDate(certificate.valid_to), n: certificate.companies_count,
                    }, certificate.companies_count) }}</span>
                    <span v-if="certificate.subject && certificate.subject !== certificate.label" class="block text-xs text-neutral-500">{{ certificate.subject }}</span>
                  </span>
                </label>
              </div>

              <div v-if="carryOverNeedsStepUp" class="mt-3 grid gap-3 sm:grid-cols-2 rounded-lg border border-neutral-200 p-3"
                data-testid="carry-over-step-up">
                <div v-if="hasPasskey" class="sm:col-span-2 flex flex-wrap items-center gap-3">
                  <div v-if="carryOverStepPasskeyToken" class="text-sm font-medium text-success-600">
                    ✓ {{ t('settings.certificate_vault_passkey_verified') }}
                  </div>
                  <template v-else-if="passkeySupported">
                    <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']"
                      :disabled="carryOverPasskeyBusy" @click="verifyCarryOverPasskey">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                      {{ carryOverPasskeyBusy ? t('common.loading') : t('settings.certificate_vault_verify_passkey') }}
                    </button>
                    <span class="text-xs text-neutral-500">{{ t('settings.certificate_vault_step_up_or_password') }}</span>
                  </template>
                  <p v-else class="text-xs text-warning-700">{{ t('settings.certificate_vault_passkey_unsupported') }}</p>
                </div>
                <template v-if="!carryOverStepPasskeyToken">
                  <label class="text-xs text-neutral-600">
                    {{ t('settings.certificate_vault_step_up_password') }}
                    <input v-model="carryOverStepPassword" type="password" autocomplete="current-password"
                      data-testid="carry-over-step-password"
                      class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
                  </label>
                  <label class="text-xs text-neutral-600">
                    {{ t('settings.certificate_vault_step_up_totp') }}
                    <input v-model="carryOverStepTotp" type="text" inputmode="numeric" maxlength="6"
                      autocomplete="one-time-code"
                      class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
                  </label>
                </template>
                <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('supplier.carry_over.step_up_hint') }}</p>
                <p v-if="carryOverStepUpMissing" class="sm:col-span-2 text-xs text-warning-700">{{ carryOverStepUpMissing }}</p>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
            <button type="button" @click="supplierCreateOpen = false" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}</button>
            <button type="submit" :class="[btnFilled('primary'), 'whitespace-nowrap']" data-testid="supplier-create-submit">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.create') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
