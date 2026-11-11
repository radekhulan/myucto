import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollRun, PayrollRunValidation } from '@/api/payroll'

const m = vi.hoisted(() => ({
  runs: vi.fn(),
  runDetail: vi.fn(),
  runPeople: vi.fn(),
  runPerson: vi.fn(),
  runHistory: vi.fn(),
  peopleOptions: vi.fn(),
  deleteRun: vi.fn(),
  commandRun: vi.fn(),
  overrideValidation: vi.fn(),
  overrideBulk: vi.fn(),
  revokeOverride: vi.fn(),
  approveInputsBatch: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  total: vi.fn(),
  suggestedPaymentDate: vi.fn(),
  readiness: vi.fn(),
  missing: vi.fn(),
  advanceTo: vi.fn(),
  advanceStart: vi.fn(),
  monthlyChecklist: vi.fn(),
  hideWarning: vi.fn(),
  fillProbable: vi.fn(),
  push: vi.fn(),
  replace: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: m.push, replace: m.replace }),
  // Období si stránka drží v URL, ať přežije obnovení i sdílený odkaz.
  useRoute: () => ({ query: {} }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    // Seznam je nově stránkovaný a `result_snapshot` v něm nese jen `totals`.
    // Adaptér drží stávající testy beze změny: scénáře pořád nastavují prosté
    // pole běhů a obálku dopočítá tenhle wrapper.
    runsPage: (period?: string, page?: { limit?: number, offset?: number }) =>
      m.runs(period, page).then((runs: unknown[]) => ({
        runs,
        total: m.total() ?? runs.length,
        limit: page?.limit ?? 12,
        offset: page?.offset ?? 0,
        // Návrh výplatního termínu ze mzdové politiky posílá server;
        // scénáře, které ho neřeší, dostanou `null` a platí nouzový termín.
        suggested_payment_date: m.suggestedPaymentDate?.() ?? null,
        // Kontrola před zahájením běhu; scénáře, které ji neřeší, dostanou `null`.
        readiness: m.readiness?.() ?? null,
        missing_previous_periods: m.missing?.() ?? [],
        advance_start_to: m.advanceTo?.() ?? null,
      })),
    advancePayrollStart: m.advanceStart,
    run: m.runDetail,
    runPeople: m.runPeople,
    runPerson: m.runPerson,
    runHistory: m.runHistory,
    peopleOptions: m.peopleOptions,
    deleteRun: m.deleteRun,
    commandRun: m.commandRun,
    overrideRunValidation: m.overrideValidation,
    overrideRunValidationsBulk: m.overrideBulk,
    revokeRunValidationOverride: m.revokeOverride,
    approveInputsBatch: m.approveInputsBatch,
    // Rozcestník „Co následuje“ v kartě zaúčtovaného běhu je TÝŽ panel
    // jako v Podáních; scénáře, které ho neřeší, dostanou prázdný přehled.
    monthlyChecklist: m.monthlyChecklist,
    hideWarning: m.hideWarning,
  },
}))
vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { fillProbableFromRate: m.fillProbable },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import PayrollRuns from '@/pages/payroll/PayrollRuns.vue'

function run(overrides: Partial<PayrollRun> = {}): PayrollRun {
  return {
    id: 15,
    supplier_id: 4,
    office_id: null,
    period_start: '2026-08-01',
    payment_date: '2026-09-15',
    status: 'calculated',
    current_revision_no: 1,
    row_version: 3,
    revision_id: 9,
    revision_no: 1,
    revision_kind: 'regular',
    revision_status: 'calculated',
    payment_materialization_supported: false,
    can_delete: false,
    delete_blocker: null,
    result_snapshot: null,
    available_commands: [],
    validations: [],
    ...overrides,
  }
}

function blocker(overrides: Partial<PayrollRunValidation> = {}): PayrollRunValidation {
  return {
    id: 81,
    severity: 'blocker',
    code: 'statutory_calculation_manual_review',
    entity_type: 'employee',
    entity_id: 44,
    message: 'Brigádník: chybí zdravotní pojišťovna.',
    remediation_path: '/payroll/people?person=44',
    requires_override: false,
    override_reason: null,
    overridden_by: null,
    overridden_by_name: null,
    overridden_at: null,
    ...overrides,
  }
}

function submitDialog(): void {
  document.body.querySelector<HTMLFormElement>('[data-test="run-command-dialog"]')!
    .dispatchEvent(new Event('submit', { cancelable: true }))
}

