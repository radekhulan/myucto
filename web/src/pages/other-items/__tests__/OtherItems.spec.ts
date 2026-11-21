import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  listKinds: vi.fn(),
  remove: vi.fn(),
  canWrite: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  replace: vi.fn(),
  navigate: vi.fn(),
  refresh: null as null | (() => Promise<unknown>),
  route: null as any,
  navSnapshot: null as any,
  prefs: null as any,
  canReadAccounting: true,
  canReadOtherItems: true,
  mode: 'double_entry',
  savedFilters: [] as Array<{ id: number; name: string; payload: Record<string, string> }>,
  savedActive: null as number | null,
  defaultQuery: null as Record<string, string> | null,
  applySaved: null as null | ((query: Record<string, string>) => void),
  savedQuery: null as null | (() => Record<string, string>),
  keyboard: null as null | { count: () => number; open: (index: number) => void },
}))

vi.mock('@/api/otherItems', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/otherItems')>()
  // Skutečná bookUrl: test ověřuje, jaký výběr dostane kniha.
  return {
    otherItemsApi: { list: m.list, remove: m.remove, bookUrl: actual.otherItemsApi.bookUrl },
    otherItemKindsApi: { list: m.listKinds },
  }
})
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite, canRead: (module: string) => module === 'other_items' ? m.canReadOtherItems
    : module !== 'accounting' || m.canReadAccounting }),
}))
// Předvolby tabulky (sloupce, pořadí, hustota, řazení) žijí v uživatelských předvolbách jako u faktur.
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => m.prefs,
  patchPagePrefs: (_page: string, patch: Record<string, unknown>) => { m.prefs.value = { ...m.prefs.value, ...patch } },
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (page: string, opts: { getQuery: () => Record<string, string>; applyQuery: (query: Record<string, string>) => void }) => {
    expect(page).toBe('other_items')
    m.applySaved = opts.applyQuery
    m.savedQuery = opts.getQuery
    return {
      activeId: { get value() { return m.savedActive } },
      filters: { get value() { return m.savedFilters } },
      apply: (filter: { payload: Record<string, string> }) => opts.applyQuery({ ...filter.payload }),
      clearActive: () => opts.applyQuery({}),
      applyDefaultIfAny: async () => {
        if (!m.defaultQuery) return false
        opts.applyQuery(m.defaultQuery)
        return true
      },
    }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('@/composables/useListKeyboard', () => ({
  useListKeyboard: (options: { count: () => number; open: (index: number) => void }) => {
    m.keyboard = options
    return { activeIndex: ref(-1) }
  },
}))
vi.mock('@/api/otherItemSettings', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/otherItemSettings')>()),
  otherItemSettingsApi: { get: vi.fn().mockResolvedValue({ document_folder: 'Smlouvy/{YYYY}', document_folder_default: 'Ostatní pohledávky a závazky/{YYYY}/{MM}', is_default: false }), update: vi.fn() },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number, currency?: string) => `${value} ${currency ?? ''}`.trim(),
  statusBadgeClass: (status: string) => `badge badge-${status}`,
}))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => m.navigate }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { accounting_mode: m.mode } }) }))
vi.mock('@/composables/useListNavigation', () => ({
  useListNavigationSource: (_kind: string, snapshot: () => unknown, listPath: () => string) => ({
    markOpen: (id: number) => { m.navSnapshot = { id, snapshot: snapshot(), listPath: listPath() } },
  }),
}))
vi.mock('@/composables/useWindowRefresh', () => ({
  useWindowRefresh: (refresh: () => Promise<unknown>) => { m.refresh = refresh },
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({
    replace: m.replace,
    resolve: ({ path, query }: { path?: string; query: Record<string, string> }) => {
      const search = new URLSearchParams(query).toString()
      return { fullPath: `${path ?? '/other-items'}${search ? `?${search}` : ''}` }
    },
  }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  // Stavy mají překlad (jinak stránka ukáže holý kód), ostatní klíče se vrací beze změny.
  useI18n: () => ({ t: (key: string, count?: unknown) => typeof count === 'number' ? `${key}:${count}`
    : key.startsWith('other_items.status.') ? `S:${key.slice(19)}` : key }),
}))

import OtherItems from '../OtherItems.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PostingBadge from '@/components/ui/PostingBadge.vue'

const stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  FilterBar: { name: 'FilterBar', props: { activeCount: Number, collapsible: Boolean, chips: Array }, template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
  RowActionsMenu: {
    props: ['actions'],
    template: '<div><button v-for="a in actions.filter(x => x.show)" :key="a.key" type="button" @click="a.run && a.run()">{{ a.label }}</button></div>',
  },
}

function item(status: 'draft' | 'posted', changes: Record<string, unknown> = {}) {
  return {
    id: 'manual:42', source_kind: 'manual', source_id: 42, side: 'payable', kind: 'rent',
    title: 'Syntetický závazek', partner_name: 'Syntetická protistrana', due_on: '2099-01-20',
    currency: 'CZK', amount: 1200, paid_amount: 0, remaining_amount: 1200, status, certainty: 'confirmed',
    document_count: 0, source_url: '/other-items/42', ...changes,
  }
}

function mountList(query: Record<string, string> = {}) {
  m.route = reactive({ name: 'other-items', query })
  return shallowMount(OtherItems, { global: { stubs, directives: { 'column-labels': {} } } })
}

// Platí pro všechny bloky: předvolby a uložené pohledy začínají prázdné.
beforeEach(() => {
  m.prefs = ref({})
  m.canReadAccounting = true
  m.canReadOtherItems = true
  m.mode = 'double_entry'
  m.savedFilters = []
  m.savedActive = null
  m.defaultQuery = null
})

describe('OtherItems', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.refresh = null
    m.canWrite.mockReturnValue(true)
    m.remove.mockResolvedValue({})
    m.listKinds.mockResolvedValue([])
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('nabídne úpravu a smazání konceptu v menu řádku a po smazání znovu načte seznam', async () => {
    m.list.mockResolvedValueOnce({ items: [item('draft')], sources: [], total: 1, per_page: 50 })
    m.list.mockResolvedValueOnce({ items: [], sources: [], total: 0, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.text()).toContain('common.edit')
    const remove = wrapper.findAll('button').find(button => button.text().includes('common.delete'))
    await remove!.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith('other_items.confirm_delete')
    expect(m.remove).toHaveBeenCalledWith(42)
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('smazání konceptu odmítnuté kvůli období ohlásí přeloženou hláškou', async () => {
    m.list.mockResolvedValue({ items: [item('draft')], sources: [], total: 1, per_page: 50 })
    m.remove.mockRejectedValue({ response: { data: { error: { code: 'other_items.error.accrual_deferral_posted', message: 'Serverová hláška' } } } })
    const wrapper = mountList()
    await flushPromises()
    const remove = wrapper.findAll('button').find(button => button.text().includes('common.delete'))
    await remove!.trigger('click')
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('other_items.accrual.error.accrual_deferral_posted')
  })

  it('u zaúčtované položky nenabídne úpravu ani smazání a detail otevře klikem na řádek', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.text()).not.toContain('common.edit')
    expect(wrapper.text()).not.toContain('common.delete')
    await wrapper.find('[data-test="other-item-row"]').trigger('click')
    expect(m.navigate).toHaveBeenCalledWith({ name: 'other-item-detail', params: { id: 42 } }, expect.anything())
  })

  it('stav ukáže sdíleným odznakem a uhrazení přebije zaúčtování', async () => {
    m.list.mockResolvedValue({ items: [
      item('posted', { id: 'manual:1', remaining_amount: 0, paid_amount: 1200 }),
      item('posted', { id: 'manual:2', remaining_amount: 200, paid_amount: 1000 }),
      item('draft', { id: 'manual:3' }),
      item('posted', { id: 'manual:4', remaining_amount: -50, paid_amount: 1250 }),
      // Jistina vyrovnaná, úrok splátky zbývá: stav podle zbývající platby, stejně jako detail.
      item('posted', { id: 'manual:5', remaining_amount: 0, paid_amount: 1200, remaining_payment: 40, has_split_installments: true }),
    ], sources: [], total: 5, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()

    const badges = wrapper.findAll('[data-test="status-badge"]')
    expect(badges.map(badge => badge.text())).toEqual(['S:paid', 'S:partial', 'S:draft', 'S:overpaid', 'S:partial'])
    expect(badges.map(badge => badge.classes().join(' '))).toEqual(['rounded px-2 py-0.5 text-xs badge badge-paid',
      'rounded px-2 py-0.5 text-xs badge badge-partially_paid', 'rounded px-2 py-0.5 text-xs badge badge-draft',
      'rounded px-2 py-0.5 text-xs badge badge-overpaid', 'rounded px-2 py-0.5 text-xs badge badge-partially_paid'])
    // Sloupec zbývá ukazuje totéž, podle čeho se řídí stav: zbývající platbu včetně úroku splátek.
    m.prefs.value = { shown: ['remaining_amount'] }
    const withColumn = mountList()
    await flushPromises()
    expect(withColumn.findAll('[data-test="remaining-cell"]').map(cell => cell.text())[4]).toBe('40 CZK')
  })

  it('zvýrazní jen otevřenou položku po splatnosti a počet dokumentů skloňuje přes překlad', async () => {
    m.list.mockResolvedValue({ items: [
      item('posted', { id: 'manual:1', due_on: '2000-01-01', document_count: 3 }),
      item('posted', { id: 'manual:2', due_on: '2000-01-01', remaining_amount: 0, paid_amount: 1200 }),
      item('draft', { id: 'manual:3', due_on: '2000-01-01' }),
    ], sources: [], total: 3, per_page: 50 })
    m.prefs.value = { shown: ['documents'] }
    const wrapper = mountList()
    await flushPromises()

    const rows = wrapper.findAll('[data-test="other-item-row"]')
    expect(rows.map(row => row.classes().includes('row-flagged'))).toEqual([true, false, false])
    expect(wrapper.find('[data-test="document-count"]').text()).toContain('other_items.document_count:3')
    expect(wrapper.find('thead').text()).not.toContain('other_items.source_label')
  })

  it('ukáže součty zbývající částky po stranách a měnách', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50, totals: [
      { side: 'receivable', currency: 'CZK', remaining_amount: 1100 },
      { side: 'receivable', currency: 'EUR', remaining_amount: 200 },
      { side: 'payable', currency: 'CZK', remaining_amount: 700 },
    ] })
    const wrapper = mountList()
    await flushPromises()

    const totals = wrapper.find('[data-test="other-items-totals"]').text()
    expect(totals).toContain('other_items.totals_by_side.receivable:')
    expect(totals).toContain('1100 CZK')
    expect(totals).toContain('200 EUR')
    expect(totals).toContain('other_items.totals_by_side.payable:')
    expect(totals).toContain('700 CZK')
    // Výchozí okno splatnosti je vidět jako věta u filtrů; se zadaným datem nebo hledáním zmizí.
    expect(wrapper.get('[data-test="due-window-hint"]').text()).toBe('other_items.window_hint_open')
    const all = mountList({ status: 'all' })
    await flushPromises()
    expect(all.get('[data-test="due-window-hint"]').text()).toBe('other_items.window_hint')
    for (const query of [{ from: '2099-01-01' }, { to: '2099-12-31' }, { q: 'nájem' }] as Record<string, string>[]) {
      const narrowed = mountList(query)
      await flushPromises()
      expect(narrowed.find('[data-test="due-window-hint"]').exists()).toBe(false)
    }
    // Že koncepty nejsou v součtu, říká popisek přímo u něj, ne jen tooltip.
    expect(wrapper.get('[data-test="other-items-totals-scope"]').text()).toBe('other_items.totals_scope')
  })

  it('neplatné datum, obrácené rozmezí a nulový druh z adresy vynechá, místo aby server vrátil 422', async () => {
    m.list.mockResolvedValue({ items: [], sources: [], total: 0, per_page: 50 })
    mountList({ from: '2099-02-31', to: '2099-13-01', kind: 'receivable|custom:0' })
    await flushPromises()
    m.list.mockClear()
    mountList({ kind: 'receivable|custom:99999999999999999999' })
    await flushPromises()
    const params = m.list.mock.calls.at(-1)![0]
    expect(params).not.toHaveProperty('from')
    expect(params).not.toHaveProperty('to')
    expect(params).not.toHaveProperty('kind_id')
    expect(params).not.toHaveProperty('kind')
    m.list.mockClear()
    mountList({ from: '2099-03-01', to: '2099-01-01' })
    await flushPromises()
    expect(m.list.mock.calls.at(-1)![0]).not.toHaveProperty('from')
    expect(m.list.mock.calls.at(-1)![0]).not.toHaveProperty('to')
    m.list.mockClear()
    mountList({ from: '2099-02-28', to: '2099-03-01' })
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2099-02-28', to: '2099-03-01' }))
  })

  it('Zrušit vše s vyplněným hledáním načte seznam jen jednou', async () => {
    vi.useFakeTimers()
    try {
      m.list.mockResolvedValue({ items: [], sources: [], total: 0, per_page: 50 })
      const wrapper = mountList({ side: 'receivable', q: 'nájem', from: '2099-01-01' })
      await flushPromises()
      m.list.mockClear()
      wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('clear-all')
      await flushPromises()
      vi.advanceTimersByTime(1000)
      await flushPromises()
      expect(m.list).toHaveBeenCalledTimes(1)
      const params = m.list.mock.calls[0][0]
      expect(params).not.toHaveProperty('q')
      expect(params).not.toHaveProperty('side')
      expect(m.replace).toHaveBeenLastCalledWith({ query: {} })
    } finally {
      vi.useRealTimers()
    }
  })

  it('načte filtry z URL a řazení z předvoleb, změnu filtru zapíše do URL bez řazení', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.prefs.value = { sort: { key: 'amount', dir: 'asc' } }
    const wrapper = mountList({ side: 'receivable', status: 'all', q: 'nájem', from: '2099-01-01' })
    await flushPromises()

    expect(m.list).toHaveBeenCalledTimes(1)
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({
      side: 'receivable', status: 'all', q: 'nájem', from: '2099-01-01', sort_by: 'amount', sort_dir: 'asc', page: 1,
    }))
    expect(m.replace).not.toHaveBeenCalled()

    await wrapper.find('[data-test="filter-status"]').setValue('open')
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { side: 'receivable', q: 'nájem', from: '2099-01-01' } })
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'open', sort_by: 'amount' }))
  })

  it('prázdné query (klik v menu) vrátí výchozí filtry', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList({ side: 'payable', status: 'all' })
    await flushPromises()

    m.route.query = {}
    await flushPromises()

    expect(m.list).toHaveBeenLastCalledWith(expect.not.objectContaining({ side: expect.anything() }))
    expect(m.list.mock.lastCall![0].status).toBe('open')
    expect((wrapper.find('[data-test="filter-side"]').element as HTMLSelectElement).value).toBe('')
  })

  it('chyba načtení nesmaže zobrazená data a první neúspěch ukáže stav chyby', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.list.mockRejectedValueOnce(new Error('500'))
    const wrapper = mountList()
    await flushPromises()
    wrapper.findAllComponents(SortableTh)[0]!.vm.$emit('toggle', 'document_no')
    await flushPromises()

    expect(wrapper.text()).toContain('Syntetický závazek')
    expect(wrapper.find('[role="alert"]').text()).toContain('other_items.load_failed_stale')

    m.list.mockRejectedValueOnce(new Error('500'))
    const fresh = mountList()
    await flushPromises()
    expect(fresh.findComponent(EmptyState).props('variant')).toBe('failed')
  })

  it('tichá obnova okna znovu načte všechny zobrazené stránky', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    m.list.mockResolvedValueOnce({ items: [item('posted', { id: 'manual:43', title: 'Druhá položka' })], sources: [], total: 2, per_page: 1 })
    const wrapper = mountList()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
    await flushPromises()

    m.list.mockResolvedValueOnce({ items: [item('posted', { title: 'Obnovená první' })], sources: [], total: 2, per_page: 1 })
    m.list.mockResolvedValueOnce({ items: [item('posted', { id: 'manual:43', title: 'Obnovená druhá' })], sources: [], total: 2, per_page: 1 })
    await m.refresh!()
    await flushPromises()

    expect(m.list.mock.calls.slice(-2).map(call => [call[0].page, call[0].per_page])).toEqual([[1, 1], [2, 1]])
    expect(wrapper.text()).toContain('Obnovená první')
    expect(wrapper.text()).toContain('Obnovená druhá')
  })

  it('načítá další stránku bez ztráty prvních položek a řadí celý seznam přes API', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    m.list.mockResolvedValueOnce({ items: [{ ...item('posted'), id: 'manual:43', source_id: 43, title: 'Druhá položka' }], sources: [], total: 2, per_page: 1 })
    const wrapper = mountList()
    await flushPromises()
    const more = wrapper.findAll('button').find(button => button.text().includes('common.load_more'))
    await more!.trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(wrapper.text()).toContain('Syntetický závazek')
    expect(wrapper.text()).toContain('Druhá položka')
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    wrapper.findAllComponents(SortableTh).find(th => th.props('sortKey') === 'amount')!.vm.$emit('toggle', 'amount')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, sort_by: 'amount', sort_dir: 'desc' }))
    expect(wrapper.text()).not.toContain('Druhá položka')
    wrapper.unmount()
  })

  it('po změně řazení zahodí opožděnou odpověď načítání další stránky', async () => {
    let resolveMore!: (value: any) => void
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    m.list.mockImplementationOnce(() => new Promise(resolve => { resolveMore = resolve }))
    const wrapper = mountList()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
    m.list.mockResolvedValueOnce({ items: [{ ...item('posted'), title: 'Nové pořadí' }], sources: [], total: 1, per_page: 50 })
    wrapper.findAllComponents(SortableTh)[0]!.vm.$emit('toggle', 'title')
    await flushPromises()
    resolveMore({ items: [{ ...item('posted'), title: 'Zastaralá odpověď' }], sources: [], total: 2, per_page: 1 })
    await flushPromises()
    expect(wrapper.text()).toContain('Nové pořadí')
    expect(wrapper.text()).not.toContain('Zastaralá odpověď')
    wrapper.unmount()
  })

  it('ukáže číslo dokladu a filtruje druh i se stranou, vestavěný přes kind, vlastní přes kind_id', async () => {
    m.listKinds.mockResolvedValue([
      { id: 5, side: 'payable', builtin: false, builtin_code: null, name: 'Syntetické nájemné', own_series: true, is_active: true },
      { id: 6, side: 'receivable', builtin: false, builtin_code: null, name: 'Syntetický nárok', own_series: false, is_active: false },
    ])
    m.list.mockResolvedValue({ items: [{ ...item('posted'), document_no: 'NJ-2099-0001' }], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.text()).toContain('NJ-2099-0001')

    // Bez směru nabídne druhy obou stran, každou ve své skupině (skrytý druh také).
    const kind = wrapper.find('[data-test="filter-kind"]')
    const groups = kind.findAll('optgroup')
    expect(groups.map(group => group.attributes('label'))).toEqual(['other_items.side.payable', 'other_items.side.receivable'])
    expect(groups[0]!.text()).toContain('other_items.kind_by_side.payable.rent')
    expect(groups[0]!.text()).toContain('Syntetické nájemné')
    expect(groups[1]!.text()).toContain('other_items.kind_by_side.receivable.rent')
    expect(groups[1]!.text()).toContain('Syntetický nárok')

    await kind.setValue('receivable|rent')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ side: 'receivable', kind: 'rent' }))
    await kind.setValue('payable|custom:5')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ side: 'payable', kind_id: 5 }))
    expect(m.list.mock.lastCall![0]).not.toHaveProperty('kind')

    // Po přepnutí na pohledávky druh závazků z filtru vypadne a zůstane jen skupina pohledávek.
    await wrapper.find('[data-test="filter-side"]').setValue('receivable')
    await flushPromises()
    expect((kind.element as HTMLSelectElement).value).toBe('')
    expect(m.list.mock.lastCall![0]).not.toHaveProperty('kind_id')
    expect(kind.findAll('optgroup').map(group => group.attributes('label'))).toEqual(['other_items.side.receivable'])
  })
})

