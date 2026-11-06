import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import type { StockQuoteRequest } from '@/api/stock'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

const pending = () => new Promise(() => {})
const quoted = (payload: StockQuoteRequest, price: string) => ({
  lines: payload.lines.map(line => ({
    key: line.key, stock_item_id: line.stock_item_id, unit: 'ks', base_unit: 'ks', numerator: 1, denominator: 1,
    base_quantity: '1.000', unit_price: price, base_unit_price: price, list_unit_price: '100.00',
    price_source: 'price_level_discount' as const, customer_price_id: null, discount_pct: '20.000', promo: null,
  })),
})
const quoteItems = vi.fn((payload: StockQuoteRequest) => Promise.resolve(quoted(payload, '80.00')))

/** Nacenění, které odpoví až na pokyn testu (souběh dvou požadavků). */
function deferredQuote() {
  let answer: (price: string) => void = () => {}
  quoteItems.mockImplementationOnce((payload: StockQuoteRequest) => new Promise((resolve) => {
    answer = price => resolve(quoted(payload, price))
  }))
  return (price: string) => answer(price)
}

vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/api/stock', () => ({
  stockApi: new Proxy({}, { get: (_target, key) => (key === 'quoteItems' ? quoteItems : pending) }),
}))
vi.mock('@/api/eshop', () => ({ eshopApi: new Proxy({}, { get: () => pending }) }))
vi.mock('@/api/invoices', () => ({ invoicesApi: new Proxy({}, { get: () => pending }) }))

import InvoiceEditor from './InvoiceEditor.vue'

type Row = { stock_item_id: number | null; unit: string; quantity: number; unit_price_without_vat: number; description?: string; vat_rate_id?: number; list_unit_price?: number | null }
type Vm = {
  form: { currency: string; currency_id: number; client_id: number | null; price_level_id: number | null; prices_include_vat: boolean; items: Row[] }
  vatRates: { id: number; rate_percent: number }[]
  setLineDiscount: (row: Row, raw: string) => void
  currencies: { id: number; code: string }[]
  loaded: boolean
  editedStatus: string
  clients: { id: number; price_level_id: number | null }[]
  priceLevels: { id: number; name: string; is_active: boolean }[]
  priceLevelsLoaded: boolean
  rememberLoadedStockPrices: () => void
}

// Zprávy s parametry, ať test vidí, co dialog a hlášky říkají.
const messages = {
  reprice_confirm: 'confirm {level} {count}',
  reprice_confirm_manual: 'confirm-manual {level} {count}',
  no_level: 'bez hladiny',
  reprice_failed: 'failed',
  reprice_missing_price: 'missing {count}',
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0))

// Uložený koncept (nebo nový doklad) s načtenými řádky: dvě karty a jeden řádek bez karty.
async function mountEditor(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSupplierStore().setAvailable([{ id: 1, is_vat_payer: true, stock_enabled: true } as SupplierBrief], 1, true)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }, { path: '/invoices/:id', component: InvoiceEditor }],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: { invoice: { price_level: messages } } }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  vm.form.currency = 'CZK'
  vm.currencies = [{ id: 1, code: 'CZK' }, { id: 2, code: 'EUR' }]
  vm.form.currency_id = 1
  vm.clients = [{ id: 1, price_level_id: null }, { id: 2, price_level_id: 3 }, { id: 4, price_level_id: 3 }, { id: 6, price_level_id: 5 }]
  vm.form.client_id = 1
  vm.form.items = [
    { stock_item_id: 7, unit: 'ks', quantity: 1, unit_price_without_vat: 100, description: 'Helma' },
    { stock_item_id: 8, unit: 'ks', quantity: 1, unit_price_without_vat: 95, description: 'Rukavice' },
    { stock_item_id: null, unit: 'h', quantity: 1, unit_price_without_vat: 500, description: 'Montáž' },
  ]
  vm.priceLevels = [{ id: 3, name: 'Dealer', is_active: true }, { id: 5, name: 'Urgent', is_active: true }]
  vm.priceLevelsLoaded = true
  vm.loaded = true
  await flush()
  return { wrapper, vm }
}

