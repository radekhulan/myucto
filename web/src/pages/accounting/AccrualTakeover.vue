<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accrualsApi, type AccrualTakeoverPreview } from '@/api/accruals'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accrualStatusClass } from '@/utils/accrualStatus'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const preview = ref<AccrualTakeoverPreview | null>(null)
const loading = ref(false)
const failed = ref(false)
const applying = ref(false)
const result = ref<{ created: number; updated: number } | null>(null)
const canWrite = computed(() => auth.canWrite('accounting.accruals.write'))

function errorText(error: any): string {
  return error?.response?.data?.error?.message || t('common.error')
}

async function load() {
  loading.value = true
  try {
    preview.value = await accrualsApi.takeoverPreview()
    failed.value = false
  } catch (error: any) {
    failed.value = true
    toast.error(errorText(error))
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!preview.value || applying.value || blockDemoMutation() || !window.confirm(t('accruals.takeover.confirm'))) return
  applying.value = true
  try {
    const applied = await accrualsApi.takeoverApply()
    result.value = { created: applied.created, updated: applied.updated }
    preview.value = applied
    toast.success(t('accruals.takeover.done', { created: applied.created, updated: applied.updated }))
  } catch (error: any) {
    toast.error(errorText(error))
  } finally {
    applying.value = false
  }
}

