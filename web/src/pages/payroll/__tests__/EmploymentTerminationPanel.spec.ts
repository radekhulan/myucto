import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollTerminationOverview } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employmentTermination: vi.fn(),
  saveEmploymentTermination: vi.fn(),
  settleTerminationLeave: vi.fn(),
  reverseTerminationLeave: vi.fn(),
  createTerminationSeverance: vi.fn(),
  createTerminationWorkInjuryCompensation: vi.fn(),
  addTerminationSurvivor: vi.fn(),
  removeTerminationSurvivor: vi.fn(),
  assessTerminationDeathTax: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employmentTermination: m.employmentTermination,
    saveEmploymentTermination: m.saveEmploymentTermination,
    settleTerminationLeave: m.settleTerminationLeave,
    reverseTerminationLeave: m.reverseTerminationLeave,
    createTerminationSeverance: m.createTerminationSeverance,
    createTerminationWorkInjuryCompensation: m.createTerminationWorkInjuryCompensation,
    addTerminationSurvivor: m.addTerminationSurvivor,
    removeTerminationSurvivor: m.removeTerminationSurvivor,
    assessTerminationDeathTax: m.assessTerminationDeathTax,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    te: () => true,
  }),
}))

vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))

import EmploymentTerminationPanel from '@/pages/payroll/EmploymentTerminationPanel.vue'

const ALLOWED = {
  employer_notice: ['organizational', 'health_long_term', 'health_work_injury', 'max_exposure', 'requirements_unmet', 'breach_gross', 'breach_serious', 'breach_minor_repeated', 'sickness_regime'],
  agreement: ['none', 'organizational', 'health_long_term', 'health_work_injury', 'max_exposure'],
  employee_notice: ['none'],
  employer_immediate: ['breach_gross', 'criminal_conviction'],
  employee_immediate: ['health_no_transfer', 'wage_not_paid'],
  probation_employer: ['none'],
  probation_employee: ['none'],
  fixed_term_expiry: ['none'],
  death: ['none'],
  foreigner_permit: ['none'],
  other: ['none'],
} as PayrollTerminationOverview['options']['allowed_grounds']

function overview(overrides: Partial<PayrollTerminationOverview> = {}): PayrollTerminationOverview {
  return {
    employment: { id: 12, employee_id: 20, relation_type: 'employment', status: 'ended', start_date: '2024-01-01', end_date: '2026-07-31' },
    termination: null,
    derived: null,
    average: { year: 2026, quarter: 3, snapshot_id: null, hourly_minor: null, monthly_gross_minor: null, monthly_net_minor: null },
    leave_settlement: { year: 2026, state: 'nothing', minutes: 0, balance_minutes: 0, average_hourly_minor: null, amount_minor: 0, input: null },
    severance: { state: 'reason_missing', kind: null, statutory_multiple: 0, multiple: 0, rule: 'none', tenure_start: null, counted_previous: [], monthly_average_minor: null, amount_minor: 0, garnishment_multiple: null, garnishment_period_to: null, work_injury_payer: null, work_injury_paid_on: null, input: null },
    death: null,
    a2_prefill: null,
    issues: [],
    options: { methods: Object.keys(ALLOWED) as PayrollTerminationOverview['options']['methods'], allowed_grounds: ALLOWED },
    ...overrides,
  }
}

function saved(): PayrollTerminationOverview {
  return overview({
    termination: {
      id: 1, employment_id: 12, termination_method: 'agreement', legal_ground: 'organizational',
      employee_stated_reason: null, severance_multiple_override: null, severance_override_reason: null,
      working_time_account_applies: false, other_income_from: null, other_payer_applies_protected_amount: false,
      work_injury_compensation_payer: null, work_injury_compensation_paid_on: null,
      death_tax_assessment: null, death_tax_assessed_by: null,
      death_tax_assessed_at: null, row_version: 1, updated_at: '2026-08-01 10:00:00',
    },
    derived: {
      regzec_reason_code: '4', unemployment_office_kind: 'organizational', ended_by_death: false,
      settlement_reportable: true, severance_basis: 'organizational', work_injury_compensation: false,
      stated_reason_allowed: false,
    },
    leave_settlement: { year: 2026, state: 'payout', minutes: 4800, balance_minutes: 4800, average_hourly_minor: 25000, amount_minor: 2000000, input: null },
    severance: { state: 'ready', kind: 'severance', statutory_multiple: 3, multiple: 3, rule: 'zp-67-1', tenure_start: '2024-01-01', counted_previous: [], monthly_average_minor: 4348000, amount_minor: 13044000, garnishment_multiple: 3, garnishment_period_to: '2026-10-31', work_injury_payer: null, work_injury_paid_on: null, input: null },
  })
}

function workInjury(): PayrollTerminationOverview {
  const base = saved()
  return {
    ...base,
    termination: { ...base.termination!, termination_method: 'employer_notice', legal_ground: 'health_work_injury' },
    severance: { ...base.severance, kind: 'work_injury_compensation', statutory_multiple: 12, multiple: 12, amount_minor: 52176000, garnishment_multiple: 12, garnishment_period_to: '2027-07-31' },
  }
}

function mountPanel(canWrite = true) {
  return mount(EmploymentTerminationPanel, { props: { employmentId: 12, canWrite } })
}

