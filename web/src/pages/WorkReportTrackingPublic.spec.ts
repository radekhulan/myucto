import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, expect, it, vi } from 'vitest'
import WorkReportTrackingPublic from './WorkReportTrackingPublic.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { token: 'a'.repeat(48) } }) }))
vi.mock('@/api/workReportTracking', () => ({ publicWorkReportApi: { get: mocks.get } }))

describe('Veřejný přehled cenového ujednání', () => {
  it('oddělí účtování od záznamů práce a respektuje skryté ceny v rekapitulaci', async () => {
    mocks.get.mockResolvedValue({ requires_auth: false, preview: {
      language: 'en', reports: [{ invoice_id: 1, date: '2026-01-01', currency: 'CZK', title: 'Syntetický výkaz',
        billing_enabled: true, total_hours: 1, total_amount: null, material_total: 0, materials: [],
        effective_display_options: { hours: true, rate: false, amount: false },
        items: [{ description: 'Analýza', hours: 1, work_date: null, rate: null, total_amount: null }],
        billing_preview: { items: [{ billing_key: 'fixed', description: 'Syntetický paušál', quantity: 1, unit: 'ks', unit_price_without_vat: null, total_amount: null }], total_hours: 1, included_hours: 1, extra_hours: 0, total_amount: null },
      }], total_hours: 1, totals_by_currency: [],
    } })
    const wrapper = mount(WorkReportTrackingPublic, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: {
      en: { work_report_billing: { recap: 'Billing summary', hours_recap: '{included} included / {extra} extra' } },
    } })] } })
    await flushPromises()
    const recap = wrapper.find('[data-test="billing-recap"]')
    expect(recap.text()).toContain('Billing summary')
    expect(recap.text()).toContain('Syntetický paušál')
    expect(recap.text()).not.toContain('CZK')
    expect(wrapper.findAll('thead th')).toHaveLength(2)
    wrapper.unmount()
  })
})
