<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CatalogJob } from '@/api/catalogJobs'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'

const props = withDefaults(defineProps<{
  job: CatalogJob | null
  cancelling?: boolean
  canCancel?: boolean
}>(), {
  cancelling: false,
  canCancel: false,
})

defineEmits<{ (e: 'cancel'): void }>()

const { t } = useI18n()

const percent = computed(() => {
  if (!props.job?.total || props.job.total <= 0) return null
  return Math.min(100, Math.round(Number(props.job.checkpoint ?? 0) / props.job.total * 100))
})

const progressJob = computed(() => {
  if (!props.job) return null
  const failedItems = Array.isArray(props.job.report?.failed_items) ? props.job.report.failed_items : []
  const counts = props.job.report?.counts as Record<string, number> | undefined
  return {
    id: props.job.id,
    status: props.job.status,
    total_items: props.job.total ?? 0,
    processed: props.job.checkpoint ?? 0,
    created_count: counts ? Number(counts.ready ?? 0) + Number(counts.applied ?? 0) : Number(props.job.report?.processed ?? props.job.checkpoint ?? 0),
    skipped_count: counts ? Number(counts.unchanged ?? 0) + Number(counts.skipped ?? 0) : 0,
    failed_count: (counts ? Number(counts.failed ?? 0) + Number(counts.conflict ?? 0) : failedItems.length) + (props.job.status === 'failed' ? 1 : 0),
    current_step: t(`eshop.jobs.status.${props.job.status}`),
  }
})
</script>

<template>
  <div v-if="job">
    <ImportJobProgress
        :job="progressJob"
        :percent="percent"
        :cancelling="cancelling"
        :show-cancel="canCancel && !job?.cancel_requested"
        counts-key="eshop.jobs.job_counts"
        background-hint-key="eshop.jobs.job_background_hint"
        running-key="eshop.jobs.job_running"
        cancel-key="eshop.jobs.job_cancel"
        cancelling-key="eshop.jobs.job_cancelling"
        @cancel="$emit('cancel')"
      />
      <p v-if="job?.cancel_requested" class="mt-2 text-xs text-warning-700">{{ t('eshop.jobs.cancel_requested') }}</p>
      <p v-if="job" class="mt-2 text-xs text-neutral-500">{{ t('eshop.jobs.cancel_boundary') }}</p>
  </div>
</template>
