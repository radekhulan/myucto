import { ref, computed, watch, type ComputedRef } from 'vue'
import { preferencesApi, type SavedFilter, type TablePrefs, type NavOrderPrefs, type DisplayPrefs } from '@/api/preferences'
import { DISPLAY_PREFS_KEY, setLoadedDisplayPrefs, storedDisplayPrefs } from '@/composables/useDisplayPrefs'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { i18n } from '@/i18n'

/**
 * Interní cache vrstva per-user UI stavu (ukládané filtry + preference tabulek).
 * Modul-level singleton (vzor useTheme.ts) — jeden GET na session, sdílený všemi stránkami.
 * Stránky na tento modul nesahají přímo, jdou přes useSavedFilters / useTablePrefs.
 */
const PREF_DEBOUNCE_MS = 800   // R14
const NAV_ORDER_KEY = 'nav.order'   // §10 — mimo 'table.' namespace

const prefsByPage  = ref<Record<string, TablePrefs>>({})   // klíč = page_key (bez 'table.')
const savedFilters = ref<SavedFilter[]>([])
const navOrder     = ref<NavOrderPrefs>({})                 // §10 — pořadí sidebar menu

let prefsLoaded = false
let filtersLoaded = false
let loadPromise: Promise<void> | null = null
let supplierWatchBound = false

const saveTimers: Record<string, ReturnType<typeof setTimeout>> = {}
let navSaveTimer: ReturnType<typeof setTimeout> | null = null
let displaySaveTimer: ReturnType<typeof setTimeout> | null = null

function stripTablePrefix(key: string): string {
  return key.startsWith('table.') ? key.slice('table.'.length) : key
}

function loadPrefs(): Promise<void> {
  return preferencesApi.getPreferences()
    .then((map) => {
      const out: Record<string, TablePrefs> = {}
      for (const [k, v] of Object.entries(map ?? {})) {
        if (k === NAV_ORDER_KEY) { navOrder.value = (v as unknown as NavOrderPrefs) ?? {}; continue }
        if (k === DISPLAY_PREFS_KEY) { setLoadedDisplayPrefs((v as unknown as DisplayPrefs) ?? {}); continue }
        if (k.startsWith('table.')) out[stripTablePrefix(k)] = v
      }
      prefsByPage.value = out
    })
    .catch(() => { /* GET selže → defaulty, bez toastu (R16/§5.3) */ })
    .finally(() => { prefsLoaded = true })
}

function loadFilters(): Promise<void> {
  return preferencesApi.listFilters()
    .then((list) => { savedFilters.value = Array.isArray(list) ? list : [] })
    .catch(() => { savedFilters.value = [] })
    .finally(() => { filtersLoaded = true })
}

function bindSupplierWatch(): void {
  if (supplierWatchBound) return
  supplierWatchBound = true
  const supplier = useSupplierStore()
  // Přepnutí firmy invaliduje JEN cache filtrů (per supplier — R3); prefs jsou globální.
  watch(() => supplier.currentSupplierId, () => { invalidateFilters() })
}

/** Idempotentní; GET preferences + GET filters paralelně, jediný request na session. */
export function ensurePrefsLoaded(): Promise<void> {
  bindSupplierWatch()
  if (!loadPromise) {
    const tasks: Promise<void>[] = []
    if (!prefsLoaded) tasks.push(loadPrefs())
    if (!filtersLoaded) tasks.push(loadFilters())
    loadPromise = Promise.all(tasks).then(() => undefined)
  }
  return loadPromise
}

/** Volá se při přepnutí firmy — zahodí cache filtrů a znovu je natáhne pro nový scope. */
export function invalidateFilters(): void {
  filtersLoaded = false
  savedFilters.value = []
  loadPromise = null
  void ensurePrefsLoaded()
}

export function getPagePrefs(pageKey: string): ComputedRef<TablePrefs> {
  return computed(() => prefsByPage.value[pageKey] ?? {})
}

/** Optimistic zápis + debounced PUT 800 ms (R14); per pageKey vlastní timer. */
export function patchPagePrefs(pageKey: string, patch: Partial<TablePrefs>): void {
  const current = prefsByPage.value[pageKey] ?? {}
  prefsByPage.value = { ...prefsByPage.value, [pageKey]: { ...current, ...patch } }
  if (saveTimers[pageKey]) clearTimeout(saveTimers[pageKey])
  saveTimers[pageKey] = setTimeout(() => { void flushSave(pageKey) }, PREF_DEBOUNCE_MS)
}

