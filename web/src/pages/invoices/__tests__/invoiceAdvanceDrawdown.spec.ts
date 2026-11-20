import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'
import type { AdvanceDrawdownCandidate, InvoiceAdvanceDrawdownSummary } from '@/api/invoices'
import { defaultDrawdownAmount, uncoveredSupply } from '../invoiceAdvanceDrawdown'

// Čerpání víc záloh v detailu konceptu faktury: výchozí částka nepřesáhne plnění faktury,
// které nekryjí jiné zálohy, zadání bez DPH a v procentech z plnění, odebrání jedné zálohy.

const summary: InvoiceAdvanceDrawdownSummary = {
  supply: { gross: 12100, base: 10000 },
  drawdowns: [{ proforma_id: 5, varsymbol: 'PF-5', gross: 6050, base: 5000, vat: 1050 }],
}

const m = vi.hoisted(() => ({
  invoice: {} as Record<string, unknown>,
  candidates: [] as AdvanceDrawdownCandidate[],
  apply: vi.fn(),
  remove: vi.fn(),
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    advanceDrawdownCandidates: vi.fn(async () => m.candidates),
    applyAdvanceDrawdown: m.apply,
    removeAdvanceDrawdown: m.remove,
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
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false } }),
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

type DrawdownVm = {
  invoiceActions: ActionItem[]
  openDrawdownModal: () => Promise<void>
  selectDrawdown: (id: number) => void
  setDrawdownMode: (mode: 'gross' | 'net' | 'percent') => void
  confirmDrawdown: () => Promise<void>
  removeDrawdownOf: (d: { proforma_id: number; varsymbol: string | null }) => Promise<void>
  drawdownAmount: number | null
  drawdownAmountValid: boolean
  drawdownPercentPreview: number
}

async function detail(): Promise<{ vm: DrawdownVm; unmount: () => void }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { vm: wrapper.vm as unknown as DrawdownVm, unmount: () => wrapper.unmount() }
}

const candidate = (over: Partial<AdvanceDrawdownCandidate>): AdvanceDrawdownCandidate => ({
  id: 7, varsymbol: 'PF-7', issue_date: '2026-10-01', total_with_vat: 12100,
  available: 12100, available_base: 10000, drawn: 0, drawn_base: 0, tax_documents: 'DD-7', ...over,
})

describe('výchozí částka čerpání zálohy', () => {
  it('nepřesáhne plnění faktury, které nekryjí ostatní zálohy', () => {
    expect(uncoveredSupply(summary, 'gross')).toBe(6050)
    expect(defaultDrawdownAmount(candidate({}), 'gross', summary)).toBe(6050)
    expect(defaultDrawdownAmount(candidate({}), 'net', summary)).toBe(5000)
    expect(defaultDrawdownAmount(candidate({ available: 1000, available_base: 826.45 }), 'gross', summary)).toBe(1000)
  })

  it('u zálohy, kterou faktura už čerpá, nabídne stávající čerpání', () => {
    expect(defaultDrawdownAmount(candidate({ id: 5, drawn: 6050, drawn_base: 5000 }), 'gross', summary)).toBe(6050)
    expect(defaultDrawdownAmount(candidate({}), 'percent', summary)).toBe(30)
  })
})

describe('modal čerpání záloh v detailu konceptu faktury', () => {
  beforeEach(() => {
    m.invoice = {
      id: 9, supplier_id: 1, client_id: 4, status: 'draft', invoice_type: 'invoice', parent_invoice_id: null,
      varsymbol: null, currency: 'CZK', amount_to_pay: 6050, paid_total: 0,
      issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
      items: [], public_token: null, public_viewed_at: null, advance_drawdown: summary,
    }
    m.candidates = [candidate({ id: 5, varsymbol: 'PF-5', available: 6050, available_base: 5000, drawn: 6050, drawn_base: 5000 }), candidate({})]
    m.apply.mockReset().mockResolvedValue({ drawdown: {}, invoice: m.invoice })
    m.remove.mockReset().mockResolvedValue(m.invoice)
    vi.stubGlobal('confirm', () => true)
  })

  it('předvybere další zálohu s částkou do nekrytého plnění a odebrání nabídne i bez vazby dokladu', async () => {
    const { vm, unmount } = await detail()
    expect(vm.invoiceActions.find(a => a.key === 'advance-drawdown-remove')?.show).toBe(true)
    await vm.openDrawdownModal()
    expect(vm.drawdownAmount).toBe(6050)
    expect(vm.drawdownAmountValid).toBe(true)
    unmount()
  })

  it('pošle procento z plnění a částku bez DPH', async () => {
    const { vm, unmount } = await detail()
    await vm.openDrawdownModal()
    vm.setDrawdownMode('percent')
    expect(vm.drawdownAmount).toBe(30)
    expect(vm.drawdownPercentPreview).toBe(3630)
    await vm.confirmDrawdown()
    expect(m.apply).toHaveBeenLastCalledWith(9, { proforma_id: 7, percent: 30 })

    vm.setDrawdownMode('net')
    expect(vm.drawdownAmount).toBe(5000)
    await vm.confirmDrawdown()
    expect(m.apply).toHaveBeenLastCalledWith(9, { proforma_id: 7, amount: 5000, basis: 'net' })
    unmount()
  })

  it('zálohu bez zaúčtované úhrady nepředvybere a nedovolí ji čerpat', async () => {
    m.candidates = [candidate({ id: 6, varsymbol: 'PF-6', receipt_posted: false }), candidate({ receipt_posted: true })]
    const { vm, unmount } = await detail()
    await vm.openDrawdownModal()
    const dvm = vm as unknown as DrawdownVm & { drawdownProformaId: number | null; drawdownSelectable: (c: AdvanceDrawdownCandidate) => boolean }
    expect(dvm.drawdownProformaId).toBe(7)
    expect(dvm.drawdownSelectable(m.candidates[0])).toBe(false)
    expect(dvm.drawdownSelectable(candidate({}))).toBe(true)
    vm.selectDrawdown(6)
    expect(vm.drawdownAmountValid).toBe(false)
    await vm.confirmDrawdown()
    expect(m.apply).not.toHaveBeenCalled()
    unmount()
  })

  it('odebere čerpání jen jedné zálohy', async () => {
    const { vm, unmount } = await detail()
    await vm.openDrawdownModal()
    await vm.removeDrawdownOf({ proforma_id: 5, varsymbol: 'PF-5' })
    expect(m.remove).toHaveBeenCalledWith(9, 5)
    unmount()
  })
})
