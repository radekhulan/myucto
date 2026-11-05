import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import ReportBillingEditor from './ReportBillingEditor.vue'
import { newBillingSnapshot, workReportBillingApi, type WorkReportBillingPreview } from '@/api/workReportBilling'

vi.mock('@/api/workReportBilling', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/workReportBilling')>(),
  workReportBillingApi: { previewDraft: vi.fn(), list: vi.fn().mockResolvedValue([]) },
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string, params?: { count?: number }) => params?.count == null ? key : `${key}:${params.count}`, locale: ref('cs') }) }))
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
    await wrapper.findAll('button').find(button => button.attributes('data-test') === 'billing-prepare-transfer')!.trigger('click')
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
    expect(wrapper.emitted('modeSelected')).toHaveLength(1)
  })
  it('změna hodin zneplatní rozpracovaný náhled i čekající přenos', async () => {
    const wrapper = mountEditor()
    let resolve!: (value: WorkReportBillingPreview) => void
    vi.mocked(workReportBillingApi.previewDraft).mockReturnValueOnce(new Promise(done => { resolve = done }))
    await wrapper.findAll('button').find(button => button.attributes('data-test') === 'billing-prepare-transfer')!.trigger('click')
    await wrapper.setProps({ items: [{ description: 'Analýza', hours: 2, rate: 0, order_index: 0 }] })
    resolve(preview)
    await flushPromises()
    expect(wrapper.emitted('changed')).toBeDefined()
    expect(wrapper.emitted('transfer')).toBeUndefined()
  })
})


it('příprava ukáže počet kanonických řádků a jasně vyžaduje uložení faktury', async () => {
  const wrapper = mountEditor()
  const rows = [
    { description: 'Paušál', quantity: 1, unit: 'ks', unit_price_without_vat: 5000, vat_rate_id: 1, billing_key: 'fixed', total_amount: 5000 },
    { description: 'Hodiny navíc', quantity: 2, unit: 'h', unit_price_without_vat: 1500, vat_rate_id: 1, billing_key: 'band:0', total_amount: 3000 },
  ]
  vi.mocked(workReportBillingApi.previewDraft).mockResolvedValueOnce({ ...preview, items: rows, total_hours: 7, extra_hours: 2, total_amount: 8000 })
  expect(wrapper.get('[data-test="billing-prepare-transfer"]').text()).toBe('work_report_billing.prepare_transfer')
  await wrapper.get('[data-test="billing-prepare-transfer"]').trigger('click')
  await flushPromises()
  await wrapper.setProps({ pending: true })
  expect(wrapper.get('[data-test="billing-prepare-transfer"]').text()).toBe('work_report_billing.ready_to_save')
  expect(wrapper.get('[role="status"]').text()).toBe('work_report_billing.pending:2')
  expect(wrapper.findComponent({ name: 'BillingRecap' }).props('preview').items).toHaveLength(2)
  expect(wrapper.emitted('transfer')).toHaveLength(1)
  wrapper.unmount()
})


it('období nabízí názvy měsíců a stále odesílá YYYY-MM', async () => {
  const wrapper = mountEditor()
  const months = wrapper.get('[data-test="billing-period-month"]')
  expect(months.text()).toContain('březen')
  expect(months.text()).toContain('prosinec')
  await months.setValue('12')
  expect(wrapper.emitted('update:period')).toEqual([['2096-12']])
  wrapper.unmount()
})
