<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import {
  filterStereoNxMessageGroup, groupStereoNxMessages, stereoNxMessageDocument, stereoNxMessageGroupTitle, stereoNxMessageOccurrences,
  type StereoNxMessageGroup, type StereoNxMessageGroupMatch, type StereoNxMessageLevel, type StereoNxProtocolMessage,
} from './stereoNxMessageGroups'

const props = defineProps<{ messages: StereoNxProtocolMessage[] }>()
const { t, te } = useI18n()
const query = ref('')
const severity = ref<StereoNxMessageLevel | 'all'>('all')
const expanded = ref<Record<string, boolean>>({})
const limits = ref<Record<string, number>>({})
const searchId = `stereo-message-search-${useId()}`
const PAGE_SIZE = 50
const groups = computed(() => groupStereoNxMessages(props.messages))
const totals = computed(() => {
  const counts = { all: 0, error: 0, warning: 0, info: 0 }
  for (const group of groups.value) { counts[group.level] += group.count; counts.all += group.count }
  return counts
})
const filters = computed(() => [
  { key: 'all' as const, label: t('stereo_nx.messages.all'), count: totals.value.all, variant: 'neutral' as const, icon: ICONS.table },
  { key: 'error' as const, label: t('stereo_nx.messages.levels.error'), count: totals.value.error, variant: 'danger' as const, icon: ICONS.x },
  { key: 'warning' as const, label: t('stereo_nx.messages.levels.warning'), count: totals.value.warning, variant: 'warning' as const, icon: ICONS.bell },
  { key: 'info' as const, label: t('stereo_nx.messages.levels.info'), count: totals.value.info, variant: 'primary' as const, icon: ICONS.doc },
])
const matches = computed(() => groups.value.flatMap(group => {
  if (severity.value !== 'all' && group.level !== severity.value) return []
  const match = filterStereoNxMessageGroup(group, query.value, title(group))
  return match ? [match] : []
}))
watch([query, severity], () => { limits.value = {} })
watch(() => props.messages, () => { expanded.value = {}; limits.value = {} })

function isOpen(group: StereoNxMessageGroup): boolean {
  return expanded.value[group.key] ?? group.level === 'error'
}
function toggle(group: StereoNxMessageGroup, event: Event): void {
  expanded.value = { ...expanded.value, [group.key]: (event.target as HTMLDetailsElement).open }
}
function title(group: StereoNxMessageGroup): string {
  return stereoNxMessageGroupTitle(group, key => t(key), key => te(key))
}
function levelClass(level: StereoNxMessageLevel): string {
  return level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-700'
    : level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700'
    : 'border-primary-500/30 bg-primary-50 text-primary-700'
}
function shown(match: StereoNxMessageGroupMatch): StereoNxProtocolMessage[] {
  return match.details.slice(0, limits.value[match.group.key] ?? PAGE_SIZE)
}
function loadMore(match: StereoNxMessageGroupMatch): void {
  limits.value = { ...limits.value, [match.group.key]: Math.min(match.details.length, (limits.value[match.group.key] ?? PAGE_SIZE) + PAGE_SIZE) }
}
function kind(message: StereoNxProtocolMessage): string {
  const code = message.context.kind
  return typeof code === 'string' && te(`stereo_nx.document_kind.${code}`) ? t(`stereo_nx.document_kind.${code}`) : ''
}
</script>

