import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

// Tlačítko Web faktura v detailu faktury se řídí příznakem instalace
// (auth.invoicePublicLinksEnabled z /api/auth/me). Server správu odkazu při
// vypnuté web faktuře odmítne i tak; tady jde o to, aby se akce vůbec nenabízela.

const m = vi.hoisted(() => ({
  publicLinksEnabled: true,
  publicLink: vi.fn(),
  publishPublicLink: vi.fn(),
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

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({ get: vi.fn(async () => m.invoice), publicLink: m.publicLink, publishPublicLink: m.publishPublicLink }),
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
    canRead: (permission: string) => permission !== 'eet',
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    isCompanyAdminRole: true,
    hasCommercialFeatures: true,
    get invoicePublicLinksEnabled() { return m.publicLinksEnabled },
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

async function mountInvoice() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

async function actions(): Promise<ActionItem[]> {
  const wrapper = await mountInvoice()
  const items = (wrapper.vm as unknown as { invoiceActions: ActionItem[] }).invoiceActions
  wrapper.unmount()
  return items
}

function publicLinkShown(items: ActionItem[]): boolean {
  const item = items.find(a => a.key === 'public-link')
  expect(item).toBeDefined()
  return Boolean(item!.show)
}

describe('akce Web faktura v detailu faktury', () => {
  beforeEach(() => {
    m.publicLinksEnabled = true
    m.invoice.status = 'sent'
    m.publicLink.mockReset().mockResolvedValue({
      url: 'https://invoice.example.test/invoice/test', token: 'test', public_viewed_at: null, published_at: null,
    })
    m.publishPublicLink.mockReset().mockResolvedValue({
      url: 'https://invoice.example.test/invoice/test', token: 'test', public_viewed_at: null, published_at: '2026-01-01T10:00:00Z',
    })
  })

  it('nabízí web fakturu u vystavené faktury', async () => {
    expect(publicLinkShown(await actions())).toBe(true)
  })

  it('otevřením odkazu nepublikuje a publikování vyžaduje vlastní akci', async () => {
    const wrapper = await mountInvoice()
    const vm = wrapper.vm as unknown as {
      openPublicLink: () => Promise<void>
      publishPublicLink: () => Promise<void>
      publicLinkPublishedAt: string | null
    }
    await vm.openPublicLink()
    expect(m.publicLink).toHaveBeenCalledWith(9)
    expect(m.publishPublicLink).not.toHaveBeenCalled()
    expect(vm.publicLinkPublishedAt).toBeNull()

    await vm.publishPublicLink()
    expect(m.publishPublicLink).toHaveBeenCalledWith(9)
    expect(vm.publicLinkPublishedAt).toBe('2026-01-01T10:00:00Z')
    wrapper.unmount()
  })

  it('zrušenou fakturu nepublikuje', async () => {
    m.invoice.status = 'cancelled'
    const wrapper = await mountInvoice()
    await (wrapper.vm as unknown as { publishPublicLink: () => Promise<void> }).publishPublicLink()
    expect(m.publishPublicLink).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('na instalaci s vypnutou web fakturou akci skryje', async () => {
    m.publicLinksEnabled = false

    expect(publicLinkShown(await actions())).toBe(false)
  })
})
