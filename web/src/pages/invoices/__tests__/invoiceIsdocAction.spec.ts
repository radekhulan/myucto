import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

// Stažení ISDOC je čtecí akce nezávislá na veřejném odkazu a e-mailu.

const m = vi.hoisted(() => ({
  canWrite: true,
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

vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({
  get: vi.fn(async () => m.invoice),
  isdocUrl: (id: number) => `/api/invoices/${id}/isdoc?supplier_id=1`,
}) }))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/eet', () => ({ eetApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => m.canWrite,
    isClientRole: false,
    isSuperadmin: false,
    isCompanyAdminRole: false,
    hasCommercialFeatures: true,
    invoicePublicLinksEnabled: false,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: false, public_isdoc_enabled: false, email_attach_isdoc: false, embed_isdoc: false } }),
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

async function actions(): Promise<ActionItem[]> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  const items = (wrapper.vm as unknown as { invoiceActions: ActionItem[] }).invoiceActions
  wrapper.unmount()
  return items
}

function isdocAction(items: ActionItem[]) {
  return items.find(a => a.key === 'isdoc' && (a.show === undefined || !!a.show))
}

describe('stažení ISDOC v detailu vydané faktury', () => {
  beforeEach(() => {
    m.canWrite = true
    m.invoice.status = 'sent'
    m.invoice.invoice_type = 'invoice'
    m.invoice.calendar_id = null
  })

  it.each(['issued', 'sent', 'reminded', 'paid'])('nabízí stažení ve stavu %s v Pokročilé', async status => {
    m.invoice.status = status
    const items = await actions()
    expect(isdocAction(items)).toMatchObject({
      label: 'invoice.download_isdoc', tier: 'advanced', variant: 'neutral',
      icon: 'download', href: '/api/invoices/9/isdoc?supplier_id=1', download: true,
    })
    expect(items[items.findIndex(a => a.key === 'isdoc') + 1]?.key).toBe('rebuild-snapshots')
  })

  it.each(['draft', 'cancelled'])('nenabízí export ve stavu %s', async status => {
    m.invoice.status = status
    expect(isdocAction(await actions())).toBeUndefined()
  })

  it('umožní export i roli jen pro čtení při vypnuté web faktuře a ISDOC přílohách', async () => {
    m.canWrite = false
    expect(isdocAction(await actions())).toBeDefined()
  })

  it.each(['rent_calendar', 'advance_calendar'])('zachová omezení akcí vystaveného kalendáře %s', async invoiceType => {
    m.invoice.invoice_type = invoiceType
    expect(isdocAction(await actions())).toBeUndefined()
  })

  it.each(['proforma', 'credit_note', 'tax_document', 'payment_calendar'])('nabídne export i pro vydaný doklad typu %s', async invoiceType => {
    m.invoice.invoice_type = invoiceType
    expect(isdocAction(await actions())).toBeDefined()
  })

  it('nabídne export na vydaném dokladu splátky', async () => {
    m.invoice.calendar_id = 12
    expect(isdocAction(await actions())).toBeDefined()
  })
})
