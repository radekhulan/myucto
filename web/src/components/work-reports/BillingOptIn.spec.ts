import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import ReportBillingEditor from './ReportBillingEditor.vue'
import BillingTemplateSelect from './BillingTemplateSelect.vue'
import { newBillingSnapshot, workReportBillingApi } from '@/api/workReportBilling'
vi.mock('@/api/workReportBilling', async importOriginal => ({ ...await importOriginal<typeof import('@/api/workReportBilling')>(), workReportBillingApi: { list: vi.fn() } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
const template = { ...newBillingSnapshot(), id: 7, name: 'Syntetický paušál' }
beforeEach(() => { vi.mocked(workReportBillingApi.list).mockReset().mockResolvedValue([]) })
describe('účtování výkazů je volitelné', () => {
  it('bez šablon a snapshotu nepřidá do editoru výkazu žádné ovládání ani rámeček', async () => {
    const wrapper = mount(ReportBillingEditor, { props: { snapshot: null, templateId: null, period: '2096-03', currency: 'CZK', title: 'Běžný výkaz', items: [], vatRateId: 1 } })
    await flushPromises()
    expect(wrapper.text()).toBe('')
    expect(wrapper.find('.border').exists()).toBe(false)
    wrapper.unmount()
  })
  it('formulář zakázky bez šablon neukáže nový výběr', async () => {
    const wrapper = mount(BillingTemplateSelect, { props: { modelValue: null } })
    await flushPromises()
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
    wrapper.unmount()
  })
  it('uložený snapshot zůstane dostupný i po odstranění všech firemních šablon', async () => {
    const wrapper = mount(ReportBillingEditor, { props: { snapshot: template, templateId: 7, period: '2096-03', currency: 'CZK', title: 'Výkaz s kopií', items: [], vatRateId: 1 } })
    await flushPromises()
    expect(wrapper.find('select').exists()).toBe(true)
    expect(wrapper.text()).toContain('work_report_billing.saved_copy')
    expect(wrapper.text()).toContain('work_report_billing.override')
    wrapper.unmount()
  })
  it('firemní šablony výběr zpřístupní i pro report bez snapshotu', async () => {
    vi.mocked(workReportBillingApi.list).mockResolvedValueOnce([template])
    const wrapper = mount(ReportBillingEditor, { props: { snapshot: null, templateId: null, period: '2096-03', currency: 'CZK', title: 'Výkaz', items: [], vatRateId: 1 } })
    await flushPromises()
    expect(wrapper.text()).toContain(template.name)
    expect(workReportBillingApi.list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
