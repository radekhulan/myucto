<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollBalanceRoundingMode,
  type PayrollBusinessDayRule,
  type PayrollDeliveryChannel,
  type PayrollEmployerPolicy,
  type PayrollEmployerPolicyPayload,
  type PayrollOptionalPolicyState,
  type PayrollSetupCheck,
  type PayrollSetupCheckItem,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const props = defineProps<{
  canWrite: boolean
}>()

const { t, te } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const editorOpen = ref(false)
const editingId = ref<number | null>(null)
const policies = ref<PayrollEmployerPolicy[]>([])
const COLUMNS: ColumnDef[] = [
  { key: 'validity', labelKey: 'payroll.employer.policies.validity', required: true },
  { key: 'payday', labelKey: 'payroll.employer.policies.payday' },
  { key: 'automation', labelKey: 'payroll.employer.policies.automation' },
  { key: 'state', labelKey: 'payroll.employer.policies.state' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-employer-policies', COLUMNS)
const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const setup = ref<PayrollSetupCheck | null>(null)
const loadError = ref('')
const setupError = ref('')
const saveError = ref('')
const conflict = ref(false)
const showValidation = ref(false)
const effectiveOn = ref(localDate())
const form = ref<PayrollEmployerPolicyPayload>(newPolicy())

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function newPolicy(): PayrollEmployerPolicyPayload {
  return {
    valid_from: localDate(),
    valid_to: null,
    payday_day: 10,
    payday_month_offset: 1,
    payday_business_day_rule: 'previous_business_day',
    balance_rounding_mode: 'exact_minor_units',
    home_office_policy: 'not_used',
    travel_expense_policy: 'not_used',
    leave_entitlement_weeks: 5,
    four_eyes_required: false,
    automatic_calculation_enabled: false,
    automatic_posting_enabled: false,
    automatic_payments_enabled: false,
    delivery_channel: 'disabled',
    delivery_verified_on: null,
    source_kind: 'manual',
    source_reference: null,
    row_version: 0,
  }
}

const businessDayOptions = computed(() => options<PayrollBusinessDayRule>(
  'business_day_rule',
  ['none', 'previous_business_day', 'next_business_day'],
))
const roundingOptions = computed(() => options<PayrollBalanceRoundingMode>(
  'rounding',
  ['exact_minor_units', 'nearest_crown', 'up_to_crown'],
))
const optionalPolicyOptions = computed(() => options<PayrollOptionalPolicyState>(
  'optional_policy',
  ['not_used', 'manual_review', 'configured'],
))
const deliveryOptions = computed(() => options<PayrollDeliveryChannel>(
  'delivery',
  ['disabled', 'employee_portal', 'smime_email', 'manual_handover'],
))
const valid = computed(() => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.value.valid_from)) return false
  const validTo = nullable(form.value.valid_to)
  if (validTo !== null
    && (!/^\d{4}-\d{2}-\d{2}$/.test(validTo)
      || validTo < form.value.valid_from)) {
    return false
  }
  if (!Number.isInteger(form.value.payday_day)
    || form.value.payday_day < 1
    || form.value.payday_day > 31) {
    return false
  }
  if (!Number.isInteger(form.value.leave_entitlement_weeks)
    || form.value.leave_entitlement_weeks < 4
    || form.value.leave_entitlement_weeks > 12) return false
  if ((form.value.source_reference?.length ?? 0) > 255) return false
  if (form.value.delivery_channel === 'disabled') {
    return form.value.delivery_verified_on === null
  }
  return form.value.delivery_verified_on !== null
    && /^\d{4}-\d{2}-\d{2}$/.test(form.value.delivery_verified_on)
})

function options<T extends string>(group: string, values: T[]) {
  return values.map(value => ({
    value,
    label: t(`payroll.employer.policies.options.${group}.${value}`),
  }))
}

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function normalizeDelivery() {
  if (form.value.delivery_channel === 'disabled') {
    form.value.delivery_verified_on = null
  }
}

/**
 * `pending` má vlastní, nenápadný tón: kontrola sice nevyšla, ale nastavení
 * neblokuje. Varovná barva by z nepovinné připravenosti dělala překážku.
 */
