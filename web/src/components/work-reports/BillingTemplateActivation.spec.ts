import { defineComponent, h, KeepAlive, nextTick, ref, type Component } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BillingTemplateSelect from './BillingTemplateSelect.vue'
import WorkReportBillingTemplates from '@/components/settings/WorkReportBillingTemplates.vue'
import { newBillingSnapshot, workReportBillingApi, type WorkReportBillingTemplate } from '@/api/workReportBilling'
vi.mock('@/api/workReportBilling', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/workReportBilling')>(),
  workReportBillingApi: { list: vi.fn() },
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
function template(name: string): WorkReportBillingTemplate { return { ...newBillingSnapshot(), id: 7, name } }
function cached(component: Component) {
  const visible = ref(true)
  const host = defineComponent({ setup: () => () => h(KeepAlive, null, () => visible.value ? h(component, { modelValue: 7 }) : null) })
  const wrapper = mount(host, { global: { stubs: { BillingSnapshotEditor: true } } })
  return { wrapper, async activate() { visible.value = false; await nextTick(); visible.value = true; await nextTick() } }
}
beforeEach(() => { vi.mocked(workReportBillingApi.list).mockReset() })
describe.each([['výběr šablony', BillingTemplateSelect], ['správa šablon', WorkReportBillingTemplates]] as const)('KeepAlive: %s', (_name, component) => {
  it('na prvním otevření načte jednou a po návratu obnoví seznam', async () => {
    vi.mocked(workReportBillingApi.list).mockResolvedValueOnce([template('Původní')]).mockResolvedValueOnce([template('Aktuální')])
    const { wrapper, activate } = cached(component)
    await flushPromises()
    expect(workReportBillingApi.list).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Původní')
    await activate()
    await flushPromises()
    expect(workReportBillingApi.list).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Aktuální')
    const selector = wrapper.findComponent(BillingTemplateSelect)
    if (selector.exists()) {
      expect(selector.props('modelValue')).toBe(7)
      expect(selector.emitted('selected')).toBeUndefined()
      expect(selector.emitted('update:modelValue')).toBeUndefined()
    }
    wrapper.unmount()
  })
  it('opožděná odpověď z předchozí aktivace nepřepíše nový seznam', async () => {
    let resolveOld!: (templates: WorkReportBillingTemplate[]) => void
    vi.mocked(workReportBillingApi.list).mockResolvedValueOnce([template('První')]).mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve })).mockResolvedValueOnce([template('Nejnovější')])
    const { wrapper, activate } = cached(component)
    await flushPromises()
    await activate()
    await activate()
    await flushPromises()
    resolveOld([template('Opožděná')])
    await flushPromises()
    expect(wrapper.text()).toContain('Nejnovější')
    expect(wrapper.text()).not.toContain('Opožděná')
    wrapper.unmount()
  })
})
