import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Telefon: souhrn pod číslem dokladu, doklad přes celou obrazovku (PdfCanvasViewer),
// sekce originálu nad kartami a karty s údaji sbalené. Mimo telefon beze změny.

const m = vi.hoisted(() => ({ get: vi.fn(), activity: vi.fn() }))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '258' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: m.get,
    activity: m.activity,
    pdfUrl: (id: number, inline?: boolean) => `/api/purchase-invoices/${id}/pdf${inline ? '?inline=1' : ''}`,
    sourceUrl: () => '',
    ourPdfUrl: () => '',
    isdocUrl: () => '',
    pohodaUrl: () => '',
  },
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: { flags: {} } }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number, c?: string) => `${v} ${c ?? ''}`.trim(),
  formatUnitPrice: (v: number) => String(v),
  formatDate: (d: string | null | undefined) => d ?? '',
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    user: { id: 1 },
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false } }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: { postPurchase: vi.fn() },
  postingErrorI18nKey: () => 'error',
  postingErrorMessage: () => 'error',
}))
vi.mock('@/api/stock', () => ({ stockApi: { receiptPropose: vi.fn() } }))

import InvoiceDetail from '@/pages/purchase-invoices/InvoiceDetail.vue'

function makeInvoice(overrides: Partial<PurchaseInvoice> = {}): PurchaseInvoice {
  return {
    id: 258, supplier_id: 1, vendor_id: 5, varsymbol: '2026258', vendor_invoice_number: 'FP-258',
    vendor_company_name: 'Dodavatel s.r.o.', document_kind: 'invoice',
    issue_date: '2026-06-01', tax_date: '2026-06-01', due_date: '2099-06-15', received_at: '2026-06-02',
    currency_id: 1, currency: 'CZK', reverse_charge: false, is_fixed_asset: false, vendor_is_vat_payer: true,
    vat_deduction: 'full', vat_deduction_percent: 100, tax_deductible: true, language: 'cs',
    total_without_vat: 1000, total_vat: 210, total_with_vat: 1210, rounding: 0, advance_paid_amount: 0,
    amount_to_pay: 1210, status: 'received', booked_at: null, paid_at: null, cancelled_at: null,
    pdf_path: '/archive/258.pdf', pdf_hash: 'abc123', pdf_size_bytes: 12345, pdf_original_name: 'faktura.pdf',
    pdf_uploaded_at: '2026-06-01', corrected_by: [], bank_payments: null, cash_payments: null,
    mark_paid_unposted: false, extraction_warning: null, created_by: 1, created_at: '2026-06-01',
    updated_at: '2026-06-01', items: [], vat_breakdown: [],
    ...overrides,
  } as unknown as PurchaseInvoice
}

const stubs = {
  ActionBar: true,
  LockedBadge: true,
  PostingBadge: true,
  WhyChip: true,
  PdfDropzone: true,
  LinkedDocumentsPanel: true,
  StockReceiptModal: true,
  DocumentPostingPanel: { template: '<section data-test="posting-panel"><slot /></section>' },
  DocumentDimensionsPanel: { template: '<section data-test="dimensions-panel" />' },
  WorkspaceTeleport: { template: '<div><slot /></div>' },
  PdfCanvasViewer: { name: 'PdfCanvasViewer', props: ['src', 'title'], template: '<div data-test="pdf-canvas-viewer" :data-src="src" />' },
}

async function mountDetail(overrides: Partial<PurchaseInvoice> = {}) {
  m.get.mockResolvedValue(makeInvoice(overrides))
  const wrapper = mount(InvoiceDetail, { global: { stubs } })
  await flushPromises()
  return wrapper
}

const isShown = (el: { attributes: (n: string) => string | undefined }) => !(el.attributes('style') ?? '').includes('display: none')

