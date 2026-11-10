import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Detail faktury, karta Sklad: výpadek jednoho ze dvou seznamů (výdejky k faktuře,
// zdrojové příjemky) ukáže chybu vedle druhého seznamu, ne místo obou.

const m = vi.hoisted(() => ({
  invoice: {} as Record<string, unknown>,
  documentsForInvoice: vi.fn(),
  sourceReceipts: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}))

function baseInvoice(over: Record<string, unknown> = {}) {
  return {
    id: 9, supplier_id: 1, status: 'sent', invoice_type: 'invoice',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    items: [{ id: 1, description: 'Šroub', quantity: 5, unit_price_without_vat: 100 }],
    public_token: null, public_viewed_at: null, language: 'cs',
    ...over,
  }
}

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    pdfUrl: (id: number, download = false) => `/api/invoices/${id}/pdf${download ? '?download=1' : ''}`,
  }),
}))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi({ documentsForInvoice: m.documentsForInvoice, sourceReceipts: m.sourceReceipts }) }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    isCompanyAdminRole: true,
    hasCommercialFeatures: true,
    invoicePublicLinksEnabled: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: true } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params?.error ? `${key}: ${params.error}` : key), te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'
import InvoiceStockCard from '@/components/stock/InvoiceStockCard.vue'

type DetailVm = { stockCardDocuments: { id: number }[]; stockDocumentsError: string }

async function mountDetail() {
  m.invoice = baseInvoice()
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

function mountCard(props: { documents: unknown[]; error: string }) {
  return mount(InvoiceStockCard, {
    props: { loading: false, ...props } as never,
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="String(to)"><slot /></a>' } } },
  })
}

const receipt = { id: 41, doc_number: 'PR-2026-0001', doc_type: 'receipt', status: 'posted' }
const issue = { id: 42, doc_number: 'VY-2026-0001', doc_type: 'issue', status: 'posted' }

afterEach(() => { vi.clearAllMocks() })

describe('InvoiceDetail: karta Sklad', () => {
  it('při chybě výdejek drží zdrojové příjemky a chybu pojmenuje', async () => {
    m.documentsForInvoice.mockRejectedValue(new Error('boom'))
    m.sourceReceipts.mockResolvedValue([receipt])
    const vm = (await mountDetail()).vm as unknown as DetailVm
    expect(vm.stockCardDocuments.map(d => d.id)).toEqual([41])
    expect(vm.stockDocumentsError).toContain('stock.invoice_card.issues_failed')
    expect(vm.stockDocumentsError).not.toContain('stock.invoice_card.receipts_failed')
  })

  it('při chybě příjemek drží výdejky', async () => {
    m.documentsForInvoice.mockResolvedValue([issue])
    m.sourceReceipts.mockRejectedValue(new Error('boom'))
    const vm = (await mountDetail()).vm as unknown as DetailVm
    expect(vm.stockCardDocuments.map(d => d.id)).toEqual([42])
    expect(vm.stockDocumentsError).toContain('stock.invoice_card.receipts_failed')
  })
})

describe('InvoiceStockCard', () => {
  it('ukáže chybu vedle seznamu, který se načetl', () => {
    const wrapper = mountCard({ documents: [receipt], error: 'stock.invoice_card.issues_failed: boom' })
    expect(wrapper.find('[data-test="stock-card-error"]').text()).toContain('issues_failed')
    expect(wrapper.find('a[href="/stock/documents/41"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('stock.invoice_card.empty')
  })

  it('když se nenačetlo nic, nehlásí prázdnou kartu', () => {
    const wrapper = mountCard({ documents: [], error: 'x' })
    expect(wrapper.find('[data-test="stock-card-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('stock.invoice_card.empty')
  })

  it('retry vyvolá nové načtení', async () => {
    const wrapper = mountCard({ documents: [], error: 'x' })
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('bez chyby a dokladů ukáže prázdný stav', () => {
    expect(mountCard({ documents: [], error: '' }).text()).toContain('stock.invoice_card.empty')
  })
})
