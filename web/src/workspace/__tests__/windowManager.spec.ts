import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref, watch } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import {
  createMemoryHistory,
  createRouter,
  useRoute,
  type Router,
  type RouteLocationRaw,
} from 'vue-router'

vi.mock('@/i18n', () => ({
  i18n: { install: () => undefined },
}))
vi.mock('@/directives/vMath', () => ({ vMath: {} }))
vi.mock('@/directives/vSelectOnFocus', () => ({ vSelectOnFocus: {} }))
vi.mock('@/directives/columnLabels', () => ({ vColumnLabels: {} }))
import { useWorkspaceStore, type PaneCount, type PaneId } from '@/stores/workspace'
import { createWindowManager, type WorkspaceWindowManager } from '@/workspace/windowManager'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { useHotkey } from '@/composables/useHotkey'

let editorInstanceSequence = 0

const TestEditor = defineComponent({
  name: 'TestEditor',
  setup() {
    const route = useRoute()
    const instance = ++editorInstanceSequence
    const draft = ref('')
    const watchedId = ref(String(route.params.id ?? ''))
    const hotkeys = ref(0)
    useHotkey('ctrl+s', () => { hotkeys.value++ })

    watch(() => route.params.id, value => {
      watchedId.value = String(value ?? '')
    })

    return () => h('article', {
      'data-testid': 'editor',
      'data-editor-id': String(route.params.id ?? ''),
      'data-instance': String(instance),
      'data-watched-id': watchedId.value,
      'data-hotkeys': String(hotkeys.value),
    }, [
      h('h1', `Editor ${String(route.params.id ?? '')}`),
      h('input', {
        'data-testid': 'draft',
        value: draft.value,
        onInput: (event: Event) => {
          draft.value = (event.target as HTMLInputElement).value
        },
      }),
      h(WorkspaceTeleport, {}, { default: () => h('div', { 'data-editor-portal': String(route.params.id) }, 'Popup') }),
    ])
  },
})

interface Deferred<T> {
  promise: Promise<T>
  resolve: (value: T) => void
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => { resolve = done })
  return { promise, resolve }
}

interface PaneMount {
  pane: HTMLElement
  target: HTMLElement
  detach: () => void
}

interface Harness {
  pinia: Pinia
  store: ReturnType<typeof useWorkspaceStore>
  manager: WorkspaceWindowManager
  errors: unknown[]
  attachPane: (id: PaneId) => PaneMount
  destroy: () => void
}

let activeHarness: Harness | null = null

function testRouter(slowGate?: { entered: Deferred<void>, release: Deferred<boolean> }): Router {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/editor/:id', name: 'editor', component: TestEditor },
      { path: '/slow/:id', name: 'slow-editor', component: TestEditor },
      { path: '/list', name: 'list', component: TestEditor },
      { path: '/', name: 'home', component: TestEditor },
    ],
  })
  if (slowGate) {
    router.beforeEach(async (to) => {
      if (to.name !== 'slow-editor') return true
      slowGate.entered.resolve(undefined)
      return slowGate.release.promise
    })
  }
  return router
}

function createHarness(options: {
  panes?: PaneCount
  slowGate?: { entered: Deferred<void>, release: Deferred<boolean> }
  authorize?: (router: Router) => Promise<boolean>
  supplier?: { id: number; name: string }
  switchSupplier?: (id: number) => Promise<boolean>
  frames?: boolean
  routerFactory?: () => Router
  globalNavigate?: (to: RouteLocationRaw) => void
} = {}): Harness {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useWorkspaceStore(pinia)
  store.resetLayout(options.panes ?? 1, '/')
  const errors: unknown[] = []
  const mounts = new Map<PaneId, PaneMount>()
  const frameContainer = document.createElement('div')
  document.body.append(frameContainer)
  const manager = createWindowManager({
    pinia,
    createRouter: options.routerFactory ?? (() => testRouter(options.slowGate)),
    authorize: options.authorize ?? vi.fn().mockResolvedValue(true),
    onError: error => { errors.push(error) },
    currentSupplier: () => options.supplier ?? { id: 0, name: '' },
    switchSupplier: options.switchSupplier,
    frames: options.frames,
    globalNavigate: options.globalNavigate,
    frameContainer: () => frameContainer,
  })

  function attachPane(id: PaneId): PaneMount {
    const previous = mounts.get(id)
    previous?.detach()
    previous?.pane.remove()
    const pane = document.createElement('section')
    pane.className = 'workspace-pane'
    pane.tabIndex = -1
    const scroller = document.createElement('div')
    scroller.className = 'workspace-pane-scroll'
    const target = document.createElement('div')
    target.dataset.paneTarget = id
    scroller.append(target)
    pane.append(scroller)
    document.body.append(pane)
    const detachManager = manager.attach(id, target)
    const mount: PaneMount = {
      pane,
      target,
      detach: () => {
        detachManager()
        if (mounts.get(id) === mount) mounts.delete(id)
      },
    }
    mounts.set(id, mount)
    return mount
  }

  const harness: Harness = {
    pinia,
    store,
    manager,
    errors,
    attachPane,
    destroy: () => {
      for (const mount of [...mounts.values()]) {
        mount.detach()
        mount.pane.remove()
      }
      manager.destroy()
      frameContainer.remove()
    },
  }
  activeHarness = harness
  return harness
}

function windowIdFor(store: Harness['store'], fullPath: string): string {
  const found = store.windows.find(window => window.fullPath === fullPath)
  if (!found) throw new Error(`Window ${fullPath} was not found.`)
  return found.id
}