describe('OtherItems obnova a URL', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.refresh = null
    m.canWrite.mockReturnValue(true)
    m.listKinds.mockResolvedValue([])
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
  })

  it('obnova během běžícího načtení nic nezasekne a další stránka jde dočíst', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    const wrapper = mountList({ side: 'payable' })
    await flushPromises()

    let finish!: (value: unknown) => void
    m.list.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.route.query = {}
    await flushPromises()
    await m.refresh!()
    finish({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    await flushPromises()

    m.list.mockResolvedValueOnce({ items: [item('posted', { id: 'manual:43', title: 'Druhá položka' })], sources: [], total: 2, per_page: 1 })
    await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(wrapper.text()).toContain('Druhá položka')
  })

  it('tichá obnova načte nejvýš pět stránek', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 9, per_page: 1 })
    const wrapper = mountList()
    await flushPromises()
    for (let i = 0; i < 6; i++) {
      await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
      await flushPromises()
    }
    m.list.mockClear()
    await m.refresh!()
    await flushPromises()
    expect(m.list.mock.calls.map(call => call[0].page)).toEqual([1, 2, 3, 4, 5])
  })

  it('druh druhé strany z ručně upraveného odkazu nepoužije', async () => {
    m.list.mockResolvedValue({ items: [], sources: [], total: 0, per_page: 50 })
    mountList({ side: 'payable', kind: 'receivable|rent' })
    await flushPromises()
    expect(m.list).toHaveBeenCalledTimes(1)
    expect(m.list.mock.lastCall![0]).toEqual(expect.objectContaining({ side: 'payable' }))
    expect(m.list.mock.lastCall![0]).not.toHaveProperty('kind')
  })

  it('dopsaný znak hledání nepřepíše opožděné potvrzení vlastní změny URL', async () => {
    vi.useFakeTimers()
    let applyReplace!: () => void
    // Jako skutečný router: promise navigace se vyřeší až po změně aktuální route.
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => new Promise(resolve => {
      applyReplace = () => { m.route.query = query; resolve(undefined) }
    }))
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    const input = wrapper.get('[data-test="other-items-search"]')
    await input.setValue('ab')
    vi.advanceTimersByTime(300)
    await flushPromises()
    await input.setValue('abc')
    applyReplace()
    await flushPromises()
    expect((input.element as HTMLInputElement).value).toBe('abc')
    vi.useRealTimers()
  })

  it('po zavření druhů zmizí kinds=1 z adresy', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList({ kinds: '1' })
    await flushPromises()
    wrapper.findComponent({ name: 'OtherItemKindsModal' }).vm.$emit('close')
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: {} })
  })

  it('filtr zdroje mzdy skryje ruční sekci i součty a nevolá API', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [
      { ...item('posted'), id: 'payroll:1', source_kind: 'payroll', title: 'Mzdový závazek', status: 'open' },
    ], total: 1, per_page: 50, totals: [{ side: 'payable', currency: 'CZK', remaining_amount: 1200 }] })
    const wrapper = mountList()
    await flushPromises()
    m.list.mockClear()
    await wrapper.get('[data-test="filter-source"]').setValue('payroll')
    await flushPromises()
    expect(m.list).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="other-items-totals"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="other-item-row"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Mzdový závazek')
  })
})

