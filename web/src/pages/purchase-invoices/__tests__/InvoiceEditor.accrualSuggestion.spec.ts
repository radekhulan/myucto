import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice, PurchaseInvoiceItem } from '@/api/purchaseInvoices'

// Časové rozlišení z textu položky: editor období rozpoznané z popisu jen NABÍDNE,
// zapíše ho až na klik „Použít" a odmítnutí u téhož textu drží.

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  expenseSuggestions: vi.fn().mockResolvedValue({ items: {} }),
  vatClassifications: vi.fn().mockResolvedValue([]),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '302' }, query: {} }),
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
    expenseSuggestions: m.expenseSuggestions,
    dismissExtractionWarning: vi.fn(),
    acceptAiPostingSuggestion: vi.fn(),
    rejectAiPostingSuggestion: vi.fn(),
    listGrouped: vi.fn().mockResolvedValue({ data: [] }),
  },
}))

vi.mock('@/api/dimensions', () => ({
  dimensionsApi: { overview: vi.fn(), getDocument: vi.fn(), saveDocument: vi.fn() },
  compactDimensions: () => ({}),
}))
vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: ['bank_transfer', 'cash', 'card', 'direct_debit'] }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([
  { id: 1, account_code: '518.100', name: 'Grafické služby', is_active: true },
  { id: 2, account_code: '518.200', name: 'Hosting', is_active: true },
  { id: 3, account_code: '042', name: 'Pořízení majetku', is_active: true },
  { id: 4, account_code: '378', name: 'Jiné pohledávky', is_active: true },
  { id: 5, account_code: '602', name: 'Tržby', is_active: true },
  { id: 6, account_code: '518.900', name: 'Neaktivní náklad', is_active: false },
]) } }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn().mockResolvedValue([{ id: 1, rate_percent: 21, is_default: true }, { id: 3, rate_percent: 0 }]),
    currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', label: 'Kč' }]),
    units: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/api/stock', () => ({ stockApi: { searchItems: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: m.vatClassifications } }))
vi.mock('@/api/settings', () => ({ settingsApi: { createCurrency: vi.fn() } }))
vi.mock('@/api/clients', () => ({ clientsApi: { getVatStatus: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number, c?: string) => `${v} ${c ?? ''}`.trim(),
  formatDate: (d: string | null | undefined) => d ?? '',
}))
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
    currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false, dimensions_enabled: false },
  }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

function item(id: number, description: string, accrual: [string | null, string | null] = [null, null]): PurchaseInvoiceItem {
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
    accrual_from: accrual[0],
    accrual_to: accrual[1],
    stock_item_id: null,
  }
}

