import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Detail faktury: výkaz práce bez voleb zobrazení vypadá jako před jejich zavedením,
// včetně oddělovačů „·" v mobilních řádcích.

const m = vi.hoisted(() => ({
  invoice: {} as Record<string, unknown>,
  workReport: null as Record<string, unknown> | null,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    getWorkReport: vi.fn(async () => m.workReport),
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
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: false } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

function workReport(options?: Record<string, boolean>) {
  return {
    id: 1, invoice_id: 9, project_id: null, title: 'Syntetický výkaz', total_hours: 1.5, total_amount: 1500,
    items: [{ id: 1, description: 'Analýza', work_date: '2096-03-02', hours: 1.5, duration_minutes: null, rate: 1000, total_amount: 1500, order_index: 0 }],
    material_title: null, material_total: 0, materials: [],
    ...(options ? { effective_display_options: options } : {}),
  }
}

async function mountDetail() {
  m.invoice = {
    id: 9, supplier_id: 1, status: 'sent', invoice_type: 'invoice', varsymbol: '20960001', currency: 'CZK',
    total: 1500, amount_to_pay: 1500, issue_date: '2096-03-10', tax_date: '2096-03-10', due_date: '2096-03-24',
    language: 'cs', items: [], public_token: null, public_viewed_at: null,
    totals: { without_vat: 1500, vat_total: 0, rounding: 0, with_vat: 1500, total: 1500, vat_recap: [] },
  }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/invoices/9')
  const wrapper = mount(InvoiceDetail, { shallow: true, global: { plugins: [router], renderStubDefaultSlot: true } })
  await flushPromises()
  return wrapper
}

/** Řádek mobilní karty výkazu práce: hodiny · sazba · částka. */
function mobileRowParts(wrapper: Awaited<ReturnType<typeof mountDetail>>): string[] {
  const card = wrapper.findAll('.md\\:hidden').find(el => el.text().includes('Analýza'))
  expect(card, 'mobilní karta výkazu').toBeDefined()
  const row = card!.find('.flex.items-baseline.justify-between')
  return row.findAll(':scope > span:last-child > span').map(part => part.text())
}

afterEach(() => { vi.clearAllMocks() })

describe('InvoiceDetail: výkaz práce', () => {
  // BEZ OPRAVY PADÁ: mobilní řádek ztratil oddělovače „·" i bez nastavených voleb.
  it.each([
    ['bez voleb', undefined],
    ['s výchozími volbami', { compact: false, date: true, hours: true, rate: true, amount: true }],
  ])('mobilní řádek výkazu %s má oddělovače jako dřív', async (_label, options) => {
    m.workReport = workReport(options)
    const wrapper = await mountDetail()
    const parts = mobileRowParts(wrapper)
    expect(parts).toHaveLength(5)
    expect(parts[1]).toBe('·')
    expect(parts[3]).toBe('·')
    expect(parts[0]).toContain('h')
    wrapper.unmount()
  })

  it('oddělovač zůstane jen mezi zobrazenými údaji', async () => {
    m.workReport = workReport({ compact: false, date: true, hours: true, rate: false, amount: true })
    const wrapper = await mountDetail()
    const parts = mobileRowParts(wrapper)
    expect(parts).toHaveLength(3)
    expect(parts[1]).toBe('·')
    wrapper.unmount()
  })
})
