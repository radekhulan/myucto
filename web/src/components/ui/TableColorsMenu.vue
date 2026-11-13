<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'
import type { TableColorsCtrl } from '@/composables/useTableColors'

const props = defineProps<{ ctrl: TablePrefsCtrl; colors: TableColorsCtrl }>()
const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
const open = ref(false)
const popup = ref<HTMLElement | null>(null)
const menuStyle = ref<Record<string, string>>()
const columns = computed(() => (props.ctrl.orderedColumns?.value ?? props.ctrl.columns).filter(c => c.available?.() !== false))
onClickOutside(root, () => { open.value = false })
async function toggleOpen() {
  open.value = !open.value
  if (!open.value) return
  menuStyle.value = undefined
  await nextTick()
  if (!root.value || !popup.value) return
  const trigger = root.value.getBoundingClientRect()
  const margin = 8
  const left = Math.max(margin, Math.min(trigger.right - popup.value.offsetWidth, window.innerWidth - popup.value.offsetWidth - margin))
  const below = window.innerHeight - trigger.bottom - margin
  const above = trigger.top - margin
  const openAbove = below < 240 && above > below
  menuStyle.value = {
    left: `${left - trigger.left}px`,
    right: 'auto',
    maxHeight: `${Math.max(120, Math.min(openAbove ? above : below, window.innerHeight * 0.7, 512))}px`,
    ...(openAbove ? { bottom: `${trigger.height + 4}px`, marginTop: '0' } : {}),
  }
}
function changeColor(key: string, event: Event) {
  props.colors.setColor(key, (event.target as HTMLInputElement).value)
}
</script>

<template>
  <div ref="root" class="relative" @keydown.esc.stop="open = false">
    <button type="button" :aria-expanded="open" :disabled="!ctrl.ready.value" :title="t('common.item_colors')" @click="toggleOpen"
      class="cursor-pointer shrink-0 whitespace-nowrap h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface text-sm text-neutral-700 hover:bg-neutral-50 transition-colors disabled:opacity-50">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 0 18h1.5a2.5 2.5 0 0 0 1.8-4.2 1.5 1.5 0 0 1 1.1-2.6H18a3 3 0 0 0 3-3A8.2 8.2 0 0 0 12 3Z"/><circle cx="7.5" cy="10" r=".7"/><circle cx="11" cy="7" r=".7"/><circle cx="15.5" cy="8.5" r=".7"/></svg>
      <span class="max-md:sr-only">{{ t('common.item_colors') }}</span>
      <svg class="w-3 h-3 max-md:hidden" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div v-if="open" ref="popup" :style="menuStyle" class="absolute right-0 mt-1 w-80 max-w-[calc(100vw-1rem)] max-h-[min(70vh,32rem)] flex flex-col rounded-lg border border-neutral-200 bg-surface shadow-lg z-40">
      <p class="px-3 py-2 text-xs text-neutral-500 border-b border-neutral-100">{{ t('common.item_colors_hint') }}</p>
      <div class="min-h-0 overflow-y-auto scrollbar-slim p-2 space-y-1">
        <div v-for="col in columns" :key="col.key" class="flex items-center gap-2">
          <label class="flex flex-1 min-w-0 items-center gap-2 cursor-pointer">
            <input type="color" class="w-8 h-8 shrink-0 cursor-pointer rounded border border-neutral-300"
              :value="colors.color(col.key) ?? '#ffffff'" :aria-label="t('common.item_color_for', { column: t(col.labelKey) })"
              @input="changeColor(col.key, $event)" @change="changeColor(col.key, $event)">
            <span class="flex-1 min-w-0 truncate rounded px-2 py-1 text-sm text-neutral-700"
              :data-custom-color="!!colors.color(col.key)" :style="colors.cellStyle(col.key)">{{ t(col.labelKey) }}</span>
          </label>
          <button type="button" :disabled="!colors.color(col.key)" @click="colors.setColor(col.key, null)"
            :title="t('common.item_color_reset', { column: t(col.labelKey) })" :aria-label="t('common.item_color_reset', { column: t(col.labelKey) })"
            class="cursor-pointer rounded p-1.5 text-neutral-500 hover:bg-neutral-50 disabled:opacity-30">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10a9 9 0 1 1 2.6 8.4M3 4v6h6"/></svg>
          </button>
        </div>
      </div>
      <div class="border-t border-neutral-100 p-2">
        <button type="button" @click="colors.reset()" class="cursor-pointer w-full inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10a9 9 0 1 1 2.6 8.4M3 4v6h6"/></svg>
          {{ t('common.item_colors_reset') }}
        </button>
      </div>
    </div>
  </div>
</template>
