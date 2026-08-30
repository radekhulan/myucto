<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import {
  deductionAgreementKinds,
  deductionPriorityCeiling,
  deductionPriorityFloor,
  payrollDeductionsApi,
  type DeductionAgreementCommand,
  type DeductionAgreementDetail,
  type DeductionAgreementKind,
  type DeductionAgreementStatus,
  type DeductionAgreementSummary,
} from '@/api/payrollDeductions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { appIsoDate } from '@/utils/date'

const { t, locale } = useI18n()
const auth = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const agreements = ref<DeductionAgreementSummary[]>([])
const detail = ref<DeductionAgreementDetail | null>(null)
const expandedId = ref<number | null>(null)
const creating = ref(false)
const formError = ref('')
const listError = ref('')
/**
 * Předvýběr z odkazu na kartě zaměstnance (`/payroll/deduction-agreements?person=7`).
 *
 * Sedí do stávajícího filtru osob, takže se zúžení hned vidí a jde ho zrušit
 * tam, kde ho uživatel čeká — v tom samém selectu. Zúžení zároveň předplní
 * formulář nové dohody (`emptyForm()` čte tenhle ref).
 */
const employeeFilter = ref<number | null>(payrollQueryId(useRoute().query, 'person'))
const statusFilter = ref<DeductionAgreementStatus | ''>('')
const total = ref(0)
const pageSize = 20
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const statuses: DeductionAgreementStatus[] = ['draft', 'active', 'paused', 'ended', 'cancelled']

const COLUMNS: ColumnDef[] = [
  { key: 'employee', labelKey: 'payroll.deductions.employee', required: true },
  { key: 'title', labelKey: 'payroll.deductions.agreement_title' },
  { key: 'status', labelKey: 'payroll.deductions.status_label' },
  { key: 'priority', labelKey: 'payroll.deductions.priority', defaultHidden: true },
  { key: 'requested', labelKey: 'payroll.deductions.requested' },
  { key: 'withheld', labelKey: 'payroll.deductions.withheld' },
  { key: 'validity', labelKey: 'payroll.deductions.validity' },
  { key: 'delivered_on', labelKey: 'payroll.deductions.delivered_on', defaultHidden: true },
  { key: 'actions', labelKey: 'common.detail', required: true },
]
const tbl = useTablePrefs('payroll-deduction-agreements', COLUMNS)

const today = appIsoDate()

interface AgreementForm {
  employee_id: number | null
  title: string
  deduction_kind: DeductionAgreementKind
  priority_no: number
  amount_mode: 'amount' | 'percentage'
  amount_czk: string
  percent: string
  base_czk: string
  limit_czk: string
  valid_from: string
  valid_to: string
  delivered_on: string
  recipient_reference: string
  note: string
  effective_from: string
  activate_now: boolean
}

function emptyForm(): AgreementForm {
  return {
    employee_id: employeeFilter.value,
    title: '',
    deduction_kind: 'meal',
    priority_no: 100,
    amount_mode: 'amount',
    amount_czk: '',
    percent: '',
    base_czk: '',
    limit_czk: '',
    valid_from: today,
    valid_to: '',
    delivered_on: '',
    recipient_reference: '',
    note: '',
    effective_from: today,
    activate_now: true,
  }
}
const form = ref<AgreementForm>(emptyForm())

function percent(basisPoints: number | null): string {
  if (basisPoints === null) return '—'
  return new Intl.NumberFormat(locale.value, {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(basisPoints / 10_000)
}

function toMinor(value: string, required: boolean): number | null {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  if (!normalized) {
    if (required) throw new Error(t('payroll.deductions.validation.amount'))
    return null
  }
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
    throw new Error(t('payroll.deductions.validation.amount'))
  }
  return Math.round(Number(normalized) * 100)
}

function toBasisPoints(value: string): number {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
    throw new Error(t('payroll.deductions.validation.percent'))
  }
  const points = Math.round(Number(normalized) * 100)
  if (points < 0 || points > 10_000) {
    throw new Error(t('payroll.deductions.validation.percent'))
  }
  return points
}

