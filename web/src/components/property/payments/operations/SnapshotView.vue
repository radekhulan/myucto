<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { flattenSnapshot } from './format'
const props = defineProps<{ snapshot: Record<string, unknown> }>()
const { t } = useI18n()
const rows = computed(() => flattenSnapshot(props.snapshot, t('propertyPayments.unknown')))
</script>

<template>
  <details open class="space-y-1" data-section="snapshot">
    <summary class="cursor-pointer font-medium">{{ t('propertyPayments.snapshotTitle') }}</summary>
    <dl class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
      <template v-for="([key, value], index) in rows" :key="index"><dt class="break-words text-neutral-500">{{ key }}</dt><dd class="break-words">{{ value }}</dd></template>
    </dl>
  </details>
</template>
