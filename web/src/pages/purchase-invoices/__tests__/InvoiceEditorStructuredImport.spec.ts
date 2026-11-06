import { config, flushPromises, shallowMount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  importStructured: vi.fn(),
  uploadSubmission: vi.fn(),
  createInvoice: vi.fn(),
  updateInvoice: vi.fn(),
  get: vi.fn(),
  getSubmission: vi.fn(),
  expenseSuggestions: vi.fn(),
  canWrite: vi.fn(),
}))

// `locale` musí být v mocku taky: editor ho čte v computed formátování data,
// takže bez něj render spadne na `locale.value` a wrapper zůstane prázdný.
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    importStructured: m.importStructured,
    create: m.createInvoice,
    update: m.updateInvoice,
    get: m.get,
    expenseSuggestions: m.expenseSuggestions,
    pdfUrl: () => '',
  },
}))

vi.mock('@/api/purchaseInvoiceSubmissions', () => ({
  portalPurchaseInvoiceSubmissionsApi: {
    upload: m.uploadSubmission,
  },
  purchaseInvoiceSubmissionsApi: {
    get: m.getSubmission,
    previewUrl: () => '',
    downloadUrl: () => '',
  },
}))

vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: [] }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn().mockResolvedValue([{ id: 1, rate_percent: 21, is_default: true }]),
    currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', is_default: true }]),
    units: vi.fn().mockResolvedValue([
      { id: 1, code: 'ks', is_default: true },
      { id: 2, code: 'h', is_default: false },
    ]),
  },
}))
vi.mock('@/api/stock', () => ({ stockApi: { searchItems: vi.fn() } }))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/settings', () => ({ settingsApi: {} }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/clients', () => ({ clientsApi: { getVatStatus: vi.fn() } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/directives/vMath', () => ({ evalMath: () => null }))
vi.mock('@/composables/useRowFocus', () => ({ focusLastRow: vi.fn() }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), info: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isClientRole: true,
    hasCommercialFeatures: false,
    isDemo: false,
    canWrite: m.canWrite,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { stock_enabled: false } }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

// Stub bez slotu by schoval celý formulář; tady jde o něj, ne o rozložení s náhledem.
config.global.stubs = { ...config.global.stubs, DocumentPreviewSplit: { template: '<div><slot /></div>' } }

function importedInvoice() {
  return {
    id: 42,
    vendor_id: 7,
    vendor_invoice_number: 'SYNTHETIC-42',
    varsymbol: '42',
    document_kind: 'invoice',
    status: 'draft',
    issue_date: '2026-08-01',
    tax_date: '2026-08-01',
    due_date: '2026-08-15',
    received_at: '2026-08-02',
    currency_id: 1,
    exchange_rate: null,
    exchange_rate_date: null,
    exchange_rate_source: 'manual',
    reverse_charge: false,
    prices_include_vat: false,
    is_fixed_asset: false,
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_deductible: true,
    language: 'cs',
    note_above_items: null,
    note_below_items: null,
    payment_account_number: null,
    payment_bank_code: null,
    payment_iban: null,
    payment_bic: null,
    payment_variable_symbol: null,
    payment_method: 'bank_transfer',
    advance_paid_amount: 0,
    rounding: 0,
    payment_currency_id: null,
    payment_exchange_rate: null,
    paid_amount_payment_ccy: null,
    paid_amount_invoice_ccy: null,
    exchange_diff_base: null,
    expense_category_id: null,
    vat_classification_code: null,
    parent_purchase_invoice_id: null,
    items: [{
      id: 5,
      description: 'Syntetická položka',
      quantity: 1,
      unit: 'ks',
      unit_price_without_vat: 100,
      vat_rate_id: 1,
      order_index: 0,
      expense_kind: null,
      accrual_from: null,
      accrual_to: null,
      stock_item_id: null,
    }],
    extraction_warning: null,
    vat_overrides: [],
    vat_allocations: [],
    vendor_is_vat_payer: true,
    ai_posting_suggestion: null,
    pdf_path: null,
  }
}

async function createEditorRouter(path = '/purchase-invoices/new') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/purchase-invoices/new', component: InvoiceEditor },
      { path: '/purchase-invoices/:id/edit', component: InvoiceEditor },
      { path: '/purchase-invoices/:id', component: { render: () => null } },
      { path: '/portal/purchase-invoice-submissions', component: { render: () => null } },
    ],
  })
  await router.push(path)
  await router.isReady()
  return router
}

