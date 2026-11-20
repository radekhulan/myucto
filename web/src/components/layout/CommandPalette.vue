<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useHotkey } from '@/composables/useHotkey'
import { markTipUsed } from '@/composables/useTips'
import { formatShortcut } from '@/composables/useKeyboardShortcuts'
import { useGlobalSearch, type SearchMenuItem, type SearchQuickAction } from '@/composables/useGlobalSearch'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'

/**
 * Paleta příkazů (Ctrl/⌘ + K).
 *
 * Výsledky dává {@link useGlobalSearch}, stejně jako hledací pole (Alt+Q).
 * Paleta je jen jiný vzhled téhož hledání: modální okno s ikonami a bez dotazu
 * ukazuje rychlé akce, ať prázdná paleta nenutí uživatele hádat, co do ní napsat.
 */

const props = defineProps<{
  navItems: readonly SearchMenuItem[]
  quickActions: readonly SearchQuickAction[]
}>()

const { t } = useI18n()
const paletteShortcutLabel = formatShortcut('ctrl+k')
const { q, loading, options, groupHeaderFor, clear } = useGlobalSearch({
  menuItems: () => props.navItems,
  quickActions: () => props.quickActions,
  quickActionsWhenEmpty: true,
})

const open = ref(false)
const activeIndex = ref(0)
const inputEl = ref<HTMLInputElement | null>(null)
const listEl = ref<HTMLElement | null>(null)

watch(q, () => { activeIndex.value = 0 })

async function show() {
  // Jakmile paletu jednou otevřel, tip na ni v patičce mizí — už ji zná.
  markTipUsed('palette')
  open.value = true
  clear()
  activeIndex.value = 0
  await nextTick()
  inputEl.value?.focus()
}

function hide() {
  open.value = false
  clear()
}

function select(i: number) {
  const opt = options.value[i]
  if (!opt) return
  hide()
  opt.run()
}

/** Aktivní řádek musí zůstat v dohledu i při ovládání šipkami. */
async function scrollActiveIntoView() {
  await nextTick()
  listEl.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (options.value.length) activeIndex.value = (activeIndex.value + 1) % options.value.length
    void scrollActiveIntoView()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (options.value.length) activeIndex.value = (activeIndex.value - 1 + options.value.length) % options.value.length
    void scrollActiveIntoView()
  } else if (e.key === 'Enter') {
    e.preventDefault()
    select(activeIndex.value)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    hide()
  }
}

useHotkey('ctrl+k', (e) => {
  e.preventDefault()
  open.value ? hide() : void show()
})

defineExpose({ show })
</script>

<template>
  <WorkspaceTeleport>
    <div v-if="open" class="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh] bg-neutral-900/50 backdrop-blur-[3px]"
      @click.self="hide">
      <div class="rise-in w-full max-w-2xl overflow-hidden rounded-xl bg-surface-raised shadow-2xl ring-1 ring-neutral-200"
        role="dialog" aria-modal="true" :aria-label="t('command.title')">

        <div class="relative border-b border-neutral-200">
          <svg class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input
            ref="inputEl"
            v-model="q"
            type="text"
            :placeholder="t('command.placeholder')"
            class="w-full h-14 pl-12 pr-16 bg-transparent text-base outline-none placeholder:text-neutral-400"
            autocomplete="off"
            spellcheck="false"
            @keydown="onKeydown"
          />
          <kbd class="absolute right-4 top-1/2 -translate-y-1/2 rounded border border-neutral-300 px-1.5 py-0.5 text-[10px] font-medium leading-none text-neutral-500">esc</kbd>
        </div>

        <div ref="listEl" class="max-h-[52vh] overflow-y-auto scrollbar-slim py-1.5">
          <div v-if="loading && options.length === 0" class="px-4 py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
          <div v-else-if="options.length === 0" class="px-4 py-8 text-center text-sm text-neutral-500">{{ t('search.no_results') }}</div>

          <template v-for="(opt, i) in options" :key="opt.group + ':' + i + ':' + opt.label">
            <div v-if="groupHeaderFor(i)" class="px-4 pt-2.5 pb-1 text-[10px] font-bold uppercase tracking-[0.14em] text-neutral-400">
              {{ groupHeaderFor(i) }}
            </div>
            <button
              type="button"
              :data-active="i === activeIndex"
              :data-accent="opt.accent"
              class="group flex w-full cursor-pointer items-center gap-3 px-4 py-2 text-left"
              :class="i === activeIndex ? 'bg-primary-50' : 'hover:bg-neutral-50'"
              @mouseenter="activeIndex = i"
              @click="select(i)"
            >
              <!-- Ikona nese barvu své sekce/skupiny — díky tomu je paleta na
                   první pohled čitelná podle barev, ne jen podle textu. -->
              <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                :style="{ backgroundColor: 'color-mix(in oklab, var(--module-accent) 14%, transparent)', color: 'var(--module-accent)' }">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="opt.icon" />
                </svg>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm text-neutral-900">{{ opt.label }}</span>
                <span v-if="opt.sub" class="block truncate text-xs text-neutral-500">{{ opt.sub }}</span>
              </span>
              <kbd v-if="i === activeIndex" class="shrink-0 rounded border border-neutral-300 px-1.5 py-0.5 text-[10px] leading-none text-neutral-500">↵</kbd>
            </button>
          </template>
        </div>

        <div class="flex items-center gap-4 border-t border-neutral-200 bg-neutral-50 px-4 py-2 text-[11px] text-neutral-500">
          <span><kbd class="font-mono">↑↓</kbd> {{ t('command.hint_move') }}</span>
          <span><kbd class="font-mono">↵</kbd> {{ t('command.hint_open') }}</span>
          <span class="ml-auto"><kbd class="font-mono">{{ paletteShortcutLabel }}</kbd></span>
        </div>
      </div>
    </div>
  </WorkspaceTeleport>
</template>
