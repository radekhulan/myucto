<script setup lang="ts">
/** Záložky stavů položek ve výsledku hromadné účetní úlohy. */
import { useI18n } from 'vue-i18n'
import type { JournalRebookItemStatus } from '@/api/journalRebook'

defineProps<{
  tabs: JournalRebookItemStatus[]
  active: JournalRebookItemStatus
  counts: Partial<Record<JournalRebookItemStatus, number>>
  i18nPrefix: string
  label: string
}>()

defineEmits<{ (e: 'select', tab: JournalRebookItemStatus): void }>()

const { t } = useI18n()
</script>

<template>
  <div class="overflow-x-auto border-b border-neutral-200" role="tablist" :aria-label="label">
    <div class="flex min-w-max gap-1">
      <button v-for="tab in tabs" :key="tab" type="button" role="tab"
        :aria-selected="active === tab" class="cursor-pointer whitespace-nowrap border-b-2 px-3 py-2 text-sm transition"
        :class="active === tab ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        @click="$emit('select', tab)">
        {{ t(i18nPrefix + '.tab.' + tab) }} ({{ counts[tab] ?? 0 }})
      </button>
    </div>
  </div>
</template>
