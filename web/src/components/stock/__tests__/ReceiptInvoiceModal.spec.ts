import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { StockDocument } from '@/api/stock'

const push = vi.fn()
const { createInvoiceFromReceipt } = vi.hoisted(() => ({
  createInvoiceFromReceipt: vi.fn((_id: number, _payload: unknown) => Promise.resolve({ id: 77 })),
}))

vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: () => Promise.resolve({ data: [{ id: 5, company_name: 'Dealer', ic: null }] }) } }))
vi.mock('@/api/eshop', () => ({ eshopApi: { listPriceLevels: () => Promise.resolve([{ id: 3, name: 'Urgentní', is_active: true }, { id: 4, name: 'Stará', is_active: false }]) } }))
vi.mock('@/api/stock', () => ({ stockApi: { createInvoiceFromReceipt } }))

import ReceiptInvoiceModal from '../ReceiptInvoiceModal.vue'

const baseDoc = {
  id: 9,
  lines: [
    { id: 101, line_no: 1, stock_item_id: 1, qty: '1.000', sku: 'A', name: 'Díl A', unit: 'ks' },
    { id: 102, line_no: 2, stock_item_id: 2, qty: '1.000', sku: 'B', name: 'Motorka', unit: 'ks', tracking_allocations: [{ quantity: '1', serial_number: 'VIN-1' }] },
  ],
  sales_invoices: [],
} as unknown as StockDocument

function mountModal(document: StockDocument = baseDoc) {
  return mount(ReceiptInvoiceModal, {
    props: { document },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: { cs: {} } })],
      stubs: {
        SearchableSelect: {
          props: ['modelValue'],
          emits: ['update:modelValue', 'search'],
          template: '<button data-test="pick-client" @click="$emit(\'update:modelValue\', 5)" />',
        },
      },
    },
  })
}

describe('ReceiptInvoiceModal', () => {
  beforeEach(() => { push.mockClear(); createInvoiceFromReceipt.mockClear() })

  it('bez odběratele nepustí dál, po vytvoření otevře editor faktury', async () => {
    const wrapper = mountModal()
    await flushPromises()
    expect(wrapper.get('[data-test="create-invoice"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('VIN-1')
    expect(wrapper.findAll('option').map(o => o.text())).toContain('Urgentní')
    expect(wrapper.findAll('option').map(o => o.text())).not.toContain('Stará')

    await wrapper.get('[data-test="pick-client"]').trigger('click')
    await wrapper.get('[data-test="create-invoice"]').trigger('click')
    await flushPromises()
    expect(createInvoiceFromReceipt).toHaveBeenCalledWith(9, {
      client_id: 5, price_level_id: null, lines: [{ line_id: 101 }, { line_id: 102 }], allow_duplicate: false,
    })
    expect(push).toHaveBeenCalledWith('/invoices/77/edit')
  })

  it('vyfakturovaný řádek nabídne nezaškrtnutý, přidání je vědomé přebití upozornění', async () => {
    const wrapper = mountModal({
      ...baseDoc,
      sales_invoices: [{ invoice_id: 1, varsymbol: '2610003', status: 'issued', client_name: 'Dealer', line_nos: [1] }],
    })
    await flushPromises()
    expect(wrapper.find('[data-test="already-invoiced"]').exists()).toBe(true)
    await wrapper.get('[data-test="pick-client"]').trigger('click')
    const boxes = wrapper.findAll('input[type="checkbox"]')
    expect((boxes[0].element as HTMLInputElement).checked).toBe(false)
    expect((boxes[1].element as HTMLInputElement).checked).toBe(true)

    await wrapper.get('[data-test="create-invoice"]').trigger('click')
    await flushPromises()
    expect(createInvoiceFromReceipt.mock.calls[0][1]).toMatchObject({ lines: [{ line_id: 102 }], allow_duplicate: false })

    createInvoiceFromReceipt.mockClear()
    await boxes[0].setValue(true)
    await wrapper.get('[data-test="create-invoice"]').trigger('click')
    await flushPromises()
    expect(createInvoiceFromReceipt.mock.calls[0][1]).toMatchObject({ allow_duplicate: true })
  })

  it('bez práva číst faktury upozorní jen na řádky, řádek se stejně nabídne nezaškrtnutý', async () => {
    const wrapper = mountModal({ ...baseDoc, sales_invoices: [{ line_nos: [2] }] })
    await flushPromises()
    expect(wrapper.get('[data-test="already-invoiced"]').text()).toContain('stock.receipt_invoice.already_invoiced_hidden')
    const boxes = wrapper.findAll('input[type="checkbox"]')
    expect((boxes[1].element as HTMLInputElement).checked).toBe(false)
  })

  it('stornovaná faktura řádky neblokuje', async () => {
    const wrapper = mountModal({
      ...baseDoc,
      sales_invoices: [{ invoice_id: 1, varsymbol: null, status: 'cancelled', client_name: 'Dealer', line_nos: [1, 2] }],
    })
    await flushPromises()
    expect(wrapper.find('[data-test="already-invoiced"]').exists()).toBe(false)
    expect(wrapper.findAll('input[type="checkbox"]').every(b => (b.element as HTMLInputElement).checked)).toBe(true)
  })
})
