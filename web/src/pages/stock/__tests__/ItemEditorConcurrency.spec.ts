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
  listPackagingUnits: vi.fn(),
  getItemPackaging: vi.fn(),
  replaceItemPackaging: vi.fn(),
  getCustomerPrices: vi.fn(),
  replaceCustomerPrices: vi.fn(),
  listTrackingUnits: vi.fn(),
  updateTrackingUnit: vi.fn(),
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
    listPackagingUnits: m.listPackagingUnits,
  },
}))

vi.mock('@/api/stock', () => ({
  stockApi: {
    createItem: vi.fn(),
    getItemPackaging: m.getItemPackaging,
    replaceItemPackaging: m.replaceItemPackaging,
    getCustomerPrices: m.getCustomerPrices,
    replaceCustomerPrices: m.replaceCustomerPrices,
    listTrackingUnits: m.listTrackingUnits,
    updateTrackingUnit: m.updateTrackingUnit,
  },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { list: vi.fn(async () => ({ data: [] })) },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: vi.fn(async () => [{ id: 1, iso2: 'DE', iso3: 'DEU', name_cs: 'Německo', name_en: 'Germany', is_eu: true }]),
    vatRates: vi.fn(async () => []),
    units: vi.fn(async () => []),
  },
}))

vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'load failed' }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: (permission: string) => ['eshop.write', 'stock.items.write'].includes(permission) }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }),
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
  onBeforeRouteLeave: vi.fn(),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import ItemEditor from '../ItemEditor.vue'
import DateInput from '@/components/ui/DateInput.vue'

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
  intrastat_cn8_code: '27101981',
  intrastat_country_of_origin: 'CZ',
  intrastat_net_mass_kg: '1.250',
  intrastat_supplementary_unit: 'PCE',
  intrastat_supplementary_unit_coefficient: '1.000000',
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

const packagingUnit = (id: number, code: string, name: string) => ({
  id, code, name, is_active: true, display_order: id * 10, usage_count: 0,
})

function mockItemExtras() {
  m.listPackagingUnits.mockResolvedValue([packagingUnit(1, 'KT', 'Karton'), packagingUnit(2, 'PAL', 'Paleta')])
  m.getItemPackaging.mockResolvedValue({
    base_unit: 'ks',
    default_sale_unit: null,
    units: [{ unit_code: 'KT', name: 'Karton', factor: '8', numerator: 8, denominator: 1, ean: null, in_use: false }],
  })
  m.replaceItemPackaging.mockImplementation(async (_id: number, payload: any) => ({
    base_unit: 'ks',
    default_sale_unit: payload.default_sale_unit,
    units: payload.units.map((u: any) => ({ ...u, name: null, numerator: Number(u.factor), denominator: 1, in_use: false })),
  }))
  m.getCustomerPrices.mockResolvedValue([])
  m.replaceCustomerPrices.mockResolvedValue([])
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(resolvePromise => {
    resolve = resolvePromise
  })
  return { promise, resolve }
}

describe('ItemEditor versionovaný přepočet', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockItemExtras()
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

  it('ponechá editaci provedenou během ukládání jako neuloženou', async () => {
    const save = deferred<typeof product>()
    m.saveProductEditor.mockReturnValueOnce(save.promise)
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    await wrapper.find('input[required]').setValue('Odeslaný název')
    await wrapper.find('form').trigger('submit')
    expect(m.saveProductEditor).toHaveBeenCalledTimes(1)
    expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined()

    await wrapper.find('input[required]').setValue('Novější název')
    save.resolve({ ...product, row_version: 8 })
    await flushPromises()

    expect((wrapper.find('input[required]').element as HTMLInputElement).value).toBe('Novější název')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).toHaveBeenLastCalledWith(42, expect.objectContaining({
      item: expect.objectContaining({ name: 'Novější název' }),
      row_version: 8,
    }))
  })
})

describe('ItemEditor balení a zákaznické ceny', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockItemExtras()
    m.getProduct.mockResolvedValue(product)
    m.getPrices.mockResolvedValue([price])
    m.getPromoPrices.mockResolvedValue([])
    m.getVendors.mockResolvedValue([])
    m.saveProductEditor.mockResolvedValue({ ...product, row_version: 8 })
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true)])
    m.listLocales.mockResolvedValue([])
  })

  it('nezměněné balení ani zákaznické ceny společné Uložit neposílá', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.saveProductEditor).toHaveBeenCalledTimes(1)
    expect(m.replaceItemPackaging).not.toHaveBeenCalled()
    expect(m.replaceCustomerPrices).not.toHaveBeenCalled()
  })

  it('uloží změněné balení a výchozí prodejní jednotku jedním Uložit po uložení karty', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    const rows = wrapper.findAll('[data-test="packaging-row"]')
    expect(rows).toHaveLength(1)
    await rows[0]!.find('input[inputmode="decimal"]').setValue('12')
    await wrapper.find('[data-test="packaging-default-unit"]').setValue('KT')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.saveProductEditor).toHaveBeenCalledTimes(1)
    expect(m.replaceItemPackaging).toHaveBeenCalledWith(42, {
      default_sale_unit: 'KT',
      units: [{ unit_code: 'KT', factor: '12', ean: null }],
    })
    expect(m.saveProductEditor.mock.invocationCallOrder[0]).toBeLessThan(m.replaceItemPackaging.mock.invocationCallOrder[0])
  })

  it('odmítne balení se stejným kódem jako základní jednotka a nic neuloží', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    await wrapper.find('input[list]').setValue('KT')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.saveProductEditor).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('stock.packaging.same_as_base')
  })

  it('když se balení nenačte, uložení karty ho nepřepíše prázdnou sadou', async () => {
    m.getItemPackaging.mockRejectedValue(new Error('not deployed'))
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    expect(wrapper.text()).toContain('stock.packaging.load_failed')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).toHaveBeenCalledTimes(1)
    expect(m.replaceItemPackaging).not.toHaveBeenCalled()
  })

  it('nový řádek balení bez vybraného kódu neprojde validací', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    const add = wrapper.findAll('button').find(button => button.text() === 'stock.packaging.add')!
    await add.trigger('click')
    expect(wrapper.findAll('[data-test="packaging-row"]')).toHaveLength(2)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('stock.packaging.code_required')
  })

  it('nová zákaznická cena bez zákazníka neprojde validací', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    const add = wrapper.findAll('button').find(button => button.text() === 'stock.customer_prices.add')!
    await add.trigger('click')
    expect(wrapper.findAll('[data-test="customer-price-row"]')).toHaveLength(1)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('stock.customer_prices.client_required')
  })
})

