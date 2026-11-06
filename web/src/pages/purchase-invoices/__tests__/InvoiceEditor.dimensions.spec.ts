import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Firma → Dimenze v editoru přijaté faktury: dimenze položek se ukládají až po uložení
// dokladu, podle pořadí položek od 1, a jdou s položkou (smazaný řádek vezme svoje s sebou).
// Při vypnutých dimenzích editor nic nenačítá ani nevykresluje.

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  overview: vi.fn(),
  getDocument: vi.fn(),
  saveDocument: vi.fn(),
  dimensionsEnabled: true,
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
    canWrite: () => true,
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

const PROJECT_TYPE = 7

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

describe('InvoiceEditor.vue — dimenze dokladu a položek', () => {
  beforeEach(() => {
    m.dimensionsEnabled = true
    m.get.mockReset().mockResolvedValue(makeInvoice())
    m.update.mockReset().mockResolvedValue({ ...makeInvoice(), _warnings: [] })
    m.overview.mockReset().mockResolvedValue({
      enabled: true,
      group: null,
      types: [{ id: PROJECT_TYPE, name: 'Projekt', code: 'projekt', kind: 'project', level: 'company', is_active: true, show_on_documents: true, sort_order: 0 }],
      values: [
        { id: 70, type_id: PROJECT_TYPE, code: 'A', name: 'Projekt A', parent_id: null, is_active: true },
        { id: 71, type_id: PROJECT_TYPE, code: 'B', name: 'Projekt B', parent_id: null, is_active: true },
      ],
    })
    m.getDocument.mockReset().mockResolvedValue({
      header: { [PROJECT_TYPE]: 70 },
      items: { 1: { [PROJECT_TYPE]: 70 }, 2: { [PROJECT_TYPE]: 71 } },
    })
    m.saveDocument.mockReset().mockResolvedValue({ header: {}, items: {}, restamp: { lines: 0, needs_repost: false } })
  })

  it('po uložení dokladu uloží dimenze položek podle pořadí — smazaná položka vezme svoje s sebou', async () => {
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(m.getDocument).toHaveBeenCalledWith('purchase-invoices', 301)
    expect(wrapper.find('[data-test="purchase-header-dimensions"]').exists()).toBe(true)

    // Smaž první položku (desktop tabulka) — položka B se posune na pozici 1.
    await wrapper.findAll('button[title="purchase_invoice.items.remove"]')[0].trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalled()
    const payload = m.update.mock.calls[0][1]
    expect(JSON.stringify(payload.items)).not.toContain('dimension')
    expect(m.saveDocument).toHaveBeenCalledWith('purchase-invoices', 301, {
      header: { [PROJECT_TYPE]: 70 },
      items: { 1: { [PROJECT_TYPE]: 71 } },
    })
  })

  it('vypnuté dimenze nic nenačítají ani nevykreslují', async () => {
    m.dimensionsEnabled = false
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('[data-test="purchase-header-dimensions"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="dimension-fields"]').exists()).toBe(false)
    expect(m.getDocument).not.toHaveBeenCalled()

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalled()
    expect(m.saveDocument).not.toHaveBeenCalled()
  })
})
