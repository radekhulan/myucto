import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ start: vi.fn(), preview: vi.fn() }))
vi.mock('@/api/reports', () => ({ reportsApi: {
  closingPackagePreview: m.preview, closingPackageJobs: async () => [], closingPackageStart: m.start,
} }))
vi.mock('@/api/closing', () => ({ closingApi: { state: async () => ({ period: { id: 1, fiscal_year: 2025, starts_on: '2024-07-01', ends_on: '2025-06-30' } }) } }))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' }, useRoute: () => ({ params: { id: '1' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value }))
import ClosingPackage from '../ClosingPackage.vue'

beforeEach(() => {
  m.start.mockReset().mockResolvedValue({ job_id: 1 })
  m.preview.mockResolvedValue({ accounting_framework: 'cz_504', counts: { balance_sheet: 1, income_statement: 1, statement_notes: 1, cash_flow: 0, equity_changes: 0, journal: 0 } })
})

it.each([false, true])('submits only effective available parts, including after select all (%s)', async (selectAll) => {
  const w = mount(ClosingPackage)
  await flushPromises()
  if (selectAll) await w.findAll('button').find(b => b.text() === 'accounting.closing_package.select_all')!.trigger('click')
  await w.findAll('button').find(b => b.text() === 'accounting.closing_package.job.start')!.trigger('click')
  await flushPromises()
  expect(m.start).toHaveBeenCalledWith(1, ['balance_sheet', 'income_statement', 'statement_notes'], false)
  for (const label of w.findAll('label')) {
    if (!label.text().includes('accounting.closing_package.parts.cash_flow')) continue
    expect((label.get('input').element as HTMLInputElement).checked).toBe(false)
  }
})
