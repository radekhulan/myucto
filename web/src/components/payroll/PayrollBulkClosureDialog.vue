<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollBulkClosureGroup,
  type PayrollBulkClosureItem,
  type PayrollBulkClosurePreview,
  type PayrollBulkClosureResult,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { btnFilled, btnOutline, BTN_DISABLED_NOTE, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { personalNumberLabel } from '@/pages/payroll/employmentLifecycleUi'

/*
 * Hromadné ukončení skončených dohod a archivace. Náhled nic nezapisuje;
 * každé ukončení pak jde serverem přes stejný přechod stavu jako na kartě
 * vztahu, takže chyba jedné položky se ukáže u ní a ostatní projdou.
 */
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollBulkClosureResult): void
}>()

const { t } = useI18n()
const toast = useToast()

const GROUPS: PayrollBulkClosureGroup[] = ['expired', 'inactive', 'archive']

const inactivityMonths = ref(3)
const archiveAfterMonths = ref(12)
const preview = ref<PayrollBulkClosurePreview | null>(null)
const loading = ref(false)
const loadError = ref('')
const confirming = ref(false)
const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollBulkClosureResult | null>(null)
const selected = reactive<Record<number, boolean>>({})
const dates = reactive<Record<number, string>>({})

const itemsByGroup = computed(() => {
  const groups: Record<PayrollBulkClosureGroup, PayrollBulkClosureItem[]> = { expired: [], inactive: [], archive: [] }
  for (const item of preview.value?.items ?? []) groups[item.group].push(item)
  return groups
})

const selectedItems = computed(() => (preview.value?.items ?? []).filter(item => selected[item.employment_id]))
const selectedEnd = computed(() => selectedItems.value.filter(item => item.action === 'end').length)
const selectedArchive = computed(() => selectedItems.value.filter(item => item.action === 'archive').length)
const maxItems = computed(() => preview.value?.max_items ?? 500)
const invalidDate = computed(() => selectedItems.value.some(item => !dates[item.employment_id]))

const applyBlockedReason = computed(() => {
  if (!preview.value) return ''
  if (selectedItems.value.length === 0) return t('payroll.bulk_closure.nothing_selected')
  if (selectedItems.value.length > maxItems.value) return t('payroll.bulk_closure.too_many', { max: maxItems.value })
  if (invalidDate.value) return t('payroll.bulk_closure.date_required')
  return ''
})

async function loadPreview() {
  loading.value = true
  loadError.value = ''
  result.value = null
  applyError.value = ''
  confirming.value = false
  try {
    preview.value = await payrollApi.bulkClosurePreview({
      inactivity_months: inactivityMonths.value,
      archive_after_months: archiveAfterMonths.value,
    })
    for (const key of Object.keys(selected)) delete selected[Number(key)]
    for (const key of Object.keys(dates)) delete dates[Number(key)]
    let preselected = 0
    for (const item of preview.value.items) {
      // Položka s prací nebo zúčtovanou mzdou po datu konce se nepředvybírá —
      // datum konce u ní musí posoudit člověk.
      const pick = item.blocked_reason === null && preselected < preview.value.max_items
      selected[item.employment_id] = pick
      if (pick) preselected++
      dates[item.employment_id] = item.proposed_on
    }
  } catch (error) {
    preview.value = null
    loadError.value = apiErrorMessage(error, t('payroll.bulk_closure.load_failed'))
  } finally {
    loading.value = false
  }
}

function toggleGroup(group: PayrollBulkClosureGroup, value: boolean) {
  for (const item of itemsByGroup.value[group]) selected[item.employment_id] = value
}

function groupAllSelected(group: PayrollBulkClosureGroup): boolean {
  const items = itemsByGroup.value[group]
  return items.length > 0 && items.every(item => selected[item.employment_id])
}

function itemDetail(item: PayrollBulkClosureItem): string {
  if (item.group === 'expired' && item.agreed_end_on) {
    return t('payroll.bulk_closure.detail.expired', { date: formatDate(item.agreed_end_on) })
  }
  if (item.group === 'inactive') {
    return item.last_activity_period
      ? t('payroll.bulk_closure.detail.inactive', { period: item.last_activity_period })
      : t('payroll.bulk_closure.detail.never_active')
  }
  return t('payroll.bulk_closure.detail.archive', { date: formatDate(item.agreed_end_on ?? '') })
}

