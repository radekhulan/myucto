<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { useWindowTitle } from '@/composables/useWindowTitle'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, TAX_NUMBER_LABELS, type Client, type ClientBankAccount, type BankLookupResult, type ViesLookupResult } from '@/api/clients'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'
import { purchaseInvoicesApi, type PurchaseInvoiceListItem } from '@/api/purchaseInvoices'
import { recurringApi, type RecurringTemplate } from '@/api/recurring'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass, taxDateClass, paymentDueLabel } from '@/composables/useFormat'
import { useSupplierStore } from '@/stores/supplier'
import MonthlyRevenueChart from '@/components/charts/MonthlyRevenueChart.vue'
import TopProjectsBarChart from '@/components/charts/TopProjectsBarChart.vue'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import SendWorkReportLinkModal from '@/components/modals/SendWorkReportLinkModal.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formatAccountNumber } from '@/utils/bankAccount'
import EmptyState from '@/components/ui/EmptyState.vue'
import EntityDimensionDefaults from '@/components/dimensions/EntityDimensionDefaults.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const showWrLinkModal = ref(false)

const route = useRoute()
const router = useRouter()

const client = ref<Client | null>(null)
const loading = ref(true)
const invoices = ref<InvoiceListItem[]>([])
const invoicesLoading = ref(false)
const invoicesLoadingMore = ref(false)
const invoicesTotal = ref(0)
const invoicesPage = ref(1)
const invoicesPages = ref(1)
const recurringTemplates = ref<RecurringTemplate[]>([])
const purchaseInvoices = ref<PurchaseInvoiceListItem[]>([])
const purchaseInvoicesLoading = ref(false)
const bankAccountNumber = ref('')
const bankCode = ref('')
const bankAccountSaving = ref(false)
const bankRegistrySyncing = ref(false)
const deletingBankAccountIds = ref<Set<number>>(new Set())
const canSyncCzechBankRegistry = computed(() => {
  const dic = (client.value?.dic ?? '').toUpperCase().replace(/[\s-]/g, '')
  return client.value?.country_iso2 === 'CZ' && /^(?:CZ)?\d{8,10}$/.test(dic)
})

// Detaily plátce DPH — CZ DIČ jde do registru plátců DPH (CRPDPH/MFČR: účty + nespolehlivost),
// zahraniční EU DIČ (SK…) do VIES; CRPDPH je jen český registr a cizí DIČ by vždy
// vrátil nepravdivé „není evidován" (#120).
const vatInfoOpen = ref(false)
const vatInfoLoading = ref(false)
const vatInfo = ref<BankLookupResult | null>(null)
const vatInfoVies = ref<ViesLookupResult | null>(null)
const vatInfoError = ref('')

async function loadVatPayerDetails() {
  const raw = (client.value?.dic || '').toUpperCase().replace(/[\s-]/g, '')
  if (!raw) return
  const prefix = raw.match(/^([A-Z]{2})\d/)?.[1] ?? null
  vatInfoOpen.value = true
  vatInfoLoading.value = true
  vatInfoError.value = ''
  vatInfo.value = null
  vatInfoVies.value = null
  try {
    if (prefix && prefix !== 'CZ') {
      vatInfoVies.value = await clientsApi.lookupVies(raw)
    } else {
      vatInfo.value = await clientsApi.lookupBank(raw.replace(/\D/g, ''))
    }
  } catch (e: any) {
    vatInfoError.value = e?.response?.data?.error?.message || t('client.vat_payer_details_failed')
  } finally {
    vatInfoLoading.value = false
  }
}

function bankAccountLabel(account: ClientBankAccount): string {
  if (account.iban) return account.iban.replace(/(.{4})/g, '$1 ').trim()
  return formatAccountNumber(account.account_number, account.bank_code)
}

