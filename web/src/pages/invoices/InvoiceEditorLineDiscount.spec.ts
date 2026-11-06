import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import type { StockQuoteRequest } from '@/api/stock'
import type { InvoicePayload } from '@/api/invoices'
import { useSupplierStore } from '@/stores/supplier'

const pending = () => new Promise(() => {})
const createInvoice = vi.fn((_payload: InvoicePayload) => pending())
const quoteItems = vi.fn((payload: StockQuoteRequest) => Promise.resolve({
  lines: payload.lines.map(line => ({
    key: line.key, stock_item_id: line.stock_item_id, unit: 'ks', base_unit: 'ks', numerator: 1, denominator: 1,
    base_quantity: '2.000', unit_price: '900.00', base_unit_price: '900.00', list_unit_price: '1000.00',
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
vi.mock('@/api/invoices', () => ({ invoicesApi: new Proxy({}, { get: (_target, key) => key === 'create' ? createInvoice : pending }) }))

import InvoiceEditor from './InvoiceEditor.vue'

type Row = { stock_item_id: number | null; unit: string; quantity: number; unit_price_without_vat: number; list_unit_price?: number | null }
type Vm = {
  form: { currency: string; items: Row[] }
  quoteStockRows: (rows: Row[], mode: 'select' | 'unit_change' | 'context') => Promise<boolean>
  onStockSelect: (rowIndex: number, itemId: number | null) => void
  lineDiscountHint: (row: Row) => string | null
  setLineDiscount: (row: Row, raw: string) => void
  loaded: boolean
  error: string
}

async function mountEditor(showLineDiscount = false) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSupplierStore().setAvailable([{ id: 1, is_vat_payer: true, stock_enabled: true, invoice_pdf_show_line_discount: showLineDiscount } as SupplierBrief], 1, true)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  vm.form.currency = 'CZK'
  vm.form.items = [{ stock_item_id: 7, unit: 'ks', quantity: 2, unit_price_without_vat: 1000, list_unit_price: null }]
  await wrapper.vm.$nextTick()
  return { wrapper, vm }
}

describe('InvoiceEditor: ceníková cena řádku', () => {
  it('nacenění uloží ceníkovou cenu, ruční změna ceny ji zachová', async () => {
    const { wrapper, vm } = await mountEditor()
    const row = vm.form.items[0]

    expect(await vm.quoteStockRows([row], 'context')).toBe(true)
    expect(row.unit_price_without_vat).toBe(900)
    expect(row.list_unit_price).toBe(1000)
    expect(vm.lineDiscountHint(row)).toBe('invoice.line_discount.hint_discount')

    row.unit_price_without_vat = 1050
    expect(row.list_unit_price).toBe(1000)
    expect(vm.lineDiscountHint(row)).toBe('invoice.line_discount.hint_markup')

    row.unit_price_without_vat = 1000
    expect(vm.lineDiscountHint(row)).toBeNull()
    wrapper.unmount()
  })

  it('odebrání karty ceníkovou cenu zruší', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.form.items[0].list_unit_price = 1000
    vm.onStockSelect(0, null)
    expect(vm.form.items[0].list_unit_price).toBeNull()
    wrapper.unmount()
  })
})

describe('InvoiceEditor: sleva v % zadaná u řádku', () => {
  it.each([0, 1])('Enter bez change a blur odešle rozepsanou slevu (pole %s)', async (index) => {
    createInvoice.mockClear()
    const { wrapper, vm } = await mountEditor(true)
    try {
      vm.loaded = true
      const row = vm.form.items[0]
      Object.assign(row, { description: 'Servis', vat_rate_id: 1 })
      await wrapper.vm.$nextTick()
      const field = wrapper.findAll('[data-test="line-discount-input"]')[index]
      ;(field.element as HTMLInputElement).value = '10'
      await field.trigger('input')
      expect(row.unit_price_without_vat).toBe(1000)
      await field.trigger('keydown', { key: 'Enter' })
      await wrapper.find('form').trigger('submit')
      expect(createInvoice).toHaveBeenCalledTimes(1)
      expect(createInvoice.mock.calls[0][0].items[0]).toMatchObject({ unit_price_without_vat: 900, list_unit_price: 1000 })
    } finally {
      wrapper.unmount()
    }
  })

  it('rozepsaná stoprocentní sleva se použije před kontrolou kladné částky', async () => {
    createInvoice.mockClear()
    const { wrapper, vm } = await mountEditor(true)
    try {
      vm.loaded = true
      Object.assign(vm.form.items[0], { description: 'Servis', vat_rate_id: 1 })
      await wrapper.vm.$nextTick()
      const field = wrapper.find('[data-test="line-discount-input"]')
      ;(field.element as HTMLInputElement).value = '100'
      await field.trigger('input')
      await wrapper.find('form').trigger('submit')
      expect(vm.form.items[0].unit_price_without_vat).toBe(0)
      expect(vm.error).toBe('invoice.amount_positive_required')
      expect(createInvoice).not.toHaveBeenCalled()
    } finally {
      wrapper.unmount()
    }
  })

  it('odeslání použije všechny rozepsané slevy a neplatný nebo záporný řádek nezmění', async () => {
    createInvoice.mockClear()
    const { wrapper, vm } = await mountEditor(true)
    try {
      vm.loaded = true
      const row = vm.form.items[0]
      Object.assign(row, { description: 'Servis', vat_rate_id: 1 })
      vm.form.items = [
        { ...row, quantity: 1 },
        { ...row, quantity: 1, stock_item_id: null, unit_price_without_vat: 500 },
        { ...row, quantity: 1, stock_item_id: null, unit_price_without_vat: 100, list_unit_price: 100 },
      ]
      await wrapper.vm.$nextTick()
      const fields = wrapper.findAll('[data-test="line-discount-input"]')
      for (const [index, value] of ['10', 'neplatné', '10'].entries()) {
        ;(fields[index].element as HTMLInputElement).value = value
        await fields[index].trigger('input')
      }
      vm.form.items[2].unit_price_without_vat = -100
      await wrapper.find('form').trigger('submit')
      expect(createInvoice).toHaveBeenCalledTimes(1)
      expect(createInvoice.mock.calls[0][0].items.map(item => 'unit_price_without_vat' in item ? [item.unit_price_without_vat, item.list_unit_price] : null))
        .toEqual([[900, 1000], [500, null], [-100, 100]])
    } finally {
      wrapper.unmount()
    }
  })

  it('záporná cena slevu nepovolí na desktopu ani mobilu, záporné množství ji povolí', async () => {
    const { wrapper, vm } = await mountEditor(true)
    try {
      vm.loaded = true
      const row = vm.form.items[0]
      row.list_unit_price = 1000
      row.unit_price_without_vat = -1000
      await wrapper.vm.$nextTick()
      const inputs = wrapper.findAll('[data-test="line-discount-input"]')
      expect(inputs).toHaveLength(2)
      for (const input of inputs) expect(input.attributes('disabled')).toBeDefined()
      vm.setLineDiscount(row, '10')
      expect(row.unit_price_without_vat).toBe(-1000)
      row.unit_price_without_vat = 1000
      row.quantity = -1
      await wrapper.vm.$nextTick()
      for (const input of inputs) expect(input.attributes('disabled')).toBeUndefined()
      vm.setLineDiscount(row, '10')
      expect([row.quantity, row.unit_price_without_vat]).toEqual([-1, 900])
    } finally {
      wrapper.unmount()
    }
  })

  it('bez volby firmy se pole neukáže', async () => {
    const { wrapper, vm } = await mountEditor(false)
    vm.loaded = true
    await wrapper.vm.$nextTick()
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('[data-test="line-discount-input"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sleva přepočte cenu z ceníkové, ruční cena přepočte zobrazenou slevu', async () => {
    const { wrapper, vm } = await mountEditor(true)
    vm.loaded = true
    const row = vm.form.items[0]
    const input = () => wrapper.find('[data-test="line-discount-input"]').element as HTMLInputElement

    // Řádek bez ceníkové ceny: aktuální cena se stane ceníkovou.
    vm.setLineDiscount(row, '10')
    expect(row.list_unit_price).toBe(1000)
    expect(row.unit_price_without_vat).toBe(900)
    await wrapper.vm.$nextTick()
    expect(input().value).toBe('10')

    row.unit_price_without_vat = 875
    await wrapper.vm.$nextTick()
    expect(input().value).toBe('12,5')
    expect(row.list_unit_price).toBe(1000)

    // Neplatná sleva řádek nemění.
    vm.setLineDiscount(row, '150')
    expect(row.unit_price_without_vat).toBe(875)

    // Skladový řádek nad ceníkovou (přirážka hladiny): sleva se počítá z ceníku karty.
    row.unit_price_without_vat = 1200
    vm.setLineDiscount(row, '10 %')
    expect(row.list_unit_price).toBe(1000)
    expect(row.unit_price_without_vat).toBe(900)

    // Ruční řádek s cenou opravenou nad ceníkovou: další sleva se počítá z nové ceny.
    row.stock_item_id = null
    row.unit_price_without_vat = 1200
    vm.setLineDiscount(row, '10 %')
    expect(row.list_unit_price).toBe(1200)
    expect(row.unit_price_without_vat).toBe(1080)

    // Desktop i mobilní pole mají přístupný název.
    const inputs = wrapper.findAll('[data-test="line-discount-input"]')
    expect(inputs.length).toBe(2)
    for (const field of inputs) expect(field.attributes('aria-label')).toBe('invoice.line_discount.column')
    wrapper.unmount()
  })

  it('rozepsaná sleva přežije překreslení řádku a Tab ji použije', async () => {
    const { wrapper, vm } = await mountEditor(true)
    vm.loaded = true
    await wrapper.vm.$nextTick()
    const row = vm.form.items[0]
    const field = wrapper.find('[data-test="line-discount-input"]')

    await field.trigger('focus')
    await field.setValue('1')
    // Překreslení během psaní (odpověď hledání karty, nacenění jiného řádku).
    row.quantity = 3
    await wrapper.vm.$nextTick()
    expect((field.element as HTMLInputElement).value).toBe('1')
    ;(field.element as HTMLInputElement).value = '15'
    await field.trigger('input')
    row.quantity = 4
    await wrapper.vm.$nextTick()
    expect((field.element as HTMLInputElement).value).toBe('15')

    await field.trigger('change')
    await field.trigger('blur')
    expect(row.unit_price_without_vat).toBe(850)
    expect((field.element as HTMLInputElement).value).toBe('15')
    wrapper.unmount()
  })

  it('u skladového řádku nápověda říká, že se sleva počítá z ceníku karty', async () => {
    const { wrapper, vm } = await mountEditor(true)
    const row = vm.form.items[0]
    row.list_unit_price = 1000
    row.unit_price_without_vat = 950
    expect(vm.lineDiscountHint(row)).toBe('invoice.line_discount.hint_discount_card')
    row.stock_item_id = null
    expect(vm.lineDiscountHint(row)).toBe('invoice.line_discount.hint_discount')
    wrapper.unmount()
  })
})
