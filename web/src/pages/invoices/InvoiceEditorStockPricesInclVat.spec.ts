import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import type { StockQuoteRequest } from '@/api/stock'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

const pending = () => new Promise(() => {})
// Nacenění vrací cenu vždy bez DPH.
const quoteItems = vi.fn((payload: StockQuoteRequest) => Promise.resolve({
  lines: payload.lines.map(line => ({
    key: line.key, stock_item_id: line.stock_item_id, unit: 'ks', base_unit: 'ks', numerator: 1, denominator: 1,
    base_quantity: '1.000', unit_price: '900.00', base_unit_price: '900.00', list_unit_price: '1000.00',
    price_source: 'price_level_discount', customer_price_id: null, discount_pct: '10.000', promo: null,
  })),
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/api/stock', () => ({
  stockApi: new Proxy({}, { get: (_target, key) => (key === 'quoteItems' ? quoteItems : pending) }),
}))
vi.mock('@/api/eshop', () => ({ eshopApi: new Proxy({}, { get: () => pending }) }))

import InvoiceEditor from './InvoiceEditor.vue'

type Row = { stock_item_id: number | null; unit: string; quantity: number; unit_price_without_vat: number; list_unit_price?: number | null; vat_rate_id: number; description: string }
type Vm = {
  form: { currency: string; prices_include_vat: boolean; reverse_charge: boolean; items: Row[] }
  vatRates: { id: number; rate_percent: number }[]
  stockItemsCache: Map<number, unknown>
  quoteStockRows: (rows: Row[], mode: 'select' | 'unit_change' | 'context') => Promise<boolean>
  onStockSelect: (rowIndex: number, itemId: number | null) => void
  onStockUnitSelect: (row: Row, unit: string) => void
  rememberPackaging: (card: { id: number; unit: string; units: { unit_code: string; numerator: number; denominator: number }[] }) => void
}

async function mountEditor(pricesIncludeVat: boolean) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSupplierStore().setAvailable([{ id: 1, is_vat_payer: true, stock_enabled: true } as SupplierBrief], 1, true)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: { invoice: { promo_price_applied: 'akce {price}' } } }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  vm.form.currency = 'CZK'
  vm.form.prices_include_vat = pricesIncludeVat
  vm.vatRates = [{ id: 1, rate_percent: 21 }, { id: 2, rate_percent: 12 }]
  vm.form.items = [{ stock_item_id: 7, unit: 'ks', quantity: 1, unit_price_without_vat: 0, list_unit_price: null, vat_rate_id: 2, description: 'Helma' }]
  vm.stockItemsCache.set(7, {
    id: 7, sku: 'H-1', name: 'Helma', unit: 'ks', vat_rate_id: 1,
    sale_price_without_vat: '1000.00', effective_price: '1000.00', promo_price: null, units: [], has_customer_prices: false,
  })
  await wrapper.vm.$nextTick()
  return { wrapper, vm }
}

describe('InvoiceEditor: skladová cena na dokladu s cenami včetně DPH', () => {
  it('změna balení zápornou cenu pouze přepočte, cenu karty ani kontext nepřevezme', async () => {
    const { wrapper, vm } = await mountEditor(true)
    try {
      const row = vm.form.items[0]
      row.unit_price_without_vat = -1210
      row.list_unit_price = 1210
      vm.rememberPackaging({ id: 7, unit: 'ks', units: [{ unit_code: 'KT', numerator: 8, denominator: 1 }] })
      const previousCalls = quoteItems.mock.calls.length
      vm.onStockUnitSelect(row, 'KT')
      await new Promise(resolve => setTimeout(resolve, 0))
      expect([row.unit, row.unit_price_without_vat, row.list_unit_price]).toEqual(['KT', -9680, 9680])
      vm.onStockUnitSelect(row, 'ks')
      expect([row.unit, row.unit_price_without_vat, row.list_unit_price]).toEqual(['ks', -1210, 1210])
      await vm.quoteStockRows([row], 'context')
      expect(row.unit_price_without_vat).toBe(-1210)
      expect(quoteItems.mock.calls.length).toBe(previousCalls)
    } finally {
      wrapper.unmount()
    }
  })

  it('změna balení dobropisu se záporným množstvím kladnou cenu dál nacení', async () => {
    const { wrapper, vm } = await mountEditor(false)
    try {
      const row = vm.form.items[0]
      row.quantity = -1
      row.unit_price_without_vat = 1000
      vm.rememberPackaging({ id: 7, unit: 'ks', units: [{ unit_code: 'KT', numerator: 8, denominator: 1 }] })
      const previousCalls = quoteItems.mock.calls.length
      vm.onStockUnitSelect(row, 'KT')
      await new Promise(resolve => setTimeout(resolve, 0))
      expect([row.unit, row.quantity, row.unit_price_without_vat]).toEqual(['KT', -1, 900])
      expect(quoteItems.mock.calls.length).toBe(previousCalls + 1)
    } finally {
      wrapper.unmount()
    }
  })

  it('výběr karty vloží cenu s DPH podle sazby karty', async () => {
    const { wrapper, vm } = await mountEditor(true)
    vm.onStockSelect(0, 7)
    const row = vm.form.items[0]
    expect(row.vat_rate_id).toBe(1)
    expect(row.unit_price_without_vat).toBe(1210)
    expect(row.list_unit_price).toBe(1210)
    wrapper.unmount()
  })

  it('nacenění vloží cenu i ceník s DPH, přenesená daň bez DPH', async () => {
    const { wrapper, vm } = await mountEditor(true)
    const row = vm.form.items[0]
    row.vat_rate_id = 1
    expect(await vm.quoteStockRows([row], 'context')).toBe(true)
    expect([row.unit_price_without_vat, row.list_unit_price]).toEqual([1089, 1210])

    vm.form.reverse_charge = true
    row.unit_price_without_vat = 0
    expect(await vm.quoteStockRows([row], 'context')).toBe(true)
    expect([row.unit_price_without_vat, row.list_unit_price]).toEqual([900, 1000])
    wrapper.unmount()
  })

  it('doklad s cenami bez DPH zůstává beze změny', async () => {
    const { wrapper, vm } = await mountEditor(false)
    vm.onStockSelect(0, 7)
    expect(vm.form.items[0].unit_price_without_vat).toBe(1000)
    expect(await vm.quoteStockRows([vm.form.items[0]], 'context')).toBe(true)
    expect([vm.form.items[0].unit_price_without_vat, vm.form.items[0].list_unit_price]).toEqual([900, 1000])
    wrapper.unmount()
  })

  it('toast akční ceny ukáže cenu, kterou řádek dostal (s DPH)', async () => {
    const { wrapper, vm } = await mountEditor(true)
    vm.stockItemsCache.set(7, {
      id: 7, sku: 'H-1', name: 'Helma', unit: 'ks', vat_rate_id: 1, promo_label: 'Výprodej',
      sale_price_without_vat: '1000.00', effective_price: '800.00', promo_price: '800.00', units: [], has_customer_prices: false,
    })
    const { toasts } = useToast()
    toasts.value = []
    vm.onStockSelect(0, 7)
    expect(vm.form.items[0].unit_price_without_vat).toBe(968)
    const text = toasts.value.map(toast => toast.text).join(' ').replace(/\s/g, ' ')
    expect(text).toMatch(/akce 968,00/)
    wrapper.unmount()
  })
})
