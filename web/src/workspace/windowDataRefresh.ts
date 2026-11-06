import { computed, nextTick, ref, watch, type Ref } from 'vue'
import { windowDataProfile } from './dataDependencies'

/** Většina modálů v aplikaci je `fixed inset-0` bez role, spinner a překryv přetažení modál nejsou. */
const DIALOG_SELECTOR = '[role="dialog"], [aria-modal="true"], .fixed.inset-0:not([role="status"]):not(.pointer-events-none)'
const SCROLL_RESTORE_MS = 3000

export function isWindowDialogOpen(): boolean {
  return document.querySelector(DIALOG_SELECTOR) !== null
}

let refreshHandlers = 0

/** Stránka s vlastní tichou obnovou nahlásí, že ji není třeba znovu vytvářet. */
export function registerWindowRefreshHandler(): () => void {
  refreshHandlers++
  let registered = true
  return () => {
    if (!registered) return
    registered = false
    refreshHandlers--
  }
}

function editableValue(element: Element): string | null {
  if (element instanceof HTMLInputElement) {
    if (['button', 'submit', 'reset', 'image', 'hidden'].includes(element.type)) return null
    return element.type === 'checkbox' || element.type === 'radio' ? String(element.checked) : element.value
  }
  if (element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement) return element.value
  if (element instanceof HTMLElement && element.isContentEditable) return element.textContent ?? ''
  return null
}

/**
 * Rozepsaná práce mimo modál: prvek, jehož hodnota se od prvního fokusu liší a který
 * je pořád v DOMu. Zavřený inline editor nebo vrácená hodnota tak nebrání obnově.
 * Obsah modálu se nesleduje, otevřený modál obnovu zdrží sám a po uložení zmizí.
 */
export function createEditTracker(target: Document = document) {
  const baselines = new Map<Element, string>()

  function remember(event: Event): void {
    const element = event.target
    if (!(element instanceof Element) || baselines.has(element) || element.closest(DIALOG_SELECTOR)) return
    const value = editableValue(element)
    if (value !== null) baselines.set(element, value)
  }

  function changed(event: Event): void {
    const element = event.target
    if (!(element instanceof Element) || baselines.has(element) || element.closest(DIALOG_SELECTOR)) return
    // Bez fokusu předchozí hodnotu neznáme, změna se proto bere jako rozepsaná.
    if (editableValue(element) !== null) baselines.set(element, '\0')
  }

  target.addEventListener('focusin', remember, true)
  target.addEventListener('input', changed, true)
  target.addEventListener('change', changed, true)

  return {
    isDirty(): boolean {
      for (const [element, baseline] of baselines) {
        if (!element.isConnected) baselines.delete(element)
        else if (editableValue(element) !== baseline) return true
      }
      return false
    },
    reset(): void { baselines.clear() },
    destroy(): void {
      target.removeEventListener('focusin', remember, true)
      target.removeEventListener('input', changed, true)
      target.removeEventListener('change', changed, true)
    },
  }
}

function editableFocused(): boolean {
  const element = document.activeElement
  return element !== null && element !== document.body && editableValue(element) !== null
}

/** Nově vykreslená stránka narůstá postupně, pozice se vrátí, jakmile je kam. Vstup uživatele má přednost. */
function restoreScroll(top: number, left: number): void {
  if (top <= 0 && left <= 0) return
  const started = performance.now()
  let interrupted = false
  const interrupt = () => { interrupted = true }
  const events = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const
  for (const type of events) window.addEventListener(type, interrupt, { capture: true, passive: true, once: true })
  const stop = () => { for (const type of events) window.removeEventListener(type, interrupt, { capture: true }) }
  const step = () => {
    if (interrupted || performance.now() - started > SCROLL_RESTORE_MS) { stop(); return }
    if (document.documentElement.scrollHeight - window.innerHeight >= top) {
      window.scrollTo({ top, left, behavior: 'instant' })
      stop()
      return
    }
    requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

/** Ponechá pozici posuvníku, když obnova dat dočasně zkrátí stránku. */
export async function keepWindowScroll<T>(work: () => T | Promise<T>): Promise<T> {
  const top = window.scrollY
  const left = window.scrollX
  const result = await work()
  await nextTick()
  if (window.scrollY !== top || window.scrollX !== left) restoreScroll(top, left)
  return result
}

interface WindowDataRefreshOptions {
  path: () => string
  visible: Ref<boolean>
  remount: () => void
}

/**
 * Obnova stránek bez vlastního `useWindowRefresh` v okně pracovní plochy. Stránka, která
 * jen zobrazuje data, se znovu vytvoří, jakmile nic nebrání. Stránka, která může držet
 * rozepsanou práci, jen ukáže značku a obnovu nechá na uživateli.
 */
export function installWindowDataRefresh(options: WindowDataRefreshOptions) {
  const edits = createEditTracker()
  const stale = ref(false)
  const dirty = ref(false)
  const manual = ref(false)
  const fromElsewhere = ref(false)
  let observer: MutationObserver | undefined

  function stopWaiting(): void {
    observer?.disconnect()
    observer = undefined
  }

  function clear(): void {
    stale.value = false
    dirty.value = false
    manual.value = false
    fromElsewhere.value = false
    stopWaiting()
  }

  function reload(): void {
    const top = window.scrollY
    const left = window.scrollX
    clear()
    edits.reset()
    options.remount()
    restoreScroll(top, left)
  }

  function settle(): void {
    if (!stale.value) return
    manual.value = windowDataProfile(options.path()).mode === 'manual'
    dirty.value = edits.isDirty()
    if (manual.value || dirty.value || !options.visible.value) return
    if (isWindowDialogOpen()) {
      if (observer) return
      observer = new MutationObserver(() => {
        if (isWindowDialogOpen()) return
        stopWaiting()
        settle()
      })
      observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'aria-modal'] })
      return
    }
    if (editableFocused()) return
    reload()
  }

  function changed(event: Event): void {
    const detail = (event as CustomEvent<{ self?: boolean } | null>).detail
    if (refreshHandlers > 0) return
    // Vlastní formulář si své uložení obsloužil sám, značka by po uložení jen mátla.
    if (detail?.self && windowDataProfile(options.path()).mode === 'manual') return
    stale.value = true
    if (!detail?.self) fromElsewhere.value = true
    settle()
  }

  // Fokus se přesouvá až po `focusout`, stav se proto čte v dalším kroku.
  const later = () => { if (stale.value) window.setTimeout(settle) }
  window.addEventListener('myucto:window-data-changed', changed)
  for (const type of ['focusout', 'input', 'change']) document.addEventListener(type, later, true)
  const stopVisible = watch(options.visible, settle)

  return {
    /** Značka patří ke změně odjinud, o které rozhoduje uživatel. Čekání na zavření modálu nebo vlastní uložení ji neukáže. */
    showNotice: computed(() => stale.value && fromElsewhere.value && (manual.value || dirty.value)),
    reload,
    /** Přechod na jinou stránku načetl data znovu a rozepsanou práci opustil. */
    routeChanged(): void {
      clear()
      edits.reset()
    },
    destroy(): void {
      stopWaiting()
      stopVisible()
      edits.destroy()
      window.removeEventListener('myucto:window-data-changed', changed)
      for (const type of ['focusout', 'input', 'change']) document.removeEventListener(type, later, true)
    },
  }
}