function kindLabel(value: string): string {
  const key = `accruals.kind.${value}`
  return t(key) === key ? value : t(key)
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <RouterLink :to="{ name: 'accounting-accruals' }" class="text-sm text-primary-700 hover:underline">← {{ t('accruals.back_to_list') }}</RouterLink>
        <h1 class="mt-1 text-2xl font-semibold">{{ t('accruals.takeover.title') }}</h1>
        <p class="mt-0.5 max-w-3xl text-sm text-neutral-500">{{ t('accruals.takeover.hint') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" :disabled="loading" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-test="takeover-reload" @click="load">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('accruals.takeover.reload') }}
        </button>
        <button v-if="canWrite" type="button" :disabled="applying || loading || !preview || !preview.items.length" data-test="takeover-apply"
          :class="[btnFilled('primary'), 'whitespace-nowrap']" @click="apply">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accruals.takeover.apply') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !preview" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="failed && !preview" boxed variant="failed" @action="load" />
    <EmptyState v-else-if="!preview || !preview.items.length" boxed icon="doc" :title="t('accruals.takeover.empty')" :message="t('accruals.takeover.empty_hint')" />
    <div v-else class="space-y-4">
      <p v-if="result" class="rounded-md border border-success-500/40 bg-success-50 px-3 py-2 text-sm text-success-700" role="status" data-test="takeover-result">
        {{ t('accruals.takeover.done', result) }}
        <RouterLink :to="{ name: 'accounting-accruals' }" class="ml-1 font-medium underline">{{ t('accruals.takeover.to_list') }}</RouterLink>
      </p>
      <p class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-600">{{ t('accruals.takeover.no_posting') }}</p>

      <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-test="takeover-summary">
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm">
          <div class="text-xs text-neutral-500">{{ t('accruals.takeover.summary_items') }}</div>
          <div class="text-lg font-semibold tabular-nums">{{ preview.summary.items }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm">
          <div class="text-xs text-neutral-500">{{ t('accruals.takeover.summary_new') }}</div>
          <div class="text-lg font-semibold tabular-nums">{{ preview.summary.new }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm">
          <div class="text-xs text-neutral-500">{{ t('accruals.takeover.summary_terminated') }}</div>
          <div class="text-lg font-semibold tabular-nums">{{ preview.summary.terminated }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm">
          <div class="text-xs text-neutral-500">{{ t('accruals.takeover.summary_unpaired') }}</div>
          <div class="text-lg font-semibold tabular-nums" :class="preview.summary.unpaired_sources ? 'text-warning-700' : ''">{{ preview.summary.unpaired_sources }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm">
          <div class="text-xs text-neutral-500">{{ t('accruals.takeover.summary_opening_remainder') }}</div>
          <div class="text-lg font-semibold tabular-nums">{{ formatMoney(preview.summary.opening_remainder) }}</div>
        </div>
      </section>

      <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
        <h2 class="border-b border-neutral-200 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.takeover.items') }}</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.item_no') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.title_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.source_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.period') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.basis_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.takeover.releases') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('accruals.status_label') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in preview.items" :key="row.import_key" data-test="takeover-item">
                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                  {{ row.item_no }}
                  <span class="ml-1 rounded px-1.5 py-0.5 font-sans" :class="row.existing_id ? 'bg-neutral-100 text-neutral-600' : 'bg-primary-50 text-primary-700'">{{ t(row.existing_id ? 'accruals.takeover.existing' : 'accruals.takeover.new') }}</span>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium">{{ row.title }}</div>
                  <div class="text-xs text-neutral-500">{{ kindLabel(row.kind) }}<span v-if="row.partner_name"> · {{ row.partner_name }}</span></div>
                  <div v-if="row.reconstructed || row.note" class="text-xs text-warning-700">{{ row.note || t('accruals.takeover.reconstructed') }}</div>
                </td>
                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ row.account_code }}<span v-if="row.counter_account_code" class="text-neutral-400"> / {{ row.counter_account_code }}</span></td>
                <td class="whitespace-nowrap px-3 py-2 text-xs">{{ t(`accruals.source.${row.source_type}`) }}<span v-if="row.source_document_no" class="ml-1 font-mono">{{ row.source_document_no }}</span></td>
                <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-600">{{ formatDate(row.period_from) }} – {{ formatDate(row.period_to) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.basis_amount) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right text-xs">{{ row.releases.length }}<span v-if="row.terminations.length" class="text-neutral-500"> + {{ t('accruals.takeover.terminations_count', row.terminations.length) }}</span></td>
                <td class="whitespace-nowrap px-3 py-2 text-center">
                  <span class="rounded px-2 py-0.5 text-xs" :class="accrualStatusClass(row.status)">{{ t(`accruals.status.${row.status}`) }}</span>
                  <div v-if="row.terminated_on" class="text-xs text-neutral-500">{{ formatDate(row.terminated_on) }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="preview.opening.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="takeover-opening">
        <h2 class="border-b border-neutral-200 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('accruals.takeover.opening', { date: preview.opening_date ? formatDate(preview.opening_date) : '—' }) }}
        </h2>
        <p class="px-4 pt-2 text-xs text-neutral-500">{{ t('accruals.takeover.opening_hint') }}</p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.account') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.takeover.opening_balance') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.takeover.allocated') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.takeover.remainder') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in preview.opening" :key="row.account_code">
                <td class="px-3 py-2 font-mono">{{ row.account_code }} <span class="font-sans text-xs text-neutral-500">· {{ t('accruals.reconciliation.items_count', row.items) }}</span></td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.opening_balance) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.allocated) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono" :class="Math.abs(row.remainder) >= 0.005 ? 'text-warning-700' : 'text-neutral-500'">{{ formatMoney(row.remainder) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="preview.terminations.length || preview.unmatched_terminations.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="takeover-terminations">
        <h2 class="border-b border-neutral-200 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.takeover.terminations') }}</h2>
        <ul class="divide-y divide-neutral-100 text-sm">
          <li v-for="row in preview.terminations" :key="`t-${row.entry_id}-${row.account_code}`" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
            <span>
              <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(row.entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ row.document_no || `#${row.entry_id}` }}</RouterLink>
              <span class="ml-2 text-xs text-neutral-500">{{ formatDate(row.date) }}</span>
              <span v-if="row.description" class="ml-2">{{ row.description }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ Object.keys(row.allocations).join(', ') }}</span>
            </span>
            <span class="font-mono">{{ formatMoney(row.amount) }}</span>
          </li>
          <li v-for="row in preview.unmatched_terminations" :key="`u-${row.entry_id}-${row.account_code}`" class="flex flex-wrap items-center justify-between gap-2 bg-warning-50/50 px-4 py-2" data-test="takeover-unmatched">
            <span>
              <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(row.entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ row.document_no || `#${row.entry_id}` }}</RouterLink>
              <span class="ml-2 text-xs text-neutral-500">{{ formatDate(row.date) }}</span>
              <span v-if="row.description" class="ml-2">{{ row.description }}</span>
              <span class="ml-2 text-xs text-warning-700">{{ t('accruals.takeover.unmatched') }}</span>
            </span>
            <span class="font-mono">{{ formatMoney(row.amount) }}</span>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
