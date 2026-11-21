import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  download: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: mocks.download }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: mocks.toastError, success: vi.fn() }) }))

import InvoiceBookMenu from './InvoiceBookMenu.vue'
import { invoicesApi } from '@/api/invoices'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { otherItemsApi } from '@/api/otherItems'

function mountMenu(urlFor = (format: 'pdf' | 'xlsx') => `/api/invoices/book?format=${format}`) {
  return mount(InvoiceBookMenu, {
    props: { label: 'Kniha', urlFor, fallbackFilename: 'kniha' },
    global: { plugins: [createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: { cs: {} } })] },
  })
}

describe('Kniha faktur', () => {
  beforeEach(() => {
    mocks.download.mockReset()
    mocks.toastError.mockReset()
  })

  it('stáhne zvolený formát z URL pro aktuální filtr', async () => {
    mocks.download.mockResolvedValue({})
    const wrapper = mountMenu()
    expect(wrapper.find('[data-test="invoice-book-menu"]').exists()).toBe(false)
    await wrapper.get('[data-test="invoice-book-toggle"]').trigger('click')
    await wrapper.get('[data-test="invoice-book-xlsx"]').trigger('click')
    await flushPromises()
    expect(mocks.download).toHaveBeenCalledWith('/api/invoices/book?format=xlsx', 'kniha.xlsx')
    expect(wrapper.find('[data-test="invoice-book-menu"]').exists()).toBe(false)
  })

  it('chybu ze serveru ukáže uživateli', async () => {
    mocks.download.mockRejectedValue({ response: { data: { error: { message: 'Neplatný formát' } } } })
    const wrapper = mountMenu()
    await wrapper.get('[data-test="invoice-book-toggle"]').trigger('click')
    await wrapper.get('[data-test="invoice-book-pdf"]').trigger('click')
    await flushPromises()
    expect(mocks.toastError).toHaveBeenCalledWith('Neplatný formát')
  })

  it('bez zprávy ze serveru ukáže hlášku knihy, kterou předá stránka', async () => {
    mocks.download.mockRejectedValue(new Error('network'))
    const wrapper = mount(InvoiceBookMenu, {
      props: { label: 'Kniha', urlFor: () => '/api/accounting/other-items/book?format=pdf', fallbackFilename: 'kniha', failedMessage: 'Knihu ostatních položek se nepodařilo vytvořit' },
      global: { plugins: [createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: { cs: {} } })] },
    })
    await wrapper.get('[data-test="invoice-book-toggle"]').trigger('click')
    await wrapper.get('[data-test="invoice-book-pdf"]').trigger('click')
    await flushPromises()
    expect(mocks.toastError).toHaveBeenCalledWith('Knihu ostatních položek se nepodařilo vytvořit')
  })

  it('rozsah a nápovědu bere od stránky, jinak zůstanou texty knihy faktur', async () => {
    const i18n = () => createI18n({ legacy: false, locale: 'cs', missingWarn: false, fallbackWarn: false, messages: { cs: {} } })
    const own = mount(InvoiceBookMenu, {
      props: { label: 'Kniha', urlFor: () => '', fallbackFilename: 'kniha', scopeText: 'Jen ručně zadané položky', hint: 'Sestava ručních položek' },
      global: { plugins: [i18n()] },
    })
    expect(own.get('[data-test="invoice-book-toggle"]').attributes('title')).toBe('Sestava ručních položek')
    await own.get('[data-test="invoice-book-toggle"]').trigger('click')
    expect(own.get('[data-test="invoice-book-menu"]').text()).toContain('Jen ručně zadané položky')

    const invoices = mountMenu()
    expect(invoices.get('[data-test="invoice-book-toggle"]').attributes('title')).toBe('invoice.book_hint')
    await invoices.get('[data-test="invoice-book-toggle"]').trigger('click')
    expect(invoices.get('[data-test="invoice-book-menu"]').text()).toContain('invoice.book_scope')
  })

  it('bez předané hlášky zůstane hláška knihy faktur', async () => {
    mocks.download.mockRejectedValue(new Error('network'))
    const wrapper = mountMenu()
    await wrapper.get('[data-test="invoice-book-toggle"]').trigger('click')
    await wrapper.get('[data-test="invoice-book-pdf"]').trigger('click')
    await flushPromises()
    expect(mocks.toastError).toHaveBeenCalledWith('invoice.book_failed')
  })

  it('URL nese filtr seznamu, ale ne stránkování ani doplňkové sloupce', () => {
    const issued = new URL(invoicesApi.bookUrl({
      q: 'servis', status: ['issued', 'paid'], client_id: 7, year: 2026, page: 3, per_page: 50,
      sort_key: 'amount', include_kh: true,
    }, 'pdf'), 'http://localhost')
    expect(issued.pathname).toBe('/api/invoices/book')
    expect(issued.searchParams.get('format')).toBe('pdf')
    expect(issued.searchParams.get('q')).toBe('servis')
    expect(issued.searchParams.get('filter[status]')).toBe('issued,paid')
    expect(issued.searchParams.get('filter[client_id]')).toBe('7')
    expect(issued.searchParams.get('filter[year]')).toBe('2026')
    for (const key of ['page', 'per_page', 'sort_key', 'filter[include_kh]']) {
      expect(issued.searchParams.has(key)).toBe(false)
    }

    const received = new URL(purchaseInvoicesApi.bookUrl({ vendor_id: 4, unpaid_only: true, page: 2 }, 'xlsx'), 'http://localhost')
    expect(received.pathname).toBe('/api/purchase-invoices/book')
    expect(received.searchParams.get('filter[vendor_id]')).toBe('4')
    expect(received.searchParams.get('filter[unpaid_only]')).toBe('1')
    expect(received.searchParams.has('page')).toBe(false)

    // Ostatní položky: výběr výpisu (stejné názvy parametrů jako GET /accounting/other-items), bez stránkování a řazení.
    const other = new URL(otherItemsApi.bookUrl({
      side: 'receivable', status: 'all', kind: 'rent', q: 'nájem', from: '2099-01-01', to: '2099-12-31',
      page: 3, per_page: 50, sort_by: 'amount', sort_dir: 'asc',
    } as Parameters<typeof otherItemsApi.bookUrl>[0], 'xlsx'), 'http://localhost')
    expect(other.pathname).toBe('/api/accounting/other-items/book')
    expect(Object.fromEntries(other.searchParams)).toEqual({
      format: 'xlsx', side: 'receivable', status: 'all', kind: 'rent', q: 'nájem', from: '2099-01-01', to: '2099-12-31',
    })
  })
})
