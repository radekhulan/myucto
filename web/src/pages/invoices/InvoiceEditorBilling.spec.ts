import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { WorkReportBillingPreview } from '@/api/workReportBilling'

vi.mock('@/api/codebooks', () => ({ codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: { plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })], directives: { math: {} } },
  })
  return { wrapper, vm: wrapper.vm as unknown as { form: { items: unknown[] }; hasNonPositiveAmountToPay: boolean; wrBillingPending: boolean; queueWrBilling: (preview: WorkReportBillingPreview) => void } }
}

describe('InvoiceEditor účtování výkazu', () => {
  it('kladný kanonický přenos umožní uložit nový koncept před vznikem fakturačních řádků', async () => {
    const { vm, wrapper } = await mountEditor()
    vm.form.items = []
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    vm.queueWrBilling({ total_amount: 5000 } as WorkReportBillingPreview)
    expect(vm.hasNonPositiveAmountToPay).toBe(false)
    expect(vm.form.items).toEqual([])
    vm.wrBillingPending = false
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
  it('nulový přenos neobchází kontrolu kladné částky', async () => {
    const { vm, wrapper } = await mountEditor()
    vm.form.items = []
    vm.queueWrBilling({ total_amount: 0 } as WorkReportBillingPreview)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
})