describe('InvoiceEditor — strukturovaný import', () => {
  // Náhled odloženého PDF si na soubor říká o object URL. Shim, kterým ho
  // vitest v jsdom nahrazuje, si sahá do vnitřku jsdom Blobu — a `File` je
  // v Node globál, takže tam ten vnitřek není a shim spadne na `_buffer`
  // mimo test (neodchycené odmítnutí = pád celé sady, i když testy projdou).
  // V prohlížeči je to korektní volání, proto se stubuje test, ne komponenta.
  beforeEach(() => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-pending-pdf')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    m.importStructured.mockReset().mockResolvedValue({
      purchase_invoice_id: 42,
      purchase_invoice_ids: [42],
      source: 'isdoc',
      duplicate: false,
    })
    m.uploadSubmission.mockReset().mockResolvedValue({
      items: [{ id: 77 }],
      created: 1,
      duplicates: 0,
      errors: [],
    })
    m.createInvoice.mockReset()
    m.updateInvoice.mockReset().mockImplementation(async (_id, payload) => ({ ...importedInvoice(), ...payload }))
    m.get.mockReset().mockResolvedValue(importedInvoice())
    m.getSubmission.mockReset()
    m.expenseSuggestions.mockReset().mockResolvedValue({ items: {} })
    m.canWrite.mockReset().mockImplementation((permission: string) => permission === 'documents.submit')
  })

  it('po přechodu z /new načte importovaný koncept bez reloadu stránky', async () => {
    const router = await createEditorRouter()

    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router],
        directives: { math: {} },
      },
    })
    await flushPromises()

    const file = new File(['<Invoice/>'], 'synthetic.isdoc', { type: 'application/xml' })
    wrapper.findComponent({ name: 'PdfDropzone' }).vm.$emit('file-dropped', file)
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/purchase-invoices/42/edit')
    expect(m.get).toHaveBeenCalledWith(42)
    expect(wrapper.find('input[maxlength="50"]').element).toHaveProperty('value', 'SYNTHETIC-42')
  })

  it('doklad mimo koncept otevře na detailu, protože by ho editor neuložil', async () => {
    m.get.mockResolvedValueOnce({ ...importedInvoice(), status: 'paid' })
    const router = await createEditorRouter('/purchase-invoices/42/edit')
    shallowMount(InvoiceEditor, { global: { plugins: [router], directives: { math: {} } } })
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/purchase-invoices/42')
  })

  it('doklad mimo koncept s ?force=1 nechá v editoru', async () => {
    m.get.mockResolvedValueOnce({ ...importedInvoice(), status: 'paid' })
    const router = await createEditorRouter('/purchase-invoices/42/edit?force=1')
    shallowMount(InvoiceEditor, { global: { plugins: [router], directives: { math: {} } } })
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/purchase-invoices/42/edit')
  })

  it('předá běžné PDF účetní jedním klikem bez založení neúplné faktury', async () => {
    m.importStructured.mockRejectedValueOnce({
      response: { data: { error: { code: 'no_embedded_isdoc' } } },
    })
    const router = await createEditorRouter()
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router],
        directives: { math: {} },
      },
    })
    await flushPromises()

    const file = new File(['%PDF-1.4\n% synthetic plain invoice'], 'plain-invoice.pdf', {
      type: 'application/pdf',
    })
    wrapper.findComponent({ name: 'PdfDropzone' }).vm.$emit('file-dropped', file)
    await flushPromises()

    const handoff = wrapper.find('[data-testid="handoff-pending-document"]')
    expect(handoff.exists()).toBe(true)
    await handoff.trigger('click')
    await flushPromises()

    expect(m.uploadSubmission).toHaveBeenCalledWith([file], '', 'invoice')
    expect(m.createInvoice).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/portal/purchase-invoice-submissions')
  })

  it('fallback skryje klientovi bez práva předávat doklady', async () => {
    m.canWrite.mockReturnValue(false)
    m.importStructured.mockRejectedValueOnce({
      response: { data: { error: { code: 'no_embedded_isdoc' } } },
    })
    const router = await createEditorRouter()
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router],
        directives: { math: {} },
      },
    })
    await flushPromises()

    const file = new File(['%PDF-1.4\n% synthetic restricted invoice'], 'restricted.pdf', {
      type: 'application/pdf',
    })
    wrapper.findComponent({ name: 'PdfDropzone' }).vm.$emit('file-dropped', file)
    await flushPromises()

    expect(wrapper.find('[data-testid="handoff-pending-document"]').exists()).toBe(false)
    expect(m.uploadSubmission).not.toHaveBeenCalled()
  })

  it('zaokrouhlí nový časový základ před výpočtem DPH', async () => {
    const item = { ...importedInvoice().items[0], unit: 'h', quantity: 0.05, duration_minutes: 3, unit_price_without_vat: 0.9 }
    m.get.mockResolvedValueOnce({ ...importedInvoice(), items: [item] })
    const router = await createEditorRouter('/purchase-invoices/42/edit')
    const wrapper = shallowMount(InvoiceEditor, {
      global: { plugins: [router], directives: { math: {} } },
    })
    await flushPromises()
    const vm = wrapper.vm as unknown as { itemTotal: (row: typeof item) => { base: number; vat: number; with: number } }
    expect(vm.itemTotal(item)).toEqual({ base: 0.05, vat: 0.01, with: 0.06 })
  })

  it('zachová přesné minuty časové položky v editoru a payloadu', async () => {
    m.get.mockResolvedValueOnce({
      ...importedInvoice(),
      items: [{
        ...importedInvoice().items[0],
        quantity: 1 / 3,
        duration_minutes: 20,
        unit: 'h',
        unit_price_without_vat: 333.333333,
      }],
    })
    const router = await createEditorRouter('/purchase-invoices/42/edit')
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router],
        directives: { math: {} },
      },
    })
    await flushPromises()

    const duration = wrapper.findComponent({ name: 'DurationInput' })
    expect(duration.exists()).toBe(true)
    expect(duration.props('durationMinutes')).toBe(20)

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const payload = m.updateInvoice.mock.calls[0][1]
    expect(payload.items[0]).toMatchObject({
      quantity: 1 / 3,
      duration_minutes: 20,
      unit_price_without_vat: 333.333333,
    })
  })

  it('zahodí přesné minuty při změně jednotky nebo navázání skladu', async () => {
    const item = {
      ...importedInvoice().items[0],
      quantity: 1 / 3,
      duration_minutes: 20,
      unit: 'h',
    }
    m.get.mockResolvedValueOnce({ ...importedInvoice(), items: [item] })
    const router = await createEditorRouter('/purchase-invoices/42/edit')
    const wrapper = shallowMount(InvoiceEditor, {
      global: { plugins: [router], directives: { math: {} } },
    })
    await flushPromises()

    const vm = wrapper.vm as unknown as { form: { items: typeof item[] }; onStockSelect: (index: number, stockItemId: number | null) => void }
    const row = vm.form.items[0]
    const unit = wrapper.find('select[data-item-unit]')
    await unit.setValue('ks')
    expect(row.duration_minutes).toBeNull()

    row.quantity = 2
    await unit.setValue('h')
    expect(row.quantity).toBe(2)
    expect(row.duration_minutes).toBeNull()

    row.duration_minutes = 20
    vm.onStockSelect(0, 77)
    expect(row.duration_minutes).toBeNull()
    vm.onStockSelect(0, null)
    expect(row.duration_minutes).toBeNull()

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.updateInvoice.mock.calls[0][1].items[0]).toMatchObject({
      quantity: 2,
      duration_minutes: null,
      stock_item_id: null,
    })
  })
})
