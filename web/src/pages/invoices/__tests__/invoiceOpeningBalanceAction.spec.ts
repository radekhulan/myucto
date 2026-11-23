import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { Ref } from 'vue'
import type { ActionItem } from '@/components/ui/ActionBar.vue'
import type { OpeningBalanceState } from '@/api/invoices'

// Akce Převzato z počátečních stavů v detailu vydané faktury (Pokročilé): jen v podvojném
// účetnictví a s právem zaúčtovat, označení i zrušení přes potvrzovací dialog.

const m = vi.hoisted(() => ({
  mode: 'double_entry',
  journalPost: true,
  state: { in_opening: false, entry_id: null, entry_date: null } as OpeningBalanceState,
  get: vi.fn(),
  mark: vi.fn(),
  unmark: vi.fn(),
  invoice: {
    id: 9, supplier_id: 1, client_id: 4, status: 'sent', invoice_type: 'proforma',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210, paid_total: 0,
    issue_date: '2025-12-01', tax_date: null, due_date: '2025-12-15',
    items: [], public_token: null, public_viewed_at: null,
  } as Record<string, unknown>,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    openingBalance: m.get,
    markOpeningBalance: m.mark,
    unmarkOpeningBalance: m.unmark,
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
    canWrite: (p: string) => (p === 'accounting.journal.post' ? m.journalPost : true),
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
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

type Vm = {
  invoiceActions: ActionItem[]
  openingBalance: { confirming: Ref<'mark' | 'unmark' | null>; inOpening: Ref<boolean>; confirm: (id: number) => Promise<void> }
}

async function detail(): Promise<{ vm: Vm; unmount: () => void }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { vm: wrapper.vm as unknown as Vm, unmount: () => wrapper.unmount() }
}

const action = (vm: Vm, key: string) => vm.invoiceActions.find(a => a.key === key)

describe('akce Převzato z počátečních stavů v detailu vydané faktury', () => {
  beforeEach(() => {
    m.mode = 'double_entry'
    m.journalPost = true
    m.state = { in_opening: false, entry_id: null, entry_date: null }
    m.get.mockReset().mockImplementation(async () => m.state)
    m.mark.mockReset().mockImplementation(async () => {
      m.state = { in_opening: true, entry_id: 50, entry_date: '2026-01-01' }
      return m.state
    })
    m.unmark.mockReset().mockImplementation(async () => {
      m.state = { in_opening: false, entry_id: null, entry_date: null }
      return m.state
    })
  })

  it('nabídne označení v Pokročilých a po potvrzení zavolá PUT', async () => {
    const { vm, unmount } = await detail()
    const mark = action(vm, 'opening-balance-mark')
    expect(mark?.show).toBe(true)
    expect(mark?.tier).toBe('advanced')
    expect(mark?.variant).toBe('warning')
    expect(action(vm, 'opening-balance-unmark')?.show).toBe(false)

    mark?.run?.()
    expect(vm.openingBalance.confirming.value).toBe('mark')
    expect(m.mark).not.toHaveBeenCalled()
    await vm.openingBalance.confirm(9)
    await flushPromises()
    expect(m.mark).toHaveBeenCalledWith(9)
    expect(vm.openingBalance.inOpening.value).toBe(true)
    expect(action(vm, 'opening-balance-unmark')?.show).toBe(true)
    expect(action(vm, 'opening-balance-mark')?.show).toBe(false)
    expect(action(vm, 'post')?.show).toBe(false)
    unmount()
  })

  it('u označeného dokladu nabídne zrušení a zavolá DELETE', async () => {
    m.state = { in_opening: true, entry_id: 50, entry_date: '2026-01-01' }
    const { vm, unmount } = await detail()
    const unmark = action(vm, 'opening-balance-unmark')
    expect(unmark?.show).toBe(true)
    unmark?.run?.()
    expect(vm.openingBalance.confirming.value).toBe('unmark')
    await vm.openingBalance.confirm(9)
    await flushPromises()
    expect(m.unmark).toHaveBeenCalledWith(9)
    expect(action(vm, 'opening-balance-mark')?.show).toBe(true)
    unmount()
  })

  it('bez práva zaúčtovat ani v daňové evidenci se akce nenabídne', async () => {
    m.journalPost = false
    let d = await detail()
    expect(action(d.vm, 'opening-balance-mark')?.show).toBe(false)
    expect(action(d.vm, 'opening-balance-unmark')?.show).toBe(false)
    d.unmount()

    m.journalPost = true
    m.mode = 'tax_records'
    m.get.mockClear()
    d = await detail()
    expect(m.get).not.toHaveBeenCalled()
    expect(action(d.vm, 'opening-balance-mark')?.show).toBe(false)
    d.unmount()
  })
})
