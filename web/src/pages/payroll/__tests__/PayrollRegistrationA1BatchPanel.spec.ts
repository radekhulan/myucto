import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  candidates: vi.fn(),
  prepare: vi.fn(),
  defaults: vi.fn(),
  canWrite: vi.fn(() => true),
  query: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.query }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    registrationA1Candidates: m.candidates,
    prepareRegistrationA1Batch: m.prepare,
    setRegistrationA1Defaults: m.defaults,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))

import PayrollRegistrationA1BatchPanel from '@/pages/payroll/PayrollRegistrationA1BatchPanel.vue'

function candidate(overrides: Record<string, unknown> = {}) {
  return {
    employment_id: 5,
    employee_id: 9,
    employee_name: 'Syntetická Osoba',
    code: 'OS-1',
    relation_type: 'employment',
    status: 'planned',
    start_date: '2026-11-16',
    end_date: null,
    profile_status: 'verified',
    complete: true,
    problems: [],
    after_pre_registration: false,
    deadline: { earliest_registration_on: '2026-10-17', due_on: '2026-11-16', derived: true, overdue: false },
    warnings: [],
    ...overrides,
  }
}

function mountPanel() {
  return mount(PayrollRegistrationA1BatchPanel, {
    props: { environment: 'test' },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
        DateInput: {
          props: ['modelValue'],
          template: '<input :value="modelValue">',
        },
      },
    },
  })
}

describe('PayrollRegistrationA1BatchPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.query = {}
    m.canWrite.mockReturnValue(true)
    m.candidates.mockResolvedValue({
      from: '2026-11-01',
      to: '2026-12-31',
      today: '2026-11-11',
      items: [
        candidate(),
        candidate({
          employment_id: 6,
          employee_name: 'Neúplný Profil',
          relation_type: 'dpp',
          profile_status: 'draft',
          complete: false,
          problems: [{ field: 'employment.profession_code', code: 'missing', message: 'Profese chybí.' }],
          deadline: { earliest_registration_on: '2026-10-01', due_on: '2026-11-02', derived: true, overdue: true },
          warnings: [{ code: 'duplicate_dpp', employment_id: 4, employment_code: 'DPP-1', message: 'x' }],
        }),
      ],
    })
  })

  it('offers only complete profiles and shows what is missing, the deadline and the duplicate warning', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(m.candidates).toHaveBeenCalledWith('test', {})
    const ready = wrapper.get('[data-test="registration-a1-select-5"]').element as HTMLInputElement
    const draft = wrapper.get('[data-test="registration-a1-select-6"]').element as HTMLInputElement
    expect(ready.disabled).toBe(false)
    expect(draft.disabled).toBe(true)
    expect(wrapper.get('[data-test="registration-a1-problems-6"]').text()).toContain('Profese chybí.')
    expect(wrapper.get('[data-test="registration-a1-deadline-6"]').text())
      .toContain('payroll.registrationA1.deadline.overdue')
    expect(wrapper.get('[data-test="registration-a1-warning-6"]').text())
      .toContain('payroll.registrationA1.warning.duplicate_dpp')
    expect(JSON.parse(wrapper.get('[data-test="registration-a1-open-6"]').attributes('data-to') ?? '{}'))
      .toEqual({
        name: 'payroll-people',
        query: { person: '9', employment: '6', panel: 'registration' },
      })
  })

  it('prepares the selected relationships and reports each result', async () => {
    m.prepare.mockResolvedValue({
      results: [{
        employment_id: 5,
        status: 'prepared',
        submission_id: 77,
        agenda_code: 'REGZEC25',
        created: true,
        code: null,
        message: null,
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="registration-a1-select-5"]').setValue(true)
    await wrapper.get('[data-test="registration-a1-submit"]').trigger('click')
    await flushPromises()

    expect(m.prepare).toHaveBeenCalledWith({ environment: 'test', employment_ids: [5] })
    expect(wrapper.get('[data-test="registration-a1-result-5"]').text())
      .toContain('payroll.registrationA1.result.prepared')
    expect(m.candidates).toHaveBeenCalledTimes(2)
  })

  /*
   * Nástup 1. 1. 2027 viděný 11. 11. 2026: přihlášku jde podat nejdřív osm
   * dnů před nástupem. Řádek řekne od kdy a k přípravě se zatím nenabídne.
   */
  it('u budoucího nástupu řekne, od kdy půjde přihlášku podat, a zatím ji nenabídne', async () => {
    m.candidates.mockResolvedValue({
      from: '2026-11-01',
      to: '2027-01-31',
      today: '2026-11-11',
      items: [
        candidate({
          employment_id: 7,
          start_date: '2027-01-01',
          deadline: {
            earliest_registration_on: '2026-12-24',
            due_on: '2027-01-01',
            derived: true,
            overdue: false,
            too_early: true,
          },
        }),
      ],
    })
    const wrapper = mountPanel()
    await flushPromises()

    const deadline = wrapper.get('[data-test="registration-a1-deadline-7"]').text()
    expect(deadline).toContain('payroll.registrationA1.deadline.from')
    expect(deadline).toContain('24. 12. 2026')
    expect(deadline).toContain('01. 01. 2027')
    expect((wrapper.get('[data-test="registration-a1-select-7"]').element as HTMLInputElement).disabled)
      .toBe(true)
    expect((wrapper.get('[data-test="registration-a1-select-all"]').element as HTMLInputElement).disabled)
      .toBe(true)
  })

  it('sets shared A1 defaults for incomplete profiles in the period and reloads the list', async () => {
    m.defaults.mockResolvedValue({
      results: [{ employment_id: 6, status: 'verified', changed: ['employment.position_name'], message: null, problems: [] }],
      counts: { verified: 1, draft: 0, skipped: 0, failed: 0 },
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="registration-a1-defaults"]').text()).toContain('"count":1')
    await wrapper.get('[data-test="registration-a1-defaults-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-defaults-position"]').setValue('Promotér')
    await wrapper.get('[data-test="registration-a1-defaults-apply"]').trigger('submit')
    await flushPromises()

    expect(m.defaults).toHaveBeenCalledWith({
      employment_ids: [6],
      defaults: {
        work_mode_code: '1',
        continuous_operation: false,
        workplace_city: null,
        position_name: 'Promotér',
        leadership: false,
      },
      overwrite: false,
      split_house_number: true,
    })
    expect(m.candidates).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-test="registration-a1-defaults-result"]').text()).toContain('"verified":1')
  })

  it('hides shared A1 defaults without write permission', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="registration-a1-defaults"]').exists()).toBe(false)
  })

  it('opens on the period from the link (period=YYYY-MM)', async () => {
    m.query = { period: '2026-02' }
    mountPanel()
    await flushPromises()
    expect(m.candidates).toHaveBeenCalledWith('test', { from: '2026-02-01', to: '2026-02-28' })
  })

  it('prefers explicit from and to in the link and ignores malformed values', async () => {
    m.query = { from: '2026-12-01', to: 'konec', period: '2026-11' }
    mountPanel()
    await flushPromises()
    expect(m.candidates).toHaveBeenCalledWith('test', { from: '2026-12-01', to: '2026-11-30' })
  })
})
