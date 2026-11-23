import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Záloha převzatá bez sazeb DPH: dialog nabídne jen kladné tuzemské sazby platné k datu
// platby a vrátí zvolenou sazbu.
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn(async () => [
      { id: 1, code: 'Z', rate_percent: 21, country: 'CZ', label_cs: 'Základní', label_en: 'Standard', is_default: true, is_reverse_charge: false, valid_from: '2024-01-01', display_order: 1 },
      { id: 2, code: 'S', rate_percent: 12, country: 'CZ', label_cs: 'Snížená', label_en: 'Reduced', is_default: false, is_reverse_charge: false, valid_from: '2024-01-01', display_order: 2 },
      { id: 3, code: 'N', rate_percent: 0, country: 'CZ', label_cs: 'Osvobozeno', label_en: 'Exempt', is_default: false, is_reverse_charge: false, valid_from: '2024-01-01', display_order: 3 },
      { id: 4, code: 'RC', rate_percent: 21, country: 'CZ', label_cs: 'PDP', label_en: 'RC', is_default: false, is_reverse_charge: true, valid_from: '2024-01-01', display_order: 4 },
    ]),
  },
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import PaymentTaxRateDialog from '../PaymentTaxRateDialog.vue'

describe('volba sazby DDKP k záloze bez sazeb', () => {
  it('nabídne jen kladné tuzemské sazby a vrátí zvolenou', async () => {
    const wrapper = mount(PaymentTaxRateDialog, {
      props: { paidOn: '2026-07-13' },
      global: { stubs: { Modal: { template: '<div><slot /></div>' }, DialogActions: { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" @click="a.run()">{{ a.label }}</button></div>' } } },
    })
    await flushPromises()
    const options = wrapper.findAll('[data-test="payment-tax-rate-select"] option')
    expect(options.map(o => o.attributes('value'))).toEqual(['1', '2'])
    await wrapper.find('[data-test="payment-tax-rate-select"]').setValue('2')
    await wrapper.find('[data-action="confirm"]').trigger('click')
    expect(wrapper.emitted('pick')?.[0]).toEqual([2])
  })
})
