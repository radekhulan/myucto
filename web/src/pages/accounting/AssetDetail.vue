<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  assetsApi,
  type AssetDetail,
  type DepreciationPlan,
  type DepreciationPlanRow,
  type DisposalType,
} from '@/api/assets'
import { accountingApi } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney, formatMonth } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnIconSm } from '@/components/ui/buttonStyles'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const assetId = computed(() => Number(route.params.id))

const asset = ref<AssetDetail | null>(null)
const plan = ref<DepreciationPlan | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    asset.value = await assetsApi.get(assetId.value)
    try {
      plan.value = await assetsApi.plan(assetId.value)
    } catch {
      plan.value = null
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    router.push({ name: 'accounting-assets' })
  } finally {
    loading.value = false
  }
}
onMounted(load)

function apiError(e: any) {
  toast.error(e?.response?.data?.error?.message || t('common.error'))
}

const num = (v: unknown): number => Number(v ?? 0) || 0

const STATUS_BADGE: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  in_use: 'bg-success-50 text-success-600',
  disposed: 'bg-neutral-100 text-neutral-400',
}

const improvements = computed(() => asset.value?.improvements ?? [])
const improvementsTotal = computed(() => improvements.value.reduce((s, i) => s + num(i.amount), 0))

const summary = computed(() => {
  const s = plan.value?.asset_summary
  const a = asset.value
  if (s) return s
  if (!a) return null
  const increased = a.increased_input_price != null
    ? num(a.increased_input_price)
    : num(a.input_price) + improvementsTotal.value
  return {
    input_price: num(a.input_price),
    increased_input_price: increased,
    tax_residual: a.tax_residual != null ? num(a.tax_residual) : increased - num(a.opening_tax_amount),
    acc_residual: a.acc_residual != null ? num(a.acc_residual) : increased - num(a.opening_acc_amount),
    accumulated_depreciation: a.accumulated_depreciation != null ? num(a.accumulated_depreciation) : num(a.opening_acc_amount),
  }
})

// ── Plán odpisů ────────────────────────────────────────────────────────────
const planTab = ref<'tax' | 'accounting'>('tax')
const planRows = computed<DepreciationPlanRow[]>(() =>
  (planTab.value === 'tax' ? plan.value?.tax : plan.value?.accounting) ?? [])
const latestMaterializedYear = computed<number | null>(() => {
  const years = [...(plan.value?.tax ?? []), ...(plan.value?.accounting ?? [])]
    .filter(row => row.source === 'confirmed')
    .map(row => row.fiscal_year)
  return years.length > 0 ? Math.max(...years) : null
})
const showClaimedCol = computed(() => planRows.value.some(r => num(r.amount) !== num(r.full_amount)))
const expandedYear = ref<number | null>(null)

function toggleMonths(row: DepreciationPlanRow) {
  if (!row.months || row.months.length === 0) return
  expandedYear.value = expandedYear.value === row.fiscal_year ? null : row.fiscal_year
}

function rowStatus(row: DepreciationPlanRow): string {
  if (row.source === 'computed') return t('accounting.assets.plan.status_planned')
  return planTab.value === 'tax'
    ? t('accounting.assets.plan.status_confirmed')
    : t('accounting.assets.plan.status_posted')
}
function rowStatusClass(row: DepreciationPlanRow): string {
  if (row.source === 'computed') return 'bg-neutral-100 text-neutral-500'
  return 'bg-success-50 text-success-600'
}

