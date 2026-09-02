import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import { ensurePrefsLoaded, getPagePrefs, patchPagePrefs } from '@/composables/useUserPrefs'

/**
 * Náhled originálu dokladu vedle obsahu místo pod ním.
 *
 * PROČ PRÁH `2xl` (1536 px) A NE `xl` (1280 px):
 * mřížky polí uvnitř detailu i editoru se lámou podle ŠÍŘKY OKNA (`sm:grid-cols-2`,
 * `md:grid-cols-3`), ne podle šířky svého kontejneru. Zúžení sloupce s dokladem jim tedy
 * nijak nepomůže — tříslupcový řádek polí zůstane tříslupcový a jen se zmáčkne.
 * Při 1280 px zbude po odečtení bočního menu (`lg:w-60`, 240 px), odsazení stránky
 * (`px-8`, 64 px), mezery a použitelného náhledu (min. 22 rem) na doklad ~570 px — pod tím,
 * s čím `md:` mřížky počítají. Od 1536 px výš zbývá ~720 px a rozložení drží.
 * Pod prahem se nic nemění: náhled se rozbalí pod dokladem jako dosud.
 *
 * Náhled se vykresluje jen jednou — buď vedle, nebo pod dokladem. Proto to řídí
 * media query v JS a ne dvojice `2xl:hidden` / `hidden 2xl:block`: ta by v DOM nechala
 * dva `<iframe>`y a prohlížeč by PDF stáhl dvakrát.
 */
export const SIDE_PREVIEW_MEDIA_QUERY = '(min-width: 1536px)'

/** Klíč lepkavého přepínače uvnitř `user_preferences` → `payload.flags`. */
export const SIDE_PREVIEW_FLAG = 'preview_open'

export interface SidePreviewCtrl {
  /** Chce uživatel náhled vidět? Lepkavé — drží se i po přechodu na další doklad. */
  open: Ref<boolean>
  /** Je okno dost široké na náhled vedle dokladu? */
  wide: Ref<boolean>
  setOpen: (next: boolean) => void
  toggle: () => void
  close: () => void
}

/**
 * @param pageKey klíč z `SavedFilterAction::PAGE_KEYS` (BE jiný nepřijme) — volba se
 *                pamatuje per agenda, aby si přijaté a vydané doklady nešahaly do stavu.
 */
export function useSidePreview(pageKey: string, flagKey: string = SIDE_PREVIEW_FLAG): SidePreviewCtrl {
  const prefs = getPagePrefs(pageKey)
  const open = ref(false)
  const wide = ref(false)
  let mql: MediaQueryList | null = null

  function onMediaChange(event: MediaQueryListEvent): void {
    wide.value = event.matches
  }

  onMounted(async () => {
    if (typeof window.matchMedia === 'function') {
      mql = window.matchMedia(SIDE_PREVIEW_MEDIA_QUERY)
      wide.value = mql.matches
      mql.addEventListener('change', onMediaChange)
    }
    // Lepkavá volba se čte až po ensurePrefsLoaded(), takže se náhled může otevřít
    // o tik později než první vykreslení. Výchozí stav zůstává zavřený.
    await ensurePrefsLoaded()
    const stored = prefs.value.flags?.[flagKey]
    if (typeof stored === 'boolean') open.value = stored
  })

  onBeforeUnmount(() => {
    mql?.removeEventListener('change', onMediaChange)
    mql = null
  })

  function setOpen(next: boolean): void {
    if (open.value === next) return
    open.value = next
    patchPagePrefs(pageKey, { flags: { ...(prefs.value.flags ?? {}), [flagKey]: next } })
  }

  return {
    open,
    wide,
    setOpen,
    toggle: () => setOpen(!open.value),
    close: () => setOpen(false),
  }
}
