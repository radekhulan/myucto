import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Rozdělení podnikatelské a osobní části: víc podnikatelských řádků s vlastním
// účtem a osobní část dopočítaná jako zbytek sazby.

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  overview: vi.fn(),
  getDocument: vi.fn(),
  saveDocument: vi.fn(),
  dimensionsEnabled: false,
  query: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '301' }, query: m.query }),
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
vi.mock('@/directives/vMath', () => ({ evalMath: (raw: string) => (raw.trim() === '' ? null : Number(raw.replace(',', '.'))) }))
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
  Modal: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /></div>' },
}


function totals(wrapper: ReturnType<typeof mount>): string[] {
  return wrapper.findAll('[data-test="allocation-total"]').map(input => (input.element as HTMLInputElement).value)
}

async function setTotal(wrapper: ReturnType<typeof mount>, index: number, value: string): Promise<void> {
  const input = wrapper.findAll('[data-test="allocation-total"]')[index]
  await input.setValue(value)
  await input.trigger('change')
}

describe('InvoiceEditor.vue — podnikatelská a osobní část', () => {
  beforeEach(() => {
    m.update.mockReset()
    m.overview.mockReset().mockResolvedValue({ enabled: false, group: null, types: [], values: [] })
    m.query = {}
    m.get.mockReset().mockResolvedValue(makeInvoice())
  })

  it('osobní část se dopočítá jako celek minus podnikatelské části s různými účty', async () => {
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()
    const enable = wrapper.findAll('button').find(b => b.text() === 'purchase_invoice.vat_allocation.enable')
    expect(enable).toBeDefined()
    await enable!.trigger('click')
    // 2 × 100 Kč + 21 % = 242 Kč celkem, zatím celé podnikatelské.
    expect(totals(wrapper)).toEqual(['242'])

    await wrapper.get('[data-test="allocation-add"]').trigger('click')
    // Osobní část převezme dopočet zbytku, podnikatelskou lze zadat.
    expect(totals(wrapper)).toEqual(['242', '0'])
    expect(wrapper.findAll('[data-test="allocation-total"]')[1].attributes('disabled')).toBeDefined()

    await setTotal(wrapper, 0, '100')
    expect(totals(wrapper)).toEqual(['100', '142'])

    await wrapper.get('[data-test="allocation-add-business"]').trigger('click')
    expect(totals(wrapper)).toEqual(['100', '142', '0'])
    await setTotal(wrapper, 2, '42')
    expect(totals(wrapper)).toEqual(['100', '100', '42'])
  })

  it('dopočet zbytku lze přesunout na jiný řádek', async () => {
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'purchase_invoice.vat_allocation.enable')!.trigger('click')
    await wrapper.get('[data-test="allocation-add"]').trigger('click')
    await setTotal(wrapper, 0, '200')
    expect(totals(wrapper)).toEqual(['200', '42'])

    // Podnikatelský řádek se zase dopočítává, osobní se zadá.
    await wrapper.get('[data-test="allocation-make-residual"]').trigger('click')
    await setTotal(wrapper, 1, '50')
    expect(totals(wrapper)).toEqual(['192', '50'])
  })
})
