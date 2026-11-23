import { describe, expect, it, vi } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Úhrada převzatá z POHODY bez protějšku v MyÚčtu (úhrada minulého roku) se nemaže:
// místo ✕ je zámek a zdroj je „Převzato" s poznámkou v tooltipu. Daňový doklad k ní
// server odmítne, takže se ani nenabízí. Ruční platba vedle ní ✕ i tlačítko má dál.

const m = vi.hoisted(() => ({
  invoice: {
    id: 9, supplier_id: 1, status: 'paid', invoice_type: 'proforma', payment_status: 'paid',
    varsymbol: '2510001', currency: 'CZK', language: 'cs', amount_to_pay: 1210, paid_total: 1210, advance_paid_amount: 0,
    issue_date: '2025-10-01', tax_date: '2025-10-01', due_date: '2025-10-15', reverse_charge: false,
    totals: { without_vat: 1000, vat: 210, with_vat: 1210, rounding: 0 }, vat_breakdown: [],
    items: [{ id: 1, description: 'Syntetická položka', quantity: 1, unit: 'ks', unit_price_without_vat: 1000, vat_rate: 21 }],
    client_id: 3, client_company_name: 'Syntetický odběratel', public_token: null, public_viewed_at: null,
  } as Record<string, unknown>,
  payments: [
    {
      id: 71, invoice_id: 9, paid_on: '2025-12-31', amount: 1000, currency: 'CZK', variable_symbol: null,
      bank_reference: null, note: 'Převzato z POHODY, úhrada v minulém roce', source: 'settlement',
      bank_transaction_id: null, tax_document_invoice_id: null, created_at: '2026-01-05 10:00:00',
      taken_over: true, taken_over_locked: true, prior_year: true,
    },
    {
      id: 72, invoice_id: 9, paid_on: '2026-02-01', amount: 210, currency: 'CZK', variable_symbol: null,
      bank_reference: null, note: null, source: 'manual',
      bank_transaction_id: null, tax_document_invoice_id: null, created_at: '2026-02-01 10:00:00',
      taken_over: false, taken_over_locked: false, prior_year: false,
    },
    {
      id: 73, invoice_id: 9, paid_on: '2025-11-30', amount: 100, currency: 'CZK', variable_symbol: null,
      bank_reference: null, note: 'Převzato z Money S3, zápočet interním dokladem ID25001', source: 'settlement',
      bank_transaction_id: null, tax_document_invoice_id: null, created_at: '2026-01-05 10:00:00',
      taken_over: true, taken_over_locked: false, prior_year: false, tax_declared_by_origin: true,
    },
  ],
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    getWorkReport: vi.fn(async () => null),
    listPayments: vi.fn(async () => ({ payments: m.payments, bank_transactions: [], paid_total: 1210, amount_to_pay: 1210, remaining: 0, payment_status: 'paid' })),
    pdfUrl: (id: number) => `/api/invoices/${id}/pdf`,
  }),
}))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true, canWrite: () => true, isClientRole: false, isSuperadmin: false,
    isCompanyAdminRole: true, hasCommercialFeatures: true, invoicePublicLinksEnabled: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: false, is_vat_payer: true } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

async function mountDetail() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = shallowMount(InvoiceDetail, {
    global: { plugins: [router], stubs: { DocumentPreviewSplit: { template: '<div><slot /></div>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('převzatá úhrada v detailu faktury', () => {
  it('u úhrady minulého roku z POHODY skryje ✕ i daňový doklad a ukáže zámek a „Převzato"', async () => {
    const wrapper = await mountDetail()
    const taken = wrapper.find('[data-test="payment-taken-over"]')
    expect(taken.exists()).toBe(true)
    expect(taken.text()).toBe('invoice.payments.taken_over')
    expect(taken.attributes('title')).toContain('Převzato z POHODY, úhrada v minulém roce')

    const lock = wrapper.find('[data-test="payment-locked-taken-over"]')
    expect(lock.exists()).toBe(true)
    expect(lock.attributes('title')).toBe('invoice.payments.locked_taken_over')

    const takenRow = lock.element.closest('tr')!
    expect(takenRow.textContent).not.toContain('✕')
    expect(takenRow.textContent).not.toContain('invoice.payments.create_tax_doc')
    wrapper.unmount()
  })

  it('ruční platbě vedle ní ✕ i vystavení daňového dokladu ponechá', async () => {
    const wrapper = await mountDetail()
    const rows = wrapper.findAll('tr').filter(r => r.text().includes('invoice.payments.sources.manual'))
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('✕')
    expect(rows[0].text()).toContain('invoice.payments.create_tax_doc')
    expect(wrapper.findAll('[data-test="payment-locked-taken-over"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('platbě z Money S3 ponechá ✕, ale daňový doklad nenabídne (daň přiznal původní program)', async () => {
    const wrapper = await mountDetail()
    const rows = wrapper.findAll('tr').filter(r => r.text().includes('ID25001') || r.html().includes('ID25001'))
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('✕')
    expect(rows[0].text()).not.toContain('invoice.payments.create_tax_doc')
    wrapper.unmount()
  })
})