describe('InvoiceEditor: přecenění uloženého dokladu po změně hladiny', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    quoteItems.mockClear()
  })

  it('potvrzení přecení kladnou cenu dobropisu a zachová zápornou cenu vratky', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    try {
      vm.form.items[0].unit_price_without_vat = -100
      vm.form.items[1].quantity = -1
      vm.form.price_level_id = 5
      await flush()
      expect(confirm).toHaveBeenCalledWith('confirm Urgent 1')
      expect(quoteItems.mock.calls[0][0].lines.map(line => line.stock_item_id)).toEqual([8])
      expect(vm.form.items.map(row => [row.quantity, row.unit_price_without_vat])).toEqual([[1, -100], [-1, 80], [1, 500]])
    } finally {
      wrapper.unmount()
    }
  })

  it('doklad pouze se zápornými skladovými cenami přecenění nenabídne', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    try {
      vm.form.items[0].unit_price_without_vat = -100
      vm.form.items[1].unit_price_without_vat = -95
      vm.form.price_level_id = 5
      await flush()
      expect(confirm).not.toHaveBeenCalled()
      expect(quoteItems).not.toHaveBeenCalled()
      expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([-100, -95, 500])
    } finally {
      wrapper.unmount()
    }
  })

  it('přecenění včetně DPH a následná řádková sleva používají stejný základ ceny', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    try {
      vm.form.prices_include_vat = true
      vm.vatRates = [{ id: 1, rate_percent: 21 }]
      vm.form.items[0].vat_rate_id = 1
      vm.form.price_level_id = 5
      await flush()
      const row = vm.form.items[0]
      expect([row.unit_price_without_vat, row.list_unit_price]).toEqual([96.8, 121])
      vm.setLineDiscount(row, '10')
      expect(row.unit_price_without_vat).toBe(108.9)
    } finally {
      wrapper.unmount()
    }
  })

  it('změna hladiny dokladu se zeptá a po potvrzení nacení všechny skladové řádky', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.price_level_id = 5
    await flush()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(confirm).toHaveBeenCalledWith('confirm Urgent 2')
    expect(quoteItems).toHaveBeenCalledTimes(1)
    expect(quoteItems.mock.calls[0][0].price_level_id).toBe(5)
    expect(quoteItems.mock.calls[0][0].lines.map(line => line.stock_item_id)).toEqual([7, 8])
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([80, 80, 500])
    wrapper.unmount()
  })

  it('Ponechat nechá ceny beze změny', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.price_level_id = 5
    await flush()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(quoteItems).not.toHaveBeenCalled()
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([100, 95, 500])
    wrapper.unmount()
  })

  it('odběratel s jinou hladinou se zeptá, se stejnou hladinou ne', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.client_id = 2
    await flush()
    expect(confirm).toHaveBeenCalledTimes(1)

    vm.form.client_id = 4
    await flush()
    expect(confirm).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('vystavený doklad bez úpravy (force) ani nový doklad se neptají', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const issued = await mountEditor('/invoices/42')
    issued.vm.editedStatus = 'issued'
    issued.vm.form.price_level_id = 5
    await flush()
    issued.wrapper.unmount()

    const created = await mountEditor('/invoices/new')
    created.vm.form.price_level_id = 5
    await flush()
    created.wrapper.unmount()

    expect(confirm).not.toHaveBeenCalled()
    expect(quoteItems).not.toHaveBeenCalled()
  })

  it('odběratel s jinou hladinou i měnou: přecenění v nové měně, stará odpověď se zahodí', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    const answerOld = deferredQuote()
    const answerNew = deferredQuote()

    // Výběr odběratele: hladina se změní hned, výchozí měna až po načtení zakázek.
    vm.form.client_id = 2
    await flush()
    vm.form.currency_id = 2
    await flush()

    expect(quoteItems).toHaveBeenCalledTimes(2)
    expect(quoteItems.mock.calls.map(call => call[0].currency)).toEqual(['CZK', 'EUR'])
    answerNew('70.00')
    await flush()
    answerOld('80.00')
    await flush()
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([70, 70, 500])
    wrapper.unmount()
  })

  it('rychlé A → B (potvrdit) → C: přecení podle C bez druhého dotazu', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    const answerB = deferredQuote()
    const answerC = deferredQuote()

    vm.form.client_id = 2
    await flush()
    vm.form.client_id = 6
    await flush()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(quoteItems.mock.calls.map(call => call[0].client_id)).toEqual([2, 6])
    answerC('60.00')
    await flush()
    answerB('80.00')
    await flush()
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([60, 60, 500])
    wrapper.unmount()
  })

  it('selhání nacenění po potvrzení ohlásí chybou a příště se zeptá znovu', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    quoteItems.mockImplementationOnce(() => Promise.reject(new Error('offline')))
    const { wrapper, vm } = await mountEditor('/invoices/42')
    const { toasts } = useToast()
    toasts.value = []

    vm.form.price_level_id = 5
    await flush()

    expect(toasts.value.map(toast => [toast.type, toast.text])).toEqual([['error', 'failed']])
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([100, 95, 500])

    // Ceny zůstaly ručně uložené, další změna hladiny se zase zeptá na oba řádky.
    vm.form.price_level_id = 3
    await flush()
    expect(confirm).toHaveBeenCalledTimes(2)
    expect(quoteItems.mock.calls[1][0].lines).toHaveLength(2)
    wrapper.unmount()
  })

  it('ručně upravenou cenu dialog zmíní, návrat bez hladiny ji pojmenuje', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(true).mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    vm.form.price_level_id = 3
    await flush()
    // Po přecenění uživatel jednu cenu přepíše ručně.
    vm.form.items[0].unit_price_without_vat = 85
    vm.form.price_level_id = 5
    await flush()
    vm.form.price_level_id = null
    await flush()
    expect(confirm.mock.calls.map(call => call[0])).toEqual(['confirm Dealer 2', 'confirm-manual Urgent 1', 'confirm-manual bez hladiny 1'])
    wrapper.unmount()
  })

  it('karta bez ceny v měně dokladu: řádek zůstane uložený, hláška řekne kolik', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    quoteItems.mockImplementationOnce((payload: StockQuoteRequest) => Promise.resolve({
      lines: quoted(payload, '80.00').lines.map(line => (line.stock_item_id === 7 ? { ...line, unit_price: null } : line)),
    }) as never)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    const { toasts } = useToast()
    toasts.value = []

    vm.form.price_level_id = 5
    await flush()
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([100, 80, 500])
    expect(toasts.value.map(toast => [toast.type, toast.text])).toEqual([['warning', 'missing 1']])

    // Nenaceněný řádek se při další změně hladiny zase ptá, přeceněný jde automaticky.
    vm.form.price_level_id = 3
    await flush()
    expect(confirm.mock.calls.map(call => call[0])).toEqual(['confirm Urgent 2', 'confirm Dealer 1'])
    wrapper.unmount()
  })

  it('ruční přepis uložené ceny v této relaci dialog zmíní', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')
    // Ceny načtené s dokladem (hydratace), pak uživatel jednu přepíše.
    vm.rememberLoadedStockPrices()
    vm.form.price_level_id = 3
    await flush()
    vm.form.items[0].unit_price_without_vat = 77
    vm.form.price_level_id = 5
    await flush()
    expect(confirm.mock.calls.map(call => call[0])).toEqual(['confirm Dealer 2', 'confirm-manual Urgent 2'])
    wrapper.unmount()
  })
})
