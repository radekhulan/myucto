import { expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value), formatDate: (value: string) => value }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({ flag: () => false, setFlag: vi.fn() }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/api/monthlyReport', () => ({ monthlyReportApi: {
  history: async () => [],
  preview: async () => {
    const metadata = { accounting_framework: 'cz_504', as_of: '2025-01-31', scope: 'full',
      period: { id: 1, fiscal_year: 2025, starts_on: '2024-07-01', ends_on: '2025-06-30' }, entity: { name: 'Synthetic association' } }
    return { accounting_framework: 'cz_504', period: { year: 2025, month: 1, as_of: '2025-01-31' },
      income_statement_month: [{ row_code: 'UT.63', label: 'Synthetic monthly result', main: -30.04, economic: 30.03, total: -0.01 }],
      income_statement_ytd: { ...metadata, statement_type: 'income_statement', rows: [], checks: { profit_current: 100 } },
      balance_sheet: { ...metadata, statement_type: 'account_view', view_kind: 'management',
        balances: [{ code: '931', name: 'Synthetic pending result', opening_debit_balance: -1000, closing_debit_balance: -1000 }],
        checks: { unresolved_pending_result: 1000, reconciliation_difference: 0 } },
      receivables_overdue: [], payables_overdue: [], upcoming_deadlines: [], vat: null }
  },
} }))

import MonthlyReport from '../MonthlyReport.vue'

it('shows three monthly activity columns and pending 931 without the 500 amount fallback', async () => {
  const w = mount(MonthlyReport, { global: { stubs: { EmptyState: true } } })
  await flushPromises()
  expect(w.text()).toContain('-30.04')
  expect(w.text()).toContain('30.03')
  expect(w.text()).toContain('-0.01')
  expect(w.text()).toContain('accounting.statement504.main')
  expect(w.text()).toContain('Synthetic pending result')
  expect(w.text()).toContain('-1000')
  expect(w.find('[data-test="accrual-spread-toggle"]').exists()).toBe(false)
})
