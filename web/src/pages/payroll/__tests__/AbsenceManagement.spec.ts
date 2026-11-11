import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  context: vi.fn(),
  absencesPage: vi.fn(),
  averages: vi.fn(),
  averageSuggestion: vi.fn(),
  leaveLedger: vi.fn(),
  decide: vi.fn(),
  createAbsence: vi.fn(),
  createAverage: vi.fn(),
  createEntitlement: vi.fn(),
  createLeaveEntry: vi.fn(),
  leaveEntitlementCandidates: vi.fn(),
  createAutomaticEntitlements: vi.fn(),
  averageCandidates: vi.fn(),
  createAveragesBulk: vi.fn(),
  setProbableBulk: vi.fn(),
  recordChildbirth: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  routeQuery: {} as Record<string, string>,
  obstacleKinds: [
    {
      kind: 'medical_examination',
      absence_type: 'employee_obstacle',
      default_rate_basis_points: 10_000,
      min_rate_basis_points: 10_000,
      max_rate_basis_points: 10_000,
      requires_reason: false,
      statutory_basis: 'zp-199+nv-590-2006-bod-1',
    },
    {
      kind: 'downtime',
      absence_type: 'employer_obstacle',
      default_rate_basis_points: 8_000,
      min_rate_basis_points: 8_000,
      max_rate_basis_points: 10_000,
      requires_reason: false,
      statutory_basis: 'zp-207-a',
    },
  ],
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    context: m.context,
    absenceContext: async () => ({ employments: await m.context(), obstacleKinds: m.obstacleKinds }),
    absencesPage: m.absencesPage,
    averages: m.averages,
    averageSuggestion: m.averageSuggestion,
    leaveLedger: m.leaveLedger,
    decide: m.decide,
    createAbsence: m.createAbsence,
    cancel: vi.fn(),
    createAverage: m.createAverage,
    approveAverage: vi.fn(),
    createLeaveEntry: m.createLeaveEntry,
    createEntitlement: m.createEntitlement,
    leaveEntitlementCandidates: m.leaveEntitlementCandidates,
    createAutomaticEntitlements: m.createAutomaticEntitlements,
    averageCandidates: m.averageCandidates,
    createAveragesBulk: m.createAveragesBulk,
    setProbableBulk: m.setProbableBulk,
    recordChildbirth: m.recordChildbirth,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      (typeof params?.code === 'string' ? `${key}:${params.code}` : key),
    te: () => true,
    locale: ref('cs-CZ'),
  }),
}))

import AbsenceManagement from '@/pages/payroll/AbsenceManagement.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import DateInput from '@/components/ui/DateInput.vue'

// DateInput má `inheritAttrs: false`, takže `data-test` nesedí na kořeni
// komponenty a hledá se přes `$attrs`.
function dateInput(wrapper: VueWrapper<any>, testId: string) {
  return wrapper.findAllComponents(DateInput)
    .find(input => (input.vm.$attrs as Record<string, unknown>)['data-test'] === testId)
}

function absence(overrides: Record<string, unknown> = {}) {
  return {
    id: 44,
    employment_id: 12,
    full_name: 'Syntetická osoba',
    employment_code: 'SYNTH-HPP',
    absence_type: 'dpn',
    date_from: '2026-06-15',
    date_to: '2026-06-28',
    partial_first_minutes: null,
    partial_last_minutes: null,
    average_snapshot_id: 8,
    average_hourly_minor: 50_000,
    note: null,
    support_status: 'manual_review',
    status: 'requested',
    correction_pending: false,
    row_version: 1,
    ...overrides,
  }
}

/*
 * Návrh vstupů průměru z uzavřených běhů. Výchozí je NEODVODITELNÝ: syntetická
 * firma v testech žádné uzavřené běhy nemá, takže formulář zůstává prázdný —
 * přesně jako v aplikaci.
 */
function averageSuggestion(overrides: Record<string, unknown> = {}) {
  return {
    employment_id: 12,
    applicable_year: 2026,
    applicable_quarter: 2,
    decisive_from: '2026-01-01',
    decisive_to: '2026-03-31',
    minimum_worked_days: 21,
    ready: false,
    blockers: ['probable_earning_not_recorded'],
    source_kind: null,
    actual_blockers: ['run_missing'],
    probable_hourly_minor: null,
    probable_rationale: null,
    probable_term_id: null,
    gross_earnings_minor: null,
    longer_period_allocated_minor: null,
    worked_minutes: null,
    worked_days: null,
    months: [],
    input_version: 'a'.repeat(64),
    ...overrides,
  }
}

function absencesPage(absences: unknown[], total = absences.length) {
  return { absences, total, limit: 12, offset: 0 }
}

// Plná první stránka z třiceti záznamů — jediný tvar, ve kterém má stránkování
// co dělat. Dovolená místo DPN, ať karty nepřidávají posuzovací zaškrtávátka.
function fullFirstPage() {
  return absencesPage(
    Array.from({ length: 12 }, (_, index) =>
      absence({ id: index + 1, absence_type: 'vacation' })),
    30,
  )
}

// Stránka načítá i z watcheru nad vybraným vztahem, takže pořadí volání není
// pevné — testy se ptají vždy na to poslední.
function lastPageArgs() {
  return m.absencesPage.mock.calls.at(-1)![3]
}