<template>
  <section class="space-y-3" data-testid="stereo-messages">
    <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stereo_nx.messages.title') }}</h4>
    <template v-if="groups.length">
      <div class="flex flex-wrap gap-2" role="group" :aria-label="t('stereo_nx.messages.filter_label')">
        <button v-for="filter in filters" :key="filter.key" type="button" :class="[btnOutline(filter.variant), severity === filter.key ? 'ring-2 ring-current/20' : '']" class="whitespace-nowrap" :data-testid="`stereo-messages-filter-${filter.key}`" :aria-pressed="severity === filter.key" @click="severity = filter.key"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="filter.icon" /></svg>{{ filter.label }}<span class="rounded-full bg-current/10 px-2 py-0.5 text-xs tabular-nums" :data-testid="`stereo-messages-count-${filter.key}`">{{ filter.count }}</span></button>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label :for="searchId" class="min-w-0 flex-1 text-sm font-medium">{{ t('stereo_nx.messages.search_label') }}<input :id="searchId" v-model="query" type="search" data-testid="stereo-messages-search" :placeholder="t('stereo_nx.messages.search_placeholder')" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm font-normal" /></label>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="!query" @click="query = ''"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('stereo_nx.messages.clear_search') }}</button>
      </div>
      <p v-if="!matches.length" class="rounded-md border border-neutral-200 px-4 py-3 text-sm text-neutral-600" role="status">{{ t('stereo_nx.messages.no_results') }}</p>
      <details v-for="match in matches" :key="match.group.key" :open="isOpen(match.group)" :data-message-code="match.group.code" :data-message-level="match.group.level" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface" @toggle="toggle(match.group, $event)">
        <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 text-sm font-medium [&::-webkit-details-marker]:hidden" :class="levelClass(match.group.level)">
          <svg class="h-4 w-4 shrink-0 transition-transform" :class="isOpen(match.group) ? '' : '-rotate-90'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          <span class="shrink-0 whitespace-nowrap rounded-full bg-surface/70 px-2 py-0.5 text-xs" data-testid="stereo-message-group-level">{{ t(`stereo_nx.level.${match.group.level}`) }}</span>
          <span class="min-w-0 flex-1 truncate" :title="title(match.group)">{{ title(match.group) }}</span>
          <span class="shrink-0 whitespace-nowrap rounded-full bg-surface/70 px-2 py-0.5 text-xs tabular-nums" data-testid="stereo-message-group-count">{{ match.group.count }}</span>
        </summary>
        <div v-if="isOpen(match.group)" class="space-y-3 p-4">
          <p v-if="query.trim()" class="text-xs text-neutral-600">{{ t('stereo_nx.messages.matching_details', { count: match.details.length }) }}</p>
          <p v-if="match.group.count > match.group.detailOccurrences" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600" data-testid="stereo-message-partial-details">{{ t('stereo_nx.messages.available_details', { count: match.group.count, details: match.group.details.length, occurrences: match.group.detailOccurrences }) }}</p>
          <ul v-if="match.details.length" class="divide-y divide-neutral-200">
            <li v-for="(message, index) in shown(match)" :key="index" class="py-3 first:pt-0 last:pb-0" data-testid="stereo-message-row">
              <div v-if="stereoNxMessageDocument(message) || kind(message) || stereoNxMessageOccurrences(message) > 1" class="mb-1 flex flex-wrap items-center gap-2 text-sm"><template v-if="stereoNxMessageDocument(message) || kind(message)"><svg class="h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg><strong class="break-words">{{ stereoNxMessageDocument(message) || t('stereo_nx.unnumbered_document') }}</strong><span v-if="kind(message)" class="text-xs text-neutral-500">{{ kind(message) }}</span></template><span v-if="stereoNxMessageOccurrences(message) > 1" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600" data-testid="stereo-message-detail-occurrences">{{ t('stereo_nx.messages.detail_occurrences', { count: stereoNxMessageOccurrences(message) }) }}</span></div>
              <p class="whitespace-pre-line break-words text-sm text-neutral-700">{{ message.text }}</p>
            </li>
          </ul>
          <div v-if="match.details.length > PAGE_SIZE" class="flex flex-wrap items-center gap-3 border-t border-neutral-200 pt-3">
            <span class="text-xs text-neutral-500" role="status">{{ t('stereo_nx.messages.shown_details', { shown: Math.min(match.details.length, limits[match.group.key] ?? PAGE_SIZE), total: match.details.length }) }}</span>
            <button v-if="match.details.length > (limits[match.group.key] ?? PAGE_SIZE)" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-testid="stereo-messages-load-more" @click="loadMore(match)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('stereo_nx.messages.load_more', { count: Math.min(PAGE_SIZE, match.details.length - (limits[match.group.key] ?? PAGE_SIZE)) }) }}</button>
          </div>
        </div>
      </details>
    </template>
    <p v-else class="text-sm text-neutral-600">{{ t('stereo_nx.messages.empty') }}</p>
  </section>
</template>
