import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Telefon: velká tlačítka Vyfotit / Vybrat soubor místo plochy „přetáhněte sem",
// rekapitulace DPH jako karty a lišta Uložit přilepená nad spodní lištou.
// Mimo telefon zůstává dropzóna i tabulka rekapitulace beze změny.

const m = vi.hoisted(() => ({
  get: vi.fn(),
  overview: vi.fn(),
}))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))

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
    update: vi.fn(),
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
  dimensionsApi: { overview: m.overview, getDocument: vi.fn(), saveDocument: vi.fn() },
  compactDimensions: () => ({}),
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
    saveAndPostEnabled: false,
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplierId: 1,
    currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false, dimensions_enabled: false },
  }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

function makeInvoice(): PurchaseInvoice {
  const item = (id: number) => ({
    id, description: `Položka ${id}`, quantity: 1, duration_minutes: null, unit: 'ks',
    unit_price_without_vat: 100, vat_rate_id: 1, order_index: id, expense_kind: null,
    accrual_from: null, accrual_to: null, stock_item_id: null,
  })
  return {
    id: 301, vendor_id: 5, vendor_invoice_number: 'FP-301', varsymbol: '301', document_kind: 'invoice',
    issue_date: '2026-06-01', tax_date: '2026-06-01', due_date: '2026-06-15', received_at: '2026-06-02',
    currency_id: 1, currency: 'CZK', exchange_rate: null, exchange_rate_source: 'manual',
    reverse_charge: false, vat_deduction: 'full', vat_deduction_percent: 100, tax_deductible: true,
    language: 'cs', status: 'draft', advance_paid_amount: 0, rounding: 0,
    items: [item(1), item(2)], vat_overrides: [], vat_allocations: [], locked: { is_locked: false },
  } as unknown as PurchaseInvoice
}

const stubs = {
  AutomationBadge: true,
  ConfidenceLabel: true,
  StockDescriptionField: true,
  ExpenseKindSuggestionHint: true,
  VendorPicker: true,
  ClientFormModal: true,
  PaymentCurrencyBlock: true,
  ExchangeRateInput: true,
  EmptyState: true,
  DocumentSidePreview: true,
  CapturePhotoButton: { template: '<button type="button" data-testid="capture-photo">foto</button>' },
}

describe('InvoiceEditor.vue (přijaté) - telefon', () => {
  beforeEach(() => {
    m.get.mockReset().mockResolvedValue(makeInvoice())
    m.overview.mockReset().mockResolvedValue({ enabled: false, group: null, types: [], values: [] })
    phoneRef.value = false
  })
  afterEach(() => { vi.restoreAllMocks() })

  async function mounted() {
    const wrapper = mount(InvoiceEditor, { global: { stubs } })
    await flushPromises()
    return wrapper
  }

  it('na telefonu ukáže Vyfotit a Vybrat soubor místo plochy dropzóny', async () => {
    phoneRef.value = true
    const wrapper = await mounted()
    const buttons = wrapper.get('[data-test="phone-upload-buttons"]')
    expect(buttons.find('[data-testid="capture-photo"]').exists()).toBe(true)
    expect(buttons.find('[data-test="phone-pick-file"]').text()).toContain('purchase_invoice.pdf.pick_file')
    // Dropzóna zůstává v DOM kvůli inputu a ořezu, ale je skrytá.
    const dropzone = wrapper.findComponent({ name: 'PdfDropzone' })
    expect(dropzone.classes()).toContain('hidden')
  })

  it('Vybrat soubor klikne na skrytý input dropzóny', async () => {
    phoneRef.value = true
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = await mounted()
    await wrapper.get('[data-test="phone-pick-file"]').trigger('click')
    expect(click).toHaveBeenCalledTimes(1)
    expect((click.mock.contexts[0] as HTMLInputElement).type).toBe('file')
  })

  it('mimo telefon zůstává dropzóna viditelná a bez telefonních tlačítek', async () => {
    const wrapper = await mounted()
    expect(wrapper.find('[data-test="phone-upload-buttons"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'PdfDropzone' }).classes()).not.toContain('hidden')
    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(true)
  })

  it('na telefonu je rekapitulace DPH jako karty místo tabulky', async () => {
    phoneRef.value = true
    const wrapper = await mounted()
    const cards = wrapper.get('[data-test="vat-recap-cards"]')
    expect(cards.text()).toContain('21 %')
    expect(cards.findAll('input')).toHaveLength(2)
  })

  it('mimo telefon zůstává rekapitulace DPH tabulkou', async () => {
    const wrapper = await mounted()
    expect(wrapper.find('[data-test="vat-recap-cards"]').exists()).toBe(false)
    expect(wrapper.findAll('th').some(th => th.text() === 'purchase_invoice.vat_recap.rate')).toBe(true)
  })

  it('lišta Uložit je na telefonu přilepená nad spodní lištou a Zpět se na telefonu skrývá', async () => {
    const wrapper = await mounted()
    const bar = wrapper.get('[data-test="purchase-editor-submit-bar"]')
    expect(bar.classes()).toContain('max-md:sticky')
    expect(bar.classes()).toContain('max-md:bottom-[calc(var(--app-footer-height,0px)+0.5rem)]')
    expect(wrapper.get('[data-test="purchase-editor-back"]').classes()).toContain('max-md:hidden')
    expect(wrapper.get('[data-test="purchase-editor-save"]').classes()).toContain('max-md:flex-1')
  })
})
