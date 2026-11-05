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

type Line = { purchase_invoice_item_id: number; stock_item_id: number | null; [key: string]: unknown }
let proposal: { lines: Line[]; unmatched_lines?: Line[]; [key: string]: unknown }

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
    proposal = {
      purchase_invoice: { id: 7, varsymbol: null, vendor_invoice_number: 'X', vendor_name: 'D', currency_code: 'EUR', exchange_rate: null },
      pf_changed_after_receipt: false,
      lines: [{ ...baseLine, purchase_invoice_item_id: 1, stock_item_id: 50, description: 'Matice', seller_item_code: 'KOD-1', matched_stock_item_id: 50, match_source: 'vendor_sku', intrastat_cn8_code: '73181639', intrastat_country_of_origin: 'IT' }],
      unmatched_lines: [{ ...baseLine, purchase_invoice_item_id: 2, stock_item_id: null, description: 'Hrnek', seller_item_code: 'KOD-2', matched_stock_item_id: null, match_source: null }],
      cost_candidates: [{ purchase_invoice_item_id: 2, description: 'Hrnek', amount: '10.00' }, { purchase_invoice_item_id: 3, description: 'Doprava', amount: '5.00' }],
    }
    // Stejně jako server: řádek s vybranou kartou přejde do `lines`.
    mocks.receiptPropose.mockImplementation(async (_id: number, cards?: Record<number, number>) => {
      const moved = (proposal.unmatched_lines ?? []).filter(l => cards?.[l.purchase_invoice_item_id])
        .map(l => ({ ...l, stock_item_id: cards![l.purchase_invoice_item_id]! }))
      return { ...proposal, lines: [...proposal.lines, ...moved] }
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

  it('zapamatování je předvolené jen u karty vybrané ručně a oznámí, odkud se kód odebere', async () => {
    proposal = {
      purchase_invoice: { id: 7, varsymbol: null, vendor_invoice_number: 'X', vendor_name: 'D', currency_code: 'EUR', exchange_rate: null },
      pf_changed_after_receipt: false,
      lines: [],
      unmatched_lines: [
        { ...baseLine, order_index: 2, purchase_invoice_item_id: 1, stock_item_id: null, description: 'Druhý', seller_item_code: 'KOD-A', matched_stock_item_id: 50, match_source: 'sku' },
        { ...baseLine, order_index: 1, purchase_invoice_item_id: 2, stock_item_id: null, description: 'První', seller_item_code: 'KOD-B', matched_stock_item_id: null, match_source: null,
          vendor_code_cards: [{ id: 70, sku: 'SKU-70', name: 'Stará karta' }] },
      ],
      cost_candidates: [],
    }
    const wrapper = mountModal()
    await flushPromises()
    expect(wrapper.findAll('.text-sm.font-medium').map(d => d.text()).filter(x => ['První', 'Druhý'].includes(x))).toEqual(['První', 'Druhý'])

    const remember = (code: string) => wrapper.findAll('label').find(l => l.text().includes(`Zapamatovat kód ${code}`))!.find('input').element as HTMLInputElement
    expect(remember('KOD-A').checked).toBe(false)

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[0]!.vm.$emit('search', 'x')
    await flushPromises()
    await selects[0]!.vm.$emit('update:modelValue', 60)
    await flushPromises()
    expect(remember('KOD-B').checked).toBe(true)
    expect(wrapper.text()).toContain('Kód se odebere z karty: SKU-70 - Stará karta')

    await selects[1]!.vm.$emit('update:modelValue', 50)
    await flushPromises()
    expect(remember('KOD-A').checked).toBe(false)

    await wrapper.findAll('footer button')[1]!.trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines.map((l: { remember_seller_code?: boolean }) => l.remember_seller_code)).toEqual([true, undefined])
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
      expect.objectContaining({ purchase_invoice_item_id: 4, stock_item_id: 50, remember_seller_code: undefined }),
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

  async function failRecalcOnSecondLine(wrapper: ReturnType<typeof mountModal>) {
    mocks.receiptPropose.mockRejectedValueOnce(new Error('síť'))
    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()
    return selects
  }
  const submitButton = (wrapper: ReturnType<typeof mountModal>) => wrapper.findAll('footer button')[1]!

  it('když se přepočet po změně karty nepodaří, vyprázdní cenu i množství a řádek blokuje odeslání', async () => {
    const wrapper = mountModal()
    await flushPromises()
    await failRecalcOnSecondLine(wrapper)

    expect(wrapper.find('[data-test="recalc-failed"]').text()).toContain('Množství a cenu pro vybranou kartu se nepodařilo načíst')
    expect((wrapper.findAll('input[step="0.000001"]')[1]!.element as HTMLInputElement).value).toBe('')
    expect(submitButton(wrapper).attributes('disabled')).toBeDefined()

    // Samotné ruční množství nestačí, cena by zůstala bez kontroly proti kartě.
    await wrapper.findAll('input[step="0.001"]')[1]!.setValue('3')
    expect(submitButton(wrapper).attributes('disabled')).toBeDefined()
    await submitButton(wrapper).trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate).not.toHaveBeenCalled()
  })

  it('řádek po neúspěšném přepočtu odblokuje nový výběr karty, nebo vyřazení množstvím 0', async () => {
    const wrapper = mountModal()
    await flushPromises()
    const selects = await failRecalcOnSecondLine(wrapper)

    await wrapper.findAll('input[step="0.001"]')[1]!.setValue('0')
    expect(wrapper.find('[data-test="recalc-failed"]').exists()).toBe(false)
    expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
    await submitButton(wrapper).trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines.map((l: { purchase_invoice_item_id: number }) => l.purchase_invoice_item_id)).toEqual([1])

    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()
    expect(wrapper.find('[data-test="recalc-failed"]').exists()).toBe(false)
    expect((wrapper.findAll('input[step="0.001"]')[1]!.element as HTMLInputElement).value).toBe('1.000')
    expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('během přepočtu po změně karty nejde příjemku odeslat', async () => {
    const wrapper = mountModal()
    await flushPromises()
    let finish!: (value: unknown) => void
    mocks.receiptPropose.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))

    const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
    await selects[1]!.vm.$emit('search', 'hrnek')
    await flushPromises()
    await selects[1]!.vm.$emit('update:modelValue', 60)
    await flushPromises()
    expect(submitButton(wrapper).attributes('disabled')).toBeDefined()
    await submitButton(wrapper).trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate).not.toHaveBeenCalled()

    finish({ ...proposal, lines: [...proposal.lines, { ...baseLine, purchase_invoice_item_id: 2, stock_item_id: 60, description: 'Hrnek', remaining_qty: '20.000', unit_cost: '40.000000' }], unmatched_lines: [] })
    await flushPromises()
    expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
    await submitButton(wrapper).trigger('click')
    await flushPromises()
    expect(mocks.receiptCreate.mock.calls[0]![1].lines[1]).toEqual(expect.objectContaining({ purchase_invoice_item_id: 2, quantity: '20.000', unit_cost: '40.000000' }))
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
