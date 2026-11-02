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

  // BEZ OPRAVY PADÁ: výchozí výkaz dostal nový sloupec „Částka" u řádků, součet se posunul
  // a sloupec materiálu se přejmenoval z „Celkem".
  it.each([
    ['bez voleb', undefined],
    ['s výchozími volbami', { compact: false, date: true, hours: true, rate: true, amount: true }],
  ])('výkaz %s vypadá jako před zavedením voleb', async (_label, options) => {
    mocks.get.mockResolvedValue({
      state: 'requested', supplier_name: 'Syntetický dodavatel', captcha_provider: 'none',
      invoice: { id: 1, language: 'cs', currency: 'CZK' },
      work_report: {
        title: 'Syntetický výkaz', total_hours: 1.5, total_amount: 1500,
        ...(options ? { effective_display_options: options } : {}),
        items: [{ id: 1, description: 'Analýza', work_date: '2096-03-02', hours: 1.5, rate: 1000, total_amount: 1500 }],
        material_title: null, material_total: 300,
        materials: [{ id: 1, description: 'Kabel', quantity: 2, unit: 'ks', unit_price: 150, total_amount: 300 }],
      },
    })
    const wrapper = mount(ApprovalPublic, {
      global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: { workReportDisplay: { amount: 'Částka' } } } })] },
    })
    await flushPromises()
    const [work, material] = wrapper.findAll('table')
    expect(work.classes()).not.toContain('wr-compact')
    expect(work.findAll('thead th').map(cell => cell.text())).toEqual(['Popis', 'Datum', 'Hodin', 'Sazba'])
    expect(work.findAll('tbody tr')[0].findAll('td')).toHaveLength(4)
    const total = work.findAll('tbody tr')[1].findAll('td')
    expect(total.map(cell => cell.text().replace(/\s/g, ' '))).toEqual(['Celkem', '1,50 h', '1 500,00 CZK'])
    expect(total[0].attributes('colspan')).toBe('2')
    expect(material.findAll('thead th').map(cell => cell.text())).toEqual(['Popis', 'Množství', 'MJ', 'Cena/MJ', 'Celkem'])
    wrapper.unmount()
  })

  it('upravený výkaz se sloupcem částky drží součet pod sloupci', async () => {
    mocks.get.mockResolvedValue({
      ...payload('cs'),
      work_report: {
        ...payload('cs').work_report,
        effective_display_options: { compact: true, date: true, hours: true, rate: true, amount: true },
        items: [{ id: 1, description: 'Analýza', work_date: null, hours: 1, rate: 1000, total_amount: 1000 }],
      },
    })
    const wrapper = mount(ApprovalPublic, {
      global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: { workReportDisplay: { amount: 'Částka' } } } })] },
    })
    await flushPromises()
    const work = wrapper.find('table')
    expect(work.findAll('thead th').map(cell => cell.text())).toEqual(['Popis', 'Hodin', 'Sazba', 'Částka'])
    expect(work.findAll('tbody tr')[1].findAll('td')).toHaveLength(4)
    wrapper.unmount()
  })
})
