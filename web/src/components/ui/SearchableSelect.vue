<script setup lang="ts" generic="T extends string | number">
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick, useId } from 'vue'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'

/**
 * `keywords` jsou další názvy, podle kterých se položka má najít, ale nemají
 * se zobrazovat (oficiální název státu, kód ISO…).
 */
type Option = { value: T; label: string; secondary?: string; keywords?: string }

const props = withDefaults(defineProps<{
  modelValue: T | null
  options: Option[]
  placeholder?: string
  emptyLabel?: string
  noResultsLabel?: string
  clearable?: boolean
  disabled?: boolean
  required?: boolean
  /** Server-side režim: options dodává rodič podle @search (žádný client-side filtr). */
  remote?: boolean
  /** Indikátor načítání ve výsledcích (jen remote). */
  loading?: boolean
  loadingLabel?: string
  /**
   * Nabídka je oříznutá — shod je víc, než kolik jich server poslal.
   *
   * Why: mlčky oříznutý seznam tvrdí „nic dalšího neexistuje". U výběru
   * bankovního důkazu k párování mzdy je to nejdražší možná lež: uživatel
   * usoudí, že transakce chybí, a platbu založí znovu.
   */
  truncated?: boolean
  truncatedLabel?: string
  /** Vybraná položka pro zobrazení labelu, i když není v options (edit / po hledání). */
  selectedOption?: Option | null
  invalid?: boolean
  accent?: 'primary' | 'payroll'
  inputClass?: string
  ariaLabel?: string
  inputId?: string
  clearLabel?: string
  /**
   * Nabídku vykreslit do <body> s position:fixed. Nutné uvnitř kontejneru s overflow
   * (např. tabulka položek faktury s overflow-x-auto), který by absolutně polohovaný
   * seznam oříznul — stejný důvod i řešení jako ve StockDescriptionField.
   */
  teleport?: boolean
}>(), {
  placeholder: '',
  emptyLabel: '',
  noResultsLabel: 'Žádné výsledky',
  clearable: true,
  disabled: false,
  required: false,
  remote: false,
  loading: false,
  loadingLabel: 'Hledám…',
  truncated: false,
  truncatedLabel: 'Zobrazena jen část shod - upřesněte hledání.',
  selectedOption: null,
  invalid: false,
  accent: 'primary',
  inputClass: '',
  ariaLabel: undefined,
  inputId: undefined,
  clearLabel: 'Zrušit výběr',
  teleport: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: T | null]
  'search': [query: string]
}>()

const root = ref<HTMLDivElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const listbox = ref<HTMLDivElement | null>(null)
const open = ref(false)
const query = ref('')
const highlightIdx = ref(0)
const listboxId = `searchable-select-${useId()}`
const activeOptionId = computed(() =>
  open.value && filtered.value[highlightIdx.value]
    ? `${listboxId}-option-${highlightIdx.value}`
    : undefined,
)
const focusClass = computed(() => props.accent === 'payroll'
  ? 'focus:ring-payroll-500/20 focus:border-payroll-500'
  : 'focus:ring-primary-500/20 focus:border-primary-500')

// Vybraná položka: v remote režimu může být mimo aktuální options (edit / po hledání),
// proto preferujeme selectedOption dodaný rodičem.
const selected = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return null
  if (props.selectedOption && props.selectedOption.value === props.modelValue) return props.selectedOption
  return props.options.find(o => o.value === props.modelValue) ?? null
})

// remote: options už přišly z backendu (rodič filtruje přes @search) → nefiltrovat client-side.
/*
 * Hledá se bez ohledu na diakritiku a velikost písmen: „cesko" musí najít
 * „Česko" a „Plzen" „Plzeň" — na klávesnici bez české sady se jinak nedá
 * vybrat nic. Prohledává se název, druhý řádek i skrytá klíčová slova.
 */
