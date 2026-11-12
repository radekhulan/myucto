<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollPayoutDefaultsBulkResult,
  type PayrollPayoutDefaultsPreviewRow,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

// `null` = osoby bez výplatního pravidla určí server (nabídka v Zaměstnancích).
const props = defineProps<{
  employeeIds: number[] | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollPayoutDefaultsBulkResult): void
}>()

const { t, te } = useI18n()
const toast = useToast()

const rows = ref<PayrollPayoutDefaultsPreviewRow[]>([])
const truncated = ref(false)
const loading = ref(false)
const loadError = ref('')
const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollPayoutDefaultsBulkResult | null>(null)

const applicable = computed(() => rows.value.filter(row => row.applicable))
const blocked = computed(() => rows.value.filter(row => !row.applicable))
const byKind = computed(() => {
  const counts = new Map<string, number>()
  for (const row of applicable.value) {
    const kind = row.destination_kind ?? 'other'
    counts.set(kind, (counts.get(kind) ?? 0) + 1)
  }
  return [...counts].map(([kind, count]) => ({ kind, count }))
})
const applyBlockedReason = computed(() => (applicable.value.length === 0 ? t('payroll.payout_defaults_bulk.nothing_to_apply') : ''))

function kindLabel(kind: string): string {
  const key = `payroll.payout_defaults_bulk.kind.${kind}`
  return te(key) ? t(key) : kind
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const preview = await payrollApi.payoutDefaultsPreview(props.employeeIds)
    rows.value = preview.people
    truncated.value = preview.truncated
  } catch (error) {
    rows.value = []
    truncated.value = false
    loadError.value = apiErrorMessage(error, t('payroll.payout_defaults_bulk.load_failed'))
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (applicable.value.length === 0 || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.payoutDefaultsApply(applicable.value.map(row => row.employee_id))
    result.value = response
    toast.success(t('payroll.payout_defaults_bulk.applied_toast', { count: response.applied.length }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.payout_defaults_bulk.apply_failed'))
  } finally {
    applying.value = false
  }
}

onMounted(load)
</script>

<template>
  <Modal :title="t('payroll.payout_defaults_bulk.title')" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-4" data-test="payout-defaults-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.payout_defaults_bulk.intro') }}</p>

      <div v-if="loading" class="space-y-2">
        <div v-for="index in 3" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutlineSm('danger')" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.payout_defaults_bulk.retry') }}
        </button>
      </div>

      <p
        v-else-if="!result && employeeIds === null && rows.length === 0"
        class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-600"
        data-test="payout-defaults-none"
      >
        {{ t('payroll.payout_defaults_bulk.none_missing') }}
      </p>

      <template v-else-if="!result">
        <p v-if="truncated" class="text-xs text-neutral-500" data-test="payout-defaults-truncated">
          {{ t('payroll.payout_defaults_bulk.truncated', { count: rows.length }) }}
        </p>
        <div class="grid grid-cols-2 gap-3">
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.payout_defaults_bulk.ready') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="payout-defaults-ready">{{ applicable.length }}</p>
          </article>
          <article class="rounded-lg p-3" :class="blocked.length ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="blocked.length ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.payout_defaults_bulk.blocked') }}</p>
            <p class="mt-1 text-lg font-semibold" :class="blocked.length ? 'text-warning-700' : ''" data-test="payout-defaults-blocked">{{ blocked.length }}</p>
          </article>
        </div>
        <ul v-if="byKind.length" class="space-y-1 text-sm text-neutral-700" data-test="payout-defaults-kinds">
          <li v-for="item in byKind" :key="item.kind">{{ kindLabel(item.kind) }}: {{ item.count }}</li>
        </ul>
        <details
          v-if="blocked.length"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="payout-defaults-blocked-list"
        >
          <summary class="cursor-pointer font-medium">{{ t('payroll.payout_defaults_bulk.blocked_title', { count: blocked.length }) }}</summary>
          <ul class="mt-2 max-h-56 space-y-1 overflow-y-auto text-xs">
            <li v-for="row in blocked" :key="row.employee_id">
              <span class="font-medium">{{ row.full_name }}:</span> {{ row.blocked_reason }}
            </li>
          </ul>
        </details>
        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">{{ applyError }}</p>
      </template>

      <div v-else class="space-y-2 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700" data-test="payout-defaults-result">
        <p class="font-medium">{{ t('payroll.payout_defaults_bulk.result', { applied: result.applied.length, skipped: result.skipped.length }) }}</p>
        <ul v-if="result.skipped.length" class="space-y-1 text-xs text-warning-800">
          <li v-for="row in result.skipped" :key="row.employee_id">
            <span class="font-medium">{{ row.full_name }}:</span> {{ row.reason }}
          </li>
        </ul>
      </div>
    </div>

    <template #footer>
      <p v-if="!result && !loading && applyBlockedReason" :class="[BTN_DISABLED_NOTE, 'mb-2 text-right']">{{ applyBlockedReason }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
        <button
          v-if="!result"
          type="button"
          :class="btnFilled('primary')"
          class="whitespace-nowrap"
          :disabled="loading || applying || applyBlockedReason !== ''"
          :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)"
          data-test="payout-defaults-apply"
          @click="apply"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('payroll.payout_defaults_bulk.apply', { count: applicable.length }) }}
        </button>
      </div>
    </template>
  </Modal>
</template>
