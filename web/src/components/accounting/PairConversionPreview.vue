<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { accountingApi, type PairConversionRun } from '@/api/accounting'
import { apiErrorText } from '@/utils/entryForm'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'

/**
 * Náhled převodu starších zápisů na dvojice nanečisto.
 *
 * Spustí běh `preview` na serveru (nic se nezapisuje), čeká na něj pollingem a ukáže,
 * kolik zápisů se rozdělí automaticky (1 : N), kolik zůstane složených k ruční úpravě
 * (M : N) a kolik se nezmění vůbec (uzavřené období, vazby na saldo, mzdy). Id běhu
 * jde ven (`ready`), změna formy ho přiloží do auditního logu.
 */
const props = defineProps<{
  dateFrom: string
  dateTo?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  ready: [runId: number]
  reset: []
}>()

const { t } = useI18n()

const POLL_MS = 1500
const run = ref<PairConversionRun | null>(null)
const starting = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | null = null
let disposed = false

const running = computed(() => starting.value || run.value?.status === 'queued' || run.value?.status === 'running')
const counts = computed(() => run.value?.counts ?? null)
const reasons = computed(() => Object.entries(counts.value?.reasons ?? {}).filter(([, n]) => Number(n) > 0))
const sample = computed(() => run.value?.sample ?? [])

function stopPolling() {
  if (timer) { clearTimeout(timer); timer = null }
}
onBeforeUnmount(() => { disposed = true; stopPolling() })

async function poll(id: number) {
  if (disposed) return
  try {
    const next = await accountingApi.getPairConversion(id)
    if (disposed) return
    run.value = next
    if (next.status === 'done') {
      emit('ready', next.id)
      return
    }
    if (next.status === 'failed' || next.status === 'cancelled') {
      error.value = next.status === 'failed'
        ? (next.error || t('accounting.pairs.conversion.failed'))
        : t('accounting.pairs.conversion.cancelled')
      return
    }
  } catch (e) {
    error.value = apiErrorText(e, t)
    return
  }
  timer = setTimeout(() => { void poll(id) }, POLL_MS)
}

async function start() {
  stopPolling()
  error.value = ''
  run.value = null
  emit('reset')
  starting.value = true
  try {
    const id = await accountingApi.previewPairConversion({
      date_from: props.dateFrom || undefined,
      date_to: props.dateTo || undefined,
    })
    starting.value = false
    await poll(id)
  } catch (e) {
    starting.value = false
    error.value = apiErrorText(e, t)
  }
}

async function cancel() {
  if (!run.value) return
  stopPolling()
  try { await accountingApi.cancelPairConversion(run.value.id) } catch { /* běh mezitím skončil */ }
  error.value = t('accounting.pairs.conversion.cancelled')
  run.value = null
}

function reasonLabel(reason: string): string {
  const key = `accounting.pairs.conversion.reason.${reason}`
  const v = t(key)
  return v === key ? reason : v
}

defineExpose({ start, run })
</script>

<template>
  <div class="rounded-lg border border-neutral-200 p-3 space-y-3" data-test="pair-conversion-preview">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0 flex-1">
        <h4 class="text-sm font-semibold">{{ t('accounting.pairs.conversion.title') }}</h4>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('accounting.pairs.conversion.hint') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="running && run" type="button" :class="btnOutline('neutral')" @click="cancel">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          <span class="whitespace-nowrap">{{ t('common.cancel') }}</span>
        </button>
        <button type="button" :class="btnOutline('primary')" :disabled="disabled || running || !dateFrom" data-test="pair-conversion-start" @click="start">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
          <span class="whitespace-nowrap">{{ running ? t('accounting.pairs.conversion.running') : (run ? t('accounting.pairs.conversion.rerun') : t('accounting.pairs.conversion.start')) }}</span>
        </button>
      </div>
    </div>

    <div v-if="error" class="text-sm text-danger-500" role="alert" data-test="pair-conversion-error">{{ error }}</div>

    <div v-if="run?.status === 'done' && counts" class="space-y-2" data-test="pair-conversion-result">
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
        <div class="flex justify-between gap-3">
          <dt class="text-neutral-600">{{ t('accounting.pairs.conversion.auto') }}</dt>
          <dd class="font-mono font-medium text-success-600" data-test="count-auto">{{ counts.auto ?? 0 }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-neutral-600">{{ t('accounting.pairs.conversion.derived') }}</dt>
          <dd class="font-mono font-medium" data-test="count-derived">{{ counts.derived ?? 0 }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-neutral-600">{{ t('accounting.pairs.conversion.composite_mn') }}</dt>
          <dd class="font-mono font-medium text-warning-600" data-test="count-mn">{{ counts.composite_mn ?? 0 }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-neutral-600">{{ t('accounting.pairs.conversion.ineligible') }}</dt>
          <dd class="font-mono font-medium" data-test="count-ineligible">{{ counts.ineligible ?? 0 }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-neutral-600">{{ t('accounting.pairs.conversion.already_paired') }}</dt>
          <dd class="font-mono font-medium">{{ counts.already_paired ?? counts.paired ?? 0 }}</dd>
        </div>
      </dl>
      <ul v-if="reasons.length" class="text-xs text-neutral-500 space-y-0.5">
        <li v-for="[reason, n] in reasons" :key="reason">{{ reasonLabel(reason) }}: <span class="font-mono">{{ n }}</span></li>
      </ul>
      <div v-if="sample.length" class="text-xs">
        <div class="font-medium text-neutral-600 mb-1">{{ t('accounting.pairs.conversion.sample') }}</div>
        <ul class="flex flex-wrap gap-x-3 gap-y-1">
          <li v-for="s in sample.slice(0, 20)" :key="s.entry_id">
            <RouterLink :to="{ path: '/accounting/journal', query: { entry_id: String(s.entry_id) } }"
              class="text-primary-700 hover:underline whitespace-nowrap">
              {{ s.document_no || `#${s.entry_id}` }}<template v-if="s.entry_date"> ({{ formatDate(s.entry_date) }})</template>
            </RouterLink>
            <span v-if="s.reason" class="ml-1 text-neutral-400">{{ reasonLabel(s.reason) }}</span>
          </li>
        </ul>
      </div>
      <p class="text-xs text-neutral-500">{{ t('accounting.pairs.conversion.nothing_written') }}</p>
    </div>
  </div>
</template>
