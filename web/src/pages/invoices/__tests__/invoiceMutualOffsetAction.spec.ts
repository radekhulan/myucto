import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

// Akce Vzájemný zápočet v detailu vydané faktury: stránka Zápočty předvyplněná
// odběratelem a touto fakturou, jen v podvojném účetnictví a s právem k zápočtům.

const m = vi.hoisted(() => ({
  offsets: true,
  mode: 'double_entry',
  invoice: {
    id: 9, supplier_id: 1, client_id: 4, status: 'sent', invoice_type: 'invoice',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210, paid_total: 210,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    items: [], public_token: null, public_viewed_at: null,
  } as Record<string, unknown>,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ get: vi.fn(async () => m.invoice) }) }))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: (p: string) => (p === 'accounting.offsets' ? m.offsets : true),
    isClientRole: false,
    isSuperadmin: false,
    isCompanyAdminRole: true,
    hasCommercialFeatures: true,
    invoicePublicLinksEnabled: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { get accounting_mode() { return m.mode }, stock_enabled: false } }),
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

async function offsetAction(): Promise<ActionItem | undefined> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  const items = (wrapper.vm as unknown as { invoiceActions: ActionItem[] }).invoiceActions
  wrapper.unmount()
  return items.find(a => a.key === 'mutual-offset')
}

describe('akce Vzájemný zápočet v detailu vydané faktury', () => {
  beforeEach(() => {
    m.offsets = true
    m.mode = 'double_entry'
    m.invoice.status = 'sent'
  })

  it('u neuhrazené faktury odkáže na Zápočty s odběratelem a dokladem', async () => {
    const item = await offsetAction()
    expect(item?.show).toBe(true)
    expect(item?.to).toEqual({ name: 'accounting-offsets', query: { partner_id: '4', docs: 'invoice:9' } })
  })

  it('bez práva k zápočtům, v daňové evidenci ani u uhrazené faktury se nenabídne', async () => {
    m.offsets = false
    expect((await offsetAction())?.show).toBeFalsy()
    m.offsets = true
    m.mode = 'tax_records'
    expect((await offsetAction())?.show).toBeFalsy()
    m.mode = 'double_entry'
    m.invoice.status = 'paid'
    expect((await offsetAction())?.show).toBeFalsy()
  })
})
