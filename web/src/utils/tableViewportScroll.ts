export const TABLE_VIEWPORT_SCROLLBAR_HEIGHT = 16

export interface TableViewportScrollBar {
  id: string
  sourceId: string
  portalTarget?: HTMLElement
  left: number
  top: number
  width: number
  viewportWidth: number
  contentWidth: number
  scrollLeft: number
  maxScrollLeft: number
  zIndex: number
}

interface Entry {
  id: string
  source: HTMLElement
  tables: HTMLTableElement[]
}

const CONTROL = '[data-table-viewport-scrollbar]'
const clips = /^(auto|scroll|hidden|clip)$/
const scrolls = /^(auto|scroll)$/
let controllerSequence = 0

/** One controller per document: workspace iframes keep their own viewport and scroll state. */
export function installTableViewportScroll(options: {
  document?: Document
  onChange: (bars: TableViewportScrollBar[]) => void
}): { destroy: () => void; refresh: () => void; scrollTo: (id: string, position: number) => void } {
  const doc = options.document ?? document
  const view = doc.defaultView!
  let disposed = false
  let frame: number | null = null
  let needsScan = true
  let sequence = 0
  const controllerId = ++controllerSequence
  const generatedIds = new Map<HTMLElement, string>()
  let entries = new Map<HTMLElement, Entry>()
  let observed = new Set<Element>()
  let previous = ''
  let previousPortals: (HTMLElement | undefined)[] = []
  const resize = typeof view.ResizeObserver === 'function' ? new view.ResizeObserver(onResize) : null
  const mutations = new view.MutationObserver(records => {
    if (!records.some(record => {
      if (record.target instanceof view.Element && record.target.closest(CONTROL)) return false
      if (record.type !== 'childList') return true
      return [...record.addedNodes, ...record.removedNodes].some(node =>
        !(node instanceof view.Element && (node.matches(CONTROL) || node.closest(CONTROL))))
    })) return
    needsScan = true
    schedule()
  })

  function style(el: Element): CSSStyleDeclaration { return view.getComputedStyle(el) }

  function hidden(el: HTMLElement): boolean {
    for (let node: HTMLElement | null = el; node; node = node.parentElement) {
      if (node.hidden || node.inert || node.getAttribute('aria-hidden') === 'true') return true
      const css = style(node)
      if (css.display === 'none' || css.visibility === 'hidden' || css.visibility === 'collapse') return true
    }
    return false
  }

  function sourceFor(table: HTMLTableElement): HTMLElement | null {
    for (let node: HTMLElement | null = table; node && node !== doc.body && node !== doc.documentElement; node = node.parentElement) {
      const css = style(node)
      if (scrolls.test(css.overflowX) && node.scrollWidth > node.clientWidth + 1) return node
      // A non-scrollable clipping wrapper prevents an outer scroller reaching the table's columns.
      if (/^(hidden|clip)$/.test(css.overflowX) && node.scrollWidth > node.clientWidth + 1) return null
    }
    return null
  }

  function scan(): void {
    needsScan = false
    const next = new Map<HTMLElement, Entry>()
    const targets = new Set<Element>([doc.documentElement, doc.body])
    for (const table of doc.querySelectorAll<HTMLTableElement>('table')) {
      if (table.closest(CONTROL)) continue
      // Keep hidden tables under observation so a responsive breakpoint can reveal them.
      for (let node: HTMLElement | null = table; node; node = node.parentElement) targets.add(node)
      const source = sourceFor(table)
      if (!source) continue
      let entry = next.get(source)
      if (!entry) {
        entry = entries.get(source) ?? { id: `table-scroll-${++sequence}`, source, tables: [] }
        if (!source.id) {
          let id = `table-scroll-source-${controllerId}-${entry.id}`
          while (doc.getElementById(id)) id += '-source'
          source.id = id
          generatedIds.set(source, id)
        }
        entry.tables = []
        next.set(source, entry)
      }
      entry.tables.push(table)
    }
    for (const footer of doc.querySelectorAll('footer.sticky')) targets.add(footer)
    for (const target of observed) if (!targets.has(target)) resize?.unobserve(target)
    for (const target of targets) if (!observed.has(target)) resize?.observe(target)
    observed = targets
    entries = next
    for (const [source, id] of generatedIds) if (!next.has(source)) {
      if (source.id === id) source.removeAttribute('id')
      generatedIds.delete(source)
    }
  }

  function layer(el: HTMLElement): number {
    let level = 0
    for (let node: HTMLElement | null = el; node; node = node.parentElement) {
      const value = Number.parseInt(style(node).zIndex, 10)
      if (Number.isFinite(value)) level = Math.max(level, value)
    }
    return level
  }

  function clientBox(el: HTMLElement) {
    const rect = el.getBoundingClientRect()
    const scaleX = el.offsetWidth > 0 ? rect.width / el.offsetWidth : 1
    const scaleY = el.offsetHeight > 0 ? rect.height / el.offsetHeight : 1
    const left = rect.left + el.clientLeft * scaleX
    const top = rect.top + el.clientTop * scaleY
    return { left, top, right: left + el.clientWidth * scaleX, bottom: top + el.clientHeight * scaleY }
  }

  function fixedContainer(portal: HTMLElement | null) {
    for (let node = portal; node && node !== doc.documentElement; node = node.parentElement) {
      const css = style(node)
      if (!(css.transform && css.transform !== 'none') && !(css.perspective && css.perspective !== 'none')
        && !(css.filter && css.filter !== 'none') && !(css.backdropFilter && css.backdropFilter !== 'none')
        && !/(paint|layout|strict|content)/.test(css.contain) && !/(transform|perspective|filter)/.test(css.willChange)) continue
      const rect = node.getBoundingClientRect()
      return { node, rect, scaleX: (node.offsetWidth > 0 ? rect.width / node.offsetWidth : 1) || 1,
        scaleY: (node.offsetHeight > 0 ? rect.height / node.offsetHeight : 1) || 1 }
    }
    return null
  }

  function measure(entry: Entry, modal: HTMLElement | undefined): TableViewportScrollBar | null {
    const { source } = entry
    if (hidden(source) || (modal && !modal.contains(source))) return null
    const sourceRect = source.getBoundingClientRect()
    const visual = view.visualViewport
    let left = visual?.offsetLeft ?? 0
    let right = left + (visual?.width ?? (doc.documentElement.clientWidth || view.innerWidth))
    let top = visual?.offsetTop ?? 0
    let bottom = top + (visual?.height ?? view.innerHeight)
    const sourceBox = clientBox(source)
    left = Math.max(left, sourceBox.left)
    right = Math.min(right, sourceBox.right)
    top = Math.max(top, sourceBox.top)
    for (let node = source.parentElement; node && node !== doc.body && node !== doc.documentElement; node = node.parentElement) {
      const css = style(node)
      const rect = clientBox(node)
      if (clips.test(css.overflowX)) {
        left = Math.max(left, rect.left)
        right = Math.min(right, rect.right)
      }
      if (clips.test(css.overflowY)) {
        top = Math.max(top, rect.top)
        bottom = Math.min(bottom, rect.bottom)
      }
    }
    if (!modal) {
      for (const footer of doc.querySelectorAll<HTMLElement>('footer.sticky')) {
        if (hidden(footer)) continue
        const rect = footer.getBoundingClientRect()
        const css = style(footer)
        if ((css.position === 'sticky' || css.position === 'fixed') && rect.right > left && rect.left < right && rect.top > top) bottom = Math.min(bottom, rect.top)
      }
    }
    // Once its bottom is reachable, use the native scrollbar below the table footer.
    // Overlay scrollbars can also be visible without reserving a measurable gutter.
    if (sourceRect.bottom <= bottom + 1) return null
    bottom = Math.min(bottom, sourceBox.bottom)
    const height = TABLE_VIEWPORT_SCROLLBAR_HEIGHT
    const portal = source.closest<HTMLElement>('[role="dialog"][aria-modal="true"], dialog[open]')
    const container = fixedContainer(portal)
    const physicalHeight = height * (container?.scaleY ?? 1)
    // Totals remain readable as a normal or sticky footer enters the viewport.
    // An existing blank gutter below the footer can still hold the control.
    const footers = entry.tables.flatMap(table => [...table.querySelectorAll<HTMLElement>('tfoot, .table-summary-row, .table-total-row')]
      .filter(footer => footer.closest('table') === table && !hidden(footer))
      .map(footer => footer.getBoundingClientRect()))
      .sort((a, b) => b.top - a.top)
    for (const footer of footers) {
      if (footer.right > left && footer.left < right && footer.top < bottom && footer.bottom > bottom - physicalHeight) bottom = footer.top
    }
    if (right - left < 32 || bottom - top < physicalHeight * 2) return null
    if (!entry.tables.some(table => {
      if (hidden(table)) return false
      const rect = table.getBoundingClientRect()
      return rect.bottom > top + physicalHeight && rect.top < bottom - physicalHeight && rect.right > left && rect.left < right
    })) return null
    const maxScrollLeft = source.scrollWidth - source.clientWidth
    if (maxScrollLeft <= 1) return null
    const raw = style(source).direction === 'rtl' ? maxScrollLeft + source.scrollLeft : source.scrollLeft
    const bar: TableViewportScrollBar = {
      id: entry.id, sourceId: source.id, left, top: bottom - physicalHeight, width: right - left,
      viewportWidth: source.clientWidth, contentWidth: source.scrollWidth,
      scrollLeft: Math.max(0, Math.min(maxScrollLeft, raw)), maxScrollLeft,
      zIndex: Math.max(21, layer(source) + 1),
    }
    // Keep modal controls in its accessible subtree. A transformed/filtering ancestor
    // changes the fixed-position containing block, so translate viewport geometry there.
    if (portal) {
      bar.portalTarget = portal
      if (container) {
        const { node, rect, scaleX, scaleY } = container
        bar.left = (bar.left - rect.left) / scaleX - node.clientLeft + node.scrollLeft
        bar.top = (bar.top - rect.top) / scaleY - node.clientTop + node.scrollTop
        bar.width /= scaleX
      }
    }
    return bar
  }

  function update(): void {
    frame = null
    if (disposed) return
    if (needsScan) scan()
    const modals = [...doc.querySelectorAll<HTMLElement>('[role="dialog"][aria-modal="true"], dialog[open], [class~="fixed"][class~="inset-0"]')]
      .filter(modal => {
        if (hidden(modal) || modal.getBoundingClientRect().width <= 0) return false
        return modal.matches('[role="dialog"], dialog') || style(modal).position === 'fixed' && layer(modal) >= 40
      })
      .sort((a, b) => layer(a) - layer(b))
    const modal = modals.at(-1)
    const bars = [...entries.values()].flatMap(entry => {
      const bar = measure(entry, modal)
      return bar ? [bar] : []
    })
    const serialized = JSON.stringify(bars.map(({ portalTarget: _, ...bar }) => bar))
    if (serialized !== previous || bars.some((bar, index) => bar.portalTarget !== previousPortals[index])) {
      previous = serialized
      previousPortals = bars.map(bar => bar.portalTarget)
      options.onChange(bars)
    }
  }

  function schedule(): void {
    if (!disposed && frame === null) frame = view.requestAnimationFrame(update)
  }

  function onResize(): void { needsScan = true; schedule() }
  doc.addEventListener('scroll', schedule, { capture: true, passive: true })
  doc.addEventListener('transitionend', schedule, true)
  doc.addEventListener('animationend', schedule, true)
  view.addEventListener('resize', onResize, { passive: true })
  view.visualViewport?.addEventListener('resize', onResize, { passive: true })
  view.visualViewport?.addEventListener('scroll', schedule, { passive: true })
  mutations.observe(doc.body, { subtree: true, childList: true, characterData: true, attributes: true,
    attributeFilter: ['class', 'style', 'hidden', 'inert', 'aria-hidden', 'aria-modal', 'open', 'colspan', 'width'] })
  schedule()

  return {
    refresh: onResize,
    scrollTo(id, position) {
      const entry = [...entries.values()].find(candidate => candidate.id === id)
      if (!entry || !Number.isFinite(position)) return
      const max = entry.source.scrollWidth - entry.source.clientWidth
      const value = Math.max(0, Math.min(max, position))
      entry.source.scrollLeft = style(entry.source).direction === 'rtl' ? value - max : value
      schedule()
    },
    destroy() {
      if (disposed) return
      disposed = true
      if (frame !== null) view.cancelAnimationFrame(frame)
      resize?.disconnect()
      mutations.disconnect()
      doc.removeEventListener('scroll', schedule, true)
      doc.removeEventListener('transitionend', schedule, true)
      doc.removeEventListener('animationend', schedule, true)
      view.removeEventListener('resize', onResize)
      view.visualViewport?.removeEventListener('resize', onResize)
      view.visualViewport?.removeEventListener('scroll', schedule)
      entries.clear()
      observed.clear()
      for (const [source, id] of generatedIds) if (source.id === id) source.removeAttribute('id')
      generatedIds.clear()
      options.onChange([])
    },
  }
}
