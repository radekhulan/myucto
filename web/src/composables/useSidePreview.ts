import { onMounted, ref, type Ref } from 'vue'
import { ensurePrefsLoaded, getPagePrefs, patchPagePrefs } from '@/composables/useUserPrefs'

/**
 * Stav náhledu originálu dokladu: otevřeno/zavřeno, umístění a šířka.
 *
 * Volba se ukládá přes `user_preferences` (`table.<pageKey>`), takže platí pro uživatele
 * napříč zařízeními. Rozložení na obrazovce řeší `DocumentPreviewSplit`, tady je jen stav.
 */
/** Klíč lepkavého přepínače uvnitř `user_preferences` → `payload.flags`. */
export const SIDE_PREVIEW_FLAG = 'preview_open'

export type SidePreviewPlacement = 'side' | 'below'

export interface SidePreviewCtrl {
  /** Chce uživatel náhled vidět? Lepkavé — drží se i po přechodu na další doklad. */
  open: Ref<boolean>
  placement: Ref<SidePreviewPlacement>
  /** Šířka náhledu v procentech šířky rozložení; `null` = výchozí. */
  width: Ref<number | null>
  setOpen: (next: boolean) => void
  toggle: () => void
  close: () => void
  /** Otevře náhled jen pro tento doklad, uloženou volbu nemění. */
  openTemporarily: () => void
  setPlacement: (next: SidePreviewPlacement) => void
  setWidth: (percent: number | null) => void
}

function validWidth(value: unknown): number | null {
  return typeof value === 'number' && value > 0 && value < 100 ? value : null
}

/**
 * @param pageKey klíč z `SavedFilterAction::PAGE_KEYS` (BE jiný nepřijme) — volba se
 *                pamatuje per agenda, aby si přijaté a vydané doklady nešahaly do stavu.
 * @param flagKey přepínač otevření; umístění a šířka se ukládají pod stejným základem,
 *                aby si okno kontroly vytěžení a detail dokladu nepřepisovaly rozložení.
 */
export function useSidePreview(
  pageKey: string,
  flagKey: string = SIDE_PREVIEW_FLAG,
  options: { defaultOpen?: boolean } = {},
): SidePreviewCtrl {
  const prefs = getPagePrefs(pageKey)
  const open = ref(options.defaultOpen ?? false)
  const placement = ref<SidePreviewPlacement>('side')
  const width = ref<number | null>(null)
  const optionBase = flagKey.replace(/_open$/, '')
  const placementKey = `${optionBase}_placement`
  const widthKey = `${optionBase}_width`
  let openDecided = false

  onMounted(async () => {
    // Lepkavá volba se čte až po ensurePrefsLoaded(), takže se náhled může otevřít
    // o tik později než první vykreslení. Bez uložené volby platí `defaultOpen`.
    await ensurePrefsLoaded()
    const stored = prefs.value.flags?.[flagKey]
    if (typeof stored === 'boolean' && !openDecided) open.value = stored
    if (prefs.value.options?.[placementKey] === 'below') placement.value = 'below'
    width.value = validWidth(prefs.value.options?.[widthKey])
  })

  function setOpen(next: boolean): void {
    openDecided = true
    if (open.value === next) return
    open.value = next
    patchPagePrefs(pageKey, { flags: { ...(prefs.value.flags ?? {}), [flagKey]: next } })
  }

  function patchOption(key: string, value: unknown): void {
    patchPagePrefs(pageKey, { options: { ...(prefs.value.options ?? {}), [key]: value } })
  }

  return {
    open,
    placement,
    width,
    setOpen,
    toggle: () => setOpen(!open.value),
    close: () => setOpen(false),
    openTemporarily: () => {
      openDecided = true
      open.value = true
    },
    setPlacement: (next) => {
      if (placement.value === next) return
      placement.value = next
      patchOption(placementKey, next)
    },
    setWidth: (percent) => {
      if (width.value === percent) return
      width.value = percent
      patchOption(widthKey, percent)
    },
  }
}