function fromMinor(minorUnits: number | null): string {
  return minorUnits === null ? '' : String(minorUnits / 100)
}

function statusClass(status: DeductionAgreementStatus): string {
  if (status === 'active') return 'bg-success-50 text-success-600'
  if (status === 'paused') return 'bg-warning-50 text-warning-700'
  if (status === 'ended' || status === 'cancelled') return 'bg-neutral-100 text-neutral-600'
  return 'bg-payroll-50 text-payroll-600'
}

function commandsFor(status: DeductionAgreementStatus): DeductionAgreementCommand[] {
  if (status === 'draft') return ['activate', 'end', 'cancel']
  if (status === 'active') return ['pause', 'end']
  if (status === 'paused') return ['resume', 'end']
  return []
}

function commandVariant(command: DeductionAgreementCommand) {
  if (command === 'cancel') return 'danger' as const
  if (command === 'end') return 'warning' as const
  if (command === 'pause') return 'warning' as const
  return 'success' as const
}

function commandIcon(command: DeductionAgreementCommand): keyof typeof ICONS {
  if (command === 'cancel') return 'x'
  if (command === 'end') return 'archive'
  if (command === 'pause') return 'pause'
  return 'check'
}

const detailActions = computed<ActionItem[]>(() => {
  const current = detail.value
  if (!current || !canWrite.value) return []
  return commandsFor(current.status).map((command, index) => ({
    key: command,
    label: t(`payroll.deductions.commands.${command}`),
    icon: commandIcon(command),
    tier: index === 0 ? 'primary' : command === 'cancel' ? 'overflow' : 'secondary',
    variant: commandVariant(command),
    disabled: saving.value,
    run: () => void transition(command),
  }))
})

function apiMessage(error: any, fallback: string): string {
  return error?.response?.data?.error?.message || error?.message || t(fallback)
}

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const page = await payrollDeductionsApi.agreementsPage({
      ...(employeeFilter.value ? { employee_id: employeeFilter.value } : {}),
      ...(statusFilter.value ? { status: statusFilter.value } : {}),
      limit: pageSize,
      offset: offset.value,
    })
    agreements.value = page.agreements
    total.value = page.total
  } catch (error: any) {
    listError.value = apiMessage(error, 'payroll.deductions.load_failed')
  } finally {
    loading.value = false
  }
}

function fillForm(item: DeductionAgreementDetail) {
  form.value = {
    employee_id: item.employee_id,
    title: item.title,
    deduction_kind: item.deduction_kind,
    priority_no: item.priority_no,
    amount_mode: item.basis_points === null ? 'amount' : 'percentage',
    amount_czk: fromMinor(item.requested_minor),
    percent: item.basis_points === null ? '' : String(item.basis_points / 100),
    base_czk: fromMinor(item.basis_amount_minor),
    limit_czk: fromMinor(item.total_limit_minor),
    valid_from: item.valid_from,
    valid_to: item.valid_to ?? '',
    delivered_on: item.delivered_on ?? '',
    recipient_reference: item.recipient_reference ?? '',
    note: item.note ?? '',
    effective_from: today,
    activate_now: item.status === 'active',
  }
}

async function openDetail(item: DeductionAgreementSummary) {
  formError.value = ''
  creating.value = false
  if (expandedId.value === item.id) {
    expandedId.value = null
    detail.value = null
    return
  }
  expandedId.value = item.id
  detail.value = null
  try {
    const loaded = await payrollDeductionsApi.agreement(item.id)
    if (expandedId.value !== item.id) return
    detail.value = loaded
    fillForm(loaded)
  } catch (error: any) {
    expandedId.value = null
    listError.value = apiMessage(error, 'payroll.deductions.detail_failed')
  }
}

