import type { PaneCount, PaneId, WorkspacePaneState } from '@/stores/workspace'

export function rightPanePlan(
  sourceId: PaneId,
  panes: Pick<WorkspacePaneState, 'id'>[],
  maximum: PaneCount,
): { paneId: PaneId; count: PaneCount } | null {
  const index = panes.findIndex(pane => pane.id === sourceId)
  if (index < 0) return null
  const next = panes[index + 1]
  if (next) return { paneId: next.id, count: panes.length as PaneCount }
  if (panes.length >= maximum) return null
  const count = (panes.length + 1) as PaneCount
  return { paneId: count === 2 ? 'secondary-2' : 'secondary-3', count }
}

export function paneForBeside(
  currentPaneId: PaneId,
  panes: Pick<WorkspacePaneState, 'id' | 'fullPath'>[],
): PaneId {
  const index = panes.findIndex(pane => pane.id === currentPaneId)
  return paneForCtrlClick(currentPaneId, panes)
    ?? panes.find(pane => pane.id !== currentPaneId && !pane.fullPath)?.id
    ?? panes[(index + 1) % panes.length]?.id ?? currentPaneId
}

export function paneForCtrlClick(
  currentPaneId: PaneId,
  panes: Pick<WorkspacePaneState, 'id' | 'fullPath'>[],
): PaneId | null {
  const currentIndex = panes.findIndex(pane => pane.id === currentPaneId)
  if (currentIndex < 0) return null
  const panesToRight = panes.slice(currentIndex + 1)
  return panesToRight.find(pane => !pane.fullPath)?.id ?? panesToRight[0]?.id ?? null
}

export function prepareExternalAnchorNavigation(event: MouseEvent, origin = window.location.origin): boolean {
  if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return false
  const anchor = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null
  if (!anchor || anchor.hasAttribute('target') || anchor.hasAttribute('download')) return false

  let url: URL
  try {
    url = new URL(anchor.href, origin)
  } catch {
    return false
  }
  if (!['http:', 'https:'].includes(url.protocol) || url.origin === origin) return false

  anchor.target = '_blank'
  anchor.relList.add('noopener')
  return true
}

export function internalRouteFromAnchor(anchor: HTMLAnchorElement, origin = window.location.origin): string | null {
  if (anchor.hasAttribute('download')) return null
  if (anchor.target && anchor.target !== '_self') return null

  const url = new URL(anchor.href, origin)
  if (url.origin !== origin || !['http:', 'https:'].includes(url.protocol)) return null
  return `${url.pathname}${url.search}${url.hash}`
}

export function internalRouteFromElement(element: Element): string | null {
  const anchor = element.closest<HTMLAnchorElement>('a[href]')
  if (anchor) return internalRouteFromAnchor(anchor)
  if (element.closest('button, input, select, textarea, [contenteditable="true"]')) return null
  return element.closest<HTMLElement>('[data-workspace-route]')?.dataset.workspaceRoute ?? null
}
