import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { shallowMount, flushPromises, RouterLinkStub } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api } from '@/api/client'
import type { Invoice } from '@/api/invoices'

const m = vi.hoisted(() => ({
  invoice: {} as Partial<Invoice>,
  writable: true,
  get: vi.fn(),
  candidates: vi.fn(),
  link: vi.fn(),
  unlink: vi.fn(),
  warning: vi.fn(),
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: m.get,
    taxDocumentAdvanceCandidates: m.candidates,
    linkTaxDocumentAdvance: m.link,
    unlinkTaxDocumentAdvance: m.unlink,
    getWorkReport: vi.fn(async () => null),
    pdfUrl: (id: number) => `/api/invoices/${id}/pdf`,
  }),
}))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: (permission: string) => permission !== 'eet', canWrite: () => m.writable, isClientRole: false, isSuperadmin: false,
    isCompanyAdminRole: false, hasCommercialFeatures: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence', stock_enabled: false } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: m.warning }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

async function detail() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = shallowMount(InvoiceDetail, {
    global: {
      plugins: [router],
      stubs: { DocumentPreviewSplit: { template: '<div><slot /></div>' }, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

const linkedAdvance = { id: 4, varsymbol: 'ZA-4', status: 'paid', invoice_type: 'proforma' } as const

describe('vazba daňového dokladu k přijaté platbě na zálohu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.spyOn(api, 'get').mockResolvedValue({ data: [] })
    m.writable = true
    m.invoice = {
      id: 9, client_id: 3, invoice_type: 'tax_document', status: 'issued',
      varsymbol: 'DD-9', currency: 'CZK', language: 'cs',
      issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
      totals: { without_vat: 1000, vat: 210, with_vat: 1210, rounding: 0, advance_paid_amount: 0, amount_to_pay: 1210 },
      amount_to_pay: 1210, paid_total: 0, items: [], vat_breakdown: [],
    }
    m.get.mockImplementation(async () => m.invoice)
    m.candidates.mockResolvedValue([{ id: 4, varsymbol: 'ZA-4', currency: 'CZK', total_with_vat: 1210 }])
    m.link.mockResolvedValue({ advance_id: 4, payment_id: null, filed_vat_period: null, accounting_period_locked: false })
    m.unlink.mockResolvedValue({ advance_id: 4 })
    vi.stubGlobal('confirm', () => true)
  })
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('nabídne výběr zálohy, zapíše vazbu a obnoví odkaz a upozornění', async () => {
    const wrapper = await detail()
    expect(wrapper.text()).toContain('invoice.tax_document_advance.none')
    await wrapper.get('[data-test="tax-document-advance-link"]').trigger('click')
    await flushPromises()
    expect(m.candidates).toHaveBeenCalledWith(9)
    expect(wrapper.get('[data-test="tax-document-advance-modal"]').text()).toContain('ZA-4')
    m.invoice = { ...m.invoice, parent_invoice: linkedAdvance, parent_invoice_id: 4, can_unlink_tax_document_advance: true }
    m.link.mockResolvedValueOnce({ advance_id: 4, payment_id: null, filed_vat_period: 'DPH 10/2026', accounting_period_locked: false })
    await wrapper.get('[data-test="tax-document-advance-candidate"]').trigger('click')
    await flushPromises()
    expect(m.link).toHaveBeenCalledWith(9, 4)
    expect(wrapper.find('[data-test="tax-document-advance-modal"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ZA-4')
    expect(m.warning).toHaveBeenCalledWith('invoice.tax_document_advance.filed_period')
    wrapper.unmount()
  })

  it('ze zálohy nabídne DDKP a po výběru obnoví seznam navázaných dokladů', async () => {
    m.invoice = { ...m.invoice, invoice_type: 'proforma', status: 'paid' }
    m.candidates.mockResolvedValueOnce([{ id: 4, varsymbol: 'DD-4', currency: 'CZK', total_with_vat: 1210 }])
    const wrapper = await detail()
    await wrapper.get('[data-test="tax-document-advance-link"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="tax-document-advance-modal"]').text()).toContain('DD-4')
    m.invoice = { ...m.invoice, advance_tax_documents: [{ id: 4, varsymbol: 'DD-4', status: 'issued' }] }
    await wrapper.get('[data-test="tax-document-advance-candidate"]').trigger('click')
    await flushPromises()
    expect(m.link).toHaveBeenCalledWith(4, 9)
    expect(wrapper.get('[data-test="advance-tax-documents"]').text()).toContain('DD-4')
    wrapper.unmount()
  })

  it('nabídne zrušení vazby jen pokud ho server povolil', async () => {
    m.invoice = { ...m.invoice, parent_invoice: linkedAdvance, parent_invoice_id: 4, can_unlink_tax_document_advance: false }
    let wrapper = await detail()
    expect(wrapper.text()).toContain('ZA-4')
    expect(wrapper.find('[data-test="tax-document-advance-unlink"]').exists()).toBe(false)
    wrapper.unmount()

    m.invoice = { ...m.invoice, can_unlink_tax_document_advance: true }
    wrapper = await detail()
    m.invoice = { ...m.invoice, parent_invoice: null, parent_invoice_id: null, can_unlink_tax_document_advance: false }
    await wrapper.get('[data-test="tax-document-advance-unlink"]').trigger('click')
    await flushPromises()
    expect(m.unlink).toHaveBeenCalledWith(9)
    expect(wrapper.text()).toContain('invoice.tax_document_advance.none')
    wrapper.unmount()
  })

  it('uživateli bez práva zápisu neukáže navázání ani odpojení', async () => {
    m.writable = false
    let wrapper = await detail()
    expect(wrapper.text()).toContain('invoice.tax_document_advance.none')
    expect(wrapper.find('[data-test="tax-document-advance-link"]').exists()).toBe(false)
    wrapper.unmount()

    m.invoice = { ...m.invoice, parent_invoice: linkedAdvance, parent_invoice_id: 4, can_unlink_tax_document_advance: true }
    wrapper = await detail()
    expect(wrapper.text()).toContain('ZA-4')
    expect(wrapper.find('[data-test="tax-document-advance-unlink"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
