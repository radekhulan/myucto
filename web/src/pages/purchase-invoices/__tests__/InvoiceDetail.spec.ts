import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// ── Mockovaný stav (hoisted) ──────────────────────────────────────────────────
const m = vi.hoisted(() => ({
  get: vi.fn(),
  activity: vi.fn(),
}))

// vue-router — useRoute (id), useRouter (push), RouterLink jako jednoduchý stub.
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '258' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

// API klient — jen get + activity, ostatní URL/akce jsou no-op (v testu se nevolají).
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: m.get,
    activity: m.activity,
    pdfUrl: () => '',
    sourceUrl: () => '',
    ourPdfUrl: () => '',
    isdocUrl: () => '',
    pohodaUrl: () => '',
  },
}))

// Lepkavý přepínač bočního náhledu (useSidePreview) čte per-user preference. Bez mocku
// by se přes `@/i18n` protáhl skutečný createI18n, který tenhle test na vue-i18n nemá.
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number, c?: string) => `${v} ${c ?? ''}`.trim(),
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
  useSupplierStore: () => ({
    currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false },
  }),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { postPurchase: vi.fn() },
  postingErrorI18nKey: () => 'error',
}))

vi.mock('@/api/stock', () => ({
  stockApi: { receiptPropose: vi.fn() },
}))

import InvoiceDetail from '@/pages/purchase-invoices/InvoiceDetail.vue'