function startCreate() {
  expandedId.value = null
  detail.value = null
  formError.value = ''
  form.value = emptyForm()
  creating.value = true
}

function payloadFromForm() {
  const base = {
    title: form.value.title.trim(),
    deduction_kind: form.value.deduction_kind,
    priority_no: Number(form.value.priority_no),
    total_limit_minor: toMinor(form.value.limit_czk, false),
    valid_from: form.value.valid_from,
    valid_to: form.value.valid_to || null,
    delivered_on: form.value.delivered_on || null,
    recipient_reference: form.value.recipient_reference.trim() || null,
    note: form.value.note.trim() || null,
  }
  if (form.value.amount_mode === 'percentage') {
    return {
      ...base,
      basis_points: toBasisPoints(form.value.percent),
      basis_amount_minor: toMinor(form.value.base_czk, true) ?? 0,
    }
  }
  return { ...base, requested_minor: toMinor(form.value.amount_czk, true) ?? 0 }
}

async function save() {
  formError.value = ''
  if (!canWrite.value) return
  saving.value = true
  try {
    const payload = payloadFromForm()
    if (creating.value) {
      if (!form.value.employee_id) {
        throw new Error(t('payroll.deductions.validation.employee'))
      }
      const created = await payrollDeductionsApi.create({
        ...payload,
        employee_id: form.value.employee_id,
        status: form.value.activate_now ? 'active' : 'draft',
      })
      creating.value = false
      await load()
      expandedId.value = created.id
      detail.value = created
      fillForm(created)
    } else if (detail.value) {
      const current = detail.value
      const updated = await payrollDeductionsApi.update(current.id, {
        ...payload,
        agreement_reference: current.agreement_reference,
        row_version: current.row_version,
        effective_from: form.value.effective_from || null,
      })
      detail.value = updated
      fillForm(updated)
      await load()
    }
  } catch (error: any) {
    formError.value = apiMessage(error, 'payroll.deductions.save_failed')
    const conflicting = detail.value
    if (error?.response?.data?.error?.code === 'row_version_conflict' && conflicting) {
      const refreshed = await payrollDeductionsApi.agreement(conflicting.id)
      detail.value = refreshed
      fillForm(refreshed)
      formError.value = t('payroll.deductions.conflict')
    }
  } finally {
    saving.value = false
  }
}

async function transition(command: DeductionAgreementCommand) {
  const current = detail.value
  if (!current) return
  formError.value = ''
  saving.value = true
  try {
    const updated = await payrollDeductionsApi.transition(current.id, command, {
      row_version: current.row_version,
      effective_on: command === 'end' ? (form.value.effective_from || today) : null,
    })
    detail.value = updated
    fillForm(updated)
    await load()
  } catch (error: any) {
    formError.value = apiMessage(error, 'payroll.deductions.save_failed')
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      const refreshed = await payrollDeductionsApi.agreement(current.id)
      detail.value = refreshed
      fillForm(refreshed)
      formError.value = t('payroll.deductions.conflict')
    }
  } finally {
    saving.value = false
  }
}

/**
 * Rozbalený detail patří ke konkrétnímu řádku seznamu. Po přestránkování ani po
 * přefiltrování ten řádek na obrazovce být nemusí a otevřený panel by pak
 * ukazoval dohodu, kterou v seznamu nikdo nevidí.
 */
function collapseDetail() {
  if (expandedId.value === null) return
  expandedId.value = null
  detail.value = null
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  collapseDetail()
  void load()
}

