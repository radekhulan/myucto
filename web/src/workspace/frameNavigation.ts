import { nextTick } from 'vue'
import type { Router, RouteLocationRaw } from 'vue-router'
import { internalRouteFromElement, prepareExternalAnchorNavigation } from './panelLinks'

export function installFrameNavigation(router: Router, traverse: (direction: -1 | 1) => void, resetScroll: () => void): void {
  const history = router.options.history
  const push = history.push.bind(history)
  const replace = history.replace.bind(history)
  const go = router.go.bind(router)
  let length = 1
  const position = () => Number(history.state.workspaceFramePosition ?? 0)
  history.replace = (to, data) => replace(to, { ...data, workspaceFramePosition: position() })
  history.replace(history.location, history.state)
  history.push = (to, data) => {
    const next = position() + 1
    push(to, { ...data, workspaceFramePosition: next })
    length = next + 1
  }
  router.go = delta => {
    if (delta === 0) return
    if (position() + delta >= 0 && position() + delta < length) go(delta)
    else traverse(delta < 0 ? -1 : 1)
  }
  router.back = () => router.go(-1)
  router.forward = () => router.go(1)
  router.afterEach((to, from, failure) => {
    if (!failure && to.path !== from.path) void nextTick(resetScroll)
  })
}

export function internalAnchorNavigation(
  router: Router,
  event: MouseEvent,
  canOpenRight: boolean,
  canOpenRightWithSplit = false,
): { to: RouteLocationRaw; newPane?: boolean; placement?: 'beside' | 'right' | 'tab' } | null {
  if (prepareExternalAnchorNavigation(event)) return null
  if (event.defaultPrevented || event.button !== 0 || event.altKey || event.metaKey) return null
  if (event.shiftKey && event.ctrlKey) return null
  // Ctrl+klik otevře panel napravo (a přidá ho, pokud chybí), Shift+klik novou záložku.
  if (event.ctrlKey && !canOpenRight && !canOpenRightWithSplit) return null
  const newPane = event.ctrlKey && canOpenRight
  const right = event.ctrlKey && !canOpenRight
  const to = event.target instanceof Element ? internalRouteFromElement(event.target) : null
  if (!to) return null
  try {
    const beside = !event.ctrlKey && !event.shiftKey && event.target instanceof Element
      && event.target.closest('a[data-workspace-open="beside"]') !== null
    const placement = event.shiftKey ? 'tab' as const : right ? 'right' as const : beside ? 'beside' as const : undefined
    return router.resolve(to).matched.length
      ? { to, ...(newPane ? { newPane: true } : {}), ...(placement ? { placement } : {}) } : null
  } catch {
    return null
  }
}

export function installShiftClickNavigation(
  router: Router,
  canOpen: (event: MouseEvent) => boolean,
  open: (to: RouteLocationRaw, event: MouseEvent) => void,
  target: Document = document,
): () => void {
  const click = (event: MouseEvent) => {
    if (!event.shiftKey || !canOpen(event)) return
    const navigation = internalAnchorNavigation(router, event, false, false)
    if (navigation?.placement !== 'tab'
      || !router.resolve(navigation.to).matched.some(record => record.meta.requiresAuth)) return
    event.preventDefault()
    event.stopImmediatePropagation()
    open(navigation.to, event)
  }
  target.addEventListener('click', click, true)
  return () => target.removeEventListener('click', click, true)
}
