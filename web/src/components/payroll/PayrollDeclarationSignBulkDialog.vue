<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollDeclarationSignPerson,
  type PayrollDeclarationSignPreview,
  type PayrollDeclarationSignResult,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatPeriod } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import { todayIso } from './payrollPaymentDate'

/*
 * Hromadný zápis podepsaných prohlášení poplatníka. Náhled nic nezapisuje;
 * zápis jde osobu po osobě přes tutéž zákonnou evidenci jako karta osoby,
 * podepsané prohlášení nepřepíše a do období schválené mzdy nesahá.
 */
const props = withDefaults(defineProps<{
  /** `null` = osoby, kterým v roce trvá pracovní vztah. */
  employeeIds?: number[] | null
}>(), {
  employeeIds: null,
})

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollDeclarationSignResult): void
}>()

const { t } = useI18n()
const toast = useToast()

const today = todayIso()
const year = ref(Number(today.slice(0, 4)))
const signedOn = ref(today)
const preview = ref<PayrollDeclarationSignPreview | null>(null)
const selected = ref<Set<number>>(new Set())
const confirmed = ref(false)
const loading = ref(false)
const loadError = ref('')
const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollDeclarationSignResult | null>(null)

const inputValid = computed(() => Number.isInteger(year.value) && /^\d{4}-\d{2}-\d{2}$/.test(signedOn.value))
const readyPeople = computed(() => (preview.value?.people ?? []).filter(person => person.state === 'ready'))
const applyIds = computed(() => readyPeople.value.map(person => person.employee_id).filter(id => selected.value.has(id)))
const skippedCount = computed(() => preview.value
  ? preview.value.summary.people - preview.value.summary.ready - preview.value.summary.already_signed
  : 0)
const names = computed(() => new Map((preview.value?.people ?? []).map(person => [person.employee_id, person.full_name])))
const blockedReason = computed(() => {
  if (!preview.value) return ''
  if (applyIds.value.length === 0) return t('payroll.declaration_sign_bulk.nothing_selected')
  return confirmed.value ? '' : t('payroll.declaration_sign_bulk.confirm_required')
})

function personName(id: number, fullName?: string | null): string {
  return fullName ?? names.value.get(id) ?? t('payroll.declaration_sign_bulk.person_fallback', { id })
}

function statusLabel(status: string | null): string {
  return status ? t(`payroll.declaration_sign_bulk.status.${status}`) : ''
}

function personDetail(person: PayrollDeclarationSignPerson): string {
  if (person.state !== 'ready' || person.change === null) {
    return t(`payroll.declaration_sign_bulk.state.${person.state}`)
  }
  const parts = [t(`payroll.declaration_sign_bulk.change.${person.change}`, { status: statusLabel(person.current_status) })]
  if (person.effective_from) {
    parts.push(t('payroll.declaration_sign_bulk.person_effective', { date: formatPeriod(person.effective_from.slice(0, 7)) }))
  }
  if (person.annual_settlement) parts.push(t('payroll.declaration_sign_bulk.annual_settlement'))
  return parts.join(' · ')
}

function toggle(id: number, on: boolean) {
  const next = new Set(selected.value)
  if (on) next.add(id)
  else next.delete(id)
  selected.value = next
}

function selectAll(on: boolean) {
  selected.value = new Set(on ? readyPeople.value.map(person => person.employee_id) : [])
}

async function loadPreview() {
  if (!inputValid.value) return
  loading.value = true
  loadError.value = ''
  result.value = null
  applyError.value = ''
  confirmed.value = false
  try {
    preview.value = await payrollApi.declarationSignBulkPreview({
      year: year.value,
      signed_on: signedOn.value,
      employee_ids: props.employeeIds,
    })
    // Výslovné „Nepodepsáno" je rozhodnutí účetní, ne mezera: bez zúžení na
    // konkrétní osoby se k přepisu nepředvybírá.
    const explicitlyUnsigned = new Set(preview.value.people
      .filter(person => person.change !== 'add' && person.current_status === 'not-signed')
      .map(person => person.employee_id))
    selected.value = new Set(preview.value.ready_employee_ids
      .filter(id => props.employeeIds !== null || !explicitlyUnsigned.has(id)))
  } catch (error) {
    preview.value = null
    loadError.value = apiErrorMessage(error, t('payroll.declaration_sign_bulk.load_failed'))
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!preview.value || blockedReason.value !== '' || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.declarationSignBulkApply({
      year: preview.value.year,
      signed_on: preview.value.signed_on,
      employee_ids: applyIds.value,
    })
    result.value = response
    toast.success(t('payroll.declaration_sign_bulk.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.declaration_sign_bulk.apply_failed'))
  } finally {
    applying.value = false
  }
}

