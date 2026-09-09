import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getProduct: vi.fn(),
  getPrices: vi.fn(),
  getPromoPrices: vi.fn(),
  getVendors: vi.fn(),
  updatePricesVersioned: vi.fn(),
  saveProductEditor: vi.fn(),
  listCurrencies: vi.fn(),
  listLocales: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/eshop', () => ({
  eshopApi: {
    getProduct: m.getProduct,
    getPrices: m.getPrices,
    getPromoPrices: m.getPromoPrices,
    getVendors: m.getVendors,
    updatePricesVersioned: m.updatePricesVersioned,
    saveProductEditor: m.saveProductEditor,
    listCurrencies: m.listCurrencies,
    listLocales: m.listLocales,
    listManufacturers: vi.fn(async () => []),
    listCategories: vi.fn(async () => []),
    listTags: vi.fn(async () => []),
    listAttributes: vi.fn(async () => []),
    listAttributeOptions: vi.fn(async () => []),
  },
}))

vi.mock('@/api/stock', () => ({
  stockApi: { createItem: vi.fn() },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { list: vi.fn(async () => ({ data: [] })) },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn(async () => []),
    units: vi.fn(async () => []),
  },
}))

vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'load failed' }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: (permission: string) => ['eshop.write', 'stock.items.write'].includes(permission) }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: new Proxy({}, { get: () => 'M0 0' }),
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import ItemEditor from '../ItemEditor.vue'

const product = {
  id: 42,
  row_version: 5,
  supplier_id: 1,
  sku: 'EDITOR-42',
  name: 'Původní název',
  item_type: 'goods',
  unit: 'ks',
  ean: null,
  vat_rate_id: null,
  sale_price_without_vat: null,
  min_qty: null,
  is_active: true,
  note: null,
  manufacturer_id: null,
  warranty_months: null,
  delivery_days: null,
  export_eshop: false,
  is_stocked: true,
  weight_g: null,
  pricing_base: 'weighted_avg',
  i18n: [],
  categories: [],
  tag_ids: [],
  attributes: [],
  fees: [],
  media: [],
}

const price = {
  id: 7,
  currency_code: 'CZK',
  price_mode: 'fixed',
  markup_pct: null,
  fixed_price: '120.00',
  rounding: 'none',
  computed_price: '120.00',
  computed_base: null,
  computed_rate: null,
  computed_at: '2099-01-01 00:00:00',
  is_manual_override: false,
}

const currency = (id: number, code: string, isDefault = false) => ({
  id,
  code,
  name: code,
  symbol: null,
  display_order: id * 10,
  is_default: isDefault,
  archived: false,
})

const locale = (id: number, code: string, name: string) => ({
  id,
  code,
  name,
  display_order: id * 10,
  is_default: code === 'cs',
  archived: false,
})

describe('ItemEditor versionovaný přepočet', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.getProduct.mockResolvedValue(product)
    m.getPrices.mockResolvedValue([price])
    m.getPromoPrices.mockResolvedValue([])
    m.getVendors.mockResolvedValue([])
    m.updatePricesVersioned.mockResolvedValue({ prices: [price], row_version: 7 })
    m.saveProductEditor.mockResolvedValue({ ...product, row_version: 8 })
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true)])
    m.listLocales.mockResolvedValue([])
  })

  it('po chybě načtení cen nechá editor nekompletní a nedovolí uložit', async () => {
    m.getPrices.mockRejectedValueOnce(new Error('prices unavailable'))
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    const submit = wrapper.find('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('load failed')

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('load failed')
  })

  it('přepočítá ceny s CAS a zachová rozpracovaná ostatní pole pro následné uložení', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    await wrapper.find('input[required]').setValue('Rozpracovaný název')

    const recompute = wrapper.findAll('button').find(button => button.text() === 'eshop.prices.recompute')
    expect(recompute).toBeDefined()
    await recompute!.trigger('click')
    await flushPromises()

    expect(m.updatePricesVersioned).toHaveBeenCalledWith(42, 5, [expect.objectContaining({
      currency_code: 'CZK',
      fixed_price: '120.00',
    })])

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).toHaveBeenCalledWith(42, expect.objectContaining({
      row_version: 7,
      item: expect.objectContaining({ name: 'Rozpracovaný název' }),
    }))
  })
})

