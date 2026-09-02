<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useHotkey } from '@/composables/useHotkey'
import { markTipUsed } from '@/composables/useTips'
import { searchApi, type SearchResults } from '@/api/search'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { formatShortcut } from '@/composables/useKeyboardShortcuts'

/**
 * Paleta příkazů (Ctrl/⌘ + K).
 *
 * Why: aplikace měla našeptávač, ale ten uměl jen skákat na stránky a entity —
 * na akci („vystav fakturu", „nahraj výpis") jsi musel doklikat menu. Paleta
 * spojuje obojí do jednoho vstupu: zakládání, navigaci i hledání v datech.
 *
 * Bez dotazu ukazuje rychlé akce — prázdná paleta by nutila uživatele hádat, co
 * do ní může napsat.
 */

interface PaletteNavItem {
  to: string
  label: string
  icon: string
  external?: boolean
  /** Nadpis sekce menu — v paletě slouží jako popisek pod názvem. */
  section: string
  /** Accent sekce; barví ikonu, aby řádky nebyly jednolitě šedé. */
  accent: string
}

interface PaletteAction {
  to: string
  label: string
  icon: string
}

const props = defineProps<{
  navItems: PaletteNavItem[]
  quickActions: PaletteAction[]
}>()

const { t } = useI18n()
const workspaceNavigation = useWorkspaceNavigation()
const paletteShortcutLabel = formatShortcut('ctrl+k')

const open = ref(false)
const q = ref('')
const activeIndex = ref(0)
const loading = ref(false)
const results = ref<SearchResults>({ q: '', clients: [], invoices: [], purchase_invoices: [] })
const inputEl = ref<HTMLInputElement | null>(null)
const listEl = ref<HTMLElement | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | undefined
let seq = 0

type Group = 'action' | 'nav' | 'client' | 'invoice' | 'purchase'

interface Option {
  group: Group
  label: string
  sub: string
  icon: string
  /** Klíč accentu pro barvu ikony (viz [data-accent] v styles/main.css). */
  accent: string
  run: () => void
}

const GROUP_LABEL: Record<Group, string> = {
  action: 'command.group_actions',
  nav: 'search.group_menu',
  client: 'search.group_clients',
  invoice: 'search.group_invoices',
  purchase: 'search.group_purchase',
}

const ICON_PLUS = 'M12 6v6m0 0v6m0-6h6m-6 0H6'
const ICON_USER = 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z'
const ICON_DOC = 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z'
const ICON_CART = 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z'

/**
 * Fuzzy shoda: nejdřív souvislý podřetězec (nejsilnější signál), pak písmena
 * v pořadí kdekoli v textu („vyfa" najde „Vydané faktury"). Vrací skóre, nebo
 * -1 když neodpovídá — palety, které jen filtrují `includes()`, neumí zkratky.
 */
function fuzzyScore(text: string, needle: string): number {
  const hay = text.toLowerCase()
  const idx = hay.indexOf(needle)
  if (idx === 0) return 1000
  if (idx > 0) return 700 - idx
  let score = 0
  let pos = 0
  for (const ch of needle) {
    const found = hay.indexOf(ch, pos)
    if (found === -1) return -1
    // Shoda na začátku slova váží víc než uprostřed.
    score += found === 0 || hay[found - 1] === ' ' ? 10 : 3
    pos = found + 1
  }
  return score
}

/**
 * Skóre víceslovného dotazu: KAŽDÉ slovo musí někde v textu sedět, výsledek je
 * součet dílčích skóre.
 *
 * Why: uživatel přirozeně napíše „nová faktura", jenže akce se jmenuje „Vydaná
 * faktura" — souvislý podřetězec neexistuje a hledání po znacích by dalo
 * nesmyslné pořadí. Po slovech to funguje, jak člověk čeká: „nová" trefí klíčové
 * slovo akce, „faktura" její název. Zároveň to drží pořadí slov nezávazné
 * („faktura nová" najde totéž).
 */
function matchScore(haystack: string, query: string): number {
  const tokens = query.split(/\s+/).filter(Boolean)
  if (tokens.length === 0) return -1
  let total = 0
  for (const token of tokens) {
    const score = fuzzyScore(haystack, token)
    if (score < 0) return -1
    total += score
  }
  return total / tokens.length
}

