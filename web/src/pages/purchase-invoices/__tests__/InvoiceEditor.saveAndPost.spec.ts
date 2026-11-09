import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Tlačítko Uložit a zaúčtovat je jen za volbou instalace (auth.saveAndPostEnabled,
// výchozí vypnuto) a jen pro uživatele s právem zaúčtovat.

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  overview: vi.fn(),
  getDocument: vi.fn(),
  saveDocument: vi.fn(),
  dimensionsEnabled: false,
  saveAndPostEnabled: false,
  canPost: true,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '301' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: m.get,
    create: vi.fn(),
    update: m.update,
    uploadPdf: vi.fn(),
    deletePdf: vi.fn(),
    pdfUrl: () => '',
    expenseSuggestions: vi.fn().mockResolvedValue({ items: {} }),
    dismissExtractionWarning: vi.fn(),
    acceptAiPostingSuggestion: vi.fn(),
    rejectAiPostingSuggestion: vi.fn(),
    listGrouped: vi.fn().mockResolvedValue({ data: [] }),
  },
}))

vi.mock('@/api/dimensions', () => ({
  dimensionsApi: { overview: m.overview, getDocument: m.getDocument, saveDocument: m.saveDocument },
  compactDimensions: (map: Record<number, number | null> | null | undefined) => {
    const out: Record<number, number> = {}
    for (const [k, v] of Object.entries(map ?? {})) if (v) out[Number(k)] = v
    return out
  },
}))

vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: ['bank_transfer', 'cash', 'card', 'direct_debit'] }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn().mockResolvedValue([{ id: 1, rate_percent: 21, is_default: true }]),
    currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', label: 'Kč' }]),
    units: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/api/stock', () => ({ stockApi: { searchItems: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/settings', () => ({ settingsApi: { createCurrency: vi.fn() } }))
vi.mock('@/api/clients', () => ({ clientsApi: { getVatStatus: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number, c?: string) => `${v} ${c ?? ''}`.trim() }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useRowFocus', () => ({ focusLastRow: vi.fn() }))
vi.mock('@/directives/vMath', () => ({ evalMath: { mounted: vi.fn(), updated: vi.fn() } }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: (key: string) => (key === 'accounting.journal.post' ? m.canPost : true),
    get saveAndPostEnabled() { return m.saveAndPostEnabled },
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplierId: 1,
    currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false, dimensions_enabled: m.dimensionsEnabled },
  }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

function item(id: number, description: string) {
  return {
    id,
    description,
    quantity: 1,
    duration_minutes: null,
    unit: 'ks',
    unit_price_without_vat: 100,
    vat_rate_id: 1,
    order_index: id,
    expense_kind: null,
    accrual_from: null,
    accrual_to: null,
    stock_item_id: null,
  }
}

function makeInvoice(): PurchaseInvoice {
  return {
    id: 301,
    vendor_id: 5,
    vendor_invoice_number: 'FP-301',
    varsymbol: '301',
    document_kind: 'invoice',
    issue_date: '2026-06-01',
    tax_date: '2026-06-01',
    due_date: '2026-06-15',
    received_at: '2026-06-02',
    currency_id: 1,
    currency: 'CZK',
    exchange_rate: null,
    exchange_rate_source: 'manual',
    reverse_charge: false,
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_deductible: true,
    language: 'cs',
    status: 'draft',
    advance_paid_amount: 0,
    rounding: 0,
    items: [item(1, 'Materiál A'), item(2, 'Materiál B')],
    vat_overrides: [],
    vat_allocations: [],
    locked: { is_locked: false },
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
  DocumentSidePreview: true,
}

describe('InvoiceEditor.vue (přijaté) - Uložit a zaúčtovat', () => {
  beforeEach(() => {
    m.get.mockReset().mockResolvedValue(makeInvoice())
    m.overview.mockReset().mockResolvedValue({ enabled: false, group: null, types: [], values: [] })
    m.saveAndPostEnabled = false
    m.canPost = true
  })

  async function mounted() {
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()
    return wrapper
  }

  it('bez volby instalace tlačítko není', async () => {
    const wrapper = await mounted()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(false)
  })

  it('se zapnutou volbou a právem zaúčtovat tlačítko je', async () => {
    m.saveAndPostEnabled = true
    const wrapper = await mounted()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(true)
  })

  it('se zapnutou volbou bez práva zaúčtovat tlačítko není', async () => {
    m.saveAndPostEnabled = true
    m.canPost = false
    const wrapper = await mounted()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(false)
  })
})