describe('PayrollRuns – cesty ven z běhu', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-09-15T12:00:00'))
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.total.mockReturnValue(undefined)
    m.suggestedPaymentDate.mockReturnValue(null)
    m.readiness.mockReturnValue(null)
    m.monthlyChecklist.mockResolvedValue({
      period: '2026-08', environment: 'production', items: [],
      summary: { total: 0, send: 0, generate: 0, manual: 0, done: 0 },
    })
    m.runHistory.mockResolvedValue({ run_id: 15, revisions: [], events: [] })
    m.peopleOptions.mockResolvedValue([])
    m.commandRun.mockResolvedValue({ outcome: null })
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('tlačítka běhu ukazuje podle práv serveru a jmenuje chybějící právo', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.reopen')
    m.runs.mockResolvedValue([run({ available_commands: ['calculate', 'approve', 'cancel'] })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-run-15-calculate"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="payroll-run-15-forbidden"]').text()).toContain('payroll.runs.forbidden_command')
    wrapper.unmount()
  })

  it('vyřadí osobu v ruční kontrole s důvodem a běh přepočítá', async () => {
    m.runs.mockResolvedValue([run({
      available_commands: ['calculate', 'refresh_inputs', 'exclude_employee', 'include_employee', 'cancel'],
      validations: [blocker()],
    })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-validation-81-exclude"]').trigger('click')
    const reason = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-command-reason"]')!
    reason.value = 'Chybí pojišťovna, doplní se zítra.'
    reason.dispatchEvent(new Event('input'))
    submitDialog()
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'exclude_employee',
      { row_version: 3, reason: 'Chybí pojišťovna, doplní se zítra.', employee_id: 44 },
      expect.any(String),
    )
    wrapper.unmount()
  })

  it('bez práva schvalovat vyřazení nenabídne', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.approve')
    m.runs.mockResolvedValue([run({
      available_commands: ['calculate', 'exclude_employee'],
      validations: [blocker()],
    })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()
    expect(wrapper.find('[data-testid="payroll-validation-81-exclude"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('vyřazenou osobu ukáže a umí ji vrátit do běhu', async () => {
    m.runs.mockResolvedValue([run({
      available_commands: ['calculate', 'include_employee'],
      exclusions: [{
        id: 1, employee_id: 44, full_name: 'Brigádník', reason: 'Chybí pojišťovna.',
        excluded_at: '2026-09-15 10:00:00', excluded_by: 1, excluded_by_name: 'Účetní',
        excluded_revision_no: 2, active: true, settled_revision_no: null,
      }],
    })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()
    expect(wrapper.get('[data-testid="payroll-run-15-exclusions"]').text()).toContain('Brigádník')
    await wrapper.get('[data-testid="payroll-run-15-include-44"]').trigger('click')
    await flushPromises()
    expect(m.commandRun).toHaveBeenCalledWith(15, 'include_employee', { row_version: 3, employee_id: 44 }, expect.any(String))
    wrapper.unmount()
  })

  it('změní datum výplaty dialogem s novým datem', async () => {
    m.runs.mockResolvedValue([run({ status: 'cancelled', available_commands: ['reopen', 'change_payment_date'] })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-change_payment_date"]').trigger('click')
    const date = document.body.querySelector<HTMLInputElement>('[data-test="run-command-payment-date"]')!
    date.value = '2026-09-10'
    date.dispatchEvent(new Event('input'))
    submitDialog()
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(15, 'change_payment_date', { row_version: 3, payment_date: '2026-09-10' }, expect.any(String))
    wrapper.unmount()
  })

  it('žádost o opravu jde vzít zpět', async () => {
    m.runs.mockResolvedValue([run({ status: 'correction_pending', available_commands: ['reopen', 'withdraw_correction'] })])

    const wrapper = mount(PayrollRuns, { attachTo: document.body })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-withdraw_correction"]').trigger('click')
    expect(document.body.querySelector('[data-test="run-withdraw-hint"]')).not.toBeNull()
    submitDialog()
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(15, 'withdraw_correction', { row_version: 3 }, expect.any(String))
    wrapper.unmount()
  })

  it('chybu s místem opravy nechá u běhu i s odkazem', async () => {
    m.runs.mockResolvedValue([run({ status: 'correction_pending', available_commands: ['reopen'] })])
    m.commandRun.mockRejectedValue({ response: { status: 422, data: { error: {
      code: 'validation_failed',
      message: 'Brigádník: dohoda o srážce se změnila.',
      remediation_path: '/payroll/deduction-agreements?person=44',
    } } } })

    const wrapper = mount(PayrollRuns, { global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-reopen"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-run-15-blocker"]').text()).toContain('dohoda o srážce')
    expect(wrapper.get('[data-testid="payroll-run-15-blocker-link"]').attributes('href')).toBe('/payroll/deduction-agreements?person=44')
    wrapper.unmount()
  })
})
