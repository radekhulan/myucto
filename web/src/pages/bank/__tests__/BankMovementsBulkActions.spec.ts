import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

// Seznam bankovních pohybů: výběr řádků a „…" → Hromadné akce (oprava VS) jen tehdy,
// když server operaci set_variable_symbol nabízí.

const m = vi.hoisted(() => ({
  listMovements: vi.fn(), listUnposted: vi.fn(), suggestions: vi.fn(), status: vi.fn(),
  perms: ['accounting.bulk_changes'] as string[],
  mode: 'double_entry',
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  useRoute: () => ({ query: {} }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    hasCommercialFeatures: true, isClientRole: false, isCompanyAdminRole: false, isDemo: false,
    canRead: () => true, canWrite: (k: string) => m.perms.includes(k),
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 7, currentSupplier: { id: 7, accounting_mode: m.mode } }),
}))
vi.mock('@/api/bankPosting', () => ({
  bankPostingApi: { listMovements: m.listMovements, listUnposted: m.listUnposted, unpostedCount: vi.fn() },
}))
vi.mock('@/api/bank', () => ({ BANK_BATCH_MATCH_LIMIT: 100, bankApi: { matchSuggestions: m.suggestions } }))
vi.mock('@/api/bulkActions', () => ({ bulkActionsApi: { status: m.status } }))
vi.mock('@/composables/useBankTransactionActions', () => ({
  useBankTransactionActions: () => ({ setSuggestions: vi.fn() }),
}))
vi.mock('@/composables/useFillViewportHeight', () => ({ useFillViewportHeight: () => undefined }))
vi.mock('@/composables/useScrollLoadMore', () => ({ useScrollLoadMore: () => undefined }))

import UnpostedTransactions from '../UnpostedTransactions.vue'

// Řádek jako BankTransactionRow: se `selectable` dá zaškrtávátko do první buňky.
const Row = defineComponent({
  name: 'BankTransactionRow',
  props: ['tx', 'layout', 'colspan', 'selectable', 'selectionDisabled', 'selected', 'isDoubleEntry'],
  emits: ['toggle-select'],
  template: `<tr v-if="layout === 'desktop'" class="row-stub"><td v-if="selectable"><input v-if="!selectionDisabled" type="checkbox" data-test="row-select" :checked="selected" @change="$emit('toggle-select', tx.id)" /></td><td>{{ tx.id }}</td></tr>
    <div v-else class="card-stub"><input v-if="selectable && !selectionDisabled" type="checkbox" data-test="card-select" :checked="selected" @change="$emit('toggle-select', tx.id)" /></div>`,
})
const ActionBarStub = {
  name: 'ActionBar',
  props: ['actions'],
  template: `<div data-test="action-bar"><button v-for="a in actions.filter(x => x.show)" :key="a.key" :data-test="'action-' + a.key" @click="a.run()">{{ a.label }}</button></div>`,
}
const DialogStub = { name: 'BulkActionsDialog', props: ['target', 'selection', 'selectionCount', 'filterSelection', 'filterCount'], emits: ['close', 'changed'], template: '<div data-test="bulk-dialog" />' }
const MatchStub = { name: 'BankBatchMatchModal', props: ['ids'], emits: ['close', 'done'], template: '<div data-test="batch-match-dialog" />' }
const stubs = {
  ActionBar: ActionBarStub, BankTransactionDialogs: true, BankMatchModal: true,
  BankCreatePurchaseModal: true, BankRequestDocModal: true, BankTransactionSortSelect: true,
  BankTransactionRow: Row, BulkActionsDialog: DialogStub, BankBatchMatchModal: MatchStub,
  BulkActionBar: { props: ['count'], template: '<div data-test="bulk-bar" :data-count="count"><slot /></div>' },
  SortableTh: defineComponent({ props: ['label', 'sortKey'], template: '<th :data-sort="sortKey">{{ label }}</th>' }),
}

const page = { items: [{ id: 31, statement_id: 3 }, { id: 32, statement_id: 3 }], total: 120, page: 1, per_page: 50, years: [2026], accounts: [] }
const status = (available: boolean) => ({
  can_run: available, limit: 500, jobs: [],
  operations: [{ key: 'set_variable_symbol', available, reason: available ? null : 'Chybí oprávnění párovat bankovní pohyby.', rollback: true }],
})

