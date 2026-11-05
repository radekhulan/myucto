import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import BillingRecap from './BillingRecap.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }))
const preview = { included_hours: 5, extra_hours: 1.125, total_amount: 1.23, items: [{ billing_key: 'band:0', description: 'Service', quantity: 1.125, unit: 'h', unit_price_without_vat: 0.123456, total_amount: 1.23 }] }
describe('BillingRecap', () => {
  it('zachová přesnost hodin a šest desetinných míst sazby, peníze ukáže na dvě', () => {
    const wrapper = mount(BillingRecap, { props: { preview, currency: 'EUR', pricesIncludeVat: true } })
    expect(wrapper.text()).toContain('1.125 h × 0.123456 EUR')
    expect(wrapper.text()).toContain('1.23 EUR')
    expect(wrapper.text()).toContain('work_report_billing.prices_incl_vat')
  })
  it('skryté hodiny, sazby a částky se nedostanou do veřejné rekapitulace', () => {
    const wrapper = mount(BillingRecap, { props: { preview, currency: 'EUR', pricesIncludeVat: false, displayOptions: { compact: false, date: true, hours: false, rate: false, amount: false } } })
    expect(wrapper.text()).toContain('Service')
    expect(wrapper.text()).not.toContain('0.123456')
    expect(wrapper.text()).not.toContain('1.125')
    expect(wrapper.text()).not.toContain('1.23')
    expect(wrapper.text()).not.toContain('prices_excl_vat')
  })
})