describe('InvoiceDetail.vue (přijaté) - telefon', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset().mockResolvedValue([])
    phoneRef.value = false
  })

  it('na telefonu ukáže souhrn s částkou k úhradě, splatností a stavem úhrady', async () => {
    phoneRef.value = true
    const wrapper = await mountDetail()
    const summary = wrapper.get('[data-test="phone-summary"]')
    expect(summary.text()).toContain('1210 CZK')
    expect(summary.text()).toContain('2099-06-15')
    expect(wrapper.get('[data-test="phone-payment-state"]').text()).toBe('purchase_invoice.phone_summary.state.unpaid')
  })

  it('stav úhrady rozliší zaplacený doklad a doklad po splatnosti', async () => {
    phoneRef.value = true
    let wrapper = await mountDetail({ status: 'paid', paid_at: '2026-06-14' } as Partial<PurchaseInvoice>)
    expect(wrapper.get('[data-test="phone-payment-state"]').text()).toBe('purchase_invoice.phone_summary.state.paid')
    wrapper.unmount()
    wrapper = await mountDetail({ due_date: '2000-01-01' } as Partial<PurchaseInvoice>)
    expect(wrapper.get('[data-test="phone-payment-state"]').text()).toBe('purchase_invoice.phone_summary.state.overdue')
  })

  it('Doklad (PDF) otevře celoobrazovkový náhled s Zavřít a Stáhnout', async () => {
    phoneRef.value = true
    const wrapper = await mountDetail()
    expect(wrapper.find('[data-test="phone-pdf-modal"]').exists()).toBe(false)
    await wrapper.get('[data-test="phone-open-pdf"]').trigger('click')
    const modal = wrapper.get('[data-test="phone-pdf-modal"]')
    expect(modal.classes()).toContain('fixed')
    expect(modal.classes()).toContain('inset-0')
    expect(modal.get('[data-test="pdf-canvas-viewer"]').attributes('data-src')).toBe('/api/purchase-invoices/258/pdf?inline=1')
    expect(modal.get('[data-test="phone-pdf-download"]').attributes('href')).toBe('/api/purchase-invoices/258/pdf')
    await modal.get('[data-test="phone-pdf-close"]').trigger('click')
    expect(wrapper.find('[data-test="phone-pdf-modal"]').exists()).toBe(false)
  })

  it('na telefonu jsou karty s údaji sbalené a rozbalí je přepínač', async () => {
    phoneRef.value = true
    const wrapper = await mountDetail()
    expect(isShown(wrapper.get('[data-test="detail-cards"]'))).toBe(false)
    expect(isShown(wrapper.get('[data-test="posting-panel"]'))).toBe(false)
    expect(isShown(wrapper.get('[data-test="dimensions-panel"]'))).toBe(false)
    await wrapper.get('[data-test="phone-details-toggle"]').trigger('click')
    expect(isShown(wrapper.get('[data-test="detail-cards"]'))).toBe(true)
    expect(isShown(wrapper.get('[data-test="posting-panel"]'))).toBe(true)
  })

  it('na telefonu je sekce originálu nad kartami s údaji', async () => {
    phoneRef.value = true
    const wrapper = await mountDetail()
    const html = wrapper.html()
    expect(html.indexOf('faktura.pdf')).toBeGreaterThan(-1)
    expect(html.indexOf('faktura.pdf')).toBeLessThan(html.indexOf('data-test="detail-cards"'))
  })

  it('mimo telefon není souhrn ani PDF tlačítko, karty jsou vidět a originál zůstává pod nimi', async () => {
    const wrapper = await mountDetail()
    expect(wrapper.find('[data-test="phone-summary"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="phone-open-pdf"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="phone-details-toggle"]').exists()).toBe(false)
    expect(isShown(wrapper.get('[data-test="detail-cards"]'))).toBe(true)
    expect(isShown(wrapper.get('[data-test="posting-panel"]'))).toBe(true)
    const html = wrapper.html()
    expect(html.indexOf('faktura.pdf')).toBeGreaterThan(html.indexOf('data-test="detail-cards"'))
  })
})
