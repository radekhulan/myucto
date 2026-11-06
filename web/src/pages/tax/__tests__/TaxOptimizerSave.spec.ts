import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Optimalizátor rodinu needituje. Rodná čísla v profilu chodí maskovaná a backend je
// převezme podle id řádku, které se každým uložením mění. Kdyby stránka rodinu
// posílala, druhé uložení bez znovunačtení by rodná čísla tiše smazalo.
const m = vi.hoisted(() => ({
  analysis: vi.fn(),
  saveProfile: vi.fn(),
}))

vi.mock('@/api/tax', () => ({ taxApi: { analysis: m.analysis, saveProfile: m.saveProfile } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useTaxEngine', () => ({
  compare: () => null,
  predict: () => null,
  regular: () => ({ net: 0 }),
  pausalPeriods: () => [],
  pausalRateChange: () => null,
}))
vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return { ...actual, useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }
})

import TaxOptimizer from '@/pages/tax/TaxOptimizer.vue'

const child = {
  id: 11, first_name: 'Dítě', last_name: 'Testovací', birth_number: '******/1234', birth_date: '2020-01-01',
  months: [],
}
const spouse = { id: 21, first_name: 'Manžel', last_name: 'Testovací', birth_number: '******/5678', eligible_months: 12 }

describe('TaxOptimizer – uložení profilu', () => {
  it('neposílá děti ani manžela, aby opakované uložení nesmazalo rodná čísla', async () => {
    m.analysis.mockResolvedValue({
      year: 2025, mode: 'forecast', is_vat_payer: false, supplier_band: 'none', constants: {},
      available_years: [2025], ytd_income: 0, months_elapsed: 1, prev: null, last_month: null,
      profile: {
        activity_rate: 60, use_actual_expenses: false, actual_expenses: 0, flat_tax_band: 'none',
        is_secondary: false, spouse_credit: true, children_count: 1, mortgage_interest: 0,
        mortgage_pre_2021: false, mortgage_months: 12, pension_contrib: 0, life_insurance: 0,
        dip_contrib: 0, long_term_care: 0, disability_12_months: 0, disability_3_months: 0,
        ztpp_months: 0, donations: 0, activities: [], children: [child], spouse_claim: spouse, osvc_months: [],
      },
    })
    m.saveProfile.mockResolvedValue({})

    const wrapper = mount(TaxOptimizer)
    await flushPromises()
    const save = wrapper.findAll('button').find(b => b.text().includes('tax.save'))
    expect(save).toBeTruthy()
    await save!.trigger('click')
    await flushPromises()
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveProfile).toHaveBeenCalledTimes(2)
    for (const [payload] of m.saveProfile.mock.calls) {
      expect(payload).not.toHaveProperty('children')
      expect(payload).not.toHaveProperty('spouse_claim')
      expect(payload).toMatchObject({ year: 2025, children_count: 1, spouse_credit: true })
    }
  })
})
