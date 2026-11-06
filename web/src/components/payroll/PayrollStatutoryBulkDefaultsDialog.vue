<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollStatutoryBulkPersonRef,
  type PayrollStatutoryBulkPreview,
  type PayrollStatutoryBulkResult,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatPeriod } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import {
  BULK_DEFAULT_SECTIONS,
  bulkApplyEmployeeIds,
  bulkCodeKey,
  discountObstacleCounts,
  effectiveBasisCounts,
  type BulkCodeGroup,
} from './statutoryBulkDefaults'

/*
 * Hromadné doplnění výchozí zákonné evidence. Náhled nic nezapisuje; zápis
 * jde osobu po osobě přes tentýž validátor jako karta osoby, takže náhled
 * nemůže slíbit víc, než zápis udělá. Prohlášení poplatníka se zapisuje jen
 * jako „nepodepsal" a jen na výslovné zaškrtnutí.
 */
const props = withDefaults(defineProps<{
  /** První den měsíce, od kterého údaje platí (YYYY-MM-DD). */
  effectiveOn: string
  /** `null` = všechny osoby, které by vzal mzdový běh za měsíc. */
  employeeIds?: number[] | null
  /** Seznam zaměstnanců nemá běh, měsíc si volí účetní. */
  periodEditable?: boolean
}>(), {
  employeeIds: null,
  periodEditable: false,
})

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollStatutoryBulkResult): void
}>()

defineSlots<{
  'after-apply'?(props: { result: PayrollStatutoryBulkResult }): unknown
}>()

const { t } = useI18n()
const toast = useToast()

const period = ref(props.effectiveOn.slice(0, 7))
const preview = ref<PayrollStatutoryBulkPreview | null>(null)
const loading = ref(false)
const loadError = ref('')
const recordUnsigned = ref(false)
const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollStatutoryBulkResult | null>(null)

const effectiveOn = computed(() => `${period.value}-01`)
const applyIds = computed(() => preview.value ? bulkApplyEmployeeIds(preview.value, recordUnsigned.value) : [])
const names = computed(() => new Map((preview.value?.people ?? []).map(person => [person.employee_id, person.full_name])))
const discountObstacles = computed(() => preview.value ? discountObstacleCounts(preview.value) : [])
const basisCounts = computed(() => preview.value ? effectiveBasisCounts(preview.value) : [])
const applyBlockedReason = computed(() => {
  if (!preview.value) return ''
  return applyIds.value.length === 0 ? t('payroll.statutory_bulk.nothing_to_apply') : ''
})

function label(group: BulkCodeGroup, code: string): string {
  const key = bulkCodeKey(group, code)
  return key ? t(key) : code
}

function personName(id: number, fullName?: string | null): string {
  return fullName ?? names.value.get(id) ?? t('payroll.statutory_bulk.person_fallback', { id })
}

function namesSummary(people: PayrollStatutoryBulkPersonRef[], limit = 12): string {
  const visible = people.slice(0, limit).map(person => personName(person.employee_id, person.full_name)).join(', ')
  return people.length > limit
    ? `${visible} · ${t('payroll.statutory_bulk.and_more', { count: people.length - limit })}`
    : visible
}

function reasonsText(reasons: string[], foreign: string[] = []): string {
  const parts = reasons.filter(reason => reason !== 'foreign_element' || foreign.length === 0)
    .map(reason => label('reason', reason))
  if (foreign.length) {
    parts.push(`${label('reason', 'foreign_element')}: ${foreign.map(code => label('foreign', code)).join(', ')}`)
  }
  return parts.join('; ')
}

async function loadPreview() {
  if (!/^\d{4}-\d{2}$/.test(period.value)) return
  loading.value = true
  loadError.value = ''
  result.value = null
  applyError.value = ''
  recordUnsigned.value = false
  try {
    preview.value = await payrollApi.statutoryBulkDefaultsPreview({
      effective_on: effectiveOn.value,
      employee_ids: props.employeeIds,
    })
  } catch (error) {
    preview.value = null
    loadError.value = apiErrorMessage(error, t('payroll.statutory_bulk.load_failed'))
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!preview.value || applyIds.value.length === 0 || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.statutoryBulkDefaultsApply({
      effective_on: effectiveOn.value,
      employee_ids: applyIds.value,
      ...(recordUnsigned.value ? { record_unsigned_declaration: true } : {}),
    })
    result.value = response
    toast.success(t('payroll.statutory_bulk.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.statutory_bulk.apply_failed'))
  } finally {
    applying.value = false
  }
}

onMounted(loadPreview)
</script>

