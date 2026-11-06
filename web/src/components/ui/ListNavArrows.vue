<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ICONS } from '@/components/ui/buttonStyles'
import { LIST_NAV_KEYS } from '@/composables/useListNavigation'

defineProps<{
  hasPrevious: boolean
  hasNext: boolean
  busy?: boolean
}>()
const emit = defineEmits<{ previous: []; next: [] }>()
const { t } = useI18n()

const BTN = 'cursor-pointer w-6 h-6 rounded inline-flex items-center justify-center shrink-0 text-neutral-400 '
  + 'hover:text-neutral-700 hover:bg-neutral-100 transition-colors '
  + 'disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-neutral-400'
</script>

<template>
  <span class="inline-flex items-center gap-0.5" role="group" :aria-label="t('list_nav.group')" data-test="list-nav">
    <button type="button" :class="BTN" :disabled="!hasPrevious || busy"
      :title="t('list_nav.previous', { key: LIST_NAV_KEYS.previous })"
      :aria-label="t('list_nav.previous', { key: LIST_NAV_KEYS.previous })"
      data-test="list-nav-previous" @click="emit('previous')">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.chevronLeft" /></svg>
    </button>
    <button type="button" :class="BTN" :disabled="!hasNext || busy"
      :title="t('list_nav.next', { key: LIST_NAV_KEYS.next })"
      :aria-label="t('list_nav.next', { key: LIST_NAV_KEYS.next })"
      data-test="list-nav-next" @click="emit('next')">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.chevronRight" /></svg>
    </button>
  </span>
</template>
