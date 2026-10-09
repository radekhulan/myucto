import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import TaxEstimate from '@/pages/tax-evidence/TaxEstimate.vue'
import type { TaxEstimate as Estimate, TaxEstimateVariant } from '@/api/taxEvidence'

const taxEstimate = vi.fn()

vi.mock('@/api/taxEvidence', () => ({
  taxEvidenceApi: { taxEstimate: (year: number) => taxEstimate(year) },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))
vi.mock('@/composables/useYearOptions', () => ({
  useYearOptions: () => computed(() => [2099, 2098]),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
}))

const RouterLinkStub = { name: 'RouterLink', props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }

function variant(mode: 'actual' | 'pausal', patch: Partial<TaxEstimateVariant> = {}): TaxEstimateVariant {
  return {
    mode, expense_rate: mode === 'pausal' ? 60 : 0, income: 1000000, expenses: 344500, s7_base: 655500,
    tax_base: 655500, tax_before_credits: 98325, credits: 30840, child_benefit: 0, tax: 67485, tax_advances: 0,
    social: { assessment_base: 360525, min_base: 195540, insurance: 105274, monthly_advance: 8773, participates: true },
    health: { assessment_base: 327750, min_base: 279342, insurance: 44247, monthly_advance: 3688 },
    total_burden: 217006, warnings: [], ...patch,
  }
}

function estimate(patch: Partial<Estimate> = {}): Estimate {
  return {
    applicable: true, reason: null, year: 2099, as_of: '2099-10-01', year_closed: false, return_status: 'none',
    selected_mode: 'actual', recommended_mode: 'pausal', has_activities: false, flat_tax_band: 'none', is_secondary: false,
    sources: { income: 1000000, cash_journal_expenses: 300000, confirmed_depreciation: 0, disposal_residuals: 0,
      pending_depreciation: 44500, planned_depreciation: 44500, increase: 0, decrease: 0 },
    pausal: { rate: 60, cap: 1200000, cap_reached: false },
    variants: {
      actual: variant('actual'),
      pausal: variant('pausal', { expenses: 600000, s7_base: 400000, tax: 29160, total_burden: 131112 }),
    },
    advances: {
      tax: { paid: 0, source: 'none', remaining: 0 },
      social: { paid: 45000, source: 'schedules', remaining: 15000 },
      health: { paid: 0, source: 'none', remaining: 0 },
    },
    settlement: { tax: 67485, social: 45274, health: 44247 },
    warnings: ['Upozornění A'],
    ...patch,
  }
}

function mountPage() {
  return mount(TaxEstimate, { global: { stubs: { RouterLink: RouterLinkStub } } })
}

describe('TaxEstimate (daňová evidence)', () => {
  beforeEach(() => taxEstimate.mockReset())

  it('compares actual and flat-rate expenses and marks the better one', async () => {
    taxEstimate.mockResolvedValue(estimate())
    const w = mountPage()
    await flushPromises()

    expect(taxEstimate).toHaveBeenCalledWith(new Date().getFullYear())
    expect(w.find('[data-test="source-pending"]').text()).toContain('44500')
    expect(w.find('[data-test="row-s7_base"]').text()).toContain('655500')
    expect(w.find('[data-test="row-s7_base"]').text()).toContain('400000')
    expect(w.find('[data-test="recommended-pausal"]').exists()).toBe(true)
    expect(w.find('[data-test="estimate-recommend-hint"]').exists()).toBe(true)
    expect(w.find('[data-test="advance-social"]').text()).toContain('45274')
    expect(w.find('[data-test="estimate-warnings"]').text()).toContain('Upozornění A')
    expect(w.find('a').attributes('data-to')).toContain('reports-income-tax')
  })

  it('reloads for another year', async () => {
    taxEstimate.mockResolvedValue(estimate())
    const w = mountPage()
    await flushPromises()
    await w.find('[data-test="estimate-year"]').setValue('2098')
    await flushPromises()
    expect(taxEstimate).toHaveBeenLastCalledWith(2098)
  })

  it('explains why the estimate does not apply', async () => {
    taxEstimate.mockResolvedValue({ applicable: false, reason: 'taxpayer_po', year: 2099 })
    const w = mountPage()
    await flushPromises()
    expect(w.text()).toContain('tax_evidence.tax_estimate.reason_taxpayer_po')
    expect(w.find('[data-test="estimate-compare"]').exists()).toBe(false)
  })

  it('shows the API error', async () => {
    taxEstimate.mockRejectedValueOnce({ response: { status: 403, data: { error: { message: 'Jen daňová evidence.' } } } })
    const w = mountPage()
    await flushPromises()
    expect(w.find('[data-test="estimate-error"]').text()).toContain('Jen daňová evidence.')
  })
})
