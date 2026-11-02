import { computed, createApp, nextTick, watch, type App } from 'vue'
import type { Pinia } from 'pinia'
import { isNavigationFailure, NavigationFailureType, type RouteLocationRaw, type Router } from 'vue-router'
import { i18n } from '@/i18n'
import { vMath } from '@/directives/vMath'
import { vSelectOnFocus } from '@/directives/vSelectOnFocus'
import { vColumnLabels } from '@/directives/columnLabels'
import { MAX_WORKSPACE_WINDOWS, useWorkspaceStore, type PaneId, type WorkspaceWindowState } from '@/stores/workspace'
import { windowContextKey } from './windowContext'
import PaneRoot from '@/components/workspace/PaneRoot.vue'
import { createFrameSurface, type FrameAppearance, type FrameKeyboardEvent } from './frameSurface'
import { paneForBeside, paneForCtrlClick, rightPanePlan } from './panelLinks'
import { getWindowManager, setWindowManager } from './windowRegistry'
import { manualSwitchDestination } from '@/composables/useSupplierSwitch'
import { changedAreas, isWindowAffected } from './dataDependencies'
export { getWindowManager } from './windowRegistry'

interface WindowRuntime {
  state: WorkspaceWindowState
  router: Router
  app: App | null
  root: HTMLElement
  portal: HTMLElement
  observer: MutationObserver | null
  removeAfterEach: () => void
  scrollTop: number
  scrollLeft: number
  focus: HTMLElement | null
  surface: ReturnType<typeof createFrameSurface> | null
  customTitle: string | null
  /** Změna dat čekající na doručení. Skryté okno ji dostane až při zobrazení. */
  staleChange: { resource: string, self: boolean } | null
}

interface WindowManagerOptions {
  pinia: Pinia
  createRouter: () => Router
  authorize: (router: Router, state?: WorkspaceWindowState) => Promise<boolean>
  onError: (error: unknown) => void
  frames?: boolean
  currentSupplier?: () => { id: number, name: string }
  switchSupplier?: (id: number) => Promise<boolean>
  appearance?: (fullPath: string) => Omit<FrameAppearance, 'active'>
  keyboard?: (event: FrameKeyboardEvent) => void
  globalNavigate?: (to: RouteLocationRaw, mode?: 'assign' | 'replace') => void
  frameContainer?: () => HTMLElement | null
}

/** Uložení často posílá víc požadavků za sebou, viditelné okno se má načíst jednou. */
const VISIBLE_REFRESH_DEBOUNCE_MS = 250

export interface WorkspaceWindowManager {
  open: (to: RouteLocationRaw, paneId: PaneId, options?: { fresh?: boolean }) => Promise<void>
  openRight: (to: RouteLocationRaw, sourcePaneId: PaneId) => Promise<void>
  select: (id: string) => Promise<void>
  /** Zavře okno a zahodí jeho rozpracovaný obsah; viditelné okno v panelu nahradí poslední zaparkované okno téže firmy. */
  closeWindow: (id: string) => Promise<void>
  /** Znovu načte okno panelu na stejné adrese s výchozími daty, ostatní okna nechá beze změny. */
  reloadPane: (paneId: PaneId) => Promise<void>
  previous: () => Promise<void>
  next: () => Promise<void>
  toggleRecent: () => Promise<void>
  routerFor: (paneId: PaneId) => Router | undefined
  attach: (paneId: PaneId, target: HTMLElement) => () => void
  parkPane: (paneId: PaneId) => void
  closePane: (paneId: PaneId) => void
  reconcile: () => void
  reset: () => void
  destroy: () => void
  reauthorize: () => Promise<void>
  switchCompany: (id: number, to?: RouteLocationRaw) => Promise<void>
  focusPane: (id: PaneId) => void
  updateFrames: () => void
}

