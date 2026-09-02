import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PurchaseInvoice } from '@/api/purchaseInvoices'

// Náhled originálu vedle dokladu místo pod ním (useSidePreview + DocumentSidePreview).
// Práh je v JS (media query), ne v `2xl:` třídách — jinak by v DOM byly dva <iframe>y
// a prohlížeč by PDF stáhl dvakrát. Proto se dá i testovat.

const m = vi.hoisted(() => {
  const store: { flags: Record<string, boolean> | null } = { flags: null }
  return {
    get: vi.fn(),
    activity: vi.fn(),
    patchPagePrefs: vi.fn(),
    store,
    prefs: { get value() { return store } },
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '258' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: m.get,
    activity: m.activity,
    pdfUrl: (id: number, inline?: boolean) => `/api/purchase-invoices/${id}/pdf${inline ? '?inline=1' : ''}`,
    sourceUrl: () => '',
    ourPdfUrl: () => '',
    isdocUrl: () => '',
    pohodaUrl: () => '',
  },
}))

// Preference se v testu neukládají na server — zajímá nás jen, CO se ukládá a co se čte.
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => m.prefs,
  patchPagePrefs: m.patchPagePrefs,
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
import { SIDE_PREVIEW_FLAG } from '@/composables/useSidePreview'

/** Okno nad / pod prahem. jsdom vlastní matchMedia hlásí vždy `matches: false`. */
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

function makeInvoice(overrides: Partial<PurchaseInvoice> = {}): PurchaseInvoice {
  return {
    id: 258,
    supplier_id: 1,
    vendor_id: 5,
    varsymbol: '2026258',
    vendor_invoice_number: 'FP-258',
    vendor_company_name: 'Dodavatel s.r.o.',
    document_kind: 'invoice',
    issue_date: '2026-06-01',
    tax_date: '2026-06-01',
    due_date: '2026-06-15',
    received_at: '2026-06-02',
    currency_id: 1,
    currency: 'CZK',
    reverse_charge: false,
    is_fixed_asset: false,
    vendor_is_vat_payer: true,
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_deductible: true,
    language: 'cs',
    total_without_vat: 1000,
    total_vat: 210,
    total_with_vat: 1210,
    rounding: 0,
    advance_paid_amount: 0,
    amount_to_pay: 1210,
    status: 'paid',
    booked_at: null,
    paid_at: '2026-06-14',
    cancelled_at: null,
    pdf_path: '/archive/258.pdf',
    pdf_hash: 'abc123',
    pdf_size_bytes: 12345,
    pdf_original_name: 'faktura.pdf',
    pdf_uploaded_at: '2026-06-01',
    corrected_by: [],
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

const SIDE = '[data-test="document-side-preview"]'

async function mountDetail() {
  const wrapper = mount(InvoiceDetail, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('InvoiceDetail.vue — náhled originálu vedle dokladu', () => {
  beforeEach(() => {
    m.get.mockReset()
    m.activity.mockReset()
    m.activity.mockResolvedValue([])
    m.patchPagePrefs.mockReset()
    // Uživatel má náhled otevřený — lepkavá volba z user_preferences.
    m.store.flags = { [SIDE_PREVIEW_FLAG]: true }
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('nad prahem se náhled vykreslí v bočním sloupci, ne pod dokladem', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice())
    const wrapper = await mountDetail()

    const side = wrapper.find(SIDE)
    expect(side.exists()).toBe(true)
    // Právě jeden iframe, a to ten v bočním sloupci — jinak by se PDF stahovalo dvakrát.
    expect(wrapper.findAll('iframe')).toHaveLength(1)
    expect(side.find('iframe').attributes('src')).toContain('inline=1')
  })

  it('pod prahem boční sloupec není a náhled zůstává rozbalený pod dokladem', async () => {
    stubViewport(false)
    m.get.mockResolvedValue(makeInvoice())
    const wrapper = await mountDetail()

    expect(wrapper.find(SIDE).exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(1)
  })

  it('doklad bez PDF → boční sloupec se nevykreslí ani nad prahem a doklad má celou šířku', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice({ pdf_path: null, pdf_original_name: null, pdf_size_bytes: null }))
    const wrapper = await mountDetail()

    expect(wrapper.find(SIDE).exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(0)
    // Obal se nepřepne do flexu, obsah si drží dosavadní max-w-5xl bez konkurenta.
    expect(wrapper.html()).not.toContain('flex items-start gap-4')
  })

  it('zavřený náhled nevykreslí ani boční sloupec, ani iframe pod dokladem', async () => {
    stubViewport(true)
    m.store.flags = { [SIDE_PREVIEW_FLAG]: false }
    m.get.mockResolvedValue(makeInvoice())
    const wrapper = await mountDetail()

    expect(wrapper.find(SIDE).exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(0)
  })

  it('bez uložené volby je náhled zavřený (dosavadní výchozí stav)', async () => {
    stubViewport(true)
    m.store.flags = null
    m.get.mockResolvedValue(makeInvoice())
    const wrapper = await mountDetail()

    expect(wrapper.find(SIDE).exists()).toBe(false)
    expect(wrapper.findAll('iframe')).toHaveLength(0)
  })

  it('zavření bočního náhledu se uloží do preferencí, aby další doklad věděl', async () => {
    stubViewport(true)
    m.get.mockResolvedValue(makeInvoice())
    const wrapper = await mountDetail()

    await wrapper.find('[data-test="document-side-preview-close"]').trigger('click')
    await flushPromises()

    expect(m.patchPagePrefs).toHaveBeenCalledWith(
      'purchase_invoices',
      { flags: { [SIDE_PREVIEW_FLAG]: false } },
    )
    expect(wrapper.find(SIDE).exists()).toBe(false)
  })
})