describe('OtherItems URL tam a zpět', () => {
  it('změna filtru a návrat před dokončením zápisu URL nechá adresu podle filtrů', async () => {
    vi.clearAllMocks()
    m.listKinds.mockResolvedValue([])
    m.canWrite.mockReturnValue(true)
    const pending: Array<() => void> = []
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => new Promise(resolve => {
      pending.push(() => { m.route.query = query; resolve(undefined) })
    }))
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    const sideSelect = wrapper.get('[data-test="filter-side"]')
    await sideSelect.setValue('payable')
    await flushPromises()
    await sideSelect.setValue('')
    await flushPromises()
    for (const apply of pending.splice(0)) { apply(); await flushPromises() }
    expect(m.route.query).toEqual({})
    expect((sideSelect.element as HTMLSelectElement).value).toBe('')
  })
})

describe('OtherItems zrušená navigace', () => {
  it('zrušený zápis URL nezablokuje pozdější navigaci zvenku na stejnou adresu', async () => {
    vi.clearAllMocks()
    m.listKinds.mockResolvedValue([])
    m.canWrite.mockReturnValue(true)
    // Navigace se zruší (další rychlá změna filtru, guard): promise se vyřeší, URL zůstane.
    m.replace.mockImplementation(() => Promise.resolve(undefined))
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-test="filter-side"]').setValue('payable')
    await flushPromises()
    m.list.mockClear()

    // Zpět v historii na adresu, kterou stránka dřív chtěla zapsat, ale navigace se zrušila.
    m.route.query = { side: 'receivable' }
    await flushPromises()
    m.route.query = { side: 'payable' }
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ side: 'payable' }))
    expect((wrapper.get('[data-test="filter-side"]').element as HTMLSelectElement).value).toBe('payable')
  })
})

