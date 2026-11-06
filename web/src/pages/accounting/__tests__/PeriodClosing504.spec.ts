import { it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// ── Mockovaný stav (hoisted, ať na něj mají mock-factory přístup) ─────────────
const m = vi.hoisted(() => ({
  state: vi.fn(),
  preview: vi.fn(),
  runStep: vi.fn(),
  revertStep: vi.fn(),
  listAccounts: vi.fn(),
}))

// closingApi — v testu se volá jen state() (na mount); ostatní metody komponenta
// jen referencuje v handlerech (spouští je až akce uživatele), takže stačí vi.fn().
vi.mock('@/api/closing', () => ({
  closingApi: {
    state: m.state,
    runStep: m.runStep,
    revertStep: m.revertStep,
    start: vi.fn(), abort: vi.fn(), fxPreview: vi.fn(), provisionsPreview: vi.fn(),
    estimatesSuggest: vi.fn(), incomeTaxPreview: vi.fn(), smallAssetAccrualPreview: vi.fn(),
    prepaidExpenseAccrualPreview: vi.fn(), profitDistributionPreview: m.preview,
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
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

// formatMoney/formatDate — deterministické (bez Intl), ať asserty čtou syrové hodnoty.
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number | null | undefined) => String(v ?? 0),
  formatDate: (d: string | null | undefined) => String(d ?? ''),
}))

import PeriodClosing from '@/pages/accounting/PeriodClosing.vue'


it('routes the actual period page to the 504 result decision without shareholder fields', async () => {
  m.state.mockResolvedValue({ accounting_framework: 'cz_504', period: { id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31', status: 'approved', row_version: 1 }, steps: [] })
  m.listAccounts.mockResolvedValue([])
  m.preview.mockResolvedValue({ accounting_framework: 'cz_504', as_of: '2026-12-31', cells: [], dimension_labels: {}, resources: [], target_roles: [], snapshot_hash: 'test', decision: null, existing_entry_id: null, target_period: { id: 2, fiscal_year: 2026, row_version: 1, starts_on: '2026-01-01', ends_on: '2026-12-31' } })
  const wrapper = mount(PeriodClosing)
  await flushPromises()
  await wrapper.findAll('button').find(button => button.text() === 'accounting.closing.profit_distribution.open')!.trigger('click')
  await flushPromises()
  expect(wrapper.find('[data-testid="result-decision-504"]').exists()).toBe(true)
  expect(wrapper.text()).not.toContain('accounting.closing.profit_distribution.withholding_rate')
  expect(wrapper.text()).not.toContain('accounting.closing.profit_distribution.shareholder_row_hint')
})
