import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { AttendancePerson, AttendancePreview } from '@/api/payrollImports'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    te: () => true,
    t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))

import AttendanceSummaryStep from '../AttendanceSummaryStep.vue'

function person(key: string, overrides: Partial<AttendancePerson> = {}): AttendancePerson {
  return {
    key,
    display_name: `Syntetický ${key}`,
    personal_number: key,
    birth_number_masked: null,
    relation_label: 'DPP',
    department: null,
    cost_center: null,
    position: null,
    weekly_hours: null,
    start_end_note: null,
    monthly_wage: null,
    sources: [],
    match: {
      status: 'matched', matched_by: 'employment_code', employment_id: 501, employee_id: 401,
      employee_name: null, employment_code: key, candidates: [],
    },
    metrics: [],
    components: [],
    reference: { gross_minor: null, net_minor: null, hours: null },
    warnings: [],
    ...overrides,
  }
}

function preview(persons: AttendancePerson[], rateWages: NonNullable<AttendancePreview['summary']['rate_wages']>): AttendancePreview {
  return {
    period: '2026-05',
    content_hash: 'h',
    profile: { id: null, name: null, auto: true },
    files: [],
    sheets: [],
    unrecognized_columns: [],
    rules: [],
    employment_options: [{ employment_id: 501, employee_id: 401, label: 'DPP B021', code: 'B021', status: 'active' }],
    persons,
    component_checks: [],
    wage_changes: [],
    upgrade_available: null,
    summary: { persons: persons.length, matched: persons.length, ambiguous: 0, not_found: 0, metrics: 0, components: 1, amount_minor_total: 610_000, rate_wages: rateWages },
  } as unknown as AttendancePreview
}

describe('AttendanceSummaryStep: mzda ze sazby a hodin', () => {
  it('řekne, že ze sazby a hodin vznikne mzda, za kolik, a u osoby ukáže výpočet', () => {
    const computed = person('B030', {
      components: [{ component_code: 'MZDA_HODINOVA_DOCH', amount: '6100.00', amount_minor: 610_000, source: 'mesic.xlsx', conflicts: [] }],
      rate_wage: { status: 'computed', component_code: 'MZDA_HODINOVA_DOCH', hours: '30.50', hourly_rate_minor: 20_000, amount_minor: 610_000 },
    })
    const wrapper = mount(AttendanceSummaryStep, {
      props: { preview: preview([computed], { computed: 1, amount_minor: 610_000, no_hours: 0, hourly_terms: 0 }), manualLinks: {} },
    })
    expect(wrapper.get('[data-testid="attendance-rate-wages-computed"]').text()).toContain('payroll_imports.attendance.summary.rate_wages_computed')
    expect(wrapper.get('[data-testid="attendance-rate-wages-computed"]').text()).toContain('"count":1')
    expect(wrapper.find('[data-testid="attendance-rate-wages-no-hours"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="attendance-rate-wage-formula"]').text()).toContain('payroll_imports.attendance.summary.rate_wage_formula')
  })

  it('řekne, u kolika osob mzda nevznikne a proč', () => {
    const wrapper = mount(AttendanceSummaryStep, {
      props: {
        preview: preview([person('B032', { rate_wage: { status: 'no_hours' } })], { computed: 0, amount_minor: 0, no_hours: 1, hourly_terms: 2 }),
        manualLinks: {},
      },
    })
    expect(wrapper.get('[data-testid="attendance-rate-wages-no-hours"]').text()).toContain('"count":1')
    expect(wrapper.get('[data-testid="attendance-rate-wages-hourly-terms"]').text()).toContain('"count":2')
    expect(wrapper.find('[data-testid="attendance-rate-wages-computed"]').exists()).toBe(false)
  })

  it('bez sazeb oznámení neukáže', () => {
    const wrapper = mount(AttendanceSummaryStep, {
      props: { preview: preview([person('B033')], { computed: 0, amount_minor: 0, no_hours: 0, hourly_terms: 0 }), manualLinks: {} },
    })
    expect(wrapper.find('[data-testid="attendance-rate-wages"]').exists()).toBe(false)
  })
})

describe('AttendanceSummaryStep: přeskočené osoby', () => {
  const rate = (key: string, minor: number, overrides: Partial<AttendancePerson> = {}) => person(key, {
    components: [{ component_code: 'MZDA_HODINOVA_DOCH', amount: String(minor / 100), amount_minor: minor, source: 'mesic.xlsx', conflicts: [] }],
    rate_wage: { status: 'computed', component_code: 'MZDA_HODINOVA_DOCH', hours: '8.00', hourly_rate_minor: 18_000, amount_minor: minor },
    ...overrides,
  })
  const unassigned = {
    status: 'not_found' as const, matched_by: null, employment_id: null, employee_id: null,
    employee_name: null, employment_code: null, candidates: [],
  }

  it('do počtu a částek nepočítá osobu, která vstup nedostane', () => {
    const persons = [rate('B040', 144_000), rate('B041', 108_000, { match: unassigned })]
    const wrapper = mount(AttendanceSummaryStep, {
      props: { preview: preview(persons, { computed: 2, amount_minor: 252_000, no_hours: 0, hourly_terms: 0 }), manualLinks: {} },
    })
    const computed = wrapper.get('[data-testid="attendance-rate-wages-computed"]').text()
    expect(computed).toContain('"count":1')
    expect(computed).toContain('rate_wages_skipped{"count":1}')
    expect(wrapper.get('[data-testid="attendance-amount-total"]').text()).toMatch(/1\s?440/)
    expect(wrapper.get('[data-testid="attendance-amount-skipped"]').text()).toMatch(/1\s?080/)
  })

  it('osobu, kterou použití samo založí, do součtů započte', () => {
    const persons = [rate('B040', 144_000), rate('B041', 108_000, { match: unassigned })]
    const wrapper = mount(AttendanceSummaryStep, {
      props: { preview: preview(persons, { computed: 2, amount_minor: 252_000, no_hours: 0, hourly_terms: 0 }), manualLinks: {}, createKeys: ['B041'] },
    })
    expect(wrapper.get('[data-testid="attendance-rate-wages-computed"]').text()).toContain('"count":2')
    expect(wrapper.find('[data-testid="attendance-amount-skipped"]').exists()).toBe(false)
  })
})