watch([employeeFilter, statusFilter], () => {
  // Zúžený výběr má míň stránek; třetí stránka by po přefiltrování ukázala prázdno.
  offset.value = 0
  collapseDetail()
  void load()
})
onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.deductions.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.deductions.subtitle') }}</p>
      </div>
      <button v-if="canWrite" :class="btnFilled('primary')" @click="startCreate">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('payroll.deductions.add') }}
      </button>
    </header>

    <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.deductions.priority_hint', { floor: deductionPriorityFloor, ceiling: deductionPriorityCeiling }) }}
    </section>

    <div class="flex flex-wrap items-end gap-3">
      <label class="text-xs font-medium text-neutral-600">
        {{ t('payroll.deductions.employee') }}
        <PayrollPersonSearchSelect
          v-model="employeeFilter"
          data-test="deduction-employee-filter"
          class="mt-1 min-w-64"
          :label="t('payroll.deductions.employee')"
          :placeholder="t('payroll.deductions.all_employees')"
        />
      </label>
      <label class="text-xs font-medium text-neutral-600">
        {{ t('payroll.deductions.status_label') }}
        <select v-model="statusFilter" data-test="deduction-status-filter" class="mt-1 block w-full min-w-40 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <option value="">{{ t('payroll.deductions.all_statuses') }}</option>
          <option v-for="status in statuses" :key="status" :value="status">{{ t(`payroll.deductions.status.${status}`) }}</option>
        </select>
      </label>
    </div>

    <p v-if="listError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
      {{ listError }}
    </p>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 4" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
      </div>
      <div v-else-if="agreements.length === 0" class="p-8 text-center">
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.deductions.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.deductions.empty_description') }}</p>
      </div>
      <template v-else>
        <div class="hidden md:block">
          <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
            <ColumnPicker class="hidden md:block" :ctrl="tbl" />
            <DensityToggle class="hidden md:block" :ctrl="tbl" />
          </div>
          <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('employee')" class="px-4 py-3">{{ t('payroll.deductions.employee') }}</th>
                <th v-if="tbl.isVisible('title')" class="px-4 py-3">{{ t('payroll.deductions.agreement_title') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.deductions.status_label') }}</th>
                <th v-if="tbl.isVisible('priority')" class="px-4 py-3 text-right">{{ t('payroll.deductions.priority') }}</th>
                <th v-if="tbl.isVisible('requested')" class="px-4 py-3 text-right">{{ t('payroll.deductions.requested') }}</th>
                <th v-if="tbl.isVisible('withheld')" class="px-4 py-3 text-right">{{ t('payroll.deductions.withheld') }}</th>
                <th v-if="tbl.isVisible('validity')" class="px-4 py-3">{{ t('payroll.deductions.validity') }}</th>
                <th v-if="tbl.isVisible('delivered_on')" class="px-4 py-3">{{ t('payroll.deductions.delivered_on') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-4 py-3"><span class="sr-only">{{ t('common.detail') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in agreements" :key="item.id" :class="expandedId === item.id ? 'bg-payroll-50/50' : ''">
                <td v-if="tbl.isVisible('employee')" class="px-4 py-3 font-medium text-neutral-900">{{ item.full_name }}</td>
                <td v-if="tbl.isVisible('title')" class="px-4 py-3">
                  {{ item.title }}
                  <span class="ml-1 text-xs text-neutral-500">{{ t(`payroll.deductions.kinds.${item.deduction_kind}`) }}</span>
                </td>
                <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                  <span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(item.status)">
                    {{ t(`payroll.deductions.status.${item.status}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('priority')" class="px-4 py-3 text-right">{{ item.priority_no }}</td>
                <td v-if="tbl.isVisible('requested')" class="px-4 py-3 text-right font-medium">
                  {{ money(item.requested_minor) }}
                  <span v-if="item.basis_points !== null" class="block text-xs text-neutral-500">{{ percent(item.basis_points) }}</span>
                </td>
                <td v-if="tbl.isVisible('withheld')" class="px-4 py-3 text-right">{{ money(item.withheld_total_minor) }}</td>
                <td v-if="tbl.isVisible('validity')" class="px-4 py-3 text-neutral-600">{{ item.valid_from }} – {{ item.valid_to || '∞' }}</td>
                <td v-if="tbl.isVisible('delivered_on')" class="px-4 py-3 text-neutral-600">{{ item.delivered_on || t('payroll.deductions.delivered_on_missing') }}</td>
                <td v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">
                  <button :class="btnOutlineSm('neutral')" :data-test="`deduction-detail-${item.id}`" @click="openDetail(item)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>
                    {{ t(expandedId === item.id ? 'common.close' : 'common.detail') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>
        <div class="space-y-3 p-4 md:hidden">
          <article v-for="item in agreements" :key="item.id" class="rounded-lg border border-neutral-200 p-4" :class="expandedId === item.id ? 'bg-payroll-50/50' : ''">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <h2 class="min-w-0 break-words font-semibold text-neutral-900">{{ item.title }}</h2>
              <span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(item.status)">
                {{ t(`payroll.deductions.status.${item.status}`) }}
              </span>
            </div>
            <p class="mt-1 break-words text-sm text-neutral-500">{{ item.full_name }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.deductions.requested') }}</dt>
                <dd class="font-medium">{{ money(item.requested_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.deductions.withheld') }}</dt>
                <dd>{{ money(item.withheld_total_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.deductions.priority') }}</dt>
                <dd>{{ item.priority_no }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.deductions.validity') }}</dt>
                <dd class="break-words">{{ item.valid_from }} – {{ item.valid_to || '∞' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.deductions.delivered_on') }}</dt>
                <dd>{{ item.delivered_on || t('payroll.deductions.delivered_on_missing') }}</dd>
              </div>
            </dl>
            <button :class="[btnOutline('neutral'), 'mt-4']" @click="openDetail(item)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>
              {{ t(expandedId === item.id ? 'common.close' : 'common.detail') }}
            </button>
          </article>
        </div>
      </template>
      <PaginationBar
        v-if="!loading"
        data-test="deduction-pagination"
        embedded
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <section v-if="creating || detail" data-test="deduction-detail-panel" class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ creating ? t('payroll.deductions.add') : detail?.title }}
          </h2>
          <p v-if="detail" class="mt-1 break-words text-xs text-neutral-500">
            {{ detail.full_name }} · {{ t('payroll.deductions.version', { version: detail.version_no }) }}
          </p>
        </div>
        <ActionBar v-if="detail && detailActions.length" :actions="detailActions" />
      </div>

      <form class="mt-4 space-y-6" @submit.prevent="save">
        <fieldset class="rounded-lg border border-neutral-200 bg-surface p-4">
          <legend class="px-1 text-sm font-medium text-neutral-800">{{ t('payroll.deductions.section_terms') }}</legend>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label v-if="creating" class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.employee') }}
              <PayrollPersonSearchSelect
                v-model="form.employee_id"
                class="mt-1"
                :label="t('payroll.deductions.employee')"
                :placeholder="t('payroll.deductions.select_employee')"
                :clearable="false"
                required
              />
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.agreement_title') }}
              <input v-model="form.title" required maxlength="190" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.kind') }}
              <select v-model="form.deduction_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option v-for="kind in deductionAgreementKinds" :key="kind" :value="kind">{{ t(`payroll.deductions.kinds.${kind}`) }}</option>
              </select>
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.priority') }}
              <input v-model.number="form.priority_no" type="number" :min="deductionPriorityFloor" :max="deductionPriorityCeiling" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.amount_mode') }}
              <select v-model="form.amount_mode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option value="amount">{{ t('payroll.deductions.amount_mode_fixed') }}</option>
                <option value="percentage">{{ t('payroll.deductions.amount_mode_percentage') }}</option>
              </select>
            </label>
            <label v-if="form.amount_mode === 'amount'" class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.requested_czk') }}
              <input v-model="form.amount_czk" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            </label>
            <template v-else>
              <label class="text-xs font-medium text-neutral-600">
                {{ t('payroll.deductions.percent') }}
                <input v-model="form.percent" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
              </label>
              <label class="text-xs font-medium text-neutral-600">
                {{ t('payroll.deductions.base_czk') }}
                <input v-model="form.base_czk" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('payroll.deductions.base_hint') }}</span>
              </label>
            </template>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.limit_czk') }}
              <input v-model="form.limit_czk" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
              <span v-if="detail" class="mt-1 block text-xs font-normal text-neutral-500">
                {{ t('payroll.deductions.remaining_limit', { value: detail.remaining_limit_minor === null ? '∞' : money(detail.remaining_limit_minor) }) }}
              </span>
            </label>
          </div>
        </fieldset>

        <fieldset class="rounded-lg border border-neutral-200 bg-surface p-4">
          <legend class="px-1 text-sm font-medium text-neutral-800">{{ t('payroll.deductions.section_validity') }}</legend>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.valid_from') }}
              <input v-model="form.valid_from" type="date" required :disabled="!!detail && detail.withheld_total_minor > 0" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100">
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.valid_to') }}
              <input v-model="form.valid_to" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.delivered_on') }}
              <input v-model="form.delivered_on" type="date" :disabled="!!detail && detail.withheld_total_minor > 0" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100">
              <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('payroll.deductions.delivered_on_hint') }}</span>
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.effective_from') }}
              <input v-model="form.effective_from" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
              <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('payroll.deductions.effective_hint') }}</span>
            </label>
            <label v-if="creating" class="flex items-center gap-2 pt-5 text-sm text-neutral-700">
              <input v-model="form.activate_now" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
              {{ t('payroll.deductions.activate_now') }}
            </label>
          </div>
        </fieldset>

        <fieldset class="rounded-lg border border-neutral-200 bg-surface p-4">
          <legend class="px-1 text-sm font-medium text-neutral-800">{{ t('payroll.deductions.section_recipient') }}</legend>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.recipient') }}
              <input v-model="form.recipient_reference" maxlength="190" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-medium text-neutral-600">
              {{ t('payroll.deductions.note') }}
              <textarea v-model="form.note" rows="2" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
          </div>
        </fieldset>

        <p v-if="formError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="deduction-agreement-error">
          {{ formError }}
        </p>

        <div class="sticky bottom-0 -mx-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-neutral-50/95 px-4 py-3 sm:-mx-6 sm:px-6">
          <button type="button" :class="btnOutline('neutral')" @click="creating = false; expandedId = null; detail = null; formError = ''">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" :class="btnFilled('primary')" :disabled="saving || !canWrite">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </form>

      <div v-if="detail" class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <h3 class="font-medium text-neutral-900">{{ t('payroll.deductions.history') }}</h3>
          <ol class="mt-3 space-y-2 text-sm">
            <li v-for="version in detail.versions" :key="version.id" class="min-w-0 border-l-2 border-payroll-500/30 pl-3">
              <p class="font-medium text-neutral-800">
                {{ t(`payroll.deductions.change_kind.${version.change_kind}`) }} · {{ t('payroll.deductions.version', { version: version.version_no }) }}
              </p>
              <p class="break-words text-xs text-neutral-500">
                {{ version.effective_from }} · {{ version.title }} · {{ money(version.requested_minor) }}
              </p>
              <p v-if="version.reason" class="mt-1 break-words text-neutral-600">{{ version.reason }}</p>
            </li>
          </ol>
        </section>
        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <h3 class="font-medium text-neutral-900">{{ t('payroll.deductions.ledger') }}</h3>
          <dl v-if="detail.ledger.length" class="mt-3 space-y-2 text-sm">
            <div v-for="entry in detail.ledger" :key="entry.id" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
              <dt class="text-neutral-600">{{ t(`payroll.deductions.ledger_kind.${entry.event_kind}`) }}</dt>
              <dd class="font-medium text-neutral-900">{{ money(entry.amount_minor) }}</dd>
            </div>
          </dl>
          <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.deductions.no_ledger') }}</p>
        </section>
      </div>
    </section>
  </div>
</template>
