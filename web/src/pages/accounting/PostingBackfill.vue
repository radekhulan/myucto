<script setup lang="ts">
/**
 * Doúčtování nezaúčtovaných dokladů.
 *
 * Existuje proto, že automatika účtování je **háček na vznik dokladu** (vystavení,
 * přijetí, opakovaná fakturace), ne zametač existujících. Doklad, který už v systému
 * leží — typicky naimportovaný z jiného systému — jí neprojde nikdy, ať je zapnutá
 * jakkoli. Hromadné zaúčtování ze seznamu má strop 500 dokladů a jede z označených
 * řádků, takže po migraci historie s tisíci doklady taky není řešení.
 *
 * Účtuje se týmž kódem, jakým doúčtovává průvodce aktivací; tahle stránka mu jen
 * dává vlastní vchod a průběh.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  postingBackfillApi,
  type PostingBackfillJob,
  type PostingBackfillStatus,
} from '@/api/postingBackfill'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const state = ref<PostingBackfillStatus | null>(null)
const running = ref<PostingBackfillJob | null>(null)
const loading = ref(true)
const starting = ref(false)
const cancelling = ref(false)
const error = ref('')

let timer: ReturnType<typeof setTimeout> | null = null

const canPost = computed(() => auth.canWrite('accounting.journal.post'))
const pending = computed(() => state.value?.pending ?? null)
const pendingDocuments = computed(() =>
  (pending.value?.invoices ?? 0) + (pending.value?.purchase_invoices ?? 0))
const percent = computed(() => {
  const j = running.value
  if (!j || !j.total_items) return null
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
})

function stopPolling() {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    state.value = await postingBackfillApi.status()
    const active = state.value.jobs.find(j => j.status === 'queued' || j.status === 'running')
    if (active) {
      running.value = active
      poll(active.id)
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function poll(jobId: number) {
  // Úloha běží dál i bez nás, takže výpadek jednoho dotazu není důvod přestat se ptát.
  let failures = 0
  const tick = async () => {
    try {
      const j = await postingBackfillApi.job(jobId)
      failures = 0
      running.value = j
      if (j.status === 'queued' || j.status === 'running') {
        timer = setTimeout(tick, 1500)
        return
      }
      timer = null
      cancelling.value = false
      if (j.status === 'failed') {
        toast.error(j.last_error || t('accounting.posting_backfill.failed'))
      } else if (j.status === 'cancelled') {
        toast.warning(t('accounting.posting_backfill.cancelled'))
      } else {
        toast.success(t('accounting.posting_backfill.done', { n: j.posted_count }))
      }
      state.value = await postingBackfillApi.status()
    } catch (e) {
      failures++
      if (failures < 5) {
        timer = setTimeout(tick, 3000)
      } else {
        timer = null
        error.value = apiErrorMessage(e)
      }
    }
  }
  timer = null
  void tick()
}

async function start(dryRun: boolean) {
  starting.value = true
  error.value = ''
  try {
    const { job_id } = await postingBackfillApi.start({ dry_run: dryRun })
    running.value = null
    poll(job_id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    starting.value = false
  }
}

async function cancel() {
  const id = running.value?.id
  if (!id || cancelling.value) return
  cancelling.value = true
  try {
    await postingBackfillApi.cancel(id)
  } catch (e) {
    cancelling.value = false
    toast.error(apiErrorMessage(e))
  }
}

const isRunning = computed(() =>
  running.value?.status === 'queued' || running.value?.status === 'running')

const actions = computed<ActionItem[]>(() => [
  {
    key: 'post', label: t('accounting.posting_backfill.action_start'), icon: 'check',
    tier: 'primary', variant: 'primary',
    show: canPost.value,
    disabled: loading.value || starting.value || isRunning.value || pendingDocuments.value === 0,
    loading: starting.value,
    title: t('accounting.posting_backfill.action_start_hint'),
    run: () => start(false),
  },
  {
    key: 'dry', label: t('accounting.posting_backfill.action_dry'), icon: 'search',
    tier: 'secondary', variant: 'neutral',
    show: canPost.value,
    disabled: loading.value || starting.value || isRunning.value || pendingDocuments.value === 0,
    title: t('accounting.posting_backfill.action_dry_hint'),
    run: () => start(true),
  },
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'overflow', variant: 'neutral',
    show: true, disabled: loading.value, loading: loading.value, run: load,
  },
])

function fmtDateTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function statusLabel(status: PostingBackfillJob['status']): string {
  return t('accounting.posting_backfill.status.' + status)
}

onMounted(load)
onUnmounted(stopPolling)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.posting_backfill.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.posting_backfill.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.posting_backfill.explainer_title') }}</p>
      <p>{{ t('accounting.posting_backfill.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <template v-else>
      <!-- Kolik čeká -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
        <h2 class="font-medium text-neutral-900 mb-3">{{ t('accounting.posting_backfill.pending_title') }}</h2>
        <div v-if="pending" class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <div class="text-2xl font-semibold" :class="pending.invoices > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ pending.invoices }}</div>
            <div class="text-neutral-500">{{ t('accounting.posting_backfill.pending_invoices') }}</div>
          </div>
          <div>
            <div class="text-2xl font-semibold" :class="pending.purchase_invoices > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ pending.purchase_invoices }}</div>
            <div class="text-neutral-500">{{ t('accounting.posting_backfill.pending_purchases') }}</div>
          </div>
          <div>
            <div class="text-2xl font-semibold" :class="pending.cash_documents > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ pending.cash_documents }}</div>
            <div class="text-neutral-500">{{ t('accounting.posting_backfill.pending_cash') }}</div>
          </div>
          <div>
            <div class="text-2xl font-semibold" :class="pending.bank_transactions > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ pending.bank_transactions }}</div>
            <div class="text-neutral-500">{{ t('accounting.posting_backfill.pending_bank') }}</div>
          </div>
          <div>
            <div class="text-2xl font-semibold" :class="pending.settlements > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ pending.settlements }}</div>
            <div class="text-neutral-500">{{ t('accounting.posting_backfill.pending_settlements') }}</div>
          </div>
        </div>
        <p v-if="pendingDocuments === 0" class="mt-3 text-sm text-success-600">
          {{ t('accounting.posting_backfill.nothing_pending') }}
        </p>
        <p v-else class="mt-3 text-xs text-neutral-500">
          {{ t('accounting.posting_backfill.scope_hint') }}
        </p>
      </div>

      <!-- Průběh -->
      <div v-if="running" class="rounded-lg border border-primary-200 bg-primary-50/50 px-4 py-4 mb-4 space-y-2">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="text-sm font-medium text-primary-700">
            {{ running.current_step || statusLabel(running.status) }}
            <span v-if="running.dry_run" class="ml-2 text-xs text-neutral-500">({{ t('accounting.posting_backfill.dry_run_badge') }})</span>
          </div>
          <button
            v-if="isRunning"
            type="button"
            class="cursor-pointer text-sm text-danger-500 hover:underline whitespace-nowrap"
            :disabled="cancelling"
            @click="cancel"
          >
            {{ cancelling ? t('accounting.posting_backfill.cancelling') : t('accounting.posting_backfill.cancel') }}
          </button>
        </div>

        <div class="h-2 rounded-full bg-primary-100 overflow-hidden">
          <div
            class="h-full bg-primary-500 transition-all duration-300"
            :class="percent === null ? 'animate-pulse w-1/3' : ''"
            :style="percent === null ? undefined : { width: percent + '%' }"
          ></div>
        </div>

        <div class="flex justify-between text-xs text-neutral-600 flex-wrap gap-x-4">
          <span v-if="running.total_items">{{ running.processed }} / {{ running.total_items }}<span v-if="percent !== null"> ({{ percent }} %)</span></span>
          <span>{{ t('accounting.posting_backfill.counts', {
            posted: running.posted_count, skipped: running.skipped_count, failed: running.failed_count,
          }) }}</span>
        </div>

        <p class="text-xs text-neutral-500">{{ t('accounting.posting_backfill.background_hint') }}</p>

        <details v-if="running.log_text" class="text-xs">
          <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('accounting.posting_backfill.log') }}</summary>
          <pre class="mt-2 max-h-72 overflow-auto bg-neutral-900 text-neutral-100 rounded p-3 text-[11px] leading-relaxed">{{ running.log_text }}</pre>
        </details>
      </div>

      <!-- Historie -->
      <div v-if="state && state.jobs.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <h2 class="font-medium text-neutral-900 mb-3">{{ t('accounting.posting_backfill.history_title') }}</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                <th class="py-2 pr-3">{{ t('accounting.posting_backfill.col_started') }}</th>
                <th class="py-2 pr-3">{{ t('accounting.posting_backfill.col_status') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('accounting.posting_backfill.col_posted') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('accounting.posting_backfill.col_skipped') }}</th>
                <th class="py-2 text-right">{{ t('accounting.posting_backfill.col_failed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="j in state.jobs" :key="j.id" class="border-b border-neutral-50 last:border-0">
                <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDateTime(j.created_at) }}</td>
                <td class="py-2 pr-3">
                  {{ statusLabel(j.status) }}
                  <span v-if="j.dry_run" class="ml-1 text-xs text-neutral-400">({{ t('accounting.posting_backfill.dry_run_badge') }})</span>
                </td>
                <td class="py-2 pr-3 text-right">{{ j.posted_count }}</td>
                <td class="py-2 pr-3 text-right">{{ j.skipped_count }}</td>
                <td class="py-2 text-right" :class="j.failed_count > 0 ? 'text-danger-500 font-medium' : ''">{{ j.failed_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