onMounted(loadPreview)
</script>

<template>
  <Modal :title="t('payroll.declaration_sign_bulk.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4" data-test="declaration-sign-bulk-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.declaration_sign_bulk.intro') }}</p>

      <ul class="space-y-1 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800" data-test="declaration-sign-bulk-warnings">
        <li class="flex items-start gap-1.5">
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.bell" /></svg>
          {{ t('payroll.declaration_sign_bulk.warning_one_payer') }}
        </li>
        <li class="flex items-start gap-1.5">
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.bell" /></svg>
          {{ t('payroll.declaration_sign_bulk.warning_responsibility') }}
        </li>
      </ul>

      <div v-if="!result" class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.declaration_sign_bulk.year') }}</span>
          <input
            v-model.number="year"
            type="number"
            min="2000"
            max="2100"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="declaration-sign-bulk-year"
            :disabled="loading || applying"
            @change="loadPreview"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.declaration_sign_bulk.signed_on') }}</span>
          <DateInput
            v-model="signedOn"
            :max="today"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="declaration-sign-bulk-signed-on"
            :disabled="loading || applying"
            @change="loadPreview" />
        </label>
        <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="loading || applying || !inputValid" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.declaration_sign_bulk.reload') }}
        </button>
      </div>

      <div v-if="loading" class="space-y-2" data-test="declaration-sign-bulk-loading">
        <div v-for="index in 3" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div v-else-if="loadError" role="alert" class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutlineSm('danger')" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.declaration_sign_bulk.retry') }}
        </button>
      </div>

      <template v-else-if="preview && !result">
        <p
          v-if="preview.effective_from === null"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="declaration-sign-bulk-year-frozen"
        >
          {{ preview.effective_from_basis === 'after_frozen_period'
            ? t('payroll.declaration_sign_bulk.year_frozen', { year: preview.year, date: formatDate(preview.frozen_through) })
            : t('payroll.declaration_sign_bulk.state.after_year_end') }}
        </p>
        <p v-else class="text-sm text-neutral-700" data-test="declaration-sign-bulk-effective">
          <span class="font-medium">{{ t('payroll.declaration_sign_bulk.effective_from', { date: formatPeriod(preview.effective_from.slice(0, 7)) }) }}</span>
          · {{ t(`payroll.declaration_sign_bulk.basis.${preview.effective_from_basis}`, { date: formatDate(preview.frozen_through) }) }}
        </p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.declaration_sign_bulk.summary.people') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.people }}</p>
          </article>
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.declaration_sign_bulk.summary.ready') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="declaration-sign-bulk-ready">{{ preview.summary.ready }}</p>
          </article>
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.declaration_sign_bulk.summary.already_signed') }}</p>
            <p class="mt-1 text-lg font-semibold" data-test="declaration-sign-bulk-already">{{ preview.summary.already_signed }}</p>
          </article>
          <article class="rounded-lg p-3" :class="skippedCount ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="skippedCount ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.declaration_sign_bulk.summary.skipped') }}</p>
            <p class="mt-1 text-lg font-semibold" :class="skippedCount ? 'text-warning-700' : ''">{{ skippedCount }}</p>
          </article>
        </div>

        <section
          v-if="preview.runs_to_recalculate.length"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="declaration-sign-bulk-recalculate"
        >
          <p class="font-medium">{{ t('payroll.declaration_sign_bulk.recalculate_title') }}</p>
          <p class="mt-1 text-xs">{{ t('payroll.declaration_sign_bulk.recalculate_hint') }}</p>
          <p class="mt-1 text-xs font-medium">{{ preview.runs_to_recalculate.map(run => formatPeriod(run.period_start.slice(0, 7))).join(', ') }}</p>
        </section>

        <p v-if="preview.people.length === 0" class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700" data-test="declaration-sign-bulk-empty">
          {{ t('payroll.declaration_sign_bulk.empty', { year: preview.year }) }}
        </p>

        <template v-else>
          <div v-if="readyPeople.length" class="flex flex-wrap gap-2">
            <button type="button" class="whitespace-nowrap" :class="btnOutlineSm('neutral')" data-test="declaration-sign-bulk-all" @click="selectAll(true)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ t('payroll.declaration_sign_bulk.select_all') }}
            </button>
            <button type="button" class="whitespace-nowrap" :class="btnOutlineSm('neutral')" data-test="declaration-sign-bulk-none" @click="selectAll(false)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('payroll.declaration_sign_bulk.select_none') }}
            </button>
          </div>
          <ul class="max-h-80 divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200">
            <li
              v-for="person in preview.people"
              :key="person.employee_id"
              class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
              :data-test="`declaration-sign-bulk-row-${person.employee_id}`"
            >
              <input
                type="checkbox"
                class="rounded border-neutral-300 text-primary-600"
                :checked="selected.has(person.employee_id)"
                :disabled="person.state !== 'ready' || applying"
                :aria-label="t('payroll.declaration_sign_bulk.select_person', { name: personName(person.employee_id, person.full_name) })"
                :data-test="`declaration-sign-bulk-pick-${person.employee_id}`"
                @change="toggle(person.employee_id, ($event.target as HTMLInputElement).checked)"
              >
              <span class="min-w-40 flex-1 font-medium text-neutral-900">{{ personName(person.employee_id, person.full_name) }}</span>
              <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="person.state === 'ready'
                  ? 'bg-success-50 text-success-700'
                  : person.state === 'already_signed' ? 'bg-neutral-100 text-neutral-600' : 'bg-warning-50 text-warning-700'"
                :data-test="`declaration-sign-bulk-state-${person.employee_id}`"
              >{{ personDetail(person) }}</span>
            </li>
          </ul>
        </template>

        <label v-if="readyPeople.length" class="flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="confirmed" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" data-test="declaration-sign-bulk-confirm" :disabled="applying">
          <span>{{ t('payroll.declaration_sign_bulk.confirm') }}</span>
        </label>

        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">{{ applyError }}</p>

        <div class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="applying" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="whitespace-nowrap"
              :class="btnFilled('success')"
              :disabled="applying || blockedReason !== ''"
              :title="disabledTitle(blockedReason !== '', blockedReason)"
              data-test="declaration-sign-bulk-apply"
              @click="apply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ applying ? t('common.saving') : t('payroll.declaration_sign_bulk.apply', { count: applyIds.length }) }}
            </button>
            <p v-if="blockedReason" :class="BTN_DISABLED_NOTE">{{ blockedReason }}</p>
          </div>
        </div>
      </template>

      <section v-if="result" class="space-y-3" data-test="declaration-sign-bulk-result">
        <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.declaration_sign_bulk.result_title') }}</h3>
        <div class="grid grid-cols-3 gap-3">
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.declaration_sign_bulk.result.applied') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="declaration-sign-bulk-applied">{{ result.counts.applied }}</p>
          </article>
          <article class="rounded-lg p-3" :class="result.counts.skipped ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="result.counts.skipped ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.declaration_sign_bulk.result.skipped') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ result.counts.skipped }}</p>
          </article>
          <article class="rounded-lg p-3" :class="result.counts.failed ? 'bg-danger-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="result.counts.failed ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.declaration_sign_bulk.result.failed') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ result.counts.failed }}</p>
          </article>
        </div>
        <ul class="max-h-80 divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200 text-sm">
          <li v-for="item in result.applied" :key="`a-${item.employee_id}`" class="flex flex-wrap gap-2 px-3 py-1.5" :data-test="`declaration-sign-bulk-result-${item.employee_id}`">
            <span class="flex-1 font-medium text-neutral-900">{{ personName(item.employee_id) }}</span>
            <span class="text-success-700">{{ t('payroll.declaration_sign_bulk.result.applied') }} · {{ t('payroll.declaration_sign_bulk.applied_item', { date: formatPeriod(item.effective_from.slice(0, 7)) }) }}<template v-if="item.annual_settlement"> · {{ t('payroll.declaration_sign_bulk.annual_settlement') }}</template></span>
          </li>
          <li v-for="item in result.skipped" :key="`s-${item.employee_id}`" class="flex flex-wrap gap-2 px-3 py-1.5" :data-test="`declaration-sign-bulk-result-${item.employee_id}`">
            <span class="flex-1 font-medium text-neutral-900">{{ personName(item.employee_id) }}</span>
            <span class="text-warning-700">{{ item.reasons.map(reason => t(`payroll.declaration_sign_bulk.state.${reason}`)).join('; ') }}</span>
          </li>
          <li v-for="item in result.failed" :key="`f-${item.employee_id}`" class="flex flex-wrap gap-2 px-3 py-1.5" :data-test="`declaration-sign-bulk-result-${item.employee_id}`">
            <span class="flex-1 font-medium text-neutral-900">{{ personName(item.employee_id) }}</span>
            <span class="text-danger-700">{{ item.message }}</span>
          </li>
        </ul>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" data-test="declaration-sign-bulk-close" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </section>
    </div>
  </Modal>
</template>