describe('OtherItems návrat z detailu', () => {
  it('při otevření detailu si zapamatuje výběr i adresu s filtry', async () => {
    vi.clearAllMocks()
    m.listKinds.mockResolvedValue([])
    m.canWrite.mockReturnValue(true)
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
    m.list.mockResolvedValue({ items: [item('posted'), item('posted', { id: 'manual:43', source_id: 43 })], sources: [], total: 2, per_page: 50 })
    m.route = reactive({ name: 'other-items', query: { side: 'payable', status: 'all' }, fullPath: '/other-items?side=payable&status=all' })
    const wrapper = shallowMount(OtherItems, { global: { stubs } })
    await flushPromises()
    await wrapper.findAll('[data-test="other-item-row"]')[1]!.trigger('click')
    expect(m.navSnapshot.id).toBe(43)
    expect(m.navSnapshot.listPath).toBe('/other-items?side=payable&status=all')
    expect(m.navSnapshot.snapshot.ids).toEqual([42, 43])
    expect(m.navSnapshot.snapshot.params).toEqual(expect.objectContaining({ side: 'payable', status: 'all' }))
    expect(m.navSnapshot.snapshot.params).not.toHaveProperty('page')
  })
})

describe('OtherItems výběr pro listování', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listKinds.mockResolvedValue([])
    m.canWrite.mockReturnValue(true)
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
  })

  it('zapamatuje parametry zobrazených řádků, ne právě načítaného řazení', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted'), item('posted', { id: 'manual:43', source_id: 43 })], sources: [], total: 2, per_page: 50 })
    m.route = reactive({ name: 'other-items', query: {}, fullPath: '/other-items' })
    const wrapper = shallowMount(OtherItems, { global: { stubs } })
    await flushPromises()
    m.list.mockImplementationOnce(() => new Promise(() => undefined))
    wrapper.findAllComponents(SortableTh).find(th => th.props('sortKey') === 'amount')!.vm.$emit('toggle', 'amount')
    await flushPromises()
    m.route.fullPath = '/other-items?side=payable'
    await wrapper.findAll('[data-test="other-item-row"]')[0]!.trigger('click')
    expect(m.navSnapshot.snapshot.params).not.toHaveProperty('sort_by')
    expect(m.navSnapshot.listPath).toBe('/other-items')
  })

  it('zapamatuje výběr i při otevření z mobilní karty prostředním tlačítkem', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    m.navSnapshot = null
    await wrapper.get('[data-test="other-item-card-link"]').trigger('auxclick')
    expect(m.navSnapshot.id).toBe(42)
  })
})