function itemLabel(employmentId: number): string {
  return preview.value?.items.find(item => item.employment_id === employmentId)?.full_name ?? String(employmentId)
}

async function apply() {
  if (!preview.value || applyBlockedReason.value || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.bulkClosureApply(selectedItems.value.map(item => ({
      employment_id: item.employment_id,
      action: item.action,
      effective_on: dates[item.employment_id] ?? item.proposed_on,
      row_version: item.row_version,
    })), {
      inactivity_months: preview.value.inactivity_months,
      archive_after_months: preview.value.archive_after_months,
    })
    result.value = response
    confirming.value = false
    toast.success(t('payroll.bulk_closure.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.bulk_closure.apply_failed'))
  } finally {
    applying.value = false
  }
}

onMounted(loadPreview)
</script>

<template>
  <Modal :title="t('payroll.bulk_closure.title')" width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4" data-test="bulk-closure-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.bulk_closure.intro') }}</p>

      <div v-if="!result" class="flex flex-wrap items-end gap-3">
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('payroll.bulk_closure.inactivity_months') }}
          <input
            v-model.number="inactivityMonths"
            type="number"
            min="1"
            max="24"
            class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="bulk-closure-inactivity"
          >
        </label>
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('payroll.bulk_closure.archive_after_months') }}
          <input
            v-model.number="archiveAfterMonths"
            type="number"
            min="1"
            max="120"
            class="mt-1 block h-9 w-24 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="bulk-closure-archive-months"
          >
        </label>
        <button
          type="button"
          class="whitespace-nowrap"
          :class="btnOutline('neutral')"
          :disabled="loading || applying"
          data-test="bulk-closure-reload"
          @click="loadPreview"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.bulk_closure.reload') }}
        </button>
      </div>

      <div v-if="loading" class="space-y-2" data-test="bulk-closure-loading">
        <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutline('danger')" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.bulk_closure.retry') }}
        </button>
      </div>

      <template v-else-if="preview && !result">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <article v-for="group in GROUPS" :key="group" class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t(`payroll.bulk_closure.groups.${group}.summary`) }}</p>
            <p class="mt-1 text-lg font-semibold" :data-test="`bulk-closure-count-${group}`">{{ preview.summary[group] }}</p>
          </article>
          <article class="rounded-lg p-3" :class="preview.summary.blocked ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="preview.summary.blocked ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.bulk_closure.summary_blocked') }}</p>
            <p class="mt-1 text-lg font-semibold" :class="preview.summary.blocked ? 'text-warning-700' : ''">{{ preview.summary.blocked }}</p>
          </article>
        </div>

        <p v-if="preview.items.length === 0" class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-600" data-test="bulk-closure-empty">
          {{ t('payroll.bulk_closure.empty') }}
        </p>

        <section
          v-for="group in GROUPS.filter(candidate => itemsByGroup[candidate].length > 0)"
          :key="group"
          class="rounded-lg border border-neutral-200"
          :data-test="`bulk-closure-group-${group}`"
        >
          <header class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2">
            <div>
              <h3 class="text-sm font-semibold text-neutral-900">{{ t(`payroll.bulk_closure.groups.${group}.title`) }}</h3>
              <p class="text-xs text-neutral-500">
                {{ t(`payroll.bulk_closure.groups.${group}.hint`, { months: group === 'archive' ? preview.archive_after_months : preview.inactivity_months }) }}
              </p>
            </div>
            <label class="flex items-center gap-2 text-xs text-neutral-600">
              <input
                type="checkbox"
                :checked="groupAllSelected(group)"
                :disabled="applying"
                :data-test="`bulk-closure-toggle-${group}`"
                @change="toggleGroup(group, ($event.target as HTMLInputElement).checked)"
              >
              {{ t('payroll.bulk_closure.select_all') }}
            </label>
          </header>
          <ul class="max-h-72 divide-y divide-neutral-100 overflow-y-auto">
            <li
              v-for="item in itemsByGroup[group]"
              :key="item.employment_id"
              class="flex flex-wrap items-center gap-3 px-3 py-2"
              :data-test="`bulk-closure-item-${item.employment_id}`"
            >
              <input
                v-model="selected[item.employment_id]"
                type="checkbox"
                :disabled="applying"
                :aria-label="item.full_name"
                :data-test="`bulk-closure-select-${item.employment_id}`"
              >
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-neutral-900">
                  {{ item.full_name }}
                  <span class="font-normal text-neutral-500">· {{ t(`payroll.people.relations.${item.relation_type}`) }}</span>
                  <span v-if="personalNumberLabel(t, item.employment_code)" class="font-normal text-neutral-500"> · {{ personalNumberLabel(t, item.employment_code) }}</span>
                </p>
                <p class="text-xs text-neutral-500">{{ itemDetail(item) }}</p>
                <p v-if="item.blocked_reason" class="mt-0.5 text-xs text-warning-700" :data-test="`bulk-closure-blocked-${item.employment_id}`">
                  {{ t(`payroll.bulk_closure.blocked.${item.blocked_reason}`, { period: item.blocked_period ?? '' }) }}
                </p>
              </div>
              <label v-if="item.action === 'end'" class="text-xs text-neutral-600">
                <span class="mb-0.5 block">{{ t('payroll.bulk_closure.end_on') }}</span>
                <DateInput
                  v-model="dates[item.employment_id]"
                  :max="preview.as_of"
                  :disabled="applying"
                  class="w-36"
                  :data-test="`bulk-closure-date-${item.employment_id}`"
                />
              </label>
              <span v-else class="text-xs text-neutral-600">{{ t('payroll.bulk_closure.archive_on', { date: formatDate(item.proposed_on) }) }}</span>
            </li>
          </ul>
        </section>

        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
          {{ applyError }}
        </p>

        <div
          v-if="confirming"
          class="space-y-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="bulk-closure-confirm"
        >
          <p>{{ t('payroll.bulk_closure.confirm_text', { end: selectedEnd, archive: selectedArchive }) }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="applying" @click="confirming = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll.bulk_closure.back') }}
            </button>
            <button
              type="button"
              class="whitespace-nowrap"
              :class="btnFilled('warning')"
              :disabled="applying"
              data-test="bulk-closure-confirm-apply"
              @click="apply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ applying ? t('common.saving') : t('payroll.bulk_closure.confirm_apply') }}
            </button>
          </div>
        </div>

        <div v-else class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="applying" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="cursor-pointer whitespace-nowrap"
              :class="btnFilled('warning')"
              :disabled="applyBlockedReason !== ''"
              :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)"
              data-test="bulk-closure-apply"
              @click="confirming = true"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.archive" /></svg>
              {{ t('payroll.bulk_closure.apply', { count: selectedItems.length }) }}
            </button>
            <p v-if="applyBlockedReason" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
          </div>
        </div>
      </template>

      <section v-if="result" data-test="bulk-closure-result" class="space-y-3">
        <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.bulk_closure.result_title') }}</h3>
        <div class="grid grid-cols-2 gap-3">
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.bulk_closure.result.applied') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="bulk-closure-applied">{{ result.counts.applied }}</p>
          </article>
          <article class="rounded-lg p-3" :class="result.counts.failed ? 'bg-danger-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="result.counts.failed ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.bulk_closure.result.failed') }}</p>
            <p class="mt-1 text-lg font-semibold" data-test="bulk-closure-failed-count">{{ result.counts.failed }}</p>
          </article>
        </div>
        <div v-if="result.failed.length" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-xs text-danger-700" data-test="bulk-closure-failed">
          <p class="font-medium">{{ t('payroll.bulk_closure.failed_title', { count: result.failed.length }) }}</p>
          <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto">
            <li v-for="item in result.failed" :key="item.employment_id">
              <span class="font-medium">{{ itemLabel(item.employment_id) }}:</span> {{ item.message }}
            </li>
          </ul>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" data-test="bulk-closure-close" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </section>
    </div>
  </Modal>
</template>
