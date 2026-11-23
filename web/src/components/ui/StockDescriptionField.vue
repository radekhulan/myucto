<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'

/** `badge` = krátký stav vpravo (např. „skladem 12 ks"); `badgeTone: 'warning'` = nula, karta se nevydá. */
type Option = { value: number; label: string; secondary?: string; badge?: string; badgeTone?: 'neutral' | 'warning' }

const props = withDefaults(defineProps<{
  /** Popis řádku (v-model:description). */
  description: string
  /** Vazba na skladovou kartu (v-model:stockItemId). */
  stockItemId: number | null
  /** Zapnutá skladová evidence — bez ní je pole čistý textový popis. */
  stockEnabled?: boolean
  /** Výsledky hledání dodané rodičem (remote). */
  options?: Option[]
  loading?: boolean
  /** Vybraná karta (label „SKU — název") pro chip, i mimo aktuální options. */
  selectedOption?: Option | null
  /** Text dostupnosti do chipu, např. „skladem 12 ks" (jen vydaná FA). */
  availabilityText?: string | null
  availabilityInsufficient?: boolean
  placeholder?: string
  ariaLabel?: string
  /** true → textarea (resize), false → jednořádkový input. */
  multiline?: boolean
  rows?: number
  /** Hodnota data-row-input pro fokus posledního řádku (useRowFocus). */
  rowInputMarker?: string
  /** Chybový rámeček. */
  invalid?: boolean
  loadingLabel?: string
  noResultsLabel?: string
  keepFreeTextLabel?: string
  unlinkLabel?: string
}>(), {
  stockEnabled: false,
  options: () => [],
  loading: false,
  selectedOption: null,
  availabilityText: null,
  availabilityInsufficient: false,
  placeholder: '',
  multiline: false,
  rows: 1,
  rowInputMarker: undefined,
  invalid: false,
  loadingLabel: 'Hledám…',
  noResultsLabel: 'Žádné výsledky',
  keepFreeTextLabel: 'Ponechat jako volný text',
  unlinkLabel: 'Odpojit skladovou kartu',
})

const emit = defineEmits<{
  'update:description': [value: string]
  'search': [query: string]
  'select': [value: number | null]
}>()

const root = ref<HTMLDivElement | null>(null)
const control = ref<HTMLTextAreaElement | HTMLInputElement | null>(null)
const listbox = ref<HTMLDivElement | null>(null)
const open = ref(false)
const highlightIdx = ref(0)

// Nabídka se teleportuje do <body> a polohuje position:fixed — jinak ji ořízne overflow
// kontejneru tabulky položek (overflow-x-auto klipuje i svisle). Umí se překlopit nahoru.
const menuStyle = reactive<Record<string, string>>({ position: 'fixed', left: '0px', top: '0px', width: '0px', zIndex: '60' })
function updateMenuPosition() {
  const el = control.value
  if (!el) return
  const r = el.getBoundingClientRect()
  const spaceBelow = window.innerHeight - r.bottom
  const openUp = spaceBelow < 240 && r.top > spaceBelow
  menuStyle.left = `${Math.round(r.left)}px`
  menuStyle.width = `${Math.round(r.width)}px`
  if (openUp) {
    menuStyle.top = 'auto'
    menuStyle.bottom = `${Math.round(window.innerHeight - r.top + 4)}px`
  } else {
    menuStyle.bottom = 'auto'
    menuStyle.top = `${Math.round(r.bottom + 4)}px`
  }
}
watch(open, (o) => {
  if (o) {
    nextTick(updateMenuPosition)
    window.addEventListener('scroll', updateMenuPosition, true)
    window.addEventListener('resize', updateMenuPosition)
  } else {
    window.removeEventListener('scroll', updateMenuPosition, true)
    window.removeEventListener('resize', updateMenuPosition)
  }
})

const linked = computed(() => props.stockEnabled && props.stockItemId != null)
/** Kompaktní kód do chipu — z labelu „SKU — název" vezme jen SKU. */
const chipCode = computed(() => {
  const label = props.selectedOption?.label ?? (props.stockItemId != null ? `#${props.stockItemId}` : '')
  return label.split(' — ')[0]
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
function emitSearchDebounced(q: string) {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => emit('search', q), 250)
}

function onInput(e: Event) {
  const val = (e.target as HTMLTextAreaElement | HTMLInputElement).value
  emit('update:description', val)
  if (props.stockEnabled) {
    open.value = true
    highlightIdx.value = 0
    emitSearchDebounced(val.trim())
  }
}

function onFocus() {
  // Auto-nabídku otevři jen když ještě není navázaná karta — při úpravě popisu navázaného
  // řádku nechceme rušivě vyskakovat dropdown.
  if (props.stockEnabled && props.stockItemId == null) {
    open.value = true
    emit('search', props.description.trim())
  }
}

function selectOption(o: Option) {
  emit('select', o.value)
  open.value = false
}

function unlink() {
  emit('select', null)
  open.value = false
  nextTick(() => control.value?.focus())
}

function keepFreeText() {
  open.value = false
  control.value?.focus()
}

function onKey(e: KeyboardEvent) {
  if (!props.stockEnabled) return
  const opts = props.options
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    open.value = true
    highlightIdx.value = Math.min(highlightIdx.value + 1, opts.length - 1)
    scrollHighlightIntoView()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightIdx.value = Math.max(highlightIdx.value - 1, 0)
    scrollHighlightIntoView()
  } else if (e.key === 'Enter') {
    // Shift+Enter vždy zalomí řádek popisu, i když je nabídka otevřená.
    if (e.shiftKey || e.isComposing) return
    if (open.value && opts[highlightIdx.value]) {
      e.preventDefault()
      selectOption(opts[highlightIdx.value])
    }
  } else if (e.key === 'Escape') {
    if (open.value) {
      e.preventDefault()
      open.value = false
    }
  }
}