describe('EmploymentTerminationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows what is missing, for whom and where to fix it', async () => {
    m.employmentTermination.mockResolvedValue(overview({
      issues: [
        { code: 'reason_missing', severity: 'blocker', params: {} },
        { code: 'average_missing', severity: 'blocker', params: { year: 2026, quarter: 3 } },
      ],
    }))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="termination-issue-reason_missing"]').exists()).toBe(true)
    const average = wrapper.find('[data-test="termination-issue-average_missing"]')
    expect(average.text()).toContain('"quarter":3')
    expect(average.find('a').attributes('href')).toBe('/payroll/absences?employment=12&tab=averages&year=2026&quarter=3')
  })

  it('offers only legal grounds that fit the method and saves one record', async () => {
    m.employmentTermination.mockResolvedValue(overview())
    m.saveEmploymentTermination.mockResolvedValue(saved())
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="termination-method"]').setValue('employee_notice')
    expect(wrapper.find('[data-test="termination-ground"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="termination-stated-reason"]').exists()).toBe(true)

    await wrapper.find('[data-test="termination-method"]').setValue('agreement')
    const options = wrapper.findAll('[data-test="termination-ground"] option').map(option => option.attributes('value'))
    expect(options).toEqual(['none', 'organizational', 'health_long_term', 'health_work_injury', 'max_exposure'])
    await wrapper.find('[data-test="termination-ground"]').setValue('organizational')

    const save = wrapper.findAll('button').find(button => button.text().includes('payroll.people.termination.save'))
    expect(save).toBeDefined()
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmploymentTermination).toHaveBeenCalledWith(12, expect.objectContaining({
      termination_method: 'agreement',
      legal_ground: 'organizational',
      severance_multiple_override: null,
      working_time_account_applies: false,
    }))
    expect(wrapper.emitted('loaded')?.at(-1)?.[0]).toMatchObject({ derived: { regzec_reason_code: '4' } })
    expect(wrapper.find('[data-test="termination-derived"]').text()).toContain('"code":"4"')
  })

  it('pays out unused leave and adds severance as last-run inputs', async () => {
    m.employmentTermination.mockResolvedValue(saved())
    m.settleTerminationLeave.mockResolvedValue(saved())
    m.createTerminationSeverance.mockResolvedValue(saved())
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="termination-leave"]').text()).toContain('payroll.people.termination.leave.states.payout')
    const buttons = wrapper.findAll('button')
    await buttons.find(button => button.text().includes('leave.settle_payout'))!.trigger('click')
    await flushPromises()
    expect(m.settleTerminationLeave).toHaveBeenCalledWith(12)

    await wrapper.findAll('button').find(button => button.text().includes('severance.create'))!.trigger('click')
    await flushPromises()
    expect(m.createTerminationSeverance).toHaveBeenCalledWith(12, 3)
  })

  /*
   * § 299 odst. 4 o. s. ř.: počet násobků pro srážky je předvyplněný z § 67 ZP
   * a jde změnit; mimo rozsah 1–36 se odstupné založit nedá.
   */
  it('sends the garnishment multiple entered on the card and rejects an invalid one', async () => {
    m.employmentTermination.mockResolvedValue(saved())
    m.createTerminationSeverance.mockResolvedValue(saved())
    const wrapper = mountPanel()
    await flushPromises()

    const multiple = wrapper.get('[data-test="termination-garnishment-multiple"]')
    expect((multiple.element as HTMLInputElement).value).toBe('3')
    await multiple.setValue('0')
    const create = () => wrapper.findAll('button').find(button => button.text().includes('severance.create'))!
    expect(create().attributes('disabled')).toBeDefined()

    await multiple.setValue('4')
    await create().trigger('click')
    await flushPromises()
    expect(m.createTerminationSeverance).toHaveBeenCalledWith(12, 4)
  })

  /** § 299 odst. 4 věta druhá — jiný příjem povinného a potvrzení nového plátce se ukládají se skončením. */
  it('saves the other income date and the new payer confirmation with the termination', async () => {
    m.employmentTermination.mockResolvedValue(saved())
    m.saveEmploymentTermination.mockResolvedValue(saved())
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="termination-other-income-from"]').setValue('2026-09-01')
    await wrapper.get('[data-test="termination-other-payer"]').setValue(true)
    const save = wrapper.findAll('button').find(button => button.text().includes('payroll.people.termination.save'))
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmploymentTermination).toHaveBeenCalledWith(12, expect.objectContaining({
      other_income_from: '2026-09-01',
      other_payer_applies_protected_amount: true,
    }))
  })

  /** § 271ca ZP — tlačítko Založit náhradu se ptá na plátce a den výplaty. */
  it('creates the Section 271ca compensation only after payer and payment date are chosen', async () => {
    m.employmentTermination.mockResolvedValue(workInjury())
    m.createTerminationWorkInjuryCompensation.mockResolvedValue(workInjury())
    const wrapper = mountPanel()
    await flushPromises()

    const create = () => wrapper.findAll('button').find(button => button.text().includes('work_injury.create'))!
    expect(create().attributes('disabled')).toBeDefined()
    expect((wrapper.get('[data-test="termination-work-injury-paid-on"]').element as HTMLInputElement).value).toBe('31. 07. 2026')

    await wrapper.get('[data-test="termination-work-injury-payer"]').setValue('insurer')
    await wrapper.get('[data-test="termination-work-injury-paid-on"]').setValue('2026-08-20')
    await create().trigger('click')
    await flushPromises()

    expect(m.createTerminationWorkInjuryCompensation).toHaveBeenCalledWith(12, { payer: 'insurer', paid_on: '2026-08-20' })
  })

  it('does not offer writes to a read-only user', async () => {
    m.employmentTermination.mockResolvedValue(saved())
    const wrapper = mountPanel(false)
    await flushPromises()

    expect(wrapper.findAll('button').some(button => button.text().includes('leave.settle_payout'))).toBe(false)
    expect((wrapper.find('[data-test="termination-method"]').element as HTMLSelectElement).disabled).toBe(true)
  })
})
