import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  context: vi.fn(),
  absencesPage: vi.fn(),
  averages: vi.fn(),
  leaveLedger: vi.fn(),
  decide: vi.fn(),
  createAbsence: vi.fn(),
  createAverage: vi.fn(),
  createEntitlement: vi.fn(),
  createLeaveEntry: vi.fn(),
  leaveEntitlementCandidates: vi.fn(),
  createAutomaticEntitlements: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    context: m.context,
    absencesPage: m.absencesPage,
    averages: m.averages,
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
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: ref('cs-CZ'),
  }),
}))

import AbsenceManagement from '@/pages/payroll/AbsenceManagement.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

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
   * Zašedlé „Vytvořit" u dovolené dřív mlčelo. Když navíc pro vztah není
   * spočítaný žádný průměr, uživatel neměl jak zjistit, že se počítá jinde.
   */
  it('points to the Averages tab when no average exists for the relation', async () => {
    m.averages.mockResolvedValue([])

    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const button = wrapper.get('[data-test="absence-create"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title'))
      .toBe('payroll_absence.absences.average_missing_for_relation')
    expect(wrapper.get('[data-test="absence-create-blocked"]').text())
      .toBe('payroll_absence.absences.average_missing_for_relation')

    await wrapper.get('[data-test="go-to-averages"]').trigger('click')
    expect(wrapper.text()).toContain('payroll_absence.averages.create')
  })

  it('asks for a pick when an average exists but none is selected', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.find('[data-test="go-to-averages"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="absence-create"]').attributes('title'))
      .toBe('payroll_absence.absences.average_required_hint')
  })

  it('renders a responsive DPN card and sends explicit review flags', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll_absence.types.dpn')
    expect(wrapper.text()).toContain('Syntetická osoba')
    const checks = wrapper.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(3)
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

    const formInputs = wrapper.findAll('input[type="number"], input[type="date"], input[type="text"]')
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

  it('does not submit an absence requiring an approved average without one', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const create = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.absences.create'))
    expect(create!.attributes('disabled')).toBeDefined()
    await create!.trigger('click')

    expect(m.createAbsence).not.toHaveBeenCalled()
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

    await wrapper.find('[data-test="average-gross-czk"]').setValue('1.001')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(m.createAverage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('payroll_absence.validation.money_precision')

    m.createAverage.mockRejectedValueOnce({
      response: { data: { error: { message: 'Přesná zpráva validační chyby z API.' } } },
    })
    await wrapper.find('[data-test="average-gross-czk"]').setValue('100')
    await wrapper.find('[data-test="average-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-test="average-error"]').text())
      .toBe('Přesná zpráva validační chyby z API.')
    expect(m.toastError).not.toHaveBeenCalledWith('Přesná zpráva validační chyby z API.')
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
})
