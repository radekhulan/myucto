<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ICONS } from '@/components/ui/buttonStyles'
import type { AiManualSource, AiToolCall } from '@/api/aiAssistant'

// Zdroje odpovědi AI asistenta: citace manuálu jako odkazy a rozbalitelný seznam
// nástrojů, které asistent použil (bez obsahu výsledků, ten zůstává jen na serveru).
const props = defineProps<{
  toolCalls?: AiToolCall[] | null
  manualSources?: AiManualSource[] | null
}>()

const { t } = useI18n()
const calls = computed(() => props.toolCalls ?? [])
const sources = computed(() => props.manualSources ?? [])
const errors = computed(() => calls.value.filter(c => c.status !== 'ok').length)

function icon(kind: string): string {
  if (kind === 'manual') return ICONS.help
  if (kind === 'data_describe') return ICONS.search
  return ICONS.table
}
</script>

<template>
  <div v-if="sources.length || calls.length" class="space-y-2" data-test="ai-tool-trace">
    <div v-if="sources.length">
      <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('accounting.ai_assistant.sources.manual') }}</h4>
      <ul class="flex flex-wrap gap-1.5">
        <li v-for="s in sources" :key="s.section_id">
          <a
            :href="s.url"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 text-xs hover:bg-primary-100 max-w-full"
            :title="s.chapter"
            data-test="ai-manual-source"
          >
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" /></svg>
            <span class="truncate">{{ s.title }}</span>
          </a>
        </li>
      </ul>
    </div>

    <details v-if="calls.length" class="group border border-neutral-200 rounded-lg bg-neutral-50" data-test="ai-tool-calls">
      <summary class="cursor-pointer select-none list-none flex flex-wrap items-center gap-2 px-3 py-1.5 text-xs text-neutral-600">
        <svg class="w-3.5 h-3.5 text-neutral-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
        <span class="font-medium">{{ t('accounting.ai_assistant.sources.tools', { count: calls.length }) }}</span>
        <span v-if="errors" class="text-warning-700">{{ t('accounting.ai_assistant.sources.errors', { count: errors }) }}</span>
      </summary>
      <ul class="border-t border-neutral-200 divide-y divide-neutral-100">
        <li v-for="(c, i) in calls" :key="i" class="flex items-start gap-2 px-3 py-1.5 text-xs">
          <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="icon(c.kind)" /></svg>
          <span class="min-w-0 flex-1">
            <span class="text-neutral-800">{{ c.label }}</span>
            <span v-if="c.detail" class="block text-neutral-500 font-mono break-all">{{ c.detail }}</span>
          </span>
          <span
            class="shrink-0 px-1.5 rounded text-[11px] whitespace-nowrap"
            :class="c.status === 'ok' ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'"
          >{{ c.status === 'ok' ? t('accounting.ai_assistant.sources.ok') : t('accounting.ai_assistant.sources.failed') }}</span>
        </li>
      </ul>
    </details>
  </div>
</template>