function makeInvoice(overrides: Partial<PurchaseInvoice> = {}): PurchaseInvoice {
  return {
    id: 258,
    supplier_id: 1,
    vendor_id: 5,
    varsymbol: '2026258',
    vendor_invoice_number: 'FP-258',
    vendor_company_name: 'Dodavatel s.r.o.',
    vendor_ic: null,
    vendor_dic: null,
    document_kind: 'invoice',
    issue_date: '2026-06-01',
    tax_date: '2026-06-01',
    due_date: '2026-06-15',
    received_at: '2026-06-02',
    currency_id: 1,
    currency: 'CZK',
    exchange_rate: null,
    exchange_rate_date: null,
    exchange_rate_source: 'manual',
    reverse_charge: false,
    is_fixed_asset: false,
    vendor_is_vat_payer: true,
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_deductible: true,
    language: 'cs',
    note_above_items: null,
    note_below_items: null,
    vendor_snapshot: null,
    own_snapshot: null,
    total_without_vat: 1000,
    total_vat: 210,
    total_with_vat: 1210,
    rounding: 0,
    advance_paid_amount: 0,
    amount_to_pay: 1210,
    payment_currency_id: null,
    payment_currency: null,
    payment_exchange_rate: null,
    paid_amount_payment_ccy: null,
    paid_amount_invoice_ccy: null,
    exchange_diff_base: null,
    payment_account_number: null,
    payment_bank_code: null,
    payment_iban: null,
    payment_bic: null,
    payment_variable_symbol: null,
    payment_account_source: null,
    status: 'paid',
    booked_at: null,
    paid_at: '2026-06-14',
    cancelled_at: null,
    pdf_path: null,
    pdf_hash: null,
    pdf_size_bytes: null,
    pdf_original_name: null,
    pdf_uploaded_at: null,
    source_path: null,
    source_hash: null,
    source_size_bytes: null,
    source_original_name: null,
    source_format: null,
    source_uploaded_at: null,
    vat_classification_code: null,
    expense_category_id: null,
    ai_posting_suggestion: null,
    advance_purchase_invoice_id: null,
    advance_link_suggested_id: null,
    linked_advance: null,
    parent_purchase_invoice_id: null,
    linked_parent: null,
    has_parent_candidates: false,
    corrected_by: [],
    advance_link_suggestion: null,
    settled_by: null,
    has_advance_candidates: false,
    has_settlement_candidates: false,
    bank_payments: null,
    cash_payments: null,
    mark_paid_unposted: false,
    extraction_warning: null,
    created_by: 1,
    created_at: '2026-06-01',
    updated_at: '2026-06-01',
    items: [],
    vat_breakdown: [],
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
  PurchaseDmsDocumentsPanel: true,
  StockReceiptModal: true,
}

const bankLinks = (wrapper: ReturnType<typeof mount>) =>
  wrapper
    .findAllComponents({ name: 'RouterLink' })
    .filter((l) => {
      const to = l.props('to') as { name?: string } | string
      return typeof to === 'object' && to?.name === 'bank-detail'
    })

describe('InvoiceDetail.vue — bankovní úhrady', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
  })

  it('zaplacená faktura s bank_payments → odkaz(y) na bankovní výpis se správným statement_id', async () => {
    m.get.mockResolvedValue(
      makeInvoice({
        bank_payments: [
          { bank_transaction_id: 11, statement_id: 42, amount: 1000, posted_at: '2026-06-14', counterparty: 'Alfa', currency: 'CZK', journal_entry_id: 501 },
          { bank_transaction_id: 12, statement_id: 43, amount: 210, posted_at: '2026-06-15', counterparty: null, currency: 'CZK', journal_entry_id: 502 },
        ],
      }),
    )
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    const links = bankLinks(wrapper)
    expect(links).toHaveLength(2)
    expect(links[0].props('to')).toEqual({ name: 'bank-detail', params: { id: 42 } })
    expect(links[1].props('to')).toEqual({ name: 'bank-detail', params: { id: 43 } })
  })

  it('faktura bez bank_payments → žádný odkaz na bankovní výpis', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'received', paid_at: null, bank_payments: [] }))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(bankLinks(wrapper)).toHaveLength(0)
  })

  it('inkaso ukáže způsob úhrady bez nadbytečného vysvětlení', async () => {
    m.get.mockResolvedValue(makeInvoice({ payment_method: 'direct_debit' }))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('payment_method.direct_debit')
    expect(wrapper.text()).not.toContain('payment_method.non_transfer_tooltip')
  })

  it('bankovní úhrada → proklik i na zaúčtování úhrady (deník s entry_id)', async () => {
    m.get.mockResolvedValue(
      makeInvoice({
        bank_payments: [
          { bank_transaction_id: 11, statement_id: 42, amount: 1210, posted_at: '2026-06-14', counterparty: 'Alfa', currency: 'CZK', journal_entry_id: 777 },
        ],
      }),
    )
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    const journalLinks = wrapper.findAllComponents({ name: 'RouterLink' }).filter((l) => {
      const to = l.props('to') as { name?: string; query?: { entry_id?: string } }
      return typeof to === 'object' && to?.name === 'accounting-journal' && to?.query?.entry_id === '777'
    })
    expect(journalLinks.length).toBeGreaterThanOrEqual(1)
  })

  it('hotovostní úhrada → proklik na pokladnu (register) i na zaúčtování úhrady', async () => {
    m.get.mockResolvedValue(
      makeInvoice({
        cash_payments: [
          { cash_document_id: 9, doc_number: 'VPD-2026-0003', amount: 1210, date: '2026-06-14', register_id: 3, register_name: 'Hlavní pokladna', journal_entry_id: 888, currency: 'CZK' },
        ],
      }),
    )
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    const links = wrapper.findAllComponents({ name: 'RouterLink' })
    const cashLink = links.find((l) => {
      const to = l.props('to') as { name?: string; query?: { register_id?: string } }
      return typeof to === 'object' && to?.name === 'accounting-cash' && to?.query?.register_id === '3'
    })
    const journalLink = links.find((l) => {
      const to = l.props('to') as { name?: string; query?: { entry_id?: string } }
      return typeof to === 'object' && to?.name === 'accounting-journal' && to?.query?.entry_id === '888'
    })
    expect(cashLink).toBeTruthy()
    expect(journalLink).toBeTruthy()
  })

  it('mark_paid_unposted → výrazné upozornění, žádná úhrada k prokliku', async () => {
    m.get.mockResolvedValue(
      makeInvoice({ status: 'paid', paid_at: '2026-06-14', bank_payments: [], cash_payments: [], mark_paid_unposted: true }),
    )
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('purchase_invoice.payment_provenance.mark_paid_unposted')
    expect(bankLinks(wrapper)).toHaveLength(0)
  })
})

describe('InvoiceDetail.vue — vazba daňového dokladu k platbě', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
  })

  it('upozorní na DDKP bez zálohy i bez finální faktury', async () => {
    m.get.mockResolvedValue(makeInvoice({ document_kind: 'tax_document' }))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('purchase_invoice.tax_document_link.missing')
  })

  it('neupozorní na samostatný DDKP vyúčtovaný finální fakturou', async () => {
    m.get.mockResolvedValue(makeInvoice({
      document_kind: 'tax_document',
      settled_by: {
        id: 21314,
        varsymbol: '202621314',
        vendor_invoice_number: 'FV-21314',
        document_kind: 'invoice',
        issue_date: '2026-06-30',
        total_with_vat: 1210,
        currency: 'CZK',
        status: 'paid',
      },
    }))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(wrapper.text()).toContain('purchase_invoice.advance_link.settled_by')
    expect(wrapper.text()).not.toContain('purchase_invoice.tax_document_link.missing')
  })
})
