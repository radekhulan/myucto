import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { preview, exportPdf, send, setFlag } = vi.hoisted(() => ({
  preview: vi.fn(), exportPdf: vi.fn(), send: vi.fn(), setFlag: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value), formatDate: (value: string) => value }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({ flag: () => false, setFlag }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/api/monthlyReport', () => ({ monthlyReportApi: { history: async () => [], preview, exportPdf, send } }))

import MonthlyReport from '../MonthlyReport.vue'

function reportData(spread?: object) {
  return {
    period: { year: 2091, month: 10, as_of: '2091-10-31' },
    income_statement_month: [{ row_code: 'VH', label: 'Synthetic result', level: 0, row_type: 'computed', amount: 10 }],
    income_statement_ytd: { rows: [], checks: { profit_current: 100, net_turnover: 0 }, ...(spread ? { accrual_spread: spread } : {}) },
    balance_sheet: { assets: [], liabilities: [], checks: { assets_net: 0, liabilities_total: 0, balanced: true } },
    receivables_overdue: [], payables_overdue: [], upcoming_deadlines: [], vat: null,
  }
}

const SPREAD = { applied: true, skipped_reason: null, prepaid_expense: 6100, deferred_revenue: 24300, documents: 2, prev: null }

beforeEach(() => {
  vi.clearAllMocks()
  preview.mockImplementation(async (_y: number, _m: number, _c?: string, options: { accrual_spread?: 1 } = {}) =>
    reportData(options.accrual_spread ? SPREAD : undefined))
  exportPdf.mockResolvedValue({ data: new Blob() })
  send.mockResolvedValue({ sent_to: ['klient@example.com'], cc: [], document_id: null, sent_at: '' })
  URL.createObjectURL = vi.fn(() => 'blob:x')
  URL.revokeObjectURL = vi.fn()
})

it('bez volby náhled parametr neposílá a poznámku neukazuje', async () => {
  const w = mount(MonthlyReport, { global: { stubs: { EmptyState: true } } })
  await flushPromises()
  expect(preview.mock.calls[0][3]).toEqual({})
  expect(w.find('[data-test="accrual-spread-note"]').exists()).toBe(false)
})

it('přepínač znovu načte náhled s volbou, ukáže poznámku a volbu nese i PDF a odeslání', async () => {
  const w = mount(MonthlyReport, { global: { stubs: { EmptyState: true } } })
  await flushPromises()

  await w.find('[data-test="accrual-spread-toggle"] input').setValue(true)
  await flushPromises()
  expect(setFlag).toHaveBeenCalledWith('accrual_spread', true)
  expect(preview.mock.lastCall?.[3]).toEqual({ accrual_spread: 1 })
  expect(w.find('[data-test="accrual-spread-note"]').exists()).toBe(true)

  const buttons = w.findAll('button')
  await buttons.find(b => b.text() === 'monthly_report.download_pdf')!.trigger('click')
  await flushPromises()
  expect(exportPdf.mock.lastCall?.[3]).toEqual({ accrual_spread: 1 })

  await buttons.find(b => b.text() === 'monthly_report.send_to_client')!.trigger('click')
  await w.find('input[placeholder="monthly_report.send_to_placeholder"]').setValue('klient@example.com')
  await w.findAll('button').find(b => b.text() === 'monthly_report.send_confirm')!.trigger('click')
  await flushPromises()
  expect(send.mock.lastCall?.[0]).toMatchObject({ to: ['klient@example.com'], accrual_spread: 1 })
})