function foldForSearch(value: string): string {
  return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

const filtered = computed(() => {
  if (props.remote) return props.options
  const raw = query.value.trim()
  if (!raw || (selected.value && raw.toLowerCase() === selected.value.label.toLowerCase())) {
    return props.options
  }
  const q = foldForSearch(raw)
  return props.options.filter(o =>
    foldForSearch(o.label).includes(q) ||
    (o.secondary !== undefined && foldForSearch(o.secondary).includes(q)) ||
    (o.keywords !== undefined && foldForSearch(o.keywords).includes(q))
  )
})

watch(selected, (s) => {
  query.value = s?.label ?? ''
}, { immediate: true })

watch(open, (o) => {
  if (o) {
    highlightIdx.value = Math.max(0, filtered.value.findIndex(opt => opt.value === props.modelValue))
    nextTick(() => {
      input.value?.select()
    })
  }
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
function emitSearchDebounced(q: string) {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => emit('search', q), 250)
}

function selectOption(o: Option) {
  emit('update:modelValue', o.value)
  query.value = o.label
  open.value = false
}

function clear() {
  emit('update:modelValue', null)
  query.value = ''
  open.value = false
  if (props.remote) emit('search', '')
  input.value?.focus()
}

function onFocus() {
  if (props.disabled) return
  open.value = true
  if (props.remote) {
    // Při otevření s vybranou položkou (query == label) ukaž první stránku, jinak hledej dle textu.
    const q = query.value.trim()
    emit('search', (selected.value && q.toLowerCase() === selected.value.label.toLowerCase()) ? '' : q)
  }
}

/*
 * Přepsání textu na název už vybrané položky ukáže celou nabídku (viz
 * `filtered`). Zvýraznění proto musí zůstat na vybrané položce — dřív
 * skočilo na první řádek a Enter po „Česko" → Enter vybral Afghánistán.
 */
function onInput() {
  open.value = true
  highlightIdx.value = Math.max(0, filtered.value.findIndex(opt => opt.value === props.modelValue))
  if (props.remote) emitSearchDebounced(query.value.trim())
}

function onKey(e: KeyboardEvent) {
  if (props.disabled) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    open.value = true
    highlightIdx.value = Math.min(highlightIdx.value + 1, filtered.value.length - 1)
    scrollHighlightIntoView()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightIdx.value = Math.max(highlightIdx.value - 1, 0)
    scrollHighlightIntoView()
  } else if (e.key === 'Enter') {
    // Otevřená nabídka Enter vždy spotřebuje: bez shody nesmí odeslat
    // okolní formulář ani nechat rozepsaný text, který nic nevybral.
    if (!open.value) return
    e.preventDefault()
    const option = filtered.value[highlightIdx.value]
    if (option) {
      selectOption(option)
    } else {
      open.value = false
      query.value = selected.value?.label ?? ''
    }
  } else if (e.key === 'Home' && open.value && filtered.value.length > 0) {
    e.preventDefault()
    highlightIdx.value = 0
    scrollHighlightIntoView()
  } else if (e.key === 'End' && open.value && filtered.value.length > 0) {
    e.preventDefault()
    highlightIdx.value = filtered.value.length - 1
    scrollHighlightIntoView()
  } else if (e.key === 'Escape') {
    // Zavření rozbaleného výběru Escape spotřebuje; okno, ve kterém výběr je, se tím nezavře.
    if (open.value) e.preventDefault()
    open.value = false
    // resetuj query na vybraný label, kdyby uživatel měnil text bez výběru
    query.value = selected.value?.label ?? ''
    input.value?.blur()
  } else if (e.key === 'Tab') {
    open.value = false
    query.value = selected.value?.label ?? ''
  }
}

function scrollHighlightIntoView() {
  nextTick(() => {
    const el = listbox.value?.querySelector<HTMLElement>(`[data-idx="${highlightIdx.value}"]`)
    if (typeof el?.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' })
  })
}

function onClickOutside(e: MouseEvent) {
  if (!root.value) return
  const target = e.target as Node
  // V teleport režimu je nabídka mimo `root` (visí na <body>) — bez téhle větve by
  // mousedown na položce zavřel seznam dřív, než se stihne vybrat.
  if (!root.value.contains(target) && !(listbox.value?.contains(target) ?? false)) {
    open.value = false
    // při zavření bez výběru: pokud query neshodí s vybraným, vrať na vybraný label
    query.value = selected.value?.label ?? ''
  }
}

// Teleportovaná nabídka nedědí pozici od rodiče → dopočítáváme ji z bounding rectu
// inputu (a přepočítáváme při scrollu i resize, jinak by při rolování odplula).
const menuStyle = reactive<Record<string, string>>({ position: 'fixed', left: '0px', top: '0px', width: '0px', zIndex: '60' })

function updateMenuPosition() {
  if (!props.teleport || !open.value || !root.value) return
  const r = root.value.getBoundingClientRect()
  const below = window.innerHeight - r.bottom
  menuStyle.left = `${r.left}px`
  menuStyle.width = `${r.width}px`
  // Málo místa pod inputem → nabídku otoč nad něj (jinak by u spodního okraje byla nedostupná).
  if (below < 200 && r.top > below) {
    menuStyle.top = ''
    menuStyle.bottom = `${window.innerHeight - r.top + 4}px`
    menuStyle.maxHeight = `${Math.max(120, r.top - 12)}px`
  } else {
    menuStyle.bottom = ''
    menuStyle.top = `${r.bottom + 4}px`
    menuStyle.maxHeight = `${Math.max(120, below - 12)}px`
  }
}

watch(open, (o) => {
  if (o && props.teleport) nextTick(updateMenuPosition)
})

onMounted(() => {
  document.addEventListener('mousedown', onClickOutside)
  window.addEventListener('scroll', updateMenuPosition, true)
  window.addEventListener('resize', updateMenuPosition)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
  window.removeEventListener('scroll', updateMenuPosition, true)
  window.removeEventListener('resize', updateMenuPosition)
  if (searchTimer) clearTimeout(searchTimer)
})
</script>

<template>
  <div ref="root" class="relative">
    <div class="relative">
      <input
        ref="input"
        v-model="query"
        :id="inputId"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open"
        :aria-controls="open ? listboxId : undefined"
        :aria-activedescendant="activeOptionId"
        :aria-invalid="invalid || undefined"
        :aria-required="required || undefined"
        :aria-label="ariaLabel"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        autocomplete="off"
        :class="[
          'w-full h-10 pl-3 pr-16 border border-neutral-300 rounded-md text-sm bg-surface',
          `focus:ring-2 outline-none ${focusClass}`,
          'disabled:bg-neutral-50 disabled:text-neutral-400',
          invalid ? 'border-danger-500' : '',
          inputClass,
        ]"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKey"
      />
      <button
        v-if="clearable && modelValue !== null && modelValue !== undefined && !disabled"
        type="button"
        @click="clear"
        class="cursor-pointer absolute right-7 top-1/2 -translate-y-1/2 w-6 h-6 inline-flex items-center justify-center text-neutral-400 hover:text-neutral-700 text-lg leading-none"
        :aria-label="clearLabel"
      >×</button>
      <span class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none text-xs">▼</span>
    </div>
    <WorkspaceTeleport :disabled="!teleport">
    <div
      v-if="open"
      ref="listbox"
      :id="listboxId"
      role="listbox"
      :style="teleport ? menuStyle : undefined"
      :class="[
        'bg-surface border border-neutral-200 rounded-md shadow-lg overflow-y-auto',
        teleport ? '' : 'absolute z-50 left-0 right-0 mt-1 max-h-72',
      ]"
    >
      <div v-if="remote && loading" class="px-3 py-2 text-sm text-neutral-400">
        {{ loadingLabel }}
      </div>
      <div v-else-if="filtered.length === 0" class="px-3 py-2 text-sm text-neutral-400">
        {{ noResultsLabel }}
      </div>
      <button
        v-for="(o, i) in filtered"
        :key="String(o.value)"
        :id="`${listboxId}-option-${i}`"
        :data-idx="i"
        role="option"
        :aria-selected="o.value === modelValue"
        type="button"
        @click="selectOption(o)"
        @mouseenter="highlightIdx = i"
        :class="[
          'cursor-pointer w-full text-left px-3 py-2 text-sm',
          i === highlightIdx ? (accent === 'payroll' ? 'bg-payroll-50' : 'bg-primary-50') : 'hover:bg-neutral-50',
          o.value === modelValue
            ? (accent === 'payroll' ? 'font-medium text-payroll-600' : 'font-medium text-primary-700')
            : 'text-neutral-900',
        ]"
      >
        <div class="truncate">{{ o.label }}</div>
        <div v-if="o.secondary" class="text-xs text-neutral-500 truncate">{{ o.secondary }}</div>
      </button>
      <!-- Oříznutí se PŘIZNÁVÁ. Bez téhle věty by uživatel četl neúplnou
           nabídku jako úplnou a hledanou položku považoval za neexistující. -->
      <div
        v-if="truncated && !loading && filtered.length > 0"
        data-test="searchable-select-truncated"
        class="border-t border-neutral-200 bg-warning-50 px-3 py-2 text-xs text-warning-800"
      >{{ truncatedLabel }}</div>
    </div>
    </WorkspaceTeleport>
  </div>
</template>