function makeInvoice(items: ReturnType<typeof item>[]): PurchaseInvoice {
  return {
    id: 302,
    vendor_id: 5,
    vendor_invoice_number: 'FP-302',
    varsymbol: '302',
    document_kind: 'invoice',
    issue_date: '2026-09-28',
    tax_date: '2026-09-28',
    due_date: '2026-10-12',
    received_at: '2026-09-29',
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
    items,
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

async function mountEditor(items: ReturnType<typeof item>[], header: Partial<PurchaseInvoice> = {}) {
  m.get.mockReset().mockResolvedValue({ ...makeInvoice(items), ...header })
  const wrapper = mount(InvoiceEditor, { global: { stubs } })
  await flushPromises()
  return wrapper
}

async function openItemDetails(wrapper: Awaited<ReturnType<typeof mountEditor>>) {
  for (const toggle of wrapper.findAll('[data-test="item-details-toggle"]')) await toggle.trigger('click')
}

describe('InvoiceEditor.vue — návrh časového rozlišení z textu položky', () => {
  beforeEach(() => {
    m.update.mockReset().mockResolvedValue({ ...makeInvoice([]), _warnings: [] })
    m.expenseSuggestions.mockReset().mockResolvedValue({ items: {} })
  })

  it('nabídne a uloží analytický účet ze šablony i při stejném druhu nákladu', async () => {
    m.expenseSuggestions.mockResolvedValue({ items: {
      1: { expense_kind: 'service', expense_account_code: '518.200', expense_rule_id: 5,
        confidence: 0.6, reason: 'Hosting', source: 'rule', auto: false, current_expense_kind: 'service' },
    } })
    const wrapper = await mountEditor([{ ...item(1, 'Hosting'), expense_kind: 'service', expense_account_code: null }])
    const hints = wrapper.findAllComponents({ name: 'ExpenseKindSuggestionHint' })
    expect(hints).toHaveLength(2)
    hints[0].vm.$emit('apply')
    await flushPromises()
    expect(wrapper.findAllComponents({ name: 'ExpenseKindSuggestionHint' })).toHaveLength(0)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update.mock.calls[0][1].items[0].expense_account_code).toBe('518.200')
  })

  it('přepnutí dodavatele na neplátce ponechá částky dokladu a zakáže odpočet', async () => {
    const wrapper = await mountEditor([item(1, 'Hosting')])
    const checkbox = wrapper.findAll('input[type="checkbox"]').find(input =>
      input.element.parentElement?.textContent?.includes('purchase_invoice.fields.vendor_is_vat_payer'))!
    await checkbox.setValue(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const payload = m.update.mock.calls[0][1]
    expect(payload.vendor_is_vat_payer).toBe(false)
    expect(payload.vat_deduction).toBe('none')
    expect(payload.items[0].vat_rate_id).toBe(1)
  })

  it('uloží ručně vybranou analytiku a zachová účet ostatních položek', async () => {
    const wrapper = await mountEditor([
      { ...item(1, 'Grafické podklady'), expense_kind: 'service', expense_account_code: null },
      { ...item(2, 'Hosting'), expense_kind: 'service', expense_account_code: '518.200' },
    ])
    expect(wrapper.findAll('select[aria-label="purchase_invoice.items.expense_account"]')).toHaveLength(0)
    await openItemDetails(wrapper)
    await wrapper.findAll('select[aria-label="purchase_invoice.items.expense_account"]')[0].setValue('518.100')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update.mock.calls[0][1].items.map((row: PurchaseInvoiceItem) => row.expense_account_code)).toEqual(['518.100', '518.200'])
  })

  it('nabízí pro službu pouze aktivní náklady na desktopu i mobilu', async () => {
    const wrapper = await mountEditor([{ ...item(1, 'Hosting'), expense_kind: 'service' }])
    await openItemDetails(wrapper)
    const selects = wrapper.findAll('select[aria-label="purchase_invoice.items.expense_account"]')
    expect(selects).toHaveLength(2)
    for (const select of selects) {
      expect(select.findAll('option').slice(1).map(option => option.attributes('value'))).toEqual(['518.100', '518.200'])
    }
  })

  it('nabízí účty pořízení dlouhodobého majetku a zachová dříve zvolený účet', async () => {
    const wrapper = await mountEditor([
      { ...item(1, 'Server'), expense_kind: 'fixed_asset', expense_account_code: '042' },
      { ...item(2, 'Starší kontace'), expense_kind: 'service', expense_account_code: '131' },
    ])
    await openItemDetails(wrapper)
    const selects = wrapper.findAll('select[aria-label="purchase_invoice.items.expense_account"]')
    expect(selects[0].findAll('option').slice(1).map(option => option.attributes('value'))).toEqual(['042'])
    expect(selects[1].findAll('option').slice(1).map(option => option.attributes('value'))).toEqual(['131', '518.100', '518.200'])
    expect((selects[1].element as HTMLSelectElement).value).toBe('131')
  })

  it('změní členění DPH jen u vybrané položky (issue #150)', async () => {
    m.vatClassifications.mockResolvedValueOnce([
      { id: 1, code: '23', label: 'Pořízení zboží z EU', supplier_id: null, is_reverse_charge: true, dphdp3_line: 3 },
      { id: 2, code: '24e', label: 'Přijetí služby z EU', supplier_id: null, is_reverse_charge: true, dphdp3_line: 5 },
    ])
    const wrapper = await mountEditor([
      { ...item(1, 'Správa kampaně'), vat_classification_code: '23' },
      { ...item(2, 'Krabice'), vat_classification_code: '23' },
    ], { reverse_charge: true, vat_classification_code: '24e' } as Partial<PurchaseInvoice>)
    expect(wrapper.findAll('[data-test="item-details-toggle"]')[0].classes()).toContain('bg-primary-50')
    expect(wrapper.find('[data-test="items-differ-from-header"]').exists()).toBe(true)

    await openItemDetails(wrapper)
    await wrapper.findAll('[data-test="item-vat-classification"]')[0].setValue('24e')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const payload = m.update.mock.calls[0][1]
    expect(payload.vat_classification_code).toBe('24e')
    expect(payload.items.map((row: PurchaseInvoiceItem) => row.vat_classification_code)).toEqual(['24e', '23'])
    expect(payload.items.map((row: PurchaseInvoiceItem) => row.unit_price_without_vat)).toEqual([100, 100])
  })

  it('kód hlavičky přepíše položky jen na výslovnou volbu', async () => {
    const wrapper = await mountEditor([
      { ...item(1, 'Správa kampaně'), vat_classification_code: '23' },
      { ...item(2, 'Licence'), vat_classification_code: '23' },
    ], { reverse_charge: true, vat_classification_code: '24e' } as Partial<PurchaseInvoice>)
    await wrapper.find('[data-test="items-differ-from-header"] button').trigger('click')
    expect(wrapper.find('[data-test="items-differ-from-header"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update.mock.calls[0][1].items.map((row: PurchaseInvoiceItem) => row.vat_classification_code)).toEqual(['24e', '24e'])
  })

  it('nabídne období jen u položky s rozpoznaným obdobím a bez vyplněného rozlišení', async () => {
    const wrapper = await mountEditor([
      item(1, 'Pojištění 28. 9. 2026 – 27. 9. 2027'),
      item(2, 'Konzultace, DUZP 28. 9. 2026'),
      item(3, 'Licence 10/2026 – 09/2027', ['2026-10-01', '2027-09-30']),
    ])

    // desktop tabulka + mobilní karta = 2 výskyty téže položky
    const hints = wrapper.findAll('[data-test="accrual-suggestion"]')
    expect(hints).toHaveLength(2)
    expect(hints[0].text()).toContain('accrual_suggest.detected')
  })

  it('Použít zapíše období na řádek a uloží ho, Nepoužívat návrh skryje', async () => {
    const wrapper = await mountEditor([
      item(1, 'Pojištění 28. 9. 2026 – 27. 9. 2027'),
      item(2, 'Nájem za září 2026'),
    ])
    expect(wrapper.findAll('[data-test="accrual-suggestion"]')).toHaveLength(4)

    await wrapper.findAll('[data-test="accrual-suggestion-apply"]')[0].trigger('click')
    await wrapper.findAll('[data-test="accrual-suggestion-dismiss"]')[0].trigger('click')
    expect(wrapper.findAll('[data-test="accrual-suggestion"]')).toHaveLength(0)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalled()
    const items = m.update.mock.calls[0][1].items
    expect([items[0].accrual_from, items[0].accrual_to]).toEqual(['2026-09-28', '2027-09-27'])
    expect([items[1].accrual_from, items[1].accrual_to]).toEqual([null, null])
  })
})
