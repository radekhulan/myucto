import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Telefon: detail faktury ukáže hned pod nadpisem částku, splatnost a stav úhrady,
// tlačítko PDF a sbalí karty nastavení dokladu. Mimo telefon zůstane beze změny.

const phoneRef = ref(true)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))

const m = vi.hoisted(() => ({
  invoice: {
    id: 9, supplier_id: 1, status: 'sent', invoice_type: 'invoice', payment_status: null,
    varsymbol: '2610001', currency: 'CZK', language: 'cs', amount_to_pay: 1210, paid_total: 0, advance_paid_amount: 0,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    totals: { without_vat: 1000, vat: 210, with_vat: 1210, rounding: 0 }, vat_breakdown: [],
    items: [{ id: 1, description: 'Syntetická položka', quantity: 1, unit: 'ks', unit_price_without_vat: 1000, vat_rate: 21 }],
    client_id: 3, client_company_name: 'Syntetický odběratel', public_token: null, public_viewed_at: null,
  } as Record<string, unknown>,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ get: vi.fn(async () => m.invoice), getWorkReport: vi.fn(async () => null), pdfUrl: (id: number) => `/api/invoices/${id}/pdf` }) }))
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
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: false } }),
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

describe('detail faktury na telefonu', () => {
  beforeEach(() => { phoneRef.value = true })

  it('pod nadpisem ukáže částku, splatnost, stav a stažení PDF; karty nastavení sbalí', async () => {
    const wrapper = await mountDetail()
    const summary = wrapper.find('[data-test="invoice-phone-summary"]')
    expect(summary.exists()).toBe(true)
    expect(summary.text()).toContain('invoice.totals.total')
    expect(wrapper.find('[data-test="invoice-phone-status"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="invoice-phone-pdf"]').exists()).toBe(true)
    const cards = wrapper.find('[data-test="invoice-detail-cards"]')
    expect((cards.element as HTMLElement).style.display).toBe('none')
    await wrapper.find('[data-test="invoice-phone-details-toggle"]').trigger('click')
    expect((cards.element as HTMLElement).style.display).toBe('')
    wrapper.unmount()
  })

  it('u částečně uhrazené faktury ukáže zbývající částku', async () => {
    m.invoice = { ...m.invoice, paid_total: 210 }
    const wrapper = await mountDetail()
    const state = (wrapper.vm.$ as unknown as { setupState: Record<string, any> }).setupState
    expect(state.phoneSummaryLabel).toBe('invoice.payments.remaining')
    expect(state.phoneSummaryAmount).toBe(1000)
    m.invoice = { ...m.invoice, paid_total: 0 }
    wrapper.unmount()
  })

  it('mimo telefon souhrn ani přepínač nevykreslí a karty zůstanou vidět', async () => {
    phoneRef.value = false
    const wrapper = await mountDetail()
    expect(wrapper.find('[data-test="invoice-phone-summary"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="invoice-phone-details-toggle"]').exists()).toBe(false)
    expect((wrapper.find('[data-test="invoice-detail-cards"]').element as HTMLElement).style.display).toBe('')
    wrapper.unmount()
  })
})
