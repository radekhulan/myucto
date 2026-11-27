import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { JournalLine } from '@/api/accounting'

// Dimenze zapnuté: typ 1 je Středisko, typ 2 Zakázka.
const types = [
  { id: 1, name: 'Středisko', kind: 'cost_center' },
  { id: 2, name: 'Zakázka', kind: 'project' },
]
const values = [
  { id: 11, type_id: 1, name: 'DEMO-123-PROVOZ – Ukázkový provoz s velmi dlouhým názvem', is_active: true },
  { id: 21, type_id: 2, name: 'Zakázka A', is_active: true },
]
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true }, canEdit: { value: false }, loading: { value: false },
    types: { value: types }, values: { value: values }, documentTypes: { value: types },
    valueById: { value: new Map(values.map(v => [v.id, v])) }, typeById: { value: new Map(types.map(ty => [ty.id, ty])) },
    load: () => Promise.resolve(), reload: () => Promise.resolve(),
    options: () => [], treeOf: () => [], pathOf: () => [], labelOf: () => '',
    valueLabel: (id: number) => values.find(v => v.id === id)?.name ?? '',
  }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number, currency?: string) => `${value.toFixed(2)}${currency ? ` ${currency}` : ' Kč'}`,
}))

import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'

/** ResizeObserver, kterému test sám řekne šířku karty. */
const observers: { cb: ResizeObserverCallback; el: Element }[] = []
class FakeResizeObserver {
  constructor(private cb: ResizeObserverCallback) {}
  observe(el: Element) { observers.push({ cb: this.cb, el }) }
  unobserve() {}
  disconnect() {}
}

async function resize(width: number) {
  await flushPromises()
  for (const o of observers) {
    o.cb([{ contentRect: { width } } as ResizeObserverEntry], o as unknown as ResizeObserver)
  }
  await flushPromises()
}

const RouterLinkStub = { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' }

function line(id: number, side: 'debit' | 'credit', code: string, name: string, amount: number, extra: Partial<JournalLine> = {}): JournalLine {
  return {
    id, entry_id: 1, supplier_id: 1, account_id: id * 10, side, amount, currency_code: null, fx_rate: null,
    amount_foreign: null, cost_center: null, line_no: id, account_code: code, account_name: name, ...extra,
  } as JournalLine
}

// Přijatá faktura s DPH: 518 + 343 proti 321 — dvě souvztažnosti, součet Celkem.
const lines = [
  line(1, 'debit', '518.100', 'Ostatní služby — nájemné a provoz kanceláře', 1_234_567.89,
    { cost_center: 'DEMO-123-PROVOZ', dimensions: { 1: 11, 2: 21 } }),
  line(2, 'debit', '343.100', 'DPH na vstupu', 259_259.26, { cost_center: 'DEMO-123-PROVOZ', dimensions: { 1: 11 } }),
  line(3, 'credit', '321.100', 'Dodavatelé — tuzemsko', 1_493_827.15),
]

function mountTable(props: Record<string, unknown> = {}) {
  return mount(JournalLinesTable, {
    props: { lines, compactBelow: 880, ...props },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('JournalLinesTable v úzké kartě', () => {
  beforeEach(() => {
    observers.length = 0
    vi.stubGlobal('ResizeObserver', FakeResizeObserver)
  })
  afterEach(() => { vi.unstubAllGlobals() })

  it('ve sloupci vedle náhledu PDF (karta 700 px) ukáže řádkový tvar bez tabulky', async () => {
    const wrapper = mountTable()
    await resize(700)
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('[data-test="journal-lines-compact"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="journal-compact-row"]')).toHaveLength(2)
  })

  it('částky jsou celé, vpravo a nezalomitelné; Celkem i u souhrnu', async () => {
    const wrapper = mountTable()
    await resize(700)
    const amounts = wrapper.findAll('[data-test="journal-compact-amount"]')
    expect(amounts.map(a => a.text())).toEqual(['1234567.89 Kč', '259259.26 Kč'])
    for (const amount of amounts) {
      expect(amount.classes()).toEqual(expect.arrayContaining(['shrink-0', 'whitespace-nowrap', 'text-right']))
    }
    const total = wrapper.get('[data-test="journal-compact-total"]')
    expect(total.text()).toContain('1493827.15 Kč')
    expect(total.find('.whitespace-nowrap.shrink-0').exists()).toBe(true)
  })

  it('název účtu se zkracuje a celý je v title; štítky dimenzí se zkracují taky', async () => {
    const wrapper = mountTable()
    await resize(700)
    const name = wrapper.get('[data-test="journal-compact-account-name"]')
    expect(name.classes()).toContain('truncate')
    expect(name.element.parentElement!.getAttribute('title')).toContain('518.100 Ostatní služby — nájemné a provoz kanceláře')
    const chip = wrapper.get('[data-test="dimension-chips"] > span')
    expect(chip.classes()).toEqual(expect.arrayContaining(['max-w-full', 'min-w-0']))
    expect(chip.attributes('title')).toBe('Středisko: DEMO-123-PROVOZ – Ukázkový provoz s velmi dlouhým názvem')
    expect(chip.find('.truncate').exists()).toBe(true)
  })

  it('středisko vynechá, když ho už ukazuje štítek dimenze; bez štítku ho ukáže', async () => {
    const wrapper = mountTable()
    await resize(700)
    expect(wrapper.find('[data-test="journal-compact-cost-center"]').exists()).toBe(false)

    const plain = mountTable({ lines: lines.map(l => ({ ...l, dimensions: {} })) })
    await resize(700)
    expect(plain.findAll('[data-test="journal-compact-cost-center"]').length).toBeGreaterThan(0)
  })

  it('široká karta (1000 px) zůstává tabulkou MD | DAL | Středisko | Částka', async () => {
    const wrapper = mountTable()
    await resize(1000)
    expect(wrapper.find('[data-test="journal-lines-compact"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.text()).toContain('accounting.journal.cost_center')
  })

  it('přepíná podle šířky karty i za běhu', async () => {
    const wrapper = mountTable()
    await resize(1000)
    expect(wrapper.find('table').exists()).toBe(true)
    await resize(760)
    expect(wrapper.find('table').exists()).toBe(false)
    await resize(1200)
    expect(wrapper.find('table').exists()).toBe(true)
  })

  it('bez prahu (deník) se neřídí šířkou karty', async () => {
    const wrapper = mountTable({ compactBelow: null })
    await resize(500)
    expect(wrapper.find('[data-test="journal-lines-compact"]').exists()).toBe(false)
  })

  it('rozpad po stranách (víc nohou na obou stranách) má v úzké kartě řádek na nohu', async () => {
    const split = [
      line(1, 'debit', '518.100', 'Služby', 100),
      line(2, 'debit', '518.200', 'Služby 2', 50),
      line(3, 'credit', '321.100', 'Dodavatelé', 120),
      line(4, 'credit', '321.200', 'Dodavatelé 2', 30),
    ]
    const wrapper = mountTable({ lines: split })
    await resize(600)
    expect(wrapper.findAll('[data-test="journal-compact-row"]')).toHaveLength(4)
    expect(wrapper.findAll('[data-test="journal-compact-amount"]').map(a => a.text())).toEqual(['100.00 Kč', '50.00 Kč', '120.00 Kč', '30.00 Kč'])
  })
})