const needle = computed(() => q.value.trim().toLowerCase())

const options = computed<Option[]>(() => {
  const out: Option[] = []
  const n = needle.value

  // Bez dotazu: rychlé akce jako rozcestník, ať paleta není prázdná.
  if (!n) {
    for (const a of props.quickActions) {
      out.push({ group: 'action', label: a.label, sub: t('command.action_hint'), icon: a.icon || ICON_PLUS, accent: 'primary', run: () => void workspaceNavigation.navigate(a.to) })
    }
    return out
  }

  const scored: Array<{ opt: Option; score: number }> = []

  // Klíčová slova zakládání („nová", „vytvořit", „založit"…) se přidávají do
  // prohledávaného textu akce, aby „nová faktura" našla akci „Vydaná faktura“.
  const createKeywords = t('command.kw_create')

  for (const a of props.quickActions) {
    const score = matchScore(`${createKeywords} ${a.label}`, n)
    if (score >= 0) {
      scored.push({ score: score + 50, opt: { group: 'action', label: a.label, sub: t('command.action_hint'), icon: a.icon || ICON_PLUS, accent: 'primary', run: () => void workspaceNavigation.navigate(a.to) } })
    }
  }

  for (const item of props.navItems) {
    const score = matchScore(`${item.section} ${item.label}`, n)
    if (score >= 0) {
      scored.push({
        score,
        opt: {
          group: 'nav',
          label: item.label,
          sub: item.section,
          icon: item.icon,
          accent: item.accent,
          run: () => { item.external ? workspaceNavigation.openExternal(item.to) : void workspaceNavigation.navigate(item.to) },
        },
      })
    }
  }

  scored.sort((a, b) => b.score - a.score)
  out.push(...scored.slice(0, 12).map(s => s.opt))

  for (const c of results.value.clients) {
    out.push({ group: 'client', label: c.company_name, sub: c.main_email || '', icon: ICON_USER, accent: 'success', run: () => void workspaceNavigation.navigate(`/clients/${c.id}`) })
  }
  for (const i of results.value.invoices) {
    out.push({ group: 'invoice', label: i.varsymbol || `#${i.id}`, sub: i.company_name, icon: ICON_DOC, accent: 'primary', run: () => void workspaceNavigation.navigate(`/invoices/${i.id}`) })
  }
  for (const p of results.value.purchase_invoices) {
    out.push({ group: 'purchase', label: p.varsymbol || p.vendor_invoice_number || `#${p.id}`, sub: p.company_name, icon: ICON_CART, accent: 'warning', run: () => void workspaceNavigation.navigate(`/purchase-invoices/${p.id}`) })
  }

  return out
})

function groupHeaderFor(i: number): string | null {
  const opt = options.value[i]
  if (!opt) return null
  if (i === 0 || options.value[i - 1].group !== opt.group) return t(GROUP_LABEL[opt.group])
  return null
}

watch(q, (val) => {
  activeIndex.value = 0
  clearTimeout(debounceTimer)
  const text = val.trim()
  if (text.length < 2) {
    results.value = { q: text, clients: [], invoices: [], purchase_invoices: [] }
    loading.value = false
    return
  }
  loading.value = true
  debounceTimer = setTimeout(async () => {
    const mySeq = ++seq
    try {
      const r = await searchApi.query(text)
      if (mySeq === seq) results.value = r
    } catch {
      if (mySeq === seq) results.value = { q: text, clients: [], invoices: [], purchase_invoices: [] }
    } finally {
      if (mySeq === seq) loading.value = false
    }
  }, 220)
})

async function show() {
  // Jakmile paletu jednou otevřel, tip na ni v patičce mizí — už ji zná.
  markTipUsed('palette')
  open.value = true
  q.value = ''
  activeIndex.value = 0
  results.value = { q: '', clients: [], invoices: [], purchase_invoices: [] }
  await nextTick()
  inputEl.value?.focus()
}

function hide() {
  open.value = false
  clearTimeout(debounceTimer)
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

onBeforeUnmount(() => clearTimeout(debounceTimer))
defineExpose({ show })
</script>

<template>
  <Teleport to="body">
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
  </Teleport>
</template>
