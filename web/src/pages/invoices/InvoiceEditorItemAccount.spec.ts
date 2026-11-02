import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { computed, nextTick, ref } from 'vue'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/composables/useResultAccounts', () => ({
  useResultAccounts: () => ({
    available: computed(() => true),
    issuedItemAccounts: ref([]),
    load: () => Promise.resolve(),
  }),
}))

import { useSupplierStore } from '@/stores/supplier'
import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor(accountingMode: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useSupplierStore()
  store.availableSuppliers = [{ id: 1, accounting_mode: accountingMode } as never]
  store.currentSupplierId = 1
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }],
  })
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
    hydrateAssetSelections: () => void
  }
  // Číselníky se v testu nedořeší, formulář se ukáže ručně.
  vm.loaded = true
  return { wrapper, vm }
}

const item = (extra: Record<string, unknown> = {}) => ({ description: 'Licence', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 0, ...extra })

describe('InvoiceEditor účet a členění DPH položky', () => {
  it('šipka otevře a zavře účet i členění DPH jen u své položky', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    vm.form.items = [item(), item({ description: 'Servis' })]
    await nextTick()
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-test="invoice-item-vat-classification"]')).toHaveLength(0)

    const toggles = wrapper.findAll('[data-test="item-details-toggle"]')
    expect(toggles).toHaveLength(2)
    await toggles[0].trigger('click')
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="invoice-item-vat-classification"]')).toHaveLength(1)
    expect(toggles[0].attributes('aria-expanded')).toBe('true')
    expect(toggles[1].attributes('aria-expanded')).toBe('false')

    await toggles[0].trigger('click')
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-test="invoice-item-vat-classification"]')).toHaveLength(0)
    wrapper.unmount()
  })

  it('volba členění se pošle, odvozený kód po změně sazby zanikne', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    const editor = vm as unknown as {
      itemVatPayload: (it: unknown) => Record<string, unknown>
      setItemVatCode: (it: unknown, value: string) => void
    }
    vm.form.items = [item({ vat_rate_id: 1, vat_classification_code: '1' }), item({ description: 'Nová' })]
    vm.hydrateAssetSelections()
    const [loaded, fresh] = vm.form.items

    expect(editor.itemVatPayload(loaded)).toEqual({ vat_classification_code: '1' })
    expect(editor.itemVatPayload(fresh)).toEqual({})

    loaded.vat_rate_id = 2
    expect(editor.itemVatPayload(loaded)).toEqual({})

    editor.setItemVatCode(fresh, '20')
    expect(editor.itemVatPayload(fresh)).toEqual({ vat_classification_code: '20' })
    editor.setItemVatCode(fresh, '')
    expect(editor.itemVatPayload(fresh)).toEqual({ vat_classification_code: null })
    wrapper.unmount()
  })

  it('položka s účtem se po načtení otevře sama, ostatní zůstanou zavřené', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    vm.form.items = [item({ revenue_account_code: '604' }), item()]
    vm.hydrateAssetSelections()
    await nextTick()
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('prodej majetku a daňová evidence mají pod šipkou jen členění DPH', async () => {
    for (const { mode, row } of [
      { mode: 'double_entry', row: item({ asset_id: 5 }) },
      { mode: 'tax_records', row: item() },
    ]) {
      const { wrapper, vm } = await mountEditor(mode)
      vm.form.items = [row]
      await nextTick()
      await wrapper.get('[data-test="item-details-toggle"]').trigger('click')
      expect(wrapper.find('[data-test="invoice-item-account"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="invoice-item-vat-classification"]').exists()).toBe(true)
      wrapper.unmount()
    }
  })

  it('neplátce v daňové evidenci šipku nemá, OSS položka členění nenabízí', async () => {
    const { wrapper, vm } = await mountEditor('tax_records')
    useSupplierStore().availableSuppliers = [{ id: 1, accounting_mode: 'tax_records', is_vat_payer: false } as never]
    vm.form.items = [item()]
    await nextTick()
    expect(wrapper.find('[data-test="item-details-toggle"]').exists()).toBe(false)
    wrapper.unmount()

    const oss = await mountEditor('double_entry')
    oss.vm.form.items = [item({ oss_applicable: true })]
    await nextTick()
    await oss.wrapper.get('[data-test="item-details-toggle"]').trigger('click')
    expect(oss.wrapper.find('[data-test="invoice-item-account"]').exists()).toBe(true)
    expect(oss.wrapper.find('[data-test="invoice-item-vat-classification"]').exists()).toBe(false)
    oss.wrapper.unmount()
  })
})
