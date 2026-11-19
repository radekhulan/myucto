import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ classifications: vi.fn(), rates: vi.fn() }))

vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: m.classifications } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { vatRates: m.rates } }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import VatClassificationFields from '../VatClassificationFields.vue'

const RATES = [
  { id: 1, code: 'CZ-21', rate_percent: 21, country: 'CZ', label_cs: 'Základní', label_en: 'Standard', is_default: true, is_reverse_charge: false, valid_from: '2024-01-01', display_order: 1 },
  { id: 6, code: 'CZ-0-RC', rate_percent: 0, country: 'CZ', label_cs: 'Přenesení', label_en: 'Reverse', is_default: false, is_reverse_charge: true, valid_from: '2024-01-01', display_order: 3 },
  { id: 7, code: 'CZ-0', rate_percent: 0, country: 'CZ', label_cs: 'Nulová', label_en: 'Zero', is_default: false, is_reverse_charge: false, valid_from: '2024-01-01', display_order: 2 },
]
const CODES = [
  { id: 1, code: 'A1', label: 'Tuzemsko', archived: false },
  { id: 2, code: 'mimo', label: 'Mimo předmět', archived: false },
  { id: 3, code: 'OLD', label: 'Archiv', archived: true },
]

describe('VatClassificationFields', () => {
  beforeEach(() => {
    m.classifications.mockReset().mockResolvedValue(CODES)
    m.rates.mockReset().mockResolvedValue(RATES)
  })

  it('u položek nabídne sazbu a předvolba mimo předmět nastaví kód i nulovou sazbu bez přenesení', async () => {
    const wrapper = mount(VatClassificationFields, { props: { direction: 'purchase', withRate: true, code: '', rateId: null } })
    await flushPromises()
    expect(m.classifications).toHaveBeenCalledWith('purchase')
    expect(wrapper.findAll('[data-test="bulk-vat-code"] option').map(o => o.attributes('value'))).toEqual(['', 'A1', 'mimo'])
    expect(wrapper.findAll('[data-test="bulk-vat-rate"] option').map(o => o.attributes('value'))).toEqual(['', '1', '7', '6'])

    await wrapper.get('[data-test="bulk-vat-outside"]').trigger('click')
    expect(wrapper.emitted('update:code')?.at(-1)).toEqual(['mimo'])
    expect(wrapper.emitted('update:rateId')?.at(-1)).toEqual([7])
    expect(wrapper.get('[data-test="bulk-vat-hint"]').text()).toContain('bulk_actions.vat_rate_hint')
  })

  it('u dokladů jen kód bez sazby', async () => {
    const wrapper = mount(VatClassificationFields, { props: { direction: 'sale', code: '', rateId: null } })
    await flushPromises()
    expect(m.rates).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="bulk-vat-rate"]').exists()).toBe(false)
    await wrapper.get('[data-test="bulk-vat-outside"]').trigger('click')
    expect(wrapper.emitted('update:code')?.at(-1)).toEqual(['mimo'])
    expect(wrapper.emitted('update:rateId')).toBeUndefined()
    expect(wrapper.get('[data-test="bulk-vat-hint"]').text()).not.toContain('bulk_actions.vat_rate_hint')
    expect(wrapper.get('[data-test="bulk-vat-hint"]').text()).toContain('bulk_actions.vat_filed_hint')
  })
})
