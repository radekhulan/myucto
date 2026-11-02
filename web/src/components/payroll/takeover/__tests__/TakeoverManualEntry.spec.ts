import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollTakeoverManualEntry } from '@/api/payrollMigrationReconciliation'

const m = vi.hoisted(() => ({
  peopleOptions: vi.fn(),
  manualForm: vi.fn(),
  manualSave: vi.fn(),
  manualFiledForms: vi.fn(),
  success: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { peopleOptions: m.peopleOptions },
}))
vi.mock('@/api/payrollMigrationReconciliation', () => ({
  payrollTakeoverWagesApi: { manualForm: m.manualForm, manualSave: m.manualSave, manualFiledForms: m.manualFiledForms },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: vi.fn() }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import TakeoverManualEntry from '@/components/payroll/takeover/TakeoverManualEntry.vue'

function row(employmentId: number, month: number, gross = 0) {
  return {
    employment_id: employmentId,
    month,
    gross_minor: gross,
    net_minor: 0,
    deductions_minor: 0,
    net_payable_minor: 0,
    social_base_minor: 0,
    health_base_minor: 0,
    employee_social_minor: 0,
    employee_health_minor: 0,
    employer_social_minor: 0,
    employer_health_minor: 0,
    health_minimum_top_up_minor: 0,
    advance_base_minor: 0,
    advance_tax_minor: 0,
    withholding_base_minor: 0,
    withholding_tax_minor: 0,
    applied_credits_minor: 0,
    applied_child_credit_minor: 0,
    tax_bonus_minor: 0,
    insurance_days: 0,
    excluded_days: 0,
    worked_days_hundredths: 0,
    worked_minutes: 0,
    pension_participation: true,
    old_age_savings_minor: 0,
    non_cash_health_benefit_minor: 0,
    non_cash_leisure_benefit_minor: 0,
    payout_date: null,
    stored: false,
  }
}

function entry(): PayrollTakeoverManualEntry {
  return {
    year: 2026,
    employee_id: 7,
    takeover_months: [1, 2],
    employments: [{ id: 11, code: 'HPP-1', relation_type: 'employment', start_date: '2024-01-01', end_date: null, months: [1, 2] }],
    rows: [row(11, 1), row(11, 2)],
    source_reference: '',
    locked: false,
    lock_reason: null,
  }
}

describe('TakeoverManualEntry', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.peopleOptions.mockResolvedValue([{ id: 7, full_name: 'Syntetická osoba', is_active: true, needs_setup: false }])
    m.manualForm.mockResolvedValue(entry())
    m.manualSave.mockResolvedValue(entry())
  })

  /** Zaměstnanec se vybírá jménem, ne interním číslem. */
  it('nabídne zaměstnance jménem a po výběru načte převzaté měsíce', async () => {
    const wrapper = mount(TakeoverManualEntry, { props: { year: 2026, canWrite: true } })
    await flushPromises()

    const select = wrapper.get('[data-test="takeover-manual-employee"]')
    expect(select.text()).toContain('Syntetická osoba')
    await select.setValue('7')
    await flushPromises()

    expect(m.manualForm).toHaveBeenCalledWith(2026, 7)
    expect(wrapper.find('[data-test="takeover-manual-row-11-1"]').exists()).toBe(true)
  })

  /** Návrh z hlášení jen vyplní formulář, ukládá až účetní. */
  it('doplní prázdná pole z přijatých hlášení a neuloží je', async () => {
    m.routeQuery = { employee: '7' }
    m.manualFiledForms.mockResolvedValue({
      rows: [{ ...row(11, 1, 450_000), employee_social_minor: 32_000, insurance_days: 31, derived: ['net_minor'] }],
      missing_months: [2],
    })
    const wrapper = mount(TakeoverManualEntry, { props: { year: 2026, canWrite: true } })
    await flushPromises()

    await wrapper.get('[data-test="takeover-manual-prefill"]').trigger('click')
    await flushPromises()

    expect(m.manualFiledForms).toHaveBeenCalledWith(2026, 7)
    expect(m.manualSave).not.toHaveBeenCalled()
    const social = wrapper.get('[data-test="takeover-manual-11-1-employee_social_minor"]').element as HTMLInputElement
    expect(social.value).toBe('320')
    const note = wrapper.get('[data-test="takeover-manual-prefill-note"]').text()
    expect(note).toContain('payroll.takeover_manual.prefill_done')
    expect(note).toContain('payroll.takeover_manual.prefill_derived')
    expect(note).toContain('payroll.takeover_manual.prefill_missing')
  })

  /** Proklik z kontroly převzetí otevře formulář rovnou u toho, komu něco chybí. */
  it('předvybere zaměstnance z adresy', async () => {
    m.routeQuery = { employee: '7' }
    mount(TakeoverManualEntry, { props: { year: 2026, canWrite: true } })
    await flushPromises()

    expect(m.manualForm).toHaveBeenCalledWith(2026, 7)
  })

  it('částky v korunách pošle v haléřích a prázdný měsíc bez potvrzení neuloží', async () => {
    m.routeQuery = { employee: '7' }
    const wrapper = mount(TakeoverManualEntry, { props: { year: 2026, canWrite: true } })
    await flushPromises()

    await wrapper.get('[data-test="takeover-manual-11-1-gross_minor"]').setValue('40 000,50')
    await wrapper.get('[data-test="takeover-manual-11-1-advance_base_minor"]').setValue('40000')
    await wrapper.get('[data-test="takeover-manual-save"]').trigger('click')
    await flushPromises()

    expect(m.manualSave).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="takeover-manual-error"]').text())
      .toBe('payroll.takeover_manual.empty_month')

    await wrapper.get('[data-test="takeover-manual-11-2-confirmed-zero"]').setValue(true)
    await wrapper.get('[data-test="takeover-manual-save"]').trigger('click')
    await flushPromises()

    const payload = m.manualSave.mock.calls[0][2]
    expect(payload.rows[0].gross_minor).toBe(4_000_050)
    expect(payload.rows[0].advance_base_minor).toBe(4_000_000)
    expect(payload.rows[0].confirmed_zero).toBeUndefined()
    expect(payload.rows[1].confirmed_zero).toBe(true)
    expect(m.success).toHaveBeenCalled()
  })

  it('bez práva zapisovat pole zamkne a tlačítko neukáže', async () => {
    m.routeQuery = { employee: '7' }
    const wrapper = mount(TakeoverManualEntry, { props: { year: 2026, canWrite: false } })
    await flushPromises()

    expect(wrapper.get('[data-test="takeover-manual-11-1-gross_minor"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="takeover-manual-save"]').exists()).toBe(false)
  })
})