describe('OtherItems prvky seznamu jako přijaté faktury', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.listKinds.mockResolvedValue([])
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
  })

  it('filtry jsou sbalené jako u přijatých faktur a lišta nabízí uložené pohledy, sloupce a hustotu', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.findComponent({ name: 'FilterBar' }).props('collapsible')).toBe(true)
    expect(wrapper.findComponent({ name: 'SavedFiltersMenu' }).exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'ColumnPicker' }).props('reorderable')).not.toBe(false)
    expect(wrapper.findComponent({ name: 'DensityToggle' }).exists()).toBe(true)
    expect(wrapper.find('[data-test="other-items-summary"]').text()).toContain('other_items.summary_count:1')
  })

  it('sloupce řídí předvolby: skryté se nekreslí, zapnuté a přeřazené ano', async () => {
    m.list.mockResolvedValue({ items: [item('posted', { variable_symbol: '20990001' })], sources: [], total: 1, per_page: 50 })
    m.prefs.value = { hidden: ['partner_name'], shown: ['variable_symbol'], column_order: ['variable_symbol', 'document_no'] }
    const wrapper = mountList()
    await flushPromises()

    const row = wrapper.get('[data-test="other-item-row"]')
    expect(row.text()).toContain('20990001')
    expect(row.text()).not.toContain('Syntetická protistrana')
    const headers = wrapper.findAll('thead th, thead sortable-th-stub').map(th => th.attributes('label') ?? th.text())
    expect(headers.findIndex(text => text.includes('other_items.variable_symbol')))
      .toBeLessThan(headers.findIndex(text => text.includes('other_items.document_no')))
  })

  it('zrušení řazení vrátí výchozí pořadí a znovu načte seznam', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.prefs.value = { sort: { key: 'amount', dir: 'desc' } }
    const wrapper = mountList()
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ sort_by: 'amount' }))

    await wrapper.get('[data-test="reset-sort"]').trigger('click')
    await flushPromises()
    expect(m.prefs.value.sort).toBeNull()
    expect(m.list).toHaveBeenLastCalledWith(expect.not.objectContaining({ sort_by: expect.anything() }))
    expect(wrapper.find('[data-test="reset-sort"]').exists()).toBe(false)
  })

  it('zaúčtovanou položku označí odkazem na zápis, jen s právem číst účetnictví', async () => {
    m.list.mockResolvedValue({ items: [
      item('posted', { id: 'manual:1', journal_entry_id: 77, posted_at: '2099-01-02 10:00:00' }),
      item('draft', { id: 'manual:2' }),
    ], sources: [], total: 2, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    const badges = wrapper.findAll('[data-test="other-item-row"]').flatMap(row => row.findAllComponents(PostingBadge))
    expect(badges).toHaveLength(1)
    expect(badges[0]!.props()).toMatchObject({ journalEntryId: 77, bookedAt: '2099-01-02 10:00:00' })

    m.canReadAccounting = false
    const restricted = mountList()
    await flushPromises()
    expect(restricted.findComponent(PostingBadge).exists()).toBe(false)
  })

  it('řádky i karty jdou přetáhnout do panelu', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.get('[data-test="other-item-row"]').attributes('data-workspace-route')).toBe('/other-items/42')
    expect(wrapper.get('[data-test="other-item-row"]').attributes('draggable')).toBe('true')
    expect(wrapper.get('[data-test="other-item-card"]').attributes('data-workspace-route')).toBe('/other-items/42')
  })

  it('klávesnice otevře řádek a zapamatuje výběr pro šipky v detailu', async () => {
    m.list.mockResolvedValue({ items: [item('posted'), item('posted', { id: 'manual:43', source_id: 43 })], sources: [], total: 2, per_page: 50 })
    mountList()
    await flushPromises()
    expect(m.keyboard!.count()).toBe(2)
    m.keyboard!.open(1)
    expect(m.navigate).toHaveBeenLastCalledWith({ name: 'other-item-detail', params: { id: 43 } }, undefined)
    expect(m.navSnapshot.id).toBe(43)
  })

  it('bez query použije výchozí uložený pohled a načte seznam jen jednou', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.defaultQuery = { side: 'payable', status: 'all' }
    const wrapper = mountList()
    await flushPromises()
    expect(m.list).toHaveBeenCalledTimes(1)
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ side: 'payable', status: 'all' }))
    expect(m.replace).toHaveBeenLastCalledWith({ query: { side: 'payable', status: 'all' } })
    expect((wrapper.get('[data-test="filter-side"]').element as HTMLSelectElement).value).toBe('payable')
  })

  it('řádek pohledů přepne uložený pohled a druhým klikem ho zruší', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.savedFilters = [{ id: 5, name: 'Závazky', payload: { side: 'payable' } }]
    const wrapper = mountList()
    await flushPromises()
    const tabs = wrapper.findAll('[data-test="saved-views"] [role="tab"]')
    expect(tabs.map(tab => tab.text())).toEqual(['common.saved_view_all', 'Závazky'])

    await tabs[1]!.trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ side: 'payable' }))
    expect(m.route.query).toEqual({ side: 'payable' })

    m.savedActive = 5
    await wrapper.findAll('[data-test="saved-views"] [role="tab"]')[1]!.trigger('click')
    await flushPromises()
    expect(m.list.mock.lastCall![0]).not.toHaveProperty('side')
    expect(m.route.query).toEqual({})
  })

  it('uložený pohled nese jen filtry, ne otevřený číselník druhů', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    mountList({ side: 'payable', kinds: '1' })
    await flushPromises()
    expect(m.savedQuery!()).toEqual({ side: 'payable' })
  })

  it('sloupec Zápis jen v podvojném účetnictví s právem číst účetnictví', async () => {
    m.list.mockResolvedValue({ items: [item('posted', { journal_entry_id: 77, account_code: '325' })], sources: [], total: 1, per_page: 50 })
    const full = mountList()
    await flushPromises()
    expect(full.find('thead').text()).toContain('other_items.col_posting')
    expect(full.findAllComponents(PostingBadge)).toHaveLength(2)

    m.canReadAccounting = false
    const noRight = mountList()
    await flushPromises()
    expect(noRight.find('thead').text()).not.toContain('other_items.col_posting')
    expect(noRight.findComponent(PostingBadge).exists()).toBe(false)

    m.canReadAccounting = true
    m.mode = 'tax_evidence'
    const taxEvidence = mountList()
    await flushPromises()
    expect(taxEvidence.find('thead').text()).not.toContain('other_items.col_posting')
    expect(taxEvidence.findComponent(PostingBadge).exists()).toBe(false)
  })

  it('štítek Zaúčtováno nemá stornovaná položka a vlastní druh ukáže i směr', async () => {
    m.list.mockResolvedValue({ items: [
      item('reversed' as 'posted', { id: 'manual:1', journal_entry_id: 77 }),
      item('posted', { id: 'manual:2', journal_entry_id: 78, posted_at: null, accounting_on: '2099-01-05', kind: 'other', kind_id: 9, kind_name: 'Vlastní druh' }),
    ], sources: [], total: 2, per_page: 50 })
    const wrapper = mountList({ status: 'all' })
    await flushPromises()
    const rows = wrapper.findAll('[data-test="other-item-row"]')
    expect(rows[0]!.findComponent(PostingBadge).exists()).toBe(false)
    expect(rows[1]!.findComponent(PostingBadge).props('bookedAt')).toBe('2099-01-05')
    expect(rows[1]!.get('[data-test="kind-cell"]').text()).toBe('other_items.side.payable · Vlastní druh')
    expect(rows[0]!.get('[data-test="kind-cell"]').text()).toBe('other_items.kind_by_side.payable.rent')
    expect(wrapper.findAll('[data-test="card-kind"]')[1]!.text()).toBe('other_items.side.payable · Vlastní druh')
  })

  it('podle stavu neřadí, protože výpis ukazuje odvozený stav', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.findAllComponents(SortableTh).map(th => th.props('sortKey'))).not.toContain('status')
  })

  it('počet ručních položek se při filtru zdroje mzdy nebo daně neukazuje', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 12, per_page: 50 })
    const wrapper = mountList({ source: 'payroll' })
    await flushPromises()
    expect(wrapper.find('[data-test="other-items-summary"]').text()).not.toContain('other_items.summary_count')
  })

  it('ťuknutí na kartu kamkoli otevře detail a zaúčtovaná karta nese štítek', async () => {
    m.list.mockResolvedValue({ items: [item('posted', { journal_entry_id: 77 })], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    const card = wrapper.get('[data-test="other-item-card"]')
    expect(card.findComponent(PostingBadge).exists()).toBe(true)
    await card.trigger('click')
    expect(m.navigate).toHaveBeenLastCalledWith({ name: 'other-item-detail', params: { id: 42 } }, expect.anything())
    expect(m.navSnapshot.id).toBe(42)
  })
})

