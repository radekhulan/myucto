<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemPlansApi, type OtherItemInstallment, type OtherItemInstallmentStatus, type OtherItemSchedule } from '@/api/otherItemPlans'
import type { OtherItem } from '@/api/otherItems'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'

const props = defineProps<{ item: OtherItem }>()
const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const busy = ref(false)
const loading = ref(false)
const schedule = ref<OtherItemSchedule | null>(null)
const installments = ref<OtherItemInstallment[]>([])
const frequency = ref<OtherItemSchedule['frequency']>('monthly')
const endsOn = ref('')
const through = ref(appIsoDate())
const autoPost = ref(false)
const canAutoPost = computed(() => ['posted', 'confirmed'].includes(props.item.status))
const canPost = computed(() => supplier.currentSupplier?.accounting_mode !== 'double_entry' || auth.canWrite('accounting.journal.post'))
const canWrite = computed(() => auth.canWrite('other_items'))
const sourceActive = computed(() => ['draft', 'posted', 'confirmed'].includes(props.item.status))
const generatedOccurrences = computed(() => schedule.value?.occurrences?.filter(row => row.occurrence_index > 0) || [])
// Kalendář zamyká každá úhrada, i ta, která pokryla jen úrok nebo poplatek (jistina zůstala celá).
const hasPayments = ref(false)
const canEditInstallments = computed(() => canWrite.value && ['draft', 'posted', 'confirmed'].includes(props.item.status)
  && Number(props.item.paid_amount) === 0 && !hasPayments.value)
/** Splátky rozdělené na jistinu, úrok a poplatek (úvěr, leasing). Částka dokladu je pak jistina. */
const components = ref(false)