export function createWindowManager(options: WindowManagerOptions): WorkspaceWindowManager {
  const workspace = useWorkspaceStore(options.pinia)
  const runtimes = new Map<string, WindowRuntime>()
  const targets = new Map<PaneId, HTMLElement>()
  const parking = document.createElement('div')
  parking.hidden = true
  parking.inert = true
  document.body.append(parking)
  let sequence = 0
  let generation = 0
  let destroyed = false
  let pending = Promise.resolve()
  let visits: string[] = []
  let visitIndex = -1
  let displaying = false
  let staleTimer: ReturnType<typeof setTimeout> | undefined
  const surfaceObserver = options.frames ? new ResizeObserver(() => positionSurfaces()) : null

  function positionSurfaces(): void {
    const container = options.frameContainer?.()
    if (!container) return
    const bounds = container.getBoundingClientRect()
    for (const runtime of runtimes.values()) {
      if (!runtime.surface) continue
      const target = runtime.state.paneId ? targets.get(runtime.state.paneId) : undefined
      runtime.root.hidden = !target
      runtime.root.inert = !target || workspace.companySwitching
      if (!target) continue
      const rect = target.getBoundingClientRect()
      const style = getComputedStyle(target)
      const left = parseFloat(style.paddingLeft) || 0
      const right = parseFloat(style.paddingRight) || 0
      const top = parseFloat(style.paddingTop) || 0
      const bottom = parseFloat(style.paddingBottom) || 0
      Object.assign(runtime.root.style, { position: 'absolute', left: `${rect.left + left - bounds.left}px`, top: `${rect.top + top - bounds.top}px`, width: `${Math.max(0, rect.width - left - right)}px`, height: `${Math.max(0, rect.height - top - bottom)}px` })
    }
  }

  function enqueue(action: () => Promise<void>): Promise<void> {
    const scheduledGeneration = generation
    const task = pending.then(async () => {
      if (!destroyed && scheduledGeneration === generation) await action()
    })
    pending = task.catch(error => {
      if (!destroyed && scheduledGeneration === generation) options.onError(error)
    })
    return pending
  }

  function windowFor(paneId: PaneId): WindowRuntime | undefined {
    return [...runtimes.values()].find(runtime => runtime.state.paneId === paneId)
  }

  async function authorized(runtime: WindowRuntime): Promise<boolean> {
    const currentGeneration = generation
    if (!await ensureSupplier(runtime.state.supplierId ?? 0)) return false
    return await options.authorize(runtime.router, runtime.state)
      && !destroyed && currentGeneration === generation && runtimes.get(runtime.state.id) === runtime
  }

  function stayOnPage(runtime: WindowRuntime): string {
    const fullPath = runtime.state.fullPath
    const route = runtime.router.resolve(fullPath)
    return manualSwitchDestination({ name: route.name ?? undefined, path: route.path, fullPath }) ?? fullPath
  }

  async function ensureSupplier(id: number): Promise<boolean> {
    if (!id || !options.switchSupplier || id === options.currentSupplier?.().id) return true
    const assigned = [...runtimes.values()].filter(runtime => runtime.state.paneId !== null)
      .map(runtime => ({ runtime, paneId: runtime.state.paneId! }))
    workspace.companySwitching = true
    for (const { runtime, paneId } of assigned) {
      park(runtime)
      workspace.updatePane(paneId, { fullPath: null, title: null, canGoBack: false, canGoForward: false })
    }
    try {
      if (await options.switchSupplier(id)) return true
      for (const { runtime, paneId } of assigned) {
        if (runtimes.get(runtime.state.id) !== runtime) continue
        runtime.state.paneId = paneId
        if (!runtime.surface) targets.get(paneId)?.append(runtime.root)
        syncPane(runtime)
      }
      return false
    } finally { workspace.companySwitching = false }
  }

  function syncPane(runtime: WindowRuntime): void {
    if (runtime.surface) runtime.surface.iframe.title = runtime.state.title
    const paneId = runtime.state.paneId
    if (!paneId) return
    workspace.updatePane(paneId, {
      fullPath: runtime.state.fullPath,
      title: runtime.state.title,
      loading: false,
      canGoBack: visitIndex > 0,
      canGoForward: visitIndex < visits.length - 1,
    })
  }

  function recordVisit(id: string): void {
    if (visits[visitIndex] === id) return
    visits.splice(visitIndex + 1)
    visits.push(id)
    if (visits.length > 100) visits.shift()
    visitIndex = visits.length - 1
  }

  function park(runtime: WindowRuntime): void {
    const paneId = runtime.state.paneId
    const target = paneId ? targets.get(paneId) : undefined
    const scroller = target?.closest<HTMLElement>('.workspace-pane-scroll')
    runtime.scrollTop = workspace.paneCount === 1 ? window.scrollY : scroller?.scrollTop ?? 0
    runtime.scrollLeft = workspace.paneCount === 1 ? window.scrollX : scroller?.scrollLeft ?? 0
    if (runtime.root.contains(document.activeElement)) runtime.focus = document.activeElement as HTMLElement
    runtime.state.paneId = null
    if (runtime.surface) { runtime.root.hidden = true; runtime.root.inert = true }
    else parking.append(runtime.root)
  }

  async function display(runtime: WindowRuntime, paneId: PaneId, visit = true): Promise<void> {
    displaying = true
    const visiblePane = runtime.state.paneId
    if (visiblePane && workspace.panes.some(pane => pane.id === visiblePane)) {
      paneId = visiblePane
    } else {
      const previous = windowFor(paneId)
      if (previous && previous !== runtime) park(previous)
      runtime.state.paneId = paneId
      if (!runtime.surface) targets.get(paneId)?.append(runtime.root)
    }
    if (visit) recordVisit(runtime.state.id)
    workspace.activatePane(paneId)
    for (const candidate of runtimes.values()) syncPane(candidate)
    displaying = false
    await nextTick()
    positionSurfaces()
    const scroller = targets.get(paneId)?.closest<HTMLElement>('.workspace-pane-scroll')
    if (scroller && !visiblePane) {
      scroller.scrollTop = runtime.scrollTop
      scroller.scrollLeft = runtime.scrollLeft
      if (workspace.paneCount === 1) window.scrollTo({ top: runtime.scrollTop, left: runtime.scrollLeft, behavior: 'instant' })
    }
    runtime.surface?.update(true)
    if (runtime.surface) runtime.surface.focus()
    else if (!visiblePane && runtime.focus?.isConnected) runtime.focus.focus({ preventScroll: true })
    else targets.get(paneId)?.closest<HTMLElement>('.workspace-pane')?.focus({ preventScroll: true })
  }

  function updateTitle(runtime: WindowRuntime): void {
    const heading = runtime.root.querySelector('h1')?.textContent?.trim()
    const route = runtime.router.currentRoute.value
    const identifier = route.params.id
    const title = runtime.customTitle ?? (heading
      ? `${heading}${identifier && !heading.includes(String(identifier)) ? ` #${identifier}` : ''}`
      : runtime.state.title)
    if (title !== runtime.state.title) {
      runtime.state.title = title
      syncPane(runtime)
    }
  }

  function remove(runtime: WindowRuntime): void {
    runtime.observer?.disconnect()
    runtime.removeAfterEach()
    runtime.surface?.destroy()
    runtime.app?.unmount()
    runtime.root.remove()
    runtime.portal.remove()
    runtimes.delete(runtime.state.id)
    workspace.windows = workspace.windows.filter(window => window.id !== runtime.state.id)
    const currentVisit = visits[visitIndex]
    visits = visits.filter(id => id !== runtime.state.id)
    visitIndex = currentVisit ? visits.lastIndexOf(currentVisit) : -1
  }

  function forwardGlobalNavigation(to: RouteLocationRaw, resolver: Router): boolean {
    // Samostatné plochy nepatří do panelu; autorizaci provede hlavní router.
    const missingName = typeof to === 'object' && 'name' in to && to.name != null && !resolver.hasRoute(to.name)
    if (!missingName && resolver.resolve(to).matched.length) return false
    options.globalNavigate?.(to)
    return true
  }

  async function open(to: RouteLocationRaw, paneId: PaneId, fresh = false, reuseVisibleElsewhere = true): Promise<void> {
    if (!workspace.panes.some(pane => pane.id === paneId)) return
    const resolver = options.createRouter()
    if (forwardGlobalNavigation(to, resolver)) return
    const resolved = resolver.resolve(to)
    const fullPath = resolved.fullPath
    const supplier = options.currentSupplier?.() ?? { id: 0, name: '' }
    const useFrame = options.frames && supplier.id > 0
    const existing = !fresh && [...runtimes.values()].find(runtime => runtime.state.fullPath === fullPath
      && (runtime.state.supplierId ?? 0) === supplier.id
      && (reuseVisibleElsewhere || runtime.state.paneId === null || runtime.state.paneId === paneId))
    if (existing) {
      if (await authorized(existing)) await display(existing, paneId)
      return
    }
    const oldest = [...workspace.windows].reverse().find(window => !window.paneId)
    if (runtimes.size >= MAX_WORKSPACE_WINDOWS && !oldest) return
    const currentGeneration = generation
    if (useFrame) {
      if (!resolved.matched.length || !await options.authorize(resolver, { id: '', fullPath, title: fullPath, paneId: null, supplierId: supplier.id })) return
    } else {
      const failure = await resolver.push(to)
      if (isNavigationFailure(failure) && !isNavigationFailure(failure, NavigationFailureType.duplicated)) return
      if (!resolver.currentRoute.value.matched.length) return
    }
    if (destroyed || currentGeneration !== generation) return
    if (oldest && runtimes.size >= MAX_WORKSPACE_WINDOWS) remove(runtimes.get(oldest.id)!)
    const state: WorkspaceWindowState = {
      id: `window-${++sequence}`,
      fullPath: useFrame ? fullPath : resolver.currentRoute.value.fullPath,
      title: useFrame ? fullPath : resolver.currentRoute.value.fullPath,
      paneId: null,
      supplierId: supplier.id,
      supplierName: supplier.name,
      subtitle: null,
    }
    workspace.windows.unshift(state)
    const storedState = workspace.windows[0]!
    const root = document.createElement('div')
    root.className = 'h-full min-h-0'
    const portal = document.createElement('div')
    portal.dataset.workspaceWindow = state.id
    portal.hidden = true
    portal.inert = true
    document.body.append(portal)
    if (useFrame) {
      root.className = 'pointer-events-auto absolute min-h-0'
      root.hidden = true
      const container = options.frameContainer?.()
      if (!container) throw new Error('Workspace frame container is unavailable.')
      container.append(root)
    } else parking.append(root)
    const runtime: WindowRuntime = {
      state: storedState, router: resolver, app: null, root, portal,
      observer: null, removeAfterEach: () => {}, scrollTop: 0, scrollLeft: 0, focus: null,
      surface: null, customTitle: null, staleChange: null,
    }
    root.addEventListener('focusin', event => {
      if (event.target instanceof HTMLElement) runtime.focus = event.target
    })
    runtimes.set(state.id, runtime)
    runtime.removeAfterEach = resolver.afterEach((route, from, failure) => {
      if (failure || !runtimes.has(state.id)) return
      runtime.state.fullPath = route.fullPath
      runtime.state.title = route.fullPath
      runtime.customTitle = null
      runtime.state.subtitle = null
      syncPane(runtime)
      void nextTick(() => {
        updateTitle(runtime)
        if (route.path !== from.path) {
          runtime.scrollTop = 0
          runtime.scrollLeft = 0
          runtime.focus = null
          const paneId = runtime.state.paneId
          if (paneId && workspace.paneCount === 1) window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
          else if (paneId) targets.get(paneId)?.closest<HTMLElement>('.workspace-pane-scroll')?.scrollTo({ top: 0, left: 0 })
        }
      })
    })
    if (useFrame) {
      runtime.surface = createFrameSurface({
        id: state.id, supplierId: supplier.id, to, root,
        appearance: () => ({ active: runtime.state.paneId !== null && workspace.activePaneId === runtime.state.paneId,
          theme: 'light', locale: 'cs', globalShortcuts: [], ...options.appearance?.(runtime.state.fullPath), paneId: runtime.state.paneId,
          canOpenRight: runtime.state.paneId ? paneForCtrlClick(runtime.state.paneId, workspace.panes) !== null : false,
          canOpenRightWithSplit: runtime.state.paneId ? rightPanePlan(runtime.state.paneId, workspace.panes, workspace.maximumPaneCount) !== null : false }),
        route: (fullPath, title, subtitle) => {
          if (!runtimes.has(state.id)) return
          runtime.state.fullPath = fullPath
          runtime.state.title = title
          runtime.state.subtitle = subtitle
          syncPane(runtime)
          runtime.surface?.update()
        },
        navigate: (to, newPane, placement) => {
          if (placement === 'right') {
            const sourcePane = runtime.state.paneId
            if (sourcePane) void enqueue(() => openRight(to, sourcePane, runtime))
            return
          }
          if (placement === 'beside') {
            void enqueue(async () => {
              const sourceGeneration = generation
              const sourcePane = runtime.state.paneId
              if (!sourcePane || !workspace.panes.some(pane => pane.id === sourcePane)) return
              const targetPane = paneForBeside(sourcePane, workspace.panes)
              if (!await authorized(runtime)) return
              if (!workspace.panes.some(pane => pane.id === sourcePane)
                || runtime.state.paneId !== null && runtime.state.paneId !== sourcePane) return
              // Změna firmy parkuje panely; zdrojový report musí zůstat vedle detailu.
              if (runtime.state.paneId === null) await display(runtime, sourcePane, false)
              if (destroyed || sourceGeneration !== generation || runtimes.get(runtime.state.id) !== runtime) return
              await open(to, targetPane)
            })
            return
          }
          const paneId = newPane && runtime.state.paneId ? paneForCtrlClick(runtime.state.paneId, workspace.panes) : runtime.state.paneId
          if (paneId && (runtime.state.supplierId ?? 0) === (options.currentSupplier?.().id ?? 0)) void manager.open(to, paneId)
        },
        activate: () => { if (runtime.state.paneId) workspace.activatePane(runtime.state.paneId) },
        history: direction => {
          if (runtime.state.paneId && workspace.activePaneId === runtime.state.paneId) {
            if (direction < 0) void manager.previous()
            else void manager.next()
          }
        },
        keyboard: event => {
          if (runtime.state.paneId && workspace.activePaneId === runtime.state.paneId) options.keyboard?.(event)
        },
        globalNavigate: (to, mode) => options.globalNavigate?.(to, mode),
        switchCompany: (id, to) => { void manager.switchCompany(id, to) },
        mutation: ({ resource, path }) => dataChanged(runtime.state.supplierId ?? 0, resource, path, runtime),
        error: error => { options.onError(error) },
        accessRevoked: () => {
          const paneId = runtime.state.paneId
          remove(runtime)
          if (paneId) workspace.updatePane(paneId, { fullPath: null, title: null })
          options.onError(new Error('Window access revoked.'))
        },
      })
      await display(runtime, paneId)
      return
    }
    const active = computed(() => runtime.state.paneId !== null && workspace.activePaneId === runtime.state.paneId)
    const app = createApp(PaneRoot)
    app.config.idPrefix = `${state.id}-`
    app.use(options.pinia)
    app.use(resolver)
    app.use(i18n)
    app.directive('math', vMath)
    app.directive('select-on-focus', vSelectOnFocus)
    app.directive('column-labels', vColumnLabels)
    app.provide(windowContextKey, {
      paneId: computed(() => runtime.state.paneId), active, root, portal,
      navigate: to => runtime.state.paneId ? enqueue(() => open(to, runtime.state.paneId!)) : Promise.resolve(),
      setTitle: (title, subtitle) => {
        if (!runtimes.has(state.id)) return
        runtime.customTitle = title
        runtime.state.subtitle = subtitle ?? null
        updateTitle(runtime)
      },
    })
    runtime.app = app
    app.mount(root)
    runtime.observer = new MutationObserver(() => updateTitle(runtime))
    runtime.observer.observe(root, { childList: true, subtree: true, characterData: true })
    await display(runtime, paneId)
    updateTitle(runtime)
  }

  async function openRight(to: RouteLocationRaw, sourcePaneId: PaneId, source = windowFor(sourcePaneId)): Promise<void> {
    if (forwardGlobalNavigation(to, options.createRouter())) return
    const sourceGeneration = generation
    if (!rightPanePlan(sourcePaneId, workspace.panes, workspace.maximumPaneCount)) return
    if (source && !await authorized(source)) return
    if (destroyed || sourceGeneration !== generation
      || source && (runtimes.get(source.state.id) !== source || source.state.paneId !== null && source.state.paneId !== sourcePaneId)) return
    // Ověření firmy může zdroj zaparkovat; při otevření detailu musí zůstat viditelný.
    if (source?.state.paneId === null) await display(source, sourcePaneId, false)
    if (destroyed || sourceGeneration !== generation) return
    const plan = rightPanePlan(sourcePaneId, workspace.panes, workspace.maximumPaneCount)
    if (!plan) return
    if (plan.count !== workspace.paneCount) {
      workspace.resizeLayout(plan.count)
      reconcile()
      await nextTick()
    }
    if (destroyed || sourceGeneration !== generation) return
    await open(to, plan.paneId, false, false)
  }

  async function select(id: string, visit = true): Promise<void> {
    const runtime = runtimes.get(id)
    if (runtime && await authorized(runtime)) await display(runtime, workspace.activePaneId, visit)
  }

  async function closeWindow(id: string): Promise<void> {
    const runtime = runtimes.get(id)
    if (!runtime) return
    const paneId = runtime.state.paneId
    remove(runtime)
    if (!paneId || !workspace.panes.some(pane => pane.id === paneId)) return
    const supplierId = options.currentSupplier?.().id ?? 0
    const next = workspace.windows.find(window => !window.paneId && runtimes.has(window.id) && (window.supplierId ?? 0) === supplierId)
    if (next) await display(runtimes.get(next.id)!, paneId, false)
    else workspace.updatePane(paneId, { fullPath: null, title: null, loading: false, canGoBack: false, canGoForward: false })
  }

  async function reloadPane(paneId: PaneId): Promise<void> {
    const runtime = windowFor(paneId)
    if (!runtime) return
    const fullPath = runtime.state.fullPath
    remove(runtime)
    await open(fullPath, paneId, true)
  }

  async function traverse(direction: number): Promise<void> {
    let index = visitIndex + direction
    while (index >= 0 && index < visits.length) {
      const id = visits[index]!
      if (runtimes.has(id) && id !== windowFor(workspace.activePaneId)?.state.id) {
        const runtime = runtimes.get(id)!
        if (!await authorized(runtime)) return
        visitIndex = index
        await display(runtime, workspace.activePaneId, false)
        return
      }
      index += direction
    }
  }

  const stopActivity = watch(() => [workspace.activePaneId, workspace.windows.map(window => `${window.id}:${window.paneId}`).join(',')], () => {
    for (const runtime of runtimes.values()) {
      const active = runtime.state.paneId !== null && workspace.activePaneId === runtime.state.paneId
      runtime.portal.hidden = !active
      runtime.portal.inert = !active
      runtime.surface?.update()
    }
    const active = windowFor(workspace.activePaneId)
    if (active && !displaying) recordVisit(active.state.id)
    deliverStaleChanges()
  }, { flush: 'sync' })

  function dataChanged(supplierId: number, resource: string, path: string, origin: WindowRuntime | null): void {
    const areas = changedAreas(path)
    let visibleChange = false
    for (const runtime of runtimes.values()) {
      if (!runtime.surface || runtime.state.supplierId !== supplierId || !isWindowAffected(runtime.state.fullPath, areas)) continue
      runtime.staleChange = { resource, self: runtime === origin && (runtime.staleChange?.self ?? true) }
      if (runtime.state.paneId !== null) visibleChange = true
    }
    if (!visibleChange) return
    clearTimeout(staleTimer)
    staleTimer = setTimeout(deliverStaleChanges, VISIBLE_REFRESH_DEBOUNCE_MS)
  }

  function deliverStaleChanges(): void {
    if (workspace.companySwitching) return
    for (const runtime of runtimes.values()) {
      const change = runtime.staleChange
      if (!change || runtime.state.paneId === null) continue
      runtime.staleChange = null
      runtime.surface?.invalidate(runtime.state.supplierId ?? 0, change.resource, change.self)
    }
  }

  // Zápis z hlavního dokumentu (např. nastavení firmy mimo okna) se rozešle
  // oknům aktuální firmy stejně jako zápis z jiného okna.
  function shellMutation(event: Event): void {
    const detail = (event as CustomEvent<unknown>).detail
    const { resource, path } = (detail && typeof detail === 'object' ? detail : {}) as Record<string, unknown>
    const supplierId = options.currentSupplier?.().id ?? 0
    if (typeof resource !== 'string' || !/^[a-z0-9-]+$/.test(resource) || supplierId <= 0) return
    dataChanged(supplierId, resource, typeof path === 'string' ? path : `/${resource}`, null)
  }
  window.addEventListener('myucto:data-mutation', shellMutation)

  function reconcile(): void {
    for (const runtime of runtimes.values()) {
      if (runtime.state.paneId && !workspace.panes.some(pane => pane.id === runtime.state.paneId)) park(runtime)
    }
    for (const pane of workspace.panes) {
      const runtime = windowFor(pane.id)
      if (runtime) {
        if (!runtime.surface) targets.get(pane.id)?.append(runtime.root)
        syncPane(runtime)
      }
    }
    void nextTick(positionSurfaces)
  }

  function reset(): void {
    generation++
    pending = Promise.resolve()
    for (const runtime of [...runtimes.values()]) remove(runtime)
    visits = []
    visitIndex = -1
    workspace.windowSwitcherOpen = false
    for (const pane of workspace.panes) workspace.updatePane(pane.id, {
      fullPath: null, title: null, loading: false, canGoBack: false, canGoForward: false,
    })
  }

  const manager: WorkspaceWindowManager = {
    open: (to, paneId, options) => enqueue(() => open(to, paneId, options?.fresh)),
    openRight: (to, sourcePaneId) => enqueue(() => openRight(to, sourcePaneId)),
    select: id => enqueue(() => select(id)),
    closeWindow: id => enqueue(() => closeWindow(id)),
    reloadPane: paneId => enqueue(() => reloadPane(paneId)),
    previous: () => enqueue(() => traverse(-1)),
    next: () => enqueue(() => traverse(1)),
    toggleRecent: () => enqueue(async () => {
      const active = windowFor(workspace.activePaneId)
      const recent = workspace.windows.find(window => window.id !== active?.state.id)
      if (recent) await select(recent.id)
    }),
    routerFor: paneId => windowFor(paneId)?.router,
    attach: (paneId, target) => {
      targets.set(paneId, target)
      surfaceObserver?.observe(target)
      const runtime = windowFor(paneId)
      if (runtime && !runtime.surface) target.append(runtime.root)
      void nextTick(positionSurfaces)
      return () => {
        if (targets.get(paneId) !== target) return
        const current = windowFor(paneId)
        if (current) park(current)
        surfaceObserver?.unobserve(target)
        targets.delete(paneId)
      }
    },
    parkPane: paneId => {
      const runtime = windowFor(paneId)
      if (runtime) park(runtime)
    },
    closePane: paneId => {
      const index = workspace.panes.findIndex(pane => pane.id === paneId)
      if (index < 0) return
      const affected = workspace.panes.slice(index).map(pane => windowFor(pane.id))
      for (const runtime of affected) if (runtime) park(runtime)
      if (workspace.panes.length > 1) workspace.dropLastPane()
      for (let offset = 0; offset < affected.length; offset++) {
        const pane = workspace.panes[index + offset]
        if (!pane) break
        const runtime = affected[offset + 1]
        if (runtime) {
          runtime.state.paneId = pane.id
          if (!runtime.surface) targets.get(pane.id)?.append(runtime.root)
          syncPane(runtime)
        } else workspace.updatePane(pane.id, { fullPath: null, title: null, canGoBack: false, canGoForward: false })
      }
      workspace.activatePane(workspace.panes[Math.min(index, workspace.panes.length - 1)]!.id)
      void nextTick(positionSurfaces)
    },
    reconcile,
    reset,
    reauthorize: () => enqueue(async () => {
      const currentGeneration = generation
      for (const runtime of [...runtimes.values()]) {
        if (runtime.state.supplierId && runtime.state.supplierId !== options.currentSupplier?.().id) continue
        const allowed = await options.authorize(runtime.router, runtime.state)
        if (destroyed || currentGeneration !== generation) return
        if (!allowed && runtimes.get(runtime.state.id) === runtime) {
          const paneId = runtime.state.paneId
          remove(runtime)
          if (paneId) workspace.updatePane(paneId, { fullPath: null, title: null })
        }
      }
    }),
    switchCompany: (id, to) => enqueue(async () => {
      // Ruční přepnutí zůstává na téže stránce, detail záznamu jde na svůj seznam.
      const current = to === undefined ? windowFor(workspace.activePaneId) : undefined
      const target = to ?? (current ? stayOnPage(current) : '/')
      if (!await ensureSupplier(id)) return
      await open(target, workspace.activePaneId)
      // Stránku nová firma nemá (jiný režim účetnictví, chybějící oprávnění): panel nezůstane prázdný.
      if (to === undefined && !workspace.panes.find(pane => pane.id === workspace.activePaneId)?.fullPath) {
        await open('/', workspace.activePaneId)
      }
    }),
    focusPane: id => windowFor(id)?.surface?.focus(),
    updateFrames: () => { for (const runtime of runtimes.values()) runtime.surface?.update(); positionSurfaces() },
    destroy: () => {
      destroyed = true
      clearTimeout(staleTimer)
      stopActivity()
      window.removeEventListener('myucto:data-mutation', shellMutation)
      surfaceObserver?.disconnect()
      reset()
      parking.remove()
      if (getWindowManager() === manager) setWindowManager(null)
    },
  }
  getWindowManager()?.destroy()
  setWindowManager(manager)
  return manager
}
