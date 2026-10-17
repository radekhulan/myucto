import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { installTableViewportScroll, type TableViewportScrollBar } from '@/utils/tableViewportScroll'

let queue: Map<number, FrameRequestCallback>
let nextFrame: number
let resizeCallback: ResizeObserverCallback
const disposals: (() => void)[] = []

beforeEach(() => {
  queue = new Map()
  nextFrame = 0
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation(callback => {
    queue.set(++nextFrame, callback)
    return nextFrame
  })
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(id => { queue.delete(id) })
  vi.spyOn(window, 'ResizeObserver').mockImplementation(class {
    constructor(callback: ResizeObserverCallback) { resizeCallback = callback }
    observe() {}
    unobserve() {}
    disconnect() {}
  })
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: 800 })
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1000 })
  metric(document.documentElement, 'clientWidth', 1000)
})

afterEach(() => {
  disposals.splice(0).forEach(dispose => dispose())
  document.body.replaceChildren()
  vi.restoreAllMocks()
})

function metric(element: HTMLElement, name: string, value: number) {
  Object.defineProperty(element, name, { configurable: true, value })
}

function box(element: HTMLElement, left: number, top: number, width: number, height: number) {
  element.getBoundingClientRect = () => ({ left, top, right: left + width, bottom: top + height, width, height, x: left, y: top, toJSON() {} })
  metric(element, 'clientWidth', width)
  metric(element, 'clientHeight', height)
  metric(element, 'offsetWidth', width)
  metric(element, 'offsetHeight', height)
}

function table(parent = document.body, left = 100, top = 100, width = 600, height = 1200) {
  const source = document.createElement('div')
  source.style.overflowX = 'auto'
  const table = document.createElement('table')
  source.append(table)
  parent.append(source)
  box(source, left, top, width, height)
  metric(source, 'scrollWidth', 1800)
  box(table, left, top, 1800, height)
  return { source, table }
}

function start(doc = document) {
  let bars: TableViewportScrollBar[] = []
  const changes = vi.fn((value: TableViewportScrollBar[]) => { bars = value })
  const controller = installTableViewportScroll({ document: doc, onChange: changes })
  disposals.push(controller.destroy)
  flush()
  return { controller, changes, get bars() { return bars } }
}

function flush() {
  const frames = [...queue.values()]
  queue.clear()
  frames.forEach(callback => callback(0))
}

async function mutations() {
  await new Promise(resolve => setTimeout(resolve, 0))
  flush()
}

