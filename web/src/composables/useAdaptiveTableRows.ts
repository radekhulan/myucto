import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue'
import { adaptiveTableWidths } from '@/utils/adaptiveTableLayout'

/**
 * Šířka levého žlábku zalomeného řádku v px. Musí sedět s tím, co se do první
 * buňky opravdu vejde: úchyt se zaškrtávátkem potřebují víc než samotné
 * zaškrtávátko, šipka rozbalení (`list-gutter-mini`) méně. Rozvržení sloupců
 * se počítá ze stejné hodnoty, jinak by poslední buňka řádku přetekla na další.
 */
function gutterWidth(table: HTMLTableElement): number {
  if (table.classList.contains('list-gutter-mini')) return 28
  const first = table.tBodies[0]?.rows[0]?.cells[0]
  return first?.querySelector('.workspace-row-drag-handle') && first.querySelector('input[type="checkbox"]') ? 52 : 44
}

export function useAdaptiveTableRows(targets: Readonly<Ref<HTMLElement[]>>, enabled?: Readonly<Ref<boolean>>): void {
  let timer: ReturnType<typeof setTimeout> | null = null
  let resize: ResizeObserver | null = null
  let mutations: MutationObserver | null = null
  let disposed = false

  function apply() {
    timer = null
    const tables = targets.value.map(box => box.querySelector<HTMLTableElement>('table'))
      .filter((table): table is HTMLTableElement => table !== null && table.parentElement!.clientWidth > 0)
    if (tables.length === 0) return
    const widths: number[] = []
    // Sloupec s `data-adaptive-max` (popis) nese text, který se smí zalomit. Do rozhodnutí
    // o víceřádkovém režimu se počítá jen do svého stropu a v jednořádkovém režimu dostane
    // přes `--adaptive-text-max` zbytek volného místa. Tabulka tak raději zalomí dlouhý
    // text, než aby rozdělila každý řádek do dvou.
    let textColumn = -1
    for (const table of tables) {
      const clone = table.cloneNode(true) as HTMLTableElement
      clone.classList.remove('adaptive-table', 'multirow-table', 'column-labels')
      clone.setAttribute('aria-hidden', 'true')
      clone.inert = true
      Object.assign(clone.style, { position: 'fixed', visibility: 'hidden', pointerEvents: 'none', width: 'max-content', minWidth: '0', top: '-10000px' })
      clone.style.removeProperty('--adaptive-text-max')
      clone.querySelectorAll<HTMLElement>('th, td').forEach(cell => cell.style.removeProperty('flex-basis'))
      clone.querySelectorAll('.table-detail-row').forEach(row => row.remove())
      document.body.append(clone)
      try {
        const cells = Array.from(clone.tHead?.rows[0]?.cells ?? [])
        cells.forEach((cell, index) => {
          if (index === 0 || index === cells.length - 1) return
          const textCap = Number(cell.dataset.adaptiveMax) || 0
          if (textCap > 0) textColumn = index
          widths[index] = Math.max(widths[index] ?? 0, Math.min(textCap || 320, Math.ceil(cell.getBoundingClientRect().width)))
        })
      } finally { clone.remove() }
    }
    const total = widths.reduce((sum, width) => sum + width, 0)
    for (const table of tables) {
      const gutter = gutterWidth(table)
      table.style.setProperty('--adaptive-gutter', `${gutter}px`)
      // Se stropem textu se počítá i poslední ovládací buňka, jinak by roztažený text
      // přetekl právě o ni.
      const headCells = table.tHead?.rows[0]?.cells
      const trailing = textColumn > 0 && headCells?.length ? Math.ceil(headCells[headCells.length - 1]!.getBoundingClientRect().width) : 0
      const available = table.parentElement!.clientWidth - gutter
      const singleLine = available - trailing
      const wrapped = enabled?.value !== false && window.innerWidth >= 768 && total > singleLine
      if (!wrapped && textColumn > 0) table.style.setProperty('--adaptive-text-max', `${widths[textColumn]! + Math.max(0, singleLine - total)}px`)
      else table.style.removeProperty('--adaptive-text-max')
      const weights = Array.from(table.tHead?.rows[0]?.cells ?? []).slice(1, -1)
        .map(cell => Number(cell.dataset.adaptiveWeight) || 1)
      const layout = wrapped ? adaptiveTableWidths(widths.slice(1), available, weights) : []
      let primaryCount = 0
      let primaryWidth = 0
      for (const width of layout) {
        if (primaryWidth + width > available) break
        primaryWidth += width
        primaryCount++
      }
      table.classList.toggle('adaptive-table', wrapped)
      const rows = [...Array.from(table.tHead?.rows ?? []), ...Array.from(table.tBodies).flatMap(body => Array.from(body.rows))]
      for (const row of rows) {
        if (row.classList.contains('table-detail-row')) continue
        Array.from(row.cells).forEach((cell, index) => {
          if (wrapped && layout[index - 1]) cell.style.flexBasis = `${layout[index - 1]}px`
          else cell.style.removeProperty('flex-basis')
          cell.toggleAttribute('data-adaptive-secondary', wrapped && index > primaryCount && index < row.cells.length - 1)
        })
        const secondary = row.querySelector<HTMLTableCellElement>('[data-adaptive-secondary]')
        if (wrapped && table.classList.contains('list-stacked-controls') && secondary && row.parentElement?.tagName === 'TBODY') {
          row.style.setProperty('--adaptive-expand-top', `${secondary.offsetTop}px`)
        } else row.style.removeProperty('--adaptive-expand-top')
      }
    }
  }

  function schedule() {
    if (!disposed && timer === null) timer = setTimeout(apply, 0)
  }

  function bind() {
    resize?.disconnect()
    mutations?.disconnect()
    for (const box of targets.value) {
      resize?.observe(box)
      const table = box.querySelector('table')
      if (table) resize?.observe(table)
      mutations?.observe(box, { childList: true, subtree: true, characterData: true, attributes: true, attributeOldValue: true, attributeFilter: ['class'] })
    }
    schedule()
  }

  onMounted(() => {
    if (typeof ResizeObserver !== 'undefined') resize = new ResizeObserver(schedule)
    mutations = new MutationObserver(records => {
      if (records.some(record => record.type !== 'attributes'
        || record.oldValue !== (record.target as Element).getAttribute('class'))) schedule()
    })
    window.addEventListener('resize', schedule)
    bind()
    void document.fonts?.ready.then(schedule)
  })
  watch(() => targets.value.slice(), bind, { flush: 'post' })
  if (enabled) watch(enabled, schedule, { flush: 'post' })
  onBeforeUnmount(() => {
    disposed = true
    resize?.disconnect()
    mutations?.disconnect()
    window.removeEventListener('resize', schedule)
    if (timer !== null) clearTimeout(timer)
  })
}
