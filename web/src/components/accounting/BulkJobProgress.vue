<script setup lang="ts">
/**
 * Průběh hromadné účetní úlohy (přeúčtování, bankovní pohyby): stav, ukazatel,
 * počty, zastavení a protokol. Texty se berou z `{i18nPrefix}.status|kind|counts|…`.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BulkJobLike } from '@/composables/useBulkJobs'

const props = defineProps<{
  job: BulkJobLike
  i18nPrefix: string
  cancelling: boolean
}>()

defineEmits<{ (e: 'cancel'): void }>()

const { t } = useI18n()

const isRunning = computed(() => props.job.status === 'queued' || props.job.status === 'running')
const percent = computed(() => {
  const j = props.job
  if (!j.total_items) return null
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
})

function tr(key: string, params?: Record<string, unknown>): string {
  return params ? t(`${props.i18nPrefix}.${key}`, params) : t(`${props.i18nPrefix}.${key}`)
}
</script>

<template>
  <div class="rounded-lg border border-primary-200 bg-primary-50/50 px-4 py-4 mb-4 space-y-2">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="text-sm font-medium text-primary-700">
        {{ tr('status.' + job.status) }}
        <span class="ml-2 text-xs text-neutral-500">({{ tr('kind.' + job.kind) }})</span>
      </div>
      <button
        v-if="isRunning"
        type="button"
        class="cursor-pointer text-sm text-danger-500 hover:underline whitespace-nowrap"
        :disabled="cancelling"
        @click="$emit('cancel')"
      >
        {{ cancelling ? tr('cancelling') : tr('cancel') }}
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
      <span v-if="job.total_items">{{ job.processed }} / {{ job.total_items }}<span v-if="percent !== null"> ({{ percent }} %)</span></span>
      <span>{{ tr('counts', {
        changed: job.changed_count, skipped: job.skipped_count, failed: job.failed_count,
      }) }}</span>
    </div>

    <p class="text-xs text-neutral-500">{{ tr('background_hint') }}</p>

    <details v-if="job.log_text" class="text-xs">
      <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ tr('log') }}</summary>
      <pre class="mt-2 max-h-72 overflow-auto bg-neutral-900 text-neutral-100 rounded p-3 text-[11px] leading-relaxed">{{ job.log_text }}</pre>
    </details>
  </div>
</template>