describe('viewport table scroll controller', () => {
  it('pins a tall table to the viewport without changing its native scroll container', () => {
    const { source } = table()
    const original = source.getAttribute('style')
    const state = start()
    expect(state.bars).toHaveLength(1)
    expect(state.bars[0]).toMatchObject({ left: 100, top: 784, width: 600, viewportWidth: 600, contentWidth: 1800, scrollLeft: 0, maxScrollLeft: 1200 })
    expect(state.bars[0].sourceId).toBe(source.id)
    expect(source.getAttribute('style')).toBe(original)
    expect(source.scrollTop).toBe(0)
    state.controller.destroy()
    expect(source.id).toBe('')
    expect(state.bars).toEqual([])
  })

  it('synchronizes both ways and keeps independent table sources separate', () => {
    const a = table(undefined, 0, 10, 450)
    const b = table(undefined, 500, 10, 450)
    const state = start()
    const [first, second] = state.bars
    state.controller.scrollTo(first.id, 380)
    flush()
    expect(a.source.scrollLeft).toBe(380)
    expect(b.source.scrollLeft).toBe(0)
    expect(state.bars[0].scrollLeft).toBe(380)
    b.source.scrollLeft = 220
    b.source.dispatchEvent(new Event('scroll'))
    flush()
    expect(state.bars.find(bar => bar.id === second.id)?.scrollLeft).toBe(220)
    state.controller.scrollTo(first.id, Infinity)
    expect(a.source.scrollLeft).toBe(380)
    state.controller.scrollTo(first.id, 9000)
    expect(a.source.scrollLeft).toBe(1350)
  })

  it('uses the nearest real scroll owner and deduplicates multiple tables sharing it', () => {
    const { source } = table()
    const extra = document.createElement('table')
    source.append(extra)
    box(extra, 100, 120, 1800, 1000)
    const inner = document.createElement('div')
    inner.style.overflowX = 'auto'
    source.firstElementChild!.replaceWith(inner)
    inner.append(document.createElement('table'))
    box(inner, 100, 100, 1800, 1200)
    box(inner.firstElementChild as HTMLElement, 100, 100, 1800, 1200)
    metric(inner, 'scrollWidth', 1800)
    const state = start()
    expect(state.bars).toHaveLength(1)
    state.controller.scrollTo(state.bars[0].id, 80)
    expect(source.scrollLeft).toBe(80)
    expect(inner.scrollLeft).toBe(0)
  })

  it('clips to nested panel client bounds and stays above the sticky app footer', () => {
    const panel = document.createElement('section')
    panel.style.overflowX = 'hidden'
    panel.style.overflowY = 'hidden'
    document.body.append(panel)
    box(panel, 150, 80, 400, 600)
    const { source } = table(panel)
    const footer = document.createElement('footer')
    footer.className = 'sticky'
    footer.style.position = 'sticky'
    document.body.append(footer)
    box(footer, 0, 650, 1000, 150)
    const state = start()
    expect(state.bars[0]).toMatchObject({ left: 150, width: 400, top: 634 })
    box(panel, 150, -600, 400, 600)
    panel.dispatchEvent(new Event('scroll'))
    flush()
    expect(state.bars).toEqual([])
    expect(source.scrollTop).toBe(0)
  })

  it('does not duplicate a visible native scrollbar or expose hidden and offscreen tables', () => {
    const native = table(undefined, 0, 100, 400, 300)
    metric(native.source, 'clientHeight', 284)
    const hidden = table()
    hidden.source.style.visibility = 'hidden'
    const inert = table()
    inert.source.inert = true
    table(undefined, 100, 900)
    const state = start()
    expect(state.bars).toEqual([])
  })

  it('hides background scrollbars behind modal overlays and clips the modal table', () => {
    table()
    const overlay = document.createElement('div')
    overlay.className = 'fixed inset-0'
    overlay.style.cssText = 'position:fixed;z-index:50'
    document.body.append(overlay)
    box(overlay, 0, 0, 1000, 800)
    const body = document.createElement('div')
    body.style.overflowY = 'auto'
    overlay.append(body)
    box(body, 200, 200, 500, 400)
    table(body, 200, 200, 500, 1000)
    const state = start()
    expect(state.bars).toHaveLength(1)
    expect(state.bars[0]).toMatchObject({ left: 200, width: 500, top: 584, zIndex: 51 })
  })

  it('does not duplicate an overlay-native scrollbar when the table bottom is visible', () => {
    table(undefined, 100, 200, 600, 300)
    const state = start()
    expect(state.bars).toEqual([])
  })

  it.each([
    ['sticky footer', 760, 40, 1200],
    ['partially visible footer', 790, 50, 1200],
  ])('keeps a %s readable instead of overlaying its totals', (_name, footerTop, footerHeight, sourceHeight) => {
    const { table: element } = table(undefined, 100, 100, 600, sourceHeight)
    const footer = element.createTFoot()
    footer.insertRow().insertCell().textContent = 'TOTAL 100'
    box(footer, 100, footerTop, 1800, footerHeight)
    const state = start()
    expect(state.bars).toHaveLength(1)
    expect(state.bars[0].top + 16).toBeLessThanOrEqual(footerTop)
    expect(state.bars[0].top).toBe(footerTop - 16)
  })

  it('keeps the bar at the visible bottom while the totals footer is still offscreen', () => {
    const { table: element } = table()
    const footer = element.createTFoot()
    box(footer, 100, 1200, 1800, 100)
    const state = start()
    expect(state.bars[0].top).toBe(784)
  })

  it.each([400, 450])('leaves only the native scrollbar below a fully visible totals footer (source height %s)', sourceHeight => {
    const { table: element } = table(undefined, 100, 100, 600, sourceHeight)
    const footer = element.createTFoot()
    box(footer, 100, 460, 1800, 40)
    const state = start()
    expect(state.bars).toEqual([])
  })

  it('hands control back to the native scrollbar as its bottom enters the viewport', () => {
    const { source } = table()
    const state = start()
    expect(state.bars).toHaveLength(1)
    box(source, 100, 100, 600, 400)
    source.dispatchEvent(new Event('scroll'))
    flush()
    expect(state.bars).toEqual([])
    box(source, 100, 100, 600, 1200)
    source.dispatchEvent(new Event('scroll'))
    flush()
    expect(state.bars).toHaveLength(1)
  })

  it('hides the control when only the totals footer remains visible', () => {
    const { table: element } = table(undefined, 100, -900, 600, 1200)
    const footer = element.createTFoot()
    box(footer, 100, 10, 1800, 300)
    const state = start()
    expect(state.bars).toEqual([])
  })

  it('portals into the semantic modal and translates fixed coordinates through its containing block', () => {
    const modal = document.createElement('section')
    modal.setAttribute('role', 'dialog')
    modal.setAttribute('aria-modal', 'true')
    modal.style.cssText = 'transform:translateX(20px);z-index:50'
    document.body.append(modal)
    box(modal, 100, 150, 800, 600)
    table(modal, 200, 200, 500, 1000)
    const state = start()
    expect(state.bars[0].portalTarget).toBe(modal)
    expect(state.bars[0]).toMatchObject({ left: 100, top: 634, width: 500, zIndex: 51 })
    const calls = state.changes.mock.calls.length
    state.controller.refresh()
    flush()
    expect(state.changes).toHaveBeenCalledTimes(calls)
  })

  it('clips scaled source client boxes in physical pixels and settles after a transform transition', () => {
    const modal = document.createElement('section')
    modal.setAttribute('role', 'dialog')
    modal.setAttribute('aria-modal', 'true')
    modal.style.cssText = 'transform:scale(.5);z-index:50;overflow-x:hidden;overflow-y:hidden'
    document.body.append(modal)
    box(modal, 100, 100, 400, 300)
    metric(modal, 'offsetWidth', 800)
    metric(modal, 'offsetHeight', 600)
    metric(modal, 'clientWidth', 800)
    metric(modal, 'clientHeight', 600)
    const { source, table: element } = table(modal, 200, 200, 250, 500)
    metric(source, 'offsetWidth', 500)
    metric(source, 'offsetHeight', 1000)
    metric(source, 'clientWidth', 500)
    metric(source, 'clientHeight', 1000)
    box(element, 200, 200, 900, 500)
    const state = start()
    expect(state.bars[0]).toMatchObject({ left: 200, width: 500, top: 584 })
    box(source, 220, 200, 250, 500)
    metric(source, 'offsetWidth', 500)
    metric(source, 'offsetHeight', 1000)
    metric(source, 'clientWidth', 500)
    metric(source, 'clientHeight', 1000)
    source.dispatchEvent(new Event('transitionend', { bubbles: true }))
    flush()
    expect(state.bars[0].left).toBe(240)
  })

  it('discovers mounted and resized tables, clears removed routes and stops after destroy', async () => {
    const state = start()
    expect(state.bars).toEqual([])
    const { source } = table()
    metric(source, 'scrollWidth', 600)
    await mutations()
    expect(state.bars).toEqual([])
    metric(source, 'scrollWidth', 1800)
    resizeCallback([], {} as ResizeObserver)
    flush()
    expect(state.bars).toHaveLength(1)
    const sourceId = source.id
    source.remove()
    await mutations()
    expect(state.bars).toEqual([])
    expect(source.id).toBe('')
    expect(sourceId).not.toBe('')
    state.controller.destroy()
    const calls = state.changes.mock.calls.length
    table()
    await mutations()
    window.dispatchEvent(new Event('resize'))
    flush()
    expect(state.changes).toHaveBeenCalledTimes(calls)
  })

  it('ignores its own control mutations and preserves source IDs supplied by the page', async () => {
    const { source } = table()
    source.id = 'invoice-list'
    const state = start()
    const calls = state.changes.mock.calls.length
    const control = document.createElement('div')
    control.dataset.tableViewportScrollbar = ''
    document.body.append(control)
    await mutations()
    control.style.left = '10px'
    await mutations()
    expect(queue.size).toBe(0)
    expect(state.changes).toHaveBeenCalledTimes(calls)
    state.controller.destroy()
    expect(source.id).toBe('invoice-list')
  })

  it('maps modern RTL negative scroll offsets to the visual left-to-right thumb position', () => {
    const { source } = table()
    source.style.direction = 'rtl'
    const state = start()
    expect(state.bars[0].scrollLeft).toBe(1200)
    state.controller.scrollTo(state.bars[0].id, 400)
    flush()
    expect(source.scrollLeft).toBe(-800)
    expect(state.bars[0].scrollLeft).toBe(400)
  })

  it('scopes scanning, viewport geometry and scroll writes to its own iframe document', () => {
    const parent = table()
    const iframe = document.createElement('iframe')
    document.body.append(iframe)
    const doc = iframe.contentDocument!
    const view = doc.defaultView!
    vi.spyOn(view, 'requestAnimationFrame').mockImplementation(callback => {
      queue.set(++nextFrame, callback)
      return nextFrame
    })
    vi.spyOn(view, 'cancelAnimationFrame').mockImplementation(id => { queue.delete(id) })
    Object.defineProperty(view, 'innerHeight', { configurable: true, value: 400 })
    Object.defineProperty(view, 'innerWidth', { configurable: true, value: 500 })
    metric(doc.documentElement, 'clientWidth', 500)
    const child = table(doc.body, 20, 40, 460, 1000)
    const state = start(doc)
    expect(state.bars).toHaveLength(1)
    expect(state.bars[0]).toMatchObject({ left: 20, width: 460, top: 384 })
    state.controller.scrollTo(state.bars[0].id, 200)
    expect(child.source.scrollLeft).toBe(200)
    expect(parent.source.scrollLeft).toBe(0)
  })
})