function editor(id: string | number): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-testid="editor"][data-editor-id="${id}"]`)
  if (!found) throw new Error(`Editor ${id} was not found.`)
  return found
}

async function typeDraft(root: HTMLElement, value: string): Promise<HTMLInputElement> {
  const input = root.querySelector<HTMLInputElement>('[data-testid="draft"]')!
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  return input
}

afterEach(() => {
  activeHarness?.destroy()
  activeHarness = null
  editorInstanceSequence = 0
  document.body.replaceChildren()
  vi.restoreAllMocks()
})

describe('workspace window manager', () => {
  it('otevření globálního cíle napravo nerozdělí pracovní plochu', async () => {
    const globalNavigate = vi.fn()
    const { manager, store } = createHarness({ globalNavigate })
    await manager.open('/editor/1', 'primary')
    const before = JSON.stringify(store.$state)
    await manager.openRight('/instance-admin', 'primary')
    expect(globalNavigate).toHaveBeenCalledWith('/instance-admin')
    expect(JSON.stringify(store.$state)).toBe(before)
  })

  it.each([false, true])('předá globální cíl hlavnímu routeru bez změny panelu (iframe: %s)', async frames => {
    if (frames) vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const globalNavigate = vi.fn()
    const { manager, store } = createHarness({ frames, supplier: { id: 1, name: 'Test' }, globalNavigate })
    await manager.open('/editor/1', 'primary')
    const before = JSON.stringify(store.$state)
    await manager.open('/instance-admin?tab=smtp#targets', 'primary')
    await manager.open({ name: 'instance-admin' }, 'primary', { fresh: true })
    expect(globalNavigate.mock.calls).toEqual([
      ['/instance-admin?tab=smtp#targets'],
      [{ name: 'instance-admin' }],
    ])
    expect(JSON.stringify(store.$state)).toBe(before)
  })

  it.each([1, 2, 3] as const)('nové čisté okno zachová rozepsanou instanci stejné stránky při %i panelech', async panes => {
    const harness = createHarness({ panes })
    const mounts = harness.store.panes.map(pane => harness.attachPane(pane.id))
    await harness.manager.open('/editor/1', 'primary')
    const originalId = harness.store.windows[0]!.id
    const originalEditor = editor(1)
    const originalInput = await typeDraft(originalEditor, 'Původní koncept')
    const targetId = harness.store.panes.at(-1)!.id

    await harness.manager.open('/editor/1', targetId, { fresh: true })
    const cleanId = harness.store.windows[0]!.id
    const cleanEditor = mounts.at(-1)!.target.querySelector<HTMLElement>('[data-testid="editor"]')!
    expect(harness.store.windows).toHaveLength(2)
    expect(cleanId).not.toBe(originalId)
    expect(cleanEditor).not.toBe(originalEditor)
    expect(cleanEditor.querySelector<HTMLInputElement>('input')!.value).toBe('')
    expect(originalInput.value).toBe('Původní koncept')
    expect(harness.store.activePaneId).toBe(targetId)
    const cleanInput = await typeDraft(cleanEditor, 'Druhý koncept')

    await harness.manager.select(originalId)
    expect(originalInput.value).toBe('Původní koncept')
    await harness.manager.select(cleanId)
    expect(cleanInput.value).toBe('Druhý koncept')
    await harness.manager.open('/editor/1', targetId)
    expect(harness.store.windows).toHaveLength(2)
  })

  it('ověří oprávnění iframe okna bez blokujícího načítání jeho lazy komponenty do shellu', async () => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const lazy = vi.fn(async () => TestEditor)
    const authorize = vi.fn().mockResolvedValue(true)
    const harness = createHarness({ frames: true, supplier: { id: 1, name: 'Firma A' }, authorize,
      routerFactory: () => createRouter({ history: createMemoryHistory(), routes: [{ path: '/editor/:id', component: lazy }] }) })
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    expect(lazy).not.toHaveBeenCalled()
    expect(authorize).toHaveBeenCalledTimes(1)
    expect(harness.store.windows[0]?.fullPath).toBe('/editor/1')
    expect(document.querySelector('iframe')).not.toBeNull()
    authorize.mockResolvedValue(false)
    await harness.manager.open('/editor/2', 'primary')
    expect(harness.store.windows).toHaveLength(1)
  })
  it('rozlišuje stejnou cestu ve dvou firmách a výběrem obnoví firmu i rozepsaný obsah', async () => {
    const supplier = { id: 1, name: 'Syntetická firma A' }
    const switchSupplier = vi.fn(async (id: number) => { supplier.id = id; supplier.name = `Firma ${id}`; return true })
    const harness = createHarness({ supplier, switchSupplier })
    const primary = harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const firstId = harness.store.windows[0]!.id
    const firstEditor = editor(1)
    const input = await typeDraft(firstEditor, 'Doklad firmy A')
    await harness.manager.switchCompany(2, '/editor/1')

    expect(harness.store.windows).toHaveLength(2)
    expect(harness.store.windows[0]?.supplierId).toBe(2)
    expect(primary.target.contains(firstEditor)).toBe(false)
    expect(input.value).toBe('Doklad firmy A')
    await harness.manager.select(firstId)
    expect(supplier.id).toBe(1)
    expect(primary.target.contains(firstEditor)).toBe(true)
    expect(input.value).toBe('Doklad firmy A')
    expect(switchSupplier.mock.calls.map(([id]) => id)).toEqual([2, 1])
  })

  it('ruční přepnutí firmy zůstane na téže stránce včetně filtru', async () => {
    const supplier = { id: 1, name: 'Syntetická firma A' }
    const harness = createHarness({ supplier, switchSupplier: async (id: number) => { supplier.id = id; return true } })
    harness.attachPane('primary')
    await harness.manager.open('/list?status=open', 'primary')
    await harness.manager.switchCompany(2)

    expect(supplier.id).toBe(2)
    expect(harness.store.panes[0]?.fullPath).toBe('/list?status=open')
    expect(harness.store.windows.find(window => window.supplierId === 2)?.fullPath).toBe('/list?status=open')
  })

  it('když nadřazená stránka detailu neexistuje, jde po ručním přepnutí firmy na přehled', async () => {
    const supplier = { id: 1, name: 'Syntetická firma A' }
    const harness = createHarness({ supplier, switchSupplier: async (id: number) => { supplier.id = id; return true } })
    harness.attachPane('primary')
    await harness.manager.open('/editor/42', 'primary')
    await harness.manager.switchCompany(2)

    expect(harness.store.panes[0]?.fullPath).toBe('/')
  })

  it('při odmítnutém přepnutí firmy zachová původní okno i firmu', async () => {
    const supplier = { id: 1, name: 'Syntetická firma A' }
    const harness = createHarness({ supplier, switchSupplier: async () => false })
    const primary = harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const first = editor(1)
    await harness.manager.switchCompany(2, '/editor/2')
    expect(supplier.id).toBe(1)
    expect(harness.store.windows).toHaveLength(1)
    expect(primary.target.contains(first)).toBe(true)
    expect(harness.store.companySwitching).toBe(false)
  })

  it.each([1, 2] as const)('Shift navigace z posledního z %i panelů přidá pravý panel a zachová původní frame', async panes => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const harness = createHarness({ panes, frames: true, supplier: { id: 1, name: 'Firma A' } })
    harness.store.setMaximumPaneCount(3)
    for (const pane of harness.store.panes) harness.attachPane(pane.id)
    const sourceId = harness.store.panes[panes - 1]!.id
    await harness.manager.open('/editor/1', sourceId)
    const original = harness.store.windows[0]!
    const iframe = document.querySelector<HTMLIFrameElement>('iframe')!
    const root = iframe.parentElement!
    const parent = root.parentElement
    window.dispatchEvent(new MessageEvent('message', { origin: window.location.origin, source: iframe.contentWindow, data: {
      type: 'myucto-window-navigate', id: original.id, to: '/editor/2?tab=detail#row', placement: 'right',
    } }))
    await vi.waitFor(() => expect(harness.store.windows).toHaveLength(2))
    const targetId = panes === 1 ? 'secondary-2' : 'secondary-3'
    expect(harness.store.paneCount).toBe(panes + 1)
    expect(harness.store.windows.find(window => window.id === original.id)?.paneId).toBe(sourceId)
    expect(harness.store.windows.find(window => window.fullPath === '/editor/2?tab=detail#row')?.paneId).toBe(targetId)
    expect(harness.store.activePaneId).toBe(targetId)
    expect(root.parentElement).toBe(parent)
    expect(root.hidden).toBe(false)
    expect(harness.errors).toEqual([])
  })

  it('otevření vpravo z menu použije sousední panel a zachová rozpracovaný formulář', async () => {
    const harness = createHarness({ panes: 3 })
    harness.store.setMaximumPaneCount(3)
    const primary = harness.attachPane('primary')
    harness.attachPane('secondary-2')
    harness.attachPane('secondary-3')
    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'secondary-2')
    const input = primary.target.querySelector<HTMLInputElement>('input')!
    input.value = 'Rozpracovaný obsah'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await harness.manager.openRight('/editor/3', 'primary')
    expect(harness.store.paneCount).toBe(3)
    expect(harness.store.activePaneId).toBe('secondary-2')
    expect(primary.target.querySelector('input')).toBe(input)
    expect(input.value).toBe('Rozpracovaný obsah')
    expect(harness.store.panes[1]?.fullPath).toBe('/editor/3')
    expect(harness.store.windows.find(window => window.fullPath === '/editor/2')?.paneId).toBeNull()
  })

  it('na úzkém okně ani za posledním podporovaným panelem rozložení nerozšíří', async () => {
    const harness = createHarness()
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.openRight('/editor/2', 'primary')
    expect(harness.store.paneCount).toBe(1)
    expect(harness.store.windows).toHaveLength(1)
    harness.store.resizeLayout(2)
    harness.store.setMaximumPaneCount(2)
    harness.attachPane('secondary-2')
    await harness.manager.openRight('/editor/2', 'secondary-2')
    expect(harness.store.paneCount).toBe(2)
    expect(harness.store.windows).toHaveLength(1)
  })

  it('Shift otevře cíl vpravo, i když už je stejná stránka vidět ve zdrojovém panelu', async () => {
    const harness = createHarness()
    harness.store.setMaximumPaneCount(2)
    const primary = harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const editor = primary.target.querySelector('[data-testid="editor"]')
    await harness.manager.openRight('/editor/1', 'primary')
    expect(harness.store.panes.map(pane => pane.fullPath)).toEqual(['/editor/1', '/editor/1'])
    expect(harness.store.activePaneId).toBe('secondary-2')
    expect(harness.store.windows).toHaveLength(2)
    expect(primary.target.querySelector('[data-testid="editor"]')).toBe(editor)
  })

  it.each([true, false])('Shift navigace dodrží firmu zdroje a její autorizaci (povoleno: %s)', async allowed => {
    const supplier = { id: 1, name: 'Firma A' }
    const authorize = vi.fn().mockResolvedValue(true)
    const switchSupplier = vi.fn(async (id: number) => { supplier.id = id; return true })
    const harness = createHarness({ frames: true, supplier, switchSupplier, authorize })
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    harness.store.setMaximumPaneCount(2)
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const originalId = harness.store.windows[0]!.id
    supplier.id = 2
    authorize.mockResolvedValue(allowed)
    await harness.manager.openRight('/editor/2', 'primary')
    expect(switchSupplier).toHaveBeenCalledWith(1)
    expect(harness.store.paneCount).toBe(allowed ? 2 : 1)
    expect(harness.store.windows).toHaveLength(allowed ? 2 : 1)
    if (allowed) {
      expect(harness.store.windows.find(window => window.id === originalId)?.paneId).toBe('primary')
      expect(harness.store.windows.find(window => window.fullPath === '/editor/2')).toMatchObject({ supplierId: 1, paneId: 'secondary-2' })
    }
  })

  it('ponechá iframe v původním rodiči při parkování i přesunu do jiného panelu', async () => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(function (this: HTMLIFrameElement, value: string) {
      this.dataset.testSrc = value
    })
    const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' } })
    harness.attachPane('primary')
    harness.attachPane('secondary-2')
    await harness.manager.open('/editor/1', 'primary')
    const firstId = harness.store.windows[0]!.id
    const frame = document.querySelector<HTMLIFrameElement>('iframe')!
    const root = frame.parentElement!
    const parent = root.parentElement!
    await harness.manager.open('/editor/2', 'primary')
    expect(root.hidden).toBe(true)
    expect(frame.parentElement).toBe(root)
    expect(root.parentElement).toBe(parent)
    harness.store.activatePane('secondary-2')
    await harness.manager.select(firstId)
    expect(root.hidden).toBe(false)
    expect(frame.parentElement).toBe(root)
    expect(root.parentElement).toBe(parent)
    expect(harness.store.windows.find(window => window.id === firstId)?.paneId).toBe('secondary-2')
  })

  it.each([1, 2, 3] as const)('otevře detail z frame při %i panelech a zachová původní report', async panes => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const harness = createHarness({ panes, frames: true, supplier: { id: 1, name: 'Firma A' } })
    for (const pane of harness.store.panes) harness.attachPane(pane.id)
    const sourcePane = harness.store.panes[panes - 1]!.id
    await harness.manager.open('/editor/1', sourcePane)
    const original = harness.store.windows[0]!
    const iframe = document.querySelector<HTMLIFrameElement>('iframe')!
    harness.store.activatePane('primary')
    const send = (origin = window.location.origin, source: MessageEventSource | null = iframe.contentWindow) => {
      window.dispatchEvent(new MessageEvent('message', { origin, source, data: {
        type: 'myucto-window-navigate', id: original.id, to: '/editor/2?tx=42&detail=1', placement: 'beside',
      } }))
    }
    send('https://foreign.example')
    send(window.location.origin, window)
    await harness.manager.open('/editor/1', sourcePane)
    expect(harness.store.windows).toHaveLength(1)
    send()
    await vi.waitFor(() => expect(harness.store.windows).toHaveLength(2))
    expect(harness.store.windows.find(window => window.id === original.id)?.paneId).toBe(panes === 1 ? null : sourcePane)
    expect(harness.store.windows.find(window => window.fullPath.includes('detail=1'))?.paneId).toBe(panes === 3 ? 'secondary-2' : 'primary')
    expect(harness.store.paneCount).toBe(panes)
    expect(harness.errors).toEqual([])
  })

  it.each([true, false])('při frame navigaci vedle ověří firmu zdroje (přepnutí: %s)', async allowed => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const supplier = { id: 1, name: 'Firma A' }
    const switchSupplier = vi.fn(async (id: number) => {
      if (allowed) { supplier.id = id; supplier.name = `Firma ${id}` }
      return allowed
    })
    const harness = createHarness({ panes: 2, frames: true, supplier, switchSupplier })
    harness.attachPane('primary')
    harness.attachPane('secondary-2')
    await harness.manager.open('/editor/1', 'primary')
    const original = harness.store.windows[0]!
    const iframe = document.querySelector<HTMLIFrameElement>('iframe')!
    supplier.id = 2
    supplier.name = 'Firma B'
    window.dispatchEvent(new MessageEvent('message', { origin: window.location.origin, source: iframe.contentWindow, data: {
      type: 'myucto-window-navigate', id: original.id, to: '/editor/2', placement: 'beside', supplierId: 999,
    } }))
    await vi.waitFor(() => expect(switchSupplier).toHaveBeenCalledWith(1))
    if (allowed) {
      await vi.waitFor(() => expect(harness.store.windows).toHaveLength(2))
      expect(harness.store.windows.find(window => window.fullPath === '/editor/2')).toMatchObject({ supplierId: 1, paneId: 'secondary-2' })
      expect(harness.store.windows.find(window => window.id === original.id)?.paneId).toBe('primary')
    } else {
      await flushPromises()
      expect(harness.store.windows.some(window => window.fullPath === '/editor/2')).toBe(false)
    }
    expect(harness.errors).toEqual([])
  })

  it('při zamítnuté autorizaci frame detail neotevře', async () => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const authorize = vi.fn().mockResolvedValue(true)
    const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' }, authorize })
    harness.attachPane('primary')
    harness.attachPane('secondary-2')
    await harness.manager.open('/editor/1', 'primary')
    const original = harness.store.windows[0]!
    const iframe = document.querySelector<HTMLIFrameElement>('iframe')!
    authorize.mockClear().mockResolvedValue(false)
    window.dispatchEvent(new MessageEvent('message', { origin: window.location.origin, source: iframe.contentWindow, data: {
      type: 'myucto-window-navigate', id: original.id, to: '/editor/2', placement: 'beside',
    } }))
    await vi.waitFor(() => expect(authorize).toHaveBeenCalled())
    await flushPromises()
    expect(harness.store.windows).toHaveLength(1)
    expect(harness.store.windows[0]?.paneId).toBe('primary')
    expect(harness.store.panes[1]?.fullPath).toBeNull()
  })

  it('respektuje odsazení obou panelů', async () => {
    vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' } })
    const primary = harness.attachPane('primary')
    const secondary = harness.attachPane('secondary-2')
    for (const [index, mount] of [primary, secondary].entries()) {
      mount.target.style.padding = '20px'
      vi.spyOn(mount.target, 'getBoundingClientRect').mockReturnValue({ left: index * 600, top: 40, width: 600, height: 800 } as DOMRect)
    }
    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'secondary-2')
    const frames = [...document.querySelectorAll<HTMLIFrameElement>('iframe')]
    expect(frames.map(frame => frame.parentElement!.style.left)).toEqual(['20px', '620px'])
    expect(frames.map(frame => frame.parentElement!.style.width)).toEqual(['560px', '560px'])
    expect(frames.map(frame => frame.parentElement!.style.top)).toEqual(['60px', '60px'])
    expect(frames.map(frame => frame.parentElement!.style.height)).toEqual(['760px', '760px'])
  })

  it('izoluje portály a klávesnici skrytého okna a obnoví rozepsané pole s ohniskem', async () => {
    const harness = createHarness()
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const firstId = windowIdFor(harness.store, '/editor/1')
    const input = await typeDraft(editor(1), 'Pokračovat tady')
    input.focus()
    await harness.manager.open('/editor/2', 'primary')

    expect(document.querySelector('[data-editor-portal="1"]')?.closest('[hidden]')).not.toBeNull()
    expect(document.querySelector('[data-editor-portal="2"]')?.closest('[hidden]')).toBeNull()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true }))
    await nextTick()
    expect(editor(1).dataset.hotkeys).toBe('0')
    expect(editor(2).dataset.hotkeys).toBe('1')

    await harness.manager.select(firstId)
    expect(document.activeElement).toBe(input)
    expect(input.value).toBe('Pokračovat tady')
    expect(document.querySelector('[data-editor-portal="1"]')?.closest('[hidden]')).toBeNull()
    expect(document.querySelector('[data-editor-portal="2"]')?.closest('[hidden]')).not.toBeNull()
  })

  it('nevrátí staré okno do panelu, pokud při ověřování oprávnění nastane reset', async () => {
    const authorization = deferred<boolean>()
    const harness = createHarness({ authorize: () => authorization.promise })
    const primary = harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const firstId = windowIdFor(harness.store, '/editor/1')
    await harness.manager.open('/editor/2', 'primary')
    const selection = harness.manager.select(firstId)
    await nextTick()
    harness.manager.reset()
    await harness.manager.open('/editor/3', 'primary')
    authorization.resolve(true)
    await selection

    expect(harness.store.windows.map(window => window.fullPath)).toEqual(['/editor/3'])
    expect(primary.target.contains(editor(3))).toBe(true)
    expect(primary.target.querySelector('[data-editor-id="1"]')).toBeNull()
  })

  it('uchová rozepsaný formulář, komponentu i vlastní route při otevření dalšího okna', async () => {
    const harness = createHarness()
    const primary = harness.attachPane('primary')

    await harness.manager.open('/editor/1', 'primary')
    const firstEditor = editor(1)
    const firstInput = await typeDraft(firstEditor, 'rozepsaná hodnota')
    const firstInstance = firstEditor.dataset.instance
    const firstWindow = windowIdFor(harness.store, '/editor/1')

    await harness.manager.open('/editor/2', 'primary')

    expect(primary.target.contains(editor(2))).toBe(true)
    expect(firstEditor.dataset.editorId).toBe('1')
    expect(firstEditor.dataset.watchedId).toBe('1')
    expect(firstInput.value).toBe('rozepsaná hodnota')
    expect(firstEditor.isConnected).toBe(true)

    await harness.manager.select(firstWindow)

    expect(primary.target.contains(firstEditor)).toBe(true)
    expect(editor(1)).toBe(firstEditor)
    expect(editor(1).dataset.instance).toBe(firstInstance)
    expect(firstInput.value).toBe('rozepsaná hodnota')
    expect(harness.errors).toEqual([])
  })

  it('znovu načte jen okno aktivního panelu s výchozím obsahem a ostatní okna zachová', async () => {
    const harness = createHarness()
    const primary = harness.attachPane('primary')

    await harness.manager.open('/editor/1', 'primary')
    const other = editor(1)
    const otherInput = await typeDraft(other, 'zůstane')
    await harness.manager.open('/editor/2', 'primary')
    const before = editor(2)
    await typeDraft(before, 'zahodí se')

    await harness.manager.reloadPane('primary')

    const after = editor(2)
    expect(after).not.toBe(before)
    expect(after.dataset.instance).not.toBe(before.dataset.instance)
    expect(primary.target.contains(after)).toBe(true)
    expect((after.querySelector('[data-testid="draft"]') as HTMLInputElement).value).toBe('')
    expect(harness.store.windows.map(window => window.fullPath).sort()).toEqual(['/editor/1', '/editor/2'])
    expect(other.isConnected).toBe(true)
    expect(otherInput.value).toBe('zůstane')
    expect(harness.errors).toEqual([])
  })

  it('zavře okno z přepínače a do panelu vrátí poslední skryté okno', async () => {
    const harness = createHarness()
    const primary = harness.attachPane('primary')

    await harness.manager.open('/editor/1', 'primary')
    const first = editor(1)
    await harness.manager.open('/editor/2', 'primary')
    await harness.manager.open('/editor/3', 'primary')
    const hidden = windowIdFor(harness.store, '/editor/1')

    await harness.manager.closeWindow(hidden)
    expect(first.isConnected).toBe(false)
    expect(harness.store.windows.map(window => window.fullPath)).toEqual(['/editor/3', '/editor/2'])
    expect(primary.target.contains(editor(3))).toBe(true)

    await harness.manager.closeWindow(windowIdFor(harness.store, '/editor/3'))
    expect(harness.store.windows.map(window => window.fullPath)).toEqual(['/editor/2'])
    expect(primary.target.contains(editor(2))).toBe(true)
    expect(harness.store.panes[0]?.fullPath).toBe('/editor/2')

    await harness.manager.closeWindow(windowIdFor(harness.store, '/editor/2'))
    expect(harness.store.windows).toEqual([])
    expect(harness.store.panes[0]?.fullPath).toBeNull()
    expect(harness.errors).toEqual([])
  })

  it('otevře různé parametry jako samostatná okna a přesnou cestu znovu pouze vybere', async () => {
    const harness = createHarness()
    const primary = harness.attachPane('primary')

    await harness.manager.open('/editor/7', 'primary')
    const first = editor(7)
    const firstId = windowIdFor(harness.store, '/editor/7')
    await harness.manager.open('/editor/8', 'primary')
    const second = editor(8)

    expect(harness.store.windows).toHaveLength(2)
    expect(first.dataset.instance).not.toBe(second.dataset.instance)

    await harness.manager.open('/editor/7', 'primary')

    expect(harness.store.windows).toHaveLength(2)
    expect(harness.store.windows.find(window => window.paneId === 'primary')?.id).toBe(firstId)
    expect(primary.target.contains(first)).toBe(true)
    expect(editor(7)).toBe(first)
  })

  it('otevře jiné query zdrojového detailu jako nové okno a zachová report vedle', async () => {
    const harness = createHarness({ panes: 2 })
    const primary = harness.attachPane('primary')
    const secondary = harness.attachPane('secondary-2')
    await harness.manager.open('/editor/1', 'primary')
    const report = editor(1)
    await harness.manager.open('/editor/2?clearing=A', 'secondary-2')
    const firstDetail = secondary.target.querySelector<HTMLElement>('[data-editor-id="2"]')!
    await harness.manager.open('/editor/2?clearing=B', 'secondary-2')
    const secondDetail = secondary.target.querySelector<HTMLElement>('[data-editor-id="2"]')!
    expect(secondDetail.dataset.instance).not.toBe(firstDetail.dataset.instance)
    expect(harness.store.windows).toHaveLength(3)
    expect(primary.target.contains(report)).toBe(true)
    expect(harness.store.windows.find(window => window.paneId === 'secondary-2')?.fullPath).toBe('/editor/2?clearing=B')
    expect(firstDetail.isConnected).toBe(true)
  })

  it('při výběru už viditelného okna zaměří jeho panel bez přesunu DOM', async () => {
    const harness = createHarness({ panes: 2 })
    const primary = harness.attachPane('primary')
    const secondary = harness.attachPane('secondary-2')

    await harness.manager.open('/editor/1', 'primary')
    const first = editor(1)
    const firstId = windowIdFor(harness.store, '/editor/1')
    await harness.manager.open('/editor/2', 'secondary-2')
    const second = editor(2)

    expect(harness.store.activePaneId).toBe('secondary-2')
    await harness.manager.select(firstId)

    expect(harness.store.activePaneId).toBe('primary')
    expect(primary.target.contains(first)).toBe(true)
    expect(secondary.target.contains(second)).toBe(true)
    expect(editor(1)).toBe(first)
    expect(editor(2)).toBe(second)
  })

  it('přežije zmenšení a obnovení layoutu a při zavření prvního panelu posune stejné DOM uzly', async () => {
    const harness = createHarness({ panes: 3 })
    const primary = harness.attachPane('primary')
    let secondary = harness.attachPane('secondary-2')
    let third = harness.attachPane('secondary-3')

    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'secondary-2')
    await harness.manager.open('/editor/3', 'secondary-3')
    const first = editor(1)
    const second = editor(2)
    const thirdEditor = editor(3)
    const secondId = windowIdFor(harness.store, '/editor/2')
    const thirdId = windowIdFor(harness.store, '/editor/3')

    harness.store.resizeLayout(1)
    secondary.detach()
    secondary.pane.remove()
    third.detach()
    third.pane.remove()
    harness.manager.reconcile()

    expect(primary.target.contains(first)).toBe(true)
    expect(second.isConnected).toBe(true)
    expect(thirdEditor.isConnected).toBe(true)
    expect(harness.store.windows.find(window => window.id === secondId)?.paneId).toBeNull()
    expect(harness.store.windows.find(window => window.id === thirdId)?.paneId).toBeNull()

    harness.store.resizeLayout(3)
    secondary = harness.attachPane('secondary-2')
    third = harness.attachPane('secondary-3')
    await harness.manager.select(secondId)
    harness.store.activatePane('secondary-3')
    await harness.manager.select(thirdId)

    expect(secondary.target.contains(second)).toBe(true)
    expect(third.target.contains(thirdEditor)).toBe(true)
    expect(editor(2)).toBe(second)
    expect(editor(3)).toBe(thirdEditor)

    harness.manager.closePane('primary')

    expect(harness.store.paneCount).toBe(2)
    expect(primary.target.contains(second)).toBe(true)
    expect(secondary.target.contains(thirdEditor)).toBe(true)
    expect(editor(1)).toBe(first)
    expect(harness.store.windows.find(window => window.fullPath === '/editor/1')?.paneId).toBeNull()
  })

  it('nad limit dvaceti oken bez ptaní zavře nejstarší skryté okno', async () => {
    const harness = createHarness()
    harness.attachPane('primary')
    for (let id = 1; id <= 20; id++) await harness.manager.open(`/editor/${id}`, 'primary')

    const oldest = editor(1)
    await harness.manager.open('/editor/21', 'primary')

    expect(harness.store.windows).toHaveLength(20)
    expect(editor(21).isConnected).toBe(true)
    expect(oldest.isConnected).toBe(false)
    expect(harness.store.windows.some(window => window.fullPath === '/editor/1')).toBe(false)
  })

  it('přepíná opakovaně mezi dvěma naposledy použitými okny', async () => {
    const harness = createHarness()
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'primary')
    await harness.manager.open('/editor/3', 'primary')

    await harness.manager.toggleRecent()
    expect(harness.store.activeFullPath).toBe('/editor/2')

    await harness.manager.toggleRecent()
    expect(harness.store.activeFullPath).toBe('/editor/3')

    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'primary')
    await harness.manager.toggleRecent()
    expect(harness.store.activeFullPath).toBe('/editor/1')
    await harness.manager.toggleRecent()
    expect(harness.store.activeFullPath).toBe('/editor/2')
  })

  it('prochází historii oken dozadu a dopředu bez vytváření dalších instancí', async () => {
    const harness = createHarness()
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    await harness.manager.open('/editor/2', 'primary')
    await harness.manager.open('/editor/3', 'primary')
    const instances = new Map([1, 2, 3].map(id => [id, editor(id).dataset.instance]))

    await harness.manager.previous()
    expect(harness.store.activeFullPath).toBe('/editor/2')
    await harness.manager.previous()
    expect(harness.store.activeFullPath).toBe('/editor/1')
    await harness.manager.next()
    expect(harness.store.activeFullPath).toBe('/editor/2')
    await harness.manager.next()
    expect(harness.store.activeFullPath).toBe('/editor/3')

    expect(harness.store.windows).toHaveLength(3)
    for (const [id, instance] of instances) expect(editor(id).dataset.instance).toBe(instance)
  })

  it('reset zneplatní rozpracované načtení a odstraní kořeny i portály všech oken', async () => {
    const slowGate = { entered: deferred<void>(), release: deferred<boolean>() }
    const harness = createHarness({ slowGate })
    harness.attachPane('primary')
    await harness.manager.open('/editor/1', 'primary')
    const existingEditor = editor(1)

    expect(document.querySelectorAll('[data-workspace-window]')).toHaveLength(1)
    const pendingOpen = harness.manager.open('/slow/2', 'primary')
    await slowGate.entered.promise

    harness.manager.reset()
    slowGate.release.resolve(true)
    await pendingOpen

    expect(harness.store.windows).toEqual([])
    expect(existingEditor.isConnected).toBe(false)
    expect(document.querySelector('[data-editor-id="2"]')).toBeNull()
    expect(document.querySelectorAll('[data-workspace-window]')).toHaveLength(0)
    expect(harness.errors).toEqual([])
  })

  describe('obnova oken po změně dat', () => {
    const anyPath = () => createRouter({ history: createMemoryHistory(), routes: [{ path: '/:path(.*)*', component: TestEditor }] })

    async function openFrames(harness: Harness, paths: string[], paneId: PaneId = 'primary') {
      vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
      const known = document.querySelectorAll('iframe').length
      for (const path of paths) await harness.manager.open(path, paneId)
      const frames = [...document.querySelectorAll<HTMLIFrameElement>('iframe')].slice(known)
      return Object.fromEntries(frames.map((frame, index) => {
        const id = harness.store.windows[paths.length - 1 - index]!.id
        const post = vi.spyOn(frame.contentWindow!, 'postMessage').mockImplementation(() => undefined)
        const emit = (type: string, extra: Record<string, unknown> = {}) => window.dispatchEvent(new MessageEvent('message', {
          origin: window.location.origin, source: frame.contentWindow, data: { type, id, ...extra } }))
        emit('myucto-window-ready')
        post.mockClear()
        return [paths[index]!, { post, id, mutate: (path: string) => emit('myucto-window-mutation', { resource: path.split('/')[1], path }) }]
      }))
    }

    function invalidations(post: { mock: { calls: unknown[][] } }) {
      return post.mock.calls.map(call => (call[0] as { invalidation?: unknown }).invalidation).filter(Boolean)
    }

    afterEach(() => { vi.useRealTimers() })

    it('viditelné dotčené okno obnoví s debounce a sloučí víc zápisů do jednoho', async () => {
      const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' }, routerFactory: anyPath })
      harness.attachPane('primary')
      harness.attachPane('secondary-2')
      const { '/invoices': list } = await openFrames(harness, ['/invoices'], 'primary')
      const { '/invoices/5/edit': editor } = await openFrames(harness, ['/invoices/5/edit'], 'secondary-2')
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      editor!.mutate('/invoices/5')
      vi.advanceTimersByTime(100)
      editor!.mutate('/invoices/5/items')
      vi.advanceTimersByTime(249)
      expect(invalidations(list!.post)).toEqual([])
      vi.advanceTimersByTime(1)
      expect(invalidations(list!.post)).toEqual([{ supplierId: 1, resource: 'invoices' }])
    })

    it('skryté okno neobnoví hned, změnu dostane jednou až při zobrazení', async () => {
      const harness = createHarness({ frames: true, supplier: { id: 1, name: 'Firma A' }, routerFactory: anyPath })
      harness.attachPane('primary')
      const windows = await openFrames(harness, ['/invoices', '/invoices/5/edit'])
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      windows['/invoices/5/edit']!.mutate('/invoices/5')
      windows['/invoices/5/edit']!.mutate('/invoices/5/items')
      vi.advanceTimersByTime(1000)
      expect(invalidations(windows['/invoices']!.post)).toEqual([])

      vi.useRealTimers()
      await harness.manager.select(windows['/invoices']!.id)
      expect(invalidations(windows['/invoices']!.post)).toEqual([{ supplierId: 1, resource: 'invoices' }])
      await harness.manager.select(windows['/invoices/5/edit']!.id)
      await harness.manager.select(windows['/invoices']!.id)
      expect(invalidations(windows['/invoices']!.post)).toHaveLength(1)
    })

    it('okno, které uložilo v popupu, dostane změnu označenou jako vlastní', async () => {
      const harness = createHarness({ frames: true, supplier: { id: 1, name: 'Firma A' }, routerFactory: anyPath })
      harness.attachPane('primary')
      const { '/invoices/5': detail } = await openFrames(harness, ['/invoices/5'])
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      detail!.mutate('/accounting/journal/9/reverse')
      vi.advanceTimersByTime(250)
      expect(invalidations(detail!.post)).toEqual([{ supplierId: 1, resource: 'accounting', self: true }])
    })

    it('nedotčené okno a zápis, který nic nemění, obnovu nespustí', async () => {
      const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' }, routerFactory: anyPath })
      harness.attachPane('primary')
      harness.attachPane('secondary-2')
      const { '/payroll/people': payroll } = await openFrames(harness, ['/payroll/people'], 'primary')
      const { '/invoices': list } = await openFrames(harness, ['/invoices'], 'secondary-2')
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      list!.mutate('/invoices/5')
      list!.mutate('/invoices/bulk-oss/preview')
      list!.mutate('/user/preferences')
      vi.advanceTimersByTime(1000)
      expect(invalidations(payroll!.post)).toEqual([])
    })

    it('zápis zaúčtování dosáhne na seznam faktur i deník, zápis faktury na deník', async () => {
      const harness = createHarness({ panes: 2, frames: true, supplier: { id: 1, name: 'Firma A' }, routerFactory: anyPath })
      harness.attachPane('primary')
      harness.attachPane('secondary-2')
      const { '/accounting/journal': journal } = await openFrames(harness, ['/accounting/journal'], 'primary')
      const { '/invoices': list } = await openFrames(harness, ['/invoices'], 'secondary-2')
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      journal!.mutate('/accounting/journal/9/reverse')
      list!.mutate('/invoices/5')
      vi.advanceTimersByTime(250)
      expect(invalidations(list!.post)).toHaveLength(1)
      expect(invalidations(journal!.post)).toHaveLength(1)
    })

    it('okno jiné firmy se neobnoví ani při zobrazení', async () => {
      const supplier = { id: 1, name: 'Firma A' }
      const harness = createHarness({ frames: true, supplier, routerFactory: anyPath, switchSupplier: async (id) => { supplier.id = id; return true } })
      harness.attachPane('primary')
      const { '/invoices': first } = await openFrames(harness, ['/invoices'])
      supplier.id = 2
      const { '/invoices/5': other } = await openFrames(harness, ['/invoices/5'])

      other!.mutate('/invoices/5')
      await harness.manager.select(first!.id)
      expect(invalidations(first!.post)).toEqual([])
    })

    it('zápis z hlavního dokumentu dorazí viditelnému oknu aktuální firmy, po zničení už ne', async () => {
      const harness = createHarness({ frames: true, supplier: { id: 1, name: 'Firma A' } })
      harness.attachPane('primary')
      const windows = await openFrames(harness, ['/editor/1', '/editor/2'])
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      window.dispatchEvent(new CustomEvent('myucto:data-mutation', { detail: { resource: '../settings' } }))
      vi.advanceTimersByTime(1000)
      expect(invalidations(windows['/editor/2']!.post)).toEqual([])

      window.dispatchEvent(new CustomEvent('myucto:data-mutation', { detail: { resource: 'settings', path: '/settings/supplier' } }))
      vi.advanceTimersByTime(250)
      expect(invalidations(windows['/editor/2']!.post)).toEqual([{ supplierId: 1, resource: 'settings' }])
      expect(invalidations(windows['/editor/1']!.post)).toEqual([])

      harness.destroy()
      activeHarness = null
      windows['/editor/2']!.post.mockClear()
      window.dispatchEvent(new CustomEvent('myucto:data-mutation', { detail: { resource: 'settings' } }))
      vi.advanceTimersByTime(1000)
      expect(windows['/editor/2']!.post).not.toHaveBeenCalled()
    })
  })
})
