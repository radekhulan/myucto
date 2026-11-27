import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, onMounted } from 'vue'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Pole protiúčtu na řádku přijatého dokladu ukazuje jako nápovědu závazek, který se
// skutečně použije: z karty dodavatele (včetně účtu firmy pro zahraniční dodavatele,
// `effective_payable_account_code`), jinak z předkontace. Data nese karta dodavatele,
// kterou VendorPicker už načetl, takže změna dodavatele nápovědu přepočítá bez dotazu.

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
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listAccounts: vi.fn().mockResolvedValue([]),
    listPostingRules: vi.fn().mockResolvedValue({
      'invoice.services.received': { rule_key: 'invoice.services.received', debit_account_code: '518', credit_account_code: '321', is_active: true },
    }),
  },
}))
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
vi.mock('@/api/clients', () => ({ clientsApi: { getVatStatus: vi.fn().mockResolvedValue({ is_vat_payer: true }) } }))
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

const vendors: Record<number, Record<string, unknown>> = {
  5: { id: 5, company_name: 'Dodavatel CZ', effective_payable_account_code: '321.200' },
  6: { id: 6, company_name: 'Dodavatel DE', effective_payable_account_code: '321.300' },
  7: { id: 7, company_name: 'Dodavatel bez karty', effective_payable_account_code: null },
}

const VendorPickerStub = defineComponent({
  name: 'VendorPicker',
  props: { modelValue: { type: Number, default: null } },
  emits: ['update:modelValue', 'selected', 'resolved'],
  setup(props, { emit, expose }) {
    expose({ upsert: () => {}, reload: () => {} })
    onMounted(() => emit('resolved', props.modelValue ? vendors[props.modelValue] ?? null : null))
    return {}
  },
  template: '<div data-test="vendor-picker-stub" />',
})

const ItemPostingFieldsStub = defineComponent({
  name: 'ItemPostingFields',
  props: { defaultCounter: { type: String, default: null } },
  template: '<span data-test="item-posting-default">{{ defaultCounter }}</span>',
})

function makeInvoice(): PurchaseInvoice {
  return {
    id: 301, vendor_id: 5, vendor_invoice_number: 'FP-301', varsymbol: '301', document_kind: 'invoice',
    issue_date: '2026-06-01', tax_date: '2026-06-01', due_date: '2026-06-15', received_at: '2026-06-02',
    currency_id: 1, currency: 'CZK', exchange_rate: null, exchange_rate_source: 'manual', reverse_charge: false,
    vat_deduction: 'full', vat_deduction_percent: 100, tax_deductible: true, language: 'cs', status: 'draft',
    advance_paid_amount: 0, rounding: 0,
    items: [{
      id: 1, description: 'Služba', quantity: 1, duration_minutes: null, unit: 'ks', unit_price_without_vat: 100,
      vat_rate_id: 1, order_index: 0, expense_kind: null, accrual_from: null, accrual_to: null, stock_item_id: null,
      posting_rule_key: 'invoice.services.received', counter_account_code: null,
    }],
    vat_overrides: [], vat_allocations: [], locked: { is_locked: false },
  } as unknown as PurchaseInvoice
}

const stubs = {
  AutomationBadge: true, ConfidenceLabel: true, StockDescriptionField: true, ExpenseKindSuggestionHint: true,
  VendorPicker: VendorPickerStub, ItemPostingFields: ItemPostingFieldsStub, ClientFormModal: true, PdfDropzone: true,
  PaymentCurrencyBlock: true, ExchangeRateInput: true, EmptyState: true, DocumentSidePreview: true,
}

async function mounted() {
  const wrapper = mount(InvoiceEditor, { global: { stubs } })
  await flushPromises()
  await wrapper.find('[data-test="item-details-toggle"]').trigger('click')
  await flushPromises()
  return wrapper
}

function defaults(wrapper: Awaited<ReturnType<typeof mounted>>): string[] {
  return wrapper.findAll('[data-test="item-posting-default"]').map(w => w.text())
}

describe('InvoiceEditor.vue (přijaté) — nápověda účtu závazku z karty dodavatele', () => {
  beforeEach(() => {
    m.get.mockReset().mockResolvedValue(makeInvoice())
    m.overview.mockReset().mockResolvedValue({ enabled: false, group: null, types: [], values: [] })
    m.canPost = true
  })

  it('ukáže účet závazku z karty dodavatele', async () => {
    const wrapper = await mounted()
    expect(defaults(wrapper).length).toBeGreaterThan(0)
    expect(new Set(defaults(wrapper))).toEqual(new Set(['321.200']))
  })

  it('po změně dodavatele nápovědu přepočítá, bez karty ukáže závazek z předkontace', async () => {
    const wrapper = await mounted()
    const picker = wrapper.findComponent(VendorPickerStub)

    picker.vm.$emit('update:modelValue', 6)
    picker.vm.$emit('resolved', vendors[6])
    await flushPromises()
    expect(new Set(defaults(wrapper))).toEqual(new Set(['321.300']))

    picker.vm.$emit('update:modelValue', 7)
    picker.vm.$emit('resolved', vendors[7])
    await flushPromises()
    expect(new Set(defaults(wrapper))).toEqual(new Set(['321']))
  })

  it('karta jiného dodavatele, než je vybraný, se nepoužije', async () => {
    const wrapper = await mounted()
    wrapper.findComponent(VendorPickerStub).vm.$emit('update:modelValue', 7)
    await flushPromises()
    expect(new Set(defaults(wrapper))).toEqual(new Set(['321']))
  })
})
