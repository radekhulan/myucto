<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{
  deadline: string
  /** Rozhodný čas evidovaného podání v řádné lhůtě; bez něj karta odpočítává lhůtu. */
  filedAt?: string | null
}>()

const { t } = useI18n()

function dayNumber(value: string): number | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) / 86_400_000 : null
}

function todayNumber(): number {
  const d = new Date()
  return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 86_400_000
}

const deadlineDay = computed(() => dayNumber(props.deadline))

const filedLateDays = computed(() => {
  if (!props.filedAt || deadlineDay.value === null) return null
  const filedDay = dayNumber(props.filedAt)
  return filedDay === null ? null : filedDay - deadlineDay.value
})

const daysToDeadline = computed(() => deadlineDay.value === null ? null : deadlineDay.value - todayNumber())

const tone = computed(() => {
  if (props.filedAt) return (filedLateDays.value ?? 0) > 0 ? 'text-warning-600' : 'text-success-600'
  const days = daysToDeadline.value ?? 999
  return days < 0 ? 'text-danger-500' : days <= 7 ? 'text-warning-600' : null
})
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
    <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
    <div class="text-xl font-bold font-mono" :class="tone ?? 'text-neutral-900'">
      {{ deadline }}
    </div>
    <div class="text-xs mt-1" :class="tone ?? 'text-neutral-500'">
      <template v-if="filedAt">
        <template v-if="(filedLateDays ?? 0) > 0">{{ t('reports.dph.filed_late', { date: formatDate(filedAt), n: filedLateDays }) }}</template>
        <template v-else>{{ t('reports.dph.filed_on', { date: formatDate(filedAt) }) }}</template>
      </template>
      <template v-else-if="daysToDeadline !== null && daysToDeadline >= 0">{{ t('reports.dph.deadline_in', { n: daysToDeadline }) }}</template>
      <template v-else-if="daysToDeadline !== null">{{ t('reports.dph.deadline_passed', { n: Math.abs(daysToDeadline) }) }}</template>
    </div>
  </div>
</template>