async function addBankAccount() {
  if (!client.value || !bankAccountNumber.value.trim()) return
  bankAccountSaving.value = true
  try {
    const account = await clientsApi.addBankAccount(client.value.id, {
      account_number: bankAccountNumber.value.trim(),
      bank_code: bankCode.value.trim() || null,
    })
    client.value.bank_accounts = [
      ...(client.value.bank_accounts ?? []).filter(a => a.id !== account.id),
      account,
    ]
    bankAccountNumber.value = ''
    bankCode.value = ''
    toast.success(t('client.bank_accounts.added'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('client.bank_accounts.add_failed'))
  } finally {
    bankAccountSaving.value = false
  }
}

async function removeBankAccount(account: ClientBankAccount) {
  if (!client.value || deletingBankAccountIds.value.has(account.id)
    || !confirm(t('client.bank_accounts.delete_confirm', { account: bankAccountLabel(account) }))) return
  deletingBankAccountIds.value = new Set([...deletingBankAccountIds.value, account.id])
  try {
    await clientsApi.deleteBankAccount(client.value.id, account.id)
    client.value.bank_accounts = (client.value.bank_accounts ?? []).filter(a => a.id !== account.id)
    toast.success(t('client.bank_accounts.deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('client.bank_accounts.delete_failed'))
  } finally {
    const ids = new Set(deletingBankAccountIds.value)
    ids.delete(account.id)
    deletingBankAccountIds.value = ids
  }
}

async function syncBankAccountsFromRegistry() {
  if (!client.value) return
  bankRegistrySyncing.value = true
  try {
    const result = await clientsApi.syncBankAccountsFromRegistry(client.value.id)
    client.value.bank_accounts = result.accounts
    if (result.source === 'group_registration') {
      toast.info(t('client.bank_accounts.sync_group'))
    } else if (result.found && result.synced > 0) {
      toast.success(t('client.bank_accounts.synced', { count: result.synced }))
    } else {
      toast.info(t('client.bank_accounts.sync_empty'))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('client.bank_accounts.sync_failed'))
  } finally {
    bankRegistrySyncing.value = false
  }
}

// Národní daňové číslo + label dle země (#120); u SK je `dic` = IČ DPH.
const taxNumberLabel = computed(() =>
  client.value ? (TAX_NUMBER_LABELS[client.value.country_iso2] ?? null) : null
)
const dicIsSk = computed(() => (client.value?.dic || '').toUpperCase().startsWith('SK'))

// Aggregace přijatých faktur per měsíc / rok (paralel se statistikami vystavených).
// Server zatím nevrací aggregated dataset, takže computované client-side z purchaseInvoices.
//
// Multi-currency: pokud má dodavatel faktury ve více měnách (např. EUR + USD),
// přepočítáme vše na CZK přes pi.exchange_rate (CNB k DUZP — fixovaný na faktuře).
// `purchaseIsMultiCurrency` rozhodne, zda graf/totals zobrazit v CZK nebo původní měně.
const purchaseCurrencies = computed(() => {
  const s = new Set<string>()
  for (const pi of purchaseInvoices.value) {
    if (pi.status === 'draft' || pi.status === 'cancelled') continue
    s.add(pi.currency || 'CZK')
  }
  return Array.from(s)
})
const purchaseIsMultiCurrency = computed(() => purchaseCurrencies.value.length > 1)
const purchaseDisplayCurrency = computed(() =>
  purchaseIsMultiCurrency.value ? 'CZK' : (purchaseCurrencies.value[0] || 'CZK')
)

// Jednotku klient bez vlastní dědí z dodavatele (stejně jako PaymentDueResolver
// na backendu) — jinak by se zděděný „1× měsíc" zobrazil jako „1 den".
function formatPaymentDue(c: Client): string {
  return paymentDueLabel(
    c.payment_due_default,
    c.payment_due_unit,
    supplierStore.currentSupplier?.default_payment_due_unit,
    t('client.due_default'),
  )
}

// Náklady čteme ze server-side agregace (client.costs_by_month / costs_by_year) — nezávislé na paginaci
// listu. Multi-currency: v multi režimu sloučíme do CZK přes total_czk, single-ccy zachová per měnu.
const purchaseByMonth = computed(() => {
  const rows = client.value?.costs_by_month ?? []
  if (purchaseIsMultiCurrency.value) {
    const m = new Map<string, number>()
    for (const r of rows) m.set(r.month, (m.get(r.month) ?? 0) + r.total_czk)
    return Array.from(m.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([month, total]) => ({ month, total, count: 0, currency: 'CZK' }))
  }
  return rows
    .slice()
    .sort((a, b) => a.month.localeCompare(b.month))
    .map(r => ({ month: r.month, total: r.total, count: 0, currency: r.currency }))
})

const purchaseByYear = computed(() => {
  const rows = client.value?.costs_by_year ?? []
  if (purchaseIsMultiCurrency.value) {
    const m = new Map<number, { total: number, count: number }>()
    for (const r of rows) {
      const v = m.get(r.year) ?? { total: 0, count: 0 }
      v.total += r.total_czk
      v.count += r.count
      m.set(r.year, v)
    }
    return Array.from(m.entries())
      .sort(([a], [b]) => b - a)
      .map(([year, v]) => ({ year: String(year), currency: 'CZK', total: v.total, count: v.count }))
  }
  return rows
    .slice()
    .sort((a, b) => b.year - a.year)
    .map(r => ({ year: String(r.year), currency: r.currency, total: r.total, count: r.count }))
})

const purchaseMonthlyChart = computed(() => ({
  labels: purchaseByMonth.value.map(r => r.month),
  values: purchaseByMonth.value.map(r => r.total),
}))

const purchaseTotalsByCurrency = computed(() => {
  const m = new Map<string, number>()
  for (const r of purchaseByYear.value) {
    m.set(r.currency, (m.get(r.currency) ?? 0) + r.total)
  }
  return Array.from(m.entries()).map(([currency, total]) => ({ currency, total }))
})

// Multi-currency revenue: pokud má klient vystavené faktury ve více měnách (např. EUR+USD),
// zobrazíme graf/tabulky v CZK (přepočet přes i.exchange_rate fixovaný k DUZP, dodaný backendem
// jako *_czk fieldy). Pro single-currency klienta zachováme původní měnu.
const revenueCurrencies = computed(() => {
  const s = new Set<string>()
  for (const r of client.value?.revenue_by_month ?? []) s.add(r.currency)
  return Array.from(s)
})
const revenueIsMultiCurrency = computed(() => revenueCurrencies.value.length > 1)
const primaryCurrency = computed(() => {
  if (revenueIsMultiCurrency.value) return 'CZK'
  // Single-ccy: nejčastější v datech, fallback default
  const tally: Record<string, number> = {}
  for (const r of client.value?.revenue_by_month ?? []) tally[r.currency] = (tally[r.currency] ?? 0) + r.total
  const top = Object.entries(tally).sort((a, b) => b[1] - a[1])[0]
  return top?.[0] || client.value?.currency_default || 'CZK'
})
const overdueAny = computed(() => (client.value?.unpaid_summary ?? []).some(u => u.overdue_count > 0))

// Single-ccy: zobrazujeme jednotlivé řádky per měna jako dnes (BC).
// Multi-ccy: agregujeme přes všechny měny do CZK přes total_czk.
const monthlyChart = computed(() => {
  if (revenueIsMultiCurrency.value) {
    // Sumace všech měn na CZK per měsíc
    const m = new Map<string, number>()
    for (const r of client.value?.revenue_by_month ?? []) {
      m.set(r.month, (m.get(r.month) ?? 0) + r.total_czk)
    }
    const sorted = Array.from(m.entries()).sort(([a], [b]) => a.localeCompare(b))
    return { labels: sorted.map(([k]) => k), values: sorted.map(([, v]) => v) }
  }
  const data = (client.value?.revenue_by_month ?? []).filter(r => r.currency === primaryCurrency.value)
  return { labels: data.map(r => r.month), values: data.map(r => r.total) }
})

const yearTable = computed(() => {
  // Multi-ccy: sloučí roky do jednoho řádku v CZK.
  if (revenueIsMultiCurrency.value) {
    const m = new Map<number, { total: number, count: number }>()
    for (const r of client.value?.revenue_by_year ?? []) {
      const v = m.get(r.year) ?? { total: 0, count: 0 }
      v.total += r.total_czk
      v.count += r.count
      m.set(r.year, v)
    }
    return Array.from(m.entries())
      .sort(([a], [b]) => b - a)
      .map(([year, v]) => ({ year, currency: 'CZK', total: v.total, count: v.count }))
  }
  return client.value?.revenue_by_year ?? []
})

const projectsChart = computed(() => {
  if (revenueIsMultiCurrency.value) {
    // Sloučí stejný projekt z různých měn → součet v CZK.
    const m = new Map<string, number>()
    for (const r of client.value?.revenue_by_project ?? []) {
      if (r.total_czk <= 0) continue
      const key = r.project_name ?? t('client.no_project')
      m.set(key, (m.get(key) ?? 0) + r.total_czk)
    }
    const entries = Array.from(m.entries()).sort(([, a], [, b]) => b - a)
    return { labels: entries.map(([k]) => k), values: entries.map(([, v]) => v) }
  }
  const data = (client.value?.revenue_by_project ?? []).filter(r => r.currency === primaryCurrency.value && r.total > 0)
  return {
    labels: data.map(r => r.project_name ?? t('client.no_project')),
    values: data.map(r => r.total),
  }
})

const projectsTable = computed(() => {
  // Single-ccy: per-currency řádky jako dnes.
  if (!revenueIsMultiCurrency.value) {
    return (client.value?.revenue_by_project ?? []).filter(r => r.total !== 0)
  }
  // Multi-ccy: sloučí projekty z různých měn (např. EUR + USD řádek stejného projektu) do CZK.
  const m = new Map<string, { project_id: number | null; project_name: string | null; total: number; count: number }>()
  for (const r of client.value?.revenue_by_project ?? []) {
    if (r.total_czk === 0) continue
    const key = `${r.project_id ?? 'none'}|${r.project_name ?? ''}`
    const v = m.get(key) ?? { project_id: r.project_id, project_name: r.project_name, total: 0, count: 0 }
    v.total += r.total_czk
    v.count += r.count
    m.set(key, v)
  }
  return Array.from(m.values())
    .sort((a, b) => b.total - a.total)
    .map(v => ({ ...v, currency: 'CZK' }))
})

// Smazat lze jen klienta bez navázaných faktur a zakázek (jinak archivovat)
const canDelete = computed(() => {
  if (!client.value) return false
  const projects = client.value.projects_count ?? client.value.projects?.length ?? 0
  const invoices = client.value.invoices_count ?? 0
  return projects === 0 && invoices === 0
})

async function load() {
  const id = Number(route.params.id)
  loading.value = true
  invoicesLoading.value = true
  purchaseInvoicesLoading.value = true
  invoicesPage.value = 1
  try {
    const [c, grouped, rec, purchaseGrouped] = await Promise.all([
      clientsApi.get(id),
      invoicesApi.listGrouped({ client_id: id, page: 1 }),
      // per_page=200 — v detailu klienta chceme všechny šablony naráz (vždy malé číslo).
      recurringApi.list({ client_id: id, per_page: 200 }).catch(() => ({ data: [] as RecurringTemplate[] })),
      // per_page=200 = backend max, aby v detailu klienta byly všechny přijaté faktury najednou
      // (rok 2024 + starší by jinak vypadly z první stránky při per_page=20 v cfg.php).
      purchaseInvoicesApi.listGrouped({ vendor_id: id, per_page: 200 }).catch(() => ({ data: [] as Array<{invoices: PurchaseInvoiceListItem[]}> })),
    ])
    client.value = c
    invoices.value = grouped.data.flatMap(g => g.invoices)
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
    recurringTemplates.value = rec.data
    purchaseInvoices.value = (purchaseGrouped.data ?? []).flatMap((g: any) => g.invoices ?? [])
  } finally {
    loading.value = false
    invoicesLoading.value = false
    purchaseInvoicesLoading.value = false
  }
}

function freqLabel(f: string): string {
  return t(`recurring.frequency_${f}`)
}

function recurringStatusBadgeClass(s: string): string {
  return {
    active:  'bg-success-50 text-success-700 border-success-200',
    paused:  'bg-warning-50 text-warning-700 border-warning-200',
    expired: 'bg-neutral-100 text-neutral-500 border-neutral-200',
  }[s] ?? 'bg-neutral-100 text-neutral-500'
}

async function loadMoreInvoices() {
  if (!client.value) return
  invoicesLoadingMore.value = true
  invoicesPage.value++
  try {
    const grouped = await invoicesApi.listGrouped({ client_id: client.value.id, page: invoicesPage.value })
    invoices.value.push(...grouped.data.flatMap(g => g.invoices))
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    invoicesLoadingMore.value = false
  }
}

onMounted(load)

async function archive() {
  if (!client.value) return
  if (!confirm(t('client.archive_confirm'))) return
  await clientsApi.archive(client.value.id)
  router.push('/clients')
}

async function unarchive() {
  if (!client.value) return
  await clientsApi.unarchive(client.value.id)
  await load()
}

async function deleteClient() {
  if (!client.value) return
  if (!confirm(t('client.delete_warning', { name: client.value.company_name }))) return
  try {
    await clientsApi.delete(client.value.id)
    router.push('/clients')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('client.delete_failed'))
  }
}

// ─── Lišta akcí (sdílená ActionBar) — sjednocuje horní toolbar i spodní lištu ───
const clientActions = computed<ActionItem[]>(() => {
  const c = client.value
  if (!c) return []
  const w = auth.canWrite('clients')
  return [
    { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'primary', variant: 'primary',
      show: w, to: `/clients/${c.id}/edit` },
    { key: 'vat', label: vatInfoLoading.value ? t('common.loading') : t('client.vat_payer_details'), icon: 'badgeCheck',
      tier: 'secondary', variant: 'primary', show: !!c.dic, disabled: vatInfoLoading.value, run: loadVatPayerDetails },
    { key: 'wr-link', label: t('workReportTracking.button'), icon: 'link', tier: 'secondary', variant: 'primary',
      show: auth.canWrite('clients.public_links'), run: () => { showWrLinkModal.value = true } },
    { key: 'archive', label: t('common.archive'), icon: 'archive', tier: 'overflow', variant: 'warning',
      show: auth.canWrite('clients.archive') && !c.archived_at, run: archive },
    { key: 'unarchive', label: t('common.restore'), icon: 'uturn', tier: 'overflow', variant: 'success',
      show: auth.canWrite('clients.archive') && !!c.archived_at, run: unarchive },
    { key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: canDelete.value && w, run: deleteClient },
  ]
})
useWindowTitle(
  () => client.value?.company_name ?? null,
  () => client.value ? [client.value.is_customer ? t('client.is_customer_label') : null, client.value.is_vendor ? t('client.is_vendor_label') : null].filter(Boolean).join(', ') : null,
)
useWindowRefresh(async () => {
  const id = Number(route.params.id)
  const [updated, grouped, rec, purchased] = await Promise.all([
    clientsApi.get(id),
    Promise.all(Array.from({ length: invoicesPage.value }, (_, index) => invoicesApi.listGrouped({ client_id: id, page: index + 1 }))),
    recurringApi.list({ client_id: id, per_page: 200 }),
    purchaseInvoicesApi.listGrouped({ vendor_id: id, per_page: 200 }),
  ])
  if (id !== Number(route.params.id)) return
  client.value = updated
  invoices.value = grouped.flatMap(result => result.data.flatMap(group => group.invoices))
  invoicesTotal.value = grouped[0]?.meta.total ?? 0
  invoicesPages.value = grouped[0]?.meta.pages ?? 1
  recurringTemplates.value = rec.data
  purchaseInvoices.value = purchased.data.flatMap(group => group.invoices)
}, () => !loading.value && !invoicesLoadingMore.value && !bankAccountSaving.value && !bankRegistrySyncing.value
  && !bankAccountNumber.value && !bankCode.value && !showWrLinkModal.value)
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="client" class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
      <div class="min-w-0">
        <RouterLink to="/clients" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('client.back_to_list') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1">{{ client.company_name }}</h1>
        <div v-if="client.first_name || client.last_name" class="text-sm text-neutral-600 mt-0.5">
          {{ [client.first_name, client.last_name].filter(Boolean).join(' ') }}
        </div>
        <div class="text-sm text-neutral-500 mt-1 flex flex-wrap items-center gap-x-2">
          <span v-if="client.ic"><span>{{ t('common.ic') }}</span> <span class="font-mono">{{ client.ic }}</span></span>
          <!-- Národní daňové číslo (#120): SK DIČ / Steuernummer / NIP / Adószám; u SK je `dic` = IČ DPH -->
          <span v-if="client.tax_number">· <span>{{ taxNumberLabel ?? t('common.tax_number') }}</span> <span class="font-mono">{{ client.tax_number }}</span></span>
          <span v-if="client.dic">· <span>{{ dicIsSk ? t('common.ic_dph') : t('common.dic') }}</span> <span class="font-mono">{{ client.dic }}</span></span>
          <span v-if="client.archived_at" class="px-2 py-0.5 text-xs bg-neutral-100 text-neutral-600 rounded">{{ t('common.archived') }}</span>
        </div>
      </div>
      <ActionBar :actions="clientActions" />
    </div>

    <!-- Detaily plátce DPH (na vyžádání z registru plátců DPH / MFČR) -->
    <div v-if="vatInfoOpen" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.vat_payer_details') }}</h3>
        <button @click="vatInfoOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-sm leading-none">✕</button>
      </div>
      <div v-if="vatInfoLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="vatInfoError" class="text-sm text-danger-500">{{ vatInfoError }}</div>
      <!-- Zahraniční EU DIČ → výsledek z VIES (#120) -->
      <template v-else-if="vatInfoVies">
        <div v-if="vatInfoVies.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_vies_unavailable') }}</div>
        <div v-else-if="!vatInfoVies.valid" class="text-sm text-neutral-600">{{ t('client.vat_payer_vies_not_registered') }}</div>
        <div v-else class="space-y-2 text-sm">
          <div>
            <span class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_vies_registered') }}</span>
          </div>
          <div v-if="vatInfoVies.vat_number" class="font-mono text-neutral-900">{{ vatInfoVies.vat_number }}</div>
          <div v-if="vatInfoVies.name" class="text-neutral-900">{{ vatInfoVies.name }}</div>
          <div v-if="vatInfoVies.address" class="text-neutral-600 whitespace-pre-line">{{ vatInfoVies.address }}</div>
          <p class="text-xs text-neutral-400">{{ t('client.vat_payer_vies_source') }}</p>
        </div>
      </template>
      <template v-else-if="vatInfo">
        <div v-if="vatInfo.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_unavailable') }}</div>
        <div v-else-if="!vatInfo.found" class="text-sm text-neutral-600">{{ t('client.vat_payer_not_registered') }}</div>
        <div v-else class="space-y-3 text-sm">
          <div class="flex items-center gap-2">
            <span class="text-neutral-500">{{ t('client.vat_payer_reliability') }}:</span>
            <span v-if="vatInfo.unreliable === true" class="px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">{{ t('client.vat_payer_unreliable') }}</span>
            <span v-else-if="vatInfo.unreliable === false" class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_reliable') }}</span>
            <span v-else class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('client.vat_payer_unknown') }}</span>
          </div>
          <div>
            <div class="text-neutral-500 mb-1">{{ t('client.vat_payer_accounts') }}:</div>
            <ul v-if="vatInfo.accounts.length" class="space-y-1">
              <li v-for="(a, i) in vatInfo.accounts" :key="i" class="font-mono text-neutral-900">{{ a.display }}</li>
            </ul>
            <div v-else class="text-neutral-500">{{ t('client.vat_payer_no_accounts') }}</div>
          </div>
          <p class="text-xs text-neutral-400">{{ t('client.vat_payer_source') }}</p>
        </div>
      </template>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <!-- Kontakt -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.section_contact') }}</h3>
        <dl class="space-y-2 text-sm">
          <div>
            <dt class="text-neutral-500">{{ t('client.email') }}</dt>
            <dd class="text-neutral-900">{{ client.main_email || '—' }}</dd>
          </div>
          <div v-if="client.phone">
            <dt class="text-neutral-500">{{ t('client.telephone') }}</dt>
            <dd class="text-neutral-900 font-mono">{{ client.phone }}</dd>
          </div>
        </dl>
        <!-- E-mailové kontakty dle účelu (#86) -->
        <div v-if="client.email_contacts?.length" class="mt-3 pt-3 border-t border-neutral-200">
          <div class="text-xs text-neutral-500 mb-2">{{ t('client.email_contacts.title') }}</div>
          <div class="space-y-2">
            <div v-for="ec in client.email_contacts" :key="ec.id ?? ec.email"
              :class="['text-sm', ec.is_active ? '' : 'opacity-50']">
              <div class="text-neutral-900 break-all">
                {{ ec.email }}
                <span v-if="ec.contact_name || ec.label" class="text-neutral-500 text-xs">
                  - {{ [ec.contact_name, ec.label].filter(Boolean).join(', ') }}</span>
                <span v-if="!ec.is_active" class="text-xs text-neutral-400">({{ t('client.email_contacts.inactive') }})</span>
              </div>
              <div class="flex flex-wrap gap-1 mt-0.5">
                <span v-for="u in ec.usages" :key="u.usage"
                  class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-primary-50 border border-primary-200 text-primary-700 text-[11px]">
                  {{ t(`client.email_contacts.usage.${u.usage}`) }}<template v-if="u.recipient !== 'to'"> · {{ u.recipient.toUpperCase() }}</template>
                </span>
                <span v-if="!ec.usages.length" class="text-[11px] text-neutral-400">{{ t('client.email_contacts.no_usage') }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Adresa -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.section_address') }}</h3>
        <div class="text-sm text-neutral-900 leading-relaxed">
          {{ client.street }}<br />
          {{ client.zip }} {{ client.city }}<br />
          {{ client.country_iso2 }}
        </div>
      </div>

      <!-- Nastavení -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('nav.settings') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.language_label') }}</dt><dd class="font-mono">{{ client.language.toUpperCase() }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ client.currency_default }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.due_label') }}</dt><dd>{{ formatPaymentDue(client) }}</dd></div>
          <div v-if="client.hourly_rate > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('client.hourly_rate') }}</dt><dd class="font-mono">{{ client.hourly_rate.toLocaleString('cs', { maximumFractionDigits: 6 }) }} {{ client.currency_default }}/h</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.rc_label') }}</dt><dd>{{ client.reverse_charge ? t('client.yes_short') : t('client.no_short') }}</dd></div>
          <div v-if="supplierStore.currentSupplier?.stock_enabled === true" data-test="client-price-level" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('client.price_level') }}</dt>
            <dd>
              <span class="inline-block px-2 py-0.5 text-xs rounded" :class="client.price_level_id != null ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">
                {{ client.price_level_id != null ? (client.price_level_name ?? `#${client.price_level_id}`) : t('client.price_level_default') }}
              </span>
            </dd>
          </div>
        </dl>
      </div>
    </div>

    <EntityDimensionDefaults entity="clients" :entity-id="client.id" mode="summary" />

    <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.bank_accounts.title') }}</h3>
          <p class="text-xs text-neutral-500 mt-1">{{ t('client.bank_accounts.hint') }}</p>
        </div>
        <button v-if="auth.canWrite('clients') && client.is_vendor && canSyncCzechBankRegistry" type="button"
          @click="syncBankAccountsFromRegistry" :disabled="bankRegistrySyncing" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ bankRegistrySyncing ? t('common.loading') : t('client.bank_accounts.sync_registry') }}
        </button>
      </div>

      <div v-if="client.bank_accounts?.length" class="divide-y divide-neutral-200 border border-neutral-200 rounded-lg mb-4">
        <div v-for="account in client.bank_accounts" :key="account.id"
          class="flex flex-wrap items-center gap-2 px-3 py-3">
          <span class="font-mono font-medium text-neutral-900 mr-auto">{{ bankAccountLabel(account) }}</span>
          <span v-if="account.source_vat_registry" class="px-2 py-0.5 rounded-full bg-success-50 border border-success-200 text-success-700 text-xs">{{ t('client.bank_accounts.source_registry') }}</span>
          <span v-if="account.source_bank_statement" class="px-2 py-0.5 rounded-full bg-primary-50 border border-primary-200 text-primary-700 text-xs">{{ t('client.bank_accounts.source_statement') }}</span>
          <span v-if="account.source_manual" class="px-2 py-0.5 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-600 text-xs">{{ t('client.bank_accounts.source_manual') }}</span>
          <button v-if="auth.canWrite('clients')" type="button" @click="removeBankAccount(account)"
            :disabled="deletingBankAccountIds.has(account.id)" :class="btnOutline('danger')" :title="t('common.delete')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
      <EmptyState v-else dense accent="neutral" icon="coin" class="mb-4" :title="t('client.bank_accounts.empty')" />

      <form v-if="auth.canWrite('clients')" class="flex flex-wrap items-end gap-2" @submit.prevent="addBankAccount">
        <label class="min-w-64 flex-1">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('client.bank_accounts.account_number') }}</span>
          <input v-model="bankAccountNumber" required :placeholder="t('client.bank_accounts.account_placeholder')"
            class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
        </label>
        <label class="w-32">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('client.bank_accounts.bank_code') }}</span>
          <input v-model="bankCode" inputmode="numeric" maxlength="11" placeholder="0100"
            class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
        </label>
        <button type="submit" :disabled="bankAccountSaving || !bankAccountNumber.trim()" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ bankAccountSaving ? t('common.loading') : t('client.bank_accounts.add') }}
        </button>
      </form>
    </section>

    <!-- KPI: nezaplaceno + po splatnosti -->
    <div v-if="(client.unpaid_summary?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.unpaid') }}</h3>
        <div class="space-y-1">
          <div v-for="u in client.unpaid_summary || []" :key="`u-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono text-neutral-900">{{ formatMoney(u.unpaid_total, u.currency) }}</span>
            <span class="text-xs text-neutral-500 ml-3 whitespace-nowrap">{{ t('client.n_invoices', { n: u.unpaid_count }) }}</span>
          </div>
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm" :class="overdueAny ? 'border-danger-500/40' : ''">
        <h3 class="text-sm font-semibold uppercase tracking-wide mb-3" :class="overdueAny ? 'text-danger-500' : 'text-neutral-500'">{{ t('client.overdue') }}</h3>
        <div class="space-y-1">
          <div v-for="u in client.unpaid_summary || []" :key="`o-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono" :class="u.overdue_total > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ formatMoney(u.overdue_total, u.currency) }}</span>
            <span class="text-xs ml-3 whitespace-nowrap" :class="u.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ t('client.n_invoices', { n: u.overdue_count }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Obrat: graf po měsících + sumace po letech -->
    <div v-if="(client.revenue_by_month?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.revenue_by_month') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }}<span v-if="revenueIsMultiCurrency" class="ml-1 text-neutral-400 normal-case">({{ t('client.converted_from', { ccys: revenueCurrencies.join(', ') }) }})</span></span>
        </div>
        <MonthlyRevenueChart :labels="monthlyChart.labels" :values="monthlyChart.values" :currency="primaryCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.revenue_by_year') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in yearTable" :key="`${r.year}-${r.currency}`">
              <td class="py-2 text-neutral-900 font-medium">{{ r.year }}</td>
              <td class="py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</td>
              <td class="py-2 pl-3 text-right text-xs text-neutral-500 whitespace-nowrap">{{ t('client.year_invoices', { n: r.count }) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Náklady (přijaté faktury) — graf po měsících + sumace po letech -->
    <div v-if="purchaseByMonth.length > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.costs_by_month') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ purchaseDisplayCurrency }}<span v-if="purchaseIsMultiCurrency" class="ml-1 text-neutral-400 normal-case">({{ t('client.converted_from', { ccys: purchaseCurrencies.join(', ') }) }})</span></span>
        </div>
        <MonthlyRevenueChart :labels="purchaseMonthlyChart.labels" :values="purchaseMonthlyChart.values" :currency="purchaseDisplayCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.costs_by_year') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in purchaseByYear" :key="`${r.year}-${r.currency}`">
              <td class="py-2 text-neutral-900 font-medium">{{ r.year }}</td>
              <td class="py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</td>
              <td class="py-2 pl-3 text-right text-xs text-neutral-500 whitespace-nowrap">{{ t('client.year_invoices', { n: r.count }) }}</td>
            </tr>
            <tr v-for="t in purchaseTotalsByCurrency" :key="`total-${t.currency}`" class="font-semibold border-t-2 border-neutral-200 pt-2">
              <td class="py-2 text-neutral-700">{{ $t('client.total') }}</td>
              <td class="py-2 text-right font-mono text-neutral-700">{{ formatMoney(t.total, t.currency) }}</td>
              <td></td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Obrat podle zakázek — graf + tabulka -->
    <div v-if="projectsTable.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.revenue_by_project') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }}</span>
        </div>
        <TopProjectsBarChart :labels="projectsChart.labels" :values="projectsChart.values" :currency="primaryCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.revenue_by_project_table') }}</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left py-2 font-medium">{{ t('project.name') }}</th>
                <th class="text-right py-2 font-medium">{{ t('common.revenue') }}</th>
                <th class="text-right py-2 pl-3 font-medium whitespace-nowrap">{{ t('client.invoices_short') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in projectsTable" :key="`p-${r.project_id ?? 'none'}-${r.currency}`">
                <td class="py-2 truncate max-w-[220px]">
                  <RouterLink v-if="r.project_id && !auth.isClientRole" :to="`/projects/${r.project_id}`" class="text-primary-700 hover:underline">
                    {{ r.project_name }}
                  </RouterLink>
                  <span v-else-if="r.project_id">{{ r.project_name }}</span>
                  <span v-else class="text-neutral-400 italic">{{ t('client.no_project') }}</span>
                </td>
                <td class="py-2 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                <td class="py-2 pl-3 text-right text-xs text-neutral-500">{{ r.count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Zakázky — visible pokud is_customer NEBO existují zakázky; role client (F6) zakázky nevidí -->
    <div v-if="!auth.isClientRole && auth.canRead('projects') && (client.is_customer !== false || (client.projects?.length ?? 0) > 0)"
         class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">{{ t('client.projects') }}</h3>
        <RouterLink v-if="auth.canWrite('projects.create')" :to="`/projects/new?client_id=${client.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('client.new_project') }}
        </RouterLink>
      </div>
      <EmptyState v-if="!client.projects?.length" dense accent="neutral" icon="folderOpen" :title="t('client.no_projects')" />
      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.name') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">Status</th>
            <th class="text-right px-4 py-2.5 font-medium">Sazba</th>
            <th class="text-center px-4 py-2.5 font-medium">Splatnost</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.number') }}</th>
            <th class="px-4 py-2.5 w-44"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="p in client.projects" :key="p.id" class="hover:bg-neutral-50">
            <td class="px-4 py-3 font-medium">{{ p.name }}</td>
            <td class="px-4 py-3">
              <span class="text-xs px-2 py-0.5 rounded"
                :class="{
                  'bg-success-50 text-success-600': p.status === 'active',
                  'bg-warning-50 text-warning-600': p.status === 'paused',
                  'bg-neutral-100 text-neutral-600': p.status === 'closed',
                }">{{ p.status }}</span>
            </td>
            <td class="px-4 py-3 text-right font-mono">{{ p.hourly_rate.toLocaleString('cs', { maximumFractionDigits: 6 }) }} {{ p.currency }}/h</td>
            <td class="px-4 py-3 text-center">{{ t('client.due_days_n', { n: p.payment_due_days }) }}</td>
            <td class="px-4 py-3 font-mono text-xs text-neutral-500">{{ p.project_number || '—' }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <RouterLink :to="`/projects/${p.id}`"
                class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded mr-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                Detail
              </RouterLink>
              <RouterLink :to="`/projects/${p.id}/edit`"
                class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Upravit
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="client.projects?.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="p in client.projects" :key="`m-${p.id}`"
          @click="router.push(`/projects/${p.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <RouterLink class="row-link font-medium text-neutral-900 truncate" :to="`/projects/${p.id}`" @click.stop @auxclick.stop>{{ p.name }}</RouterLink>
            <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
              :class="{
                'bg-success-50 text-success-600': p.status === 'active',
                'bg-warning-50 text-warning-600': p.status === 'paused',
                'bg-neutral-100 text-neutral-600': p.status === 'closed',
              }">{{ p.status }}</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">{{ p.project_number || '—' }}</span>
            <span>
              <span class="font-mono">{{ p.hourly_rate.toLocaleString('cs', { maximumFractionDigits: 6 }) }} {{ p.currency }}/h</span>
              <span class="text-neutral-400 mx-1.5">·</span>
              <span>{{ t('client.due_days_n', { n: p.payment_due_days }) }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Vystavené faktury — visible pokud is_customer NEBO existují vystavené faktury -->
    <div v-if="client.is_customer !== false || invoices.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">{{ t('client.issued_invoices') }} <span v-if="invoicesTotal" class="text-neutral-400 font-normal">({{ invoicesTotal }})</span></h3>
        <RouterLink v-if="auth.canWrite('invoices.create')" :to="`/invoices/new?client_id=${client.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
      <div v-if="invoicesLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="!invoices.length" dense accent="neutral" icon="doc" :title="t('common.no_data')" />
      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.type') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.issue_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.tax_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.due_date') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.amount_to_pay') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="inv in invoices" :key="inv.id" class="cursor-pointer hover:bg-neutral-50"
              :class="invoiceRowClass(inv.due_date, inv.status)"
              @click="router.push(`/invoices/${inv.id}`)">
            <td class="px-4 py-2.5 font-mono">
              <RouterLink class="row-link" :to="`/invoices/${inv.id}`" @click.stop @auxclick.stop>{{ inv.varsymbol || `#${inv.id}` }}</RouterLink>
            </td>
            <td class="px-4 py-2.5 text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
            <td class="px-4 py-2.5" :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ inv.tax_date ? formatDate(inv.tax_date) : '—' }}</td>
            <td class="px-4 py-2.5">
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-600 font-medium' : 'text-neutral-600'">
                {{ formatDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </td>
            <td class="px-4 py-2.5 text-center">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="invoices.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="inv in invoices" :key="`m-${inv.id}`"
          @click="router.push(`/invoices/${inv.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          :class="invoiceRowClass(inv.due_date, inv.status)">
          <div class="flex items-baseline justify-between gap-2">
            <RouterLink class="row-link font-mono font-medium text-neutral-900" :to="`/invoices/${inv.id}`" @click.stop @auxclick.stop>{{ inv.varsymbol || `#${inv.id}` }}</RouterLink>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span>{{ typeLabel(inv.invoice_type) }}</span>
            <span>
              <span>{{ formatDate(inv.issue_date) }}</span>
              <span class="text-neutral-400 mx-1"> → </span>
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                {{ formatDate(inv.due_date) }}
              </span>
            </span>
          </div>
          <div v-if="inv.tax_date" class="mt-0.5 text-xs">
            <span class="text-neutral-400">{{ t('invoice.tax_date') }}:</span>
            <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date) }}</span>
          </div>
          <div class="mt-2">
            <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
              {{ statusLabel(inv.status) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="invoices.length" class="px-5 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: invoices.length, total: invoicesTotal }) }}</span>
        <button v-if="invoicesPage < invoicesPages" @click="loadMoreInvoices" :disabled="invoicesLoadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ invoicesLoadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>

    <!-- Přijaté faktury — visible pokud is_vendor NEBO existují přijaté faktury -->
    <div v-if="client.is_vendor === true || purchaseInvoices.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">{{ t('client.received_invoices') }} <span v-if="purchaseInvoices.length" class="text-neutral-400 font-normal">({{ purchaseInvoices.length }})</span></h3>
        <RouterLink v-if="auth.canWrite('purchase_invoices.create')" :to="`/purchase-invoices/new?vendor_id=${client.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('purchase_invoice.actions.new') }}
        </RouterLink>
      </div>
      <div v-if="purchaseInvoicesLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="!purchaseInvoices.length" dense accent="neutral" icon="doc" :title="t('common.no_data')" />
      <div v-else class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('purchase_invoice.fields.issue_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('purchase_invoice.fields.tax_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('purchase_invoice.fields.due_date') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('purchase_invoice.totals.with_vat') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="pi in purchaseInvoices" :key="pi.id" class="cursor-pointer hover:bg-neutral-50"
              @click="router.push(`/purchase-invoices/${pi.id}`)">
            <td class="px-4 py-2.5 font-mono">
              <RouterLink class="row-link" :to="`/purchase-invoices/${pi.id}`" @click.stop @auxclick.stop>{{ pi.vendor_invoice_number || `#${pi.id}` }}</RouterLink>
            </td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(pi.issue_date) }}</td>
            <td class="px-4 py-2.5" :class="taxDateClass(pi.tax_date, pi.issue_date)">{{ pi.tax_date ? formatDate(pi.tax_date) : '—' }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(pi.due_date) }}</td>
            <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(pi.total_with_vat, pi.currency || 'CZK') }}</td>
            <td class="px-4 py-2.5 text-center">
              <span class="inline-block px-2 py-0.5 rounded text-xs font-medium"
                :class="pi.status === 'paid' ? 'bg-success-50 text-success-600' :
                        pi.status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' :
                        'bg-primary-50 text-primary-700'">{{ pi.status }}</span>
            </td>
          </tr>
        </tbody>
      </table></div>
    </div>

    <!-- Pravidelné fakturace — visible pokud is_customer NEBO existují recurring -->
    <div v-if="client.is_customer !== false || recurringTemplates.length > 0"
         class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">
          {{ t('recurring.title') }}
          <span v-if="recurringTemplates.length" class="text-neutral-400 font-normal">({{ recurringTemplates.length }})</span>
        </h3>
        <RouterLink v-if="auth.canWrite('recurring.create')" :to="`/recurring/new?client_id=${client.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('recurring.new') }}
        </RouterLink>
      </div>

      <EmptyState v-if="recurringTemplates.length === 0" dense accent="neutral" icon="cycle" :title="t('recurring.empty')" />

      <!-- Desktop -->
      <div v-else class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('recurring.name') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('recurring.frequency') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('recurring.next_run_date') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">Status</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('recurring.generated_invoices') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tpl in recurringTemplates" :key="tpl.id"
              @click="router.push({ name: 'recurring-detail', params: { id: tpl.id } })"
              class="cursor-pointer hover:bg-neutral-50">
              <td class="px-4 py-3 font-medium text-primary-700">
                <RouterLink class="row-link" :to="{ name: 'recurring-detail', params: { id: tpl.id } }" @click.stop @auxclick.stop>{{ tpl.name }}</RouterLink>
              </td>
              <td class="px-4 py-3">{{ freqLabel(tpl.frequency) }}<span v-if="tpl.end_of_month" class="text-neutral-400"> · EOM</span></td>
              <td class="px-4 py-3 font-mono text-xs">{{ formatDate(tpl.next_run_date) }}</td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded border" :class="recurringStatusBadgeClass(tpl.status)">
                  {{ t('recurring.status.' + tpl.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-neutral-700">{{ tpl.invoices_generated_count ?? 0 }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <RouterLink :to="{ name: 'recurring-detail', params: { id: tpl.id } }" @click.stop
                  class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  {{ t('recurring.actions.detail') }}
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile -->
      <div v-if="recurringTemplates.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="tpl in recurringTemplates" :key="`m-${tpl.id}`"
          @click="router.push({ name: 'recurring-detail', params: { id: tpl.id } })"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <RouterLink class="row-link font-medium text-neutral-900 truncate" :to="{ name: 'recurring-detail', params: { id: tpl.id } }" @click.stop @auxclick.stop>{{ tpl.name }}</RouterLink>
            <span class="text-xs px-2 py-0.5 rounded border whitespace-nowrap" :class="recurringStatusBadgeClass(tpl.status)">
              {{ t('recurring.status.' + tpl.status) }}
            </span>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span>{{ freqLabel(tpl.frequency) }}<span v-if="tpl.end_of_month" class="text-neutral-400"> · EOM</span></span>
            <span class="font-mono">{{ formatDate(tpl.next_run_date) }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-0.5">
            {{ tpl.invoices_generated_count ?? 0 }} faktur
          </div>
        </div>
      </div>
    </div>
    <LinkedDocumentsPanel v-if="client" class="mt-4 block" entity-type="client" :entity-id="client.id" />

    <SendWorkReportLinkModal v-if="client" :open="showWrLinkModal" scope="client" :entity-id="client.id" @close="showWrLinkModal = false" />
  </div>
</template>
