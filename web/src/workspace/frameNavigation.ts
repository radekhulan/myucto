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
): { to: RouteLocationRaw; newPane?: boolean; placement?: 'beside' | 'right' } | null {
  if (prepareExternalAnchorNavigation(event)) return null
  if (event.defaultPrevented || event.button !== 0 || event.altKey || event.metaKey) return null
  if (event.shiftKey && (event.ctrlKey || !canOpenRightWithSplit)) return null
  const newPane = event.ctrlKey
  if (newPane && !canOpenRight) return null
  const to = event.target instanceof Element ? internalRouteFromElement(event.target) : null
  if (!to) return null
  try {
    const beside = !newPane && !event.shiftKey && event.target instanceof Element
      && event.target.closest('a[data-workspace-open="beside"]') !== null
    return router.resolve(to).matched.length
      ? { to, ...(newPane ? { newPane: true } : {}), ...(event.shiftKey ? { placement: 'right' as const } : {}), ...(beside ? { placement: 'beside' as const } : {}) } : null
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
    if (!event.shiftKey) return
    const navigation = internalAnchorNavigation(router, event, false, canOpen(event))
    if (navigation?.placement !== 'right'
      || !router.resolve(navigation.to).matched.some(record => record.meta.requiresAuth)) return
    event.preventDefault()
    event.stopImmediatePropagation()
    open(navigation.to, event)
  }
  target.addEventListener('click', click, true)
  return () => target.removeEventListener('click', click, true)
}
