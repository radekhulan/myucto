import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollRun, PayrollRunReadiness, PayrollRunValidation } from '@/api/payroll'

const m = vi.hoisted(() => ({
  runs: vi.fn(),
  runDetail: vi.fn(),
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
    status: 'cancelled',
    current_revision_no: 0,
    row_version: 2,
    revision_id: null,
    revision_no: null,
    revision_kind: null,
    revision_status: null,
    payment_materialization_supported: false,
    can_delete: true,
    delete_blocker: null,
    result_snapshot: null,
    available_commands: [],
    validations: [],
    ...overrides,
  }
}

function validation(overrides: Partial<PayrollRunValidation> = {}): PayrollRunValidation {
  return {
    id: 71,
    severity: 'warning',
    code: 'employment_without_inputs',
    entity_type: 'employment',
    entity_id: 3,
    message: 'Pracovní vztah nemá v období žádnou schválenou mzdovou složku.',
    remediation_path: '/payroll/components',
    requires_override: true,
    override_reason: null,
    overridden_by: null,
    overridden_by_name: null,
    overridden_at: null,
    ...overrides,
  }
}

describe('PayrollRuns', () => {
  // Výchozí období je minulý měsíc; fixtury počítají se srpnem 2026.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-09-15T12:00:00'))
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.runs.mockResolvedValue([run()])
    m.total.mockReturnValue(undefined)
    m.suggestedPaymentDate.mockReturnValue(null)
    m.readiness.mockReturnValue(null)
    m.monthlyChecklist.mockResolvedValue({
      period: "2026-08",
      environment: "production",
      items: [],
      summary: { total: 0, send: 0, generate: 0, manual: 0, done: 0 },
    })
    m.runDetail.mockResolvedValue(run())
    m.runHistory.mockResolvedValue({ run_id: 15, revisions: [], events: [] })
    m.peopleOptions.mockResolvedValue([])
    m.deleteRun.mockResolvedValue(undefined)
    m.commandRun.mockResolvedValue({ outcome: null })
    m.overrideValidation.mockResolvedValue({ granted: true, four_eyes_met: true })
    m.overrideBulk.mockResolvedValue({ granted_count: 0, skipped_count: 0 })
    m.revokeOverride.mockResolvedValue({ granted: false, four_eyes_met: true })
  })

  /*
   * Prázdný seznam běhů a nenačtený seznam běhů vedou uživatele k opačnému
   * jednání (založ běh vs. zkus to znovu), takže je nesmí kreslit stejně.
   */
  it('offers a retry instead of an empty state when the runs fail to load', async () => {
    m.runs.mockRejectedValue(new Error('network'))

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.runs.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll.runs.empty_hint')

    m.runs.mockResolvedValue([])
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the period genuinely has no run', async () => {
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.runs.empty_hint')
    expect(wrapper.text()).not.toContain('payroll.runs.load_failed_hint')
  })

  it('names the missing field when a run cannot be created', async () => {
    // Bez běhu za období — tlačítko se kreslí jen tehdy, když má co dělat.
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Uživatel datum výplaty vymaže — tlačítko zšedne a musí říct proč.
    await wrapper.get('input[type="date"]').setValue('')

    const button = wrapper.get('[data-test="run-create"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe('payroll.runs.create_blocked_payment_date')
    expect(wrapper.get('[data-test="run-create-blocked"]').text())
      .toBe('payroll.runs.create_blocked_payment_date')
  })

  it.each([
    ['approved', 'post'],
    ['posted', 'prepare_payments'],
    ['payment_ready', 'close'],
    ['paid', 'close'],
  ] as const)(
    'nabízí ve stavu %s jedinou plnou akci %s',
    async (status, primary) => {
      m.runs.mockResolvedValue([run({
        status,
        can_delete: false,
        available_commands: [primary, 'request_correction'],
      })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      const primaryButton = wrapper.get(`[data-testid="payroll-run-15-${primary}"]`)
      const secondary = wrapper.get('[data-testid="payroll-run-15-request_correction"]')
      expect(primaryButton.classes().join(' ')).toContain('bg-')
      expect(secondary.classes().join(' ')).toContain('border')
      expect(secondary.classes().join(' ')).not.toContain('bg-primary-600')
    },
  )

  it('drží blokující důvod plateb u běhu místo mizejícího toastu', async () => {
    m.runs.mockResolvedValue([run({
      status: 'posted',
      can_delete: false,
      available_commands: ['prepare_payments'],
    })])
    m.commandRun.mockRejectedValue({
      response: {
        status: 422,
        data: {
          error: {
            message: 'Platby nelze připravit: Jan Syntetický nemá nastavené výplatní pravidlo.',
          },
        },
      },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-prepare_payments"]').trigger('click')
    await flushPromises()

    expect(m.error).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="payroll-run-15-blocker"]').text())
      .toContain('nemá nastavené výplatní pravidlo')
  })

  /*
   * Úhrada není rozhodnutí účetní, je to fakt. Tlačítko po ní chtělo potvrdit
   * něco, co ví banka — a dokud to sama nespárovala, drželo i uzavření běhu.
   * Server ho v `available_commands` už neposílá; kdyby ho přesto poslal
   * (starší payload), obrazovka ho nesmí vykreslit.
   */
  it('nenabízí tlačítko „Označit za uhrazené", ani když ho server pošle', async () => {
    m.runs.mockResolvedValue([run({
      status: 'payment_ready',
      can_delete: false,
      available_commands: ['mark_paid', 'close'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-mark_paid"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-run-15-close"]').exists()).toBe(true)
  })

  it('místo tlačítka ukáže, kolik úhrad ještě čeká na výpis', async () => {
    m.runs.mockResolvedValue([run({
      status: 'payment_ready',
      can_delete: false,
      available_commands: ['close'],
      payment_coverage: {
        liability_count: 4,
        settled_count: 1,
        uncovered_count: 3,
        required_minor: 400_000,
        settled_minor: 100_000,
        uncovered_minor: 300_000,
        incoming_unsettled_count: 0,
      },
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-run-15-coverage"]').text())
      .toContain('payroll.runs.coverage.waiting')
  })

  it('po přípravě plateb otevře mzdové příkazy ve správném období', async () => {
    m.runs.mockResolvedValue([run({
      status: 'posted',
      can_delete: false,
      available_commands: ['prepare_payments'],
    })])
    m.commandRun.mockResolvedValue({
      outcome: { outcome: 'payments_prepared', details: { created_count: 3 } },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-prepare_payments"]').trigger('click')
    await flushPromises()

    expect(m.push).toHaveBeenCalledWith({
      name: 'payroll-payments',
      query: { period: '2026-08', run: '15', focus: 'bank-order' },
    })
  })

  it('řekne nahlas, že se u daňové evidence nic nezaúčtovalo', async () => {
    m.runs.mockResolvedValue([run({
      status: 'approved',
      can_delete: false,
      available_commands: ['post'],
    })])
    m.commandRun.mockResolvedValue({
      outcome: { outcome: 'posting_not_applicable', details: { reason: 'tax_evidence' } },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-post"]').trigger('click')
    await flushPromises()

    expect(m.success).toHaveBeenCalledWith(
      'payroll.runs.outcome.posting_not_applicable',
    )
  })

  it('offers destructive deletion only for a run explicitly marked empty by API', async () => {
    const wrapper = mount(PayrollRuns)
    await flushPromises()

    await wrapper.get('[data-testid="delete-payroll-run-15"]').trigger('click')
    expect(m.deleteRun).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('payroll.runs.delete_confirm')
    const confirm = document.body.querySelector<HTMLButtonElement>('[data-test="confirm-delete-run"]')
    expect(confirm).not.toBeNull()
    confirm?.click()
    await flushPromises()

    expect(m.deleteRun).toHaveBeenCalledWith(15, 2)
    expect(m.success).toHaveBeenCalledWith('payroll.runs.deleted')
    expect(m.runs).toHaveBeenCalledTimes(2)
  })

  it('does not expose deletion when the API found any retained evidence', async () => {
    m.runs.mockResolvedValue([run({ can_delete: false })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="delete-payroll-run-15"]').exists()).toBe(false)
    expect(m.deleteRun).not.toHaveBeenCalled()
  })

  // Schované tlačítko účetní nic neřekne. Běh se stane nesmazatelným hned
  // prvním „Uzamknout vstupy" a bez důvodu to vypadá jako závada aplikace.
  it('says why the run cannot be deleted instead of just hiding the button', async () => {
    m.runs.mockResolvedValue([run({
      can_delete: false,
      delete_blocker: 'Mzdový běh už obsahuje neměnnou revizi.',
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const blocker = wrapper.find('[data-testid="payroll-run-15-delete-blocker"]')
    expect(blocker.exists()).toBe(true)
    expect(blocker.text()).toBe('Mzdový běh už obsahuje neměnnou revizi.')
  })

  // Seznam běhů posílal celý výsledkový snapshot každého běhu včetně osobního
  // rozpadu — u firmy se stovkou zaměstnanců to server nedokázal ani načíst.
  // Rozpad se proto dotahuje až na vyžádání, pro jeden konkrétní běh.
  it('loads the per-employee breakdown only when the user asks for it', async () => {
    m.runs.mockResolvedValue([run({
      revision_id: 9,
      revision_status: 'approved',
      result_snapshot: { totals: { cash_payable_minor: 100_000 } },
    })])
    m.runDetail.mockResolvedValue(run({
      result_snapshot: { totals: { cash_payable_minor: 100_000 }, people: [] },
    }))

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(m.runDetail).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="payroll-run-15-breakdown-toggle"]').trigger('click')
    await flushPromises()

    expect(m.runDetail).toHaveBeenCalledWith(15)
  })

  // Bez práva `payroll.enforcement` posílá server součet srážek jako null;
  // dlaždice se pak nevykreslí, místo aby ukázala nulu, která by lhala.
  it('hides the garnishment total when the API withholds it', async () => {
    m.runs.mockResolvedValue([
      run({ id: 15, result_snapshot: { totals: { cash_payable_minor: 100_000, enforcement_withheld_minor: null, payable_after_enforcement_minor: 90_000 } } }),
      run({ id: 16, period_start: '2026-05-01', result_snapshot: { totals: { cash_payable_minor: 100_000, enforcement_withheld_minor: 10_000, payable_after_enforcement_minor: 90_000 } } }),
    ])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.findAll('[data-test="run-enforcement-withheld"]')).toHaveLength(1)
  })

  it('loads revision history lazily and shows the audit trail with a safe total diff', async () => {
    m.runs.mockResolvedValue([run({
      revision_id: 10,
      revision_no: 2,
      revision_kind: 'correction',
      revision_status: 'approved',
      result_snapshot: { totals: { cash_payable_minor: 120_000 } },
    })])
    m.runHistory.mockResolvedValue({
      run_id: 15,
      revisions: [
        {
          id: 9,
          revision_no: 1,
          previous_revision_id: null,
          revision_kind: 'regular',
          status: 'approved',
          created_at: '2026-08-20 09:00:00',
          calculated_at: '2026-08-20 09:10:00',
          reviewed_at: '2026-08-20 09:20:00',
          approved_at: '2026-08-20 09:30:00',
          ruleset_manifest_hash: 'rules-a',
          input_snapshot_hash: 'input-a',
          result_snapshot_hash: 'result-a',
          totals: {
            cash_payable_minor: 100_000,
            enforcement_withheld_minor: 0,
            payable_after_enforcement_minor: 100_000,
          },
          diff_from_previous: null,
        },
        {
          id: 10,
          revision_no: 2,
          previous_revision_id: 9,
          revision_kind: 'correction',
          status: 'approved',
          created_at: '2026-08-21 09:00:00',
          calculated_at: '2026-08-21 09:10:00',
          reviewed_at: '2026-08-21 09:20:00',
          approved_at: '2026-08-21 09:30:00',
          ruleset_manifest_hash: 'rules-a',
          input_snapshot_hash: 'input-b',
          result_snapshot_hash: 'result-b',
          totals: {
            cash_payable_minor: 120_000,
            enforcement_withheld_minor: 0,
            payable_after_enforcement_minor: 120_000,
          },
          diff_from_previous: {
            input_changed: true,
            ruleset_changed: false,
            result_changed: true,
            totals: {
              cash_payable_minor: { before: 100_000, after: 120_000, delta: 20_000 },
            },
          },
        },
      ],
      events: [{
        id: 101,
        revision_id: 10,
        event_type: 'approve',
        from_status: 'reviewed',
        to_status: 'approved',
        reason: 'Oprava syntetického vstupu.',
        actor_name: 'Syntetická účetní',
        created_at: '2026-08-21 09:30:00',
      }],
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(m.runHistory).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="payroll-run-15-history-toggle"]').trigger('click')
    await flushPromises()

    expect(m.runHistory).toHaveBeenCalledWith(15)
    const history = wrapper.get('[data-testid="payroll-run-15-history"]')
    expect(history.text()).toContain('payroll.runs.history.revision_label')
    expect(history.text()).toContain('payroll.runs.history.input_changed')
    expect(history.text()).toContain('payroll.runs.history.ruleset_unchanged')
    expect(history.text()).toContain('payroll.runs.history.actor')
    expect(history.text()).toContain('Oprava syntetického vstupu.')
    expect(history.html()).not.toContain('input_snapshot')
    expect(history.html()).not.toContain('result_snapshot')
  })

  it('keeps a failed history load distinct from an empty audit trail and can retry', async () => {
    m.runHistory.mockRejectedValueOnce(new Error('network'))

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-history-toggle"]').trigger('click')
    await flushPromises()

    const failed = wrapper.get('[data-testid="payroll-run-15-history-failed"]')
    expect(failed.text()).toContain('payroll.runs.history.load_failed')
    expect(failed.text()).not.toContain('payroll.runs.history.empty')

    m.runHistory.mockResolvedValue({ run_id: 15, revisions: [], events: [] })
    await wrapper.get('[data-testid="payroll-run-15-history-retry"]').trigger('click')
    await flushPromises()

    expect(m.runHistory).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('payroll.runs.history.empty')
  })

  /*
   * Varování s `requires_override` drží celý běh. Než k němu vedla routa, byla
   * to slepá ulička; teď musí být na kartě vidět, že se čeká na člověka, a co
   * má udělat.
   */
  it('says that a warning is waiting for a person and offers the way out', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation()],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-validation-71-awaiting"]').text())
      .toContain('payroll.runs.override.awaiting')

    await wrapper.get('[data-testid="payroll-validation-71-override"]').trigger('click')
    expect(m.overrideValidation).not.toHaveBeenCalled()

    const dialog = document.body.querySelector('[data-test="run-override-dialog"]')
    expect(dialog).not.toBeNull()
    expect(document.body.textContent).toContain('payroll.runs.override.reason_hint')

    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = 'Zaměstnanec byl celý měsíc na neplaceném volnu.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    expect(m.overrideValidation).toHaveBeenCalledWith(
      15,
      71,
      { row_version: 2, reason: 'Zaměstnanec byl celý měsíc na neplaceném volnu.' },
      expect.any(String),
    )
    expect(m.success).toHaveBeenCalledWith('payroll.runs.override.granted')
  })

  it('groups repeated blockers and never exposes their internal issue codes', async () => {
    m.peopleOptions.mockResolvedValue([
      { id: 2, full_name: 'Jana Syntetická' },
      { id: 3, full_name: 'Petr Syntetický' },
    ])
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        validation({
          id: 72,
          severity: 'blocker',
          code: 'enforcement_manual_review',
          entity_type: 'employee',
          entity_id: 2,
          message: 'Exekuce. income:net_pay_result_missing_or_unverified',
          remediation_path: '/payroll/enforcement',
          requires_override: false,
        }),
        validation({
          id: 73,
          severity: 'blocker',
          code: 'enforcement_manual_review',
          entity_type: 'employee',
          entity_id: 3,
          message: 'Exekuce. income:net_pay_result_missing_or_unverified',
          remediation_path: '/payroll/enforcement',
          requires_override: false,
        }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.findAll('[data-test="payroll-validation-group-enforcement_manual_review"]'))
      .toHaveLength(1)
    expect(wrapper.text()).toContain('payroll.runs.validation.enforcement_net_pay_many')
    expect(wrapper.text()).not.toContain('net_pay_result_missing_or_unverified')
    expect(wrapper.text()).toContain('Jana Syntetická')
    expect(wrapper.text()).toContain('Petr Syntetický')
    expect(wrapper.get('[data-test="payroll-validation-remediation"]').attributes('href'))
      .toBe('/payroll/enforcement')
  })

  it('zachová odlišné cíle opravy každé osoby ve sloučené kontrole', async () => {
    m.peopleOptions.mockResolvedValue([{ id: 2, full_name: 'Jana Syntetická' }, { id: 3, full_name: 'Petr Syntetický' }])
    m.runs.mockResolvedValue([run({ status: 'calculated', validations: [2, 3].map(id => validation({
      id, code: 'enforcement_manual_review', entity_type: 'employee', entity_id: id,
      message: 'Ověřte evidenci pohledávek.', remediation_path: `/payroll/enforcement?person=${id}`, requires_override: false,
    })) })])
    const wrapper = mount(PayrollRuns)
    await flushPromises()
    const links = wrapper.findAll('[data-test="payroll-validation-remediation"]')
    expect(links.map(link => link.attributes('href'))).toEqual(['/payroll/enforcement?person=2', '/payroll/enforcement?person=3'])
    expect(links[0]?.text()).toContain('Jana Syntetická')
    expect(links[1]?.text()).toContain('Petr Syntetický')
  })

  it('předběžná kontrola ukáže jednotlivé příčiny a cíle všech dotčených vztahů', async () => {
    m.runs.mockResolvedValue([run({ status: 'draft' })])
    m.readiness.mockReturnValue({ period_start: '2026-08-01', payment_date: '2026-09-15', office_id: null, ready: false, has_findings: true, findings: [{
      code: 'time_month_not_approved', severity: 'warning', impact: 'revision', scope: 'monthly',
      message: 'Docházka vyžaduje kontrolu.', remediation_path: null, count: 2,
      entities: [
        { entity_type: 'employment', entity_id: 3, label: 'Jana Syntetická', message: 'Chybí uzavření docházky Jany.', remediation_path: '/payroll/time?employment=3&period=2026-08' },
        { entity_type: 'employment', entity_id: 4, label: 'Petr Syntetický', message: 'Chybí schválení docházky Petra.', remediation_path: '/payroll/time?employment=4&period=2026-08' },
      ],
    }] })
    const wrapper = mount(PayrollRuns)
    await flushPromises()
    const finding = wrapper.get('[data-testid="run-readiness-time_month_not_approved"]')
    expect(finding.text()).toContain('Chybí uzavření docházky Jany.')
    expect(finding.text()).toContain('Chybí schválení docházky Petra.')
    expect(finding.findAll('a').map(link => link.attributes('href'))).toEqual(['/payroll/time?employment=3&period=2026-08', '/payroll/time?employment=4&period=2026-08'])
  })

  it('keeps a grouped validation readable for hundreds of employees', async () => {
    m.peopleOptions.mockResolvedValue(Array.from({ length: 6 }, (_, index) => ({
      id: index + 1,
      full_name: `Zaměstnanec ${index + 1}`,
    })))
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: Array.from({ length: 6 }, (_, index) => validation({
        id: 100 + index,
        code: 'draft_inputs_present',
        entity_type: 'employee',
        entity_id: index + 1,
        requires_override: false,
      })),
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const group = wrapper.get('[data-test="payroll-validation-group-draft_inputs_present"]')
    expect(group.text()).toContain('Zaměstnanec 1')
    expect(group.text()).toContain('Zaměstnanec 5')
    expect(group.text()).not.toContain('Zaměstnanec 6')
    expect(group.text()).toContain('payroll.runs.validation.and_more')
  })

  /*
   * U 226 lidí visely pod jediným nálezem stovky řádků. Server posílá nejvýš
   * 25 osob a skutečný počet v `entity_total` — nález musí svítit 225×, ne 25×,
   * a zbytek výčtu se rozbaluje a prohledává, ne vysype.
   */
  it('dlouhý nález předběžné kontroly zkrátí, rozbalí a prohledá', async () => {
    m.runs.mockResolvedValue([run({ status: 'draft' })])
    m.readiness.mockReturnValue({ period_start: '2026-08-01', payment_date: '2026-09-15', office_id: null, ready: false, has_findings: true, findings: [{
      code: 'time_month_not_approved', severity: 'warning', impact: 'revision', scope: 'monthly',
      message: 'Docházka vyžaduje kontrolu.', remediation_path: null, count: 225, entity_total: 225,
      entities: Array.from({ length: 25 }, (_, index) => ({
        entity_type: 'employment',
        entity_id: index + 1,
        label: `Zaměstnanec ${index + 1}`,
        message: 'Chybí schválení docházky.',
        remediation_path: `/payroll/time?employment=${index + 1}&period=2026-08`,
      })),
    }] })
    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const finding = wrapper.get('[data-testid="run-readiness-time_month_not_approved"]')
    expect(finding.get('[data-test="run-readiness-count-time_month_not_approved"]').text()).toContain('225×')
    expect(finding.findAll('a')).toHaveLength(8)
    expect(finding.find('[data-test="run-readiness-entities-time_month_not_approved-not-listed"]').exists()).toBe(true)

    await finding.get('[data-test="run-readiness-entities-time_month_not_approved-toggle"]').trigger('click')
    expect(finding.findAll('a')).toHaveLength(25)

    await finding.get('[data-test="run-readiness-entities-time_month_not_approved-search"]').setValue('Zaměstnanec 17')
    const links = finding.findAll('a')
    expect(links).toHaveLength(1)
    expect(links[0]?.attributes('href')).toBe('/payroll/time?employment=17&period=2026-08')
  })

  it('stovky validací běhu stránkuje místo vysypání pod kartu', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      // Různé kódy: varování téhož kódu se nově slévají do jedné skupiny.
      validations: Array.from({ length: 30 }, (_, index) => validation({
        id: 300 + index,
        code: `synthetic_check_${index}`,
        entity_id: index + 1,
        message: `Pracovní vztah ${index + 1} nemá v období mzdovou složku.`,
      })),
    })])
    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.findAll('[data-test^="payroll-validation-group-"]')).toHaveLength(8)
    await wrapper.get('[data-test="payroll-run-15-validations-toggle"]').trigger('click')
    expect(wrapper.findAll('[data-test^="payroll-validation-group-"]')).toHaveLength(25)
    expect(wrapper.find('[data-test="payroll-run-15-validations-pagination"]').exists()).toBe(true)
  })

  /*
   * 225 lidí bez přihlášky u ČSSZ bylo 225 karet a 225 dialogů se stejným
   * důvodem. Skupina teď ukáže text jednou, počet, lidi v rozbalovacím seznamu
   * a jedno tlačítko pro všechny; jednotlivé schválení u osoby zůstává.
   */
  function registrationGroup(count: number, code: string, firstId: number, granted = false) {
    const text = code === 'employment_social_registration_missing'
      ? 'k pracovnímu vztahu chybí přihláška u ČSSZ (nemocenské pojištění).'
      : 'k pracovnímu vztahu chybí oznámení nástupu zdravotní pojišťovně.'
    return Array.from({ length: count }, (_, index) => validation({
      id: firstId + index,
      code,
      entity_id: index + 1,
      message: `Zaměstnanec ${index + 1}: ${text}`,
      remediation_path: `/payroll/employees/${index + 1}`,
      ...(granted
        ? {
            override_reason: 'Přihláška byla podána mimo aplikaci na podatelně.',
            overridden_by: 7,
            overridden_by_name: 'Syntetická účetní',
            overridden_at: '2026-09-01T08:00:00Z',
          }
        : {}),
    }))
  }

  it('seskupí varování s výjimkou podle kódu a schválí je jedním dialogem', async () => {
    const social = registrationGroup(30, 'employment_social_registration_missing', 1000)
    m.overrideBulk.mockResolvedValue({ granted_count: 30, skipped_count: 0 })
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        ...social,
        ...registrationGroup(30, 'employment_health_registration_missing', 2000),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const groups = wrapper.findAll('[data-test^="payroll-validation-group-override-"]')
    expect(groups).toHaveLength(2)
    const group = wrapper.get('[data-test="payroll-validation-group-override-employment_social_registration_missing-pending"]')
    expect(group.text()).toContain('K pracovnímu vztahu chybí přihláška u ČSSZ (nemocenské pojištění).')
    expect(group.get('[data-test="payroll-validation-1000-count"]').text()).toBe('30×')
    expect(group.text()).toContain('Zaměstnanec 1')
    expect(group.text()).not.toContain('Zaměstnanec 1: ')
    // Osoby se stránkují přes ExpandableList, ne vysypou všech 30.
    expect(group.findAll('[data-testid$="-person"]')).toHaveLength(8)
    expect(group.findAll('[data-test="payroll-validation-remediation"]')[0]?.attributes('href'))
      .toBe('/payroll/employees/1')
    // Jednotlivé schválení u osoby zůstává dostupné.
    expect(group.find('[data-testid="payroll-validation-1000-override"]').exists()).toBe(true)

    await group.get('[data-testid="payroll-validation-1000-override-all"]').trigger('click')
    expect(document.body.querySelector('[data-test="run-override-bulk-scope"]')).not.toBeNull()

    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = 'Přihlášky podala personální agentura mimo aplikaci.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    expect(m.overrideBulk).toHaveBeenCalledTimes(1)
    expect(m.overrideBulk).toHaveBeenCalledWith(
      15,
      {
        row_version: 2,
        code: 'employment_social_registration_missing',
        validation_ids: social.map(item => item.id),
        reason: 'Přihlášky podala personální agentura mimo aplikaci.',
      },
      expect.any(String),
    )
    expect(m.overrideValidation).not.toHaveBeenCalled()
    expect(m.success).toHaveBeenCalledWith('payroll.runs.override.granted_all')
  })

  /**
   * Q15-8/Q15-11: 19 blokujících kontrol se ztrácelo pod 187 varováními bez
   * jmen na osmi stránkách. Blokátory jdou nahoru a karta se otevře jen s nimi;
   * varování jednoho druhu jsou jedna skupina se jmény.
   */
  it('blokující kontroly ukáže nahoře a samostatně, varování sloučí se jmény', async () => {
    const warnings = [1, 2, 3, 4, 5].map(index => validation({
      id: 300 + index,
      code: 'time_month_missing',
      entity_id: 40 + index,
      message: `Syntetická Osoba ${index}: pracovní vztah nemá za období založenou a schválenou pracovní dobu.`,
      remediation_path: `/payroll/time?employment=${40 + index}`,
      requires_override: false,
    }))
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        ...warnings,
        validation({
          id: 900,
          severity: 'blocker',
          code: 'enforcement_not_deducting',
          message: 'Syntetický Dlužník: exekuční případ je doručený, ale ještě se nesráží.',
          remediation_path: '/payroll/enforcement?case=5',
          requires_override: false,
        }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const listed = () => wrapper.findAll('[data-test^="payroll-validation-group-"]')
    expect(listed()).toHaveLength(1)
    expect(listed()[0].text()).toContain('Syntetický Dlužník')
    expect(wrapper.find('[data-testid="payroll-run-15-validation-scope-hint"]').exists()).toBe(true)

    await wrapper.get('[data-testid="payroll-run-15-validation-scope-all"]').trigger('click')
    expect(listed()).toHaveLength(2)
    expect(listed()[0].text()).toContain('Syntetický Dlužník')
    const group = wrapper.get('[data-test="payroll-validation-group-warning-time_month_missing"]')
    expect(group.text()).toContain('Syntetická Osoba 1')
    expect(group.text()).toContain('Pracovní vztah nemá za období')
  })

  it('osobu ve skupině jde schválit i odvolat jednotlivě', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        ...registrationGroup(3, 'employment_social_registration_missing', 1000),
        ...registrationGroup(2, 'employment_social_registration_missing', 1100, true),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Při blokátorech se karta otevírá jen s nimi (Q15-8); vyřízená skupina
    // je pod „Všechny kontroly".
    expect(wrapper.find('[data-test="payroll-validation-group-override-employment_social_registration_missing-granted"]').exists()).toBe(false)
    await wrapper.get('[data-testid="payroll-run-15-validation-scope-all"]').trigger('click')
    const granted = wrapper.get('[data-test="payroll-validation-group-override-employment_social_registration_missing-granted"]')
    expect(granted.find('[data-testid="payroll-validation-1100-override-all"]').exists()).toBe(false)
    expect(granted.text()).toContain('payroll.runs.override.granted_group')
    await granted.get('[data-testid="payroll-validation-1101-revoke"]').trigger('click')
    await flushPromises()
    expect(m.revokeOverride).toHaveBeenCalledWith(15, 1101, { row_version: 2 }, expect.any(String))

    const pending = wrapper.get('[data-test="payroll-validation-group-override-employment_social_registration_missing-pending"]')
    await pending.get('[data-testid="payroll-validation-1001-override"]').trigger('click')
    expect(document.body.querySelector('[data-test="run-override-bulk-scope"]')).toBeNull()
    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = 'Přihláška tohoto člověka byla podána osobně na úřadě.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    expect(m.overrideValidation).toHaveBeenCalledWith(
      15,
      1001,
      { row_version: 2, reason: 'Přihláška tohoto člověka byla podána osobně na úřadě.' },
      expect.any(String),
    )
    expect(m.overrideBulk).not.toHaveBeenCalled()
  })

  /*
   * Blokátor `draft_inputs_present` dosud jen odkázal jinam, kde se schvaluje
   * řádek po řádku. U 500 zaměstnanců to je zhruba tisíc kliknutí, takže
   * zkratka musí být přímo tady.
   */
  it('approves the blocking draft inputs straight from the run screen', async () => {
    m.approveInputsBatch.mockResolvedValue({ approved: [1, 2], skipped: [], failed: [] })
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation({
        id: 100,
        code: 'draft_inputs_present',
        requires_override: false,
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    await wrapper.get('[data-testid="payroll-validation-100-approve-inputs"]').trigger('click')
    await flushPromises()

    // Bez stropu 500: server projde koncepty měsíce po dávkách podle filtru.
    expect(m.approveInputsBatch).toHaveBeenCalledWith({
      period: '2026-08',
      filter: { status: 'draft' },
      after_id: 0,
    })
    expect(m.success).toHaveBeenCalledWith('payroll.runs.validation.draft_inputs_approved')
  })

  /**
   * Toast nesl jen PRVNÍ důvod a za pár vteřin zmizel. Zbytek dávky se účetní
   * nedozvěděla vůbec a neměla podle čeho dohledat, co ještě odbavit ručně.
   */
  it('důvody neschválených vstupů nechá na obrazovce, ne jen první v toastu', async () => {
    m.approveInputsBatch.mockResolvedValue({
      approved: [1],
      skipped: [],
      failed: [
        { id: 2, code: 'average_missing', message: 'Chybí průměrný výdělek.' },
        { id: 3, code: 'average_missing', message: 'Chybí průměrný výdělek.' },
        { id: 4, code: 'terms_missing', message: 'Vztah nemá účinné podmínky.' },
      ],
    })
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation({
        id: 100,
        code: 'draft_inputs_present',
        requires_override: false,
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-validation-100-approve-inputs"]').trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('[data-test="draft-inputs-failure-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('payroll.runs.validation.draft_inputs_failed_row')
    expect(rows[1].text()).toBe('Vztah nemá účinné podmínky.')

    await wrapper.get('[data-test="draft-inputs-failures-dismiss-15"]').trigger('click')
    expect(wrapper.find('[data-test="draft-inputs-failures-15"]').exists()).toBe(false)
  })

  /**
   * Po konfliktu verzí se dialog přepne na čerstvý běh. Dokud si držel ten
   * zachycený při otevření, každé další kliknutí posílalo TUTÉŽ starou verzi a
   * dostávalo tutéž chybu — z hlášky nevedla cesta ven.
   */
  it('po konfliktu verzí nechá dialog odeslatelný s čerstvou verzí', async () => {
    m.runs
      .mockResolvedValueOnce([run({
        status: 'calculated',
        can_delete: false,
        validations: [validation()],
      })])
      .mockResolvedValue([run({
        status: 'calculated',
        can_delete: false,
        row_version: 9,
        validations: [validation()],
      })])
    m.overrideValidation.mockRejectedValueOnce({
      response: {
        status: 409,
        data: { error: { message: 'Běh se mezitím změnil.' } },
      },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-validation-71-override"]').trigger('click')
    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = 'Zaměstnanec byl celý měsíc na neplaceném volnu.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    expect(m.overrideValidation.mock.calls[0][2]).toMatchObject({ row_version: 2 })
    expect(document.body.querySelector('[data-test="run-override-error"]')?.textContent)
      .toContain('Běh se mezitím změnil.')

    m.overrideValidation.mockResolvedValue({})
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    // Napsaný důvod zůstal, jen zámek je čerstvý.
    expect(m.overrideValidation.mock.calls[1][2]).toMatchObject({
      row_version: 9,
      reason: 'Zaměstnanec byl celý měsíc na neplaceném volnu.',
    })
  })

  it('reopens a cancelled run in one click, without asking for a reason again', async () => {
    m.runs.mockResolvedValue([run({
      status: 'cancelled',
      can_delete: false,
      available_commands: ['reopen'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    const reopen = wrapper.get('[data-testid="payroll-run-15-reopen"]')
    expect(reopen.text()).toContain('payroll.runs.commands.reopen_cancelled')

    await reopen.trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[data-test="run-command-reason"]')).toBeNull()
    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'reopen',
      { row_version: 2 },
      expect.any(String),
    )
  })

  it('cancels an unapproved run without a mandatory reason', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      available_commands: ['calculate', 'approve', 'cancel'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-cancel"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="run-cancel-hint"]')).not.toBeNull()
    document.body.querySelector<HTMLButtonElement>(
      '[data-test="run-command-dialog"] button[type="submit"]',
    )?.click()
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'cancel',
      { row_version: 2 },
      expect.any(String),
    )
    wrapper.unmount()
  })

  it('requires a reason to cancel a correction revision of an approved run', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      revision_kind: 'correction',
      available_commands: ['calculate', 'approve', 'cancel'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-cancel"]').trigger('click')
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-test="run-command-reason"]',
    )!
    expect(textarea.required).toBe(true)
    expect(document.body.querySelector('[data-test="run-cancel-hint"]')?.textContent)
      .toContain('payroll.runs.cancel_correction_hint')
    document.body.querySelector<HTMLFormElement>('[data-test="run-command-dialog"]')
      ?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(m.commandRun).not.toHaveBeenCalled()

    textarea.value = 'Oprava se nakonec nedělá.'
    textarea.dispatchEvent(new Event('input'))
    document.body.querySelector<HTMLFormElement>('[data-test="run-command-dialog"]')
      ?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'cancel',
      { row_version: 2, reason: 'Oprava se nakonec nedělá.' },
      expect.any(String),
    )
    wrapper.unmount()
  })
  it('guides a run held by unapproved attendance and inputs to refresh instead of cancelling', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      available_commands: ['calculate', 'refresh_inputs', 'approve', 'cancel'],
      validations: [
        validation({ id: 91, code: 'time_month_not_approved', severity: 'blocker', requires_override: false }),
        validation({ id: 92, code: 'draft_inputs_present', severity: 'blocker', requires_override: false }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-next-step-time"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-run-15-next-step-inputs"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payroll-run-15-next-step-refresh"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('keeps distinct readable enforcement findings instead of calling all of them net-pay errors', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        validation({
          id: 77,
          code: 'enforcement_manual_review',
          entity_type: 'employee',
          entity_id: 2,
          message: 'Exekuční srážku zatím nelze spočítat, protože chybí vypočtená čistá mzda.',
          requires_override: false,
        }),
        validation({
          id: 78,
          code: 'enforcement_manual_review',
          entity_type: 'employee',
          entity_id: 3,
          message: 'V agendě Exekuce doplňte nebo ověřte další podklady: vyživované osoby a pořadí pohledávek.',
          requires_override: false,
        }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.findAll('[data-test="payroll-validation-group-enforcement_manual_review"]'))
      .toHaveLength(2)
    expect(wrapper.text()).toContain('chybí vypočtená čistá mzda')
    expect(wrapper.text()).toContain('vyživované osoby a pořadí pohledávek')
    expect(wrapper.text()).not.toContain('payroll.runs.validation.enforcement_net_pay_many')
  })

  it('translates every part of a historical mixed enforcement finding without leaking codes', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation({
        id: 79,
        code: 'enforcement_manual_review',
        entity_type: 'employee',
        entity_id: 2,
        message: 'income:net_pay_result_missing_or_unverified dependants_evidence_incomplete',
        requires_override: false,
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.runs.validation.enforcement_net_pay_one')
    expect(wrapper.text()).toContain('payroll.runs.validation.requires_attention')
    expect(wrapper.text()).not.toContain('net_pay_result_missing_or_unverified')
    expect(wrapper.text()).not.toContain('dependants_evidence_incomplete')
  })

  it('does not mislabel a historical non-net-pay enforcement finding', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation({
        id: 80,
        code: 'enforcement_manual_review',
        entity_type: 'employee',
        entity_id: 2,
        message: 'multiple_payers_protected_amount_decision_missing',
        requires_override: false,
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.runs.validation.requires_attention')
    expect(wrapper.text()).not.toContain('payroll.runs.validation.enforcement_net_pay_one')
    expect(wrapper.text()).not.toContain('multiple_payers_protected_amount_decision_missing')
  })

  it('shows a detailed Czech statutory remediation but hides historical raw codes', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [
        validation({
          id: 74,
          severity: 'blocker',
          code: 'statutory_calculation_manual_review',
          message: 'U 1 pracovního vztahu potvrďte účast na nemocenském pojištění z odměny.',
          requires_override: false,
        }),
        validation({
          id: 75,
          severity: 'blocker',
          code: 'statutory_calculation_manual_review',
          message: 'income_tax:other-withholding-eligibility-unverified:employee:4',
          requires_override: false,
        }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.text()).toContain('potvrďte účast na nemocenském pojištění z odměny')
    expect(wrapper.text()).toContain('payroll.runs.validation.statutory_incomplete')
    expect(wrapper.text()).not.toContain('other-withholding-eligibility-unverified')
  })

  it('never exposes an unknown internal code on the run card or in the override dialog', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation({
        id: 76,
        code: 'new_validation_not_known_by_frontend',
        message: 'Kontrola vyžaduje zásah. payroll:future_internal_reason:employment:3',
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.runs.validation.requires_attention')
    expect(wrapper.text()).not.toContain('future_internal_reason')

    await wrapper.get('[data-testid="payroll-validation-76-override"]').trigger('click')
    expect(document.body.textContent).toContain('payroll.runs.validation.requires_attention')
    expect(document.body.textContent).not.toContain('future_internal_reason')
  })

  /*
   * Prázdné pole zastaví už `required` v prohlížeči; mezery ne — ty vypadají
   * jako vyplněná odpověď. Dialog je zastaví sám, aby se prázdno neposílalo na
   * server jako doložené rozhodnutí.
   */
  it('refuses to send a blank reason', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation()],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-validation-71-override"]').trigger('click')
    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = '     '
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()
    await wrapper.vm.$nextTick()

    expect(m.overrideValidation).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="run-override-error"]')?.textContent)
      .toContain('payroll.runs.override.reason_required')
  })

  /* Serverová věta o minimu odůvodnění je konkrétnější — musí zůstat v dialogu. */
  it('keeps the server rejection inside the dialog', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation()],
    })])
    m.overrideValidation.mockRejectedValue({
      response: {
        status: 422,
        data: { error: { message: 'Důvod výjimky musí mít alespoň 20 znaků.' } },
      },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-validation-71-override"]').trigger('click')
    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-test="run-override-reason"]')!
    textarea.value = 'ok'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-override"]')?.click()
    await flushPromises()

    expect(m.error).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="run-override-error"]')?.textContent)
      .toContain('alespoň 20 znaků')
  })

  it('shows who approved the exception and why, and lets it be taken back', async () => {
    m.runs.mockResolvedValue([run({
      status: 'reviewed',
      can_delete: false,
      validations: [validation({
        overridden_at: '2026-08-14 09:30:00',
        overridden_by: 8,
        overridden_by_name: 'Jana Mzdová',
        override_reason: 'Zaměstnanec byl celý měsíc na neplaceném volnu.',
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const resolved = wrapper.get('[data-testid="payroll-validation-71-resolved"]')
    expect(resolved.text()).toContain('payroll.runs.override.granted_by')
    expect(resolved.text()).toContain('payroll.runs.override.reason_label')
    expect(wrapper.find('[data-testid="payroll-validation-71-awaiting"]').exists()).toBe(false)

    await wrapper.get('[data-testid="payroll-validation-71-revoke"]').trigger('click')
    await flushPromises()

    expect(m.revokeOverride).toHaveBeenCalledWith(15, 71, { row_version: 2 }, expect.any(String))
    expect(m.success).toHaveBeenCalledWith('payroll.runs.override.revoked')
  })

  /* Po schválení běhu už výjimka zpět nejde — to by přepisovalo historii. */
  it('hides the revoke button once the run is approved', async () => {
    m.runs.mockResolvedValue([run({
      status: 'approved',
      can_delete: false,
      validations: [validation({
        overridden_at: '2026-08-14 09:30:00',
        overridden_by: 8,
        overridden_by_name: 'Jana Mzdová',
        override_reason: 'Zaměstnanec byl celý měsíc na neplaceném volnu.',
      })],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Po schválení je vyřešené varování jen za odkazem „Varování při výpočtu".
    expect(wrapper.find('[data-testid="payroll-validation-71-locked"]').exists()).toBe(false)
    await wrapper.get('[data-testid="payroll-run-15-calculation-warnings"]').trigger('click')

    expect(wrapper.find('[data-testid="payroll-validation-71-revoke"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="payroll-validation-71-locked"]').text())
      .toContain('payroll.runs.override.locked_after_approval')
  })

  /* Bez práva schvalovat mzdu se nesmí nabízet tlačítko, které skončí 403. */
  it('explains instead of offering a button the user may not press', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.approve')
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      validations: [validation()],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-validation-71-override"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="payroll-validation-71-no-permission"]').text())
      .toContain('payroll.runs.override.no_permission')
  })

  it('paginates instead of loading every run the company ever had', async () => {
    m.total.mockReturnValue(40)

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Období je aktuální měsíc — na jeho hodnotě testu nezáleží, jde o strop.
    expect(m.runs).toHaveBeenCalledWith(expect.any(String), { limit: 12, offset: 0 })
    expect(wrapper.find('[data-testid="payroll-runs-pagination"]').exists()).toBe(true)
  })
  /*
   * „Uzamknout vstupy" bylo primární tlačítko obrazovky, ale nic neříkalo, co
   * se má před zámkem vyplnit ani kde. Zámek přitom zmrazí snímek vstupů a co
   * se zapíše potom, se do výpočtu nedostane bez znovuotevření běhu.
   */
  it('nabídne přípravu vstupů s odkazy na zvolené období, dokud jde vstupy měnit', async () => {
    m.runs.mockResolvedValue([run({ status: 'draft' })])

    const wrapper = mount(PayrollRuns, {
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await flushPromises()

    const prep = wrapper.get('[data-test="run-preparation"]')
    expect(prep.text()).toContain('payroll.runs.preparation.description_draft')
    expect(wrapper.get('[data-test="run-preparation-freeze"]').text())
      .toBe('payroll.runs.preparation.freeze_warning')

    // Odkaz, který zahodí období, otevře cizí měsíc — a zápis pak sedí jinam.
    for (const target of ['prepare-quick-inputs', 'prepare-time', 'prepare-absences']) {
      const to = JSON.parse(wrapper.get(`[data-test="${target}"]`).attributes('data-to') ?? '{}')
      expect(to.query?.period).toBe('2026-08')
    }

    wrapper.unmount()
  })

  it('bez běhu za období vyzve k doplnění vstupů ještě před založením', async () => {
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.get('[data-test="run-preparation"]').text())
      .toContain('payroll.runs.preparation.description_missing')

    wrapper.unmount()
  })

  /*
   * Po zamknutí vstupů by odkazy na pořizování lhaly: zapsat už jde, ale do
   * běhu se to nedostane.
   */
  it('po zamknutí vstupů přípravu schová', async () => {
    m.runs.mockResolvedValue([run({ status: 'inputs_locked' })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="run-preparation"]').exists()).toBe(false)

    wrapper.unmount()
  })
  /*
   * Za období smí být jeden běh. Zakázané tlačítko s vysvětlivkou vedle sebe
   * ale zabíralo nejvýraznější místo obrazovky a nedalo se s ním nic dělat —
   * a informace „běh existuje" je zbytečná, protože ten běh je vidět hned pod
   * tím. Tlačítko se proto nekreslí vůbec; hlavní akce patří ke konkrétnímu
   * běhu, ne do hlavičky.
   */
  it('tlačítko na nový běh vůbec nekreslí, když za období běh existuje', async () => {
    m.runs.mockResolvedValue([run({ status: 'calculated', payment_date: '2026-09-20' })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="run-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="run-create-blocked"]').exists()).toBe(false)

    const paymentDate = wrapper.findAll('input[type="date"]')[0]!
    expect((paymentDate.element as HTMLInputElement).value).toBe('2026-09-20')

    wrapper.unmount()
  })

  /*
   * Mzdový běh doběhne, obrazovka vypadá hotově — a přitom tou chvílí teprve
   * začíná to podstatné, tedy podání. Rozcestník proto patří do karty běhu,
   * ale až od zaúčtování; dřív by to byl šum, protože účetní ještě počítá.
   */
  it.each([
    ['calculated', false],
    ['posted', true],
    ['closed', true],
  ] as const)('rozcestník na podání u běhu ve stavu %s: %s', async (status, visible) => {
    m.runs.mockResolvedValue([run({ status, can_delete: false })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-run-15-next-steps"]').exists())
      .toBe(visible)

    wrapper.unmount()
  })

  /*
   * Termín výplaty má firma sjednaný v mzdové politice a server ho spočítá
   * včetně posunu na pracovní den. Formulář dřív nabízel natvrdo patnáctého
   * následujícího měsíce, takže se běhy zakládaly s termínem, který u firmy
   * neplatí — a na datu výplaty visí splatnost odvodů i lhůty hlášení.
   */
  it('předvyplní datum výplaty návrhem ze mzdové politiky', async () => {
    m.runs.mockResolvedValue([])
    m.suggestedPaymentDate.mockReturnValue('2026-10-09')

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const paymentDate = wrapper.findAll('input[type="date"]')[0]!
    expect((paymentDate.element as HTMLInputElement).value).toBe('2026-10-09')

    wrapper.unmount()
  })

  it('ručně přepsané datum výplaty návrh ze serveru nepřepíše', async () => {
    m.runs.mockResolvedValue([])
    m.suggestedPaymentDate.mockReturnValue('2026-10-09')

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const paymentDate = wrapper.findAll('input[type="date"]')[0]!
    await paymentDate.setValue('2026-10-20')
    await flushPromises()

    expect((paymentDate.element as HTMLInputElement).value).toBe('2026-10-20')

    wrapper.unmount()
  })

  // ── Sloučený první krok a kontrola PŘED zahájením ───────────────────────
  //
  // Účetní mačkala „Pokračovat" třikrát po sobě, aniž by mezi tím cokoli
  // rozhodovala: zámek vstupů a výpočet je jedna práce a samostatná kontrola
  // je u jediné účetní druhý podpis téhož člověka.
  it('nabízí v konceptu jediný sloučený krok místo „Uzamknout vstupy"', async () => {
    m.runs.mockResolvedValue([run({
      status: 'draft',
      can_delete: false,
      available_commands: ['lock_and_calculate', 'lock_inputs', 'cancel'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const primary = wrapper.get('[data-testid="payroll-run-15-lock_and_calculate"]')
    expect(primary.classes().join(' ')).toContain('bg-')
    expect(wrapper.find('[data-testid="payroll-run-15-lock_inputs"]').exists()).toBe(false)
    // Vysvětlení patří pod tlačítka, ne vedle nich.
    expect(wrapper.find('[data-testid="payroll-run-15-hint"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('ze spočítaného běhu vede rovnou schválení, bez samostatné kontroly', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      available_commands: ['calculate', 'review', 'approve', 'cancel'],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-review"]').exists()).toBe(false)
    const approve = wrapper.get('[data-testid="payroll-run-15-approve"]')
    expect(approve.classes().join(' ')).toContain('bg-')

    wrapper.unmount()
  })

  it('ukáže chybějící běhy před obdobím a nabídne posun začátku za měsíce předchozího programu', async () => {
    m.missing.mockReturnValue(['2026-06', '2026-07', '2026-08'])
    m.advanceTo.mockReturnValue('2026-09')
    m.advanceStart.mockImplementation(async () => {
      m.missing.mockReturnValue([])
      m.advanceTo.mockReturnValue(null)
      return { state: {}, moved: { from: '2026-06', to: '2026-09' } }
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const banner = wrapper.get('[data-testid="payroll-runs-missing-previous"]')
    expect(banner.findAll('[data-testid^="payroll-runs-missing-2026-"]')).toHaveLength(3)
    expect(banner.text()).toContain('payroll.runs.missing_previous.hint_takeover')
    await wrapper.get('[data-testid="payroll-runs-advance-start"]').trigger('click')
    await flushPromises()

    expect(m.advanceStart).toHaveBeenCalledWith('2026-09')
    expect(m.success).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="payroll-runs-missing-previous"]').exists()).toBe(false)

    wrapper.unmount()
    m.missing.mockReset()
    m.advanceTo.mockReset()
  })

  it('s blokujícími kontrolami schválení nepustí, řekne kolik jich je a ukáže je', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      revision_id: 40,
      available_commands: ['calculate', 'review', 'approve', 'cancel'],
      validations: [
        validation({ id: 1, severity: 'blocker', code: 'statutory_calculation_manual_review', requires_override: false }),
        validation({ id: 2, requires_override: true }),
        validation({ id: 3, requires_override: true, overridden_at: '2026-09-20 10:00:00' }),
        validation({ id: 4, requires_override: false }),
      ],
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const approve = wrapper.get('[data-testid="payroll-run-15-approve"]')
    expect(approve.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-run-15-approve-blocker-count"]').text()).toBe('(2)')
    await approve.trigger('click')
    expect(m.commandRun).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="payroll-run-15-approve-blocked"]').text())
      .toContain('payroll.runs.approve_blocked.hint')
    expect(wrapper.find('[data-testid="payroll-run-15-validations-section"]').exists()).toBe(true)

    wrapper.unmount()
  })

  // ── Obnovení podkladů otevřené revize (B2) ──────────────────────────────
  //
  // Vstup schválený po zámku přepočet nevidí; schválení by ho tiše vynechalo.
  // Karta musí varovat s počtem a hlavním krokem musí být obnova podkladů.
  const drift = {
    total: 2,
    inputs_added: 1,
    inputs_changed: 0,
    inputs_removed: 0,
    absences_added: 0,
    absences_changed: 0,
    absences_removed: 0,
    employments_added: 0,
    employments_removed: 0,
    statutory_evidence_changed: 1,
    remuneration_changed: 0,
    snapshot_created_at: '2026-09-01 10:00:00',
  }

  it('otevřená revize se změněnými podklady varuje s počtem a nabídne obnovu jako hlavní krok', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      revision_id: 40,
      revision_no: 2,
      revision_kind: 'correction',
      available_commands: ['calculate', 'refresh_inputs', 'review', 'approve', 'cancel'],
      source_drift: { ...drift, total: 4, remuneration_changed: 1, worked_time_changed: 1 },
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const box = wrapper.get('[data-testid="payroll-run-15-source-drift"]')
    expect(box.text()).toContain('payroll.runs.source_drift.title')
    expect(box.find('[data-test="payroll-run-source-drift-inputs_added"]').exists()).toBe(true)
    expect(box.find('[data-test="payroll-run-source-drift-statutory_evidence_changed"]').exists()).toBe(true)
    expect(box.find('[data-test="payroll-run-source-drift-remuneration_changed"]').exists()).toBe(true)
    expect(box.find('[data-test="payroll-run-source-drift-worked_time_changed"]').exists()).toBe(true)
    // Nulové druhy změn se nevypisují.
    expect(box.find('[data-test="payroll-run-source-drift-inputs_changed"]').exists()).toBe(false)
    expect(box.text()).toContain('payroll.runs.source_drift.action')

    const buttons = wrapper.findAll('button[data-testid^="payroll-run-15-"]')
    expect(buttons[0]!.attributes('data-testid')).toBe('payroll-run-15-refresh_inputs')
    expect(wrapper.get('[data-testid="payroll-run-15-hint"]').text())
      .toBe('payroll.runs.command_hint.refresh_inputs')

    await wrapper.get('[data-testid="payroll-run-15-refresh_inputs"]').trigger('click')
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'refresh_inputs',
      { row_version: 2 },
      expect.any(String),
    )

    wrapper.unmount()
  })

  it('změněné podmínky vztahu vypíše a jako hlavní krok nabídne obnovu podkladů místo přepočtu', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      available_commands: ['calculate', 'refresh_inputs', 'review', 'approve', 'cancel'],
      source_drift: { ...drift, total: 1, inputs_added: 0, statutory_evidence_changed: 0, employment_terms_changed: 1 },
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const box = wrapper.get('[data-testid="payroll-run-15-source-drift"]')
    expect(box.find('[data-test="payroll-run-source-drift-employment_terms_changed"]').text())
      .toContain('payroll.runs.source_drift.employment_terms_changed')
    const buttons = wrapper.findAll('button[data-testid^="payroll-run-15-"]')
    expect(buttons[0]!.attributes('data-testid')).toBe('payroll-run-15-refresh_inputs')

    wrapper.unmount()
  })

  it('bez změn podkladů nevaruje a hlavním krokem zůstává schválení', async () => {
    m.runs.mockResolvedValue([run({
      status: 'calculated',
      can_delete: false,
      available_commands: ['calculate', 'refresh_inputs', 'review', 'approve', 'cancel'],
      source_drift: { ...drift, total: 0, inputs_added: 0, statutory_evidence_changed: 0 },
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-source-drift"]').exists()).toBe(false)
    const buttons = wrapper.findAll('button[data-testid^="payroll-run-15-"]')
    expect(buttons[0]!.attributes('data-testid')).toBe('payroll-run-15-approve')
    // Obnova zůstává po ruce jako vedlejší akce.
    expect(wrapper.find('[data-testid="payroll-run-15-refresh_inputs"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('bez práva zapisovat vstupy obnovu nenabídne a řekne proč', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.inputs.write')
    m.runs.mockResolvedValue([run({
      status: 'reopened',
      can_delete: false,
      available_commands: ['calculate', 'refresh_inputs', 'cancel'],
      source_drift: drift,
    })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-run-15-refresh_inputs"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="payroll-run-15-source-drift"]').text())
      .toContain('payroll.runs.source_drift.action_forbidden')

    wrapper.unmount()
  })

  it('nálezy kontroly ukáže předem a před zahájením se zeptá, ale nezablokuje', async () => {
    const readiness: PayrollRunReadiness = {
      period_start: '2026-08-01',
      payment_date: '2026-09-15',
      office_id: null,
      ready: false,
      has_findings: true,
      findings: [{
        code: 'time_month_not_approved',
        severity: 'warning',
        impact: 'revision',
        scope: 'monthly',
        message: 'Docházka pracovního vztahu není schválena.',
        remediation_path: '/payroll/time',
        count: 2,
        entities: [{ entity_type: 'employment', entity_id: 3, label: null }],
      }],
    }
    m.readiness.mockReturnValue(readiness)
    m.runs.mockResolvedValue([run({
      status: 'draft',
      can_delete: false,
      available_commands: ['lock_and_calculate', 'lock_inputs'],
    })])
    m.commandRun.mockResolvedValue({ outcome: null })

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Nález je vidět DŘÍV, než se cokoli zmrazí.
    expect(wrapper.get('[data-testid="run-readiness-time_month_not_approved"]').text())
      .toContain('Docházka pracovního vztahu není schválena.')

    await wrapper.get('[data-testid="payroll-run-15-lock_and_calculate"]').trigger('click')
    await flushPromises()

    // Zeptá se, ale neodešle — a hlavně nezakáže pokračovat.
    expect(m.commandRun).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="run-readiness-dialog"]')).not.toBeNull()

    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-run-readiness"]')?.click()
    await flushPromises()

    expect(m.commandRun).toHaveBeenCalledWith(
      15,
      'lock_and_calculate',
      { row_version: 2 },
      expect.any(String),
    )

    wrapper.unmount()
  })

  it('bez nálezů se sloučený krok odešle rovnou, bez dialogu', async () => {
    m.readiness.mockReturnValue({
      period_start: '2026-08-01',
      payment_date: '2026-09-15',
      office_id: null,
      ready: true,
      has_findings: false,
      findings: [],
    } satisfies PayrollRunReadiness)
    m.runs.mockResolvedValue([run({
      status: 'draft',
      can_delete: false,
      available_commands: ['lock_and_calculate', 'lock_inputs'],
    })])
    m.commandRun.mockResolvedValue({ outcome: null })

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    await wrapper.get('[data-testid="payroll-run-15-lock_and_calculate"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[data-test="run-readiness-dialog"]')).toBeNull()
    expect(m.commandRun).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })

  it('bez běhu za období tlačítko nabídne', async () => {
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.get('[data-test="run-create"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="run-create-blocked"]').exists()).toBe(false)

    wrapper.unmount()
  })

  describe('trvale skrytá varování', () => {
    const summary = () => validation({
      id: 501,
      code: 'tax_declaration_not_signed_summary',
      entity_type: 'run',
      entity_id: null,
      message: 'Počet osob s nepodepsaným prohlášením poplatníka k dani: 2.',
      remediation_path: '/payroll/people',
      requires_override: false,
      hideable: true,
      subject_type: 'employee',
      subject_ids: [7, 8],
    })
    const blocker = () => validation({
      id: 900,
      severity: 'blocker',
      code: 'enforcement_not_deducting',
      message: 'Syntetický Dlužník: exekuční případ je doručený, ale ještě se nesráží.',
      remediation_path: '/payroll/enforcement?case=5',
      requires_override: false,
    })

    /*
     * Po schválení jsou varování odsouhlasená; karta nechá jen odkaz.
     * Blokující chyby se ukazují dál.
     */
    it('po schválení varování schová za odkaz a blokující chybu nechá', async () => {
      m.runs.mockResolvedValue([run({ status: 'approved', can_delete: false, validations: [summary(), blocker()] })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      const groups = () => wrapper.findAll('[data-test^="payroll-validation-group-"]')
      expect(groups()).toHaveLength(1)
      expect(groups()[0].text()).toContain('Syntetický Dlužník')
      expect(wrapper.find('[data-testid="payroll-validation-501-hide"]').exists()).toBe(false)

      await wrapper.get('[data-testid="payroll-run-15-calculation-warnings"]').trigger('click')
      await wrapper.get('[data-testid="payroll-run-15-validation-scope-all"]').trigger('click')
      expect(groups()).toHaveLength(2)
      expect(wrapper.find('[data-testid="payroll-run-15-calculation-warnings-hint"]').exists()).toBe(true)

      wrapper.unmount()
    })

    it('před schválením ukáže varování beze změny a bez odkazu', async () => {
      m.runs.mockResolvedValue([run({ status: 'calculated', can_delete: false, validations: [summary()] })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      expect(wrapper.find('[data-testid="payroll-run-15-calculation-warnings"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="payroll-run-15-hidden-warnings"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="payroll-validation-group-warning-tax_declaration_not_signed_summary"]').text())
        .toContain('nepodepsaným prohlášením')

      wrapper.unmount()
    })

    it('skryje souhrnné varování pro všechny dotčené osoby jedním dialogem', async () => {
      m.runs.mockResolvedValue([run({ status: 'calculated', can_delete: false, validations: [summary()] })])
      m.hideWarning.mockResolvedValue({ created: 2, skipped: 0 })

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      await wrapper.get('[data-testid="payroll-validation-501-hide-people"]').trigger('click')
      const reason = document.body.querySelector<HTMLTextAreaElement>('[data-test="hide-warning-reason"]')!
      reason.value = 'Studenti, prohlášení podepsali jinde'
      reason.dispatchEvent(new Event('input'))
      await flushPromises()
      document.body.querySelector<HTMLButtonElement>('[data-test="confirm-hide-warning"]')!.click()
      await flushPromises()

      expect(m.hideWarning).toHaveBeenCalledWith({
        code: 'tax_declaration_not_signed_summary',
        subject_ids: [7, 8],
        reason: 'Studenti, prohlášení podepsali jinde',
      })
      expect(m.success).toHaveBeenCalledWith('payroll.runs.hide_warning_done')
      expect(m.runs).toHaveBeenCalledTimes(2)

      wrapper.unmount()
    })

    it('typ varování skryje pro celou firmu bez seznamu osob', async () => {
      m.runs.mockResolvedValue([run({ status: 'calculated', can_delete: false, validations: [summary()] })])
      m.hideWarning.mockResolvedValue({ created: 1, skipped: 0 })

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      await wrapper.get('[data-testid="payroll-validation-501-hide-type"]').trigger('click')
      document.body.querySelector<HTMLButtonElement>('[data-test="confirm-hide-warning"]')!.click()
      await flushPromises()

      expect(m.hideWarning).toHaveBeenCalledWith({ code: 'tax_declaration_not_signed_summary', reason: null })

      wrapper.unmount()
    })

    it('blokující chybu ani bez práva schvalovat skrýt nenabídne', async () => {
      m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.approve')
      m.runs.mockResolvedValue([run({ status: 'calculated', can_delete: false, validations: [summary(), blocker()] })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()
      await wrapper.get('[data-testid="payroll-run-15-validation-scope-all"]').trigger('click')

      expect(wrapper.find('[data-testid="payroll-validation-501-hide"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="payroll-validation-900-hide"]').exists()).toBe(false)

      wrapper.unmount()
    })

    it('počet skrytých varování vede do nastavení', async () => {
      m.runs.mockResolvedValue([run({ status: 'calculated', can_delete: false, validations: [], hidden_validation_count: 3 })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      expect(wrapper.get('[data-testid="payroll-run-15-hidden-warnings"]').text())
        .toContain('payroll.runs.hidden_warnings_link')
      expect(wrapper.find('[data-testid="payroll-run-15-validations-section"]').exists()).toBe(false)

      wrapper.unmount()
    })

    it('předběžná kontrola doplní chybějící průměr ze sazby jedním tlačítkem', async () => {
      m.runs.mockResolvedValue([])
      m.readiness.mockReturnValue({
        period_start: '2026-08-01',
        payment_date: '2026-09-15',
        office_id: null,
        ready: false,
        has_findings: true,
        findings: [{
          code: 'hourly_average_earning_missing',
          severity: 'blocker',
          impact: 'approval',
          scope: 'monthly',
          message: 'Chybí schválený průměrný výdělek za 3. čtvrtletí 2026.',
          remediation_path: null,
          count: 1,
          entities: [],
          hideable: false,
          subject_type: null,
          subject_ids: [],
        }],
      } satisfies PayrollRunReadiness)
      m.fillProbable.mockResolvedValue({ created: [{ id: 1 }], candidates: 1 })

      const wrapper = mount(PayrollRuns)
      await flushPromises()
      await wrapper.get('[data-test="run-readiness-fill-probable-hourly_average_earning_missing"]').trigger('click')
      await flushPromises()

      expect(m.fillProbable).toHaveBeenCalledTimes(1)
      expect(m.success).toHaveBeenCalledWith('payroll.runs.readiness.fill_probable_average_done')
      wrapper.unmount()
    })

    it('předběžná kontrola nabídne skrytí jen u skrytelného nálezu', async () => {      m.runs.mockResolvedValue([])
      m.readiness.mockReturnValue({
        period_start: '2026-08-01',
        payment_date: '2026-09-15',
        office_id: null,
        ready: true,
        has_findings: true,
        findings: [
          {
            code: 'time_month_missing',
            severity: 'warning',
            impact: 'anytime',
            scope: 'monthly',
            message: 'Pracovní vztah nemá schválenou pracovní dobu.',
            remediation_path: null,
            count: 2,
            entity_total: 2,
            entities: [
              { entity_type: 'employment', entity_id: 41, label: 'Syntetická Osoba (P1)' },
              { entity_type: 'employment', entity_id: 42, label: 'Syntetický Člověk (P2)' },
            ],
            hideable: true,
            subject_type: 'employment',
            subject_ids: [41, 42],
          },
          {
            code: 'person_data_gap',
            severity: 'warning',
            impact: 'blocking',
            scope: 'setup',
            message: 'Chybí zákonné údaje.',
            remediation_path: null,
            count: 1,
            entities: [],
            hideable: false,
            subject_type: null,
            subject_ids: [],
          },
        ],
      } satisfies PayrollRunReadiness)
      m.hideWarning.mockResolvedValue({ created: 1, skipped: 0 })

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      expect(wrapper.find('[data-test="run-readiness-hide-person_data_gap"]').exists()).toBe(false)
      await wrapper.get('[data-test="run-readiness-hide-people-time_month_missing"]').trigger('click')
      expect(document.body.querySelector('[data-test="hide-warning-people"]')?.textContent).toContain('Syntetický Člověk (P2)')
      document.body.querySelector<HTMLInputElement>('[data-test="hide-warning-person-41"]')!.click()
      await flushPromises()
      document.body.querySelector<HTMLButtonElement>('[data-test="confirm-hide-warning"]')!.click()
      await flushPromises()

      expect(m.hideWarning).toHaveBeenCalledWith({ code: 'time_month_missing', subject_ids: [42], reason: null })

      wrapper.unmount()
    })

    it('nález s hromadnou opravou otevře dialog ze seznamu zaměstnanců na období běhu', async () => {
      m.runs.mockResolvedValue([])
      m.readiness.mockReturnValue({
        period_start: '2026-02-01',
        payment_date: '2026-03-15',
        office_id: null,
        ready: true,
        has_findings: true,
        findings: [{
          code: 'person_data_gap',
          severity: 'info',
          impact: 'anytime',
          scope: 'setup',
          message: 'Za období 2026-02 chybí zákonné údaje.',
          remediation_path: '/payroll/people?filter=blocking_data',
          count: 3,
          entities: [{
            entity_type: 'employee',
            entity_id: 7,
            label: 'Syntetická Osoba',
            message: 'Syntetická Osoba: chybí daňová rezidence.',
            gaps: ['tax_residence'],
            remediation_path: '/payroll/people?person=7&panel=statutory_evidence',
          }],
          bulk_actions: [
            { kind: 'statutory_defaults', count: 3 },
            { kind: 'health_insurer', count: 1 },
          ],
        }],
      } satisfies PayrollRunReadiness)

      const wrapper = mount(PayrollRuns, {
        global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
      })
      await flushPromises()

      const statutory = wrapper.get('[data-test="run-readiness-bulk-action-statutory_defaults"]')
      expect(JSON.parse(statutory.attributes('data-to')!)).toEqual({
        name: 'payroll-people',
        query: { bulk: 'statutory_defaults', period: '2026-02' },
      })
      expect(statutory.text()).toContain('payroll.runs.readiness.bulk.statutory_defaults')
      expect(wrapper.find('[data-test="run-readiness-bulk-action-health_insurer"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Syntetická Osoba: chybí daňová rezidence.')
      wrapper.unmount()
    })

    it('nerozhodnutá žádost o roční zúčtování vede na hromadné nastavení za minulý rok', async () => {
      m.runs.mockResolvedValue([])
      m.readiness.mockReturnValue({
        period_start: '2026-02-01',
        payment_date: '2026-03-15',
        office_id: null,
        ready: true,
        has_findings: true,
        findings: [{
          code: 'jmhz_annual_request_status_unresolved',
          severity: 'info',
          impact: 'anytime',
          scope: 'monthly',
          message: 'Není rozhodnuto, zda o roční zúčtování za minulý rok požádal(a).',
          remediation_path: '/payroll/annual-settlement',
          count: 56,
          entities: [],
          bulk_actions: [{ kind: 'annual_settlement_request', count: 56 }],
        }],
      } satisfies PayrollRunReadiness)

      const wrapper = mount(PayrollRuns, {
        global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
      })
      await flushPromises()

      const action = wrapper.get('[data-test="run-readiness-bulk-action-annual_settlement_request"]')
      expect(JSON.parse(action.attributes('data-to')!)).toEqual({
        name: 'payroll-annual-settlement',
        query: { year: '2025', state: 'request_undecided' },
      })
      expect(action.text()).toContain('payroll.runs.readiness.bulk.annual_settlement_request')
      wrapper.unmount()
    })

    it('hromadný podpis prohlášení z nálezu nese jen dotčené osoby', async () => {
      m.runs.mockResolvedValue([])
      m.readiness.mockReturnValue({
        period_start: '2026-02-01',
        payment_date: '2026-03-15',
        office_id: null,
        ready: false,
        has_findings: true,
        findings: [{
          code: 'tax_declaration_evidence_missing',
          severity: 'blocker',
          impact: 'approval',
          scope: 'monthly',
          message: 'Chybí ověřený stav daňového prohlášení (2).',
          remediation_path: '/payroll/people?bulk=declaration_signed',
          count: 2,
          entities: [],
          bulk_actions: [{ kind: 'declaration_signed', count: 2, employee_ids: [14, 15] }],
        }],
      } satisfies PayrollRunReadiness)

      const wrapper = mount(PayrollRuns, {
        global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
      })
      await flushPromises()

      const action = wrapper.get('[data-test="run-readiness-bulk-action-declaration_signed"]')
      expect(JSON.parse(action.attributes('data-to')!)).toEqual({
        name: 'payroll-people',
        query: { bulk: 'declaration_signed', period: '2026-02', employees: '14,15' },
      })
      wrapper.unmount()
    })
  })
})
