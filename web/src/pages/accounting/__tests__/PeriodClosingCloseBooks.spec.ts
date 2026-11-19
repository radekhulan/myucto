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

function closeStep(payload: ClosingStepPayload): ClosingStep {
  return { step_key: 'close_books', status: 'done', payload, note: null, done_at: '2025-12-31', done_by: 1 }
}

function makeState(steps: ClosingStep[]): ClosingState {
  return {
    period: {
      id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31',
      status: 'closed', row_version: 3,
    },
    steps,
    stock_step_required: false,
    depreciation_step_required: false,
  } as unknown as ClosingState
}

async function selectCloseBooks(wrapper: ReturnType<typeof mount>) {
  const btn = wrapper.findAll('button').find((b) => /steps\.close_books\.title/.test(b.text()))
  expect(btn).toBeTruthy()
  await btn!.trigger('click')
  await flushPromises()
}

describe('PeriodClosing.vue — uzavření knih (close_books)', () => {
  beforeEach(() => {
    m.state.mockReset()
    m.listAccounts.mockReset()
    m.listAccounts.mockResolvedValue([])
  })

  it('ukáže varování, když se nepodařilo zmrazit přílohu k závěrce', async () => {
    m.state.mockResolvedValue(makeState([closeStep({
      document_no: 'UZ-2025', profit: 100, category_frozen: true, notes_frozen: false,
      notes_warning: 'statement_notes_freeze_failed',
      notes_warning_detail: 'Zmražení automatických sekcí přílohy k závěrce se nezdařilo.',
    })]))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await selectCloseBooks(wrapper)

    const box = wrapper.find('[data-test="close-books-freeze-warning"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('accounting.closing.close.freeze_warning')
    expect(box.text()).toContain('Zmražení automatických sekcí přílohy k závěrce se nezdařilo.')
  })

  it('ukáže i varování ze zmražení kategorie účetní jednotky', async () => {
    m.state.mockResolvedValue(makeState([closeStep({
      document_no: 'UZ-2025', category_frozen: false, notes_frozen: true,
      warning: 'category_freeze_failed', warning_detail: 'Zmražení kategorie se nezdařilo.',
    })]))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await selectCloseBooks(wrapper)

    expect(wrapper.find('[data-test="close-books-freeze-warning"]').text()).toContain('Zmražení kategorie se nezdařilo.')
  })

  it('bez selhání zmražení varování nezobrazí', async () => {
    m.state.mockResolvedValue(makeState([closeStep({ document_no: 'UZ-2025', category_frozen: true, notes_frozen: true })]))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await selectCloseBooks(wrapper)

    expect(wrapper.find('[data-test="close-books-freeze-warning"]').exists()).toBe(false)
  })
})