describe('AbsenceManagement', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    m.context.mockResolvedValue([{
      id: 12,
      employee_id: 5,
      code: 'SYNTH-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }, {
      id: 13,
      employee_id: 6,
      code: 'SYNTH-DPC',
      relation_type: 'dpc',
      status: 'active',
      full_name: 'Druhá syntetická osoba',
    }])
    m.absencesPage.mockResolvedValue(absencesPage([absence()]))
    m.averages.mockResolvedValue([{
      id: 8,
      employment_id: 12,
      applicable_year: 2026,
      applicable_quarter: 2,
      source_kind: 'actual',
      average_hourly_minor: 50_000,
      rationale: null,
      support_status: 'manual_review',
      status: 'approved',
      row_version: 2,
    }])
    m.averageSuggestion.mockResolvedValue(averageSuggestion())
    m.leaveLedger.mockResolvedValue({ entries: [], balance_minutes: 0 })
    m.decide.mockResolvedValue({ id: 44, status: 'approved' })
    m.createAbsence.mockResolvedValue({ id: 45 })
    m.createAverage.mockResolvedValue({ id: 9 })
    m.createEntitlement.mockResolvedValue({ id: 10 })
    m.createLeaveEntry.mockResolvedValue({ id: 11 })
    m.leaveEntitlementCandidates.mockResolvedValue({
      items: [], total: 0, limit: 25, offset: 0,
    })
    m.createAutomaticEntitlements.mockResolvedValue([])
    m.averageCandidates.mockResolvedValue({
      items: [], total: 0, limit: 25, offset: 0,
    })
    m.createAveragesBulk.mockResolvedValue({ averages: [], skipped: [] })
  })

  it('explains itself instead of pulsing forever when the company has no employee', async () => {
    // `loadData()` se u prázdného výběru vracelo bez shození `loading`, takže
    // na stránce natrvalo zůstaly čtyři šedé skeletony a vypadalo to zaseknutě.
    m.context.mockResolvedValue([])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('.animate-pulse').exists()).toBe(false)
    expect(wrapper.find('[data-test="no-employments"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll_absence.empty.no_employments_title')
    // Filtr ani záložky nad prázdným číselníkem zaměstnanců nedávají smysl.
    expect(wrapper.find('[data-test="tab-absences"]').exists()).toBe(false)
    expect(m.absencesPage).not.toHaveBeenCalled()
  })

  it('offers a retry instead of an empty state when the absences fail to load', async () => {
    m.absencesPage.mockRejectedValue(new Error('network'))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll_absence.messages.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll_absence.absences.empty')

    m.absencesPage.mockResolvedValue(absencesPage([]))
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the relation genuinely has no absence', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([]))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll_absence.absences.empty')
    expect(wrapper.text()).not.toContain('payroll_absence.messages.load_failed_hint')
  })

  /*
   * Chybějící průměr UŽ NEBLOKUJE ULOŽENÍ — je to podmínka schválení, ne zápisu.
   * Věta pod tlačítkem i odkaz na Průměry zůstávají jako upozornění: uživatel má
   * vědět, co bude při schvalování chybět, ale rozdělaná evidence se nesmí
   * ztratit jen proto, že průměr ještě nikdo nespočítal.
   */
  it('points to the Averages tab when no average exists for the relation', async () => {
    m.averages.mockResolvedValue([])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const button = wrapper.get('[data-test="absence-create"]')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="absence-create-blocked"]').text())
      .toBe('payroll_absence.absences.average_missing_for_relation')

    await wrapper.get('[data-test="go-to-averages"]').trigger('click')
    expect(wrapper.text()).toContain('payroll_absence.averages.create')
  })

  /*
   * Nabídka bere jen schválené průměry, takže průměr čekající na schválení
   * vypadal jako žádný. Hláška „není spočítaný žádný" pak účetní posílala
   * počítat ho znovu — a založila duplicitu místo schválení toho, co už je.
   */
  it('rozliší průměr čekající na schválení od průměru, který neexistuje', async () => {
    m.averages.mockResolvedValue([{
      id: 8,
      employment_id: 12,
      applicable_year: 2026,
      applicable_quarter: 2,
      source_kind: 'actual',
      average_hourly_minor: 50_000,
      rationale: null,
      support_status: 'manual_review',
      status: 'manual_review',
      row_version: 2,
    }])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.get('[data-test="absence-create-blocked"]').text())
      .toBe('payroll_absence.absences.average_awaiting_approval')
    // Cesta na Průměry zůstává — právě tam se ten čekající průměr schvaluje.
    expect(wrapper.find('[data-test="go-to-averages"]').exists()).toBe(true)
  })

  it('asks for a pick when an average exists but none is selected', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="go-to-averages"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="absence-create-blocked"]').text())
      .toBe('payroll_absence.absences.average_required_hint')
    // Upozornění, ne závora.
    expect(wrapper.get('[data-test="absence-create"]').attributes('disabled')).toBeUndefined()
  })

  it('renders a responsive DPN card and sends explicit review flags', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll_absence.types.dpn')
    expect(wrapper.text()).toContain('Syntetická osoba')
    const checks = wrapper.findAll('[data-test="dpn-review"] input[type="checkbox"]')
    expect(checks).toHaveLength(4)
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    const approve = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))
    await approve!.trigger('click')
    await flushPromises()

    expect(m.decide).toHaveBeenCalledWith(44, {
      row_version: 1,
      decision: 'approved',
      first_day_fully_worked: false,
      insurance_eligibility_confirmed: true,
      conflicting_benefit_excluded: true,
    })
    wrapper.unmount()
  })

  /**
   * DPN bez nároku (§ 15a zák. č. 187/2006 Sb.) je výslovná volba, ne nezaškrtnuté
   * potvrzení účasti, a snížení náhrady (§ 192 odst. 4 a 5 ZP) jde se schválením.
   */
  it('sends an explicit no-entitlement choice and a compensation reduction', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const approve = () => wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))!
      .trigger('click')

    await wrapper.get('[data-test="dpn-not-eligible"]').setValue(true)
    expect(wrapper.find('[data-test="dpn-reduction"]').exists()).toBe(false)
    await approve()
    await flushPromises()
    expect(m.decide).toHaveBeenLastCalledWith(44, {
      row_version: 1,
      decision: 'approved',
      first_day_fully_worked: false,
      insurance_eligibility_confirmed: false,
      conflicting_benefit_excluded: false,
      insurance_eligibility: 'not_eligible',
    })

    await wrapper.get('[data-test="dpn-not-eligible"]').setValue(false)
    const checks = wrapper.findAll('[data-test="dpn-review"] input[type="checkbox"]')
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    await wrapper.get('[data-test="dpn-reduction-kind"]').setValue('reduced_192_5')
    await wrapper.get('[data-test="dpn-reduction-value"]').setValue('30')
    await wrapper.get('[data-test="dpn-reduction-reason"]').setValue('Syntetické porušení režimu.')
    await approve()
    await flushPromises()
    expect(m.decide).toHaveBeenLastCalledWith(44, {
      row_version: 1,
      decision: 'approved',
      first_day_fully_worked: false,
      insurance_eligibility_confirmed: true,
      conflicting_benefit_excluded: true,
      compensation_reduction: 'reduced_192_5',
      compensation_reduction_reason: 'Syntetické porušení režimu.',
      compensation_reduction_basis_points: 3000,
    })
    wrapper.unmount()
  })

  /**
   * Schválená neschopnost založí případ dávky a lhůtu NEMPRI (§ 97 zák.
   * č. 187/2006 Sb.). Účetní to musí vidět hned u schválení, s odkazem na
   * případy dávek; případ, který nevznikl, musí říct proč.
   */
  it('po schválení DPN oznámí založený případ dávky s odkazem', async () => {
    m.decide.mockResolvedValue({
      absence: { id: 44, status: 'approved' },
      sickness_case: {
        outcome: 'created',
        case_id: 5,
        benefit_kind: 'NEM',
        nempri_due_on: '2026-06-29',
        reason_code: null,
        message: null,
      },
    })
    const wrapper = mount(AbsenceManagement, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    const checks = wrapper.findAll('[data-test="dpn-review"] input[type="checkbox"]')
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    await wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))!
      .trigger('click')
    await flushPromises()

    const notice = wrapper.get('[data-test="absence-sickness-case-notice"]')
    expect(notice.text()).toContain('payroll.sicknessCases.absenceNotice.created')
    expect(wrapper.find('[data-test="absence-sickness-case-link"]').exists()).toBe(true)
    wrapper.unmount()
  })

  /* C-22: DPN přes odpracované dny a schválený běh — schválení musí říct, co opravit. */
  it('po schválení ukáže varování o docházce a schváleném běhu s proklikem', async () => {
    m.decide.mockResolvedValue({
      absence: { id: 44, status: 'approved' },
      sickness_case: null,
      warnings: [
        { code: 'absence_overlaps_worked_time', message: 'Na 8. 9. je zapsaná odpracovaná doba.', path: '/payroll/time?employment=48&period=2026-09' },
        { code: 'absence_in_approved_run', message: 'Mzdový běh za 9/2026 je už schválený.', path: '/payroll/runs?period=2026-09' },
        { code: 'absence_manual_work_time_unverifiable', message: 'Neověřený souhrn', path: '/payroll/time?employment=48&period=2026-09' },
      ],
    })
    const wrapper = mount(AbsenceManagement, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    const checks = wrapper.findAll('[data-test="dpn-review"] input[type="checkbox"]')
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    await wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="absence-approval-warning-absence_overlaps_worked_time"]').text()).toContain('8. 9.')
    expect(wrapper.find('[data-test="absence-approval-warning-absence_in_approved_run"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="absence-approval-warning-absence_manual_work_time_unverifiable"]').text()).toContain('payroll_absence.manual_work_time_unverifiable')
    wrapper.unmount()
  })

  it('když případ dávky nevznikl, řekne proč', async () => {
    m.decide.mockResolvedValue({
      absence: { id: 44, status: 'approved' },
      sickness_case: {
        outcome: 'skipped',
        case_id: null,
        benefit_kind: 'NEM',
        nempri_due_on: null,
        reason_code: 'sickness_ossz_code_missing',
        message: 'Firma nemá vyplněný kód OSSZ.',
      },
    })
    const wrapper = mount(AbsenceManagement, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    const checks = wrapper.findAll('[data-test="dpn-review"] input[type="checkbox"]')
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    await wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))!
      .trigger('click')
    await flushPromises()

    const notice = wrapper.get('[data-test="absence-sickness-case-notice"]')
    expect(notice.text()).toContain('payroll.sicknessCases.absenceNotice.skipped')
    expect(notice.classes()).toContain('bg-warning-50')
    wrapper.unmount()
  })

  it('exposes all three agenda tabs on the same mobile-safe page', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(wrapper.text()).toContain('payroll_absence.tabs.absences')
    expect(wrapper.text()).toContain('payroll_absence.tabs.averages')
    expect(wrapper.text()).toContain('payroll_absence.tabs.leave')
    const activeTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.absences')
    expect(activeTab!.classes()).toContain('border-payroll-600')
    expect(activeTab!.classes()).not.toContain('bg-payroll-600')
    wrapper.unmount()
  })

  it('uses searchable selectors and visibly bordered controls in forms', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.findAll('[role="combobox"]').length).toBeGreaterThan(0)
    const averagesTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.averages')
    await averagesTab!.trigger('click')

    // `input[type="date"]` je u DateInput skryté pole pod ikonou kalendáře —
    // viditelné je textové, a to sem patří. Skryté nemá (a nemá mít) rámeček.
    const formInputs = wrapper.findAll('input[type="number"], input[type="text"]')
    expect(formInputs.length).toBeGreaterThan(0)
    for (const input of formInputs) {
      expect(input.classes()).toContain('border-neutral-300')
      expect(input.classes()).toContain('bg-surface')
    }
    wrapper.unmount()
  })

  it('filters employment choices by the selected employee', async () => {
    m.context.mockResolvedValue([{
      id: 12,
      employee_id: 5,
      code: 'SYNTH-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }, {
      id: 14,
      employee_id: 5,
      code: 'SYNTH-DPP',
      relation_type: 'dpp',
      status: 'active',
      full_name: 'Syntetická osoba',
    }, {
      id: 13,
      employee_id: 6,
      code: 'SYNTH-DPC',
      relation_type: 'dpc',
      status: 'active',
      full_name: 'Druhá syntetická osoba',
    }])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const employment = wrapper.findComponent('[data-test="absence-employment"]') as VueWrapper<any>
    expect(employment.props('options')).toEqual(expect.arrayContaining([
      expect.objectContaining({ value: 12 }),
      expect.objectContaining({ value: 14 }),
    ]))
    expect(employment.props('options')).toHaveLength(2)

    wrapper.findComponent(PayrollPersonSearchSelect)
      .vm.$emit('update:modelValue', 6)
    await flushPromises()

    expect(employment.props('options')).toEqual([
      expect.objectContaining({ value: 13 }),
    ])
    expect(m.absencesPage).toHaveBeenLastCalledWith(
      expect.any(String),
      expect.any(String),
      13,
      expect.any(Object),
    )
  })

  /*
   * Uložit musí jít i bez průměru. Účetní se o dovolené dozví dřív, než je
   * čtvrtletní průměr spočítaný a schválený — zamčený formulář ji nutil ten
   * papír někam odložit a vrátit se k němu. Kontrola je na schválení.
   */
  it('saves an absence requiring an approved average even without one', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const create = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.absences.create'))
    expect(create!.attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenCalled()
    expect(m.createAbsence.mock.calls.at(-1)?.[0])
      .toMatchObject({ average_snapshot_id: null })
    wrapper.unmount()
  })

  /*
   * Peněžitá pomoc v mateřství je vyloučenou dobou evidenčního listu jen
   * před porodem, takže bez očekávaného dne porodu nejde zapsat. Skutečný den
   * porodu je nepovinný, doplní se, až nastane.
   */
  it('keeps paid public holiday time off separate from unpaid overtime time off', async () => {
    const wrapper = mount(AbsenceManagement); await flushPromises()
    const type = wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>
    type.vm.$emit('update:modelValue', 'holiday_compensatory_time_off'); await flushPromises()
    expect(wrapper.get('[data-test="absence-holiday-time-off-hint"]').text()).toContain('holiday_time_off_hint')
    await wrapper.get('[data-test="absence-form"]').trigger('submit'); await flushPromises()
    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({ absence_type: 'holiday_compensatory_time_off', average_snapshot_id: null }))
    expect(m.createAbsence.mock.calls.at(-1)?.[0]).not.toHaveProperty('compensation_policy')
    ;(wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>).vm.$emit('update:modelValue', 'compensatory_time_off'); await flushPromises()
    expect(wrapper.find('[data-test="absence-holiday-time-off-hint"]').exists()).toBe(false)
    await wrapper.get('[data-test="absence-form"]').trigger('submit'); await flushPromises()
    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({ absence_type: 'compensatory_time_off', average_snapshot_id: null }))
    wrapper.unmount()
  })

  it('u PPM žádá očekávaný den porodu a pošle oba dny porodu', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(dateInput(wrapper, 'absence-expected-childbirth')).toBeUndefined()

    ;(wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>)
      .vm.$emit('update:modelValue', 'ppm')
    await flushPromises()
    const expected = dateInput(wrapper, 'absence-expected-childbirth')
    expect(expected).toBeDefined()
    expect(expected!.props('required')).toBe(true)
    expect(dateInput(wrapper, 'absence-childbirth')!.props('required')).toBe(false)
    expected!.vm.$emit('update:modelValue', '2026-06-20')
    await flushPromises()
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'ppm',
      expected_childbirth_date: '2026-06-20',
      childbirth_date: null,
    }))
    wrapper.unmount()
  })

  /*
   * Osamělý zaměstnanec má ošetřovné 16 dnů místo 9; podle toho měsíční
   * hlášení počítá 10360 a 10475. Příznak se nabízí jen u ošetřování.
   */
  it('u ošetřování nabídne osamělého zaměstnance a pošle příznak', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(wrapper.find('[data-test="absence-lone-carer"]').exists()).toBe(false)

    ;(wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>)
      .vm.$emit('update:modelValue', 'ocr')
    await flushPromises()
    await wrapper.get('[data-test="absence-lone-carer"]').setValue(true)
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'ocr',
      lone_carer: true,
    }))
    wrapper.unmount()
  })

  /*
   * Výkon veřejné funkce a neplacená překážka jsou vlastní druhy — dřív
   * zbývalo jen „jiné", na kterém se hlášení zastaví. Formulář vysvětlí, že
   * náhrada nevzniká.
   */
  it('nabídne výkon veřejné funkce i neplacenou překážku a vysvětlí je', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const type = wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>
    expect(type.props('options')).toEqual(expect.arrayContaining([
      expect.objectContaining({ value: 'public_function' }),
      expect.objectContaining({ value: 'employee_obstacle_unpaid' }),
    ]))
    expect(wrapper.find('[data-test="absence-unpaid-excused-hint"]').exists()).toBe(false)

    type.vm.$emit('update:modelValue', 'public_function')
    await flushPromises()
    expect(wrapper.find('[data-test="absence-unpaid-excused-hint"]').exists()).toBe(true)
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'public_function',
      average_snapshot_id: null,
    }))
    wrapper.unmount()
  })

  /*
   * Doba trvání vztahu po neplatném skončení (§ 16 odst. 4 písm. j) zákona
   * č. 155/1995 Sb.) je vlastní druh; formulář vysvětlí, kdy ho použít.
   */
  it('nabídne trvání vztahu po neplatném skončení a vysvětlí ho', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const type = wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>
    expect(type.props('options')).toEqual(expect.arrayContaining([
      expect.objectContaining({ value: 'invalid_termination' }),
    ]))
    expect(wrapper.find('[data-test="absence-invalid-termination-hint"]').exists()).toBe(false)

    type.vm.$emit('update:modelValue', 'invalid_termination')
    await flushPromises()
    expect(wrapper.find('[data-test="absence-invalid-termination-hint"]').exists()).toBe(true)
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'invalid_termination',
      average_snapshot_id: null,
    }))
    wrapper.unmount()
  })

  /*
   * Placená překážka: druh vybere účetní, sazba se předvyplní z tabulky
   * serveru, jiná sazba si vyžádá důvod a vše odejde na server.
   */
  it('u překážky zaměstnavatele nabídne druhy strany, předvyplní sazbu a pošle ji s důvodem', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const type = wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>
    type.vm.$emit('update:modelValue', 'employer_obstacle')
    await flushPromises()

    const kind = wrapper.findComponent('[data-test="absence-obstacle-kind"]') as VueWrapper<any>
    expect(kind.props('options')).toEqual([
      expect.objectContaining({ value: 'downtime', secondary: '≥ 80 %' }),
    ])
    expect(wrapper.get('[data-test="absence-obstacle-hint"]').text())
      .toBe('payroll_absence.obstacle.pick_hint.employer_obstacle')

    kind.vm.$emit('update:modelValue', 'downtime')
    await flushPromises()
    const rate = wrapper.get('[data-test="absence-obstacle-rate"]')
    expect((rate.element as HTMLInputElement).value).toBe('80')
    expect(wrapper.get('[data-test="absence-obstacle-hint"]').text())
      .toBe('payroll_absence.obstacle.hints.downtime')
    expect(wrapper.get('[data-test="absence-obstacle-reason"]').attributes('required')).toBeUndefined()

    await rate.setValue('90')
    expect(wrapper.get('[data-test="absence-obstacle-reason"]').attributes('required')).toBeDefined()
    await wrapper.get('[data-test="absence-obstacle-reason"]').setValue('Vnitřní předpis 3/2026')
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'employer_obstacle',
      obstacle_kind: 'downtime',
      compensation_rate_basis_points: 9_000,
      compensation_rate_reason: 'Vnitřní předpis 3/2026',
    }))
    wrapper.unmount()
  })

  it('u lékaře je sazba pevná a druh strany zaměstnavatele se nenabízí', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    ;(wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>)
      .vm.$emit('update:modelValue', 'employee_obstacle')
    await flushPromises()
    const kind = wrapper.findComponent('[data-test="absence-obstacle-kind"]') as VueWrapper<any>
    expect(kind.props('options').map((option: { value: string }) => option.value)).toEqual(['medical_examination'])
    kind.vm.$emit('update:modelValue', 'medical_examination')
    await flushPromises()

    expect(wrapper.get('[data-test="absence-obstacle-rate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="absence-obstacle-reason"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('schválená překážka bez směn upozorní, že náhrada nevznikla', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([absence({
      absence_type: 'employer_obstacle',
      obstacle_kind: 'downtime',
      compensation_rate_basis_points: 8_000,
    })]))
    m.decide.mockResolvedValueOnce({
      absence: { id: 44, status: 'approved' },
      calculation: { warning: 'obstacle_without_published_shifts' },
    })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(wrapper.get('[data-test="absence-obstacle-value"]').text())
      .toContain('payroll_absence.obstacle.kinds.downtime')
    const approve = wrapper.findAll('button').find(button => button.text().includes('payroll_absence.actions.approve'))
    await approve!.trigger('click')
    await flushPromises()

    expect(m.toastWarning).toHaveBeenCalledWith('payroll_absence.obstacle.without_shifts')
    wrapper.unmount()
  })

  it('překážka zapsaná bez druhu řekne, proč ji nejde schválit', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([absence({ absence_type: 'employee_obstacle', obstacle_kind: null })]))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="absence-obstacle-missing"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('u jiného druhu než PPM dny porodu neposílá', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAbsence).toHaveBeenLastCalledWith(expect.objectContaining({
      absence_type: 'vacation',
      expected_childbirth_date: null,
      childbirth_date: null,
    }))
    wrapper.unmount()
  })

  it('doplní den porodu i ke schválené PPM', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([absence({
      absence_type: 'ppm',
      status: 'approved',
      expected_childbirth_date: '2026-06-20',
      childbirth_date: null,
      average_snapshot_id: null,
      average_hourly_minor: null,
      row_version: 3,
    })]))
    m.recordChildbirth.mockResolvedValue({ id: 44 })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    await wrapper.get('[data-test="childbirth-open"]').trigger('click')
    expect(wrapper.get('[data-test="childbirth-save"]').attributes('disabled')).toBeDefined()
    dateInput(wrapper, 'childbirth-date')!.vm.$emit('update:modelValue', '2026-06-18')
    await flushPromises()
    await wrapper.get('[data-test="childbirth-save"]').trigger('click')
    await flushPromises()

    expect(m.recordChildbirth).toHaveBeenCalledWith(44, 3, '2026-06-18')
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll_absence.absences.childbirth_recorded')
    wrapper.unmount()
  })

  it('u PPM s doplněným dnem porodu už doplnění nenabízí', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([absence({
      absence_type: 'ppm',
      status: 'approved',
      expected_childbirth_date: '2026-06-20',
      childbirth_date: '2026-06-18',
    })]))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="childbirth-open"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="absence-childbirth-value"]').text())
      .not.toContain('payroll_absence.absences.childbirth_missing')
    wrapper.unmount()
  })

  it('loads through the actual last local day of the month', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const today = new Date()
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0)
    const expected = `${lastDay.getFullYear()}-${String(lastDay.getMonth() + 1).padStart(2, '0')}-${String(lastDay.getDate()).padStart(2, '0')}`
    expect(m.absencesPage.mock.calls[0][1]).toBe(expected)
    wrapper.unmount()
  })

  it('converts human money and time units to the unchanged average API contract', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    await wrapper.find('[data-test="average-gross-czk"]').setValue('12345.67')
    await wrapper.find('[data-test="average-allocated-czk"]').setValue('10.05')
    await wrapper.find('[data-test="average-worked-hours"]').setValue('160.5')
    await wrapper.find('[data-test="average-worked-days"]').setValue('20')
    await wrapper.find('[data-test="average-probable-czk"]').setValue('250.25')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()

    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({
      gross_earnings_minor: 1_234_567,
      longer_period_allocated_minor: 1_005,
      worked_minutes: 9_630,
      worked_days: 20,
      probable_hourly_minor: 25_025,
    }))
    wrapper.unmount()
  })

  it('converts absence and leave hours to whole minutes at the API boundary', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    ;(wrapper.findComponent('[data-test="absence-average"]') as VueWrapper<any>)
      .vm.$emit('update:modelValue', 8)
    await wrapper.find('[data-test="absence-partial-first-hours"]').setValue('2.5')
    await wrapper.find('[data-test="absence-partial-last-hours"]').setValue('1.25')
    await wrapper.find('[data-test="absence-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAbsence).toHaveBeenCalledWith(expect.objectContaining({
      partial_first_minutes: 150,
      partial_last_minutes: 75,
    }))

    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    await wrapper.find('[data-test="leave-weekly-hours"]').setValue('37.5')
    await wrapper.find('[data-test="leave-worked-hours"]').setValue('1040')
    await wrapper.find('[data-test="leave-rationale"]').setValue('Syntetické ruční posouzení')
    await wrapper.find('[data-test="leave-entitlement-form"]').trigger('submit')
    await flushPromises()
    expect(m.createEntitlement).toHaveBeenCalledWith(expect.objectContaining({
      weekly_minutes: 2_250,
      worked_equivalent_minutes: 62_400,
    }))

    await wrapper.find('[data-test="leave-entry-hours"]').setValue('-7.5')
    await wrapper.find('[data-test="leave-entry-reason"]').setValue('Syntetická oprava')
    await wrapper.find('[data-test="leave-entry-form"]').trigger('submit')
    await flushPromises()
    expect(m.createLeaveEntry).toHaveBeenCalledWith(expect.objectContaining({
      minutes_delta: -450,
    }))
    wrapper.unmount()
  })

  it('rejects excessive precision locally and renders the exact API error inline', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    await wrapper.find('[data-test="average-worked-hours"]').setValue('0')
    await wrapper.find('[data-test="average-worked-days"]').setValue('0')
    await wrapper.find('[data-test="average-gross-czk"]').setValue('1.001')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('payroll_absence.averages.gross_minor: payroll_absence.validation.money_precision')

    m.createAverage.mockRejectedValueOnce({
      response: { data: { error: { message: 'Přesná zpráva validační chyby z API.' } } },
    })
    await wrapper.find('[data-test="average-gross-czk"]').setValue('100')
    await wrapper.find('[data-test="average-worked-hours"]').setValue('0')
    await wrapper.find('[data-test="average-worked-days"]').setValue('0')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('Přesná zpráva validační chyby z API.')
    expect(m.toastError).not.toHaveBeenCalledWith('Přesná zpráva validační chyby z API.')
    wrapper.unmount()
  })

  /*
   * Průměr se dřív celý opisoval ručně z čísel, která aplikace už má
   * v uzavřených bězích. Předvyplnění je proto celý smysl obrazovky — ale
   * potvrzení se nepřeskakuje: snapshot vzniká až odesláním formuláře.
   */
  it.each([3, 53])('preserves exact average worked units %i without inventing whole minutes', async units => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({ ready: true, blockers: [], source_kind: 'actual', gross_earnings_minor: 100000, worked_duration_units: units, worked_minutes: null, worked_days: 21 }))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')
    expect((wrapper.get('[data-test="average-worked-hours"]').element as HTMLInputElement).value).toBe(units === 3 ? '0,001' : '0:01:03.6')
    await wrapper.get('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({ worked_duration_units: units, worked_days: 21 }))
    expect(m.createAverage.mock.calls[0][0]).not.toHaveProperty('worked_minutes')
    wrapper.unmount()
  })

  it('treats an agreed hourly rate as an editable individual proposal', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({ ready: true, blockers: [], source_kind: 'probable', probable_source: 'agreed_hourly_rate', probable_hourly_minor: 28000, probable_rationale: 'Syntetická sazba', worked_minutes: 0, worked_days: 0 }))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.get('[data-test="tab-averages"]').trigger('click')
    expect(wrapper.get('[data-test="average-suggestion-probable"]').text()).toContain('hourly_rate_review')
    await wrapper.get('[data-test="average-probable-czk"]').setValue('300')
    await wrapper.get('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({ probable_hourly_minor: 30000 }))
    wrapper.unmount()
  })

  it('leaves unknown suggested hours and days empty', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({ worked_minutes: null, worked_duration_units: null, worked_days: null }))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')
    expect((wrapper.get('[data-test="average-worked-hours"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('[data-test="average-worked-days"]').element as HTMLInputElement).value).toBe('')
    await wrapper.get('[data-test="average-worked-hours"]').setValue('0')
    await wrapper.get('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('names the field whose number is missing instead of a generic number error', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({ worked_minutes: null, worked_duration_units: null, worked_days: null }))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')
    await wrapper.get('[data-test="average-worked-hours"]').setValue('8')
    await wrapper.get('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-test="average-error"]').text()).toContain('payroll_absence.averages.worked_days: payroll_absence.validation.number')
    await wrapper.get('[data-test="average-worked-days"]').setValue('1')
    await wrapper.get('[data-test="average-worked-hours"]').setValue('')
    await wrapper.get('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-test="average-error"]').text()).toContain('payroll_absence.averages.worked_minutes:')
    expect(m.createAverage).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('prefills zero hours and days when the employment did not exist in the decisive period', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({ worked_minutes: null, worked_duration_units: null, worked_days: null, employment_started_after_decisive_period: true }))
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')
    expect((wrapper.get('[data-test="average-worked-hours"]').element as HTMLInputElement).value).toBe('0')
    expect((wrapper.get('[data-test="average-worked-days"]').element as HTMLInputElement).value).toBe('0')
    wrapper.unmount()
  })

  it('prefills the average form from closed payroll runs and still requires confirmation', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      ready: true,
      blockers: [],
      source_kind: 'actual',
      actual_blockers: [],
      gross_earnings_minor: 14_000_000,
      worked_minutes: 27_600,
      worked_days: 61,
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    expect(m.averageSuggestion).toHaveBeenCalledWith(12, expect.any(Number), expect.any(Number))
    expect(wrapper.find('[data-test="average-suggestion-ready"]').exists()).toBe(true)
    expect((wrapper.find('[data-test="average-gross-czk"]').element as HTMLInputElement).value)
      .toBe('140000')
    expect((wrapper.find('[data-test="average-worked-hours"]').element as HTMLInputElement).value)
      .toBe('460')
    expect((wrapper.find('[data-test="average-worked-days"]').element as HTMLInputElement).value)
      .toBe('61')
    // Poměrnou část za období delší než čtvrtletí (§ 358 ZP) návrh nezná, takže
    // se nepředvyplňuje a pole u sebe nese vysvětlení.
    expect(wrapper.text()).toContain('payroll_absence.averages.source_allocated')
    // Nic se neuložilo samo.
    expect(m.createAverage).not.toHaveBeenCalled()

    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({
      gross_earnings_minor: 14_000_000,
      worked_minutes: 27_600,
      worked_days: 61,
      longer_period_allocated_minor: 0,
    }))
    wrapper.unmount()
  })

  /*
   * První čtvrtletí po přechodu: rozhodné období vedl předchozí program. Návrh
   * vzniká z převzatých mezd a musí říct, že převzatá hrubá mzda není
   * započitatelná mzda — náhrady mzdy do průměru nepatří.
   */
  it('marks a suggestion taken from the previous program so the accountant reviews it', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      ready: true,
      blockers: [],
      source_kind: 'actual',
      actual_blockers: [],
      gross_earnings_minor: 12_000_000,
      worked_minutes: 30_240,
      worked_days: 63,
      takeover_periods: ['2026-07', '2026-08', '2026-09'],
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    expect(wrapper.get('[data-test="average-suggestion-takeover"]').text())
      .toContain('payroll_absence.averages.suggestion_takeover')
    expect((wrapper.find('[data-test="average-gross-czk"]').element as HTMLInputElement).value)
      .toBe('120000')
    expect(m.createAverage).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('drops the provenance note once the accountant overwrites a derived number', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      ready: true,
      blockers: [],
      source_kind: 'actual',
      actual_blockers: [],
      gross_earnings_minor: 14_000_000,
      worked_minutes: 27_600,
      worked_days: 61,
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')
    expect(wrapper.find('[data-test="average-suggestion-edited"]').exists()).toBe(false)

    await wrapper.find('[data-test="average-gross-czk"]').setValue('150000')
    expect(wrapper.find('[data-test="average-suggestion-edited"]').exists()).toBe(true)
    wrapper.unmount()
  })

  /*
   * Nedá-li se odvodit, formulář zůstane prázdný a řekne proč. Napůl vyplněné
   * číslo by vypadalo jako hotový podklad — a z průměru se počítá náhrada mzdy
   * i údaj do hlášení ČSSZ.
   */
  it('leaves the form empty with a reason when the quarter cannot be derived', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      blockers: ['run_missing', 'probable_earning_required'],
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    expect(wrapper.find('[data-test="average-suggestion-ready"]').exists()).toBe(false)
    const blocked = wrapper.get('[data-test="average-suggestion-blocked"]')
    expect(blocked.text()).toContain('payroll_absence.averages.blockers.run_missing')
    expect(blocked.text()).toContain('payroll_absence.averages.blockers.probable_earning_required')
    expect((wrapper.find('[data-test="average-gross-czk"]').element as HTMLInputElement).value)
      .toBe('0')
    expect(wrapper.text()).not.toContain('payroll_absence.averages.source_gross')
    wrapper.unmount()
  })

  /*
   * § 355 zákoníku práce — u dohody bez odpracovaných dnů skutečný průměr
   * vzniknout nemůže a použije se pravděpodobný výdělek zadaný v podmínkách
   * vztahu. Formulář ho předvyplní i s odůvodněním a řekne, odkud je.
   */
  it('offers the probable earning only where the actual average cannot arise', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      ready: true,
      blockers: [],
      source_kind: 'probable',
      actual_blockers: ['run_missing'],
      probable_hourly_minor: 28_000,
      probable_rationale: 'Sjednaná odměna 280 Kč/hod.',
      probable_term_id: 41,
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    expect(wrapper.find('[data-test="average-suggestion-probable"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="average-probable-field"]').exists()).toBe(true)
    expect((wrapper.find('[data-test="average-probable-czk"]').element as HTMLInputElement).value)
      .toBe('280')

    await wrapper.find('[data-test="average-worked-hours"]').setValue('0')
    await wrapper.find('[data-test="average-worked-days"]').setValue('0')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).toHaveBeenCalledWith(expect.objectContaining({
      probable_hourly_minor: 28_000,
      rationale: 'Sjednaná odměna 280 Kč/hod.',
    }))
    wrapper.unmount()
  })

  /*
   * Běžná cesta se nesmí prodloužit: kde se průměr spočítá z uzavřených běhů,
   * se pole pravděpodobného výdělku vůbec nenabízí.
   */
  it('hides the probable earning field when the actual average is available', async () => {
    m.averageSuggestion.mockResolvedValue(averageSuggestion({
      ready: true,
      blockers: [],
      source_kind: 'actual',
      actual_blockers: [],
      gross_earnings_minor: 14_000_000,
      worked_minutes: 27_600,
      worked_days: 61,
    }))

    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    expect(wrapper.find('[data-test="average-probable-czk"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="average-probable-field"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="average-suggestion-probable"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('dovolenou z ručního měsíčního zdroje označí k posouzení a nenabídne automatické vytvoření', async () => {
    m.leaveEntitlementCandidates.mockResolvedValue({ items: [{ employment_id: 12, employee_id: 5, employee_name: 'Syntetická Osoba', employment_code: 'SYNTH-HPP', relation_type: 'employment', period_from: '2026-01-01', period_to: '2026-09-30', weekly_minutes: 2400, entitlement_weeks: 4, allowance_source: 'company_policy', continuous_calendar_days: 273, worked_equivalent_minutes: null, input_version: 'b'.repeat(64), ready: false, blockers: ['manual_work_source_requires_review'], assessment_absences: [], predecessor_absences: 0, takeover: null }], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    await flushPromises()
    const row = wrapper.get('[data-test="leave-candidate-12"]')
    expect(row.text()).toContain('payroll_absence.leave.blockers.manual_work_source_requires_review')
    expect(row.find('input[type="checkbox"]').attributes('disabled')).toBeDefined()
    expect(row.text()).not.toContain('payroll_absence.leave.automatic_summary')
    wrapper.unmount()
  })

  it('řekne, kterou absenci posoudit, hromadně ji rozhodne a převzatý zůstatek znovu nepočítá', async () => {
    const base = {
      employee_name: 'Syntetická osoba', relation_type: 'employment', period_from: '2026-01-01', period_to: '2026-09-26',
      weekly_minutes: 2400, entitlement_weeks: 4, allowance_source: 'company_policy', continuous_calendar_days: 269,
      worked_equivalent_minutes: 60000, input_version: 'b'.repeat(64),
    }
    m.leaveEntitlementCandidates.mockResolvedValue({
      items: [
        { ...base, employment_id: 12, employee_id: 5, employment_code: 'SYNTH-HPP', ready: false,
          blockers: ['absence_legal_assessment_required'],
          assessment_absences: [{ id: 91, row_version: 1, absence_type: 'ocr', date_from: '2026-07-06', date_to: '2026-07-08' }],
          predecessor_absences: 2, takeover: null },
        { ...base, employment_id: 13, employee_id: 6, employment_code: 'SYNTH-DPC', ready: false, blockers: [],
          takeover: { minutes: 5460, effective_date: '2026-01-01', reason: 'Převzato z PAMICA: zůstatek' },
          assessment_absences: [], predecessor_absences: 0 },
      ],
      total: 2, limit: 25, offset: 0,
    })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-test="leave-candidate-12"]')
    expect(row.get('[data-test="leave-candidate-assessment-absences"]').text()).toContain('payroll_absence.types.ocr')
    expect(row.text()).toContain('payroll_absence.leave.predecessor_absences')
    expect(row.find('[data-test="leave-candidate-fix-12"]').exists()).toBe(true)
    const checkbox = row.get('input[type="checkbox"]').element as HTMLInputElement
    expect(checkbox.disabled).toBe(true)

    const takeover = wrapper.get('[data-test="leave-candidate-13"]')
    expect(takeover.get('[data-test="leave-candidate-takeover"]').text()).toContain('payroll_absence.leave.takeover_summary')
    expect((takeover.get('input[type="checkbox"]').element as HTMLInputElement).disabled).toBe(true)

    expect(wrapper.get('[data-test="leave-assessment-ocr"]').text()).toContain('payroll_absence.leave.assessment.hint_by_type.other')
    await wrapper.get('[data-test="leave-assessment-exclude-ocr"]').trigger('click')
    expect((wrapper.get('[data-test="leave-candidate-12"] input[type="checkbox"]').element as HTMLInputElement).disabled).toBe(false)

    await wrapper.get('[data-test="leave-candidate-12"] input[type="checkbox"]').setValue(true)
    const calculate = wrapper.findAll('[data-test="automatic-leave-entitlements"] button')
      .find(button => button.text().includes('payroll_absence.leave.calculate_selected'))!
    await calculate.trigger('click')
    await flushPromises()
    expect(m.createAutomaticEntitlements).toHaveBeenCalledWith(expect.objectContaining({
      items: [{ employment_id: 12, input_version: 'b'.repeat(64) }],
      absence_decisions: { ocr: 'exclude' },
    }))
    wrapper.unmount()
  })

  it('uses a rolling year range instead of freezing form controls at 2026', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    await wrapper.find('[data-test="tab-averages"]').trigger('click')

    const currentYear = new Date().getFullYear()
    const yearInput = wrapper.find('[data-test="average-year"]')
    expect(Number(yearInput.attributes('min'))).toBeLessThanOrEqual(currentYear - 5)
    expect(Number(yearInput.attributes('max'))).toBeGreaterThanOrEqual(currentYear + 1)

    await wrapper.find('[data-test="tab-leave"]').trigger('click')
    const leaveYearInput = wrapper.find('[data-test="leave-year"]')
    expect(Number(leaveYearInput.attributes('min'))).toBeLessThanOrEqual(currentYear - 5)
    expect(Number(leaveYearInput.attributes('max'))).toBeGreaterThanOrEqual(currentYear + 1)
    wrapper.unmount()
  })

  it('předvyplní období a vztah průměru z výstupního dokumentu', async () => {
    m.routeQuery = reactive({ employment: '13', tab: 'averages' })
    m.routeQuery.year = '2025'
    m.routeQuery.quarter = '2'
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect((wrapper.get('[data-test="average-year"]').element as HTMLInputElement).value).toBe('2025')
    expect((wrapper.get('[data-test="average-quarter"]').element as HTMLInputElement).value).toBe('2')
    expect(m.absencesPage.mock.calls[0][2]).toBe(13)
    m.routeQuery.quarter = '4'
    await flushPromises()
    expect((wrapper.get('[data-test="average-quarter"]').element as HTMLInputElement).value).toBe('4')
    wrapper.unmount()
  })

  it('při změně odkazu ihned přepne identitu formulářů i před dokončením načítání', async () => {
    m.routeQuery = reactive({ employment: '12', tab: 'absences' })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    let complete!: (value: ReturnType<typeof absencesPage>) => void
    m.absencesPage.mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
    m.routeQuery.employment = '13'
    await flushPromises()
    const vm = wrapper.vm as unknown as { absenceForm: { employment_id: number }, averageForm: { employment_id: number } }
    try {
      expect(vm.absenceForm.employment_id).toBe(13)
      expect(vm.averageForm.employment_id).toBe(13)
      expect(wrapper.find('[data-test="absence-form"]').exists()).toBe(false)
      expect(m.createAbsence).not.toHaveBeenCalled()
    } finally {
      complete(absencesPage([]))
      await flushPromises()
      wrapper.unmount()
    }
  })

  it('při změně měsíce odkazem načte první stránku i z rozstránkovaného seznamu', async () => {
    m.routeQuery = reactive({ employment: '12', tab: 'absences' })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const vm = wrapper.vm as unknown as { absenceOffset: number }
    vm.absenceOffset = 12
    m.routeQuery.period = '2026-08'
    await flushPromises()
    expect(lastPageArgs().offset).toBe(0)
    wrapper.unmount()
  })

  it('po změně vztahu ignoruje starou odpověď a vytvoří absenci pro zobrazenou osobu', async () => {
    m.routeQuery = reactive({ employment: '12', tab: 'absences' })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    let completeOld!: (value: ReturnType<typeof absencesPage>) => void
    m.absencesPage.mockImplementationOnce(() => new Promise(resolve => { completeOld = resolve }))
    m.routeQuery.period = '2026-08'
    await flushPromises()
    m.absencesPage.mockResolvedValue(absencesPage([absence({ employment_id: 13, id: 55 })]))
    m.routeQuery.employment = '13'
    await flushPromises()
    completeOld(absencesPage([absence()]))
    await flushPromises()
    const vm = wrapper.vm as unknown as { absenceForm: { employment_id: number }, absences: { id: number }[] }
    expect(vm.absenceForm.employment_id).toBe(13)
    expect(vm.absences[0]?.id).toBe(55)
    await wrapper.get('[data-test="absence-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAbsence).toHaveBeenCalledWith(expect.objectContaining({ employment_id: 13 }))
    wrapper.unmount()
  })

  it('preselects the employment and absence type coming from the card link', async () => {
    m.routeQuery.employment = '13'
    m.routeQuery.type = 'dpn'
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(m.absencesPage.mock.calls[0][2]).toBe(13)
    expect((wrapper.findComponent('[data-test="absence-employment"]') as VueWrapper<any>)
      .props('modelValue')).toBe(13)
    expect((wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>)
      .props('modelValue')).toBe('dpn')
    wrapper.unmount()
  })

  it('ignores an unknown employment in the query instead of breaking the page', async () => {
    m.routeQuery.employment = '999'
    m.routeQuery.type = 'not-an-absence-type'
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(m.absencesPage.mock.calls[0][2]).toBe(12)
    expect((wrapper.findComponent('[data-test="absence-type"]') as VueWrapper<any>)
      .props('modelValue')).toBe('vacation')
    wrapper.unmount()
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", zobrazila by
   * jen prvních padesát nepřítomností a o zbytku by mlčela.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(m.absencesPage.mock.calls[0][3]).toEqual({ limit: 12, offset: 0 })
    wrapper.unmount()
  })

  it('pages the card grid and re-asks the server with the new offset', async () => {
    m.absencesPage.mockResolvedValue(fullFirstPage())
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(30)

    pager.vm.$emit('update:page', 3)
    await flushPromises()

    expect(lastPageArgs()).toEqual({ limit: 12, offset: 24 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds every absence', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="absence-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the date range changes', async () => {
    m.absencesPage.mockResolvedValue(fullFirstPage())
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 3)
    await flushPromises()
    expect(lastPageArgs()).toEqual({ limit: 12, offset: 24 })

    await wrapper.findAll('input[type="date"]')[0].setValue('2026-06-01')
    const refresh = wrapper.findAll('button')
      .find(button => button.text().includes('common.refresh'))
    await refresh!.trigger('click')
    await flushPromises()

    expect(lastPageArgs()).toEqual({ limit: 12, offset: 0 })
    wrapper.unmount()
  })

  it('returns to the first page when another relation is picked', async () => {
    m.absencesPage.mockResolvedValue(fullFirstPage())
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 3)
    await flushPromises()

    expect(lastPageArgs()).toEqual({ limit: 12, offset: 24 })

    wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
      .vm.$emit('update:modelValue', 6)
    await flushPromises()

    expect(m.absencesPage.mock.calls.at(-1)![2]).toBe(13)
    expect(lastPageArgs()).toEqual({ limit: 12, offset: 0 })
    wrapper.unmount()
  })

  /*
   * Přečerpání dovolené se NEPTÁ DOPŘEDU. Zaškrtávátko „vím, že přečerpávám"
   * u každé žádosti by při 500 zaměstnancích bylo pole, které nikdo nečte
   * a všichni odklikávají — dotaz proto otevře až 409 ze serveru, a to jen
   * u toho jednoho případu, kterého se týká.
   */
  /* Q8-19: DPN bez směn skončila toastem bez cesty dál. */
  it('DPN bez rozvrhu směn ukáže hlášku s proklikem do docházky vztahu', async () => {
    m.absencesPage.mockResolvedValue(absencesPage([absence({ absence_type: 'vacation', full_name: 'Syntetická osoba' })]))
    m.decide.mockRejectedValueOnce({
      response: { data: { error: {
        code: 'absence_shifts_missing',
        message: 'Pracovní vztah nemá v době nepřítomnosti žádnou publikovanou směnu.',
        employment_id: 12,
        period: '2026-09',
      } } },
    })
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    const approve = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))
    await approve!.trigger('click')
    await flushPromises()

    const notice = wrapper.get('[data-test="absence-shifts-missing"]')
    expect(notice.text()).toContain('Syntetická osoba')
    expect(notice.text()).toContain('publikovanou směnu')
    expect(wrapper.get('[data-test="absence-shifts-missing-link"]').attributes('href'))
      .toContain('/payroll/time')
    wrapper.unmount()
  })

  describe('přečerpaná dovolená', () => {
    function overdrawRejection() {
      return {
        response: {
          data: {
            error: {
              code: 'leave_overdraw_confirmation_required',
              message: 'Čerpání 960 minut přesahuje zůstatek dovolené 480 minut.',
              balance_minutes: 480,
              requested_minutes: 960,
            },
          },
        },
      }
    }

    async function approveVacation() {
      m.absencesPage.mockResolvedValue(absencesPage([absence({ absence_type: 'vacation' })]))
      const wrapper = mount(AbsenceManagement)
      await flushPromises()
      const approve = wrapper.findAll('button')
        .find(button => button.text().includes('payroll_absence.actions.approve'))
      await approve!.trigger('click')
      await flushPromises()
      return wrapper
    }

    it('běžné schválení nepřidává žádné pole navíc', async () => {
      const wrapper = await approveVacation()

      expect(wrapper.find('[data-test="leave-overdraw-prompt"]').exists()).toBe(false)
      expect(m.decide).toHaveBeenCalledWith(44, expect.not.objectContaining({
        overdraw_confirmed: expect.anything(),
      }))
      wrapper.unmount()
    })

    it('teprve na 409 se zeptá — s konkrétními čísly', async () => {
      m.decide.mockRejectedValueOnce(overdrawRejection())
      const wrapper = await approveVacation()

      const prompt = wrapper.get('[data-test="leave-overdraw-prompt"]')
      expect(prompt.text()).toContain('payroll_absence.leave.overdraw_question')
      // Čísla musí projít do věty, jinak se účetní rozhoduje naslepo.
      expect(m.decide).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('jedním kliknutím potvrdí a pošle overdraw_confirmed', async () => {
      m.decide.mockRejectedValueOnce(overdrawRejection())
      const wrapper = await approveVacation()

      await wrapper.get('[data-test="leave-overdraw-confirm"]').trigger('click')
      await flushPromises()

      expect(m.decide).toHaveBeenLastCalledWith(44, expect.objectContaining({
        decision: 'approved',
        overdraw_confirmed: true,
      }))
      expect(wrapper.find('[data-test="leave-overdraw-prompt"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('odmítnutí dotazu nic nepošle a nechá žádost být', async () => {
      m.decide.mockRejectedValueOnce(overdrawRejection())
      const wrapper = await approveVacation()

      await wrapper.get('[data-test="leave-overdraw-cancel"]').trigger('click')
      await flushPromises()

      expect(m.decide).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-test="leave-overdraw-prompt"]').exists()).toBe(false)
      wrapper.unmount()
    })

    /* Jiná chyba se na potvrzovací dotaz zaměnit nesmí. */
    it('jinou chybu na dotaz nepřevleče', async () => {
      m.decide.mockRejectedValueOnce({
        response: { data: { error: { code: 'payroll_year_closed', message: 'Rok je uzavřen.' } } },
      })
      const wrapper = await approveVacation()

      expect(wrapper.find('[data-test="leave-overdraw-prompt"]').exists()).toBe(false)
      wrapper.unmount()
    })
  })

  /*
   * Firemní přehled. Nepřítomnosti šly číst jen po jednom člověku — u firmy
   * s pěti sty zaměstnanci to znamená proklikat pět set karet, aby účetní
   * zjistila, co čeká na rozhodnutí.
   */
  describe('firemní přehled', () => {
    // Modal hromadného schválení se teleportuje do `document.body`; bez stubu
    // by ho `wrapper.get()` minul.
    const MOUNT = { global: { stubs: { teleport: true } } }

    function twoPeoplePage() {
      return absencesPage([
        absence({
          id: 1,
          employment_id: 12,
          absence_type: 'vacation',
          full_name: 'Syntetická osoba',
          employment_code: 'SYNTH-HPP',
        }),
        absence({
          id: 2,
          employment_id: 13,
          absence_type: 'vacation',
          full_name: 'Druhá syntetická osoba',
          employment_code: 'SYNTH-DPC',
        }),
      ])
    }

    async function mountAllEmployees(page = twoPeoplePage()) {
      const wrapper = mount(AbsenceManagement, MOUNT)
      await flushPromises()
      m.absencesPage.mockResolvedValue(page)
      await wrapper.get('[data-test="absence-all-employees"]').trigger('click')
      await flushPromises()
      return wrapper
    }

    it('načte seznam bez employment_id a nechá průměry i knihu dovolené být', async () => {
      const wrapper = mount(AbsenceManagement, MOUNT)
      await flushPromises()
      m.absencesPage.mockResolvedValue(twoPeoplePage())
      const averagesBefore = m.averages.mock.calls.length
      const ledgerBefore = m.leaveLedger.mock.calls.length

      await wrapper.get('[data-test="absence-all-employees"]').trigger('click')
      await flushPromises()

      const call = m.absencesPage.mock.calls.at(-1)!
      expect(call[2]).toBeUndefined()
      expect(call[3]).toEqual({ limit: 12, offset: 0 })
      // Průměr i kniha dovolené se vedou k jednomu vztahu — dotazovat se na ně
      // bez něj by skončilo serverovou validační chybou.
      expect(m.averages.mock.calls).toHaveLength(averagesBefore)
      expect(m.leaveLedger.mock.calls).toHaveLength(ledgerBefore)
      wrapper.unmount()
    })

    it('u každého řádku ukáže, o koho jde', async () => {
      const wrapper = await mountAllEmployees()

      expect(wrapper.text()).toContain('Syntetická osoba')
      expect(wrapper.text()).toContain('SYNTH-HPP')
      expect(wrapper.text()).toContain('Druhá syntetická osoba')
      expect(wrapper.text()).toContain('SYNTH-DPC')
      wrapper.unmount()
    })

    it('kniha dovolené si řekne o osobu, ale průměry nabídnou hromadný výpočet', async () => {
      const wrapper = await mountAllEmployees()

      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="averages-person-required"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="bulk-average-earnings"]').exists()).toBe(true)

      await wrapper.get('[data-test="tab-leave"]').trigger('click')
      expect(wrapper.find('[data-test="leave-person-required"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('payroll_absence.person_required.leave')
      wrapper.unmount()
    })

    it('stránkuje i bez vybraného vztahu — strop drží server', async () => {
      const wrapper = await mountAllEmployees(absencesPage(
        Array.from({ length: 12 }, (_, index) =>
          absence({ id: index + 1, absence_type: 'vacation' })),
        30,
      ))

      wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 3)
      await flushPromises()

      const call = m.absencesPage.mock.calls.at(-1)!
      expect(call[2]).toBeUndefined()
      expect(call[3]).toEqual({ limit: 12, offset: 24 })
      wrapper.unmount()
    })

    it('novou nepřítomnost bez vybrané osoby nenabízí, ale řekne proč', async () => {
      const wrapper = await mountAllEmployees()

      expect(wrapper.find('[data-test="absence-form"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="absence-create-needs-person"]').text())
        .toBe('payroll_absence.absences.new_needs_person')
      wrapper.unmount()
    })

    it('hromadně schválí vybrané a vyřazené řádky vypíše jménem i důvodem', async () => {
      const wrapper = await mountAllEmployees(absencesPage([
        absence({ id: 1, absence_type: 'vacation', full_name: 'Syntetická osoba' }),
        absence({ id: 2, absence_type: 'dpn', full_name: 'Druhá syntetická osoba' }),
      ]))

      await wrapper.get('[data-test="absence-select-all"]').trigger('click')
      await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
      await flushPromises()

      const excluded = wrapper.get('[data-test="bulk-approve-excluded"]')
      expect(excluded.text()).toContain('Druhá syntetická osoba')
      expect(excluded.text()).toContain('payroll_absence.bulk.excluded.sickness_checklist')
      expect(wrapper.findAll('[data-test="bulk-approve-excluded-row"]')).toHaveLength(1)

      await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
      await flushPromises()

      // DPN vyžaduje posouzení na kartě, takže do dávky nepatří.
      expect(m.decide).toHaveBeenCalledTimes(1)
      expect(m.decide).toHaveBeenCalledWith(1, { row_version: 1, decision: 'approved' })
      expect(m.toastSuccess).toHaveBeenCalledWith('payroll_absence.bulk.approved')
      wrapper.unmount()
    })

    it('nic neschválí, když ve výběru zůstala jen nezpůsobilá nepřítomnost', async () => {
      const wrapper = await mountAllEmployees(absencesPage([
        absence({ id: 2, absence_type: 'dpn', full_name: 'Druhá syntetická osoba' }),
      ]))

      await wrapper.get('[data-test="absence-select-all"]').trigger('click')
      await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="bulk-approve-confirm"]').attributes('disabled'))
        .toBeDefined()
      expect(wrapper.get('[data-test="bulk-approve-blocked"]').text())
        .toBe('payroll_absence.bulk.blocked_no_candidates')
      await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
      await flushPromises()

      expect(m.decide).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('neúspěch dávky vypíše jménem a serverovou větou, ne jedním toastem', async () => {
      const wrapper = await mountAllEmployees(absencesPage([
        absence({ id: 1, absence_type: 'vacation', full_name: 'Syntetická osoba' }),
        absence({ id: 2, absence_type: 'vacation', full_name: 'Druhá syntetická osoba', employment_id: 13 }),
      ]))
      m.decide.mockRejectedValueOnce({
        response: {
          data: {
            error: {
              code: 'leave_overdraw_confirmation_required',
              message: 'Čerpání 960 minut přesahuje zůstatek dovolené 480 minut.',
            },
          },
        },
      })

      await wrapper.get('[data-test="absence-select-all"]').trigger('click')
      await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
      await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
      await flushPromises()

      const rows = wrapper.findAll('[data-test="absence-approve-error-row"]')
      expect(rows).toHaveLength(1)
      expect(rows[0].text()).toContain('Syntetická osoba')
      expect(rows[0].text()).toContain('Čerpání 960 minut přesahuje zůstatek dovolené 480 minut.')
      // Druhá žádost prošla — dávka se na první chybě nezastavuje.
      expect(m.decide).toHaveBeenCalledTimes(2)
      wrapper.unmount()
    })

    function averageCandidate(overrides: Record<string, unknown> = {}) {
      return {
        employment_id: 12,
        employee_name: 'Syntetická osoba',
        employment_code: 'SYNTH-HPP',
        decisive_from: '2026-01-01',
        decisive_to: '2026-03-31',
        ready: true,
        blockers: [],
        source_kind: 'actual',
        probable_source: null,
        probable_hourly_minor: null,
        probable_rationale: null,
        gross_earnings_minor: 300_000,
        worked_minutes: 24_000,
        worked_days: 60,
        input_version: 'a'.repeat(64),
        existing: null,
        ...overrides,
      }
    }

    it('načte kandidáty hromadného průměru a odešle jen připravené', async () => {
      m.averageCandidates.mockResolvedValue({
        items: [
          averageCandidate({ employment_id: 12 }),
          averageCandidate({
            employment_id: 13,
            employee_name: 'Druhá syntetická osoba',
            employment_code: 'SYNTH-DPC',
            ready: false,
            blockers: ['run_missing'],
            source_kind: null,
            gross_earnings_minor: null,
            worked_minutes: null,
            worked_days: null,
          }),
        ],
        total: 2,
        limit: 25,
        offset: 0,
      })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()

      expect(m.averageCandidates).toHaveBeenCalled()
      const section = wrapper.get('[data-test="bulk-average-earnings"]')
      expect(section.text()).toContain('Syntetická osoba')
      expect(section.text()).toContain('Druhá syntetická osoba')

      const selectReady = wrapper.findAll('button')
        .find(button => button.text() === 'payroll_absence.leave.select_ready')
      await selectReady!.trigger('click')

      const createButton = wrapper.get('[data-test="bulk-average-create"]')
      expect(createButton.attributes('disabled')).toBeUndefined()
      await createButton.trigger('click')
      await flushPromises()

      expect(m.createAveragesBulk).toHaveBeenCalledWith({
        year: expect.any(Number),
        quarter: expect.any(Number),
        items: [{ employment_id: 12, input_version: 'a'.repeat(64) }],
      })
      wrapper.unmount()
    })

    it('po 409 average_inputs_changed znovu načte kandidáty a ukáže hlášku', async () => {
      m.averageCandidates.mockResolvedValue({
        items: [averageCandidate()],
        total: 1,
        limit: 25,
        offset: 0,
      })
      m.createAveragesBulk.mockRejectedValueOnce({
        response: { data: { error: { code: 'average_inputs_changed', message: 'changed' } } },
      })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()

      const selectReady = wrapper.findAll('button')
        .find(button => button.text() === 'payroll_absence.leave.select_ready')
      await selectReady!.trigger('click')
      await wrapper.get('[data-test="bulk-average-create"]').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('payroll_absence.averages.bulk_inputs_changed')
      expect(m.averageCandidates).toHaveBeenCalledTimes(2)
      wrapper.unmount()
    })

    /**
     * Schválený průměr, který po opravě výpočtu (svátek v odpracované době,
     * § 353 odst. 2 ZP) neodpovídá podkladům: řádek říká proč, o kolik se
     * liší, že vyplacené náhrady se nemění, a jde hromadně přepočítat.
     */
    it('neaktuální schválený průměr vysvětlí, ukáže rozdíl a nabídne hromadný přepočet', async () => {
      m.averageCandidates.mockResolvedValue({
        items: [
          averageCandidate({
            employment_id: 12,
            ready: false,
            blockers: ['average_already_exists'],
            source_kind: null,
            existing: { id: 7, status: 'approved', source_kind: 'actual', average_hourly_minor: 26_190 },
            existing_outdated: true,
            existing_outdated_reason: 'holiday_hours',
            suggested_average_hourly_minor: 25_781,
          }),
        ],
        total: 1,
        limit: 25,
        offset: 0,
      })
      m.createAveragesBulk.mockResolvedValueOnce({ averages: [{ id: 8 }], skipped: [] })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()

      const note = wrapper.get('[data-test="bulk-average-outdated-12"]').text()
      expect(note).toContain('payroll_absence.averages.bulk_outdated_reason.holiday_hours')
      expect(note).toContain('payroll_absence.averages.bulk_outdated_difference')
      expect(note).toContain('payroll_absence.averages.bulk_outdated_action')

      await wrapper.get('[data-test="bulk-average-select-outdated"]').trigger('click')
      const createButton = wrapper.get('[data-test="bulk-average-create"]')
      expect(createButton.attributes('disabled')).toBeUndefined()
      await createButton.trigger('click')
      await flushPromises()

      expect(m.createAveragesBulk).toHaveBeenCalledWith({
        year: expect.any(Number),
        quarter: expect.any(Number),
        items: [{ employment_id: 12, input_version: 'a'.repeat(64), recalculate_outdated: true }],
      })
      wrapper.unmount()
    })

    it('ukáže přeskočené vztahy hromadného schválení jménem a důvodem', async () => {
      m.averageCandidates.mockResolvedValue({ items: [averageCandidate()], total: 1, limit: 25, offset: 0 })
      m.createAveragesBulk.mockResolvedValueOnce({
        averages: [],
        skipped: [{
          employment_id: 12,
          employee_id: 5,
          employee_name: 'Syntetická osoba',
          employment_code: 'SYNTH-HPP',
          blockers: ['probable_earning_not_recorded'],
        }],
      })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()
      const selectReady = wrapper.findAll('button')
        .find(button => button.text() === 'payroll_absence.leave.select_ready')
      await selectReady!.trigger('click')
      await wrapper.get('[data-test="bulk-average-create"]').trigger('click')
      await flushPromises()

      const skipped = wrapper.get('[data-test="bulk-average-skipped"]')
      expect(skipped.text()).toContain('Syntetická osoba')
      expect(skipped.text()).toContain('payroll_absence.averages.blockers.probable_earning_not_recorded')
      wrapper.unmount()
    })

    it('nastaví pravděpodobný výdělek vybraným vztahům a vypíše neprovedené jménem', async () => {
      m.averageCandidates.mockResolvedValue({
        items: [
          averageCandidate({
            employment_id: 12,
            relation_type: 'dpp',
            ready: false,
            blockers: ['average_already_exists'],
            source_kind: 'probable',
            probable_source: 'minimum_wage',
            existing: { id: 3, status: 'approved', source_kind: 'probable', average_hourly_minor: 13_440, probable_source: 'minimum_wage' },
          }),
          averageCandidate({
            employment_id: 13,
            employee_name: 'Druhá syntetická osoba',
            relation_type: 'dpp',
            ready: false,
            blockers: ['average_already_exists'],
            existing: { id: 4, status: 'approved', source_kind: 'probable', average_hourly_minor: 13_440, probable_source: 'minimum_wage' },
          }),
          averageCandidate({ employment_id: 14, employee_name: 'Zaměstnanec HPP', relation_type: 'employment' }),
        ],
        total: 3,
        limit: 25,
        offset: 0,
      })
      m.setProbableBulk.mockResolvedValue({
        counts: { applied: 1, unchanged: 0, failed: 1 },
        applied: [{ employment_id: 12, employee_id: 5, employee_name: 'Syntetická osoba', employment_code: 'A', warnings: [], snapshot_id: 9 }],
        unchanged: [],
        failed: [{
          employment_id: 13,
          employee_id: 6,
          employee_name: 'Druhá syntetická osoba',
          employment_code: 'B',
          code: 'average_reported_in_jmhz',
          message: 'server',
          params: { quarter: '1/2026', period: '2/2026' },
        }],
      })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()

      await wrapper.get('[data-test="probable-select-minimum"]').trigger('click')
      expect(wrapper.get('[data-test="probable-apply"]').text()).toContain('payroll_absence.averages.probable_bulk.apply')
      // Bez odůvodnění se nic neodešle (§ 355 odst. 2 ZP).
      await wrapper.get('[data-test="probable-amount"]').setValue('180')
      await wrapper.get('[data-test="probable-apply"]').trigger('click')
      await flushPromises()
      expect(m.setProbableBulk).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('payroll_absence.averages.probable_bulk.rationale_required')

      await wrapper.get('[data-test="probable-rationale"]').setValue('Sjednaná odměna 180 Kč/h')
      await wrapper.get('[data-test="probable-apply"]').trigger('click')
      await flushPromises()

      expect(m.setProbableBulk).toHaveBeenCalledWith({
        year: expect.any(Number),
        quarter: expect.any(Number),
        probable_hourly_minor: 18_000,
        rationale: 'Sjednaná odměna 180 Kč/h',
        employment_ids: [12, 13],
      })
      const failed = wrapper.get('[data-test="probable-bulk-failed"]')
      expect(failed.text()).toContain('Druhá syntetická osoba')
      expect(failed.text()).toContain('payroll_absence.averages.probable_bulk.failure.average_reported_in_jmhz')
      wrapper.unmount()
    })

    it('rychlý výběr DPP vybere jen dohody o provedení práce', async () => {
      m.averageCandidates.mockResolvedValue({
        items: [
          averageCandidate({ employment_id: 12, relation_type: 'dpp' }),
          averageCandidate({ employment_id: 14, employee_name: 'Zaměstnanec HPP', relation_type: 'employment' }),
        ],
        total: 2,
        limit: 25,
        offset: 0,
      })
      m.setProbableBulk.mockResolvedValue({ counts: { applied: 1, unchanged: 0, failed: 0 }, applied: [], unchanged: [], failed: [] })

      const wrapper = await mountAllEmployees()
      await wrapper.get('[data-test="tab-averages"]').trigger('click')
      await flushPromises()
      await wrapper.get('[data-test="probable-select-dpp"]').trigger('click')
      await wrapper.get('[data-test="probable-amount"]').setValue('180')
      await wrapper.get('[data-test="probable-rationale"]').setValue('Dohoda')
      await wrapper.get('[data-test="probable-apply"]').trigger('click')
      await flushPromises()

      expect(m.setProbableBulk.mock.calls[0]![0].employment_ids).toEqual([12])
      wrapper.unmount()
    })
  })
})
