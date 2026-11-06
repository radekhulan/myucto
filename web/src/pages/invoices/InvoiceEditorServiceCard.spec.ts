import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))

import { useSupplierStore } from '@/stores/supplier'
import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useSupplierStore()
  store.availableSuppliers = [{ id: 1, accounting_mode: 'tax_records', stock_enabled: true } as never]
  store.currentSupplierId = 1
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as {
    loaded: boolean
    form: { items: Array<Record<string, unknown>> }
    stockItemsCache: Map<number, { item_type?: string }>
    rowIsService: (item: Record<string, unknown>) => boolean
    rowAvailability: (item: Record<string, unknown>) => string | null
    availabilityMap: Record<string, string>
  }
  vm.loaded = true
  return { wrapper, vm }
}

describe('InvoiceEditor karta typu služba', () => {
  it('řádek se službou nemá dostupnost, zboží ji má dál', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.stockItemsCache.set(1, { item_type: 'service' })
    vm.stockItemsCache.set(2, { item_type: 'goods' })
    vm.availabilityMap = { 'all:1': '0.000', 'all:2': '5.000' }
    const service = { description: 'Konzultace', stock_item_id: 1 }
    const goods = { description: 'Šroub', stock_item_id: 2 }
    vm.form.items = [service, goods]
    await nextTick()

    expect(vm.rowIsService(service)).toBe(true)
    expect(vm.rowAvailability(service)).toBeNull()
    expect(vm.rowIsService(goods)).toBe(false)
    expect(vm.rowAvailability(goods)).toBe('5.000')
    wrapper.unmount()
  })
})
