import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive } from 'vue'
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
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { list: m.list, remove: m.remove },
  otherItemKindsApi: { list: m.listKinds },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite, canRead: () => true }),
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
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
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
  useRouter: () => ({ replace: m.replace }),
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

const stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  FilterBar: { template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
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
  return shallowMount(OtherItems, { global: { stubs } })
}

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
    ], sources: [], total: 3, per_page: 50 })
    const wrapper = mountList()
    await flushPromises()

    const badges = wrapper.findAll('[data-test="status-badge"]')
    expect(badges.map(badge => badge.text())).toEqual(['S:paid', 'S:partial', 'S:draft'])
    expect(badges.map(badge => badge.classes().join(' '))).toEqual(['text-xs badge badge-paid', 'text-xs badge badge-partially_paid', 'text-xs badge badge-draft'])
  })

  it('zvýrazní jen otevřenou položku po splatnosti a počet dokumentů skloňuje přes překlad', async () => {
    m.list.mockResolvedValue({ items: [
      item('posted', { id: 'manual:1', due_on: '2000-01-01', document_count: 3 }),
      item('posted', { id: 'manual:2', due_on: '2000-01-01', remaining_amount: 0, paid_amount: 1200 }),
      item('draft', { id: 'manual:3', due_on: '2000-01-01' }),
    ], sources: [], total: 3, per_page: 50 })
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
  })

  it('načte filtry a řazení z URL a změnu filtru do URL zapíše', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = mountList({ side: 'receivable', status: 'all', q: 'nájem', sort: 'amount', dir: 'asc', from: '2099-01-01' })
    await flushPromises()

    expect(m.list).toHaveBeenCalledTimes(1)
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({
      side: 'receivable', status: 'all', q: 'nájem', from: '2099-01-01', sort_by: 'amount', sort_dir: 'asc', page: 1,
    }))
    expect(m.replace).not.toHaveBeenCalled()

    await wrapper.find('[data-test="filter-status"]').setValue('open')
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { side: 'receivable', q: 'nájem', from: '2099-01-01', sort: 'amount', dir: 'asc' } })
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'open' }))
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
    m.route.fullPath = '/other-items?sort=amount&dir=desc'
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