function flushSave(pageKey: string): Promise<void> {
  delete saveTimers[pageKey]
  return preferencesApi.putPreference(pageKey, prefsByPage.value[pageKey] ?? {})
    .then(() => undefined)
    .catch(() => { useToast().warning(i18n.global.t('common.save_failed')) })
}

export function filtersFor(pageKey: string): ComputedRef<SavedFilter[]> {
  return computed(() =>
    savedFilters.value
      .filter(f => f.page_key === pageKey)
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)),
  )
}

// Lokálně vynuť exkluzivitu defaultu (BE ji drží transakčně — R5).
function applyDefaultExclusivity(f: SavedFilter): void {
  if (!f.is_default) return
  savedFilters.value = savedFilters.value.map(x =>
    x.page_key === f.page_key && x.id !== f.id ? { ...x, is_default: false } : x,
  )
}

export async function createSavedFilter(
  p: { page_key: string; name: string; payload: Record<string, string>; is_default?: boolean },
): Promise<SavedFilter> {
  const created = await preferencesApi.createFilter(p)
  savedFilters.value = [...savedFilters.value, created]
  applyDefaultExclusivity(created)
  return created
}

export async function updateSavedFilter(
  id: number,
  p: Partial<Pick<SavedFilter, 'name' | 'payload' | 'sort_order' | 'is_default'>>,
): Promise<SavedFilter> {
  const updated = await preferencesApi.updateFilter(id, p)
  const idx = savedFilters.value.findIndex(f => f.id === id)
  if (idx !== -1) {
    const next = savedFilters.value.slice()
    next[idx] = updated
    savedFilters.value = next
  }
  applyDefaultExclusivity(updated)
  return updated
}

export async function deleteSavedFilter(id: number): Promise<void> {
  await preferencesApi.deleteFilter(id)
  savedFilters.value = savedFilters.value.filter(f => f.id !== id)
}

// ── §10: pořadí sidebar menu (nav.order) ─────────────────────────────────────

export function getNavOrder(): ComputedRef<NavOrderPrefs> {
  return computed(() => navOrder.value)
}

/** Optimistic zápis + debounced PUT 800 ms (R14), stejný vzor jako patchPagePrefs. */
export function patchNavOrder(next: NavOrderPrefs): void {
  navOrder.value = next
  if (navSaveTimer) clearTimeout(navSaveTimer)
  navSaveTimer = setTimeout(() => { void flushNavOrder() }, PREF_DEBOUNCE_MS)
}

function flushNavOrder(): Promise<void> {
  navSaveTimer = null
  return preferencesApi.putPreferenceKey(NAV_ORDER_KEY, navOrder.value)
    .then(() => undefined)
    .catch(() => { useToast().warning(i18n.global.t('common.save_failed')) })
}

/** Reset na výchozí pořadí = DELETE klíče; optimistic vyprázdnění cache. */
export async function resetNavOrder(): Promise<void> {
  navOrder.value = {}
  if (navSaveTimer) { clearTimeout(navSaveTimer); navSaveTimer = null }
  try {
    await preferencesApi.deletePreferenceKey(NAV_ORDER_KEY)
  } catch { /* 404 = už výchozí, ignoruj */ }
}

// ── Předvolby zobrazení (display.defaults, stav v useDisplayPrefs) ────────────

/** Optimistic zápis + debounced PUT 800 ms (R14), stejný vzor jako patchPagePrefs. */
export function patchDisplayPrefs(patch: DisplayPrefs): void {
  storedDisplayPrefs.value = { ...storedDisplayPrefs.value, ...patch }
  if (displaySaveTimer) clearTimeout(displaySaveTimer)
  displaySaveTimer = setTimeout(() => { void flushDisplayPrefs() }, PREF_DEBOUNCE_MS)
}

function flushDisplayPrefs(): Promise<void> {
  displaySaveTimer = null
  return preferencesApi.putPreferenceKey(DISPLAY_PREFS_KEY, storedDisplayPrefs.value)
    .then(() => undefined)
    .catch(() => { useToast().warning(i18n.global.t('common.save_failed')) })
}

/** Návrat všech předvoleb zobrazení k výchozím = DELETE klíče. */
export async function resetDisplayPrefs(): Promise<void> {
  storedDisplayPrefs.value = {}
  if (displaySaveTimer) { clearTimeout(displaySaveTimer); displaySaveTimer = null }
  try {
    await preferencesApi.deletePreferenceKey(DISPLAY_PREFS_KEY)
  } catch { /* 404 = už výchozí, ignoruj */ }
}
