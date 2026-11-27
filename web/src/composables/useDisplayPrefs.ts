import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import type { DisplayPrefs } from '@/api/preferences'

/**
 * Předvolby zobrazení: výchozí podoba sekcí a přepínačů, kterou si uživatel nastaví
 * jednou (stránka Předvolby zobrazení) a platí na všech zařízeních. Stránka z ní
 * bere jen počáteční stav; ruční rozbalení nebo přepnutí na stránce předvolbu nemění.
 */
export type ResolvedDisplayPrefs = Required<DisplayPrefs>

/**
 * Klíč v `user_preferences`. Načítání a ukládání obstarává `useUserPrefs` (společný GET,
 * debounced PUT); tenhle modul drží jen stav, aby ho komponenty mohly číst bez závislosti
 * na API vrstvě.
 */
export const DISPLAY_PREFS_KEY = 'display.defaults'

/** Uložené předvolby tak, jak přišly ze serveru (bez doplněných výchozích hodnot). */
export const storedDisplayPrefs = ref<DisplayPrefs>({})

export function setLoadedDisplayPrefs(value: DisplayPrefs | null | undefined): void {
  storedDisplayPrefs.value = value && typeof value === 'object' ? value : {}
}

/** Výchozí hodnoty = chování aplikace bez nastavené předvolby. */
export const DISPLAY_DEFAULTS: ResolvedDisplayPrefs = {
  posting_open: false,
  dimensions_open: false,
  preview_open: false,
  preview_placement: 'side',
  statement_hide_zero: true,
  statement_analytics: true,
  statement_unit: 'czk',
  estimate_open: false,
}

/** Uložené předvolby doplněné o výchozí hodnoty (neplatné hodnoty se ignorují). */
export function resolveDisplayPrefs(stored: DisplayPrefs | null | undefined): ResolvedDisplayPrefs {
  const out: ResolvedDisplayPrefs = { ...DISPLAY_DEFAULTS }
  const src = stored ?? {}
  for (const key of ['posting_open', 'dimensions_open', 'preview_open', 'statement_hide_zero', 'statement_analytics', 'estimate_open'] as const) {
    if (typeof src[key] === 'boolean') out[key] = src[key] as boolean
  }
  if (src.preview_placement === 'side' || src.preview_placement === 'below') out.preview_placement = src.preview_placement
  if (src.statement_unit === 'czk' || src.statement_unit === 'thousands') out.statement_unit = src.statement_unit
  return out
}

export function useDisplayPrefs(): ComputedRef<ResolvedDisplayPrefs> {
  return computed(() => resolveDisplayPrefs(storedDisplayPrefs.value))
}

/** Načtení předvoleb; bez přihlášení nebo mimo aplikaci (testy) tiše platí výchozí hodnoty. */
export function displayPrefsReady(): Promise<void> {
  // Dynamicky: komponenty s předvolbou se tak dají vykreslit i bez API a i18n vrstvy.
  return import('@/composables/useUserPrefs')
    .then(m => m.ensurePrefsLoaded())
    .catch(() => undefined)
}

/**
 * Lokální stav stránky s počáteční hodnotou z předvoleb. Když se předvolby načtou až
 * po vykreslení, hodnota se doplní — ale jen dokud ji uživatel na stránce sám nezměnil.
 * `onLateChange` se zavolá, když se tím hodnota opravdu změnila (stránka si podle ní
 * může znovu načíst data).
 */
export function useDisplayDefault<K extends keyof ResolvedDisplayPrefs>(
  key: K,
  onLateChange?: (value: ResolvedDisplayPrefs[K]) => void,
): Ref<ResolvedDisplayPrefs[K]> {
  const prefs = useDisplayPrefs()
  const value = ref(prefs.value[key]) as Ref<ResolvedDisplayPrefs[K]>
  let touched = false
  const stop = watch(value, () => { touched = true }, { flush: 'sync' })
  void displayPrefsReady().then(() => {
    stop()
    const next = prefs.value[key]
    if (touched || value.value === next) return
    value.value = next
    onLateChange?.(next)
  })
  return value
}
