<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  recurringApi,
  type RecurringTemplate,
  type GeneratedInvoiceRow,
  type RecurringStatus,
} from '@/api/recurring'
import { useToast } from '@/composables/useToast'
import { validateRescheduleDate } from '@/utils/recurringSchedule'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import { FILLED, OUTLINE, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const priceListEnabled = computed(() => !auth.hasCommercialFeatures || supplierStore.currentSupplier?.stock_enabled !== true)

const id = computed(() => Number(route.params.id))
const loading = ref(false)
const busy = ref(false)
const tpl = ref<RecurringTemplate | null>(null)
const invoices = ref<GeneratedInvoiceRow[]>([])
const invoicesTotal = ref(0)
const invoicesPage = ref(1)
const invoicesPages = ref(1)
const loadingMoreInvoices = ref(false)

async function load() {
  loading.value = true
  try {
    const [t1, inv] = await Promise.all([
      recurringApi.get(id.value),
      recurringApi.invoices(id.value, { page: 1 }),
    ])
    tpl.value = t1
    invoices.value = inv.data
    invoicesTotal.value = inv.meta.total
    invoicesPage.value = inv.meta.page
    invoicesPages.value = inv.meta.pages
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
    router.push({ name: 'recurring' })
  } finally {
    loading.value = false
  }
}

async function loadMoreInvoices() {
  loadingMoreInvoices.value = true
  try {
    const inv = await recurringApi.invoices(id.value, { page: invoicesPage.value + 1 })
    invoices.value.push(...inv.data)
    invoicesTotal.value = inv.meta.total
    invoicesPage.value = inv.meta.page
    invoicesPages.value = inv.meta.pages
  } finally {
    loadingMoreInvoices.value = false
  }
}

onMounted(load)

function freqLabel(f: string): string {
  return t(`recurring.frequency_${f}`)
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ')
}

function formatMoney(n: number, ccy: string): string {
  return n.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + ccy
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

const totals = computed(() => {
  if (!tpl.value?.items?.length) return { base: 0, vat: 0, total: 0 }
  const reverseCharge = tpl.value.reverse_charge
  // V režimu „ceny s DPH" nese unit_price_without_vat brutto → DPH se počítá shora
  // koeficientem (jako InvoiceMath / RecurringForm), jinak zdola ze základu.
  const pricesIncl = tpl.value.prices_include_vat
  let base = 0
  let vat = 0
  for (const it of tpl.value.items) {
    const amount = round2((Number(it.quantity) || 0) * (Number(it.unit_price_without_vat) || 0))
    const ratePct = reverseCharge ? 0 : (Number(it.vat_rate_percent) || 0)
    if (pricesIncl) {
      const lineVat = round2(amount * ratePct / (100 + ratePct))
      base += round2(amount - lineVat)
      vat += lineVat
    } else {
      base += amount
      vat += round2(amount * ratePct / 100)
    }
  }
  return { base: round2(base), vat: round2(vat), total: round2(base + vat) }
})

// V režimu „ceny s DPH" nese unit_price_without_vat brutto; pro zobrazení ukážeme
// netto dopočtené koeficientem (u šablon nemáme uložené řádkové základy).
function displayUnitPriceNet(it: { unit_price_without_vat: number; vat_rate_percent?: number }): number {
  const gross = Number(it.unit_price_without_vat) || 0
  if (tpl.value?.prices_include_vat) {
    const rate = tpl.value.reverse_charge ? 0 : (Number(it.vat_rate_percent) || 0)
    return round2(gross * 100 / (100 + rate))
  }
  return gross
}

function statusBadgeClass(s: RecurringStatus) {
  return {
    active:  'bg-success-50 text-success-700 border-success-200',
    paused:  'bg-warning-50 text-warning-700 border-warning-200',
    expired: 'bg-neutral-100 text-neutral-500 border-neutral-200',
  }[s]
}

function invoiceBadgeClass(s: string): string {
  if (s === 'paid')      return 'bg-success-50 text-success-700'
  if (s === 'sent')      return 'bg-primary-50 text-primary-700'
  if (s === 'reminded')  return 'bg-warning-50 text-warning-700'
  if (s === 'cancelled') return 'bg-neutral-100 text-neutral-500'
  if (s === 'issued')    return 'bg-neutral-100 text-neutral-700'
  return 'bg-neutral-50 text-neutral-500'
}

async function pauseAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.pause_confirm', { name: tpl.value.name }))) return
  busy.value = true
  try {
    tpl.value = await recurringApi.pause(id.value)
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

async function resumeAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.resume_confirm', { name: tpl.value.name, date: formatDate(tpl.value.next_run_date) }))) return
  busy.value = true
  try {
    tpl.value = await recurringApi.resume(id.value)
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

const rescheduleModal = ref(false)
const rescheduleForm = ref<HTMLFormElement | null>(null)
const rescheduleDate = ref('')
const rescheduleConfirmed = ref(false)
const rescheduleError = ref('')
const rescheduleMin = computed(() => [todayIso(), tpl.value?.anchor_date || ''].sort().at(-1))

function openReschedule() {
  if (!tpl.value) return
  rescheduleError.value = ''
  rescheduleDate.value = tpl.value.next_run_date
  rescheduleConfirmed.value = false
  rescheduleModal.value = true
}

async function submitReschedule() {
  if (!tpl.value || busy.value) return
  rescheduleError.value = ''
  const error = validateRescheduleDate(rescheduleDate.value, tpl.value.next_run_date, rescheduleMin.value!, tpl.value.end_date)
  if (error) {
    rescheduleError.value = t(error, { min: formatDate(rescheduleMin.value!), max: formatDate(tpl.value.end_date) })
    return
  }
  if (!rescheduleConfirmed.value) {
    rescheduleError.value = t('recurring.reschedule_confirmation_required')
    return
  }
  if (!rescheduleForm.value?.reportValidity()) return
  busy.value = true
  try {
    tpl.value = await recurringApi.reschedule(id.value, rescheduleDate.value, tpl.value.next_run_date)
    rescheduleModal.value = false
    toast.success(t('recurring.reschedule_saved'))
  } catch (e: any) {
    rescheduleError.value = e?.response?.data?.error?.message || t('common.save_failed')
  } finally { busy.value = false }
}

const advanceSchedule = ref(true)
const runNowNextDate = computed(() => {
  const r = tpl.value
  if (!r) return null
  if (!advanceSchedule.value || isPeriodDraft.value) return r.next_run_date
  const [year, month, day] = r.next_run_date.split('-').map(Number)
  const months = { monthly: 1, quarterly: 3, semi_annually: 6, annually: 12 }[r.frequency]
  const next = new Date(Date.UTC(year!, month! - 1 + months, 1))
  if (r.end_of_month) next.setUTCMonth(next.getUTCMonth() + 1, 0)
  else next.setUTCDate(Math.max(1, Math.min(28, r.day_of_month ?? day!)))
  return next.toISOString().slice(0, 10)
})

const runNowModal = ref(false)
const runNowDate = ref('')
const runNowMode = ref<'issue' | 'draft'>('issue')
// period_start koncept se vytvoří přes openDraft k plánovanému datu (next_run_date) —
// datum se nevybírá a varování o budoucím datu nedává smysl.
const isPeriodDraft = computed(() => runNowMode.value === 'draft' && tpl.value?.draft_open_mode === 'period_start')

function todayIso(): string {
  return appIsoDate()
}

function openRunNow(mode: 'issue' | 'draft' = 'issue') {
  if (!tpl.value) return
  advanceSchedule.value = true
  runNowMode.value = mode
  runNowDate.value = todayIso()
  runNowModal.value = true
}

function closeRunNow() {
  runNowModal.value = false
}

async function submitRunNow() {
  if (!tpl.value) return
  if (!runNowDate.value) return
  const draft = runNowMode.value === 'draft'
  runNowModal.value = false
  busy.value = true
  try {
    const r = await recurringApi.runNow(id.value, runNowDate.value, draft, advanceSchedule.value)
    if (draft) {
      toast.success(t('recurring.run_now_draft_done', { id: r.invoice_id }))
    } else if (r.sent_to.length > 0) {
      toast.success(t('recurring.run_now_with_send', { varsymbol: r.varsymbol ?? `#${r.invoice_id}`, recipients: r.sent_to.join(', ') }))
    } else {
      toast.success(t('recurring.run_now_done', { id: r.invoice_id, varsymbol: r.varsymbol ? ` (${r.varsymbol})` : '' }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

async function removeAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.delete_confirm', { name: tpl.value.name }))) return
  busy.value = true
  try {
    await recurringApi.delete(id.value)
    toast.success(t('recurring.deleted'))
    router.push({ name: 'recurring' })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

// ─── Lišta akcí (sdílená ActionBar) ───
// Primární = hlavní akce dle stavu: aktivní → „Spustit teď", pozastavené → „Obnovit".
const recurringActions = computed<ActionItem[]>(() => {
  const r = tpl.value
  if (!r) return []
  const w = auth.canWrite('recurring')
  const canRun = auth.canWrite('recurring.run')
  const canPause = auth.canWrite('recurring.pause')
  const active = r.status === 'active'
  const paused = r.status === 'paused'
  return [
    { key: 'run', label: t('recurring.actions.run_now'), icon: 'play', tier: 'primary', variant: 'primary',
      show: active && canRun, disabled: busy.value, run: () => openRunNow('issue') },
    { key: 'resume', label: t('recurring.actions.resume'), icon: 'play', tier: 'primary', variant: 'success',
      show: paused && canPause, disabled: busy.value, run: resumeAction },
    { key: 'edit', label: t('recurring.actions.edit'), icon: 'edit', tier: 'secondary', variant: 'success',
      show: w, to: { name: 'recurring-edit', params: { id: r.id } } },
    { key: 'run-draft', label: t('recurring.actions.run_now_draft'), icon: 'doc', tier: 'secondary', variant: 'primary',
      show: active && canRun, disabled: busy.value, run: () => openRunNow('draft') },
    { key: 'pause', label: t('recurring.actions.pause'), icon: 'pause', tier: 'overflow', variant: 'warning',
      show: active && canPause, disabled: busy.value, run: pauseAction },
    { key: 'reschedule', label: t('recurring.reschedule_title'), icon: 'cycle', tier: 'overflow', variant: 'warning',
      show: w, disabled: busy.value, run: openReschedule },
    { key: 'delete', label: t('recurring.actions.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: auth.canWrite('recurring.delete'), disabled: busy.value, run: removeAction },
  ]
})
</script>

<template>
  <div class="max-w-5xl">
    <div v-if="loading" class="text-center text-neutral-400 py-12">…</div>

    <div v-else-if="tpl" class="space-y-4">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div class="min-w-0">
          <RouterLink :to="{ name: 'recurring' }" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('recurring.back_to_list') }}</RouterLink>
          <h1 class="text-2xl font-semibold flex items-center gap-3 flex-wrap mt-1">
            {{ tpl.name }}
            <span class="text-xs px-2 py-0.5 rounded border whitespace-nowrap" :class="statusBadgeClass(tpl.status)">
              {{ t('recurring.status.' + tpl.status) }}
            </span>
            <span class="text-xs px-2 py-0.5 rounded font-normal bg-primary-50 text-primary-700 border border-primary-200 whitespace-nowrap">
              ↻ {{ t('recurring.title') }}
            </span>
          </h1>
        </div>
        <ActionBar :actions="recurringActions" />
      </div>

      <!-- Banner: poslední generování (typicky cronem) selhalo -->
      <div v-if="tpl.last_error" class="rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
        <div class="font-semibold">⚠ {{ t('recurring.last_error_title') }}</div>
        <div class="mt-0.5">{{ tpl.last_error }}</div>
        <div v-if="tpl.last_error_at" class="mt-0.5 text-xs text-danger-600/80">{{ formatDate(tpl.last_error_at) }}</div>
      </div>

      <!-- Konfigurace -->
      <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_periodicity') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.frequency') }}</dt><dd>{{ freqLabel(tpl.frequency) }}</dd></div>
            <div v-if="tpl.end_of_month" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.day_of_month') }}</dt><dd>{{ t('recurring.end_of_month') }}</dd></div>
            <div v-else class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.day_of_month') }}</dt><dd>{{ tpl.day_of_month ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.anchor_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.anchor_date) }}</dd></div>
            <div v-if="tpl.end_date" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.end_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.end_date) }}</dd></div>
            <div class="flex justify-between pt-1 mt-1 border-t border-neutral-100"><dt class="text-neutral-500">{{ t('recurring.next_run_date') }}</dt><dd class="font-mono text-xs font-medium">{{ formatDate(tpl.next_run_date) }}</dd></div>
            <div v-if="tpl.last_run_date" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.last_run_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.last_run_date) }}</dd></div>
          </dl>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_invoice_meta') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring.client') }}</dt>
              <dd>
                <RouterLink :to="{ name: 'client-detail', params: { id: tpl.client_id } }" class="text-primary-700 hover:underline">
                  {{ tpl.client_company_name }}
                </RouterLink>
              </dd>
            </div>
            <div v-if="tpl.project_name" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.project') }}</dt><dd>{{ tpl.project_name }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.invoice_type') }}</dt><dd>{{ t('type.' + tpl.invoice_type) }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.currency') }}</dt><dd class="font-mono">{{ tpl.currency }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.language') }}</dt><dd>{{ tpl.language.toUpperCase() }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('payment_method.label') }}</dt><dd>{{ t('payment_method.' + tpl.payment_method) }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.payment_due_days') }}</dt><dd>{{ tpl.payment_due_days }}</dd></div>
            <div v-if="tpl.discount_percent > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.discount.label') }}</dt><dd>{{ tpl.discount_percent }} %</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.revenue_category') }}</dt><dd>{{ tpl.revenue_category_id ? (tpl.revenue_category_label ?? `#${tpl.revenue_category_id}`) : t('recurring.revenue_category_fallback') }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.tax_date_mode') }}</dt><dd>{{ t('recurring.tax_date_mode_' + (tpl.tax_date_mode ?? 'same_as_issue')) }}</dd></div>
          </dl>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_automation') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="text-neutral-500">{{ t('recurring.draft_open_mode') }}</dt>
              <dd class="text-right">{{ t('recurring.draft_open_mode_' + (tpl.draft_open_mode ?? 'at_issue')) }}</dd>
            </div>
            <div v-if="tpl.draft_open_mode === 'period_start'" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring.reminder_days_before') }}</dt>
              <dd>{{ (tpl.reminder_days_before ?? 0) > 0 ? tpl.reminder_days_before : '—' }}</dd>
            </div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.auto_issue') }}</dt><dd>{{ tpl.auto_issue ? '✓' : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.auto_send_email') }}</dt><dd>{{ tpl.auto_send_email ? '✓' : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.increment_month') }}</dt><dd>{{ tpl.increment_month_in_descriptions ? '✓' : '—' }}</dd></div>
          </dl>
        </div>
      </div>

      <div v-if="priceListEnabled && tpl.catalog_state && tpl.catalog_state !== 'ok'" class="rounded-md border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700" :title="tpl.catalog_error ?? ''">
        <strong>{{ t('recurring.catalog_state_' + tpl.catalog_state) }}</strong>
        <span v-if="tpl.catalog_error"> · {{ tpl.catalog_error }}</span>
      </div>

      <!-- Poznámka nad položkami -->
      <div v-if="tpl.note_above_items" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('invoice.note_above') }}</h3>
        <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ tpl.note_above_items }}</p>
      </div>

      <!-- Položky šablony -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold">{{ t('recurring.items') }}</h3>
        </div>
        <EmptyState v-if="!tpl.items?.length" dense icon="doc" :title="t('recurring.items_required')" />
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2 font-medium">{{ t('invoice.items_table.description') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('invoice.items_table.qty') }}</th>
              <th class="text-left px-4 py-2 font-medium">{{ t('invoice.items_table.unit') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('invoice.items_table.unit_price') }}</th>
              <th class="text-center px-4 py-2 font-medium">DPH</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="it in tpl.items" :key="it.id">
              <td class="px-4 py-2">
                <div>{{ it.description }}</div>
                <div v-if="priceListEnabled && it.price_list_item_id" class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                  <span class="status-badge bg-primary-50 text-primary-700">{{ t('recurring.catalog_badge') }}</span>
                  <span class="text-neutral-500">{{ it.price_list_item_code }} · {{ t('recurring.catalog_policy_' + (it.catalog_policy ?? 'fixed')) }}</span>
                  <span v-if="it.price_list_item_archived" class="status-badge bg-warning-50 text-warning-700">{{ t('recurring.catalog_archived') }}</span>
                </div>
                <!-- OSS rozhodnutí uložené na šabloně — bez badge by bylo vidět až v editoru. -->
                <div v-if="it.oss_applicable" class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                  <span class="status-badge bg-primary-50 text-primary-700">{{ t('invoice.oss.enabled') }}</span>
                  <span class="text-neutral-500">{{ it.oss_consumer_country }}</span>
                  <!-- Prázdný typ plnění je na šabloně legitimní (doplní ho odvození při
                       generování) — psát sem „Služby" by tvrdilo něco, co uložené není. -->
                  <span v-if="it.oss_supply_type" class="text-neutral-500">
                    · {{ it.oss_supply_type === 'goods' ? t('invoice.oss.goods') : t('invoice.oss.services') }}
                  </span>
                  <span v-if="!it.oss_rate_type" class="status-badge bg-warning-50 text-warning-700">{{ t('invoice.oss.rate_type_missing') }}</span>
                  <span v-else class="text-neutral-500">{{ t('invoice.oss.rate_' + it.oss_rate_type) }}</span>
                </div>
              </td>
              <td class="px-4 py-2 text-right font-mono">{{ it.quantity }}</td>
              <td class="px-4 py-2">{{ it.unit }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ formatMoney(displayUnitPriceNet(it), tpl.currency ?? '') }}</td>
              <td class="px-4 py-2 text-center text-neutral-600">{{ Number(it.vat_rate_percent) > 0 ? it.vat_rate_percent + ' %' : '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="tpl.items?.length" class="px-5 py-3 bg-neutral-50 border-t border-neutral-200">
          <dl class="ml-auto max-w-xs space-y-1 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('invoice.totals.without_vat') }}</dt>
              <dd class="font-mono">{{ formatMoney(totals.base, tpl.currency ?? '') }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-neutral-500">
                {{ t('invoice.totals.vat') }}
                <span v-if="tpl.reverse_charge" class="text-xs text-neutral-400">({{ t('invoice.reverse_charge') }})</span>
              </dt>
              <dd class="font-mono">{{ formatMoney(totals.vat, tpl.currency ?? '') }}</dd>
            </div>
            <div class="flex justify-between pt-1 mt-1 border-t border-neutral-200 font-medium">
              <dt>{{ t('invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(totals.total, tpl.currency ?? '') }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Poznámka pod položkami -->
      <div v-if="tpl.note_below_items" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('invoice.note_below') }}</h3>
        <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ tpl.note_below_items }}</p>
      </div>

      <!-- Vygenerované faktury -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="font-semibold">
            {{ t('recurring.generated_invoices') }}
            <span class="text-neutral-400 font-normal">({{ invoicesTotal }})</span>
          </h3>
        </div>

        <EmptyState v-if="invoices.length === 0" dense icon="doc" :title="t('recurring.empty')" />

        <!-- Desktop -->
        <div v-else class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol_label') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">Status</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('recurring.anchor_date') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">Splatnost</th>
                <th class="text-right px-4 py-2.5 font-medium">Celkem</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="inv in invoices" :key="inv.id"
                @click="router.push({ name: 'invoice-detail', params: { id: inv.id } })"
                class="cursor-pointer hover:bg-neutral-50">
                <td class="px-4 py-3 font-mono text-primary-700">
                  <RouterLink class="row-link" :to="{ name: 'invoice-detail', params: { id: inv.id } }" @click.stop @auxclick.stop>{{ inv.varsymbol ?? `#${inv.id}` }}</RouterLink>
                </td>
                <td class="px-4 py-3">
                  <span class="text-xs px-2 py-0.5 rounded" :class="invoiceBadgeClass(inv.status)">
                    {{ t('status.' + inv.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ formatDate(inv.issue_date) }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ formatDate(inv.due_date) }}</td>
                <td class="px-4 py-3 text-right font-mono">{{ formatMoney(inv.total_with_vat, inv.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile -->
        <div v-if="invoices.length" class="md:hidden divide-y divide-neutral-100">
          <div v-for="inv in invoices" :key="`m-${inv.id}`"
            @click="router.push({ name: 'invoice-detail', params: { id: inv.id } })"
            class="cursor-pointer hover:bg-neutral-50 px-4 py-3">
            <div class="flex items-baseline justify-between gap-2">
              <RouterLink class="row-link font-mono text-primary-700 font-medium" :to="{ name: 'invoice-detail', params: { id: inv.id } }" @click.stop @auxclick.stop>{{ inv.varsymbol ?? `#${inv.id}` }}</RouterLink>
              <span class="font-mono text-sm">{{ formatMoney(inv.total_with_vat, inv.currency) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
              <span class="text-xs px-2 py-0.5 rounded" :class="invoiceBadgeClass(inv.status)">
                {{ t('status.' + inv.status) }}
              </span>
              <span class="font-mono">{{ formatDate(inv.issue_date) }}</span>
            </div>
          </div>
        </div>

        <div v-if="invoicesPage < invoicesPages" class="text-center py-3 border-t border-neutral-200">
          <button @click="loadMoreInvoices" :disabled="loadingMoreInvoices"
            class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-2 shadow-sm">
            {{ loadingMoreInvoices ? t('common.loading_more') : t('common.load_more') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="rescheduleModal && tpl" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
      role="dialog" aria-modal="true" aria-labelledby="reschedule-title" @keydown.esc="!busy && (rescheduleModal = false)">
      <form ref="rescheduleForm" novalidate @submit.prevent="submitReschedule" class="bg-surface rounded-lg shadow-xl max-w-md w-full p-6">
        <h2 id="reschedule-title" class="text-lg font-semibold mb-2">{{ t('recurring.reschedule_title') }}</h2>
        <p class="text-sm text-neutral-600 mb-3">{{ tpl.name }}</p>
        <p class="text-sm mb-3">{{ t('recurring.reschedule_current', { date: formatDate(tpl.next_run_date) }) }}</p>
        <div class="rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 mb-4">
          {{ t('recurring.reschedule_warning') }}
        </div>
        <p v-if="tpl.status === 'expired'" class="text-sm mb-3">{{ t('recurring.reschedule_expired') }}</p>
        <label class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('recurring.reschedule_new_date') }}</span>
          <DateInput v-model="rescheduleDate" @update:model-value="rescheduleConfirmed = false; rescheduleError = ''" required
            :min="rescheduleMin" :max="tpl.end_date || undefined" :disabled="busy"
            class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>
        <p class="mt-2 text-xs text-neutral-600">{{ t('recurring.reschedule_date_hint') }}</p>
        <p v-if="rescheduleError" role="alert" class="mt-3 rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{{ rescheduleError }}</p>
        <label class="flex items-start gap-2 mt-4 text-sm">
          <input v-model="rescheduleConfirmed" type="checkbox" required :disabled="busy" class="mt-1" />
          <span>{{ t('recurring.reschedule_confirm') }}</span>
        </label>
        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button @click="rescheduleModal = false" type="button" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-2 h-9 px-3 text-sm rounded-md whitespace-nowrap" :class="OUTLINE.neutral">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-2 h-9 px-3 text-sm disabled:opacity-50 text-white font-medium rounded-md whitespace-nowrap" :class="FILLED.warning">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ busy ? t('common.saving') : t('recurring.reschedule_submit') }}
          </button>
        </div>
      </form>
    </div>

    <!-- Run Now modal — date picker s defaultem dnes; varování pokud uživatel zvolí
         budoucí datum (issue_date = budoucnost je daňově problematické). -->
    <div v-if="runNowModal && tpl" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-surface rounded-lg shadow-xl max-w-md w-full p-6">
        <h2 class="text-lg font-semibold mb-1">{{ runNowMode === 'draft' ? t('recurring.run_now_draft_title') : t('recurring.run_now_title') }}</h2>
        <p class="text-sm text-neutral-600 mb-4">{{ tpl.name }}</p>
        <p v-if="runNowMode === 'draft'" class="text-xs text-neutral-500 mb-3 -mt-2">{{ t('recurring.run_now_draft_hint') }}</p>

        <label v-if="!isPeriodDraft" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('recurring.run_now_issue_date_label') }}</span>
          <DateInput v-model="runNowDate"
            class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>

        <p v-if="isPeriodDraft" class="text-sm text-neutral-700">
          {{ t('recurring.run_now_draft_period_note', { date: formatDate(tpl.next_run_date) }) }}
        </p>

        <p v-else class="mt-2 text-xs text-neutral-500">
          {{ t('recurring.run_now_next_scheduled', { date: formatDate(tpl.next_run_date) }) }}
        </p>

        <label v-if="tpl.draft_open_mode !== 'period_start'" class="flex items-start gap-2 mt-3 text-sm">
          <input v-model="advanceSchedule" type="checkbox" class="mt-1" />
          <span>{{ t('recurring.run_now_advance') }}</span>
        </label>
        <p v-if="!isPeriodDraft" class="mt-2 text-xs text-neutral-600">
          {{ advanceSchedule ? t('recurring.run_now_advance_hint') : t('recurring.run_now_extra_hint') }}
        </p>
        <p class="mt-2 text-sm font-medium">{{ t('recurring.run_now_result_date', { date: formatDate(runNowNextDate) }) }}</p>
        <p v-if="runNowNextDate && tpl.end_date && runNowNextDate > tpl.end_date" class="mt-2 text-xs text-warning-700">
          {{ t('recurring.run_now_expires') }}
        </p>

        <!-- Varování o budoucím datu jen pro „Vygenerovat teď" (vystavení) — u konceptu
             je budoucí issue_date záměr (edituje se celý měsíc), takže se nezobrazuje. -->
        <div v-if="runNowDate > todayIso() && runNowMode !== 'draft'" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-700">
          {{ t('recurring.run_now_future_warning') }}
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button @click="closeRunNow" type="button"
            class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitRunNow" type="button" :disabled="!runNowDate || busy"
            class="cursor-pointer h-9 px-3 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ runNowMode === 'draft' ? t('recurring.run_now_draft_submit') : t('recurring.run_now_submit') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