async function mountList(scope: 'all' | 'unposted') {
  const wrapper = mount(UnpostedTransactions, { props: { scope }, global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('Bankovní pohyby – hromadné akce', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    sessionStorage.clear()
    m.perms = ['accounting.bulk_changes']
    m.mode = 'double_entry'
    m.listMovements.mockResolvedValue(page)
    m.listUnposted.mockResolvedValue(page)
    m.suggestions.mockResolvedValue({ suggestions: [] })
    m.status.mockResolvedValue(status(true))
  })

  it('zaškrtávátko je první buňkou hlavičky i řádku a výběr jde do dialogu jako id', async () => {
    const wrapper = await mountList('all')
    expect(m.status).toHaveBeenCalledWith('bank_transaction')
    const firstTh = wrapper.findAll('thead th')[0]!
    expect(firstTh.find('[data-test="bank-select-all"]').exists()).toBe(true)
    const row = wrapper.findAll('tr.row-stub')[0]!
    expect(row.findAll('td')[0]!.find('[data-test="row-select"]').exists()).toBe(true)
    expect(wrapper.getComponent(Row).props('colspan')).toBe(10)

    await wrapper.findAll('[data-test="row-select"]')[1]!.trigger('change')
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    const dialog = wrapper.getComponent(DialogStub)
    expect(dialog.props('target')).toBe('bank_transaction')
    expect(dialog.props('selection')).toEqual({ ids: [32] })
    expect(dialog.props('selectionCount')).toBe(1)
    expect(dialog.props('filterSelection')).toEqual({ list_query: { scope: 'all' } })
    expect(dialog.props('filterCount')).toBe(120)
  })

  it('vybrat vše označí načtené pohyby, mobilní karta má vlastní zaškrtávátko', async () => {
    const wrapper = await mountList('all')
    await wrapper.get('[data-test="bank-select-all"]').trigger('change')
    expect(wrapper.get('[data-test="bulk-bar"]').attributes('data-count')).toBe('2')
    expect(wrapper.findAll('[data-test="card-select"]')).toHaveLength(2)
    await wrapper.findAll('[data-test="card-select"]')[0]!.trigger('change')
    await wrapper.get('[data-test="bank-bulk-open"]').trigger('click')
    expect(wrapper.getComponent(DialogStub).props('selection')).toEqual({ ids: [32] })
  })

  it.each(['tax_evidence', 'double_entry'])('nabízí párování vybraných pohybů v režimu %s i bez práva měnit VS', async mode => {
    m.perms = ['bank.match']
    m.mode = mode
    const movement = { statement_id: 3, source: 'statement', amount: -1500, currency: 'CZK', match_status: 'unmatched' }
    m.listMovements.mockResolvedValue({ ...page, items: [
      { ...movement, id: 31 }, { ...movement, id: 32 }, { ...movement, id: 33, match_status: 'manual' },
    ] })
    const wrapper = await mountList('all')
    expect(wrapper.findAll('[data-test="row-select"]')).toHaveLength(2)
    await wrapper.get('[data-test="bank-select-all"]').trigger('change')
    await wrapper.get('[data-test="bank-review-batch"]').trigger('click')
    expect(wrapper.getComponent(MatchStub).props('ids')).toEqual([31, 32])
    expect(wrapper.find('[data-test="bank-bulk-open"]').exists()).toBe(false)
    wrapper.getComponent(MatchStub).vm.$emit('done')
    await flushPromises()
    expect(m.listMovements).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-test="bulk-bar"]').attributes('data-count')).toBe('0')
  })

  it('bez výběru pošle „všech N podle filtru" s filtrem seznamu a scope', async () => {
    const wrapper = await mountList('unposted')
    const vm = wrapper.vm as unknown as { search: string; year: number | null }
    vm.year = 2026
    await flushPromises()
    vm.search = '7788'
    await new Promise(resolve => setTimeout(resolve, 350))
    await flushPromises()
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    const dialog = wrapper.getComponent(DialogStub)
    expect(dialog.props('selection')).toEqual({ list_query: { year: 2026, q: '7788', scope: 'unposted' } })
    expect(dialog.props('selectionCount')).toBe(120)
    expect(dialog.props('filterSelection')).toBeNull()
  })

  it('po provedení nebo vrácení v dialogu seznam znovu načte a výběr zruší', async () => {
    const wrapper = await mountList('all')
    await wrapper.findAll('[data-test="row-select"]')[0]!.trigger('change')
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    const calls = m.listMovements.mock.calls.length
    wrapper.getComponent(DialogStub).vm.$emit('changed')
    await flushPromises()
    expect(m.listMovements.mock.calls.length).toBe(calls + 1)
    expect(wrapper.emitted('counts-changed')).toBeTruthy()
    expect(wrapper.find('[data-test="bulk-bar"]').attributes('data-count')).toBe('0')
  })

  it('když server operaci nenabízí, není výběr ani položka v menu', async () => {
    m.status.mockResolvedValue(status(false))
    const wrapper = await mountList('all')
    expect(wrapper.find('[data-test="bank-select-all"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="row-select"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="action-bulk-actions"]').exists()).toBe(false)
    expect(wrapper.getComponent(Row).props('colspan')).toBe(9)
  })

  it('bez práva hromadných změn se server ani neptá', async () => {
    m.perms = []
    const wrapper = await mountList('unposted')
    expect(m.status).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="action-bar"]').exists()).toBe(false)
  })
})
