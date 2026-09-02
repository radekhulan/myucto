import { computed, readonly, ref } from 'vue'
import { preferencesApi } from '@/api/preferences'
import { useAuthStore } from '@/stores/auth'
import { isApplePlatform } from '@/utils/clientPlatform'

export type ShortcutGroup = 'general' | 'menu' | 'create'

export interface ShortcutAction {
  id: string
  label: string
  group: ShortcutGroup
  to?: string
  external?: boolean
}

interface ShortcutPreference {
  version: 1
  overrides: Record<string, string | null>
}

const PREFERENCE_KEY = 'keyboard.shortcuts'

/**
 * Výchozí zkratky. Zakládání dokladů drží číselnou řadu (alt+1…), skoky do agend
 * mají písmenné mnemotechnické zkratky podle popisku v menu.
 *
 * Proč ne alt+f / alt+e: to si v Chrome a Edge bere menu prohlížeče. Proč ne alt+d
 * pro Dokumenty: adresní řádek, viz RESERVED_SHORTCUTS —
 * proto druhé písmeno, alt+o.
 */
const DEFAULT_SHORTCUTS: Record<string, string> = {
  'search.global': 'alt+q',
  'new:/invoices/new': 'alt+1',
  'new:/purchase-invoices/new': 'alt+2',
  'new:/clients/new': 'alt+3',
  'new:/clients/new?role=vendor': 'alt+4',
  'new:/accounting/journal/new': 'alt+5',
  'new:/recurring/new': 'alt+6',
  'nav:/portfolio': 'alt+7',
  'create.contextual': 'alt+n',            // Nový záznam v agendě, kde právě jsem
  'nav:/invoices': 'alt+v',                // Vydané faktury
  'nav:/purchase-invoices': 'alt+p',       // Přijaté faktury
  'nav:/clients': 'alt+k',                 // Klienti
  'nav:/documents': 'alt+o',               // dOkumenty
  'nav:/accounting/journal': 'alt+u',      // Účetní deník
  'nav:/bank': 'alt+b',                    // Bankovní účty
  'nav:/accounting/cash': 'alt+c',         // Pokladna (Cash)
  'nav:/recurring': 'alt+r',               // Pravidelné fakturace
  'nav:/logbook': 'alt+j',                 // Kniha Jízd
  'nav:/': 'alt+h',                        // Přehled (Home)
  'nav:/clients?role=vendors': 'alt+a',    // dodAvatelé — D i O jsou obsazené
  'new:/stock/items/new': 'alt+s',         // nová Skladová karta
  'new:/logbook?tab=fuel&new=fuel': 'alt+t',    // Tankování
  'new:/logbook?tab=trips&new=trip': 'alt+z',   // jíZda — J drží Kniha jízd, T tankování
}

export function defaultShortcutFor(id: string, apple = isApplePlatform()): string {
  // Paleta příkazů pod Cmd+K už umí hledat v menu, klientech i dokladech.
  // Samostatný Option+Q default by na Macu nebyl obvyklý a zvyšoval riziko
  // nechtěného Cmd+Q. Uživatel si přímé hledání může přesto namapovat.
  if (apple && id === 'search.global') return ''
  return DEFAULT_SHORTCUTS[id] ?? ''
}

const actions = ref<ShortcutAction[]>([])
const overrides = ref<Record<string, string | null>>({})
const loaded = ref(false)
const loading = ref(false)
const loadedUserId = ref<number | null>(null)

const RESERVED_SHORTCUTS = new Set([
  'ctrl+n', 'ctrl+s', 'ctrl+l', 'ctrl+t', 'ctrl+w', 'ctrl+r', 'ctrl+f', 'ctrl+p',
  'ctrl+alt+1', 'ctrl+alt+2', 'ctrl+alt+3',
  'alt+shift+1', 'alt+shift+2', 'alt+shift+3',
  // alt+f a alt+e otevírají hlavní menu Chrome a Edge, alt+d skáče do adresního
  // řádku — aplikace se ke stisku vůbec nedostane. Kontroluje se jen při zadávání
  // nové zkratky, takže komu se jedna z nich podařila uložit dřív, o ni nepřijde.
  'alt+d', 'alt+e', 'alt+f', 'alt+home', 'alt+arrowleft', 'alt+arrowright',
])

function normalizeKey(key: string): string {
  if (key === ' ') return 'space'
  if (key === 'esc') return 'escape'
  return key.toLowerCase()
}

function shortcutKeyFromEvent(event: KeyboardEvent): string {
  const digitMatch = /^Digit([0-9])$/.exec(event.code)
  if (digitMatch) return digitMatch[1]

  const numpadMatch = /^Numpad([0-9])$/.exec(event.code)
  if (numpadMatch) return numpadMatch[1]

  // Option+písmeno na macOS vrací v `key` speciální znak nebo dead key. Fyzický
  // kód zachová zkratky Option+V, Option+P atd. funkční a zachytitelné.
  const letterMatch = /^Key([A-Z])$/.exec(event.code)
  if (letterMatch && (event.altKey || event.ctrlKey || event.metaKey)) {
    return letterMatch[1].toLowerCase()
  }

  return normalizeKey(event.key)
}

