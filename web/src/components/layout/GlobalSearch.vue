<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGlobalSearch, type SearchMenuItem, type SearchQuickAction } from '@/composables/useGlobalSearch'

/**
 * Hledací pole (Alt+Q). Výsledky dává {@link useGlobalSearch}, stejně jako
 * paleta příkazů (Ctrl+K); pole je jen jiný vzhled téhož hledání.
 */
const props = withDefaults(defineProps<{
  menuItems: readonly SearchMenuItem[]
  quickActions?: readonly SearchQuickAction[]
  placement?: 'above' | 'below'
  compact?: boolean
  shortcut?: string
  /** Velké pole uprostřed stránky (nová záložka). */
  large?: boolean
  autofocus?: boolean
}>(), {
  placement: 'below',
  compact: false,
})
const emit = defineEmits<{ navigated: [] }>()

const { t } = useI18n()
const { q, loading, options, groupHeaderFor, clear } = useGlobalSearch({
  menuItems: () => props.menuItems,
  quickActions: () => props.quickActions ?? [],
})

const open = ref(false)
const activeIndex = ref(0)
const inputEl = ref<HTMLInputElement | null>(null)

watch(q, (val) => {
  open.value = val.trim().length > 0
  activeIndex.value = 0
})

function select(i: number) {
  const opt = options.value[i]
  if (!opt) return
  opt.run()
  reset()
  emit('navigated')
}

function reset() {
  clear()
  open.value = false
  activeIndex.value = 0
  inputEl.value?.blur()
}

function focus(): void {
  inputEl.value?.focus()
}

defineExpose({ focus })

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (options.value.length) activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    activeIndex.value = Math.max(0, activeIndex.value - 1)
  } else if (e.key === 'Enter') {
    if (options.value.length) { e.preventDefault(); select(activeIndex.value) }
  } else if (e.key === 'Escape') {
    reset()
  }
}

const showDropdown = computed(() => open.value && q.value.trim().length > 0)
const hasResults = computed(() => options.value.length > 0)

onMounted(() => { if (props.autofocus) inputEl.value?.focus({ preventScroll: true }) })
</script>

<template>
  <div class="relative" :class="compact || large ? '' : 'px-0.5 pb-2'">
    <div class="relative">
      <svg class="absolute top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none"
           :class="large ? 'left-4 h-5 w-5' : 'left-2.5 h-4 w-4'"
           fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
      </svg>
      <input
        ref="inputEl"
        v-model="q"
        type="text"
        :placeholder="t('search.placeholder')"
        class="w-full border focus:border-primary-400 focus:outline-none"
        :class="large
          ? 'h-12 pl-12 pr-4 text-base border-neutral-300 bg-surface shadow-sm focus:ring-4 focus:ring-primary-100'
          : [
            'pl-8 text-sm rounded-md border-neutral-200 bg-neutral-50 focus:bg-surface focus:ring-1 focus:ring-primary-200',
            compact ? 'h-8 py-1' : 'py-1.5 max-lg:h-11',
            compact && shortcut ? 'pr-20' : 'pr-3',
          ]"
        autocomplete="off"
        spellcheck="false"
        @focus="open = q.trim().length > 0"
        @blur="open = false"
        @keydown="onKeydown"
      />
      <kbd
        v-if="compact && shortcut"
        class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-neutral-300 bg-surface px-1.5 py-0.5 font-sans text-[10px] font-medium leading-none text-neutral-500 shadow-sm"
      >{{ shortcut }}</kbd>
    </div>

    <!-- Dropdown výsledků -->
    <div
      v-if="showDropdown"
      class="absolute left-0 right-0 z-40 bg-surface border border-neutral-200 shadow-lg overflow-y-auto"
      :class="[placement === 'above' ? 'bottom-full mb-1' : 'top-full mt-1', large ? 'max-h-[55vh] text-left' : 'rounded-md max-h-[70vh]']"
    >
      <div v-if="loading && !hasResults" class="px-3 py-2 text-xs text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="!hasResults" class="px-3 py-2 text-xs text-neutral-500">{{ t('search.no_results') }}</div>

      <template v-for="(opt, i) in options" :key="opt.group + ':' + i">
        <div v-if="groupHeaderFor(i)"
             class="px-3 pt-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-neutral-400">
          {{ groupHeaderFor(i) }}
        </div>
        <button
          type="button"
          class="w-full text-left flex flex-col gap-0.5 cursor-pointer"
          :class="[i === activeIndex ? 'bg-primary-50' : 'hover:bg-neutral-50', large ? 'px-4 py-2' : 'px-3 py-1.5']"
          @mouseenter="activeIndex = i"
          @mousedown.prevent="select(i)"
        >
          <span class="text-sm text-neutral-800 truncate">{{ opt.label }}</span>
          <span v-if="opt.sub" class="text-xs text-neutral-500 truncate">{{ opt.sub }}</span>
        </button>
      </template>
    </div>
  </div>
</template>