async function runDeleteDepreciation(row: DepreciationPlanRow) {
  if (!row.journal_entry_id) return
  if (!confirm(t('accounting.assets.plan.delete_confirm', { year: row.fiscal_year }))) return
  acting.value = true
  try {
    await accountingApi.deleteEntry(row.journal_entry_id)
    toast.success(t('accounting.assets.plan.deleted', { year: row.fiscal_year }))
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

// ── Lifecycle: zařazení do užívání ─────────────────────────────────────────
const showPutIntoUse = ref(false)
const putIntoUseDate = ref(appIsoDate())
const putIntoUseBook = ref(true)
const acting = ref(false)

async function runPutIntoUse() {
  acting.value = true
  try {
    const r = await assetsApi.putIntoUse(assetId.value, { date: putIntoUseDate.value, book_entry: putIntoUseBook.value })
    for (const w of r.warnings || []) toast.warning(w.message)
    showPutIntoUse.value = false
    toast.success(t('accounting.assets.lifecycle.put_into_use_done'))
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

// ── Lifecycle: smazání karty ───────────────────────────────────────────────
async function removeAsset() {
  const confirmKey = asset.value?.status === 'in_use'
    ? 'accounting.assets.lifecycle.delete_in_use_confirm'
    : 'accounting.assets.lifecycle.delete_confirm'
  if (!confirm(t(confirmKey))) return
  acting.value = true
  try {
    await assetsApi.remove(assetId.value)
    toast.success(t('common.deleted'))
    router.push({ name: 'accounting-assets' })
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

// ── Lifecycle: technické zhodnocení ────────────────────────────────────────
const showImprovement = ref(false)
const impForm = ref({ completed_on: '', amount: null as number | null, description: '' })

async function runAddImprovement() {
  if (!impForm.value.completed_on || !impForm.value.amount || impForm.value.amount <= 0) {
    toast.error(t('accounting.assets.improvement.err_fields'))
    return
  }
  acting.value = true
  try {
    const r = await assetsApi.addImprovement(assetId.value, {
      completed_on: impForm.value.completed_on,
      amount: Number(impForm.value.amount),
      description: impForm.value.description.trim() || undefined,
    })
    for (const w of r.warnings || []) toast.warning(w.message)
    toast.success(t('accounting.assets.improvement.added'))
    impForm.value = { completed_on: '', amount: null, description: '' }
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

async function runDeleteImprovement(impId: number) {
  if (!confirm(t('accounting.assets.improvement.delete_confirm'))) return
  try {
    await assetsApi.deleteImprovement(assetId.value, impId)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) { apiError(e) }
}

// ── Lifecycle: přerušení odpisu (§26/8) ────────────────────────────────────
const showPause = ref(false)
const pauseYear = ref(new Date().getFullYear())
const canPause = computed(() =>
  asset.value?.tax_method === 'straight' || asset.value?.tax_method === 'accelerated')

async function runPause() {
  acting.value = true
  try {
    await assetsApi.pause(assetId.value, pauseYear.value)
    showPause.value = false
    toast.success(t('accounting.assets.lifecycle.paused', { year: pauseYear.value }))
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

async function runUnpause(year: number) {
  if (!confirm(t('accounting.assets.lifecycle.unpause_confirm', { year }))) return
  try {
    await assetsApi.unpause(assetId.value, year)
    toast.success(t('accounting.assets.lifecycle.unpaused', { year }))
    await load()
  } catch (e: any) { apiError(e) }
}

// ── Lifecycle: vyřazení ────────────────────────────────────────────────────
const showDispose = ref(false)
const disposeForm = ref({
  date: appIsoDate(),
  type: 'sold' as DisposalType,
  price: null as number | null,
})

async function runDispose() {
  acting.value = true
  try {
    const result = await assetsApi.dispose(assetId.value, {
      date: disposeForm.value.date,
      type: disposeForm.value.type,
      price: disposeForm.value.type === 'sold' ? disposeForm.value.price : undefined,
    })
    showDispose.value = false
    toast.success(t('accounting.assets.lifecycle.disposed'))
    for (const warning of result.warnings || []) {
      const key = `accounting.assets.hints.${warning.code}`
      const localized = t(key)
      toast.warning(localized !== key ? localized : warning.message)
    }
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

// ── Inventární karta (PDF, #49) ────────────────────────────────────────────
const downloadingCard = ref(false)

/**
 * Akce karty majetku pro sdílený ActionBar.
 *
 * Pořadí určuje, co zůstane inline: první je primary (plné tlačítko = další
 * logický krok podle stavu), pak dvě secondary, zbytek spadne do „…".
 * Mazání a stornování vyřazení jsou `overflow` — destruktivní a vzácné akce
 * nepatří do hlavičky, kde se dají trefit omylem.
 */
const assetActions = computed<ActionItem[]>(() => {
  const a = asset.value
  if (!a) return []
  const canWrite = auth.canWrite('assets.write')
  const draft = a.status === 'draft'
  const inUse = a.status === 'in_use'
  const disposed = !draft && !inUse

  return [
    {
      key: 'put_into_use',
      label: t('accounting.assets.lifecycle.put_into_use'),
      icon: 'checkCircle',
      tier: 'primary',
      variant: 'primary',
      show: canWrite && draft,
      run: () => { showPutIntoUse.value = true },
    },
    {
      key: 'improvement',
      label: t('accounting.assets.lifecycle.improvement'),
      icon: 'plus',
      tier: 'primary',
      variant: 'primary',
      show: canWrite && inUse,
      run: () => { showImprovement.value = true },
    },
    {
      key: 'revert',
      label: t('accounting.assets.lifecycle.revert'),
      icon: 'uturn',
      tier: 'primary',
      variant: 'neutral',
      show: canWrite && disposed,
      disabled: acting.value,
      run: () => { void runRevertDisposal() },
    },
    {
      key: 'edit',
      label: t('common.edit'),
      icon: 'edit',
      tier: 'secondary',
      show: canWrite && (draft || inUse),
      to: { name: 'accounting-asset-edit', params: { id: a.id } },
    },
    {
      key: 'download_card',
      label: t('accounting.assets.lifecycle.download_card'),
      icon: 'download',
      tier: 'secondary',
      loading: downloadingCard.value,
      run: () => { void downloadDepreciationCard() },
    },
    {
      key: 'pause',
      label: t('accounting.assets.lifecycle.pause'),
      icon: 'pause',
      tier: 'secondary',
      show: canWrite && inUse && canPause.value,
      run: () => { showPause.value = true },
    },
    {
      key: 'dispose',
      label: t('accounting.assets.lifecycle.dispose'),
      icon: 'archive',
      tier: 'overflow',
      variant: 'danger',
      show: canWrite && inUse,
      run: () => { showDispose.value = true },
    },
    {
      key: 'delete',
      label: t('common.delete'),
      icon: 'trash',
      tier: 'overflow',
      variant: 'danger',
      show: canWrite && (draft || inUse),
      disabled: acting.value,
      run: () => { void removeAsset() },
    },
  ]
})

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

async function downloadDepreciationCard() {
  if (!asset.value) return
  downloadingCard.value = true
  try {
    const r = await assetsApi.depreciationCard(assetId.value)
    downloadBlob(r.data as unknown as Blob, `inventarni-karta-${asset.value.inventory_number}.pdf`)
  } catch {
    toast.error(t('accounting.assets.lifecycle.download_card_error'))
  } finally {
    downloadingCard.value = false
  }
}

async function runRevertDisposal() {
  if (!confirm(t('accounting.assets.lifecycle.revert_confirm'))) return
  acting.value = true
  try {
    await assetsApi.revertDisposal(assetId.value)
    toast.success(t('accounting.assets.lifecycle.reverted'))
    await load()
  } catch (e: any) { apiError(e) } finally { acting.value = false }
}

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  const arr: number[] = []
  for (let i = y + 1; i >= y - 6; i--) arr.push(i)
  return arr
})

</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="asset">
      <!-- Hlavička -->
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-2xl font-semibold">{{ asset.name }}</h1>
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGE[asset.status]">
              {{ t(`accounting.assets.status.${asset.status}`) }}
            </span>
          </div>
          <p class="text-sm text-neutral-500 mt-0.5">
            <span class="font-mono">{{ asset.inventory_number }}</span>
            · {{ t(`accounting.assets.kind.${asset.kind}`) }}
            · {{ t(`accounting.assets.method.${asset.tax_method}`) }}<template v-if="asset.tax_group"> ({{ t(`accounting.assets.group.${asset.tax_group}`) }})</template>
          </p>
          <p class="text-xs text-neutral-400 mt-0.5 font-mono">
            {{ asset.asset_account_code }}<template v-if="asset.accumulated_account_code"> / {{ asset.accumulated_account_code }}</template>
            / {{ asset.acquisition_account_code }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <RouterLink :to="{ name: 'accounting-assets' }" class="text-sm text-neutral-500 hover:text-neutral-700 mr-1">
            {{ t('common.back') }}
          </RouterLink>
          <!-- Šest tlačítek v řadě porušovalo konvenci „max 3 a zbytek do …"
               (AGENTS.md §Frontend). ActionBar to řeší sám: primary zůstává plná,
               další dvě outline, zbytek spadne do dropdownu. -->
          <ActionBar :actions="assetActions" />
        </div>
      </div>

      <!-- Vyřazení info -->
      <div v-if="asset.status === 'disposed'" class="bg-warning-50 border border-warning-500/30 rounded-lg p-3 mb-4 text-sm">
        {{ t('accounting.assets.disposal_info', {
          type: t(`accounting.assets.disposal.${asset.disposal_type}`),
          date: formatDate(asset.disposal_date),
        }) }}
        <template v-if="asset.disposal_type === 'sold' && asset.disposal_price != null">
          · {{ t('accounting.assets.fields.disposal_price') }}: {{ formatMoney(num(asset.disposal_price)) }}
          <div class="text-xs text-warning-600 mt-1">{{ t('accounting.assets.hints.sale_invoice_641') }}</div>
        </template>
      </div>

      <!-- Souhrn -->
      <div v-if="summary" class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.assets.fields.input_price') }}</div>
          <div class="text-sm font-mono font-semibold mt-1">{{ formatMoney(num(summary.input_price)) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.assets.fields.increased_input_price') }}</div>
          <div class="text-sm font-mono font-semibold mt-1">{{ formatMoney(num(summary.increased_input_price)) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.assets.summary_accumulated') }}</div>
          <div class="text-sm font-mono font-semibold mt-1">{{ formatMoney(num(summary.accumulated_depreciation)) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.assets.col_tax_residual') }}</div>
          <div class="text-sm font-mono font-semibold mt-1">
            <template v-if="asset.tax_method === 'none'">—</template>
            <template v-else>{{ formatMoney(num(summary.tax_residual)) }}</template>
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.assets.col_acc_residual') }}</div>
          <div class="text-sm font-mono font-semibold mt-1">
            <template v-if="!asset.accumulated_account_code">—</template>
            <template v-else>{{ formatMoney(num(summary.acc_residual)) }}</template>
          </div>
        </div>
      </div>

      <!-- Základní údaje -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-2">
          <div>
            <span class="text-xs text-neutral-500 block">{{ t('accounting.assets.fields.acquisition_date') }}</span>
            {{ formatDate(asset.acquisition_date) }}
          </div>
          <div>
            <span class="text-xs text-neutral-500 block">{{ t('accounting.assets.fields.put_into_use_date') }}</span>
            {{ formatDate(asset.put_into_use_date) }}
          </div>
          <div v-if="asset.accumulated_account_code">
            <span class="text-xs text-neutral-500 block">{{ t('accounting.assets.fields.acc_method') }}</span>
            {{ t(`accounting.assets.accMethod.${asset.acc_method ?? 'straight_line'}`) }}
          </div>
          <div v-if="asset.accumulated_account_code && asset.acc_method !== 'by_tax'">
            <span class="text-xs text-neutral-500 block">{{ t('accounting.assets.fields.acc_useful_life_months') }}</span>
            {{ asset.acc_useful_life_months ?? '—' }}
          </div>
          <div v-if="asset.purchase_invoice_id">
            <span class="text-xs text-neutral-500 block">{{ t('accounting.assets.fields.purchase_invoice') }}</span>
            <RouterLink :to="{ name: 'purchase-invoice-detail', params: { id: asset.purchase_invoice_id } }"
              class="text-primary-600 hover:text-primary-700">
              {{ t('accounting.assets.editor.invoice_link', { id: asset.purchase_invoice_id }) }}
            </RouterLink>
          </div>
        </div>
        <p v-if="asset.description" class="mt-2 text-neutral-600">{{ asset.description }}</p>
        <!-- Proklik do deníku na zápis zařazení majetku (FEATURA C, audit 2026-07 follow-up). -->
        <div v-if="asset.status !== 'draft'" class="mt-3 pt-3 border-t border-neutral-100">
          <RouterLink :to="{ name: 'accounting-journal', query: { source_type: 'asset', source_id: String(asset.id) } }"
            class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700 hover:underline">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ t('common.view_in_journal') }}
          </RouterLink>
        </div>
      </div>

      <!-- Technická zhodnocení -->
      <div v-if="improvements.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 overflow-hidden">
        <h2 class="text-sm font-semibold px-4 pt-3 pb-2">{{ t('accounting.assets.improvement.list_title') }}</h2>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-32">{{ t('accounting.assets.improvement.col_date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.assets.improvement.col_description') }}</th>
              <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.assets.improvement.col_amount') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="imp in improvements" :key="imp.id">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(imp.completed_on) }}</td>
              <td class="px-3 py-2">{{ imp.description || '—' }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(num(imp.amount)) }}</td>
              <td class="px-3 py-2 text-right">
                <button v-if="auth.canWrite('assets.write') && asset.status === 'in_use'" @click="runDeleteImprovement(imp.id)"
                  class="cursor-pointer text-xs text-danger-500 hover:underline">{{ t('common.delete') }}</button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-neutral-300 font-semibold">
              <td class="px-3 py-2" colspan="2">{{ t('accounting.assets.improvement.total') }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(improvementsTotal) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Plán odpisů -->
      <div v-if="asset.tax_method !== 'none' || asset.accumulated_account_code" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 pt-3 flex items-center justify-between flex-wrap gap-2">
          <h2 class="text-sm font-semibold">{{ t('accounting.assets.plan.title') }}</h2>
          <div class="flex text-sm border border-neutral-300 rounded-md overflow-hidden">
            <button v-if="asset.tax_method !== 'none'" @click="planTab = 'tax'; expandedYear = null"
              class="cursor-pointer px-3 h-8" :class="planTab === 'tax' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
              {{ t('accounting.assets.plan.tab_tax') }}
            </button>
            <button v-if="asset.accumulated_account_code" @click="planTab = 'accounting'; expandedYear = null"
              class="cursor-pointer px-3 h-8" :class="planTab === 'accounting' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
              {{ t('accounting.assets.plan.tab_accounting') }}
            </button>
          </div>
        </div>
        <EmptyState v-if="planRows.length === 0" dense accent="neutral" icon="chart" :title="t('accounting.assets.plan.empty')" />
        <div v-else class="overflow-x-auto mt-2">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-8"></th>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.assets.plan.col_year') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.assets.plan.col_residual_start') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.assets.plan.col_amount') }}</th>
                <th v-if="showClaimedCol" class="px-3 py-2 text-right font-medium">{{ t('accounting.assets.plan.col_claimed') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.assets.plan.col_residual_end') }}</th>
                <th class="px-3 py-2 text-right font-medium w-44">{{ t('accounting.assets.plan.col_status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="row in planRows" :key="row.fiscal_year">
                <tr :class="{ 'cursor-pointer hover:bg-neutral-50': row.months && row.months.length > 0 }"
                  @click="toggleMonths(row)">
                  <td class="px-3 py-2 text-neutral-400">
                    <span v-if="row.months && row.months.length > 0" class="inline-block transition-transform"
                      :class="{ 'rotate-90': expandedYear === row.fiscal_year }">▸</span>
                  </td>
                  <td class="px-3 py-2 font-medium">
                    {{ row.fiscal_year }}
                    <span v-if="row.is_half" :title="t('accounting.assets.plan.badge_half')"
                      class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold">½</span>
                    <span v-if="row.is_paused" :title="t('accounting.assets.plan.badge_paused')"
                      class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-semibold">⏸</span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(num(row.residual_start)) }}</td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(num(row.full_amount)) }}</td>
                  <td v-if="showClaimedCol" class="px-3 py-2 text-right font-mono whitespace-nowrap"
                    :class="{ 'text-warning-600': num(row.amount) !== num(row.full_amount) }">
                    {{ formatMoney(num(row.amount)) }}
                  </td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(num(row.residual_end)) }}</td>
                  <!-- Stav + akce na JEDEN řádek. Textová tlačítka se do sloupce
                       nevešla a zalomila se pod sebe, takže potvrzený rok byl
                       třikrát vyšší než ostatní a tabulka se opticky rozpadla.
                       Akce jsou proto ikony s `title` — mazání jistí confirm(),
                       proklik do deníku je vratný, takže popisek stačí na hover. -->
                  <td class="px-3 py-2">
                    <div class="flex items-center justify-end gap-1.5">
                      <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="rowStatusClass(row)">{{ rowStatus(row) }}</span>
                      <!-- Proklik do deníku na zápis tohoto odpisu (FEATURA C, audit 2026-07 follow-up). -->
                      <RouterLink v-if="row.journal_entry_id"
                        :to="{ name: 'accounting-journal', query: { entry_id: String(row.journal_entry_id) } }"
                        @click.stop
                        :title="t('common.view_in_journal')" :aria-label="t('common.view_in_journal')"
                        :class="btnIconSm('primary')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
                      </RouterLink>
                      <button v-if="row.is_paused && row.source === 'confirmed' && planTab === 'tax' && auth.canWrite('assets.write') && asset.status === 'in_use'"
                        @click.stop="runUnpause(row.fiscal_year)"
                        :title="t('accounting.assets.lifecycle.unpause')" :aria-label="t('accounting.assets.lifecycle.unpause')"
                        :class="btnIconSm('primary')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
                      </button>
                      <button v-if="row.source === 'confirmed' && row.journal_entry_id && row.fiscal_year === latestMaterializedYear && auth.canWrite('assets.write')"
                        :disabled="acting" :class="btnIconSm('danger')"
                        :title="t('accounting.assets.plan.delete_booking')" :aria-label="t('accounting.assets.plan.delete_booking')"
                        @click.stop="runDeleteDepreciation(row)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandedYear === row.fiscal_year && row.months && row.months.length > 0">
                  <td :colspan="showClaimedCol ? 7 : 6" class="px-3 py-3 bg-neutral-50">
                    <table class="text-sm w-full max-w-md">
                      <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                        <tr>
                          <th class="px-2 py-1 text-left font-medium">{{ t('accounting.assets.plan.col_month') }}</th>
                          <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.assets.plan.col_amount') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-neutral-200">
                        <tr v-for="m in row.months" :key="m.month">
                          <td class="px-2 py-1">{{ formatMonth(m.month) }}</td>
                          <td class="px-2 py-1 text-right font-mono">{{ formatMoney(num(m.amount)) }}</td>
                        </tr>
                      </tbody>
                    </table>
                    <p v-if="row.note" class="text-xs text-neutral-500 mt-2">{{ row.note }}</p>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <p class="px-4 py-3 text-xs text-neutral-400 border-t border-neutral-100">
          {{ t('accounting.assets.plan.law_version_note') }}
        </p>
      </div>

      <!-- Modal: zařazení do užívání -->
      <Modal v-if="showPutIntoUse" :title="t('accounting.assets.lifecycle.put_into_use')" widthClass="max-w-md" @close="showPutIntoUse = false">
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.lifecycle.put_into_use_date') }}</label>
        <DateInput v-model="putIntoUseDate" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm mb-3" />
        <label class="inline-flex items-center gap-2 text-sm mb-4">
          <input v-model="putIntoUseBook" type="checkbox" class="rounded border-neutral-300" />
          {{ t('accounting.assets.lifecycle.put_into_use_book') }}
        </label>
        <p v-if="!putIntoUseBook" class="text-xs text-warning-600 mb-3">{{ t('accounting.assets.lifecycle.put_into_use_no_book_hint') }}</p>
        <div class="flex justify-end gap-2">
          <button @click="showPutIntoUse = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button :disabled="acting || !putIntoUseDate" @click="runPutIntoUse" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.confirm') }}
          </button>
        </div>
      </Modal>

      <!-- Modal: technické zhodnocení -->
      <Modal v-if="showImprovement" :title="t('accounting.assets.lifecycle.improvement')" widthClass="max-w-lg" @close="showImprovement = false">
        <p class="text-xs text-neutral-500 mb-3">{{ t('accounting.assets.hints.improvement_manual_entry') }}</p>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.improvement.col_date') }} *</label>
            <DateInput v-model="impForm.completed_on" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.improvement.col_amount') }} *</label>
            <input v-model.number="impForm.amount" type="number" min="0" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.improvement.col_description') }}</label>
            <input v-model="impForm.description" type="text" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-400 mb-4">{{ t('accounting.assets.hints.tz_below_80k') }}</p>
        <div class="flex justify-end gap-2">
          <button @click="showImprovement = false" :class="btnOutline('neutral')">{{ t('common.close') }}</button>
          <button :disabled="acting" @click="runAddImprovement" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('common.add') }}
          </button>
        </div>
      </Modal>

      <!-- Modal: přerušení odpisu -->
      <Modal v-if="showPause" :title="t('accounting.assets.lifecycle.pause')" widthClass="max-w-md" @close="showPause = false">
        <p class="text-xs text-neutral-500 mb-3">{{ t('accounting.assets.lifecycle.pause_hint') }}</p>
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('common.year') }}</label>
        <select v-model.number="pauseYear" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface mb-4">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <div class="flex justify-end gap-2">
          <button @click="showPause = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button :disabled="acting" @click="runPause" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ t('common.confirm') }}
          </button>
        </div>
      </Modal>

      <!-- Modal: vyřazení -->
      <Modal v-if="showDispose" :title="t('accounting.assets.lifecycle.dispose')" widthClass="max-w-md" @close="showDispose = false">
        <div class="space-y-3 mb-4">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.fields.disposal_type') }}</label>
            <select v-model="disposeForm.type" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="d in ['sold', 'liquidated', 'donated', 'damaged']" :key="d" :value="d">
                {{ t(`accounting.assets.disposal.${d}`) }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.fields.disposal_date') }}</label>
            <DateInput v-model="disposeForm.date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="disposeForm.type === 'sold'">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.fields.disposal_price') }}</label>
            <input v-model.number="disposeForm.price" type="number" min="0" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.assets.hints.sale_invoice_641') }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button @click="showDispose = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button :disabled="acting || !disposeForm.date" @click="runDispose" :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
            {{ t('accounting.assets.lifecycle.dispose_confirm_btn') }}
          </button>
        </div>
      </Modal>
    </template>
  </div>
</template>
