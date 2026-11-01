import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApprovalPublic from './ApprovalPublic.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), ensureLocaleLoaded: vi.fn().mockResolvedValue(undefined) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { token: 'a'.repeat(48) } }) }))
vi.mock('@/api/approval', () => ({ approvalApi: { get: mocks.get } }))
vi.mock('@/i18n', () => ({ ensureLocaleLoaded: mocks.ensureLocaleLoaded }))

function payload(language: 'cs' | 'en', amount = true) {
  return {
    state: 'requested', supplier_name: 'Syntetický dodavatel', captcha_provider: 'none',
    invoice: { id: 1, language, currency: 'CZK' },
    work_report: {
      title: 'Syntetický výkaz', total_hours: 1, total_amount: amount ? 1000 : null,
      effective_display_options: { date: true, hours: true, rate: false, amount, compact: true },
      items: [{ id: 1, description: 'Analýza', work_date: null, hours: 1, rate: null, total_amount: amount ? 1000 : null }],
      material_total: 0, materials: [],
    },
  }
}

describe('Veřejné schvalování výkazu', () => {
  beforeEach(() => vi.clearAllMocks())

  it.each(['cs', 'en'] as const)('použije vue-i18n a jazyk dokladu %s pro nový sloupec', async (language) => {
    mocks.get.mockResolvedValue(payload(language))
    const wrapper = mount(ApprovalPublic, {
      global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: {
        cs: { workReportDisplay: { amount: 'Částka' } },
        en: { workReportDisplay: { amount: 'Amount' } },
      } })] },
    })
    await flushPromises()
    expect(mocks.ensureLocaleLoaded).toHaveBeenCalledWith(language)
    expect(wrapper.findAll('th').map(cell => cell.text())).toContain(language === 'cs' ? 'Částka' : 'Amount')
    expect(wrapper.find('table.wr-compact').exists()).toBe(true)
    wrapper.unmount()
  })

  it('skryje sloupec částky i součet podle nastavení výkazu', async () => {
    mocks.get.mockResolvedValue(payload('cs', false))
    const wrapper = mount(ApprovalPublic, {
      global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: { workReportDisplay: { amount: 'Částka' } } } })] },
    })
    await flushPromises()
    expect(wrapper.findAll('th').map(cell => cell.text())).not.toContain('Částka')
    expect(wrapper.text()).not.toContain('1 000')
    expect(wrapper.findAll('thead tr th')).toHaveLength(2)
    wrapper.unmount()
  })
})
