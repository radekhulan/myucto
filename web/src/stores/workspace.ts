import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { readWindowTabsHidden, storeWindowTabsHidden } from '@/workspace/windowTabs'

export type PaneCount = 1 | 2 | 3
export type PaneId = 'primary' | 'secondary-2' | 'secondary-3'
export const MAX_WORKSPACE_WINDOWS = 20

export interface WorkspacePaneState {
  id: PaneId
  kind: 'primary' | 'secondary'
  title: string | null
  fullPath: string | null
  loading: boolean
  canGoBack: boolean
  canGoForward: boolean
}

export interface WorkspaceWindowState {
  id: string
  fullPath: string
  title: string
  paneId: PaneId | null
  subtitle?: string | null
  supplierId?: number
  supplierName?: string
  /** Pořadí otevření pro lištu záložek; obnovené okno si drží místo původního. */
  order?: number
  /** Záložka, do které okno patří: agenda a firma, nebo samostatná záložka otevřená uživatelem. */
  tab?: string
}

function createPane(id: PaneId, fullPath: string | null = null): WorkspacePaneState {
  return {
    id,
    kind: id === 'primary' ? 'primary' : 'secondary',
    title: null,
    fullPath,
    loading: false,
    canGoBack: false,
    canGoForward: false,
  }
}

export const useWorkspaceStore = defineStore('workspace', () => {
  const paneCount = ref<PaneCount>(1)
  const maximumPaneCount = ref<PaneCount>(1)
  const activePaneId = ref<PaneId>('primary')
  const layoutRevision = ref(0)
  const panes = ref<WorkspacePaneState[]>([createPane('primary')])
  const windows = ref<WorkspaceWindowState[]>([])
  const windowSwitcherOpen = ref(false)
  const companySwitching = ref(false)
  const windowTabsHidden = ref(readWindowTabsHidden())

  const activePane = computed(() => panes.value.find(pane => pane.id === activePaneId.value) ?? panes.value[0])
  const activeFullPath = computed(() => activePane.value?.fullPath ?? '/')

  function activatePane(id: PaneId): void {
    if (!panes.value.some(pane => pane.id === id)) return
    activePaneId.value = id
    const index = windows.value.findIndex(window => window.paneId === id)
    if (index > 0) windows.value.unshift(...windows.value.splice(index, 1))
  }

  function resetLayout(count: PaneCount, primaryPath: string): void {
    windows.value = []
    windowSwitcherOpen.value = false
    paneCount.value = count
    layoutRevision.value += 1
    activePaneId.value = 'primary'
    panes.value = [
      createPane('primary', primaryPath),
      ...(count >= 2 ? [createPane('secondary-2' as const)] : []),
      ...(count >= 3 ? [createPane('secondary-3' as const)] : []),
    ]
  }

  function resizeLayout(count: PaneCount): void {
    const expanding = count > paneCount.value
    const ids: PaneId[] = ['primary', ...(count >= 2 ? ['secondary-2' as const] : []), ...(count >= 3 ? ['secondary-3' as const] : [])]
    const existing = new Map(panes.value.map(pane => [pane.id, pane]))
    panes.value = ids.map(id => existing.get(id) ?? createPane(id))
    paneCount.value = count
    const firstEmptySecondary = expanding
      ? panes.value.find(pane => pane.kind === 'secondary' && !pane.fullPath)
      : undefined
    if (firstEmptySecondary) {
      activePaneId.value = firstEmptySecondary.id
    } else if (!panes.value.some(pane => pane.id === activePaneId.value)) {
      activePaneId.value = panes.value[panes.value.length - 1]!.id
    }
  }

  function updatePane(id: PaneId, patch: Partial<Omit<WorkspacePaneState, 'id' | 'kind'>>): void {
    const pane = panes.value.find(candidate => candidate.id === id)
    if (pane) Object.assign(pane, patch)
  }

  function removeEmptyPane(id: PaneId): boolean {
    const index = panes.value.findIndex(pane => pane.id === id)
    if (index <= 0 || panes.value.length <= 1 || panes.value[index]?.fullPath) return false
    panes.value.splice(index, 1)
    paneCount.value = panes.value.length as PaneCount
    if (activePaneId.value === id) activePaneId.value = panes.value[Math.max(0, index - 1)]!.id
    return true
  }

  function dropLastPane(): boolean {
    if (panes.value.length <= 1) return false
    const removed = panes.value.pop()!
    paneCount.value = panes.value.length as PaneCount
    if (activePaneId.value === removed.id) activePaneId.value = panes.value[panes.value.length - 1]!.id
    return true
  }

  function setMaximumPaneCount(count: PaneCount): void {
    maximumPaneCount.value = count
  }

  function setWindowTabsHidden(hidden: boolean): void {
    windowTabsHidden.value = hidden
    storeWindowTabsHidden(hidden)
  }

  return {
    paneCount,
    maximumPaneCount,
    activePaneId,
    layoutRevision,
    panes,
    windows,
    windowSwitcherOpen,
    companySwitching,
    windowTabsHidden,
    activePane,
    activeFullPath,
    activatePane,
    resetLayout,
    resizeLayout,
    updatePane,
    removeEmptyPane,
    dropLastPane,
    setMaximumPaneCount,
    setWindowTabsHidden,
  }
})
