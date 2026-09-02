import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// FR5 (vendor audit 2026-08): u konceptu založeného z hodnot, které nejsou
// ze strukturovaného ISDOC (tedy z AI extrakce PDF), se má náhled originálu otevřít
// defaultně — lidská kontrola má probíhat proti originálu (viz BUG 1: AI extrakce
// s chybnou nulovou DPH a nepravdivým odůvodněním „dodavatel je neplátce").

const m = vi.hoisted(() => ({
  get: vi.fn(),
  expenseSuggestions: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '258' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: m.get,
    create: vi.fn(),
    update: vi.fn(),
    uploadPdf: vi.fn(),
    deletePdf: vi.fn(),
    pdfUrl: () => '',
    expenseSuggestions: m.expenseSuggestions,
    dismissExtractionWarning: vi.fn(),
    acceptAiPostingSuggestion: vi.fn(),
    rejectAiPostingSuggestion: vi.fn(),
    listGrouped: vi.fn().mockResolvedValue({ data: [] }),
  },
}))

vi.mock('@/api/invoices', () => ({
  PAYMENT_METHODS: ['bank_transfer', 'cash', 'card', 'direct_debit'],
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn().mockResolvedValue([]),
    currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', label: 'Kč' }]),
    units: vi.fn().mockResolvedValue([]),
  },
}))

vi.mock('@/api/stock', () => ({
  stockApi: { searchItems: vi.fn().mockResolvedValue([]) },
}))

vi.mock('@/api/expenseCategories', () => ({
  expenseCategoriesApi: { list: vi.fn().mockResolvedValue([]) },
}))

// Zakázky a pokladny přibyly do loadCodebooks() až po vzniku tohohle testu. Bez mocku
// jde v jsdom skutečný HTTP požadavek, který se nikdy nevrátí — onMounted() se proto
// zasekne PŘED načtením faktury a komponenta zůstane trvale ve stavu „načítám".
vi.mock('@/api/projects', () => ({
  projectsApi: { list: vi.fn().mockResolvedValue({ data: [] }) },
}))

vi.mock('@/api/cash', () => ({
  cashApi: { listRegisters: vi.fn().mockResolvedValue([]) },
}))

vi.mock('@/api/vatClassifications', () => ({
  vatClassificationsApi: { list: vi.fn().mockResolvedValue([]) },
}))

vi.mock('@/api/settings', () => ({
  settingsApi: { createCurrency: vi.fn() },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { getVatStatus: vi.fn() },
}))

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number, c?: string) => `${v} ${c ?? ''}`.trim(),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

vi.mock('@/composables/useDemoMode', () => ({
  useDemoMode: () => ({ blockDemoMutation: () => false }),
}))

vi.mock('@/composables/useRowFocus', () => ({
  focusLastRow: vi.fn(),
}))

vi.mock('@/directives/vMath', () => ({
  evalMath: { mounted: vi.fn(), updated: vi.fn() },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false },
  }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

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
    received_at_source: 'import',
    currency_id: 1,
    currency: 'CZK',
    exchange_rate: null,
    exchange_rate_date: null,
    exchange_rate_source: 'manual',
    reverse_charge: false,
    prices_include_vat: false,
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
    status: 'draft',
    booked_at: null,
    paid_at: null,
    cancelled_at: null,
    pdf_path: '/archive/258.pdf',
    pdf_hash: 'abc123',
    pdf_size_bytes: 12345,
    pdf_original_name: 'faktura.pdf',
    pdf_uploaded_at: '2026-06-01',
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
    vat_overrides: [],
    vat_allocations: [],
    created_by: 1,
    created_at: '2026-06-01',
    updated_at: '2026-06-01',
    items: [],
    vat_breakdown: [],
    locked: { is_locked: false },
    ...overrides,
  } as unknown as PurchaseInvoice
}

const stubs = {
  AutomationBadge: true,
  ConfidenceLabel: true,
  StockDescriptionField: true,
  ExpenseKindSuggestionHint: true,
  VendorPicker: true,
  ClientFormModal: true,
  PdfDropzone: true,
  PaymentCurrencyBlock: true,
  ExchangeRateInput: true,
  EmptyState: true,
}

function stubViewport(matches: boolean): void {
  vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({
    matches,
    media: '',
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
}

describe('InvoiceEditor.vue — výchozí stav PDF náhledu (FR5)', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.expenseSuggestions.mockReset()
    m.expenseSuggestions.mockResolvedValue({ items: {} })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('koncept z AI extrakce (source_format=pdf) → náhled otevřený defaultně', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'pdf' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('iframe').exists()).toBe(true)
  })

  it('koncept bez zjištěného zdroje (source_format=null) → náhled taky otevřený (bezpečný default)', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: null }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('iframe').exists()).toBe(true)
  })

  it('koncept ze strukturovaného ISDOC (source_format=isdoc) → náhled zůstává zavřený', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'isdoc' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('koncept z ISDOCX balíčku → náhled taky zůstává zavřený (taky strukturovaný, ne AI)', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'isdocx' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('doklad už přijatý (status=received) z AI extrakce → náhled se nevnucuje (uživatel ho už zkontroloval)', async () => {
    m.get.mockResolvedValue(makeInvoice({ status: 'received', source_format: 'pdf' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('na široké obrazovce otevře AI koncept v pravém bočním náhledu', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'pdf' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    const side = wrapper.get('[data-test="document-side-preview"]')
    expect(wrapper.findAll('iframe')).toHaveLength(1)
    expect(side.find('iframe').exists()).toBe(true)
  })

  it('na užší obrazovce ponechá otevřený náhled pod formulářem', async () => {
    stubViewport(false)
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'pdf' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(1)
  })

  it('zavření bočního náhledu odstraní jediný iframe', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice({ status: 'draft', source_format: 'pdf' }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    await wrapper.get('[data-test="document-side-preview-close"]').trigger('click')

    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(0)
  })

  it('bez PDF nevytvoří na široké obrazovce prázdný boční sloupec', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice({
      status: 'draft',
      source_format: 'pdf',
      pdf_path: null,
      pdf_hash: null,
      pdf_size_bytes: null,
      pdf_original_name: null,
      pdf_uploaded_at: null,
    }))
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="document-side-preview"]').exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(0)
  })
})