function statusClass(status: PayrollSetupCheckItem['status']): string {
  if (status === 'ok') return 'bg-success-50 text-success-700'

  return status === 'pending'
    ? 'bg-neutral-100 text-neutral-600'
    : 'bg-warning-50 text-warning-700'
}

const knownCheckCodes = new Set([
  'employer_settings',
  'effective_policy',
  'home_office_policy',
  'travel_expense_policy',
  'automatic_calculation',
  'automatic_posting',
  'automatic_payments',
  'secure_delivery',
  'jmhz_registry',
  'jmhz_certificate',
  'jmhz_feature_source',
])

/**
 * Text kontroly skládáme z kódu a stavu, takže ho `check:i18n` staticky
 * nevidí — chybějící kombinace se pozná až na stránce, kde vyskočí syrový klíč
 * (přesně to potkalo `jmhz_certificate.pending`). Server přitom posílá vlastní
 * českou hlášku, tak ji použijeme, kdykoliv překlad chybí: horší než přeložený
 * text, pořád ale text, ne klíč.
 */
function checkMessage(check: PayrollSetupCheckItem): string {
  const key = `payroll.employer.policies.checks.${check.code}.${check.status}`

  return knownCheckCodes.has(check.code) && te(key) ? t(key) : check.message
}

function policyIsEffective(policy: PayrollEmployerPolicy): boolean {
  return policy.valid_from <= effectiveOn.value
    && (policy.valid_to === null || policy.valid_to >= effectiveOn.value)
}

function openNew() {
  editingId.value = null
  form.value = {
    ...newPolicy(),
    valid_from: effectiveOn.value,
  }
  saveError.value = ''
  conflict.value = false
  showValidation.value = false
  editorOpen.value = true
}

function edit(policy: PayrollEmployerPolicy) {
  editingId.value = policy.id
  form.value = {
    valid_from: policy.valid_from,
    valid_to: policy.valid_to,
    payday_day: policy.payday_day,
    payday_month_offset: policy.payday_month_offset,
    payday_business_day_rule: policy.payday_business_day_rule,
    balance_rounding_mode: policy.balance_rounding_mode,
    home_office_policy: policy.home_office_policy,
    travel_expense_policy: policy.travel_expense_policy,
    leave_entitlement_weeks: policy.leave_entitlement_weeks,
    four_eyes_required: false,
    automatic_calculation_enabled: policy.automatic_calculation_enabled,
    automatic_posting_enabled: policy.automatic_posting_enabled,
    automatic_payments_enabled: policy.automatic_payments_enabled,
    delivery_channel: policy.delivery_channel,
    delivery_verified_on: policy.delivery_verified_on,
    source_kind: policy.source_kind,
    source_reference: policy.source_reference,
    row_version: policy.row_version,
  }
  saveError.value = ''
  conflict.value = false
  showValidation.value = false
  editorOpen.value = true
}

async function load(): Promise<boolean> {
  loading.value = true
  loadError.value = ''
  setupError.value = ''
  let policiesLoaded = false
  const [policyResult, setupResult] = await Promise.allSettled([
    payrollApi.employerPolicies(undefined, { limit: pageSize, offset: offset.value }),
    payrollApi.payrollSetupCheck(effectiveOn.value),
  ])
  if (policyResult.status === 'fulfilled') {
    policies.value = policyResult.value.items
    total.value = policyResult.value.total
    policiesLoaded = true
    if (policyResult.value.total === 0 && props.canWrite && !editorOpen.value) {
      openNew()
    }
  } else {
    loadError.value = apiMessage(policyResult.reason)
      || t('payroll.employer.policies.load_failed')
  }
  if (setupResult.status === 'fulfilled') {
    setup.value = setupResult.value
  } else {
    setupError.value = apiMessage(setupResult.reason)
      || t('payroll.employer.policies.setup_failed')
  }
  loading.value = false
  return policiesLoaded
}

/**
 * Načtení JEN stránky historie, bez kontroly připravenosti.
 *
 * Po uložení a při listování se checklist netahá znovu — je to samostatný
 * dotaz a jeho opakování by přebilo hlášku, kterou uložení právě vypsalo.
 */
