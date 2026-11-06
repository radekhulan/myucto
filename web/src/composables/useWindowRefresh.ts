import { inject, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'
import { getWindowSupplierScope } from '@/workspace/supplierContext'
import { isWindowDialogOpen, keepWindowScroll, registerWindowRefreshHandler } from '@/workspace/windowDataRefresh'

/**
 * Tichá obnova dat v okně pracovní plochy.
 *
 * Které změny okno zajímají, rozhoduje správce oken podle `workspace/dataDependencies.ts`
 * a událost `myucto:window-data-changed` pošle jen dotčeným oknům, skrytým až při
 * zobrazení. Tady se změna poznamená a `refresh` se zavolá, až je okno vidět
 * (a v `<KeepAlive>` aktivované), neběží jiná obnova, není otevřený modál a `canRefresh`
 * nehlásí rozepsaný formulář. Víc změn mezitím se slije do jedné obnovy.
 *
 * `refresh` má data obnovit potichu, bez celostránkového skeletonu: obsah
 * zůstane na obrazovce a jen se přepíše. Chyba obnovy nechá změnu čekat na
 * další příležitost.
 *
 * `partial` = obnovuje se jen část stránky (počítadlo, panel na detailu). Stránka bez
 * úplné obnovy se pak v okně znovu vytvoří celá podle pravidel `windowDataRefresh.ts`.
 *
 * Stránky bez composable nic nestojí: posluchač je jen tady a pozorovatel
 * DOMu běží výhradně po dobu, kdy obnova čeká na zavření modálu.
 */
export function useWindowRefresh(
  refresh: () => void | Promise<unknown>,
  canRefresh: () => boolean = () => true,
  options: { partial?: boolean } = {},
) {
  const context = inject(windowContextKey, null)
  const supplierId = getWindowSupplierScope()?.supplierId
  const visible = context?.visible ?? context?.active
  const pending = ref(false)
  const kept = ref(true)
  let running = false
  let disposed = false
  let observer: MutationObserver | undefined
  let unregister: (() => void) | undefined

  function ready() {
    return visible?.value === true && kept.value && canRefresh()
  }

  function stopWaiting() {
    observer?.disconnect()
    observer = undefined
  }

  function waitForDialog() {
    if (observer || disposed) return
    observer = new MutationObserver(() => {
      if (isWindowDialogOpen()) return
      stopWaiting()
      void flush()
    })
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'aria-modal'] })
  }

  async function flush() {
    if (disposed || running || !pending.value || !ready()) return
    if (isWindowDialogOpen()) {
      waitForDialog()
      return
    }
    stopWaiting()
    pending.value = false
    running = true
    let succeeded = false
    try {
      await keepWindowScroll(refresh)
      succeeded = true
    } catch {
      pending.value = true
    } finally {
      running = false
      if (succeeded) void flush()
    }
  }

  function changed(event: Event) {
    const detail = (event as CustomEvent<unknown>).detail
    if (!detail || typeof detail !== 'object') return
    const { supplierId: changedSupplier, resource } = detail as Record<string, unknown>
    if (changedSupplier !== supplierId || typeof resource !== 'string') return
    pending.value = true
    void flush()
  }

  function register() {
    if (!options.partial) unregister ??= registerWindowRefreshHandler()
  }

  function release() {
    unregister?.()
    unregister = undefined
  }

  if (context && supplierId) {
    watch([visible!, canRefresh], () => { void flush() }, { flush: 'post' })
    onMounted(() => {
      window.addEventListener('myucto:window-data-changed', changed)
      register()
    })
    onActivated(() => {
      kept.value = true
      register()
      void flush()
    })
    onDeactivated(() => {
      kept.value = false
      release()
    })
    onBeforeUnmount(() => {
      disposed = true
      window.removeEventListener('myucto:window-data-changed', changed)
      stopWaiting()
      release()
    })
  }

  return { pending }
}
