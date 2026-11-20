import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// ── Mockovaný stav (hoisted, ať na něj mají mock-factory přístup) ─────────────
const m = vi.hoisted(() => ({
  state: vi.fn(),
  runStep: vi.fn(),
  revertStep: vi.fn(),
  listAccounts: vi.fn(),
  warning: vi.fn(),
  drPreview: vi.fn(),
}))

// closingApi — v testu se volá jen state() (na mount); ostatní metody komponenta
// jen referencuje v handlerech (spouští je až akce uživatele), takže stačí vi.fn().
vi.mock('@/api/closing', () => ({
  closingApi: {
    state: m.state,
    runStep: m.runStep,
    revertStep: m.revertStep,
    start: vi.fn(), abort: vi.fn(), fxPreview: vi.fn(), provisionsPreview: vi.fn(),
    estimatesSuggest: vi.fn(), incomeTaxPreview: vi.fn(), smallAssetAccrualPreview: vi.fn().mockResolvedValue(null),
    prepaidExpenseAccrualPreview: vi.fn().mockResolvedValue(null), deferredRevenueAccrualPreview: m.drPreview, profitDistributionPreview: vi.fn(),
    profitDistribution: vi.fn(), profitDistributionRevert: vi.fn(), createEntry: vi.fn(),
    reverseEntry: vi.fn(), close: vi.fn(), openNext: vi.fn(),
  },
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts },
}))

// i18n stub — `t` vrací klíč (na překlady se v testu nespoléháme).
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

// vue-router — useRoute pro periodId, RouterLink jako jednoduchý stub.
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ can: () => true, canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: m.warning, info: vi.fn() }),
}))

// formatMoney/formatDate — deterministické (bez Intl), ať asserty čtou syrové hodnoty.
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number | null | undefined) => String(v ?? 0),
  formatDate: (d: string | null | undefined) => String(d ?? ''),
}))

import PeriodClosing from '@/pages/accounting/PeriodClosing.vue'

function item(source: 'invoice' | 'other_item', id: number, docNo: string) {
  return {
    source, item_id: id, invoice_id: source === 'invoice' ? 7 : null, membership_case_id: null,
    other_item_id: source === 'other_item' ? id : null, invoice_number: docNo, description: 'Syntetický nájem',
    currency_code: 'CZK', total_without_vat: 36600, total_czk: 36600, debit_account: '602',
    accrual_from: '2025-07-01', accrual_to: '2026-06-30', total_days: 365, deferred_days: 181, fraction: 0.4959, deferred_amount: 18150,
    release_schedule: [{ fiscal_year: 2026, amount: 18150 }],
  }
}

describe('PeriodClosing.vue — náhled časového rozlišení výnosů (384)', () => {
  beforeEach(() => {
    m.listAccounts.mockResolvedValue([])
    m.state.mockResolvedValue({
      period: { id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31', status: 'closing', row_version: 3 },
      steps: [], stock_step_required: false, depreciation_step_required: false, can_revert_stock: false,
    })
  })

  it('faktura i ostatní pohledávka odkazují na svůj detail; stejné id obou zdrojů nekoliduje', async () => {
    m.drPreview.mockResolvedValue({
      as_of: '2025-12-31', period: { id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31' },
      next_period_start: '2026-01-01', total: 36300, by_account: { 602: 36300 },
      items: [item('invoice', 5, 'FV-1'), item('other_item', 5, 'POH-1')],
      documents: [], existing: null,
    })
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => /steps\.deferrals\.title/.test(b.text()))
    await btn!.trigger('click')
    await flushPromises()

    const links = wrapper.findAllComponents({ name: 'RouterLink' })
      .filter((l) => /^\/(invoices|other-items)\//.test(String(l.props('to'))))
    expect(links.map((l) => [l.props('to'), l.text()])).toEqual([['/invoices/7', 'FV-1'], ['/other-items/5', 'POH-1']])
  })
})