<template>
  <Modal :title="t('payroll.statutory_bulk.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4" data-test="statutory-bulk-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.statutory_bulk.intro') }}</p>

      <div v-if="periodEditable" class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.statutory_bulk.period') }}</span>
          <input
            v-model="period"
            type="month"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="statutory-bulk-period"
            :disabled="loading || applying"
            @change="loadPreview"
          >
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading || applying" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.statutory_bulk.reload') }}
        </button>
      </div>

      <div v-if="loading" class="space-y-2" data-test="statutory-bulk-loading">
        <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutlineSm('danger')" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.statutory_bulk.retry') }}
        </button>
      </div>

      <template v-else-if="preview && !result">
        <p class="text-xs text-neutral-500">
          {{ employeeIds === null
            ? t('payroll.statutory_bulk.scope_run', { period: formatPeriod(period) })
            : t('payroll.statutory_bulk.scope_selection', { count: employeeIds.length }) }}
        </p>
        <p
          v-if="preview.frozen_through"
          class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
          data-test="statutory-bulk-frozen"
        >
          {{ t('payroll.statutory_bulk.frozen', { date: formatDate(preview.frozen_through) }) }}
        </p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.statutory_bulk.summary.people') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.people }}</p>
          </article>
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.statutory_bulk.summary.ready') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="statutory-bulk-ready">{{ preview.summary.ready }}</p>
          </article>
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.statutory_bulk.summary.nothing_to_add') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.nothing_to_add }}</p>
          </article>
          <article class="rounded-lg p-3" :class="preview.summary.excluded ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="preview.summary.excluded ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.statutory_bulk.summary.excluded') }}</p>
            <p class="mt-1 text-lg font-semibold" :class="preview.summary.excluded ? 'text-warning-700' : ''">{{ preview.summary.excluded }}</p>
          </article>
        </div>

        <section class="rounded-lg border border-neutral-200 bg-surface p-3">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.statutory_bulk.sections_title') }}</h3>
          <ul class="mt-2 space-y-1 text-sm">
            <li
              v-for="section in BULK_DEFAULT_SECTIONS"
              :key="section"
              class="flex flex-wrap items-baseline justify-between gap-2"
              :data-test="`statutory-bulk-section-${section}`"
            >
              <span class="text-neutral-700">{{ t(`payroll.statutory_bulk.section.${section}`) }}</span>
              <span class="font-medium tabular-nums text-neutral-900">
                {{ t('payroll.statutory_bulk.section_count', { count: preview.summary.sections[section] }) }}
              </span>
            </li>
          </ul>
          <div v-if="discountObstacles.length" class="mt-3 text-xs text-neutral-600">
            <p class="font-medium text-neutral-700">{{ t('payroll.statutory_bulk.discount_title') }}</p>
            <ul class="mt-0.5 space-y-0.5">
              <li v-for="item in discountObstacles" :key="item.reason">{{ label('discount', item.reason) }}: {{ item.count }}</li>
            </ul>
          </div>
          <div v-if="basisCounts.length" class="mt-3 text-xs text-neutral-600">
            <p class="font-medium text-neutral-700">{{ t('payroll.statutory_bulk.basis_title') }}</p>
            <ul class="mt-0.5 space-y-0.5">
              <li v-for="item in basisCounts" :key="item.basis">{{ label('basis', item.basis) }}: {{ item.count }}</li>
            </ul>
          </div>
        </section>

        <details
          v-if="preview.excluded.length"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="statutory-bulk-excluded"
        >
          <summary class="cursor-pointer font-medium">{{ t('payroll.statutory_bulk.excluded_title', { count: preview.excluded.length }) }}</summary>
          <p class="mt-1 text-xs">{{ t('payroll.statutory_bulk.excluded_hint') }}</p>
          <ul class="mt-2 max-h-56 space-y-1 overflow-y-auto text-xs">
            <li v-for="person in preview.excluded" :key="person.employee_id">
              <span class="font-medium">{{ personName(person.employee_id, person.full_name) }}:</span>
              {{ reasonsText(person.reasons, person.foreign_elements) }}
            </li>
          </ul>
        </details>

        <section
          v-if="preview.health_insurer_missing.length"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="statutory-bulk-health"
        >
          <p class="font-medium">{{ t('payroll.statutory_bulk.health_title', { count: preview.health_insurer_missing.length }) }}</p>
          <p class="mt-1 text-xs">{{ t('payroll.statutory_bulk.health_hint') }}</p>
          <p class="mt-1 text-xs font-medium">{{ namesSummary(preview.health_insurer_missing) }}</p>
          <RouterLink
            :to="{ name: 'payroll-people', query: { bulk: 'health_insurer' } }"
            :class="[btnOutlineSm('warning'), 'mt-2 inline-flex']"
            data-test="statutory-bulk-health-link"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
            {{ t('payroll.statutory_bulk.health_link') }}
          </RouterLink>
        </section>

        <!--
          Prohlášení poplatníka: jediná sekce, kde by výchozí stav mohl
          uškodit. Nepodepsané prohlášení u dohody s nízkým příjmem přepne
          zdanění na srážku, proto je zaškrtávátko vypnuté a jmenný seznam
          ohrožených osob přímo u něj.
        -->
        <section
          v-if="preview.summary.declaration_missing"
          class="rounded-lg border p-3 text-sm"
          :class="recordUnsigned ? 'border-danger-500/40 bg-danger-50 text-danger-800' : 'border-warning-200 bg-warning-50 text-warning-800'"
          data-test="statutory-bulk-declaration"
        >
          <p class="font-medium">{{ t('payroll.statutory_bulk.declaration_title', { count: preview.summary.declaration_missing }) }}</p>
          <label class="mt-2 flex items-start gap-2">
            <input
              v-model="recordUnsigned"
              type="checkbox"
              class="mt-0.5 rounded border-neutral-300 text-danger-600"
              data-test="statutory-bulk-record-unsigned"
              :disabled="applying"
            >
            <span>
              <span class="font-medium">{{ t('payroll.statutory_bulk.declaration_label', { count: preview.summary.declaration_missing }) }}</span>
              <span class="mt-0.5 block text-xs">{{ t('payroll.statutory_bulk.declaration_hint') }}</span>
              <span v-if="recordUnsigned" class="mt-1 block text-xs font-semibold">{{ t('payroll.statutory_bulk.declaration_on') }}</span>
            </span>
          </label>
          <RouterLink
            :to="{ name: 'payroll-people', query: { bulk: 'declaration_signed' } }"
            :class="[btnOutlineSm('success'), 'mt-2 inline-flex']"
            data-test="statutory-bulk-declaration-signed-link"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
            {{ t('payroll.statutory_bulk.declaration_signed_link') }}
          </RouterLink>
          <div
            v-if="preview.unsigned_declaration_withholding_risk.length"
            class="mt-3 rounded-md border border-danger-500/40 bg-surface p-2 text-xs text-danger-700"
            data-test="statutory-bulk-withholding-risk"
          >
            <p class="flex items-start gap-1.5 font-semibold">
              <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.bell" /></svg>
              {{ t('payroll.statutory_bulk.withholding_title', { count: preview.unsigned_declaration_withholding_risk.length }) }}
            </p>
            <p class="mt-1">{{ t('payroll.statutory_bulk.withholding_hint') }}</p>
            <p class="mt-1 max-h-24 overflow-y-auto font-medium">{{ namesSummary(preview.unsigned_declaration_withholding_risk, 40) }}</p>
          </div>
        </section>

        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
          {{ applyError }}
        </p>

        <div class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="applying" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="cursor-pointer whitespace-nowrap"
              :class="btnFilled('primary')"
              :disabled="applying || applyIds.length === 0"
              :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)"
              data-test="statutory-bulk-apply"
              @click="apply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ applying ? t('common.saving') : t('payroll.statutory_bulk.apply', { count: applyIds.length }) }}
            </button>
            <p v-if="applyBlockedReason" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
          </div>
        </div>
      </template>

      <template v-if="result">
        <section data-test="statutory-bulk-result" class="space-y-3">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.statutory_bulk.result_title') }}</h3>
          <div class="grid grid-cols-3 gap-3">
            <article class="rounded-lg bg-success-50 p-3">
              <p class="text-xs text-success-700">{{ t('payroll.statutory_bulk.result.applied') }}</p>
              <p class="mt-1 text-lg font-semibold text-success-700" data-test="statutory-bulk-applied">{{ result.counts.applied }}</p>
            </article>
            <article class="rounded-lg p-3" :class="result.counts.skipped ? 'bg-warning-50' : 'bg-neutral-50'">
              <p class="text-xs" :class="result.counts.skipped ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.statutory_bulk.result.skipped') }}</p>
              <p class="mt-1 text-lg font-semibold">{{ result.counts.skipped }}</p>
            </article>
            <article class="rounded-lg p-3" :class="result.counts.failed ? 'bg-danger-50' : 'bg-neutral-50'">
              <p class="text-xs" :class="result.counts.failed ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.statutory_bulk.result.failed') }}</p>
              <p class="mt-1 text-lg font-semibold">{{ result.counts.failed }}</p>
            </article>
          </div>
          <div v-if="result.skipped.length" class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-800">
            <p class="font-medium">{{ t('payroll.statutory_bulk.skipped_title', { count: result.skipped.length }) }}</p>
            <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto">
              <li v-for="item in result.skipped" :key="item.employee_id">
                <span class="font-medium">{{ personName(item.employee_id) }}:</span>
                {{ reasonsText(item.reasons, item.foreign_elements ?? []) }}
              </li>
            </ul>
          </div>
          <div v-if="result.failed.length" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-xs text-danger-700" data-test="statutory-bulk-failed">
            <p class="font-medium">{{ t('payroll.statutory_bulk.failed_title', { count: result.failed.length }) }}</p>
            <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto">
              <li v-for="item in result.failed" :key="item.employee_id">
                <span class="font-medium">{{ personName(item.employee_id) }}:</span> {{ item.message }}
              </li>
            </ul>
          </div>
          <slot name="after-apply" :result="result" />
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" data-test="statutory-bulk-close" @click="emit('close')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.close') }}
            </button>
          </div>
        </section>
      </template>
    </div>
  </Modal>
</template>