async function loadPolicies(): Promise<boolean> {
  loading.value = true
  loadError.value = ''
  try {
    const page = await payrollApi.employerPolicies(undefined, {
      limit: pageSize,
      offset: offset.value,
    })
    policies.value = page.items
    total.value = page.total
    if (page.total === 0 && props.canWrite && !editorOpen.value) openNew()
    return true
  } catch (error: unknown) {
    loadError.value = apiMessage(error) || t('payroll.employer.policies.load_failed')
    return false
  } finally {
    loading.value = false
  }
}

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void loadPolicies()
}

async function reloadCurrent() {
  const policyId = editingId.value
  if (policyId === null) return

  const loaded = await load()
  if (!loaded) return

  const current = policies.value.find(policy => policy.id === policyId)
  if (current) {
    edit(current)
    return
  }

  editorOpen.value = false
  editingId.value = null
}

async function reloadSetup() {
  loading.value = true
  setupError.value = ''
  try {
    setup.value = await payrollApi.payrollSetupCheck(effectiveOn.value)
  } catch (error: unknown) {
    setupError.value = apiMessage(error) || t('payroll.employer.policies.setup_failed')
  } finally {
    loading.value = false
  }
}

async function save() {
  showValidation.value = true
  saveError.value = ''
  conflict.value = false
  if (!props.canWrite || !valid.value) return

  saving.value = true
  try {
    const payload: PayrollEmployerPolicyPayload = {
      ...form.value,
      valid_to: nullable(form.value.valid_to),
      delivery_verified_on: nullable(form.value.delivery_verified_on),
      source_reference: nullable(form.value.source_reference),
    }
    if (editingId.value === null) {
      await payrollApi.createEmployerPolicy(payload)
      // Nová revize sedí podle `valid_from` kdekoli v historii, ne nutně na
      // začátku načtené stránky — dopsat ji lokálně by rozešlo pořadí
      // i celkový počet, takže se historie načte znovu od první stránky.
      offset.value = 0
    } else {
      await payrollApi.updateEmployerPolicy(editingId.value, payload)
    }
    await loadPolicies()
    editorOpen.value = false
    editingId.value = null
    toast.success(t('payroll.employer.policies.saved'))
    setupError.value = ''
    try {
      setup.value = await payrollApi.payrollSetupCheck(effectiveOn.value)
    } catch (error: unknown) {
      setupError.value = apiMessage(error) || t('payroll.employer.policies.setup_failed')
    }
  } catch (error: unknown) {
    saveError.value = apiMessage(error) || t('payroll.employer.policies.save_failed')
    conflict.value = apiCode(error) === 'row_version_conflict'
  } finally {
    saving.value = false
  }
}

function apiMessage(error: unknown): string {
  if (!isAxiosError<{ error?: { message?: string } }>(error)) return ''
  return error.response?.data?.error?.message ?? ''
}

function apiCode(error: unknown): string {
  if (!isAxiosError<{ error?: { code?: string } }>(error)) return ''
  return error.response?.data?.error?.code ?? ''
}

onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.employer.policies.setup_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.employer.policies.setup_hint') }}
          </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.employer.policies.effective_on') }}
            </span>
            <input
              v-model="effectiveOn"
              type="date"
              class="h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            >
          </label>
          <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="reloadSetup">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ t('common.refresh') }}
          </button>
        </div>
      </div>

      <div
        v-if="setupError"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        {{ setupError }}
      </div>

      <div v-if="setup" class="mt-5">
        <div
          :class="[
            'rounded-lg border px-4 py-3',
            setup.ready
              ? 'border-success-500/30 bg-success-50'
              : 'border-warning-500/30 bg-warning-50',
          ]"
          role="status"
        >
          <p :class="['font-medium', setup.ready ? 'text-success-800' : 'text-warning-800']">
            {{ t(setup.ready
              ? 'payroll.employer.policies.setup_ready'
              : 'payroll.employer.policies.setup_blocked') }}
          </p>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.employer.policies.setup_summary', {
              ok: setup.checks.filter(check => check.status === 'ok').length,
              all: setup.checks.length,
            }) }}
          </p>
        </div>
        <ul class="mt-3 grid grid-cols-1 gap-2 lg:grid-cols-2">
          <li
            v-for="check in setup.checks"
            :key="check.code"
            class="flex items-start gap-3 rounded-lg border border-neutral-200 p-3"
          >
            <span :class="['mt-0.5 inline-flex rounded-full px-2 py-0.5 text-xs font-medium', statusClass(check.status)]">
              {{ t(`payroll.employer.policies.status.${check.status}`) }}
            </span>
            <span class="text-sm text-neutral-700">{{ checkMessage(check) }}</span>
          </li>
        </ul>
      </div>
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.employer.policies.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.employer.policies.hint') }}
          </p>
        </div>
        <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="openNew">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.employer.policies.add') }}
        </button>
      </div>

      <div
        v-if="loadError"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="[btnOutline('danger'), 'mt-3']" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.retry') }}
        </button>
      </div>

      <p v-if="!loading && policies.length === 0" class="mt-5 text-sm text-neutral-500">
        {{ t('payroll.employer.policies.empty') }}
      </p>

      <div v-else-if="policies.length" class="mt-5 hidden md:block">
        <div class="flex flex-wrap items-center justify-end gap-2 pb-2">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-3 py-2">{{ t('payroll.employer.policies.validity') }}</th>
              <th v-if="tbl.isVisible('payday')" class="px-3 py-2">{{ t('payroll.employer.policies.payday') }}</th>
              <th v-if="tbl.isVisible('automation')" class="px-3 py-2">{{ t('payroll.employer.policies.automation') }}</th>
              <th v-if="tbl.isVisible('state')" class="px-3 py-2">{{ t('payroll.employer.policies.state') }}</th>
              <th class="px-3 py-2 text-right">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-200">
            <tr v-for="policy in policies" :key="policy.id">
              <td class="px-3 py-3">
                {{ policy.valid_from }} – {{ policy.valid_to ?? '∞' }}
              </td>
              <td v-if="tbl.isVisible('payday')" class="px-3 py-3">
                {{ t('payroll.employer.policies.payday_value', {
                  day: policy.payday_day,
                  offset: policy.payday_month_offset,
                }) }}
              </td>
              <td v-if="tbl.isVisible('automation')" class="px-3 py-3 text-neutral-600">
                {{ [
                  policy.automatic_calculation_enabled ? t('payroll.employer.policies.auto_calculation_short') : null,
                  policy.automatic_posting_enabled ? t('payroll.employer.policies.auto_posting_short') : null,
                  policy.automatic_payments_enabled ? t('payroll.employer.policies.auto_payments_short') : null,
                ].filter(Boolean).join(', ') || '—' }}
              </td>
              <td v-if="tbl.isVisible('state')" class="px-3 py-3">
                <span
                  :class="[
                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                    policyIsEffective(policy)
                      ? 'bg-success-50 text-success-700'
                      : 'bg-neutral-100 text-neutral-600',
                  ]"
                >
                  {{ t(policyIsEffective(policy)
                    ? 'payroll.employer.policies.effective'
                    : 'payroll.employer.policies.outside_period') }}
                </span>
              </td>
              <td class="px-3 py-3 text-right">
                <button
                  v-if="canWrite"
                  type="button"
                  :class="btnOutline('neutral')"
                  @click="edit(policy)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.edit" />
                  </svg>
                  {{ t('common.edit') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>

      <div v-if="policies.length" class="mt-5 space-y-3 md:hidden">
        <article
          v-for="policy in policies"
          :key="`mobile-${policy.id}`"
          class="rounded-lg border border-neutral-200 p-4"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="font-medium text-neutral-900">
                {{ policy.valid_from }} – {{ policy.valid_to ?? '∞' }}
              </p>
              <p class="mt-1 text-sm text-neutral-500">
                {{ t('payroll.employer.policies.payday_value', {
                  day: policy.payday_day,
                  offset: policy.payday_month_offset,
                }) }}
              </p>
            </div>
            <span
              :class="[
                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                policyIsEffective(policy)
                  ? 'bg-success-50 text-success-700'
                  : 'bg-neutral-100 text-neutral-600',
              ]"
            >
              {{ t(policyIsEffective(policy)
                ? 'payroll.employer.policies.effective'
                : 'payroll.employer.policies.outside_period') }}
            </span>
          </div>
          <button
            v-if="canWrite"
            type="button"
            :class="[btnOutline('neutral'), 'mt-3 w-full justify-center']"
            @click="edit(policy)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('common.edit') }}
          </button>
        </article>
      </div>

      <PaginationBar
        v-if="policies.length"
        class="mt-5"
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <section
      v-if="editorOpen"
      class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 shadow-sm sm:p-6"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t(editingId === null
              ? 'payroll.employer.policies.new_title'
              : 'payroll.employer.policies.edit_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.employer.policies.editor_hint') }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
      </div>

      <div v-if="saveError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
        <p>{{ saveError }}</p>
        <button
          v-if="conflict"
          type="button"
          :class="[btnOutline('warning'), 'mt-3']"
          @click="reloadCurrent"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.policies.reload_current') }}
        </button>
      </div>
      <div
        v-if="showValidation && !valid"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        {{ t('payroll.employer.policies.validation') }}
      </div>

      <div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.valid_from') }}
          </span>
          <input v-model="form.valid_from" type="date" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.valid_to') }}
          </span>
          <input v-model="form.valid_to" type="date" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.payday_day') }}
          </span>
          <input v-model.number="form.payday_day" type="number" min="1" max="31" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
        </label>
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.payday_month') }}
          </span>
          <SearchableSelect
            :model-value="form.payday_month_offset"
            :options="[
              { value: 0, label: t('payroll.employer.policies.payday_current_month') },
              { value: 1, label: t('payroll.employer.policies.payday_next_month') },
            ]"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.payday_month_offset = ($event ?? 1) as 0 | 1"
          />
        </div>
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.business_day_rule') }}
          </span>
          <SearchableSelect
            :model-value="form.payday_business_day_rule"
            :options="businessDayOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.payday_business_day_rule = $event ?? 'none'"
          />
        </div>
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.rounding_mode') }}
          </span>
          <SearchableSelect
            :model-value="form.balance_rounding_mode"
            :options="roundingOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.balance_rounding_mode = $event ?? 'exact_minor_units'"
          />
        </div>
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.home_office') }}
          </span>
          <SearchableSelect
            :model-value="form.home_office_policy"
            :options="optionalPolicyOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.home_office_policy = $event ?? 'not_used'"
          />
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.leave_entitlement_weeks') }}
          </span>
          <input v-model.number="form.leave_entitlement_weeks" type="number" min="4" max="12" step="1" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.policies.leave_entitlement_weeks_hint') }}</span>
        </label>
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.travel_expenses') }}
          </span>
          <SearchableSelect
            :model-value="form.travel_expense_policy"
            :options="optionalPolicyOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.travel_expense_policy = $event ?? 'not_used'"
          />
        </div>
      </div>

      <fieldset class="mt-6">
        <legend class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.employer.policies.automation_title') }}
        </legend>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <label class="flex items-start gap-2">
            <input v-model="form.automatic_calculation_enabled" type="checkbox" :disabled="!canWrite" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
            <span class="text-sm text-neutral-700">{{ t('payroll.employer.policies.automatic_calculation') }}</span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="form.automatic_posting_enabled" type="checkbox" :disabled="!canWrite" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
            <span class="text-sm text-neutral-700">{{ t('payroll.employer.policies.automatic_posting') }}</span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="form.automatic_payments_enabled" type="checkbox" :disabled="!canWrite" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
            <span class="text-sm text-neutral-700">{{ t('payroll.employer.policies.automatic_payments') }}</span>
          </label>
        </div>
      </fieldset>

      <div class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.delivery_channel') }}
          </span>
          <SearchableSelect
            :model-value="form.delivery_channel"
            :options="deliveryOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.delivery_channel = $event ?? 'disabled'; normalizeDelivery()"
          />
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.delivery_verified_on') }}
          </span>
          <input
            v-model="form.delivery_verified_on"
            type="date"
            :disabled="!canWrite || form.delivery_channel === 'disabled'"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.policies.source_reference') }}
          </span>
          <input
            v-model="form.source_reference"
            type="text"
            maxlength="255"
            :disabled="!canWrite"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>
      </div>

      <div class="mt-6 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
        <button
          v-if="canWrite"
          type="button"
          :class="btnFilled('primary')"
          :disabled="saving"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </section>
  </div>
</template>
