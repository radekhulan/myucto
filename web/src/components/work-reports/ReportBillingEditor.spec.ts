import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import ReportBillingEditor from './ReportBillingEditor.vue'
import { newBillingSnapshot, workReportBillingApi, type WorkReportBillingPreview } from '@/api/workReportBilling'

vi.mock('@/api/workReportBilling', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/workReportBilling')>(),
  workReportBillingApi: { previewDraft: vi.fn() },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
const template = { ...newBillingSnapshot(), id: 7, name: 'Servis', fixed_fee: 5000, included_hours: 5 }
const preview: WorkReportBillingPreview = { items: [], total_hours: 0, included_hours: 0, extra_hours: 0, total_amount: 5000, fingerprint: 'canonical-fingerprint' }
function mountEditor() {
  return mount(ReportBillingEditor, {
    props: { snapshot: { ...template }, templateId: 7, period: '2096-03', currency: 'CZK', title: 'Výkaz', items: [], vatRateId: 1 },
    global: { stubs: { BillingTemplateSelect: defineComponent({ emits: ['selected'], template: '<button data-select @click="$emit(\'selected\', selected)">select</button>', setup: () => ({ selected: template }) }), BillingSnapshotEditor: true, BillingRecap: true } },
  })
}
beforeEach(() => { vi.mocked(workReportBillingApi.previewDraft).mockReset(); vi.mocked(workReportBillingApi.previewDraft).mockResolvedValue(preview) })
describe('ReportBillingEditor', () => {
  it('náhled nulových hodin používá server; samotný náhled nepožádá o přenos', async () => {
    const wrapper = mountEditor()
    await wrapper.findAll('button').find(button => button.text() === 'work_report_billing.preview')!.trigger('click')
    await flushPromises()
    expect(workReportBillingApi.previewDraft).toHaveBeenCalledWith(expect.objectContaining({ items: [], billing_period: '2096-03', billing_snapshot: expect.objectContaining({ fixed_fee: 5000 }) }))
    expect(wrapper.emitted('transfer')).toBeUndefined()
    expect(wrapper.findComponent({ name: 'BillingRecap' }).exists()).toBe(true)
  })
  it('přenos předá přesně kanonický náhled až po výslovném kliknutí', async () => {
    const wrapper = mountEditor()
    expect(workReportBillingApi.previewDraft).not.toHaveBeenCalled()
    await wrapper.findAll('button').find(button => button.text() === 'invoice.wr_push_to_item')!.trigger('click')
    await flushPromises()
    expect(wrapper.emitted('transfer')).toEqual([[preview]])
  })
  it('výběr zkopíruje šablonu a nezmění její sdílená pásma', async () => {
    const wrapper = mountEditor()
    await wrapper.find('[data-select]').trigger('click')
    const copy = wrapper.emitted('update:snapshot')![0]![0] as typeof template
    expect(copy).toEqual(template)
    expect(copy).not.toBe(template)
    expect(copy.bands).not.toBe(template.bands)
  })
  it('změna hodin zneplatní rozpracovaný náhled i čekající přenos', async () => {
    const wrapper = mountEditor()
    let resolve!: (value: WorkReportBillingPreview) => void
    vi.mocked(workReportBillingApi.previewDraft).mockReturnValueOnce(new Promise(done => { resolve = done }))
    await wrapper.findAll('button').find(button => button.text() === 'invoice.wr_push_to_item')!.trigger('click')
    await wrapper.setProps({ items: [{ description: 'Analýza', hours: 2, rate: 0, order_index: 0 }] })
    resolve(preview)
    await flushPromises()
    expect(wrapper.emitted('changed')).toBeDefined()
    expect(wrapper.emitted('transfer')).toBeUndefined()
  })
})
