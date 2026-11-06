import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

// Úpravu vystavené faktury (?force=1) nabízí detail správci a interní roli s právem
// `invoices.edit_issued`. Server rozhoduje stejně (RequestAuthorization::canEditIssuedInvoice).

const m = vi.hoisted(() => ({
  isCompanyAdmin: false,
  isClientRole: false,
  writable: new Set<string>(),
  listPayments: vi.fn(),
  invoice: {
    id: 9, supplier_id: 1, status: 'sent', invoice_type: 'invoice',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    items: [], public_token: null, public_viewed_at: null,
  } as Record<string, unknown>,
}))

// Načtení detailu volá desítky endpointů; pro viditelnost akce stačí, že všechny
// odpoví prázdně a jen `get` vrátí fakturu.
function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ get: vi.fn(async () => m.invoice), listPayments: m.listPayments }) }))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: (key: string) => m.writable.has(key),
    get isClientRole() { return m.isClientRole },
    isSuperadmin: false,
    get isCompanyAdminRole() { return m.isCompanyAdmin },
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
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

async function editIssuedShown(key = 'edit-admin'): Promise<boolean> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  const items = (wrapper.vm as unknown as { invoiceActions: ActionItem[] }).invoiceActions
  wrapper.unmount()
  const item = items.find(a => a.key === key)
  expect(item).toBeDefined()
  return Boolean(item!.show)
}

describe('akce Upravit vystavenou v detailu faktury', () => {
  beforeEach(() => {
    m.isCompanyAdmin = false
    m.isClientRole = false
    m.writable = new Set(['invoices'])
    m.invoice.status = 'sent'
    delete m.invoice.locked
    delete m.invoice.cash_documents
    m.listPayments.mockReset().mockResolvedValue({ payments: [], bank_transactions: [] })
  })

  it('správce ji vidí', async () => {
    m.isCompanyAdmin = true
    expect(await editIssuedShown()).toBe(true)
  })

  it('role s právem invoices.edit_issued ji vidí', async () => {
    m.writable.add('invoices.edit_issued')
    expect(await editIssuedShown()).toBe(true)
  })

  it('role bez práva ji nevidí', async () => {
    expect(await editIssuedShown()).toBe(false)
  })

  it('u konceptu se nenabízí ani s právem', async () => {
    m.writable.add('invoices.edit_issued')
    m.invoice.status = 'draft'
    expect(await editIssuedShown()).toBe(false)
  })

  it.each([
    ['period_closed', true], ['period_closing', true], ['date_locked', true],
    ['booked', false], ['posted', false], ['accountant_managed', false],
  ])('klientské smazání konceptu při zámku %s: %s', async (reason, visible) => {
    m.isClientRole = true
    m.invoice.status = 'draft'
    m.writable.add('invoices.delete')
    m.invoice.locked = { is_locked: true, reasons: [reason] }
    expect(await editIssuedShown('delete')).toBe(visible)
  })

  it.each(['bank', 'payment', 'cash', 'unavailable'])('klient nesmaže zamčený koncept při %s', async kind => {
    m.isClientRole = true
    m.invoice.status = 'draft'
    m.invoice.paid_total = 0
    m.writable.add('invoices.delete')
    m.invoice.locked = { is_locked: true, reasons: ['period_closed'] }
    if (kind === 'bank') m.listPayments.mockResolvedValue({ payments: [], bank_transactions: [{ id: 8 }] })
    if (kind === 'payment') m.listPayments.mockResolvedValue({ payments: [{ id: 1, amount: 100 }, { id: 2, amount: -100 }], bank_transactions: [] })
    if (kind === 'cash') m.invoice.cash_documents = [{ id: 7 }]
    if (kind === 'unavailable') m.listPayments.mockRejectedValue(new Error('unavailable'))
    expect(await editIssuedShown('delete')).toBe(false)
  })
})
