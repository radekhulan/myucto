import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({
  getAccountStatement: vi.fn(),
  getAccountInventory: vi.fn(),
  getOpenItems: vi.fn(),
  exportReport: vi.fn(),
  replace: vi.fn(),
  query: {} as Record<string, string>,
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getAccountStatement: m.getAccountStatement,
    getAccountInventory: m.getAccountInventory,
    getOpenItems: m.getOpenItems,
    exportReport: m.exportReport,
  },
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/components/accounting/JournalSourceDrawer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/utils/journalSourceLink', () => ({ journalSourceLink: () => null, journalEntryLink: () => ({ name: 'x' }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => reactive({ params: { accountId: '7' }, query: m.query }),
  useRouter: () => ({ replace: m.replace }),
}))

import AccountStatement from '@/pages/accounting/AccountStatement.vue'

function statement(patch: Record<string, unknown> = {}) {
  return {
    account: { id: 7, code: '321', name: 'Dodavatelé', type: 'liability', normal_side: 'credit', balance_sign: -1 },
    opening_balance: -800, opening_balance_signed: 800, opening_balance_unusual: false,
    closing_balance: 100, closing_balance_signed: -100, closing_balance_unusual: true,
    items: [{
      entry_id: 1, entry_date: '2099-03-01', document_no: 'D1', description: 'Úhrada', source_type: 'manual', source_id: null,
      side: 'debit', amount: 900, balance: 100, balance_signed: -100, balance_unusual: true,
      account_id: 7, account_code: '321', account_name: 'Dodavatelé', line_id: 1, line_no: 1, counter_accounts: '221',
      partner: null, variable_symbol: null, currency: 'CZK', amount_foreign: null, pairing_id: null,
    }],
    total: 1, page: 1, per_page: 50, turnover_md: 900, turnover_d: 0,
    counter_totals: [
      { account_id: 21, account_code: '221', name: 'Banka', parent_id: null, md: 600, d: 0 },
      { account_id: 11, account_code: '211', name: 'Pokladna', parent_id: null, md: 300, d: 0 },
    ],
    counter_filter: null,
    ...patch,
  }
}

function mountPage() {
  return mount(AccountStatement, { global: { stubs: { DateInput: true, EmptyState: true } } })
}

describe('AccountStatement.vue', () => {
  beforeEach(() => {
    for (const fn of [m.getAccountStatement, m.getAccountInventory, m.getOpenItems, m.replace]) fn.mockReset()
    for (const k of Object.keys(m.query)) delete m.query[k]
    m.getAccountStatement.mockResolvedValue(statement())
  })

  it('ukazuje zůstatek se znaménkem podle typu účtu a neobvyklou stranu červeně', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="opening-amount"]').text()).toBe('800')
    const closing = wrapper.get('[data-testid="closing-amount"]')
    expect(closing.text()).toBe('-100')
    expect(closing.classes()).toContain('text-danger-600')
  })

  it('sečte součty podle protiúčtu a kliknutím na řádek opis vyfiltruje', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="counter-totals"]').text()).toContain('900')
    await wrapper.findAll('[data-testid="counter-row"]')[0].trigger('click')
    await flushPromises()

    expect(m.getAccountStatement.mock.calls.at(-1)?.[1].counter_account_id).toBe(21)
    expect(m.replace.mock.calls.at(-1)?.[0].query.counter).toBe('21')
  })

  it('u řádku s dvojicí ukáže jediný protiúčet a označí rozložený starší zápis i M : N', async () => {
    const base = statement().items[0]
    m.getAccountStatement.mockResolvedValue(statement({
      items: [
        { ...base, line_id: 1, counter_accounts: '221', pair_no: 1, counter_account_id: 21 },
        { ...base, line_id: 2, counter_accounts: '602', pair_status: 'derived' },
        { ...base, line_id: 3, counter_accounts: '501, 502', pair_status: 'composite_mn' },
      ],
      total: 3,
    }))
    const wrapper = mountPage()
    await flushPromises()

    const cells = wrapper.findAll('[data-test="counter-cell"]')
    expect(cells.map(c => c.text())).toEqual([
      '221',
      '602accounting.pairs.statement.derived',
      '501, 502accounting.pairs.badge_mn',
    ])
    expect(cells[0].find('span').exists()).toBe(false)
  })

  it('filtr z výběru a jeho zrušení', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="counter-filter"]').setValue('11')
    await flushPromises()
    expect(m.getAccountStatement.mock.calls.at(-1)?.[1].counter_account_id).toBe(11)

    await wrapper.get('[data-testid="counter-filter"]').setValue('')
    await flushPromises()
    expect(m.getAccountStatement.mock.calls.at(-1)?.[1]).not.toHaveProperty('counter_account_id')
  })

  it('export nese filtr protiúčtu', async () => {
    m.query.counter = '21'
    m.exportReport.mockResolvedValue({ data: new Blob() })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.findAll('button').find(b => b.text() === 'accounting.account_statement.export_xlsx')!.trigger('click')
    await flushPromises()

    expect(m.exportReport.mock.calls[0][1].counter_account_id).toBe(21)
  })

  it('proklik z výkazů otevře inventuru účtu s řádky analytik', async () => {
    m.query.mode = 'inventory'
    m.query.to = '2099-06-30'
    m.getAccountInventory.mockResolvedValue({
      account: { id: 7, code: '321', name: 'Dodavatelé', type: 'liability', normal_side: 'credit' },
      inventoriable: true,
      period: { id: 5, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
      as_of: '2099-12-31',
      inventory: { status: 'in_progress', completed: false, responsible_person: 'Jana' },
      rows: [{ account_id: 71, account_code: '321001', name: 'Analytika', book_display: 1000, counted_display: 900, difference_display: -100, resolved: true, documentation_hint: 'Saldokonto', item_note: 'Dobropis' }],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.getAccountInventory).toHaveBeenCalledWith(7, '2099-06-30')
    const box = wrapper.get('[data-testid="account-inventory"]')
    expect(box.text()).toContain('321001')
    expect(box.text()).toContain('-100')
    expect(box.text()).toContain('Dobropis')
    const protocolLink = wrapper.findAllComponents({ name: 'RouterLink' })
      .find((link) => link.attributes('data-testid') === 'inventory-protocol-link')
    expect(protocolLink?.props('to')).toEqual({
      name: 'accounting-balance-inventory', query: { period_id: 5, account: 7 },
    })
  })

  it('výsledkový účet se neinventarizuje, ale proklik neskončí chybou', async () => {
    m.query.mode = 'inventory'
    m.getAccountInventory.mockResolvedValue({
      account: { id: 7, code: '518', name: 'Služby', type: 'expense', normal_side: 'debit' },
      inventoriable: false, period: null, as_of: null, inventory: null, rows: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="inventory-not-applicable"]').exists()).toBe(true)
  })
})
