<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { FileImportJob } from '@/api/imports'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

type ProgressJob = Pick<FileImportJob,
  'id' | 'status' | 'total_items' | 'processed' | 'created_count' |
  'skipped_count' | 'failed_count' | 'current_step'>

withDefaults(defineProps<{
  job: ProgressJob | null
  percent: number | null
  cancelling: boolean
  showCancel?: boolean
  countsKey?: string
  backgroundHintKey?: string
  runningKey?: string
  cancelKey?: string
  cancellingKey?: string
}>(), {
  showCancel: true,
  countsKey: 'imports.job_counts',
  backgroundHintKey: 'imports.job_background_hint',
  runningKey: 'imports.job_running',
  cancelKey: 'imports.job_cancel',
  cancellingKey: 'imports.job_cancelling',
})

defineEmits<{ (e: 'cancel'): void }>()

const { t } = useI18n()
</script>

<template>
  <div v-if="job" class="rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3 space-y-2">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="text-sm font-medium text-primary-700">
        {{ job.current_step || t(runningKey) }}
      </div>
      <button
        v-if="showCancel && (job.status === 'queued' || job.status === 'running')"
        @click="$emit('cancel')"
        :disabled="cancelling"
        :class="btnOutline('danger')"
        class="whitespace-nowrap"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/></svg>
        {{ cancelling ? t(cancellingKey) : t(cancelKey) }}
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
      <span>{{ t(countsKey, { created: job.created_count, changed: job.created_count, skipped: job.skipped_count, failed: job.failed_count }) }}</span>
    </div>

    <p class="text-xs text-neutral-500">{{ t(backgroundHintKey) }}</p>
  </div>
</template>
