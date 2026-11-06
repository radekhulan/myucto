import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PublicReceipt from './PublicReceipt.vue'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/i18n', () => ({ ensureLocaleLoaded: vi.fn().mockResolvedValue(undefined) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { token: 'a'.repeat(64) } }) }))
vi.mock('@/api/publicReceipt', () => ({ publicReceiptApi: { get: api.get, pdfUrl: (token: string) => `/api/public/receipt/${token}/pdf?download=1` } }))

function receipt(playground: boolean, pok: string | null) {
  return {
    receipt: {
      number: 'U2026001', issue_date: '2026-11-02', tax_date: '2026-11-02', is_simplified: true, language: 'cs',
      currency: 'CZK', currency_decimals: 2, prices_include_vat: true, payment_method: 'cash', paid_at: '2026-11-02',
      totals: { without_vat: 250, vat: 48, with_vat: 298, rounding: 0, amount_to_pay: 298 },
      vat_breakdown: [{ rate: 21, base: 200, vat: 42, total: 242 }, { rate: 12, base: 50, vat: 6, total: 56 }],
      items: [
        { description: 'Káva', quantity: 2, unit: 'ks', unit_price_without_vat: 121, vat_rate_snapshot: 21, total_without_vat: 200, total_with_vat: 242, item_kind: 'standard' },
        { description: 'Koláč', quantity: 1, unit: 'ks', unit_price_without_vat: 56, vat_rate_snapshot: 12, total_without_vat: 50, total_with_vat: 56, item_kind: 'standard' },
      ],
      expires_at: '2027-01-31T10:00:00+01:00',
    },
    supplier: { company_name: 'Synthetic s.r.o.', ic: '00000019', dic: 'CZ00000019', is_vat_payer: true },
    client: null,
    eet: [{ kind: 'sale', eic: 'CZ00000019', premises: 11, cash_register: 'M1', sequence_number: '42', sale_at: '2026-11-02T10:00:00+01:00',
      total_amount: '298.00', pok, confirmed: pok !== null, playground }],
  }
}

describe('public receipt', () => {
  it('shows simplified document, VAT recap and EET data with playground label', async () => {
    api.get.mockResolvedValue(receipt(true, 'TEST-POK-0001-ff'))
    const wrapper = mount(PublicReceipt, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })] } })
    await flushPromises()
    try {
      const text = wrapper.text()
      expect(text).toContain('Zjednodušený daňový doklad')
      expect(text).toContain('U2026001')
      expect(text).toContain('Zaplaceno v hotovosti')
      expect(text).toContain('Testovací evidence (Playground)')
      expect(text).toContain('TEST-POK-0001-ff')
      expect(text).toContain('Provozovna')
      expect(wrapper.findAll('li').length).toBeGreaterThanOrEqual(2)
    } finally {
      wrapper.unmount()
    }
  })

  it('without POK says the sale awaits confirmation and has no playground label in production', async () => {
    api.get.mockResolvedValue(receipt(false, null))
    const wrapper = mount(PublicReceipt, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })] } })
    await flushPromises()
    try {
      expect(wrapper.text()).toContain('čeká na potvrzení')
      expect(wrapper.text()).not.toContain('Playground')
    } finally {
      wrapper.unmount()
    }
  })

  it('invalid link shows an error', async () => {
    api.get.mockRejectedValue(new Error('404'))
    const wrapper = mount(PublicReceipt, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })] } })
    await flushPromises()
    try {
      expect(wrapper.text()).toContain('Odkaz není platný')
    } finally {
      wrapper.unmount()
    }
  })
})
