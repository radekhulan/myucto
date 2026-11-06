import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// ── Mockovaný stav (hoisted) ──────────────────────────────────────────────────
const m = vi.hoisted(() => ({
  get: vi.fn(),
  activity: vi.fn(),
  receiptPropose: vi.fn(),
  receiptList: vi.fn(),
  push: vi.fn(),
  route: null as null | { params: { id: string } },
  stockEnabled: false,
  stockRead: true,
  stockWrite: true,
  perms: {} as Record<string, boolean>,
  isAdmin: false,
}))

// vue-router — useRoute (id), useRouter (push), RouterLink jako jednoduchý stub.
vi.mock('vue-router', async () => {
  const { reactive } = await vi.importActual<typeof import('vue')>('vue')
  m.route = reactive({ params: { id: '258' } })
  return {
    useRoute: () => m.route,
    useRouter: () => ({ push: m.push }),
    RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  }
})

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
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
  formatUnitPrice: (v: number, c?: string) => `${v} ${c ?? ''}`.trim(),
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
    canRead: (permission: string) => permission === 'stock' ? m.stockRead : true,
    canWrite: (permission: string) => m.perms[permission] ?? (permission === 'stock' ? m.stockWrite : true),
    get isCompanyAdminRole() { return m.isAdmin },
    hasCommercialFeatures: true,
    isClientRole: false,
    isSuperadmin: false,
    user: { id: 1 },
  }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplier: {
      accounting_mode: 'double_entry',
      get stock_enabled() { return m.stockEnabled },
    },
  }),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { postPurchase: vi.fn() },
  postingErrorI18nKey: () => 'error',
  postingErrorMessage: () => 'error',
}))

vi.mock('@/api/stock', () => ({
  stockApi: { receiptPropose: m.receiptPropose, receiptList: m.receiptList },
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
  ActionBar: { name: 'ActionBar', props: ['actions'], template: '<div />' },
  LockedBadge: true,
  PostingBadge: true,
  WhyChip: true,
  PdfDropzone: true,
  LinkedDocumentsPanel: true,
  InvoiceBankMatchModal: { name: 'InvoiceBankMatchModal', props: ['docId'], template: '<div data-test="bank-match-modal" />' },
  StockReceiptModal: {
    name: 'StockReceiptModal',
    emits: ['close', 'created'],
    template: '<button data-test="create-receipt" @click="$emit(\'created\', { id: 777 })" />',
  },
}

describe('InvoiceDetail.vue — skutečný nárok na odpočet', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
    m.receiptList.mockResolvedValue([])
    m.receiptPropose.mockResolvedValue({ lines: [], cost_candidates: [], purchase_invoice_id: 258, not_receivable_kind: 'advance' })
    m.perms = {}
    m.isAdmin = false
  })

  it('hlavička „bez nároku" s plným rozpisem ukazuje plný nárok podle rozpisu a varování', async () => {
    m.get.mockResolvedValue(makeInvoice({
      vat_deduction: 'none',
      vat_treatment: {
        source: 'allocations', effective: 'full',
        parts: [{ vat_deduction: 'full', vat_deduction_percent: 100, base_amount: 1000, vat_amount: 210 }],
        items: [{ id: 1, effective_code: '40', state: 'allocations' }],
        warnings: ['header_differs_from_allocations'],
      },
    }))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    const cell = wrapper.get('[data-test="effective-vat-deduction"]').text()
    expect(cell).toContain('purchase_invoice.vat_deduction.full')
    expect(cell).not.toContain('purchase_invoice.vat_deduction.none')
    expect(cell).toContain('purchase_invoice.vat_treatment.by_allocations')
    expect(wrapper.get('[data-test="vat-treatment-warnings"]').text())
      .toContain('purchase_invoice.vat_treatment.warning.header_differs_from_allocations')
    wrapper.unmount()
  })

  it('smíšený rozpis vypíše části a položka 41 pod hlavičkou „bez nároku" nese stav', async () => {
    m.get.mockResolvedValue(makeInvoice({
      items: [{ id: 7, description: 'Kniha', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_snapshot: 12, total_with_vat: 112, vat_classification_code: '41' }],
      vat_treatment: {
        source: 'allocations', effective: 'mixed',
        parts: [
          { vat_deduction: 'full', vat_deduction_percent: 100, base_amount: 500, vat_amount: 105 },
          { vat_deduction: 'none', vat_deduction_percent: 0, base_amount: 500, vat_amount: 105 },
        ],
        items: [{ id: 7, effective_code: '41', state: 'no_claim_header' }],
        warnings: [],
      },
    } as unknown as Partial<PurchaseInvoice>))
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()

    expect(wrapper.get('[data-test="effective-vat-deduction"]').text()).toContain('purchase_invoice.vat_treatment.mixed')
    expect(wrapper.findAll('[data-test="vat-treatment-parts"] li')).toHaveLength(2)
    expect(wrapper.find('[data-test="item-vat-state"]').text()).toContain('purchase_invoice.items.vat_state_no_claim_header_short')
    wrapper.unmount()
  })
})