async function load() {
  loading.value = true
  splitFirstDue.value = addMonths(props.item.issued_on || appIsoDate(), 1)
  loan.value.first_due_on = splitFirstDue.value
  try {
    const id = Number(props.item.id)
    const [all, rows] = await Promise.all([otherItemPlansApi.schedules(), otherItemPlansApi.installments(id)])
    const found = all.find(row => Number(row.source_item_id) === id)
    schedule.value = found ? await otherItemPlansApi.schedule(found.id) : null
    installments.value = rows
    hasPayments.value = rows.some(row => Number(row.paid_amount || 0) > 0)
    components.value = rows.some(row => row.principal_amount !== null && row.principal_amount !== undefined)
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function createSchedule() {
  if (busy.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.createSchedule(Number(props.item.id), {
      frequency: frequency.value, ends_on: endsOn.value || null,
      auto_post: autoPost.value && canAutoPost.value,
    })
    toast.success(t('other_items.plans.schedule_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function generate() {
  if (!schedule.value || busy.value || !sourceActive.value) return
  busy.value = true
  try {
    const result = await otherItemPlansApi.generate(schedule.value.id, through.value)
    schedule.value = result.schedule
    toast.success(t('other_items.plans.generated', { count: result.created_ids.length }))
    if (result.posted_ids.length) toast.success(t('other_items.plans.posted', { count: result.posted_ids.length }))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function toggleSchedule() {
  if (!schedule.value || busy.value || !sourceActive.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.setStatus(schedule.value.id,
      schedule.value.status === 'active' ? 'paused' : 'active')
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function toggleAutoPost() {
  if (!schedule.value || busy.value) return
  busy.value = true
  try {
    schedule.value = await otherItemPlansApi.setStatus(schedule.value.id, schedule.value.status, !schedule.value.auto_post)
    toast.success(t('other_items.plans.schedule_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function deleteSchedule() {
  if (!schedule.value || busy.value || !window.confirm(t('other_items.plans.confirm_delete_schedule'))) return
  busy.value = true
  try {
    await otherItemPlansApi.deleteSchedule(schedule.value.id)
    schedule.value = null
    toast.success(t('other_items.plans.schedule_deleted'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

function addMonths(iso: string, months: number): string {
  const [year, month, day] = iso.split('-').map(Number)
  const index = month - 1 + months
  const y = year + Math.floor(index / 12)
  const m = index % 12
  const last = new Date(Date.UTC(y, m + 1, 0)).getUTCDate()
  return `${y}-${String(m + 1).padStart(2, '0')}-${String(Math.min(day, last)).padStart(2, '0')}`
}

const splitCount = ref(12)
const splitStep = ref(1)
const splitFirstDue = ref(addMonths(props.item.issued_on || appIsoDate(), 1))
const canSplit = computed(() => Number.isInteger(splitCount.value) && splitCount.value >= 2 && splitCount.value <= 120
  && /^\d{4}-\d{2}-\d{2}$/.test(splitFirstDue.value))

function splitInstallments() {
  if (!canSplit.value) return
  const count = splitCount.value
  const cents = Math.round(Number(props.item.amount) * 100)
  const base = Math.floor(cents / count)
  installments.value = Array.from({ length: count }, (_, index) => {
    const amount = (index === count - 1 ? cents - base * (count - 1) : base) / 100
    return {
      due_on: addMonths(splitFirstDue.value, index * splitStep.value), amount,
      ...(components.value ? { principal_amount: amount, interest_amount: 0, fee_amount: 0 } : {}),
    }
  })
}

function addInstallment() {
  const last = installments.value[installments.value.length - 1]
  installments.value = [...installments.value, {
    due_on: last ? addMonths(last.due_on, 1) : props.item.due_on || appIsoDate(), amount: 0,
    ...(components.value ? { principal_amount: 0, interest_amount: 0, fee_amount: 0 } : {}),
  }]
}

const cents = (value: unknown) => Math.round(Number(value || 0) * 100)

/** Částka rozdělené splátky je součet jistiny, úroku a poplatku. */
function rowAmount(row: OtherItemInstallment): number {
  return components.value
    ? (cents(row.principal_amount) + cents(row.interest_amount) + cents(row.fee_amount)) / 100
    : Number(row.amount || 0)
}

function toggleComponents() {
  components.value = !components.value
  installments.value = installments.value.map(row => components.value
    ? { ...row, principal_amount: Number(row.amount || 0), interest_amount: 0, fee_amount: 0 }
    : { ...row, amount: Number(row.principal_amount ?? row.amount ?? 0), principal_amount: null, interest_amount: null, fee_amount: null })
}

const totalAmount = computed(() => installments.value.reduce((sum, row) => sum + cents(rowAmount(row)), 0) / 100)
const totalPrincipal = computed(() => installments.value.reduce((sum, row) => sum + cents(row.principal_amount), 0) / 100)
const principalMismatch = computed(() => components.value && installments.value.length > 0
  && cents(totalPrincipal.value) !== cents(props.item.amount))

const loan = ref({ annual_rate: 0, count: 12, frequency: 'monthly' as 'monthly' | 'quarterly', method: 'annuity' as 'annuity' | 'linear',
  first_due_on: splitFirstDue.value, fee: 0 })
const canPlanLoan = computed(() => Number.isInteger(loan.value.count) && loan.value.count >= 2 && loan.value.count <= 120
  && Number(loan.value.annual_rate) >= 0 && Number(loan.value.annual_rate) <= 100 && Number(loan.value.fee) >= 0
  && /^\d{4}-\d{2}-\d{2}$/.test(loan.value.first_due_on))

async function planLoan() {
  if (!canPlanLoan.value || busy.value) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.planLoan(Number(props.item.id), {
      annual_rate: Number(loan.value.annual_rate), count: loan.value.count, frequency: loan.value.frequency,
      method: loan.value.method, first_due_on: loan.value.first_due_on, fee: Number(loan.value.fee || 0),
    })
    components.value = true
    toast.success(t('other_items.plans.loan_planned'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function saveInstallments() {
  if (busy.value) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.setInstallments(Number(props.item.id),
      installments.value.map(row => components.value
        ? { due_on: row.due_on, amount: rowAmount(row), principal_amount: Number(row.principal_amount || 0),
            interest_amount: Number(row.interest_amount || 0), fee_amount: Number(row.fee_amount || 0) }
        : { due_on: row.due_on, amount: Number(row.amount) }))
    toast.success(t('other_items.plans.installments_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function clearInstallments() {
  if (busy.value || !window.confirm(t('other_items.plans.confirm_clear'))) return
  busy.value = true
  try {
    installments.value = await otherItemPlansApi.setInstallments(Number(props.item.id), [])
    toast.success(t('other_items.plans.installments_cleared'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

const INSTALLMENT_STATUS_CLASS: Record<OtherItemInstallmentStatus, string> = {
  paid: 'bg-success-50 text-success-700',
  partial: 'bg-warning-50 text-warning-700',
  overdue: 'bg-danger-50 text-danger-700',
  unpaid: 'bg-neutral-100 text-neutral-600',
}

// Přiřazená nebo odpojená úhrada mění stav splátek, proto se načítá i při změně uhrazené částky.
watch(() => [props.item.id, props.item.paid_amount], () => void load(), { immediate: true })
</script>

<template>
  <div id="other-item-plans" class="mt-4 grid scroll-mt-4 gap-4 lg:grid-cols-2">
    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="font-semibold">{{ t('other_items.plans.schedule_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('other_items.plans.schedule_hint') }}</p>
      <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <template v-else-if="schedule">
        <p class="mt-3 text-sm">{{ t(`other_items.plans.${schedule.frequency}`) }} · {{ t(`other_items.plans.${schedule.status}`) }}</p>
        <label v-if="canWrite && sourceActive" class="mt-3 flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="schedule.auto_post" :disabled="busy || ((!canAutoPost || !canPost) && !schedule.auto_post)" @change="toggleAutoPost" />
          {{ t('other_items.plans.auto_post') }}
        </label>
        <p v-else-if="schedule.auto_post" class="mt-3 text-sm font-medium text-success-700">{{ t('other_items.plans.auto_post') }}</p>
        <p class="mt-1 text-xs text-neutral-500">{{ t(canAutoPost ? 'other_items.plans.auto_post_hint' : 'other_items.plans.auto_post_source') }}</p>
        <p v-if="!sourceActive" class="mt-2 text-sm text-neutral-500">{{ t('other_items.plans.source_inactive') }}</p>
        <div v-if="canWrite" class="mt-3 flex flex-wrap items-end gap-2">
          <template v-if="sourceActive">
            <label class="text-sm font-medium">{{ t('other_items.plans.generate_through') }}
              <DateInput v-model="through" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
            </label>
            <button type="button" :disabled="busy || schedule.status !== 'active' || (schedule.auto_post && !canPost)" :class="[btnOutline('success'), 'whitespace-nowrap']" @click="generate">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('other_items.plans.generate') }}
            </button>
            <button type="button" :disabled="busy || (schedule.status !== 'active' && schedule.auto_post && !canPost)" :class="[btnOutline('warning'), 'whitespace-nowrap']" @click="toggleSchedule">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="schedule.status === 'active' ? ICONS.pause : ICONS.play" /></svg>
              {{ t(schedule.status === 'active' ? 'other_items.plans.pause' : 'other_items.plans.resume') }}
            </button>
          </template>
          <button type="button" :disabled="busy" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="deleteSchedule">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('other_items.plans.delete_schedule') }}
          </button>
        </div>
        <ul v-if="generatedOccurrences.length" class="mt-3 space-y-1 text-sm">
          <li v-for="occurrence in generatedOccurrences" :key="occurrence.occurrence_index">
            <RouterLink :to="`/other-items/${occurrence.item_id}`" class="text-primary-700 hover:underline">
              {{ t('other_items.plans.created_for', { date: formatDate(occurrence.issued_on) }) }} · {{ t(`other_items.status.${occurrence.status}`) }}
            </RouterLink>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm text-neutral-500">{{ t('other_items.plans.no_generated') }}</p>
      </template>
      <form v-else-if="canWrite && ['draft', 'posted', 'confirmed'].includes(item.status)" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="createSchedule">
        <label class="text-sm font-medium">{{ t('other_items.plans.frequency') }}
          <select v-model="frequency" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
            <option value="monthly">{{ t('other_items.plans.monthly') }}</option>
            <option value="quarterly">{{ t('other_items.plans.quarterly') }}</option>
            <option value="yearly">{{ t('other_items.plans.yearly') }}</option>
          </select>
        </label>
        <label class="text-sm font-medium">{{ t('other_items.plans.ends_on') }}
          <DateInput v-model="endsOn" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="autoPost" type="checkbox" :disabled="!canAutoPost || !canPost || busy" />
          {{ t('other_items.plans.auto_post') }}
        </label>
        <p class="w-full text-xs text-neutral-500">{{ t(canAutoPost ? 'other_items.plans.auto_post_hint' : 'other_items.plans.auto_post_source') }}</p>
        <button type="submit" :disabled="busy" :class="btnOutline('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
          {{ t('other_items.plans.create_schedule') }}
        </button>
      </form>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="font-semibold">{{ t('other_items.plans.installments_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('other_items.plans.installments_hint') }}</p>
      <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <template v-else>
        <div v-if="canEditInstallments" class="mt-3 rounded-md border border-neutral-200 p-3">
          <div class="flex flex-wrap items-end gap-2">
            <label class="text-sm font-medium">{{ t('other_items.plans.split_count') }}
              <input v-model.number="splitCount" type="number" min="2" max="120" step="1" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_every') }}
              <select v-model.number="splitStep" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option :value="1">{{ t('other_items.plans.split_month') }}</option>
                <option :value="3">{{ t('other_items.plans.split_quarter') }}</option>
                <option :value="12">{{ t('other_items.plans.split_year') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_first_due') }}
              <DateInput v-model="splitFirstDue" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
            </label>
            <button type="button" :disabled="!canSplit" :class="[btnOutline('primary'), 'whitespace-nowrap']" @click="splitInstallments">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
              {{ t('other_items.plans.split') }}
            </button>
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ t('other_items.plans.split_hint') }}</p>
          <label class="mt-3 flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="components" data-test="installment-components" @change="toggleComponents" />
            {{ t('other_items.plans.components') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.plans.components_hint') }}</p>
          <div v-if="components" class="mt-3 flex flex-wrap items-end gap-2 border-t border-neutral-200 pt-3" data-test="loan-plan">
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_rate') }}
              <input v-model.number="loan.annual_rate" type="number" min="0" max="100" step="0.0001" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_count') }}
              <input v-model.number="loan.count" type="number" min="2" max="120" step="1" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.frequency') }}
              <select v-model="loan.frequency" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option value="monthly">{{ t('other_items.plans.monthly') }}</option>
                <option value="quarterly">{{ t('other_items.plans.quarterly') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_method') }}
              <select v-model="loan.method" class="mt-1 block h-9 rounded-md border border-neutral-300 bg-surface px-2">
                <option value="annuity">{{ t('other_items.plans.loan_annuity') }}</option>
                <option value="linear">{{ t('other_items.plans.loan_linear') }}</option>
              </select>
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.split_first_due') }}
              <DateInput v-model="loan.first_due_on" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
            </label>
            <label class="text-sm font-medium">{{ t('other_items.plans.loan_fee') }}
              <input v-model.number="loan.fee" type="number" min="0" step="0.01" class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <button type="button" :disabled="!canPlanLoan || busy" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="loan-plan-run" @click="planLoan">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
              {{ t('other_items.plans.loan_plan') }}
            </button>
            <p class="w-full text-xs text-neutral-500">{{ t('other_items.plans.loan_hint') }}</p>
          </div>
        </div>
        <div v-for="(row, index) in installments" :key="row.id || index" class="mt-3 flex flex-wrap items-end gap-2" data-test="installment-row">
          <label class="text-sm font-medium">{{ t('other_items.plans.due_number', { number: index + 1 }) }}
            <DateInput v-model="row.due_on" :disabled="!canEditInstallments" class="mt-1 block h-9 rounded-md border border-neutral-300 px-2" />
          </label>
          <template v-if="components">
            <label v-for="part in (['principal_amount', 'interest_amount', 'fee_amount'] as const)" :key="part" class="text-sm font-medium">{{ t(`other_items.plans.${part}`) }}
              <input v-model.number="row[part]" type="number" min="0" step="0.01" :disabled="!canEditInstallments" :data-test="`installment-${part}`" class="mt-1 block h-9 w-28 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <p class="mb-2 text-sm tabular-nums" data-test="installment-amount">{{ t('other_items.amount') }}: <span class="font-medium">{{ formatMoney(rowAmount(row), item.currency) }}</span></p>
          </template>
          <label v-else class="text-sm font-medium">{{ t('other_items.amount') }}
            <input v-model.number="row.amount" type="number" min="0.01" step="0.01" :disabled="!canEditInstallments" class="mt-1 block h-9 w-32 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
          </label>
          <span v-if="row.status" :class="INSTALLMENT_STATUS_CLASS[row.status]" class="mb-2 whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium" data-test="installment-status">
            {{ t(`other_items.plans.installment_status.${row.status}`) }}<template v-if="row.status === 'partial' || (row.status === 'overdue' && Number(row.paid_amount || 0) > 0)"> · {{ t('other_items.plans.installment_remaining', { amount: formatMoney(row.remaining_amount ?? 0, item.currency) }) }}</template>
          </span>
          <button v-if="canEditInstallments" type="button" :class="btnOutline('danger')" @click="installments.splice(index, 1)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
        <p v-if="installments.length" class="mt-3 text-sm font-medium">{{ t('other_items.plans.total') }}: {{ formatMoney(totalAmount, item.currency) }}</p>
        <p v-if="installments.length && components" class="mt-1 text-sm" :class="principalMismatch ? 'text-danger-700' : 'text-neutral-600'" data-test="installment-principal-total">
          {{ t('other_items.plans.principal_total', { amount: formatMoney(totalPrincipal, item.currency), item: formatMoney(Number(item.amount), item.currency) }) }}
        </p>
        <div v-if="canEditInstallments" class="mt-3 flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="addInstallment">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('other_items.plans.add_installment') }}
          </button>
          <button v-if="installments.length >= 2" type="button" :disabled="busy" :class="btnOutline('success')" @click="saveInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
          <button v-if="installments.some(row => row.id)" type="button" :disabled="busy" :class="btnOutline('danger')" @click="clearInstallments">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('other_items.plans.clear_installments') }}
          </button>
        </div>
        <p v-else-if="installments.length" class="mt-3 text-sm text-neutral-500">{{ t('other_items.plans.paid_locked') }}</p>
      </template>
    </section>
  </div>
</template>