export function normalizeShortcut(combo: string): string {
  const parts = combo.toLowerCase().split('+').map(part => part.trim()).filter(Boolean)
  if (!parts.length) return ''
  const key = normalizeKey(parts.pop() ?? '')
  const modifiers = new Set(parts.map(part => part === 'cmd' || part === 'meta' ? 'ctrl' : part))
  return [
    modifiers.has('ctrl') ? 'ctrl' : '',
    modifiers.has('alt') ? 'alt' : '',
    modifiers.has('shift') ? 'shift' : '',
    key,
  ].filter(Boolean).join('+')
}

export function shortcutFromEvent(event: KeyboardEvent): string {
  if (event.getModifierState?.('AltGraph')) return ''
  const key = shortcutKeyFromEvent(event)
  if (['control', 'alt', 'shift', 'meta'].includes(key)) return ''
  return [
    event.ctrlKey || event.metaKey ? 'ctrl' : '',
    event.altKey ? 'alt' : '',
    event.shiftKey ? 'shift' : '',
    key,
  ].filter(Boolean).join('+')
}

export function formatShortcut(combo: string, apple = isApplePlatform()): string {
  if (!combo) return '—'
  const labels: Record<string, string> = {
    ctrl: apple ? 'Cmd' : 'Ctrl',
    alt: apple ? 'Option' : 'Alt',
    shift: 'Shift',
    escape: 'Esc',
    space: 'Space',
  }
  return normalizeShortcut(combo)
    .split('+')
    .map(part => labels[part] ?? (part.length === 1 ? part.toUpperCase() : part))
    .join(' + ')
}

export function shortcutProblem(combo: string): 'modifier_required' | 'reserved' | null {
  const normalized = normalizeShortcut(combo)
  if (!normalized) return null
  const parts = normalized.split('+')
  if (!parts.includes('ctrl') && !parts.includes('alt')) return 'modifier_required'
  return RESERVED_SHORTCUTS.has(normalized) ? 'reserved' : null
}

export function useKeyboardShortcuts() {
  const auth = useAuthStore()
  const effectiveBindings = computed<Record<string, string>>(() => {
    const result: Record<string, string> = {}
    for (const action of actions.value) {
      result[action.id] = Object.prototype.hasOwnProperty.call(overrides.value, action.id)
        ? (overrides.value[action.id] ?? '')
        : defaultShortcutFor(action.id)
    }
    return result
  })

  function registerActions(next: ShortcutAction[]): void {
    const unique = new Map<string, ShortcutAction>()
    for (const action of next) unique.set(action.id, action)
    actions.value = [...unique.values()]
  }

  async function load(force = false): Promise<void> {
    const userId = Number(auth.user?.id ?? 0)
    if (loadedUserId.value !== userId) {
      loaded.value = false
      overrides.value = {}
    }
    if ((loaded.value && !force) || loading.value || userId <= 0) return
    loading.value = true
    try {
      const saved = await preferencesApi.getPreferenceKey<ShortcutPreference>(PREFERENCE_KEY)
      overrides.value = saved?.version === 1 && saved.overrides && typeof saved.overrides === 'object'
        ? saved.overrides
        : {}
      loadedUserId.value = userId
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  async function save(bindings: Record<string, string>): Promise<void> {
    const nextOverrides: Record<string, string | null> = { ...overrides.value }
    for (const action of actions.value) {
      const value = normalizeShortcut(bindings[action.id] ?? '')
      const defaultValue = defaultShortcutFor(action.id)
      if (value === defaultValue) {
        delete nextOverrides[action.id]
      } else {
        nextOverrides[action.id] = value || null
      }
    }
    await preferencesApi.putPreferenceKey<ShortcutPreference>(PREFERENCE_KEY, {
      version: 1,
      overrides: nextOverrides,
    })
    overrides.value = nextOverrides
    loadedUserId.value = Number(auth.user?.id ?? 0)
    loaded.value = true
  }

  async function reset(): Promise<void> {
    try {
      await preferencesApi.deletePreferenceKey(PREFERENCE_KEY)
    } catch (error: any) {
      if (error?.response?.status !== 404) throw error
    }
    overrides.value = {}
    loadedUserId.value = Number(auth.user?.id ?? 0)
    loaded.value = true
  }

  function actionForEvent(event: KeyboardEvent): ShortcutAction | undefined {
    const combo = shortcutFromEvent(event)
    if (!combo) return undefined
    return actions.value.find(action => effectiveBindings.value[action.id] === combo)
  }

  function comboFor(id: string): string {
    return effectiveBindings.value[id] ?? ''
  }

  return {
    actions: readonly(actions),
    loaded: readonly(loaded),
    loading: readonly(loading),
    effectiveBindings,
    registerActions,
    load,
    save,
    reset,
    actionForEvent,
    comboFor,
  }
}