describe('InvoiceDetail.vue — akce Změnit DPH zařazení', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
    m.receiptList.mockResolvedValue([])
    m.receiptPropose.mockResolvedValue({ lines: [], cost_candidates: [], purchase_invoice_id: 258, not_receivable_kind: 'advance' })
    m.perms = {}
    m.isAdmin = false
  })

  async function actionKeys(): Promise<string[]> {
    const wrapper = mount(InvoiceDetail, { global: { stubs } })
    await flushPromises()
    const actions = wrapper.findComponent({ name: 'ActionBar' }).props('actions') as { key: string; show?: boolean }[]
    const keys = actions.filter(a => a.show !== false).map(a => a.key)
    wrapper.unmount()
    return keys
  }

  it('role s právem purchase_invoices.vat_treatment akci vidí i u zaúčtovaného dokladu', async () => {
    m.perms['purchase_invoices.vat_treatment'] = true
    m.get.mockResolvedValue(makeInvoice({ status: 'booked' }))
    expect(await actionKeys()).toContain('vat-treatment')
  })

  it('bez práva ji nevidí (server by ji odmítl), správce ano', async () => {
    m.perms['purchase_invoices.vat_treatment'] = false
    m.get.mockResolvedValue(makeInvoice({ status: 'received' }))
    expect(await actionKeys()).not.toContain('vat-treatment')
    m.isAdmin = true
    expect(await actionKeys()).toContain('vat-treatment')
  })

  it('stornovaný doklad akci nenabízí', async () => {
    m.perms['purchase_invoices.vat_treatment'] = true
    m.get.mockResolvedValue(makeInvoice({ status: 'cancelled' }))
    expect(await actionKeys()).not.toContain('vat-treatment')
  })
})

describe('InvoiceDetail.vue — vynucená úprava v uzavřeném období', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
    m.receiptList.mockResolvedValue([])
    m.receiptPropose.mockResolvedValue({ lines: [], cost_candidates: [], purchase_invoice_id: 258, not_receivable_kind: 'advance' })
    m.push.mockReset()
    m.perms = {}
    m.isAdmin = true
  })

  it('zaúčtovaný doklad v uzavřeném období nabídne volbu režimu a předá ho editoru', async () => {
    m.get.mockResolvedValue(makeInvoice({
      status: 'booked',
      locked: { is_locked: true, reasons: ['posted', 'period_closed'], booked_at: null, journal_entry_id: 9, period_status: 'closed', date_locked: false, accountant_managed: false },
    }))
    const confirm = vi.spyOn(window, 'confirm')
    const wrapper = mount(InvoiceDetail, {
      global: { stubs: { ...stubs, Modal: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /></div>' } } },
    })
    await flushPromises()
    const actions = wrapper.findComponent({ name: 'ActionBar' }).props('actions') as { key: string; run?: () => void }[]
    actions.find(a => a.key === 'force-edit')?.run?.()
    await flushPromises()
    await vi.dynamicImportSettled()
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    await wrapper.get('[data-test="force-mode-reconcile"] input').setValue(true)
    await wrapper.get('[data-test="force-mode-confirm"]').trigger('click')
    expect(m.push).toHaveBeenCalledWith('/purchase-invoices/258/edit?force=1&force_mode=reconcile')
    confirm.mockRestore()
    wrapper.unmount()
  })
})
