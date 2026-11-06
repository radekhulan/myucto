import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getAccountDetail: vi.fn(),
  getAccountStatement: vi.fn(),
  listPeriods: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getAccountDetail: m.getAccountDetail,
    getAccountStatement: m.getAccountStatement,
    listPeriods: m.listPeriods,
  },
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ params: { accountId: '1' }, query: {} }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))

import AccountDetail from '@/pages/accounting/AccountDetail.vue'

function report(opening: number, closing: number) {
  return {
    account: { id: 1, code: '311.100', name: 'Pohledávky', account_type: 'asset', normal_side: 'debit', is_active: true, is_synthetic: false, parent: null },
    period: null,
    totals: { opening_balance: opening, turnover_md: 0, turnover_d: 0, closing_balance: closing, line_count: 0 },
    children: [],
  }
}

async function mountWith(opening: number, closing: number) {
  m.getAccountDetail.mockResolvedValue(report(opening, closing))
  const wrapper = mount(AccountDetail, {
    global: { stubs: { ActionBar: true, EmptyState: true, DateInput: true, JournalSourceDrawer: true } },
  })
  await flushPromises()
  return wrapper
}

describe('AccountDetail - zůstatek v kartách', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listPeriods.mockResolvedValue([])
    m.getAccountStatement.mockResolvedValue({ items: [], total: 0 })
  })

  it('zůstatek na straně Dal ukáže jako kladnou částku se stranou Dal', async () => {
    const wrapper = await mountWith(666138.88, -140326)

    expect(wrapper.get('[data-testid="closing-label"]').text()).toBe('accounting.account_statement.closing · accounting.journal.side.credit')
    expect(wrapper.get('[data-testid="closing-amount"]').text()).toBe('140326')
  })

  it('zůstatek na straně MD ukáže se stranou MD', async () => {
    const wrapper = await mountWith(666138.88, 100)

    expect(wrapper.get('[data-testid="opening-label"]').text()).toBe('accounting.account_statement.opening · accounting.journal.side.debit')
    expect(wrapper.get('[data-testid="opening-amount"]').text()).toBe('666138.88')
    expect(wrapper.get('[data-testid="closing-label"]').text()).toBe('accounting.account_statement.closing · accounting.journal.side.debit')
  })

  it('počáteční zůstatek závazku na straně Dal nese stranu i kladnou částku', async () => {
    const wrapper = await mountWith(-201490.13, -345.04)

    expect(wrapper.get('[data-testid="opening-label"]').text()).toBe('accounting.account_statement.opening · accounting.journal.side.credit')
    expect(wrapper.get('[data-testid="opening-amount"]').text()).toBe('201490.13')
  })

  it('nulový zůstatek stranu neuvádí', async () => {
    const wrapper = await mountWith(0, 0)

    expect(wrapper.get('[data-testid="closing-label"]').text()).toBe('accounting.account_statement.closing')
  })
})
