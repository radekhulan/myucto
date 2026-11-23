import { config, mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import Page from '@/pages/admin/WorkReportBillingTemplates.vue'
import Manager from './WorkReportBillingTemplates.vue'
import { newBillingSnapshot, workReportBillingApi, type WorkReportBillingTemplate } from '@/api/workReportBilling'
const state = vi.hoisted(() => ({ canWrite: false, stock: false }))
config.global.stubs = { RouterLink: { props: ['to'], template: '<a data-test="price-list-link"><slot /></a>' } }
vi.mock('@/api/workReportBilling', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/workReportBilling')>(),
  workReportBillingApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn() },
}))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'EUR', is_default: true }, { id: 2, code: 'CZK' }]) } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { default_hourly_rate: 2400, stock_enabled: state.stock } }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: (permission: string) => permission === 'price_list', canWrite: (permission: string) => permission === 'price_list' && state.canWrite }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
const template: WorkReportBillingTemplate = { ...newBillingSnapshot(), id: 7, name: 'Servis', fixed_fee: 5000 }
beforeEach(() => {
  state.canWrite = false
  state.stock = false
  vi.mocked(workReportBillingApi.list).mockReset().mockResolvedValue([template])
  vi.mocked(workReportBillingApi.create).mockReset().mockResolvedValue(template)
  vi.mocked(workReportBillingApi.update).mockReset().mockResolvedValue(template)
  vi.mocked(workReportBillingApi.delete).mockReset()
})
describe('stránka šablon účtování: oprávnění Ceníku', () => {
  it('čtecí právo dovolí prohlédnout pravidla bez tlačítek a metod pro zápis', async () => {
    const wrapper = mount(Page)
    await flushPromises()
    expect(wrapper.find('h1').text()).toBe('work_report_billing.templates')
    expect(wrapper.text()).not.toContain('work_report_billing.add_template')
    expect(wrapper.text()).not.toContain('common.delete')
    await wrapper.findAll('button').find(button => button.text() === 'common.view')!.trigger('click')
    expect(wrapper.find('fieldset[disabled]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('common.save')
    expect(wrapper.text()).not.toContain('work_report_billing.add_band')
    const manager = wrapper.findComponent(Manager).vm as unknown as { save: () => Promise<void>; remove: (template: WorkReportBillingTemplate) => Promise<void> }
    await manager.save()
    await manager.remove(template)
    expect(workReportBillingApi.create).not.toHaveBeenCalled()
    expect(workReportBillingApi.update).not.toHaveBeenCalled()
    expect(workReportBillingApi.delete).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('zápisové právo dovolí upravit a uložit firemní kopii šablony', async () => {
    state.canWrite = true
    const wrapper = mount(Page)
    await flushPromises()
    expect(wrapper.text()).toContain('work_report_billing.add_template')
    await wrapper.findAll('button').find(button => button.text() === 'common.edit')!.trigger('click')
    expect(wrapper.find('fieldset').attributes('disabled')).toBeUndefined()
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(workReportBillingApi.update).toHaveBeenCalledWith(7, expect.objectContaining({ name: 'Servis', fixed_fee: 5000 }))
    expect(template.name).toBe('Servis')
    wrapper.unmount()
  })
  it('nová šablona používá výchozí měnu firmy i mimo Nastavení', async () => {
    state.canWrite = true
    const wrapper = mount(Page)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'work_report_billing.add_template')!.trigger('click')
    const manager = wrapper.findComponent(Manager).vm as unknown as { editing: { currency: string } }
    expect(manager.editing.currency).toBe('EUR')
    const currency = wrapper.get('[data-test="billing-currency"]')
    expect(currency.element.tagName).toBe('SELECT')
    expect(currency.findAll('option').map(option => option.text())).toEqual(['EUR', 'CZK'])
    await currency.setValue('CZK')
    expect(manager.editing.currency).toBe('CZK')
    expect((manager.editing as unknown as { bands: { rate: number }[] }).bands[0]?.rate).toBe(2400)
    wrapper.unmount()
  })
  it('ukáže načítání, prázdný stav a odkaz zpět na Ceník jen firmě bez skladu', async () => {
    let resolve!: (value: WorkReportBillingTemplate[]) => void
    vi.mocked(workReportBillingApi.list).mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = mount(Page)
    await flushPromises()
    expect(wrapper.text()).toContain('common.loading')
    expect(wrapper.text()).not.toContain('work_report_billing.empty')
    expect(wrapper.find('[data-test="price-list-link"]').text()).toContain('price_list.back_to_list')
    resolve([])
    await flushPromises()
    expect(wrapper.text()).not.toContain('common.loading')
    expect(wrapper.text()).toContain('work_report_billing.empty')
    wrapper.unmount()
    state.stock = true
    vi.mocked(workReportBillingApi.list).mockResolvedValue([template])
    const stocked = mount(Page)
    await flushPromises()
    expect(stocked.find('[data-test="price-list-link"]').exists()).toBe(false)
    expect(stocked.text()).not.toContain('work_report_billing.empty')
    stocked.unmount()
  })
})
