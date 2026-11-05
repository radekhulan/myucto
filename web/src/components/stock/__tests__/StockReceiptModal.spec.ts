import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import StockReceiptModal from '../StockReceiptModal.vue'

const mocks = vi.hoisted(() => ({
  receiptPropose: vi.fn(),
  listWarehouses: vi.fn(),
  getItem: vi.fn(),
  searchItems: vi.fn(),
  receiptCreate: vi.fn(),
  canWrite: vi.fn(),
}))

vi.mock('@/api/stock', () => ({ stockApi: mocks }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: mocks.canWrite }) }))

const baseLine = {
  purchase_order_line_id: null,
  quantity: '1.000',
  already_received: '0.000',
  remaining_qty: '1.000',
  unit_cost: '10.000000',
}

function mountModal() {
  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
  return mount(StockReceiptModal, {
    props: { purchaseInvoiceId: 7 },
    global: {
      plugins: [i18n],
      stubs: {
        DateInput: true,
        SearchableSelect: {
          name: 'SearchableSelect',
          props: ['modelValue'],
          emits: ['update:modelValue', 'search'],
          template: '<div class="card-select" />',
        },
      },
    },
  })
}

describe('StockReceiptModal: párování podle kódu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    mocks.listWarehouses.mockResolvedValue([{ id: 1, name: 'Hlavní', is_default: true }])
    mocks.getItem.mockResolvedValue({ id: 50, sku: 'SKU-50', name: 'Karta 50', unit: 'ks', vat_rate_id: 1, sale_price_without_vat: 0 })
    mocks.searchItems.mockResolvedValue([{ id: 60, sku: 'SKU-60', name: 'Karta 60', unit: 'ks', vat_rate_id: 1, sale_price_without_vat: 0 }])
    mocks.receiptCreate.mockResolvedValue({ id: 99 })
    mocks.receiptPropose.mockResolvedValue({
      purchase_invoice: { id: 7, varsymbol: null, vendor_invoice_number: 'X', vendor_name: 'D', currency_code: 'EUR', exchange_rate: null },
      pf_changed_after_receipt: false,
      lines: [{ ...baseLine, purchase_invoice_item_id: 1, stock_item_id: 50, description: 'Matice', seller_item_code: 'KOD-1', matched_stock_item_id: 50, match_source: 'vendor_sku', intrastat_cn8_code: '73181639', intrastat_country_of_origin: 'IT' }],
      unmatched_lines: [{ ...baseLine, purchase_invoice_item_id: 2, stock_item_id: null, description: 'Hrnek', seller_item_code: 'KOD-2', matched_stock_item_id: null, match_source: null }],
      cost_candidates: [{ purchase_invoice_item_id: 2, description: 'Hrnek', amount: '10.00' }, { purchase_invoice_item_id: 3, description: 'Doprava', amount: '5.00' }],
    })
  })

  it('ukáže způsob párování, a ručně přiřazený kód nabídne zapamatovat a pošle', async () => {
    const wrapper = mountModal()
    await flushPromises()

    expect(wrapper.text()).toContain('Přiřazeno podle kódu dodavatele')
    expect(wrapper.text()).toContain('Karta nenalezena, vyberte ji')
    expect(wrapper.text()).not.toContain('Zapamatovat kód')

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()

    expect(wrapper.text()).toContain('Zapamatovat kód KOD-2')
    expect(wrapper.text()).not.toContain('Zapamatovat kód KOD-1')

    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()

    const payload = mocks.receiptCreate.mock.calls[0]![1]
    expect(payload.lines).toEqual([
      expect.objectContaining({ purchase_invoice_item_id: 1, stock_item_id: 50, remember_seller_code: undefined }),
      expect.objectContaining({ purchase_invoice_item_id: 2, stock_item_id: 60, remember_seller_code: true }),
    ])
  })

  it('řádek přiřazený na kartu zmizí z vedlejších nákladů, odškrtnutý kód se neuloží', async () => {
    const wrapper = mountModal()
    await flushPromises()
    const landedCosts = wrapper.findAll('label').find(l => l.text().includes(cs.stock.receipt.landed_costs_checkbox))!
    await landedCosts.find('input').setValue(true)
    expect(wrapper.text()).toContain('Doprava')
    expect(wrapper.findAll('.space-y-2 span.flex-1').map(s => s.text())).toEqual(['Hrnek', 'Doprava'])

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()
    expect(wrapper.findAll('.space-y-2 span.flex-1').map(s => s.text())).toEqual(['Doprava'])

    const remember = wrapper.findAll('label').find(l => l.text().includes('Zapamatovat kód KOD-2'))!
    await remember.find('input').setValue(false)
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[1].remember_seller_code).toBeUndefined()
  })

  it('bez práva upravovat karty kód nenabídne a neodešle', async () => {
    mocks.canWrite.mockReturnValue(false)
    mocks.receiptCreate.mockResolvedValue({ id: 99 })
    const wrapper = mountModal()
    await flushPromises()
    expect(mocks.canWrite).toHaveBeenCalledWith('stock.items.write')

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()

    expect(wrapper.text()).not.toContain('Zapamatovat kód')
    expect(wrapper.text()).toContain('nemáte právo upravovat skladové karty')

    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[1].remember_seller_code).toBeUndefined()
  })

  it('návrh podle kódu karty předvyplní se štítkem ke kontrole a odešle kartu výslovně', async () => {
    mocks.receiptPropose.mockResolvedValue({
      purchase_invoice: { id: 7, varsymbol: null, vendor_invoice_number: 'X', vendor_name: 'D', currency_code: 'EUR', exchange_rate: null },
      pf_changed_after_receipt: false,
      lines: [],
      unmatched_lines: [{ ...baseLine, purchase_invoice_item_id: 4, stock_item_id: null, description: 'Kroužek', seller_item_code: '88653571A', matched_stock_item_id: 50, match_source: 'sku' }],
      cost_candidates: [{ purchase_invoice_item_id: 4, description: 'Kroužek', amount: '10.00' }],
    })
    const wrapper = mountModal()
    await flushPromises()

    expect(wrapper.text()).toContain('Navrženo podle kódu karty, zkontrolujte')
    expect(wrapper.text()).toContain('Zapamatovat kód 88653571A')
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines).toEqual([
      expect.objectContaining({ purchase_invoice_item_id: 4, stock_item_id: 50, remember_seller_code: true }),
    ])
  })

  it('po změně karty přepočte zbytek, množství i cenu podle balení nové karty', async () => {
    const wrapper = mountModal()
    await flushPromises()
    mocks.receiptPropose.mockResolvedValueOnce({
      purchase_invoice: { id: 7, varsymbol: null, vendor_invoice_number: 'X', vendor_name: 'D', currency_code: 'EUR', exchange_rate: null },
      pf_changed_after_receipt: false,
      lines: [{ ...baseLine, purchase_invoice_item_id: 2, stock_item_id: 60, description: 'Hrnek', remaining_qty: '20.000', unit_cost: '40.000000' }],
      unmatched_lines: [],
      cost_candidates: [],
    })

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()

    expect(mocks.receiptPropose).toHaveBeenLastCalledWith(7, { 2: 60 })
    const price = wrapper.findAll('input[step="0.000001"]')[1]!
    expect((price.element as HTMLInputElement).value).toBe('40.000000')
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[1]).toEqual(expect.objectContaining({
      purchase_invoice_item_id: 2, stock_item_id: 60, quantity: '20.000', unit_cost: '40.000000',
    }))

    // Ruční úprava ceny po změně karty zůstává možná.
    await price.setValue('45.5')
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(String(mocks.receiptCreate.mock.calls[1]![1].lines[1].unit_cost)).toBe('45.5')
  })

  it('když se přepočet po změně karty nepodaří, vyprázdní cenu i množství a starou cenu nepošle', async () => {
    const wrapper = mountModal()
    await flushPromises()
    mocks.receiptPropose.mockRejectedValueOnce(new Error('síť'))

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()

    expect(wrapper.text()).toContain('Množství a cenu pro vybranou kartu se nepodařilo načíst')
    const inputs = wrapper.findAll('input[step="0.000001"]')
    expect((inputs[1]!.element as HTMLInputElement).value).toBe('')
    await wrapper.findAll('input[step="0.001"]')[1]!.setValue('3')
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[1]).toEqual(expect.objectContaining({
      purchase_invoice_item_id: 2, stock_item_id: 60, quantity: 3, unit_cost: undefined,
    }))
  })

  it('Intrastat z dokladu ukáže a pošle jen po výslovném zaškrtnutí', async () => {
    const wrapper = mountModal()
    await flushPromises()
    const fill = wrapper.findAll('label').find(l => l.text().includes('kód zboží 73181639, země původu IT'))!
    expect(fill.find('input').element.checked).toBe(false)

    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[0].fill_intrastat).toBeUndefined()

    await fill.find('input').setValue(true)
    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[1]![1].lines[0].fill_intrastat).toBe(true)
  })

  it('bez práva upravovat karty volbu Intrastatu nenabídne', async () => {
    mocks.canWrite.mockReturnValue(false)
    const wrapper = mountModal()
    await flushPromises()
    expect(wrapper.text()).not.toContain('kód zboží 73181639')
  })
})
