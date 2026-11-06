import { nextTick } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useWorkspaceStore, type PaneCount, type PaneId } from '@/stores/workspace'
import { getPaneRuntime } from '@/workspace/runtimeRegistry'
import { getWindowManager } from '@/workspace/windowRegistry'
import { usePaneId } from '@/workspace/paneActivity'
import { paneForBeside } from '@/workspace/panelLinks'

export function useWorkspaceNavigation() {
  const router = useRouter()
  const workspace = useWorkspaceStore()
  const paneId = usePaneId()

  async function openBeside(to: RouteLocationRaw): Promise<void> {
    const sourceId = paneId.value ?? workspace.activePaneId
    const targetId = paneForBeside(sourceId, workspace.panes)
    const manager = getWindowManager()
    if (manager) {
      await manager.open(to, targetId)
      return
    }
    const runtime = getPaneRuntime(targetId) ?? getPaneRuntime(sourceId)
    if (runtime) {
      await runtime.navigate(to)
      return
    }
    await router.push(to)
  }

  async function navigate(to: RouteLocationRaw, options?: { fresh?: boolean }): Promise<void> {
    // Správa instance má vlastní layout mimo pracovní plochu, router panelu ji nezná.
    if (router.resolve(to).matched.some(record => record.meta.instanceAdminOnly)) {
      await router.push(to)
      return
    }
    const manager = getWindowManager()
    if (options?.fresh && manager) {
      await manager.open(to, workspace.activePaneId, options)
      return
    }
    const runtime = getPaneRuntime(workspace.activePaneId)
    if (!runtime) {
      await router.push(to)
      return
    }
    await runtime.navigate(to)
  }

  function openExternal(url: string): void {
    window.open(url, '_blank', 'noopener')
  }

  function activatePane(id: PaneId): void {
    workspace.activatePane(id)
  }

  async function setPaneCount(count: PaneCount): Promise<void> {
    if (![1, 2, 3].includes(count)) throw new RangeError('Pane count must be 1, 2, or 3.')
    if (count === workspace.paneCount) return

    workspace.resizeLayout(count)
    getWindowManager()?.reconcile()
    await nextTick()
    const active = getPaneRuntime(workspace.activePaneId)
    active?.root.focus({ preventScroll: true })
    active?.focus?.()
  }

  function back(id: PaneId = workspace.activePaneId): void {
    getPaneRuntime(id)?.back()
  }

  function forward(id: PaneId = workspace.activePaneId): void {
    getPaneRuntime(id)?.forward()
  }

  function closePaneContent(id: PaneId = workspace.activePaneId): void {
    const pane = workspace.panes.find(candidate => candidate.id === id)
    if (!pane) return
    if (!pane.fullPath && workspace.removeEmptyPane(id)) return
    getPaneRuntime(id)?.clear()
  }

  async function closePane(id: PaneId = workspace.activePaneId): Promise<void> {
    getWindowManager()?.closePane(id)
    await nextTick()
    const active = getPaneRuntime(workspace.activePaneId)
    active?.root.focus({ preventScroll: true })
    active?.focus?.()
  }
  return { navigate, openBeside, openExternal, activatePane, setPaneCount, back, forward, closePaneContent, closePane }
}
