import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getProduct: vi.fn(),
  getPrices: vi.fn(),
  getPromoPrices: vi.fn(),
  getVendors: vi.fn(),
  updatePricesVersioned: vi.fn(),
  saveProductEditor: vi.fn(),
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
    listCurrencies: vi.fn(async () => [{ id: 1, code: 'CZK', is_default: true }]),
    listLocales: vi.fn(async () => []),
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

describe('ItemEditor versionovaný přepočet', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.getProduct.mockResolvedValue(product)
    m.getPrices.mockResolvedValue([price])
    m.getPromoPrices.mockResolvedValue([])
    m.getVendors.mockResolvedValue([])
    m.updatePricesVersioned.mockResolvedValue({ prices: [price], row_version: 7 })
    m.saveProductEditor.mockResolvedValue({ ...product, row_version: 8 })
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
