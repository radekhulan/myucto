import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { ClosingState, ClosingStep, ClosingStepPayload } from '@/api/closing'

// ── Mockovaný stav (hoisted, ať na něj mají mock-factory přístup) ─────────────
const m = vi.hoisted(() => ({
  state: vi.fn(),
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
    prepaidExpenseAccrualPreview: vi.fn(), profitDistributionPreview: vi.fn(),
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

const STEP_ORDER = [
  'precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals',
  'provisions', 'income_tax', 'stock', 'close_books', 'open_next',
]

function stockStep(payload: ClosingStepPayload | null, status: ClosingStep['status'] = 'done'): ClosingStep {
  return { step_key: 'stock', status, payload, note: null, done_at: '2025-12-31', done_by: 1 }
}

function makeState(overrides: Partial<ClosingState> = {}): ClosingState {
  return {
    period: {
      id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31',
      status: 'closing', row_version: 3,
    },
    steps: [],
    stock_step_required: true,
    depreciation_step_required: false,
    can_revert_stock: false,
    ...overrides,
  } as unknown as ClosingState
}

async function selectStock(wrapper: ReturnType<typeof mount>) {
  const btn = wrapper.findAll('button').find((b) => /steps\.stock\.title/.test(b.text()))
  expect(btn).toBeTruthy()
  await btn!.trigger('click')
  await flushPromises()
}

describe('PeriodClosing.vue — krok zásob (stock)', () => {
  beforeEach(() => {
    m.state.mockReset()
    m.runStep.mockReset()
    m.revertStep.mockReset()
    m.listAccounts.mockReset()
    m.listAccounts.mockResolvedValue([])
  })

  it('(a) stock je v seznamu kroků mezi income_tax a close_books', async () => {
    m.state.mockResolvedValue(makeState())
    const wrapper = mount(PeriodClosing)
    await flushPromises()

    // Klíče kroků v pořadí, jak je vykreslený levý sloupec (button per STEP_ORDER).
    const keys = wrapper.findAll('button')
      .map((b) => b.text().match(/steps\.(\w+)\.title/)?.[1])
      .filter(Boolean)
    expect(keys).toEqual(STEP_ORDER)
    expect(keys.indexOf('stock')).toBe(keys.indexOf('income_tax') + 1)
    expect(keys.indexOf('stock')).toBe(keys.indexOf('close_books') - 1)
  })

  it('(b) se zapnutým skladem se v kartě zobrazí data náhledu (konečný stav, manko/přebytek, rozdíl)', async () => {
    const payload: ClosingStepPayload = {
      totals: {
        closing: { material: 10000, goods: 2500, product: 0 },
        closing_qty: { material: 5, goods: 3, product: 0 },
        shortage: { material: 150, goods: 0, product: 0 },
        surplus: { material: 0, goods: 75, product: 0 },
      },
      entry_ids: { closing: 501, shortage: 502 },
      warnings: [{ key: 'stock_unbilled_receipts', message: 'Příjemky bez faktury.', items: [{ id: 1 }] }],
    }
    m.state.mockResolvedValue(makeState({ steps: [stockStep(payload)], can_revert_stock: true }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await selectStock(wrapper)

    const text = wrapper.text()
    // Konečný stav per typ + celkem (10000 + 2500 = 12500).
    expect(text).toContain('10000')
    expect(text).toContain('2500')
    expect(text).toContain('12500')
    // Manko / přebytek + rozdíl (75 − 150 = -75).
    expect(text).toContain('150')
    expect(text).toContain('75')
    expect(text).toContain('-75')
    // Zaúčtované sloty + podklady k ověření.
    expect(text).toContain('accounting.closing.stock.posted_slots')
    expect(text).toContain('#501')
    expect(text).toContain('accounting.closing.stock.warnings_title')
    // Karta má tlačítko zaúčtovat/přepočítat i revert (can_revert_stock=true).
    const stockBtns = wrapper.findAll('button').map((b) => b.text())
    expect(stockBtns.some((t) => t.includes('accounting.closing.stock.post'))).toBe(true)
    expect(stockBtns.some((t) => t.includes('accounting.closing.stock.revert'))).toBe(true)
  })

  it('(c) při vypnutém skladu je krok neblokující — netýká se, žádné akční tlačítko', async () => {
    m.state.mockResolvedValue(makeState({ stock_step_required: false, steps: [] }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()

    // V levém sloupci má krok stock značku „netýká se".
    const stockBtn = wrapper.findAll('button').find((b) => /steps\.stock\.title/.test(b.text()))
    expect(stockBtn!.text()).toContain('accounting.closing.step_not_applicable')

    await selectStock(wrapper)
    // Karta ukazuje neblokující hlášku, ne tlačítko pro zaúčtování.
    expect(wrapper.text()).toContain('accounting.closing.stock.not_applicable')
    const stockBtns = wrapper.findAll('button').map((b) => b.text())
    expect(stockBtns.some((t) => t.includes('accounting.closing.stock.post'))).toBe(false)
  })
})