describe('ItemEditor připravené jazyky a měny', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.getProduct.mockResolvedValue(product)
    m.getPrices.mockResolvedValue([price])
    m.getPromoPrices.mockResolvedValue([])
    m.getVendors.mockResolvedValue([])
    m.updatePricesVersioned.mockResolvedValue({ prices: [price], row_version: 7 })
    m.saveProductEditor.mockResolvedValue({ ...product, row_version: 8 })
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true)])
    m.listLocales.mockResolvedValue([])
  })

  it('otevře češtinu, výběrem přidá další jazyk a neuloží prázdný nový překlad', async () => {
    m.listLocales.mockResolvedValue([
      locale(1, 'cs', 'Čeština'),
      locale(2, 'en', 'English'),
    ])
    m.getProduct.mockResolvedValue({
      ...product,
      i18n: [{
        locale: 'de',
        name: 'Alter Name',
        short_desc: null,
        description: null,
        seo_title: null,
        seo_description: null,
        seo_slug: null,
      }],
    })

    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    expect(wrapper.find('[data-locale="cs"]').exists()).toBe(true)
    expect(wrapper.findAll('button').some(button => button.text() === 'eshop.languages.add_locale')).toBe(false)

    await wrapper.find('select[id*="new-locale"]').setValue('en')
    expect(wrapper.find('[data-locale="en"]').exists()).toBe(true)
    await wrapper.find('[data-locale="en"] input[type="text"]').setValue('Draft name')

    const czechTab = wrapper.findAll('button[role="tab"]').find(button => button.text().includes('Čeština'))
    const englishTab = wrapper.findAll('button[role="tab"]').find(button => button.text().includes('English'))
    await czechTab!.trigger('click')
    await englishTab!.trigger('click')
    expect((wrapper.find('[data-locale="en"] input[type="text"]').element as HTMLInputElement).value).toBe('Draft name')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const payload = m.saveProductEditor.mock.calls[0][1]
    expect(payload.product.i18n).toEqual(expect.arrayContaining([
      expect.objectContaining({ locale: 'de', name: 'Alter Name' }),
      expect.objectContaining({ locale: 'en', name: 'Draft name' }),
    ]))
    expect(payload.product.i18n).not.toEqual(expect.arrayContaining([
      expect.objectContaining({ locale: 'cs' }),
    ]))
  })

  it('nedovolí tiše zahodit popis jazyka bez povinného názvu', async () => {
    m.listLocales.mockResolvedValue([
      locale(1, 'cs', 'Čeština'),
      locale(2, 'en', 'English'),
    ])

    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    await wrapper.find('select[id*="new-locale"]').setValue('en')
    await wrapper.findAll('[data-locale="en"] input[type="text"]')[2].setValue('Short description without a name')
    expect(wrapper.find('[data-locale="en"] input[type="text"]').attributes('required')).toBeDefined()

    const czechTab = wrapper.findAll('button[role="tab"]').find(button => button.text().includes('Čeština'))
    await czechTab!.trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.saveProductEditor).not.toHaveBeenCalled()
    expect(wrapper.find('[data-locale="en"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('eshop.languages.translation_name_required')
  })

  it('zobrazí všechny aktivní měny a při uložení zachová neaktivní cenu bez prázdné nové ceny', async () => {
    const eurPrice = { ...price, id: 8, currency_code: 'EUR', fixed_price: '99.00', computed_price: '99.00' }
    m.listCurrencies.mockResolvedValue([
      currency(1, 'CZK', true),
      currency(2, 'USD'),
    ])
    m.getPrices.mockResolvedValue([price, eurPrice])

    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    expect(wrapper.findAll('tr[data-currency]').map(row => row.attributes('data-currency'))).toEqual(['CZK', 'USD', 'EUR'])
    expect(wrapper.find('[data-currency="EUR"]').text()).toContain('eshop.prices.inactive_currency')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const savedCurrencies = m.saveProductEditor.mock.calls[0][1].prices.map((row: any) => row.currency_code)
    expect(savedCurrencies).toEqual(['CZK', 'EUR'])
  })

  it('uloží připravenou měnu po zadání hodnoty a dovolí explicitně smazat existující cenu', async () => {
    const eurPrice = { ...price, id: 8, currency_code: 'EUR', fixed_price: '99.00', computed_price: '99.00' }
    m.listCurrencies.mockResolvedValue([
      currency(1, 'CZK', true),
      currency(2, 'USD'),
    ])
    m.getPrices.mockResolvedValue([price, eurPrice])

    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    await wrapper.find('[data-currency="USD"] input').setValue('15')
    await wrapper.find('[data-currency="CZK"] button').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const savedPrices = m.saveProductEditor.mock.calls[0][1].prices
    expect(savedPrices).toEqual(expect.arrayContaining([
      expect.objectContaining({ currency_code: 'USD', markup_pct: 15 }),
      expect.objectContaining({ currency_code: 'EUR', fixed_price: '99.00' }),
    ]))
    expect(savedPrices).not.toEqual(expect.arrayContaining([
      expect.objectContaining({ currency_code: 'CZK' }),
    ]))
  })

  it('po CAS přepočtu znovu připraví zatím prázdné aktivní měny', async () => {
    m.listCurrencies.mockResolvedValue([
      currency(1, 'CZK', true),
      currency(2, 'USD'),
    ])

    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    const recompute = wrapper.findAll('button').find(button => button.text() === 'eshop.prices.recompute')
    await recompute!.trigger('click')
    await flushPromises()

    expect(m.updatePricesVersioned).toHaveBeenCalledWith(42, 5, [expect.objectContaining({ currency_code: 'CZK' })])
    expect(wrapper.find('[data-currency="USD"]').exists()).toBe(true)
  })
})
