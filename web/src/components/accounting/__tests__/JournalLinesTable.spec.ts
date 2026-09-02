import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value),
}))

const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a><slot /></a>',
}

describe('JournalLinesTable', () => {
  it('odkazuje z účtu přímo na jeho pohyby ve zvoleném rozsahu', () => {
    const wrapper = mount(JournalLinesTable, {
      props: {
        lines: [{
          id: 1,
          entry_id: 10,
          supplier_id: 1,
          account_id: 3138611,
          account_code: '221.400',
          account_name: 'Běžný účet',
          side: 'debit',
          amount: 100,
          currency_code: null,
          fx_rate: null,
          amount_foreign: null,
          cost_center: null,
          line_no: 1,
        }],
        dateFrom: '2027-01-01',
        dateTo: '2027-12-31',
      },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })

    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({
      name: 'accounting-account-statement',
      params: { accountId: 3138611 },
      query: { from: '2027-01-01', to: '2027-12-31' },
    })
  })
})