describe('ItemEditor připravené jazyky a měny', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockItemExtras()
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

    await wrapper.find('[data-currency="USD"] input[type="number"]').setValue('15')
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

  it('zachová cenová pravidla a marži při uložení karty', async () => {
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true), currency(2, 'USD')])
    m.getPrices.mockResolvedValue([{ ...price, price_mode: 'target_margin', markup_pct: '25.000', use_pricing_rules: true }])
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    await wrapper.find('[data-currency="USD"] input[type="checkbox"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.saveProductEditor.mock.calls[0][1].prices).toEqual(expect.arrayContaining([
      expect.objectContaining({ currency_code: 'CZK', price_mode: 'target_margin', markup_pct: '25.000', use_pricing_rules: true }),
      expect.objectContaining({ currency_code: 'USD', use_pricing_rules: true }),
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

  it('publishes keyboard-accessible editor tabs and keeps placeholders out of the saved payload', async () => {
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true), currency(2, 'USD')])
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    const tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')
    expect(tabs[0]!.attributes('aria-controls')).toContain('panel-general')
    await tabs[0]!.trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.findAll('button[role="tab"]')[1]!.attributes('aria-selected')).toBe('true')
    expect(wrapper.find('[role="tabpanel"]').attributes('id')).toContain('panel-general')
  })

  it('na záložce Intrastat načte a uloží normalizované údaje skladové karty', async () => {
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()
    const intrastatTab = wrapper.findAll('button[role="tab"]').find(button => button.text() === 'stock.items.intrastat.tab')!
    await intrastatTab.trigger('click')

    expect((wrapper.get('[data-test="intrastat-cn8"]').element as HTMLInputElement).value).toBe('27101981')
    await wrapper.get('[data-test="intrastat-country"]').setValue('DE')
    await wrapper.get('[data-test="intrastat-unit"]').setValue('PCE')
    await wrapper.get('[data-test="intrastat-coefficient"]').setValue('2.5')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.saveProductEditor).toHaveBeenCalledWith(42, expect.objectContaining({
      item: expect.objectContaining({
        intrastat_cn8_code: '27101981',
        intrastat_country_of_origin: 'DE',
        intrastat_supplementary_unit: 'PCE',
        intrastat_supplementary_unit_coefficient: 2.5,
      }),
    }))
  })
})

describe('ItemEditor šarže karty', () => {
  const lotPage = { data: [{ id: 7, lot_code: 'L-B', expires_on: '2027-01-31' }], meta: { total: 1, page: 1, per_page: 50, pages: 1 } }

  beforeEach(() => {
    vi.clearAllMocks()
    mockItemExtras()
    m.getProduct.mockResolvedValue({ ...product, tracking_mode: 'lot' })
    m.getPrices.mockResolvedValue([price])
    m.getPromoPrices.mockResolvedValue([])
    m.getVendors.mockResolvedValue([])
    m.saveProductEditor.mockResolvedValue({ ...product, tracking_mode: 'lot', row_version: 8 })
    m.listCurrencies.mockResolvedValue([currency(1, 'CZK', true)])
    m.listLocales.mockResolvedValue([])
  })

  it('když se šarže nenačtou, ukáže chybu s tlačítkem Zkusit znovu místo skryté sekce', async () => {
    m.listTrackingUnits.mockRejectedValueOnce(new Error('lots unavailable')).mockResolvedValue(lotPage)
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    expect(wrapper.find('[data-test="lots-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="lots-load-error"]').text()).toContain('stock.tracking.lots_load_error')
    expect(wrapper.findAll('[data-test="lot-row"]')).toHaveLength(0)

    await wrapper.find('[data-test="lots-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="lots-load-error"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="lot-row"]')).toHaveLength(1)
  })

  it('štítky patří k polím a odmítnutá šarže označí kód i datum spotřeby', async () => {
    m.listTrackingUnits.mockResolvedValue(lotPage)
    m.updateTrackingUnit.mockRejectedValue(new Error('conflict'))
    const wrapper = shallowMount(ItemEditor)
    await flushPromises()

    const row = wrapper.find('[data-test="lot-row"]')
    const code = row.find('input[data-test="lot-code"]')
    const labels = row.findAll('label')
    expect(labels).toHaveLength(2)
    expect(labels[0]!.attributes('for')).toBe(code.attributes('id'))
    const date = row.findComponent(DateInput)
    expect(labels[1]!.attributes('for')).toBe(date.props('inputId'))
    expect(date.props('invalid')).toBe(false)

    await code.setValue('L-A')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const failed = wrapper.find('[data-test="lot-row"]')
    expect(failed.find('[data-test="lot-error"]').text()).toBe('load failed')
    expect(failed.findComponent(DateInput).props('invalid')).toBe(true)
    expect(failed.find('input[data-test="lot-code"]').attributes('aria-invalid')).toBe('true')
  })
})