function scrollHighlightIntoView() {
  nextTick(() => {
    const el = listbox.value?.querySelector<HTMLElement>(`[data-idx="${highlightIdx.value}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  })
}

function onClickOutside(e: MouseEvent) {
  const target = e.target as Node
  // listbox je teleportovaný do body → mimo root; klik do něj nesmí zavřít nabídku.
  const inRoot = root.value?.contains(target)
  const inMenu = listbox.value?.contains(target)
  if (!inRoot && !inMenu) open.value = false
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
  window.removeEventListener('scroll', updateMenuPosition, true)
  window.removeEventListener('resize', updateMenuPosition)
  if (searchTimer) clearTimeout(searchTimer)
})

const controlClass = computed(() => [
  'w-full px-2 py-1.5 border rounded text-sm outline-none',
  'focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
  props.multiline ? 'resize-y min-h-[36px]' : 'h-9',
  props.invalid ? 'border-danger-500/60' : 'border-neutral-300',
])
</script>

<template>
  <div ref="root" class="relative">
    <!-- Chip vazby na skladovou kartu — jen když je karta navázaná -->
    <div v-if="linked" class="mb-1 flex items-center gap-1 flex-wrap">
      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded border border-primary-200 bg-primary-50 text-primary-700 text-xs max-w-full">
        <span class="opacity-70">🔖</span>
        <span class="font-medium truncate">{{ chipCode }}</span>
        <span v-if="availabilityText" class="whitespace-nowrap" :class="availabilityInsufficient ? 'text-warning-600' : 'text-primary-600/70'">· {{ availabilityText }}</span>
        <button type="button" @click="unlink" :aria-label="unlinkLabel" :title="unlinkLabel"
          class="cursor-pointer ml-0.5 -mr-0.5 w-4 h-4 inline-flex items-center justify-center rounded hover:bg-primary-100 text-primary-500 hover:text-primary-700 leading-none">✕</button>
      </span>
    </div>

    <!-- Popis = zároveň našeptávač skladu (když je sklad zapnutý) -->
    <textarea
      v-if="multiline"
      ref="control"
      :value="description"
      :rows="rows"
      :data-row-input="rowInputMarker"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      autocomplete="off"
      :class="controlClass"
      @input="onInput"
      @focus="onFocus"
      @keydown="onKey"
    ></textarea>
    <input
      v-else
      ref="control"
      type="text"
      :value="description"
      :data-row-input="rowInputMarker"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      :class="controlClass"
      @input="onInput"
      @focus="onFocus"
      @keydown="onKey"
    />

    <!-- Nabídka skladových karet — teleport do body, aby ji neořízl overflow tabulky -->
    <WorkspaceTeleport>
    <div
      v-if="stockEnabled && open"
      ref="listbox"
      role="listbox"
      :style="menuStyle"
      class="bg-surface border border-neutral-200 rounded-md shadow-lg max-h-72 overflow-y-auto"
    >
      <div v-if="loading" class="px-3 py-2 text-sm text-neutral-400">{{ loadingLabel }}</div>
      <template v-else>
        <button
          v-for="(o, i) in options"
          :key="o.value"
          :data-idx="i"
          role="option"
          :aria-selected="o.value === stockItemId"
          type="button"
          @mousedown.prevent
          @click="selectOption(o)"
          @mouseenter="highlightIdx = i"
          :class="[
            'cursor-pointer w-full text-left px-3 py-2 text-sm',
            i === highlightIdx ? 'bg-primary-50' : 'hover:bg-neutral-50',
            o.value === stockItemId ? 'font-medium text-primary-700' : 'text-neutral-900',
          ]"
        >
          <div class="flex items-start gap-2">
            <div class="truncate min-w-0 flex-1">{{ o.label }}</div>
            <span v-if="o.badge" data-test="stock-option-badge"
              :class="['shrink-0 px-1.5 py-0.5 rounded border text-xs font-normal whitespace-nowrap tabular-nums',
                       o.badgeTone === 'warning' ? 'border-warning-500/40 bg-warning-50 text-warning-700' : 'border-neutral-200 bg-neutral-50 text-neutral-600']">{{ o.badge }}</span>
          </div>
          <div v-if="o.secondary" class="text-xs text-neutral-500 truncate">{{ o.secondary }}</div>
        </button>
        <div v-if="options.length === 0" class="px-3 py-2 text-sm text-neutral-400">{{ noResultsLabel }}</div>
        <button
          type="button"
          @mousedown.prevent
          @click="keepFreeText"
          class="cursor-pointer w-full text-left px-3 py-2 text-xs text-neutral-500 border-t border-neutral-100 hover:bg-neutral-50 flex items-center gap-1.5"
        >
          <span class="opacity-60">⌨</span> {{ keepFreeTextLabel }}
        </button>
      </template>
    </div>
    </WorkspaceTeleport>
  </div>
</template>