describe('OtherItems adresa pro návrat z detailu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.listKinds.mockResolvedValue([])
    // Skutečný router zapíše URL až později; detail se otevře dřív.
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => Promise.resolve().then(() => {
      m.route.query = query
      const search = new URLSearchParams(query).toString()
      m.route.fullPath = `/other-items${search ? `?${search}` : ''}`
    }))
  })

  it('po změně filtru si zapamatuje adresu nového výběru, ne té před zápisem URL', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.route = reactive({ name: 'other-items', path: '/other-items', query: {}, fullPath: '/other-items' })
    const wrapper = shallowMount(OtherItems, { global: { stubs, directives: { 'column-labels': {} } } })
    await flushPromises()
    await wrapper.get('[data-test="filter-side"]').setValue('payable')
    await flushPromises()
    await wrapper.get('[data-test="other-item-row"]').trigger('click')
    expect(m.navSnapshot.listPath).toBe('/other-items?side=payable')
  })

  it('uložený pohled i otevřený číselník druhů: návrat vede na filtry pohledu bez druhů', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.savedFilters = [{ id: 5, name: 'Závazky', payload: { side: 'payable' } }]
    m.route = reactive({ name: 'other-items', path: '/other-items', query: { kinds: '1' }, fullPath: '/other-items?kinds=1' })
    const wrapper = shallowMount(OtherItems, { global: { stubs, directives: { 'column-labels': {} } } })
    await flushPromises()
    await wrapper.findAll('[data-test="saved-views"] [role="tab"]')[1]!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="other-item-row"]').trigger('click')
    expect(m.navSnapshot.listPath).toBe('/other-items?side=payable')
  })

  it('obnova okna po neúspěšném načtení převezme nový výběr pro šipky v detailu', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.route = reactive({ name: 'other-items', path: '/other-items', query: {}, fullPath: '/other-items' })
    const wrapper = shallowMount(OtherItems, { global: { stubs, directives: { 'column-labels': {} } } })
    await flushPromises()
    m.list.mockRejectedValueOnce(new Error('500'))
    await wrapper.get('[data-test="filter-side"]').setValue('payable')
    await flushPromises()
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    await m.refresh!()
    await flushPromises()
    await wrapper.get('[data-test="other-item-row"]').trigger('click')
    expect(m.navSnapshot.snapshot.params).toEqual(expect.objectContaining({ side: 'payable' }))
    expect(m.navSnapshot.listPath).toBe('/other-items?side=payable')
  })

  it('záhlaví a filtry jako přijaté faktury: primární akce za lištou akcí, filtry sbalitelné, věta o okně mimo ně', async () => {
    const wrapper = mountList()
    await flushPromises()
    const bar = wrapper.findComponent({ name: 'ActionBar' })
    expect((bar.props('actions') as Array<{ key: string }>).map(action => action.key)).not.toContain('new')
    const primary = wrapper.get('[data-test="other-items-new"]')
    expect(primary.text()).toContain('other_items.new')
    expect(bar.element.compareDocumentPosition(primary.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    const filters = wrapper.findComponent({ name: 'FilterBar' })
    expect(filters.props('collapsible')).toBe(true)
    // Věta o výchozím okně splatnosti vysvětluje, proč ve výpisu chybí starší položky: nesmí se sbalit s filtry.
    expect(filters.find('[data-test="due-window-hint"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="due-window-hint"]').exists()).toBe(true)
  })
  it('kniha je v záhlaví před lištou akcí a primárním tlačítkem a nese výběr i řazení výpisu bez stránkování', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    m.prefs.value = { sort: { key: 'amount', dir: 'asc' } }
    const wrapper = mountList({ status: 'all', kind: 'payable|custom:5', q: 'nájem', from: '2099-01-01', to: '2099-12-31' })
    await flushPromises()

    const book = wrapper.findComponent({ name: 'InvoiceBookMenu' })
    expect(book.exists()).toBe(true)
    expect(book.props('label')).toBe('other_items.book')
    expect(book.props('fallbackFilename')).toBe('kniha-ostatnich-pohledavek-a-zavazku')
    expect(book.props('failedMessage')).toBe('other_items.book_failed')
    // Kniha nemá předpisy z mezd a daní: nabídka to musí říct, ne slibovat vše podle filtru jako u faktur.
    expect(book.props('scopeText')).toBe('other_items.book_scope')
    expect(book.props('hint')).toBe('other_items.book_hint')
    const bar = wrapper.findComponent({ name: 'ActionBar' })
    expect(book.element.compareDocumentPosition(bar.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(book.element.compareDocumentPosition(wrapper.get('[data-test="other-items-new"]').element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    const url = new URL((book.props('urlFor') as (format: 'pdf' | 'xlsx') => string)('xlsx'), 'http://localhost')
    expect(url.pathname).toBe('/api/accounting/other-items/book')
    expect(Object.fromEntries(url.searchParams)).toEqual({
      format: 'xlsx', side: 'payable', status: 'all', kind_id: '5', q: 'nájem', from: '2099-01-01', to: '2099-12-31',
      sort_by: 'amount', sort_dir: 'asc',
    })
    // Kniha jde z aktuálního filtru: po změně směru a stavu nese nový výběr.
    await wrapper.find('[data-test="filter-status"]').setValue('open')
    await flushPromises()
    const open = new URL((book.props('urlFor') as (format: 'pdf' | 'xlsx') => string)('pdf'), 'http://localhost')
    expect(open.searchParams.get('status')).toBe('open')
    expect(open.searchParams.get('format')).toBe('pdf')
  })

  it('výchozí výpis pošle knize stav Otevřené bez data a bez řazení (server: okno splatnosti, řazení podle čísla)', async () => {
    m.list.mockResolvedValue({ items: [], sources: [], total: 0, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()
    const urlFor = wrapper.findComponent({ name: 'InvoiceBookMenu' }).props('urlFor') as (format: 'pdf' | 'xlsx') => string
    expect(urlFor('pdf')).toBe('/api/accounting/other-items/book?format=pdf&status=open')
  })

  it.each(['payroll', 'tax'])('kniha ručních položek chybí při zdroji %s', async (source) => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList({ source })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'InvoiceBookMenu' }).exists()).toBe(false)
  })

  it('kniha je i při zdroji Ručně a chybí bez práva číst ostatní položky', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    expect(mountList({ source: 'manual' }).findComponent({ name: 'InvoiceBookMenu' }).exists()).toBe(true)
    m.canReadOtherItems = false
    expect(mountList().findComponent({ name: 'InvoiceBookMenu' }).exists()).toBe(false)
  })
})
